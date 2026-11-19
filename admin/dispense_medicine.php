<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/queue_helper.php';

require_permission(['dispense_medicine', 'view_dispensing_history']);

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('inventory');

$can_dispense = mh_has_permission('dispense_medicine');
$can_view_log = mh_has_permission('view_dispensing_history');

$system_name = $system_name ?? mh_setting('system_name');

$success_msg = ""; $error_msg = "";
$old = ['patient_id' => 0, 'lines' => [], 'recipient_type' => 'patient', 'staff_id' => 0, 'purpose' => ''];

function mh_dispense_staff_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $r = $conn->query("SHOW COLUMNS FROM dispensations LIKE 'recipient_type'");
        $ready = ($r && $r->num_rows > 0);
    }
    return $ready;
}
function mh_staff_recipient_roles(): array
{
    return ['doctor' => 'Doctor', 'midwife' => 'Midwife', 'dental' => 'Dentist', 'dentist' => 'Dentist', 'bhw' => 'BHW'];
}
function mh_staff_has_status(mysqli $conn): bool
{
    $r = $conn->query("SHOW COLUMNS FROM staff LIKE 'status'");
    return $r && $r->num_rows > 0;
}

if (!empty($_SESSION['dispense_flash'])) {
    $success_msg = (string) $_SESSION['dispense_flash'];
    unset($_SESSION['dispense_flash']);
}

