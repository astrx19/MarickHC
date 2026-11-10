<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/disease_stats.php';

require_permission('view_disease_statistics');

$user_id   = (int) ($_SESSION['user_id'] ?? 0);
$role      = (string) ($_SESSION['role'] ?? '');
$is_head    = mh_is_health_head($role);          // only the Health Head edits what is tracked (System Settings)

mh_disease_ensure_tables($conn);
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$msg_ok = '';
$msg_err = '';

/* ---------------- Quick Track POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_track_condition'])) {
    if (!mh_csrf_check()) {
        $msg_err = 'Security token expired. Please try again.';
    } elseif (!$is_head) {
        $msg_err = 'Only the Health Head can add tracked conditions.';
    } else {
        $cname = trim((string) ($_POST['condition_name'] ?? ''));
        $ckw   = mb_substr(trim((string) ($_POST['condition_keywords'] ?? '')), 0, 255);
        $cmin  = max(1, min(999, (int) ($_POST['min_cases'] ?? 3)));
        $cfac  = max(1.1, min(9.9, (float) ($_POST['spike_factor'] ?? 2.0)));

        if ($cname === '' || mb_strlen($cname) > 100) {
            $msg_err = 'Please enter a valid condition name (up to 100 characters).';
        } elseif ($ckw === '' || mh_disease_regex($ckw) === null) {
            $msg_err = 'Please enter at least one keyword (2+ letters).';
        } else {
            $st = $conn->prepare("INSERT INTO tracked_conditions (name, keywords, min_cases, spike_factor) VALUES (?, ?, ?, ?)");
            $st->bind_param('ssid', $cname, $ckw, $cmin, $cfac);
            $ok = $st->execute();
            $dup = ($conn->errno === 1062);
            $st->close();
            if ($ok) {
                logActivity($conn, $user_id, $role, 'Disease Tracking', "Added tracked condition \"$cname\" from Disease Statistics.");
                $msg_ok = "Now tracking \"$cname\". It will be continuously monitored for outbreaks.";
            } else {
                $msg_err = $dup ? "Condition \"$cname\" is already being tracked." : "Failed to add condition. Please try again.";
            }
        }
    }
}

/* ---------------- data ---------------- */
$stats  = mh_disease_compute($conn);

