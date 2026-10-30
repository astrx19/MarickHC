<?php
/**
 * Two entry points:
 *   reset_password.php?token=<64 hex>   (link from the email)
 *   reset_password.php?method=sms       (after the code was texted; the session remembers which reset)
 */
session_start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // keep the token out of Referer headers
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/includes/password_reset_helpers.php';

$system_name = $system_name ?? mh_setting('system_name');
$csrf  = mh_pr_csrf_token();
$debug = $_SESSION['pr_debug'] ?? null; unset($_SESSION['pr_debug']);

$mode = '';          // 'email' | 'sms'
$view = 'invalid';   // 'invalid' | 'otp' | 'password' | 'success'
$error = null;       // inline form error
$fatal = 'This reset link or code is invalid, has expired, or was already used.';
$resetRow = null;
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && !mh_pr_csrf_ok($_POST['csrf'] ?? '')) { $isPost = false; $error = 'Your session expired. Please reload the page and try again.'; }
$action = $isPost ? ($_POST['action'] ?? '') : '';

if ($token !== '') {
    /* ------------------------- EMAIL LINK ------------------------- */
    $mode = 'email';
    $resetRow = mh_pr_load_by_token($conn, $token);
    if ($resetRow) { $view = 'password'; }
} elseif (!empty($_SESSION['pr_sms']) && is_array($_SESSION['pr_sms'])) {
    /* ------------------------- SMS OTP ---------------------------- */
    $mode = 'sms';
    $sms =& $_SESSION['pr_sms'];

    if (time() - (int) ($sms['at'] ?? 0) > (MH_PR_TTL_MINUTES * 60 + 60)) {
        unset($_SESSION['pr_sms']);
    } else {
        $view = 'otp';

        if ($action === 'verify_otp') {
            $code = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));
            if (strlen($code) !== 6) {
                $error = 'Enter the 6-digit code we texted you.';
            } elseif (!empty($sms['decoy'])) {
                // Nothing was sent (unknown account / throttled): behave like a wrong code, then lock.
                $sms['tries'] = (int) ($sms['tries'] ?? 0) + 1;
                if ($sms['tries'] >= MH_PR_MAX_OTP_TRIES) { unset($_SESSION['pr_sms']); $view = 'invalid'; $fatal = 'Too many incorrect attempts. Please request a new code.'; }
                else { $error = 'That code is incorrect or has expired.'; }
            } else {
                $r = mh_pr_verify_otp($conn, (int) $sms['id'], $code);
                if ($r === 'ok') { $sms['verified_at'] = time(); }
                elseif ($r === 'bad') { $error = 'That code is incorrect. Please check and try again.'; }
                else { unset($_SESSION['pr_sms']); $view = 'invalid'; $fatal = 'This code has expired or too many incorrect attempts were made. Please request a new code.'; }
            }
        }

        if ($view === 'otp' && !empty($sms['verified_at']) && time() - (int) $sms['verified_at'] <= 600) {
            $resetRow = mh_pr_load_by_id($conn, (int) $sms['id'], 'sms');
            if ($resetRow) { $view = 'password'; }
            else { unset($_SESSION['pr_sms']); $view = 'invalid'; }
        }
    }
    unset($sms);
}

/* ------------------------- SET NEW PASSWORD ------------------------- */
if ($view === 'password' && $action === 'reset_password') {
    $account = mh_pr_get_account($conn, $resetRow['user_type'] === 'staff' ? 'staff' : 'patients', $resetRow['user_id']);
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!$account) {
        $view = 'invalid'; $fatal = 'The account tied to this request is no longer available.';
    } elseif (($error = mh_pr_password_error($new, $confirm, $account['password'] ?? '')) === null) {
        if (mh_pr_complete($conn, $resetRow, $new)) {
            $view = 'success';
            unset($_SESSION['pr_sms'], $_SESSION['pr']);
            if (function_exists('logActivity') && $resetRow['user_type'] === 'staff') {
                logActivity($conn, (int) $resetRow['user_id'], $account['role'] ?? 'staff', 'Password Reset', 'Password reset via ' . $resetRow['method'] . ' recovery.');
            }
        } else {
            $error = 'We couldn\'t save the new password - the link or code may have just expired. Please request a new one.';
        }
    }
}
$loginUrl = (($resetRow['user_type'] ?? '') === 'staff') ? 'admin/login.php' : 'patient_login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex">
    <title>Reset Password - <?php echo mh_pr_h($system_name); ?></title>
    <link rel="stylesheet" href="assets/css/password_recovery.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/password_recovery.css'); ?>">
