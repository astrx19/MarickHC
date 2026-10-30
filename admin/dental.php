<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';

$system_name = $system_name ?? mh_setting('system_name');

// Granular: saving charts and reading them are separate permissions.
require_capability(['can_log_dental', 'can_view_dental_history']);

require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/dental_helper.php';
mh_check_module_enabled('dental');
mh_dental_ensure_tables($conn);

$can_log          = mh_has_capability('can_log_dental');
$can_view_history = mh_has_capability('can_view_dental_history');
$can_ocr          = $can_log && mh_has_permission('use_ocr_scanner') && mh_module_is_enabled('dental');

$user_id = (int) $_SESSION['user_id'];
$role    = (string) ($_SESSION['role'] ?? '');
if (empty($_SESSION['dn_csrf'])) { $_SESSION['dn_csrf'] = bin2hex(random_bytes(16)); }
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$success_msg = $_SESSION['dn_ok'] ?? ''; $error_msg = $_SESSION['dn_err'] ?? '';
unset($_SESSION['dn_ok'], $_SESSION['dn_err']);

function dn_back($pid, $tab, $ok, $msg, $apt = 0)
{
    $_SESSION[$ok ? 'dn_ok' : 'dn_err'] = $msg;
    $q = $pid > 0 ? '?patient=' . (int) $pid . '&tab=' . urlencode($tab) . ($apt > 0 ? '&apt_id=' . (int) $apt : '') : '';
    header('Location: dental.php' . $q);
    exit();
}
function dn_age($birthdate)
{
    if (!$birthdate || $birthdate === '0000-00-00') { return null; }
    try { return (new DateTime($birthdate))->diff(new DateTime('today'))->y; } catch (Exception $e) { return null; }
}

