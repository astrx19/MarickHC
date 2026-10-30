<?php
/**
 * =====================================================================
 * MASTER SYSTEM SETTINGS
 * =====================================================================
 * One screen to configure, with no code editing:
 *   1. Branding    - clinic name, header text, hero copy, logo, background
 *   2. Appearance  - full colour theme, written out as CSS variables
 *   3. Masterlists - every dropdown in the system (streets, delivery
 *                    types, breastfeeding types, vaccine categories, ...)
 *   4. OCR         - Gemini model + a live connection test
 *
 * The database is the source of truth. On every save the page also
 * (nothing is written to config/ any more - every page reads the database live)
 * and the older pages that used to include static files
 * keep working without modification.
 *
 * All writes use prepared statements (see includes/settings_helper.php).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);          // never leak stack traces to staff

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';   // <-- was missing before: $conn and
                                                    //     logActivity() were undefined here
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/departments_tab.php';
require_once __DIR__ . '/../includes/disease_tab.php';

// Global system / API configuration is Health Head only. Plain Admins
// manage day-to-day operations and delegation from Staff Management.
require_health_head();

$user_id   = (int) ($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'health_head';

// Creates the tables + seeds defaults on first visit, then caches settings.
mh_settings_boot($conn, true);
mh_roles_install($conn);   // creates system_roles / role_permissions + seeds them on first visit (idempotent)

$active_tab = $_GET['tab'] ?? 'branding';
$allowed_tabs = ['branding', 'appearance', 'masterlists', 'departments', 'disease', 'ocr', 'sms', 'modules', 'roles'];
if (!in_array($active_tab, $allowed_tabs, true)) { $active_tab = 'branding'; }

$list_key  = $_GET['list'] ?? 'streets';
$all_lists = mh_managed_lists();
if (!array_key_exists($list_key, $all_lists)) { $list_key = 'streets'; }

/* =====================================================================
 * POST HANDLING  (Post/Redirect/Get so a refresh never re-submits)
 * ================================================================== */

