<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/icons.php';

// Granular: viewing the queue and acting on it (approve/proceed/reschedule/
// reject) are separate permissions.
require_permission(['view_patient_queue', 'manage_patient_queue']);
$can_process_queue = mh_has_permission('manage_patient_queue');

$system_name = $system_name ?? mh_setting('system_name');

$success_msg = ""; $error_msg = "";

// CSRF: every queue action is a POST. A missing/expired token cancels the whole request.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !mh_csrf_check()) {
    http_response_code(403);
    $error_msg = "Your session security token is missing or expired. Please reload the page and try again.";
    $_POST = [];   // no handler below may run
}
// Redirect target of walk-in intake: confirm the queue number the patient was given.
if (isset($_GET['queued'])) { $success_msg = "Patient added to the queue as Queue #" . (int) $_GET['queued'] . "."; }

// Which departments this staff member may see. Admin tiers and BHW see all; other roles
// see a department only if its module is ON and their role (or a delegated capability)
// is allowed into that module under System Settings > Module Access. Candidates are the
// live Clinic Departments masterlist plus any department already used by a queue record.
mh_all_settings($conn);   // load Module Access settings before evaluating them
$user_role    = strtolower($_SESSION['role'] ?? '');
$dept_choices = mh_clinic_departments($conn);
$candidates   = $dept_choices;
if ($dr = $conn->query("SELECT DISTINCT department FROM appointments")) {
    while ($d = $dr->fetch_assoc()) { $candidates[] = $d['department']; }
}
$candidates = array_values(array_unique($candidates));

$where_parts = [];
$where_params = [];
// Departments un-ticked "Shown" under Clinic Departments: not offered for new entries, and no longer
// listed for department-limited roles. Admin / BHW still see old records filed under them.
$hidden_depts = [];
foreach (mh_options($conn, 'clinic_departments', false) as $opt) {
    if (!(int) $opt['is_active']) { $hidden_depts[] = $opt['option_value']; }
}
$visible = mh_departments_visible_to($user_role, array_values(array_diff($candidates, $hidden_depts)));

if (!$can_process_queue && array_intersect(['approve_appointment', 'proceed_appointment', 'complete_appointment', 'cancel_appointment', 'reschedule_appointment'], array_keys($_POST))) {
    mh_deny_access(); // view-only accounts cannot change queue items
}

// A department-limited role (e.g. Inventory Staff) may only act on queue items that are in a
// department visible to them - the list is already filtered, but POST ids are user-controlled.
$queue_actions = ['approve_appointment', 'proceed_appointment', 'complete_appointment', 'cancel_appointment', 'reschedule_appointment'];
if ($visible !== null && array_intersect($queue_actions, array_keys($_POST))) {
    $chk_id = intval($_POST['appointment_id'] ?? 0);
    $chk = $conn->prepare("SELECT department FROM appointments WHERE id = ? LIMIT 1");
    $chk->bind_param('i', $chk_id);
    $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$chk_row || !in_array($chk_row['department'], $visible, true)) {
        mh_deny_access();
    }
}

if(isset($_POST['approve_appointment'])) {
    $apt_id = intval($_POST['appointment_id']);
    $st = $conn->prepare("UPDATE appointments SET status = 'Approved', admin_remarks = NULL WHERE id = ?");
    $st->bind_param('i', $apt_id); $st->execute(); $st->close();
    $success_msg = "Queue Approved!";
    logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Update", "Approved queue item #$apt_id.");
}

// Proceed Action: open the patient's department page (patient pre-selected, details pre-filled).
// The queue item is NOT completed here - it stays Waiting/Approved and is marked Completed only when
// the doctor / attendant saves the record on that department page.
if(isset($_POST['proceed_appointment'])) {
    $apt_id = intval($_POST['appointment_id']);
    
    // Fetch appointment and department details
    $st = $conn->prepare("SELECT patient_id, department, status FROM appointments WHERE id = ?");
    $st->bind_param('i', $apt_id); $st->execute();
    $apt_data = $st->get_result()->fetch_assoc();
    $st->close();
    if($apt_data && in_array($apt_data['status'], ['Waiting', 'Approved'], true)) {
        $patient_id = $apt_data['patient_id'];
        $dept = $apt_data['department'];
        
        // Where does this department's work happen? (module page, or the custom department page)
        $page = mh_department_page($dept);
        $custom_dept_id = 0;
        if ($page === null) {
            $st = $conn->prepare("SELECT id FROM system_options WHERE list_key = 'clinic_departments' AND option_value = ? LIMIT 1");
            $st->bind_param('s', $dept); $st->execute();
            $custom_dept_id = (int) ($st->get_result()->fetch_assoc()['id'] ?? 0);
            $st->close();
        }

        if ($custom_dept_id > 0) {
            // Custom departments finish the visit on their own page ("Complete" there), so the queue entry stays open.
            if (!mh_dept_user_can($dept)) { mh_deny_access(); }
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Update", "Sent queue item #$apt_id to $dept.");
            header("Location: department.php?id=" . $custom_dept_id . "&apt=" . $apt_id);
            exit();
        }

        if ($page !== null) {
            // Module departments (Immunization, Maternal Care, Dental, Check-ups, Dispensing):
            // open the form with the patient and the queue entry attached. The page completes the
            // queue entry when its record is saved.
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Update", "Sent queue item #$apt_id to $dept.");
            header("Location: " . $page . "?walkin_id=" . (int) $patient_id . "&apt_id=" . $apt_id);
            exit();
        }

        // Legacy: a department with no module page and no department page has nothing to save,
        // so there is no "Save record" step to wait for - close it and show the patient's profile.
        $st = $conn->prepare("UPDATE appointments SET status = 'Completed' WHERE id = ?");
        $st->bind_param('i', $apt_id); $st->execute(); $st->close();
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Update", "Completed queue item #$apt_id (department has no record page).");
        header("Location: patient_masterlist.php?id=" . (int) $patient_id);
        exit();
    }
}

