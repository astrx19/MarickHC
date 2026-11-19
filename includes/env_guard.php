<?php
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