function mh_redirect($tab, $type, $message, $list = null)
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $_SESSION['mh_flash'] = ['type' => $type, 'message' => $message];

    $url = 'system_settings.php?tab=' . urlencode($tab);
    if ($list !== null) { $url .= '&list=' . urlencode($list); }

    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!mh_csrf_check()) {
        mh_redirect($active_tab, 'error', 'Your session token expired. Please try that again.');
    }

    /* ---------------- 1. BRANDING & CONTENT ---------------------- */
    if (isset($_POST['save_branding'])) {

        $pairs = [
            'system_name'           => trim($_POST['system_name'] ?? ''),
            'header_title'          => trim($_POST['header_title'] ?? ''),
            'header_tagline'        => trim($_POST['header_tagline'] ?? ''),
            'hero_title'            => trim($_POST['hero_title'] ?? ''),
            'hero_subtitle'         => trim($_POST['hero_subtitle'] ?? ''),
            'global_announcement'   => trim($_POST['global_announcement'] ?? ''),
            'enable_online_booking' => isset($_POST['enable_online_booking']) ? '1' : '0',
            // Address jurisdiction (replaces the old hardcoded "Marick" checks everywhere)
            'home_address_type'     => mb_substr(trim($_POST['home_address_type'] ?? ''), 0, 60),
            'outside_address_type'  => mb_substr(trim($_POST['outside_address_type'] ?? ''), 0, 60),
            'home_address_suffix'   => mb_substr(trim($_POST['home_address_suffix'] ?? ''), 0, 150),
        ];

        if ($pairs['home_address_type'] === '' || $pairs['outside_address_type'] === '') {
            mh_redirect('branding', 'error', 'The Home Address Type and the Outside label cannot be blank.');
        }
        if (strcasecmp($pairs['home_address_type'], $pairs['outside_address_type']) === 0) {
            mh_redirect('branding', 'error', 'The Home Address Type and the Outside label must be different.');
        }
        if ($pairs['system_name'] === '') {
            mh_redirect('branding', 'error', 'The clinic name cannot be blank.');
        }

        $notes = [];

        // -- image uploads -------------------------------------------
        $logo = mh_handle_image_upload('new_logo', 'assets/images/logo.png');
        if (!$logo['ok']) {
            mh_redirect('branding', 'error', 'Logo: ' . $logo['message']);
        }
        if (!empty($logo['path'])) { $notes[] = 'logo'; }

        $bg = mh_handle_image_upload('new_background', 'assets/images/barangay_bg.png');
        if (!$bg['ok']) {
            mh_redirect('branding', 'error', 'Background: ' . $bg['message']);
        }
        if (!empty($bg['path'])) { $notes[] = 'background image'; }

        if (!mh_set_settings($conn, $pairs, $user_id)) {
            mh_redirect('branding', 'error', 'The database rejected the update. Nothing was saved.');
        }

        logActivity($conn, $user_id, $user_role, 'System Settings', 'Updated branding and global content.');

        $msg = 'Branding saved.';
        if ($notes) { $msg .= ' Updated ' . implode(' and ', $notes) . ' - press Ctrl+F5 if the old image is still showing.'; }
        mh_redirect('branding', 'success', $msg);
    }

    /* ---------------- 2. THEME COLOURS --------------------------- */
    if (isset($_POST['save_theme'])) {

        $defaults = mh_theme_defaults();
        $pairs    = [];
        foreach ($defaults as $key => $default) {
            $pairs[$key] = mh_sanitize_color($_POST[$key] ?? $default, $default);
        }

        if (!mh_set_settings($conn, $pairs, $user_id)) {
            mh_redirect('appearance', 'error', 'The database rejected the colour update. Nothing was saved.');
        }

        $result = mh_write_theme_css(mh_build_theme_css($pairs));
        logActivity($conn, $user_id, $user_role, 'System Settings', 'Updated the UI colour theme.');

        if (!empty($result['failed'])) {
            mh_redirect('appearance', 'error',
                'Colours saved to the database, but these stylesheets are not writable: '
                . implode(', ', $result['failed'])
                . '. Run: chmod 664 assets/css/*.css');
        }
        mh_redirect('appearance', 'success',
            'Theme applied to ' . implode(' and ', $result['written']) . '. Press Ctrl+F5 to see it everywhere.');
    }

    if (isset($_POST['reset_theme'])) {
        $defaults = mh_theme_defaults();
        mh_set_settings($conn, $defaults, $user_id);
        mh_write_theme_css(mh_build_theme_css($defaults));
        logActivity($conn, $user_id, $user_role, 'System Settings', 'Reset the UI colour theme to defaults.');
        mh_redirect('appearance', 'success', 'Theme restored to the original Marick colours.');
    }

    /* ---------------- 2b. DEPARTMENTS (function per department; sidebar follows) */
    if (isset($_POST['add_department']) || isset($_POST['edit_department']) || isset($_POST['delete_department'])) {
        mh_depts_handle_post($conn, $user_id, $user_role);
    }

    /* ---------------- 2c. DISEASE TRACKING (conditions Disease Statistics watches) */
    if (isset($_POST['add_condition']) || isset($_POST['edit_condition']) || isset($_POST['delete_condition'])) {
        mh_disease_tab_post($conn, $user_id, $user_role);
    }

    /* ---------------- 3. MASTERLISTS ----------------------------- */
    $posted_list = $_POST['list_key'] ?? '';
    if ($posted_list !== '' && array_key_exists($posted_list, $all_lists)) {

        if (isset($_POST['add_option'])) {
            list($ok, $msg) = mh_add_option($conn, $posted_list, $_POST['new_option'] ?? '');
            if ($ok) {
                logActivity($conn, $user_id, $user_role, 'System Settings',
                    'Added "' . trim($_POST['new_option']) . '" to the ' . $all_lists[$posted_list]['label'] . ' masterlist.');
            }
            mh_redirect('masterlists', $ok ? 'success' : 'error', $msg, $posted_list);
        }

        if (isset($_POST['update_option'])) {
            list($ok, $msg) = mh_update_option(
                $conn,
                $_POST['option_id'] ?? 0,
                $_POST['option_value'] ?? '',
                null,
                isset($_POST['is_active'])
            );
            if ($ok) {
                logActivity($conn, $user_id, $user_role, 'System Settings',
                    'Edited an option in the ' . $all_lists[$posted_list]['label'] . ' masterlist.');
            }
            mh_redirect('masterlists', $ok ? 'success' : 'error', $msg, $posted_list);
        }

        if (isset($_POST['delete_option'])) {
            list($ok, $msg) = mh_delete_option($conn, $_POST['option_id'] ?? 0);
            if ($ok) {
                logActivity($conn, $user_id, $user_role, 'System Settings',
                    'Deleted an option from the ' . $all_lists[$posted_list]['label'] . ' masterlist.');
            }
            mh_redirect('masterlists', $ok ? 'success' : 'error', $msg, $posted_list);
        }

        if (isset($_POST['bulk_add'])) {
            $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['bulk_values'] ?? ''));
            $added = 0; $skipped = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $res = mh_add_option($conn, $posted_list, $line);
                if ($res[0]) { $added++; } else { $skipped++; }
            }
            logActivity($conn, $user_id, $user_role, 'System Settings',
                'Bulk-added ' . $added . ' options to the ' . $all_lists[$posted_list]['label'] . ' masterlist.');
            mh_redirect('masterlists', $added ? 'success' : 'error',
                $added . ' option(s) added' . ($skipped ? ', ' . $skipped . ' skipped (blank or duplicate)' : '') . '.',
                $posted_list);
        }
    }

    /* ---------------- 4. OCR MODEL + GEMINI API KEY ---------------- */
    if (isset($_POST['save_ocr'])) {
        $model = trim($_POST['ocr_model'] ?? '');
        if ($model === '' || !preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $model)) {
            mh_redirect('ocr', 'error', 'That model name contains characters Google would not accept.');
        }

        $geminiKey = trim($_POST['gemini_api_key'] ?? '');
        if ($geminiKey === '') { $geminiKey = (string) mh_setting('gemini_api_key', ''); }   // blank = keep the saved secret (never echoed back into the page)
        if ($geminiKey !== '' && preg_match('/\s/', $geminiKey)) {
            mh_redirect('ocr', 'error', 'The Gemini API key contains a space or line break. Re-paste it as a single line.');
        }

        mh_set_settings($conn, [
            'ocr_model'       => $model,
            'gemini_api_key'  => $geminiKey,
        ], $user_id);
        mh_all_settings($conn, true);
        logActivity($conn, $user_id, $user_role, 'System Settings', 'Set the OCR model to ' . $model . ' and updated the Gemini API key.');

        mh_redirect('ocr', 'success', 'Scanners will now use "' . $model . '".');
    }

    /* ---------------- 5. SMS PROVIDER + SEMAPHORE + ANDROID GATEWAY -- */
    if (isset($_POST['save_sms'])) {
        $provider = trim($_POST['sms_provider'] ?? 'semaphore');
        if (!in_array($provider, ['semaphore', 'android_gateway'], true)) {
            $provider = 'semaphore';
        }

        $apiKey = trim($_POST['semaphore_api_key'] ?? '');
        if ($apiKey === '') { $apiKey = (string) mh_setting('semaphore_api_key', ''); }   // blank = keep the saved secret (never echoed back into the page)
        $sender = trim($_POST['semaphore_sender_name'] ?? '');
        $smsResetEnabled = isset($_POST['sms_password_reset_enabled']) ? '1' : '0';

        $gatewayUrl = trim($_POST['android_gateway_url'] ?? '');
        $gatewayKey = trim($_POST['android_gateway_key'] ?? '');
        if ($gatewayKey === '') { $gatewayKey = (string) mh_setting('android_gateway_key', ''); }   // blank = keep the saved secret (never echoed back into the page)

        if ($sender === '') {
            mh_redirect('sms', 'error', 'Sender Name cannot be blank.');
        }
        if (!preg_match('/^[A-Za-z0-9 _-]{3,11}$/', $sender)) {
            mh_redirect('sms', 'error', 'Sender Name must be 3-11 characters (letters, numbers, spaces, - or _) - that is Semaphore\'s own limit.');
        }
        if ($apiKey !== '' && preg_match('/\s/', $apiKey)) {
            mh_redirect('sms', 'error', 'The Semaphore API key contains a space or line break. Re-paste it as a single line.');
        }
        if ($gatewayUrl !== '') {
            if (preg_match('/\s/', $gatewayUrl)) {
                mh_redirect('sms', 'error', 'The Android Gateway URL contains a space or line break. Re-paste it as a single line.');
            }
            if (!preg_match('#^https?://#i', $gatewayUrl) || !filter_var($gatewayUrl, FILTER_VALIDATE_URL)) {
                mh_redirect('sms', 'error', 'The Android Gateway URL must be a valid http:// or https:// address.');
            }
        }
        if ($gatewayKey !== '' && preg_match('/\s/', $gatewayKey)) {
            mh_redirect('sms', 'error', 'The Android Gateway key/token contains a space or line break. Re-paste it as a single line.');
        }
        if ($provider === 'android_gateway' && $gatewayUrl === '') {
            mh_redirect('sms', 'error', 'Set the Android Gateway Endpoint URL before making it the active SMS provider.');
        }

        if (!mh_set_settings($conn, [
            'sms_provider'               => $provider,
            'semaphore_api_key'          => $apiKey,
            'semaphore_sender_name'      => $sender,
            'sms_password_reset_enabled' => $smsResetEnabled,
            'android_gateway_url'        => $gatewayUrl,
            'android_gateway_key'        => $gatewayKey,
        ], $user_id)) {
            mh_redirect('sms', 'error', 'The database rejected the update. Nothing was saved.');
        }

        $providerLabel = $provider === 'android_gateway' ? 'Android SMS Gateway' : 'Semaphore';
        logActivity($conn, $user_id, $user_role, 'System Settings', 'Updated SMS settings (active provider: ' . $providerLabel . ').');

        mh_redirect('sms', 'success', 'SMS settings saved. Active provider: ' . $providerLabel . '.');
    }

    /* ---------------- 6. SEND TEST SMS ----------------------------- */
    if (isset($_POST['send_test_sms'])) {
        $testNumber = trim($_POST['test_phone_number'] ?? '');
        require_once __DIR__ . '/../includes/sms_helper.php';

        $normalized = mh_normalize_ph_mobile($testNumber);   // 09XXXXXXXXX, used for logs
        if ($normalized === '') {
            mh_redirect('sms', 'error', 'That does not look like a valid Philippine mobile number (e.g. 09171234567).');
        }

        // Provider: the database setting (what the admin picked) wins; the
        // generated config constant is only the fallback.
        $dbProvider = function_exists('mh_setting') ? trim((string) mh_setting('sms_provider', '')) : '';
        $provider   = in_array($dbProvider, ['semaphore', 'android_gateway'], true) ? $dbProvider : mh_active_sms_provider();
        $isGateway  = ($provider === 'android_gateway');
        $providerLabel = $isGateway ? 'the Android SMS Gateway' : 'Semaphore';

        $testBody = 'This is a test message from ' . mh_setting('system_name') . ' via ' . $providerLabel
                  . '. If you received this, SMS reminders are configured correctly.';

        try {
            $result = $isGateway
                ? send_sms_simpapp($testNumber, $testBody)
                : mh_send_sms_semaphore($normalized, $testBody);
        } catch (Throwable $e) {
            error_log('[Marick Health] Test SMS crashed: ' . $e->getMessage());
            $result = ['success' => false, 'status' => 'Internal error: ' . $e->getMessage(), 'http_code' => 0,
                       'message_id' => null, 'raw' => null, 'error' => 'Internal error: ' . $e->getMessage()];
        }

        $statusText = (string) ($result['status'] ?? ($result['success'] ? 'Success' : ($result['error'] ?? 'Unknown error')));
        $httpCode   = (int) ($result['http_code'] ?? 0);
        $smsId      = $result['message_id'] ?? null;

        // Logging must never swallow the banner, so it is fenced off.
        try {
            require_once __DIR__ . '/../includes/sms/sms_scheduler.php';
            mh_sms_install($conn);
            $logStmt = $conn->prepare(
                "INSERT INTO sms_logs (scheduled_sms_id, patient_id, phone_number, message, status, response_log, triggered_by)
                 VALUES (NULL, NULL, ?, 'Test message from System Settings.', ?, ?, 'manual')"
            );
            if ($logStmt) {
                $logStatus = $result['success'] ? 'sent' : 'failed';
                $log = 'HTTP ' . $httpCode . ' | ' . $statusText
                     . ($smsId ? ' | smsId: ' . $smsId : '')
                     . (!empty($result['raw']) ? ' | ' . substr(json_encode($result['raw']), 0, 500) : '');
                $logStmt->bind_param('sss', $normalized, $logStatus, $log);
                $logStmt->execute();
                $logStmt->close();
            }
            logActivity($conn, $user_id, $user_role, 'System Settings',
                'Sent a test SMS to ' . $normalized . ' (' . ($result['success'] ? 'accepted: ' . $statusText : 'failed: ' . $statusText) . ', HTTP ' . $httpCode . ').');
        } catch (Throwable $e) {
            error_log('[Marick Health] Test SMS logging failed: ' . $e->getMessage());
        }

        if (!empty($result['success'])) {
            mh_redirect('sms', 'success',
                'Test SMS: ' . $statusText . ' successfully' . ($smsId ? ' (ID: ' . $smsId . ')' : '') . ' via ' . $providerLabel
                . ' - HTTP ' . $httpCode . '. Check the phone.');
        }
        mh_redirect('sms', 'error', 'Test SMS failed' . ($httpCode ? ' (HTTP ' . $httpCode . ')' : '') . ': ' . $statusText);
    }

    /* ---------------- 6b. STAFF ROLES (custom, database-backed) ---- */
    if (isset($_POST['add_role'])) {
        list($ok, $msg) = mh_add_custom_role($conn, $_POST['role_label'] ?? '');
        if ($ok) {
            logActivity($conn, $user_id, $user_role, 'System Settings', 'Added staff role "' . trim($_POST['role_label']) . '".');
        }
        mh_redirect('roles', $ok ? 'success' : 'error', $msg);
    }
    if (isset($_POST['update_role'])) {
        list($ok, $msg) = mh_update_custom_role($conn, $_POST['role_id'] ?? 0, $_POST['role_label'] ?? '', isset($_POST['is_active']));
        if ($ok) {
            logActivity($conn, $user_id, $user_role, 'System Settings', 'Edited a custom staff role.');
        }
        mh_redirect('roles', $ok ? 'success' : 'error', $msg);
    }
    if (isset($_POST['delete_role'])) {
        list($ok, $msg) = mh_delete_custom_role($conn, $_POST['role_id'] ?? 0);
        if ($ok) {
            logActivity($conn, $user_id, $user_role, 'System Settings', 'Deleted a custom staff role.');
        }
        mh_redirect('roles', $ok ? 'success' : 'error', $msg);
    }

    if (isset($_POST['save_permissions'])) {
        $posted = (isset($_POST['perm']) && is_array($_POST['perm'])) ? $_POST['perm'] : [];
        list($ok, $msg) = mh_save_role_permissions($conn, $posted);
        if ($ok) {
            logActivity($conn, $user_id, $user_role, 'System Settings', 'Updated the Staff Roles & Permissions matrix.');
        }
        mh_redirect('roles', $ok ? 'success' : 'error', $msg);
    }

    /* ---------------- 7. MODULE ACCESS CONTROL (on/off switches only; WHO gets in = Staff Roles & Permissions) */
    if (isset($_POST['save_modules'])) {

        $registry   = mh_module_registry();
        $pairs      = [];
        $changes    = [];

        foreach ($registry as $key => $mod) {
            $enabled = isset($_POST['module_enabled'][$key]) ? '1' : '0';

            $pairs['module_enabled_' . $key] = $enabled;
            $pairs['module_label_' . $key]   = mb_substr(trim((string) ($_POST['module_label'][$key] ?? '')), 0, 60);   // '' = built-in name

            if ($enabled === '0') {
                $changes[] = $mod['label'] . ' (off)';
            }
        }

        foreach (array_keys(mh_nav_label_defaults()) as $nk) {   // other sidebar names (blank = built-in name)
            $pairs['nav_label_' . $nk] = mb_substr(trim((string) ($_POST['nav_label'][$nk] ?? '')), 0, 60);
        }

        if (!mh_set_settings($conn, $pairs, $user_id)) {
            mh_redirect('modules', 'error', 'The database rejected the update. Nothing was saved.');
        }

        logActivity($conn, $user_id, $user_role, 'System Settings',
            'Updated Module Access settings.' . ($changes ? ' Disabled: ' . implode(', ', $changes) . '.' : ''));

        // Enforcement and the Patient Queueing department visibility read the database directly.
        mh_redirect('modules', 'success', 'Module access settings saved.');
    }

    mh_redirect($active_tab, 'error', 'Unrecognised action.');
}

