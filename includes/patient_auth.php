<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Same fix as includes/admin_auth.php: stop the page from being cached
// so the browser "back" button can't reveal it after logout.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

if (!isset($_SESSION['patient_id'])) {
    header("Location: ../index.php");
    exit();
}

// One active session per patient account (newest sign-in wins).
require_once __DIR__ . '/session_guard.php';
mh_ss_enforce('patient');
?>
