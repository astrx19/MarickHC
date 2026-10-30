<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - CENTRAL SESSION / AUTH MODULE
 * =====================================================================
 * Single source of truth for:
 *   - starting the session + sending no-cache headers on any protected
 *     page that includes it (directly or via includes/admin_auth.php)
 *   - checking whether a STAFF member or a PATIENT is logged in
 *   - sending an unauthenticated staff visitor to the dedicated
 *     admin/login.php page - never the public patient homepage
 *
 * Session keys
 * ------------
 *   Staff   : `staff_id`, `user_role`   (canonical - set by admin/login.php)
 *             `user_id`,  `role`        (legacy aliases, see below)
 *   Patient : `patient_id`, `patient_name`  (unchanged - set by
 *             patient_login_process.php)
 *
 * These two key sets never overlap, so a staff session and a patient
 * session stored in the same PHP session can never be confused with
 * one another - logging in as staff never populates `patient_id` and
 * vice versa.
 *
 * Backward compatibility
 * -----------------------
 * 25+ existing admin/*.php files were written directly against the
 * original `user_id` / `role` keys, before this module existed.
 * Rather than rewrite every one of those files (and risk missing one),
 * admin/login.php sets BOTH the canonical and legacy keys on login, and
 * this module mirrors canonical -> legacy on every request as a safety
 * net. Old code keeps working completely unchanged.
 * =====================================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------
// Prevent the browser (and any intermediate cache) from storing a copy
// of whatever protected page includes this file. Without this, hitting
// "back" after logout can redisplay a cached copy of the page instead
// of re-checking the session.
// ---------------------------------------------------------------------
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
    header("Pragma: no-cache");
    header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");
}

// ---------------------------------------------------------------------
// Legacy session key mirroring (staff side only - patient keys are
// untouched, they've always just been `patient_id` / `patient_name`).
// ---------------------------------------------------------------------
if (isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $_SESSION['staff_id'];
}
if (isset($_SESSION['user_role']) && !isset($_SESSION['role'])) {
    $_SESSION['role'] = $_SESSION['user_role'];
}

require_once __DIR__ . '/session_guard.php';   // one active session per account
require_once __DIR__ . '/roles.php';   // mh_get_roles(), mh_has_role(), ... (no side effects)

if (!function_exists('mh_staff_logged_in')) {
    function mh_staff_logged_in()
    {
        return isset($_SESSION['staff_id']) || isset($_SESSION['user_id']);
    }
}

if (!function_exists('mh_patient_logged_in')) {
    function mh_patient_logged_in()
    {
        return isset($_SESSION['patient_id']);
    }
}

if (!function_exists('mh_admin_login_url')) {
    /**
     * Absolute (root-relative) URL to admin/login.php - correct no
     * matter how deep the current script sits under /admin/
     * (admin/dashboard.php, admin/api/save_dashboard.php, ...) and no
     * matter whether the app lives at the web root or a subfolder
     * (e.g. XAMPP's /marick-health-system-v2/). A root-relative path
     * sidesteps the directory-depth bugs a relative "../login.php"
     * would have across files at different nesting levels.
     */
    function mh_admin_login_url()
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/admin/login.php'));
        $pos = strrpos($script, '/admin/');
        if ($pos !== false) {
            return substr($script, 0, $pos) . '/admin/login.php';
        }
        return '/admin/login.php';
    }
}

if (!function_exists('require_staff_login')) {
    /**
     * Guards a staff-only admin page. An unauthenticated visitor is
     * sent to the dedicated staff login page - never the public
     * patient homepage - so a bookmarked/typed admin URL always lands
     * somewhere a staff member can actually sign in from.
     */
    function require_staff_login()
    {
        if (!mh_staff_logged_in()) {
            header("Location: " . mh_admin_login_url());
            exit();
        }
        // Re-read role + capability flags from the database on every
        // request so a demotion or a revoked delegation applies at once
        // instead of only after the person logs in again.
        mh_ss_enforce('staff');   // signed in elsewhere since? -> this session ends
        mh_refresh_staff_authority();
    }
}

if (!function_exists('require_patient_login')) {
    /**
     * Mirror of require_staff_login() for the patient portal, kept here
     * for symmetry. includes/patient_auth.php (the file the patient/*
     * pages actually include) already implements this same check
     * inline and is left as-is; this is available for any new patient
     * page that wants to include this module directly instead.
     */
    function require_patient_login()
    {
        if (!mh_patient_logged_in()) {
            header("Location: ../index.php");
            exit();
        }
    }
}


