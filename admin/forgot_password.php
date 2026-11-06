<?php
/**
 * Staff password recovery redirect.
 * Redirects to the staff-scoped password recovery page.
 */
$qs = !empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : '';
header('Location: ../forgot_password.php?for=staff' . $qs);
exit();

