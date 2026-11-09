<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - GOOGLE CLOUD VISION OCR HELPER
 * =====================================================================
 * Reusable helper providing integration with the Google Cloud Vision API
 * (DOCUMENT_TEXT_DETECTION feature) and document parsing functions.
 * =====================================================================
 */

if (!defined('MH_LOADED_INCLUDES_VISION_OCR_HELPER')) {
define('MH_LOADED_INCLUDES_VISION_OCR_HELPER', true);

require_once __DIR__ . '/../config/vision.php';

/**
 * Logs an OCR error explicitly to both the PHP server error log and
 * the database activity_logs (System Logs) without throwing or crashing.
 *
 * @param string $engine  'vision' | 'gemini' | 'router' | string
 * @param string $stage   'cURL' | 'JSON' | 'API' | 'File' | 'Config' | 'Exception'
 * @param string $message Error message or description.
 * @param array  $context Additional debug context (HTTP code, curl errno, snippet, etc.)
 */
function vision_ocr_log_error($engine, $stage, $message, array $context = [])
{
    static $logged = [];

    $engineLabels = [
        'gemini'       => 'Gemini AI',
        'vision'       => 'Cloud Vision',
        'aws_textract' => 'AWS Textract',
        'groq_ocr'     => 'Groq OCR',
    ];
    $engineLabel = $engineLabels[$engine] ?? ucfirst($engine);
    $key = $engine . '|' . $stage . '|' . $message;
    if (isset($logged[$key])) {
        return; // Avoid duplicating identical log entries in the same request
    }
    $logged[$key] = true;

    $logMsg = "[MarickHealth][OCR][{$engineLabel}][{$stage}] {$message}";
    if (!empty($context)) {
        $logMsg .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // 1. Log to server error log
    @error_log($logMsg);

    // 2. Log to database activity_logs (System Logs)
    try {
        $conn = $GLOBALS['conn'] ?? null;
        if ($conn instanceof mysqli && function_exists('logActivity')) {
            $userId   = (int) ($_SESSION['user_id'] ?? 0);
            $userRole = (string) ($_SESSION['role'] ?? 'system');
            $details  = "{$engineLabel} ({$stage}): " . substr($message, 0, 250);
            if (!empty($context['http_code'])) {
                $details .= " [HTTP {$context['http_code']}]";
            }
            if (!empty($context['curl_no'])) {
                $details .= " [cURL Err #{$context['curl_no']}]";
            }
            @logActivity($conn, $userId, $userRole, 'OCR Error', $details);
        }
    } catch (Throwable $e) {
        // Logging should never throw
    }
}

/**
 * Sends an image to the Google Cloud Vision API using DOCUMENT_TEXT_DETECTION.
 *
 * All cURL operations, request building, and JSON handling are safely wrapped
 * in a try-catch block. cURL errors and invalid JSON responses are logged
 * explicitly to system logs, and a clean fallback error structure is returned.
 *
 * @param string $imagePath Local absolute or relative path to the image/document file.
 * @param string|null $apiKey Optional override for the Vision API key.
 * @return array{success:bool, text:string, error?:string, raw?:array}
 */
function call_google_vision_ocr($imagePath, $apiKey = null)
{
    try {
        if (empty($apiKey)) {
            $apiKey = function_exists('mh_ocr_live_setting')
                ? mh_ocr_live_setting('google_vision_api_key', '')
                : (defined('GOOGLE_VISION_API_KEY') ? GOOGLE_VISION_API_KEY : '');
            if ($apiKey === '' && function_exists('mh_ocr_live_setting')) {
                $apiKey = mh_ocr_live_setting('vision_api_key', '');
            }
            if ($apiKey === '' && defined('GOOGLE_VISION_API_KEY')) {
                $apiKey = GOOGLE_VISION_API_KEY;
            }
            if ($apiKey === '') {
                $apiKey = 'AIzaSyB_3kCP-DpMQqM07x99uhJgiNDFyxHQukI';
            }
        }

        if (trim((string) $apiKey) === '') {
            $err = 'Google Cloud Vision API key is not configured. Please set it in System Settings > Scanner (OCR).';
            vision_ocr_log_error('vision', 'Config', $err);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            $err = 'The document image file could not be read or does not exist on the server.';
            vision_ocr_log_error('vision', 'File', $err, ['path' => $imagePath]);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        $imageBytes = @file_get_contents($imagePath);
        if ($imageBytes === false || strlen($imageBytes) === 0) {
            $err = 'The uploaded document is empty or could not be loaded into memory.';
            vision_ocr_log_error('vision', 'File', $err);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        $base64 = base64_encode($imageBytes);
        unset($imageBytes);

        $payload = [
            'requests' => [
                [
                    'image' => [
                        'content' => $base64,
                    ],
                    'features' => [
                        [
                            'type' => 'DOCUMENT_TEXT_DETECTION',
                        ],
                    ],
                ],
            ],
        ];

        $jsonPayload = json_encode($payload);
        unset($base64, $payload);

        if ($jsonPayload === false) {
            $err = 'Failed to prepare the scanned document payload (JSON encoding error).';
            vision_ocr_log_error('vision', 'JSON', $err, ['json_error' => json_last_error_msg()]);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        $url = (defined('GOOGLE_VISION_API_ENDPOINT') ? GOOGLE_VISION_API_ENDPOINT : 'https://vision.googleapis.com/v1/images:annotate')
             . '?key=' . urlencode($apiKey);

        if (!function_exists('curl_init')) {
            $err = 'The cURL PHP extension is not enabled on this server. Contact the administrator to enable php_curl.';
            vision_ocr_log_error('vision', 'cURL', $err);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        $ch = curl_init($url);
        if (!$ch) {
            $err = 'Failed to initialize cURL for Google Cloud Vision API.';
            vision_ocr_log_error('vision', 'cURL', $err);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($jsonPayload),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = (string) curl_error($ch);
        $curlNo   = (int) curl_errno($ch);
        curl_close($ch);

        // Check for cURL / network level errors
        if ($curlNo !== 0) {
            $errDesc = $curlErr ?: ('cURL Error #' . $curlNo);
            vision_ocr_log_error('vision', 'cURL', $errDesc, ['curl_no' => $curlNo, 'http_code' => $httpCode]);
            return [
                'success' => false,
                'text'    => '',
                'error'   => 'Network error connecting to Google Cloud Vision API: ' . $errDesc,
            ];
        }

        // Validate JSON decoding
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            $snippet = substr(trim((string) $response), 0, 300);
            vision_ocr_log_error('vision', 'JSON', 'Invalid JSON response from Google Cloud Vision API', [
                'http_code'        => $httpCode,
                'response_snippet' => $snippet,
            ]);
            return [
                'success' => false,
                'text'    => '',
                'error'   => 'Google Cloud Vision API returned an invalid response (HTTP ' . $httpCode . '). Please check the system logs.',
            ];
        }

        // Check for API-level errors from Google
        if (!empty($data['error'])) {
            $msg  = (string) ($data['error']['message'] ?? 'Google Cloud Vision API error.');
            $code = $data['error']['code'] ?? $httpCode;
            vision_ocr_log_error('vision', 'API', $msg, ['api_code' => $code]);
            return [
                'success' => false,
                'text'    => '',
                'error'   => 'Google Cloud Vision error: ' . $msg,
                'raw'     => $data,
            ];
        }

        // Check for response-level error
        if (!empty($data['responses'][0]['error'])) {
            $msg = (string) ($data['responses'][0]['error']['message'] ?? 'Vision API error in image annotation.');
            vision_ocr_log_error('vision', 'API', $msg, ['response_error' => $data['responses'][0]['error']]);
            return [
                'success' => false,
                'text'    => '',
                'error'   => 'Vision scanning error: ' . $msg,
                'raw'     => $data,
            ];
        }

        // Extract text from fullTextAnnotation or fallback to textAnnotations[0]
        $text = '';
        if (!empty($data['responses'][0]['fullTextAnnotation']['text'])) {
            $text = (string) $data['responses'][0]['fullTextAnnotation']['text'];
        } elseif (!empty($data['responses'][0]['textAnnotations'][0]['description'])) {
            $text = (string) $data['responses'][0]['textAnnotations'][0]['description'];
        }

        return [
            'success' => true,
            'text'    => trim($text),
            'raw'     => $data,
        ];
    } catch (Throwable $e) {
        vision_ocr_log_error('vision', 'Exception', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return [
            'success' => false,
            'text'    => '',
            'error'   => 'An error occurred during Cloud Vision OCR processing: ' . $e->getMessage(),
        ];
    }
}

/**
 * Validates and reads an uploaded file from $_FILES for OCR scanning.
 *
 * @param string $fieldName Field name in $_FILES.
 * @return array{success:bool, path?:string, mime_type?:string, error?:string}
 */
function vision_ocr_get_uploaded_file($fieldName)
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
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: PHP has no temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
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
        return ['success' => false, 'error' => 'Unsupported file type (' . ($mimeType ?: 'unknown') . '). Please upload a JPG, PNG, WEBP, HEIC, or PDF.'];
    }

    $size = filesize($tmpPath);
    if ($size === false || $size === 0) {
        return ['success' => false, 'error' => 'The uploaded file appears to be empty. Please try again.'];
    }

    return [
        'success'   => true,
        'path'      => $tmpPath,
        'mime_type' => $mimeType,
    ];
}

/**
 * Normalizes date strings (e.g. "03/14/2024", "14-03-24", "March 14, 2024") to YYYY-MM-DD.
 */
function vision_ocr_normalize_date($rawDate)
{
    if (!$rawDate || !is_string($rawDate)) {
        return '';
    }
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return '';
    }

    // Already ISO yyyy-mm-dd
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        return $rawDate;
    }

    // Numbers separated by slashes/dashes
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
    }

    $ts = strtotime($rawDate);
    if ($ts === false) {
        return '';
    }
    $iso = date('Y-m-d', $ts);
    $year = (int) substr($iso, 0, 4);
    if ($year < 1900 || $year > ((int) date('Y') + 2)) {
        return '';
    }
    return $iso;
}

/**
 * Dynamic patient matching from OCR identity fields.
 * Safely wrapped in try-catch so database exceptions never crash the OCR response.
 */
function vision_ocr_match_patient(mysqli $conn, $firstName, $lastName, $birthdate = '')
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

        if (!$row && $birthdate !== '') {
            return vision_ocr_match_patient($conn, $firstName, $lastName, '');
        }

        if ($row) {
            $row['full_name'] = function_exists('mh_format_patient_name')
                ? mh_format_patient_name($row['first_name'], $row['middle_name'] ?? '', $row['last_name'])
                : trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_name'] ?? ''));
        }

        return $row;
    } catch (Throwable $e) {
        vision_ocr_log_error('matcher', 'Exception', 'Patient match query error: ' . $e->getMessage());
        return null;
    }
}

/* ---------------------------------------------------------------------
 * FORM-SPECIFIC TEXT PARSERS (General Consultation, Maternal, Immunization, Dental)
 * ------------------------------------------------------------------ */

/** Helper: splits full name into first and last name */
function vision_ocr_split_name($fullName)
{
    $fullName = trim(preg_replace('#^(?:Patient(?:\s*Name)?|Name|Pangalan|Child(?:\'s)?\s*Name|Mother(?:\'s)?\s*Name|Father(?:\'s)?\s*Name)\s*[:=]\s*#i', '', $fullName));
    $fullName = trim(preg_replace('#[0-9\(\)\{\}\[\]\*\#]+#', '', $fullName));
    if (strpos($fullName, ',') !== false) {
        $parts = explode(',', $fullName, 2);
        return [
            'last_name'  => trim($parts[0]),
            'first_name' => trim($parts[1]),
        ];
    }
    $words = preg_split('#\s+#', $fullName);
    if (count($words) === 1) {
        return ['first_name' => $words[0], 'last_name' => ''];
    }
    if (count($words) >= 2) {
        $last = array_pop($words);
        return ['first_name' => implode(' ', $words), 'last_name' => $last];
    }
    return ['first_name' => '', 'last_name' => ''];
}

