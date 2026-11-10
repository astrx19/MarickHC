<?php

if (!defined('MH_LOADED_INCLUDES_QUEUE_HELPER')) {
define('MH_LOADED_INCLUDES_QUEUE_HELPER', true);

function mh_ensure_queue_schema(mysqli $conn)
{
    static $done = false;
    if ($done) { return; }
    try {
        $res = $conn->query("SHOW COLUMNS FROM `appointments` LIKE 'queue_number'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `appointments`
                            ADD COLUMN `queue_number` INT NULL AFTER `appointment_time`,
                            ADD UNIQUE KEY `uq_queue_slot` (`department`, `appointment_date`, `queue_number`)");
        }
    } catch (Throwable $e) {

        error_log('mh_ensure_queue_schema failed: ' . $e->getMessage());
    }
    $done = true;
}

function mh_operating_days()
{
    $daysRaw = '';
    if (function_exists('mh_setting')) {
        $daysRaw = (string) mh_setting('operating_days', 'Monday,Tuesday,Wednesday,Thursday,Friday');
    }
    if ($daysRaw === '') {
        $daysRaw = 'Monday,Tuesday,Wednesday,Thursday,Friday';
    }

    if (strpos($daysRaw, '[') === 0) {
        $decoded = json_decode($daysRaw, true);
        if (is_array($decoded)) {
            return array_map('trim', $decoded);
        }
    }
    $parts = explode(',', $daysRaw);
    return array_values(array_filter(array_map('trim', $parts)));
}

function mh_operating_days_summary()
{
    $days = mh_operating_days();
    if (empty($days)) {
        return 'No operating days scheduled';
    }
    $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    if ($days === $weekdays) {
        return 'Monday - Friday';
    }
    if (count($days) === 7) {
        return 'Every day (Monday - Sunday)';
    }
    return implode(', ', $days);
}

function mh_operating_window()
{
    $open = '08:00';
    $close = '17:00';
    if (function_exists('mh_setting')) {
        $o = (string) mh_setting('opening_time', $open);
        $c = (string) mh_setting('closing_time', $close);
        if (preg_match('/^\d{2}:\d{2}$/', $o)) { $open = $o; }
        if (preg_match('/^\d{2}:\d{2}$/', $c)) { $close = $c; }
    }
    return ['open' => $open . ':00', 'close' => $close . ':00'];
}

function mh_operating_hours_summary()
{
    $w = mh_operating_window();
    return date('g:i A', strtotime('2000-01-01 ' . $w['open'])) . ' - ' . date('g:i A', strtotime('2000-01-01 ' . $w['close']));
}

function mh_clinic_closed_reason(mysqli $conn, $date = null, $checkTime = true)
{
    $date = ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) ? $date : null;
    $d    = $date === null ? 'CURDATE()' : '?';
    $st   = $conn->prepare("SELECT DAYOFWEEK($d) AS dow,
                                   DAYNAME($d) AS dayname,
                                   TIME(NOW()) AS server_time,
                                   (SELECT description FROM holidays WHERE holiday_date = $d LIMIT 1) AS holiday");
    if (!$st) { return null; }
    if ($date !== null) { $st->bind_param('ss', $date, $date); }
    if (!$st->execute()) { $st->close(); return null; }
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) { return null; }

    if ($row['holiday'] !== null) {
        return 'The clinic is closed today (' . trim((string) $row['holiday']) . '). Patients cannot be added to the queue on a declared holiday.';
    }

    $daysSummary = mh_operating_days_summary();
    $hoursSummary = mh_operating_hours_summary();

    $dayName = trim((string) ($row['dayname'] ?? ''));
    $operatingDays = mh_operating_days();
    $isOperatingDay = in_array($dayName, $operatingDays, true);

    if (!$isOperatingDay) {
        return 'The clinic is closed today (' . $dayName . '). Operating schedule is ' . $daysSummary . ' (' . $hoursSummary . ').';
    }

    if ($checkTime && $date === null) {
        $w = mh_operating_window();
        $serverTime = trim((string) ($row['server_time'] ?? date('H:i:s')));
        if ($serverTime < $w['open'] || $serverTime >= $w['close']) {
            return 'The clinic is currently outside its operating hours (' . $hoursSummary . '). Walk-in registration is closed.';
        }
    }

    return null;
}

function mh_queue_label($department, $number)
{
    $n = (int) $number;
    if ($n <= 0) { return ''; }
    $words = preg_split('/[^A-Za-z0-9]+/', trim((string) $department), -1, PREG_SPLIT_NO_EMPTY);
    $prefix = '';
    foreach ($words as $w) { $prefix .= strtoupper($w[0]); if (strlen($prefix) >= 3) { break; } }
    if (strlen($prefix) < 2 && isset($words[0])) { $prefix = strtoupper(substr($words[0], 0, 2)); }
    if ($prefix === '') { $prefix = 'Q'; }
    return $prefix . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
}