if(isset($_POST['complete_appointment'])) {
    $apt_id = intval($_POST['appointment_id']);
    $st = $conn->prepare("UPDATE appointments SET status = 'Completed' WHERE id = ?");
    $st->bind_param('i', $apt_id); $st->execute(); $st->close();
    $success_msg = "Queue item marked as Completed!";
}

if(isset($_POST['cancel_appointment'])) {
    $apt_id = intval($_POST['appointment_id']);
    $remarks = (string) ($_POST['admin_remarks'] ?? '');
    
    $st = $conn->prepare("UPDATE appointments SET status = 'Rejected', admin_remarks = ? WHERE id = ?");
    $st->bind_param('si', $remarks, $apt_id);
    $ok = $st->execute(); $st->close();
    if($ok) {
        $success_msg = "Queue request rejected. The patient will see your reason on their dashboard history.";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Update", "Rejected queue item #$apt_id.");
    }
}

if(isset($_POST['reschedule_appointment'])) {
    $apt_id = intval($_POST['appointment_id']);
    $new_date = (string) ($_POST['new_date'] ?? '');
    $remarks  = (string) ($_POST['admin_remarks'] ?? '');

    // Date-only queueing: appointment_time is no longer user-editable and
    // is kept at a fixed placeholder so DATE()-based comparisons stay simple.
    // (queue numbers are per day, so the old one is cleared and reissued on the new date)
    $st = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = '00:00:00', status = 'Rescheduled', queue_number = NULL, admin_remarks = ? WHERE id = ?");
    $st->bind_param('ssi', $new_date, $remarks, $apt_id);
    $ok = $st->execute(); $st->close();
    if($ok) {
        $success_msg = "Queue item rescheduled! The patient will be notified of the new date and your reason.";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Rescheduled", "Moved queue item #$apt_id to $new_date.");
    }
}

if ($visible !== null) {
    if ($visible) {
        $where_parts[]  = "a.department IN (" . implode(',', array_fill(0, count($visible), '?')) . ")";
        foreach ($visible as $v) { $where_params[] = (string) $v; }
    } else {
        $where_parts[] = "1 = 0";
    }
}

// Optional department filter, choices straight from the masterlist.
$filter_dept = trim($_GET['dept'] ?? '');
if ($filter_dept !== '' && in_array($filter_dept, $candidates, true)) {
    $where_parts[]  = "a.department = ?";
    $where_params[] = $filter_dept;
} else {
    $filter_dept = '';
}
$where_clause = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$sql_fetch = "
    SELECT a.*, p.first_name, p.last_name, p.contact_number 
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    $where_clause
    ORDER BY 
        CASE a.status 
            WHEN 'Pending' THEN 1 
            WHEN 'Rescheduled' THEN 2 
            WHEN 'Waiting' THEN 3 
            WHEN 'Approved' THEN 3 
            WHEN 'Completed' THEN 4 
            WHEN 'Cancelled' THEN 5 
            WHEN 'Rejected' THEN 6
        END ASC,
        a.appointment_date ASC, 
        a.id ASC
