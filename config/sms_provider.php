<?php
/**
 * Active SMS provider - DATABASE BACKED (system_settings.sms_provider).
 */
require_once __DIR__ . '/database.php';

if (!defined('SMS_ACTIVE_PROVIDER')) {
    $p = mh_get_option('sms_provider', 'semaphore');
    define('SMS_ACTIVE_PROVIDER', in_array($p, ['semaphore', 'android_gateway'], true) ? $p : 'semaphore');
}

if (!function_exists('mh_active_sms_provider')) {
    function mh_active_sms_provider()
    {
        return SMS_ACTIVE_PROVIDER === 'android_gateway' ? 'android_gateway' : 'semaphore';
    }
}
