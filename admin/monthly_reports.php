<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

set_include_path(dirname(__DIR__) . PATH_SEPARATOR . get_include_path());

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';

if (function_exists('mh_refresh_staff_authority')) { mh_refresh_staff_authority(); }


   1. ROLE -> REPORT SCOPE
    
$role = strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));

$scope_map = [
    'health_head' => 'master', 'super_admin' => 'master', 'admin' => 'master',
    'doctor'      => 'doctor',
    'dental'      => 'dental', 'dentist' => 'dental',
    'midwife'     => 'midwife',
    'inventory'   => 'inventory', 'encoder' => 'inventory',
];
$scope = $scope_map[$role] ?? null;
if ($scope === null) { mh_deny_access(); exit; }
if ($scope === 'inventory' && !mh_has_permission('view_stock')) { mh_deny_access(); exit; }

$scope_labels = [
    'master'    => 'All Departments',
    'doctor'    => 'General Consultation',
    'dental'    => 'Dental Clinic',
    'midwife'   => 'Maternal Care and Child Immunization',
    'inventory' => 'Medicine Inventory',
];
$scope_label = $scope_labels[$scope];

$system_name = $system_name ?? mh_setting('system_name');
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

   2. MONTH / YEAR FILTER (validated)
$this_year = (int) date('Y');
$month = (int) ($_GET['month'] ?? date('n'));
$year  = (int) ($_GET['year'] ?? $this_year);
if ($month < 1 || $month > 12) { $month = (int) date('n'); }
if ($year < $this_year - 5 || $year > $this_year) { $year = $this_year; }

$start        = sprintf('%04d-%02d-01', $year, $month);
$end          = date('Y-m-d', strtotime($start . ' +1 month'));
$P            = [$start, $end];                       
$period_label = date('F Y', strtotime($start));


if (($_GET['export'] ?? '') === 'csv') {
    require_once __DIR__ . '/../includes/report_export.php';
    mh_report_csv_export('monthly_report_' . date('Y_m', strtotime($start)) . '.csv', 'Monthly Clinic Report: ' . $period_label);
}

$module_title = "Monthly Clinic Report";


   3. SMALL DB HELPERS
$run = function ($sql, $types = '', array $params = []) use ($conn) {
    $st = @$conn->prepare($sql);
    if (!$st) { error_log('monthly_reports query failed: ' . $conn->error); return []; }
    if ($params) { $st->bind_param($types, ...$params); }
    if (!$st->execute()) { error_log('monthly_reports execute failed: ' . $st->error); $st->close(); return []; }
    $res  = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
};
$scalar = function ($sql, $types = '', array $params = []) use ($run) {
    $r = $run($sql, $types, $params);
    if (!$r) { return 0; }
    $row = array_values($r[0]);
    return (int) ($row[0] ?? 0);
};
$meta_ok = function ($table, $col = null) use ($conn) {
    static $cache = [];
    $key = $table . '.' . ($col === null ? '' : $col);
    if (!isset($cache[$key])) {
        $t   = str_replace('`', '', (string) $table);
        $sql = $col === null
            ? "SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'"
            : "SHOW COLUMNS FROM `$t` LIKE '" . $conn->real_escape_string((string) $col) . "'";
        $res = @$conn->query($sql);
        $cache[$key] = ($res instanceof mysqli_result) && $res->num_rows > 0;
        if ($res instanceof mysqli_result) { $res->free(); }
    }
    return $cache[$key];
};
$pk = function ($alias, $table) use ($meta_ok) {
    return $meta_ok($table, 'patient_name')
        ? "COALESCE(CAST($alias.patient_id AS CHAR), CONCAT('n:', $alias.patient_name))"
        : "CAST($alias.patient_id AS CHAR)";
};

   4. REPORT QUERY BUILDING BLOCKS (nothing runs until a branch calls it)
