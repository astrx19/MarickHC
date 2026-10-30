<?php
/**
 * =====================================================================
 * BARANGAY MARICK HEALTH CENTER - STAFF CONSOLE LOGIN
 * =====================================================================
 * Dedicated, isolated entry point for staff/doctors/nurses/admins.
 * Deliberately NOT protected by includes/admin_auth.php (that would
 * bounce a logged-out visitor straight back here in a loop) - instead
 * it does its own "already logged in?" check below and uses
 * includes/auth.php directly for the session start + no-cache headers
 * every auth-adjacent page needs.
 * =====================================================================
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/icons.php';

// Already signed in? Skip straight to the dashboard instead of showing
// the login form again.
if (mh_staff_logged_in()) {
    header("Location: dashboard.php");
    exit();
}

$error_msg = ""; $success_msg = "";
if (isset($_GET['logged_out'])) { $success_msg = "You have been successfully logged out."; }
if (isset($_GET['session_replaced'])) { $error_msg = "You were signed out because this account was signed in on another device or browser."; }

if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM staff WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $query = $stmt->get_result();

    if ($query->num_rows > 0) {
        $user = $query->fetch_assoc();
        $account_archived = isset($user['status']) && (int) $user['status'] === 0;
        if ($account_archived && password_verify($password, $user['password'])) {
            $error_msg = "This account has been archived. Please contact the Health Head.";
        } elseif (!$account_archived && password_verify($password, $user['password'])) {
            // New session id at every privilege change, so a session id
            // captured before login can't be reused afterwards.
            session_regenerate_id(true);

            if ($user['is_first_login'] == 1) {
                $_SESSION['temp_user_id'] = $user['id'];
                $require_password_change = true;
            } else {
                // Canonical staff session keys.
                $_SESSION['staff_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                // Legacy aliases - kept in sync so the existing admin/*.php
                // pages that still read `user_id` / `role` directly keep
                // working unchanged. See includes/auth.php for details.
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                mh_ss_issue('staff', $user['id']);   // invalidates this account's other sessions

                logActivity($conn, $user['id'], $user['role'], "System Login", "User logged into the portal.");
                header("Location: dashboard.php");
                exit();
            }
        } else { $error_msg = "Incorrect username or password."; }
    } else { $error_msg = "Incorrect username or password."; }

    $stmt->close();
}

if (isset($_POST['change_password']) && isset($_SESSION['temp_user_id'])) {
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];
    $temp_id = $_SESSION['temp_user_id'];

    if ($new_pass === $confirm_pass) {
        if (strlen($new_pass) >= 8) {
            $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("UPDATE staff SET password = ?, is_first_login = 0 WHERE id = ?");
            $stmt->bind_param("si", $hashed_pass, $temp_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("SELECT role FROM staff WHERE id = ?");
            $stmt->bind_param("i", $temp_id);
            $stmt->execute();
            $user_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            session_regenerate_id(true);

            // Canonical + legacy keys, same as the normal login path above.
            $_SESSION['staff_id'] = $temp_id;
            $_SESSION['user_role'] = $user_data['role'];
            $_SESSION['user_id'] = $temp_id;
            $_SESSION['role'] = $user_data['role'];
            unset($_SESSION['temp_user_id']);
            mh_ss_issue('staff', $temp_id);

            logActivity($conn, $temp_id, $user_data['role'], "Password Setup", "User completed first-time login setup.");
            header("Location: dashboard.php");
            exit();
        } else {
            $error_msg = "Password must be at least 8 characters long.";
            $require_password_change = true;
        }
    } else {
        $error_msg = "Passwords do not match. Please try again.";
        $require_password_change = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php /* New session / unauthenticated visitor => Light Mode. Clears any dark choice saved by a previous sign-in so the portal always opens white; dark only returns when the user toggles it again. */ ?>
    <script>try{localStorage.removeItem('theme');}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Marick Health Center - Staff Console</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <style>
        .pw-toggle { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: 0; padding: 6px;
                     cursor: pointer; color: #64748b; display: flex; align-items: center; border-radius: 6px; line-height: 0; }
        .pw-toggle:hover, .pw-toggle:focus-visible { color: #0f172a; background: rgba(100,116,139,.12); outline: none; }
        .pw-toggle .icon-eye-off { display: none; }
        .pw-toggle.is-visible .icon-eye { display: none; }
        .pw-toggle.is-visible .icon-eye-off { display: block; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body class="bg-auth">

    <div class="auth-container login-box">
        <div class="auth-header">
            <img src="../assets/images/logo.png" alt="Marick Logo">
            <h1><?php echo htmlspecialchars($system_name); ?></h1>
            <p>Staff Console</p>
        </div>

        <?php if($success_msg): ?><div class="alert alert-success" style="display:flex; align-items:center; gap:8px;"><?php echo mh_icon('check-circle'); ?> <?php echo $success_msg; ?></div><?php endif; ?>
        <?php if($error_msg): ?><div class="alert alert-error" style="display:flex; align-items:center; gap:8px;"><?php echo mh_icon('x-circle'); ?> <?php echo $error_msg; ?></div><?php endif; ?>

        <?php if(isset($require_password_change) && $require_password_change): ?>
            <div class="alert alert-success" style="background: #e0f2fe; color: #0369a1; border-color: #bae6fd;">
                Welcome! Please set a secure, private password to continue.
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>New Password</label>
                    <div style="position: relative;">
                        <input type="password" name="new_password" id="newPass" autocomplete="new-password" required placeholder="Minimum 8 characters" style="width: 100%; padding-right: 40px; box-sizing: border-box;">
                        <button type="button" class="pw-toggle" data-target="newPass" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div style="position: relative;">
                        <input type="password" name="confirm_password" id="confPass" autocomplete="new-password" required placeholder="Type password again" style="width: 100%; padding-right: 40px; box-sizing: border-box;">
                        <button type="button" class="pw-toggle" data-target="confPass" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button>
                    </div>
                </div>
                <button type="submit" name="change_password" class="btn-submit" style="margin-top: 15px;">Save & Enter Dashboard</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <div class="form-group"><label>Username</label><input type="text" name="username" required placeholder="Your username" style="width: 100%; box-sizing: border-box;" autofocus autocomplete="username"></div>
                <div class="form-group">
                    <label>Password</label>
                    <div style="position: relative;">
                        <input type="password" name="password" id="logPass" autocomplete="current-password" required placeholder="••••••••" style="width: 100%; padding-right: 40px; box-sizing: border-box;">
                        <button type="button" class="pw-toggle" data-target="logPass" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button>
                    </div>
                </div>
                <div style="text-align: right; margin-top: -12px; margin-bottom: 15px;">
                    <a href="../forgot_password.php" style="color: #4ca1af; font-size: 0.85rem; text-decoration: none; font-weight: bold;">Forgot Password?</a>
                </div>
                <button type="submit" name="login" class="btn-submit">Sign In</button>
            </form>
            <a href="../index.php" class="auth-link">Are you a patient? <span>Return to Homepage &rarr;</span></a>
        <?php endif; ?>
    </div>

    <script>
        // Show / hide password. One delegated handler covers every .pw-toggle button on the page.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.pw-toggle');
            if (!btn) { return; }
            var input = document.getElementById(btn.getAttribute('data-target'));
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    </script>
</body>
</html>
