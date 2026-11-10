<?php
/* ------------------------------------------------------------------
   DROP-IN REPLACEMENT for the <div class="dashboard-container"> ... </div>
   block in admin/disease_statistics.php (between mh_layout_open() and
   mh_layout_close()). Uses only variables your page already defines:
   $h, $stats, $ov, $conds, $ai_summary, $dominantCondition,
   $dominantCount, $outbreakBadge, $status_style, $is_head, $detail,
   $hidden_count, $msg_ok, $msg_err.

   1. Add to <head> (or the mh_layout_head call):
        <link rel="stylesheet" href="../assets/css/disease_stats_ui.css">
   2. Keep your existing <style> block, the quick-track modal and the
      <script> at the bottom. They don't conflict (new classes are dsx-*).
   ------------------------------------------------------------------ */

$dsx_state = $stats['spikes'] > 0 ? 'spike' : ($stats['rising'] > 0 ? 'rising' : 'normal');

// Horizontal bar list (replaces the old $bars closure)
$dsx_bars = function ($title, array $data) use ($h) {
    $tot = max(1, array_sum($data));
    echo '<div><h3 class="dsx-h3">' . $h($title) . '</h3>';
    if (!array_sum($data)) { echo '<p class="dsx-empty">No data for this period.</p>'; }
    foreach ($data as $label => $n) {
        if ($n <= 0) { continue; }
        echo '<div class="dsx-hbar"><div class="dsx-hbar-top"><span title="' . $h($label) . '">' . $h($label) . '</span><b>' . (int) $n . '</b></div>'
           . '<div class="dsx-hbar-track"><i style="width:' . round($n / $tot * 100) . '%"></i></div></div>';
    }
    echo '</div>';
};

// Smooth area chart (inline SVG, no JS library needed)
$dsx_area = function (array $series) use ($h) {
    $labels = array_keys($series); $vals = array_values($series); $n = count($vals);
    if ($n === 0) { return '<p class="dsx-empty">No data for this period.</p>'; }
    $W = 800; $H = 330; $pl = 34; $pr = 16; $pt = 28; $pb = 36;
    $max = max(1, max($vals)); $step = $n > 1 ? ($W - $pl - $pr) / ($n - 1) : 0; $base = $H - $pb; $pts = [];
    foreach ($vals as $i => $v) { $pts[] = [$n > 1 ? $pl + $i * $step : $W / 2, $pt + (1 - $v / $max) * ($H - $pt - $pb)]; }
    $d = 'M' . round($pts[0][0], 1) . ',' . round($pts[0][1], 1);
    for ($i = 1; $i < $n; $i++) {
        $mx = round(($pts[$i - 1][0] + $pts[$i][0]) / 2, 1);
        $d .= ' C' . $mx . ',' . round($pts[$i - 1][1], 1) . ' ' . $mx . ',' . round($pts[$i][1], 1) . ' ' . round($pts[$i][0], 1) . ',' . round($pts[$i][1], 1);
    }
    $area = $d . ' L' . round($pts[$n - 1][0], 1) . ',' . $base . ' L' . round($pts[0][0], 1) . ',' . $base . ' Z';
    $o = '<svg class="dsx-area" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Cases per week"><defs><linearGradient id="dsxg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4ca1af" stop-opacity=".30"/><stop offset="1" stop-color="#4ca1af" stop-opacity="0"/></linearGradient></defs>';
    for ($g = 0; $g <= 3; $g++) {
        $y = round($pt + $g * (($H - $pt - $pb) / 3), 1);
        $o .= '<line class="dsx-grid" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $y . '" y2="' . $y . '"/><text class="dsx-axis" x="' . ($pl - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . round($max * (1 - $g / 3)) . '</text>';
    }
    $o .= '<path d="' . $area . '" fill="url(#dsxg)"/><path class="dsx-line" d="' . $d . '"/>';
    $skip = $n > 8 ? 2 : 1;
    foreach ($pts as $i => $p) {
        $last = ($i === $n - 1); $lbl = date('M j', strtotime($labels[$i]));
        $o .= '<circle class="dsx-dot' . ($last ? ' is-last' : '') . '" cx="' . round($p[0], 1) . '" cy="' . round($p[1], 1) . '" r="' . ($last ? 6 : 4) . '"><title>Week of ' . $h($lbl) . ': ' . (int) $vals[$i] . ' case(s)</title></circle>';
        if ($i % $skip === 0 || $last) { $o .= '<text class="dsx-axis" x="' . round($p[0], 1) . '" y="' . ($H - 10) . '" text-anchor="middle">' . $h($lbl) . '</text>'; }
        if ($last) { $o .= '<text class="dsx-tag" x="' . round($p[0], 1) . '" y="' . round($p[1] - 14, 1) . '" text-anchor="middle">' . (int) $vals[$i] . '</text>'; }
    }
    return $o . '</svg>';
};

