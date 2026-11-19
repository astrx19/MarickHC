<?php
require_once __DIR__ . '/../includes/error_guard.php';
$__mh = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'marick_health_db', 'debug' => false];

foreach (['host' => 'MH_DB_HOST', 'user' => 'MH_DB_USER', 'pass' => 'MH_DB_PASS', 'name' => 'MH_DB_NAME'] as $__k => $__e) {
    $__v = getenv($__e);
    if ($__v !== false) { $__mh[$__k] = $__v; }
}

$__mhLocal = __DIR__ . '/database.local.php';
if (is_file($__mhLocal)) {
    $__o = (static function ($f) { return include $f; })($__mhLocal);
    if (is_array($__o)) { $__mh = array_merge($__mh, array_intersect_key($__o, $__mh)); }
    unset($__o);
}

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', !empty($__mh['debug']) ? '1' : '0');
mysqli_report(MYSQLI_REPORT_OFF);

$host     = $__mh['host'];
$user     = $__mh['user'];
$password = $__mh['pass'];
$database = $__mh['name'];
unset($__mh, $__mhLocal, $__k, $__e, $__v);

$conn = @new mysqli($host, $user, $password, $database);
if ($conn->connect_errno) {
    error_log('[MarickHealth] DB connection failed (' . $conn->connect_errno . '): ' . $conn->connect_error
        . " [host=$host user=$user db=$database]");
    http_response_code(503);
    die('Service temporarily unavailable.');
}
$conn->set_charset('utf8mb4');

$__tz = getenv('MH_TIMEZONE') ?: 'Asia/Manila';
if (!@date_default_timezone_set($__tz)) { $__tz = 'Asia/Manila'; date_default_timezone_set($__tz); }
$GLOBALS['mh_db_utc_offset'] = (new DateTime('now', new DateTimeZone($__tz)))->format('P');
@$conn->query("SET time_zone = '" . $GLOBALS['mh_db_utc_offset'] . "'");
unset($__tz);

if (!function_exists('logActivity')) {
    function logActivity($conn, $user_id, $user_role, $action, $details = "") {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $stmt = $conn->prepare(
            "INSERT INTO activity_logs (user_id, user_role, action, details, ip_address)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) { return false; }

        $stmt->bind_param("issss", $user_id, $user_role, $action, $details, $ip);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

$GLOBALS['conn'] = $conn;
require_once __DIR__ . '/../includes/settings_helper.php';
try {
    mh_settings_boot($conn);
    mh_export_branding_globals();
} catch (Throwable $e) {
    error_log('[MarickHealth] settings boot failed: ' . $e->getMessage());
}
