<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';

// Feature toggles: View Stock (read) or Add/Edit Medicine (write) opens this page.
// (Dispensing lives on dispense_medicine.php under 'dispense_medicine'.)
// "Supply / Stock Masterlist" (can_manage_supply_masterlist) is the ONLY thing that opens this page.
// A user who has Dispensing / Dispensing Log History but not the masterlist is sent straight to
// dispense_medicine.php instead of seeing stock controls.
if (!mh_has_capability('can_manage_supply_masterlist')) {
    if (mh_has_any_capability(['can_manage_dispensing', 'can_view_dispensing_history'])) {
        header('Location: dispense_medicine.php');
        exit();
    }
    mh_deny_access();
}
// Stock-changing controls (+ Add New Batch, Restock, Edit/Archive batch) need the 'add_medicine' toggle on top.
$can_edit_stock = mh_has_permission('add_medicine');

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('inventory');

$system_name = $system_name ?? mh_setting('system_name');

$inv_categories = mh_inventory_categories($conn);   // Inventory Categories masterlist (database)

$success_msg = ""; $error_msg = "";

// Every stock-changing form needs 'add_medicine' (checked on submit, not just in the UI)
if ((isset($_POST['add_item']) || isset($_POST['edit_item']) || isset($_POST['update_stock'])) && !$can_edit_stock) {
    mh_deny_access();
}

