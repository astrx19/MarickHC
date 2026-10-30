<?php
// Definitions wrapped in a constant-keyed if-block ON PURPOSE (same convention as auth_helpers.php).
if (!defined('MH_LOADED_INCLUDES_PATIENT_DUP')) {
define('MH_LOADED_INCLUDES_PATIENT_DUP', true);
/**
 * =====================================================================
 * DUPLICATE-PATIENT DETECTION (staff Walk-In / Quick Registration)
 * =====================================================================
 * The old check only looked at the email. Walk-ins without an email get a random
 * "...@walkin.local" placeholder, so that check could NEVER fire for them - the same
 * person could be registered again and again.
 *
 * Rules (checked in this order, first hit wins):
 *   1. SAME FIRST NAME + LAST NAME + BIRTHDATE  -> BLOCK. It is the same person. (Middle name,
 *      sex, email and phone are deliberately ignored: they are the fields people mistype.)
 *      Active record -> offer "Use this patient". Archived record -> ask an admin to restore it.
 *   2. REAL EMAIL already on a record           -> BLOCK (emails are unique; the portal login depends on it).
 *   3. SAME MOBILE + SAME FIRST/LAST NAME but a DIFFERENT BIRTHDATE -> CONFIRM. Almost always the same
 *      person with a mistyped birthdate (or a "Jr."/"Sr." pair); staff must tick a box to go ahead.
 *   Same mobile with a DIFFERENT name is allowed on purpose: parents, grandparents and siblings
 *   share one phone in a barangay clinic, so blocking it would turn families away.
 *
 * Name matching relies on the table's *_ci collation (case/accent-insensitive); input is trimmed
 * and inner whitespace collapsed first.
 * ================================================================== */

if (!function_exists('mh_normalize_ph_mobile')) {
    require_once __DIR__ . '/sms_helper.php';   // loads includes/sms/SemaphoreSmsClient.php
}

/** "  juan   dela  cruz " -> "juan dela cruz" */
function mh_pd_clean_name($s)
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
}

/** Every way the same mobile can be stored in `patients.contact_number` (09.., 639.., +639..). */
function mh_pd_phone_variants($raw)
{
    $n = mh_normalize_ph_mobile($raw);          // 09XXXXXXXXX or ''
    if ($n === '') { return []; }
    $tail = substr($n, 1);                       // 9XXXXXXXXX
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

/**
 * Checks a NEW patient before it is inserted.
 *
 * @param array $np  fname, lname, bdate (Y-m-d), contact_norm (09XXXXXXXXX), real_email ('' when none given)
 * @param bool  $confirmedDifferent staff ticked "this is a different person"
 * @param bool  $lock run inside the registration transaction: SELECT ... FOR UPDATE so two staff
 *                    members registering the same person at the same moment can't both pass
 * @return array|null null = no problem; otherwise
 *   ['level' => 'block'|'confirm', 'title' => str, 'message' => str, 'select_id' => int (0 = none)]
 */
function mh_pd_check_new_patient(mysqli $conn, array $np, $confirmedDifferent = false, $lock = false)
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $cols = "id, first_name, middle_name, last_name, birthdate, sex, contact_number, email, status";

    /* 1) Same first + last name + birthdate (prefer the active record if there are several) */
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

    /* 2) Real email already used */
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

    /* 3) Same mobile + same first/last name, different birthdate -> likely mistyped birthdate */
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
