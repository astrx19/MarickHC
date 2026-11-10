<?php

if (!defined('MH_LOADED_INCLUDES_PATIENT_DUP')) {
define('MH_LOADED_INCLUDES_PATIENT_DUP', true);

if (!function_exists('mh_normalize_ph_mobile')) {
    require_once __DIR__ . '/sms_helper.php';
}

function mh_pd_clean_name($s)
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
}

function mh_pd_phone_variants($raw)
{
    $n = mh_normalize_ph_mobile($raw);
    if ($n === '') { return []; }
    $tail = substr($n, 1);
    return [$n, '63' . $tail, '+63' . $tail];
}

function mh_pd_display_name(array $p)
{
    if (function_exists('mh_format_patient_name')) {
        return mh_format_patient_name($p['first_name'] ?? '', $p['middle_name'] ?? '', $p['last_name'] ?? '');
    }
    return trim(($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? ''));
}

function mh_pd_describe(array $p)
{
    $age = '';
    try { $age = ', ' . (new DateTime($p['birthdate']))->diff(new DateTime('today'))->y . ' yrs'; } catch (Throwable $e) {}
    return mh_pd_display_name($p) . ' (born ' . $p['birthdate'] . $age . ', ' . ($p['sex'] ?? '?') . ')';
}

function mh_pd_mask_mobile($raw)
{
    $n = mh_normalize_ph_mobile($raw);
    return $n === '' ? 'none' : substr($n, 0, 2) . str_repeat('*', 6) . substr($n, -3);
}

function mh_pd_check_new_patient(mysqli $conn, array $np, $confirmedDifferent = false, $lock = false)
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $cols = "id, first_name, middle_name, last_name, birthdate, sex, contact_number, email, status";

    $stmt = $conn->prepare("SELECT $cols FROM patients WHERE last_name = ? AND first_name = ? AND birthdate = ? ORDER BY status DESC, id ASC LIMIT 1" . $suffix);
    $stmt->bind_param('sss', $np['lname'], $np['fname'], $np['bdate']);
    $stmt->execute();
    $hit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($hit) {
        if ((int) $hit['status'] === 1) {
            return ['level' => 'block', 'select_id' => (int) $hit['id'],
                'title' => 'This patient is already registered',
                'message' => mh_pd_describe($hit) . ' already has a record (mobile on file: ' . mh_pd_mask_mobile($hit['contact_number']) . '). '
                           . 'Please use the "Existing Patient" tab and select them instead of registering again.'];
        }
        return ['level' => 'block', 'select_id' => 0,
            'title' => 'An archived record already exists',
            'message' => mh_pd_describe($hit) . ' has an archived record. Do not create a new one - ask an Administrator to restore the archived record, then use the "Existing Patient" tab.'];
    }

    if ($np['real_email'] !== '') {
        $stmt = $conn->prepare("SELECT $cols FROM patients WHERE email = ? LIMIT 1" . $suffix);
        $stmt->bind_param('s', $np['real_email']);
        $stmt->execute();
        $hit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hit) {
            $active = ((int) $hit['status'] === 1);
            return ['level' => 'block', 'select_id' => $active ? (int) $hit['id'] : 0,
                'title' => 'This email is already linked to another patient',
                'message' => 'The email ' . $np['real_email'] . ' belongs to ' . mh_pd_describe($hit) . ($active ? '' : ' (archived record)') . '. '
                           . 'If that is the same person, use the "Existing Patient" tab. If not, enter a different email or leave the email blank.'];
        }
    }

    $variants = mh_pd_phone_variants($np['contact_norm']);
    if ($variants && !$confirmedDifferent) {
        $stmt = $conn->prepare("SELECT $cols FROM patients WHERE contact_number IN (?, ?, ?) ORDER BY status DESC, id ASC LIMIT 25" . $suffix);
        $stmt->bind_param('sss', $variants[0], $variants[1], $variants[2]);
        $stmt->execute();
        $rows = $stmt->get_result();
        $f = mb_strtolower($np['fname']); $l = mb_strtolower($np['lname']);
        while ($r = $rows->fetch_assoc()) {
            if (mb_strtolower(mh_pd_clean_name($r['first_name'])) === $f && mb_strtolower(mh_pd_clean_name($r['last_name'])) === $l) {
                $stmt->close();
                $active = ((int) $r['status'] === 1);
                return ['level' => 'confirm', 'select_id' => $active ? (int) $r['id'] : 0,
                    'title' => 'Possible duplicate - please check the birthdate',
                    'message' => 'A patient with the same name and mobile number already exists: ' . mh_pd_describe($r) . ($active ? '' : ' [archived]')
                               . '. You entered a birthdate of ' . $np['bdate'] . '. If it is the same person, select them in the "Existing Patient" tab. '
                               . 'Only if this is truly a different person (for example a parent and child with the same name), tick the confirmation box below and submit again.'];
            }
        }
        $stmt->close();
    }
    return null;
}
}
