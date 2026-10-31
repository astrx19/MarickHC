<?php
/**
 * Shared plumbing for the OCR scan endpoints (Step 2 - consolidation).
 *
 * admin/ocr/checkup_scan.php, maternal_scan.php and immunization_scan.php each used to
 * carry ~110 identical lines (JSON headers, session check, Gemini config + key checks,
 * upload handling, retry mapping, patient matching). That now lives here once; each
 * endpoint only keeps what is really its own: the Gemini schema, the prompt, which
 * fields are dates, and the exact JSON shape its page expects.
 *
 * Typical endpoint:
 *
 *   require_once __DIR__ . '/../../includes/ocr/ocr_endpoint.php';
 *   mh_ocr_preflight('message');                      // JSON headers + session + 401
 *   require_once __DIR__ . '/../../config/database.php';        // must stay at top level ($conn)
 *   require_once __DIR__ . '/../../includes/settings_helper.php';
 *   mh_require_ocr_access_json('maternal_care');
 *   $upload = mh_ocr_begin(['field' => 'ocr_image', ...]);
 *   ... $schema / $prompt ...
 *   $fields = mh_ocr_extract($upload, $prompt, $schema);
 *
 * Error style = how a failure is shaped, because each page's JS reads it differently:
 *   'message' -> {"success":false,"message":"..."}
 *   'error'   -> {"success":false,"error":"..."}
 *   'bare'    -> {"error":"..."}
 *
 * dental_scan.php is intentionally NOT migrated: its page-by-page schema/response is different.
 */

if (!function_exists('mh_ocr_json_error')) {
    /** Emit a failure in the endpoint's error style and stop. */
    function mh_ocr_json_error($message, $httpCode = 0, array $extra = [])
    {
        global $mh_ocr_style;
        if ($httpCode) {
            http_response_code((int) $httpCode);
        }
        if ($mh_ocr_style === 'message') {
            $payload = ['success' => false, 'message' => $message];
        } elseif ($mh_ocr_style === 'bare') {
            $payload = ['error' => $message];
        } else {
            $payload = ['success' => false, 'error' => $message];
        }
        echo json_encode($payload + $extra);
        exit;
    }
}

if (!function_exists('mh_ocr_preflight')) {
    /**
     * JSON-safe headers + session check. includes/admin_auth.php is NOT used on purpose:
     * on a missing session it sends an HTML redirect, which fetch()/JSON.parse() can't follow.
     */
    function mh_ocr_preflight($style = 'error')
    {
        global $mh_ocr_style;
        $mh_ocr_style = $style;

        error_reporting(E_ALL);
        ini_set('display_errors', 0);   // never leak PHP errors into a JSON response
        header('Content-Type: application/json');
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');

        if (!isset($_SESSION['user_id'])) {
            mh_ocr_json_error('Your session has expired. Please log in again.', 401);
        }
    }
}