// Period overview (Step 8): default last 30 days; any range up to one year.
$is_d = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false; };
$ov_to   = $is_d($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$ov_from = $is_d($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime($ov_to . ' -29 days'));
if ($ov_from > $ov_to) { list($ov_from, $ov_to) = [$ov_to, $ov_from]; }
if ($ov_to > date('Y-m-d')) { $ov_to = date('Y-m-d'); }
if ($ov_from < date('Y-m-d', strtotime($ov_to . ' -365 days'))) { $ov_from = date('Y-m-d', strtotime($ov_to . ' -365 days')); }
$ov = mh_disease_overview($conn, $ov_from, $ov_to);
$tracked_total = count($stats['conditions']);
// Only illnesses that actually have logged cases (last 12 weeks) are listed.
$conds  = array_values(array_filter($stats['conditions'], function ($c) { return $c['total'] > 0; }));
$hidden_count = $tracked_total - count($conds);
$detail = null;
$detail_id = (int) ($_GET['cond'] ?? 0);
foreach ($conds as $c) { if ((int) $c['id'] === $detail_id) { $detail = $c; } }
$status_style = [
    'spike'  => ['Spike',  '#fee2e2', '#991b1b'],
    'rising' => ['Rising', '#fef3c7', '#92400e'],
    'normal' => ['Normal', '#dcfce7', '#166534'],
];

if (!empty($stats['unclassified'])) {
    $stats['unclassified'] = array_filter($stats['unclassified'], function ($txt) {
        return mh_disease_is_valid_diagnosis((string) $txt);
    }, ARRAY_FILTER_USE_KEY);
}

$dominantCondition = key($ov['by_cond']) ?: (isset($conds[0]['name']) ? $conds[0]['name'] : 'None logged');
$dominantCount = current($ov['by_cond']) ?: (isset($conds[0]['cur']) ? $conds[0]['cur'] : 0);
$outbreakBadge = $stats['spikes'] > 0
    ? ['Active Spike Alert', '#fee2e2', '#991b1b', 'Outbreak Triggered']
    : ($stats['rising'] > 0
        ? ['Rising Trend', '#fef3c7', '#92400e', 'Cases Rising']
        : ['Normal Baseline', '#dcfce7', '#166534', 'Stable Baseline']);

// AI Executive Outbreak Summary
$ai_summary = mh_disease_ai_summary($conn, $stats, $ov);
?>
<?php
require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'Disease Statistics']);
?>
    <style>
        .ds-msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
        .ds-ok { background: #dcfce7; color: #166534; } .ds-err { background: #fee2e2; color: #991b1b; }
        .ds-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .ds-stat { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 16px 18px; display: flex; flex-direction: column; justify-content: space-between; }
        .ds-stat b { display: block; font-size: 1.85rem; line-height: 1.15; color: var(--heading-color, #1e293b); }
        .ds-stat span { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #64748b); margin-top: 4px; display: block; }
        .ds-alert { border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
        .ds-alert.ds-calm { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
        .ds-badge { padding: 3px 12px; border-radius: 999px; font-size: .75rem; font-weight: 700; white-space: nowrap; }
        .ds-row-link { color: inherit; text-decoration: none; font-weight: 700; }
        .ds-bar { display: flex; align-items: center; gap: 10px; margin: 6px 0; font-size: .88rem; }
        .ds-bar > span:first-child { width: 70px; color: var(--text-muted, #64748b); }
        .ds-bar > div { flex: 1; background: var(--bg-hover, #f1f5f9); border-radius: 6px; height: 14px; overflow: hidden; }
        .ds-bar > div > i { display: block; height: 100%; background: #4ca1af; border-radius: 6px; }
        .ds-grid3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; }
        .ds-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: end; }
        .ds-form label { display: block; font-size: .78rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted, #64748b); margin-bottom: 4px; }
        .ds-form input[type=text], .ds-form input[type=number] { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; background: var(--card-bg, #fff); color: var(--text-color, #1e293b); }
        .ds-small { font-size: .85rem; padding: 7px 14px; cursor: pointer; }
        /* Collapsible Drawer for Demographic Micro-Breakdowns */
        .ds-drawer { border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; background: var(--card-bg, #fff); margin: 18px 0; overflow: hidden; }
        .ds-drawer summary { padding: 13px 18px; cursor: pointer; font-weight: 700; font-size: 0.95rem; color: var(--heading-color, #1e293b); display: flex; justify-content: space-between; align-items: center; background: var(--bg-hover, #f8fafc); user-select: none; list-style: none; }
        .ds-drawer summary::-webkit-details-marker { display: none; }
        .ds-drawer summary:hover { background: var(--border-color, #f1f5f9); }
        .ds-drawer-hint { font-size: 0.8rem; font-weight: 600; color: var(--text-muted, #64748b); }
        .ds-drawer-content { padding: 20px; border-top: 1px solid var(--border-color, #e2e8f0); }
        @media print { .ds-drawer { display: block !important; } .ds-drawer summary { display: none !important; } .ds-drawer-content { padding: 0 !important; border-top: none !important; } }
        /* AI Health Insights Card */
        .ds-ai-card {
            background: linear-gradient(135deg, rgba(76, 161, 175, 0.08) 0%, rgba(14, 165, 233, 0.05) 100%);
            border: 1px solid rgba(76, 161, 175, 0.35);
            border-left: 5px solid #0ea5e9;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 15px rgba(14, 165, 233, 0.06);
        }
        .ds-ai-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid rgba(76, 161, 175, 0.15);
            padding-bottom: 10px;
        }
        .ds-ai-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ds-ai-title h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--heading-color, #1e293b);
        }
        .ds-ai-title p {
            margin: 2px 0 0;
            font-size: 0.8rem;
            color: var(--text-muted, #64748b);
        }
        .ds-ai-icon {
            display: inline-flex;
            padding: 8px;
            border-radius: 10px;
            background: #e0f2fe;
            color: #0284c7;
        }
        .ds-ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .ds-ai-text {
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text-color, #1e293b);
            margin-bottom: 14px;
            font-weight: 500;
        }
        .ds-ai-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.82rem;
        }
        .ds-ai-pill {
            background: var(--card-bg, #fff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 8px;
            padding: 5px 12px;
            color: var(--text-color, #334155);
        }
        .ds-btn-spin {
            display: inline-block;
            transition: transform 0.5s ease;
        }
        .ds-spinning {
            animation: ds-spin 1s linear infinite;
        }
        @keyframes ds-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @media print { .no-print, .sidebar, .topbar, .ds-ai-header button { display: none !important; } }
    </style>
<?php mh_layout_open(); ?>
        <div class="dashboard-container">

            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                <div>
                    <h1 style="margin:0 0 4px; font-family: Georgia, serif; color:var(--heading-color, #1e293b);">Disease Statistics</h1>
                    <p style="margin:0; color:var(--text-muted, #64748b);">Community illness trends from consultation, dental and child check-up diagnoses. Counts only &mdash; no patient names are shown. As of <?php echo $h(date('F j, Y', strtotime($stats['today']))); ?>.</p>
                </div>
                <button type="button" class="btn-primary no-print" onclick="window.print()">Print</button>
            </div>

            <?php if ($msg_ok !== ''): ?>
                <div class="ds-msg ds-ok"><?php echo $h($msg_ok); ?></div>
            <?php endif; ?>
            <?php if ($msg_err !== ''): ?>
                <div class="ds-msg ds-err"><?php echo $h($msg_err); ?></div>
            <?php endif; ?>

            <!-- AI Health Insights & Outbreak Summary Card -->
            <div class="ds-ai-card">
                <div class="ds-ai-header">
                    <div class="ds-ai-title">
                        <span class="ds-ai-icon"><?php echo mh_icon('activity'); ?></span>
                        <div>
                            <h3>AI Health Insights &amp; Outbreak Summary</h3>
                            <p>Automated clinical surveillance analysis &amp; epidemiological assessment for Health Head</p>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="ds-ai-badge" id="ai-engine-badge"><?php echo $h($ai_summary['engine_label']); ?></span>
                        <button type="button" class="btn-secondary ds-small no-print" id="btn-refresh-ai" onclick="refreshAiSummary()" style="padding:6px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                            <span class="ds-btn-spin" id="ai-spin-icon">&#x21bb;</span> Refresh Analysis
                        </button>
                    </div>
                </div>
                <div class="ds-ai-body">
                    <div class="ds-ai-text" id="ai-summary-text"><?php echo $h($ai_summary['summary']); ?></div>
                    <div class="ds-ai-pills">
                        <span class="ds-ai-pill"><strong>Dominant Cluster:</strong> <?php echo $h(key($ov['by_cond']) ?: 'General Consultation'); ?></span>
                        <span class="ds-ai-pill"><strong>Outbreak Status:</strong> <?php echo $stats['spikes'] > 0 ? '<span style="color:#b91c1c; font-weight:700;">Active Spike Alert</span>' : ($stats['rising'] > 0 ? '<span style="color:#b45309; font-weight:700;">Rising Trend</span>' : '<span style="color:#15803d; font-weight:700;">Normal Baseline</span>'); ?></span>
                        <span class="ds-ai-pill"><strong>Area Center:</strong> <?php echo $h(key($ov['by_area']) ?: 'Marick Area'); ?></span>
                        <span class="ds-ai-pill" style="opacity:0.85;">Updated: <span id="ai-generated-time"><?php echo $h($ai_summary['generated_at']); ?></span></span>
                    </div>
                </div>
            </div>

            <?php if ($stats['spikes'] > 0): ?>
                <div class="ds-alert"><strong>Spike alert:</strong>
                    <?php
                    $names = [];
                    foreach ($conds as $c) { if ($c['status'] === 'spike') { $names[] = $h($c['name']) . ' (' . (int) $c['cur'] . ' this week)'; } }
                    echo implode(', ', $names);
                    ?>. Cases this week are well above the recent average &mdash; consider notifying the health officer.
                </div>
            <?php else: ?>
                <div class="ds-alert ds-calm">No unusual increases in the tracked conditions this week.</div>
            <?php endif; ?>

            <!-- Unified KPI Metrics Header -->
            <div class="ds-stats ds-kpi-header">
                <div class="ds-stat">
                    <div>
                        <b><?php echo (int) $stats['cases_30d']; ?></b>
                        <span>Total Cases (30d)</span>
                    </div>
                    <small style="color:var(--text-muted, #64748b); font-size:0.75rem; margin-top:6px;"><?php echo (int) $ov['patients']; ?> distinct patients (<?php echo (int) $ov['total']; ?> in period)</small>
                </div>
                <div class="ds-stat">
                    <div>
                        <b style="color:<?php echo $stats['spikes'] > 0 ? '#dc2626' : 'var(--heading-color, #1e293b)'; ?>;"><?php echo (int) $stats['spikes']; ?></b>
                        <span>Active Spikes</span>
                    </div>
                    <small style="color:var(--text-muted, #64748b); font-size:0.75rem; margin-top:6px;"><?php echo (int) $stats['rising']; ?> condition<?php echo $stats['rising'] === 1 ? '' : 's'; ?> rising</small>
                </div>
                <div class="ds-stat">
                    <div>
                        <b style="font-size:1.35rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo $h($dominantCondition); ?>"><?php echo $h($dominantCondition); ?></b>
                        <span>Dominant Condition</span>
                    </div>
                    <small style="color:var(--text-muted, #64748b); font-size:0.75rem; margin-top:6px;"><?php echo (int) $dominantCount; ?> recent cases recorded</small>
                </div>
                <div class="ds-stat">
                    <div>
                        <span class="ds-badge" style="background:<?php echo $outbreakBadge[1]; ?>; color:<?php echo $outbreakBadge[2]; ?>; font-size:0.86rem; padding:4px 12px; display:inline-block; margin-bottom:4px;">
                            <?php echo $outbreakBadge[0]; ?>
                        </span>
                        <span>Outbreak Status</span>
                    </div>
                    <small style="color:var(--text-muted, #64748b); font-size:0.75rem; margin-top:6px;"><?php echo $outbreakBadge[3]; ?></small>
                </div>
            </div>

            <div class="unified-panel">
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
                    <div>
                        <h2 style="margin:0 0 4px; font-size:1.2rem;">Overview for a period</h2>
                        <p style="margin:0; color:var(--text-muted, #64748b); font-size:.9rem;"><?php echo $h(date('M d, Y', strtotime($ov['from']))); ?> &ndash; <?php echo $h(date('M d, Y', strtotime($ov['to']))); ?> (<?php echo (int) $ov['days']; ?> days). Consultations, dental visits and child check-ups.</p>
                    </div>
                    <form method="GET" class="no-print" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                        <div><label style="font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted,#64748b); display:block;">From</label><input type="date" name="from" value="<?php echo $h($ov['from']); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
                        <div><label style="font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted,#64748b); display:block;">To</label><input type="date" name="to" value="<?php echo $h($ov['to']); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
                        <button type="submit" class="btn-submit btn-sm">Apply</button>
                    </form>
                </div>
                <div class="ds-presets no-print" style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
                    <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'] as $n => $lbl): ?>
                        <a class="ds-small" style="border:1px solid var(--border-color,#cbd5e1); border-radius:999px; text-decoration:none; color:var(--text-muted,#64748b); font-weight:700;" href="?from=<?php echo date('Y-m-d', strtotime('-' . ($n - 1) . ' days')); ?>&to=<?php echo date('Y-m-d'); ?>"><?php echo $lbl; ?></a>
                    <?php endforeach; ?>
                </div>

                <?php
                $bars = function ($title, array $data) use ($h) {
                    $tot = max(1, array_sum($data));
                    echo '<div><h4 style="margin:0 0 8px;">' . $h($title) . '</h4>';
                    if (!array_sum($data)) { echo '<p style="color:var(--text-muted,#64748b); margin:0;">No data.</p>'; }
                    foreach ($data as $label => $n) {
                        if ($n <= 0) { continue; }
                        echo '<div class="ds-bar"><span style="width:110px;">' . $h($label) . '</span><div><i style="width:' . round($n / $tot * 100) . '%"></i></div><b>' . (int) $n . '</b></div>';
                    }
                    echo '</div>';
                };
                ?>

                <!-- Collapsible Demographic & Department Breakdown Drawer -->
                <details class="ds-drawer no-print">
                    <summary>
                        <span>Demographic &amp; Department Breakdowns (Sex, Age, Area, Department)</span>
                        <span class="ds-drawer-hint">Click to expand / collapse</span>
                    </summary>
                    <div class="ds-drawer-content">
                        <div class="ds-grid3">
                            <?php $bars('By department', $ov['by_source']); $bars('By sex', $ov['by_sex']); $bars('By age group', $ov['by_age']); $bars('By area', $ov['by_area']); ?>
                        </div>
                    </div>
                </details>

                <h4 style="margin:22px 0 8px;">Cases per week</h4>
                <?php $wmax = max(1, max($ov['by_week'] ?: [0])); ?>
                <div style="display:flex; align-items:flex-end; gap:6px; height:120px; overflow-x:auto; padding-bottom:4px;">
                    <?php foreach ($ov['by_week'] as $wk => $n): ?>
                        <div style="flex:1; min-width:34px; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%;" title="Week of <?php echo $h(date('M d', strtotime($wk))); ?>: <?php echo (int) $n; ?> case(s)">
                            <small style="font-weight:700;"><?php echo (int) $n; ?></small>
                            <div style="width:100%; background:#4ca1af; border-radius:5px 5px 0 0; height:<?php echo max(2, round($n / $wmax * 80)); ?>%;"></div>
                            <small style="color:var(--text-muted,#64748b); font-size:.68rem;"><?php echo $h(date('M j', strtotime($wk))); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="ds-grid3" style="margin-top:22px;">
                    <?php $bars('Top tracked conditions', $ov['by_cond']); $bars('Other diagnoses / complaints', $ov['other']); ?>
                </div>
            </div>

            <div class="unified-panel">
                <h2 style="margin:0 0 12px; font-size:1.2rem;">Tracked conditions</h2>
                <div style="overflow-x:auto;">
                <table class="unified-table">
                    <thead><tr><th>Condition</th><th>This week</th><th>Last week</th><th>4-week avg</th><th>12-week trend</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$conds): ?><tr><td colspan="6" style="text-align:center; padding:24px; color:var(--text-muted, #64748b);">No tracked illness has been logged in the last 12 weeks. Conditions appear here automatically once consultations with a matching diagnosis are saved.</td></tr><?php endif; ?>
                    <?php foreach ($conds as $c): [$lbl, $bg, $fg] = $status_style[$c['status']]; ?>
                        <tr>
                            <td><a class="ds-row-link" href="disease_statistics.php?cond=<?php echo (int) $c['id']; ?>#detail"><?php echo $h($c['name']); ?></a></td>
                            <td><strong><?php echo (int) $c['cur']; ?></strong></td>
                            <td><?php echo (int) $c['last']; ?></td>
                            <td><?php echo $h($c['prev_avg']); ?></td>
                            <td><?php echo mh_disease_sparkline($c['weekly'], $c['status'] === 'spike' ? '#dc2626' : ($c['status'] === 'rising' ? '#d97706' : '#4ca1af')); ?></td>
                            <td><span class="ds-badge" style="background:<?php echo $bg; ?>; color:<?php echo $fg; ?>;"><?php echo $lbl; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p style="margin:12px 0 0; font-size:.82rem; color:var(--text-muted, #64748b);">
                    <strong>Spike</strong> = at least the minimum number of cases this week and at least the set multiple of the previous 4-week average.
                    <strong>Rising</strong> = 2 or more cases and 25% above that average. Click a condition for its breakdown.
                    <?php if ($hidden_count > 0): ?><br><?php echo (int) $hidden_count; ?> other tracked condition<?php echo $hidden_count === 1 ? ' has' : 's have'; ?> no logged cases and <?php echo $hidden_count === 1 ? 'is' : 'are'; ?> hidden.
                    <?php if ($is_head): ?><a href="system_settings.php?tab=disease">Manage tracked conditions</a><?php endif; ?><?php endif; ?>
                </p>
            </div>

            <?php if ($detail): ?>
            <div class="unified-panel" id="detail" style="margin-top:20px;">
                <h2 style="margin:0 0 4px; font-size:1.2rem;"><?php echo $h($detail['name']); ?> &mdash; breakdown</h2>
                <p style="margin:0 0 14px; color:var(--text-muted, #64748b);">Weekly cases (last 12 weeks) and who was affected in the last 30 days (<?php echo (int) $detail['cases_30d']; ?> cases).</p>
                <div style="overflow-x:auto; margin-bottom:18px;">
                    <?php
                    $max = max(1, max($detail['weekly'])); $W = count($detail['weekly']);
                    echo '<svg width="' . ($W * 52) . '" height="170" role="img" aria-label="Weekly cases">';
                    foreach ($detail['weekly'] as $i => $v) {
                        $bh = $v > 0 ? max(3, ($v / $max) * 110) : 1;
                        $x = $i * 52 + 6;
                        echo '<rect x="' . $x . '" y="' . (130 - $bh) . '" width="38" height="' . $bh . '" rx="4" fill="' . ($i === $W - 1 ? '#4ca1af' : '#94a3b8') . '"/>';
                        echo '<text x="' . ($x + 19) . '" y="' . (124 - $bh) . '" text-anchor="middle" font-size="12" font-weight="700" fill="currentColor">' . (int) $v . '</text>';
                        echo '<text x="' . ($x + 19) . '" y="150" text-anchor="middle" font-size="10" fill="currentColor" opacity=".65">' . $h($stats['week_labels'][$i]) . '</text>';
                    }
                    echo '</svg>';
                    ?>
                </div>
                <div class="ds-grid3">
                    <?php
                    $blocks = ['Age group' => $detail['ages'], 'Sex' => $detail['sex'], 'Residence' => $detail['res']];
                    foreach ($blocks as $title => $data):
                        $tot = max(1, array_sum($data)); ?>
                        <div>
                            <h4 style="margin:0 0 8px;"><?php echo $h($title); ?></h4>
                            <?php foreach ($data as $label => $n): ?>
                                <div class="ds-bar"><span><?php echo $h($label); ?></span><div><i style="width:<?php echo round($n / $tot * 100); ?>%"></i></div><b><?php echo (int) $n; ?></b></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($stats['unclassified']): ?>
            <div class="unified-panel" style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                    <div>
                        <h2 style="margin:0 0 4px; font-size:1.2rem;">Other Diagnoses &amp; Complaints (Last 30 days)</h2>
                        <p style="margin:0; color:var(--text-muted, #64748b);">Filtered for clinical validity. Non-medical test data and gibberish have been excluded. Unmapped terms are standardized using the clinical intelligence engine.</p>
                    </div>
                    <?php if ($is_head): ?>
                        <a href="system_settings.php?tab=disease" class="btn-secondary ds-small no-print" style="text-decoration:none;">Manage Tracked Masterlist</a>
                    <?php endif; ?>
                </div>
                <div style="overflow-x:auto;">
                <table class="unified-table">
                    <thead>
                        <tr>
                            <th>Recorded Diagnosis / Complaint</th>
                            <th>AI Standardized Category</th>
                            <th style="width:90px; text-align:center;">Cases</th>
                            <?php if ($is_head): ?><th class="no-print" style="width:230px; text-align:right;">Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stats['unclassified'] as $txt => $n):
                        if (!mh_disease_is_valid_diagnosis($txt)) {
                            continue;
                        }
                        $std = mh_disease_standardize_diagnosis($txt, $conn);
                        $suggestedName = $std['name'] ?: mb_convert_case($txt, MB_CASE_TITLE, 'UTF-8');
                        $suggestedKeywords = $std['keywords'] ?: (mb_strtolower($suggestedName) . ', ' . mb_strtolower($txt));
                    ?>
                        <tr>
                            <td><strong><?php echo $h($txt); ?></strong></td>
                            <td>
                                <span class="ds-badge" style="background:#e0f2fe; color:#0369a1; font-size:0.8rem; font-weight:600;">
                                    <?php echo $h($std['category'] ?: $suggestedName); ?>
                                </span>
                            </td>
                            <td style="text-align:center;"><b><?php echo (int) $n; ?></b></td>
                            <?php if ($is_head): ?>
                                <td class="no-print" style="text-align:right; white-space:nowrap;">
                                    <button type="button" class="btn-primary ds-small" style="padding:5px 12px; margin-right:4px;"
                                        onclick="openQuickTrackModal('<?php echo $h(addslashes($suggestedName)); ?>', '<?php echo $h(addslashes($suggestedKeywords)); ?>')">
                                        + Track This
                                    </button>
                                    <a class="btn-secondary ds-small" style="text-decoration:none; display:inline-block; padding:5px 10px;"
                                       href="system_settings.php?tab=disease&amp;new=<?php echo urlencode($suggestedName); ?>&amp;kw=<?php echo urlencode($suggestedKeywords); ?>"
                                       title="Customize in System Settings">
                                        Edit
                                    </a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    <?php mh_layout_close(); ?>

    <!-- Quick Track Condition Modal (Health Head only) -->
    <?php if ($is_head): ?>
    <div class="modal-overlay" id="quickTrackModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.6); align-items:center; justify-content:center; padding:20px;">
        <div style="max-width:500px; width:100%; background:var(--card-bg, #fff); border-radius:14px; box-shadow:0 20px 40px rgba(0,0,0,0.25); border:1px solid var(--border-color,#e2e8f0); overflow:hidden;">
            <div style="padding:18px 22px; border-bottom:1px solid var(--border-color,#e2e8f0); display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:1.15rem; color:var(--heading-color,#1e293b);">Add to Tracked Conditions</h3>
                <button type="button" onclick="closeQuickTrackModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted,#64748b); line-height:1;">&times;</button>
            </div>
            <form method="POST" autocomplete="off" style="padding:22px;">
                <?php echo mh_csrf_field(); ?>
                <input type="hidden" name="quick_track_condition" value="1">
                
                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.78rem; font-weight:700; text-transform:uppercase; color:var(--text-muted,#64748b); margin-bottom:5px;">Condition Name</label>
                    <input type="text" name="condition_name" id="modal_condition_name" maxlength="100" required style="width:100%; box-sizing:border-box; padding:10px; border-radius:8px; border:1px solid var(--border-color,#cbd5e1);">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.78rem; font-weight:700; text-transform:uppercase; color:var(--text-muted,#64748b); margin-bottom:5px;">Keywords (Comma-separated)</label>
                    <input type="text" name="condition_keywords" id="modal_condition_keywords" maxlength="255" required style="width:100%; box-sizing:border-box; padding:10px; border-radius:8px; border:1px solid var(--border-color,#cbd5e1);">
                    <small style="color:var(--text-muted,#64748b); font-size:0.75rem;">Diagnoses matching any of these whole words will be classified under this condition.</small>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px;">
                    <div>
                        <label style="display:block; font-size:0.78rem; font-weight:700; text-transform:uppercase; color:var(--text-muted,#64748b); margin-bottom:5px;">Spike Threshold</label>
                        <input type="number" name="min_cases" value="3" min="1" max="999" required style="width:100%; box-sizing:border-box; padding:10px; border-radius:8px; border:1px solid var(--border-color,#cbd5e1);">
                        <small style="color:var(--text-muted,#64748b); font-size:0.75rem;">Min cases/week</small>
                    </div>
                    <div>
                        <label style="display:block; font-size:0.78rem; font-weight:700; text-transform:uppercase; color:var(--text-muted,#64748b); margin-bottom:5px;">Multiple of Avg</label>
                        <input type="number" name="spike_factor" value="2.0" min="1.1" max="9.9" step="0.1" required style="width:100%; box-sizing:border-box; padding:10px; border-radius:8px; border:1px solid var(--border-color,#cbd5e1);">
                        <small style="color:var(--text-muted,#64748b); font-size:0.75rem;">e.g. 2.0x average</small>
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn-secondary" onclick="closeQuickTrackModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save &amp; Track Condition</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function openQuickTrackModal(name, keywords) {
        var m = document.getElementById('quickTrackModal');
        if (!m) return;
        document.getElementById('modal_condition_name').value = name || '';
        document.getElementById('modal_condition_keywords').value = keywords || '';
        m.style.display = 'flex';
    }

    function closeQuickTrackModal() {
        var m = document.getElementById('quickTrackModal');
        if (m) m.style.display = 'none';
    }

    function refreshAiSummary() {
        var btn = document.getElementById('btn-refresh-ai');
        var icon = document.getElementById('ai-spin-icon');
        var textEl = document.getElementById('ai-summary-text');
        var badge = document.getElementById('ai-engine-badge');
        var timeEl = document.getElementById('ai-generated-time');

        if (btn) btn.disabled = true;
        if (icon) icon.classList.add('ds-spinning');
        if (textEl) textEl.style.opacity = '0.5';

        var params = new URLSearchParams(window.location.search);
        params.set('force', '1');

        fetch('api/disease_ai_summary.php?' + params.toString())
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.summary) {
                    if (textEl) textEl.innerText = data.summary;
                    if (badge && data.engine_label) badge.innerText = '' + data.engine_label;
                    if (timeEl && data.generated_at) timeEl.innerText = data.generated_at;
                }
            })
            .catch(function(err) {
                console.error('AI summary refresh error:', err);
            })
            .finally(function() {
                if (btn) btn.disabled = false;
                if (icon) icon.classList.remove('ds-spinning');
                if (textEl) textEl.style.opacity = '1';
            });
    }
    </script>
<?php mh_layout_end(); ?>
