<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';

require_permission('view_system_archives'); 

$system_name = $system_name ?? mh_setting('system_name');

$success_msg = "";
$error_msg = "";

if(isset($_POST['restore_patient'])) {
    $id = intval($_POST['patient_id']);
    $st = $conn->prepare("UPDATE patients SET status = 1 WHERE id = ?");
    $st->bind_param('i', $id);
    $ok = $st->execute(); $st->close();
    if($ok) {
        $success_msg = "Patient successfully restored to the Masterlist!";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Restored Record", "Restored Patient ID: $id from archives.");
    } else {
        $error_msg = "Error restoring patient. Please try again.";
    }
}

if(isset($_POST['restore_inventory'])) {
    $id = intval($_POST['item_id']);
    $st = $conn->prepare("UPDATE inventory SET status = 1 WHERE id = ?");
    $st->bind_param('i', $id);
    $ok = $st->execute(); $st->close();
    if($ok) {
        $success_msg = "Inventory item successfully restored!";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Restored Record", "Restored Inventory Item ID: $id from archives.");
    } else {
        $error_msg = "Error restoring item. Please try again.";
    }
}

require_once __DIR__ . '/../includes/settings_helper.php';
$can_staff_tab = mh_has_permission('manage_staff') && mh_staff_ensure_status($conn);
if ($can_staff_tab && isset($_POST['restore_staff'])) {
    if (!mh_csrf_check()) {
        $error_msg = "Your session security token is missing or expired. Please reload the page and try again.";
    } else {
        $sid = (int) ($_POST['staff_id'] ?? 0);
        $q = $conn->prepare("SELECT role, first_name, last_name FROM staff WHERE id = ? AND status = 0 LIMIT 1");
        $q->bind_param('i', $sid); $q->execute();
        $t = $q->get_result()->fetch_assoc(); $q->close();
        if (!$t) {
            $error_msg = "That archived account was not found.";
        } elseif (mh_is_admin_tier($t['role']) && !mh_is_health_head()) {
            $error_msg = "Only a Health Head can restore an Admin or Health Head account.";
        } else {
            $st = $conn->prepare("UPDATE staff SET status = 1, archived_at = NULL WHERE id = ?");
            $st->bind_param('i', $sid);
            $ok = $st->execute(); $st->close();
            if ($ok) {
                $success_msg = "Staff account restored. They can sign in again with their existing password.";
                logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Restored Record", "Restored Staff ID: $sid ({$t['first_name']} {$t['last_name']}) from archives.");
            } else {
                $error_msg = "Error restoring the account. Please try again.";
            }
        }
    }
}
$archived_staff = $can_staff_tab ? $conn->query("SELECT id, first_name, last_name, email, role, archived_at FROM staff WHERE status = 0 ORDER BY last_name ASC") : false;

$archived_patients = $conn->query("SELECT * FROM patients WHERE status = 0 ORDER BY last_name ASC");
$archived_inventory = $conn->query("SELECT * FROM inventory WHERE status = 0 ORDER BY item_name ASC");