// Donut chart + legend (top 5 + "Others")
$dsx_donut = function (array $data) use ($h) {
    $data = array_filter($data, function ($n) { return $n > 0; }); arsort($data);
    $tot = array_sum($data);
    if (!$tot) { return '<p class="dsx-empty">No data for this period.</p>'; }
    $top = array_slice($data, 0, 5, true); $rest = $tot - array_sum($top); if ($rest > 0) { $top['Others'] = $rest; }
    $colors = ['#4ca1af', '#6366f1', '#f59e0b', '#f43f5e', '#10b981', '#cbd5e1'];
    $R = 54; $C = 2 * M_PI * $R; $acc = 0; $i = 0; $leg = '';
    $o = '<div class="dsx-donut"><svg viewBox="0 0 140 140" role="img" aria-label="Top conditions"><circle cx="70" cy="70" r="' . $R . '" fill="none" stroke="#eef3f6" stroke-width="16"/>';
    foreach ($top as $label => $n) {
        $len = $n / $tot * $C; $c = $colors[$i % count($colors)];
        $o .= '<circle cx="70" cy="70" r="' . $R . '" fill="none" stroke="' . $c . '" stroke-width="16" stroke-dasharray="' . round(max(0, $len - 2), 2) . ' ' . round($C - max(0, $len - 2), 2) . '" stroke-dashoffset="' . round(-$acc, 2) . '" transform="rotate(-90 70 70)"/>';
        $leg .= '<li><span class="dsx-sw" style="background:' . $c . '"></span><span class="dsx-nm" title="' . $h($label) . '">' . $h($label) . '</span><b>' . (int) $n . '</b><span class="dsx-pc">' . round($n / $tot * 100) . '%</span></li>';
        $acc += $len; $i++;
    }
    return $o . '<text class="dsx-donut-num" x="70" y="72" text-anchor="middle">' . (int) $tot . '</text><text class="dsx-donut-lbl" x="70" y="88" text-anchor="middle">cases</text></svg><ul class="dsx-leg">' . $leg . '</ul></div>';
};
?>
<div class="dsx">

    <!-- ============ Header ============ -->
    <header class="dsx-head">
        <div>
            <h1 class="dsx-title">Disease Statistics</h1>
            <p class="dsx-sub">Community illness trends from consultation, dental and child check-up diagnoses. Counts only, no patient names. As of <?php echo $h(date('F j, Y', strtotime($stats['today']))); ?>.</p>
        </div>
        <button type="button" class="dsx-btn dsx-btn--soft no-print" onclick="window.print()">Print report</button>
    </header>

    <?php if ($msg_ok !== ''): ?><div class="dsx-flash dsx-flash--ok"><?php echo $h($msg_ok); ?></div><?php endif; ?>
    <?php if ($msg_err !== ''): ?><div class="dsx-flash dsx-flash--err"><?php echo $h($msg_err); ?></div><?php endif; ?>

    <!-- ============ AI insight (collapsed by default) ============ -->
    <section class="dsx-ai" aria-label="AI health insights">
        <div class="dsx-ai-row">
            <span class="dsx-ai-icon"><?php echo mh_icon('activity', 'dsx-i'); ?></span>
            <div class="dsx-ai-main">
                <div class="dsx-ai-top">
                    <span class="dsx-ai-title">AI health insights</span>
                    <span class="dsx-ai-engine" id="ai-engine-badge"><?php echo $h($ai_summary['engine_label']); ?></span>
                    <span class="dsx-pill dsx-pill--<?php echo $dsx_state; ?>"><?php echo $h($outbreakBadge[0]); ?></span>
                </div>
                <p class="dsx-ai-text" id="ai-summary-text"><?php echo $h($ai_summary['summary']); ?></p>
            </div>
            <div class="dsx-ai-actions no-print">
                <button type="button" class="dsx-btn dsx-btn--ghost dsx-btn--sm dsx-ai-toggle" aria-expanded="false"
                        onclick="var c=this.closest('.dsx-ai'),o=c.classList.toggle('is-open');this.setAttribute('aria-expanded',o);this.firstElementChild.textContent=o?'Show less':'Read more';">
                    <span>Read more</span><?php echo mh_icon('chevron-down', 'dsx-i'); ?>
                </button>
                <button type="button" class="dsx-btn dsx-btn--ghost dsx-btn--sm" id="btn-refresh-ai" onclick="refreshAiSummary()" title="Refresh analysis" aria-label="Refresh analysis">
                    <span class="dsx-spin" id="ai-spin-icon"><?php echo mh_icon('refresh-cw', 'dsx-i'); ?></span>
                </button>
            </div>
        </div>
        <dl class="dsx-ai-meta">
            <div><dt>Dominant cluster</dt><dd><?php echo $h(key($ov['by_cond']) ?: 'General Consultation'); ?></dd></div>
            <div><dt>Outbreak status</dt><dd><?php echo $h($outbreakBadge[0]); ?></dd></div>
            <div><dt>Area centre</dt><dd><?php echo $h(key($ov['by_area']) ?: 'Marick Area'); ?></dd></div>
            <div><dt>Updated</dt><dd id="ai-generated-time"><?php echo $h($ai_summary['generated_at']); ?></dd></div>
        </dl>
    </section>

    <?php if ($stats['spikes'] > 0): ?>
    <div class="dsx-notice" role="alert">
        <?php echo mh_icon('alert-triangle', 'dsx-i'); ?>
        <p><strong>Spike alert:</strong>
        <?php
        $names = [];
        foreach ($conds as $c) { if ($c['status'] === 'spike') { $names[] = $h($c['name']) . ' (' . (int) $c['cur'] . ' this week)'; } }
        echo implode(', ', $names);
        ?>. Cases are well above the recent average. Consider notifying the health officer.</p>
    </div>
    <?php endif; ?>

    <!-- ============ KPI cards ============ -->
    <section class="dsx-kpis" aria-label="Key figures">
        <article class="dsx-kpi">
            <span class="dsx-kpi-chip"><?php echo mh_icon('users', 'dsx-i'); ?></span>
            <div>
                <div class="dsx-kpi-label">Total cases (30 days)</div>
                <div class="dsx-kpi-value"><?php echo (int) $stats['cases_30d']; ?></div>
                <div class="dsx-kpi-note"><?php echo (int) $ov['patients']; ?> distinct patients</div>
            </div>
        </article>

        <article class="dsx-kpi dsx-kpi--spikes <?php echo $stats['spikes'] > 0 ? '' : 'is-calm'; ?>">
            <span class="dsx-kpi-chip"><?php echo mh_icon('alert-triangle', 'dsx-i'); ?></span>
            <div>
                <div class="dsx-kpi-label">Active spikes</div>
                <div class="dsx-kpi-value"><?php echo (int) $stats['spikes']; ?></div>
                <div class="dsx-kpi-note"><?php echo (int) $stats['rising']; ?> condition<?php echo $stats['rising'] === 1 ? '' : 's'; ?> rising</div>
            </div>
        </article>

        <article class="dsx-kpi dsx-kpi--dom">
            <span class="dsx-kpi-chip"><?php echo mh_icon('heart', 'dsx-i'); ?></span>
            <div style="min-width:0;">
                <div class="dsx-kpi-label">Dominant condition</div>
                <div class="dsx-kpi-value dsx-kpi-value--text" title="<?php echo $h($dominantCondition); ?>"><?php echo $h($dominantCondition); ?></div>
                <div class="dsx-kpi-note"><?php echo (int) $dominantCount; ?> recent cases</div>
            </div>
        </article>

        <article class="dsx-kpi dsx-kpi--status is-<?php echo $dsx_state; ?>">
            <span class="dsx-kpi-chip"><?php echo mh_icon('shield', 'dsx-i'); ?></span>
            <div>
                <div class="dsx-kpi-label">Outbreak status</div>
                <div class="dsx-kpi-value dsx-kpi-value--text"><?php echo $h($outbreakBadge[0]); ?></div>
                <div class="dsx-kpi-note"><?php echo $h($outbreakBadge[3]); ?></div>
            </div>
        </article>
    </section>

    <!-- ============ Period filter ============ -->
    <div class="dsx-filter no-print">
        <nav class="dsx-seg" aria-label="Quick ranges">
            <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'] as $n => $lbl):
                $active = ((int) $ov['days'] === $n && $ov['to'] === date('Y-m-d')); ?>
                <a href="?from=<?php echo date('Y-m-d', strtotime('-' . ($n - 1) . ' days')); ?>&to=<?php echo date('Y-m-d'); ?>"
                   class="<?php echo $active ? 'is-active' : ''; ?>"<?php echo $active ? ' aria-current="true"' : ''; ?>><?php echo $lbl; ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="GET" class="dsx-range">
            <input type="date" name="from" aria-label="From" value="<?php echo $h($ov['from']); ?>" max="<?php echo date('Y-m-d'); ?>">
            <span class="dsx-range-sep">to</span>
            <input type="date" name="to" aria-label="To" value="<?php echo $h($ov['to']); ?>" max="<?php echo date('Y-m-d'); ?>">
            <button type="submit" class="dsx-btn dsx-btn--solid dsx-btn--sm">Apply</button>
        </form>
    </div>

    <!-- ============ Charts ============ -->
    <div class="dsx-grid-2">
        <section class="dsx-card">
            <div class="dsx-card-head">
                <div>
                    <h2 class="dsx-h2">Cases per week</h2>
                    <p class="dsx-hint"><?php echo $h(date('M j', strtotime($ov['from']))); ?> to <?php echo $h(date('M j, Y', strtotime($ov['to']))); ?>. The latest week is still in progress.</p>
                </div>
            </div>
            <?php echo $dsx_area($ov['by_week']); ?>
        </section>
        <section class="dsx-card">
            <div class="dsx-card-head"><h2 class="dsx-h2">Top conditions</h2></div>
            <?php echo $dsx_donut($ov['by_cond']); ?>
        </section>
    </div>

    <details class="dsx-drawer dsx-drawer--solo no-print">
        <summary>Breakdown by department, sex, age and area <?php echo mh_icon('chevron-down', 'dsx-i'); ?></summary>
        <div class="dsx-drawer-body">
            <?php $dsx_bars('By department', $ov['by_source']); $dsx_bars('By sex', $ov['by_sex']); $dsx_bars('By age group', $ov['by_age']); $dsx_bars('By area', $ov['by_area']); ?>
        </div>
    </details>

    <!-- ============ Tracked conditions table ============ -->
    <section class="dsx-card">
        <div class="dsx-card-head">
            <div>
                <h2 class="dsx-h2">Tracked conditions</h2>
                <p class="dsx-hint">Select a condition to see who was affected.</p>
            </div>
        </div>
        <div class="dsx-table-wrap">
            <table class="dsx-table">
                <thead><tr>
                    <th>Condition</th><th class="dsx-num">This week</th><th class="dsx-num dsx-hide-sm">Last week</th>
                    <th class="dsx-num dsx-hide-sm">4-week avg</th><th class="dsx-hide-sm">12-week trend</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php if (!$conds): ?>
                    <tr><td colspan="6" class="dsx-table-empty">No tracked illness has been logged in the last 12 weeks. Conditions appear here automatically once matching diagnoses are saved.</td></tr>
                <?php endif; ?>
                <?php foreach ($conds as $c): [$lbl] = $status_style[$c['status']]; ?>
                    <tr>
                        <td><a class="dsx-link" href="disease_statistics.php?cond=<?php echo (int) $c['id']; ?>#detail"><?php echo $h($c['name']); ?></a></td>
                        <td class="dsx-num dsx-strong"><?php echo (int) $c['cur']; ?></td>
                        <td class="dsx-num dsx-dim dsx-hide-sm"><?php echo (int) $c['last']; ?></td>
                        <td class="dsx-num dsx-dim dsx-hide-sm"><?php echo $h($c['prev_avg']); ?></td>
                        <td class="dsx-spark dsx-hide-sm"><?php echo mh_disease_sparkline($c['weekly'], $c['status'] === 'spike' ? '#dc2626' : ($c['status'] === 'rising' ? '#ea8a1b' : '#4ca1af')); ?></td>
                        <td><span class="dsx-pill dsx-pill--<?php echo $h($c['status']); ?>"><?php echo $h($lbl); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <details class="dsx-note">
            <summary>How statuses are calculated</summary>
            <p><b>Spike</b>: at least the minimum number of cases this week and at least the set multiple of the previous 4-week average. <b>Rising</b>: 2 or more cases and 25% above that average.
            <?php if ($hidden_count > 0): ?><?php echo (int) $hidden_count; ?> tracked condition<?php echo $hidden_count === 1 ? ' has' : 's have'; ?> no logged cases and <?php echo $hidden_count === 1 ? 'is' : 'are'; ?> hidden.
            <?php if ($is_head): ?><a class="dsx-link" href="system_settings.php?tab=disease">Manage tracked conditions</a><?php endif; ?><?php endif; ?></p>
        </details>
    </section>

    <?php if ($detail): ?>
        <!-- Keep your existing <?php /* if ($detail) */ ?> breakdown block here (id="detail"). It still works with the old ds-* styles. -->
    <?php endif; ?>

    <!-- ============ Other diagnoses table ============ -->
    <?php if ($stats['unclassified']): ?>
    <section class="dsx-card">
        <div class="dsx-card-head">
            <div>
                <h2 class="dsx-h2">Other diagnoses &amp; complaints</h2>
                <p class="dsx-hint">Last 30 days. Long entries are shortened; hover to read the full text.</p>
            </div>
            <?php if ($is_head): ?>
                <a href="system_settings.php?tab=disease" class="dsx-btn dsx-btn--soft dsx-btn--sm no-print">Manage tracked list</a>
            <?php endif; ?>
        </div>
        <div class="dsx-table-wrap">
            <table class="dsx-table">
                <thead><tr>
                    <th>Recorded diagnosis</th><th class="dsx-hide-sm">Standardized category</th><th class="dsx-num">Cases</th>
                    <?php if ($is_head): ?><th class="dsx-num no-print"><span class="sr-only" style="position:absolute;left:-9999px;">Actions</span></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php $dsx_i = 0; foreach ($stats['unclassified'] as $txt => $n):
                    if (!mh_disease_is_valid_diagnosis($txt)) { continue; }
                    $std = mh_disease_standardize_diagnosis($txt, $conn);
                    $suggestedName = $std['name'] ?: mb_convert_case($txt, MB_CASE_TITLE, 'UTF-8');
                    $suggestedKeywords = $std['keywords'] ?: (mb_strtolower($suggestedName) . ', ' . mb_strtolower($txt));
                    $cat = $std['category'] ?: $suggestedName;
                    $same = mb_strtolower(trim($cat)) === mb_strtolower(trim($txt));
                    $dsx_i++;
                ?>
                    <tr<?php echo $dsx_i > 6 ? ' class="dsx-more" hidden' : ''; ?>>
                        <td class="dsx-strong"><span class="dsx-trunc" title="<?php echo $h($txt); ?>"><?php echo $h($txt); ?></span></td>
                        <td class="dsx-hide-sm"><?php echo $same ? '<span class="dsx-dim">&ndash;</span>' : '<span class="dsx-pill dsx-pill--info dsx-trunc" title="' . $h($cat) . '">' . $h($cat) . '</span>'; ?></td>
                        <td class="dsx-num dsx-strong"><?php echo (int) $n; ?></td>
                        <?php if ($is_head): ?>
                        <td class="no-print">
                            <div class="dsx-actions">
                                <button type="button" class="dsx-btn dsx-btn--soft dsx-btn--sm"
                                    onclick="openQuickTrackModal('<?php echo $h(addslashes($suggestedName)); ?>', '<?php echo $h(addslashes($suggestedKeywords)); ?>')">+ Track this</button>
                                <a class="dsx-btn dsx-btn--ghost dsx-btn--sm" title="Customize in System Settings"
                                   href="system_settings.php?tab=disease&amp;new=<?php echo urlencode($suggestedName); ?>&amp;kw=<?php echo urlencode($suggestedKeywords); ?>">Edit</a>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($stats['unclassified']) > 6): ?>
            <div class="dsx-more-row no-print"><button type="button" class="dsx-btn dsx-btn--ghost dsx-btn--sm"
                onclick="this.closest('.dsx-card').querySelectorAll('.dsx-more').forEach(function(r){r.hidden=false});this.parentNode.remove();">Show all <?php echo (int) count($stats['unclassified']); ?></button></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</div>