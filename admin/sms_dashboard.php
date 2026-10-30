<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sms_helper.php';
require_once __DIR__ . '/../includes/sms/sms_scheduler.php';
require_once __DIR__ . '/../includes/sms/sms_queue_runner.php';
if (file_exists(__DIR__ . '/../includes/settings_helper.php')) {
    require_once __DIR__ . '/../includes/settings_helper.php';
    mh_settings_boot($conn);
}

require_roles(['admin']);

mh_check_module_enabled('sms_reminders');

mh_sms_install($conn); // creates scheduled_sms / sms_logs on first visit

$system_name = $system_name ?? mh_setting('system_name');

$user_id   = (int) ($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'admin';

$flash = null;

/* =====================================================================
 * "RUN PENDING SMS QUEUE NOW" - the manual trigger for local testing
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_queue_now'])) {
    $summary = mh_run_sms_queue($conn, 'manual', 200);

    if ($summary['skipped_no_key']) {
        $flash = ['type' => 'error', 'message' => 'Cannot send: ' . ($summary['details'][0]['note'] ?? 'Semaphore is not configured.')
            . ' Set it up in System Settings > SMS Reminders.'];
    } elseif ($summary['checked'] === 0) {
        $flash = ['type' => 'success', 'message' => 'Queue checked - nothing was due right now (no pending reminders with a scheduled date of today or earlier).'];
    } else {
        $flash = ['type' => $summary['failed'] > 0 && $summary['sent'] === 0 ? 'error' : 'success',
            'message' => "Queue run complete. Checked {$summary['checked']}, sent {$summary['sent']}, failed {$summary['failed']}."];
    }

    logActivity($conn, $user_id, $user_role, 'SMS Reminders',
        'Manually ran the SMS queue: checked ' . ($summary['checked'] ?? 0)
        . ', sent ' . ($summary['sent'] ?? 0) . ', failed ' . ($summary['failed'] ?? 0) . '.');
}

/* =====================================================================
 * BALANCE CHECK (on demand, like the OCR "Test Connection" button)
 * ================================================================== */
$active_provider        = mh_active_sms_provider();
$semaphore_key_problem  = mh_sms_provider_problem();
$balance = null;
if ($active_provider === 'semaphore' && $semaphore_key_problem === null && isset($_GET['probe'])) {
    $balance = mh_semaphore_get_balance();
}

/* =====================================================================
 * DATA FOR THE PAGE
 * ================================================================== */
$pending_count = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM scheduled_sms WHERE status = 'pending'");
if ($r) { $pending_count = (int) ($r->fetch_assoc()['c'] ?? 0); }

$due_now_count = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM scheduled_sms WHERE status = 'pending' AND scheduled_date <= CURDATE()");
if ($r) { $due_now_count = (int) ($r->fetch_assoc()['c'] ?? 0); }

$upcoming = $conn->query(
    "SELECT s.id, s.phone_number, s.message, s.scheduled_date, s.source_module, s.patient_id,
            p.first_name, p.middle_name, p.last_name
     FROM scheduled_sms s
     LEFT JOIN patients p ON s.patient_id = p.id
     WHERE s.status = 'pending'
     ORDER BY s.scheduled_date ASC, s.id ASC
     LIMIT 25"
);

$recent_logs = $conn->query(
    "SELECT l.id, l.phone_number, l.message, l.status, l.response_log, l.triggered_by, l.created_at, l.patient_id,
            p.first_name, p.middle_name, p.last_name
     FROM sms_logs l
     LEFT JOIN patients p ON l.patient_id = p.id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 50"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Reminders - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <?php if (function_exists('mh_theme_style_tag')) { echo mh_theme_style_tag(); } ?>
    <style>
        body { background-color: #f1f5f9; }
        .sms-container { width: 95%; max-width: 1400px; margin: 40px auto; }
        .sms-header h1 { margin: 0 0 5px; color: #1e293b; font-family: Georgia, serif; font-size: 1.9rem; }
        .sms-header p { margin: 0; color: #64748b; }

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 24px 0; }
        .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; }
        .stat-card .num { font-size: 1.9rem; font-weight: 800; color: #1e293b; }
        .stat-card .lbl { font-size: 0.82rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }

        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 24px; }
        .card > header { padding: 16px 22px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 10px; }
        .card > header h3 { margin: 0; font-size: 1.05rem; color: #1e293b; }
        .card .body { padding: 22px; }

        .btn { padding: 10px 18px; border: 0; border-radius: 7px; font-weight: 700; font-size: 0.92rem; cursor: pointer; font-family: inherit; text-decoration:none; display:inline-block; }
        .btn-main { background: var(--button-bg, #4ca1af); color: var(--button-text, #fff); }
        .btn-soft { background: #e2e8f0; color: #334155; }
        .btn-lg { padding: 14px 26px; font-size: 1rem; }

        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.92rem; line-height: 1.55; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }

        table.sms-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        table.sms-table th { text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; padding: 8px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        table.sms-table td { padding: 9px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        table.sms-table .msg-cell { max-width: 360px; color: #475569; }
        .table-scroll { overflow-x: auto; }

        .badge { display:inline-block; padding: 3px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .badge-sent { background:#dcfce7; color:#166534; }
        .badge-failed { background:#fee2e2; color:#991b1b; }
        .badge-pending { background:#fef9c3; color:#854d0e; }
        .badge-manual { background:#e0f2fe; color:#075985; }
        .badge-cron { background:#ede9fe; color:#5b21b6; }
        code.inline { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

<?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

<div class="main-content">
    <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

    <div class="sms-container">

        <div class="sms-header">
            <h1 style="display:flex; align-items:center; gap:10px;"><?php echo mh_icon('message'); ?> SMS Reminders</h1>
            <p>Follow-up appointment reminders queued from Check-ups, Immunization and Maternal Care, sent via Semaphore.</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>" style="margin-top:20px;">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($semaphore_key_problem !== null): ?>
            <div class="alert alert-warn">
                <strong><?php echo $active_provider === 'android_gateway' ? 'Android SMS Gateway' : 'Semaphore'; ?> is not fully configured:</strong>
                <?php echo htmlspecialchars($semaphore_key_problem); ?>
                &mdash; <a href="system_settings.php?tab=sms" style="color:#92400e; font-weight:700;">Fix it in System Settings</a>.
            </div>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="num"><?php echo $pending_count; ?></div>
                <div class="lbl">Pending in Queue</div>
            </div>
            <div class="stat-card">
                <div class="num"><?php echo $due_now_count; ?></div>
                <div class="lbl">Due Right Now</div>
            </div>
            <div class="stat-card">
                <div class="num"><?php echo $balance !== null && $balance['success'] ? htmlspecialchars($balance['balance']) : '—'; ?></div>
                <div class="lbl">Semaphore Credit Balance</div>
            </div>
        </div>

        <div class="card">
            <header>
                <h3>Manual Trigger (Local Testing)</h3>
            </header>
            <div class="body" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <form method="POST" style="margin:0;">
                    <button type="submit" name="run_queue_now" data-confirm="Send all pending SMS messages now?" data-confirm-title="Run SMS queue?" data-confirm-type="warning" data-confirm-ok="Send now" class="btn btn-main btn-lg">▶ Run Pending SMS Queue Now</button>
                </form>
                <a href="?probe=1" class="btn btn-soft">Check Semaphore Balance</a>
                <p style="margin:0; color:#64748b; font-size:0.85rem; max-width: 480px;">
                    Sends every reminder in <code class="inline">scheduled_sms</code> with
                    <code class="inline">scheduled_date &lt;= today</code> and <code class="inline">status = 'pending'</code> right now.
                    On a live server, <code class="inline">cron/send_daily_reminders.php</code> does this automatically every morning
                    &mdash; see the instructions in that file for setting up the scheduled task.
                </p>
                <?php if ($balance !== null && !$balance['success']): ?>
                    <div class="alert alert-error" style="width:100%; margin:0;"><?php echo htmlspecialchars($balance['error']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <header><h3>Upcoming Pending Reminders <span style="color:#94a3b8; font-weight:normal; font-size:0.85rem;">(next 25)</span></h3></header>
            <div class="body">
                <?php if (!$upcoming || $upcoming->num_rows === 0): ?>
                    <p style="color:#64748b; margin:0;">Nothing queued. Enter a follow-up date on a Check-up, Immunization or Maternal Care record to schedule one.</p>
                <?php else: ?>
                    <div class="table-scroll">
                    <table class="sms-table">
                        <thead>
                            <tr><th>Send Date</th><th>Patient</th><th>Phone</th><th>Module</th><th>Message</th></tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $upcoming->fetch_assoc()): ?>
                            <tr>
                                <td style="white-space:nowrap; font-weight:700; color:<?php echo $row['scheduled_date'] <= date('Y-m-d') ? '#b91c1c' : '#1e293b'; ?>;">
                                    <?php echo htmlspecialchars(date('M d, Y', strtotime($row['scheduled_date']))); ?>
                                    <?php if ($row['scheduled_date'] <= date('Y-m-d')): ?><br><span class="badge badge-failed">Due</span><?php endif; ?>
                                </td>
                                <td><?php echo $row['first_name'] ? htmlspecialchars(mh_format_patient_name($row['first_name'], $row['middle_name'] ?? '', $row['last_name'])) : '<span style="color:#94a3b8;">Patient #' . (int) $row['patient_id'] . '</span>'; ?></td>
                                <td><?php echo htmlspecialchars($row['phone_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['source_module'] ?: '—'); ?></td>
                                <td class="msg-cell"><?php echo htmlspecialchars($row['message']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <header><h3>Recent Send Logs <span style="color:#94a3b8; font-weight:normal; font-size:0.85rem;">(last 50)</span></h3></header>
            <div class="body">
                <?php if (!$recent_logs || $recent_logs->num_rows === 0): ?>
                    <p style="color:#64748b; margin:0;">No SMS has been sent yet. Use "Run Pending SMS Queue Now" above once a reminder is due, or send a test message from System Settings &gt; SMS Reminders.</p>
                <?php else: ?>
                    <div class="table-scroll">
                    <table class="sms-table">
                        <thead>
                            <tr><th>When</th><th>Status</th><th>Trigger</th><th>Patient</th><th>Phone</th><th>Message</th><th>API Response</th></tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $recent_logs->fetch_assoc()): ?>
                            <tr>
                                <td style="white-space:nowrap; color:#64748b;"><?php echo htmlspecialchars(date('M d, g:i A', strtotime($row['created_at']))); ?></td>
                                <td><span class="badge badge-<?php echo $row['status'] === 'sent' ? 'sent' : 'failed'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                <td><span class="badge badge-<?php echo $row['triggered_by'] === 'manual' ? 'manual' : 'cron'; ?>"><?php echo htmlspecialchars($row['triggered_by']); ?></span></td>
                                <td><?php echo $row['first_name'] ? htmlspecialchars(mh_format_patient_name($row['first_name'], $row['middle_name'] ?? '', $row['last_name'])) : ($row['patient_id'] ? '<span style="color:#94a3b8;">Patient #' . (int) $row['patient_id'] . '</span>' : '<span style="color:#94a3b8;">—</span>'); ?></td>
                                <td><?php echo htmlspecialchars($row['phone_number']); ?></td>
                                <td class="msg-cell"><?php echo htmlspecialchars($row['message']); ?></td>
                                <td class="msg-cell" style="color:#64748b; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:0.78rem;"><?php echo htmlspecialchars($row['response_log'] ?? ''); ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
</body>
</html>