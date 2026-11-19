<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';

if (!mh_has_capability('can_manage_supply_masterlist')) {
    if (mh_has_any_capability(['can_manage_dispensing', 'can_view_dispensing_history'])) {
        header('Location: dispense_medicine.php');
        exit();
    }
    mh_deny_access();
}
$can_edit_stock = mh_has_permission('add_medicine');

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('inventory');

$system_name = $system_name ?? mh_setting('system_name');

$inv_categories = mh_inventory_categories($conn);   
require_once __DIR__ . '/../includes/disposal_helper.php';
mh_disp_ensure_table($conn);

$success_msg = ""; $error_msg = "";

if ((isset($_POST['add_item']) || isset($_POST['edit_item']) || isset($_POST['update_stock'])) && !$can_edit_stock) {
    mh_deny_access();
}

function mh_inv_read_item_fields(): array
{
    $t = function ($k) { return trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))); };
    $nullable_int = function ($k) {
        if (!isset($_POST[$k]) || $_POST[$k] === '') { return null; }
        $n = (int) $_POST[$k];
        return $n >= 1 ? min($n, 10000) : null;
    };
    return [
        'item_name'      => $t('item_name'),
        'brand_name'     => $t('brand_name'),
        'category'       => $t('category'),
        'tracking_code'  => $t('tracking_code'),
        'batch_no'       => $t('batch_number'),
        'unit'           => $t('unit'),
        'expiry'         => !empty($_POST['expiry_date']) ? (string) $_POST['expiry_date'] : null,
        'units_per_pack' => $nullable_int('units_per_pack'),
        'packs_per_box'  => $nullable_int('packs_per_box'),
        'pack_label'     => $t('pack_label') !== '' ? $t('pack_label') : 'Blister Pack',
        'box_label'      => $t('box_label')  !== '' ? $t('box_label')  : 'Box',
    ];
}

