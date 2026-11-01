<?php
/**
 * Patient profile data (Step 5): additional contact numbers, dispense-medicine history and the
 * complete medical-history timeline. Plain prepared mysqli statements (the site's proven driver).
 * Compatible with PHP 7.2+.
 */

if (!function_exists('mh_ensure_patient_contacts_schema')) {

    /** Creates `patient_contacts` when the Step 5 migration was not run (same safety net as the queue schema). */
    function mh_ensure_patient_contacts_schema(mysqli $conn)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS `patient_contacts` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `patient_id` INT(11) NOT NULL,
                `contact_name` VARCHAR(100) NOT NULL,
                `relationship` VARCHAR(50) DEFAULT NULL,
                `contact_number` VARCHAR(20) NOT NULL,
                `created_by` INT(11) DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_pc_patient` (`patient_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('mh_ensure_patient_contacts_schema failed (run database/step5_migration.sql): ' . $e->getMessage());
        }
    }

    /** @return array[] id, contact_name, relationship, contact_number */
    function mh_patient_contacts(mysqli $conn, $patientId)
    {
        mh_ensure_patient_contacts_schema($conn);
        $patientId = (int) $patientId;
        $st = @$conn->prepare("SELECT id, contact_name, relationship, contact_number FROM patient_contacts WHERE patient_id = ? ORDER BY id ASC");
        if (!$st) { return []; }
        $st->bind_param('i', $patientId);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }

    /** "0912 345 6789", "+63 912..." or "09123456789" -> "+639123456789"; null when it is not a PH mobile number. */
    function mh_pc_normalize_mobile($raw)
    {
        $d = preg_replace('/[^0-9+]/', '', (string) $raw);
        if (preg_match('/^\+63(9[0-9]{9})$/', $d, $m)) { return '+63' . $m[1]; }
        if (preg_match('/^63(9[0-9]{9})$/', $d, $m))   { return '+63' . $m[1]; }
        if (preg_match('/^0(9[0-9]{9})$/', $d, $m))    { return '+63' . $m[1]; }
        return null;
    }

    /** @return string|null error message, null on success */
    function mh_patient_contact_add(mysqli $conn, $patientId, $name, $relationship, $number, $staffId)
    {
        mh_ensure_patient_contacts_schema($conn);
        $patientId = (int) $patientId;
        $name = trim((string) $name);
        $relationship = trim((string) $relationship);
        $mobile = mh_pc_normalize_mobile($number);

        if ($name === '' || mb_strlen($name) > 100)            { return 'Enter the contact person\'s name (up to 100 characters).'; }
        if (mb_strlen($relationship) > 50)                      { return 'The relationship is too long (50 characters at most).'; }
        if ($mobile === null)                                   { return 'Enter a valid mobile number, e.g. 09123456789 or +639123456789.'; }

        $cnt = $conn->prepare("SELECT COUNT(*) AS n FROM patient_contacts WHERE patient_id = ?");
        $cnt->bind_param('i', $patientId);
        $cnt->execute();
        $n = (int) ($cnt->get_result()->fetch_assoc()['n'] ?? 0);
        $cnt->close();
        if ($n >= 5) { return 'A patient can have up to 5 additional contacts. Remove one first.'; }

        $dup = $conn->prepare("SELECT id FROM patient_contacts WHERE patient_id = ? AND contact_number = ? LIMIT 1");
        $dup->bind_param('is', $patientId, $mobile);
        $dup->execute();
        $exists = (bool) $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($exists) { return 'That number is already saved for this patient.'; }

        $rel = $relationship === '' ? null : $relationship;
        $by  = (int) $staffId;
        $ins = $conn->prepare("INSERT INTO patient_contacts (patient_id, contact_name, relationship, contact_number, created_by) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param('isssi', $patientId, $name, $rel, $mobile, $by);
        $ok = $ins->execute();
        if (!$ok) { error_log('mh_patient_contact_add: ' . $ins->error); }
        $ins->close();
        return $ok ? null : 'The contact could not be saved. Please try again.';
    }

    function mh_patient_contact_delete(mysqli $conn, $patientId, $contactId)
    {
        $patientId = (int) $patientId; $contactId = (int) $contactId;
        $st = $conn->prepare("DELETE FROM patient_contacts WHERE id = ? AND patient_id = ?");
        $st->bind_param('ii', $contactId, $patientId);
        $st->execute();
        $deleted = $st->affected_rows > 0;
        $st->close();
        return $deleted;
    }

    /** Medicines given to this patient, newest first (joined in ONE query - no per-row lookups). */
    function mh_patient_dispense_history(mysqli $conn, $patientId)
    {
        $patientId = (int) $patientId;
        $st = @$conn->prepare(
            "SELECT d.id, d.quantity, d.dispense_date, d.is_fefo_override,
                    i.item_name, i.brand_name, i.unit, i.batch_number,
                    s.first_name AS staff_first, s.last_name AS staff_last
               FROM dispensations d
               JOIN inventory i ON i.id = d.inventory_id
               LEFT JOIN staff s ON s.id = d.dispensed_by
              WHERE d.patient_id = ?
              ORDER BY d.dispense_date DESC, d.id DESC
              LIMIT 200"
        );
        if (!$st) { return []; }
        $st->bind_param('i', $patientId);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }

    /**
     * Child Immunization records are filed by typed name. A baby who is not a registered patient yet used to
     * be saved with patient_id = NULL and therefore never appeared in the Patient Masterlist.
     * This registers the child (or links to the SAME child if name + birthdate already exist).
     *
     * @param array $pn  result of mh_read_patient_name_post()  (first, last, mi)
     * @return array [patient_id|null, error|null, created(bool)]
     */
    function mh_register_child_patient(mysqli $conn, array $pn, $birthdate, $sex, $mobile, $familyNo)
    {
        $birthdate = trim((string) $birthdate);
        $sex = trim((string) $sex);
        $d = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$d || $d->format('Y-m-d') !== $birthdate || $d > new DateTime('today') || $d < new DateTime('1900-01-01')) {
            return [null, "Enter the child's real birthdate (not in the future) so the child can be added to the Patient Masterlist.", false];
        }
        if (!in_array($sex, ['Male', 'Female'], true)) {
            return [null, "Choose the child's sex so the child can be added to the Patient Masterlist.", false];
        }

        // Same name + birthdate already registered? Then this IS that child: link, do not duplicate.
        $st = $conn->prepare("SELECT id FROM patients WHERE status = 1 AND birthdate = ? AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1");
        $st->bind_param('sss', $birthdate, $pn['first'], $pn['last']);
        $st->execute();
        $found = $st->get_result()->fetch_assoc();
        $st->close();
        if ($found) { return [(int) $found['id'], null, false]; }

        $middle = ($pn['mi'] ?? '') !== '' ? $pn['mi'] : null;
        $contact = mh_pc_normalize_mobile($mobile);
        $contact = $contact !== null ? $contact : '';
        $email = preg_replace('/[^a-z0-9]/', '', strtolower($pn['first'])) . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($pn['last']))
               . '.' . bin2hex(random_bytes(4)) . '@walkin.local';          // placeholder (NOT NULL UNIQUE), never mailed
        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);   // no one can log in as this record
        $family = trim((string) $familyNo) === '' ? null : trim((string) $familyNo);
        $address = '';

        $ins = $conn->prepare("INSERT INTO patients (first_name, middle_name, last_name, birthdate, sex, contact_number, email, password, address, family_no, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        if (!$ins) { return [null, 'The child could not be registered right now. Please try again.', false]; }
        $ins->bind_param('ssssssssss', $pn['first'], $middle, $pn['last'], $birthdate, $sex, $contact, $email, $password, $address, $family);
        if (!$ins->execute()) {
            error_log('mh_register_child_patient: ' . $ins->error);
            $ins->close();
            return [null, 'The child could not be registered right now. Please try again.', false];
        }
        $id = (int) $conn->insert_id;
        $ins->close();
        return [$id, null, true];
    }

    /** Small helper: run a prepared SELECT by patient id and return all rows. */
    function mh_ph_rows(mysqli $conn, $sql, $patientId)
    {
        $patientId = (int) $patientId;
        $st = @$conn->prepare($sql);
        if (!$st) { return []; }
        $st->bind_param('i', $patientId);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }

    /** Only non-empty values, labelled: ['Weight' => '12 kg', ...]. Dates that are 0000-00-00 are dropped. */
    function mh_ph_fields(array $row, array $map)
    {
        $out = [];
        foreach ($map as $col => $label) {
            if (!array_key_exists($col, $row)) { continue; }
            $v = trim((string) $row[$col]);
            if ($v === '' || strpos($v, '0000-00-00') === 0) { continue; }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $v = date('M j, Y', strtotime($v)); }
            $out[$label] = $v;
        }
        return $out;
    }

    function mh_ph_staff($first, $last, $prefix = '')
    {
        $n = trim((string) $first . ' ' . (string) $last);
        return $n === '' ? 'Not recorded' : trim($prefix . ' ' . $n);
    }

    /**
     * The complete timeline: every consultation, vital-signs reading, dental visit, prenatal visit,
     * child-immunization card and child check-up of ONE patient, newest first.
     * Each item: type, date (Y-m-d), color [r,g,b], attending, fields [label => value] (the first
     * three are the summary; the rest sit behind "See more").
     */
    function mh_patient_history(mysqli $conn, $patientId)
    {
        $items = [];

        foreach (mh_ph_rows($conn, "SELECT c.*, s.first_name AS sf, s.last_name AS sl FROM consultations c LEFT JOIN staff s ON c.doctor_id = s.id WHERE c.patient_id = ?", $patientId) as $r) {
            $f = mh_ph_fields($r, ['diagnosis' => 'Diagnosis', 'chief_complaints' => 'Chief complaint', 'prescription' => 'Prescription',
                                   'bp' => 'Blood pressure', 'temp' => 'Temperature', 'weight' => 'Weight', 'height' => 'Height',
                                   'notes' => 'Notes', 'next_visit_date' => 'Next visit']);
            $items[] = ['type' => 'General Consultation', 'date' => $r['visit_date'], 'color' => [59, 130, 246],
                        'attending' => mh_ph_staff($r['sf'], $r['sl'], 'Dr.'), 'fields' => $f];
        }

        foreach (mh_ph_rows($conn, "SELECT v.*, s.first_name AS sf, s.last_name AS sl FROM vital_signs v LEFT JOIN staff s ON v.recorded_by = s.id WHERE v.patient_id = ?", $patientId) as $r) {
            $f = mh_ph_fields($r, ['bp' => 'Blood pressure', 'temp' => 'Temperature', 'pulse' => 'Pulse', 'resp_rate' => 'Respiratory rate',
                                   'spo2' => 'Oxygen (SpO2)', 'weight' => 'Weight', 'height' => 'Height', 'notes' => 'Notes']);
            $items[] = ['type' => 'Vital Signs', 'date' => substr((string) $r['recorded_at'], 0, 10), 'color' => [20, 184, 166],
                        'attending' => mh_ph_staff($r['sf'], $r['sl']), 'fields' => $f,
                        'time' => substr((string) $r['recorded_at'], 11, 5)];
        }

        foreach (mh_ph_rows($conn, "SELECT d.*, s.first_name AS sf, s.last_name AS sl FROM dental_records d LEFT JOIN staff s ON d.dentist_id = s.id WHERE d.patient_id = ?", $patientId) as $r) {
            $f = mh_ph_fields($r, ['procedure_done' => 'Procedure', 'tooth_number' => 'Tooth no.', 'notes' => 'Notes']);
            $items[] = ['type' => 'Dental Clinic', 'date' => $r['visit_date'], 'color' => [245, 158, 11],
                        'attending' => mh_ph_staff($r['sf'], $r['sl'], 'Dr.'), 'fields' => $f];
        }

        foreach (mh_ph_rows($conn, "SELECT m.*, s.first_name AS sf, s.last_name AS sl FROM maternal_records m LEFT JOIN staff s ON m.midwife_id = s.id WHERE m.patient_id = ?", $patientId) as $r) {
            $f = mh_ph_fields($r, ['aog' => 'Age of gestation', 'blood_pressure' => 'Blood pressure', 'weight' => 'Weight', 'height' => 'Height', 'bmi' => 'BMI',
                                   'fh_fht' => 'Fundal height / FHT', 'gravida' => 'Gravida', 'para' => 'Para', 'lmp' => 'LMP', 'edc' => 'Expected delivery',
                                   'tt1_date' => 'TT1', 'tt2_date' => 'TT2', 'tt3_date' => 'TT3', 'tt4_date' => 'TT4', 'tt5_date' => 'TT5',
                                   'notes' => 'Notes', 'next_visit_date' => 'Next visit']);
            $items[] = ['type' => 'Prenatal Care', 'date' => $r['visit_date'], 'color' => [236, 72, 153],
                        'attending' => mh_ph_staff($r['sf'], $r['sl'], 'MW.'), 'fields' => $f];
        }

        foreach (mh_ph_rows($conn, "SELECT c.*, s.first_name AS sf, s.last_name AS sl FROM child_immunization_records c LEFT JOIN staff s ON c.attending_staff_id = s.id WHERE c.patient_id = ?", $patientId) as $r) {
            $f = mh_ph_fields($r, ['bcg_date' => 'BCG', 'hep_b_24hrs_date' => 'Hepatitis B (24 hrs)', 'penta_1_opv_1_date' => 'Penta 1 / OPV 1',
                                   'penta_2_opv_2_date' => 'Penta 2 / OPV 2', 'penta_opv_3_date' => 'Penta 3 / OPV 3',
                                   'pcv_1_date' => 'PCV 1', 'pcv_2_date' => 'PCV 2', 'pcv_3_date' => 'PCV 3', 'mr_date' => 'MR', 'mmr_date' => 'MMR',
                                   'others_immunization' => 'Other immunizations', 'next_visit_date' => 'Next visit',
                                   'birth_weight' => 'Birth weight', 'birth_height' => 'Birth height', 'birth_place' => 'Place of birth',
                                   'type_of_delivery' => 'Type of delivery', 'type_of_breastfeeding' => 'Breastfeeding',
                                   'newborn_screening_date' => 'Newborn screening', 'mother_name' => 'Mother', 'father_name' => 'Father']);
            $items[] = ['type' => 'Child Immunization', 'date' => substr((string) $r['created_at'], 0, 10), 'color' => [168, 85, 247],
                        'attending' => mh_ph_staff($r['sf'], $r['sl']), 'fields' => $f];
        }

        foreach (mh_ph_rows($conn, "SELECT l.* FROM child_consultation_logs l JOIN child_immunization_records c ON c.id = l.child_record_id WHERE c.patient_id = ?", $patientId) as $r) {
            $f = mh_ph_fields($r, ['chief_complaints' => 'Chief complaint', 'remarks' => 'Remarks', 'weight' => 'Weight', 'height' => 'Height', 'temp' => 'Temperature']);
            $items[] = ['type' => 'Child Check-up', 'date' => $r['consultation_date'], 'color' => [217, 70, 239],
                        'attending' => 'Child immunization staff', 'fields' => $f];
        }

        usort($items, function ($a, $b) {
            $d = strcmp((string) $b['date'], (string) $a['date']);
            return $d !== 0 ? $d : strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? ''));
        });
        return $items;
    }
}