/**
 * Parses raw text from a General Consultation / Checkup document.
 */
function vision_parse_checkup_text($text)
{
    $fields = [
        'first_name'       => '',
        'last_name'        => '',
        'birthdate'        => '',
        'weight'           => '',
        'height'           => '',
        'temp'             => '',
        'bp'               => '',
        'chief_complaints' => '',
        'diagnosis'        => '',
        'prescription'     => '',
        'notes'            => '',
    ];

    if (empty($text)) {
        return $fields;
    }

    // 1. Blood Pressure (e.g. "120/80")
    if (preg_match('#(?:BP|B\.P\.|Blood\s*Pressure)\s*[:=]?\s*(\d{2,3}\s*/\s*\d{2,3})#i', $text, $m)) {
        $fields['bp'] = preg_replace('#\s+#', '', $m[1]);
    } elseif (preg_match('#(?<![\d/.\-])(\d{2,3}\s*/\s*\d{2,3})(?![\d/.\-])\s*(?:mmHg)?#i', $text, $m)) {
        // Bare "120/80" fallback - the lookarounds stop it from matching the "05/14" inside a date like 05/14/1990
        $fields['bp'] = preg_replace('#\s+#', '', $m[1]);
    }

    // 2. Temperature (e.g. "37.2" or "37.2 °C")
    if (preg_match('#(?:Temp(?:erature)?|T)\s*[:=]?\s*([34]\d(?:\.\d)?)\s*(?:°?C)?#i', $text, $m)) {
        $fields['temp'] = $m[1] . ' °C';
    } elseif (preg_match('#\b(3[5-9](?:\.\d)?|4[0-2](?:\.\d)?)\s*(?:°C|deg\s*C)\b#i', $text, $m)) {
        $fields['temp'] = $m[1] . ' °C';
    }

    // 3. Weight (e.g. "65 kg")
    if (preg_match('#(?:Weight|Wt|WT)\s*[:=]?\s*(\d{1,3}(?:\.\d{1,2})?)\s*(kg|kilos|lbs)?#i', $text, $m)) {
        $unit = !empty($m[2]) ? strtolower($m[2]) : 'kg';
        $fields['weight'] = $m[1] . ' ' . $unit;
    }

    // 4. Height (e.g. "160 cm")
    if (preg_match('#(?:Height|Ht|HT)\s*[:=]?\s*(\d{2,3}(?:\.\d{1,2})?)\s*(cm|m)?#i', $text, $m)) {
        $unit = !empty($m[2]) ? strtolower($m[2]) : 'cm';
        $fields['height'] = $m[1] . ' ' . $unit;
    }

    // 5. Patient Name
    if (preg_match('#(?:Patient(?:\s*Name)?|Name|Pangalan)\s*[:=]\s*([^\n\r]+)#i', $text, $m)) {
        $nameInfo = vision_ocr_split_name($m[1]);
        $fields['first_name'] = $nameInfo['first_name'];
        $fields['last_name']  = $nameInfo['last_name'];
    }

    // 6. Birthdate
    if (preg_match('#(?:Birthdate|DOB|Date\s*of\s*Birth|Birthday|Bday|Kapanganakan)\s*[:=]?\s*([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})#i', $text, $m)) {
        $fields['birthdate'] = vision_ocr_normalize_date($m[1]);
    }

    // 7. Chief Complaints
    if (preg_match('#(?:Chief\s*Complaints?|C/?C|Reason\s*for\s*(?:Visit|Consultation)|Reklamo|Dahilan\s*ng\s*Konsulta)\s*[:=]?\s*([^\n\r]+(?:\n(?!(?:Diagnosis|Dx|Assessment|Impression|Rx|Prescription|Plan|Notes|Remarks))[^\n\r]+)*)#i', $text, $m)) {
        $fields['chief_complaints'] = trim($m[1]);
    }

    // 8. Diagnosis
    if (preg_match('#(?:Diagnosis|Dx|Assessment|Impression|Karamdaman)\s*[:=]?\s*([^\n\r]+(?:\n(?!(?:Rx|Prescription|Medications|Plan|Notes|Remarks))[^\n\r]+)*)#i', $text, $m)) {
        $fields['diagnosis'] = trim($m[1]);
    }

    // 9. Prescription
    if (preg_match('#(?:Prescription|Rx|Medications?|Meds|Gamot)\s*[:=]?\s*([^\n\r]+(?:\n(?!(?:Plan|Notes|Remarks|Follow\s*up))[^\n\r]+)*)#i', $text, $m)) {
        $fields['prescription'] = trim($m[1]);
    }

    // 10. Notes / Remarks
    if (preg_match('#(?:Notes?|Remarks?|Plan|Advice|Tagubilin)\s*[:=]?\s*([^\n\r]+(?:\n[^\n\r]+)*)#i', $text, $m)) {
        $fields['notes'] = trim($m[1]);
    }

    return $fields;
}

/**
 * Parses raw text from a Maternal Care / Prenatal card document.
 */
