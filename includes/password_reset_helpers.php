<?php
// Wrapped in a constant-keyed if-block like the other includes (see auth_helpers.php for why).
if (!defined('MH_LOADED_INCLUDES_PASSWORD_RESET')) {
define('MH_LOADED_INCLUDES_PASSWORD_RESET', true);
define('MH_PR_TTL_MINUTES', 15);     // link / OTP lifetime
define('MH_PR_MAX_OTP_TRIES', 5);    // wrong OTP guesses before the code is dead
define('MH_PR_COOLDOWN_SECONDS', 60);
define('MH_PR_MAX_PER_ACCOUNT_HOUR', 5);
define('MH_PR_MAX_PER_IP_HOUR', 10);

function mh_pr_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mh_pr_ip() { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

function mh_pr_csrf_token()
{
    if (empty($_SESSION['pr_csrf'])) { $_SESSION['pr_csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['pr_csrf'];
}
function mh_pr_csrf_ok($t)
{
    return is_string($t) && !empty($_SESSION['pr_csrf']) && hash_equals($_SESSION['pr_csrf'], $t);
}

/** Server-side secret used only to make decoy (unknown-account) screens stable. */
function mh_pr_pepper()
{
    if (defined('PASSWORD_RESET_PEPPER') && PASSWORD_RESET_PEPPER !== '') { return PASSWORD_RESET_PEPPER; }
    return hash('sha256', 'mh-pr|' . ($GLOBALS['database'] ?? '') . '|' . ($GLOBALS['password'] ?? ''));
}

/* ----------------------------- masking ---------------------------- */
function mh_pr_mask_email($email)
{
    $email = (string) $email;
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) { return '***'; }
    return substr($email, 0, 1) . '***' . substr($email, $at);
}
/** 09171234567 -> 09******567 (expects the normalised 11-digit form). */
function mh_pr_mask_mobile($mobile)
{
    $m = (string) $mobile;
    if (strlen($m) < 6) { return '***'; }
    return substr($m, 0, 2) . str_repeat('*', strlen($m) - 5) . substr($m, -3);
}

/* ----------------------------- accounts --------------------------- */
/** Which account type a recovery request is for: 'staff' | 'patient' | '' (unknown -> search both). */
function mh_pr_scope($value)
{
    $value = strtolower(trim((string) $value));
    return in_array($value, ['staff', 'patient'], true) ? $value : '';
}

/**
 * Active (status = 1) patient or staff account by username OR email. Archived accounts cannot reset.
 *
 * $scope comes from the login page the visitor started on ("Forgot Password?" on the staff console
 * -> 'staff', on the patient page -> 'patient'). Usernames/emails are only unique PER TABLE, so
 * without a scope a patient with the same username would always shadow the staff member.
 */
function mh_pr_find_account(mysqli $conn, $identifier, $scope = '')
{
    $identifier = trim((string) $identifier);
    if ($identifier === '') { return null; }
    $scope  = mh_pr_scope($scope);
    $tables = $scope === 'staff' ? ['staff'] : ($scope === 'patient' ? ['patients'] : ['patients', 'staff']);
    foreach ($tables as $table) {
        $stmt = $conn->prepare("SELECT * FROM `$table` WHERE (username = ? OR email = ?) AND status = 1 LIMIT 1");
        if (!$stmt) { continue; }
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) { return ['table' => $table, 'row' => $row]; }
    }
    return null;
}

function mh_pr_get_account(mysqli $conn, $table, $id)
{
    $table = ($table === 'staff') ? 'staff' : 'patients';
    $id = (int) $id;
    $stmt = $conn->prepare("SELECT * FROM `$table` WHERE id = ? AND status = 1 LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * One definition of "an address we may send mail to": syntactically valid, no line breaks
 * (header injection), sane length, and not a placeholder (walk-in patients get "@walkin.local").
 */
function mh_pr_valid_email($email)
{
    $email = trim((string) $email);
    if ($email === '' || strlen($email) > 100 || preg_match('/[\r\n,;<>\s]/', $email)) { return false; }
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && !preg_match('/\.local$/i', $email);
}

/** Channels the SYSTEM offers to everybody (never depends on whether an account exists). */
function mh_pr_global_channels()
{
    $sms = function_exists('mh_sms_provider_problem') && mh_sms_provider_problem() === null;
    return ['email' => true, 'sms' => (bool) $sms];
}

/** Which recovery channels this account can actually use right now. */
function mh_pr_channels(array $row)
{
    $email = trim((string) ($row['email'] ?? ''));
    $emailOk = mh_pr_valid_email($email);

    $phone = function_exists('mh_normalize_ph_mobile') ? mh_normalize_ph_mobile($row['contact_number'] ?? '') : '';
    $smsOk = ($phone !== '') && function_exists('mh_sms_provider_problem') && mh_sms_provider_problem() === null;

    return [
        'email' => (bool) $emailOk, 'sms' => (bool) $smsOk, 'phone' => $phone,
        'email_masked' => $emailOk ? mh_pr_mask_email($email) : '',
        'phone_masked' => $phone !== '' ? mh_pr_mask_mobile($phone) : '',
    ];
}

/**
 * What step 2 shows for a lookup. Real and unknown accounts produce the SAME shape:
 *  - the options offered depend only on what the SYSTEM supports (mh_pr_global_channels),
 *  - a masked address / number is always shown; when the real account has none, the stable
 *    decoy mask for that identifier is used, so the screen never reveals which case it is.
 * What can really be delivered is decided later, silently, in forgot_password_process.php.
 */
function mh_pr_step2_view($identifier, $found)
{
    $g = mh_pr_global_channels();
    $d = mh_pr_decoy($identifier);
    $ch = $found ? mh_pr_channels($found['row']) : null;
    return [
        'email_ok'     => $g['email'],
        'sms_ok'       => $g['sms'],
        'email_masked' => ($ch && $ch['email_masked'] !== '') ? $ch['email_masked'] : $d['email_masked'],
        'phone_masked' => ($ch && $ch['phone_masked'] !== '') ? $ch['phone_masked'] : $d['phone_masked'],
    ];
}

/**
 * Sends the redirect, closes the connection, and lets the script keep running.
 * Mail/SMS delivery can take seconds for a real account and nothing for an unknown one -
 * doing it AFTER the response removes that timing difference (an account-existence oracle).
 */
function mh_pr_redirect_then_continue($url)
{
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    ignore_user_abort(true);
    @set_time_limit(60);
    header('Location: ' . $url);
    header('Connection: close');
    header('Content-Length: 0');
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        flush();
    }
}

/** Same-looking step-2 data for identifiers that match nothing (stops username probing). */
function mh_pr_decoy($identifier)
{
    $h = hash_hmac('sha256', strtolower(trim((string) $identifier)), mh_pr_pepper());
    $digits = '';
    foreach (str_split(substr($h, 0, 12)) as $c) { $digits .= (string) (hexdec($c) % 10); }
    $local = substr(preg_replace('/[^a-z]/', '', strtr($h, '0123456789', 'abcdefghij')), 0, 1) ?: 'u';
    $domains = ['gmail.com', 'yahoo.com', 'outlook.com'];
    return [
        'email_masked' => $local . '***@' . $domains[hexdec($h[0]) % 3],
        'phone_masked' => mh_pr_mask_mobile('09' . substr($digits, 0, 9)),
    ];
}

/* ----------------------------- throttling ------------------------- */
/** @return bool true when the send should be silently skipped. */
function mh_pr_throttled(mysqli $conn, $userType, $userId, $ip)
{
    $checks = [
        ["SELECT COUNT(*) c FROM password_resets WHERE user_type = ? AND user_id = ? AND created_at > NOW() - INTERVAL " . MH_PR_COOLDOWN_SECONDS . " SECOND", 'si', [$userType, $userId], 1],
        ["SELECT COUNT(*) c FROM password_resets WHERE user_type = ? AND user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR", 'si', [$userType, $userId], MH_PR_MAX_PER_ACCOUNT_HOUR],
        ["SELECT COUNT(*) c FROM password_resets WHERE ip_address = ? AND created_at > NOW() - INTERVAL 1 HOUR", 's', [$ip], MH_PR_MAX_PER_IP_HOUR],
    ];
    foreach ($checks as [$sql, $types, $params, $limit]) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) { return true; }   // fail closed
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        if ($n >= $limit) { return true; }
    }
    return false;
}

/* ----------------------------- issue ------------------------------ */
/**
 * Creates a reset for one account. Earlier pending resets for that account are expired
 * (kept for rate-limit counting, purged after a day). Only a HASH is stored.
 *
 * @return array{id:int, secret:string}|null  secret = raw token (email) or 6-digit OTP (sms)
 */
function mh_pr_issue(mysqli $conn, $table, array $accountRow, $method, $destination)
{
    $userType = ($table === 'staff') ? 'staff' : 'patient';
    $userId = (int) $accountRow['id'];
    $method = ($method === 'sms') ? 'sms' : 'email';

    $conn->query("DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY");

    $exp = $conn->prepare("UPDATE password_resets SET expires_at = NOW() WHERE user_type = ? AND user_id = ? AND expires_at > NOW()");
    if ($exp) { $exp->bind_param('si', $userType, $userId); $exp->execute(); $exp->close(); }

    if ($method === 'email') {
        $secret = bin2hex(random_bytes(32));
        $stored = hash('sha256', $secret);
    } else {
        $secret = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stored = password_hash($secret, PASSWORD_DEFAULT);
    }

    $ip = mh_pr_ip();
    $stmt = $conn->prepare(
        "INSERT INTO password_resets (identifier, token_or_otp, method, user_type, user_id, ip_address, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . MH_PR_TTL_MINUTES . " MINUTE))"
    );
    if (!$stmt) { return null; }
    $stmt->bind_param('ssssis', $destination, $stored, $method, $userType, $userId, $ip);
    $ok = $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $ok ? ['id' => $id, 'secret' => $secret] : null;
}

