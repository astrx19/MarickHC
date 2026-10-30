<?php
/**
 * Dental module: tables, form definitions (taken from the RHU dental forms), tooth legend,
 * caries-risk rules and POST validation. Shared by admin/dental.php and admin/ocr/dental_scan.php.
 */
if (!defined('MH_LOADED_INCLUDES_DENTAL_HELPER')) {
define('MH_LOADED_INCLUDES_DENTAL_HELPER', true);

/* =====================================================================
 * TABLES
 * ================================================================== */
function mh_dental_ensure_tables(mysqli $conn)
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS dental_charts (
        id INT NOT NULL AUTO_INCREMENT,
        patient_id INT NOT NULL,
        occupation VARCHAR(100) DEFAULT NULL,
        guardian_name VARCHAR(150) DEFAULT NULL,
        philhealth_no VARCHAR(50) DEFAULT NULL,
        sectors LONGTEXT DEFAULT NULL,
        medical_history LONGTEXT DEFAULT NULL,
        consent_privacy TINYINT(1) NOT NULL DEFAULT 0,
        consent_waiver TINYINT(1) NOT NULL DEFAULT 0,
        consent_name VARCHAR(150) DEFAULT NULL,
        consent_date DATE DEFAULT NULL,
        dental_history LONGTEXT DEFAULT NULL,
        clinical_findings LONGTEXT DEFAULT NULL,
        oral_health LONGTEXT DEFAULT NULL,
        odontogram LONGTEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_dental_chart_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS dental_caries_assessments (
        id INT NOT NULL AUTO_INCREMENT,
        chart_id INT NOT NULL,
        patient_id INT NOT NULL,
        age_group VARCHAR(10) NOT NULL,
        assessed_on DATE NOT NULL,
        answers LONGTEXT DEFAULT NULL,
        disease_result VARCHAR(10) DEFAULT NULL,
        socio_result VARCHAR(10) DEFAULT NULL,
        protective_result VARCHAR(10) DEFAULT NULL,
        overall_computed VARCHAR(10) DEFAULT NULL,
        overall_final VARCHAR(10) DEFAULT NULL,
        assessed_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_caries_patient (patient_id, assessed_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS dental_treatments (
        id INT NOT NULL AUTO_INCREMENT,
        chart_id INT NOT NULL,
        patient_id INT NOT NULL,
        treatment_date DATE NOT NULL,
        routine_codes VARCHAR(100) DEFAULT NULL,
        other_codes VARCHAR(100) DEFAULT NULL,
        tooth_numbers VARCHAR(100) DEFAULT NULL,
        referrals VARCHAR(255) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        dentist_id INT DEFAULT NULL,
        dental_record_id INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_treat_patient (patient_id, treatment_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* =====================================================================
 * FORM DEFINITIONS
 * ================================================================== */

/** Vulnerable-sector indicators (Individual Treatment Record). */
function mh_dental_sectors()
{
    return [
        'fourps_recipient'   => "4P's Recipient",
        'fourps_beneficiary' => "4P's Beneficiary",
        'ip'                 => 'Indigenous People (IP)',
        'pwd'                => 'Person with Disability (PWD)',
        'senior'             => 'Senior Citizen',
        'solo_parent'        => 'Solo Parent',
    ];
}

/** Medical history questions 1-6: key => [question, label for the "if so" detail or null]. */
function mh_dental_medical_questions()
{
    return [
        'treatment'    => ['Are you under medical treatment now?', 'Condition being treated'],
        'illness'      => ['Have you ever had a serious illness or surgical operation?', 'What illness or operation?'],
        'hospitalized' => ['Have you ever been hospitalized?', 'When and why?'],
        'medicine'     => ['Are you taking any prescription / non-prescription medicine?', 'Please specify'],
        'tobacco'      => ['Do you use tobacco products?', null],
        'alcohol'      => ['Do you use alcohol or other dangerous drugs?', null],
    ];
}

function mh_dental_allergies()
{
    return [
        'local_anesthetics' => 'Local anesthetics',
        'penicillin'        => 'Penicillin, Antibiotics',
        'aspirin'           => 'Aspirin',
        'sulfa'             => 'Sulfa drugs',
        'latex'             => 'Latex',
    ];
}

function mh_dental_women_questions()
{
    return [
        'pregnant'      => 'Are you pregnant?',
        'nursing'       => 'Are you nursing?',
        'birth_control' => 'Are you taking birth control pills?',
    ];
}

/** Question 9: "Do you have or have you had any of the following?" */
function mh_dental_conditions()
{
    return [
        'high_bp' => 'High blood pressure', 'low_bp' => 'Low blood pressure', 'diabetes' => 'Diabetes',
        'chest_pain' => 'Chest pain', 'heart_disease' => 'Heart disease', 'heart_attack' => 'Heart attack',
        'heart_murmur' => 'Heart murmur', 'heart_surgery' => 'Heart surgery', 'stroke' => 'Stroke',
        'respiratory' => 'Respiratory problem', 'tuberculosis' => 'Tuberculosis', 'kidney' => 'Kidney disease',
        'asthma' => 'Asthma', 'thyroid' => 'Thyroid problem', 'fainting' => 'Fainting / seizure',
        'hiv' => 'AIDS / HIV', 'epilepsy' => 'Epilepsy / convulsion', 'hepatitis' => 'Hepatitis / liver disease',
        'stomach' => 'Stomach troubles / ulcers', 'cancer' => 'Cancer / tumor', 'arthritis' => 'Arthritis',
        'bleeding' => 'Bleeding disorder (added)',
    ];
}

/**
 * Field specs for the plain sections. type: text | date | number | textarea | select | yn | check.
 * Form input name = section[key]; the OCR key is "section.key".
 */
function mh_dental_specs($section)
{
    $specs = [
        'extra' => [
            'occupation'    => ['text', 'Occupation'],
            'guardian_name' => ['text', 'Parent / Guardian (if minor)'],
            'philhealth_no' => ['text', 'PhilHealth number'],
        ],
        'dental_history' => [
            'last_visit'        => ['text', 'Most recent dental visit'],
            'frequency'         => ['select', 'How often do you visit the dentist?', ['twice' => 'At least twice a year', 'once' => 'Once a year', 'never' => 'Never']],
            'orthodontic'       => ['text', 'History / current orthodontic treatment'],
            'prosthesis'        => ['yn', 'Dental prosthesis present'],
            'prosthesis_since'  => ['text', 'Since when?'],
            'referred_by'       => ['text', 'Referred by and reason (if referral)'],
            'remarks'           => ['textarea', 'Remarks'],
        ],
        'clinical' => [
            'periodontal_affected' => ['text', 'A.1 Periodontal disease - affected'],
            'mobility_grade'       => ['select', 'Grade of mobility', ['1' => 'Grade 1', '2' => 'Grade 2', '3' => 'Grade 3']],
            'torus'                => ['yn', 'A.2 Presence of Torus Palatinus / Mandibularis'],
            'epulis'               => ['yn', 'A.3 Presence of Epulis Fissuratum, Cyst, Tumor, etc.'],
            'epulis_detail'        => ['text', 'A.3 details'],
            'cd_upper'  => ['check', 'CD upper'],  'cd_lower'  => ['check', 'CD lower'],
            'rpd_upper' => ['check', 'RPD upper'], 'rpd_lower' => ['check', 'RPD lower'],
            'fpd_upper' => ['check', 'FPD upper'], 'fpd_lower' => ['check', 'FPD lower'],
            'prosthesis_years' => ['text', 'A.4 Number of years in use'],
            'ridge_upper'      => ['select', 'A.5 Height of ridge - upper', ['normal' => 'Normal', 'low' => 'Low']],
            'ridge_lower'      => ['select', 'A.5 Height of ridge - lower', ['normal' => 'Normal', 'low' => 'Low']],
            'tx_scaling'     => ['check', 'B.1 Root planing & scaling'],
            'tx_extraction'  => ['check', 'B.1 Extraction of affected tooth/teeth'],
            'tx_surgical'    => ['check', 'B.2 Surgical removal'],
            'tx_biopsy'      => ['check', 'B.3 Surgical removal / Biopsy'],
            'tx_repair'      => ['check', 'B.4 For repair'],
            'tx_replacement' => ['check', 'B.4 For replacement / new'],
        ],
        'oral_health' => [
            'caries_teeth'    => ['number', 'No. of teeth with dental caries'],
            'debris'          => ['yn', 'Presence of oral debris / calcular deposits'],
            'gingivitis'      => ['yn', 'Presence of gingivitis / periodontitis'],
            'dentofacial'     => ['yn', 'Presence of dento-facial anomalies'],
            'soft_tissue'     => ['yn', 'Presence of soft tissue lesions'],
            'permanent_teeth' => ['number', 'No. of permanent teeth present'],
            'deciduous_teeth' => ['number', 'No. of deciduous teeth present (if pediatric)'],
            'orofacial'       => ['yn', 'Presence of oro-facial anomalies'],
            'orofacial_type'  => ['text', 'If yes, indicate type'],
            'oral_lesions'    => ['yn', 'Presence of oral lesions'],
            'oral_lesions_type' => ['text', 'If yes, indicate type'],
            'remarks'         => ['textarea', 'Remarks'],
        ],
    ];
    return $specs[$section] ?? [];
}

/* =====================================================================
 * TOOTH CHART
 * ================================================================== */
function mh_dental_teeth()
{
    return [
        'permanent' => [
            'upper' => [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28],
            'lower' => [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38],
        ],
        'primary' => [
            'upper' => [55, 54, 53, 52, 51, 61, 62, 63, 64, 65],
            'lower' => [85, 84, 83, 82, 81, 71, 72, 73, 74, 75],
        ],
    ];
}

function mh_dental_all_teeth()
{
    $out = [];
    foreach (mh_dental_teeth() as $set) { foreach ($set as $row) { foreach ($row as $t) { $out[] = $t; } } }
    return $out;
}

/** key => [label, permanent code, primary code, colour] - the official legend. */
function mh_dental_tooth_conditions()
{
    return [
        'carious'      => ['Carious',          'C',   'c',   '#dc2626'],
        'gic_restored' => ['GIC restored',     'GIC', 'gic', '#0d9488'],
        'gic_sealed'   => ['GIC sealed',       'N/A', 's',   '#2563eb'],
        'composite'    => ['Composite',        'Co',  'co',  '#0ea5e9'],
        'amalgam'      => ['Amalgam',          'Am',  'am',  '#64748b'],
        'crown'        => ['Crowns / Veneers', 'C/V', 'ss',  '#ca8a04'],
        'denture'      => ['Denture',          'D',   'd',   '#7c3aed'],
        'extraction'   => ['For extraction',   'X',   'x',   '#111827'],
    ];
}

function mh_dental_tooth_statuses()
{
    return [
        'sound'         => ['Sound tooth',   "\u{2713}", "\u{2713}"],
        'missing'       => ['Missing',       'M',  'm'],
        'root_fragment' => ['Root fragment', 'RF', 'rf'],
        'unerupted'     => ['Unerupted',     'Un', 'un'],
    ];
}

/** Maps a handwritten code (any case) to a condition / status key. */
function mh_dental_code_to_condition($code)
{
    $c = strtolower(trim((string) $code));
    $map = ['c' => 'carious', 'gic' => 'gic_restored', 'n/a' => 'gic_sealed', 's' => 'gic_sealed', 'co' => 'composite',
            'am' => 'amalgam', 'c/v' => 'crown', 'ss' => 'crown', 'd' => 'denture', 'x' => 'extraction'];
    return $map[$c] ?? '';
}
function mh_dental_code_to_status($code)
{
    $c = strtolower(trim((string) $code));
    if ($c === "\u{2713}" || $c === 'v' || $c === 'check' || $c === 'sound' || $c === 'ok') { return 'sound'; }
    $map = ['m' => 'missing', 'rf' => 'root_fragment', 'un' => 'unerupted'];
    return $map[$c] ?? '';
}

/* =====================================================================
 * TREATMENT CODES (Treatment Record legend)
 * ================================================================== */
function mh_dental_routine_codes()
{
    return ['OE' => 'Oral Examination', 'RA' => 'Risk Assessment', 'OP' => 'Oral Prophylaxis',
            'FV' => 'Fluoride Varnish application', 'OHC' => 'Oral Health Counselling'];
}
function mh_dental_other_codes()
{
    return ['ART' => 'GIC ART', 'S' => 'GIC Sealants', 'Exo' => 'Tooth Extraction', 'RSP' => 'Root Surface Protection'];
}

/* =====================================================================
 * CARIES RISK ASSESSMENT
 * ================================================================== */
/** 'child' = ages 5 and below, 'adult' = ages 6 and above. */
function mh_dental_age_group($ageYears)
{
    return ((int) $ageYears <= 5) ? 'child' : 'adult';
}

/**
 * @return array<string,array{title:string,items:array<int,array{q:string,text:string,high:string}>}>
 * 'high' = the answer (yes/no) that counts as a HIGH-risk answer.
 */
function mh_dental_caries_questions($group)
{
    $isChild = ($group === 'child');
    $who = $isChild ? 'Child' : 'Patient';

    $a = [
        ["$who has cavitated lesions", 'yes'],
        ["$who has non cavitated lesions (white spots, interproximal caries, etc.)", 'yes'],
        ["$who has missing teeth due to dental caries", 'yes'],
        ["$who has restorations present", 'yes'],
    ];

    if ($isChild) {
        $b = [
            ["Child's parent / guardian's health literacy (low)", 'yes'],
            ['Child is living in poverty or family is part of a government assistance program (4Ps, etc.)', 'yes'],
            ['Child routinely visits the dentist at least twice a year', 'no'],
            ['Child is exclusively breastfeeding or was exclusively breastfed', 'no'],
            ['Frequent exposure (>3 x a day) to sugar rich food, vitamins or beverages in between meals', 'yes'],
            ['Has a disability that affects day to day normal living, including birth defects such as cleft lip and palate', 'yes'],
            ['In unserved and underserved location', 'yes'],
            ['Presence of intra oral appliance', 'yes'],
            ['Presence of unusual tooth morphology that can affect oral hygiene or dento-enamel defects', 'yes'],
            ['Presence of visible oral debris / dental plaque', 'yes'],
            ['Presence of defective restorations', 'yes'],
            ['Taking / undergoing hyposalivatory medications / treatment (chemotherapy, etc.)', 'yes'],
        ];
        $c = [
            ['Brushes with fluoride toothpaste at least twice a day', 'no'],
            ['Routine topical fluoride varnish application by dental professional', 'no'],
            ['Pit and fissure sealant application to all permanent molars', 'no'],
            ['Dental home (established patient record, regular oral health services in a dental facility)', 'no'],
        ];
    } else {
        $b = [
            ['Patient has low health literacy', 'yes'],
            ['Patient is living in poverty or family is part of a government assistance program (4Ps, etc.)', 'yes'],
            ['Patient routinely visits the dentist at least twice a year', 'no'],
            ["Patient's water intake is at least 6 glasses a day", 'no'],
            ['Frequent exposure (>3 x a day) to sugar rich food, vitamins or beverages in between meals', 'yes'],
            ['Has a disability that affects day to day normal living, including birth defects such as cleft lip and palate', 'yes'],
            ['In unserved and underserved location', 'yes'],
            ['Presence of dentures / dental appliance', 'yes'],
            ['Presence of unusual tooth morphology that can affect oral hygiene or dento-enamel defects', 'yes'],
            ['Presence of visible oral debris / dental plaque / calcular deposits', 'yes'],
            ['Presence of defective restorations', 'yes'],
            ['Taking / undergoing hyposalivatory medications / treatment (chemotherapy, etc.)', 'yes'],
            ['Has exposed roots', 'yes'],
            ['Is a cigarette / vape smoker', 'yes'],
            ['Uses illegal drugs (methamphetamine, cocaine, etc.)', 'yes'],
        ];
        $c = [
            ['Brushes with fluoride toothpaste at least twice a day', 'no'],
            ['Routine topical fluoride varnish application by dental professional', 'no'],
            ['Pit and fissure sealant application to all permanent molars', 'no'],
            ['Has had oral prophylaxis at least twice a year', 'no'],
            ['Has had root surface protection application', 'no'],
            ['Dental home (established patient record, regular oral health services in a dental facility)', 'no'],
        ];
    }

    $build = function ($letter, $title, array $rows) {
        $items = [];
        foreach ($rows as $i => $r) { $items[] = ['q' => $letter . ($i + 1), 'text' => $r[0], 'high' => $r[1]]; }
        return ['title' => $title, 'items' => $items];
    };
    return [
        'A' => $build('A', 'A. Disease factors', $a),
        'B' => $build('B', 'B. Socio-economic, biological, medical and behavioral factors', $b),
        'C' => $build('C', 'C. Protective factors', $c),
    ];
}

/** Section rule: HIGH when high-risk answers are at least as many as low-risk ones (and there is at least one). */
function mh_dental_section_result($high, $low)
{
    if ($high + $low === 0) { return null; }
    return ($high >= $low && $high > 0) ? 'high' : 'low';
}

/**
 * Overall rule from the form: HIGH when high-risk factors exceed low-risk factors by at least one OR the two are equal;
 * LOW when low-risk factors exceed high-risk factors by five or more; anything in between needs clinical judgment.
 */
function mh_dental_overall_result($high, $low)
{
    if ($high + $low === 0) { return null; }
    if ($high >= $low) { return 'high'; }
    return ($low - $high >= 5) ? 'low' : 'moderate';
}

/** @param array<string,string> $answers  q => 'yes'|'no' */
function mh_dental_caries_evaluate($group, array $answers)
{
    $sections = []; $H = 0; $L = 0;
    foreach (mh_dental_caries_questions($group) as $letter => $sec) {
        $h = 0; $l = 0;
        foreach ($sec['items'] as $it) {
            $a = $answers[$it['q']] ?? '';
            if ($a !== 'yes' && $a !== 'no') { continue; }
            if ($a === $it['high']) { $h++; } else { $l++; }
        }
        $sections[$letter] = ['high' => $h, 'low' => $l, 'result' => mh_dental_section_result($h, $l)];
        $H += $h; $L += $l;
    }
    return ['sections' => $sections, 'high' => $H, 'low' => $L, 'overall' => mh_dental_overall_result($H, $L)];
}

/* =====================================================================
 * JSON + VALIDATION HELPERS
 * ================================================================== */
function mh_dental_json_decode($s)
{
    if (!is_string($s) || $s === '') { return []; }
    $d = json_decode($s, true);
    return is_array($d) ? $d : [];
}

function mh_dental_str($v, $max = 255)
{
    return mb_substr(trim((string) $v), 0, $max);
}

function mh_dental_yn($v)
{
    $v = strtolower(trim((string) $v));
    if (in_array($v, ['y', 'yes', '1', 'true'], true)) { return 'y'; }
    if (in_array($v, ['n', 'no', '0', 'false'], true)) { return 'n'; }
    return '';
}

/** Cleans one value according to its spec. */
function mh_dental_clean_value(array $spec, $raw)
{
    switch ($spec[0]) {
        case 'yn':       return mh_dental_yn($raw);
        case 'check':    return ($raw === '1' || $raw === 1 || $raw === true || strtolower((string) $raw) === 'yes') ? '1' : '';
        case 'number':   return mb_substr(preg_replace('/[^0-9]/', '', (string) $raw), 0, 3);
        case 'date':     $d = (string) $raw; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
        case 'select':   $v = (string) $raw; return array_key_exists($v, $spec[2] ?? []) ? $v : '';
        case 'textarea': return mh_dental_str($raw, 1000);
        default:         return mh_dental_str($raw, 255);
    }
}

function mh_dental_clean_section($section, $src)
{
    $out = [];
    $src = is_array($src) ? $src : [];
    foreach (mh_dental_specs($section) as $key => $spec) {
        $out[$key] = mh_dental_clean_value($spec, $src[$key] ?? '');
    }
    return $out;
}

function mh_dental_clean_medical($src)
{
    $src = is_array($src) ? $src : [];
    $q = []; $d = [];
    foreach (mh_dental_medical_questions() as $k => $def) {
        $q[$k] = mh_dental_yn($src['q'][$k] ?? '');
        $d[$k] = ($def[1] !== null) ? mh_dental_str($src['detail'][$k] ?? '', 255) : '';
    }
    $allergy = [];
    foreach (mh_dental_allergies() as $k => $_l) { $allergy[$k] = !empty($src['allergy'][$k]) ? '1' : ''; }
    $women = [];
    foreach (mh_dental_women_questions() as $k => $_l) { $women[$k] = mh_dental_yn($src['women'][$k] ?? ''); }
    $cond = [];
    foreach (mh_dental_conditions() as $k => $_l) { $cond[$k] = !empty($src['cond'][$k]) ? '1' : ''; }
    return [
        'q' => $q, 'detail' => $d, 'allergy' => $allergy,
        'allergy_other' => mh_dental_str($src['allergy_other'] ?? '', 150),
        'women' => $women, 'pregnant_months' => mh_dental_str($src['pregnant_months'] ?? '', 20),
        'cond' => $cond, 'cond_other' => mh_dental_str($src['cond_other'] ?? '', 150),
    ];
}

function mh_dental_clean_sectors($src)
{
    $src = is_array($src) ? $src : [];
    $out = [];
    foreach (mh_dental_sectors() as $k => $_l) { $out[$k] = !empty($src[$k]) ? '1' : ''; }
    return $out;
}

/** @return array<string,array{c:string,s:string}> only valid teeth with a valid condition and/or status */
function mh_dental_clean_odontogram($json)
{
    $in = mh_dental_json_decode((string) $json);
    $valid = array_flip(array_map('strval', mh_dental_all_teeth()));
    $conds = mh_dental_tooth_conditions(); $stats = mh_dental_tooth_statuses();
    $out = [];
    foreach ($in as $tooth => $v) {
        $tooth = (string) $tooth;
        if (!isset($valid[$tooth]) || !is_array($v)) { continue; }
        $c = (string) ($v['c'] ?? ''); $s = (string) ($v['s'] ?? '');
        if (!isset($conds[$c])) { $c = ''; }
        if (!isset($stats[$s])) { $s = ''; }
        if ($c !== '' || $s !== '') { $out[$tooth] = ['c' => $c, 's' => $s]; }
    }
    return $out;
}

/** @return array<string,string> q => yes|no, restricted to the questions of $group */
function mh_dental_clean_caries($group, $src)
{
    $src = is_array($src) ? $src : [];
    $out = [];
    foreach (mh_dental_caries_questions($group) as $sec) {
        foreach ($sec['items'] as $it) {
            $a = strtolower((string) ($src[$it['q']] ?? ''));
            if ($a === 'yes' || $a === 'no') { $out[$it['q']] = $a; }
        }
    }
    return $out;
}

/** Short alert chips from a cleaned medical-history array (sensitive diagnoses are not spelled out). */
function mh_dental_medical_alerts(array $mh)
{
    $alerts = [];
    $cond = $mh['cond'] ?? [];
    $names = mh_dental_conditions();
    foreach (['high_bp', 'diabetes', 'heart_disease', 'heart_attack', 'heart_murmur', 'heart_surgery', 'stroke', 'bleeding', 'asthma',
              'epilepsy', 'fainting', 'kidney', 'hepatitis', 'tuberculosis', 'cancer'] as $k) {
        if (!empty($cond[$k])) { $alerts[] = $names[$k]; }
    }
    if (!empty($cond['hiv'])) { $alerts[] = 'Infectious disease flagged - see medical history'; }
    foreach (mh_dental_allergies() as $k => $label) {
        if (!empty($mh['allergy'][$k])) { $alerts[] = 'Allergy: ' . $label; }
    }
    if (!empty($mh['allergy_other'])) { $alerts[] = 'Allergy: ' . $mh['allergy_other']; }
    if (($mh['women']['pregnant'] ?? '') === 'y') {
        $alerts[] = 'Pregnant' . (!empty($mh['pregnant_months']) ? ' (' . $mh['pregnant_months'] . ' mo)' : '');
    }
    if (($mh['q']['medicine'] ?? '') === 'y') { $alerts[] = 'Taking medicine'; }
    return $alerts;
}

/* =====================================================================
 * FORM FIELD RENDERING
 * ================================================================== */
function mh_dental_field($name, array $spec, $value, $disabled = false, $showWhen = '')
{
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $label = $spec[1];
    $dis = $disabled ? ' disabled' : '';
    $when = $showWhen !== '' ? ' data-dn-when="' . $e($showWhen) . '"' : '';
    $wide = in_array($spec[0], ['textarea'], true) ? ' du-wide' : '';
    $out = '<div class="du-field' . $wide . '"' . $when . '>';

    if ($spec[0] === 'check') {
        $out .= '<label class="du-check"><input type="checkbox" name="' . $e($name) . '" value="1"' . ($value === '1' ? ' checked' : '') . $dis . '><span>' . $e($label) . '</span></label></div>';
        return $out;
    }
    $out .= '<span class="du-label">' . $e($label) . '</span>';
    switch ($spec[0]) {
        case 'yn':
            $out .= '<div class="du-yn">'
                  . '<label><input type="radio" name="' . $e($name) . '" value="y"' . ($value === 'y' ? ' checked' : '') . $dis . '><span>Yes</span></label>'
                  . '<label><input type="radio" name="' . $e($name) . '" value="n"' . ($value === 'n' ? ' checked' : '') . $dis . '><span>No</span></label></div>';
            break;
        case 'select':
            $out .= '<select name="' . $e($name) . '"' . $dis . '><option value="">-- Select --</option>';
            foreach ($spec[2] as $k => $l) { $out .= '<option value="' . $e($k) . '"' . ((string) $value === (string) $k ? ' selected' : '') . '>' . $e($l) . '</option>'; }
            $out .= '</select>';
            break;
        case 'textarea':
            $out .= '<textarea name="' . $e($name) . '" rows="3" maxlength="1000"' . $dis . '>' . $e($value) . '</textarea>';
            break;
        case 'number':
            $out .= '<input type="number" name="' . $e($name) . '" min="0" max="999" value="' . $e($value) . '"' . $dis . '>';
            break;
        case 'date':
            $out .= '<input type="date" name="' . $e($name) . '" value="' . $e($value) . '"' . $dis . '>';
            break;
        default:
            $out .= '<input type="text" name="' . $e($name) . '" maxlength="255" value="' . $e($value) . '" autocomplete="off"' . $dis . '>';
    }
    return $out . '</div>';
}

/* =====================================================================
 * OCR KEY CATALOG (what the scanner may return; same names as the form inputs, dot notation)
 * ================================================================== */
/** @return array<string,array{0:string,1:string,2?:array}> key => spec */
function mh_dental_ocr_catalog()
{
    $c = [];
    foreach (['last_name' => 'Last name', 'first_name' => 'First name', 'middle_initial' => 'Middle initial', 'birthdate' => 'Date of birth',
              'age' => 'Age in years', 'sex' => 'Sex (Male/Female)', 'barangay' => 'Barangay', 'address' => 'Address', 'contact_no' => 'Contact number'] as $k => $l) {
        $c['patient.' . $k] = [$k === 'birthdate' ? 'date' : 'text', $l];
    }
    foreach (mh_dental_specs('extra') as $k => $s) { $c['extra.' . $k] = $s; }
    foreach (mh_dental_sectors() as $k => $l) { $c['sectors.' . $k] = ['check', $l]; }
    foreach (mh_dental_medical_questions() as $k => $def) {
        $c['mh.q.' . $k] = ['yn', $def[0]];
        if ($def[1] !== null) { $c['mh.detail.' . $k] = ['text', $def[1] . ' (for: ' . $def[0] . ')']; }
    }
    foreach (mh_dental_allergies() as $k => $l) { $c['mh.allergy.' . $k] = ['check', 'Allergic to: ' . $l]; }
    $c['mh.allergy_other'] = ['text', 'Allergic to: Others (text)'];
    foreach (mh_dental_women_questions() as $k => $l) { $c['mh.women.' . $k] = ['yn', $l . ' (women only)']; }
    $c['mh.pregnant_months'] = ['text', 'Pregnant - indicate months'];
    foreach (mh_dental_conditions() as $k => $l) { $c['mh.cond.' . $k] = ['check', 'Has/had: ' . $l]; }
    $c['mh.cond_other'] = ['text', 'Has/had: Others (text)'];
    foreach (['dental_history', 'clinical', 'oral_health'] as $sec) {
        foreach (mh_dental_specs($sec) as $k => $s) { $c[$sec . '.' . $k] = $s; }
    }
    return $c;
}

} // MH_LOADED_INCLUDES_DENTAL_HELPER
