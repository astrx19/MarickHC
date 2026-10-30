<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE: PHP registers top-level functions at
// compile time, so require_once cannot stop a redeclare when one file is reached by two paths that differ
// only in letter case (/MARICK-HEALTH-SYSTEM-v2/ vs /marick-health-system-v2/ on macOS/Windows).
if (!defined('MH_LOADED_INCLUDES_SMS_SMS_SCHEDULER')) {
define('MH_LOADED_INCLUDES_SMS_SMS_SCHEDULER', true);
/**
 * =====================================================================
 * FOLLOW-UP SMS SCHEDULER
 * =====================================================================
 * One shared function, called by the three clinic modules
 * (checkups_consultations.php, immunization.php, maternal_care.php)
 * right after a record with a follow-up/return date is saved.
 *
 * It does NOT send anything itself - it only queues row(s) in
 * `scheduled_sms`. Sending happens later, either by
 * cron/send_daily_reminders.php or the "Run Pending SMS Queue Now"
 * button on admin/sms_dashboard.php.
 *
 * USAGE
 * -----
 *   require_once __DIR__ . '/../../includes/sms/SemaphoreSmsClient.php';
 *   require_once __DIR__ . '/../../includes/sms/sms_scheduler.php';
 *
 *   mh_schedule_followup_sms($conn, $patient_id, $_POST['next_visit_date'], 'Check-up', $record_id);
 */

/**
 * Queues follow-up reminder SMS for a patient.
 *
 * By default this queues TWO reminders (see MH_SMS_REMINDER_* below):
 * one for the day before the follow-up, and one for the morning of the
 * follow-up itself. Either can be disabled independently.
 *
 * @param mysqli $conn
 * @param int    $patientId
 * @param string $followupDate  "Y-m-d" - the next_visit_date/return_date
 *                               the health worker entered. Silently does
 *                               nothing if empty/invalid/in the past.
 * @param string $moduleLabel   Human label used in the message, e.g.
 *                               "Check-up", "Immunization", "Prenatal Care".
 * @param int|null $sourceRecordId  The consultation/record id, purely for
 *                               traceability in scheduled_sms.source_record_id.
 * @return array{queued:int, skipped_reason:string|null}  How many rows were
 *                               inserted (0-2), and why none were queued if 0.
 */
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

    // ---- look up the patient's name + phone -----------------------
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
        return ['queued' => 0, 'skipped_reason' => 'Patient has no valid PH mobile number on file (has: "'
            . ($patient['contact_number'] ?? '') . '").'];
    }

    $firstName  = trim((string) ($patient['first_name'] ?? '')) ?: 'Patient';
    $clinicName = function_exists('mh_setting') ? mh_setting('system_name') : 'Health Center';
    $prettyDate = date('F j, Y (D)', strtotime($followupDate));

    // ---- how many / which reminders to queue -----------------------
    // Toggle either reminder off by defining the constant as false
    // before this file is included (e.g. in config/semaphore.php).
    if (!defined('MH_SMS_REMINDER_DAY_BEFORE')) { define('MH_SMS_REMINDER_DAY_BEFORE', true); }
    if (!defined('MH_SMS_REMINDER_SAME_DAY'))   { define('MH_SMS_REMINDER_SAME_DAY', true); }

    $toQueue = [];

    if (MH_SMS_REMINDER_DAY_BEFORE) {
        $sendOn = date('Y-m-d', strtotime($followupDate . ' -1 day'));
        if ($sendOn >= $today) {
            $msg = "Hello {$firstName}, this is a reminder from {$clinicName} for your {$moduleLabel} follow-up "
                 . "TOMORROW, {$prettyDate}. Please bring your health card. Reply STOP to opt out.";
            $toQueue[] = ['date' => $sendOn, 'message' => $msg];
        }
    }

    if (MH_SMS_REMINDER_SAME_DAY) {
        $msg = "Hello {$firstName}, this is a reminder from {$clinicName} for your {$moduleLabel} follow-up "
             . "TODAY, {$prettyDate}. Please bring your health card. Reply STOP to opt out.";
        $toQueue[] = ['date' => $followupDate, 'message' => $msg];
    }

    if (!$toQueue) {
        return ['queued' => 0, 'skipped_reason' => 'Both reminder types are disabled in configuration.'];
    }

    $insert = $conn->prepare(
        "INSERT INTO scheduled_sms (patient_id, phone_number, message, scheduled_date, status, source_module, source_record_id)
         VALUES (?, ?, ?, ?, 'pending', ?, ?)"
    );
    if (!$insert) {
        return ['queued' => 0, 'skipped_reason' => 'Database error queuing the reminder: ' . $conn->error];
    }

    $queued = 0;
    foreach ($toQueue as $item) {
        $insert->bind_param(
            'issssi',
            $patientId,
            $normalizedPhone,
            $item['message'],
            $item['date'],
            $moduleLabel,
            $sourceRecordId
        );
        // source_record_id may be null - bind_param with 'i' + null is fine, mysqli sends SQL NULL.
        if ($insert->execute()) {
            $queued++;
        }
    }
    $insert->close();

    return ['queued' => $queued, 'skipped_reason' => $queued === 0 ? 'Insert failed for all reminders.' : null];
}
}
