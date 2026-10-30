<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';

// BHW intake page: vital signs only. Doctors / midwives / dental / admin can use it too (see
// System Settings -> Staff Roles & Permissions -> "Vital Signs Entry"). Health Head always.
require_permission('log_vital_signs');

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role    = (string) ($_SESSION['role'] ?? '');

// Table is created on first visit; database/health_head_rbac_migration.sql creates it too.
$conn->query("CREATE TABLE IF NOT EXISTS vital_signs (
    id INT NOT NULL AUTO_INCREMENT,
    patient_id INT NOT NULL,
    recorded_by INT NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bp VARCHAR(20) DEFAULT NULL,
    temp VARCHAR(20) DEFAULT NULL,
    pulse VARCHAR(20) DEFAULT NULL,
    resp_rate VARCHAR(20) DEFAULT NULL,
    spo2 VARCHAR(20) DEFAULT NULL,
    weight VARCHAR(20) DEFAULT NULL,
    height VARCHAR(20) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_patient_time (patient_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (empty($_SESSION['vs_csrf'])) { $_SESSION['vs_csrf'] = bin2hex(random_bytes(16)); }

$success_msg = ''; $error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vitals'])) {
    require_permission('log_vital_signs');   // re-check on the write itself
    $tok = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['vs_csrf'], $tok)) {
        $error_msg = 'Your session form expired. Please try again.';
    } else {
        $patient_id = (int) ($_POST['patient_id'] ?? 0);
        $f = function ($k, $max = 20) { return mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max); };
        $bp = $f('bp'); $temp = $f('temp'); $pulse = $f('pulse'); $rr = $f('resp_rate');
        $spo2 = $f('spo2'); $weight = $f('weight'); $height = $f('height'); $notes = $f('notes', 255);

        $chk = $conn->prepare("SELECT id FROM patients WHERE id = ? AND status = 1 LIMIT 1");
        $chk->bind_param('i', $patient_id); $chk->execute();
        $exists = $chk->get_result()->num_rows > 0; $chk->close();

        if (!$exists) {
            $error_msg = 'Please choose a registered patient.';
        } elseif ($bp === '' && $temp === '' && $pulse === '' && $rr === '' && $spo2 === '' && $weight === '' && $height === '') {
            $error_msg = 'Enter at least one vital sign.';
        } else {
            $ins = $conn->prepare("INSERT INTO vital_signs (patient_id, recorded_by, bp, temp, pulse, resp_rate, spo2, weight, height, notes)
                                   VALUES (?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param('iissssssss', $patient_id, $user_id, $bp, $temp, $pulse, $rr, $spo2, $weight, $height, $notes);
            if ($ins->execute()) {
                logActivity($conn, $user_id, $role, 'Vital Signs Recorded', "Recorded vital signs for Patient ID #$patient_id.");
                $_SESSION['vs_success'] = 'Vital signs saved.';
                $ins->close();
                header('Location: vital_signs.php');   // PRG: refresh does not resubmit
                exit();
            }
            $error_msg = 'Could not save the record. Please try again.';
            $ins->close();
        }
    }
}
if (isset($_SESSION['vs_success'])) { $success_msg = $_SESSION['vs_success']; unset($_SESSION['vs_success']); }

$patients = [];
$res = $conn->query("SELECT id, first_name, middle_name, last_name FROM patients WHERE status = 1 ORDER BY last_name, first_name");
while ($res && ($r = $res->fetch_assoc())) { $patients[] = $r; }

