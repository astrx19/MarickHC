<?php
/**
 * Generic page for a custom department (one whose function is "Custom department page").
 * Departments linked to a module (Consultations, Dental, ...) redirect to that module's own page.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role    = (string) ($_SESSION['role'] ?? '');
$dept_id = (int) ($_GET['id'] ?? 0);
$st = $conn->prepare("SELECT option_value FROM system_options WHERE id = ? AND list_key = 'clinic_departments' AND is_active = 1");
$st->bind_param('i', $dept_id); $st->execute();
$row = $st->get_result()->fetch_assoc(); $st->close();
if (!$row) { header('Location: dashboard.php'); exit(); }
$dept = $row['option_value'];

// Who may use a custom department is set under System Settings -> Departments (per role)
// and Staff Management (per person). Health Head always may.
$can_view = $can_write = mh_dept_user_can($dept);
if (!$can_view) { mh_deny_access(); }

$page = mh_department_page($dept);                 // linked to a module -> use that module's page
if (mh_department_module($dept) !== null && $page) { header('Location: ' . $page); exit(); }

$conn->query("CREATE TABLE IF NOT EXISTS department_visits (
    id INT NOT NULL AUTO_INCREMENT,
    department VARCHAR(100) NOT NULL,
    patient_id INT NOT NULL,
    recorded_by INT NOT NULL,
    visit_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dept_date (department, visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (empty($_SESSION['deptpage_csrf'])) { $_SESSION['deptpage_csrf'] = bin2hex(random_bytes(16)); }
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$success_msg = $_SESSION['deptpage_ok'] ?? ''; $error_msg = $_SESSION['deptpage_err'] ?? '';
unset($_SESSION['deptpage_ok'], $_SESSION['deptpage_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_visit'])) {
    if (!$can_write) { mh_deny_access(); }
    if (!hash_equals($_SESSION['deptpage_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['deptpage_err'] = 'Your session form expired. Please try again.';
    } else {
        $apt_id     = (int) ($_POST['appointment_id'] ?? 0);
        $patient_id = (int) ($_POST['patient_id'] ?? 0);
        $notes      = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000);

        if ($apt_id > 0) {   // completing someone from today's queue: the queue entry decides the patient
            $st = $conn->prepare("SELECT patient_id FROM appointments WHERE id = ? AND department = ?");
            $st->bind_param('is', $apt_id, $dept); $st->execute();
            $a = $st->get_result()->fetch_assoc(); $st->close();
            $patient_id = $a ? (int) $a['patient_id'] : 0;
        }
        $st = $conn->prepare("SELECT id FROM patients WHERE id = ? AND status = 1");
        $st->bind_param('i', $patient_id); $st->execute();
        $exists = $st->get_result()->num_rows > 0; $st->close();

        if (!$exists) {
            $_SESSION['deptpage_err'] = 'Please choose a registered patient.';
        } else {
            $today = date('Y-m-d');
            $st = $conn->prepare("INSERT INTO department_visits (department, patient_id, recorded_by, visit_date, notes) VALUES (?, ?, ?, ?, ?)");
            $st->bind_param('siiss', $dept, $patient_id, $user_id, $today, $notes);
            $ok = $st->execute(); $st->close();
            if ($ok && $apt_id > 0) {
                $st = $conn->prepare("UPDATE appointments SET status = 'Completed' WHERE id = ? AND department = ?");
                $st->bind_param('is', $apt_id, $dept); $st->execute(); $st->close();
            }
            if ($ok) {
                logActivity($conn, $user_id, $role, 'Department Visit', "Recorded a $dept visit for Patient ID #$patient_id.");
                $_SESSION['deptpage_ok'] = 'Visit recorded.';
            } else {
                $_SESSION['deptpage_err'] = 'Could not save the visit. Please try again.';
            }
        }
    }
    header('Location: department.php?id=' . $dept_id);
    exit();
}

$today = date('Y-m-d');
$st = $conn->prepare("SELECT a.id, a.queue_number, a.status, a.reason, p.first_name, p.middle_name, p.last_name
                      FROM appointments a JOIN patients p ON p.id = a.patient_id
                      WHERE a.department = ? AND a.appointment_date = ? AND a.status IN ('Waiting','Approved')
                      ORDER BY a.queue_number ASC, a.id ASC");
$st->bind_param('ss', $dept, $today); $st->execute();
$queue = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$st = $conn->prepare("SELECT v.visit_date, v.notes, p.first_name, p.middle_name, p.last_name, s.first_name AS s_first, s.last_name AS s_last
                      FROM department_visits v
                      JOIN patients p ON p.id = v.patient_id
                      LEFT JOIN staff s ON s.id = v.recorded_by
                      WHERE v.department = ? ORDER BY v.created_at DESC, v.id DESC LIMIT 30");
$st->bind_param('s', $dept); $st->execute();
$visits = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$patients = [];
if ($can_write) {
    $res = $conn->query("SELECT id, first_name, middle_name, last_name FROM patients WHERE status = 1 ORDER BY last_name, first_name");
    while ($res && ($r = $res->fetch_assoc())) { $patients[] = $r; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($dept); ?> - Marick Health Center</title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .dp-hl { background: rgba(59,130,246,.08); border-radius: 8px; }
        .dp-msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
        .dp-ok { background: #dcfce7; color: #166534; } .dp-err { background: #fee2e2; color: #991b1b; }
        .dp-row { display: grid; grid-template-columns: 70px 1.4fr 2fr auto; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border-color, #e2e8f0); }
        .dp-row input[type=text], .dp-form select, .dp-form textarea { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; background: var(--card-bg, #fff); color: var(--text-color, #1e293b); }
        .dp-form label { display: block; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted, #64748b); margin: 10px 0 4px; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>
    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>
    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>
        <div class="dashboard-container">

            <div class="unified-panel">
                <h2 style="margin:0 0 12px 0;"><?php echo $h($dept); ?> &mdash; Today's Queue</h2>
                <?php if ($success_msg): ?><div class="dp-msg dp-ok"><?php echo $h($success_msg); ?></div><?php endif; ?>
                <?php if ($error_msg): ?><div class="dp-msg dp-err"><?php echo $h($error_msg); ?></div><?php endif; ?>
                <?php if (!$queue): ?>
                    <p style="color:var(--text-muted, #64748b); margin:0;">Nobody is waiting in this department today.</p>
                <?php endif; ?>
                <?php foreach ($queue as $q): ?>
                    <form method="POST" action="department.php?id=<?php echo (int) $dept_id; ?>" class="dp-row<?php echo ((int) ($_GET['apt'] ?? 0) === (int) $q['id']) ? ' dp-hl' : ''; ?>">
                        <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['deptpage_csrf']); ?>">
                        <input type="hidden" name="appointment_id" value="<?php echo (int) $q['id']; ?>">
                        <strong>#<?php echo $h($q['queue_number'] ?? '-'); ?></strong>
                        <span><strong><?php echo $h(mh_format_patient_name($q['first_name'], $q['middle_name'], $q['last_name'])); ?></strong><br>
                              <small style="color:var(--text-muted, #64748b);"><?php echo $h($q['reason']); ?></small></span>
                        <?php if ($can_write): ?>
                            <input type="text" name="notes" maxlength="2000" placeholder="Visit notes (optional)">
                            <button type="submit" name="record_visit" class="btn-primary">Complete</button>
                        <?php else: ?><span></span><span></span><?php endif; ?>
                    </form>
                <?php endforeach; ?>
            </div>

            <?php if ($can_write): ?>
            <div class="unified-panel dp-form" style="margin-top:20px;">
                <h2 style="margin:0 0 6px 0; font-size:1.2rem;">Record a visit (no queue entry)</h2>
                <form method="POST" action="department.php?id=<?php echo (int) $dept_id; ?>">
                    <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['deptpage_csrf']); ?>">
                    <label>Patient</label>
                    <select name="patient_id" required>
                        <option value="">-- Select patient --</option>
                        <?php foreach ($patients as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo $h(mh_format_patient_name($p['first_name'], $p['middle_name'], $p['last_name'])); ?></option><?php endforeach; ?>
                    </select>
                    <label>Notes</label>
                    <textarea name="notes" rows="3" maxlength="2000"></textarea>
                    <button type="submit" name="record_visit" class="btn-primary" style="margin-top:12px;">Save Visit</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="unified-panel" style="margin-top:20px;">
                <h2 style="margin:0 0 12px 0; font-size:1.2rem;">Recent visits</h2>
                <div style="overflow-x:auto;"><table class="unified-table">
                    <thead><tr><th>Date</th><th>Patient</th><th>Notes</th><th>Recorded by</th></tr></thead>
                    <tbody>
                    <?php if (!$visits): ?><tr><td colspan="4" style="text-align:center; padding:24px; color:var(--text-muted, #64748b);">No visits recorded yet.</td></tr><?php endif; ?>
                    <?php foreach ($visits as $v): ?>
                        <tr><td><?php echo $h(date('M d, Y', strtotime($v['visit_date']))); ?></td>
                            <td><strong><?php echo $h(mh_format_patient_name($v['first_name'], $v['middle_name'], $v['last_name'])); ?></strong></td>
                            <td><?php echo $h($v['notes']); ?></td>
                            <td><?php echo $h(trim(($v['s_first'] ?? '') . ' ' . ($v['s_last'] ?? ''))); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</body>
</html>