</head>
<body class="pr-body">
<div class="pr-card">
    <button type="button" class="pr-theme" id="prTheme" title="Toggle light/dark" aria-label="Toggle light or dark theme"><?php echo mh_pr_icon('moon'); ?></button>
    <div class="pr-head">
        <img src="assets/images/logo.png" alt="Clinic logo">
        <h1><?php echo $view === 'otp' ? 'Enter Your Code' : 'Reset Password'; ?></h1>
        <?php if ($view === 'otp'): ?><p>We texted a 6-digit code to your mobile number. It expires in <?php echo MH_PR_TTL_MINUTES; ?> minutes.</p>
        <?php elseif ($view === 'password'): ?><p>Choose a new password for your account.</p><?php endif; ?>
    </div>

    <?php if ($error && $view !== 'invalid'): ?>
        <div class="pr-alert err"><?php echo mh_pr_icon('alert'); ?><span><?php echo mh_pr_h($error); ?></span></div>
    <?php endif; ?>
    <?php if ($debug !== null && $view === 'otp'): ?><div class="pr-debug"><b>Local testing mode</b> (never shown on the live site)<br><?php echo $debug; ?></div><br><?php endif; ?>

<?php if ($view === 'success'): ?>
    <div class="pr-alert ok"><?php echo mh_pr_icon('check'); ?><span>Your password has been reset and any open sessions were signed out. You can now log in with your new password.</span></div>
    <a class="pr-btn" href="<?php echo mh_pr_h($loginUrl); ?>">Go to Login</a>

<?php elseif ($view === 'invalid'): ?>
    <div class="pr-alert err"><?php echo mh_pr_icon('alert'); ?><span><?php echo mh_pr_h($fatal); ?></span></div>
    <a class="pr-btn" href="forgot_password.php?restart=1">Request a New Link or Code</a>

<?php elseif ($view === 'otp'): ?>
    <form method="POST" action="reset_password.php?method=sms" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo mh_pr_h($csrf); ?>">
        <input type="hidden" name="action" value="verify_otp">
        <div class="pr-field">
            <label for="otp">6-Digit Verification Code</label>
            <input type="text" id="otp" name="otp" class="pr-otp" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   autocomplete="one-time-code" placeholder="••••••" autofocus>
        </div>
        <button type="submit" class="pr-btn">Verify Code</button>
    </form>
    <div class="pr-links"><a href="forgot_password.php?restart=1">Didn't get a code? Start over</a></div>

<?php else: /* password */ ?>
    <form method="POST" action="<?php echo $mode === 'email' ? 'reset_password.php' : 'reset_password.php?method=sms'; ?>" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo mh_pr_h($csrf); ?>">
        <input type="hidden" name="action" value="reset_password">
        <?php if ($mode === 'email'): ?><input type="hidden" name="token" value="<?php echo mh_pr_h($token); ?>"><?php endif; ?>
        <div class="pr-field">
            <label for="np">New Password</label>
            <input type="password" id="np" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters" autofocus>
        </div>
        <div class="pr-field">
            <label for="cp">Confirm New Password</label>
            <input type="password" id="cp" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="Type it again">
        </div>
        <label class="pr-show"><input type="checkbox" id="prShow"> Show passwords</label>
        <button type="submit" class="pr-btn">Save New Password</button>
    </form>
<?php endif; ?>

    <?php if ($view !== 'success'): ?>
    <div class="pr-links"><a href="patient_login.php">&larr; Back to Login</a></div>
    <?php endif; ?>
</div>
<script>
document.getElementById('prTheme').addEventListener('click', function () {
    var n = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', n);
    try { localStorage.setItem('theme', n); } catch (e) {}
});
var s = document.getElementById('prShow');
if (s) s.addEventListener('change', function () {
    ['np', 'cp'].forEach(function (id) { document.getElementById(id).type = s.checked ? 'text' : 'password'; });
});
</script>
</body>
</html>
