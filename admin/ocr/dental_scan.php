<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // never leak PHP errors into a JSON response
header('Content-Type: application/json');

// JSON-safe auth (see immunization_scan.php for why admin_auth.php is not used here)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
header("Pragma: no-cache");
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/dental_helper.php';
mh_require_ocr_access_json('dental');          // module switch + 'use_ocr_scanner' toggle
if (!mh_has_capability('can_log_dental')) {    // scanning pre-fills a record, so it needs the Log permission
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your account can view dental records but not create them.']);
    exit;
}

// One page per request (the browser sends the pages one after another and merges them), so a slow
// Gemini retry on one page never hits the server's execution limit for the whole batch.
if (!defined('GEMINI_MAX_ATTEMPTS'))    { define('GEMINI_MAX_ATTEMPTS', 4); }
if (!defined('GEMINI_BACKOFF_BASE_MS')) { define('GEMINI_BACKOFF_BASE_MS', 700); }
if (!defined('GEMINI_BACKOFF_CAP_MS'))  { define('GEMINI_BACKOFF_CAP_MS', 8000); }
if (!defined('GEMINI_HTTP_TIMEOUT'))    { define('GEMINI_HTTP_TIMEOUT', 75); }

require_once __DIR__ . '/../../includes/ocr/GeminiOcrClient.php';
if (file_exists(__DIR__ . '/../../config/gemini.php')) {
    require_once __DIR__ . '/../../config/gemini.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The OCR configuration loader is missing on the server, so the scanner cannot read its API key.']);
    exit;
}
if (function_exists('gemini_api_key_problem')) {
    $keyProblem = gemini_api_key_problem();
    if ($keyProblem !== null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $keyProblem]);
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}
@set_time_limit(180);

$upload = gemini_ocr_get_uploaded_file('ocr_file');
if (!$upload['success']) { echo json_encode($upload); exit; }

/* ---------------------------------------------------------------------
 * Schema (kept small on purpose: the form fields come back as key/value pairs
 * whose keys are listed in the prompt, rather than ~150 schema properties).
 * ------------------------------------------------------------------ */
$str = ['type' => 'string'];
$schema = [
    'type' => 'object',
    'properties' => [
        'page_type' => ['type' => 'string', 'description' => 'treatment_record | medical_history | odontogram | caries_child | caries_adult | other'],
        'fields' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => $str, 'value' => $str]]],
        'odontogram' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'tooth' => $str, 'condition_code' => $str, 'status_code' => $str]]],
        'caries_date' => $str,
        'caries' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['q' => $str, 'answer' => $str]]],
        'treatments' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'date' => $str, 'routine_codes' => $str, 'other_codes' => $str, 'tooth_numbers' => $str, 'referrals' => $str, 'remarks' => $str]]],
    ],
];

$catalog = mh_dental_ocr_catalog();
$keyLines = [];
foreach ($catalog as $k => $spec) {
    $hint = '';
    if ($spec[0] === 'yn')     { $hint = ' [value: yes or no]'; }
    if ($spec[0] === 'check')  { $hint = ' [value: yes only when the box is ticked/checked]'; }
    if ($spec[0] === 'date')   { $hint = ' [date exactly as written]'; }
    if ($spec[0] === 'select') { $hint = ' [one of: ' . implode(', ', array_keys($spec[2])) . ']'; }
    $keyLines[] = '- ' . $k . ': ' . $spec[1] . $hint;
}

$prompt = "You are reading ONE page of a scanned or photographed Philippine Rural Health Unit DENTAL record (Municipality of Cainta). "
    . "The page may be: the Individual Treatment Record front (patient details, other patient information, dental history, oral health status), "
    . "the Medical History / Clinical Findings page, the Odontogram (tooth chart with status and condition rows), the Individual Treatment Record "
    . "table (date, routine preventive care, other oral health services, referrals, remarks), or a Caries Risk Assessment (ages 6 and above, or 5 and below). "
    . "Extract ONLY what is actually written, marked or circled on THIS page. Never guess; leave out anything blank or illegible.\n\n"
    . "1) 'fields': a list of {key, value} using ONLY these keys:\n" . implode("\n", $keyLines) . "\n"
    . "Rules: a checkbox/parenthesis counts as ticked only if it has a mark inside; for Yes/No columns report the column that is marked.\n\n"
    . "2) 'odontogram': for every tooth whose condition or status is written in the chart, one item {tooth: FDI number such as 16 or 55, "
    . "condition_code: the code in the condition row (permanent: C, GIC, N/A, Co, Am, C/V, D, X; primary: c, gic, s, co, am, ss, d, x), "
    . "status_code: the code in the status row (a tick, M, RF, Un or m, rf, un)}. Leave a code empty if only the other one is written.\n\n"
    . "3) 'caries': for a Caries Risk Assessment, one item {q, answer} for each row where 'yes' or 'no' is circled. q is the section letter + row number, "
    . "e.g. A1 (Disease factors row 1), B3 (Socio-economic row 3), C2 (Protective factors row 2). answer is the word that is circled (yes or no). "
    . "Also set caries_date to the assessment date if written.\n\n"
    . "4) 'treatments': for the Individual Treatment Record table, one item per filled row: date as written, routine_codes (OE, RA, OP, FV, OHC), "
    . "other_codes (ART, S, Exo, RSP), tooth_numbers, referrals, remarks.\n\n"
    . "Set page_type to what the page is. If the page has none of these, return empty lists.";

