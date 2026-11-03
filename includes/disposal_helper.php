<?php
/**
 * Expired / damaged medicine disposal (Step 7).
 *
 *   mh_disp_dispose($conn, $batchId, $input, $staffId)  -> [ok, error|null, newRemainingQty]
 *   mh_disp_history($conn, $filters)                    -> rows for the Disposal History page
 *
 * A disposal removes quantity from ONE inventory batch (a batch that reaches 0 is archived) and writes a permanent,
 * self-contained row to `inventory_disposals` (item details are copied, so the record stays readable after the batch is
 * edited or archived). Compatible with PHP 7.2+.
 */

if (!function_exists('mh_disp_forms')) {

    /** The three kinds of stock a disposal can concern. */
    function mh_disp_forms()
    {
        return ['liquid' => 'Liquid (syrup, drops, injectable)', 'capsule' => 'Capsule / Tablet', 'piece' => 'Item / Piece'];
    }
    function mh_disp_reasons()
    {
        return ['expired' => 'Expired', 'damaged' => 'Damaged / contaminated', 'recalled' => 'Recalled', 'other' => 'Other'];
    }
    function mh_disp_methods()
    {
        return ['Incineration' => 'Incineration', 'Returned to supplier' => 'Returned to supplier',
                'Turned over to LGU / city health office' => 'Turned over to LGU / city health office',
                'Safe disposal (waste contractor)' => 'Safe disposal (waste contractor)',
                'Sharps / medical waste container' => 'Sharps / medical waste container', 'Other' => 'Other'];
    }

    /** Best guess of the stock type from the batch's unit and category (the dialog lets staff change it). */
    function mh_disp_guess_form($unit, $category = '')
    {
        $u = strtolower(trim((string) $unit)); $c = strtolower(trim((string) $category));
        if (preg_match('/\b(ml|l|liter|litre|bottle|bottles|vial|vials|ampule|ampules|ampoule|ampoules|sachet|sachets)\b/', $u)
            || preg_match('/syrup|suspension|drops|solution|injection|liquid/', $c)) { return 'liquid'; }
        if (preg_match('/\b(cap|caps|capsule|capsules|tab|tabs|tablet|tablets|pill|pills)\b/', $u)) { return 'capsule'; }
        return 'piece';
    }

    /** Same safety net used by the other modules: the page creates the table itself if the SQL file was never run. */
    function mh_disp_ensure_table(mysqli $conn)
    {
        if (!empty($_SESSION['mh_disp_tbl_v1'])) { return; }
        $ok = @$conn->query("CREATE TABLE IF NOT EXISTS inventory_disposals (
            id INT(11) NOT NULL AUTO_INCREMENT,
            inventory_id INT(11) DEFAULT NULL,
            item_name VARCHAR(100) NOT NULL,
            brand_name VARCHAR(255) DEFAULT NULL,
            category VARCHAR(50) DEFAULT NULL,
            tracking_code VARCHAR(100) DEFAULT NULL,
            batch_number VARCHAR(50) DEFAULT NULL,
            item_form VARCHAR(10) NOT NULL DEFAULT 'piece',
            unit VARCHAR(20) NOT NULL,
            expiry_date DATE DEFAULT NULL,
            quantity_before INT(11) NOT NULL,
            quantity_disposed INT(11) NOT NULL,
            quantity_after INT(11) NOT NULL,
            reason VARCHAR(20) NOT NULL DEFAULT 'expired',
            method VARCHAR(80) NOT NULL,
            witness VARCHAR(100) DEFAULT NULL,
            remarks VARCHAR(500) DEFAULT NULL,
            disposed_on DATE NOT NULL,
            disposed_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_disp_date (disposed_on),
            KEY idx_disp_inventory (inventory_id),
            KEY idx_disp_reason (reason, disposed_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($ok) { $_SESSION['mh_disp_tbl_v1'] = true; } else { error_log('mh_disp_ensure_table: ' . $conn->error); }
    }

    /**
     * @param array $in amount, item_form, reason, method, disposed_on (Y-m-d), witness, remarks
     * @return array [ok(bool), error(string|null), remaining(int)]
     */
    function mh_disp_dispose(mysqli $conn, $batchId, array $in, $staffId)
    {
        mh_disp_ensure_table($conn);
        $batchId = (int) $batchId; $staffId = (int) $staffId;
        $forms = mh_disp_forms(); $reasons = mh_disp_reasons(); $methods = mh_disp_methods();

        $amount  = (int) ($in['amount'] ?? 0);
        $form    = (string) ($in['item_form'] ?? '');
        $reason  = (string) ($in['reason'] ?? '');
        $method  = (string) ($in['method'] ?? '');
        $on      = (string) ($in['disposed_on'] ?? '');
        $witness = mb_substr(trim((string) ($in['witness'] ?? '')), 0, 100);
        $remarks = mb_substr(trim((string) ($in['remarks'] ?? '')), 0, 500);
        $today   = date('Y-m-d');

        if (!isset($forms[$form]))     { return [false, 'Choose the type of stock being disposed (liquid, capsule/tablet or item/piece).', 0]; }
        if (!isset($reasons[$reason])) { return [false, 'Choose the reason for disposal.', 0]; }
        if (!isset($methods[$method])) { return [false, 'Choose how the stock was disposed of.', 0]; }
        if ($amount < 1)               { return [false, 'Enter how much is being disposed (at least 1).', 0]; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today || $on < '2000-01-01') { return [false, 'Enter a valid disposal date (not in the future).', 0]; }
        if (($reason === 'damaged' || $reason === 'other') && mb_strlen($remarks) < 5) { return [false, 'Please explain the reason in the remarks.', 0]; }

        $conn->begin_transaction();
        try {
            $st = $conn->prepare("SELECT * FROM inventory WHERE id = ? AND status = 1 FOR UPDATE");
            $st->bind_param('i', $batchId); $st->execute();
            $b = $st->get_result()->fetch_assoc(); $st->close();
            if (!$b) { $conn->rollback(); return [false, 'That batch no longer exists or was already archived.', 0]; }

            $before = (int) $b['quantity'];
            if ($amount > $before) { $conn->rollback(); return [false, 'Only ' . number_format($before) . ' ' . $b['unit'] . ' left in this batch.', $before]; }
            if ($reason === 'expired' && !(!empty($b['expiry_date']) && $b['expiry_date'] < $today)) {
                $conn->rollback();
                return [false, 'This batch has not expired yet. Choose "Damaged", "Recalled" or "Other" if it has to be removed early.', $before];
            }

            $after  = $before - $amount;
            $status = $after <= 0 ? 0 : 1;   // a fully disposed batch leaves the active masterlist (the disposal record stays)
            $st = $conn->prepare("UPDATE inventory SET quantity = ?, status = ? WHERE id = ?");
            $st->bind_param('iii', $after, $status, $batchId);
            if (!$st->execute()) { throw new RuntimeException($st->error); }
            $st->close();

            $w = $witness === '' ? null : $witness; $r = $remarks === '' ? null : $remarks;
            $st = $conn->prepare("INSERT INTO inventory_disposals
                (inventory_id, item_name, brand_name, category, tracking_code, batch_number, item_form, unit, expiry_date,
                 quantity_before, quantity_disposed, quantity_after, reason, method, witness, remarks, disposed_on, disposed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param('issssssssiiisssssi', $batchId, $b['item_name'], $b['brand_name'], $b['category'], $b['tracking_code'], $b['batch_number'],
                $form, $b['unit'], $b['expiry_date'], $before, $amount, $after, $reason, $method, $w, $r, $on, $staffId);
            if (!$st->execute()) { throw new RuntimeException($st->error); }
            $st->close();

            $conn->commit();
            return [true, null, $after];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('mh_disp_dispose: ' . $e->getMessage());
            return [false, 'The disposal could not be saved. Nothing was changed.', 0];
        }
    }

    /** Disposal records, newest first. $f: from, to, reason, form, q. At most 500 rows. */
    function mh_disp_history(mysqli $conn, array $f)
    {
        mh_disp_ensure_table($conn);
        $where = []; $params = []; $types = '';
        $date = function ($v) { return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : ''; };
        if (($from = $date($f['from'] ?? '')) !== '') { $where[] = 'd.disposed_on >= ?'; $params[] = $from; $types .= 's'; }
        if (($to = $date($f['to'] ?? '')) !== '')     { $where[] = 'd.disposed_on <= ?'; $params[] = $to;   $types .= 's'; }
        if (isset(mh_disp_reasons()[$f['reason'] ?? ''])) { $where[] = 'd.reason = ?';    $params[] = $f['reason']; $types .= 's'; }
        if (isset(mh_disp_forms()[$f['form'] ?? '']))     { $where[] = 'd.item_form = ?'; $params[] = $f['form'];   $types .= 's'; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 60), '\\%_') . '%';
            $where[] = '(d.item_name LIKE ? OR d.brand_name LIKE ? OR d.batch_number LIKE ? OR d.tracking_code LIKE ?)';
            array_push($params, $like, $like, $like, $like); $types .= 'ssss';
        }
        $sql = "SELECT d.*, s.first_name AS s_first, s.last_name AS s_last
                  FROM inventory_disposals d LEFT JOIN staff s ON s.id = d.disposed_by"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . " ORDER BY d.disposed_on DESC, d.id DESC LIMIT 500";
        $st = $conn->prepare($sql);
        if (!$st) { return []; }
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }
}
