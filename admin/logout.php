<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/session_guard.php';
mh_ss_clear('staff');   // release the single-session token while the session still knows it

$_SESSION = array();

// Also clear the session cookie itself, not just the server-side data,
// so no valid session id is left sitting in the browser.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
header("Pragma: no-cache");
header("Location: login.php?logged_out=1");
exit();
?>