$result = gemini_ocr_extract_fields($upload['path'], $upload['mime_type'], $prompt, $schema);
if (!$result['success']) {
    if (in_array(($result['http_code'] ?? 0), [0, 429, 500, 502, 503, 504], true)) { http_response_code(503); }
    $payload = ['success' => false, 'error' => $result['error']];
    if (!empty($result['http_code'])) { $payload['http_code'] = $result['http_code']; }
    echo json_encode($payload);
    exit;
}
$raw = $result['fields'];

/* ---------------------------------------------------------------------
 * Normalise everything into the values the form expects
 * ------------------------------------------------------------------ */
$fields = [];
foreach (($raw['fields'] ?? []) as $item) {
    $key = (string) ($item['key'] ?? '');
    if (!isset($catalog[$key])) { continue; }          // ignore anything that is not a known form field
    $spec = $catalog[$key];
    $val  = trim((string) ($item['value'] ?? ''));
    if ($val === '') { continue; }
    if ($spec[0] === 'date') {
        $val = gemini_ocr_normalize_date($val);
        if ($val === '') { continue; }
    } elseif ($key === 'patient.sex') {
        $val = (stripos($val, 'f') === 0) ? 'Female' : ((stripos($val, 'm') === 0) ? 'Male' : '');
        if ($val === '') { continue; }
    } else {
        $val = mh_dental_clean_value($spec[0] === 'text' || $key === 'patient.age' ? ['text'] : $spec, $val);
        if ($val === '') { continue; }
    }
    $fields[$key] = $val;
}

$validTeeth = array_flip(array_map('strval', mh_dental_all_teeth()));
$odo = [];
foreach (($raw['odontogram'] ?? []) as $t) {
    $tooth = preg_replace('/\D/', '', (string) ($t['tooth'] ?? ''));
    if (!isset($validTeeth[$tooth])) { continue; }
    $c = mh_dental_code_to_condition($t['condition_code'] ?? '');
    $s = mh_dental_code_to_status($t['status_code'] ?? '');
    if ($c === '' && $s === '') { continue; }
    $odo[$tooth] = ['c' => $c, 's' => $s];
}

$caries = [];
foreach (($raw['caries'] ?? []) as $a) {
    $q = strtoupper(preg_replace('/\s+/', '', (string) ($a['q'] ?? '')));
    $ans = strtolower(trim((string) ($a['answer'] ?? '')));
    if (preg_match('/^[ABC]\d{1,2}$/', $q) && ($ans === 'yes' || $ans === 'no')) { $caries[$q] = $ans; }
}
$cariesGroup = '';
$pt = (string) ($raw['page_type'] ?? '');
if ($pt === 'caries_child') { $cariesGroup = 'child'; } elseif ($pt === 'caries_adult') { $cariesGroup = 'adult'; }
$cariesDate = !empty($raw['caries_date']) ? gemini_ocr_normalize_date((string) $raw['caries_date']) : '';

$routineOk = array_keys(mh_dental_routine_codes());
$otherOk   = array_keys(mh_dental_other_codes());
$treatments = [];
foreach (($raw['treatments'] ?? []) as $t) {
    $date = gemini_ocr_normalize_date((string) ($t['date'] ?? ''));
    $pick = function ($text, array $allowed) {
        $found = [];
        foreach (preg_split('/[\s,\/;+&]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            foreach ($allowed as $code) { if (strcasecmp($w, $code) === 0) { $found[$code] = true; } }
        }
        return array_keys($found);
    };
    $routine = $pick($t['routine_codes'] ?? '', $routineOk);
    $other   = $pick($t['other_codes'] ?? '', $otherOk);
    $row = [
        'date' => $date, 'routine' => $routine, 'other' => $other,
        'teeth' => mh_dental_str($t['tooth_numbers'] ?? '', 100),
        'referrals' => mh_dental_str($t['referrals'] ?? '', 255),
        'remarks' => mh_dental_str($t['remarks'] ?? '', 500),
    ];
    if ($date !== '' || $routine || $other || $row['remarks'] !== '' || $row['referrals'] !== '') { $treatments[] = $row; }
}

// Who is this? (used by the browser to open / verify the right chart)
$matched = null;
$first = $fields['patient.first_name'] ?? ''; $last = $fields['patient.last_name'] ?? '';
if ($first !== '' && $last !== '') {
    $m = gemini_ocr_match_patient($conn, $first, $last, $fields['patient.birthdate'] ?? '');
    if ($m) {
        $matched = ['id' => (int) $m['id'], 'full_name' => $m['full_name'], 'first_name' => $m['first_name'],
                    'middle_name' => $m['middle_name'] ?? '', 'last_name' => $m['last_name'], 'birthdate' => $m['birthdate']];
    }
}

echo json_encode([
    'success'       => true,
    'page_type'     => $pt,
    'fields'        => $fields,
    'odontogram'    => $odo,
    'caries'        => $caries,
    'caries_group'  => $cariesGroup,
    'caries_date'   => $cariesDate,
    'treatments'    => $treatments,
    'matched_patient' => $matched,
]);
