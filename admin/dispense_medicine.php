<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);   // errors go to the log, never to the browser
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/queue_helper.php';

// Granular: issuing medicine and reading the dispensing log are separate
// permissions (includes/auth.php). Either one opens the page.
require_permission(['dispense_medicine', 'view_dispensing_history']);   // page load

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('inventory');

$can_dispense = mh_has_permission('dispense_medicine');
$can_view_log = mh_has_permission('view_dispensing_history');   // Dispensing Records - NOT implied by dispensing

$system_name = $system_name ?? mh_setting('system_name');

$success_msg = ""; $error_msg = "";
$old = ['inventory_id' => 0, 'patient_id' => 0, 'quantity' => ''];   // posted values, restored after a failed submit

// Post/Redirect/Get: a successful dispense redirects, so refreshing the page can NEVER dispense twice.
if (!empty($_SESSION['dispense_flash'])) {
    $success_msg = (string) $_SESSION['dispense_flash'];
    unset($_SESSION['dispense_flash']);
}

/** Rows of the Dispensing Records table (latest 100 matches). Every word typed must match something. */
function mh_render_dispense_rows(mysqli $conn, $q)
{
    $sql = "SELECT d.dispense_date, d.quantity, d.is_fefo_override,
                   i.item_name, i.brand_name, i.unit, i.batch_number, i.tracking_code,
                   p.id AS patient_id, p.first_name AS p_first, p.last_name AS p_last,
                   s.first_name AS s_first, s.last_name AS s_last
              FROM dispensations d
              JOIN inventory i ON d.inventory_id = i.id
              JOIN patients p ON d.patient_id = p.id
              LEFT JOIN staff s ON d.dispensed_by = s.id";
    $where = []; $types = ''; $params = [];
    $terms = preg_split('/\s+/', trim((string) $q), -1, PREG_SPLIT_NO_EMPTY);
    foreach (array_slice($terms, 0, 6) as $t) {
        $like = '%' . addcslashes($t, '%_\\') . '%';
        $id = ltrim($t, '#');                      // "#4" finds patient ID 4
        $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR i.item_name LIKE ? OR i.brand_name LIKE ? OR i.batch_number LIKE ?
                     OR i.tracking_code LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?
                     OR DATE_FORMAT(d.dispense_date, '%b %d, %Y %l:%i %p') LIKE ? OR CAST(p.id AS CHAR) = ? OR CAST(d.quantity AS CHAR) = ?)";
        $types .= str_repeat('s', 11);
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $id, $id);
    }
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY d.dispense_date DESC, d.id DESC LIMIT 100';

    $stmt = $conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();

    ob_start();
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $p_initials = mb_strtoupper(mb_substr($row['p_first'], 0, 1) . mb_substr($row['p_last'], 0, 1));
            $staff_name = $row['s_first'] ? htmlspecialchars($row['s_first'] . ' ' . $row['s_last']) : 'System';
            ?>
            <tr>
                <td style="font-size: 0.85rem; color: var(--text-main);">
                    <strong style="color: var(--heading-color, #1e293b); display: block;"><?php echo date("M d, Y", strtotime($row['dispense_date'])); ?></strong>
                    <span style="color: var(--text-muted);"><?php echo date("g:i A", strtotime($row['dispense_date'])); ?></span>
                </td>
                <td>
                    <div class="patient-badge">
                        <div class="avatar-circle"><?php echo htmlspecialchars($p_initials); ?></div>
                        <div>
                            <span class="patient-badge-name"><?php echo htmlspecialchars($row['p_last'] . ', ' . $row['p_first']); ?></span>
                            <span class="patient-badge-id">ID: <?php echo (int) $row['patient_id']; ?></span>
                        </div>
                    </div>
                </td>
                <td>
                    <strong style="color: var(--heading-color, #0f172a); font-size: 1rem;"><?php echo htmlspecialchars($row['item_name']); ?></strong><br>
                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['brand_name'] ?: 'Generic'); ?></span>
                    <div class="tracking-chip" style="margin-top: 4px; padding: 3px 8px;">
                        <span class="sku" style="font-size:0.72rem;">SKU: <?php echo htmlspecialchars($row['tracking_code'] ?: 'N/A'); ?></span>
                        <span class="lot" style="font-size:0.72rem;"> &bull; Lot: <?php echo htmlspecialchars($row['batch_number'] ?: 'N/A'); ?></span>
                    </div>
                    <?php if ($row['is_fefo_override']): ?>
                        <div class="near-expiry-pill" style="margin-top:4px;" title="Staff dispensed a later-expiring batch while an earlier one still had stock"><?php echo mh_icon('alert-triangle'); ?> FEFO override</div>
                    <?php endif; ?>
                </td>
                <td><span class="staff-chip"><?php echo mh_icon('user'); ?> <?php echo $staff_name; ?></span></td>
                <td style="text-align: center;">
                    <span class="status-pill status-completed"><?php echo (int) $row['quantity'] . " " . htmlspecialchars($row['unit']); ?></span>
                </td>
            </tr>
            <?php
        }
    } else {
        echo '<tr><td colspan="5" style="text-align:center; padding: 40px; color: var(--text-muted);">'
           . ($q !== '' ? 'No dispensing records match your search.' : 'No dispensing records found.') . '</td></tr>';
    }
    $stmt->close();
    return ob_get_clean();
}

