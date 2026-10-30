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

/* ---------------- data ---------------- */
$stats  = mh_disease_compute($conn);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disease Statistics - Marick Health Center</title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .ds-msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
        .ds-ok { background: #dcfce7; color: #166534; } .ds-err { background: #fee2e2; color: #991b1b; }
        .ds-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .ds-stat { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 16px 18px; }
        .ds-stat b { display: block; font-size: 1.9rem; line-height: 1.1; color: var(--heading-color, #1e293b); }
        .ds-stat span { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #64748b); }
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
        @media print { .no-print, .sidebar, .topbar { display: none !important; } }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>
    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>
    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>
        <div class="dashboard-container">

            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                <div>
                    <h1 style="margin:0 0 4px; font-family: Georgia, serif; color:var(--heading-color, #1e293b);">Disease Statistics</h1>
                    <p style="margin:0; color:var(--text-muted, #64748b);">Community illness trends from consultation diagnoses. Counts only &mdash; no patient names are shown. As of <?php echo $h(date('F j, Y', strtotime($stats['today']))); ?>.</p>
                </div>
                <button type="button" class="btn-primary no-print" onclick="window.print()">Print</button>
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

            <div class="ds-stats">
                <div class="ds-stat"><b><?php echo (int) $stats['cases_30d']; ?></b><span>Consultations, last 30 days</span></div>
                <div class="ds-stat"><b style="color:#991b1b;"><?php echo (int) $stats['spikes']; ?></b><span>Active spikes</span></div>
                <div class="ds-stat"><b style="color:#92400e;"><?php echo (int) $stats['rising']; ?></b><span>Rising</span></div>
                <div class="ds-stat"><b><?php echo count($conds); ?></b><span>Conditions with cases</span></div>
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
                <h2 style="margin:0 0 4px; font-size:1.2rem;">Other diagnoses (last 30 days)</h2>
                <p style="margin:0 0 12px; color:var(--text-muted, #64748b);">Not matched by any tracked condition. Anything that keeps appearing here may deserve tracking.</p>
                <table class="unified-table">
                    <thead><tr><th>Diagnosis / complaint</th><th>Cases</th><?php if ($is_head): ?><th class="no-print"></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($stats['unclassified'] as $txt => $n): ?>
                        <tr><td><?php echo $h($txt); ?></td><td><?php echo (int) $n; ?></td>
                        <?php if ($is_head): ?>
                            <td class="no-print" style="text-align:right;"><a class="btn-primary ds-small" style="text-decoration:none; display:inline-block;" href="system_settings.php?tab=disease&amp;new=<?php echo urlencode(mb_convert_case(mb_substr($txt, 0, 100), MB_CASE_TITLE, 'UTF-8')); ?>">Track this</a></td>
                        <?php endif; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>
