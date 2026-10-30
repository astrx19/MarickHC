<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - PATIENT PORTAL LAYOUT + DATA HELPERS
 * =====================================================================
 * One include that gives every patient page the same shell as the
 * Staff Console (includes/admin_sidebar.php + admin_topbar.php):
 *
 *   - It reuses the SAME classes from assets/css/admin_theme.css
 *     (.sidebar, .sidebar-nav, .topbar, .main-content, .unified-panel,
 *     .stat-card, .status-pill, .unified-table ...), so colours, borders,
 *     the collapsible sidebar and the mobile drawer come from the one
 *     design system and cannot drift apart.
 *   - assets/css/patient_portal.css only adds what the admin theme has
 *     no equivalent for (hero banner, timeline, category pills...).
 *
 * USAGE (every page under /patient):
 *
 *   require_once __DIR__ . '/../includes/patient_auth.php';
 *   require_once __DIR__ . '/../config/database.php';
 *   require_once __DIR__ . '/../includes/patient_layout.php';
 *   ...
 *   mh_pp_layout_start([
 *       'title'       => 'Dashboard',            // <title> prefix
 *       'active'      => 'dashboard',            // dashboard|history|medicines|profile|info
 *       'patient'     => $patient,               // row from mh_pp_patient()
 *       'system_name' => $system_name,
 *   ]);
 *   ... page markup ...
 *   mh_pp_layout_end();
 *
 * Every DB helper below uses prepared statements and swallows errors
 * (returns [] / null): PHP 8.1+ mysqli throws on a missing table, and a
 * patient page should degrade to an empty state, not a fatal error.
 */

require_once __DIR__ . '/icons.php';

/* ------------------------------------------------------------------
 * Small utilities
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_e')) {
    function mh_pp_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mh_pp_date')) {
    /** Format a DB date/datetime; returns an em dash when empty/invalid. */
    function mh_pp_date($value, string $format = 'M d, Y'): string
    {
        if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$value);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('mh_pp_ago')) {
    /** "today", "3 days ago", "2 months ago"... for a DB date. */
    function mh_pp_ago($value): string
    {
        if (empty($value)) { return ''; }
        try {
            $then = new DateTime(date('Y-m-d', strtotime((string)$value)));
            $now  = new DateTime('today');
        } catch (Throwable $e) {
            return '';
        }
        $days = (int)$now->diff($then)->format('%r%a') * -1;
        if ($days <= 0)  { return 'today'; }
        if ($days === 1) { return 'yesterday'; }
        if ($days < 31)  { return $days . ' days ago'; }
        $months = (int)floor($days / 30.44);
        if ($months < 12) { return $months . ($months === 1 ? ' month ago' : ' months ago'); }
        $years = (int)floor($days / 365.25);
        return $years . ($years === 1 ? ' year ago' : ' years ago');
    }
}

if (!function_exists('mh_pp_age')) {
    function mh_pp_age($birthdate): ?int
    {
        if (empty($birthdate)) { return null; }
        try {
            return (new DateTime((string)$birthdate))->diff(new DateTime('today'))->y;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('mh_pp_excerpt')) {
    /** Plain-text excerpt (tags stripped, whitespace collapsed, ellipsised). */
    function mh_pp_excerpt($text, int $max = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)($text ?? ''))));
        if ($text === '') { return ''; }
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
        }
        return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
    }
}

if (!function_exists('mh_pp_initials')) {
    function mh_pp_initials(?string $first, ?string $last = ''): string
    {
        $a = mb_strtoupper(mb_substr(trim((string)$first), 0, 1, 'UTF-8'), 'UTF-8');
        $b = mb_strtoupper(mb_substr(trim((string)$last), 0, 1, 'UTF-8'), 'UTF-8');
        $out = $a . $b;
        return $out !== '' ? $out : 'P';
    }
}

if (!function_exists('mh_pp_patient_no')) {
    /** Same "#00012" format the Staff Console uses (admin/view_patient.php). */
    function mh_pp_patient_no($id): string
    {
        return '#' . str_pad((string)(int)$id, 5, '0', STR_PAD_LEFT);
    }
}

