<?php
/**
 * Global injector for the custom modal & notification engine
 * (mh_alert / mh_confirm / mh_prompt / mh_toast) AND the browser-tab favicon.
 *
 * Include this once inside <head>...</head> on every page.
 *
 * Path handling: define MH_ASSET_PATH before including this file if the
 * page is NOT one folder below the project root (e.g. root-level pages
 * like index.php / patient_login.php use 'assets/'). Pages inside
 * admin/ or patient/ can just include this file as-is; it defaults to
 * '../assets/'.
 */
require_once __DIR__ . '/ui_helpers.php';   // mh_js(), mh_notify_script(), mh_flash_scripts()
$mh_asset_path = defined('MH_ASSET_PATH') ? MH_ASSET_PATH : '../assets/';

// ---- Favicon -------------------------------------------------------------
// Root-absolute URL so it resolves identically from /, /admin/, /patient/ and
// from clean URLs (e.g. /admin/dashboard). Works on the live site (app at the
// web root) and on XAMPP (app in a subfolder) because the base is derived from
// where this project actually sits under DOCUMENT_ROOT.
$mh_fav_docroot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$mh_fav_appdir  = realpath(__DIR__ . '/..');
$mh_fav_base    = '';
if ($mh_fav_docroot && $mh_fav_appdir && strpos($mh_fav_appdir, $mh_fav_docroot) === 0) {
    $mh_fav_base = str_replace('\\', '/', substr($mh_fav_appdir, strlen($mh_fav_docroot)));
}
$mh_fav_url = $mh_fav_base . '/assets/images/logo.png?v=' . (int) @filemtime(__DIR__ . '/../assets/images/logo.png');
?>
<link rel="icon" type="image/png" href="<?php echo htmlspecialchars($mh_fav_url); ?>">
<link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($mh_fav_url); ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($mh_fav_url); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($mh_asset_path); ?>css/custom_modal.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/custom_modal.css'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($mh_asset_path); ?>css/mh_ui.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/mh_ui.css'); ?>">
<script src="<?php echo htmlspecialchars($mh_asset_path); ?>js/custom_modal.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/custom_modal.js'); ?>" defer></script>
<script src="<?php echo htmlspecialchars($mh_asset_path); ?>js/mh_ui.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/mh_ui.js'); ?>" defer></script>
<script src="<?php echo htmlspecialchars($mh_asset_path); ?>js/mh_forms.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/mh_forms.js'); ?>" defer></script>