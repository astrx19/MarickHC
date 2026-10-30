<?php
/**
 * =====================================================================
 * PATIENT REGISTRATION + SECURE ACCOUNT CLAIM
 * =====================================================================
 * A walk-in patient already has a clinic record (no username yet). When someone registers
 * with matching details we do NOT overwrite that record on their say-so. Instead:
 *
 *   Step A  register_patient      Identity matches an UNCLAIMED record (username IS NULL/blank)
 *                                 -> generate a 6-digit OTP, keep it (hashed) in $_SESSION['claim_otp']
 *                                 with a 10-minute expiry, and SMS it to the mobile number ALREADY ON
 *                                 FILE for that record. The registrant never supplies that number.
 *   Step B  claim_verify_otp      Correct, unexpired OTP -> ONLY NOW write username + password
 *                                 (atomic UPDATE ... WHERE username IS NULL/blank), then wipe the session state.
 *
 * Also: claim_resend_otp (cooldown + caps) and claim_cancel. Every POST requires a valid CSRF token.
 * A record whose username is already set can never be claimed.
 */
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings_helper.php';   // mh_csrf_check(), mh_setting()
require_once __DIR__ . '/includes/auth_helpers.php';      // mh_username_available()

const CLAIM_OTP_TTL_SECONDS    = 600;   // OTP valid for 10 minutes
const CLAIM_OTP_MAX_ATTEMPTS   = 5;     // wrong guesses before the code is burned
const CLAIM_RESEND_COOLDOWN    = 60;    // seconds between SMS for one claim
const CLAIM_MAX_SMS_PER_CLAIM  = 3;     // SMS per claim session
const CLAIM_MAX_SMS_PER_RECORD = 3;     // SMS per patient record per hour (stops SMS-bombing a victim from new sessions)
const CLAIM_MAX_SMS_PER_IP     = 10;    // SMS per IP per hour

/* ---------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */

// Same address string for registration and claims. Values are bound as parameters, never escaped here.
function build_patient_address($data) {
    if (mh_is_marick_resident($data['address_type'] ?? '')) {   // home jurisdiction comes from System Settings
        return mh_build_resident_address($data['house_number'] ?? '', $data['marick_street'] ?? '');
    }
    return trim($data['outsider_address'] ?? '');
}

function claim_go($location) { header('Location: ' . $location); exit(); }

function claim_flash($message) { $_SESSION['claim_flash'] = $message; }

/** Forget every piece of claim state (pending data AND the OTP). */
function claim_clear_session() {
    unset($_SESSION['claim_otp'], $_SESSION['claim_pending']);
}

/** Record claim SMS sends so one record / one IP cannot be spammed. Table is created on first use. */
function claim_throttle_table(mysqli $conn) {
    static $ready = false;
    if ($ready) { return; }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `patient_claim_attempts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `patient_id` INT NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_patient_time` (`patient_id`, `created_at`),
            KEY `idx_ip_time` (`ip_address`, `created_at`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ready = true;
}

/** @return bool true when another OTP SMS may be sent for this record / IP. */
function claim_sms_allowed(mysqli $conn, $patientId, $ip) {
    claim_throttle_table($conn);
    $stmt = $conn->prepare(
        "SELECT SUM(patient_id = ?) AS per_record, SUM(ip_address = ?) AS per_ip
           FROM patient_claim_attempts
          WHERE created_at > (NOW() - INTERVAL 1 HOUR)"
    );
    $stmt->bind_param('is', $patientId, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int) ($row['per_record'] ?? 0) < CLAIM_MAX_SMS_PER_RECORD)
        && ((int) ($row['per_ip'] ?? 0) < CLAIM_MAX_SMS_PER_IP);
}

function claim_log_sms(mysqli $conn, $patientId, $ip) {
    claim_throttle_table($conn);
    $stmt = $conn->prepare("INSERT INTO patient_claim_attempts (patient_id, ip_address) VALUES (?, ?)");
    $stmt->bind_param('is', $patientId, $ip);
    $stmt->execute();
    $stmt->close();
}