function vision_parse_maternal_text($text)
{
    $fields = [
        'first_name'     => '',
        'last_name'      => '',
        'birthdate'      => '',
        'gravida'        => '',
        'para'           => '',
        'lmp'            => '',
        'edc'            => '',
        'aog'            => '',
        'blood_pressure' => '',
        'weight'         => '',
        'height'         => '',
        'fh_fht'         => '',
        'notes'          => '',
    ];

    if (empty($text)) {
        return $fields;
    }

    // 1. Gravida & Para (e.g. G2P1, G: 2, P: 1)
    if (preg_match('#G\s*(\d+)\s*P\s*(\d+)#i', $text, $m)) {
        $fields['gravida'] = $m[1];
        $fields['para']    = $m[2];
    } else {
        if (preg_match('#(?:Gravida|G)\s*[:=]?\s*(\d+)#i', $text, $m)) {
            $fields['gravida'] = $m[1];
        }
        if (preg_match('#(?:Para|P)\s*[:=]?\s*(\d+)#i', $text, $m)) {
            $fields['para'] = $m[1];
        }
    }

    // 2. LMP (Last Menstrual Period)
    if (preg_match('#LMP\s*[:=]?\s*([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})#i', $text, $m)) {
        $fields['lmp'] = vision_ocr_normalize_date($m[1]);
    }

    // 3. EDC / EDD
    if (preg_match('#(?:EDC|EDD)\s*[:=]?\s*([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})#i', $text, $m)) {
        $fields['edc'] = vision_ocr_normalize_date($m[1]);
    }

    // 4. AOG (Age of Gestation, e.g. "24 wks")
    if (preg_match('#AOG\s*[:=]?\s*([0-9]+(?:\.[0-9]+)?\s*(?:wks|weeks|wk|months|mos)?)#i', $text, $m)) {
        $fields['aog'] = trim($m[1]);
    }

    // 5. Blood Pressure
    if (preg_match('#(?:BP|B\.P\.|Blood\s*Pressure)\s*[:=]?\s*(\d{2,3}\s*/\s*\d{2,3})#i', $text, $m)) {
        $fields['blood_pressure'] = preg_replace('#\s+#', '', $m[1]);
    } elseif (preg_match('#(?<![\d/.\-])(\d{2,3}\s*/\s*\d{2,3})(?![\d/.\-])#', $text, $m)) {
        $fields['blood_pressure'] = preg_replace('#\s+#', '', $m[1]);   // lookarounds: never a slice of a date
    }

    // 6. Weight (numeric only as required by form)
    if (preg_match('#(?:Weight|Wt)\s*[:=]?\s*(\d{1,3}(?:\.\d{1,2})?)#i', $text, $m)) {
        $fields['weight'] = $m[1];
    }

    // 7. Height (numeric only as required by form)
    if (preg_match('#(?:Height|Ht)\s*[:=]?\s*(\d{2,3}(?:\.\d{1,2})?)#i', $text, $m)) {
        $fields['height'] = $m[1];
    }

    // 8. FH / FHT
    if (preg_match('#(?:FH\s*[/&]\s*FHT|Fundal\s*Height|Fetal\s*Heart\s*Tone|FH|FHT)\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['fh_fht'] = trim($m[1]);
    }

    // 9. Mother's Name
    if (preg_match('#(?:Mother(?:\'s)?\s*Name|Patient(?:\s*Name)?|Name|Pangalan(?:\s*ng\s*Ina)?)\s*[:=]\s*([^\n\r]+)#i', $text, $m)) {
        $nameInfo = vision_ocr_split_name($m[1]);
        $fields['first_name'] = $nameInfo['first_name'];
        $fields['last_name']  = $nameInfo['last_name'];
    }

    // 10. Birthdate
    if (preg_match('#(?:Birthdate|DOB|Date\s*of\s*Birth|Birthday|Bday|Kapanganakan)\s*[:=]?\s*([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})#i', $text, $m)) {
        $fields['birthdate'] = vision_ocr_normalize_date($m[1]);
    }

    // 11. Notes
    if (preg_match('#(?:Notes?|Remarks?|Vitamins?|Plan)\s*[:=]?\s*([^\n\r]+(?:\n[^\n\r]+)*)#i', $text, $m)) {
        $fields['notes'] = trim($m[1]);
    }

    return $fields;
}

/**
 * Parses raw text from a Child Immunization Form document.
 */
function vision_parse_immunization_text($text)
{
    $fields = [
        'first_name'             => '',
        'last_name'              => '',
        'birthdate'              => '',
        'family_no'              => '',
        'newborn_screening_date' => '',
        'tt_of_mother_date'      => '',
        'birth_time'             => '',
        'birth_weight'           => '',
        'birth_height'           => '',
        'birth_place'            => '',
        'type_of_delivery'       => '',
        'type_of_breastfeeding'  => '',
        'mother_name'            => '',
        'mother_bday'            => '',
        'mother_age'             => '',
        'father_name'            => '',
        'father_bday'            => '',
        'father_age'             => '',
        'attending_physician'    => '',
        'no_of_children'         => '',
        'contact_no'             => '',
        'bcg_date'               => '',
        'hep_b_24hrs_date'       => '',
        'penta_1_opv_1_date'     => '',
        'penta_2_opv_2_date'     => '',
        'penta_opv_3_date'       => '',
        'pcv_1_date'             => '',
        'pcv_2_date'             => '',
        'pcv_3_date'             => '',
        'others_immunization'    => '',
        'mr_date'                => '',
        'mmr_date'               => '',
    ];

    if (empty($text)) {
        return $fields;
    }

    // 1. Child Name
    if (preg_match('#(?:Child(?:\'s)?\s*Name|Patient(?:\s*Name)?|Name|Pangalan(?:\s*ng\s*Bata)?)\s*[:=]\s*([^\n\r]+)#i', $text, $m)) {
        $nameInfo = vision_ocr_split_name($m[1]);
        $fields['first_name'] = $nameInfo['first_name'];
        $fields['last_name']  = $nameInfo['last_name'];
    }

    // 2. Child Birthdate
    if (preg_match('#(?:Date\s*of\s*Birth|Birthdate|Birthday|Bday|DOB|Kapanganakan)\s*[:=]?\s*([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})#i', $text, $m)) {
        $fields['birthdate'] = vision_ocr_normalize_date($m[1]);
    }

    // 3. Family No
    if (preg_match('#(?:Family\s*No\.?|Fam\s*No\.?)\s*[:=]?\s*([0-9A-Za-z\-_]+)#i', $text, $m)) {
        $fields['family_no'] = trim($m[1]);
    }

    // 4. Birth Time, Weight, Height
    if (preg_match('#(?:Birth\s*Time|Time\s*of\s*Birth)\s*[:=]?\s*([^\n\r,]+)#i', $text, $m)) {
        $fields['birth_time'] = trim($m[1]);
    }
    if (preg_match('#(?:Birth\s*Weight|BW)\s*[:=]?\s*(\d{1,2}(?:\.\d{1,2})?\s*(?:kg|g)?)#i', $text, $m)) {
        $fields['birth_weight'] = trim($m[1]);
    }
    if (preg_match('#(?:Birth\s*Height|Birth\s*Length|BH|BL)\s*[:=]?\s*(\d{2,3}(?:\.\d{1,2})?\s*(?:cm)?)#i', $text, $m)) {
        $fields['birth_height'] = trim($m[1]);
    }

    // 5. Place of birth, delivery, breastfeeding
    if (preg_match('#(?:Place\s*of\s*Birth|Birth\s*Place)\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['birth_place'] = trim($m[1]);
    }
    if (preg_match('#(?:Type\s*of\s*Delivery|Delivery)\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['type_of_delivery'] = trim($m[1]);
    }
    if (preg_match('#(?:Type\s*of\s*Breastfeeding|Breastfeeding)\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['type_of_breastfeeding'] = trim($m[1]);
    }

    // 6. Parents info
    if (preg_match('#(?:Mother(?:\'s)?\s*Name|Pangalan\s*ng\s*Ina)\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['mother_name'] = trim($m[1]);
    }
    if (preg_match('#(?:Mother(?:\'s)?\s*(?:Bday|Birthdate|Birthday))\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['mother_bday'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:Mother(?:\'s)?\s*Age)\s*[:=]?\s*(\d{1,2})#i', $text, $m)) {
        $fields['mother_age'] = trim($m[1]);
    }

    if (preg_match('#(?:Father(?:\'s)?\s*Name|Pangalan\s*ng\s*Ama)\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['father_name'] = trim($m[1]);
    }
    if (preg_match('#(?:Father(?:\'s)?\s*(?:Bday|Birthdate|Birthday))\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['father_bday'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:Father(?:\'s)?\s*Age)\s*[:=]?\s*(\d{1,2})#i', $text, $m)) {
        $fields['father_age'] = trim($m[1]);
    }

    if (preg_match('#(?:Contact\s*(?:No\.?|Number)|Tel|CP)\s*[:=]?\s*([0-9\+\-\s]+)#i', $text, $m)) {
        $fields['contact_no'] = trim($m[1]);
    }

    // 7. Vaccines
    $datePat = '([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})';

    if (preg_match('#BCG\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['bcg_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:Hep\s*B|Hepa\s*B|Hepatitis\s*B)(?:\s*(?:within)?\s*24\s*(?:hrs|hours)?)?\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['hep_b_24hrs_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:Penta(?:valent)?|DPT|OPV)\s*[-_]?\s*1\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['penta_1_opv_1_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:Penta(?:valent)?|DPT|OPV)\s*[-_]?\s*2\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['penta_2_opv_2_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:Penta(?:valent)?|DPT|OPV)\s*[-_]?\s*3\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['penta_opv_3_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#PCV\s*[-_]?\s*1\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['pcv_1_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#PCV\s*[-_]?\s*2\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['pcv_2_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#PCV\s*[-_]?\s*3\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['pcv_3_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:MR|Measles)\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['mr_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#MMR\b\s*[:=]?\s*' . $datePat . '#i', $text, $m)) {
        $fields['mmr_date'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#(?:Others?|Other\s*Vaccines?|IPV)\s*[:=]?\s*([^\n\r]+)#i', $text, $m)) {
        $fields['others_immunization'] = trim($m[1]);
    }

    return $fields;
}

/**
 * Parses raw text from a Dental Record document.
 */
function vision_parse_dental_text($text, array $catalog = [])
{
    $pageType = 'treatment_record';
    if (stripos($text, 'caries') !== false && stripos($text, 'risk') !== false) {
        $pageType = (stripos($text, 'child') !== false || stripos($text, '5 and below') !== false) ? 'caries_child' : 'caries_adult';
    } elseif (stripos($text, 'odontogram') !== false || stripos($text, 'tooth chart') !== false) {
        $pageType = 'odontogram';
    } elseif (stripos($text, 'medical history') !== false || stripos($text, 'clinical findings') !== false) {
        $pageType = 'medical_history';
    }

    $fields = [];
    $odo = [];
    $caries = [];
    $treatments = [];

    // Patient Name
    if (preg_match('#(?:Patient(?:\s*Name)?|Name|Pangalan)\s*[:=]\s*([^\n\r]+)#i', $text, $m)) {
        $nameInfo = vision_ocr_split_name($m[1]);
        if (!empty($nameInfo['first_name'])) {
            $fields['patient.first_name'] = $nameInfo['first_name'];
        }
        if (!empty($nameInfo['last_name'])) {
            $fields['patient.last_name'] = $nameInfo['last_name'];
        }
    }

    // Birthdate & Age
    if (preg_match('#(?:Birthdate|DOB|Date\s*of\s*Birth|Birthday|Bday)\s*[:=]?\s*([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})#i', $text, $m)) {
        $fields['patient.birthdate'] = vision_ocr_normalize_date($m[1]);
    }
    if (preg_match('#\bAge\s*[:=]?\s*(\d{1,2})\b#i', $text, $m)) {
        $fields['patient.age'] = $m[1];
    }
    if (preg_match('#\bSex\s*[:=]?\s*(Male|Female|M|F)\b#i', $text, $m)) {
        $fields['patient.sex'] = (stripos($m[1], 'f') === 0) ? 'Female' : 'Male';
    }

    // Odontogram (Tooth numbers and status/condition)
    // Matches patterns like "Tooth 16: C / M" or "16: c, s"
    if (preg_match_all('#\b([1-8][1-8])\s*[:=]\s*([a-zA-Z/\+]+)#', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tooth = $match[1];
            $code = trim($match[2]);
            $odo[$tooth] = ['c' => $code, 's' => ''];
        }
    }

    // Caries items (A1..A3, B1..B6, C1..C3)
    if (preg_match_all('#\b([ABC]\d{1,2})\s*[:=]?\s*(yes|no)\b#i', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $caries[strtoupper($match[1])] = strtolower($match[2]);
        }
    }

    $cariesDate = '';
    if (preg_match('#(?:Assessment\s*Date|Caries\s*Date|Date)\s*[:=]?\s*([0-9]{1,4}[/\-.][0-9]{1,2}[/\-.][0-9]{1,4})#i', $text, $m)) {
        $cariesDate = vision_ocr_normalize_date($m[1]);
    }

    return [
        'page_type'    => $pageType,
        'fields'       => $fields,
        'odontogram'   => $odo,
        'caries'       => $caries,
        'caries_group' => ($pageType === 'caries_child') ? 'child' : (($pageType === 'caries_adult') ? 'adult' : ''),
        'caries_date'  => $cariesDate,
        'treatments'   => $treatments,
    ];
}

/* =====================================================================
 * AWS TEXTRACT OCR ENGINE
 * ===================================================================*/

/**
 * Resolves the AWS Textract credentials and region.
 *
 * Reads the values LIVE from system_settings every time (never from constants that
 * were frozen at include time - config/vision.php is often loaded before the settings
 * cache is booted, which used to leave the keys empty). Explicit arguments win.
 *
 * @return array{0:string,1:string,2:string} [accessKey, secretKey, region]
 */
function mh_textract_credentials($accessKey = null, $secretKey = null, $region = null)
{
    if (empty($accessKey)) {
        $accessKey = function_exists('mh_ocr_live_setting')
            ? mh_ocr_live_setting('aws_access_key_id', '')
            : (defined('AWS_TEXTRACT_ACCESS_KEY_ID') ? AWS_TEXTRACT_ACCESS_KEY_ID : '');
    }
    if (empty($secretKey)) {
        $secretKey = function_exists('mh_ocr_live_setting')
            ? mh_ocr_live_setting('aws_secret_access_key', '')
            : (defined('AWS_TEXTRACT_SECRET_ACCESS_KEY') ? AWS_TEXTRACT_SECRET_ACCESS_KEY : '');
    }
    if (empty($region)) {
        $region = function_exists('mh_ocr_live_setting')
            ? mh_ocr_live_setting('aws_region', 'ap-southeast-1')
            : (defined('AWS_TEXTRACT_REGION') ? AWS_TEXTRACT_REGION : 'ap-southeast-1');
    }
    $region = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) $region)));
    if ($region === '') { $region = 'ap-southeast-1'; }

    return [trim((string) $accessKey), trim((string) $secretKey), $region];
}

/**
 * True when the AWS SDK for PHP (Aws\Textract\TextractClient) can be used.
 * Looks for Composer's autoloader in the project root (or one level above it).
 */
function mh_textract_sdk_available()
{
    if (class_exists('Aws\\Textract\\TextractClient', false)) {
        return true;
    }
    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            try {
                require_once $autoload;
            } catch (Throwable $e) {
                continue;
            }
            if (class_exists('Aws\\Textract\\TextractClient')) {
                return true;
            }
        }
    }
    return false;
}

/** Turns a Textract error code into a message the staff can act on. */
function mh_textract_friendly_error($code, $message, $httpCode = 0)
{
    $code = (string) $code;
    if (stripos($code, 'InvalidSignature') !== false || stripos($code, 'SignatureDoesNotMatch') !== false) {
        return 'AWS Textract: Invalid signature. Check that the Access Key ID and Secret Access Key in System Settings are correct.';
    }
    if (stripos($code, 'UnrecognizedClient') !== false || stripos($code, 'InvalidClientTokenId') !== false) {
        return 'AWS Textract: The Access Key ID is not recognised. Re-enter the AWS keys in System Settings.';
    }
    if (stripos($code, 'AccessDenied') !== false || (int) $httpCode === 403) {
        return 'AWS Textract: Access denied. Ensure the IAM user has the textract:AnalyzeDocument permission (e.g. AmazonTextractFullAccess).';
    }
    if (stripos($code, 'UnsupportedDocument') !== false) {
        return 'AWS Textract: The document format is not supported. Use JPEG, PNG, or a single-page PDF.';
    }
    if (stripos($code, 'BadDocument') !== false) {
        return 'AWS Textract could not read this document. Retake the photo (upright, flat, well-lit) and try again.';
    }
    if (stripos($code, 'DocumentTooLarge') !== false) {
        return 'AWS Textract: The file is too large (limit is 10 MB). Use a smaller photo.';
    }
    if (stripos($code, 'Throttling') !== false || stripos($code, 'ProvisionedThroughput') !== false || stripos($code, 'LimitExceeded') !== false) {
        return 'AWS Textract is busy right now. Please wait a few seconds and scan again.';
    }
    if (stripos($code, 'SubscriptionRequired') !== false || stripos($code, 'OptInRequired') !== false) {
        return 'AWS Textract is not enabled for this AWS account yet. Complete the account activation / billing setup in AWS.';
    }
    return $message !== '' ? ('AWS Textract: ' . $message) : ('AWS Textract error (HTTP ' . (int) $httpCode . ').');
}

/**
 * Splits a Textract Blocks array into plain LINE text and a FORMS key => value map.
 * Shared by the SDK path and the raw SigV4 path so both return the same structure.
 *
 * @return array{text:string, kv:array<string,string>}
 */
function mh_textract_parse_blocks(array $blocks)
{
    $lines    = [];
    $kvBlocks = [];

    foreach ($blocks as $block) {
        $type = $block['BlockType'] ?? '';
        if ($type === 'LINE') {
            $lines[] = (string) ($block['Text'] ?? '');
        } elseif ($type === 'KEY_VALUE_SET' && !empty($block['Id'])) {
            $kvBlocks[$block['Id']] = $block;
        }
    }

    return [
        'text' => trim(implode("\n", $lines)),
        'kv'   => mh_textract_extract_kv($kvBlocks, $blocks),
    ];
}

/**
 * AnalyzeDocument through the AWS SDK for PHP (Aws\Textract\TextractClient).
 * Pass the RAW image bytes - the SDK base64-encodes the blob itself.
 *
 * @return array{ok:bool, data?:array, error?:string, code?:string, http_code?:int}
 */
function mh_textract_call_sdk($bytes, $accessKey, $secretKey, $region)
{
    try {
        $client = new \Aws\Textract\TextractClient([
            'version'     => '2018-06-27',
            'region'      => $region,
            'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
            'http'        => ['timeout' => 90, 'connect_timeout' => 20],
            'retries'     => 2,
        ]);

        $result = $client->analyzeDocument([
            'Document'     => ['Bytes' => $bytes],
            'FeatureTypes' => ['FORMS', 'TABLES'],
        ]);

        return ['ok' => true, 'data' => $result->toArray()];
    } catch (\Aws\Exception\AwsException $e) {
        $code = (string) $e->getAwsErrorCode();
        $http = (int) $e->getStatusCode();
        $msg  = (string) ($e->getAwsErrorMessage() ?: $e->getMessage());
        return ['ok' => false, 'error' => mh_textract_friendly_error($code, $msg, $http), 'code' => $code ?: 'AwsException', 'http_code' => $http, 'raw_message' => $msg];
    } catch (\Aws\Exception\CredentialsException $e) {
        return ['ok' => false, 'error' => 'AWS Textract: The saved AWS credentials could not be used. Re-enter them in System Settings.', 'code' => 'Credentials', 'http_code' => 0, 'raw_message' => $e->getMessage()];
    }
}

/**
 * AnalyzeDocument through a hand-signed AWS Signature V4 cURL request.
 * Used automatically when the AWS SDK (vendor/autoload.php) is not installed.
 *
 * @return array{ok:bool, data?:array, error?:string, code?:string, http_code?:int}
 */
function mh_textract_call_sigv4($bytes, $accessKey, $secretKey, $region)
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'The cURL PHP extension is not enabled on this server. Contact the administrator to enable php_curl.', 'code' => 'cURL'];
    }

    $payload = json_encode([
        'Document'     => ['Bytes' => base64_encode($bytes)],
        'FeatureTypes' => ['FORMS', 'TABLES'],
    ]);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Failed to encode the document payload for AWS Textract (JSON error).', 'code' => 'JSON'];
    }

    $service   = 'textract';
    $host      = "textract.{$region}.amazonaws.com";
    $amzTarget = 'Textract.AnalyzeDocument';
    $dateTime  = gmdate('Ymd\THis\Z');
    $dateOnly  = gmdate('Ymd');

    $canonicalHeaders = "content-type:application/x-amz-json-1.1\n"
                      . "host:{$host}\n"
                      . "x-amz-date:{$dateTime}\n"
                      . "x-amz-target:{$amzTarget}\n";
    $signedHeaders = 'content-type;host;x-amz-date;x-amz-target';

    $canonicalRequest = implode("\n", ['POST', '/', '', $canonicalHeaders, $signedHeaders, hash('sha256', $payload)]);
    $credentialScope  = "{$dateOnly}/{$region}/{$service}/aws4_request";
    $stringToSign     = "AWS4-HMAC-SHA256\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $signingKey = hash_hmac('sha256', 'aws4_request',
                    hash_hmac('sha256', $service,
                      hash_hmac('sha256', $region,
                        hash_hmac('sha256', $dateOnly, 'AWS4' . $secretKey, true),
                      true),
                    true),
                  true);
    $signature  = hash_hmac('sha256', $stringToSign, $signingKey);
    $authHeader = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $ch = curl_init("https://{$host}/");
    if (!$ch) {
        return ['ok' => false, 'error' => 'Failed to initialize cURL for AWS Textract.', 'code' => 'cURL'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-amz-json-1.1',
            'Content-Length: ' . strlen($payload),
            'Host: ' . $host,
            'X-Amz-Date: ' . $dateTime,
            'X-Amz-Target: ' . $amzTarget,
            'Authorization: ' . $authHeader,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = (string) curl_error($ch);
    $curlNo   = (int) curl_errno($ch);
    curl_close($ch);

    if ($curlNo !== 0) {
        return ['ok' => false, 'error' => 'Network error connecting to AWS Textract: ' . ($curlErr ?: ('cURL Error #' . $curlNo)), 'code' => 'cURL', 'http_code' => $httpCode, 'curl_no' => $curlNo];
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'AWS Textract returned an invalid response (HTTP ' . $httpCode . '). Check system logs.', 'code' => 'JSON', 'http_code' => $httpCode, 'raw_message' => substr(trim((string) $response), 0, 300)];
    }

    if ($httpCode !== 200) {
        $type = (string) ($data['__type'] ?? ($data['code'] ?? 'UnknownError'));
        $msg  = (string) ($data['Message'] ?? ($data['message'] ?? ''));
        return ['ok' => false, 'error' => mh_textract_friendly_error($type, $msg, $httpCode), 'code' => $type, 'http_code' => $httpCode, 'raw_message' => $msg];
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * Runs a scanned document through Amazon Textract (AnalyzeDocument: FORMS + TABLES).
 *
 * Uses the AWS SDK for PHP (Aws\Textract\TextractClient) when vendor/autoload.php is
 * present, otherwise an equivalent hand-signed SigV4 request - either way the result is
 * Textract's, and this function NEVER falls back to Google Cloud Vision or Gemini.
 *
 * Credentials and region are read live from System Settings (aws_access_key_id,
 * aws_secret_access_key, aws_region - default ap-southeast-1) unless passed explicitly.
 *
 * @return array{success:bool, text:string, kv:array<string,string>, sdk?:bool, error?:string}
 */
function call_aws_textract_ocr($imagePath, $accessKey = null, $secretKey = null, $region = null)
{
    try {
        require_once __DIR__ . '/../config/vision.php';

        list($accessKey, $secretKey, $region) = mh_textract_credentials($accessKey, $secretKey, $region);

        if ($accessKey === '' || $secretKey === '') {
            $err = 'AWS Textract credentials are not configured. Open System Settings > Scanner (OCR) and enter your AWS Access Key ID and Secret Access Key.';
            vision_ocr_log_error('aws_textract', 'Config', $err);
            return ['success' => false, 'text' => '', 'kv' => [], 'error' => $err];
        }

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            $err = 'The document image file could not be read or does not exist on the server.';
            vision_ocr_log_error('aws_textract', 'File', $err, ['path' => $imagePath]);
            return ['success' => false, 'text' => '', 'kv' => [], 'error' => $err];
        }

        $mimeType = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mimeType = (string) finfo_file($fi, $imagePath); finfo_close($fi); }
        }
        if ($mimeType === '' && function_exists('mime_content_type')) {
            $mimeType = (string) mime_content_type($imagePath);
        }
        $aliases = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'image/x-png' => 'image/png'];
        if (isset($aliases[$mimeType])) { $mimeType = $aliases[$mimeType]; }

        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            $err = 'AWS Textract supports JPEG, PNG, and single-page PDF only. Received: ' . ($mimeType ?: 'unknown') . '. Please upload a JPG or PNG.';
            vision_ocr_log_error('aws_textract', 'File', $err);
            return ['success' => false, 'text' => '', 'kv' => [], 'error' => $err];
        }

        $size = (int) @filesize($imagePath);
        if ($size > 10 * 1024 * 1024) {
            $err = 'AWS Textract accepts files up to 10 MB. This file is ' . round($size / 1048576, 1) . ' MB - please use a smaller photo.';
            vision_ocr_log_error('aws_textract', 'File', $err);
            return ['success' => false, 'text' => '', 'kv' => [], 'error' => $err];
        }

        $bytes = @file_get_contents($imagePath);
        if ($bytes === false || $bytes === '') {
            $err = 'The uploaded document is empty or could not be loaded into memory.';
            vision_ocr_log_error('aws_textract', 'File', $err);
            return ['success' => false, 'text' => '', 'kv' => [], 'error' => $err];
        }

        $useSdk = mh_textract_sdk_available();
        $call   = $useSdk
            ? mh_textract_call_sdk($bytes, $accessKey, $secretKey, $region)
            : mh_textract_call_sigv4($bytes, $accessKey, $secretKey, $region);
        unset($bytes);

        if (empty($call['ok'])) {
            $ctx = ['via' => $useSdk ? 'sdk' : 'sigv4', 'region' => $region, 'type' => $call['code'] ?? ''];
            if (!empty($call['http_code'])) { $ctx['http_code'] = $call['http_code']; }
            if (!empty($call['curl_no']))   { $ctx['curl_no']   = $call['curl_no']; }
            vision_ocr_log_error('aws_textract', 'API', (string) ($call['raw_message'] ?? $call['error'] ?? 'Textract call failed'), $ctx);
            return ['success' => false, 'text' => '', 'kv' => [], 'error' => (string) ($call['error'] ?? 'AWS Textract request failed.')];
        }

        $parsed = mh_textract_parse_blocks($call['data']['Blocks'] ?? []);

        return [
            'success' => true,
            'text'    => $parsed['text'],
            'kv'      => $parsed['kv'],
            'sdk'     => $useSdk,
        ];
    } catch (Throwable $e) {
        vision_ocr_log_error('aws_textract', 'Exception', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return [
            'success' => false,
            'text'    => '',
            'kv'      => [],
            'error'   => 'An error occurred during AWS Textract OCR processing: ' . $e->getMessage(),
        ];
    }
}

