<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';

require_permission('view_system_logs');

$system_name = $system_name ?? mh_setting('system_name');
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

/* ---------------- filters (all validated; all values are bound, never concatenated) ---------------- */
$is_date = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false; };
$from   = $is_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to     = $is_date($_GET['to'] ?? '') ? $_GET['to'] : '';
if ($from !== '' && $to !== '' && $from > $to) { list($from, $to) = [$to, $from]; }   // typed backwards: just swap
$action = trim((string) ($_GET['action'] ?? ''));
$uid    = (int) ($_GET['user'] ?? 0);
$q      = trim((string) ($_GET['q'] ?? ''));
$print  = isset($_GET['print']);
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per    = 100;

$where = []; $params = []; $types = '';
if ($from !== '') { $where[] = 'l.created_at >= ?'; $params[] = $from . ' 00:00:00'; $types .= 's'; }
if ($to !== '')   { $where[] = 'l.created_at < ?';  $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; $types .= 's'; }
if ($action !== '') { $where[] = 'l.action = ?'; $params[] = $action; $types .= 's'; }
if ($uid > 0)       { $where[] = 'l.user_id = ?'; $params[] = $uid; $types .= 'i'; }
if ($q !== '') {
    $like = '%' . addcslashes(mb_substr($q, 0, 80), '\\%_') . '%';
    $where[] = "(l.details LIKE ? OR l.action LIKE ? OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?)";
    array_push($params, $like, $like, $like); $types .= 'sss';
}
$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$from_sql  = " FROM activity_logs l LEFT JOIN staff s ON l.user_id = s.id" . $where_sql;

$run = function ($sql, $bindTypes, $bindParams) use ($conn) {
    $st = $conn->prepare($sql);
    if (!$st) { error_log('activity_logs query failed: ' . $conn->error); return []; }
    if ($bindParams) { $st->bind_param($bindTypes, ...$bindParams); }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
};