/* ----------------------------- validate --------------------------- */
function mh_pr_load_by_token(mysqli $conn, $token)
{
    $token = trim((string) $token);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return null; }
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT * FROM password_resets WHERE token_or_otp = ? AND method = 'email' AND expires_at > NOW() LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mh_pr_load_by_id(mysqli $conn, $id, $method)
{
    $id = (int) $id;
    $stmt = $conn->prepare("SELECT * FROM password_resets WHERE id = ? AND method = ? AND expires_at > NOW() LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('is', $id, $method);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** @return string 'ok' | 'bad' | 'locked' (expired, missing, or too many wrong guesses) */
function mh_pr_verify_otp(mysqli $conn, $resetId, $code)
{
    $resetId = (int) $resetId;
    $max = MH_PR_MAX_OTP_TRIES;
    // Count the attempt BEFORE checking it, atomically, so parallel requests can't dodge the cap.
    $upd = $conn->prepare("UPDATE password_resets SET attempts = attempts + 1
                           WHERE id = ? AND method = 'sms' AND expires_at > NOW() AND attempts < ?");
    if (!$upd) { return 'locked'; }
    $upd->bind_param('ii', $resetId, $max);
    $upd->execute();
    $counted = $upd->affected_rows === 1;
    $upd->close();
    if (!$counted) { return 'locked'; }

    $row = mh_pr_load_by_id($conn, $resetId, 'sms');
    if (!$row) { return 'locked'; }
    if (password_verify((string) $code, $row['token_or_otp'])) { return 'ok'; }
    return ((int) $row['attempts'] >= $max) ? 'locked' : 'bad';
}

/** @return string|null error message, or null when the password is acceptable */
function mh_pr_password_error($new, $confirm, $currentHash)
{
    if (strlen($new) < 8) { return 'Password must be at least 8 characters long.'; }
    if (strlen($new) > 72) { return 'Password must be 72 characters or fewer.'; }  // bcrypt ignores anything past 72 bytes
    if ($new !== $confirm) { return 'Passwords do not match. Please try again.'; }
    if ($currentHash && password_verify($new, $currentHash)) { return 'Your new password must be different from your current one.'; }
    return null;
}

/**
 * Sets the new password, signs the account out everywhere, and deletes EVERY reset row for it
 * (the used one and any stragglers) in one transaction.
 */
function mh_pr_complete(mysqli $conn, array $resetRow, $newPlain)
{
    $table = ($resetRow['user_type'] === 'staff') ? 'staff' : 'patients';
    $userId = (int) $resetRow['user_id'];
    $resetId = (int) $resetRow['id'];
    $hash = password_hash($newPlain, PASSWORD_DEFAULT);

    if (!$conn->begin_transaction()) { return false; }

    // Lock the reset row and re-check it is still valid: two tabs can't both use one token.
    $lock = $conn->prepare("SELECT id FROM password_resets WHERE id = ? AND expires_at > NOW() FOR UPDATE");
    $lock->bind_param('i', $resetId);
    $lock->execute();
    $stillValid = (bool) $lock->get_result()->fetch_assoc();
    $lock->close();
    if (!$stillValid) { $conn->rollback(); return false; }

    // session_token = NULL kicks out any session that was open with the old password.
    // Staff also finish first-time setup by resetting (same behaviour as the old flow).
    $sql = ($table === 'staff')
        ? "UPDATE staff SET password = ?, is_first_login = 0, session_token = NULL, session_started_at = NULL WHERE id = ? AND status = 1"
        : "UPDATE patients SET password = ?, session_token = NULL, session_started_at = NULL WHERE id = ? AND status = 1";
    $upd = $conn->prepare($sql);
    if (!$upd) { $conn->rollback(); return false; }
    $upd->bind_param('si', $hash, $userId);
    $ok = $upd->execute() && $upd->affected_rows === 1;
    $upd->close();

    if ($ok) {
        $del = $conn->prepare("DELETE FROM password_resets WHERE user_type = ? AND user_id = ?");
        $del->bind_param('si', $resetRow['user_type'], $userId);
        $ok = $del->execute();
        $del->close();
    }

    if ($ok) { return $conn->commit(); }
    $conn->rollback();
    return false;
}

/* ----------------------------- email body ------------------------- */
function mh_pr_email_html($firstName, $systemName, $link)
{
    $n = mh_pr_h($firstName ?: 'there'); $s = mh_pr_h($systemName); $l = mh_pr_h($link);
    return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#334155;line-height:1.55">'
        . "<h2 style=\"color:#1e293b;margin:0 0 12px\">Reset your password</h2>"
        . "<p>Hello $n,</p>"
        . "<p>We received a request to reset the password for your $s account. Click the button below to choose a new one.</p>"
        . "<p style=\"margin:24px 0\"><a href=\"$l\" style=\"background:#4ca1af;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block\">Reset My Password</a></p>"
        . "<p style=\"font-size:13px;color:#64748b\">Or paste this link into your browser:<br><span style=\"word-break:break-all\">$l</span></p>"
        . '<p style="font-size:13px;color:#64748b">This link works once and expires in ' . MH_PR_TTL_MINUTES . ' minutes. '
        . 'If you didn\'t request this, ignore this email - your password will not change.</p></div>';
}

/* ----------------------------- tiny icons ------------------------- */
function mh_pr_icon($name)
{
    $p = [
        'mail'  => '<path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/>',
        'sms'   => '<path d="M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-8l-4 3v-3H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/><path d="M8 10h8M8 13h5"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>',
        'moon'  => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
    ][$name] ?? '';
    return '<svg class="pr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
}
