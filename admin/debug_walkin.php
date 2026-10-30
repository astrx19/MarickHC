<?php
/**
 * TEMPORARY - open  /admin/debug_walkin.php  instead of walkin.php to see the real PHP error on screen.
 * Works even for syntax (parse) errors in walkin.php, because the error is raised while INCLUDING it.
 * DELETE THIS FILE when you are done (it exposes error details to anyone logged in as staff).
 */
define('MH_FORCE_DEBUG', true);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require __DIR__ . '/walkin.php';
