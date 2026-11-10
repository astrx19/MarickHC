<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/queue_helper.php';
require_once __DIR__ . '/../includes/patient_module_helper.php';
require_once __DIR__ . '/../includes/layout/layout.php';

require_permission(['view_patient_queue', 'manage_patient_queue']);
$can_process_queue = mh_has_permission('manage_patient_queue');

const MH_QUEUE_STALE = 'This queue entry was already updated by someone else. The list has been refreshed.';
const MH_QUEUE_ACTIONS = ['approve_appointment', 'proceed_appointment', 'complete_appointment', 'cancel_appointment', 'reschedule_appointment'];

function mh_queue_log(mysqli $conn, $action, $details)
{
    logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], $action, $details);
}

function mh_queue_transition(mysqli $conn, $sql, $types, array $params)
{
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $ok = $st->execute();
    $changed = $ok && $st->affected_rows > 0;
    $st->close();
    return [$ok, $changed];
}

function mh_queue_proceed(mysqli $conn, $id)
{
    $st = $conn->prepare("SELECT patient_id, department, status FROM appointments WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $apt = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$apt || !in_array($apt['status'], mh_queue_active_statuses(), true)) {
        return ['error', MH_QUEUE_STALE];
    }

    $dept = $apt['department'];
    $page = mh_department_page($dept);
    $customId = 0;
    if ($page === null) {
        $st = $conn->prepare("SELECT id FROM system_options WHERE list_key = 'clinic_departments' AND option_value = ? LIMIT 1");
        $st->bind_param('s', $dept);
        $st->execute();
        $customId = (int) ($st->get_result()->fetch_assoc()['id'] ?? 0);
        $st->close();
    }

    if ($customId > 0) {
        if (!mh_dept_user_can($dept)) { mh_deny_access(); }
        mh_queue_log($conn, 'Patient Queueing Update', "Sent queue item #$id to $dept.");
        header('Location: department.php?id=' . $customId . '&apt=' . $id);
        exit();
    }
    if ($page !== null) {
        mh_queue_log($conn, 'Patient Queueing Update', "Sent queue item #$id to $dept.");
        header('Location: ' . $page . '?walkin_id=' . (int) $apt['patient_id'] . '&apt_id=' . $id);
        exit();
    }

    mh_queue_transition($conn, "UPDATE appointments SET status = 'Completed' WHERE id = ? AND status IN ('Waiting', 'Approved')", 'i', [$id]);
    mh_queue_log($conn, 'Patient Queueing Update', "Completed queue item #$id (department has no record page).");
    header('Location: view_patient.php?id=' . (int) $apt['patient_id']);
    exit();
}

