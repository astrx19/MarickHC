<?php
/**
 * Disease statistics engine (aggregate counts only - no patient names ever leave this file).
 *
 * Cases come from consultations (diagnosis, else chief complaints) and child consultation logs.
 * A "tracked condition" matches a case when one of its keywords appears as a whole word.
 * Spike rule: this week (last 7 days) has at least `min_cases` AND at least `spike_factor` x the average
 * of the previous 4 weeks (or the previous 4 weeks had none). "Rising": 2+ cases and 25% above that average.
 */

const MH_DISEASE_WEEKS = 12;

function mh_disease_ensure_tables(mysqli $conn)
{
    $conn->query("CREATE TABLE IF NOT EXISTS tracked_conditions (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        keywords VARCHAR(255) NOT NULL,
        min_cases INT NOT NULL DEFAULT 3,
        spike_factor DECIMAL(3,1) NOT NULL DEFAULT 2.0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_condition_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS diagnosis_normalization_cache (
        raw_hash VARCHAR(64) NOT NULL,
        raw_text VARCHAR(255) NOT NULL,
        standard_name VARCHAR(150) NOT NULL,
        category VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (raw_hash),
        KEY idx_raw_text (raw_text(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS disease_synonyms_map (
        id INT NOT NULL AUTO_INCREMENT,
        canonical_name VARCHAR(150) NOT NULL,
        synonyms TEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_canonical (canonical_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $res = $conn->query("SELECT COUNT(*) AS c FROM tracked_conditions");
    if ($res && (int) $res->fetch_assoc()['c'] === 0) {
        $seed = [
            ['Upper Respiratory Tract Infection', 'uri, urti, upper respiratory, sipon, ubo, cold, colds, pharyngitis, sore throat, tonsillitis, cough and colds', 3],
            ['Influenza / Flu',           'flu, influenza, ili, influenza-like, trangkaso, lagnat at trangkaso', 3],
            ['Diarrhea / Gastroenteritis', 'diarrhea, diarrhoea, gastroenteritis, lbm, agw, age, pagtatae, nagtatae', 3],
            ['Hypertension',              'hypertension, htn, altapresyon, high blood, highblood', 5],
            ['Oral Cavity Disease',       'dental, caries, toothache, tooth decay, ngipin, bunot, gingivitis, oral cavity, sakit ng ngipin', 3],
            ['UTI',                       'uti, urinary tract infection, balisawsaw, dysuria', 3],
            ['Dengue',                    'dengue, dhf, dengue fever', 2],
            ['Bronchial Asthma',          'asthma, hika, bronchospasm, wheezing', 3],
            ['Pneumonia',                 'pneumonia, pulmonia, cap', 2],
            ['Tuberculosis',              'tuberculosis, ptb, tb, primary complex', 2],
            ['Chickenpox',                'chickenpox, chicken pox, varicella, bulutong', 2],
            ['Skin infection / Scabies',  'scabies, impetigo, skin infection, galis, kati-kati, dermatitis', 3],
            ['COVID-19',                  'covid, covid-19, sars-cov-2', 2],
        ];
        $st = $conn->prepare("INSERT IGNORE INTO tracked_conditions (name, keywords, min_cases) VALUES (?, ?, ?)");
        foreach ($seed as [$n, $k, $m]) { $st->bind_param('ssi', $n, $k, $m); $st->execute(); }
        $st->close();
    }

    $resSyn = $conn->query("SELECT COUNT(*) AS c FROM disease_synonyms_map");
    if ($resSyn && (int) $resSyn->fetch_assoc()['c'] === 0) {
        $synSeed = [
            ['Upper Respiratory Tract Infection', 'trangkaso, flu, sipon at ubo, ubo at sipon, colds, cough, pharyngitis, sore throat, tonsillitis, masakit ang lalamunan, uri, urti'],
            ['Influenza-like Illness', 'influenza, flu, ili, trangkaso, lagnat at trangkaso'],
            ['Acute Gastroenteritis', 'diarrhea, gastroenteritis, pagtatae, nagtatae, lbm, pagsusuka at pagtatae, age'],
            ['Hypertension', 'altapresyon, alta presyon, high blood, highblood, htn'],
            ['Oral Cavity Disease', 'sakit ng ngipin, dental caries, bunot, toothache, tooth decay, dental, ngipin, masakit ang ngipin'],
            ['Urinary Tract Infection', 'uti, balisawsaw, masakit umihi, urinary tract infection, dysuria'],
            ['Dengue Fever', 'dengue, dhf, dengue fever'],
            ['Bronchial Asthma', 'asthma, hika, bronchospasm, wheezing'],
            ['Pneumonia', 'pulmonia, pneumonia, cap'],
            ['Pulmonary Tuberculosis', 'tuberculosis, ptb, tb, primary complex'],
            ['Chickenpox', 'chickenpox, chicken pox, varicella, bulutong, bulutong tubig'],
            ['Skin infection / Scabies', 'scabies, impetigo, galis, galis aso, kati-kati sa balat, dermatitis'],
            ['COVID-19', 'covid, covid-19, sars-cov-2'],
        ];
        $st = $conn->prepare("INSERT IGNORE INTO disease_synonyms_map (canonical_name, synonyms) VALUES (?, ?)");
        foreach ($synSeed as [$cn, $syns]) {
            $st->bind_param('ss', $cn, $syns);
            $st->execute();
        }
        $st->close();
    }
}

/**
 * Validates whether a diagnosis or complaint string is a clinically meaningful entry.
 * Filters out test entries ('test', 'qwe', 'asd'), gibberish key-smashing ('skhdajgf...'),
 * conversational filler ('wala', 'checkup', 'ok'), and short non-medical strings (< 3 chars).
 *
 * @param string $text Raw input string from diagnosis or complaint field
 * @return bool True if valid clinical text; false if garbage / test data
 */
function mh_disease_is_valid_diagnosis(string $text): bool
{
    $clean = trim($text);
    if ($clean === '') {
        return false;
    }

    // Strip out common wrapping quotes and symbols for analysis
    $clean = trim($clean, " \t\n\r\0\x0B\"'.,;:-_()[]{}<>!?");
    if ($clean === '') {
        return false;
    }

    // Must contain at least one unicode letter
    if (!preg_match('/\p{L}/u', $clean)) {
        return false;
    }

    $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $clean));
    $normalized = trim(preg_replace('/\s+/u', ' ', $normalized));

    // Whitelist legitimate 2-letter medical abbreviations
    $valid2Letter = ['tb', 'dm', 'gi', 'gu', 'pt', 'dx', 'rx'];
    if (mb_strlen($normalized) === 2) {
        return in_array($normalized, $valid2Letter, true);
    }

    // Ignore short strings (< 3 characters)
    if (mb_strlen($normalized) < 3) {
        return false;
    }

    // Blacklist of non-medical, test, placeholder, or conversational filler terms
    static $blacklist = [
        'test', 'testing', 'tester', 'test patient', 'test only', 'test1', 'test2', 'tested',
        'qwe', 'qwer', 'qwert', 'qwerty', 'qweqwe', 'qwertyuiop',
        'asd', 'asdf', 'asdfg', 'asdfgh', 'asdasd', 'asdfghj', 'asdfghjkl',
        'zxc', 'zxcv', 'zxcvb', 'zxcvbn', 'zxczxc',
        'abc', 'abcd', 'abcde', '123', '1234', '12345',
        'dummy', 'sample', 'sample diagnosis', 'temp', 'temporary',
        'none', 'na', 'n/a', 'nil', 'null', 'no illness', 'no complaints', 'healthy', 'normal',
        'wala', 'walang sakit', 'walang reklamo', 'ok', 'okay', 'all good', 'fine', 'good',
        'checkup', 'check up', 'check-up', 'pacheckup', 'pacheck', 'pacheck up',
        'for checkup', 'for check up', 'for consult', 'for consultation',
        'routine', 'routine checkup', 'routine check up', 'general checkup', 'general consultation',
        'follow up', 'follow-up', 'followup', 'ff up', 'ff-up',
        'consult', 'consultation', 'inquire', 'inquiry', 'nagpunta lang', 'nagtanong lang',
        'blank', 'nothing', 'not recorded', 'unknown', 'others', 'other', 'na',
        'broken hearted', 'broken heart', 'brokenhearted', 'heart broken',
        'no condom included', 'no condom', 'condom', 'condom included',
    ];

    if (in_array($normalized, $blacklist, true)) {
        return false;
    }

    // Dynamic stop-words configured in System Settings
    if (function_exists('mh_setting')) {
        $customStopWords = (string) mh_setting('disease_stop_words', '');
        if ($customStopWords !== '') {
            $swList = explode(',', mb_strtolower($customStopWords));
            foreach ($swList as $sw) {
                $sw = trim($sw);
                if ($sw !== '' && ($normalized === $sw || mb_strpos($normalized, $sw) !== false)) {
                    return false;
                }
            }
        }
    }

    // Substring check for non-medical phrases (e.g. "broken hearted", "no condom included")
    static $phraseBlacklist = ['broken hearted', 'brokenhearted', 'no condom included', 'test patient', 'sample diagnosis'];
    foreach ($phraseBlacklist as $pb) {
        if (mb_strpos($normalized, $pb) !== false) {
            return false;
        }
    }

    // Repeated characters heuristic (e.g. 'aaaa', 'zzzzz', 'qqqq')
    if (preg_match('/(.)\1{2,}/u', $normalized)) {
        return false;
    }

    // Key-smashing / consonant cluster checks (treating 'y' as vowel)
    if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/i', $normalized)) {
        return false;
    }

    // Analyze individual words for unnatural character distributions
    $words = explode(' ', $normalized);
    $validConsonantAbbr = ['htn', 'ptb', 'lbm', 'agw', 'dhf', 'bph', 'copd', 'gerd', 'pcos', 'ckd', 'sle', 'tb', 'cap', 'uri', 'urti'];
    foreach ($words as $w) {
        $wLen = mb_strlen($w);
        if ($wLen >= 3) {
            // Unnatural 4-consonant cluster (unless standard medical prefix/root)
            if (preg_match('/[bcdfghjklmnpqrstvwxz]{4,}/i', $w)) {
                if (!preg_match('/(?:phth|diphth|rhythm|bronch|scler|strep|splen|sphing|sphyg|lymph|thromb|psor|asthm|sthm|isch|ophthal|laryng|pharyng|gangr|arthr)/i', $w)) {
                    return false;
                }
            }
            // Length >= 6 with <= 1 vowel is almost always keyboard smashing (e.g., 'skhdajgf')
            preg_match_all('/[aeiouy]/i', $w, $vm);
            $vowels = count($vm[0]);
            if ($wLen >= 6 && $vowels <= 1) {
                return false;
            }
            // Length >= 4 with 0 vowels
            if ($wLen >= 4 && $vowels === 0 && !in_array($w, $validConsonantAbbr, true)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Built-in high-accuracy clinical dictionary for Filipino / Tagalog medical terms,
 * colloquial symptoms, abbreviations and synonyms.
 *
 * @return array<string,array{name:string,category:string,keywords:string}>
 */
function mh_disease_clinical_dictionary(): array
{
    static $dict = null;
    if ($dict !== null) {
        return $dict;
    }

    $dict = [
        // Upper Respiratory Tract
        'trangkaso'         => ['name' => 'Influenza-like Illness', 'category' => 'Influenza / Flu', 'keywords' => 'flu, influenza, ili, trangkaso'],
        'lagnat at trangkaso' => ['name' => 'Influenza-like Illness', 'category' => 'Influenza / Flu', 'keywords' => 'flu, influenza, ili, trangkaso'],
        'sipon at ubo'      => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, cough, colds, sipon, ubo'],
        'ubo at sipon'      => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, cough, colds, sipon, ubo'],
        'ubo\'t sipon'      => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, cough, colds, sipon, ubo'],
        'sipon'             => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, colds, sipon'],
        'ubo'               => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, cough, ubo'],
        'colds'             => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, colds'],
        'cold and cough'    => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, cough, colds'],
        'cough and colds'   => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, cough, colds'],
        'urti'              => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, upper respiratory'],
        'uri'               => ['name' => 'Upper Respiratory Tract Infection', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'urti, uri, upper respiratory'],
        'sore throat'       => ['name' => 'Pharyngitis / Tonsillitis', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'pharyngitis, tonsillitis, sore throat'],
        'masakit ang lalamunan' => ['name' => 'Pharyngitis / Tonsillitis', 'category' => 'Upper Respiratory Tract Infection', 'keywords' => 'pharyngitis, tonsillitis, sore throat'],

        // Gastrointestinal
        'pagtatae'          => ['name' => 'Acute Gastroenteritis', 'category' => 'Diarrhea / Gastroenteritis', 'keywords' => 'gastroenteritis, diarrhea, lbm, pagtatae'],
        'nagtatae'          => ['name' => 'Acute Gastroenteritis', 'category' => 'Diarrhea / Gastroenteritis', 'keywords' => 'gastroenteritis, diarrhea, lbm, pagtatae'],
        'lbm'               => ['name' => 'Acute Gastroenteritis', 'category' => 'Diarrhea / Gastroenteritis', 'keywords' => 'gastroenteritis, diarrhea, lbm'],
        'pagsusuka at pagtatae' => ['name' => 'Acute Gastroenteritis', 'category' => 'Diarrhea / Gastroenteritis', 'keywords' => 'gastroenteritis, diarrhea, lbm, age'],
        'age'               => ['name' => 'Acute Gastroenteritis', 'category' => 'Diarrhea / Gastroenteritis', 'keywords' => 'gastroenteritis, diarrhea, lbm, age'],

        // Cardiovascular
        'altapresyon'       => ['name' => 'Hypertension', 'category' => 'Hypertension', 'keywords' => 'hypertension, htn, altapresyon, high blood'],
        'alta presyon'      => ['name' => 'Hypertension', 'category' => 'Hypertension', 'keywords' => 'hypertension, htn, altapresyon, high blood'],
        'high blood'        => ['name' => 'Hypertension', 'category' => 'Hypertension', 'keywords' => 'hypertension, htn, high blood'],
        'highblood'         => ['name' => 'Hypertension', 'category' => 'Hypertension', 'keywords' => 'hypertension, htn, high blood'],
        'htn'               => ['name' => 'Hypertension', 'category' => 'Hypertension', 'keywords' => 'hypertension, htn'],

        // Dental / Oral Cavity
        'sakit ng ngipin'   => ['name' => 'Oral Cavity Disease / Dental Caries', 'category' => 'Oral Cavity Disease', 'keywords' => 'dental, caries, toothache, ngipin'],
        'masakit ang ngipin'=> ['name' => 'Oral Cavity Disease / Dental Caries', 'category' => 'Oral Cavity Disease', 'keywords' => 'dental, caries, toothache, ngipin'],
        'sirang ngipin'     => ['name' => 'Oral Cavity Disease / Dental Caries', 'category' => 'Oral Cavity Disease', 'keywords' => 'dental, caries, tooth decay, ngipin'],
        'toothache'         => ['name' => 'Oral Cavity Disease / Dental Caries', 'category' => 'Oral Cavity Disease', 'keywords' => 'dental, caries, toothache'],
        'tooth decay'       => ['name' => 'Oral Cavity Disease / Dental Caries', 'category' => 'Oral Cavity Disease', 'keywords' => 'dental, caries, tooth decay'],
        'dental caries'     => ['name' => 'Oral Cavity Disease / Dental Caries', 'category' => 'Oral Cavity Disease', 'keywords' => 'dental, caries, tooth decay'],
        'bunot ng ngipin'   => ['name' => 'Oral Cavity Disease / Extraction', 'category' => 'Oral Cavity Disease', 'keywords' => 'dental, caries, extraction, bunot'],

        // Urinary
        'balisawsaw'        => ['name' => 'Urinary Tract Infection', 'category' => 'UTI', 'keywords' => 'uti, urinary tract infection, balisawsaw'],
        'masakit umihi'     => ['name' => 'Urinary Tract Infection', 'category' => 'UTI', 'keywords' => 'uti, urinary tract infection, dysuria'],
        'uti'               => ['name' => 'Urinary Tract Infection', 'category' => 'UTI', 'keywords' => 'uti, urinary tract infection'],

        // Vector / Viral / Exanthem
        'dengue'            => ['name' => 'Dengue Fever', 'category' => 'Dengue', 'keywords' => 'dengue, dhf'],
        'bulutong'          => ['name' => 'Chickenpox', 'category' => 'Chickenpox', 'keywords' => 'chickenpox, varicella, bulutong'],
        'bulutong tubig'    => ['name' => 'Chickenpox', 'category' => 'Chickenpox', 'keywords' => 'chickenpox, varicella, bulutong'],
        'tigdas'            => ['name' => 'Measles', 'category' => 'Measles', 'keywords' => 'measles, rubeola, tigdas'],

        // Respiratory Lower
        'hika'              => ['name' => 'Bronchial Asthma', 'category' => 'Bronchial Asthma', 'keywords' => 'asthma, hika, bronchospasm'],
        'asthma'            => ['name' => 'Bronchial Asthma', 'category' => 'Bronchial Asthma', 'keywords' => 'asthma, hika, bronchospasm'],
        'pulmonia'          => ['name' => 'Pneumonia', 'category' => 'Pneumonia', 'keywords' => 'pneumonia, cap, pulmonia'],
        'tb'                => ['name' => 'Pulmonary Tuberculosis', 'category' => 'Tuberculosis', 'keywords' => 'tuberculosis, ptb, tb'],
        'ptb'               => ['name' => 'Pulmonary Tuberculosis', 'category' => 'Tuberculosis', 'keywords' => 'tuberculosis, ptb, tb'],

        // Dermatological
        'galis'             => ['name' => 'Skin Infection / Scabies', 'category' => 'Skin infection / Scabies', 'keywords' => 'scabies, impetigo, galis'],
        'galis aso'         => ['name' => 'Skin Infection / Scabies', 'category' => 'Skin infection / Scabies', 'keywords' => 'scabies, impetigo, galis'],
        'kati-kati sa balat'=> ['name' => 'Skin Infection / Dermatitis', 'category' => 'Skin infection / Scabies', 'keywords' => 'dermatitis, skin infection, kati-kati'],
    ];

    return $dict;
}

/**
 * Standardizes a clinical diagnosis string into an official medical category.
 * Resolves synonyms, Tagalog terms, abbreviations, and AI-assisted classification.
 *
 * @param string $rawText Raw diagnosis string
 * @param mysqli|null $conn Database handle for cache lookup/save
 * @return array{name:string,category:string,keywords:string,is_standard:bool}
 */
function mh_disease_standardize_diagnosis(string $rawText, ?mysqli $conn = null): array
{
    $clean = trim($rawText);
    if (!mh_disease_is_valid_diagnosis($clean)) {
        return ['name' => '', 'category' => '', 'keywords' => '', 'is_standard' => false];
    }

    $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $clean));
    $normalized = trim(preg_replace('/\s+/u', ' ', $normalized));

    // 0. Dynamic Database Synonym Mapping Rules (disease_synonyms_map)
    if ($conn instanceof mysqli) {
        $rules = mh_disease_synonyms_rules($conn, true);
        foreach ($rules as $r) {
            $canon = (string) $r['canonical_name'];
            $kwList = explode(',', mb_strtolower((string) $r['synonyms']));
            foreach ($kwList as $kw) {
                $kw = trim($kw);
                if ($kw !== '' && ($normalized === $kw || mb_strpos($normalized, $kw) !== false)) {
                    return [
                        'name'        => $canon,
                        'category'    => $canon,
                        'keywords'    => mb_strtolower($canon) . ', ' . $kw,
                        'is_standard' => true,
                    ];
                }
            }
        }
    }

    // 1. Fast Dictionary Lookup
    $dict = mh_disease_clinical_dictionary();
    if (isset($dict[$normalized])) {
        return array_merge($dict[$normalized], ['is_standard' => true]);
    }
    foreach ($dict as $key => $info) {
        if (mb_strpos($normalized, $key) !== false || mb_strpos($key, $normalized) !== false) {
            return array_merge($info, ['is_standard' => true]);
        }
    }

    // 2. Database Cache Lookup
    if ($conn instanceof mysqli) {
        $hash = hash('sha256', $normalized);
        $st = @$conn->prepare("SELECT standard_name, category FROM diagnosis_normalization_cache WHERE raw_hash = ? LIMIT 1");
        if ($st) {
            $st->bind_param('s', $hash);
            $st->execute();
            $res = $st->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $st->close();
                return [
                    'name'        => (string) $row['standard_name'],
                    'category'    => (string) $row['category'],
                    'keywords'    => mb_strtolower((string) $row['standard_name']) . ', ' . $normalized,
                    'is_standard' => true,
                ];
            }
            $st->close();
        }
    }

    // Fallback title-cased term
    $title = mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    return [
        'name'        => $title,
        'category'    => $title,
        'keywords'    => mb_strtolower($title) . ', ' . $normalized,
        'is_standard' => false,
    ];
}

/**
 * Augments raw diagnosis text with standardized medical keywords and Tagalog synonyms
 * so regex matching against tracked condition keywords succeeds automatically.
 *
 * @param string $text
 * @param mysqli|null $conn
 * @return string Normalized text string containing original + standardized keywords
 */
function mh_disease_normalize_text(string $text, ?mysqli $conn = null): string
{
    $std = mh_disease_standardize_diagnosis($text, $conn);
    if (!empty($std['keywords'])) {
        return $text . ' ' . $std['keywords'] . ' ' . $std['category'];
    }
    return $text;
}

/** @return array<int,array<string,mixed>> */
function mh_disease_conditions(mysqli $conn, bool $onlyActive = true)
{
    mh_disease_ensure_tables($conn);
    $sql = "SELECT id, name, keywords, min_cases, spike_factor, is_active FROM tracked_conditions"
         . ($onlyActive ? " WHERE is_active = 1" : "") . " ORDER BY name";
    $out = [];
    $res = $conn->query($sql);
    while ($res && ($r = $res->fetch_assoc())) { $out[] = $r; }
    return $out;
}

/**
 * Retrieves the AI & clinical synonym mapping rules.
 *
 * @param mysqli $conn
 * @param bool $onlyActive
 * @return array<int,array<string,mixed>>
 */
function mh_disease_synonyms_rules(mysqli $conn, bool $onlyActive = true): array
{
    static $cache = [];
    $cacheKey = $onlyActive ? 'active' : 'all';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    mh_disease_ensure_tables($conn);
    $sql = "SELECT id, canonical_name, synonyms, is_active FROM disease_synonyms_map"
         . ($onlyActive ? " WHERE is_active = 1" : "") . " ORDER BY canonical_name ASC";
    $out = [];
    $res = $conn->query($sql);
    while ($res && ($r = $res->fetch_assoc())) {
        $out[] = $r;
    }
    $cache[$cacheKey] = $out;
    return $out;
}

function mh_disease_regex(string $keywords)
{
    $parts = [];
    foreach (explode(',', mb_strtolower($keywords)) as $k) {
        $k = trim($k);
        if (mb_strlen($k) >= 2) { $parts[] = preg_quote($k, '/'); }
    }
    return $parts ? '/(?<![\p{L}\p{N}])(?:' . implode('|', $parts) . ')(?![\p{L}\p{N}])/iu' : null;
}

function mh_disease_age_group(?string $birthdate, string $onDate)
{
    if (!$birthdate || $birthdate === '0000-00-00') { return 'Unknown'; }
    try { $age = (new DateTime($birthdate))->diff(new DateTime($onDate))->y; } catch (Exception $e) { return 'Unknown'; }
    if ($age < 5)  { return '0-4'; }
    if ($age < 15) { return '5-14'; }
    if ($age < 25) { return '15-24'; }
    if ($age < 60) { return '25-59'; }
    return '60+';
}

/** One row per case: [date, src (department), pid, text, sex, birthdate, address_type]. */
function mh_disease_load_cases(mysqli $conn, string $from, string $to)
{
    $rows = [];
    $st = $conn->prepare("SELECT c.visit_date AS d, 'General Consultation' AS src, p.id AS pid,
                                 COALESCE(NULLIF(TRIM(c.diagnosis), ''), c.chief_complaints) AS txt,
                                 p.sex, p.birthdate, p.address_type
                          FROM consultations c JOIN patients p ON p.id = c.patient_id
                          WHERE c.visit_date BETWEEN ? AND ?");
    if ($st) {
        $st->bind_param('ss', $from, $to); $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
        $st->close();
    }
    // Dental visits: the dentist's diagnosis (column added by the dental module; skipped quietly if it does not exist yet).
    $st = @$conn->prepare("SELECT dr.visit_date AS d, 'Dental Clinic' AS src, p.id AS pid,
                                 COALESCE(NULLIF(TRIM(dr.diagnosis), ''), NULLIF(TRIM(dr.chief_complaint), '')) AS txt,
                                 p.sex, p.birthdate, p.address_type
                          FROM dental_records dr JOIN patients p ON p.id = dr.patient_id
                          WHERE dr.visit_date BETWEEN ? AND ? AND (TRIM(COALESCE(dr.diagnosis, '')) <> '' OR TRIM(COALESCE(dr.chief_complaint, '')) <> '')");
    if ($st) {
        $st->bind_param('ss', $from, $to); $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
        $st->close();
    }
    $st = $conn->prepare("SELECT cl.consultation_date AS d, 'Child Immunization' AS src, p.id AS pid,
                                 CONCAT_WS(' ', cl.chief_complaints, cl.remarks) AS txt,
                                 p.sex, p.birthdate, p.address_type
                          FROM child_consultation_logs cl
                          JOIN child_immunization_records r ON r.id = cl.child_record_id
                          JOIN patients p ON p.id = r.patient_id
                          WHERE cl.consultation_date BETWEEN ? AND ?");
    if ($st) {
        $st->bind_param('ss', $from, $to); $st->execute();
        $res = $st->get_result();
        while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
        $st->close();
    }
    return $rows;
}

/**
 * @return array{conditions:array,unclassified:array,week_labels:array,cases_30d:int,spikes:int,rising:int,today:string}
 */
function mh_disease_compute(mysqli $conn, ?string $today = null)
{
    $today = $today ?: date('Y-m-d');
    $W     = MH_DISEASE_WEEKS;
    $start = date('Y-m-d', strtotime($today . ' -' . ($W * 7 - 1) . ' days'));
    $cases = mh_disease_load_cases($conn, $start, $today);

    $conds = [];
    foreach (mh_disease_conditions($conn, true) as $c) {
        $c['regex']  = mh_disease_regex((string) $c['keywords']);
        $c['weekly'] = array_fill(0, $W, 0);
        $c['ages']   = ['0-4' => 0, '5-14' => 0, '15-24' => 0, '25-59' => 0, '60+' => 0, 'Unknown' => 0];
        $c['sex']    = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        $c['res']    = ['Resident' => 0, 'Outside' => 0];
        $c['cases_30d'] = 0;
        $conds[] = $c;
    }

    $unclassified = [];
    $cases_30d = 0;
    $todayTs = strtotime($today);
    foreach ($cases as $case) {
        $ts = strtotime((string) $case['d']);
        if (!$ts) { continue; }
        $days = (int) floor(($todayTs - $ts) / 86400);
        if ($days < 0 || $days >= $W * 7) { continue; }
        $bucket = ($W - 1) - intdiv($days, 7);
        $txt = trim((string) $case['txt']);

        // 1. Strict garbage / test data filtering (Requirement 1)
        if (!mh_disease_is_valid_diagnosis($txt)) {
            continue;
        }

        $recent = $days < 30;
        if ($recent) { $cases_30d++; }

        $matched = false;
        // Direct keyword match
        foreach ($conds as &$c) {
            if ($c['regex'] && preg_match($c['regex'], $txt)) {
                $matched = true;
                $c['weekly'][$bucket]++;
                if ($recent) {
                    $c['cases_30d']++;
                    $c['ages'][mh_disease_age_group($case['birthdate'], (string) $case['d'])]++;
                    $sx = strtolower((string) $case['sex']);
                    $c['sex'][$sx === 'male' ? 'Male' : ($sx === 'female' ? 'Female' : 'Other')]++;
                    $c['res'][mh_is_marick_resident($case['address_type'] ?? '') ? 'Resident' : 'Outside']++;
                }
            }
        }
        unset($c);

        // 2. Normalized AI / synonym match (Tagalog, typos, abbreviations)
        if (!$matched) {
            $augmentedTxt = mh_disease_normalize_text($txt, $conn);
            foreach ($conds as &$c) {
                if ($c['regex'] && preg_match($c['regex'], $augmentedTxt)) {
                    $matched = true;
                    $c['weekly'][$bucket]++;
                    if ($recent) {
                        $c['cases_30d']++;
                        $c['ages'][mh_disease_age_group($case['birthdate'], (string) $case['d'])]++;
                        $sx = strtolower((string) $case['sex']);
                        $c['sex'][$sx === 'male' ? 'Male' : ($sx === 'female' ? 'Female' : 'Other')]++;
                        $c['res'][mh_is_marick_resident($case['address_type'] ?? '') ? 'Resident' : 'Outside']++;
                    }
                    break;
                }
            }
            unset($c);
        }

        // 3. Track unclassified genuine clinical diagnoses only
        if (!$matched && $recent) {
            $std = mh_disease_standardize_diagnosis($txt, $conn);
            $key = $std['name'] ?: mb_substr(preg_replace('/\s+/u', ' ', mb_strtolower($txt)), 0, 60);
            $unclassified[$key] = ($unclassified[$key] ?? 0) + 1;
        }
    }

    $spikes = 0; $rising = 0;
    foreach ($conds as &$c) {
        $w = $c['weekly'];
        $c['cur']      = $w[$W - 1];
        $c['last']     = $w[$W - 2];
        $c['prev_avg'] = round(array_sum(array_slice($w, $W - 5, 4)) / 4, 1);
        $c['total']    = array_sum($w);
        $min = max(1, (int) $c['min_cases']); $factor = (float) $c['spike_factor'];
        $risingPct = function_exists('mh_setting') ? (float) mh_setting('disease_rising_threshold_pct', '25') : 25.0;
        $risingMultiplier = 1.0 + max(0.05, $risingPct / 100.0);
        $spikePct = function_exists('mh_setting') ? (float) mh_setting('disease_spike_threshold_pct', '100') : 100.0;
        $effectiveFactor = ($factor == 2.0 && $spikePct > 0) ? (1.0 + ($spikePct / 100.0)) : $factor;

        if ($c['cur'] >= $min && ($c['prev_avg'] == 0 || $c['cur'] >= $effectiveFactor * $c['prev_avg'])) { $c['status'] = 'spike'; $spikes++; }
        elseif ($c['cur'] >= 2 && ($c['prev_avg'] == 0 || $c['cur'] > $c['prev_avg'] * $risingMultiplier)) { $c['status'] = 'rising'; $rising++; }
        else { $c['status'] = 'normal'; }
        unset($c['regex']);
    }
    unset($c);
    usort($conds, function ($a, $b) {
        $rank = ['spike' => 0, 'rising' => 1, 'normal' => 2];
        return [$rank[$a['status']], -$a['cur'], -$a['total']] <=> [$rank[$b['status']], -$b['cur'], -$b['total']];
    });

    arsort($unclassified);
    $labels = [];
    for ($i = 0; $i < $W; $i++) { $labels[] = date('M j', strtotime($today . ' -' . (($W - 1 - $i) * 7 + 6) . ' days')); }

    return [
        'conditions'   => $conds,
        'unclassified' => array_slice($unclassified, 0, 10, true),
        'week_labels'  => $labels,
        'cases_30d'    => $cases_30d,
        'spikes'       => $spikes,
        'rising'       => $rising,
        'today'        => $today,
    ];
}

/** Tiny inline bar sparkline (no JS, no external library). */
function mh_disease_sparkline(array $values, string $color = '#4ca1af', int $w = 120, int $h = 30)
{
    $max = max(1, max($values)); $n = count($values); $bw = $w / $n;
    $out = '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="12-week trend">';
    foreach ($values as $i => $v) {
        $bh = $v > 0 ? max(2, ($v / $max) * ($h - 2)) : 1;
        $op = ($i === $n - 1) ? '1' : '0.55';
        $out .= '<rect x="' . round($i * $bw + 1, 1) . '" y="' . round($h - $bh, 1) . '" width="' . round($bw - 2, 1) . '" height="' . round($bh, 1) . '" rx="1.5" fill="' . $color . '" opacity="' . $op . '"/>';
    }
    return $out . '</svg>';
}


/**
 * Overview of a chosen period (Step 8): totals, change against the previous period of the same length,
 * and breakdowns by department, sex, age group, area, week, tracked condition and other diagnoses.
 * Counts only - no names leave this function.
 */
function mh_disease_overview(mysqli $conn, string $from, string $to)
{
    $d1 = new DateTime($from); $d2 = new DateTime($to);
    $days = (int) $d1->diff($d2)->days + 1;
    $prevTo   = (clone $d1)->modify('-1 day')->format('Y-m-d');
    $prevFrom = (clone $d1)->modify('-' . $days . ' days')->format('Y-m-d');

    $casesRaw = mh_disease_load_cases($conn, $from, $to);
    $prev_total = count(array_filter(mh_disease_load_cases($conn, $prevFrom, $prevTo), function ($r) {
        return mh_disease_is_valid_diagnosis(trim((string) ($r['txt'] ?? '')));
    }));

    $conds = [];
    foreach (mh_disease_conditions($conn) as $c) {
        $rx = mh_disease_regex((string) $c['keywords']);
        if ($rx) { $conds[] = ['name' => $c['name'], 'rx' => $rx]; }
    }

    $by_source = []; $by_sex = []; $by_age = ['0-4' => 0, '5-14' => 0, '15-24' => 0, '25-59' => 0, '60+' => 0, 'Unknown' => 0];
    $by_area = []; $by_week = []; $by_cond = []; $other = []; $patients = [];

    // every week of the period, even empty ones, so the trend has no gaps
    $cur = new DateTime(date('Y-m-d', strtotime('monday this week', strtotime($from))));
    $endW = date('Y-m-d', strtotime('monday this week', strtotime($to)));
    while ($cur->format('Y-m-d') <= $endW) { $by_week[$cur->format('Y-m-d')] = 0; $cur->modify('+7 days'); }

    $validCaseCount = 0;
    foreach ($casesRaw as $r) {
        $rawTxt = trim((string) ($r['txt'] ?? ''));
        // Filter out non-medical test entries completely from statistical tracking
        if (!mh_disease_is_valid_diagnosis($rawTxt)) {
            continue;
        }
        $validCaseCount++;

        $src = $r['src'] ?? 'Other';             $by_source[$src] = ($by_source[$src] ?? 0) + 1;
        $sex = trim((string) ($r['sex'] ?? '')); $sex = $sex !== '' ? $sex : 'Not recorded'; $by_sex[$sex] = ($by_sex[$sex] ?? 0) + 1;
        $ag  = mh_disease_age_group($r['birthdate'] ?? null, (string) $r['d']); $by_age[$ag] = ($by_age[$ag] ?? 0) + 1;
        $ar  = trim((string) ($r['address_type'] ?? '')); $ar = $ar !== '' ? $ar : 'Not recorded'; $by_area[$ar] = ($by_area[$ar] ?? 0) + 1;
        $wk  = date('Y-m-d', strtotime('monday this week', strtotime((string) $r['d']))); if (isset($by_week[$wk])) { $by_week[$wk]++; }
        if (!empty($r['pid'])) { $patients[(int) $r['pid']] = true; }

        $txt = mb_strtolower($rawTxt);
        $matched = false;
        foreach ($conds as $c) {
            if (preg_match($c['rx'], $txt)) {
                $by_cond[$c['name']] = ($by_cond[$c['name']] ?? 0) + 1;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $augmentedTxt = mh_disease_normalize_text($rawTxt, $conn);
            foreach ($conds as $c) {
                if (preg_match($c['rx'], $augmentedTxt)) {
                    $by_cond[$c['name']] = ($by_cond[$c['name']] ?? 0) + 1;
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched) {
            $std = mh_disease_standardize_diagnosis($rawTxt, $conn);
            $k = $std['name'] ?: mb_substr($txt, 0, 60);
            $other[$k] = ($other[$k] ?? 0) + 1;
        }
    }
    arsort($by_source); arsort($by_sex); arsort($by_area); arsort($by_cond); arsort($other);
    $total = $validCaseCount;
    return [
        'from' => $from, 'to' => $to, 'days' => $days, 'total' => $total, 'prev_total' => $prev_total,
        'change_pct' => $prev_total > 0 ? round(($total - $prev_total) / $prev_total * 100) : null,
        'patients' => count($patients), 'by_source' => $by_source, 'by_sex' => $by_sex, 'by_age' => $by_age,
        'by_area' => $by_area, 'by_week' => $by_week, 'by_cond' => array_slice($by_cond, 0, 10, true),
        'other' => array_slice($other, 0, 10, true),
    ];
}

/**
 * Returns available AI configuration (Groq or Gemini) from system_settings.
 *
 * @return array{provider:string,api_key:string,model:string}
 */
function mh_disease_get_ai_config(): array
{
    $groqKey = '';
    if (function_exists('mh_ocr_live_setting')) {
        $groqKey = trim((string) mh_ocr_live_setting('groq_api_key', ''));
    }
    if ($groqKey === '' && function_exists('mh_setting')) {
        $groqKey = trim((string) mh_setting('groq_api_key', ''));
    }

    $geminiKey = '';
    if (function_exists('mh_ocr_live_setting')) {
        $geminiKey = trim((string) mh_ocr_live_setting('gemini_api_key', ''));
    }
    if ($geminiKey === '' && function_exists('mh_setting')) {
        $geminiKey = trim((string) mh_setting('gemini_api_key', ''));
    }
    if ($geminiKey === '' && defined('GEMINI_API_KEY')) {
        $geminiKey = trim((string) GEMINI_API_KEY);
    }

    if ($groqKey !== '') {
        return [
            'provider' => 'groq',
            'api_key'  => $groqKey,
            'model'    => 'llama-3.3-70b-versatile',
        ];
    }

    if ($geminiKey !== '') {
        return [
            'provider' => 'gemini',
            'api_key'  => $geminiKey,
            'model'    => 'gemini-2.0-flash',
        ];
    }

    return [
        'provider' => 'none',
        'api_key'  => '',
        'model'    => '',
    ];
}

/**
 * Safe cURL caller for Groq or Gemini AI completions.
 *
 * @param string $prompt
 * @param array $config Output of mh_disease_get_ai_config()
 * @param int $timeout Max seconds (default 8)
 * @return string|null
 */
function mh_disease_ai_call(string $prompt, array $config, int $timeout = 8): ?string
{
    if (empty($config['api_key']) || empty($config['provider']) || !function_exists('curl_init')) {
        return null;
    }

    try {
        if ($config['provider'] === 'groq') {
            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            if (!$ch) { return null; }
            $payload = json_encode([
                'model'       => $config['model'] ?: 'llama-3.3-70b-versatile',
                'messages'    => [
                    ['role' => 'system', 'content' => 'You are an epidemiologist and health analytics AI for Barangay Sto. Domingo Marick Health Center in Cainta, Rizal, Philippines.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
                'max_tokens'  => 300,
            ]);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $config['api_key'],
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp && $code === 200) {
                $data = json_decode((string) $resp, true);
                $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
                if ($content !== '') { return $content; }
            }
        } elseif ($config['provider'] === 'gemini') {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                 . urlencode($config['model'] ?: 'gemini-2.0-flash')
                 . ':generateContent?key=' . urlencode($config['api_key']);
            $ch = curl_init($url);
            if (!$ch) { return null; }
            $payload = json_encode([
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature'     => 0.2,
                    'maxOutputTokens' => 300,
                ],
            ]);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp && $code === 200) {
                $data = json_decode((string) $resp, true);
                $content = trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
                if ($content !== '') { return $content; }
            }
        }
    } catch (Throwable $e) {
        @error_log('[MarickHealth][DiseaseAI] Call failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Intelligent rule-based fallback summary when no AI keys are configured or API call times out.
 *
 * @param array $stats
 * @param array $ov
 * @return string
 */
function mh_disease_generate_rule_based_summary(array $stats, array $ov): string
{
    $total = (int) ($ov['total'] ?? 0);
    $spikes = (int) ($stats['spikes'] ?? 0);
    $rising = (int) ($stats['rising'] ?? 0);

    if ($total === 0) {
        return "Surveillance Analysis: No patient illnesses recorded for the selected {$ov['days']}-day surveillance window. Community baseline remains quiet with zero epidemic alerts active.";
    }

    // Top condition
    $topCond = 'clinical consultations';
    $topCount = 0;
    $topPct = 0;
    if (!empty($ov['by_cond'])) {
        $topCond  = (string) key($ov['by_cond']);
        $topCount = (int) current($ov['by_cond']);
        $topPct   = $total > 0 ? round(($topCount / $total) * 100) : 0;
    }

    // Top area
    $topArea = 'Marick Subdivision residents';
    if (!empty($ov['by_area'])) {
        $ar = (string) key($ov['by_area']);
        if ($ar !== 'Not recorded' && $ar !== '') {
            $topArea = $ar;
        }
    }

    $sentence1 = "Surveillance Analysis: Approximately {$topPct}% of recent cases ({$topCount} of {$total}) are categorized as {$topCond}, primarily observed among {$topArea}.";

    if ($spikes > 0) {
        $spikeNames = [];
        foreach ($stats['conditions'] as $c) {
            if ($c['status'] === 'spike') { $spikeNames[] = $c['name']; }
        }
        $sentence2 = "Active outbreak alert: " . implode(', ', $spikeNames) . " has crossed statistical alert thresholds this week and warrants priority clinical triage.";
        $sentence3 = "The Health Head should coordinate with community BHWs for active case monitoring and stock preparation in affected puroks.";
    } elseif ($rising > 0) {
        $sentence2 = "Moderate elevation noted: {$rising} tracked condition(s) are trending above the 4-week moving average without reaching epidemic thresholds.";
        $sentence3 = "Standard clinical precautions and routine health education remain sufficient at this time.";
    } else {
        $sentence2 = "No active epidemic spikes or abnormal clustering detected across all monitored conditions.";
        $sentence3 = "Community disease incidence remains well within expected seasonal baseline parameters for the barangay.";
    }

    return "{$sentence1} {$sentence2} {$sentence3}";
}

/**
 * Generates an executive summary for the Health Head using AI (Groq / Gemini)
 * or the intelligent surveillance fallback engine. Caches results to avoid unnecessary latency.
 *
 * @param mysqli $conn
 * @param array $stats Output of mh_disease_compute()
 * @param array $ov Output of mh_disease_overview()
 * @param bool $force Force regeneration, bypassing cache
 * @return array{summary:string,engine:string,engine_label:string,generated_at:string,cached:bool}
 */
function mh_disease_ai_summary(mysqli $conn, array $stats, array $ov, bool $force = false): array
{
    $hashData = [
        'from'   => $ov['from'],
        'to'     => $ov['to'],
        'total'  => $ov['total'],
        'spikes' => $stats['spikes'],
        'rising' => $stats['rising'],
        'top'    => array_slice($ov['by_cond'], 0, 3, true),
        'areas'  => array_slice($ov['by_area'], 0, 2, true),
    ];
    $dataHash = hash('sha256', json_encode($hashData));

    // 1. Check cache (unless forced)
    if (!$force) {
        $cachedRaw = '';
        if (function_exists('mh_setting')) {
            $cachedRaw = (string) mh_setting('ai_disease_summary_cache', '');
        }
        if ($cachedRaw !== '') {
            $cached = json_decode($cachedRaw, true);
            if (is_array($cached) && !empty($cached['summary']) && ($cached['hash'] ?? '') === $dataHash) {
                // If generated in the last 2 hours
                if ((time() - (int) ($cached['time'] ?? 0)) < 7200) {
                    return [
                        'summary'      => (string) $cached['summary'],
                        'engine'       => (string) ($cached['engine'] ?? 'ai'),
                        'engine_label' => (string) ($cached['engine_label'] ?? 'AI Intelligence'),
                        'generated_at' => date('M j, g:i A', (int) ($cached['time'] ?? time())),
                        'cached'       => true,
                    ];
                }
            }
        }
    }

    // 2. Prepare AI prompt
    $aiCfg = mh_disease_get_ai_config();
    $summary = null;
    $engineLabel = 'Surveillance Rules Engine';
    $engineKey   = 'rules';

    if ($aiCfg['provider'] !== 'none') {
        $spikesList = [];
        $risingList = [];
        foreach ($stats['conditions'] as $c) {
            if ($c['status'] === 'spike')  { $spikesList[] = $c['name'] . ' (' . $c['cur'] . ' cases)'; }
            if ($c['status'] === 'rising') { $risingList[] = $c['name'] . ' (' . $c['cur'] . ' cases)'; }
        }

        $topIllnesses = [];
        foreach (array_slice($ov['by_cond'], 0, 4, true) as $n => $cnt) {
            $topIllnesses[] = "$n ($cnt cases)";
        }
        $topAreas = [];
        foreach (array_slice($ov['by_area'], 0, 3, true) as $a => $cnt) {
            $topAreas[] = "$a ($cnt)";
        }

        $prompt = "Write a 2-3 sentence executive disease surveillance summary for the Health Head of Barangay Sto. Domingo Marick Health Center in Cainta, Rizal, Philippines.\n"
                . "Surveillance period: " . $ov['from'] . " to " . $ov['to'] . " (" . $ov['days'] . " days)\n"
                . "Total validated cases: " . $ov['total'] . " (change vs previous period: " . ($ov['change_pct'] !== null ? ($ov['change_pct'] > 0 ? "+{$ov['change_pct']}%" : "{$ov['change_pct']}%") : "N/A") . ")\n"
                . "Active epidemic spikes: " . (empty($spikesList) ? "None" : implode(', ', $spikesList)) . "\n"
                . "Rising trends: " . (empty($risingList) ? "None" : implode(', ', $risingList)) . "\n"
                . "Dominant illnesses: " . (empty($topIllnesses) ? "None" : implode(', ', $topIllnesses)) . "\n"
                . "Area distribution: " . (empty($topAreas) ? "General" : implode(', ', $topAreas)) . "\n\n"
                . "Requirements:\n"
                . "- Sentence 1: Report dominant illness cluster, proportion/case count, and primary affected population or area.\n"
                . "- Sentence 2: State epidemic/outbreak status clearly (whether any spikes or unusual clusters are detected).\n"
                . "- Sentence 3: State an actionable public health takeaway or assurance for center operations.\n"
                . "- Format: Return exactly 2 or 3 professional sentences. Prefix with 'AI Analysis:'. No bullet points or markdown.";

        $aiResp = mh_disease_ai_call($prompt, $aiCfg, 8);
        if ($aiResp !== null && mb_strlen($aiResp) > 30) {
            $summary = trim(preg_replace('/^#+\s*/m', '', $aiResp));
            $engineKey = $aiCfg['provider'];
            $engineLabel = $aiCfg['provider'] === 'groq' ? 'Groq LLaMA 3.3' : 'Gemini 2.0 Flash';
        }
    }

    // Fallback if AI was unavailable
    if ($summary === null || $summary === '') {
        $summary = mh_disease_generate_rule_based_summary($stats, $ov);
    }

    // 3. Save cache
    $cachePayload = json_encode([
        'summary'      => $summary,
        'engine'       => $engineKey,
        'engine_label' => $engineLabel,
        'time'         => time(),
        'hash'         => $dataHash,
    ]);
    if (function_exists('mh_update_setting')) {
        mh_update_setting($conn, 'ai_disease_summary_cache', $cachePayload);
    }

    return [
        'summary'      => $summary,
        'engine'       => $engineKey,
        'engine_label' => $engineLabel,
        'generated_at' => date('M j, g:i A'),
        'cached'       => false,
    ];
}
