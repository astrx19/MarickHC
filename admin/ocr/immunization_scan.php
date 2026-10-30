<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // never leak PHP errors into a JSON response
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
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('immunization');   // module switch + 'use_ocr_scanner' toggle

// ---------------------------------------------------------------------
// Retry budget for this endpoint. These are defined BEFORE config/gemini.php
// is loaded, so they win over the project-wide defaults there (that file
// only defines a constant when it is not already set).
//
// Backoff is exponential with full jitter and honours any Retry-After
// header Google sends, so a 503 "model is overloaded" burst is ridden out
// rather than surfaced to the health worker: ~0.7s, 1.4s, 2.8s, 5.6s between attempts, per model.
// ---------------------------------------------------------------------
if (!defined('GEMINI_MAX_ATTEMPTS'))    { define('GEMINI_MAX_ATTEMPTS', 4); }
if (!defined('GEMINI_BACKOFF_BASE_MS')) { define('GEMINI_BACKOFF_BASE_MS', 700); }
if (!defined('GEMINI_BACKOFF_CAP_MS'))  { define('GEMINI_BACKOFF_CAP_MS', 8000); }
if (!defined('GEMINI_HTTP_TIMEOUT'))    { define('GEMINI_HTTP_TIMEOUT', 75); }

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
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// Fail fast on a missing / malformed key so the user sees the real
// problem instead of a 404 from Google several seconds later.
if (function_exists('gemini_api_key_problem')) {
    $keyProblem = gemini_api_key_problem();
    if ($keyProblem !== null) {
        http_response_code(500);
        $msg = $keyProblem;
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$upload = gemini_ocr_get_uploaded_file('ocr_file');
if (!$upload['success']) {
    echo json_encode($upload);
    exit;
}

// Schema mirrors the "Child Immunization Form" fields exactly (see
// admin/immunization.php) so the JSON response can be dropped straight
// into the form inputs with no further parsing.
//
// first_name / last_name / birthdate identify the CHILD (the patient)
// as printed on the card, separate from mother_name / father_name
// below. They are used only for the dynamic patient-matching lookup
// further down - the Child Immunization Form has no like-named inputs,
// so they are never auto-typed into a text field.
$schema = [
    'type' => 'object',
    'properties' => [
        'first_name'             => ['type' => 'string', 'description' => "The CHILD/patient's first name, as printed on the card"],
        'last_name'              => ['type' => 'string', 'description' => "The CHILD/patient's last name, as printed on the card"],
        'birthdate'              => ['type' => 'string', 'description' => "The CHILD/patient's date of birth, any format found on the form"],
        'family_no'              => ['type' => 'string'],
        'newborn_screening_date' => ['type' => 'string', 'description' => 'Date, any format found on the form'],
        'tt_of_mother_date'      => ['type' => 'string'],
        'birth_time'             => ['type' => 'string', 'description' => 'e.g. 14:30 or 2:30 PM'],
        'birth_weight'           => ['type' => 'string', 'description' => 'e.g. 3.1 kg'],
        'birth_height'           => ['type' => 'string', 'description' => 'e.g. 49 cm'],
        'birth_place'            => ['type' => 'string'],
        'type_of_delivery'       => ['type' => 'string'],
        'type_of_breastfeeding'  => ['type' => 'string'],
        'mother_name'            => ['type' => 'string'],
        'mother_bday'            => ['type' => 'string'],
        'mother_age'             => ['type' => 'string'],
        'father_name'            => ['type' => 'string'],
        'father_bday'            => ['type' => 'string'],
        'father_age'             => ['type' => 'string'],
        'attending_physician'    => ['type' => 'string'],
        'no_of_children'         => ['type' => 'string'],
        'contact_no'             => ['type' => 'string'],
        'bcg_date'               => ['type' => 'string'],
        'hep_b_24hrs_date'       => ['type' => 'string'],
        'penta_1_opv_1_date'     => ['type' => 'string'],
        'penta_2_opv_2_date'     => ['type' => 'string'],
        'penta_opv_3_date'       => ['type' => 'string'],
        'pcv_1_date'             => ['type' => 'string'],
        'pcv_2_date'             => ['type' => 'string'],
        'pcv_3_date'             => ['type' => 'string'],
        'others_immunization'    => ['type' => 'string'],
        'mr_date'                => ['type' => 'string'],
        'mmr_date'               => ['type' => 'string'],
    ],
];

$prompt = "You are reading a scanned or photographed Philippine Barangay Health Center "
    . "'Child Immunization Form' / immunization card. Extract every field you can find and "
    . "return them using the exact JSON schema provided. Rules:\n"
    . "- first_name, last_name and birthdate refer to the CHILD the card belongs to (the "
    . "patient), not the mother or father - look near the top of the card where the child's "
    . "name is usually printed or handwritten.\n"
    . "- If a field is not present or illegible on the document, omit it (do not guess).\n"
    . "- For every *_date field (including birthdate), write the date exactly as it appears "
    . "on the document (any format is fine, e.g. 03/14/2024 or March 14 2024) - do not attempt "
    . "to reformat it yourself.\n"
    . "- birth_weight and birth_height should include their units if shown (e.g. '3.1 kg', '49 cm').\n"
    . "- mother_age, father_age, and no_of_children should be plain numbers as strings.\n"
    . "- Names should be title-cased as written on the form.";

$result = gemini_ocr_extract_fields($upload['path'], $upload['mime_type'], $prompt, $schema);

if (!$result['success']) {
    // Transient upstream failures get a 503 so the browser console and any
    // future monitoring can tell "Gemini is down" apart from "bad scan".
    if (in_array(($result['http_code'] ?? 0), [0, 429, 500, 502, 503, 504], true)) {
        http_response_code(503);
    }
    $payload = ['success' => false, 'error' => $result['error']];
    if (!empty($result['http_code'])) { $payload['http_code'] = $result['http_code']; }
    if (!empty($result['model']))     { $payload['model']     = $result['model']; }
    if (!empty($result['debug']))     { $payload['debug']     = $result['debug']; }
    echo json_encode($payload);
    exit;
}

// Reformat every *_date field into yyyy-mm-dd for the <input type="date"> fields.
$dateFields = [
    'newborn_screening_date', 'tt_of_mother_date', 'mother_bday', 'father_bday',
    'bcg_date', 'hep_b_24hrs_date', 'penta_1_opv_1_date', 'penta_2_opv_2_date',
    'penta_opv_3_date', 'pcv_1_date', 'pcv_2_date', 'pcv_3_date', 'mr_date', 'mmr_date',
];

$fields = $result['fields'];
foreach ($dateFields as $key) {
    if (!empty($fields[$key])) {
        $normalized = gemini_ocr_normalize_date($fields[$key]);
        if ($normalized !== '') {
            $fields[$key] = $normalized;
        } else {
            // Couldn't confidently parse it - leave it for manual entry
            // rather than risk populating the wrong date.
            unset($fields[$key]);
        }
    }
}

// ---------------------------------------------------------------------
// Dynamic patient matching: pull the child's identity fields out of the
// clinical fields (the Child Immunization Form has no first_name /
// last_name / birthdate inputs, so leaving them in $fields would just be
// silently ignored by autoFillChildForm() - we pull them out explicitly
// instead so they're only ever used for the lookup below).
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
    // Not require-ing includes/admin_auth.php here: it performs an
    // HTML redirect on a missing session, which would corrupt this
    // JSON response. gemini_ocr_match_patient() falls back to its own
    // "Last, First M." formatting when mh_format_patient_name() (from
    // admin_auth.php) isn't loaded, so it isn't needed here.
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
    'extracted_fields'    => $fields,              // same data, name matches the OCR-matching contract
    'extracted_identity'  => $extracted_identity,  // child's name/birthdate as read from the card
    'matched_patient'     => $matched_patient,     // null when no existing patient matched
]);
?>