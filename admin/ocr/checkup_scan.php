<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// JSON-safe auth check: unlike includes/admin_auth.php, this returns a
// JSON 401 instead of an HTML redirect, since a redirect Location header
// can't be meaningfully followed by fetch()/JSON.parse() on the frontend,
// and this endpoint lives two directories deep (admin/ocr/) rather than
// the one level includes/admin_auth.php's relative redirect assumes.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
header("Pragma: no-cache");
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Your session has expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('checkups');   // module switch + 'use_ocr_scanner' toggle

// ---------------------------------------------------------------------
// Retry budget for this endpoint. These are defined BEFORE config/gemini.php
// is loaded, so they win over the project-wide defaults there (that file
// only defines a constant when it is not already set).
//
// Backoff is exponential with full jitter and honours any Retry-After
// header Google sends, so a 503 "model is overloaded" burst is ridden out
// rather than surfaced to the health worker: ~0.6s, 1.2s, 2.4s between attempts, per model.
// ---------------------------------------------------------------------
if (!defined('GEMINI_MAX_ATTEMPTS'))    { define('GEMINI_MAX_ATTEMPTS', 3); }
if (!defined('GEMINI_BACKOFF_BASE_MS')) { define('GEMINI_BACKOFF_BASE_MS', 600); }
if (!defined('GEMINI_BACKOFF_CAP_MS'))  { define('GEMINI_BACKOFF_CAP_MS', 6000); }
if (!defined('GEMINI_HTTP_TIMEOUT'))    { define('GEMINI_HTTP_TIMEOUT', 45); }

require_once __DIR__ . '/../../includes/ocr/GeminiOcrClient.php';

// config/gemini.php holds the API key, the model name and the retry
// tuning (GEMINI_MAX_ATTEMPTS / GEMINI_BACKOFF_BASE_MS). Without it the
// scanner cannot run, so say so plainly instead of failing generically.
if (file_exists(__DIR__ . '/../../config/gemini.php')) {
    require_once __DIR__ . '/../../config/gemini.php';
} else {
    http_response_code(500);
    $msg = 'The OCR configuration loader is missing on the server, so the scanner cannot read its API key. '
         . 'Copy it from the project template and paste a key from Google AI Studio.';
    echo json_encode(['error' => $msg]);
    exit;
}

// Fail fast on a missing / malformed key so the user sees the real
// problem instead of a 404 from Google several seconds later.
if (function_exists('gemini_api_key_problem')) {
    $keyProblem = gemini_api_key_problem();
    if ($keyProblem !== null) {
        http_response_code(500);
        $msg = $keyProblem;
        echo json_encode(['error' => $msg]);
        exit;
    }
}

// NOTE: the existing frontend (admin/checkups_consultations.php, runOCR())
// uploads under the field name "document", and expects the extracted
// fields as FLAT top-level keys on success, or { error: "..." } on failure.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$upload = gemini_ocr_get_uploaded_file('document');
if (!$upload['success']) {
    echo json_encode(['error' => $upload['error']]);
    exit;
}

// Schema mirrors the Checkups/Consultations form fields (see admin/checkups_consultations.php).
//
// first_name / last_name / birthdate identify the patient the note is
// about (if printed on the document) and are used only for the dynamic
// patient-matching lookup below - the checkup form has no like-named
// text inputs, so they are never auto-typed anywhere.
$schema = [
    'type' => 'object',
    'properties' => [
        'first_name'        => ['type' => 'string', 'description' => "The patient's first name, if printed on the document"],
        'last_name'         => ['type' => 'string', 'description' => "The patient's last name, if printed on the document"],
        'birthdate'         => ['type' => 'string', 'description' => "The patient's date of birth, any format found on the document"],
        'weight'            => ['type' => 'string', 'description' => 'e.g. "65 kg"'],
        'height'            => ['type' => 'string', 'description' => 'e.g. "160 cm"'],
        'temp'              => ['type' => 'string', 'description' => 'Temperature, e.g. "37.2 °C"'],
        'bp'                => ['type' => 'string', 'description' => 'Blood pressure, e.g. "120/80"'],
        'chief_complaints'  => ['type' => 'string'],
        'diagnosis'         => ['type' => 'string'],
        'prescription'      => ['type' => 'string', 'description' => 'Medications given or prescribed'],
        'notes'             => ['type' => 'string', 'description' => 'General remarks'],
    ],
];

$prompt = "You are reading a scanned or photographed medical consultation note or referral "
    . "slip from a Philippine barangay health center. Extract every field you can find and "
    . "return them using the exact JSON schema provided. Rules:\n"
    . "- first_name, last_name and birthdate refer to the PATIENT the note is about, if their "
    . "name/birthdate is printed or handwritten on the document. Omit them if not shown.\n"
    . "- If a field is not present or illegible, omit it (do not guess or invent a diagnosis "
    . "that isn't written down).";

$result = gemini_ocr_extract_fields($upload['path'], $upload['mime_type'], $prompt, $schema);

if (!$result['success']) {
    if (in_array(($result['http_code'] ?? 0), [0, 429, 500, 502, 503, 504], true)) {
        http_response_code(503);
    }
    // This frontend reads a bare { error: "..." } object.
    $payload = ['error' => $result['error']];
    if (!empty($result['http_code'])) { $payload['http_code'] = $result['http_code']; }
    if (!empty($result['model']))     { $payload['model']     = $result['model']; }
    if (!empty($result['debug']))     { $payload['debug']     = $result['debug']; }
    echo json_encode($payload);
    exit;
}

// This frontend expects the clinical fields directly at the top level (no wrapper),
// so we merge them back in alongside the new matching keys for backward compatibility.
$fields = $result['fields'];

$extracted_identity = [
    'first_name' => $fields['first_name'] ?? '',
    'last_name'  => $fields['last_name'] ?? '',
    'birthdate'  => '',
];
unset($fields['first_name'], $fields['last_name']);

if (!empty($fields['birthdate'])) {
    $normalizedBday = gemini_ocr_normalize_date($fields['birthdate']);
    if ($normalizedBday !== '') {
        $extracted_identity['birthdate'] = $normalizedBday;
    }
}
unset($fields['birthdate']);

// ---------------------------------------------------------------------
// Dynamic patient matching against the patients table.
// ---------------------------------------------------------------------
$matched_patient = null;
if ($extracted_identity['first_name'] !== '' && $extracted_identity['last_name'] !== '') {
    require_once __DIR__ . '/../../config/database.php';
    $match = gemini_ocr_match_patient($conn, $extracted_identity['first_name'], $extracted_identity['last_name'], $extracted_identity['birthdate']);
    if ($match) {
        $matched_patient = [
            'id'        => (int) $match['id'],
            'full_name' => $match['full_name'],
            'first_name' => $match['first_name'],
            'middle_name' => $match['middle_name'] ?? '',
            'last_name' => $match['last_name'],
            'birthdate' => $match['birthdate'],
        ];
    }
}

echo json_encode($fields + [
    'success'             => true,
    'extracted_fields'    => $fields,
    'extracted_identity'  => $extracted_identity,
    'matched_patient'     => $matched_patient,
]);
?>