/* ------------------------------------------------------------------
 * DB helpers (prepared statements, never throw)
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_rows')) {
    function mh_pp_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) { return []; }
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $res  = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            error_log('[patient portal] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('mh_pp_row')) {
    function mh_pp_row(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
    {
        $rows = mh_pp_rows($conn, $sql, $types, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('mh_pp_has_column')) {
    function mh_pp_has_column(mysqli $conn, string $table, string $column): bool
    {
        $row = mh_pp_row(
            $conn,
            "SELECT 1 AS ok FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            'ss',
            [$table, $column]
        );
        return $row !== null;
    }
}

if (!function_exists('mh_pp_patient')) {
    function mh_pp_patient(mysqli $conn, int $patient_id): ?array
    {
        return mh_pp_row($conn, "SELECT * FROM patients WHERE id = ? LIMIT 1", 'i', [$patient_id]);
    }
}

if (!function_exists('mh_pp_staff_name')) {
    /** Row must carry first_name / last_name / role (from a LEFT JOIN staff). */
    function mh_pp_staff_name(array $r): string
    {
        $first = trim((string)($r['first_name'] ?? ''));
        $last  = trim((string)($r['last_name'] ?? ''));
        if ($first === '' && $last === '') { return 'Health center staff'; }
        $role   = strtolower((string)($r['role'] ?? ''));
        switch ($role) {   // (switch, not match: keeps this file working on PHP 7.x hosts)
            case 'doctor': case 'dental': $prefix = 'Dr. '; break;
            case 'midwife':               $prefix = 'Midwife '; break;
            case 'bhw':                   $prefix = 'BHW '; break;
            default:                      $prefix = '';
        }
        return $prefix . trim($first . ' ' . $last);
    }
}

/* ------------------------------------------------------------------
 * Clinic categories -> label, pill class, icon
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_categories')) {
    function mh_pp_categories(): array
    {
        return [
            'general'      => ['label' => 'General Consult', 'class' => 'cat-general',      'icon' => 'activity'],
            'dental'       => ['label' => 'Dental',          'class' => 'cat-dental',       'icon' => 'smile'],
            'maternal'     => ['label' => 'Maternal Care',   'class' => 'cat-maternal',     'icon' => 'heart'],
            'immunization' => ['label' => 'Immunization',    'class' => 'cat-immunization', 'icon' => 'droplet'],
        ];
    }
}

/* Categories that count as a "visit" for the visit counter. Individual
   vaccine doses / child health logs are listed in the history but are
   not counted as separate consultations. */
if (!function_exists('mh_pp_visit_categories')) {
    function mh_pp_visit_categories(): array
    {
        return ['general', 'dental', 'maternal'];
    }
}

