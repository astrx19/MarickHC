<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE: PHP registers top-level functions at
// compile time, so require_once cannot stop a redeclare when one file is reached by two paths that differ
// only in letter case (/MARICK-HEALTH-SYSTEM-v2/ vs /marick-health-system-v2/ on macOS/Windows).
if (!defined('MH_LOADED_INCLUDES_OCR_GEMINIOCRCLIENT')) {
define('MH_LOADED_INCLUDES_OCR_GEMINIOCRCLIENT', true);
/**
 * Shared helper for calling the Gemini API to extract structured field
 * data from a scanned/photographed paper form.
 *
 * Used by:
 *   - admin/ocr/immunization_scan.php
 *   - admin/ocr/maternal_scan.php
 *   - admin/ocr/checkup_scan.php
 *
 * Requires config/gemini.php to be loaded first.
 *
 * What changed in this revision:
 *   - Model fallback chain: a 404 on one model automatically retries the
 *     next model instead of failing the whole scan (gemini-1.5-flash is
 *     retired and now returns 404 - that was the main bug).
 *   - The API key is sent in the x-goog-api-key header, not the query
 *     string, so it does not end up in server / proxy access logs.
 *   - Key shape is validated up front (must start with "AIzaSy").
 *   - True exponential backoff with jitter + Retry-After support for
 *     503 / 429 / transient network failures.
 *   - Every failure path returns a specific, human-readable message
 *     (plus an "http_code" and "reason" the callers can pass through).
 */

if (!defined('GEMINI_API_ENDPOINT_BASE')) {
    define('GEMINI_API_ENDPOINT_BASE', 'https://generativelanguage.googleapis.com');
}

/* =====================================================================
 * UPLOAD HANDLING
 * ================================================================== */

/**
 * Reads and validates the uploaded file for a given $_FILES field name.
 *
 * @return array{success:bool, path?:string, mime_type?:string, error?:string}
 */
function gemini_ocr_get_uploaded_file($fieldName)
{
    if (!isset($_FILES[$fieldName])) {
        return ['success' => false, 'error' => 'No file was received by the server. Please choose a file and try again.'];
    }

    $err = $_FILES[$fieldName]['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'The file is larger than this server allows (upload_max_filesize in php.ini). Please use a smaller photo.',
            UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form allows. Please use a smaller photo.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected. Please choose a scan or photo to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: PHP has no temporary upload folder. Contact the system administrator.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk. Contact the system administrator.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload. Contact the system administrator.',
        ];
        return ['success' => false, 'error' => $map[$err] ?? 'The file could not be uploaded (error code ' . $err . ').'];
    }

    $tmpPath = $_FILES[$fieldName]['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        return ['success' => false, 'error' => 'The uploaded file could not be verified. Please try again.'];
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = (string) finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }
    }
    if ($mimeType === '' && function_exists('mime_content_type')) {
        $mimeType = (string) mime_content_type($tmpPath);
    }

    // Normalise a couple of aliases browsers still send.
    $aliases = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'image/x-png' => 'image/png'];
    if (isset($aliases[$mimeType])) {
        $mimeType = $aliases[$mimeType];
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];
    if (!in_array($mimeType, $allowed, true)) {
        return ['success' => false, 'error' => 'Unsupported file type (' . ($mimeType ?: 'unknown')
            . '). Please upload a JPG, PNG, WEBP, HEIC, or PDF.'];
    }

    $size = filesize($tmpPath);
    if ($size === false || $size === 0) {
        return ['success' => false, 'error' => 'The uploaded file appears to be empty. Please try again.'];
    }

    // 15 MB safety cap, well under Gemini's inline-data limits.
    if ($size > 15 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File is too large (' . round($size / 1048576, 1)
            . ' MB). Please upload a file under 15 MB.'];
    }

    return ['success' => true, 'path' => $tmpPath, 'mime_type' => $mimeType];
}

/* =====================================================================
 * MODEL LIST
 * ================================================================== */

