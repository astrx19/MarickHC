<?php
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

if ($action === 'lookup') {
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

    $scope = mh_pr_scope($_POST['for'] ?? ($_SESSION['pr_for'] ?? ''));
    if ($scope === '') {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $scope = (strpos($ref, 'admin') !== false || strpos($ref, 'for=staff') !== false) ? 'staff' : 'patient';
    }
    $_SESSION['pr_for'] = $scope;

    $found = mh_pr_find_account($conn, $identifier, $scope);

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

if ($action === 'username') {
    $scope = mh_pr_scope($_POST['for'] ?? ($_SESSION['pr_for'] ?? '')) ?: 'patient';
    $_SESSION['pr_for'] = $scope;
    $back = 'forgot_password.php?for=' . $scope . '&mode=username';

    $now = time();
    $_SESSION['pr_user_reqs'] = array_values(array_filter($_SESSION['pr_user_reqs'] ?? [], fn($t) => $t > $now - 600));
    $last = $_SESSION['pr_user_reqs'] ? max($_SESSION['pr_user_reqs']) : 0;
    if (count($_SESSION['pr_user_reqs']) >= 3 || ($now - $last) < MH_PR_COOLDOWN_SECONDS) {
        mh_pr_flash('Too many requests. Please wait a minute or two and try again.');
        mh_pr_go($back);
    }

    $input = trim((string) ($_POST['contact'] ?? ''));
    if ($input === '' || strlen($input) > 100) {
        mh_pr_flash('Please enter your registered email address or mobile number.');
        mh_pr_go($back);
    }
    $isEmail = strpos($input, '@') !== false;
    if ($isEmail && !mh_pr_valid_email($input)) {
        mh_pr_flash('Please enter a valid email address.');
        mh_pr_go($back);
    }
    if (!$isEmail && (!function_exists('mh_normalize_ph_mobile') || mh_normalize_ph_mobile($input) === '')) {
        mh_pr_flash('Please enter a valid mobile number (e.g. 09171234567) or your registered email.');
        mh_pr_go($back);
    }
    $_SESSION['pr_user_reqs'][] = $now;

    $found = mh_pr_find_usernames($conn, $scope, $input);
    $deliver = null;
    $debug = null;

    if (!empty($found['usernames'])) {
        $names = $found['usernames'];
        if ($found['kind'] === 'email') {
            $firstName = $found['first_name'];
            $deliver = function () use ($system_name, $input, $firstName, $names, $scope) {
                $res = mh_send_email(
                    $input,
                    $system_name . ' - Your Username',
                    mh_pr_username_email_html($firstName, $system_name, $names, $scope),
                    'Your ' . $system_name . ' ' . $scope . ' username: ' . implode(', ', $names)
                );
                if (!$res['success']) { error_log('forgot_username: email failed (' . $scope . '): ' . $res['error']); }
            };
            if (MAIL_DEBUG_MODE) { $debug = 'Username(s) (local testing only): <b>' . mh_pr_h(implode(', ', $names)) . '</b>'; }
        } elseif (function_exists('mh_sms_provider_problem') && mh_sms_provider_problem() === null) {
            $phone = mh_normalize_ph_mobile($input);
            $text = $system_name . ': Your ' . $scope . ' username is ' . implode(', ', $names) . '. Never share your password with anyone.';
            $deliver = function () use ($phone, $text, $scope) {
                $res = mh_send_sms($phone, $text);
                if (!$res['success']) { error_log('forgot_username: SMS failed (' . $scope . '): ' . $res['error']); }
            };
            if (MAIL_DEBUG_MODE) { $debug = 'Username(s) (local testing only): <b>' . mh_pr_h(implode(', ', $names)) . '</b>'; }
        }
    }

    // Always the same answer, whether or not an account matched (prevents account guessing).
    $_SESSION['pr_user_done'] = ['at' => $now, 'kind' => $isEmail ? 'email' : 'sms', 'scope' => $scope];
    if ($debug !== null) { $_SESSION['pr_debug'] = $debug; }

    mh_pr_redirect_then_continue($back);
    if ($deliver) { $deliver(); }
    exit;
}

if ($action === 'send') {
    $method = $_POST['method'] ?? '';
    $state  = $_SESSION['pr'] ?? null;
    // The scope is dictated by the account found in step 1, never by a posted value,
    // so a patient flow can never send a staff reset (or the other way round).
    $scope  = $state ? (($state['table'] ?? '') === 'staff' ? 'staff' : 'patient')
                     : (mh_pr_scope($_POST['for'] ?? ($_SESSION['pr_for'] ?? '')) ?: 'patient');

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
        $_SESSION['pr_done'] = ['method' => 'email', 'at' => time(), 'email_masked' => $ch['email_masked'], 'scope' => $scope];
        $next = 'forgot_password.php' . ($scope !== '' ? '?for=' . $scope : '');
    }

    mh_pr_redirect_then_continue($next);
    if ($deliver) { $deliver(); }
    exit;
}

mh_pr_go('forgot_password.php');

function mh_absolute_url_pr($relativePath)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    return $scheme . '://' . $host . $dir . '/' . ltrim($relativePath, '/');
}