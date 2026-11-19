<?php
header("Location: login.php" . (isset($_GET['logged_out']) ? '?logged_out=1' : ''));
exit();