/**
 * Builds a flat key => value map from Textract KEY_VALUE_SET blocks.
 * Keys and values are built by walking the child WORD blocks.
 *
 * @param array $kvBlocks  Filtered blocks of type KEY_VALUE_SET keyed by Id.
 * @param array $allBlocks All blocks from the response (for child lookups).
 * @return array<string,string>
 */
function mh_textract_extract_kv(array $kvBlocks, array $allBlocks)
{
    // Build a lookup of all blocks by their Id
    $byId = [];
    foreach ($allBlocks as $b) {
        if (!empty($b['Id'])) {
            $byId[$b['Id']] = $b;
        }
    }

    /**
     * Walks Relationships of type CHILD to collect all WORD text under a block.
     */
    $getText = function (array $block) use (&$byId): string {
        $words = [];
        foreach ($block['Relationships'] ?? [] as $rel) {
            if (($rel['Type'] ?? '') !== 'CHILD') { continue; }
            foreach ($rel['Ids'] ?? [] as $childId) {
                $child = $byId[$childId] ?? null;
                if ($child && ($child['BlockType'] ?? '') === 'WORD') {
                    $words[] = (string) ($child['Text'] ?? '');
                }
                if ($child && ($child['BlockType'] ?? '') === 'SELECTION_ELEMENT') {
                    if (($child['SelectionStatus'] ?? '') === 'SELECTED') {
                        $words[] = 'Yes';
                    }
                }
            }
        }
        return trim(implode(' ', $words));
    };

    $kv = [];

    foreach ($kvBlocks as $block) {
        // Only process KEY blocks
        if (($block['EntityTypes'][0] ?? '') !== 'KEY') { continue; }

        $keyText = $getText($block);
        if ($keyText === '') { continue; }

        // Find the VALUE block linked via VALUE relationship
        $valueText = '';
        foreach ($block['Relationships'] ?? [] as $rel) {
            if (($rel['Type'] ?? '') !== 'VALUE') { continue; }
            foreach ($rel['Ids'] ?? [] as $valId) {
                $valBlock = $kvBlocks[$valId] ?? ($byId[$valId] ?? null);
                if ($valBlock) {
                    $valueText = $getText($valBlock);
                }
            }
        }

        // Normalize the key: strip trailing punctuation, lowercase
        $normalizedKey = rtrim(trim($keyText), ':');
        $kv[$normalizedKey] = $valueText;
    }

    return $kv;
}

