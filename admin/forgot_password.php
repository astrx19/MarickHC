<?php
$qs = !empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : '';
header('Location: ../forgot_password.php?for=staff' . $qs);
exit();