function mh_render_dispense_rows(mysqli $conn, $q)
{
    $ready = mh_dispense_staff_ready($conn);
    $sql = "SELECT d.dispense_date, d.quantity, d.is_fefo_override,
                   i.item_name, i.brand_name, i.unit, i.batch_number, i.tracking_code,
                   p.id AS patient_id, p.first_name AS p_first, p.last_name AS p_last,
                   s.first_name AS s_first, s.last_name AS s_last"
         . ($ready ? ", d.recipient_type, d.purpose, rs.first_name AS r_first, rs.last_name AS r_last, rs.role AS r_role"
                   : ", 'patient' AS recipient_type, NULL AS purpose, NULL AS r_first, NULL AS r_last, NULL AS r_role")
         . " FROM dispensations d
              JOIN inventory i ON d.inventory_id = i.id
              LEFT JOIN patients p ON d.patient_id = p.id
              LEFT JOIN staff s ON d.dispensed_by = s.id"
         . ($ready ? " LEFT JOIN staff rs ON d.recipient_staff_id = rs.id" : "");
    $where = []; $types = ''; $params = [];
    $cols = ['p.first_name', 'p.last_name', 'i.item_name', 'i.brand_name', 'i.batch_number', 'i.tracking_code', 's.first_name', 's.last_name'];
    if ($ready) { array_push($cols, 'rs.first_name', 'rs.last_name', 'd.purpose'); }
    $terms = preg_split('/\s+/', trim((string) $q), -1, PREG_SPLIT_NO_EMPTY);
    foreach (array_slice($terms, 0, 6) as $t) {
        $like = '%' . addcslashes($t, '%_\\') . '%';
        $id = ltrim($t, '#');
        $parts = [];
        foreach ($cols as $c) { $parts[] = "$c LIKE ?"; $types .= 's'; $params[] = $like; }
        $parts[] = "DATE_FORMAT(d.dispense_date, '%b %d, %Y %l:%i %p') LIKE ?"; $types .= 's'; $params[] = $like;
        $parts[] = "CAST(p.id AS CHAR) = ?";       $types .= 's'; $params[] = $id;
        $parts[] = "CAST(d.quantity AS CHAR) = ?"; $types .= 's'; $params[] = $id;
        $where[] = '(' . implode(' OR ', $parts) . ')';
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
            $is_staff_row = (($row['recipient_type'] ?? 'patient') === 'staff');
            if ($is_staff_row) {
                $roles_map = mh_staff_recipient_roles();
                $r_name    = $row['r_first'] !== null ? $row['r_last'] . ', ' . $row['r_first'] : 'Former staff member';
                $p_initials = $row['r_first'] !== null ? mb_strtoupper(mb_substr($row['r_first'], 0, 1) . mb_substr($row['r_last'], 0, 1)) : '?';
                $r_role    = $roles_map[strtolower((string) $row['r_role'])] ?? ucfirst((string) $row['r_role']);
            } else {
                $p_initials = mb_strtoupper(mb_substr((string) $row['p_first'], 0, 1) . mb_substr((string) $row['p_last'], 0, 1));
            }
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
                        <?php if ($is_staff_row): ?>
                            <span class="patient-badge-name"><?php echo htmlspecialchars($r_name); ?></span>
                            <span class="patient-badge-id">Medical Staff &middot; <?php echo htmlspecialchars($r_role); ?></span>
                            <?php if (!empty($row['purpose'])): ?><span class="patient-badge-id" style="font-style: italic;">Purpose: <?php echo htmlspecialchars($row['purpose']); ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="patient-badge-name"><?php echo htmlspecialchars($row['p_last'] . ', ' . $row['p_first']); ?></span>
                            <span class="patient-badge-id">ID: <?php echo (int) $row['patient_id']; ?></span>
                        <?php endif; ?>
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

if (isset($_GET['batches'])) {
    if (!$can_dispense) { http_response_code(403); exit; }
    $b_item  = trim((string) ($_GET['item'] ?? ''));
    $b_brand = trim((string) ($_GET['brand'] ?? ''));
    $brand_expr = "IFNULL(NULLIF(TRIM(brand_name), ''), 'Generic')";

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
    header('X-Dispense-Batches: 1');
    echo json_encode(['batches' => $out, 'expired_count' => $expired_count], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

if (isset($_GET['log_rows'])) {
    if (!$can_view_log) { http_response_code(403); exit; }
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Dispense-Log: 1');
    echo mh_render_dispense_rows($conn, trim((string) ($_GET['q'] ?? '')));
    exit;
}

if(isset($_POST['dispense_item']) && !mh_has_permission('dispense_medicine')) {
    mh_deny_access();
}

if (isset($_POST['dispense_item'])) {
    if (!mh_csrf_check()) {
        http_response_code(403);
        $error_msg = "Your session security token is missing or expired. Please reload the page and try again.";
    } else {
        $staff_ready  = mh_dispense_staff_ready($conn);
        $to_staff     = $staff_ready && (($_POST['recipient_type'] ?? 'patient') === 'staff');
        $patient_id   = $to_staff ? 0 : (int) ($_POST['patient_id'] ?? 0);
        $recipient_staff_id = $to_staff ? (int) ($_POST['recipient_staff_id'] ?? 0) : 0;
        $purpose      = $to_staff ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['purpose'] ?? ''))), 0, 255) : '';
        $staff_id     = (int) ($_SESSION['user_id'] ?? 0);

        $ids  = (array) ($_POST['inventory_id'] ?? []);
        $qtys = (array) ($_POST['quantity'] ?? []);
        $lines = [];
        $line_problem = '';
        foreach ($ids as $i => $rawId) {
            $iid = (int) $rawId; $q = (int) ($qtys[$i] ?? 0);
            if ($iid <= 0 && $q <= 0) { continue; }
            if ($iid <= 0) { $line_problem = "Please select a medicine and a batch for every line."; break; }
            $lines[$iid] = ($lines[$iid] ?? 0) + $q;
        }
        $old = ['patient_id' => $patient_id, 'lines' => [], 'recipient_type' => $to_staff ? 'staff' : 'patient', 'staff_id' => $recipient_staff_id, 'purpose' => $purpose];
        foreach ($lines as $iid => $q) { $old['lines'][] = ['inventory_id' => $iid, 'quantity' => $q]; }

        if ($line_problem !== '') {
            $error_msg = $line_problem;
        } elseif (!$lines) {
            $error_msg = "Add at least one medicine to dispense.";
        } elseif (count($lines) > 25) {
            $error_msg = "Too many medicines in one visit (maximum 25). Dispense the rest separately.";
        } elseif (!$to_staff && $patient_id <= 0) {
            $error_msg = "Please select the patient receiving the medicine.";
        } elseif ($to_staff && $recipient_staff_id <= 0) {
            $error_msg = "Please select the medical staff member receiving the supplies.";
        } elseif ($to_staff && mb_strlen($purpose) < 3) {
            $error_msg = "Please enter a brief purpose or note for this issuance (for example: home visit kit, outreach, clinic use).";
        } else {
            foreach ($lines as $q) {
                if ($q <= 0 || $q > 100000) { $error_msg = "Enter a valid quantity for every medicine (1 to 100,000)."; break; }
            }
        }

        if ($error_msg === '') {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            try {
                $conn->begin_transaction();

                $patient_row = null; $staff_row = null;
                if ($to_staff) {
                    $role_list = "'" . implode("','", array_keys(mh_staff_recipient_roles())) . "'";
                    $sc = $conn->prepare("SELECT first_name, last_name, role FROM staff WHERE id = ? AND role IN ($role_list)" . (mh_staff_has_status($conn) ? " AND status = 1" : "") . " LIMIT 1");
                    $sc->bind_param('i', $recipient_staff_id);
                    $sc->execute();
                    $staff_row = $sc->get_result()->fetch_assoc();
                    $sc->close();
                } else {
                    $pc = $conn->prepare("SELECT first_name, last_name FROM patients WHERE id = ? AND status = 1 LIMIT 1");
                    $pc->bind_param('i', $patient_id);
                    $pc->execute();
                    $patient_row = $pc->get_result()->fetch_assoc();
                    $pc->close();
                }

                $fail = null;
                $done = [];
                if ($to_staff && !$staff_row) {
                    $fail = "That staff account is not active or cannot receive supplies.";
                } elseif (!$to_staff && !$patient_row) {
                    $fail = "That patient record is not active.";
                } else {
                    ksort($lines);
                    foreach ($lines as $item_id => $amount) {
                        $ls = $conn->prepare("SELECT item_name, brand_name, category, expiry_date, status, quantity, unit, batch_number, tracking_code FROM inventory WHERE id = ? FOR UPDATE");
                        $ls->bind_param('i', $item_id);
                        $ls->execute();
                        $item = $ls->get_result()->fetch_assoc();
                        $ls->close();

                        if (!$item) { $fail = "A selected batch could not be found."; break; }
                        $name = $item['item_name'] . ' (' . (($item['brand_name'] ?? '') !== '' ? $item['brand_name'] : 'Generic') . ')';
                        if ((int) $item['status'] !== 1) { $fail = "$name: this batch has been archived and can no longer be dispensed."; break; }
                        if ($to_staff && stripos((string) $item['category'], 'suppl') === false) {
                            $fail = "$name is a " . ($item['category'] ?: 'non-supply item') . ". Only Medical Supplies can be issued to staff; medicines and vaccines are dispensed to patients."; break;
                        }
                        if (!empty($item['expiry_date']) && $item['expiry_date'] < date('Y-m-d')) { $fail = "$name: this batch has expired and cannot be dispensed."; break; }
                        if ($amount > (int) $item['quantity']) {
                            $fail = "Not enough stock for $name: only " . (int) $item['quantity'] . ' ' . $item['unit'] . ' left in lot ' . ($item['batch_number'] ?: 'N/A') . ". You asked for $amount.";
                            break;
                        }

                        $brand = (string) ($item['brand_name'] ?? '');
                        $fs = $conn->prepare(
                            "SELECT 1 FROM inventory
                              WHERE status = 1 AND quantity > 0 AND id <> ? AND item_name = ? AND IFNULL(brand_name, '') = ?
                                AND expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND (? IS NULL OR expiry_date < ?)
                              LIMIT 1"
                        );
                        $this_expiry = $item['expiry_date'];
                        $fs->bind_param('issss', $item_id, $item['item_name'], $brand, $this_expiry, $this_expiry);
                        $fs->execute();
                        $is_override = $fs->get_result()->num_rows > 0 ? 1 : 0;
                        $fs->close();

                        $up = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND status = 1 AND quantity >= ?");
                        $up->bind_param('iii', $amount, $item_id, $amount);
                        $up->execute();
                        $deducted = $up->affected_rows;
                        $up->close();
                        if ($deducted === 0) { $fail = "Insufficient stock available for $name."; break; }

                        if ($to_staff) {
                            $di = $conn->prepare("INSERT INTO dispensations (inventory_id, patient_id, recipient_type, recipient_staff_id, purpose, dispensed_by, quantity, is_fefo_override) VALUES (?, NULL, 'staff', ?, ?, ?, ?, ?)");
                            $di->bind_param('iisiii', $item_id, $recipient_staff_id, $purpose, $staff_id, $amount, $is_override);
                        } else {
                            $di = $conn->prepare("INSERT INTO dispensations (inventory_id, patient_id, dispensed_by, quantity, is_fefo_override) VALUES (?, ?, ?, ?, ?)");
                            $di->bind_param('iiiii', $item_id, $patient_id, $staff_id, $amount, $is_override);
                        }
                        $di->execute();
                        $di->close();

                        if ($to_staff) {
                            $log_note = "Issued $amount {$item['unit']} of $name (SKU " . ($item['tracking_code'] ?: 'N/A') . ", Lot " . ($item['batch_number'] ?: 'N/A')
                                      . ") to Medical Staff #$recipient_staff_id (" . $staff_row['last_name'] . ", " . $staff_row['first_name'] . " - " . $staff_row['role'] . "). Purpose: $purpose. Stock left in lot: " . ((int) $item['quantity'] - $amount) . ".";
                        } else {
                            $log_note = "Dispensed $amount {$item['unit']} of $name (SKU " . ($item['tracking_code'] ?: 'N/A') . ", Lot " . ($item['batch_number'] ?: 'N/A')
                                      . ") to Patient ID #$patient_id (" . $patient_row['last_name'] . ", " . $patient_row['first_name'] . "). Stock left in lot: " . ((int) $item['quantity'] - $amount) . ".";
                        }
                        if ($is_override) {
                            $log_note .= " NOTE: an earlier-expiring active batch of the same item still had stock on hand (FEFO override).";
                        }
                        if (!logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], $to_staff ? "Issued Supply to Staff" : "Dispensed Medicine", $log_note)) {
                            throw new RuntimeException('Audit log write failed');
                        }
                        $done[] = "$amount {$item['unit']} of $name";
                    }
                }

                if ($fail !== null) {
                    $conn->rollback();
                    $error_msg = $fail . (count($lines) > 1 ? " Nothing was dispensed - fix this line and try again." : '');
                } else {
                    $q_done = false;
                    $queue_ctx_post = $to_staff ? null : mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'inventory', $patient_id);
                    if ($queue_ctx_post && mh_queue_complete($conn, $queue_ctx_post['id'], $patient_id)) { $q_done = true; }

                    $conn->commit();
                    $success_msg = $to_staff
                        ? "Successfully issued " . implode('; ', $done) . " to " . $staff_row['first_name'] . " " . $staff_row['last_name'] . "!"
                        : "Successfully dispensed " . implode('; ', $done) . "!";
                    if ($q_done) {
                        $success_msg .= " Queue" . (!empty($queue_ctx_post['queue_number']) ? " " . mh_queue_label($queue_ctx_post['department'] ?? '', $queue_ctx_post['queue_number']) : "") . " marked Completed.";
                    }
                    $_SESSION['dispense_flash'] = $success_msg;
                    header('Location: dispense_medicine.php');
                    exit();
                }
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                error_log('Dispense failed: ' . $e->getMessage());
                $error_msg = "The medicine could not be dispensed. Nothing was changed - please try again.";
            }
        }
    }
}

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