/** Load the clinic record being claimed (fresh from the database, never from the session). */
function claim_load_record(mysqli $conn, $patientId) {
    $stmt = $conn->prepare(
        "SELECT id, username, contact_number, status FROM patients WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** A record can be claimed only while its username is NULL or blank. */
function claim_is_unclaimed(array $record) {
    return trim((string) ($record['username'] ?? '')) === '';
}

/**
 * STEP A (also used by "resend"): create a fresh 6-digit OTP and SMS it to the number ON FILE.
 * The code is stored only as a hash. Returns an error key, or null on success.
 */
function claim_send_otp(mysqli $conn, array $record, $isResend = false) {
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    require_once __DIR__ . '/includes/sms_helper.php';       // loads the DB-backed SMS provider config
    $mobile = mh_normalize_ph_mobile($record['contact_number'] ?? '');
    if ($mobile === '') { return 'claim_no_phone'; }          // nothing usable on file -> must visit the clinic

    if (!claim_sms_allowed($conn, (int) $record['id'], $ip)) { return 'claim_throttled'; }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $message = 'Your ' . mh_setting('system_name') . ' verification code is ' . $code
             . '. It expires in 10 minutes. Never share this code with anyone.';

    $sms = mh_send_sms($mobile, $message);
    if (empty($sms['success'])) {
        error_log('Claim OTP SMS failed for patient #' . (int) $record['id'] . ': ' . ($sms['error'] ?? 'unknown'));
        unset($_SESSION['claim_otp']);
        return 'claim_sms_failed';                            // fail CLOSED: no SMS, no claim
    }
    claim_log_sms($conn, (int) $record['id'], $ip);

    $prev = $_SESSION['claim_otp'] ?? [];
    $_SESSION['claim_otp'] = [
        'patient_id' => (int) $record['id'],
        'hash'       => password_hash($code, PASSWORD_DEFAULT),   // never keep the plain code
        'expires'    => time() + CLAIM_OTP_TTL_SECONDS,
        'attempts'   => 0,
        'sent_at'    => time(),
        'sent_count' => ($isResend ? (int) ($prev['sent_count'] ?? 0) : 0) + 1,
        'masked'     => substr($mobile, 0, 2) . str_repeat('*', max(0, strlen($mobile) - 5)) . substr($mobile, -3),
    ];
    return null;
}

/* ---------------------------------------------------------------------
 * Router
 * ------------------------------------------------------------------ */

// Legacy "?confirm_claim=yes" link used to link a record with ZERO verification (account takeover).
// It can no longer change anything; it just abandons any pending claim.
if (isset($_GET['confirm_claim'])) {
    claim_clear_session();
    claim_go('index.php?reg_status=cancelled');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    claim_go('index.php');
}

// Every POST route below needs a valid CSRF token.
if (!mh_csrf_check()) {
    http_response_code(403);
    claim_go('index.php?reg_status=error_csrf');
}

/* ===== Cancel ====================================================== */
if (isset($_POST['claim_cancel'])) {
    claim_clear_session();
    claim_go('index.php?reg_status=cancelled');
}

/* ===== STEP B: verify the OTP, then (and only then) create the credentials ===== */
if (isset($_POST['claim_verify_otp'])) {
    $pending = $_SESSION['claim_pending'] ?? null;
    $otp     = $_SESSION['claim_otp'] ?? null;
    if (!$pending || !$otp || (int) $pending['patient_id'] !== (int) $otp['patient_id']) {
        claim_clear_session();
        claim_go('index.php?reg_status=claim_expired');
    }
    if (time() > (int) $otp['expires']) {
        claim_clear_session();
        claim_go('index.php?reg_status=claim_expired');
    }
    if ((int) $otp['attempts'] >= CLAIM_OTP_MAX_ATTEMPTS) {
        claim_clear_session();
        claim_go('index.php?reg_status=claim_locked');
    }

    $code = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? ''));
    if (strlen($code) !== 6 || !password_verify($code, $otp['hash'])) {
        $_SESSION['claim_otp']['attempts'] = (int) $otp['attempts'] + 1;
        $left = CLAIM_OTP_MAX_ATTEMPTS - (int) $_SESSION['claim_otp']['attempts'];
        if ($left <= 0) { claim_clear_session(); claim_go('index.php?reg_status=claim_locked'); }
        claim_flash('That code is not correct. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.');
        claim_go('claim_account.php');
    }

    // ---- OTP is correct. Re-check everything against the database, then write atomically. ----
    $patientId = (int) $pending['patient_id'];
    $record = claim_load_record($conn, $patientId);
    if (!$record || (int) $record['status'] !== 1 || !claim_is_unclaimed($record)) {
        claim_clear_session();
        claim_go('index.php?reg_status=error_claimed');
    }
    if (!mh_username_available($conn, $pending['username'])) {
        claim_clear_session();
        claim_go('index.php?reg_status=error_username');
    }

    // Only the credentials are written. Name, birthdate, contact number and clinical data stay as the clinic recorded them.
    // The email is replaced only when the clinic has a blank/placeholder one (walk-in records).
    // "AND username IS NULL/blank" makes it impossible for two concurrent claims to both win.
    $stmt = $conn->prepare(
        "UPDATE patients
            SET username = ?, password = ?,
                email = IF(email IS NULL OR TRIM(email) = '' OR email LIKE '%@walkin.local', ?, email)
          WHERE id = ? AND status = 1 AND (username IS NULL OR TRIM(username) = '')"
    );
    $stmt->bind_param('sssi', $pending['username'], $pending['password_hash'], $pending['email'], $patientId);
    $ok = $stmt->execute();
    $claimed = $ok && $stmt->affected_rows === 1;
    $stmt->close();

    if (!$claimed) {
        error_log('Claim UPDATE did not apply for patient #' . $patientId . ': ' . $conn->error);
        claim_clear_session();
        claim_go('index.php?reg_status=error_claimed');
    }

    logActivity($conn, 0, 'patient', 'Account Claimed', 'Patient #' . $patientId . ' linked a portal account after SMS verification.');

    // Credentials exist -> clear the OTP + pending data immediately and rotate the session id.
    claim_clear_session();
    session_regenerate_id(true);
    claim_go('index.php?reg_status=success');
}

