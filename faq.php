<?php
session_start();
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
                <summary>Mision</summary>
                <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
            </details>

            <details>
                <summary>Vision</summary>
                <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
            </details>


        </div>
    </section>

</body>
</html>