/* =====================================================================
 * ROLE TIERS + DELEGATED CAPABILITIES
 * =====================================================================
 * Hierarchy (highest first):
 *   health_head - master authority: System Settings (API keys, SMS
 *                 gateway, Gemini OCR, DB-level config) and ALL accounts,
 *                 including Admins.
 *   admin       - operational administrator. What it may open is now
 *                 DATABASE-DRIVEN like every other role (role_permissions,
 *                 seeded with the old defaults). Never System Settings.
 *   doctor / midwife / dental / bhw / inventory - clinical / pharmacy staff. Their primary role
 *                 gives the usual modules; an Admin can additionally
 *                 switch on granular capability flags (staff.can_manage_* / can_log_* / can_view_*) so
 *                 someone can cover a colleague's duties temporarily
 *                 without changing their job title.
 *
 * Page access = a feature toggle on the role (role_permissions, edited under
 * System Settings -> Staff Roles & Permissions) OR an enabled per-account
 * capability flag. Only health_head implicitly holds everything.
 * Use mh_has_permission() / require_permission() in new code; the older
 * mh_has_capability() / require_capability() keep working and now resolve
 * through the same database toggles.
 * ================================================================== */

if (!function_exists('mh_capability_registry')) {
    /**
     * GRANULAR sub-module capability flags. column name => definition.
     * Single source of truth: the migration, the Staff Management form,
     * the sidebar and every page guard read this.
     *
     * Naming convention
     *   can_manage_*  write actions on a sub-module (edit/deduct/approve)
     *   can_log_*     create new records in a clinical module
     *   can_view_*    read-only access to lists / history
     *
     * definition keys
     *   label  - checkbox label            hint  - one-line description
     *   group  - heading in the staff form module - settings_helper module
     *            key (Module Access tab), or null when the feature has none
     *   perms  - role feature toggles (role_permissions) that satisfy this
     *            capability; ANY one is enough. Which roles hold them is
     *            edited in System Settings -> Staff Roles & Permissions.
     *   grants - feature toggles the per-account flag itself hands out
     *            (empty for read-only flags, which grant no toggle)
     */
    function mh_capability_registry()
    {
        return [
            // ---- Medicine Inventory: three separate duties ----------------
            'can_manage_supply_masterlist' => ['group' => 'Medicine Inventory', 'label' => 'Supply / Stock Masterlist',
                'hint' => 'View/edit stock levels, packaging types and item master records', 'module' => 'inventory', 'perms' => ['view_stock', 'add_medicine'], 'grants' => ['view_stock', 'add_medicine']],
            'can_manage_dispensing'        => ['group' => 'Medicine Inventory', 'label' => 'Medicine Dispensing',
                'hint' => 'Record patient dispensing and deduct active stock', 'module' => 'inventory', 'perms' => ['dispense_medicine'], 'grants' => ['dispense_medicine']],
            'can_view_dispensing_history'  => ['group' => 'Medicine Inventory', 'label' => 'Dispensing Log History',
                'hint' => 'Read-only view of past dispensing records', 'module' => 'inventory', 'perms' => ['view_dispensing_history'], 'grants' => ['view_dispensing_history']],

            // ---- General Consultations: logging vs history ----------------
            'can_log_consultations'        => ['group' => 'General Consultations', 'label' => 'Log Consultations',
                'hint' => 'Record new consultation / checkup entries', 'module' => 'checkups', 'perms' => ['manage_consultations'], 'grants' => ['manage_consultations']],
            'can_view_consultation_history'=> ['group' => 'General Consultations', 'label' => 'View Consultation History',
                'hint' => 'Read-only view of past consultation entries', 'module' => 'checkups', 'perms' => ['manage_consultations'], 'grants' => []],

            // ---- Child Immunization ---------------------------------------
            'can_log_immunization'         => ['group' => 'Child Immunization', 'label' => 'Log Immunization',
                'hint' => 'Create immunization forms, administer vaccines, log child consults', 'module' => 'immunization', 'perms' => ['manage_child_immunization'], 'grants' => ['manage_child_immunization']],
            'can_view_immunization_records'=> ['group' => 'Child Immunization', 'label' => 'View Immunization Records',
                'hint' => 'Read-only view of immunization lists and vaccination history', 'module' => 'immunization', 'perms' => ['manage_child_immunization'], 'grants' => []],

            // ---- Maternal Care --------------------------------------------
            'can_log_maternal'             => ['group' => 'Maternal Care', 'label' => 'Log Prenatal Visits',
                'hint' => 'Record new prenatal checkups', 'module' => 'maternal_care', 'perms' => ['manage_maternal_care'], 'grants' => ['manage_maternal_care']],
            'can_view_maternal_history'    => ['group' => 'Maternal Care', 'label' => 'View Prenatal History',
                'hint' => 'Read-only view of past prenatal records', 'module' => 'maternal_care', 'perms' => ['manage_maternal_care'], 'grants' => []],

            // ---- Dental ---------------------------------------------------
            'can_log_dental'               => ['group' => 'Dental Records', 'label' => 'Log Dental Visits',
                'hint' => 'Record new dental visits', 'module' => 'dental', 'perms' => ['manage_dental_records'], 'grants' => ['manage_dental_records']],
            'can_view_dental_history'      => ['group' => 'Dental Records', 'label' => 'View Dental History',
                'hint' => 'Read-only view of past dental visits', 'module' => 'dental', 'perms' => ['manage_dental_records'], 'grants' => []],

            // ---- Patient Queue --------------------------------------------
            'can_view_queue'               => ['group' => 'Patient Queue', 'label' => 'View Patient Queue',
                'hint' => 'See incoming and scheduled queue items (no actions)', 'module' => null, 'perms' => ['view_patient_queue', 'manage_patient_queue'], 'grants' => ['view_patient_queue']],
            'can_manage_queue'             => ['group' => 'Patient Queue', 'label' => 'Process Patient Queue',
                'hint' => 'Approve, proceed, reschedule or reject queue items', 'module' => null, 'perms' => ['manage_patient_queue'], 'grants' => ['manage_patient_queue']],

            // ---- Area Masterlist ------------------------------------------
            'can_view_area_masterlist'     => ['group' => 'Area Masterlist', 'label' => 'View Area Masterlist',
                'hint' => 'Resident / outsider counts per area', 'module' => null, 'perms' => ['view_area_masterlist'], 'grants' => ['view_area_masterlist']],
        ];
    }
}