/* ------------------------------------------------------------------
 * Unified consultation history (newest first)
 *
 * Each entry:
 *   cat, date, sort, title, attending, summary, details[label => text]
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_history')) {
    function mh_pp_history(mysqli $conn, int $pid): array
    {
        $out = [];

        // General consultations
        $rows = mh_pp_rows($conn,
            "SELECT c.id, c.visit_date, c.chief_complaints, c.diagnosis, c.prescription,
                    s.first_name, s.last_name, s.role
               FROM consultations c
               LEFT JOIN staff s ON s.id = c.doctor_id
              WHERE c.patient_id = ?
              ORDER BY c.visit_date DESC, c.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $out[] = [
                'cat'       => 'general',
                'date'      => $r['visit_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'General Consultation',
                'attending' => mh_pp_staff_name($r),
                'summary'   => mh_pp_excerpt($r['chief_complaints'] ?: $r['diagnosis'], 150),
                'details'   => [
                    'Chief complaint'       => $r['chief_complaints'],
                    'Diagnosis'             => $r['diagnosis'],
                    'Prescription / advice' => $r['prescription'],
                ],
            ];
        }

        // Dental
        $rows = mh_pp_rows($conn,
            "SELECT d.id, d.visit_date, d.procedure_done, d.tooth_number, d.notes,
                    s.first_name, s.last_name, s.role
               FROM dental_records d
               LEFT JOIN staff s ON s.id = d.dentist_id
              WHERE d.patient_id = ?
              ORDER BY d.visit_date DESC, d.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $summary = (string)$r['procedure_done'];
            if (!empty($r['tooth_number'])) { $summary .= ' (tooth ' . $r['tooth_number'] . ')'; }
            $out[] = [
                'cat'       => 'dental',
                'date'      => $r['visit_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'Dental Visit',
                'attending' => mh_pp_staff_name($r),
                'summary'   => mh_pp_excerpt($summary, 150),
                'details'   => [
                    'Procedure' => $r['procedure_done'],
                    'Tooth'     => $r['tooth_number'],
                    'Notes'     => $r['notes'],
                ],
            ];
        }

        // Maternal / prenatal
        $rows = mh_pp_rows($conn,
            "SELECT m.id, m.visit_date, m.aog, m.blood_pressure, m.edc, m.notes,
                    s.first_name, s.last_name, s.role
               FROM maternal_records m
               LEFT JOIN staff s ON s.id = m.midwife_id
              WHERE m.patient_id = ?
              ORDER BY m.visit_date DESC, m.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $parts = [];
            if (!empty($r['aog']))            { $parts[] = 'AOG ' . $r['aog']; }
            if (!empty($r['blood_pressure'])) { $parts[] = 'BP ' . $r['blood_pressure']; }
            $out[] = [
                'cat'       => 'maternal',
                'date'      => $r['visit_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'Prenatal Check-up',
                'attending' => mh_pp_staff_name($r),
                'summary'   => $parts ? implode(', ', $parts) : mh_pp_excerpt($r['notes'], 150),
                'details'   => [
                    'Age of gestation'  => $r['aog'],
                    'Blood pressure'    => $r['blood_pressure'],
                    'Expected delivery' => !empty($r['edc']) ? mh_pp_date($r['edc'], 'F j, Y') : '',
                    'Notes'             => $r['notes'],
                ],
            ];
        }

        // Vaccines given (general immunization log)
        $rows = mh_pp_rows($conn,
            "SELECT i.id, i.vaccine_name, i.dose_number, i.date_administered, i.notes,
                    s.first_name, s.last_name, s.role
               FROM immunization_records i
               LEFT JOIN staff s ON s.id = i.health_worker_id
              WHERE i.patient_id = ?
              ORDER BY i.date_administered DESC, i.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $dose = !empty($r['dose_number']) ? 'Dose ' . $r['dose_number'] : '';
            $out[] = [
                'cat'       => 'immunization',
                'date'      => $r['date_administered'],
                'sort'      => (int)$r['id'],
                'title'     => trim((string)$r['vaccine_name']) ?: 'Vaccination',
                'attending' => mh_pp_staff_name($r),
                'summary'   => $dose !== '' ? $dose : mh_pp_excerpt($r['notes'], 150),
                'details'   => [
                    'Vaccine' => $r['vaccine_name'],
                    'Dose'    => $r['dose_number'],
                    'Notes'   => $r['notes'],
                ],
            ];
        }

        // Child health consultation logs (attached to the child immunization record)
        $rows = mh_pp_rows($conn,
            "SELECT l.id, l.consultation_date, l.chief_complaints, l.remarks,
                    s.first_name, s.last_name, s.role
               FROM child_consultation_logs l
               JOIN child_immunization_records r ON r.id = l.child_record_id
               LEFT JOIN staff s ON s.id = r.attending_staff_id
              WHERE r.patient_id = ?
              ORDER BY l.consultation_date DESC, l.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $out[] = [
                'cat'       => 'immunization',
                'date'      => $r['consultation_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'Child Health Consultation',
                'attending' => mh_pp_staff_name($r),
                'summary'   => mh_pp_excerpt($r['chief_complaints'] ?: $r['remarks'], 150),
                'details'   => [
                    'Chief complaint' => $r['chief_complaints'],
                    'Remarks'         => $r['remarks'],
                ],
            ];
        }

        usort($out, function ($a, $b) {
            $c = strcmp((string)$b['date'], (string)$a['date']);
            return $c !== 0 ? $c : ($b['sort'] <=> $a['sort']);
        });

        return $out;
    }
}

/* ------------------------------------------------------------------
 * Medicines dispensed to the patient (newest first)
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_dispensed')) {
    function mh_pp_dispensed(mysqli $conn, int $pid, int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        return mh_pp_rows($conn,
            "SELECT d.id, d.quantity, d.dispense_date,
                    i.item_name, i.brand_name, i.unit,
                    s.first_name, s.last_name, s.role
               FROM dispensations d
               JOIN inventory i ON i.id = d.inventory_id
               LEFT JOIN staff s ON s.id = d.dispensed_by
              WHERE d.patient_id = ?
              ORDER BY d.dispense_date DESC, d.id DESC
              LIMIT $limit", 'i', [$pid]);
    }
}

if (!function_exists('mh_pp_recent_dispense_count')) {
    /** Dispensing records in the last $days days. */
    function mh_pp_recent_dispense_count(mysqli $conn, int $pid, int $days = 90): int
    {
        $days = max(1, $days);
        $row  = mh_pp_row($conn,
            "SELECT COUNT(*) AS c FROM dispensations
              WHERE patient_id = ? AND dispense_date >= (NOW() - INTERVAL $days DAY)", 'i', [$pid]);
        return (int)($row['c'] ?? 0);
    }
}

