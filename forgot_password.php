<?php
session_start();
header('Cache-Control: no-store');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/includes/password_reset_helpers.php';

$system_name = $system_name ?? mh_setting('system_name');

// Remember which login page the visitor came from (staff console vs patient page).
if (isset($_GET['for'])) { $_SESSION['pr_for'] = mh_pr_scope($_GET['for']); }
$scope     = mh_pr_scope($_SESSION['pr_for'] ?? '');
$forQuery  = $scope !== '' ? '?for=' . $scope : '';
$loginHref = $scope === 'staff' ? 'admin/login.php' : 'patient_login.php';

if (isset($_GET['restart'])) {
    unset($_SESSION['pr'], $_SESSION['pr_done'], $_SESSION['pr_sms'], $_SESSION['pr_debug']);
    header('Location: forgot_password.php' . $forQuery);
    exit;
}

$flash = $_SESSION['pr_flash'] ?? null; unset($_SESSION['pr_flash']);
$debug = $_SESSION['pr_debug'] ?? null; unset($_SESSION['pr_debug']);
$state = $_SESSION['pr'] ?? null;
if ($state && (time() - (int) ($state['at'] ?? 0)) > 900) { unset($_SESSION['pr']); $state = null; }
$done = $_SESSION['pr_done'] ?? null;
$step = $done ? 3 : ($state ? 2 : 1);
$csrf = mh_pr_csrf_token();
$no_option = $step === 2 && !$state['email_ok'] && !$state['sms_ok'];   // only when the SYSTEM offers no channel
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Forgot Password - <?php echo mh_pr_h($system_name); ?></title>
    <link rel="stylesheet" href="assets/css/password_recovery.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/password_recovery.css'); ?>">
</head>
<body class="pr-body">
<div class="pr-card">
    <button type="button" class="pr-theme" id="prTheme" title="Toggle light/dark" aria-label="Toggle light or dark theme"><?php echo mh_pr_icon('moon'); ?></button>

    <div class="pr-head">
        <img src="assets/images/logo.png" alt="Clinic logo">
        <h1>Forgot Password?</h1>
        <p><?php
            if ($step === 1) echo 'Enter your username or registered email and we\'ll help you get back in.';
            elseif ($step === 2) echo 'Choose how you\'d like to receive your recovery instructions.';
            else echo 'Check your inbox.';
        ?></p>
    </div>
    <div class="pr-steps"><i class="on"></i><i class="<?php echo $step >= 2 ? 'on' : ''; ?>"></i><i class="<?php echo $step >= 3 ? 'on' : ''; ?>"></i></div>

    <?php if ($flash && !$no_option && $step !== 3): /* one alert at a time: step 3 / "no option" carry their own */ ?>
        <div class="pr-alert err"><?php echo mh_pr_icon('alert'); ?><span><?php echo mh_pr_h($flash); ?></span></div>
    <?php endif; ?>

<?php if ($step === 1): ?>
    <form method="POST" action="forgot_password_process.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo mh_pr_h($csrf); ?>">
        <input type="hidden" name="action" value="lookup">
        <input type="hidden" name="for" value="<?php echo mh_pr_h($scope); ?>">
        <div class="pr-field">
            <label for="identifier"><?php echo $scope === 'staff' ? 'Staff Username or Email Address' : 'Username or Email Address'; ?></label>
            <input type="text" id="identifier" name="identifier" required maxlength="100" autofocus
                   placeholder="e.g. juandelacruz or your@email.com">
        </div>
        <button type="submit" class="pr-btn">Continue</button>
    </form>

<?php elseif ($step === 2): ?>
    <div class="pr-summary">
        <div>Email on file: <b><?php echo mh_pr_h($state['email_masked']); ?></b></div>
        <div>Mobile on file: <b><?php echo mh_pr_h($state['phone_masked']); ?></b></div>
    </div>

    <?php if ($no_option): ?>
        <div class="pr-alert err"><?php echo mh_pr_icon('alert'); ?><span>Password recovery is not available right now. Please visit the health center so staff can help you.</span></div>
        <div class="pr-links"><a href="forgot_password.php?restart=1">Start over</a></div>
    <?php else: ?>
    <form method="POST" action="forgot_password_process.php">
        <input type="hidden" name="csrf" value="<?php echo mh_pr_h($csrf); ?>">
        <input type="hidden" name="action" value="send">
        <div class="pr-opts">
            <label class="pr-opt <?php echo $state['email_ok'] ? '' : 'off'; ?>">
                <input type="radio" name="method" value="email" required <?php echo $state['email_ok'] ? 'checked' : 'disabled'; ?>>
                <?php echo mh_pr_icon('mail'); ?>
                <span><strong>Send Password Reset Link via Email</strong>
                <small><?php echo mh_pr_h($state['email_masked']); ?></small></span>
            </label>
            <label class="pr-opt <?php echo $state['sms_ok'] ? '' : 'off'; ?>">
                <input type="radio" name="method" value="sms" <?php echo $state['sms_ok'] ? ($state['email_ok'] ? '' : 'checked') : 'disabled'; ?>>
                <?php echo mh_pr_icon('sms'); ?>
                <span><strong>Send 6-Digit Verification Code via SMS</strong>
                <small><?php echo $state['sms_ok'] ? mh_pr_h($state['phone_masked']) : 'Not available right now'; ?></small></span>
            </label>
        </div>
        <button type="submit" class="pr-btn">Send Recovery Instructions</button>
    </form>
    <div class="pr-links"><a href="forgot_password.php?restart=1">Not you? Start over</a></div>
    <?php endif; ?>

<?php else: ?>
    <div class="pr-alert ok"><?php echo mh_pr_icon('check'); ?><span>If the account can receive it, we've sent a password reset link to the email on file. It works once and expires in <?php echo MH_PR_TTL_MINUTES; ?> minutes. Check your spam folder too. Still nothing? Visit the health center and staff can help.</span></div>
    <?php if ($debug !== null): ?><div class="pr-debug"><b>Local testing mode</b> (never shown on the live site)<br><?php echo $debug; ?></div><?php endif; ?>
    <div class="pr-links"><a href="forgot_password.php?restart=1">Didn't get it? Request again (wait a minute first)</a></div>
<?php endif; ?>

    <div class="pr-links">
        <?php if ($scope === 'staff'): ?>
            <a href="admin/login.php">&larr; Staff Login</a>
            <a href="patient_login.php">Patient Login &rarr;</a>
        <?php else: ?>
            <a href="patient_login.php">&larr; Patient Login</a>
            <a href="admin/login.php">Staff Login &rarr;</a>
        <?php endif; ?>
    </div>
</div>
<script>
document.getElementById('prTheme').addEventListener('click', function () {
    var n = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', n);
    try { localStorage.setItem('theme', n); } catch (e) {}
});
</script>
</body>
</html>
