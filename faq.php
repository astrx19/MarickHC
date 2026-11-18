<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings_helper.php';
require_once __DIR__ . '/includes/queue_helper.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FAQ - Marick Health Center</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Specific FAQ Page Styling */
        body { background-color: #f8fbfb; }
        .faq-page-header { text-align: center; padding: 60px 20px 20px; }
        .faq-page-header h1 { color: #004d80; font-size: 2.5rem; }
        .faq-page-header p { color: #7f8c8d; font-size: 1.1rem; margin-top: 10px; }
        .btn-back { display: inline-block; margin-top: 20px; color: #00bfff; text-decoration: none; font-weight: bold; }
        .btn-back:hover { text-decoration: underline; }
        
        /* FAQ Dropdowns */
        .faq-section { text-align: center; }
        .faq-container { max-width: 800px; margin: 0 auto; text-align: left; }
        details { background: white; margin-bottom: 15px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); padding: 20px; cursor: pointer; transition: 0.3s ease; }
        details:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        summary { font-size: 1.1rem; font-weight: bold; color: #2c3e50; outline: none; list-style-position: outside; }
        summary::marker { color: #00bfff; }
        details p { margin-top: 15px; color: #555; line-height: 1.6; border-top: 1px solid #eee; padding-top: 15px; }
    </style>
</head>
<body>

    <header>
        <div class="logo-area">
            <img src="assets/images/logo.png" alt="Health Center Logo" class="logo">
            <h2>MARICK HEALTH CENTER</h2>
        </div>
        
        <div class="nav">
            <a href="index.php" class="nav-link">HOME</a>
            <div class="dropdown">
                <button class="dropbtn" onclick="window.location.href='index.php'">LOGIN</button>
            </div>
        </div>
    </header>

    <div class="faq-page-header">
        <h1>Frequently Asked Questions</h1>
        <p>Find answers to common questions about our clinic and online system.</p>
    </div>

    <section class="faq-section" style="padding-top: 30px;">
        <div class="faq-container">
            
            <details>
                <summary>What are your operating hours?</summary>
                <p>We are open <?php echo htmlspecialchars(mh_operating_days_summary()); ?>, from <?php echo htmlspecialchars(mh_operating_hours_summary()); ?>. We are closed on non-operating days and declared holidays.</p>
            </details>

            <details>
                <summary>Do you accept walk-in patients?</summary>
                <p>Yes. Walk-in patients are welcome during clinic hours. Staff register you and give you a queue number for the department you need, and emergency check-ups are always accommodated.</p>
            </details>

            <details>
                <summary>Can I book an appointment online?</summary>
                <p>No. Visits are walk-in and handled through the queue at the health center. Your patient account is for viewing your own records, not for booking.</p>
            </details>

            <details>
                <summary>How do I create a patient account?</summary>
                <p>Use "Register" on the home page. If the health center already has a record for you, we verify your mobile number with a one-time code and link the account to that record instead of creating a duplicate.</p>
            </details>

            <details>
                <summary>What can I see in my patient account?</summary>
                <p>Your profile, consultation and program history, medicines dispensed to you, and your upcoming follow-up schedule. You can update your own contact details and password. Only health center staff can add or change medical records.</p>
            </details>

            <details>
                <summary>Will I get text message reminders?</summary>
                <p>Yes. When staff set a follow-up date, we send an SMS reminder the day before and on the day of the visit to the mobile number on your record. Please keep your number up to date. No reminders are sent on declared clinic holidays.</p>
            </details>

            <details>
                <summary>Are vaccines free for infants?</summary>
                <p>Yes. Mandatory routine immunizations (BCG, Hepatitis B, Pentavalent, OPV, etc.) are provided free as part of the center's health program.</p>
            </details>

            <details>
                <summary>I forgot my password. What do I do?</summary>
                <p>Use "Forgot password" on the login page. We send a reset link to your email or a code by SMS. It expires after 15 minutes.</p>
            </details>

            <details>
                <summary>Do you accept online payments or process insurance claims?</summary>
                <p>No. This system manages health records, queueing, medicine inventory and reminders only. It does not handle payments, billing or PhilHealth claims.</p>
            </details>

        </div>
    </section>

</body>
</html>