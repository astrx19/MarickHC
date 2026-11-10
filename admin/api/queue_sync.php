<?php

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
session_write_close();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/queue_helper.php';

$now  = mh_queue_signature($conn);
$last = (string) ($_GET['sig'] ?? '');
echo json_encode(['sig' => $now['sig'], 'count' => $now['count'], 'changed' => ($last !== '' && $last !== $now['sig'])]);
