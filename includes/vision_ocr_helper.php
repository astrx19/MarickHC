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

    $engineLabel = ($engine === 'gemini') ? 'Gemini AI' : (($engine === 'vision') ? 'Cloud Vision' : ucfirst($engine));
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
            $apiKey = defined('GOOGLE_VISION_API_KEY') ? GOOGLE_VISION_API_KEY : 'AIzaSyB_3kCP-DpMQqM07x99uhJgiNDFyxHQukI';
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
    } elseif (preg_match('#\b(\d{2,3}\s*/\s*\d{2,3})\s*(?:mmHg)?\b#i', $text, $m)) {
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
    } elseif (preg_match('#\b(\d{2,3}\s*/\s*\d{2,3})\b#', $text, $m)) {
        $fields['blood_pressure'] = preg_replace('#\s+#', '', $m[1]);
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
            $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
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

/**
 * Unified OCR dispatcher.
 *
 * Reads the `ocr_engine` system setting and routes the image to either:
 *   - Google Cloud Vision API  (ocr_engine = 'vision')
 *   - Google Gemini AI API     (ocr_engine = 'gemini')
 *
 * Always returns the same contract as call_google_vision_ocr():
 *   {success: bool, text: string, engine: string, error?: string}
 *
 * All four scan endpoints (checkup, maternal, immunization, dental) call
 * this function; they never reference a specific engine directly.
 *
 * @param string      $imagePath  Absolute path to the uploaded image file.
 * @param string|null $apiKey     Optional key override (uses config/vision.php defaults otherwise).
 * @return array{success:bool, text:string, engine:string, error?:string}
 */
function call_ocr_engine($imagePath, $apiKey = null)
{
    try {
        require_once __DIR__ . '/../config/vision.php';

        $engine = function_exists('mh_ocr_active_engine') ? mh_ocr_active_engine() : 'vision';

        if ($engine === 'gemini') {
            $result = call_gemini_vision_ocr($imagePath, $apiKey);
        } else {
            $result = call_google_vision_ocr($imagePath, $apiKey);
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
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return [
            'success' => false,
            'text'    => '',
            'engine'  => 'unknown',
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
