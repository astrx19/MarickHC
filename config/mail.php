<?php
require_once __DIR__ . '/database.php';   // DB settings (branding) for the sender name
/**
 * ---------------------------------------------------------------------
 * Outbound email configuration (password reset links, etc.).
 *
 * Credentials precedence (later wins):
 *   1. defaults below (no SMTP -> includes/mailer.php falls back to PHP mail())
 *   2. environment variables MH_SMTP_HOST / _PORT / _SECURE / _USER / _PASS, MAIL_FROM_ADDRESS
 *   3. config/mail.local.php  (see mail.local.php.example) - use this one on Hostinger
 * ---------------------------------------------------------------------
 */
$__ml = ['smtp_host' => '', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => '', 'smtp_pass' => '',
         'from_address' => '', 'from_name' => '', 'pepper' => ''];

foreach (['smtp_host' => 'MH_SMTP_HOST', 'smtp_port' => 'MH_SMTP_PORT', 'smtp_secure' => 'MH_SMTP_SECURE',
          'smtp_user' => 'MH_SMTP_USER', 'smtp_pass' => 'MH_SMTP_PASS', 'from_address' => 'MAIL_FROM_ADDRESS'] as $__k => $__e) {
    $__v = getenv($__e);
    if ($__v !== false && $__v !== '') { $__ml[$__k] = trim($__v); }
}
if (is_file(__DIR__ . '/mail.local.php')) {
    $__o = (static function ($f) { return include $f; })(__DIR__ . '/mail.local.php');
    if (is_array($__o)) { $__ml = array_merge($__ml, array_intersect_key($__o, $__ml)); }
    unset($__o);
}

if (!defined('MAIL_SMTP_HOST'))   { define('MAIL_SMTP_HOST', (string) $__ml['smtp_host']); }
if (!defined('MAIL_SMTP_PORT'))   { define('MAIL_SMTP_PORT', (int) $__ml['smtp_port']); }
if (!defined('MAIL_SMTP_SECURE')) { define('MAIL_SMTP_SECURE', strtolower((string) $__ml['smtp_secure']) === 'tls' ? 'tls' : 'ssl'); }
if (!defined('MAIL_SMTP_USER'))   { define('MAIL_SMTP_USER', (string) $__ml['smtp_user']); }
if (!defined('MAIL_SMTP_PASS'))   { define('MAIL_SMTP_PASS', (string) $__ml['smtp_pass']); }
if (!defined('PASSWORD_RESET_PEPPER')) { define('PASSWORD_RESET_PEPPER', (string) $__ml['pepper']); }

if (!defined('MAIL_FROM_ADDRESS')) {
    $from = $__ml['from_address'] !== '' ? $__ml['from_address']
          : (MAIL_SMTP_USER !== '' ? MAIL_SMTP_USER : 'no-reply@marickhealthcenter.local');
    define('MAIL_FROM_ADDRESS', $from);
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', $__ml['from_name'] !== '' ? $__ml['from_name'] : mh_get_option('system_name', 'Health Center'));
}
unset($__ml, $__k, $__e, $__v);

/**
 * When true, pages that send a reset link/code ALSO print it on screen ("Local Testing Mode").
 * On only for localhost / *.local requests - never on the live Hostinger domain.
 */
if (!defined('MAIL_DEBUG_MODE')) {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $looksLocal = (PHP_SAPI === 'cli')
        || strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || strpos($host, '.local') !== false;
    define('MAIL_DEBUG_MODE', $looksLocal);
}