/**
 * Ordered list of models to try. The configured model comes first, then
 * the fallbacks, de-duplicated. Retired models such as gemini-1.5-flash
 * are still honoured if explicitly configured, but they will 404 and the
 * client will move on to the next candidate automatically.
 */
function gemini_ocr_model_candidates()
{
    $models = [];

    if (defined('GEMINI_OCR_MODEL') && trim((string) GEMINI_OCR_MODEL) !== '') {
        $models[] = trim((string) GEMINI_OCR_MODEL);
    }

    $fallbacks = defined('GEMINI_OCR_FALLBACK_MODELS')
        ? GEMINI_OCR_FALLBACK_MODELS
        : 'gemini-2.0-flash,gemini-2.5-flash,gemini-flash-latest';

    foreach (explode(',', (string) $fallbacks) as $m) {
        $m = trim($m);
        if ($m !== '') { $models[] = $m; }
    }

    $models = array_values(array_unique($models));

    return $models ?: ['gemini-2.0-flash'];
}

/* =====================================================================
 * LOW-LEVEL HTTP
 * ================================================================== */

/**
 * Single POST to the Gemini generateContent endpoint.
 *
 * @return array{http_code:int, body:string|false, curl_error:string, retry_after:int}
 */
function gemini_ocr_http_post($model, $payloadJson)
{
    $version = defined('GEMINI_API_VERSION') ? GEMINI_API_VERSION : 'v1beta';
    $timeout = defined('GEMINI_HTTP_TIMEOUT') ? (int) GEMINI_HTTP_TIMEOUT : 60;

    $url = GEMINI_API_ENDPOINT_BASE . '/' . $version . '/models/'
         . rawurlencode($model) . ':generateContent';

    $retryAfter = 0;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        // Key travels as a header so it is not written into access logs.
        'x-goog-api-key: ' . GEMINI_API_KEY,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$retryAfter) {
        if (stripos($header, 'retry-after:') === 0) {
            $value = trim(substr($header, strlen('retry-after:')));
            if (ctype_digit($value)) {
                $retryAfter = (int) $value;
            }
        }
        return strlen($header);
    });

    $body      = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'http_code'   => $httpCode,
        'body'        => $body,
        'curl_error'  => $curlError,
        'retry_after' => $retryAfter,
    ];
}

/**
 * Sleep helper that understands milliseconds.
 */
function gemini_ocr_sleep_ms($ms)
{
    if ($ms <= 0) { return; }
    usleep((int) round($ms * 1000));
}

/**
 * Exponential backoff with full jitter, honouring a server Retry-After.
 *
 * attempt 0 -> ~0.7s, 1 -> ~1.4s, 2 -> ~2.8s, 3 -> ~5.6s (capped at 8s)
 */
function gemini_ocr_backoff_delay_ms($attempt, $retryAfterSeconds = 0)
{
    if ($retryAfterSeconds > 0) {
        return min($retryAfterSeconds * 1000, 15000);
    }

    $base = defined('GEMINI_BACKOFF_BASE_MS') ? (int) GEMINI_BACKOFF_BASE_MS : 700;
    $cap  = defined('GEMINI_BACKOFF_CAP_MS')  ? (int) GEMINI_BACKOFF_CAP_MS  : 8000;

    $ideal = min($cap, $base * (int) pow(2, $attempt));

    // Full jitter: random between half and full of the ideal window.
    return random_int((int) ($ideal / 2), $ideal);
}

/**
 * Turns a Gemini error response into something a barangay health worker
 * can actually act on.
 */
