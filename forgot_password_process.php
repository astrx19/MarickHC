<?php
/**
 * Backend for forgot_password.php. POST only; always redirects (Post/Redirect/Get).
 *   action=lookup  -> find the account, store masked options in the session  (step 1 -> 2)
 *   action=send    -> issue an email link or an SMS OTP and dispatch it      (step 2 -> 3)
 *
 * Whatever happens, an outsider can't tell from the responses whether an account exists,
 * whether a send was rate-limited, or whether delivery failed - those only go to the error log.
 * Same-looking screens (mh_pr_step2_view) and delivery AFTER the redirect (no timing difference).
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/sms_helper.php';
require_once __DIR__ . '/includes/password_reset_helpers.php';

$system_name = $system_name ?? mh_setting('system_name');

function mh_pr_go($url) { header('Location: ' . $url); exit; }
function mh_pr_flash($msg) { $_SESSION['pr_flash'] = $msg; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { mh_pr_go('forgot_password.php'); }
if (!mh_pr_csrf_ok($_POST['csrf'] ?? '')) {
    mh_pr_flash('Your session expired. Please try again.');
    mh_pr_go('forgot_password.php');
}

$action = $_POST['action'] ?? '';

/* ===================== STEP 1: look the account up ===================== */
if ($action === 'lookup') {
    // Session-level brake on username/email guessing (8 lookups per 10 minutes).
    $now = time();
    $_SESSION['pr_lookups'] = array_values(array_filter($_SESSION['pr_lookups'] ?? [], fn($t) => $t > $now - 600));
    if (count($_SESSION['pr_lookups']) >= 8) {
        mh_pr_flash('Too many attempts. Please wait a few minutes and try again.');
        mh_pr_go('forgot_password.php');
    }
    $_SESSION['pr_lookups'][] = $now;

    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    if ($identifier === '' || strlen($identifier) > 100) {
        mh_pr_flash('Please enter your username or email address.');
        mh_pr_go('forgot_password.php');
    }

    unset($_SESSION['pr_done'], $_SESSION['pr_sms']);
    // Which login page the visitor came from: staff recovery only ever looks at the staff table.
    $scope = mh_pr_scope($_POST['for'] ?? ($_SESSION['pr_for'] ?? ''));
    $found = mh_pr_find_account($conn, $identifier, $scope);
    $view  = mh_pr_step2_view($identifier, $found);   // identical shape for real and unknown accounts
    $_SESSION['pr'] = [
        'table' => $found ? $found['table'] : '', 'id' => $found ? (int) $found['row']['id'] : 0,
        'decoy' => !$found, 'at' => $now,
        'email_ok' => $view['email_ok'], 'sms_ok' => $view['sms_ok'],
        'email_masked' => $view['email_masked'], 'phone_masked' => $view['phone_masked'],
    ];
    mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
}

/* ===================== STEP 2: send the link / code ===================== */
if ($action === 'send') {
    $method = $_POST['method'] ?? '';
    $state = $_SESSION['pr'] ?? null;

    if (!$state || (time() - (int) ($state['at'] ?? 0)) > 900) {
        unset($_SESSION['pr']);
        mh_pr_flash('That took too long. Please start again.');
        mh_pr_go('forgot_password.php');
    }
    if (!in_array($method, ['email', 'sms'], true)) {
        mh_pr_flash('Please choose how you want to recover your password.');
        mh_pr_go('forgot_password.php');
    }

    $debug = null;
    // Default = "nothing was really sent". Real sends overwrite $smsRef below.
    $smsRef = ['id' => 0, 'decoy' => true, 'tries' => 0, 'at' => time()];
    $deliver = null;   // runs AFTER the redirect has been sent (see mh_pr_redirect_then_continue)

    $account = $state['decoy'] ? null : mh_pr_get_account($conn, $state['table'], $state['id']);
    if ($account) {
        $ch = mh_pr_channels($account);
        $userType = ($state['table'] === 'staff') ? 'staff' : 'patient';

        if (!mh_pr_throttled($conn, $userType, (int) $account['id'], mh_pr_ip())) {
            if ($method === 'email' && $ch['email']) {
                $emailTo = trim($account['email']);
                $reset = mh_pr_issue($conn, $state['table'], $account, 'email', $emailTo);
                if ($reset) {
                    $link = mh_absolute_url_pr('reset_password.php?token=' . $reset['secret']);
                    $firstName = trim($account['first_name'] ?? '');
                    $accountId = (int) $account['id'];
                    $deliver = function () use ($system_name, $emailTo, $firstName, $link, $userType, $accountId) {
                        $res = mh_send_email(
                            $emailTo,
                            $system_name . ' - Password Reset Request',
                            mh_pr_email_html($firstName, $system_name, $link),
                            "Reset your password: $link\n\nThis link works once and expires in " . MH_PR_TTL_MINUTES . " minutes. If you didn't request it, ignore this email."
                        );
                        if (!$res['success']) { error_log('forgot_password: email failed (' . $userType . ' #' . $accountId . '): ' . $res['error']); }
                    };
                    if (MAIL_DEBUG_MODE) { $debug = 'Reset link: <a href="' . mh_pr_h($link) . '">' . mh_pr_h($link) . '</a>'; }
                }
            } elseif ($method === 'sms' && $ch['sms']) {
                $reset = mh_pr_issue($conn, $state['table'], $account, 'sms', $ch['phone']);
                if ($reset) {
                    $text = $system_name . ': Your password reset code is ' . $reset['secret']
                          . '. It expires in ' . MH_PR_TTL_MINUTES . ' minutes. Never share this code with anyone.';
                    $phone = $ch['phone']; $accountId = (int) $account['id'];
                    $deliver = function () use ($phone, $text, $userType, $accountId) {
                        $res = mh_send_sms($phone, $text);
                        if (!$res['success']) { error_log('forgot_password: SMS failed (' . $userType . ' #' . $accountId . '): ' . $res['error']); }
                    };
                    $smsRef = ['id' => (int) $reset['id'], 'decoy' => false, 'tries' => 0, 'at' => time()];
                    if (MAIL_DEBUG_MODE) { $debug = 'SMS code (local testing only): <b>' . mh_pr_h($reset['secret']) . '</b>'; }
                }
            }
            if (function_exists('logActivity') && $state['table'] === 'staff') {
                logActivity($conn, (int) $account['id'], $account['role'] ?? 'staff', 'Password Reset Requested', 'Recovery method: ' . $method);
            }
        }
    }

    $scope = mh_pr_scope($_SESSION['pr_for'] ?? '');
    unset($_SESSION['pr']);
    if ($debug !== null) { $_SESSION['pr_debug'] = $debug; }

    if ($method === 'sms') {
        $_SESSION['pr_sms'] = $smsRef;
        $next = 'reset_password.php?method=sms';
    } else {
        $_SESSION['pr_done'] = ['method' => 'email', 'at' => time()];
        $next = 'forgot_password.php' . ($scope !== '' ? '?for=' . $scope : '');
    }

    // Unknown accounts, throttled accounts and real ones now all answer at the same speed.
    mh_pr_redirect_then_continue($next);
    if ($deliver) { $deliver(); }
    exit;
}

mh_pr_go('forgot_password.php');

/** Absolute URL for a root-level script (works from this root-level file). */
function mh_absolute_url_pr($relativePath)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    return $scheme . '://' . $host . $dir . '/' . ltrim($relativePath, '/');
}