/** Shared field cleanup for add / edit item (nothing here touches SQL; values are bound below). */
function mh_inv_read_item_fields(): array
{
    $t = function ($k) { return trim((string) ($_POST[$k] ?? '')); };
    $nullable_int = function ($k) {
        return (isset($_POST[$k]) && $_POST[$k] !== '') ? (int) $_POST[$k] : null;
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
    $quantity = (int) ($_POST['quantity'] ?? 0);

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

    } elseif($action === 'add') {
        $amount = (int) ($_POST['amount'] ?? 0);
        $st = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
        $st->bind_param('ii', $amount, $item_id);
        $ok = $st->execute(); $st->close();
        if($ok) {
            $success_msg = "Stock updated successfully!";
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Updated Stock", "Restocked $amount units of $item_name.");
        } else {
            $error_msg = "Could not update the stock. Please try again.";
        }
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
$NEAR_EXPIRY_DAYS = 30;
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
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supply Masterlist - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .stats-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        @media (max-width: 1100px) { .stats-grid-4 { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .stats-grid-4 { grid-template-columns: 1fr; } }

        .stat-card.clickable { cursor: pointer; text-align: left; flex-direction: row; align-items: center; gap: 14px; padding: 20px; }
        .stat-card.clickable .stat-icon-badge { margin-bottom: 0; flex-shrink: 0; }
        .stat-card.clickable .stat-body h3 { margin: 0 0 6px 0; }
        .stat-card.clickable .stat-body .number { font-size: 1.9rem; }
        .stat-card.clickable.active-quick { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 76, 161, 175), 0.18); }

        /* --- Filter toolbar --- */
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

        /* --- Grouped table --- */
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

        /* .fefo-tag, .tracking-chip and the .packaging-* calculator classes
           are now canonical, shared components declared once in
           assets/css/admin_theme.css (section 6f/6g) so
           dispense_medicine.php can reuse the exact same look. */

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
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 25px;">
                <div>
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
                <div class="stat-card clickable" data-quick="expiry:30" style="border-top-color: #c2410c;">
                    <div class="stat-icon-badge" style="color:#c2410c; background:rgba(194,65,12,0.12);"><?php echo mh_icon('clock'); ?></div>
                    <div class="stat-body">
                        <h3>Near Expiry (30d)</h3>
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
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTbody">
                        <tr class="empty-state"><td colspan="6"><?php echo mh_icon('package'); ?><br>Loading masterlist...</td></tr>
                    </tbody>
                </table>
                </div>
            </div>

        </div>
    </div>

    <?php if ($can_edit_stock): /* stock-editing modals exist only for roles/accounts holding add_medicine */ ?>
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
                        <input type="text" name="item_name" id="itemNameInput" required placeholder="e.g., Amoxicillin 500mg" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    </div>
                    <div class="form-group">
                        <label>Brand Name (Optional)</label>
                        <input type="text" name="brand_name" id="brandNameInput" placeholder="e.g., Clavamox" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
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
                        <input type="text" name="batch_number" id="batchNumberInput" value="LOT-<?php echo date('ymd') . rand(10, 99); ?>" readonly style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-family: monospace; background: #e2e8f0; color: #475569; outline: none;">
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="expiryDateInput" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    </div>
                </div>

                <div class="form-group packaging-config-box" style="grid-column: 1 / -1;">
                    <label class="section-label"><?php echo mh_icon('package'); ?> Packaging Structure (Box &rarr; Pack &rarr; Base Unit)</label>
                    <p style="margin: 0 0 10px 0; font-size: 0.78rem; color: var(--text-muted);">Define how this batch is packaged. Leave the multipliers blank if this item is only ever tracked in base units (e.g. a single vial or a piece of equipment).</p>
                    <div class="packaging-grid" style="margin-bottom: 12px;">
                        <div class="form-group">
                            <label>Box Label</label>
                            <input type="text" name="box_label" id="add_box_label" placeholder="e.g., Box, Carton" value="Box" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Packs per Box</label>
                            <input type="number" name="packs_per_box" id="add_packs_per_box" min="1" placeholder="e.g., 10" onkeyup="calcAddTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Pack Label</label>
                            <input type="text" name="pack_label" id="add_pack_label" placeholder="e.g., Blister Pack, Banig" value="Blister Pack" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Base Units per Pack</label>
                            <input type="number" name="units_per_pack" id="add_units_per_pack" min="1" placeholder="e.g., 10" onkeyup="calcAddTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                    </div>

                    <label class="section-label" style="margin-top: 4px;"><?php echo mh_icon('calculator'); ?> Enter Delivered Stock (auto-calculates total base units)</label>
                    <div class="packaging-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                        <div class="form-group">
                            <label id="add_box_qty_label">No. of Boxes</label>
                            <input type="number" id="add_box_qty" min="0" placeholder="e.g., 5" onkeyup="calcAddTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label id="add_pack_qty_label">No. of Packs</label>
                            <input type="number" id="add_pack_qty" min="0" placeholder="e.g., 3" onkeyup="calcAddTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Loose Base Units</label>
                            <input type="number" id="add_loose_qty" min="0" placeholder="e.g., 4" onkeyup="calcAddTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                    </div>
                    <div class="packaging-total-readout" id="add_total_readout">Total: <strong>0</strong> base units</div>
                </div>

                <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label>Total Initial Quantity (Base Units) <span style="color:red;">*</span></label>
                        <input type="number" name="quantity" id="add_total_qty" required value="0" min="0" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-weight: bold; color: var(--heading-color, #0f172a); background: var(--bg-hover);">
                    </div>
                    <div class="form-group">
                        <label>Base Unit <span style="color:red;">*</span></label>
                        <input type="text" name="unit" id="unitInput" required placeholder="e.g., pcs, capsules" value="pcs" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                </div>

                <button type="submit" name="add_item" data-confirm="Add this item to the Supply Masterlist?" data-confirm-title="Add item?" data-confirm-type="info" data-confirm-ok="Add item" class="btn-submit" style="width: 100%; padding: 15px; font-size: 1.1rem; background: #3b82f6; border:none; color:white; border-radius:6px; cursor:pointer; font-weight:bold;">Register Item Batch</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="updateStockModal">
        <div class="modal-content" style="max-width: 420px; border-top: 5px solid #0369a1;">
            <span class="close-btn" onclick="closeModal('updateStockModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 15px;">
                <h2 style="margin:0; color:#0369a1;">Manage Stock</h2>
                <h3 id="modalItemName" style="margin:5px 0 0 0; color:var(--heading-color, #1e293b); font-size: 1.1rem;">Item Name</h3>
                <p style="margin:5px 0 0 0; font-size: 0.85rem; color: var(--text-muted);">SKU: <span id="modalBatchNo" style="font-family: monospace; font-weight: bold; color:#1d4ed8;">000</span></p>
                <p style="margin:5px 0 0 0; color:var(--text-muted); font-size: 0.9rem;">Current Stock: <strong id="modalCurrentQty">0</strong> <span id="modalUnit">pcs</span></p>
            </div>
            
            <form method="POST">
                <input type="hidden" name="item_id" id="modalItemId">
                <input type="hidden" name="item_name_hidden" id="modalItemNameHidden">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Action <span style="color:red;">*</span></label>
                    <select name="stock_action" id="stockAction" onchange="toggleStockAction()" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-weight: bold;">
                        <option value="add" style="color: #10b981;">[+] Restock / Add Delivery</option>
                        <option value="edit_expiry" style="color: #f59e0b;">Update Expiry Date</option>
                        <option value="archive" style="color: #b91c1c;">Archive / Remove Item</option>
                    </select>
                </div>

                <div class="form-group" id="adjustAmountGroup" style="margin-bottom: 20px;">
                    <label>Amount to Restock (Base Unit) <span style="color:red;">*</span></label>
                    <input type="number" name="amount" id="adjustAmountInput" required min="1" placeholder="Enter total base units..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-size: 1.1rem; margin-bottom: 10px;">
                    
                    <div class="packaging-config-box" style="margin-bottom: 0;">
                        <label style="font-size: 0.8rem; color: var(--primary); margin-bottom: 8px; display: block; font-weight: bold;">Optional Calculator</label>
                        <div class="packaging-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                            <div class="form-group">
                                <label id="upd_box_qty_label" style="font-size:0.72rem;">Boxes</label>
                                <input type="number" id="upd_box_qty" placeholder="0" min="0" onkeyup="calcUpdTotal()" style="width: 100%; padding: 6px; border-radius: 4px; border: 1px solid var(--border-color, #cbd5e1); font-size: 0.85rem; box-sizing: border-box;">
                            </div>
                            <div class="form-group">
                                <label id="upd_pack_qty_label" style="font-size:0.72rem;">Packs</label>
                                <input type="number" id="upd_pack_qty" placeholder="0" min="0" onkeyup="calcUpdTotal()" style="width: 100%; padding: 6px; border-radius: 4px; border: 1px solid var(--border-color, #cbd5e1); font-size: 0.85rem; box-sizing: border-box;">
                            </div>
                            <div class="form-group">
                                <label style="font-size:0.72rem;">Loose Units</label>
                                <input type="number" id="upd_loose_qty" placeholder="0" min="0" onkeyup="calcUpdTotal()" style="width: 100%; padding: 6px; border-radius: 4px; border: 1px solid var(--border-color, #cbd5e1); font-size: 0.85rem; box-sizing: border-box;">
                            </div>
                        </div>
                        <div class="packaging-total-readout" id="upd_total_readout" style="margin-top: 8px;">Adds: <strong>0</strong> base units</div>
                    </div>
                </div>

                <div class="form-group" id="editExpiryGroup" style="display: none; margin-bottom: 20px;">
                    <label>New Expiry Date <span style="color:red;">*</span></label>
                    <input type="date" name="new_expiry_date" id="newExpiryInput" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-size: 1.1rem;">
                </div>

                <div id="archiveWarningGroup" style="display: none; margin-bottom: 20px; background: #fee2e2; border: 1px solid #fca5a5; padding: 15px; border-radius: 8px; color: #991b1b; font-size: 0.9rem;">
                    <strong>Warning:</strong> This will move the item to the Archives and hide it from the active masterlist.
                </div>

                <button type="submit" name="update_stock" id="confirmBtn" data-confirm="Apply this stock update?" data-confirm-title="Update stock?" data-confirm-type="warning" data-confirm-ok="Apply" class="btn-submit" style="width: 100%; padding: 12px; font-size: 1rem; background: #0369a1; border:none; color:white; border-radius:6px; cursor:pointer; font-weight:bold;">Confirm Update</button>
            </form>
        </div>
    </div>

    <!-- Edit Batch Modal: full detail edit (brand, category, tracking, lot,
         expiry, base unit and packaging structure) for a single existing
         batch row. Stock levels are NOT edited here - use Manage Stock. -->
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
                        <input type="text" name="item_name" id="editItemNameInput" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    <div class="form-group">
                        <label>Brand Name (Optional)</label>
                        <input type="text" name="brand_name" id="editBrandNameInput" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
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
                        <input type="date" name="expiry_date" id="editExpiryDateInput" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                </div>

                <div class="form-group packaging-config-box" style="grid-column: 1 / -1;">
                    <label class="section-label"><?php echo mh_icon('package'); ?> Packaging Structure</label>
                    <div class="packaging-grid">
                        <div class="form-group">
                            <label>Box Label</label>
                            <input type="text" name="box_label" id="editBoxLabel" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Packs per Box</label>
                            <input type="number" name="packs_per_box" id="editPacksPerBox" min="1" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Pack Label</label>
                            <input type="text" name="pack_label" id="editPackLabel" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                        <div class="form-group">
                            <label>Base Units per Pack</label>
                            <input type="number" name="units_per_pack" id="editUnitsPerPack" min="1" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Base Unit <span style="color:red;">*</span></label>
                    <input type="text" name="unit" id="editUnitInput" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                </div>

                <button type="submit" name="edit_item" data-confirm="Save changes to this item?" data-confirm-title="Save changes?" data-confirm-type="warning" data-confirm-ok="Save" class="btn-submit" style="width: 100%; padding: 15px; font-size: 1.1rem; background: var(--primary); border:none; color:white; border-radius:6px; cursor:pointer; font-weight:bold;">Save Batch Details</button>
            </form>
        </div>
    </div>

    <!-- Quick-archive confirmation form, submitted programmatically by
         archiveBatchQuick() so an "Archive" shortcut can live directly on
         each batch row without opening the full Manage Stock modal. -->
    <form method="POST" id="quickArchiveForm" style="display:none;">
        <input type="hidden" name="item_id" id="quickArchiveItemId">
        <input type="hidden" name="item_name_hidden" id="quickArchiveItemName">
        <input type="hidden" name="stock_action" value="archive">
        <input type="hidden" name="update_stock" value="1">
    </form>

    <?php endif; // can_edit_stock ?>

    <script>
        // Dispense shortcut is only offered to roles holding the 'dispense_medicine' toggle.
        const MH_CAN_EDIT_STOCK = <?php echo $can_edit_stock ? 'true' : 'false'; ?>;   // 'add_medicine' toggle
        const MH_CAN_DISPENSE = <?php echo mh_has_permission('dispense_medicine') ? 'true' : 'false'; ?>;
        /* =========================================================
           DATA (rendered by PHP once on page load)
           ========================================================= */
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

        /* ---------------------------------------------------------
           Helpers
           --------------------------------------------------------- */
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
            // Uses THIS item's own recorded packaging structure - no more
            // hardcoded "1 box = 100 pcs" guess. Items with no packaging
            // structure recorded (units_per_pack not set) show nothing extra.
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

        /* ---------------------------------------------------------
           Per-batch derived status (mirrors PHP logic exactly)
           --------------------------------------------------------- */
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

            // Near-expiry gets its own canonical status pill, but only when
            // nothing more urgent (out of stock / expired / low stock) is
            // already claiming the badge for this batch.
            if (isNear && status === 'in') {
                status = 'near'; label = 'Near Expiry'; statusClass = 'status-nearexpiry';
            }

            return Object.assign({}, item, {
                days, status, label, statusClass, isExpired, isNear
            });
        }

        const ANNOTATED_ITEMS = RAW_ITEMS.map(annotateBatch);

        // Fast id -> batch lookup so row action buttons can hand the full
        // batch object (packaging config included) to the modal openers
        // without re-serializing it into a data-* attribute soup.
        const BATCH_BY_ID = {};
        ANNOTATED_ITEMS.forEach(b => { BATCH_BY_ID[b.id] = b; });

        /* ---------------------------------------------------------
           Filtering (batch level) then grouping
           --------------------------------------------------------- */
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

        /* ---------------------------------------------------------
           Rendering
           --------------------------------------------------------- */
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
                    <td>
                        <div class="action-btn-group">
                            ${MH_CAN_EDIT_STOCK ? `<button class="btn-manage" data-id="${b.id}" onclick="openUpdateModal(BATCH_BY_ID[this.dataset.id])">${mhIconSvg('package')} Restock</button>
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
                    <td onclick="event.stopPropagation();">
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
                                        <th>Action</th>
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

        /* ---------------------------------------------------------
           Filter chips
           --------------------------------------------------------- */
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

        /* ---------------------------------------------------------
           Quick-filter stat cards
           --------------------------------------------------------- */
        function updateQuickCardState() {
            document.querySelectorAll('[data-quick]').forEach(card => {
                const q = card.dataset.quick;
                let active = false;
                if (q === 'stock:low') active = state.stockStatus === 'low';
                if (q === 'expiry:30') active = state.expiryWindow === '30';
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
                if (q === 'expiry:30') {
                    state.expiryWindow = state.expiryWindow === '30' ? '' : '30';
                    document.getElementById('expiryFilter').value = state.expiryWindow;
                }
                if (q === 'expiry:expired') {
                    state.expiryWindow = state.expiryWindow === 'expired' ? '' : 'expired';
                    document.getElementById('expiryFilter').value = state.expiryWindow;
                }
                render();
            });
        });

        /* ---------------------------------------------------------
           Toggle group expand / collapse
           --------------------------------------------------------- */
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

        /* =========================================================
           Add / Update modal helpers (existing behaviour, extended)
           ========================================================= */
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
            if(!cat) { input.value = ''; return; }
            
            const prefix = cat.charAt(0).toUpperCase();
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            const dateStr = `${yyyy}${mm}${dd}`;
            const randomNum = Math.floor(1000 + Math.random() * 9000);
            
            input.value = `${prefix}${dateStr}-${randomNum}`;
        }

        function toggleStockAction() {
            var action = document.getElementById('stockAction').value;
            var amountGroup = document.getElementById('adjustAmountGroup');
            var expiryGroup = document.getElementById('editExpiryGroup');
            var archiveGroup = document.getElementById('archiveWarningGroup');
            
            var amountInput = document.getElementById('adjustAmountInput');
            var expiryInput = document.getElementById('newExpiryInput');
            var confirmBtn = document.getElementById('confirmBtn');

            amountGroup.style.display = 'none';
            expiryGroup.style.display = 'none';
            archiveGroup.style.display = 'none';
            amountInput.removeAttribute('required');
            expiryInput.removeAttribute('required');
            confirmBtn.style.background = '#0369a1';
            confirmBtn.innerText = "Confirm Update";
            var MSG = {add: ['Add this delivery to the stock?', 'Restock item?', 'warning', 'Add stock'],
                       edit_expiry: ['Change the expiry date of this batch?', 'Update expiry?', 'warning', 'Update'],
                       archive: ['Archive this item? It will be hidden from the active Supply Masterlist.', 'Archive item?', 'danger', 'Archive']};
            var m = MSG[action] || MSG.add;
            confirmBtn.dataset.confirm = m[0]; confirmBtn.dataset.confirmTitle = m[1];
            confirmBtn.dataset.confirmType = m[2]; confirmBtn.dataset.confirmOk = m[3];

            if(action === 'edit_expiry') {
                expiryGroup.style.display = 'block';
                expiryInput.setAttribute('required', 'required');
            } else if (action === 'archive') {
                archiveGroup.style.display = 'block';
                confirmBtn.style.background = '#b91c1c';
                confirmBtn.innerText = "Yes, Archive Item";
            } else {
                amountGroup.style.display = 'block';
                amountInput.setAttribute('required', 'required');
            }
        }

        /* ---------------------------------------------------------
           Smart unit conversion calculators
           Boxes -> Packs (Blister/Banig) -> Base Units, using whatever
           multipliers/labels were entered for THIS batch (no more
           hardcoded "1 box = 100 pcs" assumption).
           --------------------------------------------------------- */
        function calcAddTotal() {
            var packsPerBox = parseInt(document.getElementById('add_packs_per_box').value) || 0;
            var unitsPerPack = parseInt(document.getElementById('add_units_per_pack').value) || 0;
            var boxQty = parseInt(document.getElementById('add_box_qty').value) || 0;
            var packQty = parseInt(document.getElementById('add_pack_qty').value) || 0;
            var looseQty = parseInt(document.getElementById('add_loose_qty').value) || 0;

            var boxLabel = document.getElementById('add_box_label').value.trim() || 'Box';
            var packLabel = document.getElementById('add_pack_label').value.trim() || 'Pack';
            document.getElementById('add_box_qty_label').innerText = 'No. of ' + boxLabel + 's';
            document.getElementById('add_pack_qty_label').innerText = 'No. of ' + packLabel + 's';

            var total = looseQty;
            if (unitsPerPack > 0) {
                total += packQty * unitsPerPack;
                if (packsPerBox > 0) total += boxQty * packsPerBox * unitsPerPack;
            }
            document.getElementById('add_total_qty').value = total;
            document.getElementById('add_total_readout').innerHTML = 'Total: <strong>' + total.toLocaleString() + '</strong> base units';
        }

        function calcUpdTotal() {
            // Packaging config for the batch currently open in the Manage
            // Stock modal is stashed on window by openUpdateModal() below.
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
            document.getElementById('adjustAmountInput').value = total;
            document.getElementById('upd_total_readout').innerHTML = 'Adds: <strong>' + total.toLocaleString() + '</strong> base units';
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function resetAddItemForm() {
            document.getElementById('itemNameInput').value = '';
            document.getElementById('brandNameInput').value = '';
            document.getElementById('categorySelect').value = '';
            document.getElementById('trackingCodeInput').value = '';
            document.getElementById('unitInput').value = 'pcs';
            document.getElementById('add_box_label').value = 'Box';
            document.getElementById('add_pack_label').value = 'Blister Pack';
            document.getElementById('add_packs_per_box').value = '';
            document.getElementById('add_units_per_pack').value = '';
            document.getElementById('add_box_qty').value = '';
            document.getElementById('add_pack_qty').value = '';
            document.getElementById('add_loose_qty').value = '';
            document.getElementById('add_total_qty').value = 0;
            document.getElementById('add_total_readout').innerHTML = 'Total: <strong>0</strong> base units';
            document.getElementById('add_box_qty_label').innerText = 'No. of Boxes';
            document.getElementById('add_pack_qty_label').innerText = 'No. of Packs';
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
            // Carry over the last-known packaging structure for this generic
            // (if any batch of it has one) so staff don't have to redefine
            // "1 box = 10 blister packs = 100 tablets" every single delivery.
            if (prefill.units_per_pack) document.getElementById('add_units_per_pack').value = prefill.units_per_pack;
            if (prefill.packs_per_box) document.getElementById('add_packs_per_box').value = prefill.packs_per_box;
            if (prefill.pack_label) document.getElementById('add_pack_label').value = prefill.pack_label;
            if (prefill.box_label) document.getElementById('add_box_label').value = prefill.box_label;
            calcAddTotal();
            document.getElementById('addItemModalTitle').innerText = 'Add Batch — ' + (prefill.item_name || '');
            document.getElementById('brandNameInput').focus();
            openModal('addItemModal');
        }

        function openUpdateModal(batch) {
            document.getElementById('modalItemId').value = batch.id;
            document.getElementById('modalItemNameHidden').value = batch.item_name + (batch.brand_name ? ' (' + batch.brand_name + ')' : '');
            document.getElementById('modalItemName').innerText = batch.item_name + (batch.brand_name ? ' — ' + batch.brand_name : '');
            document.getElementById('modalCurrentQty').innerText = batch.quantity;
            document.getElementById('modalUnit').innerText = batch.unit;
            document.getElementById('modalBatchNo').innerText = batch.tracking_code || 'N/A';

            document.getElementById('stockAction').value = 'add';

            window._updUnitsPerPack = batch.units_per_pack || 0;
            window._updPacksPerBox = batch.packs_per_box || 0;
            document.getElementById('upd_box_qty_label').innerText = (batch.box_label || 'Box') + 's';
            document.getElementById('upd_pack_qty_label').innerText = (batch.pack_label || 'Pack') + 's';
            document.getElementById('upd_box_qty').value = '';
            document.getElementById('upd_pack_qty').value = '';
            document.getElementById('upd_loose_qty').value = '';
            document.getElementById('adjustAmountInput').value = '';
            document.getElementById('upd_total_readout').innerHTML = 'Adds: <strong>0</strong> base units';

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
</body>
</html>
