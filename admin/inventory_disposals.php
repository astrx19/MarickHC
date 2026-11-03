<?php
/**
 * Disposal History (Step 7): every expired / damaged / recalled batch that was disposed of, who did it, how, and when.
 * Records are written by the "Dispose Stock" action on the Supply Masterlist (admin/inventory.php) and cannot be edited here.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';

// Same door as the Supply Masterlist.
if (!mh_has_capability('can_manage_supply_masterlist')) {
    if (mh_has_any_capability(['can_manage_dispensing', 'can_view_dispensing_history'])) {
        header('Location: dispense_medicine.php');
        exit();
    }
    mh_deny_access();
}
require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('inventory');
require_once __DIR__ . '/../includes/disposal_helper.php';

$system_name = $system_name ?? mh_setting('system_name');
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

$f = [
    'from'   => trim((string) ($_GET['from'] ?? '')),
    'to'     => trim((string) ($_GET['to'] ?? '')),
    'reason' => trim((string) ($_GET['reason'] ?? '')),
    'form'   => trim((string) ($_GET['form'] ?? '')),
    'q'      => trim((string) ($_GET['q'] ?? '')),
];
$rows = mh_disp_history($conn, $f);

// Totals for what is on screen (one pass, no extra queries).
$by_form = ['liquid' => 0, 'capsule' => 0, 'piece' => 0]; $by_reason = [];
foreach ($rows as $r) {
    $by_form[$r['item_form']] = ($by_form[$r['item_form']] ?? 0) + (int) $r['quantity_disposed'];
    $by_reason[$r['reason']]  = ($by_reason[$r['reason']] ?? 0) + 1;
}
$reasons = mh_disp_reasons(); $forms = mh_disp_forms();
$form_short = ['liquid' => 'Liquid', 'capsule' => 'Capsule / Tablet', 'piece' => 'Item / Piece'];
$filtered = ($f['from'] !== '' || $f['to'] !== '' || $f['reason'] !== '' || $f['form'] !== '' || $f['q'] !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disposal History - <?php echo $h($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .dh-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .dh-card { background: var(--bg-card, #fff); border: 1px solid var(--border-color, #e2e8f0); border-top: 4px solid var(--primary, #4ca1af); border-radius: 12px; padding: 14px 16px; }
        .dh-card .n { font-size: 1.7rem; font-weight: 800; color: var(--heading-color, #1e293b); }
        .dh-card .l { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted, #64748b); }
        .dh-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; padding: 14px 20px; border-bottom: 1px solid var(--border-color, #e2e8f0); }
        .dh-filters label { display: block; font-size: .74rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted, #64748b); margin-bottom: 4px; }
        .dh-sub { display: block; font-size: .78rem; color: var(--text-muted, #64748b); }
        @media print {
            .sidebar, .topbar, .mh-tabs, .dh-filters, .no-print, #mhToastStack { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            body { background: #fff !important; }
        }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">

            <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:22px;">
                <div>
                    <div class="mh-tabs" style="margin-bottom:10px;"><a href="inventory.php">Stock</a><a class="active" href="inventory_disposals.php">Disposal History</a></div>
                    <h1 style="margin:0 0 5px; color: var(--heading-color, #1e293b); font-family: Georgia, serif; font-size: 1.8rem;">Disposal History</h1>
                    <p style="margin:0; color: var(--text-muted, #64748b); font-size:.95rem;">Every disposal of expired, damaged or recalled medicine and supplies. Records are permanent and cannot be edited.</p>
                </div>
                <button type="button" class="btn-secondary no-print" onclick="window.print()"><?php echo mh_icon('file-text'); ?> Print</button>
            </div>

            <div class="dh-cards">
                <div class="dh-card"><div class="n"><?php echo count($rows); ?></div><div class="l">Disposal records<?php echo $filtered ? ' (filtered)' : ''; ?></div></div>
                <div class="dh-card" style="border-top-color:#3b82f6;"><div class="n"><?php echo number_format($by_form['liquid']); ?></div><div class="l">Liquid disposed (base units)</div></div>
                <div class="dh-card" style="border-top-color:#a855f7;"><div class="n"><?php echo number_format($by_form['capsule']); ?></div><div class="l">Capsules / tablets disposed</div></div>
                <div class="dh-card" style="border-top-color:#f59e0b;"><div class="n"><?php echo number_format($by_form['piece']); ?></div><div class="l">Items / pieces disposed</div></div>
            </div>

            <div class="unified-panel">
                <form method="GET" class="dh-filters">
                    <div><label for="dhFrom">From</label><input type="date" id="dhFrom" name="from" value="<?php echo $h($f['from']); ?>"></div>
                    <div><label for="dhTo">To</label><input type="date" id="dhTo" name="to" value="<?php echo $h($f['to']); ?>"></div>
                    <div><label for="dhReason">Reason</label>
                        <select id="dhReason" name="reason"><option value="">All reasons</option>
                            <?php foreach ($reasons as $k => $lbl): ?><option value="<?php echo $h($k); ?>"<?php echo $f['reason'] === $k ? ' selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?></select></div>
                    <div><label for="dhForm">Type</label>
                        <select id="dhForm" name="form"><option value="">All types</option>
                            <?php foreach ($form_short as $k => $lbl): ?><option value="<?php echo $h($k); ?>"<?php echo $f['form'] === $k ? ' selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?></select></div>
                    <div style="flex:1; min-width:180px;"><label for="dhQ">Search</label>
                        <input type="text" id="dhQ" name="q" value="<?php echo $h($f['q']); ?>" placeholder="Item, brand, SKU or lot..." style="width:100%;"></div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn-submit btn-sm">Apply</button>
                        <?php if ($filtered): ?><a class="btn-secondary btn-sm" href="inventory_disposals.php" style="text-decoration:none; display:inline-flex; align-items:center;">Clear</a><?php endif; ?>
                    </div>
                </form>

                <div class="table-scroll">
                <table class="unified-table">
                    <thead><tr><th>Date</th><th>Item</th><th>Batch / SKU</th><th>Type</th><th>Disposed</th><th>Reason</th><th>Method</th><th>By</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" style="text-align:center; padding:36px; color: var(--text-muted, #64748b);">
                            <?php echo $filtered ? 'No disposal records match these filters.' : 'Nothing has been disposed of yet. Expired batches show a red &ldquo;Dispose&rdquo; button on the Supply Masterlist.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r):
                        $by = trim(($r['s_first'] ?? '') . ' ' . ($r['s_last'] ?? ''));
                        $reason_class = ['expired' => 'mh-badge-danger', 'damaged' => 'mh-badge-warning', 'recalled' => 'mh-badge-warning', 'other' => ''][$r['reason']] ?? '';
                    ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo $h(date('M d, Y', strtotime($r['disposed_on']))); ?></td>
                            <td><strong><?php echo $h($r['item_name']); ?></strong><?php if (!empty($r['brand_name'])): ?><span class="dh-sub"><?php echo $h($r['brand_name']); ?></span><?php endif; ?>
                                <?php if (!empty($r['remarks'])): ?><span class="dh-sub" title="<?php echo $h($r['remarks']); ?>"><?php echo $h(mb_strimwidth($r['remarks'], 0, 70, '...')); ?></span><?php endif; ?></td>
                            <td><?php echo $h($r['batch_number'] ?: '—'); ?><span class="dh-sub"><?php echo $h($r['tracking_code'] ?: ''); ?><?php echo !empty($r['expiry_date']) ? ' &middot; exp ' . $h(date('M d, Y', strtotime($r['expiry_date']))) : ''; ?></span></td>
                            <td><?php echo $h($form_short[$r['item_form']] ?? $r['item_form']); ?></td>
                            <td style="white-space:nowrap;"><strong><?php echo number_format((int) $r['quantity_disposed']); ?></strong> <?php echo $h($r['unit']); ?>
                                <span class="dh-sub">of <?php echo number_format((int) $r['quantity_before']); ?> &middot; <?php echo (int) $r['quantity_after'] > 0 ? number_format((int) $r['quantity_after']) . ' left' : 'batch emptied'; ?></span></td>
                            <td><span class="mh-badge <?php echo $reason_class; ?>"><?php echo $h($reasons[$r['reason']] ?? $r['reason']); ?></span></td>
                            <td><?php echo $h($r['method']); ?><?php if (!empty($r['witness'])): ?><span class="dh-sub">Witness: <?php echo $h($r['witness']); ?></span><?php endif; ?></td>
                            <td><?php echo $by !== '' ? $h($by) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (count($rows) >= 500): ?><p class="no-print" style="padding:10px 20px; margin:0; color: var(--text-muted, #64748b); font-size:.85rem;">Showing the newest 500 records. Narrow the dates to see older ones.</p><?php endif; ?>
            </div>

        </div>
    </div>
</body>
</html>