function mh_queue_run_action(mysqli $conn, $action, array $in)
{
    $id = (int) ($in['appointment_id'] ?? 0);
    $remarks = mb_substr(trim((string) ($in['admin_remarks'] ?? '')), 0, 500);

    switch ($action) {
        case 'approve_appointment':
            list(, $changed) = mh_queue_transition($conn, "UPDATE appointments SET status = 'Approved', admin_remarks = NULL WHERE id = ? AND status IN ('Pending', 'Rescheduled')", 'i', [$id]);
            if (!$changed) { return ['error', MH_QUEUE_STALE]; }
            mh_queue_log($conn, 'Patient Queueing Update', "Approved queue item #$id.");
            return ['success', 'Queue entry approved.'];

        case 'proceed_appointment':
            return mh_queue_proceed($conn, $id);

        case 'complete_appointment':
            list(, $changed) = mh_queue_transition($conn, "UPDATE appointments SET status = 'Completed' WHERE id = ? AND status IN ('Waiting', 'Approved')", 'i', [$id]);
            if (!$changed) { return ['error', MH_QUEUE_STALE]; }
            mh_queue_log($conn, 'Patient Queueing Update', "Completed queue item #$id.");
            return ['success', 'Queue entry marked as completed.'];

        case 'cancel_appointment':
            if ($remarks === '') { return ['error', 'Please give a reason for the rejection.']; }
            list($ok, $changed) = mh_queue_transition($conn, "UPDATE appointments SET status = 'Rejected', admin_remarks = ? WHERE id = ? AND status IN ('Pending', 'Rescheduled', 'Waiting', 'Approved')", 'si', [$remarks, $id]);
            if (!$ok || !$changed) { return ['error', MH_QUEUE_STALE]; }
            mh_queue_log($conn, 'Patient Queueing Update', "Rejected queue item #$id.");
            return ['success', 'Queue request rejected. The patient will see your reason on their dashboard history.'];

        case 'reschedule_appointment':
            $date = (string) ($in['new_date'] ?? '');
            $parsed = DateTime::createFromFormat('Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < date('Y-m-d')) { return ['error', 'Please choose a valid date that is today or later.']; }
            if ($remarks === '') { return ['error', 'Please give a reason for rescheduling.']; }
            if (($closed = mh_clinic_closed_reason($conn, $date, false)) !== null) { return ['error', $closed]; }
            list($ok, $changed) = mh_queue_transition(
                $conn,
                "UPDATE appointments SET appointment_date = ?, appointment_time = '00:00:00', status = 'Rescheduled', queue_number = NULL, admin_remarks = ? WHERE id = ? AND status IN ('Pending', 'Rescheduled')",
                'ssi', [$date, $remarks, $id]
            );
            if (!$ok || !$changed) { return ['error', MH_QUEUE_STALE]; }
            mh_queue_log($conn, 'Patient Queueing Rescheduled', "Moved queue item #$id to $date.");
            return ['success', 'Queue entry rescheduled. The patient will be notified of the new date and your reason.'];
    }
    return ['error', 'Unknown queue action.'];
}

mh_all_settings($conn);
$user_role = strtolower($_SESSION['role'] ?? '');

$dept_choices = mh_clinic_departments($conn);
$candidates = $dept_choices;
if ($used = $conn->query("SELECT DISTINCT department FROM appointments WHERE appointment_date = CURDATE()")) {
    while ($d = $used->fetch_assoc()) { $candidates[] = $d['department']; }
}
$candidates = array_values(array_unique($candidates));

$hidden_depts = [];
foreach (mh_options($conn, 'clinic_departments', false) as $opt) {
    if (!(int) $opt['is_active']) { $hidden_depts[] = $opt['option_value']; }
}
$visible = mh_departments_visible_to($user_role, array_values(array_diff($candidates, $hidden_depts)));
$can_see_all = ($visible === null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = '';
    foreach (MH_QUEUE_ACTIONS as $a) {
        if (isset($_POST[$a])) { $action = $a; break; }
    }
    if ($action !== '') {
        if (!mh_csrf_check()) {
            http_response_code(403);
            mh_pm_flash_set('error', 'Your session security token is missing or expired. Please reload the page and try again.');
            mh_pm_redirect_self();
        }
        if (!$can_process_queue) { mh_deny_access(); }
        if ($visible !== null) {
            $aptId = (int) ($_POST['appointment_id'] ?? 0);
            $st = $conn->prepare("SELECT department FROM appointments WHERE id = ? LIMIT 1");
            $st->bind_param('i', $aptId);
            $st->execute();
            $owner = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$owner || !in_array($owner['department'], $visible, true)) { mh_deny_access(); }
        }
        list($type, $message) = mh_queue_run_action($conn, $action, $_POST);
        mh_pm_flash_set($type, $message);
        mh_pm_redirect_self();
    }
}

$flash = mh_pm_flash_pull();
if (!$flash && isset($_GET['queued'])) {
    $flash = ['type' => 'success', 'message' => 'Patient added to the queue as ' . mh_queue_label($_GET['dept'] ?? '', (int) $_GET['queued']) . '.'];
}

$where = ['a.appointment_date = CURDATE()'];
$params = [];
if ($visible !== null) {
    if ($visible) {
        $where[] = 'a.department IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
        foreach ($visible as $v) { $params[] = (string) $v; }
    } else {
        $where[] = '1 = 0';
    }
}

$filter_dept = trim($_GET['dept'] ?? '');
if ($can_see_all) {
    if (!in_array($filter_dept, $candidates, true)) { $filter_dept = ''; }
} elseif (!in_array($filter_dept, $visible, true)) {
    $filter_dept = $visible ? (string) $visible[0] : '';
}
if ($filter_dept !== '') {
    $where[] = 'a.department = ?';
    $params[] = $filter_dept;
}

