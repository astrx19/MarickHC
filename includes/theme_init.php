<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - DARK MODE INIT SNIPPET
 * =====================================================================
 * This project has no shared admin_header.php / header.php — every page
 * carries its own <head> block — so this tiny include is the shared
 * piece instead. Require it as the FIRST thing inside <head>, before
 * the admin_theme.css <link>, on every page that should support the
 * dark/light toggle:
 *
 *   <head>
 *       <?php include __DIR__ . '/../includes/theme_init.php'; ?>
 *       <meta charset="UTF-8">
 *       ...
 *       <link rel="stylesheet" href="../assets/css/admin_theme.css?...">
 *
 * DEFAULT THEME: Light Mode. With no saved choice (new visitor, new
 * browser, after sign-in) the page is light; dark mode only applies once
 * the user flips the toggle (stored in localStorage as 'theme').
 *
 * It just prints a tiny synchronous <script>. Reading localStorage and
 * setting data-theme on <html> before the stylesheet paints is what
 * avoids the light-mode "flash" on a page load in dark mode - if this
 * ran after the CSS instead, the browser would paint light, then
 * repaint dark a moment later.
 */
?><script>(function(){try{var t=localStorage.getItem('theme');/* Light (white) is the default. Dark only ever applies after the user explicitly toggles it - the OS colour-scheme preference is intentionally NOT consulted. */if(t!=='dark'){t='light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
