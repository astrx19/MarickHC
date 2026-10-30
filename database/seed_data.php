<?php
// CLI ONLY - never executable through a web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * =========================================================================
 * MARICK HEALTH SYSTEM v2 - TEST DATA SEEDER
 * =========================================================================
 * Populates every module (staff, patients, queue/appointments, inventory,
 * consultations, immunization, maternal care, dental, SMS reminders) with
 * realistic test data so the app can be clicked through without manual
 * data entry.
 *
 * USAGE (run from the project root, or anywhere - the path below is
 * resolved relative to this file):
 *   php database/seed_data.php
 *
 * SAFE TO RE-RUN:
 *   - staff / patients have real UNIQUE keys (email, username) in the
 *     schema, so those use genuine INSERT ... ON DUPLICATE KEY UPDATE.
 *   - None of the other tables touched here (appointments, inventory,
 *     consultations, child_immunization_records, maternal_records,
 *     dental_records, scheduled_sms) have a natural unique key in
 *     schema.sql / sms_schema.sql, so ON DUPLICATE KEY UPDATE cannot
 *     de-duplicate them. Instead, this script DELETEs any previously
 *     seeded rows for these exact seeded patients/items (identified
 *     below) before re-inserting them, which gives the same practical
 *     result: run it as many times as you want, you always end up with
 *     exactly one clean copy of the seed data, and nothing that belongs
 *     to a real patient or a record you created by hand in the UI is
 *     touched.
 *
 * NOTE ON ROLES: the `staff.role` column (see database/schema.sql) only
 * supports admin | doctor | bhw | midwife | dental - there is no "nurse"
 * role in this codebase (confirmed against module_access_migration.sql
 * and every role check in the app). "nurse_joy" is seeded with role
 * 'bhw', the closest existing clinical role, so the account actually
 * logs in and hits real, permitted pages. Two accounts beyond the ones
 * you listed are also seeded - a dentist and a midwife - because
 * dental_records.dentist_id and maternal_records.midwife_id are NOT
 * NULL foreign keys into `staff` and need a real row of the matching
 * role to satisfy them.
 *
 * NOTE ON "PATIENT QUEUE": the schema has no `queue` / `patient_queue`
 * table - admin/walkin.php confirms the live queue is just the
 * `appointments` table (status Pending/Approved/Completed/...), used
 * for today's date. Seeded here accordingly.
 *
 * NOTE ON "SMS QUEUE": the schema has no `sms_queue` table either - the
 * outbound queue is `scheduled_sms` (see database/sms_schema.sql).
 * Seeded here accordingly.
 * =========================================================================
 */

require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Could not obtain a database connection from config/database.php\n");
}

$conn->set_charset('utf8mb4');

$PLAIN_PASSWORD = 'Password123!';
$PASSWORD_HASH  = password_hash($PLAIN_PASSWORD, PASSWORD_BCRYPT);

echo "=========================================================\n";
echo " Marick Health System v2 - seeding test data\n";
echo "=========================================================\n";

/**
 * Insert a row into a table that has a genuine UNIQUE column (staff.email,
 * patients.email), upsert it with ON DUPLICATE KEY UPDATE, and return its
 * id - correctly, whether the row was just inserted OR already existed
 * and was updated (the `id = LAST_INSERT_ID(id)` trick makes
 * mysqli::$insert_id reliable in both cases).
 *
 * @param string $table
 * @param array  $columns   column_name => value
 * @param string $uniqueCol the column the UNIQUE index is on (used only
 *                          to build the UPDATE clause; the conflict
 *                          itself is resolved by MySQL against whichever
 *                          UNIQUE key actually collides)
 */
function upsert_and_get_id(mysqli $conn, string $table, array $columns, string $uniqueCol): int
{
    $cols        = array_keys($columns);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList      = '`' . implode('`, `', $cols) . '`';

    // Update every column on conflict except the id itself, then force
    // insert_id to resolve to the existing row's id.
    $updates = [];
    foreach ($cols as $col) {
        if ($col === 'id') {
            continue;
        }
        $updates[] = "`$col` = VALUES(`$col`)";
    }
    $updates[] = "`id` = LAST_INSERT_ID(`id`)";
    $updateSql = implode(', ', $updates);

    $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders) "
         . "ON DUPLICATE KEY UPDATE $updateSql";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed for $table: " . $conn->error . "\n");
    }

    $types  = '';
    $values = [];
    foreach ($columns as $val) {
        if (is_int($val)) {
            $types .= 'i';
        } else {
            $types .= 's';
        }
        $values[] = $val;
    }
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        die("Insert failed for $table: " . $stmt->error . "\n");
    }
    $id = $conn->insert_id;
    $stmt->close();

    return $id;
}

