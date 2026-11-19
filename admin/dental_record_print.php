<?php
/**
 * admin/dental_record_print.php - printable replica of Marick Health Center's paper DENTAL RECORD.
 *
 *   dental_record_print.php?patient=ID         the patient's record (header, findings of the latest visit, full procedure history)
 *   dental_record_print.php?patient=0          a blank form (no patient)   [&autoprint=1 opens the print dialog]
 *
 * Same access as the Dental module (can_log_dental or can_view_dental_history). Black and white, A4.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../config/database.php';

require_capability(['can_log_dental', 'can_view_dental_history']);

require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/dental_helper.php';
mh_check_module_enabled('dental');
mh_dental_ensure_tables($conn);

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role    = (string) ($_SESSION['role'] ?? '');
$pid     = (int) ($_GET['patient'] ?? 0);
$blank   = $pid <= 0;

$sheet = mh_dental_sheet_info($conn, $pid);
if (!$blank && $sheet['name'] === '') {
    http_response_code(404);
    exit('That patient could not be found.');
}
$rows = $blank ? [] : mh_dental_history_rows($conn, $pid);
$last = $rows ? $rows[count($rows) - 1] : null;

$checkup   = $last && in_array('OE', $last['codes'], true);
$extract   = $last && in_array('Exo', $last['codes'], true);
$visitDate = $last && $last['date'] ? date('m/d/Y', strtotime($last['date'])) : '';
$remarks   = $last ? $last['notes'] : '';
$minRows   = 15;
$pad       = max(0, $minRows - count($rows));

if (!$blank && function_exists('logActivity')) {
    logActivity($conn, $user_id, $role, 'Printed Dental Record', "Printed the Dental Record of Patient ID #$pid.");
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dental Record<?php echo $sheet['name'] !== '' ? ' - ' . mh_h($sheet['name']) : ''; ?></title>
<style>
    @page { size: A4 portrait; margin: 14mm 14mm 16mm; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; }
    body { font-family: "Times New Roman", Times, serif; font-size: 12pt; line-height: 1.25; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .toolbar { display: flex; gap: 10px; align-items: center; justify-content: center; padding: 10px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1; font-family: Arial, Helvetica, sans-serif; font-size: 14px; }
    .toolbar button, .toolbar a { padding: 8px 16px; border: 1px solid #334155; border-radius: 6px; background: #fff; color: #0f172a; font: inherit; text-decoration: none; cursor: pointer; }
    .toolbar button.primary { background: #0f172a; color: #fff; }
    .sheet { width: 182mm; max-width: 100%; margin: 6mm auto; }
    .meta, .patient { display: grid; column-gap: 12mm; row-gap: 3mm; }
    .meta { grid-template-columns: 1fr 1fr; }
    .patient { grid-template-columns: 1.7fr 1fr; margin-top: 4mm; }
    .f { display: flex; align-items: flex-end; gap: 2mm; min-width: 0; }
    .f b { font-weight: 700; text-transform: uppercase; white-space: nowrap; }
    .f .v { flex: 1; min-width: 0; min-height: 6.4mm; padding: 0 1mm; border-bottom: 1px solid #000; overflow-wrap: anywhere; }
    .f.pair { gap: 3mm; }
    .f.pair .v { flex: 0 0 22mm; }
    h1, h2 { margin: 0; text-align: center; font-weight: 700; }
    h1 { margin-top: 7mm; font-size: 22pt; letter-spacing: .01em; }
    h2 { margin-bottom: 3mm; font-size: 17pt; }
    h3 { margin: 8mm 0 2mm; font-size: 14pt; font-weight: 700; text-transform: uppercase; }
    .finds { display: flex; gap: 38mm; padding-left: 7mm; }
    .cb { display: inline-block; width: 3.8mm; height: 3.8mm; margin-right: 2mm; border: 1px solid #000; vertical-align: -0.7mm; text-align: center; font: 700 9pt/3.4mm Arial, sans-serif; }
    .rem-label { margin: 6mm 0 0; font-weight: 700; text-transform: uppercase; }
    .ruled { min-height: 17mm; line-height: 8.5mm; padding: 0 1mm; white-space: pre-wrap; overflow-wrap: anywhere;
        background: repeating-linear-gradient(to bottom, transparent 0, transparent 7.9mm, #000 7.9mm, #000 8.5mm); }
    table { width: 100%; table-layout: fixed; border-collapse: collapse; border: 1px solid #000; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { padding: 1.4mm 2mm; border: 1px solid #000; font-weight: 400; text-align: center; text-transform: uppercase; }
    td { height: 9mm; padding: 1mm 2mm; border-left: 1px solid #000; border-right: 1px solid #000; vertical-align: top; overflow-wrap: anywhere; font-size: 11pt; }
    tr.fill td { border-bottom: 1px solid #999; }
    @media print {
        .toolbar { display: none; }
        .sheet { margin: 0 auto; width: auto; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">Print</button>
    <button type="button" onclick="window.close()">Close</button>
    <span><?php echo $blank ? 'Blank Dental Record form' : 'Dental Record of ' . mh_h($sheet['name']); ?></span>
</div>

<div class="sheet">
    <div class="meta">
        <div class="f"><b>Family No.:</b><span class="v"><?php echo mh_h($sheet['family_no']); ?></span></div>
        <div class="f"><b>Date of Visit:</b><span class="v"><?php echo mh_h($visitDate); ?></span></div>
        <div class="f"><b>Others:</b><span class="v"><?php echo mh_h($sheet['others']); ?></span></div>
        <div class="f"><b>Member No.:</b><span class="v"><?php echo mh_h($sheet['member_no']); ?></span></div>
    </div>

    <h1>MARICK HEALTH CENTER</h1>
    <h2>DENTAL RECORD</h2>

    <div class="patient">
        <div class="f"><b>Name:</b><span class="v"><?php echo mh_h($sheet['name']); ?></span></div>
        <div class="f"><b>Birthday:</b><span class="v"><?php echo mh_h($sheet['birthday']); ?></span></div>
        <div class="f"><b>Address:</b><span class="v"><?php echo mh_h($sheet['address']); ?></span></div>
        <div class="f pair"><b>Age:</b><span class="v"><?php echo mh_h($sheet['age']); ?></span><b>Sex:</b><span class="v"><?php echo mh_h($sheet['sex']); ?></span></div>
        <div class="f"><b>Contact No.:</b><span class="v"><?php echo mh_h($sheet['contact']); ?></span></div>
    </div>

    <h3>Dental Findings</h3>
    <div class="finds">
        <span><span class="cb"><?php echo $checkup ? 'X' : ''; ?></span>Routine Check-up</span>
        <span><span class="cb"><?php echo $extract ? 'X' : ''; ?></span>Extraction</span>
    </div>

    <p class="rem-label">Remarks:</p>
    <div class="ruled"><?php echo mh_h($remarks); ?></div>

    <h3>Dental Procedure History</h3>
    <table>
        <thead><tr><th>Date</th><th>Procedure</th><th>Tooth No.</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="fill">
                <td><?php echo mh_h($r['date_label']); ?></td>
                <td><?php echo mh_h($r['procedure']); ?></td>
                <td><?php echo mh_h($r['tooth']); ?></td>
                <td><?php echo mh_h($r['remarks']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $pad; $i++): ?>
            <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
        </tbody>
    </table>
</div>
<script>
    window.addEventListener('load', function () {
        if (location.search.indexOf('autoprint=1') !== -1) { window.print(); }
    });
</script>
</body>
</html>
