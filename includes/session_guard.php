<?php
/**
 * =====================================================================
 * SINGLE ACTIVE SESSION PER ACCOUNT  (staff + patients)
 * =====================================================================
 * "Newest sign-in wins": every successful login stores a fresh random
 * token in the account's row (staff.session_token / patients.session_token)
 * and in the PHP session. Every protected request compares the two. If
 * the account has signed in somewhere else since, the token no longer
 * matches and this session is ended with a clear message.
 *
 * Why "newest wins" instead of "block the second login": a person who
 * closed the browser without logging out (or lost their phone) would
 * otherwise be locked out until someone cleared the row by hand.
 *
 * Rollout safety
 *   - If the session_token column does not exist yet, validation is
 *     skipped (nobody is locked out). mh_ss_issue() also tries to add the
 *     column itself on the next login; database/single_session_migration.sql
 *     is the clean way to do it.
 *   - Sessions that were already open before this feature went live carry
 *     no token and the row has none either, so they stay valid until the
 *     account's next login.
 * =====================================================================
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('mh_ss_db')) {
    function mh_ss_db()
    {
        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
            (function () {
                global $conn;
                require_once __DIR__ . '/../config/database.php';
            })();
        }
        return $GLOBALS['conn'] ?? null;
    }
}

function mh_ss_table($kind)  { return $kind === 'patient' ? 'patients' : 'staff'; }
function mh_ss_key($kind)    { return 'mh_sess_token_' . ($kind === 'patient' ? 'patient' : 'staff'); }
function mh_ss_account_id($kind)
{
    if ($kind === 'patient') { return (int) ($_SESSION['patient_id'] ?? 0); }
    return (int) ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
}

/** Adds the token columns if the migration has not been run. Cached per request. */
function mh_ss_ensure_column(mysqli $conn, $kind)
{
    static $done = [];
    if (isset($done[$kind])) { return $done[$kind]; }
    $t = mh_ss_table($kind);
    $ok = false;
    try {
        $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE 'session_token'");
        if ($r && $r->num_rows > 0) {
            $ok = true;
        } else {
            $conn->query("ALTER TABLE `$t`
                ADD COLUMN `session_token` CHAR(64) NULL DEFAULT NULL,
                ADD COLUMN `session_started_at` DATETIME NULL DEFAULT NULL");
            $ok = true;
        }
    } catch (Throwable $e) {
        error_log('mh_ss_ensure_column(' . $t . '): ' . $e->getMessage());
    }
    return $done[$kind] = $ok;
}

/**
 * Call right after a successful login (after session_regenerate_id) and
 * after the account's id has been put in $_SESSION. Replaces any older session.
 */
function mh_ss_issue($kind, $id)
{
    $key = mh_ss_key($kind);
    unset($_SESSION[$key]);
    $id = (int) $id;
    $conn = mh_ss_db();
    if (!$conn || $id <= 0 || !mh_ss_ensure_column($conn, $kind)) { return false; }
    try {
        $token = bin2hex(random_bytes(32));
        $t = mh_ss_table($kind);
        $stmt = $conn->prepare("UPDATE `$t` SET session_token = ?, session_started_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $token, $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION[$key] = $token;
        return true;
    } catch (Throwable $e) {
        error_log('mh_ss_issue: ' . $e->getMessage());
        return false;
    }
}

/** True when this PHP session is still the account's newest sign-in. */
function mh_ss_is_valid($kind)
{
    $id = mh_ss_account_id($kind);
    $conn = mh_ss_db();
    if ($id <= 0 || !$conn) { return true; }
    try {
        $t = mh_ss_table($kind);
        $stmt = $conn->prepare("SELECT session_token FROM `$t` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        return true;                      // column not created yet: do not lock anyone out
    }
    if (!$row) { return true; }           // missing account is handled by the caller's own checks
    $db   = (string) ($row['session_token'] ?? '');
    $mine = (string) ($_SESSION[mh_ss_key($kind)] ?? '');
    if ($db === '' && $mine === '') { return true; }   // legacy session from before this feature
    return $db !== '' && $mine !== '' && hash_equals($db, $mine);
}

/** Logout: release the token, but only if it is still OURS (a stale tab must not end the newer session). */
function mh_ss_clear($kind)
{
    $mine = (string) ($_SESSION[mh_ss_key($kind)] ?? '');
    $id   = mh_ss_account_id($kind);
    $conn = mh_ss_db();
    if ($mine === '' || $id <= 0 || !$conn) { return; }
    try {
        $t = mh_ss_table($kind);
        $stmt = $conn->prepare("UPDATE `$t` SET session_token = NULL WHERE id = ? AND session_token = ?");
        $stmt->bind_param('is', $id, $mine);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('mh_ss_clear: ' . $e->getMessage());
    }
}

function mh_ss_login_url($kind)
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($kind === 'patient') {
        $pos = strrpos($script, '/patient/');
        return ($pos !== false ? substr($script, 0, $pos) : '') . '/patient_login.php';
    }
    $pos = strrpos($script, '/admin/');
    return ($pos !== false ? substr($script, 0, $pos) : '') . '/admin/login.php';
}

function mh_ss_wants_json()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || strpos($script, '/api/') !== false
        || strpos($script, '/ocr/') !== false
        || (stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false);
}

/** Ends ONLY this kind of login (a staff session and a patient session can share one PHP session). */
function mh_ss_terminate($kind)
{
    if ($kind === 'patient') {
        unset($_SESSION['patient_id'], $_SESSION['patient_name']);
    } else {
        unset($_SESSION['staff_id'], $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['role'], $_SESSION['temp_user_id']);
    }
    unset($_SESSION[mh_ss_key($kind)]);

    if (mh_ss_wants_json()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'session_replaced' => true,
                          'error' => 'This account was signed in on another device or browser.']);
        exit();
    }
    header('Location: ' . mh_ss_login_url($kind) . '?session_replaced=1');
    exit();
}

function mh_ss_enforce($kind)
{
    if (!mh_ss_is_valid($kind)) { mh_ss_terminate($kind); }
}