$recent = [];
$res = $conn->query("SELECT v.*, p.first_name, p.middle_name, p.last_name, s.first_name AS s_first, s.last_name AS s_last
                     FROM vital_signs v
                     JOIN patients p ON p.id = v.patient_id
                     LEFT JOIN staff s ON s.id = v.recorded_by
                     ORDER BY v.recorded_at DESC, v.id DESC LIMIT 25");
while ($res && ($r = $res->fetch_assoc())) { $recent[] = $r; }
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vital Signs - Marick Health Center</title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .vs-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 14px 0; }
        .vs-grid label, .vs-field label { display: block; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted, #64748b); margin-bottom: 4px; }
        .vs-grid input, .vs-field input, .vs-field select { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; background: var(--card-bg, #fff); color: var(--text-color, #1e293b); }
        .vs-msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
        .vs-ok  { background: #dcfce7; color: #166534; }
        .vs-err { background: #fee2e2; color: #991b1b; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>
    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>
    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>
        <div class="dashboard-container">

            <div class="unified-panel">
                <h2 style="margin:0 0 6px 0;">Record Vital Signs</h2>
                <p style="margin:0 0 14px 0; color:var(--text-muted, #64748b);">Intake only. Diagnosis and treatment are recorded by the doctor.</p>

                <?php if ($success_msg): ?><div class="vs-msg vs-ok"><?php echo $h($success_msg); ?></div><?php endif; ?>
                <?php if ($error_msg): ?><div class="vs-msg vs-err"><?php echo $h($error_msg); ?></div><?php endif; ?>

                <form method="POST" action="vital_signs.php" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['vs_csrf']); ?>">
                    <div class="vs-field">
                        <label for="patientFilter">Patient</label>
                        <input type="text" id="patientFilter" placeholder="Type to filter by name..." style="margin-bottom:8px;">
                        <select name="patient_id" id="patientSelect" required size="1">
                            <option value="">-- Select patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>"><?php echo $h(mh_format_patient_name($p['first_name'], $p['middle_name'], $p['last_name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vs-grid">
                        <div><label>BP (mmHg)</label><input type="text" name="bp" maxlength="20" placeholder="120/80"></div>
                        <div><label>Temp (&deg;C)</label><input type="text" name="temp" maxlength="20" placeholder="36.5"></div>
                        <div><label>Pulse (bpm)</label><input type="text" name="pulse" maxlength="20"></div>
                        <div><label>Resp. rate</label><input type="text" name="resp_rate" maxlength="20"></div>
                        <div><label>SpO&#8322; (%)</label><input type="text" name="spo2" maxlength="20"></div>
                        <div><label>Weight (kg)</label><input type="text" name="weight" maxlength="20"></div>
                        <div><label>Height (cm)</label><input type="text" name="height" maxlength="20"></div>
                    </div>
                    <div class="vs-field"><label>Notes (optional)</label><input type="text" name="notes" maxlength="255"></div>
                    <button type="submit" name="save_vitals" class="btn-primary" style="margin-top:14px;">Save Vital Signs</button>
                </form>
            </div>

            <div class="unified-panel" style="margin-top:20px;">
                <h2 style="margin:0 0 12px 0; font-size:1.2rem;">Recent Entries</h2>
                <div style="overflow-x:auto;">
                <table class="unified-table">
                    <thead><tr><th>Date</th><th>Patient</th><th>BP</th><th>Temp</th><th>Pulse</th><th>RR</th><th>SpO&#8322;</th><th>Wt</th><th>Ht</th><th>Recorded by</th></tr></thead>
                    <tbody>
                    <?php if ($recent): foreach ($recent as $r): ?>
                        <tr>
                            <td><?php echo $h(date('M d, Y g:i A', strtotime($r['recorded_at']))); ?></td>
                            <td><strong><?php echo $h(mh_format_patient_name($r['first_name'], $r['middle_name'], $r['last_name'])); ?></strong></td>
                            <td><?php echo $h($r['bp']); ?></td><td><?php echo $h($r['temp']); ?></td>
                            <td><?php echo $h($r['pulse']); ?></td><td><?php echo $h($r['resp_rate']); ?></td>
                            <td><?php echo $h($r['spo2']); ?></td><td><?php echo $h($r['weight']); ?></td>
                            <td><?php echo $h($r['height']); ?></td>
                            <td><?php echo $h(trim(($r['s_first'] ?? '') . ' ' . ($r['s_last'] ?? ''))); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="10" style="text-align:center; padding:24px; color:var(--text-muted, #64748b);">No vital signs recorded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var f = document.getElementById('patientFilter'), s = document.getElementById('patientSelect');
            f.addEventListener('input', function () {
                var q = f.value.toLowerCase();
                for (var i = 1; i < s.options.length; i++) {
                    s.options[i].hidden = q !== '' && s.options[i].text.toLowerCase().indexOf(q) === -1;
                }
            });
        })();
    </script>
</body>
</html>
