<?php
/**
 * Android SMS Gateway configuration - DATABASE BACKED.
 * URL and API key come from `system_settings` (System Settings > SMS Reminders).
 * No static settings file is read or written.
 */
require_once __DIR__ . '/database.php';

if (!defined('ANDROID_GATEWAY_URL')) { define('ANDROID_GATEWAY_URL', mh_get_option('android_gateway_url', '')); }
if (!defined('ANDROID_GATEWAY_KEY')) { define('ANDROID_GATEWAY_KEY', mh_get_option('android_gateway_key', '')); }
if (!defined('ANDROID_GATEWAY_HTTP_TIMEOUT')) { define('ANDROID_GATEWAY_HTTP_TIMEOUT', 20); } // legacy
// Verify TLS by default. Only a plain-http LAN gateway is unaffected by this flag.
if (!defined('ANDROID_GATEWAY_SSL_VERIFY')) { define('ANDROID_GATEWAY_SSL_VERIFY', true); }

if (!function_exists('android_gateway_problem')) {
    /** @return string|null */
    function android_gateway_problem()
    {
        $url = trim((string) ANDROID_GATEWAY_URL);

        if ($url === '') {
            mh_log_config_error('android_gateway_url', 'SMS provider: Android gateway');
            return 'The Android SMS Gateway endpoint URL has not been set yet. Open System Settings > SMS Reminders '
                 . 'and paste the address your gateway app shows (e.g. http://192.168.1.50:8080/send).';
        }
        if (!preg_match('#^https?://#i', $url)) {
            return 'The Android SMS Gateway URL must start with http:// or https://.';
        }
        if (preg_match('/\s/', $url)) {
            return 'The Android SMS Gateway URL contains a space or line break. Re-paste it as a single line.';
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'The Android SMS Gateway URL does not look like a valid URL.';
        }
        if (stripos($url, 'cloudfunctions.net') !== false && trim((string) ANDROID_GATEWAY_KEY) === '') {
            mh_log_config_error('android_gateway_key', 'required by this endpoint');
            return 'This gateway endpoint requires an API key (sent as the X-API-Key header). Paste it in System Settings > SMS Reminders > Gateway API Key.';
        }
        return null;
    }
}
