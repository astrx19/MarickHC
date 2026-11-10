<?php
/**
 * ============================================================
 * MARICK HEALTH CENTER - DATABASE SEEDER
 * ============================================================
 * Populates every module with realistic, clinically-sound
 * Philippine mock data for testing UI, queues, and Disease
 * Statistics dashboard charts.
 *
 * ACCESS: Health Head only (or run via CLI).
 * CLI usage: /Applications/XAMPP/xamppfiles/bin/php admin/db_seeder.php
 *
 * SAFE TO RE-RUN: Skips patients/staff with duplicate emails.
 * ============================================================
 */

define('MH_SEEDER_RUNNING', true);

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/../includes/admin_auth.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/settings_helper.php';
    require_once __DIR__ . '/../includes/disease_stats.php';
    require_once __DIR__ . '/../includes/queue_helper.php';
    require_once __DIR__ . '/../includes/dental_helper.php';

    // Health Head only
    if (!function_exists('mh_is_health_head') || !mh_is_health_head($_SESSION['role'] ?? '')) {
        http_response_code(403);
        die('<h2>Access Denied. Health Head only.</h2>');
    }

    // CSRF check on POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!mh_csrf_check()) {
            die('<h2>CSRF token mismatch. Go back and try again.</h2>');
        }
    }
} else {
    // CLI mode: load bare minimum
    chdir(dirname(__DIR__));
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/settings_helper.php';
    require_once __DIR__ . '/../includes/disease_stats.php';
    require_once __DIR__ . '/../includes/queue_helper.php';
    require_once __DIR__ . '/../includes/dental_helper.php';
    if (!function_exists('logActivity')) {
        function logActivity($conn, $userId, $role, $action, $detail) { return true; }
    }
}

// ─── SEEDER FUNCTIONS ──────────────────────────────────────────────────────

class MhSeeder
{
    private mysqli $db;
    private array $log = [];
    private array $seededPatientIds = [];
    private int $staffId = 1;

    public function __construct(mysqli $conn)
    {
        $this->db = $conn;
        mh_disease_ensure_tables($conn);
        mh_ensure_queue_schema($conn);
        mh_dental_ensure_tables($conn);
        mh_settings_boot($conn);
    }

    // ─── LOGGING ───────────────────────────────────────────────────────────

    private function ok(string $msg): void
    {
        $this->log[] = ['ok', $msg];
        if (PHP_SAPI === 'cli') {
            echo "  [OK] $msg\n";
        }
    }

    private function skip(string $msg): void
    {
        $this->log[] = ['skip', $msg];
        if (PHP_SAPI === 'cli') {
            echo "  · $msg\n";
        }
    }

    private function err(string $msg): void
    {
        $this->log[] = ['err', $msg];
        if (PHP_SAPI === 'cli') {
            echo "  [ERR] $msg\n";
        }
    }

    public function getLogs(): array { return $this->log; }

    // ─── HELPERS ───────────────────────────────────────────────────────────

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    private function randDaysAgo(int $min, int $max): string
    {
        return $this->daysAgo(rand($min, $max));
    }

    private function getOrFindStaffId(): int
    {
        $res = $this->db->query("SELECT id FROM staff ORDER BY id LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return (int) $row['id'];
        }
        return 1;
    }

    // ─── 1. PATIENTS ───────────────────────────────────────────────────────