/* ------------------------------------------------------------------
 * Program enrolment (Child Immunization / Maternal Care / vaccine log)
 * Returns a list of ['label','status','detail','icon'] - possibly empty.
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_programs')) {
    function mh_pp_programs(mysqli $conn, int $pid): array
    {
        $programs = [];

        // Child immunization master record
        $child = mh_pp_row($conn,
            "SELECT bcg_date, hep_b_24hrs_date, penta_opv_3_date, penta_1_opv_1_date,
                    penta_2_opv_2_date, pcv_1_date, pcv_2_date, pcv_3_date, mr_date, mmr_date
               FROM child_immunization_records
              WHERE patient_id = ? ORDER BY id DESC LIMIT 1", 'i', [$pid]);
        if ($child) {
            $done = 0;
            foreach ($child as $v) {
                if (!empty($v) && $v !== '0000-00-00') { $done++; }
            }
            $programs[] = [
                'label'  => 'Child Immunization',
                'status' => 'Enrolled',
                'detail' => $done . ' of ' . count($child) . ' scheduled vaccines recorded',
                'icon'   => 'droplet',
            ];
        }

        // Maternal care (latest prenatal visit)
        $mat = mh_pp_row($conn,
            "SELECT aog, edc, visit_date FROM maternal_records
              WHERE patient_id = ? ORDER BY visit_date DESC, id DESC LIMIT 1", 'i', [$pid]);
        if ($mat) {
            $detail = !empty($mat['edc'])
                ? 'Expected delivery ' . mh_pp_date($mat['edc'], 'M d, Y')
                : 'Last check-up ' . mh_pp_date($mat['visit_date'], 'M d, Y');
            $programs[] = [
                'label'  => 'Maternal Care',
                'status' => 'Enrolled',
                'detail' => $detail,
                'icon'   => 'heart',
            ];
        }

        // Vaccines on record (general immunization log)
        $imm = mh_pp_row($conn,
            "SELECT COUNT(*) AS c, MAX(date_administered) AS last_date
               FROM immunization_records WHERE patient_id = ?", 'i', [$pid]);
        if ($imm && (int)$imm['c'] > 0) {
            $programs[] = [
                'label'  => 'Vaccinations',
                'status' => (int)$imm['c'] . ((int)$imm['c'] === 1 ? ' dose' : ' doses') . ' on record',
                'detail' => 'Latest ' . mh_pp_date($imm['last_date'], 'M d, Y'),
                'icon'   => 'shield',
            ];
        }

        return $programs;
    }
}

/* ------------------------------------------------------------------
 * Health center information (hours, services, hotlines)
 * ------------------------------------------------------------------
 * EDIT HERE. These are the values shown on the dashboard card and on
 * patient/health_center.php. The $health_center_hours / $health_center_hotline
 * globals override the
 * defaults below, so it can be wired to System Settings later without
 * touching any patient page.
 */
