<?php
/**
 * DEV-TOOLS GUARD (Paper REQ74/REQ88). Test-data and diagnostic pages (admin/db_seeder.php, admin/db_check.php)
 * must never run against the live clinic database. They work on localhost / CLI, and on a server only if the
 * file config/allow_dev_tools.flag exists (create it deliberately, delete it afterwards).
 */
if (!function_exists('mh_is_dev_environment')) {
    function mh_is_dev_environment(): bool
    {
        if (PHP_SAPI === 'cli') { return true; }
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) { return true; }
        return is_file(__DIR__ . '/../config/allow_dev_tools.flag');
    }
}
if (!function_exists('mh_require_dev_environment')) {
    function mh_require_dev_environment(): void
    {
        if (!mh_is_dev_environment()) { http_response_code(404); exit; }
    }
}
