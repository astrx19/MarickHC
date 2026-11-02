<?php
/**
 * Consultation note / referral slip OCR scan.
 * Page contract: upload field "document"; success -> clinical fields as FLAT top-level keys + extracted_fields, extracted_identity, matched_patient; failure -> {error}
 * Shared plumbing (headers, session, Gemini config, upload, retries, matching) lives in
 * includes/ocr/ocr_endpoint.php - this file only holds what is specific to this form.
 */
require_once __DIR__ . '/../../includes/ocr/ocr_endpoint.php';
mh_ocr_preflight('bare');

require_once __DIR__ . '/../../config/database.php';          // kept at top level: defines $conn
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('checkups');   // module switch + 'use_ocr_scanner' toggle

$upload = mh_ocr_begin(['module' => 'General Consultation', 'field' => 'document', 'attempts' => 3, 'base_ms' => 600, 'cap_ms' => 6000, 'timeout' => 45]);

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

$fields = mh_ocr_extract($upload, $prompt, $schema);

$extracted_identity = mh_ocr_take_identity($fields);
$matched_patient    = mh_ocr_match($extracted_identity);

// This page reads the clinical fields directly at the top level (no wrapper).
echo json_encode($fields + [
    'success'             => true,
    'extracted_fields'    => $fields,
    'extracted_identity'  => $extracted_identity,
    'matched_patient'     => $matched_patient,
]);