if (!function_exists('mh_capabilities_for_module')) {
    /** settings_helper module key (e.g. 'checkups') -> list of capability columns. */
    function mh_capabilities_for_module($moduleKey)
    {
        $out = [];
        foreach (mh_capability_registry() as $cap => $def) {
            if ($def['module'] === $moduleKey) { $out[] = $cap; }
        }
        return $out;
    }
}

if (!function_exists('mh_capability_for_module')) {
    /** Back-compat shim: first capability of a module, or null. */
    function mh_capability_for_module($moduleKey)
    {
        $caps = mh_capabilities_for_module($moduleKey);
        return $caps ? $caps[0] : null;
    }
}

if (!function_exists('mh_current_role')) {
    function mh_current_role()
    {
        return (string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
    }
}
if (!function_exists('mh_is_health_head')) {
    function mh_is_health_head($role = null)
    {
        return ($role ?? mh_current_role()) === 'health_head';
    }
}
if (!function_exists('mh_is_admin_tier')) {
    /** health_head OR admin: implicitly holds every operational capability. */
    function mh_is_admin_tier($role = null)
    {
        return in_array($role ?? mh_current_role(), ['health_head', 'admin'], true);
    }
}

if (!function_exists('mh_auth_db')) {
    /** The shared mysqli connection, loading config/database.php on demand. */
    function mh_auth_db()
    {
        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
            // `global` makes the include assign the real global $conn.
            (function () {
                global $conn;
                require_once __DIR__ . '/../config/database.php';
            })();
        }
        return $GLOBALS['conn'] ?? null;
    }
}

if (!function_exists('mh_caps_tristate_ready')) {
    /** True once the staff.can_* columns accept NULL (explicit-deny overrides possible). Cached. */
    function mh_caps_tristate_ready()
    {
        static $ready = null;
        if ($ready !== null) { return $ready; }
        $ready = false;
        $conn = mh_auth_db();
        if (!$conn) { return false; }
        try {
            $res = $conn->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'can_manage_dispensing' LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            $ready = $row && strtoupper($row['IS_NULLABLE']) === 'YES';
        } catch (Throwable $e) { $ready = false; }
        return $ready;
    }
}