if (!function_exists('mh_ocr_begin')) {
    /**
     * Retry budget, Gemini client + config, API-key check, POST check and the uploaded file.
     * Call AFTER config/database.php, settings_helper.php and mh_require_ocr_access_json().
     *
     * @param array $o field (upload field name), attempts, base_ms, cap_ms, timeout
     * @return array the upload ['success'=>true,'path'=>...,'mime_type'=>...]
     */
    function mh_ocr_begin(array $o)
    {
        // Defined BEFORE config/gemini.php, so these per-endpoint values win over its defaults.
        if (!defined('GEMINI_MAX_ATTEMPTS'))    { define('GEMINI_MAX_ATTEMPTS',    (int) ($o['attempts'] ?? 3)); }
        if (!defined('GEMINI_BACKOFF_BASE_MS')) { define('GEMINI_BACKOFF_BASE_MS', (int) ($o['base_ms'] ?? 700)); }
        if (!defined('GEMINI_BACKOFF_CAP_MS'))  { define('GEMINI_BACKOFF_CAP_MS',  (int) ($o['cap_ms'] ?? 6000)); }
        if (!defined('GEMINI_HTTP_TIMEOUT'))    { define('GEMINI_HTTP_TIMEOUT',    (int) ($o['timeout'] ?? 60)); }

        require_once __DIR__ . '/GeminiOcrClient.php';

        $cfg = __DIR__ . '/../../config/gemini.php';
        if (!file_exists($cfg)) {
            mh_ocr_json_error(
                'The OCR configuration loader is missing on the server, so the scanner cannot read its API key. '
                . 'Copy it from the project template and paste a key from Google AI Studio.',
                500
            );
        }
        require_once $cfg;

        // Fail fast on a missing / malformed key instead of a 404 from Google seconds later.
        if (function_exists('gemini_api_key_problem')) {
            $problem = gemini_api_key_problem();
            if ($problem !== null) {
                mh_ocr_json_error($problem, 500);
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            mh_ocr_json_error('Invalid request method.');
        }

        $upload = gemini_ocr_get_uploaded_file($o['field']);
        if (empty($upload['success'])) {
            mh_ocr_json_error($upload['error'] ?? 'The file could not be uploaded.');
        }
        return $upload;
    }
}

if (!function_exists('mh_ocr_extract')) {
    /** Run Gemini. On failure: 503 for transient upstream errors, then the endpoint's error shape. Returns the fields array. */
    function mh_ocr_extract(array $upload, $prompt, array $schema)
    {
        $result = gemini_ocr_extract_fields($upload['path'], $upload['mime_type'], $prompt, $schema);
        if (!empty($result['success'])) {
            return $result['fields'];
        }

        // 503 lets the browser console / monitoring tell "Gemini is down" apart from "bad scan".
        $transient = in_array(($result['http_code'] ?? 0), [0, 429, 500, 502, 503, 504], true);
        $extra = [];
        foreach (['http_code', 'model', 'debug'] as $k) {
            if (!empty($result[$k])) {
                $extra[$k] = $result[$k];
            }
        }
        mh_ocr_json_error($result['error'] ?? 'The scan failed.', $transient ? 503 : 0, $extra);
    }
}

if (!function_exists('mh_ocr_normalize_dates')) {
    /** Rewrite each listed date field as yyyy-mm-dd for <input type="date">; unparseable ones are dropped (manual entry beats a wrong date). */
    function mh_ocr_normalize_dates(array $fields, array $keys)
    {
        foreach ($keys as $key) {
            if (empty($fields[$key])) {
                continue;
            }
            $normalized = gemini_ocr_normalize_date($fields[$key]);
            if ($normalized !== '') {
                $fields[$key] = $normalized;
            } else {
                unset($fields[$key]);
            }
        }
        return $fields;
    }
}

if (!function_exists('mh_ocr_take_identity')) {
    /** Pull first_name / last_name / birthdate OUT of $fields (they are only used to find the patient). */
    function mh_ocr_take_identity(array &$fields)
    {
        $identity = [
            'first_name' => $fields['first_name'] ?? '',
            'last_name'  => $fields['last_name'] ?? '',
            'birthdate'  => '',
        ];
        unset($fields['first_name'], $fields['last_name']);

        if (!empty($fields['birthdate'])) {
            $bday = gemini_ocr_normalize_date($fields['birthdate']);
            if ($bday !== '') {
                $identity['birthdate'] = $bday;
            }
        }
        unset($fields['birthdate']);
        return $identity;
    }
}

if (!function_exists('mh_ocr_match')) {
    /** Existing-patient lookup (prepared statement inside gemini_ocr_match_patient). Returns the matched_patient payload or null. */
    function mh_ocr_match(array $identity)
    {
        global $conn;
        if ($identity['first_name'] === '' || $identity['last_name'] === '' || !($conn instanceof mysqli)) {
            return null;
        }
        $m = gemini_ocr_match_patient($conn, $identity['first_name'], $identity['last_name'], $identity['birthdate']);
        if (!$m) {
            return null;
        }
        return [
            'id'          => (int) $m['id'],
            'full_name'   => $m['full_name'],
            'first_name'  => $m['first_name'],
            'middle_name' => $m['middle_name'] ?? '',
            'last_name'   => $m['last_name'],
            'birthdate'   => $m['birthdate'],
        ];
    }
}