$total = (int) (($run("SELECT COUNT(*) AS n" . $from_sql, $types, $params)[0]['n']) ?? 0);
$limit = $print ? 3000 : $per;
$pages = max(1, (int) ceil($total / $per));
if (!$print && $page > $pages) { $page = $pages; }
$offset = $print ? 0 : ($page - 1) * $per;
$logs = $run("SELECT l.id, l.created_at, l.action, l.details, s.first_name, s.last_name, COALESCE(s.role, l.user_role) AS role"
           . $from_sql . " ORDER BY l.created_at DESC, l.id DESC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$limit, $offset]));

$unique_actions = array_column($run("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC", '', []), 'action');
$users = $run("SELECT DISTINCT s.id, s.first_name, s.last_name FROM activity_logs l JOIN staff s ON s.id = l.user_id ORDER BY s.first_name, s.last_name", '', []);

$filtered = ($from !== '' || $to !== '' || $action !== '' || $uid > 0 || $q !== '');
$range_label = ($from !== '' || $to !== '')
    ? (($from !== '' ? date('M d, Y', strtotime($from)) : 'the beginning') . ' - ' . ($to !== '' ? date('M d, Y', strtotime($to)) : 'today'))
    : 'All dates';
$user_label = '';
foreach ($users as $u) { if ((int) $u['id'] === $uid) { $user_label = trim($u['first_name'] . ' ' . $u['last_name']); } }

$base = ['from' => $from, 'to' => $to, 'action' => $action, 'user' => $uid ?: '', 'q' => $q];
$url = function (array $extra = []) use ($base) { return '?' . http_build_query(array_filter(array_merge($base, $extra), function ($v) { return $v !== '' && $v !== null && $v !== 0; })); };

if ($print) {
    // Printing the audit trail is itself an auditable act.
    logActivity($conn, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? ''), 'Printed System Logs',
        'Printed ' . count($logs) . ' log entr' . (count($logs) === 1 ? 'y' : 'ies') . ' (' . $range_label . ').');
}
?>
<?php
require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => $print ? 'System Logs - Print' : 'System Logs']);
?>
    <style>
        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
            border: 1px solid;
            margin-top: 4px;
        }
        .role-admin { background: #e0f2fe; color: #0284c7; border-color: #7dd3fc; }
        .role-doctor { background: #d1fae5; color: #059669; border-color: #6ee7b7; }
        .role-midwife { background: #fce7f3; color: #db2777; border-color: #fbcfe8; }
        .role-dental { background: #fef3c7; color: #d97706; border-color: #fde68a; }
        .role-bhw { background: #f1f5f9; color: var(--text-main, #334155); border-color: var(--border-color, #cbd5e1); }
        .role-inventory { background: #ffedd5; color: #c2410c; border-color: #fed7aa; }
        .lg-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; padding: 14px 20px; border-bottom: 1px solid var(--border-color, #e2e8f0); }
        .lg-filters label { display: block; font-size: .74rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted, #64748b); margin-bottom: 4px; }
        .lg-presets { display: flex; gap: 6px; flex-wrap: wrap; padding: 10px 20px 0; }
        .lg-presets a { padding: 5px 12px; border-radius: 999px; font-size: .8rem; font-weight: 700; text-decoration: none; border: 1px solid var(--border-color, #cbd5e1); color: var(--text-muted, #64748b); }
        .lg-presets a:hover { border-color: var(--primary, #4ca1af); color: var(--primary, #4ca1af); }
        .lg-pager { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 20px; flex-wrap: wrap; font-size: .88rem; color: var(--text-muted, #64748b); }
        .lg-pager a, .lg-pager span.cur { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border-color, #cbd5e1); text-decoration: none; color: var(--text-main, #334155); font-weight: 700; }
        .lg-pager span.cur { background: var(--primary, #4ca1af); color: #fff; border-color: var(--primary, #4ca1af); }
        .lg-pager .off { opacity: .4; pointer-events: none; }
        /* print layout (also used by ?print=1) */
        .pr-head { margin: 0 0 14px; }
        .pr-head h1 { margin: 0 0 4px; font-size: 1.4rem; }
        .pr-head p { margin: 2px 0; font-size: .9rem; color: #475569; }
        .pr-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .pr-table th, .pr-table td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; vertical-align: top; }
        .pr-table th { background: #f1f5f9; }
        .pr-foot { margin-top: 14px; font-size: .8rem; color: #475569; }
        @media print { .sidebar, .topbar, .lg-filters, .lg-presets, .lg-pager, .no-print { display: none !important; } .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; } body { background: #fff !important; } }
    </style>
<?php if ($print): mh_layout_open(['shell' => false, 'body_attr' => 'style="background:#fff; color:#0f172a; display:block; padding:24px; font-family: system-ui, sans-serif;"']); ?>
    <div class="pr-head">
        <h1><?php echo $h($system_name); ?> &mdash; System Logs (Audit Trail)</h1>
        <p><strong>Period:</strong> <?php echo $h($range_label); ?><?php if ($action !== ''): ?> &middot; <strong>Action:</strong> <?php echo $h($action); ?><?php endif; ?><?php if ($user_label !== ''): ?> &middot; <strong>User:</strong> <?php echo $h($user_label); ?><?php endif; ?><?php if ($q !== ''): ?> &middot; <strong>Search:</strong> &ldquo;<?php echo $h($q); ?>&rdquo;<?php endif; ?></p>
        <p><strong>Entries:</strong> <?php echo count($logs); ?><?php echo $total > count($logs) ? ' of ' . (int) $total . ' (the newest 3,000 are printed - narrow the dates to print the rest)' : ''; ?></p>
    </div>
    <table class="pr-table">
        <thead><tr><th style="width:140px;">Time</th><th style="width:170px;">User</th><th style="width:150px;">Action</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $row): $user_name = $row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'System / Unknown'; ?>
            <tr><td><?php echo $h(date('M d, Y h:i A', strtotime($row['created_at']))); ?></td>
                <td><?php echo $h($user_name); ?><?php echo !empty($row['role']) ? ' <small>(' . $h($row['role']) . ')</small>' : ''; ?></td>
                <td><?php echo $h($row['action']); ?></td><td><?php echo $h($row['details']); ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="4" style="text-align:center; padding:20px;">No log entries for this selection.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="pr-foot">Printed by <?php echo $h(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['role'] ?? 'staff')); ?> on <?php echo $h(date('M d, Y h:i A')); ?>.</p>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
<?php mh_layout_end(); else: mh_layout_open(); ?>

        <div class="dashboard-container">

            <div class="unified-panel">
                <div class="panel-controls" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <h3 style="margin:0; font-size: 1.2rem; color: var(--heading-color, #1e293b);">Audit Trail <small style="font-weight:600; color: var(--text-muted, #64748b);">(<?php echo number_format($total); ?>)</small></h3>
                    <a class="btn-secondary no-print" href="<?php echo $h($url(['print' => 1])); ?>" target="_blank" rel="noopener"><?php echo mh_icon('file-text'); ?> Print<?php echo $filtered ? ' filtered logs' : ''; ?></a>
                </div>

                <div class="lg-presets">
                    <?php $t = date('Y-m-d'); ?>
                    <a href="?from=<?php echo $t; ?>&to=<?php echo $t; ?>">Today</a>
                    <a href="?from=<?php echo date('Y-m-d', strtotime('-6 days')); ?>&to=<?php echo $t; ?>">Last 7 days</a>
                    <a href="?from=<?php echo date('Y-m-d', strtotime('-29 days')); ?>&to=<?php echo $t; ?>">Last 30 days</a>
                    <a href="?from=<?php echo date('Y-m-01'); ?>&to=<?php echo $t; ?>">This month</a>
                    <a href="?from=<?php echo date('Y-m-01', strtotime('first day of last month')); ?>&to=<?php echo date('Y-m-t', strtotime('first day of last month')); ?>">Last month</a>
                </div>

                <form method="GET" class="lg-filters">
                    <div><label for="lgFrom">From</label><input type="date" id="lgFrom" name="from" value="<?php echo $h($from); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label for="lgTo">To</label><input type="date" id="lgTo" name="to" value="<?php echo $h($to); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label for="lgAction">Action</label>
                        <select id="lgAction" name="action"><option value="">All actions</option>
                            <?php foreach ($unique_actions as $a): ?><option value="<?php echo $h($a); ?>"<?php echo $a === $action ? ' selected' : ''; ?>><?php echo $h($a); ?></option><?php endforeach; ?></select></div>
                    <div><label for="lgUser">User</label>
                        <select id="lgUser" name="user"><option value="">All users</option>
                            <?php foreach ($users as $u): ?><option value="<?php echo (int) $u['id']; ?>"<?php echo (int) $u['id'] === $uid ? ' selected' : ''; ?>><?php echo $h(trim($u['first_name'] . ' ' . $u['last_name'])); ?></option><?php endforeach; ?></select></div>
                    <div style="flex:1; min-width:170px;"><label for="lgQ">Search</label><input type="text" id="lgQ" name="q" value="<?php echo $h($q); ?>" placeholder="Details, action or name..." style="width:100%;"></div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn-submit btn-sm">Apply</button>
                        <?php if ($filtered): ?><a class="btn-secondary btn-sm" href="activity_logs.php" style="text-decoration:none; display:inline-flex; align-items:center;">Clear</a><?php endif; ?>
                    </div>
                </form>

                <div style="overflow-x: auto;">
                    <table class="unified-table" id="logTable">
                        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                            <?php if ($logs): foreach ($logs as $row):
                                $role_class = "role-" . strtolower($row['role'] ?? 'bhw');
                                $user_name = $row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'System / Unknown';
                            ?>
                            <tr>
                                <td style="color: var(--text-muted, #64748b); font-size: 0.9rem; white-space: nowrap;"><?php echo $h(date("M d, Y - h:i A", strtotime($row['created_at']))); ?></td>
                                <td><strong style="color: var(--heading-color, #1e293b); display: block;"><?php echo $h($user_name); ?></strong>
                                    <span class="role-badge <?php echo $h($role_class); ?>"><?php echo $h($row['role'] ?? 'N/A'); ?></span></td>
                                <td><strong style="color: var(--heading-color, #1e293b);"><?php echo $h($row['action']); ?></strong></td>
                                <td style="color: var(--text-main, #334155); line-height: 1.5;"><?php echo $h($row['details']); ?></td>
                            </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="4" style="text-align:center; padding: 40px; color: var(--text-muted, #64748b);"><?php echo $filtered ? 'No log entries match these filters.' : 'No system logs found.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total > 0): ?>
                <div class="lg-pager">
                    <span>Showing <?php echo number_format($offset + 1); ?>&ndash;<?php echo number_format(min($offset + $per, $total)); ?> of <?php echo number_format($total); ?></span>
                    <span style="display:flex; gap:6px; align-items:center;">
                        <a class="<?php echo $page <= 1 ? 'off' : ''; ?>" href="<?php echo $h($url(['page' => $page - 1])); ?>">&larr; Newer</a>
                        <span class="cur">Page <?php echo $page; ?> / <?php echo $pages; ?></span>
                        <a class="<?php echo $page >= $pages ? 'off' : ''; ?>" href="<?php echo $h($url(['page' => $page + 1])); ?>">Older &rarr;</a>
                    </span>
                </div>
                <?php endif; ?>
            </div>

        </div>
    <?php mh_layout_close(); mh_layout_end(); ?>
<?php endif; ?>