if (!function_exists('mh_staff_caps_upgrade')) {
    /**
     * One-time, idempotent: make every staff.can_* column NULL-able and turn the old "0 = not
     * delegated" into NULL (inherit role). Existing 1s stay grants, so nobody gains or loses access.
     * Same as database/delegated_overrides_migration.sql.
     */
    function mh_staff_caps_upgrade(mysqli $conn)
    {
        if (mh_caps_tristate_ready()) { return true; }
        try {
            $have = [];
            $res = $conn->query("SHOW COLUMNS FROM `staff`");
            while ($res && ($c = $res->fetch_assoc())) { $have[$c['Field']] = true; }
            foreach (array_keys(mh_capability_registry()) as $col) {
                if (!isset($have[$col])) { continue; }
                $conn->query("ALTER TABLE `staff` MODIFY `$col` TINYINT(1) NULL DEFAULT NULL");
                $conn->query("UPDATE `staff` SET `$col` = NULL WHERE `$col` = 0");
            }
        } catch (Throwable $e) {
            error_log('mh_staff_caps_upgrade: ' . $e->getMessage());
            return false;
        }
        return true;
    }
}

if (!function_exists('mh_refresh_staff_authority')) {
    /**
     * Loads the signed-in staff member's CURRENT role + capability flags
     * (one indexed query per request, memoised) and re-syncs the session
     * role keys. If the account was deleted, the session is dropped.
     *
     * If the capability columns have not been created yet
     * (database/granular_permissions_migration.sql not applied) the role is
     * STILL re-synced from the database - otherwise a freshly promoted
     * Health Head would keep a stale session role and never see System
     * Settings - and every flag simply reads as 0.
     *
     * Each flag is TRI-STATE once mh_staff_caps_upgrade() has run:
     *   NULL = inherit the role's toggles   1 = explicitly granted   0 = explicitly DENIED
     * (an explicit 0 removes a function the role would otherwise give).
     * On a legacy install (NOT NULL columns) 0 can only mean "not delegated", so it reads as NULL.
     *
     * @return array<string,int|null>  capability column => null|0|1
     */
    function mh_refresh_staff_authority($force = false)
    {
        static $caps = null;
        if ($caps !== null && !$force) { return $caps; }

        $caps = array_fill_keys(array_keys(mh_capability_registry()), null);

        $id = (int) ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
        $conn = mh_auth_db();
        if ($id <= 0 || !$conn) { return $caps; }

        $row = null;
        try {
            $cols = implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($caps)));
            $stmt = $conn->prepare("SELECT `role`, $cols FROM `staff` WHERE `id` = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            // Capability columns missing (mysqli throws on PHP 8.1+). Fall
            // back to a role-only lookup instead of fataling every page.
            error_log('mh_refresh_staff_authority: ' . $e->getMessage());
            try {
                $stmt = $conn->prepare("SELECT `role` FROM `staff` WHERE `id` = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } catch (Throwable $e2) {
                error_log('mh_refresh_staff_authority (role only): ' . $e2->getMessage());
                return $caps;
            }
        }

        if ($row) {   // archived while signed in -> treated like a removed account
            try {
                $stA = $conn->prepare("SELECT `status` FROM `staff` WHERE `id` = ? LIMIT 1");
                if ($stA) {
                    $stA->bind_param('i', $id); $stA->execute();
                    $sa = $stA->get_result()->fetch_assoc();
                    $stA->close();
                    if ($sa && $sa['status'] !== null && (int) $sa['status'] === 0) { $row = null; }
                }
            } catch (Throwable $eA) { /* status column not created yet: nobody is archived */ }
        }

        if (($row['role'] ?? '') === 'super' . '_admin') {   // legacy account from before the Health Head restructure
            mh_roles_upgrade_health_head($conn);
            $row['role'] = 'health_head';
        }

        if (!$row) { // account deleted while signed in
            session_unset();
            header('Location: ' . mh_admin_login_url());
            exit();
        }

        $_SESSION['role'] = $_SESSION['user_role'] = $row['role'];
        foreach ($caps as $c => $_) {
            if (!array_key_exists($c, $row) || $row[$c] === null) { continue; }
            $v = (int) $row[$c];
            if ($v === 0 && !mh_caps_tristate_ready()) { continue; }   // legacy 0 = not delegated
            $caps[$c] = $v;
        }
        return $caps;
    }
}

if (!function_exists('mh_role_default_grants')) {
    /** Does the signed-in user's ROLE (database toggles) satisfy this capability? */
    function mh_role_default_grants($capability)
    {
        $reg = mh_capability_registry();
        if (!isset($reg[$capability])) { return false; }
        $role = mh_current_role();
        if ($role === 'health_head') { return true; }
        $held = mh_role_permissions($role);
        foreach ($reg[$capability]['perms'] as $p) { if (!empty($held[$p])) { return true; } }
        return false;
    }
}