/**
 * Maps Textract key-value pairs and raw LINE text to our standard
 * patient field structure used by vision_parse_checkup_text() and
 * sibling parsers.
 *
 * The KV pairs take precedence over regex matches from the raw text,
 * giving more reliable extraction on form-structured documents.
 *
 * @param array  $kv     Key-value pairs from mh_textract_extract_kv().
 * @param string $text   Raw LINE text joined by newlines (for regex fallback).
 * @param string $formType  'checkup' | 'maternal' | 'immunization' | 'dental'
 * @return array  Standard parsed fields array.
 */
function mh_textract_map_fields(array $kv, $text, $formType = 'checkup', array $catalog = [])
{
    // Values from the Groq engine are already structured + validated and are AUTHORITATIVE: they replace
    // whatever the regex parsers guessed from the raw text for the same inputs (that guessing is what put
    // birthdays into blood pressure and whole pages into chief complaints).
    $groq = (($kv['__source'] ?? '') === 'groq');
    unset($kv['__source']);

    // Dental uses a nested structure ('fields' keyed 'patient.*', odontogram, caries...), so it is
    // handled on its own - the flat first_name/last_name keys below do not exist there.
    if ($formType === 'dental') {
        $parsed = vision_parse_dental_text($text, $catalog);
        $kvLowerD = [];
        foreach ($kv as $k => $v) { $kvLowerD[strtolower(trim($k))] = $v; }
        $pick = function (array $candidates) use ($kvLowerD): string {
            foreach ($candidates as $c) {
                $c = strtolower(trim($c));
                if (isset($kvLowerD[$c]) && $kvLowerD[$c] !== '') { return $kvLowerD[$c]; }
            }
            return '';
        };
        if (empty($parsed['fields']['patient.first_name']) || empty($parsed['fields']['patient.last_name'])) {
            $rawName = $pick(['Patient Name', 'Name', 'Pangalan', "Patient's Name"]);
            if ($rawName !== '') {
                $ni = vision_ocr_split_name($rawName);
                if ($ni['first_name'] !== '') { $parsed['fields']['patient.first_name'] = $ni['first_name']; }
                if ($ni['last_name']  !== '') { $parsed['fields']['patient.last_name']  = $ni['last_name'];  }
            }
        }
        if (empty($parsed['fields']['patient.birthdate'])) {
            $rawDate = $pick(['Birthdate', 'Date of Birth', 'Birthday', 'DOB', 'Bday']);
            if ($rawDate !== '') {
                $iso = vision_ocr_normalize_date($rawDate);
                if ($iso !== '') { $parsed['fields']['patient.birthdate'] = $iso; }
            }
        }
        if ($groq) {
            // Groq's separate name/date keys override the regex guess for the patient block
            foreach (['patient.first_name' => 'First Name', 'patient.last_name' => 'Last Name',
                      'patient.middle_name' => 'Middle Name', 'patient.birthdate' => 'Birthdate'] as $dest => $label) {
                $val = $pick([$label]);
                if ($val !== '') { $parsed['fields'][$dest] = $val; }
            }
        }
        return $parsed;
    }

    // 1. Parse via existing regex parsers as the baseline
    switch ($formType) {
        case 'maternal':      $fields = vision_parse_maternal_text($text);      break;
        case 'immunization':  $fields = vision_parse_immunization_text($text);  break;
        case 'dental':        $fields = vision_parse_dental_text($text);        break;
        default:              $fields = vision_parse_checkup_text($text);       break;
    }

    // 2. Overlay with KV values where Textract found explicit form fields.
    //    KV keys from Textract are compared case-insensitively.
    $kvLower = [];
    foreach ($kv as $k => $v) {
        $kvLower[strtolower(trim($k))] = $v;
    }

    // Helper: looks up a list of possible KV keys and returns the first match
    $kvGet = function (array $candidates) use ($kvLower): string {
        foreach ($candidates as $c) {
            $c = strtolower(trim($c));
            if (isset($kvLower[$c]) && $kvLower[$c] !== '') {
                return $kvLower[$c];
            }
        }
        return '';
    };

    // Groq: clear every input Groq owns so a regex guess can't survive where Groq read a blank.
    // (Fields Groq never returns, e.g. the immunization vaccine dates, keep their regex values.)
    if ($groq) {
        $owned = ['first_name', 'last_name', 'birthdate', 'weight', 'height', 'temp', 'bp', 'blood_pressure',
                  'chief_complaints', 'diagnosis', 'prescription', 'notes', 'gravida', 'para', 'lmp', 'edc',
                  'aog', 'fh_fht', 'family_no', 'mother_name', 'father_name', 'contact_no', 'birth_place',
                  'type_of_delivery', 'attending_physician'];
        foreach ($owned as $k) {
            if (array_key_exists($k, $fields)) { $fields[$k] = ''; }
        }
    }

    // ---- Shared fields (all form types) -----
    // Explicit First/Last Name pairs (e.g. from the Groq engine) beat re-splitting a full name,
    // which would misplace multi-word given names such as "Maria Cristina".
    $kvFirst = $kvGet(['First Name', 'Given Name']);
    $kvLast  = $kvGet(['Last Name', 'Surname', 'Family Name']);
    if ($groq) {
        // Groq returns separate keys: take each one as-is, never re-split a full name
        if ($kvFirst !== '') { $fields['first_name'] = $kvFirst; }
        if ($kvLast !== '')  { $fields['last_name']  = $kvLast; }
        $fields['middle_name'] = $kvGet(['Middle Name']);
        if (in_array($formType, ['checkup', 'maternal'], true)) {
            $vd = $kvGet(['Consultation Date']);
            if ($vd !== '') { $fields['visit_date'] = $vd; }   // -> consultations / maternal_records.visit_date
        }
    } elseif ($kvFirst !== '' && $kvLast !== '') {
        $fields['first_name'] = $kvFirst;
        $fields['last_name']  = $kvLast;
    }
    if ($fields['first_name'] === '' || $fields['last_name'] === '') {
        $rawName = $kvGet(['Patient Name', 'Name', 'Pangalan', "Patient's Name", 'Child Name', "Child's Name", 'Mother Name', "Mother's Name"]);
        if ($rawName !== '') {
            $nameInfo = vision_ocr_split_name($rawName);
            if ($nameInfo['first_name'] !== '') { $fields['first_name'] = $nameInfo['first_name']; }
            if ($nameInfo['last_name']  !== '') { $fields['last_name']  = $nameInfo['last_name'];  }
        }
    }

    if (isset($fields['birthdate']) && $fields['birthdate'] === '') {
        $rawDate = $kvGet(['Birthdate', 'Date of Birth', 'Birthday', 'DOB', 'Bday']);
        if ($rawDate !== '') { $fields['birthdate'] = vision_ocr_normalize_date($rawDate); }
    }

    // ---- Checkup-specific fields -----
    if ($formType === 'checkup') {
        if ($fields['bp'] === '')               { $fields['bp']               = $kvGet(['BP', 'Blood Pressure', 'B.P.']); }
        if ($fields['temp'] === '')             { $fields['temp']             = $kvGet(['Temp', 'Temperature', 'T']); }
        if ($fields['weight'] === '')           { $fields['weight']           = $kvGet(['Weight', 'Wt']); }
        if ($fields['height'] === '')           { $fields['height']           = $kvGet(['Height', 'Ht']); }
        if ($fields['chief_complaints'] === '') { $fields['chief_complaints'] = $kvGet(['Chief Complaints', 'Chief Complaint', 'CC', 'Reklamo', 'Reason for Visit']); }
        if ($fields['diagnosis'] === '')        { $fields['diagnosis']        = $kvGet(['Diagnosis', 'Dx', 'Assessment', 'Impression']); }
        if ($fields['prescription'] === '')     { $fields['prescription']     = $kvGet(['Prescription', 'Rx', 'Medications', 'Meds']); }
        if ($fields['notes'] === '')            { $fields['notes']            = $kvGet(['Notes', 'Remarks', 'Plan', 'Advice']); }
    }

    // ---- Maternal-specific fields -----
    if ($formType === 'maternal') {
        if ($fields['gravida'] === '')        { $fields['gravida']        = $kvGet(['Gravida', 'G']); }
        if ($fields['para'] === '')           { $fields['para']           = $kvGet(['Para', 'P']); }
        if ($fields['lmp'] === '')            { $r = $kvGet(['LMP', 'Last Menstrual Period']); if ($r !== '') { $fields['lmp'] = vision_ocr_normalize_date($r); } }
        if ($fields['edc'] === '')            { $r = $kvGet(['EDC', 'EDD']); if ($r !== '') { $fields['edc'] = vision_ocr_normalize_date($r); } }
        if ($fields['aog'] === '')            { $fields['aog']            = $kvGet(['AOG', 'Age of Gestation']); }
        if ($fields['blood_pressure'] === '') { $fields['blood_pressure'] = $kvGet(['BP', 'Blood Pressure']); }
        if ($fields['weight'] === '')         { $fields['weight']         = $kvGet(['Weight', 'Wt']); }
        if ($fields['height'] === '')         { $fields['height']         = $kvGet(['Height', 'Ht']); }
        if ($fields['fh_fht'] === '')         { $fields['fh_fht']         = $kvGet(['FH / FHT', 'FH', 'FHT', 'Fundal Height', 'Fetal Heart Tone']); }
        if ($fields['notes'] === '')          { $fields['notes']          = $kvGet(['Notes', 'Remarks', 'Vitamins', 'Plan']); }
    }

    // ---- Immunization-specific fields -----
    if ($formType === 'immunization') {
        if ($fields['family_no'] === '')       { $fields['family_no']       = $kvGet(['Family No', 'Fam No']); }
        if ($fields['mother_name'] === '')     { $fields['mother_name']     = $kvGet(["Mother's Name", 'Mother Name']); }
        if ($fields['father_name'] === '')     { $fields['father_name']     = $kvGet(["Father's Name", 'Father Name']); }
        if ($fields['contact_no'] === '')      { $fields['contact_no']      = $kvGet(['Contact No', 'Contact Number', 'Tel', 'CP']); }
        if ($fields['birth_place'] === '')     { $fields['birth_place']     = $kvGet(['Place of Birth', 'Birth Place']); }
        if ($fields['type_of_delivery'] === '') { $fields['type_of_delivery'] = $kvGet(['Type of Delivery', 'Delivery']); }
        if ($fields['attending_physician'] === '') { $fields['attending_physician'] = $kvGet(['Attending Physician', 'Physician', 'Doctor']); }
    }

    // The maternal form's weight/height inputs take numbers only ("65", not "65 kg")
    if ($groq && $formType === 'maternal') {
        foreach (['weight', 'height'] as $k) {
            $fields[$k] = (isset($fields[$k]) && preg_match('#\d+(?:\.\d+)?#', $fields[$k], $nm)) ? $nm[0] : '';
        }
    }

    return $fields;
}

/* =====================================================================
 * OCR ENGINE ROUTER
 * ===================================================================*/

/**
 * Calls the Gemini AI API (generateContent) to perform plain-text OCR
 * on a document image, without a responseSchema — just asking for the
 * raw transcription. This mirrors call_google_vision_ocr()'s contract
 * so callers can be switched transparently.
 *
 * All operations are wrapped in try-catch; cURL errors and invalid JSON
 * responses are logged explicitly to system logs, and a clean fallback
 * error is returned.
 *
 * @param string      $imagePath  Absolute path to the image/document file.
 * @param string|null $apiKey     Optional override for the Gemini API key.
 * @return array{success:bool, text:string, error?:string}
 */
