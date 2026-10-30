<?php
/**
 * Step B screen of the account-claim flow (see register_process.php).
 * Shows the OTP form. Reachable only while a claim is pending in the session.
 * The mobile number is displayed MASKED and is the one already on file - the visitor cannot change it.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings_helper.php';   // mh_csrf_field()

$pending = $_SESSION['claim_pending'] ?? null;
$otp     = $_SESSION['claim_otp'] ?? null;
if (!$pending || !$otp || (int) $pending['patient_id'] !== (int) $otp['patient_id']) {
    unset($_SESSION['claim_pending'], $_SESSION['claim_otp']);
    header('Location: index.php?reg_status=claim_expired');
    exit();
}

$flash = $_SESSION['claim_flash'] ?? '';
unset($_SESSION['claim_flash']);
$seconds_left = max(0, (int) $otp['expires'] - time());
$cooldown_left = max(0, 60 - (time() - (int) $otp['sent_at']));
$system_name = $system_name ?? mh_setting('system_name');

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Record - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <style>
        body { background:#f1f5f9; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; font-family:Arial,sans-serif; }
        .card { background:#fff; width:100%; max-width:440px; padding:36px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.06); text-align:center; }
        h2 { margin:0 0 6px; color:#1e293b; font-family:Georgia,serif; }
        p { color:#64748b; font-size:.95rem; line-height:1.5; }
        .otp { width:100%; box-sizing:border-box; font-size:1.8rem; letter-spacing:.5em; text-align:center; padding:12px; border:2px solid #cbd5e1; border-radius:8px; margin:14px 0; }
        .btn { width:100%; padding:12px; border:0; border-radius:8px; font-weight:bold; cursor:pointer; font-size:1rem; margin-top:8px; }
        .btn-primary { background:var(--primary,#4ca1af); color:#fff; }
        .btn-light { background:#e2e8f0; color:#334155; }
        .flash { background:#fef3c7; color:#92400e; padding:10px; border-radius:8px; margin-bottom:14px; font-size:.9rem; }
        .row { display:flex; gap:8px; }
        .row form { flex:1; }
    </style>
</head>
<body>
<div class="card">
    <h2>Verify It's You</h2>
    <p>We found an existing clinic record that matches your details. For your privacy, we sent a 6-digit code by SMS to the
       mobile number we have on file (<strong><?php echo htmlspecialchars($otp['masked']); ?></strong>).</p>

    <?php if ($flash !== ''): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <form method="POST" action="register_process.php" autocomplete="off">
        <?php echo mh_csrf_field(); ?>
        <input type="text" name="otp_code" class="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
               autocomplete="one-time-code" placeholder="••••••" required autofocus>
        <button type="submit" name="claim_verify_otp" value="1" class="btn btn-primary">Verify &amp; Link My Account</button>
    </form>

    <p style="font-size:.85rem;">Code expires in <span id="left"><?php echo (int) floor($seconds_left / 60) . ':' . str_pad((string) ($seconds_left % 60), 2, '0', STR_PAD_LEFT); ?></span>.
       No longer have that number? Please visit the health center so staff can update your record.</p>

    <div class="row">
        <form method="POST" action="register_process.php">
            <?php echo mh_csrf_field(); ?>
            <button type="submit" name="claim_resend_otp" value="1" class="btn btn-light" id="resendBtn" <?php echo $cooldown_left > 0 ? 'disabled' : ''; ?>>
                Resend code<?php echo $cooldown_left > 0 ? ' (<span id="cd">' . (int) $cooldown_left . '</span>s)' : ''; ?>
            </button>
        </form>
        <form method="POST" action="register_process.php">
            <?php echo mh_csrf_field(); ?>
            <button type="submit" name="claim_cancel" value="1" class="btn btn-light">Cancel</button>
        </form>
    </div>
</div>
<script>
    (function () {
        var s = <?php echo (int) $seconds_left; ?>, c = <?php echo (int) $cooldown_left; ?>;
        var left = document.getElementById('left'), cd = document.getElementById('cd'), btn = document.getElementById('resendBtn');
        setInterval(function () {
            if (s > 0) { s--; left.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
            if (c > 0) { c--; if (cd) cd.textContent = c; if (c === 0 && btn) { btn.disabled = false; btn.textContent = 'Resend code'; } }
        }, 1000);
    })();
</script>
</body>
</html>