if (!function_exists('mh_capability_owners_of')) {
    /** Capability columns whose per-account flag controls this feature toggle (their 'grants'). */
    function mh_capability_owners_of($permission_key)
    {
        $out = [];
        foreach (mh_capability_registry() as $cap => $def) {
            if (in_array($permission_key, $def['grants'], true)) { $out[] = $cap; }
        }
        return $out;
    }
}

if (!function_exists('mh_role_default_caps')) {
    /** [capability => bool]: what a ROLE gives with no per-account override (prefills the staff form). */
    function mh_role_default_caps($role)
    {
        $out = [];
        $held = mh_role_permissions($role);
        foreach (mh_capability_registry() as $cap => $def) {
            $ok = ((string) $role === 'health_head');
            foreach ($def['perms'] as $p) { if (!empty($held[$p])) { $ok = true; } }
            $out[$cap] = $ok;
        }
        return $out;
    }
}

if (!function_exists('mh_has_capability')) {
    /**
     * One granular capability for the signed-in user. Health Head always. Otherwise the
     * per-account flag decides when set (1 = granted, 0 = DENIED even if the role has it);
     * with no flag (NULL) the role's feature toggles decide.
     */
    function mh_has_capability($capability)
    {
        if (mh_is_health_head()) { return true; }
        $caps = mh_refresh_staff_authority();
        if (isset($caps[$capability]) && $caps[$capability] !== null) { return (int) $caps[$capability] === 1; }
        return mh_role_default_grants($capability);
    }
}

if (!function_exists('mh_has_any_capability')) {
    function mh_has_any_capability(array $capabilities)
    {
        foreach ($capabilities as $c) {
            if (mh_has_capability($c)) { return true; }
        }
        return false;
    }
}

if (!function_exists('mh_can_access')) {
    /**
     * Generic test: health_head, OR role key in $allowedRoles (identity list, only for
     * legacy callers - prefer a permission), OR the (optional) capability.
     */
    function mh_can_access(array $allowedRoles, $capability = null)
    {
        if (mh_is_health_head()) { return true; }
        if ($allowedRoles && in_array(mh_current_role(), $allowedRoles, true)) { return true; }
        if ($capability === null) { return false; }
        return mh_has_any_capability((array) $capability);
    }
}

if (!function_exists('mh_deny_access')) {
    function mh_deny_access($json = false)
    {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'You do not have permission for this action.']);
            exit();
        }
        echo "<div style='padding: 20px; text-align: center; font-family: sans-serif;'>";
        echo "<h2>Access Denied</h2>";
        echo "<p>You do not have permission to view this page. If you are covering for a colleague, ask an Administrator to enable the matching function on your account.</p>";
        echo "<a href='dashboard.php'>Return to Dashboard</a>";
        echo "</div>";
        exit();
    }
}

if (!function_exists('require_access')) {
    /** Page guard: primary role OR capability flag (admin tier always passes). */
    function require_access(array $allowedRoles, $capability = null)
    {
        mh_refresh_staff_authority();
        if (!mh_can_access($allowedRoles, $capability)) { mh_deny_access(); }
    }
}

if (!function_exists('require_capability')) {
    /**
     * Page / action guard for ONE granular capability, or an array of
     * capabilities of which any one is enough. Pass $json = true from AJAX
     * endpoints so the 403 is JSON instead of an HTML page.
     */
    function require_capability($capability, $json = false)
    {
        mh_refresh_staff_authority();
        if (!mh_has_any_capability((array) $capability)) { mh_deny_access($json); }
    }
}

if (!function_exists('require_permission')) {
    /**
     * Page / action guard on feature toggle(s) from role_permissions. One key or an array of
     * keys (any one is enough). $json = true for AJAX/OCR endpoints (403 as JSON).
     * Call it at page load AND again at the top of each POST handler that changes data.
     */
    function require_permission($permission, $json = false)
    {
        mh_refresh_staff_authority();
        if (!mh_has_any_permission((array) $permission)) { mh_deny_access($json); }
    }
}

if (!function_exists('require_health_head')) {
    function require_health_head()
    {
        mh_refresh_staff_authority();
        if (!mh_is_health_head()) { mh_deny_access(); }
    }
}

/* ---------------------------------------------------------------------
 * Deprecated aliases: the Super Admin role was retired in favour of Health Head.
 * Kept only so any custom page still calling the old names keeps working.
 * ------------------------------------------------------------------ */
if (!function_exists('mh_is_super_admin')) {
    function mh_is_super_admin($role = null) { return mh_is_health_head($role); }
}
if (!function_exists('require_super_admin')) {
    function require_super_admin() { require_health_head(); }
}
