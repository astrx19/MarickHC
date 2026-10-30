<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';

// Health Head always; other roles only when "Department Management" is switched on for them
// (System Settings -> Staff Roles & Permissions).
require_permission('manage_departments');

mh_department_ensure_table($conn);

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role    = (string) ($_SESSION['role'] ?? '');
$LIST    = 'clinic_departments';
$choices = mh_department_module_choices();

if (empty($_SESSION['dept_csrf'])) { $_SESSION['dept_csrf'] = bin2hex(random_bytes(16)); }
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$success_msg = $_SESSION['dept_success'] ?? ''; $error_msg = $_SESSION['dept_error'] ?? '';
unset($_SESSION['dept_success'], $_SESSION['dept_error']);

function dept_done($ok, $msg) {
    $_SESSION[$ok ? 'dept_success' : 'dept_error'] = $msg;
    header('Location: departments.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('manage_departments');   // re-check on the write itself
    if (!hash_equals($_SESSION['dept_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        dept_done(false, 'Your session form expired. Please try again.');
    }

    $name = trim((string) ($_POST['dept_name'] ?? ''));
    $mod  = (string) ($_POST['module_key'] ?? '');
    $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
    if (!array_key_exists($mod, $choices)) { $mod = ''; }
    $nameLen = mb_strlen($name);

    /* ---------- ADD ---------- */
    if (isset($_POST['add_department'])) {
        if ($name === '' || $nameLen > 100) { dept_done(false, 'Enter a department name (up to 100 characters).'); }
        [$ok, $msg] = mh_add_option($conn, $LIST, $name);
        if (!$ok) { dept_done(false, $msg); }
        $st = $conn->prepare("INSERT INTO department_settings (dept_name, module_key, description) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE module_key = VALUES(module_key), description = VALUES(description)");
        $st->bind_param('sss', $name, $mod, $desc); $st->execute(); $st->close();
        logActivity($conn, $user_id, $role, 'Department Added', "Added department \"$name\".");
        dept_done(true, "Department \"$name\" added. It now appears in walk-in registration and Patient Queueing.");
    }

    /* ---------- EDIT / RENAME / HIDE ---------- */
    if (isset($_POST['edit_department'])) {
        $opt_id = (int) ($_POST['option_id'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '' || $nameLen > 100) { dept_done(false, 'Enter a department name (up to 100 characters).'); }

        $st = $conn->prepare("SELECT option_value FROM system_options WHERE id = ? AND list_key = ?");
        $st->bind_param('is', $opt_id, $LIST); $st->execute();
        $old = $st->get_result()->fetch_assoc(); $st->close();
        if (!$old) { dept_done(false, 'That department no longer exists.'); }
        $old_name = $old['option_value'];

        $conn->begin_transaction();
        $ok = true;
        $st = $conn->prepare("UPDATE system_options SET option_value = ?, is_active = ? WHERE id = ? AND list_key = ?");
        $st->bind_param('siis', $name, $active, $opt_id, $LIST);
        $ok = $st->execute(); $dup = ($conn->errno === 1062); $st->close();

        if ($ok && $name !== $old_name) {   // keep existing queue history attached to the renamed department
            $st = $conn->prepare("UPDATE appointments SET department = ? WHERE department = ?");
            $st->bind_param('ss', $name, $old_name);
            $ok = $st->execute(); $st->close();
        }
        if ($ok) {
            $st = $conn->prepare("DELETE FROM department_settings WHERE dept_name = ?");
            $st->bind_param('s', $old_name); $ok = $st->execute(); $st->close();
        }
        if ($ok) {
            $st = $conn->prepare("INSERT INTO department_settings (dept_name, module_key, description) VALUES (?, ?, ?)
                                  ON DUPLICATE KEY UPDATE module_key = VALUES(module_key), description = VALUES(description)");
            $st->bind_param('sss', $name, $mod, $desc);
            $ok = $st->execute(); $st->close();
        }
        if ($ok) { $conn->commit(); } else { $conn->rollback(); }
        mh_masterlist_flush();
        if (!$ok) { dept_done(false, !empty($dup) ? 'A department with that name already exists.' : 'Could not save the changes. Nothing was changed.'); }
        logActivity($conn, $user_id, $role, 'Department Updated', "Updated department \"$old_name\"" . ($name !== $old_name ? " (renamed to \"$name\")" : '') . '.');
        dept_done(true, "Department \"$name\" saved.");
    }

    /* ---------- DELETE (only when never used) ---------- */
    if (isset($_POST['delete_department'])) {
        $opt_id = (int) ($_POST['option_id'] ?? 0);
        $st = $conn->prepare("SELECT option_value FROM system_options WHERE id = ? AND list_key = ?");
        $st->bind_param('is', $opt_id, $LIST); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        if (!$row) { dept_done(false, 'That department no longer exists.'); }
        $dn = $row['option_value'];

        $st = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE department = ?");
        $st->bind_param('s', $dn); $st->execute();
        $used = (int) ($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
        if ($used > 0) { dept_done(false, "\"$dn\" has $used queue record(s) and cannot be deleted. Uncheck Active to hide it instead."); }

        [$ok, $msg] = mh_delete_option($conn, $opt_id);
        if ($ok) {
            $st = $conn->prepare("DELETE FROM department_settings WHERE dept_name = ?");
            $st->bind_param('s', $dn); $st->execute(); $st->close();
            logActivity($conn, $user_id, $role, 'Department Deleted', "Deleted department \"$dn\".");
        }
        dept_done($ok, $ok ? "Department \"$dn\" deleted." : $msg);
    }
}

$cfg  = mh_department_settings($conn, true);
$rows = mh_options($conn, $LIST, false);
// queue usage per department
$usage = [];
$res = $conn->query("SELECT department, COUNT(*) AS c FROM appointments GROUP BY department");
while ($res && ($r = $res->fetch_assoc())) { $usage[$r['department']] = (int) $r['c']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments - Marick Health Center</title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .dp-msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
        .dp-ok  { background: #dcfce7; color: #166534; } .dp-err { background: #fee2e2; color: #991b1b; }
        .dp-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: end; }
        .dp-form label { display: block; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted, #64748b); margin-bottom: 4px; }
        .dp-form input[type=text], .dp-form select { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; background: var(--card-bg, #fff); color: var(--text-color, #1e293b); }
        .dp-edit-row { display: none; } .dp-edit-row.open { display: table-row; }
        .dp-badge { padding: 2px 10px; border-radius: 999px; font-size: .78rem; font-weight: 700; }
        .dp-on { background: #dcfce7; color: #166534; } .dp-off { background: #e2e8f0; color: #475569; }
        .dp-small { font-size: .85rem; padding: 6px 12px; cursor: pointer; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>
    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>
    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>
        <div class="dashboard-container">

            <div class="unified-panel">
                <h2 style="margin:0 0 6px 0;">Clinic Departments</h2>
                <p style="margin:0 0 14px 0; color:var(--text-muted, #64748b);">
                    Departments appear in walk-in registration and Patient Queueing. Link a department to a module so
                    &ldquo;Proceed&rdquo; opens the right page, or choose &ldquo;General department&rdquo; for one with no special page.
                    Which staff roles see it follows each module's access settings in System Settings.
                </p>
                <?php if ($success_msg): ?><div class="dp-msg dp-ok"><?php echo $h($success_msg); ?></div><?php endif; ?>
                <?php if ($error_msg): ?><div class="dp-msg dp-err"><?php echo $h($error_msg); ?></div><?php endif; ?>

                <form method="POST" action="departments.php" class="dp-form" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['dept_csrf']); ?>">
                    <div><label>New department name</label><input type="text" name="dept_name" maxlength="100" required placeholder="e.g. TB-DOTS"></div>
                    <div><label>Linked module</label>
                        <select name="module_key">
                            <?php foreach ($choices as $k => $label): ?><option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option><?php endforeach; ?>
                        </select></div>
                    <div><label>Description (optional)</label><input type="text" name="description" maxlength="255"></div>
                    <div><button type="submit" name="add_department" class="btn-primary">Add Department</button></div>
                </form>
            </div>

            <div class="unified-panel" style="margin-top:20px;">
                <div style="overflow-x:auto;">
                <table class="unified-table">
                    <thead><tr><th>Department</th><th>Linked module</th><th>Queue records</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" style="text-align:center; padding:24px; color:var(--text-muted, #64748b);">No departments yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $o):
                        $name = $o['option_value'];
                        $c    = $cfg[strtolower($name)] ?? ['module_key' => '', 'description' => ''];
                        $used = $usage[$name] ?? 0;
                        $detected = ($c['module_key'] === '') ? mh_department_module($name) : null;
                        $label = $c['module_key'] === '' ? ('Auto' . ($detected ? ': ' . ($choices[$detected] ?? $detected) : ': none found'))
                                                         : ($choices[$c['module_key']] ?? $c['module_key']);
                    ?>
                        <tr>
                            <td><strong><?php echo $h($name); ?></strong>
                                <?php if ($c['description'] !== ''): ?><br><small style="color:var(--text-muted, #64748b);"><?php echo $h($c['description']); ?></small><?php endif; ?></td>
                            <td><?php echo $h($label); ?></td>
                            <td><?php echo (int) $used; ?></td>
                            <td><span class="dp-badge <?php echo $o['is_active'] ? 'dp-on' : 'dp-off'; ?>"><?php echo $o['is_active'] ? 'Active' : 'Hidden'; ?></span></td>
                            <td style="text-align:right;">
                                <button type="button" class="btn-primary dp-small" onclick="document.getElementById('dpedit<?php echo (int) $o['id']; ?>').classList.toggle('open')">Edit</button>
                            </td>
                        </tr>
                        <tr class="dp-edit-row" id="dpedit<?php echo (int) $o['id']; ?>">
                            <td colspan="5">
                                <form method="POST" action="departments.php" class="dp-form" autocomplete="off">
                                    <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['dept_csrf']); ?>">
                                    <input type="hidden" name="option_id" value="<?php echo (int) $o['id']; ?>">
                                    <div><label>Name</label><input type="text" name="dept_name" maxlength="100" required value="<?php echo $h($name); ?>"></div>
                                    <div><label>Linked module</label>
                                        <select name="module_key">
                                            <?php foreach ($choices as $k => $lbl): ?><option value="<?php echo $h($k); ?>" <?php echo $c['module_key'] === (string) $k ? 'selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
                                        </select></div>
                                    <div><label>Description</label><input type="text" name="description" maxlength="255" value="<?php echo $h($c['description']); ?>"></div>
                                    <div><label><input type="checkbox" name="is_active" value="1" <?php echo $o['is_active'] ? 'checked' : ''; ?>> Active</label></div>
                                    <div style="display:flex; gap:8px;">
                                        <button type="submit" name="edit_department" class="btn-primary dp-small">Save</button>
                                        <button type="submit" name="delete_department" class="dp-small" style="background:#fee2e2; color:#991b1b; border:0; border-radius:8px;"
                                                onclick="return confirm('Delete this department? This only works if it was never used in the queue.');">Delete</button>
                                    </div>
                                </form>
                                <?php if ($used > 0): ?><small style="color:var(--text-muted, #64748b);">Renaming keeps its <?php echo (int) $used; ?> queue record(s) attached to the new name.</small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
