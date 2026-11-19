<?php
require_once __DIR__ . '/database.php';

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', mh_get_option('gemini_api_key', ''));
}

if (!defined('GEMINI_OCR_MODEL')) {
    $m = preg_replace('/[^a-zA-Z0-9._-]/', '', mh_get_option('ocr_model', 'gemini-2.0-flash'));
    define('GEMINI_OCR_MODEL', $m !== '' ? $m : 'gemini-2.0-flash');
}

if (!defined('GEMINI_OCR_FALLBACK_MODELS')) {
    define('GEMINI_OCR_FALLBACK_MODELS', implode(',', [
        'gemini-2.0-flash',
        'gemini-2.5-flash',
        'gemini-flash-latest',
        'gemini-2.0-flash-001',
    ]));
}

if (!defined('GEMINI_API_VERSION')) {
    define('GEMINI_API_VERSION', 'v1beta');
}

if (!defined('GEMINI_MAX_ATTEMPTS'))   { define('GEMINI_MAX_ATTEMPTS', 4);   }
if (!defined('GEMINI_BACKOFF_BASE_MS')){ define('GEMINI_BACKOFF_BASE_MS', 700); }
if (!defined('GEMINI_BACKOFF_CAP_MS')) { define('GEMINI_BACKOFF_CAP_MS', 8000); }
if (!defined('GEMINI_HTTP_TIMEOUT'))   { define('GEMINI_HTTP_TIMEOUT', 60);  }

if (!defined('GEMINI_DEBUG')) { define('GEMINI_DEBUG', false); }

if (!function_exists('gemini_api_key_problem')) {
    function gemini_api_key_problem()
    {
        return null;
    }
}