/* =====================================================================
 * FLASH MESSAGE + PAGE DATA
 * ================================================================== */
$flash = $_SESSION['mh_flash'] ?? null;
unset($_SESSION['mh_flash']);

$settings    = mh_all_settings($conn, true);
$theme       = mh_theme_defaults();
$groups      = mh_theme_field_groups();
$options     = mh_options($conn, $list_key, false);   // include hidden ones in the admin view
$system_name = mh_setting('system_name');

// Quick health check for the Gemini config, shown on the OCR tab.
$gemini_key_problem = null;
$gemini_available   = null;
if ($active_tab === 'ocr') {
    if (is_readable(__DIR__ . '/../config/gemini.php')) {  // DB-backed shim (no key stored in the file)
        require_once __DIR__ . '/../config/gemini.php';
        require_once __DIR__ . '/../includes/ocr/GeminiOcrClient.php';
        $gemini_key_problem = gemini_api_key_problem();
        if ($gemini_key_problem === null && isset($_GET['probe'])) {
            $gemini_available = gemini_ocr_list_models();
        }
    } else {
        $gemini_key_problem = 'The OCR configuration loader is missing from this install. Re-deploy config/gemini.php (it contains no secrets), then enter the API key here.';
    }
}

// Quick health check for the active SMS provider, shown on the SMS tab.
$semaphore_key_problem = null;
$android_gateway_problem_msg = null;
$semaphore_balance     = null;
if ($active_tab === 'sms') {
    require_once __DIR__ . '/../includes/sms_helper.php';
    require_once __DIR__ . '/../includes/sms/sms_scheduler.php';
    mh_sms_install($conn); // creates scheduled_sms / sms_logs on first visit, harmless after
    $semaphore_key_problem       = semaphore_api_key_problem();
    $android_gateway_problem_msg = android_gateway_problem();
    if (mh_active_sms_provider() === 'semaphore' && $semaphore_key_problem === null && isset($_GET['probe'])) {
        $semaphore_balance = mh_semaphore_get_balance();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <?php
    // Inline the live theme so this page always shows the current colours,
    // even before the browser re-fetches the cached stylesheet.
    echo mh_theme_style_tag();
    ?>
    <style>
        /* ---------------------------------------------------------------
           Page tokens. Everything below reads these, never a raw colour,
           so light and dark mode (and the Theme Customizer's panel colour)
           all flow through one place. Dark overrides sit in the
           html[data-theme="dark"] block at the bottom of this <style>.
           --------------------------------------------------------------- */
        :root {
            --ss-card:        var(--bg-card, var(--panel-bg, #ffffff));
            --ss-card-head:   var(--bg-hover, #f8fafc);
            --ss-input-bg:    var(--panel-bg, #ffffff);
            --ss-border:      var(--border-color, #e2e8f0);
            --ss-border-strong: #cbd5e1;
            --ss-row-line:    #f1f5f9;
            --ss-text:        var(--text-main, #334155);
            --ss-heading:     var(--heading-color, #1e293b);
            --ss-muted:       var(--text-muted, #64748b);
            --ss-faint:       #94a3b8;
            --ss-soft-btn:    #e2e8f0;
            --ss-switch-off:  #cbd5e1;
            --ss-pill-bg:     #f1f5f9;
            --ss-pill-text:   #475569;
            --ss-ok-bg:   #ecfdf5; --ss-ok-bd:   #a7f3d0; --ss-ok-tx:   #065f46;
            --ss-err-bg:  #fef2f2; --ss-err-bd:  #fecaca; --ss-err-tx:  #991b1b;
            --ss-warn-bg: #fffbeb; --ss-warn-bd: #fde68a; --ss-warn-tx: #92400e;
            --ss-danger-btn-bg: #fee2e2; --ss-danger-btn-tx: #b91c1c;
        }
        html[data-theme="dark"] {
            --ss-card:        var(--bg-card, #1e293b);
            --ss-card-head:   var(--bg-hover, #263449);
            --ss-input-bg:    var(--bg-input, #16213a);
            --ss-border:      var(--border-color, #334155);
            --ss-border-strong: #475569;
            --ss-row-line:    rgba(148, 163, 184, 0.14);
            --ss-faint:       #64748b;
            --ss-soft-btn:    #334155;
            --ss-switch-off:  #475569;
            --ss-pill-bg:     #263449;
            --ss-pill-text:   #cbd5e1;
            --ss-ok-bg:   rgba(16, 185, 129, 0.14); --ss-ok-bd:   rgba(16, 185, 129, 0.40); --ss-ok-tx:   #6ee7b7;
            --ss-err-bg:  rgba(220, 38, 38, 0.16);  --ss-err-bd:  rgba(220, 38, 38, 0.40);  --ss-err-tx:  #fca5a5;
            --ss-warn-bg: rgba(217, 119, 6, 0.16);  --ss-warn-bd: rgba(217, 119, 6, 0.40);  --ss-warn-tx: #fcd34d;
            --ss-danger-btn-bg: rgba(220, 38, 38, 0.18); --ss-danger-btn-tx: #fca5a5;
        }

        .tabs { display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 2px solid var(--ss-border); margin-bottom: 25px; }
        .tabs a {
            padding: 12px 20px; text-decoration: none; font-weight: 700; font-size: 0.95rem;
            color: var(--ss-muted); border-bottom: 3px solid transparent; margin-bottom: -2px;
        }
        .tabs a.active { color: var(--primary, #4ca1af); border-bottom-color: var(--primary, #4ca1af); }

        .settings-grid { display: grid; grid-template-columns: 1.15fr 1fr; gap: 25px; align-items: start; }
        @media (max-width: 950px) { .settings-grid { grid-template-columns: 1fr; } }

        .card { background: var(--ss-card); color: var(--ss-text); border: 1px solid var(--ss-border); border-radius: 12px; overflow: hidden; margin-bottom: 25px; }
        .card > header { padding: 16px 22px; background: var(--ss-card-head); border-bottom: 1px solid var(--ss-border); }
        .card > header h3 { margin: 0; font-size: 1.05rem; color: var(--ss-heading); }
        .card > header p { margin: 4px 0 0; font-size: 0.82rem; color: var(--ss-muted); }
        .card .body { padding: 22px; }

        .field { margin-bottom: 16px; }
        .field label { display: block; font-weight: 600; font-size: 0.88rem; color: var(--ss-text); margin-bottom: 6px; }
        .field input[type="text"], .field input[type="password"], .field textarea, .field select, .field input[type="number"] {
            width: 100%; padding: 10px 12px; border: 1px solid var(--ss-border-strong); border-radius: 6px;
            font-family: inherit; font-size: 0.95rem; box-sizing: border-box;
            background: var(--ss-input-bg); color: var(--ss-text);
        }
        .field textarea { resize: vertical; min-height: 80px; }
        .field input:focus, .field textarea:focus, .field select:focus { outline: 2px solid var(--primary, #4ca1af); outline-offset: 0; border-color: var(--primary, #4ca1af); }
        .hint { font-size: 0.78rem; color: var(--ss-muted); margin: 4px 0 0; line-height: 1.5; }

        /* Logo / Background upload dropzones */
        .preview-box { background: var(--ss-card-head); border: 2px dashed var(--ss-border-strong); padding: 18px; text-align: center; border-radius: 10px; margin-bottom: 16px; color: var(--ss-text); }
        .preview-box img { max-width: 100%; max-height: 130px; object-fit: contain; display: block; margin: 0 auto 12px; }
        .preview-box input[type="file"] { width: 100%; padding: 8px; border: 1px solid var(--ss-border-strong); border-radius: 6px; background: var(--ss-input-bg); color: var(--ss-text); box-sizing: border-box; font-size: 0.85rem; }
        .preview-box input[type="file"]::file-selector-button { margin-right: 10px; padding: 6px 12px; border: 1px solid var(--ss-border-strong); border-radius: 6px; background: var(--ss-card-head); color: var(--ss-text); font-weight: 600; cursor: pointer; }

        /* Feature toggles */
        .switch { position: relative; display: inline-block; width: 50px; height: 26px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; top:0; left:0; right:0; bottom:0; cursor: pointer; background-color: var(--ss-switch-off); transition: .3s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 4px; bottom: 4px; background-color: #fff; transition: .3s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,.3); } /* knob stays white on purpose */
        input:checked + .slider { background-color: #10b981; }
        input:checked + .slider:before { transform: translateX(24px); }
        .feature-row { display: flex; justify-content: space-between; align-items: center; gap: 15px; padding: 14px; background: var(--ss-card-head); border: 1px solid var(--ss-border); border-radius: 8px; margin-bottom: 15px; }
        .feature-row strong { display: block; color: var(--ss-heading); }
        .feature-row span.sub { color: var(--ss-muted); font-size: 0.83rem; }

        .color-row { display: flex; align-items: center; gap: 12px; padding: 9px 0; border-bottom: 1px dashed var(--ss-border); }
        .color-row:last-child { border-bottom: 0; }
        .color-row label { flex: 1; font-size: 0.9rem; color: var(--ss-text); font-weight: 600; margin: 0; }
        .color-row input[type="color"] { width: 46px; height: 34px; padding: 2px; border: 1px solid var(--ss-border-strong); border-radius: 6px; background: var(--ss-input-bg); cursor: pointer; flex-shrink: 0; }
        .color-row .hexcode { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.8rem; color: var(--ss-muted); width: 70px; text-align: right; }
        .group-title { font-size: 0.78rem; text-transform: uppercase; letter-spacing: .06em; color: var(--ss-faint); font-weight: 800; margin: 18px 0 4px; }

        .list-nav { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
        .list-nav a { padding: 8px 14px; border-radius: 20px; text-decoration: none; font-size: 0.85rem; font-weight: 700; background: var(--ss-pill-bg); color: var(--ss-pill-text); border: 1px solid var(--ss-border); }
        .list-nav a.active { background: var(--primary, #4ca1af); color: #fff; border-color: var(--primary, #4ca1af); }

        table.opts { width: 100%; border-collapse: collapse; }
        table.opts th { text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--ss-faint); padding: 8px; border-bottom: 2px solid var(--ss-border); background: transparent; }
        table.opts td { padding: 7px 8px; border-bottom: 1px solid var(--ss-row-line); vertical-align: middle; color: var(--ss-text); background: transparent; }
        table.opts input[type="text"] { width: 100%; padding: 7px 9px; border: 1px solid var(--ss-border-strong); border-radius: 5px; box-sizing: border-box; background: var(--ss-input-bg); color: var(--ss-text); }
        table.opts input[type="number"] { width: 70px; padding: 7px; border: 1px solid var(--ss-border-strong); border-radius: 5px; background: var(--ss-input-bg); color: var(--ss-text); }

        .btn { padding: 10px 18px; border: 0; border-radius: 7px; font-weight: 700; font-size: 0.92rem; cursor: pointer; font-family: inherit; }
        .btn-main { background: var(--button-bg, #4ca1af); color: var(--button-text, #fff); }
        .btn-soft { background: var(--ss-soft-btn); color: var(--ss-text); }
        .btn-danger { background: var(--ss-danger-btn-bg); color: var(--ss-danger-btn-tx); }
        .btn-mini { padding: 6px 10px; font-size: 0.8rem; }
        .btn-block { width: 100%; padding: 14px; font-size: 1rem; }

        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.92rem; line-height: 1.55; }
        .alert-success { background: var(--ss-ok-bg);   border: 1px solid var(--ss-ok-bd);   color: var(--ss-ok-tx); }
        .alert-error   { background: var(--ss-err-bg);  border: 1px solid var(--ss-err-bd);  color: var(--ss-err-tx); }
        .alert-warn    { background: var(--ss-warn-bg); border: 1px solid var(--ss-warn-bd); color: var(--ss-warn-tx); }

        /* Global Announcement Banner field (was inline red-on-pink) */
        .field label.announce-label { color: var(--ss-err-tx); }
        .field textarea.announce-input { border-color: var(--ss-err-bd); background: var(--ss-err-bg); }

        /* API-key "Show" button + Gemini model list (were inline #f8fafc / white) */
        .reveal-btn { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: 1px solid var(--ss-border-strong); background: var(--ss-card-head); color: var(--ss-text); border-radius: 6px; padding: 4px 10px; font-size: 0.78rem; cursor: pointer; }
        .model-list { max-height: 220px; overflow: auto; border: 1px solid var(--ss-border); border-radius: 8px; padding: 12px; background: var(--ss-card-head); color: var(--ss-text); font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.8rem; line-height: 1.7; }

        .page-title { margin: 0 0 5px; color: var(--ss-heading); font-family: Georgia, serif; font-size: 1.8rem; }
        .page-sub { margin: 0; color: var(--ss-muted); font-size: 0.95rem; }
        .soft-hr { border: 0; border-top: 1px dashed var(--ss-border-strong); margin: 20px 0; }
        .sub-title { margin: 0 0 12px; font-size: 0.92rem; color: var(--ss-heading); }
        .prose { font-size: 0.9rem; line-height: 1.65; color: var(--ss-text); }

        /* Live preview of the colours being edited: its inner colours come
           from the pickers on purpose, only the frame follows the theme. */
        .theme-preview { border: 1px solid var(--ss-border); border-radius: 10px; overflow: hidden; }
        .tp-side { padding: 14px; font-size: 0.85rem; font-weight: 700; }
        .tp-body { padding: 18px; }
        code.inline { background: var(--ss-card-head); color: var(--ss-text); padding: 2px 6px; border-radius: 4px; font-size: 0.85em; }

        /* Dark-mode-only polish that tokens alone can't express */
        html[data-theme="dark"] .card { box-shadow: var(--shadow-sm); }
        html[data-theme="dark"] .preview-box img { background: rgba(255,255,255,.04); border-radius: 8px; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

<?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

<div class="main-content">
    <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

    <div class="dashboard-container">

        <div style="margin-bottom: 20px;">
            <h1 class="page-title">Master System Settings</h1>
            <p class="page-sub">
                Branding, colours and every dropdown in the system - no code editing required.
            </p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <nav class="tabs">
            <a href="?tab=branding"    class="<?php echo $active_tab === 'branding' ? 'active' : ''; ?>">Branding &amp; Content</a>
            <a href="?tab=appearance"  class="<?php echo $active_tab === 'appearance' ? 'active' : ''; ?>">Appearance &amp; Colours</a>
            <a href="?tab=masterlists" class="<?php echo $active_tab === 'masterlists' ? 'active' : ''; ?>">Dropdown Masterlists</a>
            <a href="?tab=departments" class="<?php echo $active_tab === 'departments' ? 'active' : ''; ?>">Departments</a>
            <a href="?tab=disease"     class="<?php echo $active_tab === 'disease' ? 'active' : ''; ?>">Disease Tracking</a>
            <a href="?tab=ocr"         class="<?php echo $active_tab === 'ocr' ? 'active' : ''; ?>">Scanner (OCR)</a>
            <a href="?tab=sms"         class="<?php echo $active_tab === 'sms' ? 'active' : ''; ?>">SMS Reminders</a>
            <a href="?tab=modules"     class="<?php echo $active_tab === 'modules' ? 'active' : ''; ?>">Module Access</a>
            <a href="?tab=roles"       class="<?php echo $active_tab === 'roles' ? 'active' : ''; ?>">Staff Roles &amp; Permissions</a>
        </nav>

<?php /* ================= TAB 1: BRANDING ================= */ ?>
<?php if ($active_tab === 'branding'): ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo mh_csrf_field(); ?>
        <div class="settings-grid">

            <div>
                <div class="card">
                    <header>
                        <h3>Health Centre Identity</h3>
                        <p>Appears in the sidebar, the browser tab, printed headers and the patient portal.</p>
                    </header>
                    <div class="body">
                        <div class="field">
                            <label>Clinic / System Name <span style="color:#dc2626;">*</span></label>
                            <input type="text" name="system_name" required maxlength="120"
                                   value="<?php echo htmlspecialchars($settings['system_name']); ?>">
                        </div>
                        <div class="field">
                            <label>Header Title</label>
                            <input type="text" name="header_title" maxlength="120"
                                   value="<?php echo htmlspecialchars($settings['header_title']); ?>">
                            <p class="hint">Large text shown beside the logo in the admin top bar.</p>
                        </div>
                        <div class="field">
                            <label>Header Tagline</label>
                            <input type="text" name="header_tagline" maxlength="160"
                                   value="<?php echo htmlspecialchars($settings['header_tagline']); ?>">
                        </div>
                        <hr class="soft-hr">
                        <div class="field">
                            <label>Landing Page Headline</label>
                            <input type="text" name="hero_title" maxlength="200"
                                   value="<?php echo htmlspecialchars($settings['hero_title']); ?>">
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>Landing Page Subtitle</label>
                            <textarea name="hero_subtitle" maxlength="600"><?php echo htmlspecialchars($settings['hero_subtitle']); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <header>
                        <h3>Features &amp; Announcements</h3>
                        <p>Controls what patients can do on the public portal.</p>
                    </header>
                    <div class="body">
                        <div class="feature-row">
                            <div>
                                <strong>Allow Online Appointments</strong>
                                <span class="sub">Let patients request schedules through the portal.</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="enable_online_booking"
                                    <?php echo $settings['enable_online_booking'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label class="announce-label">Global Announcement Banner</label>
                            <textarea name="global_announcement" maxlength="500"
                                      placeholder="e.g. Clinic closed tomorrow due to storm..."
                                      class="announce-input"><?php echo htmlspecialchars($settings['global_announcement']); ?></textarea>
                            <p class="hint">Leave blank to hide. Any text here shows as a red alert on the patient portal.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="card">
                    <header>
                        <h3>System Logo</h3>
                        <p>Replaces <code class="inline">assets/images/logo.png</code> everywhere at once.</p>
                    </header>
                    <div class="body">
                        <div class="preview-box">
                            <img src="../<?php echo htmlspecialchars($settings['logo_path']); ?>?t=<?php echo time(); ?>" alt="Current logo">
                            <input type="file" name="new_logo" accept="image/png,image/jpeg,image/webp">
                        </div>
                        <p class="hint" style="margin:0;">PNG, JPG or WEBP up to 5 MB. Non-PNG files are converted to PNG
                        automatically so every existing page keeps working. A square image around 512&times;512 looks best.</p>
                    </div>
                </div>

                <div class="card">
                    <header>
                        <h3>Background Image</h3>
                        <p>Replaces <code class="inline">assets/images/barangay_bg.png</code> on the landing page.</p>
                    </header>
                    <div class="body">
                        <div class="preview-box">
                            <img src="../<?php echo htmlspecialchars($settings['background_path']); ?>?t=<?php echo time(); ?>" alt="Current background">
                            <input type="file" name="new_background" accept="image/png,image/jpeg,image/webp">
                        </div>
                        <p class="hint" style="margin:0;">A wide landscape photo (roughly 1920&times;1080) works best.</p>
                    </div>
                </div>

                <div class="card">
                    <header>
                        <h3>Address Jurisdiction</h3>
                        <p>Which Location Type counts as a resident of the barangay.</p>
                    </header>
                    <div class="body">
                        <div class="field">
                            <label>Home Address Type</label>
                            <input type="text" name="home_address_type" maxlength="60" required
                                   value="<?php echo htmlspecialchars($settings['home_address_type']); ?>">
                            <p class="hint">Patients with this Location Type are residents (street + house number). Must match an item in the Address Jurisdiction list.</p>
                        </div>
                        <div class="field">
                            <label>Outside Label</label>
                            <input type="text" name="outside_address_type" maxlength="60" required
                                   value="<?php echo htmlspecialchars($settings['outside_address_type']); ?>">
                            <p class="hint">Default non-resident Location Type (free-text address).</p>
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>Resident Address Suffix</label>
                            <input type="text" name="home_address_suffix" maxlength="150"
                                   value="<?php echo htmlspecialchars($settings['home_address_suffix']); ?>">
                            <p class="hint">Printed after the street, e.g. "12 Aida St., <em>this text</em>". Existing patients keep the Location Type they were saved with, so rename the matching list item too.</p>
                        </div>
                    </div>
                </div>

                <button type="submit" name="save_branding" class="btn btn-main btn-block">Save Branding &amp; Content</button>
            </div>

        </div>
    </form>

<?php /* ================= TAB 2: APPEARANCE ================= */ ?>
<?php elseif ($active_tab === 'appearance'): ?>

    <form method="POST" id="themeForm">
        <?php echo mh_csrf_field(); ?>
        <div class="settings-grid">

            <div class="card">
                <header>
                    <h3>Colour Theme</h3>
                    <p>Saved to the database and written into <code class="inline">admin_theme.css</code> and
                       <code class="inline">style.css</code> as CSS variables.</p>
                </header>
                <div class="body">
                    <?php foreach ($groups as $groupName => $fields): ?>
                        <div class="group-title"><?php echo htmlspecialchars($groupName); ?></div>
                        <?php foreach ($fields as $key => $label):
                            $value = mh_sanitize_color($settings[$key] ?? $theme[$key], $theme[$key]); ?>
                            <div class="color-row">
                                <label for="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></label>
                                <span class="hexcode" id="hex_<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($value); ?></span>
                                <input type="color" id="<?php echo htmlspecialchars($key); ?>" name="<?php echo htmlspecialchars($key); ?>"
                                       value="<?php echo htmlspecialchars($value); ?>"
                                       data-var="<?php echo htmlspecialchars($key); ?>">
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <div style="display:flex; gap:10px; margin-top:22px;">
                        <button type="submit" name="save_theme" class="btn btn-main" style="flex:1;">Apply Theme</button>
                        <button type="submit" name="reset_theme" class="btn btn-soft" id="resetThemeBtn">Reset</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <header>
                    <h3>Live Preview</h3>
                    <p>Updates as you pick - nothing is saved until you press Apply.</p>
                </header>
                <div class="body">
                    <div class="theme-preview" id="tpWrap">
                        <div class="tp-side" id="tpSide">Dashboard &nbsp;&middot;&nbsp; Patients &nbsp;&middot;&nbsp; Immunization</div>
                        <div class="tp-body" id="tpBody">
                            <h3 id="tpHeading" style="margin:0 0 8px;">Patient Masterlist</h3>
                            <p id="tpText" style="margin:0 0 14px; font-size:0.9rem;">
                                Body text renders in the colour you chose for readable paragraphs and table rows.
                            </p>
                            <button type="button" class="btn" id="tpButton">Primary Button</button>
                            <a href="#" id="tpLink" style="margin-left:12px; font-size:0.9rem;" onclick="return false;">A sample link</a>
                        </div>
                    </div>

                    <div class="alert alert-warn" style="margin: 20px 0 0;">
                        Browsers cache CSS aggressively. After applying, press <strong>Ctrl+F5</strong>
                        (or <strong>Cmd+Shift+R</strong>) once to see the new colours on every page.
                    </div>

                    <p class="hint" style="margin-top:14px;">
                        Generated variables include <code class="inline">--primary-color</code>,
                        <code class="inline">--text-color</code>, <code class="inline">--sidebar-bg</code>,
                        <code class="inline">--heading-color</code>, <code class="inline">--button-bg</code> and
                        <code class="inline">--border-color</code>, so any new CSS you write can reference them directly.
                    </p>
                </div>
            </div>

        </div>
    </form>

    <script>
    document.getElementById('resetThemeBtn').addEventListener('click', async function (e) {
        e.preventDefault();
        const btn = e.currentTarget;
        const ok = await mh_confirm('Restore the original Marick colours?', 'Reset Theme?', 'warning');
        // requestSubmit(btn) preserves the button's name="reset_theme" in the POST data,
        // unlike form.submit() which would drop it.
        if (ok) { document.getElementById('themeForm').requestSubmit(btn); }
    });

    document.querySelectorAll('.mh-delete-option-btn').forEach(function (btn) {
        btn.addEventListener('click', async function (e) {
            e.preventDefault();
            const ok = await mh_confirm('Delete this option permanently? Records already saved with this value are not changed.', 'Delete Option?', 'danger');
            if (ok) { btn.closest('form').requestSubmit(btn); }
        });
    });

    (function () {
        var map = {
            theme_sidebar_bg:   function (el) { document.getElementById('tpSide').style.background = el.value; },
            theme_sidebar_text: function (el) { document.getElementById('tpSide').style.color = el.value; },
            theme_page_bg:      function (el) { document.getElementById('tpBody').style.background = el.value; },
            theme_body_text:    function (el) { document.getElementById('tpText').style.color = el.value; },
            theme_heading_text: function (el) { document.getElementById('tpHeading').style.color = el.value; },
            theme_button_bg:    function (el) { document.getElementById('tpButton').style.background = el.value; },
            theme_button_text:  function (el) { document.getElementById('tpButton').style.color = el.value; },
            theme_link:         function (el) { document.getElementById('tpLink').style.color = el.value; },
            theme_border:       function (el) { document.getElementById('tpWrap').style.borderColor = el.value; }
        };

        function apply(el) {
            var hex = document.getElementById('hex_' + el.dataset.var);
            if (hex) { hex.textContent = el.value; }
            if (map[el.dataset.var]) { map[el.dataset.var](el); }
        }

        document.querySelectorAll('#themeForm input[type="color"]').forEach(function (el) {
            el.addEventListener('input', function () { apply(el); });
            apply(el);
        });
    })();
    </script>

<?php /* ================= TAB 3: MASTERLISTS ================= */ ?>
<?php elseif ($active_tab === 'masterlists'): ?>

    <div class="list-nav">
        <?php foreach ($all_lists as $key => $meta): ?>
            <a href="?tab=masterlists&amp;list=<?php echo urlencode($key); ?>"
               class="<?php echo $key === $list_key ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($meta['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="settings-grid">

        <div class="card">
            <header>
                <h3><?php echo htmlspecialchars($all_lists[$list_key]['label']); ?></h3>
                <p><?php echo htmlspecialchars($all_lists[$list_key]['hint']); ?></p>
            </header>
            <div class="body">
                <?php if (!$options): ?>
                    <p style="color:var(--text-muted); font-size:0.92rem; margin:0;">
                        This list is empty. Add the first option using the panel on the right.
                    </p>
                <?php else: ?>
                    <table class="opts">
                        <thead>
                            <tr>
                                <th style="width:66%;">Option</th>
                                <th style="width:16%;">Shown</th>
                                <th style="width:18%;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($options as $opt): ?>
                            <tr>
                                <td colspan="4" style="padding:0;">
                                    <form method="POST" style="display:flex; gap:8px; align-items:center; padding:7px 8px;">
                                        <?php echo mh_csrf_field(); ?>
                                        <input type="hidden" name="list_key" value="<?php echo htmlspecialchars($list_key); ?>">
                                        <input type="hidden" name="option_id" value="<?php echo (int) $opt['id']; ?>">

                                        <input type="text" name="option_value" maxlength="150" required
                                               style="flex:1;"
                                               value="<?php echo htmlspecialchars($opt['option_value']); ?>">

                                        <label style="display:flex; align-items:center; gap:5px; font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">
                                            <input type="checkbox" name="is_active" value="1"
                                                   <?php echo $opt['is_active'] ? 'checked' : ''; ?>
                                                   onchange="this.form.requestSubmit(this.form.querySelector('[name=update_option]'));"
                                                   style="width:16px; height:16px;">
                                            Shown
                                        </label>

                                        <button type="submit" name="update_option" class="btn btn-soft btn-mini">Save</button>
                                        <button type="submit" name="delete_option" class="btn btn-danger btn-mini mh-delete-option-btn">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="hint" style="margin-top:14px;">
                        Options are listed alphabetically (A–Z) automatically. Un-ticking <strong>Shown</strong> hides an
                        option from new entries while keeping old records readable.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <header><h3>Add an Option</h3></header>
                <div class="body">
                    <form method="POST">
                        <?php echo mh_csrf_field(); ?>
                        <input type="hidden" name="list_key" value="<?php echo htmlspecialchars($list_key); ?>">
                        <div class="field">
                            <label>New value</label>
                            <input type="text" name="new_option" maxlength="150" required
                                   placeholder="<?php echo htmlspecialchars($list_key === 'streets' ? 'e.g. Sampaguita' : 'e.g. New option'); ?>">
                        </div>
                        <button type="submit" name="add_option" class="btn btn-main btn-block">Add to List</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <header>
                    <h3>Paste Many at Once</h3>
                    <p>One per line. Duplicates are skipped automatically.</p>
                </header>
                <div class="body">
                    <form method="POST">
                        <?php echo mh_csrf_field(); ?>
                        <input type="hidden" name="list_key" value="<?php echo htmlspecialchars($list_key); ?>">
                        <div class="field">
                            <textarea name="bulk_values" style="height:150px; font-family: ui-monospace, Menlo, Consolas, monospace;"
                                      placeholder="Aida&#10;Cattleya&#10;Cora"></textarea>
                        </div>
                        <button type="submit" name="bulk_add" class="btn btn-soft btn-block">Add All Lines</button>
                    </form>
                </div>
            </div>

            <div class="alert alert-warn" style="margin:0;">
                Changes are saved straight to the database and every dropdown in the app reads from it, so a new, renamed or hidden
                option shows up on the next page load. (Every page reads these lists live from the database.)
            </div>
        </div>

    </div>

<?php /* ================= TAB 4: OCR ================= */ ?>
<?php elseif ($active_tab === 'departments'): ?>

    <?php mh_depts_render($conn); ?>

<?php elseif ($active_tab === 'disease'): ?>

    <?php mh_disease_tab_render($conn); ?>

<?php elseif ($active_tab === 'ocr'): ?>

    <div class="settings-grid">

        <div class="card">
            <header>
                <h3>Gemini Scanner Configuration</h3>
                <p>Used by the Immunization, Check-up and Maternal Care scan buttons.</p>
            </header>
            <div class="body">

                <?php if ($gemini_key_problem !== null): ?>
                    <div class="alert alert-error" style="margin-bottom:20px;">
                        <strong>API key problem:</strong><br><?php echo htmlspecialchars($gemini_key_problem); ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success" style="margin-bottom:20px;">
                        The saved API key has the correct format.
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo mh_csrf_field(); ?>
                    <div class="field">
                        <label>Gemini OCR API Key</label>
                        <div style="position:relative;">
                            <input type="password" id="gemini_api_key_input" name="gemini_api_key" maxlength="80"
                                   style="padding-right:70px;"
                                   value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(mh_mask_secret(mh_setting('gemini_api_key', ''))); ?>">
                            <button type="button" class="reveal-btn" onclick="mhToggleReveal('gemini_api_key_input', this)">Show</button>
                        </div>
                        <p class="hint">
                            From <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>
                            (Get API key). Starts with <code class="inline">AIzaSy</code> or <code class="inline">AQ.</code>.
                            Stored only in the database (system_settings); it is never written to a file.
                        </p>
                    </div>
                    <div class="field">
                        <label>OCR Model</label>
                        <select name="ocr_model">
                            <?php
                            $models  = ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.0-flash-001'];
                            $current = mh_setting('ocr_model', 'gemini-2.0-flash');
                            if (!in_array($current, $models, true)) { array_unshift($models, $current); }
                            foreach ($models as $m) {
                                echo '<option value="' . htmlspecialchars($m) . '"'
                                   . ($m === $current ? ' selected' : '') . '>' . htmlspecialchars($m) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="hint">
                            <strong>gemini-1.5-flash has been retired</strong> and now answers with HTTP 404 &mdash;
                            that is the usual cause of "scan failed" here. Stay on
                            <code class="inline">gemini-2.0-flash</code> unless Google announces otherwise.
                        </p>
                    </div>
                    <button type="submit" name="save_ocr" class="btn btn-main">Save OCR Settings</button>
                    <a href="?tab=ocr&amp;probe=1" class="btn btn-soft" style="text-decoration:none; display:inline-block;">Test Connection</a>
                </form>

                <?php if ($gemini_available !== null): ?>
                    <hr class="soft-hr">
                    <?php if ($gemini_available['success']): ?>
                        <div class="alert alert-success">
                            Connection OK. This key can use <?php echo count($gemini_available['models']); ?> model(s).
                        </div>
                        <div class="model-list">
                            <?php foreach ($gemini_available['models'] as $m): ?>
                                <div><?php echo htmlspecialchars($m); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($gemini_available['error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <script>
                function mhToggleReveal(inputId, btn) {
                    var el = document.getElementById(inputId);
                    if (!el) { return; }
                    if (el.type === 'password') {
                        el.type = 'text';
                        btn.textContent = 'Hide';
                    } else {
                        el.type = 'password';
                        btn.textContent = 'Show';
                    }
                }
                </script>

            </div>
        </div>

        <div class="card">
            <header><h3>Troubleshooting Guide</h3></header>
            <div class="body prose">
                <p style="margin-top:0;"><strong>HTTP 404 &ndash; model not found</strong><br>
                The model string is retired or not enabled for your key. Press <em>Test Connection</em> above
                and pick a model that actually appears in the list.</p>

                <p><strong>HTTP 401 / 403</strong><br>
                The key is wrong, deleted, restricted to other websites, or the Generative Language API is not
                enabled for the Cloud project. Keys always start with <code class="inline">AIzaSy</code>;
                anything starting with <code class="inline">AQ.</code> or <code class="inline">ya29.</code> is an
                OAuth token and will never work here.</p>

                <p><strong>HTTP 503 &ndash; high demand</strong><br>
                Transient. Each scanner already retries with exponential backoff
                (roughly 0.7s, 1.4s, 2.8s, 5.6s) and honours Google's <code class="inline">Retry-After</code>
                header before giving up, then falls through to a backup model.</p>

                <p style="margin-bottom:0;"><strong>HTTP 429</strong><br>
                Free-tier rate limit. Wait about a minute, or enable billing on the Google Cloud project.</p>
            </div>
        </div>

    </div>

<?php /* ================= TAB 5: SMS REMINDERS ================= */ ?>
<?php elseif ($active_tab === 'sms'): ?>

    <?php $active_provider = mh_active_sms_provider(); ?>

    <div class="settings-grid">

        <div class="card">
            <header>
                <h3>SMS Configuration</h3>
                <p>Used for follow-up reminders queued from Check-ups, Immunization and Maternal Care.</p>
            </header>
            <div class="body">

                <form method="POST">
                    <?php echo mh_csrf_field(); ?>

                    <div class="field">
                        <label>Active SMS Provider</label>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <label style="display:flex; align-items:flex-start; gap:10px; font-weight:600; cursor:pointer;">
                                <input type="radio" name="sms_provider" value="semaphore" style="width:auto; margin-top:3px;"
                                       <?php echo $active_provider === 'semaphore' ? 'checked' : ''; ?>>
                                <span>
                                    Semaphore SMS
                                    <span class="hint" style="display:block; font-weight:400; margin-top:2px;">
                                        Cloud SMS API - no phone needed, billed per message via semaphore.co credits.
                                    </span>
                                </span>
                            </label>
                            <label style="display:flex; align-items:flex-start; gap:10px; font-weight:600; cursor:pointer;">
                                <input type="radio" name="sms_provider" value="android_gateway" style="width:auto; margin-top:3px;"
                                       <?php echo $active_provider === 'android_gateway' ? 'checked' : ''; ?>>
                                <span>
                                    Android SMS Gateway
                                    <span class="hint" style="display:block; font-weight:400; margin-top:2px;">
                                        Sends through a phone on-site running a gateway app - no per-message cost, but the
                                        phone must stay powered on, connected, and reachable at the URL below.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <hr class="soft-hr">

                    <h4 class="sub-title">Semaphore Settings</h4>

                    <?php if ($active_provider === 'semaphore'): ?>
                        <?php if ($semaphore_key_problem !== null): ?>
                            <div class="alert alert-error" style="margin-bottom:16px;">
                                <strong>API key problem:</strong><br><?php echo htmlspecialchars($semaphore_key_problem); ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success" style="margin-bottom:16px;">
                                The Semaphore API key has the correct format.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="field">
                        <label>Semaphore API Key</label>
                        <input type="password" name="semaphore_api_key" maxlength="64"
                               value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(mh_mask_secret(mh_setting('semaphore_api_key', ''))); ?>">
                        <p class="hint">From your Semaphore account &rarr; Account &rarr; API Key.</p>
                    </div>
                    <div class="field">
                        <label>Sender Name</label>
                        <input type="text" name="semaphore_sender_name" maxlength="11"
                               value="<?php echo htmlspecialchars(mh_setting('semaphore_sender_name', 'SEMAPHORE')); ?>">
                        <p class="hint">
                            Must already be approved on your Semaphore account (Sender Names tab), 3-11 characters.
                            The sandbox default <code class="inline">SEMAPHORE</code> always works for testing.
                        </p>
                    </div>

                    <hr class="soft-hr">

                    <h4 class="sub-title">Android SMS Gateway Settings</h4>

                    <?php if ($active_provider === 'android_gateway'): ?>
                        <?php if ($android_gateway_problem_msg !== null): ?>
                            <div class="alert alert-error" style="margin-bottom:16px;">
                                <strong>Gateway problem:</strong><br><?php echo htmlspecialchars($android_gateway_problem_msg); ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success" style="margin-bottom:16px;">
                                The Android SMS Gateway endpoint looks correctly formatted.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="field">
                        <label>Gateway Endpoint URL</label>
                        <input type="text" name="android_gateway_url" maxlength="255"
                               placeholder="https://europe-west1-sms-gateway-api-simpapp.cloudfunctions.net/api_sms_send"
                               value="<?php echo htmlspecialchars(mh_setting('android_gateway_url', '')); ?>">
                        <p class="hint">
                            For the SMS Gateway API (Simpapp) use
                            <code class="inline">https://europe-west1-sms-gateway-api-simpapp.cloudfunctions.net/api_sms_send</code>.
                            A self-hosted gateway on the clinic network (e.g. <code class="inline">http://192.168.1.50:8080/send</code>)
                            also works if it accepts the same JSON request.
                        </p>
                    </div>
                    <div class="field">
                        <label>Gateway API Key <span style="font-weight:400; color:var(--text-muted);">(required for Simpapp)</span></label>
                        <input type="password" name="android_gateway_key" maxlength="128"
                               value="" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(mh_mask_secret(mh_setting('android_gateway_key', ''))); ?>">
                        <p class="hint">Sent as the <code class="inline">X-API-Key</code> header on every request. Numbers go out as <code class="inline">+639XXXXXXXXX</code> in a JSON body.</p>
                    </div>

                    <hr class="soft-hr">

                    <div class="field">
                        <label style="display:flex; align-items:center; gap:8px; font-weight:600;">
                            <input type="checkbox" name="sms_password_reset_enabled" value="1" style="width:auto;"
                                   <?php echo mh_setting('sms_password_reset_enabled', '0') === '1' ? 'checked' : ''; ?>>
                            Also text password reset links via SMS
                        </label>
                        <p class="hint">
                            When on, <code class="inline">forgot_password.php</code> sends the reset link by SMS as well as
                            email, for any account with a valid PH mobile number on file, via whichever provider is
                            active above.
                        </p>
                    </div>
                    <button type="submit" name="save_sms" class="btn btn-main">Save SMS Settings</button>
                    <?php if ($active_provider === 'semaphore'): ?>
                        <a href="?tab=sms&amp;probe=1" class="btn btn-soft" style="text-decoration:none; display:inline-block;">Check Balance</a>
                    <?php endif; ?>
                </form>

                <?php if ($semaphore_balance !== null): ?>
                    <hr class="soft-hr">
                    <?php if ($semaphore_balance['success']): ?>
                        <div class="alert alert-success">
                            Connection OK. Remaining credit balance: <strong><?php echo htmlspecialchars($semaphore_balance['balance']); ?></strong> SMS credits.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($semaphore_balance['error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        </div>

        <div>
            <div class="card">
                <header><h3>Send a Test SMS</h3></header>
                <div class="body">
                    <form method="POST">
                        <?php echo mh_csrf_field(); ?>
                        <div class="field">
                            <label>Phone Number</label>
                            <input type="text" name="test_phone_number" placeholder="09171234567" required>
                            <p class="hint">Accepts 09XXXXXXXXX, +639XXXXXXXXX or 639XXXXXXXXX. Sent via the active provider above (<?php echo $active_provider === 'android_gateway' ? 'Android SMS Gateway' : 'Semaphore'; ?>).</p>
                        </div>
                        <button type="submit" name="send_test_sms" class="btn btn-main btn-block">Send Test SMS Now</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <header><h3>Follow-up Reminder Queue</h3></header>
                <div class="body prose">
                    <p style="margin-top:0;">
                        When a health worker enters a follow-up date in Check-ups, Immunization or Maternal Care,
                        the system automatically queues reminder SMS in <code class="inline">scheduled_sms</code>
                        (one day before, and the morning of, by default).
                    </p>
                    <p style="margin-bottom:0;">
                        On a live server a scheduled task runs
                        <code class="inline">cron/send_daily_reminders.php</code> every morning to send anything due.
                        On localhost, use the
                        <a href="sms_dashboard.php" style="color: var(--primary, #4ca1af); font-weight:700;">SMS Reminders dashboard</a>
                        to trigger the queue manually and see delivery logs.
                    </p>
                </div>
            </div>
        </div>

    </div>

<?php /* ================= STAFF ROLES & PERMISSIONS ================= */ ?>
<?php elseif ($active_tab === 'roles'): ?>
    <?php
        $all_roles = mh_role_definitions(true);          // system_roles (active + hidden)
        $registry  = mh_permission_registry();           // feature toggles grouped by category
        $role_use  = [];
        if ($ru = $conn->query("SELECT role, COUNT(*) AS n FROM staff GROUP BY role")) {
            while ($r = $ru->fetch_assoc()) { $role_use[$r['role']] = (int) $r['n']; }
        }
        $perm_sets = [];
        foreach ($all_roles as $rk => $_) { $perm_sets[$rk] = mh_role_permissions($rk); }
    ?>
    <style>
        .pm-wrap { overflow:auto; max-height:70vh; border:1px solid var(--border-color,#e2e8f0); border-radius:8px; }
        table.pm { border-collapse:separate; border-spacing:0; width:100%; min-width:640px; }
        table.pm th, table.pm td { padding:8px 10px; border-bottom:1px solid var(--border-color,#e2e8f0); text-align:center; background:var(--bg-card,#fff); }
        table.pm thead th { position:sticky; top:0; z-index:3; font-size:0.78rem; }
        table.pm th:first-child, table.pm td:first-child { position:sticky; left:0; z-index:2; text-align:left; min-width:230px; }
        table.pm thead th:first-child { z-index:4; }
        table.pm tr.grp td { background:var(--bg-hover,#f1f5f9); font-weight:700; font-size:0.8rem; text-transform:uppercase; letter-spacing:.04em; text-align:left; }
        .pm-sw { position:relative; display:inline-block; width:38px; height:22px; }
        .pm-sw input { opacity:0; width:0; height:0; }
        .pm-sw span { position:absolute; inset:0; background:#cbd5e1; border-radius:22px; cursor:pointer; transition:.15s; }
        .pm-sw span::before { content:""; position:absolute; height:16px; width:16px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.15s; }
        .pm-sw input:checked + span { background:var(--primary,#4ca1af); }
        .pm-sw input:checked + span::before { transform:translateX(16px); }
        .pm-sw input:disabled + span { opacity:.55; cursor:not-allowed; }
        .pm-colbtn { font-size:0.68rem; color:var(--primary,#4ca1af); cursor:pointer; background:none; border:0; padding:0 3px; }
    </style>

    <div class="alert alert-success" style="margin-bottom:18px;">
        Everything here is stored in the database (<code>system_roles</code> and <code>role_permissions</code>) - no code edits needed.
        A new role appears at once in <strong>Staff Management &rarr; Account Role</strong> and starts with <strong>no access</strong> until you switch features on below.
        Changes apply on each user's next click. <strong>Health Head</strong> is locked to everything; <strong>Admin</strong> always keeps Staff Management.
    </div>

    <div class="card">
        <header>
            <h3>Create New Role</h3>
            <p>For example <em>Encoder</em>, <em>Inventory / Pharmacist</em>, <em>Triage Nurse</em>.</p>
        </header>
        <div class="body">
            <form method="POST" style="display:flex; gap:8px;">
                <?php echo mh_csrf_field(); ?>
                <input type="text" name="role_label" maxlength="60" required placeholder="New role name, e.g. Triage Nurse" style="flex:1;">
                <button type="submit" name="add_role" class="btn btn-main">Add Role</button>
            </form>

            <table class="opts" style="margin-top:18px;">
                <thead><tr><th>Role</th><th style="width:90px;">Accounts</th><th style="width:360px;"></th></tr></thead>
                <tbody>
                <?php foreach ($all_roles as $rk => $rd): ?>
                    <?php if ($rd['builtin']): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($rd['label']); ?></strong> <span class="hint">system role &middot; <code><?php echo htmlspecialchars($rk); ?></code></span></td>
                        <td><?php echo (int) ($role_use[$rk] ?? 0); ?></td>
                        <td class="hint">Cannot be renamed or deleted</td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td colspan="3" style="padding:0;">
                            <form method="POST" style="display:flex; gap:8px; align-items:center; padding:7px 8px;">
                                <?php echo mh_csrf_field(); ?>
                                <input type="hidden" name="role_id" value="<?php echo (int) $rd['id']; ?>">
                                <input type="text" name="role_label" maxlength="60" required style="flex:1;" value="<?php echo htmlspecialchars($rd['label']); ?>">
                                <span class="hint" style="width:90px;"><?php echo (int) ($role_use[$rk] ?? 0); ?> account(s)</span>
                                <label style="display:flex; align-items:center; gap:5px; font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">
                                    <input type="checkbox" name="is_active" value="1" <?php echo $rd['active'] ? 'checked' : ''; ?>
                                           onchange="this.form.requestSubmit(this.form.querySelector('[name=update_role]'));" style="width:16px; height:16px;">
                                    Shown
                                </label>
                                <button type="submit" name="update_role" class="btn btn-soft btn-mini">Save</button>
                                <button type="submit" name="delete_role" class="btn btn-danger btn-mini" data-confirm="Delete this role and its permissions?" data-confirm-title="Delete role?" data-confirm-type="danger" data-confirm-ok="Delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" id="permForm">
        <?php echo mh_csrf_field(); ?>
        <div class="card" style="margin-top:20px;">
            <header>
                <h3>Feature Permission Matrix</h3>
                <p>Switch features on or off per role. Module on/off switches live under <strong>Module Access</strong>.</p>
            </header>
            <div class="body">
                <div class="pm-wrap">
                <table class="pm">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <?php foreach ($all_roles as $rk => $rd): ?>
                                <th title="<?php echo htmlspecialchars($rk); ?>">
                                    <?php echo htmlspecialchars($rd['label']); ?><?php echo $rd['active'] ? '' : ' <span class="hint">(hidden)</span>'; ?>
                                    <?php if ($rk !== 'health_head'): ?>
                                        <br><button type="button" class="pm-colbtn" onclick="pmColumn('<?php echo htmlspecialchars($rk); ?>', true)">all</button>
                                        <button type="button" class="pm-colbtn" onclick="pmColumn('<?php echo htmlspecialchars($rk); ?>', false)">none</button>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($registry as $group => $items): ?>
                        <tr class="grp"><td colspan="<?php echo count($all_roles) + 1; ?>"><?php echo htmlspecialchars($group); ?></td></tr>
                        <?php foreach ($items as $pk => $pd): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($pd['label']); ?></strong>
                                <div class="hint"><?php echo htmlspecialchars($pd['hint']); ?></div></td>
                            <?php foreach ($all_roles as $rk => $rd): ?>
                                <?php
                                    $locked = ($rk === 'health_head') || ($rk === 'admin' && $pk === 'manage_staff');
                                    $on     = $locked || !empty($perm_sets[$rk][$pk]);
                                ?>
                                <td>
                                    <label class="pm-sw" title="<?php echo $locked ? 'Always on' : ''; ?>">
                                        <input type="checkbox" data-role="<?php echo htmlspecialchars($rk); ?>"
                                               name="perm[<?php echo htmlspecialchars($rk); ?>][]" value="<?php echo htmlspecialchars($pk); ?>"
                                               <?php echo $on ? 'checked' : ''; ?> <?php echo $locked ? 'disabled' : ''; ?>>
                                        <span></span>
                                    </label>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button type="submit" name="save_permissions" data-confirm="Save the role permission changes? They apply to every account with those roles." data-confirm-title="Save permissions?" data-confirm-type="warning" data-confirm-ok="Save" class="btn btn-main" style="margin-top:18px;">Save Permissions</button>
            </div>
        </div>
    </form>
    <script>
        function pmColumn(role, on) {
            document.querySelectorAll('#permForm input[type=checkbox][data-role="' + role + '"]').forEach(function (cb) {
                if (!cb.disabled) { cb.checked = on; }
            });
        }
    </script>

<?php elseif ($active_tab === 'modules'): ?>

    <?php
        $module_registry = mh_module_registry();
    ?>

    <div class="alert alert-warn" style="margin-bottom:18px;">
        Turning a module <strong>Off</strong> blocks it for every role, admins included — use that for "we don't
        offer this service" cases. <strong>Who</strong> may use each feature is no longer set here - use the
        <a href="?tab=roles"><strong>Staff Roles &amp; Permissions</strong></a> tab. System Settings itself is never gated by these switches.
    </div>
    <div class="alert alert-success" style="margin-bottom:18px;">
        <strong>Clinic Departments follow these settings.</strong> In Patient Queueing, a department is matched to a module by its
        name (names containing <em>General/Consultation</em> &rarr; Consultations, <em>Dental</em> &rarr; Dental Records,
        <em>Maternal/Prenatal</em> &rarr; Maternal Care, <em>Immunization/Child</em> &rarr; Child Immunization,
        <em>Inventory/Pharmacy/Medicine</em> &rarr; Medicine Inventory). Doctors, Midwives and Dental staff only see a department
        whose module is On and open to their role; Admin and BHW see every department, including brand-new ones that match no module.
        <strong>Inventory / Pharmacist</strong> accounts see only the departments that map to Medicine Inventory (and any other module you tick for them below).
    </div>

    <form method="POST">
        <?php echo mh_csrf_field(); ?>

        <div class="card">
            <header>
                <h3>Module Visibility</h3>
                <p>Applies instantly to the sidebar nav and to the page itself (a direct link to a hidden module is also blocked).</p>
            </header>
            <div class="body" style="overflow-x:auto;">
                <table class="opts" style="min-width:560px;">
                    <thead>
                        <tr>
                            <th style="width:24%;">Module</th>
                            <th style="width:10%; text-align:center;">Enabled</th>
                            <th style="text-align:left;">Roles with access (from Staff Roles &amp; Permissions)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($module_registry as $modKey => $mod): ?>
                            <?php
                                $isEnabled    = mh_module_is_enabled($modKey);
                                $allowedRoles = mh_module_allowed_roles($modKey);
                            ?>
                            <tr>
                                <td>
                                    <input type="text" name="module_label[<?php echo htmlspecialchars($modKey); ?>]" maxlength="60"
                                           value="<?php echo htmlspecialchars(mh_module_label($modKey)); ?>"
                                           placeholder="<?php echo htmlspecialchars($mod['label']); ?>" title="Display name (leave blank for the built-in name)"
                                           style="font-weight:700; margin-bottom:4px;">
                                    <p class="hint" style="margin:2px 0 0;"><?php echo htmlspecialchars($mod['hint']); ?></p>
                                </td>
                                <td style="text-align:center;">
                                    <input type="checkbox" name="module_enabled[<?php echo htmlspecialchars($modKey); ?>]"
                                           value="1" style="width:18px; height:18px;"
                                           <?php echo $isEnabled ? 'checked' : ''; ?>>
                                </td>
                                <td class="hint"><?php
                                    $names = [];
                                    foreach ($allowedRoles as $rk2) { if ($rk2 !== 'health_head') { $names[] = mh_role_label($rk2); } }
                                    echo $names ? htmlspecialchars(implode(', ', $names)) : 'Health Head only';
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h4 style="margin:26px 0 6px;">Other sidebar names</h4>
                <p class="hint" style="margin:0 0 10px;">Rename the remaining menu items. Leave blank to use the built-in name.</p>
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:12px; margin-bottom:18px;">
                    <?php foreach (mh_nav_label_defaults() as $nk => $ndef): ?>
                        <div><label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;"><?php echo htmlspecialchars($ndef); ?></label>
                            <input type="text" name="nav_label[<?php echo htmlspecialchars($nk); ?>]" maxlength="60"
                                   value="<?php echo htmlspecialchars(mh_nav_label($nk, '')); ?>" placeholder="<?php echo htmlspecialchars($ndef); ?>"></div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" name="save_modules" data-confirm="Save the module switch changes?" data-confirm-title="Save modules?" data-confirm-type="warning" data-confirm-ok="Save" class="btn btn-main" style="margin-top:18px;">Save Module Switches</button>
            </div>
        </div>
    </form>

<?php endif; ?>

    </div>
</div>
</body>
</html>