/* ===== Resend the OTP (to the SAME number on file) ===== */
if (isset($_POST['claim_resend_otp'])) {
    $pending = $_SESSION['claim_pending'] ?? null;
    $otp     = $_SESSION['claim_otp'] ?? null;
    if (!$pending) { claim_clear_session(); claim_go('index.php?reg_status=claim_expired'); }

    if ($otp && (time() - (int) $otp['sent_at']) < CLAIM_RESEND_COOLDOWN) {
        claim_flash('Please wait a minute before requesting another code.');
        claim_go('claim_account.php');
    }
    if ($otp && (int) $otp['sent_count'] >= CLAIM_MAX_SMS_PER_CLAIM) {
        claim_clear_session();
        claim_go('index.php?reg_status=claim_throttled');
    }
    $record = claim_load_record($conn, (int) $pending['patient_id']);      // number comes from the database again
    if (!$record || (int) $record['status'] !== 1 || !claim_is_unclaimed($record)) {
        claim_clear_session();
        claim_go('index.php?reg_status=error_claimed');
    }
    $err = claim_send_otp($conn, $record, true);
    if ($err) { claim_clear_session(); claim_go('index.php?reg_status=' . $err); }
    claim_flash('A new code was sent to the mobile number on file.');
    claim_go('claim_account.php');
}