function gemini_ocr_friendly_error($httpCode, $decoded, $model, $curlError = '')
{
    $apiMessage = '';
    $apiStatus  = '';
    if (is_array($decoded)) {
        $apiMessage = (string) ($decoded['error']['message'] ?? '');
        $apiStatus  = (string) ($decoded['error']['status'] ?? '');
    }

    switch ($httpCode) {
        case 0:
            $detail = $curlError !== '' ? ' (' . $curlError . ')' : '';
            return 'Could not reach the Google Gemini service' . $detail
                 . '. Check this computer\'s internet connection, then try the scan again.';

        case 400:
            if (stripos($apiMessage, 'API key not valid') !== false || stripos($apiMessage, 'API_KEY_INVALID') !== false) {
                return 'Google rejected the API key as invalid. Generate a new key at '
                     . 'https://aistudio.google.com/app/apikey and paste it into System Settings > Scanner (OCR).';
            }
            return 'Gemini rejected the request (HTTP 400). '
                 . ($apiMessage !== '' ? $apiMessage : 'The document or request format was not accepted.');

        case 401:
            return 'The Gemini API key was not accepted (HTTP 401 Unauthorized). Confirm that the key in '
                 . 'saved in System Settings starts with "AIzaSy" and has not been deleted or rotated in Google AI Studio.';

        case 403:
            if (stripos($apiMessage, 'SERVICE_DISABLED') !== false || stripos($apiMessage, 'has not been used') !== false) {
                return 'The Generative Language API is not enabled for this Google Cloud project (HTTP 403). '
                     . 'Enable "Generative Language API" in the Google Cloud console, wait a minute, then retry.';
            }
            return 'Access to the Gemini API was refused (HTTP 403). The key may be restricted to certain '
                 . 'websites or IP addresses, or billing may not be set up for the project.';

        case 404:
            return 'The AI model "' . $model . '" is not available to this API key (HTTP 404). '
                 . 'Older models such as gemini-1.5-flash have been retired. Open System Settings and switch the '
                 . 'OCR model in System Settings > Scanner (OCR).';

        case 413:
            return 'The uploaded document is too large for the AI service. Please take a smaller or more compressed photo.';

        case 429:
            return 'The Gemini free-tier rate limit has been reached (HTTP 429). Please wait about a minute and scan again.';

        case 500:
        case 502:
            return 'Google\'s AI service had an internal error (HTTP ' . $httpCode . '). This is temporary - please try the scan again.';

        case 503:
            return 'The AI service is overloaded right now (HTTP 503 - high demand). The system already retried several times. '
                 . 'Please wait a moment and scan again, or encode the form manually.';

        case 504:
            return 'The AI service took too long to respond (HTTP 504). Try again with a smaller or clearer photo.';
    }

    if ($apiMessage !== '') {
        return 'Gemini returned an error (HTTP ' . $httpCode
             . ($apiStatus !== '' ? ' / ' . $apiStatus : '') . '): ' . $apiMessage;
    }

    return 'The OCR service returned an unexpected response (HTTP ' . $httpCode . '). Please try again in a moment.';
}

/* =====================================================================
 * MAIN ENTRY POINT
 * ================================================================== */

/**
 * Sends the given file to Gemini with a JSON response schema and returns
 * the extracted fields as an associative array.
 *
 * @param string $filePath   Path to the uploaded file on disk.
 * @param string $mimeType   MIME type of the uploaded file.
 * @param string $promptText Instructions describing what to extract.
 * @param array  $schema     Gemini responseSchema (JSON Schema subset).
 *
 * @return array{success:bool, fields?:array, error?:string, http_code?:int, model?:string, debug?:array}
 */