";
$stmt_fetch = $conn->prepare($sql_fetch);
if ($where_params) { $stmt_fetch->bind_param(str_repeat('s', count($where_params)), ...$where_params); }
$stmt_fetch->execute();
$appointments = $stmt_fetch->get_result();
$stmt_fetch->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Queueing - <?php echo htmlspecialchars($system_name ?? 'Health Center'); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; text-transform: uppercase; border: 1px solid; display: inline-block; }
        .status-pending { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .status-rescheduled { background: #e0e7ff; color: #3730a3; border-color: #c7d2fe; }
        .status-waiting { background: #fef9c3; color: #854d0e; border-color: #fde68a; }
        .queue-no { display:inline-block; min-width:2.2em; text-align:center; background:#0ea5e9; color:#fff; border-radius:6px; padding:2px 7px; font-weight:bold; font-size:.85rem; margin-right:8px; }
        .status-approved { background: #d1fae5; color: #047857; border-color: #a7f3d0; }
        .status-completed { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        
        .status-cancelled { background: #f1f5f9; color: var(--text-main, #334155); border-color: var(--border-color, #cbd5e1); }
        .status-rejected { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }

        .btn-action { padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; cursor: pointer; border: 1px solid; margin-right: 5px; transition: 0.2s;}
        .btn-approve { background: #10b981; color: white; border-color: #059669; }
        .btn-approve:hover { background: #059669; }
        .btn-reschedule { background: #3b82f6; color: white; border-color: #2563eb; }
        .btn-reschedule:hover { background: #2563eb; }
        .btn-cancel { background: var(--bg-card, #ffffff); color: #ef4444; border-color: #ef4444; }
        .btn-cancel:hover { background: #fee2e2; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <div style="margin-bottom: 25px;">
                <h1 style="margin: 0 0 5px 0; color: var(--heading-color, #1e293b); font-family: Georgia, serif; font-size: 1.8rem;">Patient Queueing</h1>
                <p style="margin: 0; color: var(--text-muted, #64748b); font-size: 0.95rem;">
                    Review, approve, and manage incoming patient queue requests 
                    <?php if(!empty($user_role) && !mh_is_admin_tier($user_role)) echo "for <strong>" . ucfirst($user_role) . "</strong> department"; ?>.
                </p>
            </div>

            <?php 
            if($success_msg) echo "<div class='alert alert-success' style='background:#d1fae5; color:#047857; padding:15px; border-radius:8px; border:1px solid #a7f3d0; margin-bottom:20px; display:flex; align-items:center; gap:8px;'>" . mh_icon('check-circle') . " $success_msg</div>";
            if($error_msg) echo "<div class='alert alert-error' style='background:#fee2e2; color:#b91c1c; padding:15px; border-radius:8px; border:1px solid #fecaca; margin-bottom:20px; display:flex; align-items:center; gap:8px;'>" . mh_icon('x-circle') . " $error_msg</div>";
            ?>

            <div class="unified-panel">
                <div class="panel-controls">
                    <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);">Incoming & Scheduled Queue</h3>
                    <form method="GET" style="margin:0; display:flex; align-items:center; gap:8px;">
                        <label for="deptFilter" style="margin:0; font-size:0.8rem; font-weight:700; color:var(--text-muted, #64748b);">Department</label>
                        <select id="deptFilter" name="dept" onchange="this.form.submit()" style="padding:8px 10px; border-radius:6px; border:1px solid var(--border-color, #cbd5e1);">
                            <option value="">All departments</option>
                            <?php echo mh_department_options($conn, $filter_dept); ?>
                        </select>
                    </form>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="unified-table">
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Department</th>
                                <th>Schedule & Reason</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($appointments && $appointments->num_rows > 0): ?>
                                <?php while($row = $appointments->fetch_assoc()): 
                                    $status_class = "status-" . strtolower($row['status']);
                                    $apt_date = date("M d, Y", strtotime($row['appointment_date']));
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($row['queue_number'])): ?><span class="queue-no">#<?php echo (int) $row['queue_number']; ?></span><?php endif; ?><strong style="color: var(--heading-color, #1e293b); font-size: 1.05rem;"><?php echo htmlspecialchars($row['last_name'] . ", " . $row['first_name']); ?></strong><br>
                                        <span style="font-size: 0.85rem; color: var(--text-muted, #64748b);"><?php echo htmlspecialchars($row['contact_number']); ?></span>
                                    </td>
                                    <td style="color: var(--text-main, #334155); font-weight: bold;"><span style="display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:7px; background:<?php echo mh_department_color($row['department']); ?>;"></span><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>
                                        <strong style="color: var(--heading-color, #1e293b); display: block;"><?php echo mh_h($apt_date); ?></strong>
                                        <span style="color: var(--text-muted, #64748b); font-size: 0.9rem; font-style: italic;">"<?php echo !empty($row['reason']) ? htmlspecialchars($row['reason']) : 'No reason provided'; ?>"</span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo mh_h($status_class); ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <?php if(!$can_process_queue): ?>
                                            <span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">View only</span>
                                        <?php else: ?>
                                        <?php if($row['status'] == 'Pending' || $row['status'] == 'Rescheduled'): ?>
                                            <form method="POST" style="display: inline-block;">
<?php echo mh_csrf_field(); ?>
                                                <input type="hidden" name="appointment_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="approve_appointment" data-confirm="Approve this queue request?" data-confirm-title="Approve?" data-confirm-type="info" data-confirm-ok="Approve" class="btn-action btn-approve"><?php echo mh_icon('check'); ?> Approve</button>
                                            </form>
                                            
                                            <button class="btn-action btn-reschedule" onclick="openRescheduleModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['first_name'] . ' ' . $row['last_name']); ?>', '<?php echo $row['appointment_date']; ?>')"><?php echo mh_icon('refresh-cw'); ?> Resched</button>
                                            
                                            <button class="btn-action btn-cancel" onclick="openCancelModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['first_name'] . ' ' . $row['last_name']); ?>')"><?php echo mh_icon('x'); ?> Reject</button>
                                        
                                        <?php elseif(in_array($row['status'], ['Approved', 'Waiting'], true)): ?>
                                            
                                            <form method="POST" style="display: inline-block;">
<?php echo mh_csrf_field(); ?>
                                                <input type="hidden" name="appointment_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" name="proceed_appointment" data-confirm="Open this patient's department form? The queue entry is marked Completed once the record is saved." data-confirm-title="Proceed?" data-confirm-type="info" data-confirm-ok="Proceed" class="btn-action" style="background: #0ea5e9; color: white; border-color: #0284c7;">Proceed</button>
                                            </form>

                                            <button class="btn-action btn-cancel" onclick="openCancelModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['first_name'] . ' ' . $row['last_name']); ?>')"><?php echo mh_icon('x'); ?> Reject</button>
                                            
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-size: 0.85rem; font-style: italic;">
                                                <?php echo !empty($row['admin_remarks']) ? 'Message sent.' : 'Closed.'; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php endif; // can_process_queue ?>

                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; padding: 40px; color: var(--text-muted, #64748b);">No queue records found for this department.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <div class="modal-overlay" id="rescheduleModal">
        <div class="modal-content" style="max-width: 400px; border-top: 5px solid #3b82f6;">
            <span class="close-btn" onclick="closeModal('rescheduleModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:var(--heading-color, #1e293b);">Reschedule Queue Item</h2>
                <p id="reschedulePatientName" style="color: var(--text-muted, #64748b); margin: 5px 0 0 0; font-weight: bold;">Patient Name</p>
            </div>
            
            <form method="POST">
<?php echo mh_csrf_field(); ?>
                <input type="hidden" name="appointment_id" id="rescheduleAptId">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>New Date <span style="color:red;">*</span></label>
                    <input type="date" name="new_date" id="rescheduleDate" required min="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Reason for Rescheduling <span style="color:red;">*</span></label>
                    <textarea name="admin_remarks" required placeholder="e.g., The doctor is currently in an emergency meeting..." style="width: 100%; height: 70px; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); resize: vertical;"></textarea>
                </div>

                <button type="submit" name="reschedule_appointment" data-confirm="Send the reschedule notice to the patient?" data-confirm-title="Reschedule?" data-confirm-type="warning" data-confirm-ok="Send notice" class="btn-submit" style="width: 100%; padding: 12px; background: #3b82f6;">Send Reschedule Notice</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="cancelModal">
        <div class="modal-content" style="max-width: 400px; border-top: 5px solid #ef4444;">
            <span class="close-btn" onclick="closeModal('cancelModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:#ef4444;">Reject Queue Request</h2>
                <p id="cancelPatientName" style="color: var(--text-muted, #64748b); margin: 5px 0 0 0; font-weight: bold;">Patient Name</p>
            </div>
            
            <form method="POST">
<?php echo mh_csrf_field(); ?>
                <input type="hidden" name="appointment_id" id="cancelAptId">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Reason for Rejection <span style="color:red;">*</span></label>
                    <textarea name="admin_remarks" required placeholder="e.g., We do not offer this service at this time, please proceed to the main hospital..." style="width: 100%; height: 90px; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); resize: vertical;"></textarea>
                </div>

                <button type="submit" name="cancel_appointment" data-confirm="Reject this request? The patient will be notified." data-confirm-title="Reject request?" data-confirm-type="danger" data-confirm-ok="Reject" class="btn-submit" style="width: 100%; padding: 12px; background: #ef4444; border-color: #dc2626;">Confirm Rejection</button>
            </form>
        </div>
    </div>

    <script>
        function openRescheduleModal(id, patientName, currentDate) {
            document.getElementById('rescheduleAptId').value = id;
            document.getElementById('reschedulePatientName').innerText = "For: " + patientName;
            document.getElementById('rescheduleDate').value = currentDate;
            document.getElementById('rescheduleModal').classList.add('active');
        }

        function openCancelModal(id, patientName) {
            document.getElementById('cancelAptId').value = id;
            document.getElementById('cancelPatientName').innerText = "For: " + patientName;
            document.getElementById('cancelModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
    </script>
</body>
</html>