if (!function_exists('mh_pp_center_info')) {
    function mh_pp_center_info(): array
    {
        global $health_center_hours, $health_center_hotline;

        $hours = [
            ['days' => 'Monday – Friday', 'time' => '8:00 AM – 5:00 PM'],
        ];
        if (!empty($health_center_hours) && is_string($health_center_hours)) {
            $hours = [['days' => 'Open', 'time' => $health_center_hours]];
        }

        $hotlines = [
            ['label' => 'National Emergency Hotline', 'number' => '911', 'tel' => '911'],
        ];
        if (!empty($health_center_hotline) && is_string($health_center_hotline)) {
            array_unshift($hotlines, [
                'label'  => 'Barangay Health Center',
                'number' => $health_center_hotline,
                'tel'    => preg_replace('/[^0-9+]/', '', $health_center_hotline),
            ]);
        }

        return [
            'hours'    => $hours,
            'services' => [
                ['name' => 'Walk-in Consultations', 'icon' => 'activity', 'desc' => 'General check-ups and consultations with our doctor and health workers.'],
                ['name' => 'Child Immunization',    'icon' => 'droplet',  'desc' => 'Routine childhood vaccines and growth monitoring.'],
                ['name' => 'Maternal & Prenatal Care', 'icon' => 'heart', 'desc' => 'Prenatal check-ups and maternal health monitoring with our midwife.'],
                ['name' => 'Dental Services',       'icon' => 'smile',    'desc' => 'Dental consultations and basic procedures.'],
                ['name' => 'Medicine Dispensing',   'icon' => 'package',  'desc' => 'Medicines issued by health center staff, subject to available stock.'],
            ],
            'hotlines' => $hotlines,
        ];
    }
}

/* ------------------------------------------------------------------
 * Health center card (compact on the dashboard, roomy on its own page)
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_render_center_card')) {
    function mh_pp_render_center_card(bool $detailed = false): void
    {
        $info = mh_pp_center_info();
        ?>
        <div class="unified-panel">
            <div class="panel-header">
                <h3><?php echo mh_icon('home'); ?> Health Center Information</h3>
            </div>

            <div class="pp-section">
                <h4 class="pp-section-title">Operating hours</h4>
                <?php foreach ($info['hours'] as $h): ?>
                    <div class="pp-hours-row">
                        <span class="pp-hours-icon"><?php echo mh_icon('clock'); ?></span>
                        <span class="pp-hours-days"><?php echo mh_pp_e($h['days']); ?></span>
                        <span class="pp-hours-time"><?php echo mh_pp_e($h['time']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pp-section">
                <h4 class="pp-section-title">Available services</h4>
                <ul class="pp-services<?php echo $detailed ? ' is-detailed' : ''; ?>">
                    <?php foreach ($info['services'] as $s): ?>
                        <li>
                            <span class="pp-service-icon"><?php echo mh_icon($s['icon']); ?></span>
                            <span>
                                <strong><?php echo mh_pp_e($s['name']); ?></strong>
                                <?php if ($detailed): ?>
                                    <small><?php echo mh_pp_e($s['desc']); ?></small>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="pp-section">
                <div class="pp-emergency">
                    <div class="pp-emergency-head">
                        <?php echo mh_icon('alert-triangle'); ?>
                        <strong>Emergency hotline</strong>
                    </div>
                    <?php foreach ($info['hotlines'] as $hl): ?>
                        <a class="pp-hotline" href="tel:<?php echo mh_pp_e($hl['tel']); ?>">
                            <span><?php echo mh_pp_e($hl['label']); ?></span>
                            <span class="pp-hotline-number"><?php echo mh_icon('phone'); ?> <?php echo mh_pp_e($hl['number']); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <p class="pp-emergency-note">For life-threatening emergencies, go to the nearest hospital right away.</p>
                </div>
            </div>
        </div>
        <?php
    }
}

/* ------------------------------------------------------------------
 * Layout shell
 * ------------------------------------------------------------------ */
