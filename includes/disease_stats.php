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

    $res = $conn->query("SELECT COUNT(*) AS c FROM tracked_conditions");
    if ($res && (int) $res->fetch_assoc()['c'] === 0) {
        $seed = [
            ['Influenza / Flu',           'flu, influenza, ili, influenza-like',          3],
            ['Chickenpox',                'chickenpox, chicken pox, varicella',           2],
            ['Dengue',                    'dengue, dhf',                                  2],
            ['Diarrhea / Gastroenteritis', 'diarrhea, diarrhoea, gastroenteritis, lbm, agw', 3],
            ['Pneumonia',                 'pneumonia',                                    2],
            ['Tuberculosis',              'tuberculosis, ptb, tb',                        2],
            ['Hypertension',              'hypertension, htn',                            5],
            ['Skin infection / Scabies',  'scabies, impetigo, skin infection',            3],
            ['UTI',                       'uti, urinary tract infection',                 3],
            ['COVID-19',                  'covid, covid-19, sars-cov-2',                  2],
        ];
        $st = $conn->prepare("INSERT IGNORE INTO tracked_conditions (name, keywords, min_cases) VALUES (?, ?, ?)");
        foreach ($seed as [$n, $k, $m]) { $st->bind_param('ssi', $n, $k, $m); $st->execute(); }
        $st->close();
    }
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

/** One row per case: [date, text, sex, birthdate, address_type]. */
function mh_disease_load_cases(mysqli $conn, string $from, string $to)
{
    $rows = [];
    $st = $conn->prepare("SELECT c.visit_date AS d,
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
    $st = @$conn->prepare("SELECT dr.visit_date AS d,
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
    $st = $conn->prepare("SELECT cl.consultation_date AS d,
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
        $recent = $days < 30;
        if ($recent) { $cases_30d++; }

        $matched = false;
        foreach ($conds as &$c) {
            if ($c['regex'] && $txt !== '' && preg_match($c['regex'], $txt)) {
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
        if (!$matched && $recent && $txt !== '') {
            $key = mb_substr(preg_replace('/\s+/u', ' ', mb_strtolower($txt)), 0, 60);
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
        if ($c['cur'] >= $min && ($c['prev_avg'] == 0 || $c['cur'] >= $factor * $c['prev_avg'])) { $c['status'] = 'spike'; $spikes++; }
        elseif ($c['cur'] >= 2 && $c['cur'] > $c['prev_avg'] * 1.25) { $c['status'] = 'rising'; $rising++; }
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
