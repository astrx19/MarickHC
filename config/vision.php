<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - OCR ENGINE CONFIGURATION
 * =====================================================================
 * Centralizes API keys and endpoints for both supported OCR engines:
 *   - Google Cloud Vision API  (ocr_engine = 'vision')
 *   - Google Gemini AI API     (ocr_engine = 'gemini')
 *
 * The active engine and API keys are configurable from
 * System Settings > Scanner (OCR). This file sets safe defaults and
 * reads database overrides.
 * =====================================================================
 */

/* ---- Google Cloud Vision ------------------------------------------ */
if (!defined('GOOGLE_VISION_API_KEY')) {
    $dbKey = '';
    if (function_exists('mh_get_option')) {
        $dbKey = (string) mh_get_option('google_vision_api_key', '');
        if ($dbKey === '') {
            $dbKey = (string) mh_get_option('vision_api_key', '');
        }
    } elseif (function_exists('mh_setting')) {
        $dbKey = (string) mh_setting('google_vision_api_key', '');
        if ($dbKey === '') {
            $dbKey = (string) mh_setting('vision_api_key', '');
        }
    }
    $apiKey = ($dbKey !== '') ? $dbKey : 'AIzaSyB_3kCP-DpMQqM07x99uhJgiNDFyxHQukI';
    define('GOOGLE_VISION_API_KEY', trim($apiKey));
}

if (!defined('GOOGLE_VISION_API_ENDPOINT')) {
    define('GOOGLE_VISION_API_ENDPOINT', 'https://vision.googleapis.com/v1/images:annotate');
}

/* ---- Gemini AI API ------------------------------------------------- */
if (!defined('GEMINI_API_KEY')) {
    $dbGeminiKey = '';
    if (function_exists('mh_get_option')) {
        $dbGeminiKey = (string) mh_get_option('gemini_api_key', '');
    } elseif (function_exists('mh_setting')) {
        $dbGeminiKey = (string) mh_setting('gemini_api_key', '');
    }
    define('GEMINI_API_KEY', trim($dbGeminiKey));
}

if (!defined('GEMINI_OCR_MODEL')) {
    $dbModel = '';
    if (function_exists('mh_get_option')) {
        $dbModel = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) mh_get_option('ocr_model', ''));
    } elseif (function_exists('mh_setting')) {
        $dbModel = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) mh_setting('ocr_model', ''));
    }
    define('GEMINI_OCR_MODEL', $dbModel !== '' ? $dbModel : 'gemini-2.0-flash');
}

if (!defined('GEMINI_OCR_FALLBACK_MODELS')) {
    define('GEMINI_OCR_FALLBACK_MODELS', 'gemini-2.0-flash,gemini-2.5-flash,gemini-flash-latest,gemini-2.0-flash-001');
}

if (!defined('GEMINI_API_VERSION'))    { define('GEMINI_API_VERSION',    'v1beta'); }
if (!defined('GEMINI_MAX_ATTEMPTS'))   { define('GEMINI_MAX_ATTEMPTS',   4); }
if (!defined('GEMINI_BACKOFF_BASE_MS')){ define('GEMINI_BACKOFF_BASE_MS', 700); }
if (!defined('GEMINI_BACKOFF_CAP_MS')) { define('GEMINI_BACKOFF_CAP_MS',  8000); }
if (!defined('GEMINI_HTTP_TIMEOUT'))   { define('GEMINI_HTTP_TIMEOUT',   60); }
if (!defined('GEMINI_DEBUG'))          { define('GEMINI_DEBUG',           false); }

/* ---- Active engine helper ----------------------------------------- */
if (!function_exists('mh_ocr_active_engine')) {
    /**
     * Returns the currently configured OCR engine: 'vision' or 'gemini'.
     * Falls back to 'vision' for safety when the setting is missing or invalid.
     *
     * @return string 'vision' | 'gemini'
     */
    function mh_ocr_active_engine()
    {
        $engine = '';
        if (function_exists('mh_setting')) {
            $engine = strtolower(trim((string) mh_setting('ocr_engine', 'vision')));
        } elseif (function_exists('mh_get_option')) {
            $engine = strtolower(trim((string) mh_get_option('ocr_engine', 'vision')));
        }
        return in_array($engine, ['vision', 'gemini'], true) ? $engine : 'vision';
    }
}

