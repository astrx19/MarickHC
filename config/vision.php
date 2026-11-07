<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - OCR ENGINE CONFIGURATION
 * =====================================================================
 * Centralizes API keys and endpoints for both supported OCR engines:
 *   - Google Cloud Vision API  (ocr_engine = 'vision')
 *   - Google Gemini AI API     (ocr_engine = 'gemini')
 *   - Amazon Textract          (ocr_engine = 'aws_textract')
 *   - Groq vision LLM          (ocr_engine = 'groq_ocr')
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

/* ---- AWS Textract -------------------------------------------------- */
if (!defined('AWS_TEXTRACT_ACCESS_KEY_ID')) {
    $dbAwsKey = '';
    if (function_exists('mh_get_option')) {
        $dbAwsKey = (string) mh_get_option('aws_access_key_id', '');
    } elseif (function_exists('mh_setting')) {
        $dbAwsKey = (string) mh_setting('aws_access_key_id', '');
    }
    define('AWS_TEXTRACT_ACCESS_KEY_ID', trim($dbAwsKey));
}

if (!defined('AWS_TEXTRACT_SECRET_ACCESS_KEY')) {
    $dbAwsSecret = '';
    if (function_exists('mh_get_option')) {
        $dbAwsSecret = (string) mh_get_option('aws_secret_access_key', '');
    } elseif (function_exists('mh_setting')) {
        $dbAwsSecret = (string) mh_setting('aws_secret_access_key', '');
    }
    define('AWS_TEXTRACT_SECRET_ACCESS_KEY', trim($dbAwsSecret));
}

if (!defined('AWS_TEXTRACT_REGION')) {
    $dbAwsRegion = '';
    if (function_exists('mh_get_option')) {
        $dbAwsRegion = (string) mh_get_option('aws_region', '');
    } elseif (function_exists('mh_setting')) {
        $dbAwsRegion = (string) mh_setting('aws_region', '');
    }
    // Default to ap-southeast-1 (Singapore) for optimal latency in the Philippines
    $dbAwsRegion = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($dbAwsRegion)));
    define('AWS_TEXTRACT_REGION', $dbAwsRegion !== '' ? $dbAwsRegion : 'ap-southeast-1');
}

/* ---- Groq (OpenAI-compatible chat completions, vision model) ------- */
/* NOTE: the Groq API key is deliberately NOT frozen into a constant here - call_groq_ocr()
 * reads `groq_api_key` live from system_settings on every scan. */
if (!defined('GROQ_API_ENDPOINT'))  { define('GROQ_API_ENDPOINT',  'https://api.groq.com/openai/v1/chat/completions'); }
if (!defined('GROQ_OCR_MODEL'))     { define('GROQ_OCR_MODEL',     'qwen/qwen3.8-27b'); }
if (!defined('GROQ_HTTP_TIMEOUT'))  { define('GROQ_HTTP_TIMEOUT',  60); }

/* ---- Live settings reader + active engine helper ----------------- */
if (!function_exists('mh_ocr_live_setting')) {
    /**
     * Reads one value straight from the system_settings table on every call.
     *
     * The OCR scan endpoints load this file BEFORE the settings cache is booted, so anything
     * frozen into a constant at include time can be stale or empty. Reading the table directly
     * guarantees the engine/keys saved in System Settings are the ones used for this scan.
     * Falls back to the cached mh_setting()/mh_get_option() accessors if there is no DB handle.
     *
     * @param string $key      setting_key in system_settings
     * @param string $default  returned when the row is missing or blank
     */
    function mh_ocr_live_setting($key, $default = '')
    {
        $conn = $GLOBALS['conn'] ?? null;
        if ($conn instanceof mysqli) {
            try {
                $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('s', $key);
                    $stmt->execute();
                    $val = null;
                    $stmt->bind_result($val);
                    $found = $stmt->fetch();
                    $stmt->close();
                    if ($found && $val !== null && trim((string) $val) !== '') {
                        return trim((string) $val);
                    }
                    return $default;
                }
            } catch (Throwable $e) {
                // fall through to the cached accessors below
            }
        }
        if (function_exists('mh_get_option')) {
            return mh_get_option($key, $default);
        }
        if (function_exists('mh_setting')) {
            $v = trim((string) mh_setting($key, ''));
            return $v !== '' ? $v : $default;
        }
        return $default;
    }
}

if (!function_exists('mh_ocr_active_engine')) {
    /**
     * Returns the OCR engine currently selected in System Settings, read live from the
     * database: 'vision', 'gemini', 'aws_textract' or 'groq_ocr'. Anything else (or an unreadable
     * setting) falls back to 'vision' - the factory default.
     *
     * @return string 'vision' | 'gemini' | 'aws_textract' | 'groq_ocr'
     */
    function mh_ocr_active_engine()
    {
        $engine = strtolower(trim((string) mh_ocr_live_setting('ocr_engine', 'vision')));
        return in_array($engine, ['vision', 'gemini', 'aws_textract', 'groq_ocr'], true) ? $engine : 'vision';
    }
}
