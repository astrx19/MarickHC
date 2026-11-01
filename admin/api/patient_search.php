<?php
/**
 * Patient search for the department forms (Step 6): GET ?q=<name part(s)>  ->  JSON.
 *
 *   {"results":[{"id":12,"first_name":"Ana","middle_name":"M","last_name":"Cruz","birthdate":"1990-05-01","age":36,"sex":"Female"}, ...]}
 *
 * - JSON-safe auth (a missing session answers 401 JSON, never an HTML redirect that fetch() cannot read).
 * - Only staff who may open a clinical form or the masterlist can search.
 * - Active patients only, at most 8 rows, and only what the picker needs (no address / contact / email).
 * - Every word must match the first, middle or last name; LIKE wildcards typed by the user are escaped.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Your session has expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';

$allowed = false;
foreach (['manage_consultations', 'manage_maternal_care', 'manage_child_immunization', 'manage_dental_records', 'view_patient_masterlist', 'log_vital_signs'] as $perm) {
    if (function_exists('mh_has_permission') && mh_has_permission($perm)) { $allowed = true; break; }
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have access to search patients.']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$q = preg_replace('/[\s,]+/u', ' ', $q);
if (mb_strlen($q) < 2) { echo json_encode(['results' => []]); exit; }

$tokens = array_slice(array_values(array_filter(explode(' ', $q), function ($t) { return $t !== ''; })), 0, 4);
$where = []; $params = [];
foreach ($tokens as $t) {
    $like = '%' . addcslashes(mb_substr($t, 0, 40), '\\%_') . '%';
    $where[] = '(first_name LIKE ? OR last_name LIKE ? OR middle_name LIKE ?)';
    array_push($params, $like, $like, $like);
}

$sql = "SELECT id, first_name, middle_name, last_name, birthdate, sex
          FROM patients
         WHERE status = 1 AND " . implode(' AND ', $where) . "
         ORDER BY last_name ASC, first_name ASC
         LIMIT 8";
$st = $conn->prepare($sql);
if (!$st) {
    error_log('patient_search prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Search is unavailable right now.']);
    exit;
}
$st->bind_param(str_repeat('s', count($params)), ...$params);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$today = new DateTime('today');
$out = [];
foreach ($rows as $r) {
    $age = null;
    try { $age = (new DateTime($r['birthdate']))->diff($today)->y; } catch (Exception $e) { /* bad date: leave blank */ }
    $out[] = [
        'id' => (int) $r['id'], 'first_name' => $r['first_name'], 'middle_name' => (string) $r['middle_name'],
        'last_name' => $r['last_name'], 'birthdate' => $r['birthdate'], 'age' => $age, 'sex' => $r['sex'],
    ];
}
echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
