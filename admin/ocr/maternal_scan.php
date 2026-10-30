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
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('maternal_care');   // module switch + 'use_ocr_scanner' toggle

// ---------------------------------------------------------------------
// Retry budget for this endpoint. These are defined BEFORE config/gemini.php
// is loaded, so they win over the project-wide defaults there (that file
// only defines a constant when it is not already set).
//
// Backoff is exponential with full jitter and honours any Retry-After
// header Google sends, so a 503 "model is overloaded" burst is ridden out
// rather than surfaced to the health worker: ~0.7s, 1.4s, 2.8s between attempts, per model.
// ---------------------------------------------------------------------
if (!defined('GEMINI_MAX_ATTEMPTS'))    { define('GEMINI_MAX_ATTEMPTS', 3); }
if (!defined('GEMINI_BACKOFF_BASE_MS')) { define('GEMINI_BACKOFF_BASE_MS', 700); }
if (!defined('GEMINI_BACKOFF_CAP_MS'))  { define('GEMINI_BACKOFF_CAP_MS', 6000); }
if (!defined('GEMINI_HTTP_TIMEOUT'))    { define('GEMINI_HTTP_TIMEOUT', 60); }

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
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// Fail fast on a missing / malformed key so the user sees the real
// problem instead of a 404 from Google several seconds later.
if (function_exists('gemini_api_key_problem')) {
    $keyProblem = gemini_api_key_problem();
    if ($keyProblem !== null) {
        http_response_code(500);
        $msg = $keyProblem;
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
}

// NOTE: the existing frontend (admin/maternal_care.php, triggerOcrScan())
// uploads under the field name "ocr_image", and expects:
//   success -> { success: true, fields: { ... } }
//   failure -> { success: false, message: "..." }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$upload = gemini_ocr_get_uploaded_file('ocr_image');
if (!$upload['success']) {
    echo json_encode(['success' => false, 'message' => $upload['error']]);
    exit;
}

// Schema mirrors the Prenatal/Maternal Care form fields (see admin/maternal_care.php).
//
// first_name / last_name / birthdate identify the MOTHER (the patient)
// if printed on the card, and are used only for the dynamic
// patient-matching lookup below - the Prenatal Record Form has no
// like-named text inputs (the mother is chosen via a patient dropdown),
// so they are never auto-typed anywhere.
$schema = [
    'type' => 'object',
    'properties' => [
        'first_name'     => ['type' => 'string', 'description' => "The mother/patient's first name, if printed on the card"],
        'last_name'      => ['type' => 'string', 'description' => "The mother/patient's last name, if printed on the card"],
        'birthdate'      => ['type' => 'string', 'description' => "The mother/patient's date of birth, any format found on the card"],
        'gravida'        => ['type' => 'string', 'description' => 'G - Gravida, e.g. 2'],
        'para'           => ['type' => 'string', 'description' => 'P - Para, e.g. 1'],
        'lmp'            => ['type' => 'string', 'description' => 'Last Menstrual Period date'],
        'edc'            => ['type' => 'string', 'description' => 'Expected Date of Confinement'],
        'aog'            => ['type' => 'string', 'description' => 'Age of Gestation, e.g. "24 wks"'],
        'blood_pressure' => ['type' => 'string', 'description' => 'e.g. 110/70'],
        'weight'         => ['type' => 'string', 'description' => 'in kg, numbers only'],
        'height'         => ['type' => 'string', 'description' => 'in cm, numbers only'],
        'fh_fht'         => ['type' => 'string', 'description' => 'Fundal Height / Fetal Heart Tone, e.g. "24 cm / 140 bpm"'],
        'notes'          => ['type' => 'string', 'description' => 'Any remarks, vitamins prescribed, or additional instructions'],
    ],
];

$prompt = "You are reading a scanned or photographed Philippine Barangay Health Center "
    . "prenatal/maternal care record card. Extract every field you can find and return them "
    . "using the exact JSON schema provided. Rules:\n"
    . "- first_name, last_name and birthdate refer to the MOTHER the card belongs to (the "
    . "patient) - look near the top of the card where the patient's name is usually printed "
    . "or handwritten. Omit them if not shown.\n"
    . "- If a field is not present or illegible, omit it (do not guess).\n"
    . "- lmp and edc should be written exactly as they appear on the document - do not reformat them yourself.\n"
    . "- weight should be a plain number in kilograms (no unit text).\n"
    . "- height should be a plain number in centimeters (no unit text).";

$result = gemini_ocr_extract_fields($upload['path'], $upload['mime_type'], $prompt, $schema);

if (!$result['success']) {
    if (in_array(($result['http_code'] ?? 0), [0, 429, 500, 502, 503, 504], true)) {
        http_response_code(503);
    }
    // This frontend reads { success: false, message: "..." }.
    $payload = ['success' => false, 'message' => $result['error']];
    if (!empty($result['http_code'])) { $payload['http_code'] = $result['http_code']; }
    if (!empty($result['model']))     { $payload['model']     = $result['model']; }
    if (!empty($result['debug']))     { $payload['debug']     = $result['debug']; }
    echo json_encode($payload);
    exit;
}

$fields = $result['fields'];
foreach (['lmp', 'edc'] as $key) {
    if (!empty($fields[$key])) {
        $normalized = gemini_ocr_normalize_date($fields[$key]);
        if ($normalized !== '') {
            $fields[$key] = $normalized;
        } else {
            unset($fields[$key]);
        }
    }
}

// ---------------------------------------------------------------------
// Dynamic patient matching: pull the mother's identity fields out of the
// clinical fields (the Prenatal Record Form has no first_name /
// last_name / birthdate inputs - the mother is chosen via the patient
// dropdown - so we pull them out explicitly instead of leaving them in
// $fields, where they'd just be ignored).
// ---------------------------------------------------------------------
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

echo json_encode([
    'success'             => true,
    'fields'              => $fields,             // clinical fields - back-compat with the existing frontend
    'extracted_fields'    => $fields,
    'extracted_identity'  => $extracted_identity,  // mother's name/birthdate as read from the card
    'matched_patient'     => $matched_patient,     // null when no existing patient matched
]);
?>