/* ===== Registration / STEP A ===== */
if (isset($_POST['register_patient'])) {
    $fname    = trim($_POST['first_name'] ?? '');
    $lname    = trim($_POST['last_name'] ?? '');
    $mname    = trim($_POST['middle_name'] ?? '');
    $suffix   = trim($_POST['suffix'] ?? '');
    $bdate    = trim($_POST['birthdate'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password_plain = (string) ($_POST['password'] ?? '');
    $password_confirm = (string) ($_POST['confirm_password'] ?? $password_plain);

    $d = DateTime::createFromFormat('Y-m-d', $bdate);
    if ($fname === '' || $lname === '' || !$d || $d->format('Y-m-d') !== $bdate || $d > new DateTime('today')) {
        claim_go('index.php?reg_status=error_input');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        claim_go('index.php?reg_status=error_email');
    }
    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username) || !mh_username_available($conn, $username)) {
        claim_go('index.php?reg_status=error_username');
    }
    if (strlen($password_plain) < 8 || !hash_equals($password_plain, $password_confirm)) {
        claim_go('index.php?reg_status=error_password');
    }

    // ---- Does this look like an existing clinic record? -----------------------------
    $stmt = $conn->prepare(
        "SELECT id, email, username, status, first_name, last_name, birthdate FROM patients
          WHERE email = ? OR (first_name = ? AND last_name = ? AND birthdate = ?)"
    );
    $stmt->bind_param('ssss', $email, $fname, $lname, $bdate);
    $stmt->execute();
    $matches = $stmt->get_result();
    $by_email = null; $by_identity = null;
    while ($m = $matches->fetch_assoc()) {
        if (strtolower(trim((string) $m['email'])) === strtolower($email)) { $by_email = $by_email ?? $m; }
        elseif (strtolower(trim($m['first_name'])) === strtolower($fname)
             && strtolower(trim($m['last_name'])) === strtolower($lname)
             && trim((string) $m['birthdate']) === $bdate) { $by_identity = $by_identity ?? $m; }
    }
    $stmt->close();

    if ($by_email || $by_identity) {
        // Email says record A, name+birthdate says record B -> ambiguous, refuse rather than guess.
        if ($by_email && $by_identity && (int) $by_email['id'] !== (int) $by_identity['id']) {
            claim_go('index.php?reg_status=error_email');
        }
        $target = $by_email ?? $by_identity;

        // 1) CLAIMABILITY: username must be NULL or blank. Already claimed -> reject, never overwrite.
        if (!claim_is_unclaimed($target)) { claim_go('index.php?reg_status=error_claimed'); }
        if ((int) $target['status'] !== 1) { claim_go('index.php?reg_status=error_claimed'); }   // archived records: contact the clinic

        // 2) Read the number ON FILE (never the one typed into the form) and send the OTP there.
        $record = claim_load_record($conn, (int) $target['id']);
        if (!$record) { claim_go('index.php?reg_status=error_claimed'); }

        // Keep ONLY what is needed after verification. The plain password is hashed now and never stored.
        $_SESSION['claim_pending'] = [
            'patient_id'    => (int) $record['id'],
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash($password_plain, PASSWORD_DEFAULT),
        ];
        unset($_SESSION['claim_otp']);
        $err = claim_send_otp($conn, $record, false);
        if ($err) { claim_clear_session(); claim_go('index.php?reg_status=' . $err); }

        session_regenerate_id(true);
        claim_go('claim_account.php');
    }

    // ---- Brand-new patient: plain registration ---------------------------------------
    $contact  = trim($_POST['contact_number'] ?? '');
    $sex      = trim($_POST['sex'] ?? '');
    $philhealth = trim($_POST['philhealth_no'] ?? '');
    $family   = trim($_POST['family_no'] ?? '');
    $password = password_hash($password_plain, PASSWORD_DEFAULT);
    // Address: validated on the server too (the browser check can be bypassed or stale).
    //   home type (Marick resident) -> house number + a street that exists in the Streets masterlist
    //   any other type (Outsider ...) -> free-text full address, no street dropdown involved
    $address_type = trim($_POST['address_type'] ?? '');
    if (!mh_masterlist_has($conn, 'address_types', $address_type)) { claim_go('index.php?reg_status=error_address'); }
    if (mh_is_marick_resident($address_type)) {
        $house  = trim($_POST['house_number'] ?? '');
        $street = trim($_POST['marick_street'] ?? '');
        if ($house === '' || !mh_masterlist_has($conn, 'streets', $street)) { claim_go('index.php?reg_status=error_address'); }
        $address_detail = mh_build_resident_address($house, $street);
    } else {
        $address_detail = trim($_POST['outsider_address'] ?? '');
        if ($address_detail === '' || mb_strlen($address_detail) > 255) { claim_go('index.php?reg_status=error_address'); }
    }

    $stmt = $conn->prepare(
        "INSERT INTO patients
            (first_name, last_name, middle_name, suffix, birthdate, sex, contact_number,
             email, username, password, address_type, address, philhealth_no, family_no)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "ssssssssssssss",
        $fname, $lname, $mname, $suffix, $bdate, $sex, $contact,
        $email, $username, $password, $address_type, $address_detail, $philhealth, $family
    );
    $registered = $stmt->execute();
    $stmt->close();

    if ($registered) { claim_go('index.php?reg_status=success'); }
    error_log("Patient registration failed: " . $conn->error);   // never echo DB errors to the browser
    claim_go('index.php?reg_status=error_db');
}

claim_go('index.php');
