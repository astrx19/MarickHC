<?php
/**
 * Consultation note / referral slip OCR scan — dual-engine aware.
 * Page contract: upload field "document";
 * Success -> clinical fields as FLAT top-level keys + extracted_fields, extracted_identity, matched_patient, raw_text, engine;
 * Failure -> {error: "..."}
 */
require_once __DIR__ . '/../../includes/vision_ocr_helper.php';
require_once __DIR__ . '/../../includes/ocr/ocr_endpoint.php';
mh_ocr_preflight('bare');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('checkups');

try {
    $upload = mh_ocr_begin([
        'module' => 'General Consultation',
        'field'  => 'document',
    ]);

    // Route through the unified OCR engine dispatcher (Vision or Gemini per System Settings)
    $ocrResult = call_ocr_engine($upload['path']);
    if (!$ocrResult['success']) {
        mh_ocr_json_error($ocrResult['error'] ?? 'OCR scan failed. Please check the document or try again.');
    }

    $rawText = $ocrResult['text'] ?? '';
    if ($rawText === '') {
        mh_ocr_json_error('No readable text could be detected on this document. Please ensure the photo is clear, upright, and well-lit.');
    }

    // Parse clinical and identity fields from the extracted text
    $fields = vision_parse_checkup_text($rawText);

    // Normalize identity fields and perform dynamic patient matching safely
    $extracted_identity = mh_ocr_take_identity($fields);
    $matched_patient    = null;
    try {
        $matched_patient = mh_ocr_match($extracted_identity);
    } catch (Throwable $pe) {
        vision_ocr_log_error($ocrResult['engine'] ?? 'vision', 'Match', 'Patient lookup failed: ' . $pe->getMessage());
    }

    // Log successful scan in the audit trail (System Logs)
    try {
        if (isset($conn) && $conn instanceof mysqli && function_exists('logActivity') && !empty($_SESSION['user_id'])) {
            $nonEmptyCount = 0;
            foreach ($fields as $v) { if ($v !== '' && $v !== null) $nonEmptyCount++; }
            $engine = $ocrResult['engine'] ?? 'vision';
            logActivity($conn, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? 'staff'), 'OCR Scan', 'General Consultation OCR (' . $engine . '): ' . $nonEmptyCount . ' fields read.');
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

    // The checkups page reads the clinical fields directly at the top level
    $payload = $safeFields + [
        'success'            => true,
        'raw_text'           => $safeRawText,
        'engine'             => $ocrResult['engine'] ?? 'vision',
        'extracted_fields'   => $safeFields,
        'extracted_identity' => $extracted_identity,
        'matched_patient'    => $matched_patient,
    ];

    $encoded = json_encode($payload);
    if ($encoded === false) {
        vision_ocr_log_error($ocrResult['engine'] ?? 'vision', 'JSON', 'json_encode failed: ' . json_last_error_msg());
        mh_ocr_json_error('Scanned data could not be formatted into JSON. Please try another photo.');
    }

    echo $encoded;
    exit;

} catch (Throwable $e) {
    vision_ocr_log_error('checkup_scan', 'Fatal', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    mh_ocr_json_error('Server error during OCR scan: ' . $e->getMessage());
}
