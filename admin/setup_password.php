<?php
/**
 * Staff password setup / recovery redirect.
 * First-login password setup is handled in admin/login.php.
 * Staff password recovery is handled in forgot_password.php?for=staff and reset_password.php.
 */
if (isset($_GET['token']) || (isset($_GET['method']) && $_GET['method'] === 'sms')) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ../reset_password.php' . $qs);
    exit();
}

header('Location: ../forgot_password.php?for=staff');
exit();
