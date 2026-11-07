<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // never leak PHP errors into a JSON response
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
header("Pragma: no-cache");
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/vision.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/dental_helper.php';
require_once __DIR__ . '/../../includes/vision_ocr_helper.php';

mh_require_ocr_access_json('dental');          // module switch + 'use_ocr_scanner' toggle
if (!mh_has_capability('can_log_dental')) {    // scanning pre-fills a record, so it needs the Log permission
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your account can view dental records but not create them.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}
@set_time_limit(180);

try {
    $upload = vision_ocr_get_uploaded_file('ocr_file');
    if (!$upload['success']) {
        $upload = vision_ocr_get_uploaded_file('document');
        if (!$upload['success']) {
            $upload = vision_ocr_get_uploaded_file('ocr_image');
        }
    }
    if (!$upload['success']) {
        echo json_encode($upload);
        exit;
    }

    // Route through the unified OCR engine dispatcher (Vision or Gemini per System Settings)
    $ocrResult = call_ocr_engine($upload['path']);
    if (!$ocrResult['success']) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => $ocrResult['error'] ?? 'OCR scan failed. Please try again.']);
        exit;
    }

    $rawText = $ocrResult['text'] ?? '';
    if ($rawText === '') {
        echo json_encode(['success' => false, 'error' => 'No readable text was detected on this page. Please ensure the photo is clear and well-lit.']);
        exit;
    }

    $catalog = mh_dental_ocr_catalog();
    // Engines that return key-value pairs (Groq, Textract) refine the regex parse of the raw text
    if (!empty($ocrResult['kv']) && function_exists('mh_textract_map_fields')) {
        $parsed = mh_textract_map_fields($ocrResult['kv'], $rawText, 'dental', $catalog);
    } else {
        $parsed = vision_parse_dental_text($rawText, $catalog);
    }

    $pt          = $parsed['page_type'];
    $fields      = $parsed['fields'];
    $odo         = $parsed['odontogram'];
    $caries      = $parsed['caries'];
    $cariesGroup = $parsed['caries_group'];
    $cariesDate  = $parsed['caries_date'];
    $treatments  = $parsed['treatments'];

    // Dynamic patient matching safely
    $matched = null;
    $first = $fields['patient.first_name'] ?? '';
    $last  = $fields['patient.last_name'] ?? '';
    if ($first !== '' && $last !== '') {
        try {
            $m = vision_ocr_match_patient($conn, $first, $last, $fields['patient.birthdate'] ?? '');
            if ($m) {
                $matched = [
                    'id'          => (int) $m['id'],
                    'full_name'   => $m['full_name'],
                    'first_name'  => $m['first_name'],
                    'middle_name' => $m['middle_name'] ?? '',
                    'last_name'   => $m['last_name'],
                    'birthdate'   => $m['birthdate'],
                ];
            }
        } catch (Throwable $pe) {
            vision_ocr_log_error($ocrResult['engine'] ?? 'vision', 'Match', 'Dental patient lookup failed: ' . $pe->getMessage());
        }
    }

    // Log successful scan in the audit trail (System Logs)
    try {
        if (function_exists('logActivity')) {
            $engine = $ocrResult['engine'] ?? 'vision';
            logActivity($conn, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? 'staff'), 'OCR Scan',
                'Dental scan (' . ($pt !== '' ? $pt : 'unknown page') . ') via ' . $engine . ': ' . count($fields) . ' fields, ' . count($odo) . ' teeth, ' . count($caries) . ' caries answers, ' . count($treatments) . ' treatment rows.');
        }
    } catch (Throwable $le) {
        // Logging should not break the response
    }

    // Ensure valid UTF-8 strings before json_encode
    $safeFields = [];
    foreach ($fields as $k => $v) {
        $safeFields[$k] = is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v;
    }
    $safeRawText = mb_convert_encoding($rawText, 'UTF-8', 'UTF-8');

    $payload = [
        'success'         => true,
        'page_type'       => $pt,
        'fields'          => $safeFields,
        'raw_text'        => $safeRawText,
        'engine'          => $ocrResult['engine'] ?? 'vision',
        'odontogram'      => $odo,
        'caries'          => $caries,
        'caries_group'    => $cariesGroup,
        'caries_date'     => $cariesDate,
        'treatments'      => $treatments,
        'matched_patient' => $matched,
    ];

    $encoded = json_encode($payload);
    if ($encoded === false) {
        vision_ocr_log_error($ocrResult['engine'] ?? 'vision', 'JSON', 'json_encode failed: ' . json_last_error_msg());
        echo json_encode(['success' => false, 'error' => 'Scanned dental data could not be formatted into JSON. Please try another photo.']);
        exit;
    }

    echo $encoded;
    exit;

} catch (Throwable $e) {
    vision_ocr_log_error('dental_scan', 'Fatal', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error during Dental OCR scan: ' . $e->getMessage()]);
    exit;
}
