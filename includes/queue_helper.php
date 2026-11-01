<?php
// Wrapped in a constant-keyed block so a second include under a different path case cannot redeclare functions.
if (!defined('MH_LOADED_INCLUDES_QUEUE_HELPER')) {
define('MH_LOADED_INCLUDES_QUEUE_HELPER', true);

/**
 * =====================================================================
 * FIRST-COME, FIRST-SERVED WALK-IN QUEUE
 * =====================================================================
 * Order = order of arrival. Each department numbers its own queue per calendar day (1, 2, 3 ...).
 * The number is computed INSIDE the same transaction that inserts the row, using
 * SELECT ... FOR UPDATE on the (department, date, queue_number) index, so two receptionists
 * registering at the same moment can never get the same number. A UNIQUE key is the final backstop.
 *
 * Queue status for a patient who has arrived and is waiting to be called: 'Waiting'.
 */

/** Adds appointments.queue_number + indexes if missing (DDL, so call BEFORE begin_transaction()). */
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
        // e.g. the DB user has no ALTER right: run database/queue_number_migration.sql manually.
        error_log('mh_ensure_queue_schema failed: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Is the clinic closed TODAY? Returns a user-facing reason, or null when it is open.
 *
 * Closed = a holiday declared on the dashboard calendar (table `holidays`) OR a weekend
 * (the clinic is open Monday-Friday). "Today" is the DATABASE's CURDATE() - the same clock the
 * queue numbers and the "today only" list use, so they can never disagree.
 * If the check itself fails the clinic is treated as OPEN (a database hiccup must never block patient care).
 *
 * @param string|null $date 'Y-m-d' to test another day (defaults to today)
 */
function mh_clinic_closed_reason(mysqli $conn, $date = null)
{
    $date = ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) ? $date : null;
    $d    = $date === null ? 'CURDATE()' : '?';
    $st   = $conn->prepare("SELECT DAYOFWEEK($d) AS dow,
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
    if ((int) $row['dow'] === 1 || (int) $row['dow'] === 7) {   // MySQL: 1 = Sunday, 7 = Saturday
        return 'The clinic is closed on weekends (open Monday to Friday). Patients cannot be added to the queue today.';
    }
    return null;
}

/** Status values meaning "in today's queue, ready to be called". 'Approved' = a staff-approved appointment. */
function mh_queue_active_statuses()
{
    return ['Waiting', 'Approved'];
}

/**
 * Strict check that a department is a currently ACTIVE Clinic Department and that the clinical module
 * it maps to (Dental, Maternal Care, ...) is switched on in System Settings.
 * @return string|null error message, or null when the department can take patients
 */
function mh_queue_department_problem(mysqli $conn, $department)
{
    $department = (string) $department;
    $options = mh_options($conn, 'clinic_departments', false);   // every row, with is_active
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

/**
 * Put an EXISTING, ACTIVE patient at the end of a department's queue for today.
 * Call inside a transaction opened by the caller (so patient creation + queueing commit together).
 *
 * @return array{0:?int,1:string} [queue_number or null, error message]
 */
function mh_enqueue_patient(mysqli $conn, $patientId, $department, $reason = 'Walk-In Queue Registration')
{
    $patientId = (int) $patientId;

    // 0) Closed day? (holiday / weekend) - enforced here so no screen can bypass it.
    if (($closed = mh_clinic_closed_reason($conn)) !== null) { return [null, $closed]; }

    // 1) Patient must exist and be active (status = 1, i.e. not archived).
    $st = $conn->prepare("SELECT status, sex FROM patients WHERE id = ? LIMIT 1 FOR UPDATE");
    $st->bind_param('i', $patientId);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$p || (int) $p['status'] !== 1) { return [null, 'That patient record is not active.']; }

    // Male patients cannot join the Maternal Care queue (also stops a new walk-in registered as male).
    if (mh_department_module($department) === 'maternal_care' && strcasecmp(trim((string) $p['sex']), 'Male') === 0) {
        return [null, 'Maternal Care is for female patients. This patient is registered as male, so they cannot be added to that queue.'];
    }

    // 2) No double booking: already waiting in this department today?
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
            . ($already['queue_number'] ? ' (Queue #' . (int) $already['queue_number'] . ')' : '') . '.'];
    }

    // 3) Next number for THIS department and day. FOR UPDATE locks the index range -> arrivals are serialised.
    $next = $conn->prepare(
        "SELECT COALESCE(MAX(queue_number), 0) + 1 AS n FROM appointments
          WHERE department = ? AND appointment_date = CURDATE() FOR UPDATE"
    );
    $next->bind_param('s', $department);
    $next->execute();
    $queueNo = (int) $next->get_result()->fetch_assoc()['n'];
    $next->close();

    // 4) Arrival time is stored for the record; the queue number is what orders the line.
    $ins = $conn->prepare(
        "INSERT INTO appointments (patient_id, department, appointment_date, appointment_time, reason, status, queue_number)
         VALUES (?, ?, CURDATE(), CURTIME(), ?, 'Waiting', ?)"
    );
    $ins->bind_param('issi', $patientId, $department, $reason, $queueNo);
    $ins->execute();
    $ins->close();

    return [$queueNo, ''];
}