// Live lot list for ONE medicine (called when a medicine is picked): only active, non-expired lots with stock,
// earliest expiry first (First-Expired-First-Out), plus how many expired lots with stock are being hidden.
if (isset($_GET['batches'])) {
    if (!$can_dispense) { http_response_code(403); exit; }
    $b_item  = trim((string) ($_GET['item'] ?? ''));
    $b_brand = trim((string) ($_GET['brand'] ?? ''));
    $brand_expr = "IFNULL(NULLIF(TRIM(brand_name), ''), 'Generic')";   // same rule the medicine list uses for blank brands

    $stmt = $conn->prepare(
        "SELECT id, batch_number, tracking_code, quantity, unit, units_per_pack, pack_label, packs_per_box, box_label,
                expiry_date, DATEDIFF(expiry_date, CURDATE()) AS days_left
           FROM inventory
          WHERE status = 1 AND quantity > 0 AND item_name = ? AND $brand_expr = ?
            AND (expiry_date IS NULL OR expiry_date >= CURDATE())
          ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC"
    );
    $stmt->bind_param('ss', $b_item, $b_brand);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'id' => (int) $r['id'], 'batch_number' => $r['batch_number'], 'tracking_code' => $r['tracking_code'],
            'quantity' => (int) $r['quantity'], 'unit' => $r['unit'],
            'units_per_pack' => $r['units_per_pack'] !== null ? (int) $r['units_per_pack'] : null,
            'pack_label' => $r['pack_label'] ?: 'Blister Pack',
            'packs_per_box' => $r['packs_per_box'] !== null ? (int) $r['packs_per_box'] : null,
            'box_label' => $r['box_label'] ?: 'Box',
            'expiry_date' => $r['expiry_date'],
            'days_to_expiry' => $r['days_left'] !== null ? (int) $r['days_left'] : null,
            'is_fefo' => count($out) === 0,
        ];
    }
    $stmt->close();

    $ex = $conn->prepare("SELECT COUNT(*) AS c FROM inventory WHERE status = 1 AND quantity > 0 AND item_name = ? AND $brand_expr = ? AND expiry_date < CURDATE()");
    $ex->bind_param('ss', $b_item, $b_brand);
    $ex->execute();
    $expired_count = (int) ($ex->get_result()->fetch_assoc()['c'] ?? 0);
    $ex->close();

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Dispense-Batches: 1');             // lets the JS tell real data from a login-redirect page
    echo json_encode(['batches' => $out, 'expired_count' => $expired_count], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// Live log refresh / server-side search (called by the page's JavaScript). Same permission as the table itself.
if (isset($_GET['log_rows'])) {
    if (!$can_view_log) { http_response_code(403); exit; }
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Dispense-Log: 1');                 // lets the JS tell real rows from a login-redirect page
    echo mh_render_dispense_rows($conn, trim((string) ($_GET['q'] ?? '')));
    exit;
}

if(isset($_POST['dispense_item']) && !mh_has_permission('dispense_medicine')) {
    mh_deny_access(); // form submit level: view-only accounts cannot deduct stock
}

/* =====================================================================
 * DISPENSE (atomic)
 *   CSRF -> validate input -> ONE transaction:
 *     lock the batch row -> FEFO audit flag -> conditional stock deduction
 *     (UPDATE ... WHERE quantity >= ?) -> dispensations row -> activity log -> COMMIT.
 *   Zero rows updated = not enough stock (or batch archived by someone else a moment ago):
 *   ROLLBACK and report it. Any exception also ROLLBACKs, so stock can never be deducted
 *   without a matching log row, and two clerks can never both take the last units.
 * ================================================================== */
if (isset($_POST['dispense_item'])) {
    if (!mh_csrf_check()) {
        http_response_code(403);
        $error_msg = "Your session security token is missing or expired. Please reload the page and try again.";
    } else {
        $item_id    = (int) ($_POST['inventory_id'] ?? 0);
        $amount     = (int) ($_POST['quantity'] ?? 0);
        $patient_id = (int) ($_POST['patient_id'] ?? 0);
        $staff_id   = (int) ($_SESSION['user_id'] ?? 0);
        $old = ['inventory_id' => $item_id, 'patient_id' => $patient_id, 'quantity' => $amount > 0 ? $amount : ''];

        if ($item_id <= 0) {
            $error_msg = "Please select a medicine and a batch.";
        } elseif ($amount <= 0 || $amount > 100000) {
            $error_msg = "Enter a valid quantity to dispense (1 to 100,000).";
        } elseif ($patient_id <= 0) {
            $error_msg = "Please select the patient receiving the medicine.";
        } else {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // SQL errors become exceptions -> caught below
            try {
                $conn->begin_transaction();

                // The patient must exist and be active (not archived).
                $pc = $conn->prepare("SELECT first_name, last_name FROM patients WHERE id = ? AND status = 1 LIMIT 1");
                $pc->bind_param('i', $patient_id);
                $pc->execute();
                $patient_row = $pc->get_result()->fetch_assoc();
                $patient_ok = (bool) $patient_row;
                $pc->close();

                // Lock the batch row so its details cannot change under us.
                $ls = $conn->prepare("SELECT item_name, brand_name, expiry_date, status, quantity, unit, batch_number, tracking_code FROM inventory WHERE id = ? FOR UPDATE");
                $ls->bind_param('i', $item_id);
                $ls->execute();
                $item = $ls->get_result()->fetch_assoc();
                $ls->close();

                if (!$patient_ok) {
                    $conn->rollback();
                    $error_msg = "That patient record is not active.";
                } elseif (!$item) {
                    $conn->rollback();
                    $error_msg = "Selected batch could not be found.";
                } elseif ((int) $item['status'] !== 1) {
                    $conn->rollback();
                    $error_msg = "This batch has been archived and can no longer be dispensed.";
                } elseif (!empty($item['expiry_date']) && $item['expiry_date'] < date('Y-m-d')) {
                    $conn->rollback();
                    $error_msg = "This batch has expired and cannot be dispensed.";
                } elseif ($amount > (int) $item['quantity']) {
                    // Friendly pre-check (row is locked, so this number is exact). The conditional UPDATE below stays as the safety net.
                    $conn->rollback();
                    $error_msg = "Not enough stock: only " . (int) $item['quantity'] . " " . $item['unit'] . " left in lot " . ($item['batch_number'] ?: 'N/A') . ". You asked for $amount.";
                } else {
                    $item_full_name = $item['item_name'] . ' (' . (($item['brand_name'] ?? '') !== '' ? $item['brand_name'] : 'Generic') . ')';

                    // FEFO audit flag: is an earlier-expiring active batch of the same item still in stock?
                    // (Does not block the dispense - staff may have a valid reason - but it is recorded.)
                    $brand = (string) ($item['brand_name'] ?? '');
                    $fs = $conn->prepare(
                        "SELECT 1 FROM inventory
                          WHERE status = 1 AND quantity > 0 AND id <> ? AND item_name = ? AND IFNULL(brand_name, '') = ?
                            AND expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND (? IS NULL OR expiry_date < ?)
                          LIMIT 1"
                    );
                    $this_expiry = $item['expiry_date'];   // NULL (no expiry) = anything dated sooner counts as earlier
                    $fs->bind_param('issss', $item_id, $item['item_name'], $brand, $this_expiry, $this_expiry);
                    $fs->execute();
                    $is_override = $fs->get_result()->num_rows > 0 ? 1 : 0;
                    $fs->close();

                    // Conditional deduction: only succeeds while enough stock remains.
                    $up = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND status = 1 AND quantity >= ?");
                    $up->bind_param('iii', $amount, $item_id, $amount);
                    $up->execute();
                    $deducted = $up->affected_rows;
                    $up->close();

                    if ($deducted === 0) {
                        $conn->rollback();
                        $error_msg = "Insufficient stock available for this item.";
                    } else {
                        $di = $conn->prepare(
                            "INSERT INTO dispensations (inventory_id, patient_id, dispensed_by, quantity, is_fefo_override)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $di->bind_param('iiiii', $item_id, $patient_id, $staff_id, $amount, $is_override);
                        $di->execute();
                        $di->close();

                        $log_note = "Dispensed $amount {$item['unit']} of $item_full_name (SKU " . ($item['tracking_code'] ?: 'N/A') . ", Lot " . ($item['batch_number'] ?: 'N/A')
                                  . ") to Patient ID #$patient_id (" . $patient_row['last_name'] . ", " . $patient_row['first_name'] . "). Stock left in lot: " . ((int) $item['quantity'] - $amount) . ".";
                        if ($is_override) {
                            $log_note .= " NOTE: an earlier-expiring active batch of the same item still had stock on hand (FEFO override).";
                        }
                        if (!logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Dispensed Medicine", $log_note)) {
                            throw new RuntimeException('Audit log write failed');   // no log = no dispense: roll everything back
                        }

                        // Opened from Patient Queueing > Proceed: the dispense IS the "save record" step, so the queue
                        // entry closes in the same transaction (only when it is this patient's inventory queue entry).
                        $q_done = false;
                        $queue_ctx_post = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'inventory', $patient_id);
                        if ($queue_ctx_post && mh_queue_complete($conn, $queue_ctx_post['id'], $patient_id)) { $q_done = true; }

                        $conn->commit();   // stock deduction + dispensation record + audit log succeed together, or not at all
                        $success_msg = "Successfully dispensed $amount {$item['unit']} of $item_full_name!";
                        if ($q_done) {
                            $success_msg .= " Queue" . (!empty($queue_ctx_post['queue_number']) ? " #" . (int) $queue_ctx_post['queue_number'] : "") . " marked as Completed.";
                        }
                        $_SESSION['dispense_flash'] = $success_msg;   // PRG: show it after the redirect, never re-POST on refresh
                        header('Location: dispense_medicine.php');
                        exit();
                    }
                }
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                error_log('Dispense failed: ' . $e->getMessage());
                $error_msg = "The medicine could not be dispensed. Nothing was changed - please try again.";
            }
        }
    }
}

// =====================================================================
// Build FEFO-sorted medicine -> batch groups for the dispense form.
// Each group is one generic+brand combination; batches within a group
// are always sorted earliest-expiry-first (nulls/no-expiry last) so
// the dispense form can auto-select the correct FEFO batch in JS.
// =====================================================================
$active_stock_res = $conn->query("
    SELECT id, item_name, brand_name, category, batch_number, tracking_code, quantity, unit,
           units_per_pack, pack_label, packs_per_box, box_label, expiry_date
    FROM inventory
    WHERE status = 1 AND quantity > 0 AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ORDER BY item_name ASC, brand_name ASC
");

$expired_batches = (int) ($conn->query("SELECT COUNT(*) c FROM inventory WHERE status = 1 AND quantity > 0 AND expiry_date < CURDATE()")->fetch_assoc()['c'] ?? 0);
$today = date('Y-m-d');
$today_ts = strtotime($today);

$medicine_groups = []; // keyed by "generic||brand"
$queue_ctx = null; $queue_patient_id = 0; $queue_label = '';
if ($can_dispense && (isset($_GET['walkin_id']) || isset($_GET['apt_id']))) {
    $q_patient = (int) ($_GET['walkin_id'] ?? 0);
    $queue_ctx = mh_queue_open_context($conn, $_GET['apt_id'] ?? 0, 'inventory', $q_patient);
    if ($queue_ctx) { $q_patient = (int) $queue_ctx['patient_id']; }
    $profile = $q_patient > 0 ? mh_queue_patient_profile($conn, $q_patient) : null;
    if ($profile) { $queue_patient_id = $q_patient; $queue_label = $profile['last_name'] . ', ' . $profile['first_name']; }
}
$preselect_batch_id = isset($_GET['dispense']) ? intval($_GET['dispense']) : 0;
$preselect_group_key = null;

if($active_stock_res && $active_stock_res->num_rows > 0) {
    while($row = $active_stock_res->fetch_assoc()) {
        $brand = trim((string) ($row['brand_name'] ?? ''));
        $brand_display = $brand !== '' ? $brand : 'Generic';
        $key = $row['item_name'] . '||' . $brand_display;

        if(!isset($medicine_groups[$key])) {
            $medicine_groups[$key] = [
                'key'        => $key,
                'item_name'  => $row['item_name'],
                'brand_name' => $brand_display,
                'category'   => $row['category'],
                'unit'       => $row['unit'],
                'batches'    => [],
            ];
        }

        $days = null;
        if(!empty($row['expiry_date'])) {
            $days = (int) round((strtotime($row['expiry_date']) - $today_ts) / 86400);
        }

        $medicine_groups[$key]['batches'][] = [
            'id'             => (int) $row['id'],
            'batch_number'   => $row['batch_number'],
            'tracking_code'  => $row['tracking_code'],
            'quantity'       => (int) $row['quantity'],
            'unit'           => $row['unit'],
            'units_per_pack' => $row['units_per_pack'] !== null ? (int) $row['units_per_pack'] : null,
            'pack_label'     => $row['pack_label'] ?: 'Blister Pack',
            'packs_per_box'  => $row['packs_per_box'] !== null ? (int) $row['packs_per_box'] : null,
            'box_label'      => $row['box_label'] ?: 'Box',
            'expiry_date'    => $row['expiry_date'],
            'days_to_expiry' => $days,
        ];

        if($preselect_batch_id > 0 && (int) $row['id'] === $preselect_batch_id) {
            $preselect_group_key = $key;
        }
    }
}

// Sort each group's batches FEFO (earliest expiry first, no-expiry last)
// and flag index 0 as the recommended batch, independent of whatever
// order the SQL happened to return rows in.
foreach($medicine_groups as $key => &$grp) {
    usort($grp['batches'], function($a, $b) {
        if($a['expiry_date'] === $b['expiry_date']) return $a['id'] <=> $b['id'];   // same expiry: oldest received first
        if(empty($a['expiry_date'])) return 1;
        if(empty($b['expiry_date'])) return -1;
        return strcmp($a['expiry_date'], $b['expiry_date']);
    });
    foreach($grp['batches'] as $i => &$b) {
        $b['is_fefo'] = ($i === 0);
    }
    unset($b);
}
unset($grp);

$medicine_groups = array_values($medicine_groups);
usort($medicine_groups, function($a, $b) {
    return strcasecmp($a['item_name'] . $a['brand_name'], $b['item_name'] . $b['brand_name']);
});

$js_medicine_groups = json_encode($medicine_groups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$js_preselect_group_key = json_encode($preselect_group_key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$patients_list = $conn->query("SELECT id, first_name, last_name, birthdate FROM patients WHERE status = 1 ORDER BY last_name ASC, first_name ASC");

$log_rows_html = $can_view_log ? mh_render_dispense_rows($conn, '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispense Medicine - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        input[type=number] {
            -moz-appearance: textfield;
        }

        .select2-container .select2-selection--single {
            height: 42px !important;
            border: 1px solid var(--border-color, #cbd5e1) !important;
            border-radius: 6px !important;
            background: var(--bg-card, #fff) !important;
            display: flex;
            align-items: center;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: var(--text-main, #334155);
            font-size: 0.95rem;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: var(--text-muted, #94a3b8);
        }
        .select2-dropdown {
            border: 1px solid var(--border-color, #cbd5e1) !important;
            border-radius: 6px !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .select2-search__field {
            border-radius: 4px !important;
            border: 1px solid var(--border-color, #cbd5e1) !important;
            padding: 6px 10px !important;
        }
        html[data-theme="dark"] .select2-container--default .select2-results__option { background: var(--bg-card); color: var(--text-main); }
        html[data-theme="dark"] .select2-dropdown { background: var(--bg-card); }
        html[data-theme="dark"] .select2-search__field { background: var(--bg-hover); color: var(--text-main); }

        .batch-select-option { display: flex; flex-direction: column; gap: 2px; }
        #packagingCalcWrap { display: none; }
        #packagingCalcWrap.active { display: block; }
        .stock-readout { font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; }
        .stock-readout strong { color: var(--primary); }
        .stock-readout.bad, .stock-readout.bad strong { color: #dc2626; }
        .stock-pill { display: inline-block; margin-top: 8px; padding: 6px 12px; border-radius: 999px; font-size: 0.85rem; font-weight: 600; background: rgba(16,185,129,.12); color: #047857; border: 1px solid rgba(16,185,129,.35); }
        .stock-pill.warn { background: rgba(245,158,11,.12); color: #b45309; border-color: rgba(245,158,11,.4); }
        .expired-note a { color: inherit; font-weight: bold; text-decoration: underline; }
        #batchSelect:disabled { opacity: .65; cursor: not-allowed; }
        .log-meta { padding: 10px 16px; font-size: 0.8rem; color: var(--text-muted); border-top: 1px solid var(--border-color, #e2e8f0); }
        .expired-note { display: flex; gap: 8px; align-items: flex-start; font-size: 0.82rem; margin-bottom: 15px; padding: 9px 11px; border-radius: 6px; background: rgba(245,158,11,.12); color: #b45309; border: 1px solid rgba(245,158,11,.4); }
        @media (max-width: 1000px) {
            .dispense-grid { grid-template-columns: 1fr !important; }
            .dispense-grid > .unified-panel { position: static !important; }
        }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <div style="margin-bottom: 25px;">
                <h1 style="margin: 0 0 5px 0; color: var(--heading-color, #1e293b); font-family: Georgia, serif; font-size: 1.8rem;">Dispense Medicine</h1>
                <p style="margin: 0; color: var(--text-muted); font-size: 0.95rem;">Issue medicines to patients following First-Expired-First-Out and track dispensing history.</p>
            </div>

            <div class="dispense-grid" style="display: grid; grid-template-columns: <?php echo ($can_dispense && $can_view_log) ? '380px 1fr' : '1fr'; ?>; gap: 25px; align-items: start;">
                
                <?php if($can_dispense): ?>
                <div class="unified-panel" style="margin: 0; position: sticky; top: 90px;">
                    <div class="panel-controls" style="background: rgba(16,185,129,0.1); border-top: 4px solid #10b981;">
                        <h3 style="margin:0; font-size: 1.1rem; color: #047857;"><?php echo mh_icon('droplet'); ?> Issue Medicine</h3>
                    </div>
                    <form id="dispenseForm" method="POST" style="padding: 20px;">
                        <?php if ($queue_ctx): ?>
                            <input type="hidden" name="apt_id" value="<?php echo (int) $queue_ctx['id']; ?>">
                            <?php mh_queue_render_banner($queue_ctx, $queue_label, false); ?>
                        <?php endif; ?>
                        <?php echo mh_csrf_field(); ?>
                        <input type="hidden" name="dispense_item" value="1">
                        <input type="hidden" name="inventory_id" id="selectedBatchId" required>
                        
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label>Select Medicine <span style="color:red;">*</span></label>
                            <select id="medicineSelect" style="width: 100%;">
                                <option value="" disabled selected>Search generic or brand name...</option>
                                <?php foreach($medicine_groups as $grp): ?>
                                    <option value="<?php echo htmlspecialchars($grp['key']); ?>">
                                        <?php echo htmlspecialchars($grp['item_name']) . ' (' . htmlspecialchars($grp['brand_name']) . ')'; ?>
                                        &mdash; <?php echo (int) array_sum(array_column($grp['batches'], 'quantity')); ?> <?php echo htmlspecialchars($grp['unit']); ?> across <?php echo count($grp['batches']); ?> batch<?php echo count($grp['batches']) === 1 ? '' : 'es'; ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if(empty($medicine_groups)): ?>
                                    <option value="" disabled>No active stock available</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="expired-note" id="expiredNote" style="<?php echo $expired_batches > 0 ? '' : 'display:none;'; ?>">
                            <?php echo mh_icon('alert-triangle'); ?>
                            <span id="expiredNoteText"><?php if ($expired_batches > 0) { echo $expired_batches . ' expired batch' . ($expired_batches === 1 ? '' : 'es') . ' with stock on hand ' . ($expired_batches === 1 ? 'is' : 'are') . ' hidden here and cannot be dispensed. Review ' . ($expired_batches === 1 ? 'it' : 'them') . ' in the <a href="inventory.php">Supply Masterlist</a>.'; } ?></span>
                        </div>

                        <div class="form-group" id="batchSelectWrap" style="margin-bottom: 15px;">
                            <label for="batchSelect">Batch / Lot <span style="color:red;">*</span></label>
                            <select id="batchSelect" style="width: 100%;" disabled>
                                <option value="">Select a medicine first&hellip;</option>
                            </select>
                            <div class="stock-pill" id="batchStockReadout">Available: &mdash;</div>
                        </div>

                        <div id="fefoWarningBanner" class="fefo-warning-banner" style="display:none;">
                            <?php echo mh_icon('alert-triangle'); ?>
                            <div>
                                <strong>FEFO reminder:</strong>
                                <span id="fefoWarningText"></span> Consider dispensing from that batch first unless there's a reason not to (e.g. it's on hold).
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label>Select Patient <span style="color:red;">*</span></label>
                            <select name="patient_id" id="patientSelect" required style="width: 100%;">
                                <option value="" disabled selected>Search registered patient...</option>
                                <?php 
                                if($patients_list && $patients_list->num_rows > 0){
                                    while($p = $patients_list->fetch_assoc()){
                                        echo "<option value='".(int) $p['id']."'>".htmlspecialchars($p['last_name'].", ".$p['first_name']." (ID #".$p['id']." \u{2022} b. ".$p['birthdate'].")")."</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group packaging-config-box" id="packagingCalcWrap">
                            <label class="section-label"><?php echo mh_icon('calculator'); ?> Unit Dispensing Calculator</label>
                            <div class="packaging-grid" style="grid-template-columns: 1fr 1fr 1fr;" id="packagingCalcInputs">
                                <div class="form-group">
                                    <label id="disp_box_label">Boxes</label>
                                    <input type="number" id="disp_box_qty" min="0" placeholder="0" oninput="calcDispenseTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); box-sizing: border-box;">
                                </div>
                                <div class="form-group">
                                    <label id="disp_pack_label">Packs</label>
                                    <input type="number" id="disp_pack_qty" min="0" placeholder="0" oninput="calcDispenseTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); box-sizing: border-box;">
                                </div>
                                <div class="form-group">
                                    <label>Loose Units</label>
                                    <input type="number" id="disp_loose_qty" min="0" placeholder="0" oninput="calcDispenseTotal()" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); box-sizing: border-box;">
                                </div>
                            </div>
                            <div class="packaging-total-readout" id="disp_total_readout">Total: <strong>0</strong> base units</div>
                        </div>

                        <div class="form-group" style="margin-bottom: 25px;">
                            <label>Total Quantity to Give (Base Unit) <span style="color:red;">*</span></label>
                            <input type="number" name="quantity" id="disp_total_qty" required min="1" placeholder="e.g., 20" oninput="onManualQtyInput(); validateQty(true)" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-size: 1.1rem; font-weight: bold; color: #10b981; background: rgba(16,185,129,0.08); box-sizing: border-box;">
                        <div class="stock-readout" id="qtyHint"></div>
                        </div>

                        <button type="button" class="btn-submit" onclick="confirmDispense()" style="width: 100%; padding: 12px; font-size: 1rem; background: #10b981; border:none; color:white; border-radius:6px; cursor:pointer; font-weight:bold; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);"><?php echo mh_icon('check-circle'); ?> Dispense to Patient</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if($can_view_log): ?>
                <div class="unified-panel" style="margin: 0;">
                    <div class="panel-controls">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);"><?php echo mh_icon('file-text'); ?> Dispensing Records</h3>
                        <input type="text" id="searchLog" class="search-box" placeholder="Search patient, ID #, item, lot, staff, date..." oninput="debouncedLoadLog()" autocomplete="off" style="width: 260px; max-width: 100%; padding: 6px 12px;">
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="unified-table" id="logTable">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Patient</th>
                                    <th>Item / Batch</th>
                                    <th>Dispensed By</th>
                                    <th style="text-align: center;">Qty</th>
                                </tr>
                            </thead>
                            <tbody id="logBody"><?php echo $log_rows_html; ?></tbody>
                        </table>
                    </div>
                    <div class="log-meta"><span id="logMeta">Latest 100 matching records &middot; updates automatically</span></div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        <?php if($can_dispense): ?>
        /* =========================================================
           DATA - the medicine list comes from PHP once; the LOTS of a medicine are fetched live
           (dispense_medicine.php?batches=1) every time a medicine is chosen, so stock is never stale.
           ========================================================= */
        const MEDICINE_GROUPS = <?php echo $js_medicine_groups; ?>;   // also holds each group's lots as an offline fallback
        const PRESELECT_GROUP_KEY = <?php echo $js_preselect_group_key; ?>;
        const PRESELECT_BATCH_ID = <?php echo (int) $preselect_batch_id; ?>;
        const EXPIRED_GLOBAL = <?php echo (int) $expired_batches; ?>;

        const GROUPS_BY_KEY = {};
        MEDICINE_GROUPS.forEach(g => { GROUPS_BY_KEY[g.key] = g; });

        let currentGroup = null;
        let currentBatch = null;
        let loadSeq = 0;                       // ignores late answers when the user switches medicine quickly
        let lastKey = null;
        let pendingLoad = Promise.resolve();   // resolves when the lot list for the chosen medicine is on screen

        /* PURE-HELPERS-START (no DOM access: unit-tested in Node) */
        function escHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        }
        // First-Expired-First-Out: earliest expiry first, lots with no expiry last, ties -> received first (lowest id).
        function fifoSort(batches) {
            return batches.slice().sort((a, b) => {
                if (a.expiry_date === b.expiry_date) return a.id - b.id;
                if (!a.expiry_date) return 1;
                if (!b.expiry_date) return -1;
                return a.expiry_date < b.expiry_date ? -1 : 1;
            }).map((b, i) => Object.assign({}, b, { is_fefo: i === 0 }));
        }
        function daysText(d) {
            if (d === null || d === undefined) return 'no expiry on file';
            if (d <= 0) return 'expires today';
            return d + (d === 1 ? ' day' : ' days') + ' left';
        }
        function batchLabel(b) {
            let s = 'Lot ' + (b.batch_number || 'N/A') + ' \u2014 ' + b.quantity + ' ' + b.unit + ' available';
            s += b.expiry_date ? ' \u00b7 exp. ' + b.expiry_date + ' (' + daysText(b.days_to_expiry) + ')' : ' \u00b7 no expiry on file';
            if (b.is_fefo) s += ' \u2605 expires first';
            return s;
        }
        function expiredNoteHtml(n, medicineName) {
            if (!n) return '';
            return n + ' expired batch' + (n === 1 ? '' : 'es') + (medicineName ? ' of <strong>' + escHtml(medicineName) + '</strong>' : '')
                 + ' with stock on hand ' + (n === 1 ? 'is' : 'are') + ' hidden here and cannot be dispensed. Review '
                 + (n === 1 ? 'it' : 'them') + ' in the <a href="inventory.php">Supply Masterlist</a>.';
        }
        /* PURE-HELPERS-END */

        function showExpiredNote(n, medicineName) {
            const box = document.getElementById('expiredNote');
            if (!box) return;
            if (!n) { box.style.display = 'none'; return; }
            document.getElementById('expiredNoteText').innerHTML = expiredNoteHtml(n, medicineName);
            box.style.display = 'flex';
        }

        /* ---------------------------------------------------------
           Batch / Lot dropdown (always visible; disabled until a medicine is chosen)
           --------------------------------------------------------- */
        function resetBatchUI(placeholder) {
            const sel = document.getElementById('batchSelect');
            sel.innerHTML = '';
            const o = document.createElement('option');
            o.value = ''; o.textContent = placeholder || 'Select a medicine first\u2026';
            sel.appendChild(o);
            sel.disabled = true;
            currentBatch = null;
            document.getElementById('selectedBatchId').value = '';
            const pill = document.getElementById('batchStockReadout');
            pill.className = 'stock-pill'; pill.textContent = 'Available: \u2014';
            document.getElementById('fefoWarningBanner').style.display = 'none';
            document.getElementById('packagingCalcWrap').classList.remove('active');
            document.getElementById('disp_total_qty').value = '';
            validateQty();
        }

        function populateBatchSelect(group) {
            const sel = document.getElementById('batchSelect');
            if (!group.batches.length) { resetBatchUI('No dispensable lots (all expired or out of stock)'); return; }
            sel.innerHTML = '';
            group.batches.forEach((b, idx) => {
                const opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = batchLabel(b);
                if (idx === 0) opt.selected = true;     // FIFO: the lot that expires first is pre-selected
                sel.appendChild(opt);
            });
            sel.disabled = false;
            onBatchChange();
        }

        function loadBatches(group) {
            const seq = ++loadSeq;
            resetBatchUI('Loading lots\u2026');
            const url = 'dispense_medicine.php?batches=1&item=' + encodeURIComponent(group.item_name) + '&brand=' + encodeURIComponent(group.brand_name);
            return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                .then(r => (r.ok && r.headers.get('X-Dispense-Batches') === '1') ? r.json() : Promise.reject(new Error('bad response')))
                .then(data => ({ batches: data.batches, expired: data.expired_count, live: true }))
                .catch(() => ({ batches: group.batches, expired: null, live: false }))   // offline / session problem: fall back to the page-load copy
                .then(res => {
                    if (seq !== loadSeq) return;                  // user already picked another medicine
                    group.batches = fifoSort(res.batches);
                    populateBatchSelect(group);
                    showExpiredNote(res.expired === null ? 0 : res.expired, group.item_name + ' (' + group.brand_name + ')');
                    if (!res.live) {
                        const pill = document.getElementById('batchStockReadout');
                        pill.title = 'Could not refresh live stock - showing the figures loaded with the page. The server re-checks stock on submit.';
                    }
                });
        }

        function onMedicineChange() {
            const key = document.getElementById('medicineSelect').value;
            if (key === lastKey && pendingLoad) return pendingLoad;
            lastKey = key;
            currentGroup = GROUPS_BY_KEY[key] || null;
            if (!currentGroup) {
                loadSeq++;
                resetBatchUI();
                showExpiredNote(EXPIRED_GLOBAL, null);
                pendingLoad = Promise.resolve();
                return pendingLoad;
            }
            pendingLoad = loadBatches(currentGroup);
            return pendingLoad;
        }

        function onBatchChange() {
            const sel = document.getElementById('batchSelect');
            const id = parseInt(sel.value, 10);
            currentBatch = ((currentGroup && currentGroup.batches) || []).find(b => b.id === id) || null;
            document.getElementById('selectedBatchId').value = id || '';
            if (!currentBatch) return;

            // Available Stock Indicator
            const pill = document.getElementById('batchStockReadout');
            const soon = currentBatch.days_to_expiry !== null && currentBatch.days_to_expiry !== undefined && currentBatch.days_to_expiry <= 90;
            pill.className = 'stock-pill' + (soon ? ' warn' : '');
            pill.innerHTML = 'Available: <strong>' + currentBatch.quantity.toLocaleString() + '</strong> ' + escHtml(currentBatch.unit)
                + (currentBatch.expiry_date ? ' &middot; exp. ' + escHtml(currentBatch.expiry_date) + ' (' + daysText(currentBatch.days_to_expiry) + ')' : '');

            // Reminder when a later-expiring lot is chosen while the recommended lot still has stock.
            const fefoBanner = document.getElementById('fefoWarningBanner');
            const recommended = currentGroup.batches.find(b => b.is_fefo);
            if (recommended && !currentBatch.is_fefo && recommended.quantity > 0) {
                document.getElementById('fefoWarningText').textContent =
                    'Lot ' + (recommended.batch_number || 'N/A') + (recommended.expiry_date ? ' expires ' + recommended.expiry_date : '') +
                    ' and still has ' + recommended.quantity + ' ' + recommended.unit + ' in stock.';
                fefoBanner.style.display = 'flex';
            } else {
                fefoBanner.style.display = 'none';
            }

            setupPackagingCalculator(currentBatch);
            validateQty();
        }

        $('#medicineSelect').on('change', onMedicineChange);   // jQuery binding: select2 fires jQuery events, NOT native ones
        $('#batchSelect').on('change', onBatchChange);

        /* ---------------------------------------------------------
           Interactive unit dispensing calculator - Boxes / Packs / loose units
           --------------------------------------------------------- */
        function setupPackagingCalculator(batch) {
            const calcWrap = document.getElementById('packagingCalcWrap');
            const boxField = document.getElementById('disp_box_qty').closest('.form-group');

            document.getElementById('disp_box_qty').value = '';
            document.getElementById('disp_pack_qty').value = '';
            document.getElementById('disp_loose_qty').value = '';
            document.getElementById('disp_total_qty').value = '';
            document.getElementById('disp_total_readout').innerHTML = 'Total: <strong>0</strong> base units';

            if (!batch.units_per_pack) { calcWrap.classList.remove('active'); return; }

            calcWrap.classList.add('active');
            document.getElementById('disp_pack_label').textContent = (batch.pack_label || 'Pack') + 's';
            if (batch.packs_per_box) {
                boxField.style.display = '';
                document.getElementById('disp_box_label').textContent = (batch.box_label || 'Box') + 's';
            } else {
                boxField.style.display = 'none';
            }
        }

        function calcDispenseTotal() {
            if (!currentBatch) return;
            const unitsPerPack = currentBatch.units_per_pack || 0;
            const packsPerBox = currentBatch.packs_per_box || 0;
            const boxQty = parseInt(document.getElementById('disp_box_qty').value) || 0;
            const packQty = parseInt(document.getElementById('disp_pack_qty').value) || 0;
            const looseQty = parseInt(document.getElementById('disp_loose_qty').value) || 0;

            let total = looseQty;
            if (unitsPerPack > 0) {
                total += packQty * unitsPerPack;
                if (packsPerBox > 0) total += boxQty * packsPerBox * unitsPerPack;
            }
            document.getElementById('disp_total_qty').value = total;
            validateQty(true);
            document.getElementById('disp_total_readout').innerHTML = 'Total: <strong>' + (parseInt(document.getElementById('disp_total_qty').value, 10) || 0).toLocaleString() + '</strong> base units';
        }

        /* Typing straight into Total Quantity clears the calculator so the two never disagree. */
        function onManualQtyInput() {
            document.getElementById('disp_box_qty').value = '';
            document.getElementById('disp_pack_qty').value = '';
            document.getElementById('disp_loose_qty').value = '';
        }

        /* Live check against the SELECTED lot. cap=true (typing) clamps the number to the lot's stock and says so;
           cap=false (submit) just reports. Returns true when the quantity fits. */
        function validateQty(cap) {
            const el = document.getElementById('disp_total_qty');
            const hint = document.getElementById('qtyHint');
            if (!currentBatch) { el.removeAttribute('max'); hint.textContent = ''; hint.className = 'stock-readout'; return true; }
            const max = currentBatch.quantity;
            el.max = max;
            const v = parseInt(el.value, 10);
            if (v > max) {
                if (cap) {
                    el.value = max;
                    hint.className = 'stock-readout bad';
                    hint.innerHTML = 'Capped to <strong>' + max.toLocaleString() + '</strong> - only ' + max.toLocaleString() + ' ' + escHtml(currentBatch.unit) + ' in this batch.';
                    return true;
                }
                hint.className = 'stock-readout bad';
                hint.innerHTML = 'Only <strong>' + max.toLocaleString() + '</strong> ' + escHtml(currentBatch.unit) + ' available in this batch - reduce the quantity.';
                return false;
            }
            hint.className = 'stock-readout';
            hint.innerHTML = 'Max <strong>' + max.toLocaleString() + '</strong> ' + escHtml(currentBatch.unit) + ' in this batch';
            return true;
        }

        /* After a failed submit the page reloads: put the staff member's choices back. */
        function restoreForm(o) {
            const g = MEDICINE_GROUPS.find(x => x.batches.some(b => b.id === o.inventory_id));
            if (!g) return;
            $('#medicineSelect').val(g.key).trigger('change');
            pendingLoad.then(function () {
                const sel = document.getElementById('batchSelect');
                if (Array.prototype.some.call(sel.options, op => op.value === String(o.inventory_id))) {
                    sel.value = String(o.inventory_id);
                    onBatchChange();
                }
                if (o.patient_id) { $('#patientSelect').val(String(o.patient_id)).trigger('change'); }
                document.getElementById('disp_total_qty').value = o.quantity || '';
                validateQty(true);
            });
        }

        window.onload = function() {
            mhSelect2('#patientSelect', { placeholder: 'Search by name or ID...' });
            <?php if ($queue_patient_id > 0): ?>
            $('#patientSelect').val('<?php echo (int) $queue_patient_id; ?>').trigger('change');   // patient from the queue
            <?php endif; ?>

            mhSelect2('#medicineSelect', { placeholder: 'Search generic or brand name...' });

            <?php if ($error_msg !== '' && (int) $old['inventory_id'] > 0): ?>
            restoreForm(<?php echo json_encode($old, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
            <?php elseif ($preselect_group_key !== null): ?>
            // Deep-linked from the Supply Masterlist's "Dispense" action: preselect the medicine and (if given) that lot.
            $('#medicineSelect').val(<?php echo json_encode($preselect_group_key); ?>).trigger('change');
            pendingLoad.then(function () {
                <?php if ($preselect_batch_id > 0): ?>
                const sel = document.getElementById('batchSelect');
                if (Array.prototype.some.call(sel.options, op => op.value === '<?php echo (int) $preselect_batch_id; ?>')) {
                    sel.value = '<?php echo (int) $preselect_batch_id; ?>';
                    onBatchChange();
                }
                <?php endif; ?>
                document.getElementById('dispenseForm')?.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
            <?php endif; ?>

            <?php if($success_msg): ?>
                mh_notify(<?php echo mh_js($success_msg); ?>, 'success');
                window.history.replaceState(null, null, window.location.pathname);
            <?php endif; ?>

            <?php if($error_msg): ?>
                mh_notify(<?php echo mh_js($error_msg); ?>, 'error');
            <?php endif; ?>
        };

        function confirmDispense() {
            const patientSelect = document.getElementById('patientSelect');
            const quantity = document.getElementById('disp_total_qty').value;

            if(!currentGroup || !currentBatch || !patientSelect.value || !quantity || quantity < 1) {   // incomplete
                mh_alert('Please select a medicine, a batch / lot, a patient, and enter a valid quantity to dispense.', 'Incomplete Form', 'warning');
                return;
            }

            if(!validateQty(false)) {
                mh_alert('Only ' + currentBatch.quantity.toLocaleString() + ' ' + currentBatch.unit + ' left in this batch.', 'Not Enough Stock', 'danger');
                return;
            }

            const itemName = currentGroup.item_name + ' (' + currentGroup.brand_name + ')';
            const patientName = patientSelect.options[patientSelect.selectedIndex].text;
            const isOverride = document.getElementById('fefoWarningBanner').style.display !== 'none';

            mh_confirm(
                'Dispense <strong>' + escHtml(quantity) + ' ' + escHtml(currentBatch.unit) + '</strong> of <strong>' + escHtml(itemName) + '</strong>' +
                ' (Lot ' + escHtml(currentBatch.batch_number || 'N/A') + ') to <strong>' + escHtml(patientName) + '</strong>?' +
                (isOverride ? '<br><br><span class="mh-text-warning">An earlier-expiring batch still has stock - this will be logged as a FEFO override.</span>' : ''),
                isOverride ? 'Confirm Dispensing (FEFO Override)' : 'Confirm Dispensing',
                isOverride ? 'warning' : 'info',
                'Yes, Dispense',
                { html: true }
            ).then(function (ok) {
                if (ok) {
                    document.getElementById('dispenseForm').submit();
                }
            });
        }

        <?php endif; // can_dispense ?>

        /* Dispensing Log: server-side search + automatic refresh (so a dispense by another staff member shows up). */
        <?php if ($can_view_log): ?>
        let logTimer = null, logBusy = false;
        function loadLog() {
            if (logBusy) return;
            logBusy = true;
            const q = document.getElementById('searchLog').value.trim();
            fetch('dispense_medicine.php?log_rows=1&q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' })
                .then(r => (r.ok && r.headers.get('X-Dispense-Log') === '1') ? r.text() : null)
                .then(html => {
                    if (html === null) return;                       // session expired / error: leave the table as is
                    document.getElementById('logBody').innerHTML = html;
                    document.getElementById('logMeta').textContent = (q ? 'Latest 100 matches for "' + q + '"' : 'Latest 100 records') + ' \u00b7 updated ' + new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
                })
                .catch(() => {})
                .finally(() => { logBusy = false; });
        }
        function debouncedLoadLog() { clearTimeout(logTimer); logTimer = setTimeout(loadLog, 300); }
        setInterval(function () { if (!document.hidden) loadLog(); }, 30000);
        <?php endif; ?>
    </script>
</body>
</html>
