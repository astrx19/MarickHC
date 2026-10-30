<?php 
session_start();
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/config/database.php';   // loads DB-backed branding ($system_name)

$system_name = $system_name ?? mh_setting('system_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Login - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css'); ?>"> 
    <style>
        body {
            background-color: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            font-family: Arial, sans-serif;
        }
        .login-card {
            background: white;
            width: 100%;
            max-width: 450px;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            text-align: center;
        }
        .login-header img {
            width: 70px;
            margin-bottom: 15px;
        }
        .login-header h2 {
            margin: 0;
            color: #1e293b;
            font-family: Georgia, serif;
            font-size: 1.8rem;
        }
        .login-header p {
            color: #64748b;
            margin: 5px 0 30px 0;
            font-size: 0.95rem;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #475569;
            font-size: 0.9rem;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 1rem;
            box-sizing: border-box;
            transition: 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #4ca1af;
            box-shadow: 0 0 0 3px rgba(76, 161, 175, 0.2);
        }
        .btn-login {
            background: #4ca1af;
            color: white;
            border: none;
            width: 100%;
            padding: 15px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 1.1rem;
            cursor: pointer;
            transition: 0.3s;
        }
        .btn-login:hover {
            background: #3b828e;
            transform: translateY(-2px);
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 20px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.9rem;
            transition: 0.3s;
        }
        .back-link .mh-icon { width: 14px; height: 14px; }
        .back-link:hover {
            color: #4ca1af;
        }

    </style>
    <?php define('MH_ASSET_PATH', 'assets/'); include __DIR__ . '/includes/modal_assets.php'; ?>
</head>
<body>

    <div class="login-card">
        <div class="login-header">
            <img src="assets/images/logo.png" alt="Clinic Logo">
            <h2>Patient Portal</h2>
            <p>Welcome back! Please login to your account.</p>
        </div>
        
        <form action="patient_login_process.php" method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="Your username" autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div style="position: relative;">
                    <input type="password" name="password" id="logPass" required placeholder="••••••••" style="width: 100%; padding-right: 40px; box-sizing: border-box;">
                    <span onclick="togglePass('logPass', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b; display: flex;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </span>
                </div>
            </div>
            <div style="text-align: right; margin-top: -12px; margin-bottom: 22px;">
                <a href="forgot_password.php" style="color: #4ca1af; font-size: 0.85rem; text-decoration: none; font-weight: bold;">Forgot Password?</a>
            </div>
            <button type="submit" class="btn-login">Secure Login</button>
        </form>
        
        <a href="index.php" class="back-link"><?php echo mh_icon('arrow-left'); ?> Return to Homepage</a>
    </div>

    <script>
        function togglePass(inputId, iconSpan) {
            let input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                iconSpan.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
            } else {
                input.type = 'password';
                iconSpan.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
            }
        }

        <?php if(isset($_GET['error']) || isset($_GET['session_replaced'])): ?>
            let errorMessage = "";
            
            <?php if(($_GET['error'] ?? '') === 'incorrect_password'): ?>
                errorMessage = "Incorrect password. Please try again.";
            <?php elseif(($_GET['error'] ?? '') === 'not_found'): ?>
                errorMessage = "Account not found. Please register as a new patient on the homepage.";
            <?php elseif(isset($_GET['session_replaced'])): ?>
                errorMessage = "You were signed out because this account was signed in on another device or browser.";
            <?php endif; ?>

            if (errorMessage !== "") {
                // Unified alert modal (assets/js/custom_modal.js) - runs once the deferred scripts are ready.
                document.addEventListener('DOMContentLoaded', function () {
                    mh_alert(errorMessage, 'Login Failed', 'danger').then(function () {
                        window.history.replaceState(null, null, window.location.pathname);
                    });
                });
            }
        <?php endif; ?>
    </script>

</body>
</html>