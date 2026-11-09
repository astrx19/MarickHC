<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/disease_stats.php';

if (!function_exists('mh_has_permission') || !mh_has_permission('view_disease_statistics')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to view disease statistics.']);
    exit;
}

$is_d = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false; };
$ov_to   = $is_d($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$ov_from = $is_d($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime($ov_to . ' -29 days'));
if ($ov_from > $ov_to) { list($ov_from, $ov_to) = [$ov_to, $ov_from]; }
if ($ov_to > date('Y-m-d')) { $ov_to = date('Y-m-d'); }
if ($ov_from < date('Y-m-d', strtotime($ov_to . ' -365 days'))) { $ov_from = date('Y-m-d', strtotime($ov_to . ' -365 days')); }

$stats = mh_disease_compute($conn);
$ov    = mh_disease_overview($conn, $ov_from, $ov_to);

$force = !empty($_GET['force']) || !empty($_POST['force']);
$ai    = mh_disease_ai_summary($conn, $stats, $ov, $force);

echo json_encode([
    'success'      => true,
    'summary'      => $ai['summary'],
    'engine'       => $ai['engine'],
    'engine_label' => $ai['engine_label'],
    'generated_at' => $ai['generated_at'],
    'cached'       => $ai['cached'],
]);