function mh_queue_signature(mysqli $conn)
{
    $res = $conn->query(
        "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS(':', id, department, status, COALESCE(queue_number, 0)))), 0) AS s
           FROM appointments WHERE appointment_date = CURDATE()"
    );
    $row = $res ? $res->fetch_assoc() : null;
    $count = (int) ($row['c'] ?? 0);
    return ['sig' => md5($count . ':' . ($row['s'] ?? '0')), 'count' => $count];
}

function mh_queue_active_statuses()
{
    return ['Waiting', 'Approved'];
}

function mh_queue_department_problem(mysqli $conn, $department)
{
    $department = (string) $department;
    $options = mh_options($conn, 'clinic_departments', false);
    if ($options) {
        $active = false;
        foreach ($options as $o) {
            if ($o['option_value'] === $department && (int) $o['is_active'] === 1) { $active = true; break; }
        }
        if (!$active) { return 'Please choose an active clinic department.'; }
    } elseif (!mh_is_clinic_department($conn, $department)) {
        return 'Please choose an active clinic department.';
    }
    $module = mh_department_module($department);
    if ($module !== null && !mh_module_is_enabled($module)) {
        return 'That service is currently turned off in System Settings, so it is not accepting patients.';
    }
    return null;
}

function mh_enqueue_patient(mysqli $conn, $patientId, $department, $reason = 'Walk-In Queue Registration')
{
    $patientId = (int) $patientId;

    if (($closed = mh_clinic_closed_reason($conn)) !== null) { return [null, $closed]; }

    $st = $conn->prepare("SELECT status, sex FROM patients WHERE id = ? LIMIT 1 FOR UPDATE");
    $st->bind_param('i', $patientId);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$p || (int) $p['status'] !== 1) { return [null, 'That patient record is not active.']; }

    if (mh_department_module($department) === 'maternal_care' && strcasecmp(trim((string) $p['sex']), 'Male') === 0) {
        return [null, 'Maternal Care is for female patients. This patient is registered as male, so they cannot be added to that queue.'];
    }

    $statuses = mh_queue_active_statuses();
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $dup = $conn->prepare(
        "SELECT queue_number FROM appointments
          WHERE patient_id = ? AND department = ? AND appointment_date = CURDATE() AND status IN ($in) LIMIT 1"
    );
    $dup->bind_param('is' . str_repeat('s', count($statuses)), $patientId, $department, ...$statuses);
    $dup->execute();
    $already = $dup->get_result()->fetch_assoc();
    $dup->close();
    if ($already) {
        return [null, 'This patient is already in the ' . $department . ' queue today'
            . ($already['queue_number'] ? ' (Queue ' . mh_queue_label($department, $already['queue_number']) . ')' : '') . '.'];
    }

    $next = $conn->prepare(
        "SELECT COALESCE(MAX(queue_number), 0) + 1 AS n FROM appointments
          WHERE department = ? AND appointment_date = CURDATE() FOR UPDATE"
    );
    $next->bind_param('s', $department);
    $next->execute();
    $queueNo = (int) $next->get_result()->fetch_assoc()['n'];
    $next->close();

    $ins = $conn->prepare(
        "INSERT INTO appointments (patient_id, department, appointment_date, appointment_time, reason, status, queue_number)
         VALUES (?, ?, CURDATE(), CURTIME(), ?, 'Waiting', ?)"
    );
    $ins->bind_param('issi', $patientId, $department, $reason, $queueNo);
    $ins->execute();
    $ins->close();

    return [$queueNo, ''];
}

function mh_queue_open_context(mysqli $conn, $aptId, $module = null, $patientHint = 0)
{
    $aptId = (int) $aptId;
    if ($aptId <= 0) { return null; }
    $st = $conn->prepare(
        "SELECT a.id, a.patient_id, a.department, a.status, a.queue_number, a.reason, a.appointment_date
           FROM appointments a
           JOIN patients p ON p.id = a.patient_id AND p.status = 1
          WHERE a.id = ? LIMIT 1"
    );
    if (!$st) { return null; }
    $st->bind_param('i', $aptId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) { return null; }
    if (!in_array($row['status'], mh_queue_active_statuses(), true)) { return null; }
    if ($module !== null && mh_department_module($row['department']) !== $module) { return null; }
    if ((int) $patientHint > 0 && (int) $row['patient_id'] !== (int) $patientHint) { return null; }
    return $row;
}

