<?php
if (!defined('MH_LOADED_INCLUDES_SMS_SMS_SCHEDULER')) {
define('MH_LOADED_INCLUDES_SMS_SMS_SCHEDULER', true);

if (!function_exists('mh_followup_notes')) {
    function mh_followup_notes($notes)
    {
        $notes  = (string) $notes;
        $date   = trim((string) ($_POST['next_visit_date'] ?? ''));
        $reason = trim((string) ($_POST['followup_reason'] ?? ''));
        if ($date === '' || $reason === '') { return $notes; }
        $reason = mb_substr(preg_replace('/\s+/', ' ', $reason), 0, 255);
        return rtrim($notes) === '' ? 'Return visit reason: ' . $reason : rtrim($notes) . "\nReturn visit reason: " . $reason;
    }
}

if (!function_exists('mh_sms_is_holiday')) {
    function mh_sms_is_holiday(mysqli $conn, $date)
    {
        static $cache = [];
        $date = (string) $date;
        if (isset($cache[$date])) { return $cache[$date]; }
        $cache[$date] = false;
        try {
            $st = $conn->prepare("SELECT 1 FROM holidays WHERE holiday_date = ? LIMIT 1");
            if ($st) {
                $st->bind_param('s', $date);
                $st->execute();
                $cache[$date] = $st->get_result()->num_rows > 0;
                $st->close();
            }
        } catch (Throwable $e) { }
        return $cache[$date];
    }
}

if (!function_exists('mh_sms_shift_off_holiday')) {
    function mh_sms_shift_off_holiday(mysqli $conn, $date, $floor, $ceiling)
    {
        if (!mh_sms_is_holiday($conn, $date)) { return $date; }
        for ($d = strtotime($date . ' -1 day'); date('Y-m-d', $d) >= $floor; $d = strtotime('-1 day', $d)) {
            $cand = date('Y-m-d', $d);
            if (!mh_sms_is_holiday($conn, $cand)) { return $cand; }
        }
        for ($d = strtotime($date . ' +1 day'); date('Y-m-d', $d) <= $ceiling; $d = strtotime('+1 day', $d)) {
            $cand = date('Y-m-d', $d);
            if (!mh_sms_is_holiday($conn, $cand)) { return $cand; }
        }
        return null;
    }
}

if (!function_exists('mh_sms_reminder_exists')) {
    function mh_sms_reminder_exists(mysqli $conn, $patientId, $moduleLabel, $sourceRecordId, $sendDate)
    {
        $st = $conn->prepare("SELECT 1 FROM scheduled_sms
                               WHERE patient_id = ? AND scheduled_date = ? AND source_module <=> ?
                                 AND source_record_id <=> ? AND status IN ('pending','sent') LIMIT 1");
        if (!$st) { return false; }
        $st->bind_param('issi', $patientId, $sendDate, $moduleLabel, $sourceRecordId);
        $st->execute();
        $found = $st->get_result()->num_rows > 0;
        $st->close();
        return $found;
    }
}

if (!function_exists('mh_sms_record_skip')) {
    function mh_sms_record_skip(mysqli $conn, $patientId, $moduleLabel, $sourceRecordId, $followupDate, $reason)
    {
        try {
            $st = $conn->prepare("SELECT 1 FROM scheduled_sms
                                   WHERE patient_id = ? AND scheduled_date = ? AND source_module <=> ?
                                     AND source_record_id <=> ? AND status = 'failed' AND response_log LIKE 'SKIPPED:%' LIMIT 1");
            if ($st) {
                $st->bind_param('issi', $patientId, $followupDate, $moduleLabel, $sourceRecordId);
                $st->execute();
                $dup = $st->get_result()->num_rows > 0;
                $st->close();
                if ($dup) { return; }
            }
            $note = 'SKIPPED: ' . $reason;
            $ins = $conn->prepare("INSERT INTO scheduled_sms (patient_id, phone_number, message, scheduled_date, status, response_log, source_module, source_record_id)
                                   VALUES (?, '', '', ?, 'failed', ?, ?, ?)");
            if ($ins) {
                $ins->bind_param('isssi', $patientId, $followupDate, $note, $moduleLabel, $sourceRecordId);
                $ins->execute();
                $ins->close();
            }
        } catch (Throwable $e) { error_log('mh_sms_record_skip: ' . $e->getMessage()); }
    }
}


if (!function_exists('mh_sms_template_placeholders')) {
    function mh_sms_template_placeholders()
    {
        return ['first_name', 'clinic', 'module', 'date', 'when_date', 'holiday_note'];
    }
}
if (!function_exists('mh_sms_render_template')) {
    function mh_sms_render_template($template, array $vars)
    {
        $out = (string) $template;
        if (($vars['holiday_note'] ?? '') !== '' && strpos($out, '{holiday_note}') === false) {
            $out .= ' {holiday_note}';
        }
        foreach (mh_sms_template_placeholders() as $k) {
            $out = str_replace('{' . $k . '}', (string) ($vars[$k] ?? ''), $out);
        }
        $out = trim(preg_replace('/[ \t]{2,}/', ' ', $out));
        return $out;
    }
}

function mh_schedule_followup_sms(mysqli $conn, $patientId, $followupDate, $moduleLabel, $sourceRecordId = null)
{
    $patientId    = (int) $patientId;
    $followupDate = trim((string) $followupDate);

    if ($patientId <= 0) {
        return ['queued' => 0, 'skipped_reason' => 'No valid patient.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $followupDate)) {
        return ['queued' => 0, 'skipped_reason' => 'No follow-up date was entered.'];
    }

    $today = date('Y-m-d');
    if ($followupDate < $today) {
        return ['queued' => 0, 'skipped_reason' => 'Follow-up date is in the past - not scheduling a reminder.'];
    }

    $stmt = $conn->prepare("SELECT first_name, contact_number FROM patients WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['queued' => 0, 'skipped_reason' => 'Database error looking up the patient.'];
    }
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $res = $stmt->get_result();
    $patient = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$patient) {
        return ['queued' => 0, 'skipped_reason' => 'Patient not found.'];
    }

    $normalizedPhone = mh_normalize_ph_mobile($patient['contact_number'] ?? '');
    if ($normalizedPhone === '') {
        $why = 'Patient has no valid PH mobile number on file (has: "' . ($patient['contact_number'] ?? '') . '").';
        mh_sms_record_skip($conn, $patientId, (string) $moduleLabel, $sourceRecordId === null ? null : (int) $sourceRecordId, $followupDate, $why);
        return ['queued' => 0, 'skipped_reason' => $why];
    }
    $sourceRecordId = ($sourceRecordId === null || $sourceRecordId === '') ? null : (int) $sourceRecordId;

    $firstName  = trim((string) ($patient['first_name'] ?? '')) ?: 'Patient';
    $clinicName = function_exists('mh_setting') ? mh_setting('system_name') : 'Health Center';
    $prettyDate = date('F j, Y (D)', strtotime($followupDate));

    if (!defined('MH_SMS_REMINDER_DAY_BEFORE')) { define('MH_SMS_REMINDER_DAY_BEFORE', true); }
    if (!defined('MH_SMS_REMINDER_SAME_DAY'))   { define('MH_SMS_REMINDER_SAME_DAY', true); }

    $followupIsHoliday = mh_sms_is_holiday($conn, $followupDate);
    $holidayNote = $followupIsHoliday ? ' Note: that date is a declared clinic holiday - please confirm with the health center.' : '';

    $toQueue = [];

    $tplTomorrow = function_exists('mh_setting') ? (string) mh_setting('sms_followup_tomorrow_template') : '';
    $tplToday    = function_exists('mh_setting') ? (string) mh_setting('sms_followup_today_template') : '';
    if (trim($tplTomorrow) === '') { $tplTomorrow = 'Hello {first_name}, this is a reminder from {clinic} for your {module} follow-up {when_date}.{holiday_note} Please bring your health card. Reply STOP to opt out.'; }
    if (trim($tplToday) === '')    { $tplToday    = $tplTomorrow; }
    $tplVars = ['first_name' => $firstName, 'clinic' => $clinicName, 'module' => (string) $moduleLabel,
                'date' => $prettyDate, 'holiday_note' => $holidayNote];

    if (MH_SMS_REMINDER_DAY_BEFORE) {
        $sendOn = date('Y-m-d', strtotime($followupDate . ' -1 day'));
        if ($sendOn >= $today) {
            $sendOn = mh_sms_shift_off_holiday($conn, $sendOn, $today, $followupDate);
            if ($sendOn !== null) {
                $when = ($sendOn === date('Y-m-d', strtotime($followupDate . ' -1 day'))) ? 'TOMORROW, ' : 'on ';
                $toQueue[$sendOn] = mh_sms_render_template($tplTomorrow, $tplVars + ['when_date' => $when . $prettyDate]);
            }
        }
    }

    if (MH_SMS_REMINDER_SAME_DAY) {
        $sendOn = mh_sms_shift_off_holiday($conn, $followupDate, $today, $followupDate);
        if ($sendOn !== null && !isset($toQueue[$sendOn])) {
            $when = ($sendOn === $followupDate) ? 'TODAY, ' : 'on ';
            $toQueue[$sendOn] = mh_sms_render_template($tplToday, $tplVars + ['when_date' => $when . $prettyDate]);
        }
    }

    if (!$toQueue) {
        return ['queued' => 0, 'skipped_reason' => (MH_SMS_REMINDER_DAY_BEFORE || MH_SMS_REMINDER_SAME_DAY)
            ? 'No open (non-holiday) day is available to send a reminder before the follow-up.'
            : 'Both reminder types are disabled in configuration.'];
    }

    if ($sourceRecordId !== null) {
        $keep = array_keys($toQueue);
        $in   = implode(',', array_fill(0, count($keep), '?'));
        $del  = $conn->prepare("DELETE FROM scheduled_sms
                                 WHERE patient_id = ? AND source_module <=> ? AND source_record_id = ?
                                   AND status = 'pending' AND scheduled_date NOT IN ($in)");
        if ($del) {
            $types = 'isi' . str_repeat('s', count($keep));
            $args  = array_merge([$patientId, (string) $moduleLabel, $sourceRecordId], $keep);
            $del->bind_param($types, ...$args);
            $del->execute();
            $del->close();
        }
    }

    $insert = $conn->prepare(
        "INSERT INTO scheduled_sms (patient_id, phone_number, message, scheduled_date, status, source_module, source_record_id)
         VALUES (?, ?, ?, ?, 'pending', ?, ?)"
    );
    if (!$insert) {
        return ['queued' => 0, 'skipped_reason' => 'Database error queuing the reminder: ' . $conn->error];
    }

    $queued = 0;
    $duplicates = 0;
    foreach ($toQueue as $date => $message) {
        if (mh_sms_reminder_exists($conn, $patientId, (string) $moduleLabel, $sourceRecordId, $date)) {
            $duplicates++;
            continue;
        }
        $insert->bind_param('issssi', $patientId, $normalizedPhone, $message, $date, $moduleLabel, $sourceRecordId);
        try {
            if ($insert->execute()) { $queued++; }
        } catch (Throwable $e) {
            $duplicates++;
        }
    }
    $insert->close();

    if ($queued === 0 && $duplicates > 0) {
        return ['queued' => 0, 'skipped_reason' => 'Reminders for this follow-up are already scheduled (no duplicates created).'];
    }
    return ['queued' => $queued, 'skipped_reason' => $queued === 0 ? 'Insert failed for all reminders.' : null];
}
}