function gemini_ocr_extract_fields($filePath, $mimeType, $promptText, array $schema)
{
    // ---- 1. Configuration sanity checks -----------------------------
    if (function_exists('gemini_api_key_problem')) {
        $keyProblem = gemini_api_key_problem();
        if ($keyProblem !== null) {
            return ['success' => false, 'error' => $keyProblem, 'http_code' => 0, 'reason' => 'config'];
        }
    } elseif (!defined('GEMINI_API_KEY') || trim((string) GEMINI_API_KEY) === '') {
        return ['success' => false, 'error' => 'The Gemini API key has not been configured yet. Add it in System Settings > Scanner (OCR).',
                'http_code' => 0, 'reason' => 'config'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'The PHP cURL extension is not enabled on this server, so the scanner cannot '
            . 'contact Google. Enable php_curl in php.ini and restart Apache.', 'http_code' => 0, 'reason' => 'config'];
    }

    // ---- 2. Read + encode the document ------------------------------
    $fileContents = @file_get_contents($filePath);
    if ($fileContents === false || $fileContents === '') {
        return ['success' => false, 'error' => 'Could not read the uploaded file from the server.', 'http_code' => 0, 'reason' => 'upload'];
    }
    $imageData = base64_encode($fileContents);
    unset($fileContents);

    $payload = [
        'contents' => [[
            'role'  => 'user',
            'parts' => [
                ['text' => $promptText],
                ['inline_data' => [
                    'mime_type' => $mimeType,
                    'data'      => $imageData,
                ]],
            ],
        ]],
        'generationConfig' => [
            'temperature'      => 0,
            'responseMimeType' => 'application/json',
            'responseSchema'   => $schema,
        ],
    ];

    $payloadJson = json_encode($payload);
    unset($payload, $imageData);

    if ($payloadJson === false) {
        return ['success' => false, 'error' => 'The document could not be prepared for the AI service (JSON encoding failed).',
                'http_code' => 0, 'reason' => 'encode'];
    }

    // ---- 3. Try each model, with backoff on transient failures -------
    $maxAttempts = defined('GEMINI_MAX_ATTEMPTS') ? max(1, (int) GEMINI_MAX_ATTEMPTS) : 4;
    $models      = gemini_ocr_model_candidates();

    $lastError   = 'The OCR service could not be reached.';
    $lastCode    = 0;
    $lastModel   = $models[0];
    $trace       = [];

    foreach ($models as $model) {
        $lastModel = $model;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {

            $res      = gemini_ocr_http_post($model, $payloadJson);
            $httpCode = $res['http_code'];
            $body     = $res['body'];
            $decoded  = ($body !== false && $body !== '') ? json_decode($body, true) : null;

            $trace[] = ['model' => $model, 'attempt' => $attempt + 1, 'http' => $httpCode];

            // ---- success -------------------------------------------
            if ($httpCode === 200 && is_array($decoded)) {
                $parsed = gemini_ocr_parse_candidate($decoded);
                if ($parsed['success']) {
                    $parsed['model'] = $model;
                    if (defined('GEMINI_DEBUG') && GEMINI_DEBUG) { $parsed['debug'] = $trace; }
                    return $parsed;
                }
                // 200 but unreadable: retrying the same model rarely helps.
                $parsed['http_code'] = 200;
                $parsed['model']     = $model;
                if (defined('GEMINI_DEBUG') && GEMINI_DEBUG) { $parsed['debug'] = $trace; }
                return $parsed;
            }

            $lastCode  = $httpCode;
            $lastError = gemini_ocr_friendly_error($httpCode, $decoded, $model, $res['curl_error']);

            // ---- 404: this model is gone. Do not waste retries. -----
            if ($httpCode === 404) {
                break; // move on to the next model in the chain
            }

            // ---- 401 / 403 / 400: a retry will fail the same way ----
            if (in_array($httpCode, [400, 401, 403, 413], true)) {
                if (defined('GEMINI_DEBUG') && GEMINI_DEBUG) {
                    return ['success' => false, 'error' => $lastError, 'http_code' => $httpCode,
                            'model' => $model, 'reason' => 'permanent', 'debug' => $trace];
                }
                return ['success' => false, 'error' => $lastError, 'http_code' => $httpCode,
                        'model' => $model, 'reason' => 'permanent'];
            }

            // ---- transient: 0 (network), 429, 500, 502, 503, 504 ----
            $isTransient = ($httpCode === 0 || $httpCode === 429 || $httpCode >= 500);
            if (!$isTransient) {
                break;
            }

            if ($attempt < $maxAttempts - 1) {
                gemini_ocr_sleep_ms(gemini_ocr_backoff_delay_ms($attempt, $res['retry_after']));
                continue;
            }
            // Retries exhausted for this model - fall through to the next one.
        }
    }

    $out = [
        'success'   => false,
        'error'     => $lastError,
        'http_code' => $lastCode,
        'model'     => $lastModel,
        'reason'    => 'exhausted',
    ];
    if (defined('GEMINI_DEBUG') && GEMINI_DEBUG) { $out['debug'] = $trace; }
    return $out;
}

