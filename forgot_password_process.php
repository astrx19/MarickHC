<?php
/**
 * Backend for forgot_password.php. POST only; always redirects (Post/Redirect/Get).
 *   action=lookup  -> find the account, store options in the session (step 1 -> 2)
 *   action=send    -> issue an email link or an SMS OTP and dispatch it (step 2 -> 3)
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

    unset($_SESSION['pr_done'], $_SESSION['pr_sms'], $_SESSION['pr']);

    // Strictly determine portal scope (staff vs patient)
    $scope = mh_pr_scope($_POST['for'] ?? ($_SESSION['pr_for'] ?? ''));
    if ($scope === '') {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $scope = (strpos($ref, 'admin') !== false || strpos($ref, 'for=staff') !== false) ? 'staff' : 'patient';
    }
    $_SESSION['pr_for'] = $scope;

    $found = mh_pr_find_account($conn, $identifier, $scope);

    // Clear error handling if account is not found in the respective table
    if (!$found) {
        if ($scope === 'staff') {
            mh_pr_flash('No staff account found with that username or email address. Please check your credentials or contact the administrator.');
        } else {
            mh_pr_flash('No patient account found with that username or email address. Please check your credentials or register at the clinic.');
        }
        mh_pr_go('forgot_password.php?for=' . $scope);
    }

    if (!empty($found['archived'])) {
        if ($scope === 'staff') {
            mh_pr_flash('This staff account has been archived. Please contact the Health Head or System Administrator.');
        } else {
            mh_pr_flash('This patient record has been archived. Please visit the health center for assistance.');
        }
        mh_pr_go('forgot_password.php?for=' . $scope);
    }

    $account = $found['row'];
    $view = mh_pr_step2_view($account);

    if (!$view['email_ok'] && !$view['sms_ok']) {
        if ($scope === 'staff') {
            mh_pr_flash('This staff account does not have a registered email address on file. Please contact the administrator.');
        } else {
            mh_pr_flash('No usable email or mobile number is registered for this account. Please visit the health center.');
        }
        mh_pr_go('forgot_password.php?for=' . $scope);
    }

    $_SESSION['pr'] = [
        'table'        => $found['table'],
        'id'           => (int) $account['id'],
        'at'           => $now,
        'email_ok'     => $view['email_ok'],
        'sms_ok'       => $view['sms_ok'],
        'email_masked' => $view['email_masked'],
        'phone_masked' => $view['phone_masked'],
    ];

    mh_pr_go('forgot_password.php?for=' . $scope);
}

/* ===================== STEP 2: send the link / code ===================== */
if ($action === 'send') {
    $method = $_POST['method'] ?? '';
    $scope  = mh_pr_scope($_POST['for'] ?? ($_SESSION['pr_for'] ?? ''));
    if ($scope === '') { $scope = 'staff'; }
    $state  = $_SESSION['pr'] ?? null;

    if (!$state || (time() - (int) ($state['at'] ?? 0)) > 900) {
        unset($_SESSION['pr']);
        mh_pr_flash('That took too long. Please start again.');
        mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
    }
    if (!in_array($method, ['email', 'sms'], true)) {
        mh_pr_flash('Please choose how you want to recover your password.');
        mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
    }

    $debug = null;
    $smsRef = ['id' => 0, 'tries' => 0, 'at' => time()];
    $deliver = null;

    $account = mh_pr_get_account($conn, $state['table'], $state['id']);
    if (!$account) {
        unset($_SESSION['pr']);
        mh_pr_flash('The account could not be found or has been deactivated.');
        mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
    }

    $ch = mh_pr_channels($account);
    $userType = ($state['table'] === 'staff') ? 'staff' : 'patient';

    if (mh_pr_throttled($conn, $userType, (int) $account['id'], mh_pr_ip())) {
        mh_pr_flash('Too many recovery attempts for this account. Please wait a while before requesting again.');
        mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
    }

    if ($method === 'email') {
        if (!$ch['email']) {
            mh_pr_flash('This account does not have a valid email address on file.');
            mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
        }
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
        } else {
            mh_pr_flash('Failed to generate reset link. Please try again.');
            mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
        }
    } elseif ($method === 'sms') {
        if (!$ch['sms']) {
            mh_pr_flash('SMS recovery is currently unavailable or no valid mobile number is registered.');
            mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
        }
        $reset = mh_pr_issue($conn, $state['table'], $account, 'sms', $ch['phone']);
        if ($reset) {
            $text = $system_name . ': Your password reset code is ' . $reset['secret']
                  . '. It expires in ' . MH_PR_TTL_MINUTES . ' minutes. Never share this code with anyone.';
            $phone = $ch['phone'];
            $accountId = (int) $account['id'];
            $deliver = function () use ($phone, $text, $userType, $accountId) {
                $res = mh_send_sms($phone, $text);
                if (!$res['success']) { error_log('forgot_password: SMS failed (' . $userType . ' #' . $accountId . '): ' . $res['error']); }
            };
            $smsRef = ['id' => (int) $reset['id'], 'tries' => 0, 'at' => time()];
            if (MAIL_DEBUG_MODE) { $debug = 'SMS code (local testing only): <b>' . mh_pr_h($reset['secret']) . '</b>'; }
        } else {
            mh_pr_flash('Failed to generate SMS code. Please try again.');
            mh_pr_go('forgot_password.php' . ($scope !== '' ? '?for=' . $scope : ''));
        }
    }

    if (function_exists('logActivity') && $state['table'] === 'staff') {
        logActivity($conn, (int) $account['id'], $account['role'] ?? 'staff', 'Password Reset Requested', 'Recovery method: ' . $method);
    }

    unset($_SESSION['pr']);
    if ($debug !== null) { $_SESSION['pr_debug'] = $debug; }

    if ($method === 'sms') {
        $_SESSION['pr_sms'] = $smsRef;
        $next = 'reset_password.php?method=sms';
    } else {
        $_SESSION['pr_done'] = ['method' => 'email', 'at' => time(), 'email_masked' => $ch['email_masked']];
        $next = 'forgot_password.php' . ($scope !== '' ? '?for=' . $scope : '');
    }

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