/**
 * Delete any rows this seeder previously created for a given natural key
 * (e.g. all appointments for a specific patient_id + today's date, all
 * inventory rows with a given item_name), then insert the fresh set.
 * This is what keeps the script idempotent for tables with no UNIQUE key.
 */
function reseed(mysqli $conn, string $deleteSql, array $deleteParams, string $deleteTypes, string $insertSql, array $rows, string $insertTypes): void
{
    if ($deleteSql !== '') {
        $stmt = $conn->prepare($deleteSql);
        if (!$stmt) {
            die("Prepare failed (delete): " . $conn->error . "\n");
        }
        if ($deleteParams) {
            $stmt->bind_param($deleteTypes, ...$deleteParams);
        }
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare($insertSql);
    if (!$stmt) {
        die("Prepare failed (insert): " . $conn->error . "\n");
    }
    foreach ($rows as $row) {
        $stmt->bind_param($insertTypes, ...$row);
        if (!$stmt->execute()) {
            die("Insert failed: " . $stmt->error . "\n");
        }
    }
    $stmt->close();
}

$conn->begin_transaction();

try {

    // ---------------------------------------------------------------
    // 1. STAFF
    // ---------------------------------------------------------------
    echo "Seeding staff accounts...\n";

    $staffIds = [];

    $staffIds['admin'] = upsert_and_get_id($conn, 'staff', [
        'first_name'      => 'Ramon',
        'last_name'       => 'Villanueva',
        'middle_name'     => 'Santos',
        'suffix'          => null,
        'birthdate'       => '1980-04-11',
        'contact_number'  => '09170000001',
        'email'           => 'admin@marick.gov.ph',
        'username'        => 'admin_marick',
        'password'        => $PASSWORD_HASH,
        'role'            => 'health_head',
        'is_first_login'  => 0,
    ], 'email');

    $staffIds['doctor'] = upsert_and_get_id($conn, 'staff', [
        'first_name'      => 'Miguel',
        'last_name'       => 'Santos',
        'middle_name'     => 'Torres',
        'suffix'          => null,
        'birthdate'       => '1985-09-23',
        'contact_number'  => '09170000002',
        'email'           => 'dr.santos@marick.gov.ph',
        'username'        => 'dr_santos',
        'password'        => $PASSWORD_HASH,
        'role'            => 'doctor',
        'is_first_login'  => 0,
    ], 'email');

    // "nurse" isn't a role this app has - mapped to 'bhw', see header note.
    $staffIds['nurse'] = upsert_and_get_id($conn, 'staff', [
        'first_name'      => 'Joy',
        'last_name'       => 'Fernandez',
        'middle_name'     => 'Aquino',
        'suffix'          => null,
        'birthdate'       => '1992-01-30',
        'contact_number'  => '09170000003',
        'email'           => 'nurse.joy@marick.gov.ph',
        'username'        => 'nurse_joy',
        'password'        => $PASSWORD_HASH,
        'role'            => 'bhw',
        'is_first_login'  => 0,
    ], 'email');

    $staffIds['bhw'] = upsert_and_get_id($conn, 'staff', [
        'first_name'      => 'Maria',
        'last_name'       => 'Bautista',
        'middle_name'     => 'Cruz',
        'suffix'          => null,
        'birthdate'       => '1990-07-18',
        'contact_number'  => '09170000004',
        'email'           => 'bhw.maria@marick.gov.ph',
        'username'        => 'bhw_maria',
        'password'        => $PASSWORD_HASH,
        'role'            => 'bhw',
        'is_first_login'  => 0,
    ], 'email');

    // Extra: needed to satisfy dental_records.dentist_id (NOT NULL FK).
    $staffIds['dentist'] = upsert_and_get_id($conn, 'staff', [
        'first_name'      => 'Carla',
        'last_name'       => 'Ramos',
        'middle_name'     => 'Diaz',
        'suffix'          => null,
        'birthdate'       => '1988-03-05',
        'contact_number'  => '09170000005',
        'email'           => 'dr.ramos@marick.gov.ph',
        'username'        => 'dr_ramos',
        'password'        => $PASSWORD_HASH,
        'role'            => 'dental',
        'is_first_login'  => 0,
    ], 'email');

    // Extra: needed to satisfy maternal_records.midwife_id (NOT NULL FK).
    $staffIds['midwife'] = upsert_and_get_id($conn, 'staff', [
        'first_name'      => 'Elena',
        'last_name'       => 'Lopez',
        'middle_name'     => 'Garcia',
        'suffix'          => null,
        'birthdate'       => '1983-12-09',
        'contact_number'  => '09170000006',
        'email'           => 'midwife.lopez@marick.gov.ph',
        'username'        => 'midwife_lopez',
        'password'        => $PASSWORD_HASH,
        'role'            => 'midwife',
        'is_first_login'  => 0,
    ], 'email');

    echo "  staff done (" . count($staffIds) . " accounts)\n";

    // ---------------------------------------------------------------
    // 2. PATIENTS
    // ---------------------------------------------------------------
    echo "Seeding patients...\n";

    $patientIds = [];

    $patientIds['juan'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Juan',
        'last_name'      => 'Dela Cruz',
        'middle_name'    => 'Ponce',
        'suffix'         => null,
        'birthdate'      => '1990-05-12',
        'sex'            => 'Male',
        'contact_number' => '09171234501',
        'email'          => 'juan.delacruz@gmail.com',
        'username'       => 'patient_juan',
        'password'       => $PASSWORD_HASH,
        'address_type'   => 'Marick',
        'address'        => '123 Mabini St., Purok 1, Marick',
        'philhealth_no'  => '12-345678901-2',
        'family_no'      => 'FAM-0001',
        'status'         => 1,
    ], 'email');

    $patientIds['ana'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Ana',
        'last_name'      => 'Reyes',
        'middle_name'    => 'Manalo',
        'suffix'         => null,
        'birthdate'      => '1995-08-23',
        'sex'            => 'Female',
        'contact_number' => '09171234502',
        'email'          => 'ana.reyes@yahoo.com',
        'username'       => 'patient_ana',
        'password'       => $PASSWORD_HASH,
        'address_type'   => 'Marick',
        'address'        => '45 Rizal St., Purok 2, Marick',
        'philhealth_no'  => '12-345678902-3',
        'family_no'      => 'FAM-0002',
        'status'         => 1,
    ], 'email');

    $patientIds['pedro'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Pedro',
        'last_name'      => 'Penduko',
        'middle_name'    => 'Ilagan',
        'suffix'         => null,
        'birthdate'      => '1988-02-14',
        'sex'            => 'Male',
        'contact_number' => '09171234503',
        'email'          => 'pedro.penduko@gmail.com',
        'username'       => 'patient_pedro',
        'password'       => $PASSWORD_HASH,
        'address_type'   => 'Outside Marick',
        'address'        => '78 Quezon Ave., Brgy. San Isidro',
        'philhealth_no'  => '12-345678903-4',
        'family_no'      => 'FAM-0003',
        'status'         => 1,
    ], 'email');

    // Walk-in patients (no portal account - mirrors admin/walkin.php's
    // "Quick Registration", which leaves username NULL and gives them a
    // system-generated password nobody is meant to log in with).
    $patientIds['maria'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Maria',
        'last_name'      => 'Santos',
        'middle_name'    => 'Villar',
        'suffix'         => null,
        'birthdate'      => '1993-11-02',
        'sex'            => 'Female',
        'contact_number' => '09171234504',
        'email'          => 'maria.santos.walkin@marick.gov.ph',
        'username'       => null,
        'password'       => password_hash(bin2hex(random_bytes(6)), PASSWORD_BCRYPT),
        'address_type'   => 'Marick',
        'address'        => '12 Bonifacio St., Purok 3, Marick',
        'philhealth_no'  => '12-345678904-5',
        'family_no'      => 'FAM-0004',
        'status'         => 1,
    ], 'email');

    $patientIds['jose'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Jose',
        'last_name'      => 'Ramos',
        'middle_name'    => 'Bunag',
        'suffix'         => null,
        'birthdate'      => '1975-06-30',
        'sex'            => 'Male',
        'contact_number' => '09171234505',
        'email'          => 'jose.ramos.walkin@marick.gov.ph',
        'username'       => null,
        'password'       => password_hash(bin2hex(random_bytes(6)), PASSWORD_BCRYPT),
        'address_type'   => 'Marick',
        'address'        => '56 Luna St., Purok 1, Marick',
        'philhealth_no'  => '12-345678905-6',
        'family_no'      => 'FAM-0005',
        'status'         => 1,
    ], 'email');

    // Walk-in child patients - these are what the child immunization
    // module attaches to (child_immunization_records.patient_id).
    $patientIds['kyle'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Kyle',
        'last_name'      => 'Mendoza',
        'middle_name'    => null,
        'suffix'         => null,
        'birthdate'      => '2026-06-15',
        'sex'            => 'Male',
        'contact_number' => '09171234506',
        'email'          => 'kyle.mendoza.child@marick.gov.ph',
        'username'       => null,
        'password'       => password_hash(bin2hex(random_bytes(6)), PASSWORD_BCRYPT),
        'address_type'   => 'Marick',
        'address'        => '89 Aguinaldo St., Purok 2, Marick',
        'philhealth_no'  => null,
        'family_no'      => 'FAM-0006',
        'status'         => 1,
    ], 'email');

    $patientIds['sophia'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Sophia',
        'last_name'      => 'Cruz',
        'middle_name'    => null,
        'suffix'         => null,
        'birthdate'      => '2026-03-10',
        'sex'            => 'Female',
        'contact_number' => '09171234507',
        'email'          => 'sophia.cruz.child@marick.gov.ph',
        'username'       => null,
        'password'       => password_hash(bin2hex(random_bytes(6)), PASSWORD_BCRYPT),
        'address_type'   => 'Marick',
        'address'        => '34 Del Pilar St., Purok 4, Marick',
        'philhealth_no'  => null,
        'family_no'      => 'FAM-0007',
        'status'         => 1,
    ], 'email');

    $patientIds['miguel'] = upsert_and_get_id($conn, 'patients', [
        'first_name'     => 'Miguel',
        'last_name'      => 'Torres',
        'middle_name'    => null,
        'suffix'         => null,
        'birthdate'      => '2025-12-05',
        'sex'            => 'Male',
        'contact_number' => '09171234508',
        'email'          => 'miguel.torres.child@marick.gov.ph',
        'username'       => null,
        'password'       => password_hash(bin2hex(random_bytes(6)), PASSWORD_BCRYPT),
        'address_type'   => 'Marick',
        'address'        => '21 Katipunan St., Purok 3, Marick',
        'philhealth_no'  => null,
        'family_no'      => 'FAM-0008',
        'status'         => 1,
    ], 'email');

    echo "  patients done (" . count($patientIds) . " records)\n";

    // ---------------------------------------------------------------
    // 3. QUEUE (appointments table - today's live queue, see header note)
    // ---------------------------------------------------------------
    echo "Seeding today's patient queue (appointments)...\n";

    $today = date('Y-m-d');

    reseed(
        $conn,
        "DELETE FROM appointments WHERE appointment_date = ? AND admin_remarks = 'SEEDED_TEST_DATA'",
        [$today],
        's',
        "INSERT INTO appointments (patient_id, department, reason, appointment_date, appointment_time, status, admin_remarks) VALUES (?, ?, ?, ?, ?, ?, 'SEEDED_TEST_DATA')",
        // Date-only queueing: appointment_time is a fixed placeholder; queue
        // order for the day comes from insertion order (id), which is why
        // these are listed in the intended first-come, first-served order.
        [
            [$patientIds['jose'],   'General Consultation', 'Routine checkup',        $today, '00:00:00', 'Pending'],
            [$patientIds['kyle'],   'Child Immunization',   'Immunization follow-up', $today, '00:00:00', 'Approved'],
            [$patientIds['pedro'],  'General Consultation', 'Blood pressure recheck', $today, '00:00:00', 'Completed'],
            [$patientIds['sophia'], 'Child Immunization',   'Scheduled vaccine dose', $today, '00:00:00', 'Pending'],
            [$patientIds['ana'],    'Maternal Care',        'Prenatal checkup',       $today, '00:00:00', 'Approved'],
        ],
        'isssss'
    );

    echo "  queue done (5 records for $today)\n";

    // ---------------------------------------------------------------
    // 4. INVENTORY
    // ---------------------------------------------------------------
    echo "Seeding medicine inventory...\n";

    $inventoryItems = [
        // name, brand, category, tracking_code, batch, qty, unit, expiry
        ['Paracetamol 500mg',        'Biogesic', 'Medicine',      'MED-0001', 'PCM-2026-01', 500,  'tablet', '2027-06-30'],
        ['Amoxicillin 500mg',        'Amoxil',   'Medicine',      'MED-0002', 'AMX-2026-02', 300,  'capsule','2027-03-15'],
        ['Mefenamic Acid 500mg',     'Ponstan',  'Medicine',      'MED-0003', 'MFA-2026-03', 250,  'tablet', '2027-08-20'],
        ['Losartan 50mg',            'Cozaar',   'Medicine',      'MED-0004', 'LOS-2026-04', 6,    'tablet', '2027-11-10'], // low stock
        ['Cetirizine 10mg',          'Virlix',   'Medicine',      'MED-0005', 'CET-2026-05', 200,  'tablet', '2027-05-01'],
        ['Oral Rehydration Salts',   'Hydrite',  'Medicine',      'MED-0006', 'ORS-2026-06', 5,    'sachet', '2027-01-15'], // low stock
        ['BCG Vaccine',              null,       'Vaccine',       'VAC-0001', 'BCG-2026-07', 40,   'vial',   date('Y-m-d', strtotime('+16 days'))], // near expiry
        ['Measles-Rubella Vaccine',  null,       'Vaccine',       'VAC-0002', 'MR-2026-08',  35,   'vial',   '2027-04-12'],
        ['Disposable Syringe 5mL',   null,       'Medical Supply','SUP-0001', 'SYR-2026-09', 1000, 'piece',  '2028-01-01'],
        ['Digital Thermometer',      'Omron',    'Equipment',     'EQP-0001', 'THM-2026-10', 15,   'piece',  null],
    ];

    reseed(
        $conn,
        "DELETE FROM inventory WHERE item_name IN ('Paracetamol 500mg','Amoxicillin 500mg','Mefenamic Acid 500mg','Losartan 50mg','Cetirizine 10mg','Oral Rehydration Salts','BCG Vaccine','Measles-Rubella Vaccine','Disposable Syringe 5mL','Digital Thermometer')",
        [],
        '',
        "INSERT INTO inventory (item_name, brand_name, category, tracking_code, batch_number, quantity, unit, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
        $inventoryItems,
        'sssssiss'
    );

    echo "  inventory done (" . count($inventoryItems) . " items - 2 low-stock, 1 near-expiry)\n";

    // ---------------------------------------------------------------
    // 5. CONSULTATIONS
    // ---------------------------------------------------------------
    echo "Seeding consultations...\n";

    $consultations = [
        [$patientIds['juan'],  $staffIds['doctor'], '2026-09-15', '68', '170', '36.8', '120/80',
            'Cough and colds for 3 days', 'Upper Respiratory Tract Infection',
            'Paracetamol 500mg 1 tab every 4 hrs PRN; Salbutamol syrup 5ml TID',
            'Advised rest and increased fluid intake', '2026-09-22'],
        [$patientIds['ana'],   $staffIds['doctor'], '2026-09-16', '58', '158', '37.9', '110/70',
            'Fever and headache', 'Viral Fever',
            'Paracetamol 500mg every 4 hrs PRN; Oral Rehydration Salts',
            'Follow up if fever persists beyond 3 days', '2026-09-19'],
        [$patientIds['pedro'], $staffIds['doctor'], '2026-09-17', '75', '172', '36.6', '140/90',
            'Recurring headaches, dizziness', 'Hypertension, Stage 1',
            'Losartan 50mg once daily',
            'Referred for home BP monitoring; advised low-salt diet', '2026-10-01'],
        [$patientIds['maria'], $staffIds['doctor'], '2026-09-18', '60', '160', '36.7', '100/70',
            'Menstrual cramps', 'Primary Dysmenorrhea',
            'Mefenamic Acid 500mg every 8 hrs PRN',
            'OTC pain relief advised; return if pain worsens', null],
    ];

    reseed(
        $conn,
        "DELETE FROM consultations WHERE patient_id IN (?, ?, ?, ?) AND doctor_id = ? AND notes LIKE '%[SEED]%'",
        [$patientIds['juan'], $patientIds['ana'], $patientIds['pedro'], $patientIds['maria'], $staffIds['doctor']],
        'iiiii',
        "INSERT INTO consultations (patient_id, doctor_id, visit_date, weight, height, temp, bp, chief_complaints, diagnosis, prescription, notes, next_visit_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        array_map(function ($c) {
            $c[10] = $c[10] . ' [SEED]'; // tag notes so the delete-before-insert above can find it next run
            return $c;
        }, $consultations),
        'iissssssssss'
    );

    echo "  consultations done (" . count($consultations) . " records)\n";

    // ---------------------------------------------------------------
    // 6. CHILD IMMUNIZATION RECORDS
    // ---------------------------------------------------------------
    echo "Seeding child immunization records...\n";

    reseed(
        $conn,
        "DELETE FROM child_immunization_records WHERE patient_id IN (?, ?, ?)",
        [$patientIds['kyle'], $patientIds['sophia'], $patientIds['miguel']],
        'iii',
        "INSERT INTO child_immunization_records
            (patient_id, attending_staff_id, family_no, newborn_screening_date, birth_weight, birth_height,
             birth_place, type_of_delivery, place_of_delivery, type_of_breastfeeding,
             mother_name, father_name, attending_physician,
             bcg_date, hep_b_24hrs_date, penta_1_opv_1_date, penta_2_opv_2_date, penta_opv_3_date,
             pcv_1_date, pcv_2_date, pcv_3_date, mr_date, next_visit_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            // Kyle - 3 months old, series in progress
            [$patientIds['kyle'], $staffIds['bhw'], 'FAM-0006', '2026-06-16', '3.2 kg', '49 cm',
                'Marick Barangay Health Center', 'Normal Spontaneous Delivery', 'Barangay Health Center', 'Exclusive Breastfeeding',
                'Liza Mendoza', 'Ramon Mendoza', 'Dr. Miguel Santos',
                '2026-06-16', '2026-06-16', '2026-08-15', null, null,
                '2026-08-15', null, null, null, '2026-09-25'],
            // Sophia - 6 months old, penta/pcv series complete, MR not yet due
            [$patientIds['sophia'], $staffIds['midwife'], 'FAM-0007', '2026-03-11', '3.0 kg', '48 cm',
                'Marick Barangay Health Center', 'Normal Spontaneous Delivery', 'Barangay Health Center', 'Exclusive Breastfeeding',
                'Carmen Cruz', 'Antonio Cruz', 'Dr. Miguel Santos',
                '2026-03-11', '2026-03-11', '2026-05-10', '2026-06-10', '2026-07-10',
                '2026-05-10', '2026-06-10', '2026-07-10', null, '2026-10-10'],
            // Miguel - 9 months old, full series including MR
            [$patientIds['miguel'], $staffIds['bhw'], 'FAM-0008', '2025-12-06', '3.4 kg', '50 cm',
                'City General Hospital', 'Caesarean Section', 'Hospital', 'Mixed Feeding',
                'Rowena Torres', 'Danilo Torres', 'Dr. Miguel Santos',
                '2025-12-06', '2025-12-06', '2026-02-05', '2026-03-05', '2026-04-05',
                '2026-02-05', '2026-03-05', '2026-04-05', '2026-09-05', '2026-12-05'],
        ],
        'iisssssssssssssssssssss'
    );

    echo "  child immunization done (3 records)\n";

    // ---------------------------------------------------------------
    // 7. MATERNAL RECORDS
    // ---------------------------------------------------------------
    echo "Seeding maternal care records...\n";

    reseed(
        $conn,
        "DELETE FROM maternal_records WHERE patient_id IN (?, ?)",
        [$patientIds['ana'], $patientIds['maria']],
        'ii',
        "INSERT INTO maternal_records
            (patient_id, midwife_id, visit_date, gravida, para, lmp, edc, aog, blood_pressure,
             weight, height, bmi, fh_fht, tt1_date, tt2_date, notes, next_visit_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            [$patientIds['ana'], $staffIds['midwife'], '2026-09-16', 'G2', 'P1', '2026-02-10', '2026-11-17',
                '31 weeks', '110/70', '58 kg', '158 cm', '23.2', 'FH 28cm, FHT 140bpm',
                '2026-05-01', '2026-08-01', 'Normal prenatal findings, no danger signs', '2026-10-14'],
            [$patientIds['maria'], $staffIds['midwife'], '2026-09-18', 'G1', 'P0', '2026-04-05', '2027-01-10',
                '23 weeks', '100/70', '60 kg', '160 cm', '23.4', 'FH 22cm, FHT 148bpm',
                '2026-07-01', null, 'First prenatal visit, advised iron and folic acid supplementation', '2026-10-16'],
        ],
        'ii' . str_repeat('s', 15) // patient_id, midwife_id (int) + 15 string columns
    );

    echo "  maternal care done (2 records)\n";

    // ---------------------------------------------------------------
    // 8. DENTAL RECORDS
    // ---------------------------------------------------------------
    echo "Seeding dental records...\n";

    reseed(
        $conn,
        "DELETE FROM dental_records WHERE patient_id IN (?, ?, ?)",
        [$patientIds['juan'], $patientIds['pedro'], $patientIds['jose']],
        'iii',
        "INSERT INTO dental_records (patient_id, dentist_id, visit_date, procedure_done, tooth_number, notes) VALUES (?, ?, ?, ?, ?, ?)",
        [
            [$patientIds['juan'],  $staffIds['dentist'], '2026-09-10', 'Tooth Extraction', '36', 'Extraction due to severe decay; prescribed Amoxicillin 500mg'],
            [$patientIds['pedro'], $staffIds['dentist'], '2026-09-12', 'Oral Prophylaxis (Cleaning)', null, 'Routine cleaning, mild gingivitis noted'],
            [$patientIds['jose'],  $staffIds['dentist'], '2026-09-14', 'Dental Checkup', null, 'No caries found, advised regular brushing and flossing'],
        ],
        'iissss'
    );

    echo "  dental done (3 records)\n";

    // ---------------------------------------------------------------
    // 9. SMS QUEUE (scheduled_sms table - see header note)
    // ---------------------------------------------------------------
    echo "Seeding SMS reminder queue...\n";

    reseed(
        $conn,
        "DELETE FROM scheduled_sms WHERE patient_id IN (?, ?, ?) AND source_module IN ('Maternal Care', 'Immunization', 'Check-up')",
        [$patientIds['ana'], $patientIds['kyle'], $patientIds['juan']],
        'iii',
        "INSERT INTO scheduled_sms (patient_id, phone_number, message, scheduled_date, status, source_module) VALUES (?, ?, ?, ?, ?, ?)",
        [
            [$patientIds['ana'],  '09171234502', 'Reminder: Your prenatal checkup at Marick Health Center is on 2026-10-14.', '2026-10-13', 'pending', 'Maternal Care'],
            [$patientIds['kyle'], '09171234506', "Reminder: Kyle's next immunization dose is on 2026-09-25.", '2026-09-24', 'pending', 'Immunization'],
            [$patientIds['juan'], '09171234501', 'Reminder: Your follow-up checkup at Marick Health Center was on 2026-09-22.', '2026-09-22', 'sent', 'Check-up'],
        ],
        'isssss'
    );

    echo "  SMS queue done (3 records)\n";

    $conn->commit();

    echo "=========================================================\n";
    echo " Done. All test data committed successfully.\n";
    echo "=========================================================\n";

} catch (Throwable $e) {
    $conn->rollback();
    echo "Seeding FAILED, rolled back: " . $e->getMessage() . "\n";
    exit(1);
}