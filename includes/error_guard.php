<?php
// Constant-keyed so it is safe to include from several pages.
if (!defined('MH_ERROR_GUARD')) {
define('MH_ERROR_GUARD', true);
/**
 * =====================================================================
 * ERROR GUARD - turns "blank white page" into a useful message + a log line.
 *
 *  - Always logs the full error (Hostinger: hPanel > Websites > Advanced > Error logs,
 *    or the error_log file next to the script).
 *  - On localhost, or when a page defines MH_FORCE_DEBUG first (see admin/debug_walkin.php),
 *    it also shows the real error on screen.
 *  - On the live site visitors only see a friendly message + a short reference code that you can
 *    search for in the error log.
 *
 * Limit (PHP itself, not this file): a PARSE error in the page you are opening can never be caught by
 * code inside that same page, because the page never starts running. For those, use admin/debug_walkin.php
 * or read the error log.
 * ================================================================== */
$__host  = $_SERVER['HTTP_HOST'] ?? '';
$__local = (PHP_SAPI === 'cli') || strpos($__host, 'localhost') !== false || strpos($__host, '127.0.0.1') !== false || strpos($__host, '.local') !== false;
define('MH_SHOW_ERRORS', $__local || defined('MH_FORCE_DEBUG'));

error_reporting(E_ALL);
ini_set('display_errors', MH_SHOW_ERRORS ? '1' : '0');
ini_set('log_errors', '1');

function mh_error_guard_fail($detail)
{
    $ref = strtoupper(bin2hex(random_bytes(3)));
    error_log("[MH-$ref] $detail");
    while (ob_get_level() > 0) { @ob_end_clean(); }          // drop half-rendered HTML
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=UTF-8'); }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title></head>'
       . '<body style="font-family:Arial,sans-serif;max-width:640px;margin:12vh auto;padding:0 20px;color:#334155">'
       . '<h2 style="color:#b91c1c">This page could not be loaded</h2>'
       . '<p>Something went wrong on our side. Please go back and try again. If it keeps happening, tell the system administrator this reference code: <b>' . $ref . '</b>.</p>';
    if (MH_SHOW_ERRORS) {
        echo '<pre style="background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;white-space:pre-wrap">' . htmlspecialchars($detail) . '</pre>';
    }
    echo '</body></html>';
}

set_exception_handler(function ($e) {
    mh_error_guard_fail(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        mh_error_guard_fail('Fatal error: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    }
});
}