$medicine_groups = [];
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

foreach($medicine_groups as $key => &$grp) {
    usort($grp['batches'], function($a, $b) {
        if($a['expiry_date'] === $b['expiry_date']) return $a['id'] <=> $b['id'];
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

$staff_issue_ready = mh_dispense_staff_ready($conn);
$staff_recipients = [];
if ($staff_issue_ready && $can_dispense) {
    $role_list = "'" . implode("','", array_keys(mh_staff_recipient_roles())) . "'";
    $sr = $conn->query("SELECT id, first_name, last_name, role FROM staff WHERE role IN ($role_list)" . (mh_staff_has_status($conn) ? " AND status = 1" : "") . " ORDER BY last_name ASC, first_name ASC");
    $role_labels = mh_staff_recipient_roles();
    while ($sr && ($row_s = $sr->fetch_assoc())) {
        $staff_recipients[$role_labels[strtolower($row_s['role'])] ?? ucfirst($row_s['role'])][] = $row_s;
    }
    ksort($staff_recipients);
}
$patients_list = $conn->query("SELECT id, first_name, last_name, birthdate FROM patients WHERE status = 1 ORDER BY last_name ASC, first_name ASC");

$log_rows_html = $can_view_log ? mh_render_dispense_rows($conn, '') : '';
?>
<?php
require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'Dispense Medicine', 'external_styles' => ['https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css']]);
?>
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
        .recipient-toggle { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; overflow: hidden; }
        .recipient-toggle button { padding: 9px 10px; border: 0; background: var(--bg-card, #fff); color: var(--text-main, #334155); font-weight: 600; font-size: 0.9rem; cursor: pointer; font-family: inherit; }
        .recipient-toggle button + button { border-left: 1px solid var(--border-color, #cbd5e1); }
        .recipient-toggle button.active { background: #10b981; color: #fff; }
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
<?php mh_layout_open(); ?>

        <div class="dashboard-container">
            
            <div style="margin-bottom: 25px;">
                <h1 style="margin: 0 0 5px 0; color: var(--heading-color, #1e293b); font-family: Georgia, serif; font-size: 1.8rem;">Dispense Medicine</h1>
                <p style="margin: 0; color: var(--text-muted); font-size: 0.95rem;">Issue medicines to patients<?php echo $staff_issue_ready ? ' (and medical supplies to staff)' : ''; ?> following First-Expired-First-Out and track dispensing history.</p>
            </div>

            <div class="dispense-grid" style="display: grid; grid-template-columns: <?php echo ($can_dispense && $can_view_log) ? '380px 1fr' : '1fr'; ?>; gap: 25px; align-items: start;">
                
                <?php if($can_dispense): ?>
                <div class="unified-panel" style="margin: 0; position: sticky; top: 90px;">
                    <div class="panel-controls" style="background: rgba(16,185,129,0.1); border-top: 4px solid #10b981;">
                        <h3 style="margin:0; font-size: 1.1rem; color: #047857;"><?php echo mh_icon('droplet'); ?> <span id="issueTitleText">Issue Medicine</span></h3>
                    </div>
                    <form id="dispenseForm" method="POST" style="padding: 20px;">
                        <?php if ($queue_ctx): ?>
                            <input type="hidden" name="apt_id" value="<?php echo (int) $queue_ctx['id']; ?>">
                            <?php mh_queue_render_banner($queue_ctx, $queue_label, false); ?>
                        <?php endif; ?>
                        <?php echo mh_csrf_field(); ?>
                        <input type="hidden" name="dispense_item" value="1">
                        <input type="hidden" id="selectedBatchId">   <?php ?>
                        <div id="cartInputs"></div>
                        
                        <?php if ($staff_issue_ready): ?>
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label>Recipient</label>
                            <input type="hidden" name="recipient_type" id="recipientType" value="patient">
                            <div class="recipient-toggle" role="tablist">
                                <button type="button" class="active" data-recipient="patient" onclick="setRecipient('patient')">Patient</button>
                                <button type="button" data-recipient="staff" onclick="setRecipient('staff')">Medical Staff</button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label><span id="medLabelText">Select Medicine</span> <span style="color:red;">*</span></label>
                            <select id="medicineSelect" style="width: 100%;">
                                <option value="" disabled selected>Search generic or brand name...</option>
                                <?php foreach($medicine_groups as $grp): ?>
                                    <option value="<?php echo htmlspecialchars($grp['key']); ?>" data-cat="<?php echo htmlspecialchars((string) $grp['category']); ?>">
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

                        <div class="form-group" id="patientGroup" style="margin-bottom: 15px;">
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

                        <?php if ($staff_issue_ready): ?>
                        <div class="form-group" id="staffGroup" style="margin-bottom: 15px; display: none;">
                            <label>Select Staff Member <span style="color:red;">*</span></label>
                            <select name="recipient_staff_id" id="staffSelect" style="width: 100%;" disabled>
                                <option value="" disabled selected>Search doctor, midwife, dentist, BHW...</option>
                                <?php foreach ($staff_recipients as $role_label => $members): ?>
                                    <optgroup label="<?php echo htmlspecialchars($role_label); ?>">
                                        <?php foreach ($members as $m): ?>
                                            <option value="<?php echo (int) $m['id']; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name'] . ' (' . $role_label . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <label style="margin-top: 12px; display: block;">Purpose / Note <span style="color:red;">*</span></label>
                            <input type="text" name="purpose" id="purposeInput" maxlength="255" disabled placeholder="e.g., Home-visit kit, outreach activity, clinic use" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); box-sizing: border-box;">
                            <div class="stock-readout">Only Medical Supplies can be issued to staff. No patient record is needed; the stock-out is logged with the staff member and purpose.</div>
                        </div>
                        <?php endif; ?>

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
                            <input type="number" id="disp_total_qty" min="1" placeholder="e.g., 20" oninput="onManualQtyInput(); validateQty(true)" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-size: 1.1rem; font-weight: bold; color: #10b981; background: rgba(16,185,129,0.08); box-sizing: border-box;">
                        <div class="stock-readout" id="qtyHint"></div>
                        </div>

                        <button type="button" onclick="cartAdd()" style="width:100%; padding:10px; margin:-10px 0 14px; border:2px dashed #10b981; background:transparent; color:#047857; border-radius:6px; font-weight:700; cursor:pointer;">+ Add this medicine to the list</button>
                        <div id="dispenseCart" style="display:none; margin-bottom:18px; border:1px solid var(--border-color,#e2e8f0); border-radius:8px; overflow:hidden;">
                            <div style="padding:8px 12px; background:rgba(16,185,129,.1); font-weight:700; font-size:.85rem;">Medicines to dispense (<span id="cartCount">0</span>)</div>
                            <div id="cartList"></div>
                        </div>

                        <button type="button" class="btn-submit" onclick="confirmDispense()" style="width: 100%; padding: 12px; font-size: 1rem; background: #10b981; border:none; color:white; border-radius:6px; cursor:pointer; font-weight:bold; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);"><?php echo mh_icon('check-circle'); ?> <span id="dispenseBtnText">Dispense to Patient</span></button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if($can_view_log): ?>
                <div class="unified-panel" style="margin: 0;">
                    <div class="panel-controls">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);"><?php echo mh_icon('file-text'); ?> Dispensing Records</h3>
                        <input type="text" id="searchLog" class="search-box" placeholder="Search patient, staff, ID #, item, lot, date..." oninput="debouncedLoadLog()" autocomplete="off" style="width: 260px; max-width: 100%; padding: 6px 12px;">
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="unified-table" id="logTable">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Patient / Recipient</th>
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
    <?php mh_layout_close(); ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        <?php if($can_dispense): ?>
       
        const MEDICINE_GROUPS = <?php echo $js_medicine_groups; ?>;  
        const PRESELECT_GROUP_KEY = <?php echo $js_preselect_group_key; ?>;
        const PRESELECT_BATCH_ID = <?php echo (int) $preselect_batch_id; ?>;
        const EXPIRED_GLOBAL = <?php echo (int) $expired_batches; ?>;

        const GROUPS_BY_KEY = {};
        MEDICINE_GROUPS.forEach(g => { GROUPS_BY_KEY[g.key] = g; });

        const ALL_MED_OPTIONS = $('#medicineSelect option').filter(function () { return this.value !== ''; })
            .map(function () { return { value: this.value, text: this.text, cat: this.getAttribute('data-cat') || '' }; }).get();
        let recipient = 'patient';
        function isSupply(cat) { return /suppl/i.test(cat || ''); }

        let currentGroup = null;
        let currentBatch = null;
        let loadSeq = 0;
        let lastKey = null;
        let pendingLoad = Promise.resolve();   

        function escHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        }
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
            if (b.is_fefo) s += ' expires first';
            return s;
        }
        function expiredNoteHtml(n, medicineName) {
            if (!n) return '';
            return n + ' expired batch' + (n === 1 ? '' : 'es') + (medicineName ? ' of <strong>' + escHtml(medicineName) + '</strong>' : '')
                 + ' with stock on hand ' + (n === 1 ? 'is' : 'are') + ' hidden here and cannot be dispensed. Review '
                 + (n === 1 ? 'it' : 'them') + ' in the <a href="inventory.php">Supply Masterlist</a>.';
        }

        function showExpiredNote(n, medicineName) {
            const box = document.getElementById('expiredNote');
            if (!box) return;
            if (!n) { box.style.display = 'none'; return; }
            document.getElementById('expiredNoteText').innerHTML = expiredNoteHtml(n, medicineName);
            box.style.display = 'flex';
        }

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
                if (idx === 0) opt.selected = true;     
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
                .catch(() => ({ batches: group.batches, expired: null, live: false }))   
                .then(res => {
                    if (seq !== loadSeq) return;                 
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

            const pill = document.getElementById('batchStockReadout');
            const soon = currentBatch.days_to_expiry !== null && currentBatch.days_to_expiry !== undefined && currentBatch.days_to_expiry <= 90;
            pill.className = 'stock-pill' + (soon ? ' warn' : '');
            pill.innerHTML = 'Available: <strong>' + currentBatch.quantity.toLocaleString() + '</strong> ' + escHtml(currentBatch.unit)
                + (currentBatch.expiry_date ? ' &middot; exp. ' + escHtml(currentBatch.expiry_date) + ' (' + daysText(currentBatch.days_to_expiry) + ')' : '');

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

        $('#medicineSelect').on('change', onMedicineChange);   
        $('#batchSelect').on('change', onBatchChange);


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
            const boxOfPieces = currentGroup && /suppl|vaccine/i.test(currentGroup.category || '');
            const packWord = boxOfPieces ? 'Box' : (batch.pack_label || 'Pack');
            document.getElementById('disp_pack_label').textContent = packWord + (/(s|x|ch|sh)$/i.test(packWord) ? 'es' : 's');
            if (batch.packs_per_box && !boxOfPieces) {
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

        function onManualQtyInput() {
            document.getElementById('disp_box_qty').value = '';
            document.getElementById('disp_pack_qty').value = '';
            document.getElementById('disp_loose_qty').value = '';
        }

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

        function rebuildMedicineOptions() {
            const staff = recipient === 'staff';
            const $m = $('#medicineSelect');
            const list = ALL_MED_OPTIONS.filter(o => !staff || isSupply(o.cat));
            if ($m.data('select2')) { $m.select2('destroy'); }
            $m.empty().append($('<option>').val('').prop('disabled', true).prop('selected', true).text(staff ? 'Search medical supply...' : 'Search generic or brand name...'));
            list.forEach(o => { $m.append($('<option>').val(o.value).text(o.text).attr('data-cat', o.cat)); });
            if (!list.length) { $m.append($('<option>').val('').prop('disabled', true).text(staff ? 'No medical supplies in stock' : 'No active stock available')); }
            mhSelect2('#medicineSelect', { placeholder: staff ? 'Search medical supply...' : 'Search generic or brand name...' });
            $m.val('').trigger('change');
        }

        function setRecipient(mode) {
            if (mode === recipient || !document.getElementById('recipientType')) return;
            if (mode === 'staff') {
                const bad = cart.filter(c => !isSupply(c.cat));
                if (bad.length) {
                    cart = cart.filter(c => isSupply(c.cat));
                    renderCart();
                    mh_alert('Only Medical Supplies can be issued to staff, so these were removed from the list: ' + bad.map(c => c.name).join(', ') + '.', 'Items Removed', 'warning');
                }
            }
            recipient = mode;
            const staff = mode === 'staff';
            document.getElementById('recipientType').value = mode;
            document.querySelectorAll('.recipient-toggle button').forEach(b => b.classList.toggle('active', b.dataset.recipient === mode));
            document.getElementById('patientGroup').style.display = staff ? 'none' : '';
            document.getElementById('staffGroup').style.display = staff ? '' : 'none';
            $('#patientSelect').prop('disabled', staff);
            $('#staffSelect').prop('disabled', !staff);
            $('#purposeInput').prop('disabled', !staff);
            document.getElementById('issueTitleText').textContent = staff ? 'Issue Supplies to Staff' : 'Issue Medicine';
            document.getElementById('medLabelText').textContent = staff ? 'Select Medical Supply' : 'Select Medicine';
            document.getElementById('dispenseBtnText').textContent = staff ? 'Issue to Staff' : 'Dispense to Patient';
            rebuildMedicineOptions();
        }

        function restoreForm(o) {
            if (o.recipient_type === 'staff') {
                setRecipient('staff');
                if (o.staff_id) { $('#staffSelect').val(String(o.staff_id)).trigger('change'); }
                document.getElementById('purposeInput').value = o.purpose || '';
            }
            (o.lines || []).forEach(function (ln) {
                const g = MEDICINE_GROUPS.find(x => x.batches.some(b => b.id === ln.inventory_id));
                if (!g) return;
                const b = g.batches.find(x => x.id === ln.inventory_id);
                cart.push({ id: ln.inventory_id, qty: parseInt(ln.quantity, 10) || 0, unit: b.unit, lot: b.batch_number,
                            name: g.item_name + ' (' + g.brand_name + ')', override: false, cat: g.category });
            });
            renderCart();
            if (o.patient_id && o.recipient_type !== 'staff') { $('#patientSelect').val(String(o.patient_id)).trigger('change'); }
        }

        window.onload = function() {
            mhSelect2('#patientSelect', { placeholder: 'Search by name or ID...' });
            <?php if ($queue_patient_id > 0): ?>
            $('#patientSelect').val('<?php echo (int) $queue_patient_id; ?>').trigger('change');   
            <?php endif; ?>

            mhSelect2('#medicineSelect', { placeholder: 'Search generic or brand name...' });
            if (document.getElementById('staffSelect')) { mhSelect2('#staffSelect', { placeholder: 'Search staff...' }); }

            <?php if ($error_msg !== '' && !empty($old['lines'])): ?>
            restoreForm(<?php echo json_encode($old, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
            <?php elseif ($preselect_group_key !== null): ?>
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


        let cart = [];

        function renderCart() {
            const box = document.getElementById('dispenseCart');
            const list = document.getElementById('cartList');
            const inputs = document.getElementById('cartInputs');
            document.getElementById('cartCount').textContent = cart.length;
            box.style.display = cart.length ? 'block' : 'none';
            list.innerHTML = cart.map((c, i) =>
                '<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 12px; border-top:1px solid var(--border-color,#e2e8f0);">' +
                    '<div><strong>' + escHtml(c.name) + '</strong><div style="font-size:.8rem; opacity:.75;">Lot ' + escHtml(c.lot || 'N/A') + (c.override ? ' &middot; <span style="color:#b45309;">FEFO override</span>' : '') + '</div></div>' +
                    '<div style="white-space:nowrap;"><strong>' + Number(c.qty).toLocaleString() + '</strong> ' + escHtml(c.unit || '') +
                    ' <button type="button" onclick="cartRemove(' + i + ')" aria-label="Remove" style="margin-left:8px; border:none; background:transparent; color:#dc2626; font-size:1.1rem; cursor:pointer;">&times;</button></div>' +
                '</div>').join('');
            inputs.innerHTML = cart.map(c =>
                '<input type="hidden" name="inventory_id[]" value="' + Number(c.id) + '">' +
                '<input type="hidden" name="quantity[]" value="' + Number(c.qty) + '">').join('');
        }

        function cartRemove(i) { cart.splice(i, 1); renderCart(); }

        function clearLineEditor() {
            ['disp_box_qty', 'disp_pack_qty', 'disp_loose_qty'].forEach(function (id) { const e = document.getElementById(id); if (e) e.value = ''; });
            $('#medicineSelect').val('').trigger('change');   
        }

        function cartAdd() {
            const qty = parseInt(document.getElementById('disp_total_qty').value, 10);
            if (!currentGroup || !currentBatch || !qty || qty < 1) {
                mh_alert('Pick a medicine, a batch / lot and a quantity first, then add it to the list.', 'Incomplete Line', 'warning');
                return false;
            }
            if (!validateQty(false)) {
                mh_alert('Only ' + currentBatch.quantity.toLocaleString() + ' ' + currentBatch.unit + ' left in this batch.', 'Not Enough Stock', 'danger');
                return false;
            }
            const already = cart.filter(c => c.id === currentBatch.id).reduce((s, c) => s + c.qty, 0);
            if (already + qty > currentBatch.quantity) {
                mh_alert('This lot has only ' + currentBatch.quantity.toLocaleString() + ' ' + currentBatch.unit + ' and ' + already.toLocaleString() + ' is already on your list.', 'Not Enough Stock', 'danger');
                return false;
            }
            const override = document.getElementById('fefoWarningBanner').style.display !== 'none';
            const hit = cart.find(c => c.id === currentBatch.id);
            if (hit) { hit.qty += qty; hit.override = hit.override || override; }
            else {
                cart.push({ id: currentBatch.id, qty: qty, unit: currentBatch.unit, lot: currentBatch.batch_number,
                            name: currentGroup.item_name + ' (' + currentGroup.brand_name + ')', override: override, cat: currentGroup.category });
            }
            renderCart();
            clearLineEditor();
            return true;
        }

        function confirmDispense() {
            const patientSelect = document.getElementById('patientSelect');
            const lineStarted = !!(currentBatch && document.getElementById('disp_total_qty').value);
            if (lineStarted && !cartAdd()) { return; }
            if (!cart.length) {
                mh_alert('Add at least one medicine (choose a medicine, a batch / lot and a quantity).', 'Nothing to Dispense', 'warning');
                return;
            }
            const toStaff = recipient === 'staff';
            let recipientLine;
            if (toStaff) {
                const staffSelect = document.getElementById('staffSelect');
                const purpose = document.getElementById('purposeInput').value.trim();
                if (!staffSelect.value) { mh_alert('Please select the medical staff member receiving the supplies.', 'Incomplete Form', 'warning'); return; }
                if (purpose.length < 3) { mh_alert('Please enter a brief purpose or note (for example: home visit kit, outreach, clinic use).', 'Purpose Needed', 'warning'); return; }
                recipientLine = 'Issue to <strong>' + escHtml(staffSelect.options[staffSelect.selectedIndex].text) + '</strong><br><span style="opacity:.85;">Purpose: ' + escHtml(purpose) + '</span>';
            } else {
                if (!patientSelect.value) {
                    mh_alert('Please select the patient receiving the medicine.', 'Incomplete Form', 'warning');
                    return;
                }
                recipientLine = 'Dispense to <strong>' + escHtml(patientSelect.options[patientSelect.selectedIndex].text) + '</strong>';
            }
            const anyOverride = cart.some(c => c.override);
            const rows = cart.map(c => '<li><strong>' + escHtml(Number(c.qty).toLocaleString()) + ' ' + escHtml(c.unit || '') + '</strong> of <strong>' + escHtml(c.name) + '</strong> (Lot ' + escHtml(c.lot || 'N/A') + ')' + (c.override ? ' <em>- FEFO override</em>' : '') + '</li>').join('');

            mh_confirm(
                recipientLine + ':<ul style="margin:10px 0 0 18px; text-align:left;">' + rows + '</ul>' +
                (cart.length > 1 ? '<br><span style="opacity:.8;">All medicines are dispensed together; if one cannot be, nothing is dispensed.</span>' : '') +
                (anyOverride ? '<br><br><span class="mh-text-warning">An earlier-expiring batch still has stock for at least one item - this will be logged as a FEFO override.</span>' : ''),
                (toStaff ? 'Confirm Issuance' : 'Confirm Dispensing') + (anyOverride ? ' (FEFO Override)' : ''),
                anyOverride ? 'warning' : 'info',
                toStaff ? 'Yes, Issue' : 'Yes, Dispense',
                { html: true }
            ).then(function (ok) {
                if (ok) {
                    document.getElementById('dispenseForm').submit();
                }
            });
        }

        <?php endif; ?>

        <?php if ($can_view_log): ?>
        let logTimer = null, logBusy = false;
        function loadLog() {
            if (logBusy) return;
            logBusy = true;
            const q = document.getElementById('searchLog').value.trim();
            fetch('dispense_medicine.php?log_rows=1&q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' })
                .then(r => (r.ok && r.headers.get('X-Dispense-Log') === '1') ? r.text() : null)
                .then(html => {
                    if (html === null) return;                  
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
<?php mh_layout_end(); ?>