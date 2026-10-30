<?php
/**
 * Gemini OCR configuration - DATABASE BACKED.
 *
 * The API key and model are read from `system_settings` on every request
 * (System Settings > Scanner (OCR)). No key is stored in any file. If the key
 * is missing the scanners return a friendly "not configured" message and a
 * "Config Warning" is written to the System Logs (key name only).
 */
require_once __DIR__ . '/database.php';   // idempotent: connects + loads DB settings

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

/* API surface. v1beta is required for responseSchema / structured output. */
if (!defined('GEMINI_API_VERSION')) {
    define('GEMINI_API_VERSION', 'v1beta');
}

/* ---------------------------------------------------------------------
 * 3. RETRY / TIMEOUT TUNING (used by includes/ocr/GeminiOcrClient.php)
 * ------------------------------------------------------------------ */
if (!defined('GEMINI_MAX_ATTEMPTS'))   { define('GEMINI_MAX_ATTEMPTS', 4);   } // per model
if (!defined('GEMINI_BACKOFF_BASE_MS')){ define('GEMINI_BACKOFF_BASE_MS', 700); }
if (!defined('GEMINI_BACKOFF_CAP_MS')) { define('GEMINI_BACKOFF_CAP_MS', 8000); }
if (!defined('GEMINI_HTTP_TIMEOUT'))   { define('GEMINI_HTTP_TIMEOUT', 60);  }

/* Set to true only while debugging; it adds a "debug" node to the JSON
   error responses returned by admin/ocr/*.php. Never leave on in prod. */
if (!defined('GEMINI_DEBUG')) { define('GEMINI_DEBUG', false); }

/**
 * Returns a human-readable problem with the configured API key,
 * or NULL when the key looks structurally valid.
 */
if (!function_exists('gemini_api_key_problem')) {
    /** @return string|null  Human-readable problem (never contains the key), or null if usable. */
    function gemini_api_key_problem()
    {
        $key = trim((string) GEMINI_API_KEY);

        if ($key === '') {
            mh_log_config_error('gemini_api_key', 'OCR scanner');
            return 'The Gemini API key has not been set yet. Open System Settings > Scanner (OCR) and paste a key from '
                 . 'https://aistudio.google.com/app/apikey (it starts with "AIzaSy" or "AQ.").';
        }
        if (preg_match('/\s/', $key)) {
            mh_log_config_error('gemini_api_key', 'contains whitespace');
            return 'The Gemini API key contains a space or line break. Re-paste it as a single line.';
        }
        if (strpos($key, 'AIzaSy') !== 0 && strpos($key, 'AQ.') !== 0) {
            mh_log_config_error('gemini_api_key', 'unexpected format');
            return 'The saved value does not look like a Google AI Studio Gemini key (those start with "AIzaSy" or "AQ."). '
                 . 'OAuth or OpenAI tokens will be rejected. Re-enter it in System Settings > Scanner (OCR).';
        }
        if (strlen($key) < 30) {
            mh_log_config_error('gemini_api_key', 'looks truncated');
            return 'The Gemini API key looks truncated. A full key is typically 35 to 45 characters long.';
        }
        return null;
    }
}
