<?php
/**
 * Legacy entry point. The actual Staff Console login now lives at
 * admin/login.php (see includes/auth.php for the guard that sends any
 * unauthenticated staff visitor there). This file is kept as a thin
 * redirect purely so old bookmarks/typed URLs to "/admin" or
 * "/admin/index.php" - which Apache serves by default for a bare
 * "/admin" directory request - still land somewhere useful.
 */
header("Location: login.php" . (isset($_GET['logged_out']) ? '?logged_out=1' : ''));
exit();
