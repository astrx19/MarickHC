<?php
if (!defined('MH_LOADED_INCLUDES_OCR_GEMINIOCRCLIENT')) {
define('MH_LOADED_INCLUDES_OCR_GEMINIOCRCLIENT', true);

if (!defined('GEMINI_API_ENDPOINT_BASE')) {
    define('GEMINI_API_ENDPOINT_BASE', '');
}

if (!function_exists('gemini_ocr_get_uploaded_file')) {
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

    if ($size > 15 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File is too large (' . round($size / 1048576, 1)
            . ' MB). Please upload a file under 15 MB.'];
    }

    return ['success' => true, 'path' => $tmpPath, 'mime_type' => $mimeType];
}
}

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

function gemini_ocr_http_post($model, $payloadJson)
{
    return [
        'http_code'   => 0,
        'body'        => false,
        'curl_error'  => 'Gemini API endpoint has been decommissioned.',
        'retry_after' => 0,
    ];
}

function gemini_ocr_sleep_ms($ms)
{
    if ($ms <= 0) { return; }
    usleep((int) round($ms * 1000));
}

function gemini_ocr_backoff_delay_ms($attempt, $retryAfterSeconds = 0)
{
    if ($retryAfterSeconds > 0) {
        return min($retryAfterSeconds * 1000, 15000);
    }

    $base = defined('GEMINI_BACKOFF_BASE_MS') ? (int) GEMINI_BACKOFF_BASE_MS : 700;
    $cap  = defined('GEMINI_BACKOFF_CAP_MS')  ? (int) GEMINI_BACKOFF_CAP_MS  : 8000;

    $ideal = min($cap, $base * (int) pow(2, $attempt));

    return random_int((int) ($ideal / 2), $ideal);
}

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

function gemini_ocr_extract_fields($filePath, $mimeType, $promptText, array $schema)
{
    try {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['success' => false, 'error' => 'Could not read the uploaded file from the server.', 'http_code' => 0, 'reason' => 'upload'];
        }
        require_once __DIR__ . '/../vision_ocr_helper.php';
        if (function_exists('call_groq_ocr')) {
            $ocr = call_groq_ocr($filePath);
            if (!empty($ocr['success'])) {
                return [
                    'success' => true,
                    'fields'  => $ocr['kv'] ?? [],
                    'raw'     => $ocr['raw'] ?? [],
                    'model'   => 'groq-vision',
                ];
            }
            return [
                'success'   => false,
                'error'     => $ocr['error'] ?? 'OCR extraction failed.',
                'http_code' => 0,
                'model'     => 'groq-vision',
                'reason'    => 'failed',
            ];
        }
        return ['success' => false, 'error' => 'OCR engine not available.', 'http_code' => 0, 'reason' => 'config'];
    } catch (Throwable $e) {
        return [
            'success'   => false,
            'error'     => $e->getMessage(),
            'http_code' => 500,
            'model'     => 'unknown',
            'reason'    => 'exception',
        ];
    }
}

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

function gemini_ocr_list_models()
{
    return [
        'success'   => true,
        'models'    => ['llama-3.3-70b-versatile', 'qwen/qwen3.8-27b'],
        'http_code' => 200,
    ];
}

if (!function_exists('gemini_ocr_normalize_date')) {
function gemini_ocr_normalize_date($rawDate)
{
    if (!$rawDate || !is_string($rawDate)) {
        return '';
    }
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        return $rawDate;
    }

    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $rawDate, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $y = (int) $m[3];
        if ($y < 100) { $y += ($y > 50) ? 1900 : 2000; }

        if ($a > 12 && $b <= 12) {
            $month = $b; $day = $a;
        } else {
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

    $year = (int) substr($iso, 0, 4);
    if ($year < 1900 || $year > ((int) date('Y') + 2)) {
        return '';
    }

    return $iso;
}
}

if (!function_exists('gemini_ocr_match_patient')) {
function gemini_ocr_match_patient(mysqli $conn, $firstName, $lastName, $birthdate = '')
{
    try {
        $firstName = trim((string) $firstName);
        $lastName  = trim((string) $lastName);

        if ($firstName === '' || $lastName === '') {
            return null;
        }

        $firstLike = '%' . $firstName . '%';
        $lastLike  = '%' . $lastName . '%';

        if ($birthdate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
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
            if ($birthdate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
                return gemini_ocr_match_patient($conn, $firstName, $lastName, '');
            }
            return null;
        }

        $row['full_name'] = function_exists('mh_format_patient_name')
            ? mh_format_patient_name($row['first_name'], $row['middle_name'] ?? '', $row['last_name'])
            : trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_name'] ?? ''));

        return $row;
    } catch (Throwable $e) {
        return null;
    }
}
}
}