function mh_queue_complete(mysqli $conn, $aptId, $patientId)
{
    $aptId = (int) $aptId; $patientId = (int) $patientId;
    if ($aptId <= 0 || $patientId <= 0) { return false; }
    $statuses = mh_queue_active_statuses();
    $st = $conn->prepare("UPDATE appointments SET status = 'Completed'
                           WHERE id = ? AND patient_id = ? AND status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")");
    if (!$st) { return false; }
    $st->bind_param('ii' . str_repeat('s', count($statuses)), $aptId, $patientId, ...$statuses);
    $st->execute();
    $changed = $st->affected_rows > 0;
    $st->close();
    return $changed;
}

function mh_maternal_sex_error(mysqli $conn, $patientId)
{
    $patientId = (int) $patientId;
    if ($patientId <= 0) { return null; }
    $st = $conn->prepare("SELECT sex FROM patients WHERE id = ? LIMIT 1");
    if (!$st) { return null; }
    $st->bind_param('i', $patientId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row && strcasecmp(trim((string) $row['sex']), 'Male') === 0) {
        return 'Maternal Care is for female patients. This patient is registered as male, so nothing was saved.';
    }
    return null;
}

function mh_queue_norm_name($v)
{
    return trim(preg_replace('/\s+/u', ' ', str_replace(['.', ','], '', mb_strtolower((string) $v))));
}

function mh_queue_name_matches(mysqli $conn, $patientId, array $pn)
{
    $st = $conn->prepare("SELECT first_name, middle_name, last_name FROM patients WHERE id = ? LIMIT 1");
    if (!$st) { return false; }
    $patientId = (int) $patientId;
    $st->bind_param('i', $patientId);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$p) { return false; }
    if (mh_queue_norm_name($p['last_name']) !== mh_queue_norm_name($pn['last'] ?? '')) { return false; }
    if (mh_queue_norm_name($p['first_name']) !== mh_queue_norm_name($pn['first'] ?? '')) { return false; }
    $mi = mb_strtolower(trim((string) ($pn['mi'] ?? '')));
    $pm = mb_strtolower(mb_substr(trim((string) ($p['middle_name'] ?? '')), 0, 1));
    return $mi === '' || $pm === '' || $mi === $pm;
}

function mh_queue_resolve_patient(mysqli $conn, $ctx, array $pn)
{
    if ($ctx && mh_queue_name_matches($conn, $ctx['patient_id'], $pn)) { return (int) $ctx['patient_id']; }

    $picked = (int) ($_POST['picked_patient_id'] ?? 0);
    if ($picked > 0 && mh_queue_name_matches($conn, $picked, $pn)) { return $picked; }
    return mh_find_patient_id_by_parts($conn, $pn['last'], $pn['first'], $pn['mi']);
}

function mh_queue_patient_profile(mysqli $conn, $patientId)
{
    $patientId = (int) $patientId;
    if ($patientId <= 0) { return null; }
    $st = $conn->prepare("SELECT id, first_name, middle_name, last_name, birthdate, sex, contact_number, address, philhealth_no, family_no
                            FROM patients WHERE id = ? AND status = 1 LIMIT 1");
    if (!$st) { return null; }
    $st->bind_param('i', $patientId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function mh_queue_today_vitals(mysqli $conn, $patientId)
{
    $patientId = (int) $patientId;
    if ($patientId <= 0) { return null; }
    $st = $conn->prepare("SELECT bp, temp, pulse, weight, height FROM vital_signs
                           WHERE patient_id = ? AND DATE(recorded_at) = CURDATE()
                           ORDER BY recorded_at DESC, id DESC LIMIT 1");
    if (!$st) { return null; }
    $st->bind_param('i', $patientId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function mh_queue_number($v)
{
    return preg_match('/\d+(?:\.\d+)?/', (string) $v, $m) ? $m[0] : '';
}

function mh_queue_clean_prefill(array $f)
{
    return array_filter($f, function ($v) { return $v !== null && $v !== ''; });
}

function mh_queue_render_banner(array $ctx, $patientLabel, $hasPrefill = true)
{
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    ?>
    <div class="mh-queue-banner" style="background:#e0f2fe; border:1px solid #7dd3fc; color:#075985; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:0.9rem; line-height:1.5;">
        <strong>From the queue<?php echo !empty($ctx['queue_number']) ? ' &middot; Queue ' . mh_queue_label($ctx['department'] ?? '', $ctx['queue_number']) : ''; ?></strong>
        &mdash; <?php echo $e($patientLabel); ?> (<?php echo $e($ctx['department']); ?>).
        <?php if ($hasPrefill): ?>Details were filled in from the patient's records &mdash; please review them.<?php endif; ?><br>
        <span style="opacity:.85;">The queue entry is marked <strong>Completed</strong> only after you save this record.</span>
    </div>
    <?php
}

}