function call_gemini_vision_ocr($imagePath, $apiKey = null)
{
    try {
        require_once __DIR__ . '/../config/vision.php';
        require_once __DIR__ . '/ocr/GeminiOcrClient.php';

        if (empty($apiKey)) {
            $apiKey = function_exists('mh_ocr_live_setting')
                ? mh_ocr_live_setting('gemini_api_key', '')
                : (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
            if ($apiKey === '' && defined('GEMINI_API_KEY')) {
                $apiKey = GEMINI_API_KEY;
            }
        }

        if (trim((string) $apiKey) === '') {
            $err = 'The Gemini API key has not been configured yet. Open System Settings > Scanner (OCR) and paste a Gemini key (starts with "AIzaSy").';
            vision_ocr_log_error('gemini', 'Config', $err);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            $err = 'The document image file could not be read or does not exist on the server.';
            vision_ocr_log_error('gemini', 'File', $err, ['path' => $imagePath]);
            return [
                'success' => false,
                'text'    => '',
                'error'   => $err,
            ];
        }

        // Detect MIME type
        $mimeType = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mimeType = (string) finfo_file($fi, $imagePath); finfo_close($fi); }
        }
        if ($mimeType === '' && function_exists('mime_content_type')) {
            $mimeType = (string) mime_content_type($imagePath);
        }
        $aliases = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'image/x-png' => 'image/png'];
        if (isset($aliases[$mimeType])) { $mimeType = $aliases[$mimeType]; }
        if ($mimeType === '') { $mimeType = 'image/jpeg'; }

        $fileContents = @file_get_contents($imagePath);
        if ($fileContents === false || strlen($fileContents) === 0) {
            $err = 'The uploaded document is empty or could not be loaded into memory.';
            vision_ocr_log_error('gemini', 'File', $err);
            return ['success' => false, 'text' => '', 'error' => $err];
        }

        $imageData = base64_encode($fileContents);
        unset($fileContents);

        $promptText = 'You are an OCR engine. Transcribe ALL visible text from this document exactly as printed, preserving field labels, values, dates, and table rows. Return plain text only — no commentary, no markdown, no explanations. If a field is blank, leave it blank.';

        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    ['text' => $promptText],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $imageData]],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0,
                'responseMimeType' => 'text/plain',
            ],
        ];
        unset($imageData);

        $payloadJson = json_encode($payload);
        unset($payload);

        if ($payloadJson === false) {
            $err = 'Failed to prepare the document payload (JSON encoding error).';
            vision_ocr_log_error('gemini', 'JSON', $err, ['json_error' => json_last_error_msg()]);
            return ['success' => false, 'text' => '', 'error' => $err];
        }

        if (!function_exists('curl_init')) {
            $err = 'The cURL PHP extension is not enabled on this server. Contact the administrator to enable php_curl.';
            vision_ocr_log_error('gemini', 'cURL', $err);
            return ['success' => false, 'text' => '', 'error' => $err];
        }

        // Try each model in the fallback chain
        $models   = function_exists('gemini_ocr_model_candidates') ? gemini_ocr_model_candidates() : ['gemini-2.0-flash'];
        $version  = defined('GEMINI_API_VERSION') ? GEMINI_API_VERSION : 'v1beta';
        $timeout  = defined('GEMINI_HTTP_TIMEOUT') ? (int) GEMINI_HTTP_TIMEOUT : 60;
        $maxTries = defined('GEMINI_MAX_ATTEMPTS') ? max(1, (int) GEMINI_MAX_ATTEMPTS) : 3;

        $lastError = 'Gemini OCR service could not be reached.';

        foreach ($models as $model) {
            $url = 'https://generativelanguage.googleapis.com/' . $version . '/models/'
                 . rawurlencode($model) . ':generateContent';

            for ($attempt = 0; $attempt < $maxTries; $attempt++) {
                $ch = curl_init($url);
                if (!$ch) {
                    vision_ocr_log_error('gemini', 'cURL', 'Failed to initialize cURL', ['model' => $model]);
                    return ['success' => false, 'text' => '', 'error' => 'Failed to initialize cURL for Gemini AI.'];
                }

                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payloadJson,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'x-goog-api-key: ' . $apiKey,
                    ],
                ]);

                $body     = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr  = (string) curl_error($ch);
                $curlNo   = (int) curl_errno($ch);
                curl_close($ch);

                if ($curlNo !== 0) {
                    $lastError = 'Network error reaching Gemini: ' . ($curlErr ?: ('Code #' . $curlNo));
                    vision_ocr_log_error('gemini', 'cURL', $lastError, ['curl_no' => $curlNo, 'model' => $model, 'attempt' => $attempt + 1]);
                    if ($attempt < $maxTries - 1) { usleep(700000); continue; }
                    break;
                }

                $decoded = ($body !== false && $body !== '') ? json_decode($body, true) : null;

                if ($httpCode === 200 && is_array($decoded)) {
                    $rawText = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
                    // Strip any accidental markdown fences
                    $rawText = trim($rawText);
                    if (strpos($rawText, '```') === 0) {
                        $rawText = preg_replace('/^```[^\n]*\n?/i', '', $rawText);
                        $rawText = preg_replace('/\n?```$/', '', $rawText);
                    }
                    return ['success' => true, 'text' => trim($rawText)];
                }

                // If non-JSON received, log explicitly
                if (!is_array($decoded)) {
                    $snippet = substr(trim((string) $body), 0, 300);
                    vision_ocr_log_error('gemini', 'JSON', 'Invalid JSON from Gemini API', [
                        'http_code'        => $httpCode,
                        'model'            => $model,
                        'response_snippet' => $snippet,
                    ]);
                }

                if ($httpCode === 404) {
                    vision_ocr_log_error('gemini', 'API', "Gemini model '{$model}' returned 404 (retired); attempting fallback model.");
                    break; // Model gone → try next in chain
                }

                if (in_array($httpCode, [400, 401, 403, 413], true)) {
                    $msg = (string) ($decoded['error']['message'] ?? ('Gemini API error (HTTP ' . $httpCode . ').'));
                    vision_ocr_log_error('gemini', 'API', $msg, ['http_code' => $httpCode, 'model' => $model]);
                    return ['success' => false, 'text' => '', 'error' => $msg];
                }

                $lastError = 'Gemini returned HTTP ' . $httpCode . '.';
                vision_ocr_log_error('gemini', 'HTTP', $lastError, ['model' => $model, 'attempt' => $attempt + 1]);

                if ($attempt < $maxTries - 1) {
                    $delay = function_exists('gemini_ocr_backoff_delay_ms') ? gemini_ocr_backoff_delay_ms($attempt) : 700000;
                    usleep(is_int($delay) && $delay > 0 ? $delay * 1000 : 700000);
                }
            }
        }

        return ['success' => false, 'text' => '', 'error' => $lastError];
    } catch (Throwable $e) {
        vision_ocr_log_error('gemini', 'Exception', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return [
            'success' => false,
            'text'    => '',
            'error'   => 'An error occurred during Gemini AI OCR processing: ' . $e->getMessage(),
        ];
    }
}

/* ---------------------------------------------------------------------
 * Groq JSON -> clean, validated values
 * ------------------------------------------------------------------ */

/** Person-name cleaner: drops digits/symbols, collapses spaces, Title-Cases ALL-CAPS handwriting. */
function mh_groq_clean_name($v)
{
    $v = trim((string) $v);
    $v = preg_replace("/[^\\p{L}\\s'\\-\\.]/u", '', $v);
    $v = trim(preg_replace('/\\s+/u', ' ', $v));
    if (mb_strlen($v, 'UTF-8') > 1 && $v === mb_strtoupper($v, 'UTF-8') && !preg_match('/^\\p{Lu}\\.?$/u', $v)) {
        $v = mb_convert_case($v, MB_CASE_TITLE, 'UTF-8');
    }
    return $v;
}

/** Date cleaner -> yyyy-mm-dd or ''. Future dates are rejected unless $allowFuture (e.g. EDC). */
function mh_groq_clean_date($v, $allowFuture = false)
{
    $iso = vision_ocr_normalize_date(trim((string) $v));
    if ($iso === '') { return ''; }
    if (!$allowFuture && $iso > date('Y-m-d')) { return ''; }
    return $iso;
}

/** Blood pressure "120/80" only. A date such as 05/14/1990 can never pass (the lookarounds reject it). */
function mh_groq_clean_bp($v)
{
    if (!preg_match('#(?<![\\d/.\\-])(\\d{2,3})\\s*/\\s*(\\d{2,3})(?![\\d/.\\-])#', (string) $v, $m)) { return ''; }
    $sys = (int) $m[1];
    $dia = (int) $m[2];
    if ($sys < 60 || $sys > 260 || $dia < 30 || $dia > 160 || $dia >= $sys) { return ''; }
    return $sys . '/' . $dia;
}

/** Trims a float for display: 36.80 -> "36.8", 65.0 -> "65". */
function mh_groq_num($n)
{
    return rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');
}

/** Temperature in Celsius, formatted like the regex parser ("36.8 °C"); Fahrenheit is converted. */
function mh_groq_clean_temp($v)
{
    if (!preg_match('#(\\d{2,3}(?:\\.\\d+)?)\\s*(?:°|º)?\\s*([CF])?#iu', (string) $v, $m)) { return ''; }
    $t = (float) $m[1];
    $unit = strtoupper($m[2] ?? '');
    if ($unit === 'F' || ($unit === '' && $t >= 90)) { $t = ($t - 32) * 5 / 9; }
    if ($t < 30 || $t > 45) { return ''; }
    return mh_groq_num($t) . ' °C';
}

/** Weight as "65 kg" (lbs converted). */
function mh_groq_clean_weight($v)
{
    if (!preg_match('#(\\d{1,3}(?:\\.\\d{1,2})?)\\s*(kgs?|kilos?|lbs?|pounds?)?#i', (string) $v, $m)) { return ''; }
    $w = (float) $m[1];
    if (isset($m[2]) && stripos($m[2], 'l') === 0 && stripos($m[2], 'k') !== 0) { $w *= 0.45359237; }
    if ($w < 1 || $w > 300) { return ''; }
    return mh_groq_num($w) . ' kg';
}

/** Height as "160 cm" (metres, feet/inches converted). */
function mh_groq_clean_height($v)
{
    $v = (string) $v;
    if (preg_match("#(\\d)\\s*(?:'|ft|feet)\\s*(\\d{1,2})?#i", $v, $m)) {
        $h = ((int) $m[1] * 12 + (int) ($m[2] ?? 0)) * 2.54;
    } elseif (preg_match('#(\\d{1,3}(?:\\.\\d{1,2})?)\\s*(cm|m)?\\b#i', $v, $m)) {
        $h = (float) $m[1];
        $unit = strtolower($m[2] ?? '');
        if ($unit === 'm' || ($unit === '' && $h < 3)) { $h *= 100; }
    } else {
        return '';
    }
    if ($h < 30 || $h > 250) { return ''; }
    return mh_groq_num($h) . ' cm';
}

/**
 * Free-text cleaner for chief complaints / diagnosis / prescription / notes.
 * Strips an echoed leading label, cuts off anything that runs into another form label
 * (so one field can never swallow the rest of the page), and rejects a value that is
 * just the whole transcription or only numbers/dates.
 */