/**
 * =====================================================================
 * "PROCEED" HAND-OFF: queue -> department form -> Save record -> Completed
 * =====================================================================
 * Pressing Proceed on Patient Queueing only OPENS the department page for that queue entry
 * (?walkin_id=<patient>&apt_id=<queue entry>). The queue entry stays Waiting/Approved until the
 * doctor / attendant actually saves the record on the department page, which then calls
 * mh_queue_complete(). Nothing is marked Completed just because someone clicked Proceed.
 */

/**
 * The open queue entry a department page was opened for, or null when there is none / it is not valid.
 * Valid = exists, patient is active, still Waiting/Approved, belongs to $module's department,
 * and (when $patientHint > 0) belongs to that patient.
 *
 * @param string|null $module module key from mh_module_registry(), e.g. 'immunization', 'maternal_care'
 * @return array|null  [id, patient_id, department, status, queue_number, reason, appointment_date]
 */
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

/** Marks the queue entry Completed. Only moves Waiting/Approved -> Completed. @return bool true when a row changed */
function mh_queue_complete(mysqli $conn, $aptId, $patientId)
{
    $aptId = (int) $aptId; $patientId = (int) $patientId;
    if ($aptId <= 0 || $patientId <= 0) { return false; }
    $st = $conn->prepare("UPDATE appointments SET status = 'Completed'
                           WHERE id = ? AND patient_id = ? AND status IN ('Waiting','Approved')");
    if (!$st) { return false; }
    $st->bind_param('ii', $aptId, $patientId);
    $st->execute();
    $changed = $st->affected_rows > 0;
    $st->close();
    return $changed;
}

/**
 * Maternal Care is for female patients. Returns an error message when this registered patient is male
 * (null = fine, or the patient is not registered / not found - an unregistered typed name cannot be checked).
 */
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

/** Normalises a name part for comparison (case, dots, commas, extra spaces). */
function mh_queue_norm_name($v)
{
    return trim(preg_replace('/\s+/u', ' ', str_replace(['.', ','], '', mb_strtolower((string) $v))));
}

/**
 * Does the name typed on the form (from mh_read_patient_name_post()) still belong to this registered patient?
 * Used so a record saved for a DIFFERENT person never closes someone else's queue entry.
 */
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

/**
 * Which registered patient is this record for?
 * When the form was opened from the queue and the name is unchanged, it is exactly that queue patient
 * (even if two registered patients share the name); otherwise fall back to the usual unique-name match.
 *
 * @return int|null
 */
function mh_queue_resolve_patient(mysqli $conn, $ctx, array $pn)
{
    if ($ctx && mh_queue_name_matches($conn, $ctx['patient_id'], $pn)) { return (int) $ctx['patient_id']; }
    // A patient chosen from the "Search registered patients" box wins over a name guess - it is the only way
    // to tell apart two registered patients who share a name. Still verified: the typed name must match them.
    $picked = (int) ($_POST['picked_patient_id'] ?? 0);
    if ($picked > 0 && mh_queue_name_matches($conn, $picked, $pn)) { return $picked; }
    return mh_find_patient_id_by_parts($conn, $pn['last'], $pn['first'], $pn['mi']);
}

/** Registered-patient details used to pre-fill forms (never includes password/session columns). */
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

/** Vital signs recorded for the patient TODAY (Vital Signs page), newest first; null when none. */
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

/** First numeric value in a string ("65 kg" -> "65"); '' when none. For <input type="number">. */
function mh_queue_number($v)
{
    return preg_match('/\d+(?:\.\d+)?/', (string) $v, $m) ? $m[0] : '';
}

/** Drops empty values so only real data is sent to the form. */
function mh_queue_clean_prefill(array $f)
{
    return array_filter($f, function ($v) { return $v !== null && $v !== ''; });
}

/**
 * Banner shown above a department form that was opened from the queue.
 * @param array $ctx result of mh_queue_open_context()
 */
function mh_queue_render_banner(array $ctx, $patientLabel, $hasPrefill = true)
{
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    ?>
    <div class="mh-queue-banner" style="background:#e0f2fe; border:1px solid #7dd3fc; color:#075985; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:0.9rem; line-height:1.5;">
        <strong>From the queue<?php echo !empty($ctx['queue_number']) ? ' &middot; Queue #' . (int) $ctx['queue_number'] : ''; ?></strong>
        &mdash; <?php echo $e($patientLabel); ?> (<?php echo $e($ctx['department']); ?>).
        <?php if ($hasPrefill): ?>Details were filled in from the patient's records &mdash; please review them.<?php endif; ?><br>
        <span style="opacity:.85;">The queue entry is marked <strong>Completed</strong> only after you save this record.</span>
    </div>
    <?php
}

} // MH_LOADED_INCLUDES_QUEUE_HELPER