if(isset($_POST['add_item'])) {
    $f = mh_inv_read_item_fields();
    $quantity = max(0, min(1000000, (int) ($_POST['quantity'] ?? 0)));

    $st = $conn->prepare("INSERT INTO inventory (item_name, brand_name, category, tracking_code, batch_number, quantity, unit,
                                                 units_per_pack, pack_label, packs_per_box, box_label, expiry_date, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $st->bind_param('sssssisisiss', $f['item_name'], $f['brand_name'], $f['category'], $f['tracking_code'], $f['batch_no'],
                    $quantity, $f['unit'], $f['units_per_pack'], $f['pack_label'], $f['packs_per_box'], $f['box_label'], $f['expiry']);
    $ok = $st->execute();
    if ($ok) {
        $success_msg = "New item added to inventory successfully!";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Added Inventory", "Added $quantity {$f['unit']} of {$f['item_name']} (Code: {$f['tracking_code']}).");
    } else {
        error_log('inventory add_item: ' . $st->error);
        $error_msg = "Could not add the item. Please check the details and try again.";
    }
    $st->close();
}

if(isset($_POST['edit_item'])) {
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $f = mh_inv_read_item_fields();

    $st = $conn->prepare("UPDATE inventory SET item_name = ?, brand_name = ?, category = ?, tracking_code = ?, batch_number = ?,
                                 unit = ?, units_per_pack = ?, pack_label = ?, packs_per_box = ?, box_label = ?, expiry_date = ?
                          WHERE id = ?");
    $st->bind_param('ssssssisissi', $f['item_name'], $f['brand_name'], $f['category'], $f['tracking_code'], $f['batch_no'],
                    $f['unit'], $f['units_per_pack'], $f['pack_label'], $f['packs_per_box'], $f['box_label'], $f['expiry'], $item_id);
    $ok = $st->execute();
    if ($ok) {
        $success_msg = "Batch details updated successfully!";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Edited Inventory Batch", "Edited batch #$item_id ({$f['item_name']}).");
    } else {
        error_log('inventory edit_item: ' . $st->error);
        $error_msg = "Could not update the batch. Please try again.";
    }
    $st->close();
}

if(isset($_POST['update_stock'])) {
    $item_id   = (int) ($_POST['item_id'] ?? 0);
    $action    = (string) ($_POST['stock_action'] ?? '');
    $item_name = (string) ($_POST['item_name_hidden'] ?? '');

    if($action === 'archive') {
        $st = $conn->prepare("UPDATE inventory SET status = 0 WHERE id = ?");
        $st->bind_param('i', $item_id);
        $ok = $st->execute(); $st->close();
        if($ok) {
            $success_msg = "Item successfully moved to Archives!";
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Archived Inventory", "Archived inventory item: $item_name.");
        } else {
            $error_msg = "Could not archive the item. Please try again.";
        }

    } elseif($action === 'edit_expiry') {
        $new_expiry = !empty($_POST['new_expiry_date']) ? (string) $_POST['new_expiry_date'] : null;
        $st = $conn->prepare("UPDATE inventory SET expiry_date = ? WHERE id = ?");
        $st->bind_param('si', $new_expiry, $item_id);
        $ok = $st->execute(); $st->close();
        if($ok) {
            $success_msg = "Expiry date updated successfully!";
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Updated Stock", "Changed expiry date for $item_name.");
        } else {
            $error_msg = "Could not update the expiry date. Please try again.";
        }

    } elseif($action === 'dispose') {
     
        if (!mh_csrf_check()) {
            $error_msg = "Your session security token is missing or expired. Please reload the page and try again.";
        } else {
            list($ok, $err, $remaining) = mh_disp_dispose($conn, $item_id, [
                'amount' => $_POST['amount'] ?? 0, 'item_form' => $_POST['item_form'] ?? '', 'reason' => $_POST['reason'] ?? '',
                'method' => $_POST['method'] ?? '', 'disposed_on' => $_POST['disposed_on'] ?? '',
                'witness' => $_POST['witness'] ?? '', 'remarks' => $_POST['remarks'] ?? '',
            ], (int) $_SESSION['user_id']);
            if ($ok) {
                $amt = (int) ($_POST['amount'] ?? 0);
                $success_msg = "Disposal recorded: " . number_format($amt) . " disposed" . ($remaining <= 0 ? " - the batch is now empty and was archived." : ", " . number_format($remaining) . " left in the batch.");
                logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Disposed Inventory", "Disposed $amt of $item_name (" . (string) ($_POST['reason'] ?? '') . ").");
            } else {
                $error_msg = $err;
            }
        }
    } else {
        $error_msg = "Restocking is done by adding a new batch with its own lot number and expiry date.";
    }
}

$inventory = $conn->query("
    SELECT * FROM inventory 
    WHERE status = 1
    ORDER BY 
        CASE 
            WHEN quantity <= 0 THEN 1 
            WHEN expiry_date < CURDATE() AND expiry_date IS NOT NULL THEN 2 
            WHEN quantity <= 20 THEN 3 
            ELSE 4 
        END ASC, 
        expiry_date ASC, 
        item_name ASC
");

$total_items = 0;
$low_stock_count = 0;
$expired_count = 0;
$near_expiry_count = 0;
$today = date('Y-m-d');
$today_ts = strtotime($today);
$NEAR_EXPIRY_DAYS = 90;   
$LOW_STOCK_THRESHOLD = 20;

$items_array = [];
if($inventory && $inventory->num_rows > 0) {
    while($row = $inventory->fetch_assoc()) {
        $total_items++;

        $days_to_expiry = null;
        if(!empty($row['expiry_date'])) {
            $days_to_expiry = (int) round((strtotime($row['expiry_date']) - $today_ts) / 86400);
        }

        $status = "In Stock";
        $status_class = "status-instock";

        if($row['quantity'] <= 0) {
            $status = "Out of Stock";
            $status_class = "status-outofstock";
        } elseif($row['quantity'] <= $LOW_STOCK_THRESHOLD) {
            $status = "Low Stock";
            $status_class = "status-lowstock";
            $low_stock_count++;
        }

        $is_expired = false;
        if(!empty($row['expiry_date']) && $row['expiry_date'] < $today) {
            $status = "Expired";
            $status_class = "status-expired";
            $expired_count++;
            $is_expired = true;
        } elseif($days_to_expiry !== null && $days_to_expiry >= 0 && $days_to_expiry <= $NEAR_EXPIRY_DAYS && $row['quantity'] > 0) {
            $near_expiry_count++;
        }

        $row['calc_status'] = $status;
        $row['status_class'] = $status_class;
        $row['days_to_expiry'] = $days_to_expiry;
        $row['is_expired'] = $is_expired;
        $items_array[] = $row;
    }
}

$js_inventory = json_encode(array_map(function($r) {
    return [
        'id'             => (int) $r['id'],
        'item_name'      => $r['item_name'],
        'brand_name'     => $r['brand_name'],
        'category'       => $r['category'],
        'tracking_code'  => $r['tracking_code'],
        'batch_number'   => $r['batch_number'],
        'quantity'       => (int) $r['quantity'],
        'unit'           => $r['unit'],
        'units_per_pack' => isset($r['units_per_pack']) && $r['units_per_pack'] !== null ? (int) $r['units_per_pack'] : null,
        'pack_label'     => $r['pack_label'] ?? 'Blister Pack',
        'packs_per_box'  => isset($r['packs_per_box']) && $r['packs_per_box'] !== null ? (int) $r['packs_per_box'] : null,
        'box_label'      => $r['box_label'] ?? 'Box',
        'expiry_date'    => $r['expiry_date'],
    ];
}, $items_array), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$js_categories = json_encode(array_values($inv_categories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<?php
require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'Supply Masterlist', 'print_title' => 'Medicine Inventory Report']);
?>
    <style>
        .suggest-list { position: absolute; left: 0; right: 0; top: 100%; z-index: 50; margin-top: 2px; background: var(--bg-card, #fff); border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18); overflow: hidden; max-height: 290px; overflow-y: auto; }
        .suggest-head { padding: 6px 12px; font-size: 0.7rem; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted, #64748b); background: var(--bg-hover, #f1f5f9); }
        .suggest-item { padding: 8px 12px; cursor: pointer; border-top: 1px solid var(--border-color, #e2e8f0); }
        .suggest-item:first-of-type { border-top: 0; }
        .suggest-item .s-name { font-weight: 600; color: var(--heading-color, #0f172a); font-size: 0.9rem; }
        .suggest-item .s-meta { font-size: 0.74rem; color: var(--text-muted, #64748b); margin-top: 1px; }
        .suggest-item.active, .suggest-item:hover { background: var(--bg-hover, #f1f5f9); }
        .autofix-note { font-size: 0.74rem; margin-top: 4px; color: #047857; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .autofix-note.suggest { color: #b45309; }
        .autofix-note button { border: 1px solid currentColor; background: transparent; color: inherit; border-radius: 6px; padding: 1px 8px; font-size: 0.74rem; cursor: pointer; font-family: inherit; }
        .stats-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        @media (max-width: 1100px) { .stats-grid-4 { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .stats-grid-4 { grid-template-columns: 1fr; } }
        .stat-card.clickable { cursor: pointer; text-align: left; flex-direction: row; align-items: center; gap: 14px; padding: 20px; }
        .stat-card.clickable .stat-icon-badge { margin-bottom: 0; flex-shrink: 0; }
        .stat-card.clickable .stat-body h3 { margin: 0 0 6px 0; }
        .stat-card.clickable .stat-body .number { font-size: 1.9rem; }
        .stat-card.clickable.active-quick { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 76, 161, 175), 0.18); }
        .filter-toolbar { flex-wrap: wrap; }
        .mode-toggle { display: inline-flex; border: 1px solid var(--border-color, #cbd5e1); border-radius: 20px; overflow: hidden; }
        .mode-toggle button { border: none; background: var(--bg-input); color: var(--text-muted); padding: 9px 16px; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: 0.15s; }
        .mode-toggle button.active { background: var(--primary); color: #fff; }
        .filter-chips { display: flex; flex-wrap: wrap; gap: 8px; padding: 0 24px 16px 24px; }
        .filter-chip { display: inline-flex; align-items: center; gap: 6px; background: rgba(var(--primary-rgb, 76, 161, 175), 0.12); color: var(--primary); border: 1px solid rgba(var(--primary-rgb, 76, 161, 175), 0.3); border-radius: var(--radius-pill); padding: 5px 8px 5px 12px; font-size: 0.78rem; font-weight: 700; }
        .filter-chip button { border: none; background: transparent; color: inherit; cursor: pointer; display: flex; align-items: center; padding: 2px; border-radius: 50%; }
        .filter-chip button:hover { background: rgba(0,0,0,0.08); }
        .filter-chip button .mh-icon { width: 12px; height: 12px; }
        .filter-reset-btn { border: 1px dashed var(--border-color, #cbd5e1); background: transparent; color: var(--text-muted); border-radius: var(--radius-pill); padding: 5px 12px; font-size: 0.78rem; font-weight: 700; cursor: pointer; }
        .filter-reset-btn:hover { color: var(--heading-color); border-color: var(--text-muted); }
        .group-row { cursor: pointer; }
        .group-row:hover { background-color: var(--bg-hover); }
        .group-row.expanded { background-color: var(--bg-hover); }
        .group-chevron { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; color: var(--text-muted); transition: transform 0.15s ease, background 0.15s ease; flex-shrink: 0; }
        .group-row.expanded .group-chevron { transform: rotate(90deg); color: var(--primary); }
        .group-chevron .mh-icon { width: 16px; height: 16px; }
        .item-cell { display: flex; align-items: flex-start; gap: 10px; }
        .item-cell-text strong { color: var(--heading-color, #0f172a); font-size: 1.02rem; display: block; }
        .brand-badge { font-size: 0.8rem; color: var(--primary); font-weight: 700; }
        .brand-badge.multi { color: var(--text-muted); font-style: italic; }
        .batch-count-tag { font-size: 0.72rem; color: var(--text-muted); font-weight: 600; background: var(--bg-hover); border: 1px solid var(--border-color, #e2e8f0); border-radius: var(--radius-pill); padding: 1px 8px; margin-left: 6px; }
        .near-expiry-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 700; color: #c2410c; background: #ffedd5; border: 1px solid #fed7aa; border-radius: var(--radius-pill); padding: 2px 9px; margin-top: 4px; }
        html[data-theme="dark"] .near-expiry-pill { background: rgba(194, 65, 12, 0.2); color: #fdba74; border-color: rgba(194, 65, 12, 0.4); }
        .batch-detail-row td { padding: 0 !important; border-bottom: 1px solid var(--border-color, #e2e8f0); background: var(--bg-body); }
        .batch-subtable-wrap { padding: 6px 16px 16px 54px; }
        .batch-subtable { width: 100%; border-collapse: collapse; background: var(--bg-card); border: 1px solid var(--border-color, #e2e8f0); border-radius: var(--radius-md); overflow: hidden; }
        .batch-subtable th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); padding: 10px 14px; background: var(--bg-hover); border-bottom: 1px solid var(--border-color, #e2e8f0); }
        .batch-subtable td { padding: 10px 14px; border-bottom: 1px solid var(--border-subtle); font-size: 0.88rem; color: var(--text-main); vertical-align: middle; }
        .batch-subtable tr:last-child td { border-bottom: none; }
        .action-btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-manage { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.8rem; transition: 0.2s; white-space: nowrap; }
        .btn-manage:hover { background: #bae6fd; }
        html[data-theme="dark"] .btn-manage { background: rgba(3,105,161,0.25); color: #7dd3fc; border-color: rgba(3,105,161,0.5); }
        html[data-theme="dark"] .btn-manage:hover { background: rgba(3,105,161,0.4); }
        .btn-dispense { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.8rem; transition: 0.2s; white-space: nowrap; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-dispense:hover { background: #a7f3d0; }
        .btn-dispense.disabled { opacity: 0.5; pointer-events: none; }
        html[data-theme="dark"] .btn-dispense { background: rgba(16,185,129,0.18); color: #6ee7b7; border-color: rgba(16,185,129,0.4); }
        .btn-add-batch { background: var(--bg-hover); color: var(--text-main); border: 1px solid var(--border-color, #cbd5e1); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.8rem; white-space: nowrap; }
        .btn-add-batch:hover { border-color: var(--primary); color: var(--primary); }
        .empty-state td { padding: 48px 16px; text-align: center; color: var(--text-muted); font-size: 0.95rem; }
        .batch-toggle-container { display: flex; gap: 10px; font-weight: normal; font-size: 0.8rem; }
        .batch-toggle-container label { cursor: pointer; display: flex; align-items: center; gap: 3px; margin: 0; color: #475569; }
        .batch-toggle-container input[type="radio"] { width: auto; height: auto; margin: 0; cursor: pointer; accent-color: #0369a1; }
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        input[type=number] {
            -moz-appearance: textfield;
        }
    </style>
<?php mh_layout_open(); ?>

        <div class="dashboard-container">
            
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 25px;">
                <div>
                    <div class="mh-tabs" style="margin-bottom:10px;"><a class="active" href="inventory.php">Stock</a><a href="inventory_disposals.php">Disposal History</a></div>
                    <h1 style="margin: 0 0 5px 0; color: var(--heading-color, #1e293b); font-family: Georgia, serif; font-size: 1.8rem;">Supply Masterlist</h1>
                    <p style="margin: 0; color: var(--text-muted); font-size: 0.95rem;">Manage active stock levels and register new batches. Batches are grouped by generic name so brand variants and lots stay together.</p>
                </div>
            </div>

            <?php 
            if($success_msg) echo "<div class='alert alert-success' style='background:#d1fae5; color:#047857; padding:15px; border-radius:8px; border:1px solid #a7f3d0; margin-bottom:20px;'>" . mh_icon('check-circle') . " $success_msg</div>";
            if($error_msg) echo "<div class='alert alert-error' style='background:#fee2e2; color:#b91c1c; padding:15px; border-radius:8px; border:1px solid #fecaca; margin-bottom:20px;'>" . mh_icon('x-circle') . " $error_msg</div>";
            ?>

            <div class="stats-grid-4">
                <div class="stat-card clickable" data-quick="reset" style="border-top-color: #3b82f6;">
                    <div class="stat-icon-badge" style="color:#3b82f6; background:rgba(59,130,246,0.12);"><?php echo mh_icon('package'); ?></div>
                    <div class="stat-body">
                        <h3>Total Batches</h3>
                        <div class="number"><?php echo $total_items; ?></div>
                    </div>
                </div>
                <div class="stat-card clickable" data-quick="stock:low" style="border-top-color: #f59e0b;">
                    <div class="stat-icon-badge" style="color:#f59e0b; background:rgba(245,158,11,0.12);"><?php echo mh_icon('alert-triangle'); ?></div>
                    <div class="stat-body">
                        <h3>Low Stock Alerts</h3>
                        <div class="number" style="<?php echo $low_stock_count > 0 ? 'color:#f59e0b;' : ''; ?>"><?php echo $low_stock_count; ?></div>
                    </div>
                </div>
                <div class="stat-card clickable" data-quick="expiry:90" style="border-top-color: #c2410c;">
                    <div class="stat-icon-badge" style="color:#c2410c; background:rgba(194,65,12,0.12);"><?php echo mh_icon('clock'); ?></div>
                    <div class="stat-body">
                        <h3>Near Expiry (<?php echo (int) $NEAR_EXPIRY_DAYS; ?>d)</h3>
                        <div class="number" style="<?php echo $near_expiry_count > 0 ? 'color:#c2410c;' : ''; ?>"><?php echo $near_expiry_count; ?></div>
                    </div>
                </div>
                <div class="stat-card clickable" data-quick="expiry:expired" style="border-top-color: #ef4444;">
                    <div class="stat-icon-badge" style="color:#ef4444; background:rgba(239,68,68,0.12);"><?php echo mh_icon('x-circle'); ?></div>
                    <div class="stat-body">
                        <h3>Expired Items</h3>
                        <div class="number" style="<?php echo $expired_count > 0 ? 'color:#ef4444;' : ''; ?>"><?php echo $expired_count; ?></div>
                    </div>
                </div>
            </div>

            <div class="unified-panel">
                <div class="panel-controls">
                    <h3 style="margin:0; font-size: 1.2rem; color: var(--heading-color, #1e293b);">Supply Masterlist</h3>
                    <div class="filter-toolbar">
                        <div class="mode-toggle" id="modeToggle">
                            <button type="button" data-mode="generic" class="active">By Generic</button>
                            <button type="button" data-mode="brand">By Brand</button>
                        </div>
                        <input type="text" id="inventorySearch" class="search-box" placeholder="Search item, brand, SKU or lot...">
                        <select id="categoryFilter">
                            <option value="">All Categories</option>
                            <?php foreach($inv_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="stockStatusFilter">
                            <option value="">All Stock Status</option>
                            <option value="in">In Stock</option>
                            <option value="low">Low Stock</option>
                            <option value="out">Out of Stock</option>
                            <option value="near">Near Expiry</option>
                        </select>
                        <select id="expiryFilter">
                            <option value="">Any Expiration</option>
                            <option value="30">Expiring in 30 days</option>
                            <option value="60">Expiring in 60 days</option>
                            <option value="90">Expiring in 90 days</option>
                            <option value="expired">Expired</option>
                        </select>
                        <button type="button" class="btn-secondary no-print" onclick="window.print()"><?php echo mh_icon('file-text'); ?> Print</button>
                        <?php if ($can_edit_stock): ?>
                        <button class="btn-add" onclick="openAddItemModalBlank()"><?php echo mh_icon('plus'); ?> Add New Batch</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="filter-chips" id="filterChips" style="display:none;"></div>
                
                <div class="table-scroll">
                <table class="unified-table" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Total Stock</th>
                            <th>Earliest Expiration</th>
                            <th>Category &amp; Form</th>
                            <th>Status</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTbody">
                        <tr class="empty-state"><td colspan="6"><?php echo mh_icon('package'); ?><br>Loading masterlist...</td></tr>
                    </tbody>
                </table>
                </div>
            </div>

        </div>
    <?php mh_layout_close(); ?>

    <?php if ($can_edit_stock): ?>
    <div class="modal-overlay" id="addItemModal">
        <div class="modal-content" style="max-width: 650px;">
            <span class="close-btn" onclick="closeModal('addItemModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:var(--heading-color, #1e293b); font-family: Georgia, serif;" id="addItemModalTitle">Add New Inventory Batch</h2>
            </div>
            
            <form method="POST">
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label>Generic Name <span style="color:red;">*</span></label>
                        <input type="text" name="item_name" id="itemNameInput" data-autofix="name" data-suggest="1" autocomplete="off" required placeholder="e.g., Amoxicillin 500mg" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    </div>
                    <div class="form-group">
                        <label>Brand Name (Optional)</label>
                        <input type="text" name="brand_name" id="brandNameInput" data-autofix="name" placeholder="e.g., Clavamox" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    </div>
                </div>

                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed #cbd5e1;">
                    <div class="form-group">
                        <label>Category <span style="color:red;">*</span></label>
                        <select name="category" id="categorySelect" required onchange="generateTrackingCode()" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <option value="" disabled selected>Select...</option>
                            <?php foreach($inv_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="color: #0369a1;">System Tracking Code (Auto)</label>
                        <input type="text" name="tracking_code" id="trackingCodeInput" readonly placeholder="Select category first..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #bae6fd; font-family: monospace; background: #e0f2fe; color: #0369a1; font-weight: bold; outline: none;">
                    </div>
                </div>
                
                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Batch / Lot No.</span>
                            <div class="batch-toggle-container">
                                <label style="color: #0369a1;"><input type="radio" name="batch_type" value="auto" checked onchange="toggleBatchInput()"> Predefined</label>
                                <label><input type="radio" name="batch_type" value="manual" onchange="toggleBatchInput()"> Manual</label>
                            </div>
                        </label>
                        <input type="text" name="batch_number" id="batchNumberInput" data-autofix="batch" value="LOT-<?php echo date('ymd') . rand(10, 99); ?>" readonly style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-family: monospace; background: #e2e8f0; color: #475569; outline: none;">
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="expiryDateInput" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+15 years')); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    </div>
                </div>

                <div class="form-group packaging-config-box" style="grid-column: 1 / -1;">
                    <input type="hidden" id="add_mode" value="">
                    <input type="hidden" name="box_label" id="add_box_label" value="Box">
                    <input type="hidden" name="pack_label" id="add_pack_label" value="Blister">

                    <div id="add_pick_cat" style="font-size: 0.85rem; color: var(--text-muted);">Choose a <strong>Category</strong> above and the quantity fields will appear here.</div>

                    <div id="add_pack_body" style="display: none;">
                        <div id="add_cfg_wrap">
                            <label class="section-label"><?php echo mh_icon('package'); ?> How is it packed?</label>
                            <div id="add_cfg_row" class="packaging-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 14px;">
                                <div class="form-group" id="add_g_ppb">
                                    <label id="add_ppb_label">Blisters per Box</label>
                                    <input type="number" name="packs_per_box" id="add_packs_per_box" data-autofix="int" data-max="10000" inputmode="numeric" min="1" placeholder="e.g., 10" oninput="calcAddTotal()" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                                </div>
                                <div class="form-group" id="add_g_upp">
                                    <label id="add_upp_label">Tablets per Blister</label>
                                    <input type="number" name="units_per_pack" id="add_units_per_pack" data-autofix="int" data-max="10000" inputmode="numeric" min="1" placeholder="e.g., 10" oninput="calcAddTotal()" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                                </div>
                            </div>
                        </div>

                        <label class="section-label"><?php echo mh_icon('calculator'); ?> <span id="add_qty_title">How much was delivered?</span></label>
                        <div id="add_qty_row" class="packaging-grid" style="grid-template-columns: 1fr;">
                            <div class="form-group" id="add_g_box_qty">
                                <label id="add_box_qty_label">Boxes</label>
                                <input type="number" id="add_box_qty" data-autofix="int" data-max="100000" inputmode="numeric" min="0" placeholder="0" oninput="calcAddTotal()" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                            </div>
                            <div class="form-group" id="add_g_pack_qty">
                                <label id="add_pack_qty_label">Extra Blisters <span style="font-weight: 400;">(optional)</span></label>
                                <input type="number" id="add_pack_qty" data-autofix="int" data-max="100000" inputmode="numeric" min="0" placeholder="0" oninput="calcAddTotal()" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                            </div>
                            <div class="form-group" id="add_g_loose">
                                <label id="add_loose_label">Quantity</label>
                                <input type="number" id="add_loose_qty" data-autofix="int" data-max="100000" inputmode="numeric" min="0" placeholder="0" oninput="calcAddTotal()" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                            </div>
                        </div>
                        <div id="add_total_sentence" class="packaging-total-readout" style="margin-top: 12px;">Total: <strong>0</strong></div>
                        <div id="add_total_hint" style="display: none; margin-top: 8px; font-size: 0.78rem; color: #b45309;"></div>
                        <div id="add_total_warn" style="display: none; margin-top: 6px; font-size: 0.78rem; color: #b45309;"></div>
                    </div>
                </div>

                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label>Total Quantity (auto) <span style="color:red;">*</span></label>
                        <input type="number" name="quantity" id="add_total_qty" required readonly value="0" min="0" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-weight: bold; color: var(--heading-color, #0f172a); background: var(--bg-hover);">
                    </div>
                    <div class="form-group">
                        <label>Base Unit <span style="color:red;">*</span></label>
                        <input type="text" name="unit" id="unitInput" data-autofix="unit" required placeholder="e.g., pcs, capsules" value="pcs" oninput="syncAddLabels()" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                </div>

                <button type="submit" name="add_item" data-confirm="Add this item to the Supply Masterlist?" data-confirm-title="Add item?" data-confirm-type="info" data-confirm-ok="Add item" class="btn-submit" style="width: 100%; padding: 15px; font-size: 1.1rem; background: #3b82f6; border:none; color:white; border-radius:6px; cursor:pointer; font-weight:bold;">Register Item Batch</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="updateStockModal">
        <div class="modal-content" style="max-width: 480px; max-height: 92vh; overflow-y: auto; border-top: 5px solid #0369a1;">
            <span class="close-btn" onclick="closeModal('updateStockModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 15px;">
                <h2 style="margin:0; color:#0369a1;">Manage Batch</h2>
                <h3 id="modalItemName" style="margin:5px 0 0 0; color:var(--heading-color, #1e293b); font-size: 1.1rem;">Item Name</h3>
                <p style="margin:5px 0 0 0; font-size: 0.85rem; color: var(--text-muted);">SKU: <span id="modalBatchNo" style="font-family: monospace; font-weight: bold; color:#1d4ed8;">000</span></p>
                <p style="margin:5px 0 0 0; color:var(--text-muted); font-size: 0.9rem;">Current Stock: <strong id="modalCurrentQty">0</strong> <span id="modalUnit">pcs</span></p>
            </div>
            
            <form method="POST" id="stockForm">
                <?php echo mh_csrf_field(); ?>
                <input type="hidden" name="item_id" id="modalItemId">
                <input type="hidden" name="item_name_hidden" id="modalItemNameHidden">

                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Action <span style="color:red;">*</span></label>
                    <select name="stock_action" id="stockAction" onchange="toggleStockAction()" required>
                        <option value="dispose">Dispose Stock (expired / damaged)</option>
                        <option value="edit_expiry">Update Expiry Date</option>
                        <option value="archive">Archive / Remove Item</option>
                    </select>
                    <small class="field-hint">New deliveries are added with &ldquo;+ Add New Batch&rdquo; so each lot keeps its own expiry date.</small>
                </div>

                <div id="disposeGroup" style="display:none;">
                    <div id="disposeNote" class="alert alert-warn" style="padding:10px 12px; margin-bottom: 14px; font-size:.88rem;"></div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label>Type of stock <span style="color:red;">*</span></label>
                        <select name="item_form" id="disposeForm" onchange="updateDisposeLabels()">
                            <?php foreach (mh_disp_forms() as $k => $lbl): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars($lbl); ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;" id="adjustAmountGroup">
                        <label id="disposeAmountLabel">Amount to dispose <span style="color:red;">*</span></label>
                        <div style="display:flex; gap:8px;">
                            <input type="number" name="amount" id="adjustAmountInput" min="1" step="1" placeholder="Base units...">
                            <button type="button" class="btn-secondary btn-sm" onclick="disposeAll()" style="white-space:nowrap;">Dispose all</button>
                        </div>
                        <div class="packaging-config-box" style="margin: 10px 0 0;">
                            <label style="font-size: 0.8rem; color: var(--primary); margin-bottom: 8px; display: block; font-weight: bold;">Optional calculator</label>
                            <div class="packaging-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                                <div class="form-group"><label id="upd_box_qty_label" style="font-size:0.72rem;">Boxes</label>
                                    <input type="number" id="upd_box_qty" placeholder="0" min="0" onkeyup="calcUpdTotal()" onchange="calcUpdTotal()"></div>
                                <div class="form-group"><label id="upd_pack_qty_label" style="font-size:0.72rem;">Packs</label>
                                    <input type="number" id="upd_pack_qty" placeholder="0" min="0" onkeyup="calcUpdTotal()" onchange="calcUpdTotal()"></div>
                                <div class="form-group"><label style="font-size:0.72rem;">Loose Units</label>
                                    <input type="number" id="upd_loose_qty" placeholder="0" min="0" onkeyup="calcUpdTotal()" onchange="calcUpdTotal()"></div>
                            </div>
                            <div class="packaging-total-readout" id="upd_total_readout" style="margin-top: 8px;">Disposes: <strong>0</strong> base units</div>
                        </div>
                    </div>

                    <div class="form-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div class="form-group"><label>Reason <span style="color:red;">*</span></label>
                            <select name="reason" id="disposeReason" onchange="updateDisposeLabels()">
                                <?php foreach (mh_disp_reasons() as $k => $lbl): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars($lbl); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group"><label>Date disposed <span style="color:red;">*</span></label>
                            <input type="date" name="disposed_on" id="disposeDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
                    </div>
                    <div class="form-group"><label>How was it disposed? <span style="color:red;">*</span></label>
                        <select name="method" id="disposeMethod">
                            <?php foreach (mh_disp_methods() as $k => $lbl): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($lbl); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Witnessed by <small class="field-hint" style="display:inline;">(optional)</small></label>
                        <input type="text" name="witness" id="disposeWitness" maxlength="100" placeholder="Name of the witness / receiving person"></div>
                    <div class="form-group"><label>Remarks <small class="field-hint" style="display:inline;" id="disposeRemarksHint">(optional)</small></label>
                        <textarea name="remarks" id="disposeRemarks" rows="2" maxlength="500" placeholder="Anything the audit should know"></textarea></div>
                </div>

                <div class="form-group" id="editExpiryGroup" style="display: none; margin-bottom: 20px;">
                    <label>New Expiry Date <span style="color:red;">*</span></label>
                    <input type="date" name="new_expiry_date" id="newExpiryInput" min="<?php echo date('Y-m-d'); ?>">
                </div>

                <div id="archiveWarningGroup" style="display: none; margin-bottom: 20px; background: var(--mh-danger-bg, #fee2e2); border: 1px solid var(--mh-danger-border, #fca5a5); padding: 15px; border-radius: 8px; color: var(--mh-danger-fg, #991b1b); font-size: 0.9rem;">
                    <strong>Warning:</strong> This will move the item to the Archives and hide it from the active masterlist. To remove expired medicine properly, use <em>Dispose Stock</em> so the disposal is recorded.
                </div>

                <button type="submit" name="update_stock" id="confirmBtn" data-confirm="Record this disposal?" data-confirm-title="Dispose stock?" data-confirm-type="danger" data-confirm-ok="Dispose" class="btn-submit" style="width: 100%;">Confirm</button>
            </form>
        </div>
    </div>

    
    <div class="modal-overlay" id="editBatchModal">
        <div class="modal-content" style="max-width: 650px; border-top: 5px solid var(--primary);">
            <span class="close-btn" onclick="closeModal('editBatchModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:var(--heading-color, #1e293b); font-family: Georgia, serif;">Edit Batch</h2>
                <p style="margin:5px 0 0 0; font-size: 0.85rem; color: var(--text-muted);">Editing SKU: <span id="editModalSku" style="font-family: monospace; font-weight: bold; color: var(--primary);">000</span></p>
            </div>

            <form method="POST">
                <input type="hidden" name="item_id" id="editItemId">

                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label>Generic Name <span style="color:red;">*</span></label>
                        <input type="text" name="item_name" id="editItemNameInput" data-autofix="name" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    <div class="form-group">
                        <label>Brand Name (Optional)</label>
                        <input type="text" name="brand_name" id="editBrandNameInput" data-autofix="name" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                </div>

                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label>Category <span style="color:red;">*</span></label>
                        <select name="category" id="editCategorySelect" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                            <?php foreach($inv_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>System Tracking Code (SKU)</label>
                        <input type="text" name="tracking_code" id="editTrackingCodeInput" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-family: monospace;">
                    </div>
                </div>

                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label>Batch / Lot No.</label>
                        <input type="text" name="batch_number" id="editBatchNumberInput" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-family: monospace;">
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="editExpiryDateInput" max="<?php echo date('Y-m-d', strtotime('+15 years')); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                </div>

                <div class="form-group packaging-config-box" style="grid-column: 1 / -1;">
                    <label class="section-label"><?php echo mh_icon('package'); ?> Packaging Structure</label>
                    <div class="packaging-grid">
                        <div class="form-group">
                            <label>Box Label</label>
                            <input type="text" name="box_label" id="editBoxLabel" data-autofix="label" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Packs per Box</label>
                            <input type="number" name="packs_per_box" id="editPacksPerBox" data-autofix="int" data-max="10000" inputmode="numeric" min="1" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Pack Label</label>
                            <input type="text" name="pack_label" id="editPackLabel" data-autofix="label" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Base Units per Pack</label>
                            <input type="number" name="units_per_pack" id="editUnitsPerPack" data-autofix="int" data-max="10000" inputmode="numeric" min="1" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Base Unit <span style="color:red;">*</span></label>
                    <input type="text" name="unit" id="editUnitInput" data-autofix="unit" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                </div>

                <button type="submit" name="edit_item" data-confirm="Save changes to this item?" data-confirm-title="Save changes?" data-confirm-type="warning" data-confirm-ok="Save" class="btn-submit" style="width: 100%; padding: 15px; font-size: 1.1rem; background: var(--primary); border:none; color:white; border-radius:6px; cursor:pointer; font-weight:bold;">Save Batch Details</button>
            </form>
        </div>
    </div>

    
    <form method="POST" id="quickArchiveForm" style="display:none;">
        <input type="hidden" name="item_id" id="quickArchiveItemId">
        <input type="hidden" name="item_name_hidden" id="quickArchiveItemName">
        <input type="hidden" name="stock_action" value="archive">
        <input type="hidden" name="update_stock" value="1">
    </form>

    <?php endif;  ?>

    <script>
        
        const MH_CAN_EDIT_STOCK = <?php echo $can_edit_stock ? 'true' : 'false'; ?>;   
        const MH_CAN_DISPENSE = <?php echo mh_has_permission('dispense_medicine') ? 'true' : 'false'; ?>;
        
        const TODAY_STR   = "<?php echo $today; ?>";
        const CATEGORIES  = <?php echo $js_categories; ?>;
        const RAW_ITEMS   = <?php echo $js_inventory; ?>;
        const LOW_STOCK_THRESHOLD = <?php echo (int) $LOW_STOCK_THRESHOLD; ?>;
        const NEAR_EXPIRY_DAYS_DEFAULT = <?php echo (int) $NEAR_EXPIRY_DAYS; ?>;
        const defaultBatchNumber = "LOT-<?php echo date('ymd') . rand(10, 99); ?>";

        const state = {
            search: '',
            category: '',
            stockStatus: '',
            expiryWindow: '',
            mode: 'generic'
        };

        const expandedGroups = new Set();

       
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return 'N/A';
            return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }

        function daysToExpiry(dateStr) {
            if (!dateStr) return null;
            const todayD = new Date(TODAY_STR + 'T00:00:00');
            const expD = new Date(dateStr + 'T00:00:00');
            if (isNaN(expD.getTime())) return null;
            return Math.round((expD - todayD) / 86400000);
        }

        function packagingBreakdown(item) {
            
            const qty = item.quantity;
            const unitsPerPack = item.units_per_pack;
            if (!unitsPerPack || unitsPerPack <= 0) return '';

            const packsPerBox = item.packs_per_box || 0;
            const boxLabel = item.box_label || 'Box';
            const packLabel = item.pack_label || 'Pack';

            let remaining = qty;
            const parts = [];
            if (packsPerBox > 0) {
                const unitsPerBox = packsPerBox * unitsPerPack;
                const boxes = Math.floor(remaining / unitsPerBox);
                remaining -= boxes * unitsPerBox;
                if (boxes > 0) parts.push(`${boxes} ${boxLabel}${boxes === 1 ? '' : 's'}`);
            }
            const packs = Math.floor(remaining / unitsPerPack);
            remaining -= packs * unitsPerPack;
            if (packs > 0) parts.push(`${packs} ${packLabel}${packs === 1 ? '' : 's'}`);
            if (remaining > 0 || parts.length === 0) parts.push(`${remaining} loose`);
            return parts.join(' + ');
        }

        
        function annotateBatch(item) {
            const days = daysToExpiry(item.expiry_date);
            let status = 'in';
            let label = 'In Stock';
            let statusClass = 'status-instock';

            if (item.quantity <= 0) {
                status = 'out'; label = 'Out of Stock'; statusClass = 'status-outofstock';
            } else if (item.quantity <= LOW_STOCK_THRESHOLD) {
                status = 'low'; label = 'Low Stock'; statusClass = 'status-lowstock';
            }

            const isExpired = item.expiry_date && days !== null && days < 0;
            if (isExpired) {
                status = 'expired'; label = 'Expired'; statusClass = 'status-expired';
            }

            const isNear = !isExpired && item.quantity > 0 && days !== null && days >= 0 && days <= NEAR_EXPIRY_DAYS_DEFAULT;

            
            if (isNear && status === 'in') {
                status = 'near'; label = 'Near Expiry'; statusClass = 'status-nearexpiry';
            }

            return Object.assign({}, item, {
                days, status, label, statusClass, isExpired, isNear
            });
        }

        const ANNOTATED_ITEMS = RAW_ITEMS.map(annotateBatch);

        
        const BATCH_BY_ID = {};
        ANNOTATED_ITEMS.forEach(b => { BATCH_BY_ID[b.id] = b; });

        
        function batchMatchesFilters(b) {
            if (state.category && b.category !== state.category) return false;

            if (state.stockStatus) {
                if (state.stockStatus === 'near') {
                    if (!b.isNear) return false;
                } else if (b.status !== state.stockStatus) {
                    return false;
                }
            }

            if (state.expiryWindow) {
                if (state.expiryWindow === 'expired') {
                    if (!b.isExpired) return false;
                } else {
                    const window = parseInt(state.expiryWindow, 10);
                    if (b.days === null || b.days < 0 || b.days > window) return false;
                }
            }

            if (state.search) {
                const q = state.search.toLowerCase();
                const haystack = [b.item_name, b.brand_name, b.tracking_code, b.batch_number]
                    .filter(Boolean).join(' ').toLowerCase();
                if (!haystack.includes(q)) return false;
            }

            return true;
        }

        function groupKey(b) {
            const generic = (b.item_name || '').trim().toLowerCase();
            if (state.mode === 'brand') {
                const brand = (b.brand_name || 'Generic').trim().toLowerCase();
                return generic + '||' + brand;
            }
            return generic;
        }

        const STATUS_PRIORITY = { out: 1, expired: 2, low: 3, near: 4, in: 5 };

        function buildGroups() {
            const filtered = ANNOTATED_ITEMS.filter(batchMatchesFilters);
            const groups = new Map();

            filtered.forEach(b => {
                const key = groupKey(b);
                if (!groups.has(key)) {
                    groups.set(key, {
                        key,
                        generic_name: b.item_name,
                        category: b.category,
                        unit: b.unit,
                        brands: new Set(),
                        batches: [],
                        totalStock: 0
                    });
                }
                const g = groups.get(key);
                g.brands.add((b.brand_name || 'Generic').trim() || 'Generic');
                g.batches.push(b);
                g.totalStock += b.quantity;
            });

            const result = [];
            groups.forEach(g => {
                g.batches.sort((a, b2) => {
                    if (a.expiry_date === b2.expiry_date) return 0;
                    if (!a.expiry_date) return 1;
                    if (!b2.expiry_date) return -1;
                    return a.expiry_date < b2.expiry_date ? -1 : 1;
                });

                const hasExpired = g.batches.some(b => b.isExpired);
                const hasNear = g.batches.some(b => b.isNear);

                let status = 'in', label = 'In Stock', statusClass = 'status-instock';
                if (g.totalStock <= 0) { status = 'out'; label = 'Out of Stock'; statusClass = 'status-outofstock'; }
                else if (hasExpired) { status = 'expired'; label = 'Expired'; statusClass = 'status-expired'; }
                else if (g.totalStock <= LOW_STOCK_THRESHOLD) { status = 'low'; label = 'Low Stock'; statusClass = 'status-lowstock'; }
                else if (hasNear) { status = 'near'; label = 'Near Expiry'; statusClass = 'status-nearexpiry'; }

                const earliestBatch = g.batches.find(b => b.expiry_date) || null;
                const fefoBatch = g.batches.find(b => b.quantity > 0 && !b.isExpired) || null;
                const samplePackaging = g.batches.find(b => b.units_per_pack) || null;

                let firstEligibleMarked = false;
                g.batches.forEach(b => {
                    b.isFefo = false;
                    if (!firstEligibleMarked && b.quantity > 0 && !b.isExpired && b.expiry_date) {
                        b.isFefo = true;
                        firstEligibleMarked = true;
                    }
                });

                result.push({
                    key: g.key,
                    generic_name: g.generic_name,
                    category: g.category,
                    unit: g.unit,
                    brands: Array.from(g.brands),
                    batches: g.batches,
                    totalStock: g.totalStock,
                    status, label, statusClass,
                    hasNear,
                    earliestExpiry: earliestBatch ? earliestBatch.expiry_date : null,
                    earliestDays: earliestBatch ? earliestBatch.days : null,
                    fefoBatchId: fefoBatch ? fefoBatch.id : null,
                    samplePackagingId: samplePackaging ? samplePackaging.id : null
                });
            });

            result.sort((a, b) => {
                const pa = STATUS_PRIORITY[a.status] || 5;
                const pb = STATUS_PRIORITY[b.status] || 5;
                if (pa !== pb) return pa - pb;
                if (a.earliestExpiry !== b.earliestExpiry) {
                    if (!a.earliestExpiry) return 1;
                    if (!b.earliestExpiry) return -1;
                    return a.earliestExpiry < b.earliestExpiry ? -1 : 1;
                }
                return a.generic_name.localeCompare(b.generic_name);
            });

            return result;
        }

        
        function renderBatchRow(group, b) {
            const brandLabel = (b.brand_name || 'Generic').trim() || 'Generic';
            const modalName = group.generic_name + ' — ' + brandLabel;
            return `
                <tr>
                    <td>
                        <strong style="color:var(--heading-color,#0f172a);">${escapeHtml(brandLabel)}</strong>
                        ${b.isFefo ? `<div class="fefo-tag" style="margin-top:4px;">${mhIconSvg('flag')} Dispense first (FEFO)</div>` : ''}
                    </td>
                    <td>
                        <div class="tracking-chip">
                            <div class="sku">SKU: ${escapeHtml(b.tracking_code || 'N/A')}</div>
                            <div class="lot">Lot: ${escapeHtml(b.batch_number || 'N/A')}</div>
                        </div>
                    </td>
                    <td>
                        ${escapeHtml(formatDate(b.expiry_date))}
                        ${b.expiry_date ? `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">${b.days < 0 ? 'Expired ' + Math.abs(b.days) + ' days ago' : b.days + ' days left'}</div>` : ''}
                    </td>
                    <td>
                        <strong style="color:${b.quantity <= LOW_STOCK_THRESHOLD ? '#ef4444' : 'var(--text-main)'};">${b.quantity.toLocaleString()}</strong>
                        <span style="color:var(--text-muted); font-size:0.85rem;"> ${escapeHtml(b.unit)}</span>
                        ${packagingBreakdown(b) ? `<div style="font-size:0.72rem;color:var(--primary);font-weight:bold;margin-top:2px;">${packagingBreakdown(b)}</div>` : ''}
                    </td>
                    <td><span class="status-pill ${b.statusClass}">${b.label}</span></td>
                    <td class="no-print">
                        <div class="action-btn-group">
                            ${MH_CAN_EDIT_STOCK ? `${b.isExpired && b.quantity > 0 ? `<button class="btn-manage" style="background:#b91c1c;color:#fff;" data-id="${b.id}" onclick="openUpdateModal(BATCH_BY_ID[this.dataset.id], 'dispose')">${mhIconSvg('archive')} Dispose</button>` : `<button class="btn-manage" data-id="${b.id}" onclick="openUpdateModal(BATCH_BY_ID[this.dataset.id])">${mhIconSvg('package')} Manage</button>`}
                            <button class="btn-icon icon-edit" data-id="${b.id}" title="Edit Batch" onclick="openEditBatchModal(BATCH_BY_ID[this.dataset.id])">${mhIconSvg('edit')}</button>
                            <button class="btn-icon icon-delete" data-id="${b.id}" data-name="${escapeHtml(modalName)}" title="Archive Batch" onclick="archiveBatchQuick(this.dataset.id, this.dataset.name)">${mhIconSvg('archive')}</button>` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }

        function mhIconSvg(name) {
            const icons = {
                'chevron-right': '<svg class="mh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>',
                'flag': '<svg class="mh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="3"></line></svg>',
                'package': '<svg class="mh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5 9-5z"></path><path d="M3 8v8l9 5 9-5V8"></path><path d="M12 13v8"></path></svg>',
                'edit': '<svg class="mh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"></path></svg>',
                'archive': '<svg class="mh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5" rx="1"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>'
            };
            return icons[name] || '';
        }

        function renderGroupRow(group) {
            const isExpanded = expandedGroups.has(group.key);
            const brandLabel = group.brands.length > 1
                ? `<span class="brand-badge multi">${group.brands.length} brands</span>`
                : `<span class="brand-badge">${escapeHtml(group.brands[0] || 'Generic')}</span>`;

            const dispenseHref = group.fefoBatchId
                ? `dispense_medicine.php?dispense=${group.fefoBatchId}`
                : '#';
            const dispenseDisabled = group.fefoBatchId ? '' : 'disabled';

            const groupRow = `
                <tr class="group-row ${isExpanded ? 'expanded' : ''}" data-key="${escapeHtml(group.key)}" onclick="toggleGroup(this.dataset.key, event)">
                    <td>
                        <div class="item-cell">
                            <span class="group-chevron">${mhIconSvg('chevron-right')}</span>
                            <div class="item-cell-text">
                                <strong>${escapeHtml(group.generic_name)}<span class="batch-count-tag">${group.batches.length} batch${group.batches.length === 1 ? '' : 'es'}</span></strong>
                                ${brandLabel}
                                ${group.hasNear && group.status !== 'expired' && group.status !== 'near' ? `<div class="near-expiry-pill">${mhIconSvg('flag')} Near expiry</div>` : ''}
                            </div>
                        </div>
                    </td>
                    <td>
                        <strong style="font-size:1.15rem; color:${group.totalStock <= LOW_STOCK_THRESHOLD ? '#ef4444' : 'var(--heading-color,#1e293b)'};">${group.totalStock.toLocaleString()}</strong>
                        <span style="color:var(--text-muted); font-size:0.85rem;"> ${escapeHtml(group.unit)}</span>
                    </td>
                    <td>
                        ${escapeHtml(formatDate(group.earliestExpiry))}
                        ${group.earliestExpiry !== null && group.earliestDays !== null ? `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">${group.earliestDays < 0 ? 'Expired ' + Math.abs(group.earliestDays) + ' days ago' : group.earliestDays + ' days left'}</div>` : ''}
                    </td>
                    <td>${escapeHtml(group.category)}<div style="font-size:0.8rem;color:var(--text-muted);">${escapeHtml(group.unit)}</div></td>
                    <td><span class="status-pill ${group.statusClass}">${group.label}</span></td>
                    <td class="no-print" onclick="event.stopPropagation();">
                        <div class="action-btn-group">
                            ${MH_CAN_EDIT_STOCK ? `<button class="btn-add-batch"
                                data-item-name="${escapeHtml(group.generic_name)}"
                                data-category="${escapeHtml(group.category)}"
                                data-unit="${escapeHtml(group.unit)}"
                                data-sample-id="${group.samplePackagingId || ''}"
                                onclick="openAddBatchModal(Object.assign({item_name: this.dataset.itemName, category: this.dataset.category, unit: this.dataset.unit}, BATCH_BY_ID[this.dataset.sampleId] || {}))">${mhIconSvg('package')} Add Batch</button>` : ''}
                            ${MH_CAN_DISPENSE ? `<a class="btn-dispense ${dispenseDisabled}" href="${dispenseHref}" title="${group.fefoBatchId ? 'Dispense the earliest-expiring batch first' : 'No dispensable stock'}">Dispense</a>` : ''}
                            <button class="btn-manage" data-key="${escapeHtml(group.key)}" onclick="toggleGroup(this.dataset.key, event)">${isExpanded ? 'Hide' : 'Manage'} Batches</button>
                        </div>
                    </td>
                </tr>
            `;

            const detailRow = `
                <tr class="batch-detail-row" data-parent="${escapeHtml(group.key)}" style="display:${isExpanded ? 'table-row' : 'none'};">
                    <td colspan="6">
                        <div class="batch-subtable-wrap">
                            <table class="batch-subtable">
                                <thead>
                                    <tr>
                                        <th>Brand</th>
                                        <th>Tracking &amp; Batch No.</th>
                                        <th>Expiry Date</th>
                                        <th>Stock Level</th>
                                        <th>Status</th>
                                        <th class="no-print">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${group.batches.map(b => renderBatchRow(group, b)).join('')}
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            `;

            return groupRow + detailRow;
        }

        function render() {
            const groups = buildGroups();
            const tbody = document.getElementById('inventoryTbody');

            if (groups.length === 0) {
                tbody.innerHTML = `<tr class="empty-state"><td colspan="6">${mhIconSvg('package')}<br>No items match the selected filters.<br><button class="filter-reset-btn" style="margin-top:10px;" onclick="resetFilters()">Reset filters</button></td></tr>`;
            } else {
                tbody.innerHTML = groups.map(renderGroupRow).join('');
            }

            renderChips();
            updateQuickCardState();
        }

        
        function renderChips() {
            const container = document.getElementById('filterChips');
            const chips = [];

            if (state.search) chips.push({ label: `Search: "${state.search}"`, clear: () => { state.search = ''; document.getElementById('inventorySearch').value = ''; } });
            if (state.category) chips.push({ label: `Category: ${state.category}`, clear: () => { state.category = ''; document.getElementById('categoryFilter').value = ''; } });
            if (state.stockStatus) {
                const map = { in: 'In Stock', low: 'Low Stock', out: 'Out of Stock', near: 'Near Expiry' };
                chips.push({ label: `Status: ${map[state.stockStatus] || state.stockStatus}`, clear: () => { state.stockStatus = ''; document.getElementById('stockStatusFilter').value = ''; } });
            }
            if (state.expiryWindow) {
                const map = { '30': 'Expiring ≤ 30 days', '60': 'Expiring ≤ 60 days', '90': 'Expiring ≤ 90 days', 'expired': 'Expired' };
                chips.push({ label: `Expiry: ${map[state.expiryWindow] || state.expiryWindow}`, clear: () => { state.expiryWindow = ''; document.getElementById('expiryFilter').value = ''; } });
            }
            if (state.mode === 'brand') chips.push({ label: 'View: By Brand', clear: () => { setMode('generic'); } });

            if (chips.length === 0) {
                container.style.display = 'none';
                container.innerHTML = '';
                return;
            }

            container.style.display = 'flex';
            container.innerHTML = chips.map((c, i) => `
                <span class="filter-chip">${escapeHtml(c.label)} <button type="button" data-chip-index="${i}" aria-label="Remove filter">${mhIconSvgSmallX()}</button></span>
            `).join('') + `<button type="button" class="filter-reset-btn" onclick="resetFilters()">Clear all</button>`;

            container.querySelectorAll('button[data-chip-index]').forEach(btn => {
                btn.addEventListener('click', () => {
                    chips[parseInt(btn.dataset.chipIndex, 10)].clear();
                    render();
                });
            });
        }

        function mhIconSvgSmallX() {
            return '<svg class="mh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        }

        function resetFilters() {
            state.search = ''; state.category = ''; state.stockStatus = ''; state.expiryWindow = '';
            document.getElementById('inventorySearch').value = '';
            document.getElementById('categoryFilter').value = '';
            document.getElementById('stockStatusFilter').value = '';
            document.getElementById('expiryFilter').value = '';
            render();
        }

        
        function updateQuickCardState() {
            document.querySelectorAll('[data-quick]').forEach(card => {
                const q = card.dataset.quick;
                let active = false;
                if (q === 'stock:low') active = state.stockStatus === 'low';
                if (q === 'expiry:90') active = state.expiryWindow === '90';
                if (q === 'expiry:expired') active = state.expiryWindow === 'expired';
                card.classList.toggle('active-quick', active);
            });
        }

        document.querySelectorAll('[data-quick]').forEach(card => {
            card.addEventListener('click', () => {
                const q = card.dataset.quick;
                if (q === 'reset') { resetFilters(); return; }
                if (q === 'stock:low') {
                    state.stockStatus = state.stockStatus === 'low' ? '' : 'low';
                    document.getElementById('stockStatusFilter').value = state.stockStatus;
                }
                if (q === 'expiry:90') {
                    state.expiryWindow = state.expiryWindow === '90' ? '' : '90';
                    document.getElementById('expiryFilter').value = state.expiryWindow;
                }
                if (q === 'expiry:expired') {
                    state.expiryWindow = state.expiryWindow === 'expired' ? '' : 'expired';
                    document.getElementById('expiryFilter').value = state.expiryWindow;
                }
                render();
            });
        });

        
        function toggleGroup(key, evt) {
            if (evt) evt.stopPropagation();
            if (expandedGroups.has(key)) expandedGroups.delete(key);
            else expandedGroups.add(key);
            render();
        }

        function setMode(mode) {
            state.mode = mode;
            expandedGroups.clear();
            document.querySelectorAll('#modeToggle button').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
            render();
        }

        document.querySelectorAll('#modeToggle button').forEach(btn => {
            btn.addEventListener('click', () => setMode(btn.dataset.mode));
        });

        document.getElementById('inventorySearch').addEventListener('input', (e) => { state.search = e.target.value.trim(); render(); });
        document.getElementById('categoryFilter').addEventListener('change', (e) => { state.category = e.target.value; render(); });
        document.getElementById('stockStatusFilter').addEventListener('change', (e) => { state.stockStatus = e.target.value; render(); });
        document.getElementById('expiryFilter').addEventListener('change', (e) => { state.expiryWindow = e.target.value; render(); });

        render();

        
        function toggleBatchInput() {
            var type = document.querySelector('input[name="batch_type"]:checked').value;
            var input = document.getElementById('batchNumberInput');
            var labels = document.querySelectorAll('.batch-toggle-container label');
            
            if (type === 'auto') {
                input.readOnly = true;
                input.style.background = '#e2e8f0';
                input.style.color = '#475569';
                input.value = defaultBatchNumber;
                labels[0].style.color = '#0369a1'; 
                labels[1].style.color = '#475569';
            } else {
                input.readOnly = false;
                input.style.background = '#ffffff';
                input.style.color = '#0f172a';
                input.value = '';
                input.placeholder = 'Enter manual batch no...';
                input.focus();
                labels[0].style.color = '#475569';
                labels[1].style.color = '#0369a1'; 
            }
        }

        function generateTrackingCode() {
            const cat = document.getElementById('categorySelect').value;
            const input = document.getElementById('trackingCodeInput');
            if(!cat) { input.value = ''; applyCategoryMode(''); return; }
            
            const prefix = /^medical\s+suppl/i.test(cat.trim()) ? 'S' : cat.trim().charAt(0).toUpperCase();
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            const dateStr = `${yyyy}${mm}${dd}`;
            const randomNum = Math.floor(1000 + Math.random() * 9000);
            
            input.value = `${prefix}${dateStr}-${randomNum}`;
            applyCategoryMode(cat);
        }

        function toggleStockAction() {
            var action = document.getElementById('stockAction').value;
            var show = function (id, on) { document.getElementById(id).style.display = on ? 'block' : 'none'; };
            show('disposeGroup', action === 'dispose');
            show('editExpiryGroup', action === 'edit_expiry');
            show('archiveWarningGroup', action === 'archive');

            var req = function (id, on) { var el = document.getElementById(id); if (on) { el.setAttribute('required', ''); } else { el.removeAttribute('required'); } };
            req('adjustAmountInput', action === 'dispose'); req('disposeReason', action === 'dispose'); req('disposeMethod', action === 'dispose');
            req('disposeDate', action === 'dispose'); req('newExpiryInput', action === 'edit_expiry');

            var confirmBtn = document.getElementById('confirmBtn');
            var MSG = {dispose: ['Record this disposal? The quantity is removed from the batch and saved in Disposal History.', 'Dispose stock?', 'danger', 'Dispose', 'Record Disposal', '#b91c1c'],
                       edit_expiry: ['Change the expiry date of this batch?', 'Update expiry?', 'warning', 'Update', 'Update Expiry', '#0369a1'],
                       archive: ['Archive this item? It will be hidden from the active Supply Masterlist.', 'Archive item?', 'danger', 'Archive', 'Archive Item', '#b91c1c']};
            var m = MSG[action] || MSG.dispose;
            confirmBtn.dataset.confirm = m[0]; confirmBtn.dataset.confirmTitle = m[1];
            confirmBtn.dataset.confirmType = m[2]; confirmBtn.dataset.confirmOk = m[3];
            confirmBtn.innerText = m[4]; confirmBtn.style.background = m[5];
            if (action === 'dispose') { updateDisposeLabels(); }
        }

        function updateDisposeLabels() {
            var form = document.getElementById('disposeForm').value;
            var unit = document.getElementById('modalUnit').innerText || 'units';
            var label = {liquid: 'Volume to dispose', capsule: 'Capsules / tablets to dispose', piece: 'Pieces to dispose'}[form] || 'Amount to dispose';
            document.getElementById('disposeAmountLabel').innerHTML = label + ' (in ' + escapeHtml(unit) + ') <span style="color:red;">*</span>';
            var reason = document.getElementById('disposeReason').value;
            var needText = (reason === 'damaged' || reason === 'other');
            document.getElementById('disposeRemarks').toggleAttribute('required', needText);
            document.getElementById('disposeRemarksHint').innerText = needText ? '(required - explain briefly)' : '(optional)';
            var b = window._disposeBatch;
            if (b) {
                var expired = b.expiry_date && b.expiry_date < new Date().toISOString().slice(0, 10);
                var note = document.getElementById('disposeNote');
                note.innerHTML = '<strong>' + escapeHtml(b.item_name) + '</strong> - ' + Number(b.quantity).toLocaleString() + ' ' + escapeHtml(b.unit) + ' in this batch. '
                    + (b.expiry_date ? (expired ? 'It <strong>expired on ' + escapeHtml(b.expiry_date) + '</strong>.' : 'It has not expired yet (' + escapeHtml(b.expiry_date) + '), so use "Damaged", "Recalled" or "Other".') : 'No expiry date is recorded.');
            }
        }
        function disposeAll() {
            var b = window._disposeBatch; if (!b) { return; }
            document.getElementById('adjustAmountInput').value = b.quantity;
            document.getElementById('upd_total_readout').innerHTML = 'Disposes: <strong>' + Number(b.quantity).toLocaleString() + '</strong> base units';
        }

        function calcUpdTotal() {
            var packsPerBox = window._updPacksPerBox || 0;
            var unitsPerPack = window._updUnitsPerPack || 0;
            var boxQty = parseInt(document.getElementById('upd_box_qty').value) || 0;
            var packQty = parseInt(document.getElementById('upd_pack_qty').value) || 0;
            var looseQty = parseInt(document.getElementById('upd_loose_qty').value) || 0;
            var total = looseQty;
            if (unitsPerPack > 0) {
                total += packQty * unitsPerPack;
                if (packsPerBox > 0) total += boxQty * packsPerBox * unitsPerPack;
            }
            document.getElementById('adjustAmountInput').value = total > 0 ? total : '';
            document.getElementById('upd_total_readout').innerHTML = 'Disposes: <strong>' + total.toLocaleString() + '</strong> base units';
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        var AutoFix = (function () {
            var UNIT_TOKENS = ['mg', 'mcg', 'g', 'kg', 'ml', 'l', 'cc', 'iu', 'pcs'];
            var SMALL_WORDS = ['of', 'and', 'with', 'for', 'in', 'per', 'to', 'the'];
            var UNIT_ALIAS = {pc: 'pcs', pcs: 'pcs', piece: 'pcs', pieces: 'pcs', tab: 'tablets', tabs: 'tablets', tablet: 'tablets', tablets: 'tablets',
                              cap: 'capsules', caps: 'capsules', capsule: 'capsules', capsules: 'capsules', vial: 'vials', vials: 'vials', amp: 'ampules', ampule: 'ampules', ampules: 'ampules'};

            function collapse(v) { return v.replace(/\s+/g, ' ').trim(); }

            function fixName(v) {
                v = collapse(v);
                v = v.replace(/(\d)\s*(mg|mcg|g|kg|ml|l|cc|iu)\b/gi, function (_, n, u) { u = u.toLowerCase(); return n + (u === 'iu' ? 'IU' : u); });
                return v.split(' ').map(function (w, i) {
                    var lw = w.toLowerCase();
                    if (UNIT_TOKENS.indexOf(lw) !== -1) { return lw; }
                    if (!/^[A-Za-z]+$/.test(w)) { return w; }
                    if (SMALL_WORDS.indexOf(lw) !== -1 && i > 0) { return lw; }
                    if (w === lw) { return w.charAt(0).toUpperCase() + w.slice(1); }
                    if (w === w.toUpperCase() && w.length >= 5) { return w.charAt(0) + lw.slice(1); }
                    return w;
                }).join(' ');
            }
            function fixUnit(v) { v = collapse(v).toLowerCase().replace(/\.$/, ''); return UNIT_ALIAS[v] || v; }
            function fixLabel(v) { return collapse(v).split(' ').map(function (w) { return w ? w.charAt(0).toUpperCase() + w.slice(1).toLowerCase() : w; }).join(' '); }
            function fixBatch(v) { return collapse(v).toUpperCase().replace(/\s/g, '-').replace(/[^A-Z0-9\-_\/.]/g, '').replace(/-{2,}/g, '-'); }

            function noteBox(input) {
                var n = input.parentNode.querySelector('.autofix-note[data-for="' + input.id + '"]');
                if (!n) { n = document.createElement('div'); n.className = 'autofix-note'; n.dataset.for = input.id; input.insertAdjacentElement('afterend', n); }
                return n;
            }
            function clearNote(input) { var n = input.parentNode.querySelector('.autofix-note[data-for="' + input.id + '"]'); if (n) { n.remove(); } }
            function flash(input, before, after) {
                var n = noteBox(input);
                n.className = 'autofix-note';
                n.textContent = 'Auto-corrected: “' + before + '” → “' + after + '”';
                clearTimeout(n._t); n._t = setTimeout(function () { n.remove(); }, 5000);
            }

            function key(v) { return v.toLowerCase().replace(/[^a-z0-9]/g, ''); }
            function nums(v) { return (v.match(/\d+(\.\d+)?/g) || []).join(','); }
            function lev(a, b) {
                var m = a.length, n = b.length, d = [], i, j;
                for (i = 0; i <= m; i++) { d[i] = [i]; }
                for (j = 1; j <= n; j++) { d[0][j] = j; }
                for (i = 1; i <= m; i++) { for (j = 1; j <= n; j++) {
                    d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
                } }
                return d[m][n];
            }
            function knownNames() {
                var seen = {}, out = [];
                (typeof RAW_ITEMS !== 'undefined' ? RAW_ITEMS : []).forEach(function (r) { if (r.item_name && !seen[r.item_name]) { seen[r.item_name] = 1; out.push(r.item_name); } });
                return out;
            }
            function checkExistingName(input) {
                var v = input.value; if (!v) { return; }
                var names = knownNames(), k = key(v), best = null, bestD = 99;
                for (var i = 0; i < names.length; i++) {
                    if (names[i] === v) { return; }
                    var nk = key(names[i]);
                    if (nk === k) { var old = v; input.value = names[i]; flash(input, old, names[i]); return; }
                    if (nums(names[i]) !== nums(v)) { continue; }
                    var a = k.replace(/\d/g, ''), b = nk.replace(/\d/g, '');
                    var dist = lev(a, b), limit = a.length >= 9 ? 2 : (a.length >= 5 ? 1 : 0);
                    if (dist > 0 && dist <= limit && dist < bestD) { best = names[i]; bestD = dist; }
                }
                if (best) {
                    var n = noteBox(input); n.className = 'autofix-note suggest'; n.textContent = 'Did you mean “' + best + '” (already in your list)? ';
                    var btn = document.createElement('button'); btn.type = 'button'; btn.textContent = 'Use it';
                    btn.onclick = function () { input.value = best; n.remove(); input.focus(); };
                    n.appendChild(btn);
                }
            }

            function onBlur(e) {
                var t = e.target, kind = t && t.dataset ? t.dataset.autofix : '';
                if (!kind || kind === 'int' || t.readOnly) { return; }
                var before = t.value, after = before;
                if (kind === 'name')  { after = fixName(before); }
                if (kind === 'unit')  { after = fixUnit(before); }
                if (kind === 'label') { after = fixLabel(before); }
                if (kind === 'batch') { after = fixBatch(before); }
                clearNote(t);
                if (after !== before) { t.value = after; if (collapse(before) !== after || before.trim() !== after) { flash(t, before.trim(), after); } }
                if (t.dataset.suggest) { checkExistingName(t); }
                if (kind === 'unit' || kind === 'label') { if (typeof syncAddLabels === 'function') { syncAddLabels(); } if (typeof calcAddTotal === 'function') { calcAddTotal(); } }
            }
            function onKeydown(e) {
                var t = e.target;
                if (t && t.dataset && t.dataset.autofix === 'int' && ['-', '+', 'e', 'E', '.', ','].indexOf(e.key) !== -1) { e.preventDefault(); }
            }
            function onInput(e) {
                var t = e.target;
                if (!t || !t.dataset || t.dataset.autofix !== 'int') { return; }
                var raw = t.value; if (raw === '') { clearNote(t); return; }
                var n = parseInt(raw, 10), max = parseInt(t.dataset.max, 10) || 0;
                if (isNaN(n) || n < 0) { t.value = ''; return; }
                if (max && n > max) { t.value = max; var nb = noteBox(t); nb.className = 'autofix-note suggest'; nb.textContent = 'Limited to ' + max.toLocaleString() + '. Check for an extra digit.'; clearTimeout(nb._t); nb._t = setTimeout(function () { nb.remove(); }, 5000); }
                else if (String(n) !== raw) { t.value = n; }
            }
            function onIntBlur(e) {
                var t = e.target;
                if (t && t.dataset && t.dataset.autofix === 'int' && t.value !== '' && parseInt(t.min, 10) >= 1 && parseInt(t.value, 10) < parseInt(t.min, 10)) { t.value = ''; }
            }
            document.addEventListener('focusout', onBlur);
            document.addEventListener('focusout', onIntBlur);
            document.addEventListener('keydown', onKeydown);
            document.addEventListener('input', onInput, true);
            return {fixName: fixName, fixUnit: fixUnit, fixLabel: fixLabel, fixBatch: fixBatch};
        })();

        var ItemSuggest = (function () {
            var input, list, active = -1, shown = [];

            function norm(v) { return (v || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
            function dist(a, b) {
                var m = a.length, n = b.length, d = [], i, j;
                for (i = 0; i <= m; i++) { d[i] = [i]; }
                for (j = 1; j <= n; j++) { d[0][j] = j; }
                for (i = 1; i <= m; i++) { for (j = 1; j <= n; j++) {
                    d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
                } }
                return d[m][n];
            }

            function catalog() {
                var map = {};
                (typeof RAW_ITEMS !== 'undefined' ? RAW_ITEMS : []).forEach(function (r) {
                    if (!r.item_name) { return; }
                    var k = norm(r.item_name), e = map[k];
                    if (!e) { e = map[k] = {name: r.item_name, category: r.category, unit: r.unit, brand: '', brands: {}, batches: 0, stock: 0, pack: null, newest: 0}; }
                    e.batches++; e.stock += Number(r.quantity) || 0; if (r.brand_name) { e.brands[norm(r.brand_name)] = r.brand_name; }
                    if (r.id > e.newest) { e.newest = r.id; e.category = r.category || e.category; e.unit = r.unit || e.unit; if (r.brand_name) { e.brand = r.brand_name; } }
                    if (r.units_per_pack && (!e.pack || r.id > e.pack.id)) { e.pack = r; }
                });
                return Object.keys(map).map(function (k) { map[k].key = k; return map[k]; });
            }

            function score(e, q) {
                if (e.key.indexOf(q) === 0) { return 0; }
                var words = e.name.toLowerCase().split(/[^a-z0-9]+/), i;
                for (i = 0; i < words.length; i++) { if (words[i].indexOf(q) === 0) { return 1; } }
                if (e.key.indexOf(q) !== -1) { return 2; }
                if (Object.keys(e.brands).some(function (bk) { return bk.indexOf(q) !== -1; })) { return 3; }
                if (q.length >= 4) {
                    var tol = q.length >= 8 ? 2 : 1;
                    if (dist(q, e.key.slice(0, q.length)) <= tol) { return 4; }
                    for (i = 0; i < words.length; i++) { if (words[i].length >= 4 && dist(q, words[i].slice(0, q.length)) <= tol) { return 4; } }
                }
                return -1;
            }

            function search(text) {
                var q = norm(text); if (!q) { return []; }
                return catalog().map(function (e) { return {e: e, s: score(e, q)}; })
                    .filter(function (x) { return x.s >= 0; })
                    .sort(function (a, b) { return a.s - b.s || a.e.name.localeCompare(b.e.name); })
                    .slice(0, 6).map(function (x) { return x.e; });
            }

            function close() { if (list) { list.style.display = 'none'; } active = -1; }
            function setActive(i) {
                active = i;
                Array.prototype.forEach.call(list.querySelectorAll('.suggest-item'), function (el, j) { el.classList.toggle('active', j === i); if (j === i) { el.scrollIntoView({block: 'nearest'}); } });
            }

            function render() {
                shown = search(input.value);
                if (!shown.length) { close(); return; }
                list.innerHTML = '<div class="suggest-head">Already in your list &mdash; pick one to reuse its details</div>';
                shown.forEach(function (e, i) {
                    var d = document.createElement('div'); d.className = 'suggest-item';
                    var meta = [e.category || 'Uncategorized', fmtNum(e.stock) + ' ' + (e.unit || 'pcs') + ' in stock', e.batches + (e.batches === 1 ? ' batch' : ' batches')];
                    var bl = Object.keys(e.brands).map(function (k) { return e.brands[k]; }); if (bl.length) { meta.push('Brand: ' + bl.slice(0, 3).join(', ')); }
                    d.innerHTML = '<div class="s-name">' + escapeHtml(e.name) + '</div><div class="s-meta">' + escapeHtml(meta.join(' · ')) + '</div>';
                    d.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(e); });
                    d.addEventListener('mousemove', function () { setActive(i); });
                    list.appendChild(d);
                });
                list.style.display = 'block'; active = -1;
            }

            function choose(e) {
                input.value = e.name;
                var sel = document.getElementById('categorySelect');
                if (e.category && Array.prototype.some.call(sel.options, function (o) { return o.value === e.category; })) { sel.value = e.category; generateTrackingCode(); }
                if (e.unit) { document.getElementById('unitInput').value = e.unit; }
                var pk = e.pack || {};
                document.getElementById('add_units_per_pack').value = pk.units_per_pack || '';
                document.getElementById('add_packs_per_box').value = pk.packs_per_box || '';
                if (e.brand && !document.getElementById('brandNameInput').value) { document.getElementById('brandNameInput').value = e.brand; }
                syncAddLabels(); calcAddTotal();
                close();
                var nb = input.parentNode.querySelector('.autofix-note[data-for="itemNameInput"]'); if (nb) { nb.remove(); }
                var n = document.createElement('div'); n.className = 'autofix-note'; n.dataset.for = 'itemNameInput';
                n.textContent = 'Existing item selected — this will be added as a new batch. Category, unit and packing were filled in.';
                input.insertAdjacentElement('afterend', n); setTimeout(function () { n.remove(); }, 6000);
                var next = document.getElementById('add_loose_qty'); if (next && next.offsetParent) { next.focus(); }
            }

            function init() {
                input = document.getElementById('itemNameInput'); if (!input) { return; }
                input.parentNode.style.position = 'relative';
                list = document.createElement('div'); list.className = 'suggest-list'; list.style.display = 'none';
                input.parentNode.appendChild(list);
                input.addEventListener('input', render);
                input.addEventListener('focus', function () { if (input.value) { render(); } });
                input.addEventListener('blur', close);
                input.addEventListener('keydown', function (ev) {
                    if (list.style.display === 'none') { return; }
                    if (ev.key === 'ArrowDown') { ev.preventDefault(); setActive((active + 1) % shown.length); }
                    else if (ev.key === 'ArrowUp') { ev.preventDefault(); setActive((active - 1 + shown.length) % shown.length); }
                    else if (ev.key === 'Enter' && active >= 0) { ev.preventDefault(); choose(shown[active]); }
                    else if (ev.key === 'Escape') { ev.stopPropagation(); close(); }
                });
            }
            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
            return {search: search};
        })();

        function plural(label) { return label + (/(s|x|ch|sh)$/i.test(label) ? 'es' : 's'); }
        function fmtNum(n) { return Number(n).toLocaleString(); }
        function nounCount(n, one, many) { return fmtNum(n) + ' ' + (n === 1 ? one : many); }

        function categoryMode(cat) {
            var c = (cat || '').toLowerCase();
            if (!c) { return ''; }
            if (/medicine/.test(c)) { return 'blister'; }
            if (/suppl|vaccine/.test(c)) { return 'boxes'; }
            return 'single';
        }
        function addUnit() { return (document.getElementById('unitInput').value || '').trim() || 'pcs'; }
        function addUnitWord() { var u = addUnit(); return (u === 'pcs' || u === 'tablets') ? 'tabs' : u; }

        function show(id, on) { var el = document.getElementById(id); if (el) { el.style.display = on ? '' : 'none'; } }
        function enable(id, on) { var el = document.getElementById(id); if (el) { el.disabled = !on; } }

        function setAddMode(mode) {
            var prev = document.getElementById('add_mode').value;
            if (prev !== '' && prev !== mode) {
                ['add_packs_per_box', 'add_units_per_pack', 'add_box_qty', 'add_pack_qty', 'add_loose_qty'].forEach(function (id) { document.getElementById(id).value = ''; });
            }
            document.getElementById('add_mode').value = mode;
            var none = mode === '', single = mode === 'single', boxes = mode === 'boxes', blister = mode === 'blister';
            show('add_pick_cat', none); show('add_pack_body', !none);
            show('add_cfg_wrap', blister || boxes);
            show('add_g_ppb', blister); show('add_g_upp', blister || boxes);
            show('add_g_box_qty', blister || boxes); show('add_g_pack_qty', blister);
            enable('add_packs_per_box', blister); enable('add_units_per_pack', blister || boxes);
            enable('add_pack_label', blister || boxes); enable('add_box_label', blister);
            document.getElementById('add_box_label').value = 'Box';
            document.getElementById('add_pack_label').value = blister ? 'Blister' : 'Box';
            document.getElementById('add_cfg_row').style.gridTemplateColumns = blister ? '1fr 1fr' : '1fr';
            document.getElementById('add_qty_row').style.gridTemplateColumns = blister ? '1fr 1fr 1fr' : (boxes ? '1fr 1fr' : '1fr');
            syncAddLabels();
            calcAddTotal();
        }

        function applyCategoryMode(cat) { setAddMode(categoryMode(cat)); }

        function syncAddLabels() {
            var mode = document.getElementById('add_mode').value, u = addUnit();
            var noun = (u === 'pcs' || u === 'tablets') ? 'Tablets' : u.charAt(0).toUpperCase() + u.slice(1);
            document.getElementById('add_upp_label').innerText = mode === 'blister' ? noun + ' per Blister' : 'Pieces per Box';
            document.getElementById('add_loose_label').innerHTML = mode === 'single' ? 'Quantity' : 'Loose pcs <span style="font-weight: 400;">(optional)</span>';
            document.getElementById('add_qty_title').innerText = mode === 'single' ? 'How many?' : 'How much was delivered?';
        }

        function calcAddTotal() {
            var mode = document.getElementById('add_mode').value, unit = addUnit(), tabs = addUnitWord();
            var perBox  = mode === 'blister' ? (parseInt(document.getElementById('add_packs_per_box').value) || 0) : 0;
            var perPack = parseInt(document.getElementById('add_units_per_pack').value) || 0;
            var boxes   = mode === 'single' || mode === '' ? 0 : (parseInt(document.getElementById('add_box_qty').value) || 0);
            var xb      = mode === 'blister' ? (parseInt(document.getElementById('add_pack_qty').value) || 0) : 0;
            var loose   = parseInt(document.getElementById('add_loose_qty').value) || 0;
            var total = loose, parts = [], hint = [], warn = [];

            if (mode === 'blister') {
                if (boxes > 0) {
                    if (perBox > 0 && perPack > 0) { total += boxes * perBox * perPack; parts.push(nounCount(boxes, 'box', 'boxes') + ' × ' + nounCount(perBox, 'blister', 'blisters') + ' × ' + fmtNum(perPack) + ' ' + tabs); }
                    else { hint.push('enter Blisters per Box and ' + document.getElementById('add_upp_label').innerText + ' to count the boxes'); }
                }
                if (xb > 0) {
                    if (perPack > 0) { total += xb * perPack; parts.push(nounCount(xb, 'blister', 'blisters') + ' × ' + fmtNum(perPack) + ' ' + tabs); }
                    else { hint.push('enter ' + document.getElementById('add_upp_label').innerText + ' to count the extra blisters'); }
                }
                if (loose > 0) { parts.push(fmtNum(loose) + ' loose'); }
                if (perPack > 0 && loose >= perPack) { warn.push('Loose pcs (' + fmtNum(loose) + ') fills a whole blister or more. Did you mean to enter it under Extra Blisters?'); }
                if (perBox > 0 && xb >= perBox) { warn.push('Extra Blisters (' + fmtNum(xb) + ') fills a whole box or more. Did you mean to enter it under Boxes?'); }
            } else if (mode === 'boxes') {
                if (boxes > 0) {
                    if (perPack > 0) { total += boxes * perPack; parts.push(nounCount(boxes, 'box', 'boxes') + ' × ' + fmtNum(perPack) + ' ' + unit); }
                    else { hint.push('enter Pieces per Box to count the boxes'); }
                }
                if (loose > 0) { parts.push(fmtNum(loose) + ' loose'); }
                if (perPack > 0 && loose >= perPack) { warn.push('Loose pcs (' + fmtNum(loose) + ') fills a whole box or more. Did you mean to enter it under Boxes?'); }
            }
            if (total >= 100000) { warn.push('Total is very large (' + fmtNum(total) + '). Check for an extra digit.'); }

            document.getElementById('add_total_qty').value = total;
            document.getElementById('add_total_sentence').innerHTML = (mode === 'single' || mode === '' || !parts.length)
                ? 'Total: <strong>' + fmtNum(total) + ' ' + escapeHtml(unit) + '</strong>'
                : 'Total: ' + escapeHtml(parts.join(' + ')) + ' = <strong>' + fmtNum(total) + ' ' + escapeHtml(unit) + '</strong>';
            var h = document.getElementById('add_total_hint');
            h.style.display = hint.length ? 'block' : 'none';
            h.innerText = hint.length ? 'Not counted yet: ' + hint.join('; ') + '.' : '';
            var w = document.getElementById('add_total_warn');
            w.style.display = warn.length ? 'block' : 'none';
            w.innerText = warn.join(' ');
        }

        function resetAddItemForm() {
            document.getElementById('itemNameInput').value = '';
            document.getElementById('brandNameInput').value = '';
            document.getElementById('categorySelect').value = '';
            document.getElementById('trackingCodeInput').value = '';
            document.getElementById('unitInput').value = 'pcs';
            document.getElementById('add_packs_per_box').value = '';
            document.getElementById('add_units_per_pack').value = '';
            document.getElementById('add_box_qty').value = '';
            document.getElementById('add_pack_qty').value = '';
            document.getElementById('add_loose_qty').value = '';
            document.getElementById('add_total_qty').value = 0;
            document.getElementById('add_total_hint').style.display = 'none';
            setAddMode('');
            document.querySelector('input[name="batch_type"][value="auto"]').checked = true;
            toggleBatchInput();
        }

        function openAddItemModalBlank() {
            resetAddItemForm();
            document.getElementById('addItemModalTitle').innerText = 'Add New Inventory Batch';
            openModal('addItemModal');
        }

        function openAddBatchModal(prefill) {
            resetAddItemForm();
            document.getElementById('itemNameInput').value = prefill.item_name || '';
            document.getElementById('unitInput').value = prefill.unit || 'pcs';
            if (prefill.category) {
                document.getElementById('categorySelect').value = prefill.category;
                generateTrackingCode();
            }
            
            if (prefill.units_per_pack) document.getElementById('add_units_per_pack').value = prefill.units_per_pack;
            if (prefill.packs_per_box) document.getElementById('add_packs_per_box').value = prefill.packs_per_box;
            syncAddLabels();
            calcAddTotal();
            document.getElementById('addItemModalTitle').innerText = 'Add Batch — ' + (prefill.item_name || '');
            document.getElementById('brandNameInput').focus();
            openModal('addItemModal');
        }

        function openUpdateModal(batch, preset) {
            document.getElementById('modalItemId').value = batch.id;
            document.getElementById('modalItemNameHidden').value = batch.item_name + (batch.brand_name ? ' (' + batch.brand_name + ')' : '');
            document.getElementById('modalItemName').innerText = batch.item_name + (batch.brand_name ? ' — ' + batch.brand_name : '');
            document.getElementById('modalCurrentQty').innerText = batch.quantity;
            document.getElementById('modalUnit').innerText = batch.unit;
            document.getElementById('modalBatchNo').innerText = batch.tracking_code || 'N/A';

            var isExpired = batch.expiry_date && batch.expiry_date < new Date().toISOString().slice(0, 10);
            document.getElementById('stockAction').value = preset || (isExpired && batch.quantity > 0 ? 'dispose' : 'edit_expiry');
            window._disposeBatch = batch;
            var guess = {liquid: /\b(ml|l|liter|litre|bottles?|vials?|ampou?les?|sachets?)\b/i, capsule: /\b(caps?|capsules?|tabs?|tablets?|pills?)\b/i};
            document.getElementById('disposeForm').value = guess.liquid.test(batch.unit || '') ? 'liquid' : (guess.capsule.test(batch.unit || '') ? 'capsule' : 'piece');
            document.getElementById('disposeReason').value = isExpired ? 'expired' : 'damaged';
            document.getElementById('disposeDate').value = new Date().toISOString().slice(0, 10);
            ['disposeWitness', 'disposeRemarks'].forEach(function (id) { document.getElementById(id).value = ''; });
            document.getElementById('adjustAmountInput').max = batch.quantity;

            window._updUnitsPerPack = batch.units_per_pack || 0;
            window._updPacksPerBox = batch.packs_per_box || 0;
            document.getElementById('upd_box_qty_label').innerText = (batch.box_label || 'Box') + 's';
            document.getElementById('upd_pack_qty_label').innerText = (batch.pack_label || 'Pack') + 's';
            document.getElementById('upd_box_qty').value = '';
            document.getElementById('upd_pack_qty').value = '';
            document.getElementById('upd_loose_qty').value = '';
            document.getElementById('adjustAmountInput').value = '';
            document.getElementById('upd_total_readout').innerHTML = 'Disposes: <strong>0</strong> base units';

            toggleStockAction(); 
            openModal('updateStockModal');
        }

        function openEditBatchModal(batch) {
            document.getElementById('editItemId').value = batch.id;
            document.getElementById('editModalSku').innerText = batch.tracking_code || 'N/A';
            document.getElementById('editItemNameInput').value = batch.item_name || '';
            document.getElementById('editBrandNameInput').value = batch.brand_name || '';
            document.getElementById('editCategorySelect').value = batch.category || '';
            document.getElementById('editTrackingCodeInput').value = batch.tracking_code || '';
            document.getElementById('editBatchNumberInput').value = batch.batch_number || '';
            document.getElementById('editExpiryDateInput').value = batch.expiry_date || '';
            document.getElementById('editUnitInput').value = batch.unit || '';
            document.getElementById('editBoxLabel').value = batch.box_label || 'Box';
            document.getElementById('editPackLabel').value = batch.pack_label || 'Blister Pack';
            document.getElementById('editPacksPerBox').value = batch.packs_per_box || '';
            document.getElementById('editUnitsPerPack').value = batch.units_per_pack || '';
            openModal('editBatchModal');
        }

        async function archiveBatchQuick(id, name) {
            if (!(await mh_confirm('Archive "' + name + '"? It will be hidden from the active Supply Masterlist.', 'Archive item?', 'danger', 'Archive'))) return;
            document.getElementById('quickArchiveItemId').value = id;
            document.getElementById('quickArchiveItemName').value = name;
            document.getElementById('quickArchiveForm').submit();
        }

        <?php if($success_msg || $error_msg): ?>
        window.addEventListener('DOMContentLoaded', function() {
            <?php if($success_msg): ?>
            document.querySelector('.alert-success')?.scrollIntoView({behavior:'smooth', block:'center'});
            <?php endif; ?>
        });
        <?php endif; ?>
    </script>
<?php mh_layout_end(); ?>