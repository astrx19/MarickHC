<?php
require_once __DIR__ . '/database.php';

if (!defined('SEMAPHORE_API_KEY')) {
    define('SEMAPHORE_API_KEY', mh_get_option('semaphore_api_key', ''));
}
if (!defined('SEMAPHORE_SENDER_NAME')) {
    define('SEMAPHORE_SENDER_NAME', mh_get_option('semaphore_sender_name', 'SEMAPHORE'));
}
if (!defined('SEMAPHORE_API_BASE')) {
    define('SEMAPHORE_API_BASE', 'https://api.semaphore.co/api/v4');
}
if (!defined('SEMAPHORE_HTTP_TIMEOUT')) {
    define('SEMAPHORE_HTTP_TIMEOUT', 20);
}

if (!defined('CRON_RUN_KEY')) {
    define('CRON_RUN_KEY', mh_get_option('cron_run_key', ''));
}

if (!function_exists('semaphore_api_key_problem')) {
    function semaphore_api_key_problem()
    {
        $key = trim((string) SEMAPHORE_API_KEY);

        if ($key === '') {
            mh_log_config_error('semaphore_api_key', 'SMS provider: Semaphore');
            return 'The Semaphore API key has not been set yet. Open System Settings > SMS Reminders and paste '
                 . 'the key from https://semaphore.co/account (Account > API Key).';
        }
        if (preg_match('/\s/', $key)) {
            mh_log_config_error('semaphore_api_key', 'contains whitespace');
            return 'The Semaphore API key contains a space or line break. Re-paste it as a single line.';
        }
        if (strlen($key) < 20) {
            mh_log_config_error('semaphore_api_key', 'looks truncated');
            return 'The Semaphore API key looks truncated. A full key is normally 32 characters.';
        }
        return null;
    }
}
