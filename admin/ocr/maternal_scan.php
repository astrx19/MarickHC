<?php
/**
 * Maternal care (prenatal card) OCR scan — dual-engine aware.
 * Page contract: upload field "ocr_image";
 * Success -> {success:true, fields:{...}, raw_text, engine, extracted_fields, extracted_identity, matched_patient};
 * Failure -> {success:false, message: "..."}
 */
require_once __DIR__ . '/../../includes/vision_ocr_helper.php';
require_once __DIR__ . '/../../includes/ocr/ocr_endpoint.php';
mh_ocr_preflight('message');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('maternal_care');

try {
    $upload = mh_ocr_begin([
        'module' => 'Maternal Care',
        'field'  => 'ocr_image',
    ]);

    // Route through the unified OCR engine dispatcher (Vision or Gemini per System Settings)
    $ocrResult = call_ocr_engine($upload['path']);
    if (!$ocrResult['success']) {
        mh_ocr_json_error($ocrResult['error'] ?? 'OCR scan failed. Please check the document or try again.');
    }

    $rawText = $ocrResult['text'] ?? '';
    if ($rawText === '') {
        mh_ocr_json_error('No readable text could be detected on this prenatal document. Please ensure the image is clear and well-lit.');
    }

    // Parse clinical and mother identity fields from the extracted text
    $fields = vision_parse_maternal_text($rawText);
    $fields = mh_ocr_normalize_dates($fields, ['lmp', 'edc']);

    // The mother's name/birthdate are used to look her up safely
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
            logActivity($conn, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? 'staff'), 'OCR Scan', 'Maternal Care OCR (' . $engine . '): ' . $nonEmptyCount . ' fields read.');
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
        'success'            => true,
        'fields'             => $safeFields,             // clinical fields - auto-fills admin/maternal_care.php inputs
        'raw_text'           => $safeRawText,
        'engine'             => $ocrResult['engine'] ?? 'vision',
        'extracted_fields'   => $safeFields,
        'extracted_identity' => $extracted_identity,     // mother's name/birthdate as read from the card
        'matched_patient'    => $matched_patient,        // matched patient in database or null
    ];

    $encoded = json_encode($payload);
    if ($encoded === false) {
        vision_ocr_log_error($ocrResult['engine'] ?? 'vision', 'JSON', 'json_encode failed: ' . json_last_error_msg());
        mh_ocr_json_error('Scanned data could not be formatted into JSON. Please try another photo.');
    }

    echo $encoded;
    exit;

} catch (Throwable $e) {
    vision_ocr_log_error('maternal_scan', 'Fatal', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    mh_ocr_json_error('Server error during Maternal Care scan: ' . $e->getMessage());
}