if (!function_exists('mh_pp_layout_start')) {
    function mh_pp_layout_start(array $o): void
    {
        $title       = (string)($o['title'] ?? 'Patient Portal');
        $active      = (string)($o['active'] ?? '');
        $patient     = is_array($o['patient'] ?? null) ? $o['patient'] : [];
        $system_name = (string)($o['system_name'] ?? mh_setting('system_name'));

        $first    = (string)($patient['first_name'] ?? ($_SESSION['patient_name'] ?? ''));
        $last     = (string)($patient['last_name'] ?? '');
        $fullname = trim($first . ' ' . $last);
        if ($fullname === '') { $fullname = 'Patient'; }
        $initials  = mh_pp_initials($first, $last);
        $patientNo = mh_pp_patient_no($_SESSION['patient_id'] ?? 0);

        $css_admin = @filemtime(__DIR__ . '/../assets/css/admin_theme.css');
        $css_pp    = @filemtime(__DIR__ . '/../assets/css/patient_portal.css');
        $logo_v    = @filemtime(__DIR__ . '/../assets/images/logo.png');

        $nav = [
            'dashboard' => ['dashboard.php',                'Dashboard',              'grid'],
            'history'   => ['records.php?tab=history',      'My Consultation History', 'file-text'],
            'medicines' => ['records.php?tab=medicines',    'Dispensed Medicines',    'package'],
            'profile'   => ['patient_edit_profile.php',     'My Profile',             'user'],
            'info'      => ['health_center.php',            'Health Center Info',     'home'],
        ];
        ?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <!-- Light (white) is the default for every new session. Dark only applies
         after the user flips the topbar toggle; that choice is shared with
         the Staff Console through the same localStorage key. Runs before the
         CSS paints to avoid a flash. -->
    <script>(function(){var t='light';try{if(localStorage.getItem('theme')==='dark'){t='dark';}}catch(e){}document.documentElement.setAttribute('data-theme',t);})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo mh_pp_e($title); ?> - <?php echo mh_pp_e($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo (int)$css_admin; ?>">
    <link rel="stylesheet" href="../assets/css/patient_portal.css?v=<?php echo (int)$css_pp; ?>">
    <?php include __DIR__ . '/modal_assets.php'; ?>
</head>
<body class="patient-portal">

<aside class="sidebar" id="appSidebar" aria-label="Patient portal navigation">
    <div class="sidebar-header">
        <span class="brand-mark brand-logo">
            <img class="no-dim" src="../assets/images/logo.png?v=<?php echo (int)$logo_v; ?>" alt="Barangay Marick Health Center logo">
        </span>
        <div class="brand-text nav-text">
            <h2>Marick Health</h2>
            <span class="portal-badge">Patient Portal</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
                <li>
                    <a href="<?php echo mh_pp_e($href); ?>"
                       title="<?php echo mh_pp_e($label); ?>"
                       class="<?php echo $active === $key ? 'active' : ''; ?>"
                       <?php echo $active === $key ? 'aria-current="page"' : ''; ?>>
                        <span class="nav-icon"><?php echo mh_icon($icon); ?></span>
                        <span class="nav-text"><?php echo mh_pp_e($label); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a class="sidebar-user" href="patient_edit_profile.php" title="My Profile">
            <span class="pp-avatar" aria-hidden="true"><?php echo mh_pp_e($initials); ?></span>
            <span class="nav-text sidebar-user-text">
                <strong><?php echo mh_pp_e($fullname); ?></strong>
                <small>Patient <?php echo mh_pp_e($patientNo); ?></small>
            </span>
        </a>
        <a href="../patient_logout.php" title="Logout" class="pp-logout" data-confirm="Are you sure you want to log out of the Patient Portal?" data-confirm-title="Log out?" data-confirm-ok="Log out">
            <span class="nav-icon"><?php echo mh_icon('log-out'); ?></span>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</aside>
<div class="pp-backdrop" id="ppBackdrop" aria-hidden="true"></div>
<script src="../assets/js/single_tab_guard.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/single_tab_guard.js'); ?>" data-scope="patient-<?php echo (int) ($_SESSION['patient_id'] ?? 0); ?>" defer></script>

<script>
    // Restore the collapsed sidebar state before first paint (same key the Staff Console uses).
    (function () {
        try {
            if (window.innerWidth > 992 && localStorage.getItem('sidebarCollapsed') === 'true') {
                document.getElementById('appSidebar').classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    })();
</script>

<div class="main-content">
    <header class="topbar">
        <div class="nav-brand-group">
            <button type="button" class="sidebar-toggle-btn" id="sidebarCollapseBtn" onclick="ppToggleSidebar()"
                    title="Toggle navigation" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false">
                <?php echo mh_icon('menu'); ?>
            </button>
            <div style="line-height: 1.2;">
                <h2 class="topbar-title" style="margin: 0; font-size: 1.05rem; color: var(--heading-color); font-weight: 800; letter-spacing: -0.01em;">
                    <?php echo mh_pp_e($system_name); ?>
                </h2>
                <span style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted);">Patient Portal</span>
            </div>
        </div>

        <div class="user-info">
            <button type="button" class="theme-toggle" id="themeToggle" onclick="mhToggleTheme()" title="Toggle dark mode" aria-label="Toggle dark mode">
                <span class="theme-toggle-knob">
                    <span class="icon-sun"><?php echo mh_icon('sun'); ?></span>
                    <span class="icon-moon"><?php echo mh_icon('moon'); ?></span>
                </span>
            </button>
            <div class="user-text-info" style="text-align: right; line-height: 1.2;">
                <span style="font-weight: bold; color: var(--heading-color); display: block; font-size: 0.95rem;"><?php echo mh_pp_e($fullname); ?></span>
                <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: 600;"><?php echo mh_pp_e($patientNo); ?></span>
            </div>
            <a href="patient_edit_profile.php" class="pp-avatar pp-avatar-lg" title="My Profile" aria-label="My Profile"><?php echo mh_pp_e($initials); ?></a>
        </div>
    </header>

    <main class="pp-page" id="ppMain">
        <?php
    }
}

if (!function_exists('mh_pp_layout_end')) {
    function mh_pp_layout_end(): void
    {
        ?>
    </main>
</div>

<script>
    (function () {
        var sidebar  = document.getElementById('appSidebar');
        var backdrop = document.getElementById('ppBackdrop');
        var toggle   = document.getElementById('sidebarCollapseBtn');

        function isMobile() { return window.innerWidth <= 992; }

        function setDrawer(open) {
            sidebar.classList.toggle('mobile-open', open);
            if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        }

        // Mobile (<= 992px): slide the drawer in/out. Desktop: collapse to icons.
        window.ppToggleSidebar = function () {
            if (isMobile()) {
                setDrawer(!sidebar.classList.contains('mobile-open'));
            } else {
                var collapsed = sidebar.classList.toggle('collapsed');
                document.body.classList.toggle('sidebar-collapsed');
                try { localStorage.setItem('sidebarCollapsed', collapsed); } catch (e) {}
            }
        };

        // Dark / light toggle (same key + behaviour as includes/admin_topbar.php).
        window.mhToggleTheme = function () {
            var root = document.documentElement;
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) {}
        };

        if (backdrop) { backdrop.addEventListener('click', function () { setDrawer(false); }); }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                setDrawer(false);
                if (toggle) { toggle.focus(); }
            }
        });

        // Close the drawer after tapping a nav link (same-page anchors, e.g. tab switches).
        sidebar.addEventListener('click', function (e) {
            if (isMobile() && e.target.closest('.sidebar-nav a')) { setDrawer(false); }
        });

        window.addEventListener('resize', function () {
            if (!isMobile()) { setDrawer(false); }
        });
    })();
</script>
</body>
</html>
        <?php
    }
}