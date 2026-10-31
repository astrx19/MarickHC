<?php
/**
 * Maternal care (prenatal card) OCR scan.
 * Page contract: upload field "ocr_image"; success -> {success:true, fields:{...}, extracted_fields, extracted_identity, matched_patient}; failure -> {success:false, message}
 * Shared plumbing (headers, session, Gemini config, upload, retries, matching) lives in
 * includes/ocr/ocr_endpoint.php - this file only holds what is specific to this form.
 */
require_once __DIR__ . '/../../includes/ocr/ocr_endpoint.php';
mh_ocr_preflight('message');

require_once __DIR__ . '/../../config/database.php';          // kept at top level: defines $conn
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('maternal_care');   // module switch + 'use_ocr_scanner' toggle

$upload = mh_ocr_begin(['field' => 'ocr_image', 'attempts' => 3, 'base_ms' => 700, 'cap_ms' => 6000, 'timeout' => 60]);

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

$fields = mh_ocr_extract($upload, $prompt, $schema);
$fields = mh_ocr_normalize_dates($fields, ['lmp', 'edc']);

// The mother's name/birthdate are only used to look her up - the form picks the patient from a dropdown.
$extracted_identity = mh_ocr_take_identity($fields);
$matched_patient    = mh_ocr_match($extracted_identity);

echo json_encode([
    'success'             => true,
    'fields'              => $fields,             // clinical fields - back-compat with the existing frontend
    'extracted_fields'    => $fields,
    'extracted_identity'  => $extracted_identity,  // mother's name/birthdate as read from the card
    'matched_patient'     => $matched_patient,     // null when no existing patient matched
]);