/**
 * Pulls the structured JSON payload out of a successful Gemini response.
 *
 * @return array{success:bool, fields?:array, error?:string}
 */
function gemini_ocr_parse_candidate(array $decoded)
{
    $finishReason = $decoded['candidates'][0]['finishReason'] ?? '';
    $rawText      = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if ($rawText === '') {
        $blockReason = $decoded['promptFeedback']['blockReason'] ?? null;
        if ($blockReason) {
            return ['success' => false, 'error' => 'Google\'s safety filter blocked this document (' . $blockReason
                . '). Please encode this form manually.'];
        }
        if ($finishReason === 'MAX_TOKENS') {
            return ['success' => false, 'error' => 'The document was too long for one pass. Try scanning one page at a time.'];
        }
        if ($finishReason === 'RECITATION') {
            return ['success' => false, 'error' => 'The AI declined to transcribe this document. Please encode it manually.'];
        }
        return ['success' => false, 'error' => 'The model did not return any readable content from the document. '
            . 'Try a brighter, straighter photo of the form.'];
    }

    // Strip stray markdown fences if the model wrapped its JSON.
    $clean = trim($rawText);
    if (strpos($clean, '```') === 0) {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
    }

    $fields = json_decode($clean, true);
    if (!is_array($fields)) {
        return ['success' => false, 'error' => 'Could not read structured data from the scanned document. '
            . 'Please try a clearer, well-lit photo taken straight above the form.'];
    }

    // Normalize: trim strings, drop blanks, keep dates as plain strings
    // for the calling endpoint to reformat as needed.
    $out = [];
    foreach ($fields as $key => $value) {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || strcasecmp($value, 'null') === 0 || strcasecmp($value, 'n/a') === 0) {
                continue;
            }
        }
        if ($value === null) { continue; }
        $out[$key] = $value;
    }

    return ['success' => true, 'fields' => $out];
}

/* =====================================================================
 * DIAGNOSTICS
 * ================================================================== */

/**
 * Calls ListModels so an administrator can see exactly which models the
 * configured key may use. Used by admin/ocr/gemini_diagnose.php.
 *
 * @return array{success:bool, models?:array, error?:string, http_code?:int}
 */
function gemini_ocr_list_models()
{
    if (function_exists('gemini_api_key_problem')) {
        $problem = gemini_api_key_problem();
        if ($problem !== null) {
            return ['success' => false, 'error' => $problem, 'http_code' => 0];
        }
    }

    $version = defined('GEMINI_API_VERSION') ? GEMINI_API_VERSION : 'v1beta';
    $url     = GEMINI_API_ENDPOINT_BASE . '/' . $version . '/models?pageSize=100';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-goog-api-key: ' . GEMINI_API_KEY]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body      = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = ($body !== false && $body !== '') ? json_decode($body, true) : null;

    if ($httpCode !== 200 || !is_array($decoded)) {
        return [
            'success'   => false,
            'error'     => gemini_ocr_friendly_error($httpCode, $decoded, '(model list)', $curlError),
            'http_code' => $httpCode,
        ];
    }

    $names = [];
    foreach (($decoded['models'] ?? []) as $m) {
        $methods = $m['supportedGenerationMethods'] ?? [];
        if (!in_array('generateContent', $methods, true)) { continue; }
        $name = (string) ($m['name'] ?? '');
        if (strpos($name, 'models/') === 0) { $name = substr($name, 7); }
        if ($name !== '') { $names[] = $name; }
    }
    sort($names);

    return ['success' => true, 'models' => $names, 'http_code' => 200];
}