    public function seedPatients(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[1/6] Patients...\n" : '';

        $this->staffId = $this->getOrFindStaffId();

        $patients = [
            // Babies & Infants (under 1 yr old - months formatting)
            ['Carlo',   'Santos',  'Reyes',   '', '2026-06-15', 'Male',   '09171234526', 'marick.seed.026@test.local', 'Marick',          '10 Aida St.'],
            ['Chloe',   'Garcia',  'Torres',  '', '2026-03-10', 'Female', '09181234527', 'marick.seed.027@test.local', 'Marick',          'Purok 1, Sto. Domingo'],
            ['Mateo',   'Villanueva','Cruz',  '', '2026-10-05', 'Male',   '09191234528', 'marick.seed.028@test.local', 'Marick',          'Zone 1, Sto. Domingo'],
            ['Althea',  'Pascual', 'Dela Torre','', '2026-05-20', 'Female', '09201234529', 'marick.seed.029@test.local', 'Outside Marick', 'Blk 2 Lot 14, Cainta, Rizal'],
            ['Lucas',   'Mendoza', 'Flores',  '', '2026-08-12', 'Male',   '09211234530', 'marick.seed.030@test.local', 'Outside Marick', '45 San Roque, Antipolo, Rizal'],

            // Pediatric children (1-14 yrs)
            ['Mariano', 'Santos', 'Rivera', '', '2023-04-10', 'Male',   '09171234501', 'marick.seed.001@test.local', 'Marick',          '15 Aida St.'],
            ['Kristina', 'Reyes', 'Cruz',  '', '2022-11-25', 'Female',  '09181234502', 'marick.seed.002@test.local', 'Marick',          '7 Cattleya St.'],
            ['Angelo',  'Garcia', 'Lim',   '', '2020-07-14', 'Male',   '09191234503', 'marick.seed.003@test.local', 'Marick',          '3 Cora St.'],
            ['Sofia',   'Torres', 'Dela Cruz', '', '2018-02-28', 'Female', '09201234504', 'marick.seed.004@test.local', 'Marick',       '21 Daffodil St.'],
            ['Liam',    'Villanueva', 'Mendoza', '', '2016-09-05', 'Male', '09211234505', 'marick.seed.005@test.local', 'Marick',      '8 Delia St.'],

            // Young adults (15-35 yrs)
            ['Maria',   'Fernandez', 'Bautista', '', '2001-03-20', 'Female', '09221234506', 'marick.seed.006@test.local', 'Marick',    '5 Emma St.'],
            ['Jose',    'Ramos',   'Aquino',  '', '1999-07-11', 'Male',   '09231234507', 'marick.seed.007@test.local', 'Marick',       '14 Flora St.'],
            ['Andrea',  'Cruz',    'Pascual', '', '2003-12-30', 'Female', '09241234508', 'marick.seed.008@test.local', 'Marick',       '2 Gracia St.'],
            ['Miguel',  'Hernandez', 'Soriano', '', '1997-05-17', 'Male', '09251234509', 'marick.seed.009@test.local', 'Marick',      '9 Irma St.'],
            ['Isabelle','Delos Reyes', 'Tan', '', '2005-08-02', 'Female', '09261234510', 'marick.seed.010@test.local', 'Marick',      '17 Jaguar St.'],
            ['Juan',    'De Leon', 'Flores', '',  '2000-01-15', 'Male',   '09271234511', 'marick.seed.011@test.local', 'Marick',       '6 Joey St.'],
            ['Ana',     'Sy',      'Morales', '', '1998-04-22', 'Female', '09281234512', 'marick.seed.012@test.local', 'Marick',       '11 Leonard St.'],

            // Middle-aged adults (36-59 yrs)
            ['Roberto', 'Gonzales', 'Perez',  '', '1985-06-30', 'Male',   '09291234513', 'marick.seed.013@test.local', 'Marick',      '4 Ritchie St.'],
            ['Teresita', 'Castillo', 'Navarro', '', '1979-11-10', 'Female', '09301234514', 'marick.seed.014@test.local', 'Marick',    '20 Vicenta St.'],
            ['Eduardo', 'Moreno',   'Lopez',  '', '1974-03-14', 'Male',   '09171234515', 'marick.seed.015@test.local', 'Marick',      '13 Aida St.'],
            ['Corazon', 'Dela Cruz', 'Espino', '', '1983-09-08', 'Female', '09181234516', 'marick.seed.016@test.local', 'Marick',     '1 Cattleya St.'],
            ['Renaldo', 'Lim',      'Reyes',   '', '1967-12-25', 'Male',   '09191234517', 'marick.seed.017@test.local', 'Marick',    '30 Cora St.'],

            // Elderly (60+ yrs)
            ['Felicidad', 'Bautista', 'Santos', '', '1958-07-19', 'Female', '09201234518', 'marick.seed.018@test.local', 'Marick',   '19 Daffodil St.'],
            ['Renato',  'Aquino',  'Garcia',   '', '1948-05-01', 'Male',   '09211234519', 'marick.seed.019@test.local', 'Marick',    '12 Delia St.'],
            ['Natividad', 'Pascual', 'Torres',  '', '1953-10-14', 'Female', '09221234520', 'marick.seed.020@test.local', 'Marick',   '25 Emma St.'],

            // Outside Marick residents (for demographics testing)
            ['Carlos',  'Soriano', 'Ramos',   '', '1990-08-29', 'Male',   '09231234521', 'marick.seed.021@test.local', 'Outside Marick', 'Blk 5 Lot 3, Phase 2, Cainta, Rizal'],
            ['Maricel', 'Flores',  'Cruz',    '', '1988-02-14', 'Female', '09241234522', 'marick.seed.022@test.local', 'Outside Marick', '123 Bagong Nayon, Antipolo, Rizal'],
            ['Jonathan','Santos',  'Villarreal', '', '2008-06-20', 'Male', '09251234523', 'marick.seed.023@test.local', 'Outside Marick', '78 Sta. Mesa, Pasig City'],
            ['Luisa',   'Reyes',   'Dela Torre', '', '1965-04-07', 'Female', '09261234524', 'marick.seed.024@test.local', 'Outside Marick', '45 Kapitolyo, Pasig City'],
            ['Ernesto', 'Tan',     'Villanueva', '', '1945-01-30', 'Male', '09271234525', 'marick.seed.025@test.local', 'Outside Marick', '12 San Pedro, Laguna'],
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO patients
                (first_name, last_name, middle_name, suffix, birthdate, sex, contact_number,
                 email, username, password, address_type, address, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        if (!$stmt) {
            $this->err("patients prepare failed: " . $this->db->error);
            return;
        }

        $defaultPw = password_hash('Marick2024!', PASSWORD_BCRYPT);
        $inserted = 0;
        foreach ($patients as $p) {
            [$fn, $ln, $mn, $sfx, $bd, $sex, $contact, $email, $atype, $addr] = $p;
            $username = strtolower($fn[0] . $ln) . rand(10, 99);

            // Skip duplicates
            $chk = $this->db->prepare("SELECT id FROM patients WHERE email = ? LIMIT 1");
            $chk->bind_param('s', $email);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->close();
                $this->skip("Patient $fn $ln already exists, skipping.");
                // Still load their ID for later steps
                $existing = $this->db->prepare("SELECT id FROM patients WHERE email = ? LIMIT 1");
                $existing->bind_param('s', $email);
                $existing->execute();
                $row = $existing->get_result()->fetch_assoc();
                if ($row) {
                    $this->seededPatientIds[] = (int) $row['id'];
                }
                $existing->close();
                continue;
            }
            $chk->close();

            $stmt->bind_param(
                'ssssssssssss',
                $fn, $ln, $mn, $sfx, $bd, $sex, $contact,
                $email, $username, $defaultPw, $atype, $addr
            );
            if ($stmt->execute()) {
                $pid = (int) $this->db->insert_id;
                $this->seededPatientIds[] = $pid;
                $inserted++;
                $this->ok("Patient: $fn $ln (ID #$pid)");
            } else {
                $this->err("Failed to insert $fn $ln: " . $stmt->error);
            }
        }
        $stmt->close();
        $this->ok("Patients seeded: $inserted new, " . (count($this->seededPatientIds) - $inserted) . " existing loaded.");
    }

    // ─── 2. CONSULTATIONS ──────────────────────────────────────────────────

    public function seedConsultations(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[2/6] General Consultations (90-day spread)...\n" : '';

        if (empty($this->seededPatientIds)) {
            $this->err("No patient IDs loaded; skipping consultations.");
            return;
        }

        // Clinically valid diagnoses distributed for disease stats curves
        $diagnosisSets = [
            // Upper Respiratory (high volume, common)
            ['Upper Respiratory Tract Infection', 'Cough, colds, and mild fever for 3 days', '500mg Amoxicillin TID x 7 days, Paracetamol 500mg PRN', 25],
            ['Acute Pharyngitis', 'Sore throat, low-grade fever, difficulty swallowing', 'Amoxicillin 500mg TID x 7 days, Betadine gargle', 20],
            ['Influenza-like Illness', 'High fever 38.9C, body aches, headache, colds', 'Paracetamol 500mg q4-6h PRN, rest and fluids', 20],
            // Gastrointestinal
            ['Acute Gastroenteritis', 'Loose watery stools 5-6 episodes, mild abdominal cramps, vomiting', 'ORS, Loperamide 2mg PRN, Zinc supplementation', 18],
            ['Diarrhea with Vomiting', 'Watery diarrhea, nausea, vomiting, mild fever', 'ORS hydration, Metoclopramide 10mg PRN', 15],
            // Hypertension
            ['Hypertension', 'Persistent headache, dizziness, BP elevated on 3 readings', 'Losartan 50mg OD, lifestyle modification, low-sodium diet', 15],
            ['Hypertension with Dizziness', 'Severe headache, nausea, BP 170/100', 'Amlodipine 5mg OD, Losartan 50mg OD, monitoring', 10],
            // UTI
            ['Urinary Tract Infection', 'Painful urination, increased frequency, lower abdominal discomfort', 'Cotrimoxazole 960mg BID x 5 days, increase fluid intake', 12],
            // Dengue
            ['Dengue Fever', 'High fever 3 days, petechiae, positive tourniquet test, severe headache', 'Paracetamol PRN, hydration, CBC monitoring daily', 8],
            // Dental/Oral
            ['Oral Cavity Disease - Dental Caries', 'Toothache, tooth sensitivity to cold', 'Mefenamic Acid 500mg TID PRN, referral to dental', 10],
            // Asthma
            ['Bronchial Asthma', 'Episodic wheezing, chest tightness, shortness of breath', 'Salbutamol inhaler 2 puffs PRN, Budesonide inhaler OD', 8],
            // Skin
            ['Skin infection - Scabies', 'Generalized pruritus, papular rash worse at night, interdigital burrows', 'Permethrin 5% cream apply overnight x 2 applications', 7],
            // TB
            ['Pulmonary Tuberculosis - presumptive', 'Productive cough >2 weeks, weight loss, night sweats', 'Referral for sputum AFB, CXR, weight monitoring', 5],
            // Pneumonia
            ['Community-acquired Pneumonia', 'High fever, productive cough with yellowish sputum, chest pain, dyspnea', 'Co-amoxiclav 625mg BID x 7 days, Paracetamol PRN', 6],
            // Chickenpox
            ['Varicella / Chickenpox', 'Vesicular rash in various stages, fever, pruritus', 'Acyclovir 800mg 5x daily x 7 days, Calamine lotion', 5],
            // COVID
            ['COVID-19 Infection', 'Fever, cough, loss of taste/smell, fatigue', 'Isolation, Paracetamol PRN, monitoring O2 saturation', 5],
            // Diabetes
            ['Diabetes Mellitus Type 2', 'Increased thirst, frequent urination, blurred vision, FBS elevated', 'Metformin 500mg BID, dietary counseling, glucose monitoring', 7],
        ];

        // Build weighted pool: each diagnosis repeated by its weight
        $pool = [];
        foreach ($diagnosisSets as [$diag, $cc, $rx, $weight]) {
            for ($i = 0; $i < $weight; $i++) {
                $pool[] = [$diag, $cc, $rx];
            }
        }

        // Vitals ranges
        $vitals = [
            ['120/80', 65, 162, 36.5],
            ['130/85', 72, 158, 36.8],
            ['140/90', 78, 170, 36.6],
            ['110/70', 55, 155, 37.2],
            ['150/95', 85, 168, 37.5],
            ['100/65', 48, 150, 36.4],
            ['160/100', 90, 172, 38.0],
            ['125/82', 68, 165, 36.9],
            ['135/88', 75, 160, 36.7],
            ['170/105', 95, 175, 36.5],
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO consultations
                (patient_id, patient_name, doctor_id, visit_date, weight, height, temp, bp,
                 chief_complaints, diagnosis, prescription, notes, next_visit_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            $this->err("consultations prepare failed: " . $this->db->error);
            return;
        }

        // Get all patient names for reference
        $nameMap = [];
        foreach ($this->seededPatientIds as $pid) {
            $r = $this->db->prepare("SELECT first_name, last_name FROM patients WHERE id = ? LIMIT 1");
            $r->bind_param('i', $pid);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            if ($row) {
                $nameMap[$pid] = $row['first_name'] . ' ' . $row['last_name'];
            }
            $r->close();
        }

        $count = 0;
        $totalVisits = 150; // spread across 90 days

        for ($v = 0; $v < $totalVisits; $v++) {
            $pid = $this->seededPatientIds[array_rand($this->seededPatientIds)];
            $pname = $nameMap[$pid] ?? 'Unknown Patient';

            // Weight heavily toward recent 30 days (more data for charts)
            $dayBucket = rand(0, 100);
            if ($dayBucket < 50) {
                $days = rand(1, 30);
            } elseif ($dayBucket < 80) {
                $days = rand(31, 60);
            } else {
                $days = rand(61, 90);
            }
            $visitDate = $this->daysAgo($days);

            [$diag, $cc, $rx] = $pool[array_rand($pool)];
            [$bp, $wt, $ht, $temp] = $vitals[array_rand($vitals)];

            // Vary vitals slightly
            $wtStr   = (string) round($wt + rand(-5, 5) * 0.5, 1);
            $htStr   = (string) ($ht + rand(-5, 5));
            $tempStr = (string) round($temp + rand(-2, 8) * 0.1, 1);
            $notes   = "Routine follow-up recommended in 1-2 weeks. Advised on compliance with medication.";
            $nextVisit = date('Y-m-d', strtotime("$visitDate +14 days"));

            $stmt->bind_param(
                'isissssssssss',
                $pid, $pname, $this->staffId, $visitDate,
                $wtStr, $htStr, $tempStr, $bp,
                $cc, $diag, $rx,
                $notes, $nextVisit
            );
            if ($stmt->execute()) {
                $count++;
            }
        }
        $stmt->close();
        $this->ok("General consultations seeded: $count records across 90 days.");
    }

    // ─── 3. QUEUE / WALK-INS ───────────────────────────────────────────────

    public function seedQueue(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[3/6] Queue & Walk-in Entries...\n" : '';

        if (empty($this->seededPatientIds)) {
            $this->err("No patient IDs; skipping queue.");
            return;
        }

        $departments = [
            'General Consultation',
            'Dental Clinic',
            'Maternal Care',
            'Child Immunization',
        ];
        $statuses = ['Waiting', 'Approved', 'Waiting', 'Approved', 'In Consultation', 'Completed'];

        $today = date('Y-m-d');
        $count = 0;

        foreach ($departments as $dept) {
            $queueNum = 1;
            $patientsForDept = array_slice(
                $this->seededPatientIds,
                rand(0, max(0, count($this->seededPatientIds) - 5)),
                rand(3, 6)
            );

            foreach ($patientsForDept as $pid) {
                $status = $statuses[array_rand($statuses)];
                $reason = match($dept) {
                    'General Consultation' => 'Walk-in general consultation and checkup',
                    'Maternal Care'        => 'Prenatal check-up visit',
                    'Child Immunization'   => 'Child immunization routine schedule',
                    'Dental Clinic'        => 'Dental check-up and oral prophylaxis',
                    default                => 'Walk-in visit',
                };

                // Check if already queued today
                $chk = $this->db->prepare(
                    "SELECT id FROM appointments WHERE patient_id = ? AND department = ? AND appointment_date = ? LIMIT 1"
                );
                $chk->bind_param('iss', $pid, $dept, $today);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    $this->skip("Patient ID $pid already queued in $dept today.");
                    continue;
                }
                $chk->close();

                $st = $this->db->prepare(
                    "INSERT INTO appointments
                        (patient_id, department, appointment_date, appointment_time,
                         reason, status, queue_number)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $time = sprintf('%02d:00:00', rand(8, 16));
                $st->bind_param('isssssi', $pid, $dept, $today, $time, $reason, $status, $queueNum);
                if ($st->execute()) {
                    $count++;
                    $queueNum++;
                    $this->ok("Queue: Patient $pid to $dept queue #" . ($queueNum - 1) . " [$status]");
                } else {
                    $this->skip("Queue insert skip for patient $pid / $dept: " . $st->error);
                }
                $st->close();
            }
        }
        $this->ok("Queue entries seeded: $count entries.");
    }

    // ─── 4. MATERNAL CARE ──────────────────────────────────────────────────

    public function seedMaternalCare(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[4a/6] Maternal Care Records...\n" : '';

        // Use female patients only
        $femaleIds = [];
        foreach ($this->seededPatientIds as $pid) {
            $r = $this->db->prepare(
                "SELECT id, first_name, last_name, sex, birthdate FROM patients WHERE id = ? AND sex = 'Female' LIMIT 1"
            );
            $r->bind_param('i', $pid);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            if ($row) {
                // Only women of childbearing age (15–45)
                $age = (int) floor((time() - strtotime($row['birthdate'])) / (365.25 * 86400));
                if ($age >= 15 && $age <= 45) {
                    $femaleIds[] = ['id' => $pid, 'name' => $row['first_name'] . ' ' . $row['last_name']];
                }
            }
            $r->close();
        }

        if (empty($femaleIds)) {
            $this->skip("No female patients in childbearing age; skipping maternal records.");
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO maternal_records
                (patient_id, patient_name, midwife_id, visit_date, gravida, para,
                 lmp, edc, aog, blood_pressure, weight, height, bmi, fh_fht,
                 tt1_date, notes, next_visit_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            $this->err("maternal_records prepare failed: " . $this->db->error);
            return;
        }

        $count = 0;
        $visitCount = min(count($femaleIds), 10);

        for ($i = 0; $i < $visitCount; $i++) {
            $p = $femaleIds[$i % count($femaleIds)];
            $pid   = $p['id'];
            $pname = $p['name'];

            $days      = rand(5, 60);
            $visitDate = $this->daysAgo($days);
            $gravida   = rand(1, 4);
            $para      = rand(0, $gravida);
            $aog       = rand(10, 36);
            $lmp       = date('Y-m-d', strtotime("$visitDate -" . ($aog * 7) . " days"));
            $edc       = date('Y-m-d', strtotime("$lmp +280 days"));
            $bp        = rand(0, 1) ? '110/70' : '120/80';
            $wt        = round(rand(50, 90) + rand(0, 9) * 0.1, 1);
            $ht        = rand(150, 165);
            $bmi       = round($wt / pow($ht / 100, 2), 1);
            $fhFht     = rand(20, 36) . 'cm / ' . rand(130, 160) . 'bpm';
            $tt1       = $this->daysAgo(rand(60, 180));
            $nextVisit = date('Y-m-d', strtotime("$visitDate +28 days"));
            $notes     = "No complications noted. Advised on iron supplementation and prenatal vitamins.";

            $stmt->bind_param(
                'isisiiissdidsssss',
                $pid, $pname, $this->staffId, $visitDate,
                $gravida, $para, $lmp, $edc, $aog,
                $bp, $wt, $ht, $bmi, $fhFht,
                $tt1, $notes, $nextVisit
            );
            if ($stmt->execute()) {
                $count++;
                $this->ok("Maternal: $pname (G$gravida P$para AOG {$aog}wks) on $visitDate");
            } else {
                $this->err("Maternal insert failed for $pname: " . $stmt->error);
            }
        }
        $stmt->close();
        $this->ok("Maternal records seeded: $count records.");
    }

    // ─── 5. CHILD IMMUNIZATION ─────────────────────────────────────────────

    public function seedImmunization(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[4b/6] Child Immunization Records...\n" : '';

        // Use pediatric patients (under 5 years)
        $childPatients = [];
        foreach ($this->seededPatientIds as $pid) {
            $r = $this->db->prepare(
                "SELECT id, first_name, last_name, birthdate FROM patients WHERE id = ? LIMIT 1"
            );
            $r->bind_param('i', $pid);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            if ($row) {
                $ageMonths = (int) floor((time() - strtotime($row['birthdate'])) / (30.44 * 86400));
                if ($ageMonths <= 60) {
                    $childPatients[] = $row;
                }
            }
            $r->close();
        }

        if (empty($childPatients)) {
            $this->skip("No pediatric patients found; skipping immunization records.");
            return;
        }

        // Check if child_immunization_records table exists
        $res = $this->db->query("SHOW TABLES LIKE 'child_immunization_records'");
        if (!$res || $res->num_rows === 0) {
            $this->skip("child_immunization_records table doesn't exist yet; skipping.");
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO child_immunization_records
                (patient_id, patient_name, attending_staff_id, family_no,
                 birth_weight, birth_height, birth_place, type_of_delivery,
                 type_of_breastfeeding, mother_name, no_of_children, contact_no,
                 bcg_date, hep_b_24hrs_date, penta_1_opv_1_date, penta_2_opv_2_date, pcv_1_date, pcv_2_date,
                 mr_date, next_visit_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            $this->err("immunization prepare failed: " . $this->db->error);
            return;
        }

        $count = 0;
        foreach (array_slice($childPatients, 0, 8) as $child) {
            $pid    = (int) $child['id'];
            $pname  = $child['first_name'] . ' ' . $child['last_name'];
            $bdate  = $child['birthdate'];

            $bwt   = round(rand(25, 38) * 0.1, 1);
            $bht   = rand(47, 52);
            $fno   = 'F-' . rand(1000, 9999);
            $motherName = 'Maria ' . $child['last_name'];
            $contact    = '0917' . rand(1000000, 9999999);

            $bcg       = date('Y-m-d', strtotime("$bdate +1 day"));
            $hepb      = date('Y-m-d', strtotime("$bdate +1 day"));
            $penta1    = date('Y-m-d', strtotime("$bdate +6 weeks"));
            $penta2    = date('Y-m-d', strtotime("$bdate +10 weeks"));
            $pcv1      = date('Y-m-d', strtotime("$bdate +6 weeks"));
            $pcv2      = date('Y-m-d', strtotime("$bdate +10 weeks"));
            $mr        = date('Y-m-d', strtotime("$bdate +9 months"));
            $nextVisit = date('Y-m-d', strtotime("today +1 month"));

            $delivery   = 'Normal Spontaneous Delivery';
            $bfType     = 'Exclusive Breastfeeding';
            $birthPlace = 'Barangay Health Center';
            $nChildren  = rand(1, 4);

            $stmt->bind_param(
                'issiisssssississssss',
                $pid, $pname, $this->staffId, $fno,
                $bwt, $bht, $birthPlace, $delivery,
                $bfType, $motherName, $nChildren, $contact,
                $bcg, $hepb, $penta1, $penta2, $pcv1, $pcv2,
                $mr, $nextVisit
            );
            if ($stmt->execute()) {
                $count++;
                $this->ok("Immunization: $pname (DOB: $bdate)");
            } else {
                $this->err("Immunization failed for $pname: " . $stmt->error);
            }
        }
        $stmt->close();

        // Also seed child consultation logs
        $this->seedChildConsultationLogs();
        $this->ok("Immunization records seeded: $count records.");
    }

    private function seedChildConsultationLogs(): void
    {
        $res = $this->db->query("SHOW TABLES LIKE 'child_consultation_logs'");
        if (!$res || $res->num_rows === 0) { return; }

        $records = $this->db->query("SELECT id FROM child_immunization_records ORDER BY id DESC LIMIT 8");
        if (!$records || $records->num_rows === 0) { return; }

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO child_consultation_logs
                (child_record_id, consultation_date, weight, height, temp, chief_complaints, remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) { return; }

        $complaints = [
            'Fever and colds, mild cough',
            'Routine immunization visit, no complaints',
            'Diarrhea, 3-4 loose stools per day',
            'Rashes on trunk and extremities, mild fever',
            'Ear pain, left ear, mild fever',
        ];

        $count = 0;
        while ($row = $records->fetch_assoc()) {
            $cid  = (int) $row['id'];
            for ($i = 0; $i < rand(2, 4); $i++) {
                $days = rand(10, 90);
                $date = $this->daysAgo($days);
                $wt   = round(rand(60, 180) * 0.1, 1);
                $ht   = round(rand(500, 1000) * 0.1, 1);
                $temp = round(rand(365, 390) * 0.1, 1);
                $cc   = $complaints[array_rand($complaints)];
                $rem  = 'Advised on proper nutrition and hydration.';

                $stmt->bind_param('isdddss', $cid, $date, $wt, $ht, $temp, $cc, $rem);
                if ($stmt->execute()) { $count++; }
            }
        }
        $stmt->close();
        $this->ok("Child consultation logs: $count records added.");
    }

    // ─── 6. DENTAL RECORDS ─────────────────────────────────────────────────

    public function seedDental(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[4c/6] Dental Records...\n" : '';

        if (empty($this->seededPatientIds)) { return; }

        $count = 0;
        $patientsForDental = array_slice($this->seededPatientIds, 0, 12);

        foreach ($patientsForDental as $pid) {
            // Check chart exists
            $chk = $this->db->prepare("SELECT id FROM dental_charts WHERE patient_id = ? LIMIT 1");
            $chk->bind_param('i', $pid);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$row) {
                $medHistory = json_encode(['allergies' => '', 'heart_condition' => false, 'diabetes' => false, 'hypertension' => rand(0, 1) === 1]);
                $clinicalFindings = json_encode(['chief_complaint' => 'Toothache, dental caries', 'bp' => '120/80']);
                $st = $this->db->prepare(
                    "INSERT INTO dental_charts
                        (patient_id, medical_history, clinical_findings, consent_privacy, consent_waiver, created_by)
                     VALUES (?, ?, ?, 1, 1, ?)"
                );
                $st->bind_param('issi', $pid, $medHistory, $clinicalFindings, $this->staffId);
                if (!$st->execute()) {
                    $this->err("Dental chart failed for patient $pid: " . $st->error);
                    $st->close();
                    continue;
                }
                $chartId = (int) $this->db->insert_id;
                $st->close();
                $this->ok("Dental chart: Patient ID $pid");
            } else {
                $chartId = (int) $row['id'];
                $this->skip("Dental chart for patient $pid already exists.");
            }

            // Add treatments
            $treatments = [
                ['EXT', '14,15',  'Tooth extraction - lower left molar, dental caries'],
                ['R',   '11,12',  'Restoration (composite filling) - upper front teeth'],
                ['SC',  'all',    'Dental prophylaxis / oral prophylaxis'],
                ['OHI', '',       'Oral hygiene instructions, tooth brushing technique'],
            ];

            $treatStmt = $this->db->prepare(
                "INSERT INTO dental_treatments
                    (chart_id, patient_id, treatment_date, routine_codes, tooth_numbers, remarks, dentist_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach (array_slice($treatments, 0, rand(1, 3)) as [$codes, $teeth, $remarks]) {
                $tdate = $this->randDaysAgo(5, 60);
                $treatStmt->bind_param('iissssi', $chartId, $pid, $tdate, $codes, $teeth, $remarks, $this->staffId);
                if ($treatStmt->execute()) {
                    $count++;
                }
            }
            $treatStmt->close();
        }
        $this->ok("Dental records seeded: $count treatment records.");
    }

    // ─── 7. INVENTORY ──────────────────────────────────────────────────────

    public function seedInventory(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[5/6] Inventory...\n" : '';

        $items = [
            ['Paracetamol 500mg', 'Biogesic', 'Medicine', 'PAR500', 'LOT-2024-001', 500, 'Tablet', 10, 'Blister', 10, 'Box', '2026-12-31'],
            ['Amoxicillin 500mg', 'Moxatag', 'Medicine', 'AMX500', 'LOT-2024-002', 200, 'Capsule', 10, 'Strip', 10, 'Box', '2026-10-15'],
            ['Mefenamic Acid 500mg', 'Ponstan', 'Medicine', 'MEF500', 'LOT-2024-003', 300, 'Capsule', 10, 'Strip', 10, 'Box', '2027-03-01'],
            ['Losartan 50mg', 'Cozaar', 'Medicine', 'LOS050', 'LOT-2024-004', 150, 'Tablet', 10, 'Blister', 3, 'Box', '2027-01-31'],
            ['Amlodipine 5mg', 'Norvasc', 'Medicine', 'AML005', 'LOT-2024-005', 120, 'Tablet', 10, 'Blister', 3, 'Box', '2026-11-30'],
            ['Metformin 500mg', 'Glucophage', 'Medicine', 'MET500', 'LOT-2024-006', 200, 'Tablet', 10, 'Blister', 5, 'Box', '2027-02-28'],
            ['Cotrimoxazole 960mg', 'Bactrim', 'Medicine', 'CTX960', 'LOT-2024-007', 100, 'Tablet', 10, 'Strip', 5, 'Box', '2026-09-30'],
            ['Salbutamol Inhaler', 'Ventolin', 'Medicine', 'SAL-INH', 'LOT-2024-008', 30, 'Piece', 1, 'Unit', 1, 'Box', '2026-08-15'],
            ['ORS Sachet', 'Hydralyte', 'Medical Supply', 'ORS-SAC', 'LOT-2024-009', 1000, 'Sachet', 25, 'Pack', 4, 'Box', '2027-06-30'],
            ['Acyclovir 200mg', 'Zovirax', 'Medicine', 'ACY200', 'LOT-2024-010', 80, 'Tablet', 10, 'Strip', 5, 'Box', '2026-12-01'],
            ['Zinc Sulfate 20mg Syrup', 'Zinc-C', 'Medicine', 'ZNC020', 'LOT-2024-011', 50, 'Bottle', 1, 'Bottle', 12, 'Box', '2026-11-01'],
            ['BCG Vaccine', 'BCG SSI', 'Vaccine', 'BCG-VAC', 'LOT-2024-012', 20, 'Vial', 1, 'Vial', 10, 'Box', '2026-07-15'],
            ['Hepatitis B Vaccine', 'Engerix-B', 'Vaccine', 'HEPB-VAC', 'LOT-2024-013', 15, 'Vial', 1, 'Vial', 10, 'Box', '2026-08-31'],
            ['Surgical Gloves (Medium)', 'Medline', 'Medical Supply', 'GLOVES-M', 'LOT-2024-014', 500, 'Piece', 50, 'Pair Box', 10, 'Case', '2029-12-31'],
            ['N95 Respiratory Mask', 'Generic PPE', 'Medical Supply', 'N95-MASK', 'LOT-2024-015', 200, 'Piece', 20, 'Pack', 5, 'Box', '2028-06-30'],
            ['Alcohol 70% 500mL', 'Green Cross', 'Medical Supply', 'ALC-500', 'LOT-2024-016', 48, 'Bottle', 1, 'Bottle', 12, 'Case', '2028-01-01'],
            ['Povidone-Iodine Solution', 'Betadine', 'Medical Supply', 'BETA-SOL', 'LOT-2024-017', 24, 'Bottle', 1, 'Bottle', 6, 'Box', '2027-05-01'],
            ['Loperamide 2mg', 'Imodium', 'Medicine', 'LOP002', 'LOT-2024-018', 100, 'Capsule', 10, 'Strip', 5, 'Box', '2026-10-31'],
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO inventory
                (item_name, brand_name, category, tracking_code, batch_number, quantity, unit,
                 units_per_pack, pack_label, packs_per_box, box_label, expiry_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        if (!$stmt) {
            $this->err("inventory prepare failed: " . $this->db->error);
            return;
        }

        $count = 0;
        foreach ($items as [$name, $brand, $cat, $code, $batch, $qty, $unit, $upp, $plabel, $ppb, $blabel, $expiry]) {
            // Check duplicate tracking code
            $chk = $this->db->prepare("SELECT id FROM inventory WHERE tracking_code = ? LIMIT 1");
            $chk->bind_param('s', $code);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->close();
                $this->skip("Inventory item '$name' (code: $code) already exists.");
                continue;
            }
            $chk->close();

            $stmt->bind_param(
                'sssssisisiss',
                $name, $brand, $cat, $code, $batch,
                $qty, $unit, $upp, $plabel, $ppb, $blabel, $expiry
            );
            if ($stmt->execute()) {
                $count++;
                $this->ok("Inventory: $name ($qty $unit)");
            } else {
                $this->err("Inventory failed for $name: " . $stmt->error);
            }
        }
        $stmt->close();
        $this->ok("Inventory seeded: $count items.");
    }

    // ─── 8. SYSTEM OPTIONS (Streets / Departments) ─────────────────────────

    public function seedSystemOptions(): void
    {
        echo PHP_SAPI === 'cli' ? "\n[6/6] System Options & Settings Verification...\n" : '';

        $optionSets = [
            'streets' => [
                'Aida', 'Cattleya', 'Cora', 'Daffodil', 'Delia', 'Emma', 'Flora',
                'Gracia', 'Irma', 'Jaguar', 'Joey', 'Leonard', 'Ritchie', 'Vicenta',
                'Purok 1 (Sto. Domingo)', 'Purok 2 (Sto. Domingo)', 'Purok 3 (Sto. Domingo)', 'Purok 4 (Sto. Domingo)',
                'Zone 1', 'Zone 2',
            ],
            'address_types' => [
                'Marick', 'Outside Marick',
            ],
            'clinic_departments' => [
                'General Consultation', 'Dental Clinic', 'Maternal Care', 'Child Immunization',
            ],
            'inv_categories' => [
                'Medicine', 'Vaccine', 'Medical Supply', 'Equipment',
            ],
            'delivery_types' => [
                'Normal Spontaneous Delivery', 'Caesarean Section',
                'Assisted Breech', 'Forceps Delivery', 'Vacuum Extraction',
            ],
            'breastfeeding_types' => [
                'Exclusive Breastfeeding', 'Mixed Feeding', 'Formula Feeding',
            ],
            'vaccine_categories' => [
                'BCG', 'Hepatitis B', 'Pentavalent', 'Oral Polio Vaccine (OPV)',
                'Inactivated Polio Vaccine (IPV)', 'Pneumococcal Conjugate Vaccine (PCV)', 'Measles, Mumps, Rubella (MMR)',
            ],
            'birth_places' => [
                'Barangay Health Center', 'Rizal Provincial Hospital',
                'Amang Rodriguez Memorial Medical Center', 'Private Lying-in Clinic',
            ],
        ];

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO system_options (list_key, option_value, sort_order, is_active) VALUES (?, ?, ?, 1)"
        );
        if (!$stmt) {
            $this->err("system_options prepare failed: " . $this->db->error);
            return;
        }

        $count = 0;
        foreach ($optionSets as $listKey => $values) {
            foreach ($values as $i => $val) {
                $sort = ($i + 1) * 10;
                $stmt->bind_param('ssi', $listKey, $val, $sort);
                if ($stmt->execute() && $this->db->affected_rows > 0) {
                    $count++;
                    $this->ok("Option: [$listKey] = $val");
                }
            }
        }
        $stmt->close();
        $this->ok("System options seeded: $count new entries.");
    }

    // ─── MAIN RUN ──────────────────────────────────────────────────────────

    public function run(): array
    {
        $this->seedPatients();
        $this->seedConsultations();
        $this->seedQueue();
        $this->seedMaternalCare();
        $this->seedImmunization();
        $this->seedDental();
        $this->seedInventory();
        $this->seedSystemOptions();

        $totals = ['ok' => 0, 'skip' => 0, 'err' => 0];
        foreach ($this->log as [$type, ]) {
            $totals[$type]++;
        }
        return $totals;
    }
}

// ─── CLI ENTRY POINT ───────────────────────────────────────────────────────

if ($isCli) {
    echo "\n╔══════════════════════════════════════════════╗\n";
    echo "║   MARICK HEALTH CENTER — DB SEEDER (CLI)    ║\n";
    echo "╚══════════════════════════════════════════════╝\n";
    $seeder = new MhSeeder($conn);
    $totals = $seeder->run();
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Done! {$totals['ok']} inserted, {$totals['skip']} skipped, {$totals['err']} errors\n\n";
    exit(0);
}

// ─── WEB ENTRY POINT ───────────────────────────────────────────────────────

$ran     = false;
$totals  = null;
$seeder  = null;
$csrfField = function_exists('mh_csrf_field') ? mh_csrf_field() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_seeder'])) {
    $ran    = true;
    $seeder = new MhSeeder($conn);
    $totals = $seeder->run();
    if (function_exists('logActivity')) {
        @logActivity(
            $conn,
            (int) ($_SESSION['user_id'] ?? 0),
            (string) ($_SESSION['role'] ?? 'Health Head'),
            'Database Seeder',
            "Executed seeder: {$totals['ok']} inserted, {$totals['skip']} skipped, {$totals['err']} errors."
        );
    }
}
?>
<?php
require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'Database Seeder']);
?>
    <style>
        .seed-card { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .seed-title { font-size: 1.3rem; font-weight: 800; color: var(--heading-color, #1e293b); margin: 0 0 6px; }
        .seed-sub { color: var(--text-muted, #64748b); font-size: 0.9rem; margin: 0 0 20px; }
        .seed-warn { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; color: #92400e; font-size: 0.88rem; }
        .seed-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: #0ea5e9; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; border-radius: 10px; }
        .seed-btn:hover { background: #0284c7; }
        .seed-btn.danger { background: #dc2626; }
        .log-box { background: var(--bg-hover, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 16px; max-height: 500px; overflow-y: auto; font-family: monospace; font-size: 0.82rem; margin-top: 20px; }
        .log-ok   { color: #16a34a; } .log-skip { color: #64748b; } .log-err  { color: #dc2626; font-weight: 700; }
        .totals   { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 16px; }
        .tot-card { padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 0.95rem; }
        .tot-ok   { background: #dcfce7; color: #166534; } .tot-skip { background: #f1f5f9; color: #475569; } .tot-err  { background: #fee2e2; color: #991b1b; }
        .seed-modules { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 16px 0; }
        .seed-module { background: var(--bg-hover, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 12px 16px; font-size: 0.85rem; }
        .seed-module strong { display: block; color: var(--heading-color, #1e293b); font-size: 0.95rem; margin-bottom: 4px; }
        .seed-module span { color: var(--text-muted, #64748b); }
    </style>
<?php mh_layout_open(); ?>
        <div class="dashboard-container">

            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:18px;">
                <div>
                    <h1 style="margin:0 0 4px; font-family:Georgia,serif; color:var(--heading-color,#1e293b);">Database Seeder</h1>
                    <p style="margin:0; color:var(--text-muted,#64748b);">Populate all modules with realistic Philippine clinical test data for UI and dashboard testing.</p>
                </div>
            </div>

            <?php if (!$ran): ?>
            <div class="seed-warn">
                <strong>Test Environment Only.</strong> This tool inserts mock patient data, consultations, and clinical records into the live database. Only run this on a development or staging server. All seed emails end with <code>@test.local</code> to prevent confusion with real patients. The seeder is <strong>safe to re-run</strong> — existing records are skipped automatically.
            </div>

            <div class="seed-card">
                <p class="seed-title">Database Seeder</p>
                <p class="seed-sub">Generates 25 realistic patient profiles and populates all clinical modules with clinically-sound data distributed across the last 90 days.</p>

                <div class="seed-modules">
                    <div class="seed-module"><strong>25 Patients</strong><span>Pediatric, adult, elderly — Marick & outsiders</span></div>
                    <div class="seed-module"><strong>150 Consultations</strong><span>90-day spread, 17 valid clinical diagnoses</span></div>
                    <div class="seed-module"><strong>Queue Entries</strong><span>All 4 departments, mixed statuses</span></div>
                    <div class="seed-module"><strong>Maternal Records</strong><span>Prenatal visits, gravida/para, vitals</span></div>
                    <div class="seed-module"><strong>Immunization</strong><span>BCG, HepB, Pentavalent, PCV, MR</span></div>
                    <div class="seed-module"><strong>Dental Records</strong><span>Charts, caries, treatment codes</span></div>
                    <div class="seed-module"><strong>18 Inventory Items</strong><span>Medicines, vaccines, medical supplies</span></div>
                    <div class="seed-module"><strong>System Options</strong><span>Streets, departments, masterlists</span></div>
                </div>

                <form method="POST">
                    <?php echo $csrfField; ?>
                    <button type="submit" name="run_seeder" value="1" class="seed-btn"
                        onclick="return confirm('Run the database seeder? This will insert test data into your database.')">
                        Run Database Seeder
                    </button>
                </form>
            </div>

            <?php else: ?>
            <div class="seed-card">
                <p class="seed-title">Seeder Complete</p>
                <p class="seed-sub">The following data was inserted or verified in your database.</p>

                <?php if ($totals): ?>
                <div class="totals">
                    <div class="tot-card tot-ok"><?php echo $totals['ok']; ?> Inserted / Updated</div>
                    <div class="tot-card tot-skip">· <?php echo $totals['skip']; ?> Skipped (already exists)</div>
                    <?php if ($totals['err'] > 0): ?>
                    <div class="tot-card tot-err"><?php echo $totals['err']; ?> Errors</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="log-box">
                <?php foreach ($seeder->getLogs() as [$type, $msg]): ?>
                    <div class="log-<?php echo $type; ?>"><?php
                        $icon = match($type) { 'ok' => 'OK', 'skip' => '-', 'err' => 'ERR', default => '' };
                        echo htmlspecialchars("$icon $msg", ENT_QUOTES, 'UTF-8');
                    ?></div>
                <?php endforeach; ?>
                </div>

                <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
                    <a href="disease_statistics.php" class="seed-btn" style="text-decoration:none;">View Disease Statistics</a>
                    <a href="patient_masterlist.php" class="seed-btn" style="text-decoration:none; background:#64748b;">Patient Masterlist</a>
                    <a href="appointments.php" class="seed-btn" style="text-decoration:none; background:#64748b;">Queue Monitor</a>
                    <a href="inventory.php" class="seed-btn" style="text-decoration:none; background:#64748b;">Inventory</a>
                    <form method="POST" style="display:inline;">
                        <?php echo $csrfField; ?>
                        <button type="submit" name="run_seeder" value="1" class="seed-btn" style="background:#64748b;">Run Again</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    <?php mh_layout_close(); ?>
<?php mh_layout_end(); ?>

