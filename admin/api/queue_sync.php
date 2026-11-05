<?php
/**
 * Queue sync (Round A): GET ?sig=<last signature>  ->  {"sig":"...","changed":true|false,"count":N}
 *
 * A fingerprint of TODAY's whole queue. Pages that show the queue poll this every few seconds (it is one small indexed
 * query) and only re-draw their own list when the fingerprint changed - see assets/js/mh_live.js.
 * Reveals no patient data: just a hash and a count. JSON-safe auth (401 JSON, never an HTML redirect).
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
session_write_close();   // a polling request must never hold the session lock and block the user's real requests

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/queue_helper.php';

$now  = mh_queue_signature($conn);
$last = (string) ($_GET['sig'] ?? '');
echo json_encode(['sig' => $now['sig'], 'count' => $now['count'], 'changed' => ($last !== '' && $last !== $now['sig'])]);
