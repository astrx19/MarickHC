<?php
/**
 * Marick Health - database bootstrap. Works unchanged on XAMPP (macOS) and Hostinger (Linux).
 *
 * Credential precedence (later wins):
 *   1. XAMPP defaults (root / no password / marick_health_db)
 *   2. Environment variables MH_DB_HOST / MH_DB_USER / MH_DB_PASS / MH_DB_NAME
 *      (Hostinger shared hosting cannot set these, so use #3 there)
 *   3. config/database.local.php  -> must `return [...]` an array (see database.local.php.example).
 *      That file is git-ignored and denied to web visitors by config/.htaccess.
 *
 * Production behaviour: PHP errors are logged, never printed, and mysqli does NOT throw
 * (same behaviour as the XAMPP setup this code was written against). Set 'debug' => true in
 * database.local.php on your Mac if you want errors on screen.
 */
$__mh = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'marick_health_db', 'debug' => false];

foreach (['host' => 'MH_DB_HOST', 'user' => 'MH_DB_USER', 'pass' => 'MH_DB_PASS', 'name' => 'MH_DB_NAME'] as $__k => $__e) {
    $__v = getenv($__e);
    if ($__v !== false) { $__mh[$__k] = $__v; }
}

$__mhLocal = __DIR__ . '/database.local.php';
if (is_file($__mhLocal)) {
    // Closure keeps any variables the file defines out of the global scope.
    $__o = (static function ($f) { return include $f; })($__mhLocal);
    if (is_array($__o)) { $__mh = array_merge($__mh, array_intersect_key($__o, $__mh)); }
    unset($__o);
}

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', !empty($__mh['debug']) ? '1' : '0');
// PHP 8.1+ makes mysqli throw by default; PHP 7.x / XAMPP-era setups return false. Pin the old behaviour.
mysqli_report(MYSQLI_REPORT_OFF);

$host     = $__mh['host'];
$user     = $__mh['user'];
$password = $__mh['pass'];
$database = $__mh['name'];
unset($__mh, $__mhLocal, $__k, $__e, $__v);

$conn = @new mysqli($host, $user, $password, $database);
if ($conn->connect_errno) {
    // Log the real reason (wrong user/pass/db name, host) - never show it to visitors.
    error_log('[MarickHealth] DB connection failed (' . $conn->connect_errno . '): ' . $conn->connect_error
        . " [host=$host user=$user db=$database]");
    http_response_code(503);
    die('Service temporarily unavailable.');
}
$conn->set_charset('utf8mb4');

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

/* ---------------------------------------------------------------------
 * Load DB-backed settings for this request (branding, credentials, flags).
 * Replaces the old generated config/settings.php et al. Never fatal: if the
 * settings tables do not exist yet, factory defaults apply.
 * ------------------------------------------------------------------ */
$GLOBALS['conn'] = $conn;
require_once __DIR__ . '/../includes/settings_helper.php';
try {
    mh_settings_boot($conn);
    mh_export_branding_globals();
} catch (Throwable $e) {
    error_log('[MarickHealth] settings boot failed: ' . $e->getMessage());
}
