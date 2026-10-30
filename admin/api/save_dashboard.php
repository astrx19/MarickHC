<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - DASHBOARD LAYOUT API
 * =====================================================================
 * AJAX endpoint used by admin/dashboard.php's "Save Layout" and
 * "Reset Default" buttons.
 *
 * POST JSON body:
 *   { "action": "save",  "widgets": [ {"id":"walkin_queue","visible":true,"size":"small"}, ... ] }
 *   { "action": "reset" }
 *
 * Response JSON: { "success": true|false, "message": "..." }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/dashboard_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role    = $_SESSION['role'] ?? '';

if ($user_id <= 0 || $role === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

mh_dashboard_install($conn);

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || empty($body['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Malformed request.']);
    exit;
}

switch ($body['action']) {

    case 'save':
        $widgets = $body['widgets'] ?? null;
        if (!is_array($widgets) || empty($widgets)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No widget layout provided.']);
            exit;
        }

        $ok = mh_dashboard_save_layout($conn, $user_id, $role, $widgets);

        if ($ok) {
            logActivity($conn, $user_id, $role, 'Dashboard Customization', 'Saved a custom dashboard layout.');
            echo json_encode(['success' => true, 'message' => 'Layout saved.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not save layout. Please try again.']);
        }
        break;

    case 'reset':
        $ok = mh_dashboard_reset_layout($conn, $user_id);

        if ($ok) {
            logActivity($conn, $user_id, $role, 'Dashboard Customization', 'Reset dashboard layout to default.');
            echo json_encode(['success' => true, 'message' => 'Layout reset to default.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not reset layout. Please try again.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}