function mh_groq_clean_text($v, $rawText = '', $ownLabel = '')
{
    $v = trim(str_replace("\r", '', (string) $v));
    if ($v === '') { return ''; }
    $labels = 'Patient\s*Name|First\s*Name|Last\s*Name|Middle\s*Name|Birth\s*date|Date\s*of\s*Birth|DOB|Consultation\s*Date|Blood\s*Pressure|B\.?P\.?|Temp(?:erature)?|Weight|Height|Chief\s*Complaints?|Diagnosis|Prescription|Rx|Notes?|Remarks?';
    // Three or more form labels in one value = the model dumped the page into this field
    if (preg_match_all('#\b(?:' . $labels . ')\s*[:=]#i', $v) >= 3) { return ''; }
    if ($ownLabel !== '') {
        $v = preg_replace('#^\s*(?:' . $ownLabel . ')\s*[:=]\s*#i', '', $v);   // echoed own label: "Diagnosis: URTI"
    }
    if (preg_match('#\b(?:' . $labels . ')\s*[:=]#i', $v, $m, PREG_OFFSET_CAPTURE)) {
        if ($m[0][1] === 0) { return ''; }                // starts with some OTHER field's label: wrong field
        $v = substr($v, 0, $m[0][1]);                     // runs into the next field: keep only its own part
    }
    $v = trim(preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $v));
    if ($v === '' || preg_match('#^[\d\s/\-.:,]+$#', $v)) { return ''; }
    $raw = trim((string) $rawText);
    if ($raw !== '' && strlen($raw) > 40 && strlen($v) >= 0.8 * strlen($raw)) { return ''; }
    return mb_substr($v, 0, 600, 'UTF-8');
}

/**
 * Maps the JSON object returned by the Groq model onto the label => value map understood by
 * mh_textract_map_fields(), validating every value on the way so nothing lands in the wrong
 * input (no dates in blood pressure, no whole-page dumps in chief complaints).
 *
 * Expected keys: last_name, first_name, middle_name (or middle_initial), birthdate,
 * consultation_date, weight, height, temperature, blood_pressure, chief_complaints,
 * diagnosis, prescription - plus optional notes and maternal / immunization extras.
 *
 * The reserved '__source' => 'groq' entry tells the mapper these values are authoritative.
 *
 * @param array $data Decoded JSON object from the model.
 * @return array<string,string> label => value (empty values dropped)
 */
function mh_groq_json_to_kv(array $data)
{
    $get = function (array $keys) use ($data) {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $data)) { continue; }
            $v = $data[$k];
            if (is_array($v)) { $v = implode(', ', array_filter(array_map('strval', array_filter($v, 'is_scalar')))); }
            if (!is_scalar($v)) { continue; }
            $v = trim((string) $v);
            if ($v === '' || preg_match('#^(null|none|n/?a|-+)$#i', $v)) { continue; }
            return $v;
        }
        return '';
    };
    $rawText = $get(['raw_text']);

    $kv = [];
    $put = function ($label, $value) use (&$kv) { if ($value !== '') { $kv[$label] = $value; } };

    // ---- Identity ----
    $first  = mh_groq_clean_name($get(['first_name']));
    $last   = mh_groq_clean_name($get(['last_name']));
    $middle = mh_groq_clean_name($get(['middle_name', 'middle_initial']));
    if (($first === '' || $last === '') && ($full = $get(['patient_name', 'name'])) !== '') {
        $n = vision_ocr_split_name($full);   // handles "Santos, Maria" and "Maria Santos"
        if ($first === '') { $first = mh_groq_clean_name($n['first_name']); }
        if ($last === '')  { $last  = mh_groq_clean_name($n['last_name']); }
    }
    $put('First Name', $first);
    $put('Last Name', $last);
    $put('Middle Name', $middle);
    $put('Patient Name', trim($first . ' ' . $last));
    $put('Birthdate', mh_groq_clean_date($get(['birthdate', 'date_of_birth', 'dob'])));
    $put('Consultation Date', mh_groq_clean_date($get(['consultation_date', 'visit_date'])));

    // ---- Vitals ----
    $put('Blood Pressure', mh_groq_clean_bp($get(['blood_pressure', 'bp'])));
    $put('Temperature', mh_groq_clean_temp($get(['temperature', 'temp'])));
    $put('Weight', mh_groq_clean_weight($get(['weight'])));
    $put('Height', mh_groq_clean_height($get(['height'])));

    // ---- Clinical text ----
    $put('Chief Complaints', mh_groq_clean_text($get(['chief_complaints', 'chief_complaint']), $rawText, 'Chief\s*Complaints?'));
    $put('Diagnosis', mh_groq_clean_text($get(['diagnosis']), $rawText, 'Diagnosis|Dx'));
    $put('Prescription', mh_groq_clean_text($get(['prescription']), $rawText, 'Prescription|Rx'));
    $put('Notes', mh_groq_clean_text($get(['notes']), $rawText, 'Notes?|Remarks?'));

    // ---- Maternal care extras ----
    $put('Gravida', preg_replace('#\\D#', '', $get(['gravida'])));
    $put('Para', preg_replace('#\\D#', '', $get(['para'])));
    $put('LMP', mh_groq_clean_date($get(['lmp'])));
    $put('EDC', mh_groq_clean_date($get(['edc']), true));
    $put('AOG', mb_substr($get(['aog']), 0, 40, 'UTF-8'));
    $put('FH / FHT', mb_substr($get(['fh_fht']), 0, 100, 'UTF-8'));

    // ---- Immunization extras ----
    $put('Family No', mb_substr($get(['family_no']), 0, 40, 'UTF-8'));
    $put("Mother's Name", mh_groq_clean_name($get(['mother_name'])));
    $put("Father's Name", mh_groq_clean_name($get(['father_name'])));
    $put('Contact No', preg_match('#[\\d\\s+()\\-]{7,20}#', $get(['contact_no']), $cm) ? trim($cm[0]) : '');
    $put('Place of Birth', mb_substr($get(['birth_place']), 0, 100, 'UTF-8'));
    $put('Type of Delivery', mb_substr($get(['type_of_delivery']), 0, 60, 'UTF-8'));
    $put('Attending Physician', mh_groq_clean_name($get(['attending_physician'])));

    $kv['__source'] = 'groq';
    return $kv;
}

/**
 * Shrinks a photo for Groq OCR using GD, so users never hit Groq's ~4 MB base64 limit.
 *
 * Triggers when the file is larger than 2 MB or either side exceeds 1600 px. The image is scaled
 * down proportionally (never up), phone-camera EXIF rotation is applied, transparency is flattened
 * onto white, and the result is written as a JPEG (quality 80, stepping down only if still > 2 MB).
 *
 * Never throws and never blocks a scan: on any GD problem it logs and returns the ORIGINAL file,
 * so call_groq_ocr()'s own size check still applies as a safety net.
 *
 * The caller MUST unlink() `path` when `temp` is true.
 *
 * @param string $imagePath Absolute path to the uploaded image.
 * @param string $mimeType  Detected MIME type (image/jpeg | image/png | image/webp).
 * @return array{path:string, mime:string, temp:bool}
 */