$show_finished = (($_GET['show'] ?? '') === 'all');
if (!$show_finished) { $where[] = "a.status IN ('Pending', 'Rescheduled', 'Waiting', 'Approved')"; }

$stmt = $conn->prepare(
    "SELECT a.id, a.department, a.reason, a.appointment_date, a.status, a.queue_number, a.admin_remarks,
            p.first_name, p.last_name, p.contact_number
       FROM appointments a
       JOIN patients p ON p.id = a.patient_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY CASE a.status WHEN 'Pending' THEN 1 WHEN 'Rescheduled' THEN 2 WHEN 'Waiting' THEN 3 WHEN 'Approved' THEN 3
                             WHEN 'Completed' THEN 4 WHEN 'Cancelled' THEN 5 WHEN 'Rejected' THEN 6 ELSE 7 END ASC,
               COALESCE(a.queue_number, 9999) ASC, a.id ASC"
);
if ($params) { $stmt->bind_param(str_repeat('s', count($params)), ...$params); }
$stmt->execute();
$appointments = $stmt->get_result();
$stmt->close();

$clinic_closed = mh_clinic_closed_reason($conn);
$live_query = http_build_query(array_filter(['dept' => $filter_dept, 'show' => $show_finished ? '' : 'all']));

mh_layout_head(['title' => 'Patient Queueing']);
?>
    <style>
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; text-transform: uppercase; border: 1px solid; display: inline-block; }
        .status-pending { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .status-rescheduled { background: #e0e7ff; color: #3730a3; border-color: #c7d2fe; }
        .status-waiting { background: #fef9c3; color: #854d0e; border-color: #fde68a; }
        .queue-no { display:inline-block; min-width:4.6em; white-space:nowrap; letter-spacing:.02em; text-align:center; background:#0ea5e9; color:#fff; border-radius:6px; padding:2px 7px; font-weight:bold; font-size:.85rem; margin-right:8px; }
        .status-approved { background: #d1fae5; color: #047857; border-color: #a7f3d0; }
        .status-completed { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .status-cancelled { background: #f1f5f9; color: var(--text-main, #334155); border-color: var(--border-color, #cbd5e1); }
        .status-rejected { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .btn-action { padding: 6px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; cursor: pointer; border: 1px solid; margin-right: 5px; transition: 0.2s; }
        .btn-approve { background: #10b981; color: white; border-color: #059669; }
        .btn-approve:hover { background: #059669; }
        .btn-reschedule { background: #3b82f6; color: white; border-color: #2563eb; }
        .btn-reschedule:hover { background: #2563eb; }
        .btn-cancel { background: var(--bg-card, #ffffff); color: #ef4444; border-color: #ef4444; }
        .btn-cancel:hover { background: #fee2e2; }
        .btn-proceed { background: #0ea5e9; color: white; border-color: #0284c7; }
        .queue-form { display: inline-block; margin: 0; }
    </style>
<?php mh_layout_open(); ?>

        <div class="dashboard-container">

            <div style="margin-bottom: 25px;">
                <h1 style="margin: 0 0 5px 0; color: var(--heading-color, #1e293b); font-family: Georgia, serif; font-size: 1.8rem;">Patient Queueing</h1>
                <p style="margin: 0; color: var(--text-muted, #64748b); font-size: 0.95rem;">
                    Review, approve, and manage incoming patient queue requests<?php echo ($user_role !== '' && !mh_is_admin_tier($user_role)) ? ' for <strong>' . mh_h(ucfirst($user_role)) . '</strong> department' : ''; ?>.
                </p>
            </div>

            <?php
            if ($flash) { echo mh_pm_alert($flash['type'], $flash['message']); }
            if ($clinic_closed !== null) { echo mh_pm_alert('warn', $clinic_closed, 'Clinic closed.'); }
            ?>

            <div class="unified-panel">
                <div class="panel-controls">
                    <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);">Today's Queue <small style="font-weight:600; color: var(--text-muted, #64748b);">&middot; <?php echo mh_h(date('l, F j, Y')); ?></small></h3>
                    <form method="GET" style="margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <?php if ($show_finished): ?><input type="hidden" name="show" value="all"><?php endif; ?>
                        <a href="?<?php echo mh_h($live_query); ?>" style="font-size:0.82rem; font-weight:700; color: var(--primary, #4ca1af); text-decoration:none;">
                            <?php echo $show_finished ? 'Hide finished' : 'Show finished today'; ?>
                        </a>
                        <?php if ($can_see_all || count($visible) > 1): ?>
                        <label for="deptFilter" style="margin:0; font-size:0.8rem; font-weight:700; color:var(--text-muted, #64748b);">Department</label>
                        <select id="deptFilter" name="dept" onchange="this.form.submit()">
                            <?php if ($can_see_all): ?>
                                <option value="">All departments</option>
                                <?php echo mh_department_options($conn, $filter_dept); ?>
                            <?php else: foreach ($visible as $v): ?>
                                <option value="<?php echo mh_h($v); ?>"<?php echo $v === $filter_dept ? ' selected' : ''; ?>><?php echo mh_h($v); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                        <?php elseif ($filter_dept !== ''): ?>
                            <span class="mh-badge mh-badge-info"><?php echo mh_h($filter_dept); ?></span>
                        <?php endif; ?>
                    </form>
                </div>

                <div style="overflow-x: auto;" data-live="queue-list">
                    <table class="unified-table">
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Department</th>
                                <th>Schedule &amp; Reason</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($appointments && $appointments->num_rows > 0): ?>
                            <?php while ($row = $appointments->fetch_assoc()):
                                $fullName = $row['first_name'] . ' ' . $row['last_name'];
                                $isOpenRequest = in_array($row['status'], ['Pending', 'Rescheduled'], true);
                                $isCalled = in_array($row['status'], mh_queue_active_statuses(), true);
                            ?>
                            <tr>
                                <td>
                                    <?php if (!empty($row['queue_number'])): ?><span class="queue-no"><?php echo mh_h(mh_queue_label($row['department'], $row['queue_number'])); ?></span><?php endif; ?><strong style="color: var(--heading-color, #1e293b); font-size: 1.05rem;"><?php echo mh_h($row['last_name'] . ', ' . $row['first_name']); ?></strong><br>
                                    <span style="font-size: 0.85rem; color: var(--text-muted, #64748b);"><?php echo mh_h($row['contact_number']); ?></span>
                                </td>
                                <td style="color: var(--text-main, #334155); font-weight: bold;"><span style="display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:7px; background:<?php echo mh_h(mh_department_color($row['department'])); ?>;"></span><?php echo mh_h($row['department']); ?></td>
                                <td>
                                    <strong style="color: var(--heading-color, #1e293b); display: block;"><?php echo mh_h(date('M d, Y', strtotime($row['appointment_date']))); ?></strong>
                                    <span style="color: var(--text-muted, #64748b); font-size: 0.9rem; font-style: italic;">"<?php echo mh_h(!empty($row['reason']) ? $row['reason'] : 'No reason provided'); ?>"</span>
                                </td>
                                <td><span class="status-badge status-<?php echo mh_h(strtolower($row['status'])); ?>"><?php echo mh_h($row['status']); ?></span></td>
                                <td style="white-space: nowrap;">
                                    <?php if (!$can_process_queue): ?>
                                        <span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">View only</span>
                                    <?php elseif ($isOpenRequest): ?>
                                        <form method="POST" class="queue-form">
                                            <?php echo mh_csrf_field(); ?>
                                            <input type="hidden" name="appointment_id" value="<?php echo (int) $row['id']; ?>">
                                            <button type="submit" name="approve_appointment" value="1" data-confirm="Approve this queue request?" data-confirm-title="Approve?" data-confirm-type="info" data-confirm-ok="Approve" class="btn-action btn-approve"><?php echo mh_icon('check'); ?> Approve</button>
                                        </form>
                                        <button type="button" class="btn-action btn-reschedule" data-queue-resched data-apt-id="<?php echo (int) $row['id']; ?>" data-name="<?php echo mh_h($fullName); ?>" data-date="<?php echo mh_h($row['appointment_date']); ?>"><?php echo mh_icon('refresh-cw'); ?> Resched</button>
                                        <button type="button" class="btn-action btn-cancel" data-queue-reject data-apt-id="<?php echo (int) $row['id']; ?>" data-name="<?php echo mh_h($fullName); ?>"><?php echo mh_icon('x'); ?> Reject</button>
                                    <?php elseif ($isCalled): ?>
                                        <form method="POST" class="queue-form">
                                            <?php echo mh_csrf_field(); ?>
                                            <input type="hidden" name="appointment_id" value="<?php echo (int) $row['id']; ?>">
                                            <button type="submit" name="proceed_appointment" value="1" data-confirm="Open this patient's department form? The queue entry is marked Completed once the record is saved." data-confirm-title="Proceed?" data-confirm-type="info" data-confirm-ok="Proceed" class="btn-action btn-proceed">Proceed</button>
                                        </form>
                                        <button type="button" class="btn-action btn-cancel" data-queue-reject data-apt-id="<?php echo (int) $row['id']; ?>" data-name="<?php echo mh_h($fullName); ?>"><?php echo mh_icon('x'); ?> Reject</button>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1; font-size: 0.85rem; font-style: italic;"><?php echo !empty($row['admin_remarks']) ? 'Message sent.' : 'Closed.'; ?></span>
                                    <?php endif; ?>
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
    <?php mh_layout_close(); ?>

    <?php if ($can_process_queue): ?>
    <div class="modal-overlay" id="rescheduleModal">
        <div class="modal-content" style="max-width: 400px; border-top: 5px solid #3b82f6;">
            <span class="close-btn" data-close-modal="rescheduleModal">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:var(--heading-color, #1e293b);">Reschedule Queue Item</h2>
                <p id="reschedulePatient" style="color: var(--text-muted, #64748b); margin: 5px 0 0 0; font-weight: bold;">Patient Name</p>
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
                    <textarea name="admin_remarks" id="rescheduleRemarks" required maxlength="500" placeholder="e.g., The doctor is currently in an emergency meeting..." style="width: 100%; height: 70px; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); resize: vertical;"></textarea>
                </div>
                <button type="submit" name="reschedule_appointment" value="1" data-confirm="Send the reschedule notice to the patient?" data-confirm-title="Reschedule?" data-confirm-type="warning" data-confirm-ok="Send notice" class="btn-submit" style="width: 100%; padding: 12px; background: #3b82f6;">Send Reschedule Notice</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="rejectModal">
        <div class="modal-content" style="max-width: 400px; border-top: 5px solid #ef4444;">
            <span class="close-btn" data-close-modal="rejectModal">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:#ef4444;">Reject Queue Request</h2>
                <p id="rejectPatient" style="color: var(--text-muted, #64748b); margin: 5px 0 0 0; font-weight: bold;">Patient Name</p>
            </div>
            <form method="POST">
                <?php echo mh_csrf_field(); ?>
                <input type="hidden" name="appointment_id" id="rejectAptId">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Reason for Rejection <span style="color:red;">*</span></label>
                    <textarea name="admin_remarks" id="rejectRemarks" required maxlength="500" placeholder="e.g., We do not offer this service at this time, please proceed to the main hospital..." style="width: 100%; height: 90px; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); resize: vertical;"></textarea>
                </div>
                <button type="submit" name="cancel_appointment" value="1" data-confirm="Reject this request? The patient will be notified." data-confirm-title="Reject request?" data-confirm-type="danger" data-confirm-ok="Reject" class="btn-submit" style="width: 100%; padding: 12px; background: #ef4444; border-color: #dc2626;">Confirm Rejection</button>
            </form>
        </div>
    </div>
    <script src="../assets/js/queue.js?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/js/queue.js'); ?>" defer></script>
    <?php endif; ?>
    <script>window.MH_LIVE = { url: 'api/queue_sync.php', interval: 5000 };</script>
    <script src="../assets/js/mh_live.js?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/js/mh_live.js'); ?>" defer></script>
<?php mh_layout_end(); ?>
