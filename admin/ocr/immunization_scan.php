<?php
/**
 * Child immunization card OCR scan.
 * Page contract: upload field "ocr_file"; success -> {success:true, fields:{...}, extracted_fields, extracted_identity, matched_patient}; failure -> {success:false, error}
 * Shared plumbing (headers, session, Gemini config, upload, retries, matching) lives in
 * includes/ocr/ocr_endpoint.php - this file only holds what is specific to this form.
 */
require_once __DIR__ . '/../../includes/ocr/ocr_endpoint.php';
mh_ocr_preflight('error');

require_once __DIR__ . '/../../config/database.php';          // kept at top level: defines $conn
require_once __DIR__ . '/../../includes/settings_helper.php';
mh_require_ocr_access_json('immunization');   // module switch + 'use_ocr_scanner' toggle

$upload = mh_ocr_begin(['field' => 'ocr_file', 'attempts' => 4, 'base_ms' => 700, 'cap_ms' => 8000, 'timeout' => 75]);

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

$fields = mh_ocr_extract($upload, $prompt, $schema);

// Every *_date field -> yyyy-mm-dd for the <input type="date"> fields.
$fields = mh_ocr_normalize_dates($fields, [
    'newborn_screening_date', 'tt_of_mother_date', 'mother_bday', 'father_bday',
    'bcg_date', 'hep_b_24hrs_date', 'penta_1_opv_1_date', 'penta_2_opv_2_date',
    'penta_opv_3_date', 'pcv_1_date', 'pcv_2_date', 'pcv_3_date', 'mr_date', 'mmr_date',
]);

// The child's name/birthdate are only used to look the child up (the form has no such inputs).
$extracted_identity = mh_ocr_take_identity($fields);
$matched_patient    = mh_ocr_match($extracted_identity);

echo json_encode([
    'success'             => true,
    'fields'              => $fields,             // clinical fields - back-compat with the existing frontend
    'extracted_fields'    => $fields,             // same data, name matches the OCR-matching contract
    'extracted_identity'  => $extracted_identity,  // child's name/birthdate as read from the card
    'matched_patient'     => $matched_patient,     // null when no existing patient matched
]);