$visit_stats = function ($table, $alias, $date_col) use ($run, $pk, $P) {
    $k = $pk($alias, $table);
    $r = $run("SELECT COUNT(*) AS visits, COUNT(DISTINCT $k) AS patients FROM `$table` $alias
               WHERE $alias.`$date_col` >= ? AND $alias.`$date_col` < ?", 'ss', $P);
    return ['visits' => (int) ($r[0]['visits'] ?? 0), 'patients' => (int) ($r[0]['patients'] ?? 0)];
};

$dx_consult = "SELECT COALESCE(NULLIF(TRIM(c.diagnosis), ''), NULLIF(TRIM(c.chief_complaints), '')) AS txt
               FROM consultations c WHERE c.visit_date >= ? AND c.visit_date < ?";
$dx_dental = function () use ($meta_ok) {
    $cols = [];
    foreach (['diagnosis', 'chief_complaint'] as $c) {
        if ($meta_ok('dental_records', $c)) { $cols[] = "NULLIF(TRIM(d.`$c`), '')"; }
    }
    if (!$cols) { return null; }
    return "SELECT COALESCE(" . implode(', ', $cols) . ") AS txt FROM dental_records d
            WHERE d.visit_date >= ? AND d.visit_date < ?";
};
$dx_child = "SELECT NULLIF(TRIM(cl.chief_complaints), '') AS txt
             FROM child_consultation_logs cl WHERE cl.consultation_date >= ? AND cl.consultation_date < ?";

$top_texts = function (array $parts, $limit = 10) use ($run, $P) {
    $parts = array_values(array_filter($parts));
    if (!$parts) { return []; }
    $types = ''; $params = [];
    foreach ($parts as $unused) { $types .= 'ss'; array_push($params, $P[0], $P[1]); }
    return $run("SELECT MIN(TRIM(txt)) AS label, COUNT(*) AS n
                 FROM (" . implode(' UNION ALL ', $parts) . ") t
                 WHERE txt IS NOT NULL AND TRIM(txt) <> ''
                 GROUP BY LOWER(TRIM(txt)) ORDER BY n DESC, label ASC LIMIT " . (int) $limit, $types, $params);
};

$vaccines = [
    'BCG' => 'bcg_date', 'Hepatitis B (within 24 hrs)' => 'hep_b_24hrs_date',
    'Penta/OPV 1' => 'penta_1_opv_1_date', 'Penta/OPV 2' => 'penta_2_opv_2_date', 'Penta/OPV 3' => 'penta_opv_3_date',
    'PCV 1' => 'pcv_1_date', 'PCV 2' => 'pcv_2_date', 'PCV 3' => 'pcv_3_date',
    'MR' => 'mr_date', 'MMR' => 'mmr_date',
];
$child_doses = function () use ($run, $meta_ok, $vaccines, $P) {
    $labels = array_keys($vaccines); $parts = []; $types = ''; $params = [];
    foreach (array_values($vaccines) as $i => $col) {
        if (!$meta_ok('child_immunization_records', $col)) { continue; }
        $parts[] = "SELECT $i AS idx, COUNT(*) AS n FROM child_immunization_records WHERE `$col` >= ? AND `$col` < ?";
        $types .= 'ss'; array_push($params, $P[0], $P[1]);
    }
    $out = [];
    if ($parts) {
        foreach ($run(implode(' UNION ALL ', $parts), $types, $params) as $r) {
            if ((int) $r['n'] > 0) { $out[$labels[(int) $r['idx']]] = (int) $r['n']; }
        }
    }
    foreach ($run("SELECT TRIM(vaccine_name) AS vaccine, COUNT(*) AS n FROM immunization_records
                   WHERE date_administered >= ? AND date_administered < ? GROUP BY TRIM(vaccine_name)", 'ss', $P) as $r) {
        if ($r['vaccine'] !== '' && (int) $r['n'] > 0) { $out[$r['vaccine']] = ($out[$r['vaccine']] ?? 0) + (int) $r['n']; }
    }
    arsort($out);
    return $out;
};
$child_keys = function () use ($meta_ok, $pk, $vaccines, $P) {
    $conds = []; $types = ''; $params = [];
    foreach ($vaccines as $col) {
        if (!$meta_ok('child_immunization_records', $col)) { continue; }
        $conds[] = "(r.`$col` >= ? AND r.`$col` < ?)";
        $types .= 'ss'; array_push($params, $P[0], $P[1]);
    }
    $k = $pk('r', 'child_immunization_records');
    $sql = '';
    if ($conds) { $sql .= "SELECT $k AS k FROM child_immunization_records r WHERE " . implode(' OR ', $conds) . ' UNION ALL '; }
    $sql .= "SELECT $k AS k FROM child_consultation_logs cl JOIN child_immunization_records r ON r.id = cl.child_record_id
             WHERE cl.consultation_date >= ? AND cl.consultation_date < ?
             UNION ALL SELECT CAST(ir.patient_id AS CHAR) AS k FROM immunization_records ir
             WHERE ir.date_administered >= ? AND ir.date_administered < ?";
    $types .= 'ssss'; array_push($params, $P[0], $P[1], $P[0], $P[1]);
    return [$sql, $types, $params];
};

$inv_parts = function () use ($meta_ok) {
    $joins = "LEFT JOIN (SELECT inventory_id, SUM(quantity) AS q FROM dispensations GROUP BY inventory_id) dq ON dq.inventory_id = i.id";
    $expr  = "(i.quantity + COALESCE(dq.q, 0)";
    if ($meta_ok('inventory_disposals')) {
        $joins .= " LEFT JOIN (SELECT inventory_id, SUM(quantity_disposed) AS q FROM inventory_disposals GROUP BY inventory_id) xq ON xq.inventory_id = i.id";
        $expr  .= " + COALESCE(xq.q, 0)";
    }
    return [$joins, $expr . ")"];
};
$inv_totals = function () use ($run, $scalar, $meta_ok, $inv_parts, $P) {
    list($joins, $orig) = $inv_parts();
    $in = $run("SELECT COUNT(*) AS batches, COALESCE(SUM($orig), 0) AS units FROM inventory i $joins
                WHERE i.created_at >= ? AND i.created_at < ?", 'ss', $P);
    $out = $run("SELECT COALESCE(SUM(quantity), 0) AS units, COUNT(*) AS transactions, COUNT(DISTINCT patient_id) AS patients
                 FROM dispensations WHERE dispense_date >= ? AND dispense_date < ?", 'ss', $P);
    $disposed = $meta_ok('inventory_disposals')
        ? $scalar("SELECT COALESCE(SUM(quantity_disposed), 0) FROM inventory_disposals WHERE disposed_on >= ? AND disposed_on < ?", 'ss', $P)
        : 0;
    return [
        'batches'      => (int) ($in[0]['batches'] ?? 0),
        'received'     => (int) ($in[0]['units'] ?? 0),
        'dispensed'    => (int) ($out[0]['units'] ?? 0),
        'transactions' => (int) ($out[0]['transactions'] ?? 0),
        'patients'     => (int) ($out[0]['patients'] ?? 0),
        'disposed'     => $disposed,
    ];
};
$inv_received_rows = function () use ($run, $inv_parts, $P) {
    list($joins, $orig) = $inv_parts();
    return $run("SELECT i.created_at, i.item_name, i.brand_name, i.batch_number, i.unit, i.expiry_date, $orig AS original_qty
                 FROM inventory i $joins WHERE i.created_at >= ? AND i.created_at < ?
                 ORDER BY i.created_at DESC, i.item_name ASC LIMIT 200", 'ss', $P);
};
$inv_dispensed_rows = function () use ($run, $P) {
    return $run("SELECT i.item_name, MIN(i.unit) AS unit, SUM(d.quantity) AS units, COUNT(*) AS times, COUNT(DISTINCT d.patient_id) AS patients
                 FROM dispensations d JOIN inventory i ON i.id = d.inventory_id
                 WHERE d.dispense_date >= ? AND d.dispense_date < ?
                 GROUP BY i.item_name ORDER BY units DESC, i.item_name ASC LIMIT 200", 'ss', $P);
};
$LOW_STOCK_THRESHOLD = 20;   
$inv_low_rows = function () use ($run, $LOW_STOCK_THRESHOLD) {
    return $run("SELECT i.item_name, MIN(i.unit) AS unit, SUM(i.quantity) AS total
                 FROM inventory i WHERE i.status = 1
                 GROUP BY i.item_name HAVING SUM(i.quantity) <= ?
                 ORDER BY total ASC, i.item_name ASC", 'i', [$LOW_STOCK_THRESHOLD]);
};

   5. RENDER HELPERS
$fmt = function ($v) use ($h) {
    return (is_int($v) || is_float($v)) ? number_format($v) : $h($v);
};
$kpis = function (array $items) use ($h) {
    echo '<div class="mr-kpis print-avoid">';
    foreach ($items as $label => $value) {
        echo '<div class="mr-kpi"><span>' . $h($label) . '</span><strong>'
           . (is_int($value) ? number_format($value) : $h($value)) . '</strong></div>';
    }
    echo '</div>';
};
$tbl = function ($title, array $heads, array $rows, array $numCols = [], $note = '') use ($h, $fmt) {
    echo '<section class="mr-section print-avoid"><h3 class="mr-h">' . $h($title) . '</h3>';
    if ($note !== '') { echo '<p class="mr-note">' . $h($note) . '</p>'; }
    echo '<div class="mr-tablewrap"><table class="unified-table mr-table"><thead><tr>';
    foreach ($heads as $i => $label) {
        echo '<th' . (in_array($i, $numCols, true) ? ' class="mr-num"' : '') . '>' . $h($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . count($heads) . '" class="mr-empty">No records for this period.</td></tr>';
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_values($row) as $i => $cell) {
            echo '<td' . (in_array($i, $numCols, true) ? ' class="mr-num"' : '') . '>' . $fmt($cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
};
$ranked = function (array $rows) {          
    $out = []; $i = 0;
    foreach ($rows as $r) { $out[] = [++$i, $r['label'], (int) $r['n']]; }
    return $out;
};
$date_fmt = function ($d) { return $d ? date('M d, Y', strtotime($d)) : ''; };

require_once __DIR__ . '/../includes/layout/layout.php';

mh_layout_head([
    'title'           => 'Monthly Reports',
    'print_title'     => $module_title,
    'print_note'      => 'Period: ' . $period_label . ' | Scope: ' . $scope_label,
    'external_styles' => ['../assets/css/print.css'],   
]);
?>
    <style>
        .mr-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; padding: 18px 24px; }
        .mr-filter { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin: 0; }
        .mr-filter label { display: block; margin-bottom: 4px; font-size: .74rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted, #64748b); }
        .mr-report { padding: 24px 28px 32px; }
        .mr-title h2 { margin: 0 0 4px; font-size: 1.35rem; color: var(--heading-color, #1e293b); }
        .mr-title p { margin: 0 0 20px; color: var(--text-muted, #64748b); }
        .mr-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 28px; }
        .mr-kpi { border: 1px solid var(--border-color, #e2e8f0); border-radius: 10px; padding: 14px 16px; }
        .mr-kpi span { display: block; font-size: .8rem; color: var(--text-muted, #64748b); }
        .mr-kpi strong { display: block; margin-top: 2px; font-size: 1.6rem; line-height: 1.2; color: var(--heading-color, #1e293b); }
        .mr-section { margin-bottom: 30px; }
        .mr-h { margin: 0 0 10px; font-size: 1.05rem; color: var(--heading-color, #1e293b); }
        .mr-note { margin: -4px 0 10px; font-size: .85rem; color: var(--text-muted, #64748b); }
        .mr-table { width: 100%; border-collapse: collapse; }
        .mr-num { text-align: right !important; white-space: nowrap; }
        .mr-empty { text-align: center; padding: 22px 10px !important; color: var(--text-muted, #64748b); }
        @media screen { .mr-tablewrap { overflow-x: auto; } }
    </style>
<?php mh_layout_open(); ?>

        <div class="dashboard-container">

            
            <div class="unified-panel mr-toolbar no-print">
                <form method="get" class="mr-filter">
                    <div>
                        <label for="mrMonth">Month</label>
                        <select id="mrMonth" name="month">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>"<?php echo $m === $month ? ' selected' : ''; ?>><?php echo $h(date('F', mktime(0, 0, 0, $m, 1))); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="mrYear">Year</label>
                        <select id="mrYear" name="year">
                            <?php for ($y = $this_year; $y >= $this_year - 5; $y--): ?>
                                <option value="<?php echo $y; ?>"<?php echo $y === $year ? ' selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-submit btn-sm">Apply</button>
                </form>
                <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
                <a class="btn btn-primary" style="text-decoration:none;" href="?month=<?php echo (int) $month; ?>&amp;year=<?php echo (int) $year; ?>&amp;export=csv">Download Excel (.csv)</a>
            </div>

<?php 
$module_title = "Monthly Clinic Report"; 
include 'includes/print_header.php'; 
?>
            <div class="unified-panel mr-report">
                <div class="mr-title no-print">
                    <h2>Monthly Clinic Report: <?php echo $h($period_label); ?></h2>
                    <p>Scope: <?php echo $h($scope_label); ?></p>
                </div>

<?php
try {
if ($scope === 'master') {
    $gc  = $visit_stats('consultations', 'c', 'visit_date');
    $den = $visit_stats('dental_records', 'd', 'visit_date');
    $mat = $visit_stats('maternal_records', 'm', 'visit_date');

    $doses = $child_doses();
    list($csql, $ctypes, $cparams) = $child_keys();
    $child_patients = $scalar("SELECT COUNT(DISTINCT k) FROM ($csql) u WHERE k IS NOT NULL", $ctypes, $cparams);
    $child_logs     = $scalar("SELECT COUNT(*) FROM child_consultation_logs WHERE consultation_date >= ? AND consultation_date < ?", 'ss', $P);
    $child_visits   = $child_logs + array_sum($doses);

    $parts = []; $types = ''; $params = [];
    foreach ([['consultations', 'c'], ['dental_records', 'd'], ['maternal_records', 'm']] as $src) {
        $parts[] = 'SELECT ' . $pk($src[1], $src[0]) . " AS k FROM `{$src[0]}` {$src[1]} WHERE {$src[1]}.visit_date >= ? AND {$src[1]}.visit_date < ?";
        $types .= 'ss'; array_push($params, $start, $end);
    }
    $parts[] = $csql; $types .= $ctypes; $params = array_merge($params, $cparams);
    $unique_all = $scalar("SELECT COUNT(DISTINCT k) FROM (" . implode(' UNION ALL ', $parts) . ") u WHERE k IS NOT NULL", $types, $params);

    $total_visits = $gc['visits'] + $den['visits'] + $mat['visits'] + $child_visits;
    $inv = $inv_totals();
    $low = $inv_low_rows();

    $kpis([
        'Total patients (unique)' => $unique_all,
        'Total visits'            => $total_visits,
        'Units dispensed'         => $inv['dispensed'],
        'Low / out of stock items' => count($low),
    ]);

    $tbl('Patients by department', ['Department', 'Visits', 'Unique patients'], [
        ['General Consultation', $gc['visits'], $gc['patients']],
        ['Dental Clinic', $den['visits'], $den['patients']],
        ['Maternal Care', $mat['visits'], $mat['patients']],
        ['Child Immunization', $child_visits, $child_patients],
        ['All departments', $total_visits, $unique_all],
    ], [1, 2], 'Child Immunization visits = child consultations + vaccine doses given. The last row counts each person once, even if seen in several departments.');

    $tbl('Top diseases and complaints (system-wide)', ['#', 'Diagnosis / complaint', 'Cases'],
        $ranked($top_texts([$dx_consult, $dx_dental(), $dx_child], 10)), [0, 2],
        'As recorded by staff. For standardized, tracked conditions see Disease Statistics.');

    $tbl('Overall inventory movement', ['Movement', 'Units', 'Details'], [
        ['Stock in (received)', $inv['received'], $inv['batches'] . ' batch(es) added'],
        ['Stock out: dispensed', $inv['dispensed'], $inv['transactions'] . ' transaction(s), ' . $inv['patients'] . ' patient(s)'],
        ['Stock out: disposed', $inv['disposed'], 'Expired, damaged or recalled'],
        ['Net movement', $inv['received'] - $inv['dispensed'] - $inv['disposed'], 'Received minus dispensed and disposed'],
    ], [1]);

} elseif ($scope === 'doctor') {
    $gc = $visit_stats('consultations', 'c', 'visit_date');
    $rx = $scalar("SELECT COUNT(*) FROM consultations WHERE visit_date >= ? AND visit_date < ? AND TRIM(COALESCE(prescription, '')) <> ''", 'ss', $P);

    $kpis([
        'Consultations'        => $gc['visits'],
        'Unique patients'      => $gc['patients'],
        'With prescription'    => $rx,
    ]);

    $tbl('Top diagnoses', ['#', 'Diagnosis / complaint', 'Cases'], $ranked($top_texts([$dx_consult], 10)), [0, 2]);

    $rx_rows = $run("SELECT MIN(TRIM(prescription)) AS label, COUNT(*) AS n FROM consultations
                     WHERE visit_date >= ? AND visit_date < ? AND TRIM(COALESCE(prescription, '')) <> ''
                     GROUP BY LOWER(TRIM(prescription)) ORDER BY n DESC, label ASC LIMIT 10", 'ss', $P);
    $tbl('Treatments rendered: most common prescriptions', ['#', 'Prescription', 'Times given'], $ranked($rx_rows), [0, 2]);

} elseif ($scope === 'dental') {
    $den = $visit_stats('dental_records', 'd', 'visit_date');

    $kpis([
        'Dental visits'   => $den['visits'],
        'Unique patients' => $den['patients'],
    ]);

    $tbl('Top diagnoses', ['#', 'Diagnosis / complaint', 'Cases'], $ranked($top_texts([$dx_dental()], 10)), [0, 2]);

    $proc = $run("SELECT MIN(TRIM(d.procedure_done)) AS label, COUNT(*) AS n FROM dental_records d
                  WHERE d.visit_date >= ? AND d.visit_date < ? AND TRIM(COALESCE(d.procedure_done, '')) <> ''
                  GROUP BY LOWER(TRIM(d.procedure_done)) ORDER BY n DESC, label ASC LIMIT 15", 'ss', $P);
    $tbl('Treatments rendered', ['#', 'Procedure', 'Times done'], $ranked($proc), [0, 2]);

} elseif ($scope === 'midwife') {
    $mat = $visit_stats('maternal_records', 'm', 'visit_date');
    $mk  = $pk('m', 'maternal_records');
    $new_mothers = $scalar("SELECT COUNT(*) FROM (SELECT $mk AS k, MIN(m.visit_date) AS first_visit FROM maternal_records m
                            WHERE $mk IS NOT NULL GROUP BY k HAVING first_visit >= ? AND first_visit < ?) t", 'ss', $P);
    $due = $scalar("SELECT COUNT(DISTINCT $mk) FROM maternal_records m WHERE m.edc >= ? AND m.edc < ?", 'ss', $P);

    $tt_parts = []; $tt_types = ''; $tt_params = [];
    foreach ([1, 2, 3, 4, 5] as $n) {
        if (!$meta_ok('maternal_records', "tt{$n}_date")) { continue; }
        $tt_parts[] = "SELECT $n AS dose, COUNT(*) AS cnt FROM maternal_records WHERE tt{$n}_date >= ? AND tt{$n}_date < ?";
        $tt_types .= 'ss'; array_push($tt_params, $start, $end);
    }
    $tt_rows = [];
    if ($tt_parts) {
        foreach ($run(implode(' UNION ALL ', $tt_parts), $tt_types, $tt_params) as $r) { $tt_rows[] = ['TT' . (int) $r['dose'], (int) $r['cnt']]; }
    }

    $doses = $child_doses();
    $child_logs = $scalar("SELECT COUNT(*) FROM child_consultation_logs WHERE consultation_date >= ? AND consultation_date < ?", 'ss', $P);
    $children_seen = $scalar("SELECT COUNT(DISTINCT child_record_id) FROM child_consultation_logs WHERE consultation_date >= ? AND consultation_date < ?", 'ss', $P);
    $new_children = $scalar("SELECT COUNT(*) FROM child_immunization_records WHERE created_at >= ? AND created_at < ?", 'ss', $P);

    echo '<h3 class="mr-h">Maternal Care</h3>';
    $kpis([
        'Prenatal visits'      => $mat['visits'],
        'Mothers seen'         => $mat['patients'],
        'New mothers'          => $new_mothers,
        'Expected deliveries'  => $due,
    ]);
    $tbl('Tetanus toxoid doses given', ['Dose', 'Given this month'], $tt_rows, [1]);

    echo '<h3 class="mr-h">Child Immunization</h3>';
    $kpis([
        'Vaccine doses given'   => (int) array_sum($doses),
        'Child consultations'   => $child_logs,
        'Children consulted'    => $children_seen,
        'Newly registered'      => $new_children,
    ]);
    $dose_rows = [];
    foreach ($doses as $vaccine => $n) { $dose_rows[] = [$vaccine, (int) $n]; }
    $tbl('Vaccines administered', ['Vaccine', 'Doses given'], $dose_rows, [1]);

} elseif ($scope === 'inventory') {
    $inv = $inv_totals();
    $low = $inv_low_rows();

    $kpis([
        'Batches received'  => $inv['batches'],
        'Units received'    => $inv['received'],
        'Units dispensed'   => $inv['dispensed'],
        'Units disposed'    => $inv['disposed'],
        'Low / out of stock' => count($low),
    ]);

    $in_rows = [];
    foreach ($inv_received_rows() as $r) {
        $name = $r['item_name'] . (trim((string) $r['brand_name']) !== '' ? ' (' . trim($r['brand_name']) . ')' : '');
        $in_rows[] = [$date_fmt($r['created_at']), $name, (string) $r['batch_number'], (int) $r['original_qty'] . ' ' . $r['unit'], $date_fmt($r['expiry_date'])];
    }
    $tbl('Stock in: batches received', ['Date received', 'Item', 'Batch no.', 'Quantity received', 'Expiry'], $in_rows, [],
        'Quantity received = remaining stock + units already dispensed or disposed from that batch.');

    $out_rows = [];
    foreach ($inv_dispensed_rows() as $r) {
        $out_rows[] = [$r['item_name'], (int) $r['units'] . ' ' . $r['unit'], (int) $r['times'], (int) $r['patients']];
    }
    $tbl('Stock out: dispensed medicines', ['Medicine', 'Units dispensed', 'Times dispensed', 'Patients'], $out_rows, [2, 3]);

    $low_rows = [];
    foreach ($low as $r) {
        $total = (int) $r['total'];
        $low_rows[] = [$r['item_name'], $total . ' ' . $r['unit'], $total <= 0 ? 'Out of stock' : 'Low stock'];
    }
    $tbl('Low stock alerts (as of today)', ['Medicine', 'Remaining', 'Status'], $low_rows, [],
        'Active batches only. Alert level: ' . $LOW_STOCK_THRESHOLD . ' units or fewer, the same as the Medicine Inventory page.');
}
} catch (Throwable $ex) {
    error_log('[monthly_reports] ' . get_class($ex) . ': ' . $ex->getMessage() . ' in ' . basename($ex->getFile()) . ':' . $ex->getLine());
    echo '<p class="mr-empty">This report could not be built.'
       . (mh_is_admin_tier() ? ' Detail: ' . $h(get_class($ex) . ': ' . $ex->getMessage() . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')') : '')
       . '</p>';
}
?>
            </div>
        </div>

    <?php mh_layout_close(); ?>
<?php mh_layout_end(); ?>