$active_tab = in_array(($_GET['tab'] ?? ''), ['inventory', 'staff'], true) ? $_GET['tab'] : 'patients';
if ($active_tab === 'staff' && !$can_staff_tab) { $active_tab = 'patients'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Archives - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        /* Page background comes from admin_theme.css (--bg-body) so the dark toggle applies here too. */
        
        .archive-container { 
            width: 95%; 
            max-width: 1800px; 
            margin: 40px auto; 
            padding: 0; 
            box-sizing: border-box;
        }
        
        .archive-header {
            text-align: left !important;
            margin-bottom: 30px;
        }
        .archive-header h1 { margin: 0 0 10px 0; color: var(--heading-color, #1e293b); font-family: Georgia, serif; font-size: 2.2rem; display: flex; align-items: center; gap: 12px;}
        .archive-header p { margin: 0; color: var(--text-muted, #64748b); font-size: 1.1rem; }

        .tab-navigation { display: flex; gap: 5px; margin-bottom: 0; padding-left: 10px;}
        .tab-btn { background: var(--bg-hover); border: 1px solid var(--border-color, #cbd5e1); border-bottom: none; padding: 15px 35px; font-size: 1.1rem; font-weight: bold; color: var(--text-muted, #64748b); cursor: pointer; border-radius: 10px 10px 0 0; transition: 0.2s; }
        .tab-btn:hover { background: var(--bg-card); color: var(--heading-color, #1e293b); }
        .tab-btn.active { color: #0ea5e9; background: var(--bg-card, #ffffff); border-top: 3px solid #0ea5e9; box-shadow: 0 -4px 6px rgba(0,0,0,0.01); z-index: 2; position: relative;}
        
        .tab-content { display: none; background: var(--bg-card, #ffffff); border-radius: 0 12px 12px 12px; border: 1px solid var(--border-color, #cbd5e1); box-shadow: 0 4px 10px rgba(0,0,0,0.03); overflow: hidden; padding: 0; width: 100%; position: relative; z-index: 1;}
        .tab-content.active { display: block; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 1rem; }
        .data-table th { background: var(--bg-hover, #f8fafc); color: var(--text-muted, #64748b); padding: 20px 25px; text-align: left; text-transform: uppercase; font-size: 0.85rem; border-bottom: 2px solid var(--border-color, #cbd5e1); font-weight: bold;}
        .data-table td { padding: 20px 25px; border-bottom: 1px solid var(--border-color, #e2e8f0); color: var(--text-main, #334155); }
        
        .btn-restore { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 8px;}
        .btn-restore:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);}
        
        html[data-theme="dark"] .btn-restore { background: rgba(16,185,129,0.18); color: #6ee7b7; border: 1px solid rgba(16,185,129,0.4); }
        html[data-theme="dark"] .btn-restore:hover { background: rgba(16,185,129,0.3); }
        html[data-theme="dark"] .tab-btn.active { color: #38bdf8; border-top-color: #38bdf8; }
        .item-chip { background: var(--bg-hover); padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-weight: bold; color: #0369a1; }
        html[data-theme="dark"] .item-chip { color: #7dd3fc; }
        .tab-content .data-table tbody tr:hover { background: var(--bg-hover); }
        .alert-box { padding: 20px 25px; border-radius: 10px; margin-bottom: 25px; font-size: 1.05rem; font-weight: bold; display: flex; align-items: center; gap: 10px;}
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="archive-container">
            <div class="archive-header">
                <h1><span style="font-size: 2.5rem; color: var(--text-muted, #64748b);"></span> System Archives</h1>
                <p>View and restore deleted records. Archived data is securely hidden from the active masterlist.</p>
            </div>

            <?php 
            if($success_msg) echo "<div class='alert-box alert-success'>" . mh_icon('check-circle') . " $success_msg</div>";
            if($error_msg) echo "<div class='alert-box alert-error'>" . mh_icon('x-circle') . " $error_msg</div>";
            ?>

            <div class="tab-navigation">
                <button class="tab-btn <?php echo ($active_tab == 'patients') ? 'active' : ''; ?>" onclick="switchTab('patients')"> Archived Patients</button>
                <button class="tab-btn <?php echo ($active_tab == 'inventory') ? 'active' : ''; ?>" onclick="switchTab('inventory')"> Archived Inventory</button>
                <?php if ($can_staff_tab): ?>
                <button class="tab-btn <?php echo ($active_tab == 'staff') ? 'active' : ''; ?>" onclick="switchTab('staff')"> Archived Staff</button>
                <?php endif; ?>
            </div>

            <div id="tab-patients" class="tab-content <?php echo ($active_tab == 'patients') ? 'active' : ''; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Patient ID</th>
                            <th>Full Name</th>
                            <th style="width: 200px;">Contact</th>
                            <th>Address</th>
                            <th style="text-align: center; width: 150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($archived_patients && $archived_patients->num_rows > 0): ?>
                            <?php while($row = $archived_patients->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: bold; color: var(--text-muted, #64748b); font-family: monospace; font-size: 1.1rem;">#<?php echo str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td style="font-weight: bold; color: var(--heading-color, #1e293b); font-size: 1.1rem;"><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['contact_number']); ?></td>
                                    <td style="color: var(--text-muted, #64748b);"><?php echo htmlspecialchars($row['address']); ?></td>
                                    <td style="text-align: center;">
                                        <form method="POST" class="mh-restore-patient-form">
                                            <input type="hidden" name="patient_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="restore_patient" class="btn-restore"> Restore</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 60px; color: var(--text-muted, #64748b); font-size: 1.1rem;"><em>No archived patients found.</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="tab-inventory" class="tab-content <?php echo ($active_tab == 'inventory') ? 'active' : ''; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th style="width: 250px;">Category</th>
                            <th style="width: 200px;">Remaining Stock</th>
                            <th style="text-align: center; width: 150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($archived_inventory && $archived_inventory->num_rows > 0): ?>
                            <?php while($row = $archived_inventory->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: bold; color: var(--heading-color, #1e293b); font-size: 1.1rem;"><?php echo htmlspecialchars($row['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['category'] ?? 'General'); ?></td>
                                    <td>
                                        <span class="item-chip">
                                            <?php echo $row['quantity']; ?> <?php echo htmlspecialchars($row['unit']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <form method="POST" class="mh-restore-inventory-form">
                                            <input type="hidden" name="item_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="restore_inventory" class="btn-restore"> Restore</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center; padding: 60px; color: var(--text-muted, #64748b); font-size: 1.1rem;"><em>No archived inventory items found.</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($can_staff_tab): ?>
            <div id="tab-staff" class="tab-content <?php echo ($active_tab == 'staff') ? 'active' : ''; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th style="width: 200px;">Role</th>
                            <th style="width: 200px;">Archived on</th>
                            <th style="text-align: center; width: 150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($archived_staff && $archived_staff->num_rows > 0): ?>
                            <?php while($row = $archived_staff->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: bold; color: var(--heading-color, #1e293b); font-size: 1.1rem;"><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?><br>
                                        <span style="font-weight: normal; font-size: 0.85rem; color: var(--text-muted, #64748b);"><?php echo htmlspecialchars($row['email']); ?></span></td>
                                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $row['role']))); ?></td>
                                    <td><?php echo $row['archived_at'] ? htmlspecialchars(date('M d, Y', strtotime($row['archived_at']))) : '&mdash;'; ?></td>
                                    <td style="text-align: center;">
                                        <form method="POST" class="mh-restore-staff-form">
                                            <?php echo mh_csrf_field(); ?>
                                            <input type="hidden" name="staff_id" value="<?php echo (int) $row['id']; ?>">
                                            <button type="submit" name="restore_staff" class="btn-restore"> Restore</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center; padding: 60px; color: var(--text-muted, #64748b); font-size: 1.1rem;"><em>No archived staff accounts.</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById('tab-' + tabName).classList.add('active');
            
            window.history.replaceState(null, null, "?tab=" + tabName);
        }

        function mhWireRestoreForm(form, message) {
            const btn = form.querySelector('button[type="submit"]');
            btn.addEventListener('click', async function (e) {
                e.preventDefault();
                const ok = await mh_confirm(message, 'Restore Record?', 'info');
                // requestSubmit(btn) preserves the button's name="..." in the POST data,
                // unlike form.submit() which would drop it.
                if (ok) { form.requestSubmit(btn); }
            });
        }

        document.querySelectorAll('.mh-restore-patient-form').forEach(function (form) {
            mhWireRestoreForm(form, 'Are you sure you want to restore this patient to the active masterlist?');
        });
        document.querySelectorAll('.mh-restore-inventory-form').forEach(function (form) {
            mhWireRestoreForm(form, 'Are you sure you want to restore this item to the active inventory?');
        });
    </script>
</body>
</html>