function mh_groq_prepare_image($imagePath, $mimeType)
{
    $original = ['path' => $imagePath, 'mime' => $mimeType, 'temp' => false];
    $maxBytes = 2 * 1024 * 1024;
    $maxSide  = 1600;
    $tmp      = '';
    $src      = null;
    $dst      = null;

    try {
        $size = (int) @filesize($imagePath);
        $info = @getimagesize($imagePath);
        if ($info === false) {
            vision_ocr_log_error('groq_ocr', 'Image', 'Could not read image dimensions; sending the original file.');
            return $original;
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w < 1 || $h < 1) { return $original; }

        if ($size <= $maxBytes && $w <= $maxSide && $h <= $maxSide) {
            return $original; // already small enough
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            vision_ocr_log_error('groq_ocr', 'Image', 'PHP GD library is not available; cannot resize the photo for Groq OCR.', ['bytes' => $size, 'w' => $w, 'h' => $h]);
            return $original;
        }

        // ---- Load ----
        switch ($mimeType) {
            case 'image/png':  $src = function_exists('imagecreatefrompng')  ? @imagecreatefrompng($imagePath)  : false; break;
            case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : false; break;
            default:           $src = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($imagePath) : false; break;
        }
        if (!$src) {
            vision_ocr_log_error('groq_ocr', 'Image', 'GD could not decode the image (corrupt, unsupported, or not enough memory); sending the original file.', ['bytes' => $size, 'w' => $w, 'h' => $h]);
            return $original;
        }

        // ---- Honour EXIF orientation (phone photos are often stored sideways) ----
        if ($mimeType === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
            $exif  = @exif_read_data($imagePath);
            $orient = (int) ($exif['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
            if ($angle !== 0) {
                $rot = @imagerotate($src, $angle, 0);
                if ($rot) { $src = $rot; }
            }
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min(1.0, $maxSide / max($sw, $sh));
        $nw = max(1, (int) round($sw * $scale));
        $nh = max(1, (int) round($sh * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if (!$dst) {
            vision_ocr_log_error('groq_ocr', 'Image', 'GD could not allocate the resized image; sending the original file.');
            return $original;
        }
        // JPEG has no alpha: flatten PNG/WebP transparency onto white (black would hide text)
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh)) {
            vision_ocr_log_error('groq_ocr', 'Image', 'GD failed while resampling the image; sending the original file.');
            return $original;
        }

        $tmp = @tempnam(sys_get_temp_dir(), 'groq_');
        if ($tmp === false || $tmp === '') {
            vision_ocr_log_error('groq_ocr', 'Image', 'Could not create a temporary file for the resized image; sending the original file.');
            return $original;
        }

        // ---- Encode: quality 80, step down only if still over the size target ----
        $ok = false;
        foreach ([80, 70, 60, 50] as $q) {
            if (!@imagejpeg($dst, $tmp, $q)) { break; }
            clearstatcache(true, $tmp);
            $ok = true;
            if ((int) @filesize($tmp) <= $maxBytes) { break; }
        }
        if (!$ok || (int) @filesize($tmp) === 0) {
            vision_ocr_log_error('groq_ocr', 'Image', 'GD failed to write the compressed JPEG; sending the original file.');
            @unlink($tmp);
            return $original;
        }

        return ['path' => $tmp, 'mime' => 'image/jpeg', 'temp' => true];
    } catch (Throwable $e) {
        vision_ocr_log_error('groq_ocr', 'Image', 'Image compression failed: ' . $e->getMessage() . ' (sending the original file).');
        if ($tmp !== '' && $tmp !== false) { @unlink($tmp); }
        return $original;
    } finally {
        // Release GD memory promptly (no-op objects on PHP 8, needed on PHP 7)
        if (PHP_VERSION_ID < 80000) {
            if (is_resource($src)) { imagedestroy($src); }
            if (is_resource($dst)) { imagedestroy($dst); }
        }
    }
}

/**
 * Sends a document image to Groq's OpenAI-compatible chat completions endpoint
 * with a multimodal vision model and asks for the consultation-form fields as a
 * JSON object.
 *
 * Contract mirrors call_aws_textract_ocr(): on success returns
 *   ['success'=>true, 'text'=>string, 'kv'=>array<label,value>, 'raw'=>array]
 * where `kv` feeds mh_textract_map_fields() and `text` is the model's verbatim
 * transcription (or "Label: value" lines) for the regex-parser fallbacks.
 * On failure returns ['success'=>false, 'text'=>'', 'kv'=>[], 'error'=>string].
 * It never throws and never falls back to another engine.
 *
 * @param string      $image_path    Absolute path to the uploaded JPG/PNG/WebP.
 * @param string|null $groq_api_key  Groq key ("gsk_..."). Blank => read live from system_settings.
 * @return array{success:bool, text:string, kv:array, error?:string, raw?:array}
 */
function call_groq_ocr($image_path, $groq_api_key = null)
{
    $fail = function ($stage, $message, array $ctx = []) {
        vision_ocr_log_error('groq_ocr', $stage, $message, $ctx);
        return ['success' => false, 'text' => '', 'kv' => [], 'error' => $message];
    };

    try {
        require_once __DIR__ . '/../config/vision.php';

        $groq_api_key = trim((string) $groq_api_key);
        if ($groq_api_key === '' && function_exists('mh_ocr_live_setting')) {
            $groq_api_key = trim((string) mh_ocr_live_setting('groq_api_key', ''));
        }
        if ($groq_api_key === '') {
            return $fail('Config', 'The Groq API key has not been configured yet. Open System Settings > Scanner (OCR) and paste a Groq key (starts with "gsk_").');
        }
        if (preg_match('/\s/', $groq_api_key)) {
            return $fail('Config', 'The saved Groq API key contains a space or line break. Re-paste it as a single line in System Settings > Scanner (OCR).');
        }

        if (!is_file($image_path) || !is_readable($image_path)) {
            return $fail('File', 'The document image file could not be read or does not exist on the server.', ['path' => $image_path]);
        }

        // ---- MIME type (Groq vision accepts images only, not PDFs) ----
        $mimeType = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mimeType = (string) finfo_file($fi, $image_path); finfo_close($fi); }
        }
        if ($mimeType === '' && function_exists('mime_content_type')) {
            $mimeType = (string) mime_content_type($image_path);
        }
        $aliases = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'image/x-png' => 'image/png'];
        if (isset($aliases[$mimeType])) { $mimeType = $aliases[$mimeType]; }
        if ($mimeType === '') { $mimeType = 'image/jpeg'; }
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $fail('File', 'Groq OCR supports JPEG, PNG and WebP images only (not PDF). Received: ' . $mimeType . '. Please upload a photo of the form.');
        }

        // Shrink big photos first (GD): >2 MB or >1600 px => proportional resize + JPEG q80.
        // Falls back to the original file if GD is unavailable or fails.
        $prepared = mh_groq_prepare_image($image_path, $mimeType);
        $mimeType = $prepared['mime'];
        $bytes    = @file_get_contents($prepared['path']);
        if ($prepared['temp']) { @unlink($prepared['path']); } // temp file no longer needed once in memory
        if ($bytes === false || $bytes === '') {
            return $fail('File', 'The uploaded document is empty or could not be loaded into memory.');
        }
        $b64 = base64_encode($bytes);
        unset($bytes);
        // Safety net: Groq rejects base64 images over 4 MB (only reachable if compression was skipped/failed)
        if (strlen($b64) > 4 * 1024 * 1024) {
            return $fail('File', 'The photo is too large for Groq OCR and could not be compressed automatically. Please retake it at a lower resolution.', ['base64_bytes' => strlen($b64)]);
        }
        $dataUrl = 'data:' . $mimeType . ';base64,' . $b64;
        unset($b64);

        // ---- Prompt ----
        $prompt = <<<'PROMPT'
You are an OCR engine for a barangay health center. The image is a handwritten or printed medical form (general consultation slip, prenatal card, immunization card or dental record). Read it carefully and return ONE JSON object only - no markdown, no commentary.

Return these exact keys. Use "" for anything blank or unreadable. Never guess, never copy a value into a different key.
{
  "last_name": "surname only",
  "first_name": "given name(s) only",
  "middle_name": "middle name, or just the initial if only an initial is written",
  "middle_initial": "single letter, only if middle_name is not written out",
  "birthdate": "the patient's date of birth, YYYY-MM-DD",
  "consultation_date": "date of this visit/consultation, YYYY-MM-DD",
  "weight": "number + unit, e.g. 65 kg",
  "height": "number + unit, e.g. 160 cm",
  "temperature": "number + unit, e.g. 36.8 C",
  "blood_pressure": "systolic/diastolic only, e.g. 120/80",
  "chief_complaints": "the patient's reason for visit / symptoms, short text",
  "diagnosis": "the diagnosis / assessment only",
  "prescription": "medicines with dose and frequency only"
}

Rules:
- Dates: output YYYY-MM-DD. "birthdate" is the date of birth; "consultation_date" is the visit date. Never put one in place of the other and never put a date in any other key.
- blood_pressure is ONLY two numbers separated by a slash (e.g. 110/70). A date such as 05/14/1990 is NEVER a blood pressure.
- If the name is written "Surname, Given Middle", split it into last_name, first_name and middle_name.
- chief_complaints, diagnosis and prescription each contain only their own field's text - never the whole form, other fields, labels, or vitals.

Only if the form shows them, you may also add these optional keys (omit them otherwise): "notes", "gravida", "para", "lmp", "edc", "aog", "fh_fht", "family_no", "mother_name", "father_name", "contact_no", "birth_place", "type_of_delivery", "attending_physician".

Finally add "raw_text": a verbatim line-by-line transcription of ALL visible text.
PROMPT;

        $payload = [
            'model'       => defined('GROQ_OCR_MODEL') ? GROQ_OCR_MODEL : 'qwen/qwen3.8-27b',
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ]],
            'temperature'     => 0,
            'response_format' => ['type' => 'json_object'],
        ];
        unset($dataUrl);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        unset($payload);
        if ($payloadJson === false) {
            return $fail('JSON', 'Failed to prepare the document payload (JSON encoding error).', ['json_error' => json_last_error_msg()]);
        }

        // ---- HTTP call ----
        if (!function_exists('curl_init')) {
            return $fail('cURL', 'The PHP cURL extension is not available on this server.');
        }
        $ch = curl_init(defined('GROQ_API_ENDPOINT') ? GROQ_API_ENDPOINT : 'https://api.groq.com/openai/v1/chat/completions');
        if ($ch === false) {
            return $fail('cURL', 'Failed to initialize cURL for Groq OCR.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $groq_api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => defined('GROQ_HTTP_TIMEOUT') ? (int) GROQ_HTTP_TIMEOUT : 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $curlNo   = curl_errno($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        unset($payloadJson);

        if ($response === false || $curlNo !== 0) {
            return $fail('cURL', 'Network error reaching Groq: ' . ($curlErr ?: ('Code #' . $curlNo)), ['curl_no' => $curlNo]);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return $fail('JSON', 'Groq returned an unreadable response (HTTP ' . $httpCode . ').', [
                'http_code' => $httpCode,
                'snippet'   => substr((string) $response, 0, 200),
            ]);
        }

        if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
            $apiMsg = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['message'] ?? '') : (string) ($decoded['error'] ?? '');
            if ($httpCode === 401) {
                $msg = 'Groq rejected the API key (HTTP 401). Check the key in System Settings > Scanner (OCR).';
            } elseif ($httpCode === 429) {
                $msg = 'Groq rate limit reached (HTTP 429). Wait a minute and scan again.';
            } else {
                $msg = $apiMsg !== '' ? $apiMsg : ('Groq API error (HTTP ' . $httpCode . ').');
            }
            return $fail('API', $msg, ['http_code' => $httpCode]);
        }

        // ---- Parse the model's JSON answer ----
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) { // some gateways return content parts
            $content = implode('', array_map(function ($p) { return is_array($p) ? (string) ($p['text'] ?? '') : (string) $p; }, $content));
        }
        $content = (string) $content;
        // Reasoning models may prepend <think>...</think>; strip it before decoding
        $content = trim(preg_replace('#<think>.*?</think>#is', '', $content));
        $data = json_decode($content, true);
        if (!is_array($data)) {
            // Fall back to the outermost {...} in case of stray prose or code fences
            $s = strpos($content, '{');
            $e = strrpos($content, '}');
            if ($s !== false && $e !== false && $e > $s) {
                $data = json_decode(substr($content, $s, $e - $s + 1), true);
            }
        }
        if (!is_array($data)) {
            return $fail('JSON', 'Groq did not return a valid JSON object for this document. Please try another photo.', [
                'snippet' => substr($content, 0, 200),
            ]);
        }

        $kv = mh_groq_json_to_kv($data);

        $text = '';
        if (isset($data['raw_text'])) {
            $text = is_array($data['raw_text']) ? implode("\n", array_map('strval', array_filter($data['raw_text'], 'is_scalar'))) : (string) $data['raw_text'];
            $text = trim($text);
        }
        if ($text === '') {
            // No transcription: synthesise "Label: value" lines so the regex parsers still have input
            $lines = [];
            foreach ($kv as $label => $value) { if (strpos($label, '__') === 0) { continue; } $lines[] = $label . ': ' . $value; }
            $text = implode("\n", $lines);
        }

        return [
            'success' => true,
            'text'    => $text,
            'kv'      => $kv,
            'raw'     => $data,
        ];
    } catch (Throwable $e) {
        vision_ocr_log_error('groq_ocr', 'Exception', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return [
            'success' => false,
            'text'    => '',
            'kv'      => [],
            'error'   => 'An error occurred during Groq OCR processing: ' . $e->getMessage(),
        ];
    }
}

/**
 * Unified OCR dispatcher.
 *
 * Reads the `ocr_engine` system setting and routes the image to either:
 *   - Google Cloud Vision API  (ocr_engine = 'vision')
 *   - Google Gemini AI API     (ocr_engine = 'gemini')
 *   - Amazon Textract          (ocr_engine = 'aws_textract')
 *   - Groq vision LLM          (ocr_engine = 'groq_ocr')
 *
 * Always returns a standardized contract:
 *   {success: bool, text: string, engine: string, error?: string, kv?: array}
 *
 * All four scan endpoints (checkup, maternal, immunization, dental) call
 * this function; they never reference a specific engine directly.
 *
 * @param string      $imagePath  Absolute path to the uploaded image file.
 * @param string|null $apiKey     Optional key override (uses config defaults otherwise).
 * @return array{success:bool, text:string, engine:string, error?:string, kv?:array}
 */
function call_ocr_engine($imagePath, $apiKey = null)
{
    $engine = 'unknown';
    try {
        require_once __DIR__ . '/../config/vision.php';

        // Read LIVE from system_settings on every scan (see mh_ocr_active_engine()).
        $engine = function_exists('mh_ocr_active_engine') ? mh_ocr_active_engine() : 'vision';

        switch ($engine) {
            case 'aws_textract':
                // Textract only. A Textract failure is reported as a Textract failure -
                // it never silently retries on Google Cloud Vision or Gemini.
                $result = call_aws_textract_ocr($imagePath);
                break;

            case 'gemini':
                $result = call_gemini_vision_ocr($imagePath, $apiKey);
                break;

            case 'groq_ocr':
                // Groq only. The key is read LIVE from system_settings on every scan (never a
                // constant frozen at include time). The returned `kv` map flows through the same
                // mh_textract_map_fields() pipeline the scan endpoints already use for Textract.
                $groqKey = (string) mh_ocr_live_setting('groq_api_key', '');
                $result  = call_groq_ocr($imagePath, $groqKey);
                break;

            case 'vision':
            default:
                $result = call_google_vision_ocr($imagePath, $apiKey);
                break;
        }

        if (!is_array($result)) {
            $result = [
                'success' => false,
                'text'    => '',
                'error'   => 'OCR processor returned an invalid response structure.',
            ];
        }

        $result['engine'] = $engine;
        return $result;
    } catch (Throwable $e) {
        vision_ocr_log_error('router', 'Exception', $e->getMessage(), [
            'file'   => $e->getFile(),
            'line'   => $e->getLine(),
            'engine' => $engine,
        ]);
        return [
            'success' => false,
            'text'    => '',
            'engine'  => $engine,
            'error'   => 'Document OCR scanning failed: ' . $e->getMessage(),
        ];
    }
}

// Backwards-compatible aliases if other files reference old Gemini naming
if (!function_exists('gemini_ocr_get_uploaded_file')) {
    function gemini_ocr_get_uploaded_file($fieldName) { return vision_ocr_get_uploaded_file($fieldName); }
}
if (!function_exists('gemini_ocr_normalize_date')) {
    function gemini_ocr_normalize_date($rawDate) { return vision_ocr_normalize_date($rawDate); }
}
if (!function_exists('gemini_ocr_match_patient')) {
    function gemini_ocr_match_patient(mysqli $conn, $firstName, $lastName, $birthdate = '') {
        return vision_ocr_match_patient($conn, $firstName, $lastName, $birthdate);
    }
}

} // End define guard