/* =====================================================================
 * DATE NORMALISATION
 * ================================================================== */

/**
 * Best-effort normalization of a date string (as commonly handwritten on
 * PH clinic forms, e.g. "03/14/2024", "14-03-24", "March 14, 2024") into
 * the yyyy-mm-dd format required by <input type="date">.
 * Returns '' if it cannot confidently parse the value.
 */
function gemini_ocr_normalize_date($rawDate)
{
    if (!$rawDate || !is_string($rawDate)) {
        return '';
    }
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return '';
    }

    // Already ISO.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        return $rawDate;
    }

    // PH clinic forms are overwhelmingly month/day/year. Resolve the
    // ambiguous n/n/nnnn case explicitly so 03/14/2024 and 14/03/2024
    // both land on 2024-03-14 instead of silently failing.
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $rawDate, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) { $y += ($y > 50) ? 1900 : 2000; }

        if ($a > 12 && $b <= 12) {          // clearly day/month
            $month = $b; $day = $a;
        } else {                             // default to month/day
            $month = $a; $day = $b;
        }
        if (checkdate($month, $day, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $month, $day);
        }
        return '';
    }

    $timestamp = strtotime($rawDate);
    if ($timestamp === false) {
        return '';
    }

    $iso = date('Y-m-d', $timestamp);

    // Guard against strtotime turning junk like "ok" into today's date.
    $year = (int) substr($iso, 0, 4);
    if ($year < 1900 || $year > ((int) date('Y') + 2)) {
        return '';
    }

    return $iso;
}

/* =====================================================================
 * DYNAMIC PATIENT MATCHING
 * ================================================================== */

/**
 * Looks up an existing patient from OCR-extracted identity fields.
 *
 * Matches on first_name + last_name (case-insensitive, tolerant of
 * leading/trailing OCR noise via LIKE) and, when a birthdate was also
 * extracted, prefers/limits to a matching birthdate so two patients who
 * share a name aren't confused. Always uses a parameterized statement.
 *
 * @param mysqli $conn
 * @param string $firstName  As extracted from the scanned document.
 * @param string $lastName   As extracted from the scanned document.
 * @param string $birthdate  Optional, normalized yyyy-mm-dd (see gemini_ocr_normalize_date).
 * @return array|null {id, first_name, middle_name, last_name, birthdate, full_name} or null if no match.
 */
function gemini_ocr_match_patient(mysqli $conn, $firstName, $lastName, $birthdate = '')
{
    $firstName = trim((string) $firstName);
    $lastName  = trim((string) $lastName);

    if ($firstName === '' || $lastName === '') {
        return null; // not enough signal to search safely
    }

    $firstLike = '%' . $firstName . '%';
    $lastLike  = '%' . $lastName . '%';

    if ($birthdate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
        // Birthdate was extracted too - use it to disambiguate same-name patients.
        $stmt = $conn->prepare(
            "SELECT id, first_name, middle_name, last_name, birthdate
             FROM patients
             WHERE last_name LIKE ? AND first_name LIKE ? AND birthdate = ?
             ORDER BY id ASC LIMIT 1"
        );
        if (!$stmt) { return null; }
        $stmt->bind_param('sss', $lastLike, $firstLike, $birthdate);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, first_name, middle_name, last_name, birthdate
             FROM patients
             WHERE last_name LIKE ? AND first_name LIKE ?
             ORDER BY id ASC LIMIT 1"
        );
        if (!$stmt) { return null; }
        $stmt->bind_param('ss', $lastLike, $firstLike);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        // Birthdate-qualified search found nothing - fall back to a
        // name-only search in case the OCR'd birthdate itself was wrong.
        if ($birthdate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
            return gemini_ocr_match_patient($conn, $firstName, $lastName, '');
        }
        return null;
    }

    $row['full_name'] = function_exists('mh_format_patient_name')
        ? mh_format_patient_name($row['first_name'], $row['middle_name'] ?? '', $row['last_name'])
        : trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_name'] ?? ''));

    return $row;
}
}