/* =====================================================================
 * SAVE HANDLERS
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_log) { mh_deny_access(); }
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $tab = in_array(($_POST['active_tab'] ?? ''), ['A', 'B', 'C', 'D', 'E'], true) ? $_POST['active_tab'] : 'A';
    $apt = (int) ($_POST['apt_id'] ?? 0);
    if (!hash_equals($_SESSION['dn_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        dn_back($pid, $tab, false, 'Your session form expired. Please reload the page and try again.', $apt);
    }

    $st = $conn->prepare("SELECT id, birthdate FROM patients WHERE id = ? AND status = 1");
    $st->bind_param('i', $pid); $st->execute();
    $pat = $st->get_result()->fetch_assoc(); $st->close();
    if (!$pat) { dn_back(0, 'A', false, 'That patient could not be found.'); }

    /* ---------- save chart (tabs A-D) ---------- */
    if (isset($_POST['save_chart'])) {
        $extra   = mh_dental_clean_section('extra', $_POST['extra'] ?? []);
        $sectors = json_encode(mh_dental_clean_sectors($_POST['sectors'] ?? []));
        $mh      = json_encode(mh_dental_clean_medical($_POST['mh'] ?? []));
        $dh      = json_encode(mh_dental_clean_section('dental_history', $_POST['dental_history'] ?? []));
        $cl      = json_encode(mh_dental_clean_section('clinical', $_POST['clinical'] ?? []));
        $oh      = json_encode(mh_dental_clean_section('oral_health', $_POST['oral_health'] ?? []));
        $odo     = json_encode(mh_dental_clean_odontogram($_POST['odontogram'] ?? ''), JSON_FORCE_OBJECT);
        $cPriv   = !empty($_POST['consent_privacy']) ? 1 : 0;
        $cWaiv   = !empty($_POST['consent_waiver']) ? 1 : 0;
        $cName   = mh_dental_str($_POST['consent_name'] ?? '', 150);
        $cDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['consent_date'] ?? '')) ? $_POST['consent_date'] : null;
        $occ = $extra['occupation']; $gua = $extra['guardian_name']; $phn = $extra['philhealth_no'];

        $sql = "INSERT INTO dental_charts (patient_id, occupation, guardian_name, philhealth_no, sectors, medical_history,
                    consent_privacy, consent_waiver, consent_name, consent_date, dental_history, clinical_findings, oral_health, odontogram,
                    created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE occupation = VALUES(occupation), guardian_name = VALUES(guardian_name), philhealth_no = VALUES(philhealth_no),
                    sectors = VALUES(sectors), medical_history = VALUES(medical_history), consent_privacy = VALUES(consent_privacy),
                    consent_waiver = VALUES(consent_waiver), consent_name = VALUES(consent_name), consent_date = VALUES(consent_date),
                    dental_history = VALUES(dental_history), clinical_findings = VALUES(clinical_findings), oral_health = VALUES(oral_health),
                    odontogram = VALUES(odontogram), updated_by = VALUES(updated_by)";
        $st = $conn->prepare($sql);
        $st->bind_param('isssssiissssssii', $pid, $occ, $gua, $phn, $sectors, $mh, $cPriv, $cWaiv, $cName, $cDate, $dh, $cl, $oh, $odo, $user_id, $user_id);
        $ok = $st->execute(); $err = $st->error; $st->close();
        if (!$ok) { error_log('dental chart save: ' . $err); dn_back($pid, $tab, false, 'The chart could not be saved. Please try again.', $apt); }

        $st = $conn->prepare("SELECT id FROM dental_charts WHERE patient_id = ?");
        $st->bind_param('i', $pid); $st->execute();
        $chart_id = (int) ($st->get_result()->fetch_assoc()['id'] ?? 0); $st->close();

        // Caries risk assessment (only when at least one question was answered)
        $group = in_array(($_POST['caries_group'] ?? ''), ['child', 'adult'], true) ? $_POST['caries_group'] : mh_dental_age_group((int) dn_age($pat['birthdate']));
        $answers = mh_dental_clean_caries($group, $_POST['caries'] ?? []);
        if ($answers) {
            $ev = mh_dental_caries_evaluate($group, $answers);
            $computed = $ev['overall'];
            $final = in_array(($_POST['caries_final'] ?? ''), ['low', 'high'], true) ? $_POST['caries_final'] : ($computed === 'moderate' ? null : $computed);
            $on = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['caries_date'] ?? '')) ? $_POST['caries_date'] : date('Y-m-d');
            $aj = json_encode($answers);
            $rA = $ev['sections']['A']['result']; $rB = $ev['sections']['B']['result']; $rC = $ev['sections']['C']['result'];

            $st = $conn->prepare("SELECT id FROM dental_caries_assessments WHERE chart_id = ? AND assessed_on = ? ORDER BY id DESC LIMIT 1");
            $st->bind_param('is', $chart_id, $on); $st->execute();
            $existing = (int) ($st->get_result()->fetch_assoc()['id'] ?? 0); $st->close();
            if ($existing > 0) {
                $st = $conn->prepare("UPDATE dental_caries_assessments SET age_group = ?, answers = ?, disease_result = ?, socio_result = ?, protective_result = ?,
                                      overall_computed = ?, overall_final = ?, assessed_by = ? WHERE id = ?");
                $st->bind_param('sssssssii', $group, $aj, $rA, $rB, $rC, $computed, $final, $user_id, $existing);
            } else {
                $st = $conn->prepare("INSERT INTO dental_caries_assessments (chart_id, patient_id, age_group, assessed_on, answers, disease_result, socio_result,
                                      protective_result, overall_computed, overall_final, assessed_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $st->bind_param('iissssssssi', $chart_id, $pid, $group, $on, $aj, $rA, $rB, $rC, $computed, $final, $user_id);
            }
            $st->execute(); $st->close();
        }
        logActivity($conn, $user_id, $role, 'Dental Chart Saved', "Saved the dental chart of Patient ID #$pid.");
        dn_back($pid, $tab, true, 'Dental chart saved.', $apt);
    }

    /* ---------- add a treatment row (tab E) ---------- */
    if (isset($_POST['add_treatment'])) {
        $date = (string) ($_POST['treatment_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { dn_back($pid, 'E', false, 'Enter the treatment date.', $apt); }
        $pick = function ($posted, array $allowed) {
            $out = [];
            foreach ((array) $posted as $c) { if (isset($allowed[$c])) { $out[$c] = $c; } }
            return array_values($out);
        };
        $routine = $pick($_POST['routine'] ?? [], mh_dental_routine_codes());
        $other   = $pick($_POST['other'] ?? [], mh_dental_other_codes());
        $teeth   = mh_dental_str($_POST['tooth_numbers'] ?? '', 100);
        $refer   = mh_dental_str($_POST['referrals'] ?? '', 255);
        $remarks = mh_dental_str($_POST['remarks'] ?? '', 1000);
        if (!$routine && !$other && $remarks === '' && $refer === '') {
            dn_back($pid, 'E', false, 'Tick at least one service, or write a referral or remark.', $apt);
        }

        $st = $conn->prepare("INSERT IGNORE INTO dental_charts (patient_id, created_by, updated_by) VALUES (?, ?, ?)");
        $st->bind_param('iii', $pid, $user_id, $user_id); $st->execute(); $st->close();
        $st = $conn->prepare("SELECT id FROM dental_charts WHERE patient_id = ?");
        $st->bind_param('i', $pid); $st->execute();
        $chart_id = (int) ($st->get_result()->fetch_assoc()['id'] ?? 0); $st->close();

        // Keep the older "dental_records" summary in step: the patient profile, patient portal and dashboard read it.
        $codes = implode(', ', array_merge($routine, $other));
        $proc  = $codes !== '' ? $codes : 'Dental visit';
        $tooth = $teeth !== '' ? $teeth : null;
        $notes = trim(($refer !== '' ? 'Referral: ' . $refer . '. ' : '') . $remarks);
        $notes = $notes !== '' ? $notes : null;
        $st = $conn->prepare("INSERT INTO dental_records (patient_id, dentist_id, visit_date, procedure_done, tooth_number, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $st->bind_param('iissss', $pid, $user_id, $date, $proc, $tooth, $notes);
        $ok = $st->execute(); $rec_id = (int) $conn->insert_id; $st->close();

        if ($ok) {
            $rc = implode(',', $routine); $oc = implode(',', $other);
            $st = $conn->prepare("INSERT INTO dental_treatments (chart_id, patient_id, treatment_date, routine_codes, other_codes, tooth_numbers, referrals, remarks, dentist_id, dental_record_id)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param('iissssssii', $chart_id, $pid, $date, $rc, $oc, $teeth, $refer, $remarks, $user_id, $rec_id);
            $ok = $st->execute(); $st->close();
        }
        if (!$ok) { dn_back($pid, 'E', false, 'The treatment could not be saved. Please try again.', $apt); }

        // Finish the queue entry (same behaviour as the previous dental page)
        if ($apt > 0) {
            $st = $conn->prepare("UPDATE appointments SET status = 'Completed' WHERE id = ? AND patient_id = ?");
            $st->bind_param('ii', $apt, $pid);
        } else {
            $st = $conn->prepare("UPDATE appointments SET status = 'Completed' WHERE patient_id = ? AND status IN ('Waiting','Approved')
                                  AND (department LIKE '%Dental%' OR department = 'Dental Clinic')");
            $st->bind_param('i', $pid);
        }
        $st->execute(); $st->close();

        logActivity($conn, $user_id, $role, 'Added Dental Record', "Logged dental treatment for Patient ID #$pid.");
        dn_back($pid, 'E', true, 'Treatment recorded.');
    }
}

/* =====================================================================
 * PAGE DATA
 * ================================================================== */
$pid = (int) ($_GET['patient'] ?? ($_GET['walkin_id'] ?? ($_GET['patient_id'] ?? 0)));
$apt_id = (int) ($_GET['apt_id'] ?? 0);
$tab = in_array(($_GET['tab'] ?? ''), ['A', 'B', 'C', 'D', 'E'], true) ? $_GET['tab'] : 'A';
$patient = null;
if ($pid > 0) {
    $st = $conn->prepare("SELECT * FROM patients WHERE id = ? AND status = 1");
    $st->bind_param('i', $pid); $st->execute();
    $patient = $st->get_result()->fetch_assoc(); $st->close();
    if (!$patient) { $pid = 0; $error_msg = $error_msg ?: 'That patient could not be found.'; }
}

if ($patient) {
    /* ---------------- chart view ---------------- */
    $st = $conn->prepare("SELECT * FROM dental_charts WHERE patient_id = ?");
    $st->bind_param('i', $pid); $st->execute();
    $chart = $st->get_result()->fetch_assoc(); $st->close();

    $age  = dn_age($patient['birthdate']);
    $sex  = (string) $patient['sex'];
    $extra   = mh_dental_clean_section('extra', ['occupation' => $chart['occupation'] ?? '', 'guardian_name' => $chart['guardian_name'] ?? '',
                                                  'philhealth_no' => ($chart['philhealth_no'] ?? '') !== '' ? $chart['philhealth_no'] : ($patient['philhealth_no'] ?? '')]);
    $sectors = mh_dental_clean_sectors(mh_dental_json_decode($chart['sectors'] ?? ''));
    $mh      = mh_dental_clean_medical(mh_dental_json_decode($chart['medical_history'] ?? ''));
    $dh      = mh_dental_clean_section('dental_history', mh_dental_json_decode($chart['dental_history'] ?? ''));
    $cl      = mh_dental_clean_section('clinical', mh_dental_json_decode($chart['clinical_findings'] ?? ''));
    $oh      = mh_dental_clean_section('oral_health', mh_dental_json_decode($chart['oral_health'] ?? ''));
    $odo     = mh_dental_clean_odontogram($chart['odontogram'] ?? '');
    $alerts  = mh_dental_medical_alerts($mh);

    $latest = null; $caries_hist = [];
    $st = $conn->prepare("SELECT * FROM dental_caries_assessments WHERE patient_id = ? ORDER BY assessed_on DESC, id DESC LIMIT 12");
    $st->bind_param('i', $pid); $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) { $caries_hist[] = $r; }
    $st->close();
    if ($caries_hist) { $latest = $caries_hist[0]; }
    $c_group   = $latest ? $latest['age_group'] : mh_dental_age_group($age ?? 20);
    $c_answers = $latest ? mh_dental_clean_caries($c_group, mh_dental_json_decode($latest['answers'])) : [];

    $treatments = [];
    $st = $conn->prepare("SELECT t.*, s.first_name AS s_first, s.last_name AS s_last FROM dental_treatments t LEFT JOIN staff s ON s.id = t.dentist_id
                          WHERE t.patient_id = ? ORDER BY t.treatment_date DESC, t.id DESC");
    $st->bind_param('i', $pid); $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) { $treatments[] = $r; }
    $st->close();

    $legacy = [];   // visits logged with the old dental page (before charts existed)
    $st = $conn->prepare("SELECT d.*, s.first_name AS s_first, s.last_name AS s_last FROM dental_records d LEFT JOIN staff s ON s.id = d.dentist_id
                          WHERE d.patient_id = ? AND d.id NOT IN (SELECT dental_record_id FROM dental_treatments WHERE dental_record_id IS NOT NULL)
                          ORDER BY d.visit_date DESC, d.id DESC");
    $st->bind_param('i', $pid); $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) { $legacy[] = $r; }
    $st->close();

    $dn_data = [
        'patientId' => $pid, 'age' => $age, 'sex' => $sex, 'canEdit' => $can_log, 'tab' => $tab,
        'teeth' => mh_dental_teeth(), 'conditions' => mh_dental_tooth_conditions(), 'statuses' => mh_dental_tooth_statuses(),
        'odontogram' => (object) $odo,
        'caries' => ['child' => mh_dental_caries_questions('child'), 'adult' => mh_dental_caries_questions('adult')],
        'cariesGroup' => $c_group, 'cariesAnswers' => (object) $c_answers,
        'cariesFinal' => $latest['overall_final'] ?? '',
        'patientLast' => (string) $patient['last_name'], 'patientFirst' => (string) $patient['first_name'],
    ];
} else {
    /* ---------------- list view ---------------- */
    $dentalDepts = [];
    foreach (mh_options($conn, 'clinic_departments', true) as $o) {
        if (mh_department_module($o['option_value']) === 'dental') { $dentalDepts[] = $o['option_value']; }
    }
    if (!$dentalDepts) { $dentalDepts = ['Dental Clinic']; }

    $queue = [];
    $today = date('Y-m-d');
    $in = implode(',', array_fill(0, count($dentalDepts), '?'));
    $st = $conn->prepare("SELECT a.id AS apt_id, a.queue_number, a.status, p.id, p.first_name, p.middle_name, p.last_name, p.birthdate, p.sex
                          FROM appointments a JOIN patients p ON p.id = a.patient_id
                          WHERE a.department IN ($in) AND a.appointment_date = ? AND a.status IN ('Waiting','Approved')
                          ORDER BY a.queue_number ASC, a.id ASC");
    $types = str_repeat('s', count($dentalDepts) + 1);
    $params = array_merge($dentalDepts, [$today]);
    $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) { $queue[] = $r; }
    $st->close();

    $charts = [];
    if ($can_view_history) {
        $res = $conn->query("SELECT p.id, p.first_name, p.middle_name, p.last_name, p.birthdate, p.sex, c.id AS chart_id, c.updated_at,
                (SELECT MAX(visit_date) FROM dental_records d WHERE d.patient_id = p.id) AS last_visit,
                (SELECT a.overall_final FROM dental_caries_assessments a WHERE a.patient_id = p.id ORDER BY a.assessed_on DESC, a.id DESC LIMIT 1) AS risk
            FROM patients p LEFT JOIN dental_charts c ON c.patient_id = p.id
            WHERE p.status = 1 AND (c.id IS NOT NULL OR EXISTS (SELECT 1 FROM dental_records d2 WHERE d2.patient_id = p.id))
            ORDER BY p.last_name ASC, p.first_name ASC");
        while ($res && ($r = $res->fetch_assoc())) { $charts[] = $r; }
    }
    $all_patients = [];
    if ($can_log) {
        $res = $conn->query("SELECT id, first_name, middle_name, last_name FROM patients WHERE status = 1 ORDER BY last_name, first_name");
        while ($res && ($r = $res->fetch_assoc())) { $all_patients[] = $r; }
    }
}
$ver = @filemtime(__DIR__ . '/../assets/js/dental.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dental Records - <?php echo $h($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <link rel="stylesheet" href="../assets/css/dept_ui.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/dept_ui.css'); ?>">
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../includes/admin_topbar.php'; ?>
<div class="dashboard-container du-wrap">

<?php
echo mh_flash_scripts($success_msg, $error_msg);
?>

<?php if (!$patient): /* ===================== LIST VIEW ===================== */ ?>

    <div class="du-pagehead">
        <div>
            <h1><?php echo $h(mh_module_label('dental')); ?></h1>
            <p>Open a patient's dental chart, or scan the paper forms to pre-fill one.</p>
        </div>
        <?php if ($can_ocr): ?>
            <button type="button" class="du-btn du-btn-primary" id="dnOpenOcr"><?php echo mh_icon('camera'); ?> Scan / Upload Physical Dental Records</button>
        <?php endif; ?>
    </div>

    <div class="du-card">
        <h2 class="du-card-title">Today's dental queue</h2>
        <?php if (!$queue): ?>
            <p class="du-muted">Nobody is waiting for the dental department today.</p>
        <?php else: ?>
            <div class="du-table-wrap"><table class="du-table">
                <thead><tr><th>#</th><th>Patient</th><th>Age / Sex</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($queue as $q): $qa = dn_age($q['birthdate']); ?>
                    <tr>
                        <td><strong><?php echo $h($q['queue_number'] ?? '-'); ?></strong></td>
                        <td class="du-person"><?php echo mh_avatar_html(mh_initials($q['first_name'], $q['last_name']), 32, $q['first_name'] . ' ' . $q['last_name']); ?>
                            <span><?php echo $h(mh_format_patient_name($q['first_name'], $q['middle_name'], $q['last_name'])); ?></span></td>
                        <td><?php echo $qa === null ? '-' : (int) $qa . ' yrs'; ?> &middot; <?php echo $h($q['sex']); ?></td>
                        <td style="text-align:right;"><a class="du-btn du-btn-primary du-btn-sm" href="dental.php?patient=<?php echo (int) $q['id']; ?>&apt_id=<?php echo (int) $q['apt_id']; ?>">Open chart</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <?php if ($can_log): ?>
    <div class="du-card">
        <h2 class="du-card-title">Open or start a chart</h2>
        <form method="GET" class="du-inline-form">
            <input type="text" id="dnPatientFilter" placeholder="Type to filter patients..." autocomplete="off">
            <select name="patient" id="dnPatientSelect" required>
                <option value="">-- Select patient --</option>
                <?php foreach ($all_patients as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo $h(mh_format_patient_name($p['first_name'], $p['middle_name'], $p['last_name'])); ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="du-btn du-btn-primary">Open chart</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($can_view_history): ?>
    <div class="du-card">
        <div class="du-card-head">
            <h2 class="du-card-title">Dental charts &amp; records</h2>
            <input type="text" id="dnChartSearch" placeholder="Search by name..." autocomplete="off">
        </div>
        <div class="du-table-wrap"><table class="du-table" id="dnChartTable">
            <thead><tr><th>Patient</th><th>Age / Sex</th><th>Last visit</th><th>Caries risk</th><th>Chart</th><th></th></tr></thead>
            <tbody>
            <?php if (!$charts): ?><tr><td colspan="6" class="du-empty">No dental charts yet.</td></tr><?php endif; ?>
            <?php foreach ($charts as $c): $ca = dn_age($c['birthdate']); ?>
                <tr data-name="<?php echo $h(strtolower($c['last_name'] . ' ' . $c['first_name'])); ?>">
                    <td class="du-person"><?php echo mh_avatar_html(mh_initials($c['first_name'], $c['last_name']), 32, $c['first_name'] . ' ' . $c['last_name']); ?>
                        <span><?php echo $h(mh_format_patient_name($c['first_name'], $c['middle_name'], $c['last_name'])); ?></span></td>
                    <td><?php echo $ca === null ? '-' : (int) $ca . ' yrs'; ?> &middot; <?php echo $h($c['sex']); ?></td>
                    <td><?php echo $c['last_visit'] ? $h(date('M d, Y', strtotime($c['last_visit']))) : '&mdash;'; ?></td>
                    <td><?php if ($c['risk']): ?><span class="du-pill du-risk-<?php echo $h($c['risk']); ?>"><?php echo strtoupper($h($c['risk'])); ?></span><?php else: ?>&mdash;<?php endif; ?></td>
                    <td><?php echo $c['chart_id'] ? '<span class="du-pill du-pill-ok">Chart on file</span>' : '<span class="du-pill du-pill-muted">Visits only</span>'; ?></td>
                    <td style="text-align:right;"><a class="du-btn du-btn-ghost du-btn-sm" href="dental.php?patient=<?php echo (int) $c['id']; ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

<?php else: /* ===================== CHART VIEW ===================== */
    $fullName = mh_format_patient_name($patient['first_name'], $patient['middle_name'], $patient['last_name']);
    $isResident = function_exists('mh_is_marick_resident') ? mh_is_marick_resident($patient['address_type'] ?? '') : true;
    $dis = !$can_log;
?>

    <div class="du-patientbar">
        <div class="du-patient-main">
            <?php echo mh_avatar_html(mh_initials($patient['first_name'], $patient['last_name']), 56, $patient['first_name'] . ' ' . $patient['last_name']); ?>
            <div>
                <h1><?php echo $h($fullName); ?></h1>
                <p><?php echo $age === null ? 'Age unknown' : (int) $age . ' years old'; ?> &middot; <?php echo $h($sex); ?> &middot; Born <?php echo $h(date('M d, Y', strtotime($patient['birthdate']))); ?></p>
            </div>
        </div>
        <div class="du-patient-actions">
            <?php if ($can_ocr): ?><button type="button" class="du-btn du-btn-primary" id="dnOpenOcr"><?php echo mh_icon('camera'); ?> Scan / Upload Records</button><?php endif; ?>
            <button type="button" class="du-btn du-btn-ghost" onclick="window.print()"><?php echo mh_icon('file-text'); ?> Print</button>
            <a class="du-btn du-btn-ghost" href="dental.php">&larr; All charts</a>
        </div>
    </div>

    <div class="du-alerts" id="dnAlerts" data-initial="<?php echo $h(json_encode($alerts)); ?>">
        <?php foreach ($alerts as $a): ?><span class="du-alert-chip">&#9888; <?php echo $h($a); ?></span><?php endforeach; ?>
        <?php if (!$alerts): ?><span class="du-muted" id="dnNoAlerts">No medical alerts recorded.</span><?php endif; ?>
    </div>

    <div class="du-ocr-banner" id="dnOcrBanner" hidden></div>

    <nav class="du-tabs" id="dnTabs" role="tablist">
        <button type="button" class="du-tab" data-tab="A"><b>A</b><span>Patient &amp; Medical History</span></button>
        <button type="button" class="du-tab" data-tab="B"><b>B</b><span>Dental History &amp; Findings</span></button>
        <button type="button" class="du-tab" data-tab="C"><b>C</b><span>Odontogram</span></button>
        <button type="button" class="du-tab" data-tab="D"><b>D</b><span>Caries Risk</span></button>
        <button type="button" class="du-tab" data-tab="E"><b>E</b><span>Treatment &amp; Signatures</span></button>
    </nav>

    <form method="POST" id="dnForm" action="dental.php?patient=<?php echo (int) $pid; ?>" autocomplete="off">
    <fieldset class="du-fieldset" <?php echo $dis ? 'disabled' : ''; ?>>
        <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['dn_csrf']); ?>">
        <input type="hidden" name="patient_id" value="<?php echo (int) $pid; ?>">
        <input type="hidden" name="apt_id" value="<?php echo (int) $apt_id; ?>">
        <input type="hidden" name="active_tab" id="dnActiveTab" value="<?php echo $h($tab); ?>">
        <input type="hidden" name="odontogram" id="dnOdoInput" value="<?php echo $h(json_encode((object) $odo)); ?>">

        <!-- ================= TAB A ================= -->
        <section class="du-panel" data-panel="A">
            <div class="du-card">
                <h2 class="du-card-title">Patient information</h2>
                <div class="du-grid du-grid-3">
                    <div class="du-ro"><span>Name</span><strong><?php echo $h($fullName); ?></strong></div>
                    <div class="du-ro"><span>Date of birth</span><strong><?php echo $h(date('F d, Y', strtotime($patient['birthdate']))); ?></strong></div>
                    <div class="du-ro"><span>Age / Sex</span><strong><?php echo $age === null ? '-' : (int) $age . ' yrs'; ?> / <?php echo $h($sex); ?></strong></div>
                    <div class="du-ro"><span>Barangay</span><strong><?php echo $isResident ? 'Sto. Domingo (resident)' : 'Outside the barangay'; ?></strong></div>
                    <div class="du-ro du-span2"><span>Address</span><strong><?php echo $h($patient['address'] ?: '-'); ?></strong></div>
                    <div class="du-ro"><span>Contact no.</span><strong><?php echo $h($patient['contact_number'] ?: '-'); ?></strong></div>
                </div>
                <p class="du-hint">These come from the patient's registration. Correct them in the Patient Masterlist.</p>
                <div class="du-grid du-grid-3" style="margin-top:14px;">
                    <?php foreach (mh_dental_specs('extra') as $k => $spec): echo mh_dental_field("extra[$k]", $spec, $extra[$k], $dis); endforeach; ?>
                </div>
            </div>

            <div class="du-card">
                <h2 class="du-card-title">Other patient information <small>(vulnerable sectors)</small></h2>
                <div class="du-checks">
                    <?php foreach (mh_dental_sectors() as $k => $label): echo mh_dental_field("sectors[$k]", ['check', $label], $sectors[$k], $dis); endforeach; ?>
                </div>
            </div>

            <div class="du-card">
                <h2 class="du-card-title">Medical history</h2>
                <?php $n = 0; foreach (mh_dental_medical_questions() as $k => $def): $n++; ?>
                    <div class="du-qrow">
                        <div class="du-q"><span class="du-qn"><?php echo $n; ?>.</span> <?php echo $h($def[0]); ?></div>
                        <?php echo mh_dental_field("mh[q][$k]", ['yn', 'Answer'], $mh['q'][$k], $dis); ?>
                        <?php if ($def[1] !== null): echo mh_dental_field("mh[detail][$k]", ['text', $def[1]], $mh['detail'][$k], $dis, "mh[q][$k]=y"); endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="du-qrow">
                    <div class="du-q"><span class="du-qn">7.</span> Are you allergic to any of the following?</div>
                    <div class="du-checks">
                        <?php foreach (mh_dental_allergies() as $k => $label): echo mh_dental_field("mh[allergy][$k]", ['check', $label], $mh['allergy'][$k], $dis); endforeach; ?>
                    </div>
                    <?php echo mh_dental_field('mh[allergy_other]', ['text', 'Others (specify)'], $mh['allergy_other'], $dis); ?>
                </div>

                <div class="du-qrow">
                    <div class="du-q"><span class="du-qn">8.</span> For women only</div>
                    <?php if (strcasecmp($sex, 'Female') === 0): ?>
                        <?php foreach (mh_dental_women_questions() as $k => $label): echo mh_dental_field("mh[women][$k]", ['yn', $label], $mh['women'][$k], $dis); endforeach; ?>
                        <?php echo mh_dental_field('mh[pregnant_months]', ['text', 'If pregnant, indicate months'], $mh['pregnant_months'], $dis, 'mh[women][pregnant]=y'); ?>
                    <?php else: ?>
                        <p class="du-hint" style="margin:0;">Shown for female patients only.</p>
                    <?php endif; ?>
                </div>

                <div class="du-qrow">
                    <div class="du-q"><span class="du-qn">9.</span> Do you have or have you had any of the following? Check which apply.</div>
                    <div class="du-checks du-checks-wide">
                        <?php foreach (mh_dental_conditions() as $k => $label): echo mh_dental_field("mh[cond][$k]", ['check', $label], $mh['cond'][$k], $dis); endforeach; ?>
                    </div>
                    <?php echo mh_dental_field('mh[cond_other]', ['text', 'Others (specify)'], $mh['cond_other'], $dis); ?>
                </div>
            </div>

            <div class="du-card">
                <h2 class="du-card-title">Data privacy consent &amp; waiver</h2>
                <div class="du-legal">
                    <h4>Data Privacy Consent</h4>
                    <p>Pinapatunayan ko na ang lahat ng impormasyong ibinigay ay tama at totoo. Pinahihintulutan ko ang pagproseso ng aking mga personal na datos, kabilang na ang resulta ng aking dental na operasyon at pagsusuri na makukuha mula sa akin ng pasilidad na ito. Gayundin, ang naturang pasilidad ay pinahihintulutan ko na gamitin at iproseso ang naturang datos o impormasyon para sa anumang kadahilanang may kaugnayan sa aking dental na operasyon at pagsusuri.</p>
                    <p>Pinagtitibay ko ang aking Karapatan na maabisuhan, tumutol sa pagproseso, magkaroon ng kopya at Maitama, ipasuspinde o bawiin ang aking personal na datos at makatanggap ng danyos kung sakaling may pinsala, alinsunod sa mga probisyon ng Republic Act No. 10173 ang Data Privacy Act of 2012 at ang mga regulasyon kaugnay nito.</p>
                    <label class="du-check du-check-strong"><input type="checkbox" name="consent_privacy" value="1" <?php echo !empty($chart['consent_privacy']) ? 'checked' : ''; ?>><span>Nabasa at sumasang-ayon ang pasyente sa Data Privacy Consent</span></label>
                </div>
                <div class="du-legal">
                    <h4>Waiver</h4>
                    <p>Ako ay nasa mabuting kalusugan (i.e. walang sakit sa puso, hypertension, diabetes, pagdurugo, AIDS, sakit sa buto o iba pang karamdaman) at maaring magpabunot ng ngipin ng walang peligro sa akin. Ako ay nakapagkonsulta na sa doctor at walang pagbabawal sa akin na bunutan ng ngipin.</p>
                    <p>Ako ay sumasang-ayon na kung mayroong mali o huwad na impormasyon sa anumang aking ipinapahayag na walang magiging pananagutan ang dentistang kokonsultahin ko at magbubunot ng aking ngipin. Hindi ako magsasampa ng anumang reklamo o demanda laban sa sino man sa kanila sa korte o kung saang ahensyang pamahalaan o organisasyon kaugnay sa aking pagpapabunot ng ngipin o sa anumang magiging resulta nito.</p>
                    <p>Susundin ko ang lahat ng magiging bilin ng aking dentista pagkatapos na ako ay bunutan at iinumin ko sa tamang paraan at tagal ang gamot na maaring ibigay o ireseta sa akin.</p>
                    <p>Nalintindihan ko ang salysay na ito at malaya ko itong ginawa at nilagdaan.</p>
                    <label class="du-check du-check-strong"><input type="checkbox" name="consent_waiver" value="1" <?php echo !empty($chart['consent_waiver']) ? 'checked' : ''; ?>><span>Nabasa at sumasang-ayon ang pasyente sa Waiver</span></label>
                </div>
                <div class="du-grid du-grid-3">
                    <?php echo mh_dental_field('consent_name', ['text', 'Buong pangalan (patient or guardian who agreed)'], $chart['consent_name'] ?? '', $dis); ?>
                    <?php echo mh_dental_field('consent_date', ['date', 'Petsa'], $chart['consent_date'] ?? '', $dis); ?>
                </div>
                <p class="du-hint">This records that consent was given. The signed paper form stays on file; the printed copy has signature lines.</p>
            </div>
        </section>

        <!-- ================= TAB B ================= -->
        <section class="du-panel" data-panel="B">
            <div class="du-card">
                <h2 class="du-card-title">Dental history</h2>
                <div class="du-grid du-grid-2">
                    <?php foreach (mh_dental_specs('dental_history') as $k => $spec):
                        $when = ($k === 'prosthesis_since') ? 'dental_history[prosthesis]=y' : '';
                        echo mh_dental_field("dental_history[$k]", $spec, $dh[$k], $dis, $when);
                    endforeach; ?>
                </div>
            </div>

            <div class="du-card">
                <h2 class="du-card-title">Clinical findings <small>(A)</small></h2>
                <?php $cs = mh_dental_specs('clinical'); ?>
                <div class="du-grid du-grid-2">
                    <?php echo mh_dental_field('clinical[periodontal_affected]', $cs['periodontal_affected'], $cl['periodontal_affected'], $dis);
                          echo mh_dental_field('clinical[mobility_grade]', $cs['mobility_grade'], $cl['mobility_grade'], $dis);
                          echo mh_dental_field('clinical[torus]', $cs['torus'], $cl['torus'], $dis);
                          echo mh_dental_field('clinical[epulis]', $cs['epulis'], $cl['epulis'], $dis);
                          echo mh_dental_field('clinical[epulis_detail]', $cs['epulis_detail'], $cl['epulis_detail'], $dis, 'clinical[epulis]=y'); ?>
                </div>
                <h4 class="du-sub">A.4 With prosthesis</h4>
                <div class="du-checks">
                    <?php foreach (['cd_upper', 'cd_lower', 'rpd_upper', 'rpd_lower', 'fpd_upper', 'fpd_lower'] as $k): echo mh_dental_field("clinical[$k]", $cs[$k], $cl[$k], $dis); endforeach; ?>
                </div>
                <div class="du-grid du-grid-3" style="margin-top:12px;">
                    <?php echo mh_dental_field('clinical[prosthesis_years]', $cs['prosthesis_years'], $cl['prosthesis_years'], $dis);
                          echo mh_dental_field('clinical[ridge_upper]', $cs['ridge_upper'], $cl['ridge_upper'], $dis);
                          echo mh_dental_field('clinical[ridge_lower]', $cs['ridge_lower'], $cl['ridge_lower'], $dis); ?>
                </div>
                <h4 class="du-sub">Treatment needed <small>(B)</small></h4>
                <div class="du-checks">
                    <?php foreach (['tx_scaling', 'tx_extraction', 'tx_surgical', 'tx_biopsy', 'tx_repair', 'tx_replacement'] as $k): echo mh_dental_field("clinical[$k]", $cs[$k], $cl[$k], $dis); endforeach; ?>
                </div>
            </div>

            <div class="du-card">
                <h2 class="du-card-title">Oral health status</h2>
                <div class="du-grid du-grid-2">
                    <?php foreach (mh_dental_specs('oral_health') as $k => $spec):
                        $when = ($k === 'orofacial_type') ? 'oral_health[orofacial]=y' : (($k === 'oral_lesions_type') ? 'oral_health[oral_lesions]=y' : '');
                        echo mh_dental_field("oral_health[$k]", $spec, $oh[$k], $dis, $when);
                    endforeach; ?>
                </div>
                <p class="du-hint">Tip: on the Odontogram tab, <em>Copy counts to Oral Health Status</em> fills the tooth counts for you.</p>
            </div>
        </section>

        <!-- ================= TAB C ================= -->
        <section class="du-panel" data-panel="C">
            <div class="du-card">
                <div class="du-card-head">
                    <h2 class="du-card-title">Odontogram <small>(tooth chart)</small></h2>
                    <div class="du-toolbar">
                        <span class="du-seg" id="dnArchView">
                            <button type="button" data-view="both">Both</button>
                            <button type="button" data-view="permanent">Permanent</button>
                            <button type="button" data-view="primary">Primary</button>
                        </span>
                        <label class="du-check"><input type="checkbox" id="dnMulti"><span>Select several teeth</span></label>
                    </div>
                </div>
                <p class="du-hint">Click a tooth, then choose its condition and status. Hold Shift or Ctrl/Cmd (or tick &ldquo;Select several&rdquo;) to pick more than one.</p>
                <div class="du-chart" id="dnChart"></div>

                <div class="du-picker" id="dnPicker">
                    <div class="du-picker-head"><strong id="dnPickerTitle">No tooth selected</strong>
                        <span class="du-picker-actions">
                            <button type="button" class="du-btn du-btn-ghost du-btn-sm" id="dnSelClear">Clear selected</button>
                            <button type="button" class="du-btn du-btn-ghost du-btn-sm" id="dnAllSound">Mark all teeth sound</button>
                            <button type="button" class="du-btn du-btn-ghost du-btn-sm" id="dnResetAll">Reset chart</button>
                        </span>
                    </div>
                    <div class="du-picker-cols">
                        <div><h4 class="du-sub">Tooth condition</h4><div class="du-opts" id="dnCondOpts"></div></div>
                        <div><h4 class="du-sub">Tooth status</h4><div class="du-opts" id="dnStatOpts"></div></div>
                    </div>
                </div>

                <div class="du-counts" id="dnCounts"></div>
                <button type="button" class="du-btn du-btn-ghost du-btn-sm" id="dnCopyCounts">Copy counts to Oral Health Status</button>
            </div>

            <div class="du-card">
                <h2 class="du-card-title">Legend</h2>
                <div class="du-legend">
                    <div><h4 class="du-sub">Tooth condition</h4>
                        <table class="du-table du-table-sm"><thead><tr><th>Permanent</th><th>Condition</th><th>Primary</th></tr></thead><tbody>
                        <?php foreach (mh_dental_tooth_conditions() as $k => $c): ?><tr><td><b><?php echo $h($c[1]); ?></b></td><td><span class="du-dot" style="background:<?php echo $h($c[3]); ?>"></span> <?php echo $h($c[0]); ?></td><td><b><?php echo $h($c[2]); ?></b></td></tr><?php endforeach; ?>
                        </tbody></table></div>
                    <div><h4 class="du-sub">Tooth status</h4>
                        <table class="du-table du-table-sm"><thead><tr><th>Permanent</th><th>Status</th><th>Primary</th></tr></thead><tbody>
                        <?php foreach (mh_dental_tooth_statuses() as $k => $s): ?><tr><td><b><?php echo $h($s[1]); ?></b></td><td><?php echo $h($s[0]); ?></td><td><b><?php echo $h($s[2]); ?></b></td></tr><?php endforeach; ?>
                        </tbody></table></div>
                </div>
            </div>
        </section>

        <!-- ================= TAB D ================= -->
        <section class="du-panel" data-panel="D">
            <div class="du-card">
                <div class="du-card-head">
                    <h2 class="du-card-title">Caries risk assessment</h2>
                    <span class="du-seg" id="dnCariesGroup">
                        <button type="button" data-group="child">Ages 5 and below</button>
                        <button type="button" data-group="adult">Ages 6 and above</button>
                    </span>
                </div>
                <input type="hidden" name="caries_group" id="dnCariesGroupInput" value="<?php echo $h($c_group); ?>">
                <p class="du-hint" id="dnCariesAuto">The form shown follows the patient's age (<?php echo $age === null ? 'unknown' : (int) $age . ' years'; ?>). You can switch it.</p>
                <div id="dnCaries"></div>
            </div>

            <div class="du-card du-summary" id="dnCariesSummary">
                <h2 class="du-card-title">Assessment summary</h2>
                <div class="du-sumgrid" id="dnSumGrid"></div>
                <div class="du-grid du-grid-3" style="margin-top:14px;">
                    <div class="du-field"><span class="du-label">Overall assessment (final)</span>
                        <select name="caries_final" id="dnFinal"><option value="">-- Use clinical judgment --</option><option value="low">LOW risk</option><option value="high">HIGH risk</option></select></div>
                    <div class="du-field"><span class="du-label">Date of assessment</span>
                        <input type="date" name="caries_date" id="dnCariesDate" value="<?php echo $h($latest['assessed_on'] ?? date('Y-m-d')); ?>"></div>
                </div>
                <p class="du-hint">Rule from the form: HIGH when high-risk factors outnumber or equal low-risk factors; LOW when low-risk factors outnumber them by at least five; in between needs clinical judgment. Section results show whichever side has more answers.</p>
            </div>

            <?php if ($caries_hist): ?>
            <div class="du-card">
                <h2 class="du-card-title">Previous assessments</h2>
                <table class="du-table du-table-sm"><thead><tr><th>Date</th><th>Form</th><th>Disease</th><th>Socio-economic</th><th>Protective</th><th>Overall</th></tr></thead><tbody>
                <?php foreach ($caries_hist as $r): $rs = function ($v) use ($h) { return $v ? '<span class="du-pill du-risk-' . $h($v) . '">' . strtoupper($h($v)) . '</span>' : '&mdash;'; }; ?>
                    <tr><td><?php echo $h(date('M d, Y', strtotime($r['assessed_on']))); ?></td><td><?php echo $r['age_group'] === 'child' ? '5 &amp; below' : '6 &amp; above'; ?></td>
                        <td><?php echo $rs($r['disease_result']); ?></td><td><?php echo $rs($r['socio_result']); ?></td><td><?php echo $rs($r['protective_result']); ?></td>
                        <td><?php echo $rs($r['overall_final'] ?: $r['overall_computed']); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php endif; ?>
        </section>
    </fieldset>
    </form>

    <!-- ================= TAB E (own form) ================= -->
    <section class="du-panel" data-panel="E">
        <?php if ($can_log): ?>
        <div class="du-card">
            <h2 class="du-card-title">Record a treatment</h2>
            <form method="POST" action="dental.php?patient=<?php echo (int) $pid; ?>" id="dnTreatForm" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['dn_csrf']); ?>">
                <input type="hidden" name="patient_id" value="<?php echo (int) $pid; ?>">
                <input type="hidden" name="apt_id" value="<?php echo (int) $apt_id; ?>">
                <input type="hidden" name="active_tab" value="E">
                <div class="du-grid du-grid-3">
                    <div class="du-field"><span class="du-label">Date</span><input type="date" name="treatment_date" id="dnTDate" value="<?php echo $h(date('Y-m-d')); ?>" required></div>
                    <div class="du-field"><span class="du-label">Tooth number(s)</span><input type="text" name="tooth_numbers" id="dnTTeeth" maxlength="100" placeholder="e.g. 16, 26"></div>
                    <div class="du-field"><span class="du-label">Referrals</span><input type="text" name="referrals" id="dnTRefer" maxlength="255"></div>
                </div>
                <div class="du-grid du-grid-2" style="margin-top:12px;">
                    <div><h4 class="du-sub">Routine preventive oral care</h4><div class="du-checks">
                        <?php foreach (mh_dental_routine_codes() as $code => $label): ?><label class="du-check"><input type="checkbox" name="routine[]" value="<?php echo $h($code); ?>" data-code="<?php echo $h($code); ?>"><span><b><?php echo $h($code); ?></b> &middot; <?php echo $h($label); ?></span></label><?php endforeach; ?>
                    </div></div>
                    <div><h4 class="du-sub">Other oral health services</h4><div class="du-checks">
                        <?php foreach (mh_dental_other_codes() as $code => $label): ?><label class="du-check"><input type="checkbox" name="other[]" value="<?php echo $h($code); ?>" data-code="<?php echo $h($code); ?>"><span><b><?php echo $h($code); ?></b> &middot; <?php echo $h($label); ?></span></label><?php endforeach; ?>
                    </div></div>
                </div>
                <div class="du-field" style="margin-top:12px;"><span class="du-label">Remarks</span><textarea name="remarks" id="dnTRemarks" rows="2" maxlength="1000" placeholder="Procedure with no code goes here"></textarea></div>
                <p class="du-hint">For other oral health services, include the tooth number. For referrals, include the reason. The dentist is recorded automatically from your login.</p>
                <button type="submit" name="add_treatment" class="du-btn du-btn-primary">Save treatment</button>
            </form>
        </div>
        <div class="du-card" id="dnScannedTreat" hidden></div>
        <?php endif; ?>

        <div class="du-card">
            <h2 class="du-card-title">Individual treatment record</h2>
            <div class="du-table-wrap"><table class="du-table" id="dnTreatTable">
                <thead><tr><th>Date</th><th>Routine preventive care</th><th>Other oral health services</th><th>Referrals</th><th>Remarks</th><th>Dentist's signature</th></tr></thead>
                <tbody>
                <?php if (!$treatments && !$legacy): ?><tr><td colspan="6" class="du-empty">No treatments recorded yet.</td></tr><?php endif; ?>
                <?php foreach ($treatments as $t): ?>
                    <tr>
                        <td><?php echo $h(date('M d, Y', strtotime($t['treatment_date']))); ?></td>
                        <td><?php echo $h(str_replace(',', ', ', (string) $t['routine_codes'])) ?: '&mdash;'; ?></td>
                        <td><?php echo $h(str_replace(',', ', ', (string) $t['other_codes'])); ?><?php echo $t['tooth_numbers'] ? ' <small>(tooth ' . $h($t['tooth_numbers']) . ')</small>' : ''; ?></td>
                        <td><?php echo $h($t['referrals']) ?: '&mdash;'; ?></td>
                        <td><?php echo $h($t['remarks']) ?: '&mdash;'; ?></td>
                        <td><?php echo $h(trim(($t['s_first'] ?? '') . ' ' . ($t['s_last'] ?? ''))); ?><div class="du-sigline"></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($legacy as $t): ?>
                    <tr class="du-legacy">
                        <td><?php echo $h(date('M d, Y', strtotime($t['visit_date']))); ?></td>
                        <td colspan="3"><?php echo $h($t['procedure_done']); ?><?php echo $t['tooth_number'] ? ' <small>(tooth ' . $h($t['tooth_number']) . ')</small>' : ''; ?> <span class="du-pill du-pill-muted">earlier record</span></td>
                        <td><?php echo $h($t['notes']) ?: '&mdash;'; ?></td>
                        <td><?php echo $h(trim(($t['s_first'] ?? '') . ' ' . ($t['s_last'] ?? ''))); ?><div class="du-sigline"></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="du-sigblock"><div><div class="du-sigline"></div><span>Assessed by &mdash; name of dentist / dental hygienist</span></div><div><div class="du-sigline"></div><span>Position</span></div></div>
        </div>

        <div class="du-card">
            <h2 class="du-card-title">Legend</h2>
            <div class="du-legend">
                <table class="du-table du-table-sm"><thead><tr><th colspan="2">Routine preventive care</th></tr></thead><tbody>
                    <?php foreach (mh_dental_routine_codes() as $code => $label): ?><tr><td><?php echo $h($label); ?></td><td><b><?php echo $h($code); ?></b></td></tr><?php endforeach; ?></tbody></table>
                <table class="du-table du-table-sm"><thead><tr><th colspan="2">Other dental procedure</th></tr></thead><tbody>
                    <?php foreach (mh_dental_other_codes() as $code => $label): ?><tr><td><?php echo $h($label); ?></td><td><b><?php echo $h($code); ?></b></td></tr><?php endforeach; ?></tbody></table>
            </div>
        </div>
    </section>

    <div class="du-savebar" id="dnSaveBar">
        <div class="du-steps">
            <button type="button" class="du-btn du-btn-ghost" id="dnPrev">&larr; Back</button>
            <button type="button" class="du-btn du-btn-ghost" id="dnNext">Next &rarr;</button>
        </div>
        <span class="du-dirty" id="dnDirty" hidden>Unsaved changes</span>
        <?php if ($can_log): ?><button type="submit" form="dnForm" name="save_chart" value="1" class="du-btn du-btn-primary" id="dnSave">Save chart</button><?php endif; ?>
    </div>

    <script>window.DN = <?php echo json_encode($dn_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<?php endif; ?>

<?php if ($can_ocr): ?>
    <!-- ================= OCR MODAL ================= -->
    <div class="du-modal" id="dnOcrModal" hidden>
        <div class="du-modal-box" role="dialog" aria-modal="true" aria-labelledby="dnOcrTitle">
            <div class="du-modal-head"><h2 id="dnOcrTitle">Scan / Upload Physical Dental Records</h2><button type="button" class="du-x" id="dnOcrClose" aria-label="Close">&times;</button></div>
            <p class="du-hint">Add one photo or scan per page (JPG, PNG, WEBP or PDF) &mdash; treatment record, medical history, odontogram, caries risk. The pages are read one by one and the results are combined. Nothing is saved until you review and press <b>Save chart</b>. The images are not stored.</p>
            <label class="du-drop" id="dnDrop">
                <input type="file" id="dnFiles" accept="image/jpeg,image/png,image/webp,image/heic,application/pdf" multiple class="du-sr">
                <span><?php echo mh_icon('camera'); ?> <b>Choose pages</b> or drop them here</span>
            </label>
            <ul class="du-files" id="dnFileList"></ul>
            <div class="du-ocr-result" id="dnOcrResult" hidden></div>
            <div class="du-modal-foot">
                <button type="button" class="du-btn du-btn-ghost" id="dnOcrCancel">Close</button>
                <button type="button" class="du-btn du-btn-primary" id="dnOcrStart" disabled>Scan &amp; read pages</button>
            </div>
        </div>
    </div>
<?php endif; ?>

</div><!-- /dashboard-container -->
</div><!-- /main-content -->
<script>window.DN_CFG = <?php echo json_encode(['scanUrl' => 'ocr/dental_scan.php', 'onChart' => (bool) $patient, 'canOcr' => (bool) $can_ocr]); ?>;</script>
<script src="../assets/js/dental.js?v=<?php echo (int) $ver; ?>"></script>
</body>
</html>
