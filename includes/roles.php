<?php
// Load-once guard keyed on a constant, NOT on the file path: on case-insensitive filesystems
// (macOS/Windows XAMPP) /MARICK-HEALTH-SYSTEM-v2/ and /marick-health-system-v2/ are different
// strings to require_once but the same file, which would redeclare every function.
if (defined('MH_ROLES_LOADED')) { return; }
define('MH_ROLES_LOADED', true);
/**
 * =====================================================================
 * MARICK HEALTH CENTER - ROLES & PERMISSIONS (database-driven)
 * =====================================================================
 * Roles live in `system_roles`, feature toggles in `role_permissions`
 * (see database/roles_permissions_migration.sql). Nothing here decides
 * WHO may do something - only WHAT can be toggled (the permission
 * registry) and the factory defaults used to seed an empty database or
 * to keep the app usable if the tables have not been created yet.
 *
 *   mh_get_roles()                  role_key => label (active roles)
 *   mh_has_permission($key,$role)   does a role hold a feature toggle?
 *   mh_permission_registry()        the toggles, grouped for the matrix UI
 *   mh_roles_install($conn)         create + seed tables (idempotent)
 *   mh_add/update/delete_custom_role, mh_save_role_permissions
 *
 * The value stored in staff.role is system_roles.role_key (VARCHAR(50)).
 * Free of session/header side effects, so it is safe in CLI/cron too.
 * =====================================================================
 */

/* ---------------------------------------------------------------------
 * 1. PERMISSION REGISTRY (what can be toggled - single source of truth)
 * ------------------------------------------------------------------ */
if (!function_exists('mh_permission_registry')) {
    /** group label => [permission_key => ['label' =>, 'hint' =>]] */
    function mh_permission_registry()
    {
        return [
            'Patient Management & OCR' => [
                'view_patient_masterlist' => ['label' => 'View Patient Masterlist',        'hint' => 'Open the patient list and patient profiles'],
                'edit_patient_profile'    => ['label' => 'Add / Edit Patient Profile',     'hint' => 'Edit or archive patient records'],
                'walkin_registration'     => ['label' => 'Walk-In Registration',           'hint' => 'Register a patient and add them to the queue'],
                'use_ocr_scanner'         => ['label' => 'OCR Scanner (Fast Encoding)',    'hint' => 'Scan paper records to auto-fill forms'],
            ],
            'Inventory & Pharmacy' => [
                'view_stock'              => ['label' => 'View Stock',                     'hint' => 'Open the supply masterlist'],
                'add_medicine'            => ['label' => 'Add / Edit Medicine',            'hint' => 'Create items, edit them, adjust stock'],
                'dispense_medicine'       => ['label' => 'Dispense Medicine',              'hint' => 'Issue medicine to patients and deduct stock'],
                'view_dispensing_history' => ['label' => 'View Dispensing History',        'hint' => 'Read-only dispensing log'],
            ],
            'Clinical & Queueing' => [
                'view_patient_queue'       => ['label' => 'View Patient Queue',            'hint' => 'See incoming queue items'],
                'manage_patient_queue'     => ['label' => 'Process Patient Queue',         'hint' => 'Approve, proceed, reschedule or reject'],
                'manage_consultations'     => ['label' => 'Consultations',                 'hint' => 'Log and view consultation records'],
                'manage_maternal_care'     => ['label' => 'Maternal Care',                 'hint' => 'Log and view prenatal records'],
                'manage_child_immunization'=> ['label' => 'Child Immunization',            'hint' => 'Log and view immunization records'],
                'manage_dental_records'    => ['label' => 'Dental Records',                'hint' => 'Log and view dental visits'],
                'queue_all_departments'    => ['label' => 'Queue: See All Departments',    'hint' => 'Unrestricted queue view, incl. departments that match no module'],
                'view_area_masterlist'     => ['label' => 'Area Masterlist',               'hint' => 'Resident / outsider counts per area'],
                'view_disease_statistics'  => ['label' => 'Disease Statistics',               'hint' => 'Community illness trends and spike alerts (counts only, no patient names)'],
            ],
            'System Administration' => [
                'manage_staff'            => ['label' => 'Staff Management',               'hint' => 'Create and edit staff accounts (never Admin tier unless Health Head)'],
                'view_system_logs'        => ['label' => 'System Logs',                    'hint' => 'Read the activity log'],
                'view_system_archives'    => ['label' => 'System Archives',                'hint' => 'View and restore archived records'],
            ],
        ];
    }
}

if (!function_exists('mh_permission_keys')) {
    function mh_permission_keys()
    {
        $out = [];
        foreach (mh_permission_registry() as $items) { foreach ($items as $k => $_) { $out[] = $k; } }
        return $out;
    }
}

/* ---------------------------------------------------------------------
 * 2. FACTORY DEFAULTS (seed data + fallback when tables are missing)
 * ------------------------------------------------------------------ */
if (!function_exists('mh_builtin_role_definitions')) {
    /** Seed roles. key => [label, is_system]. Built-ins (is_system) cannot be deleted/renamed. */
    function mh_builtin_role_definitions()
    {
        return [
            'health_head' => ['label' => 'Health Head',             'grid' => 'Health Head',  'is_system' => 1],
            'admin'       => ['label' => 'Admin',                   'grid' => 'Admin',        'is_system' => 1],
            'doctor'      => ['label' => 'Doctor',                  'grid' => 'Doctor',       'is_system' => 1],
            'midwife'     => ['label' => 'Midwife',                 'grid' => 'Midwife',      'is_system' => 1],
            'dental'      => ['label' => 'Dental',                  'grid' => 'Dental Staff', 'is_system' => 1],
            'bhw'         => ['label' => 'BHW',                     'grid' => 'BHW',          'is_system' => 1],
            'inventory'   => ['label' => 'Inventory Staff',         'grid' => 'Inventory Staff', 'is_system' => 0],
            'encoder'     => ['label' => 'Encoder / Registrations', 'grid' => 'Encoder',      'is_system' => 0],
        ];
    }
}

if (!function_exists('mh_default_role_permissions')) {
    /** permission_key => comma list of roles that hold it by default (health_head implied). */
    function mh_default_role_permissions()
    {
        // Health Head holds every toggle implicitly (never listed here).
        // Per Dra. Celma's evaluation:
        //   encoder - external-platform encoding only (PhilHealth / Yakap): registration intake + OCR + read-only queue.
        //             NO patient masterlist, NO profile editing, NO admin pages.
        //   bhw     - physical folders (read-only masterlist + area list) and vital signs entry. Nothing else.
        return [
            'view_patient_masterlist'   => 'admin,doctor,bhw,midwife,dental',
            'edit_patient_profile'      => 'admin,doctor,midwife,dental',
            'walkin_registration'       => 'admin,doctor,midwife,dental,encoder',
            'use_ocr_scanner'           => 'admin,doctor,midwife,encoder',
            'view_stock'                => 'admin,inventory',
            'add_medicine'              => 'admin,inventory',
            'dispense_medicine'         => 'admin,inventory',
            'view_dispensing_history'   => 'admin,inventory',
            'view_patient_queue'        => 'admin,doctor,midwife,dental,inventory,encoder,bhw',
            'manage_patient_queue'      => 'admin,doctor,midwife,dental,inventory',
            'manage_consultations'      => 'admin,doctor',
            'manage_maternal_care'      => 'admin,midwife',
            'manage_child_immunization' => 'admin,midwife',
            'manage_dental_records'     => 'admin,dental',
            'view_area_masterlist'      => 'admin,bhw',
            'view_disease_statistics'   => 'admin,doctor',
            'queue_all_departments'     => 'admin,bhw',
            'manage_staff'              => 'admin',
            'view_system_logs'          => 'admin',
            'view_system_archives'      => 'admin',
        ];
    }
}

if (!function_exists('mh_is_builtin_role')) {
    /** True for roles flagged is_system (DB when available, else the seed list). */
    function mh_is_builtin_role($role)
    {
        $all = mh_role_definitions(true);
        if (isset($all[(string) $role])) { return !empty($all[(string) $role]['builtin']); }
        return false;
    }
}

/* ---------------------------------------------------------------------
 * 3. DATABASE ACCESS + INSTALL
 * ------------------------------------------------------------------ */
if (!function_exists('mh_roles_conn')) {
    function mh_roles_conn()
    {
        if (function_exists('mh_auth_db')) { return mh_auth_db(); }
        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
            (function () { global $conn; require_once __DIR__ . '/../config/database.php'; })();
        }
        return $GLOBALS['conn'] ?? null;
    }
}

if (!function_exists('mh_roles_cache')) {
    /** Per-request cache. mh_roles_cache(null,true) flushes it. */
    function &mh_roles_cache()
    {
        static $c = ['ready' => null, 'roles' => null, 'perms' => []];
        return $c;
    }
}

if (!function_exists('mh_roles_flush')) {
    function mh_roles_flush()
    {
        $c = &mh_roles_cache();
        $c = ['ready' => null, 'roles' => null, 'perms' => []];
    }
}
if (!function_exists('mh_custom_roles_flush')) { function mh_custom_roles_flush() { mh_roles_flush(); } }

if (!function_exists('mh_roles_db_ready')) {
    /** Both tables exist AND role_permissions has been seeded. Cached per request. */
    function mh_roles_db_ready()
    {
        $c = &mh_roles_cache();
        if ($c['ready'] !== null) { return $c['ready']; }
        $c['ready'] = false;
        $conn = mh_roles_conn();
        if (!($conn instanceof mysqli)) { return false; }
        try {
            $r = $conn->query("SELECT (SELECT COUNT(*) FROM system_roles) AS a, (SELECT COUNT(*) FROM role_permissions) AS b");
            $row = $r ? $r->fetch_assoc() : null;
            $c['ready'] = $row && (int) $row['a'] > 0 && (int) $row['b'] > 0;
        } catch (Throwable $e) {
            $c['ready'] = false;   // tables not created yet -> factory defaults apply
        }
        return $c['ready'];
    }
}

if (!function_exists('mh_roles_install')) {
    /**
     * Creates the tables and seeds them if empty (same result as
     * database/roles_permissions_migration.sql). Existing Module Access role lists
     * (system_settings.module_roles_*) and old custom roles (system_options) are carried over.
     */
    function mh_roles_install(mysqli $conn)
    {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS system_roles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT, role_key VARCHAR(50) NOT NULL, role_name VARCHAR(60) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0, status TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_role_key (role_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("CREATE TABLE IF NOT EXISTS role_permissions (
                role_key VARCHAR(50) NOT NULL, permission_key VARCHAR(60) NOT NULL, allowed TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (role_key, permission_key), KEY idx_perm (permission_key, allowed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $haveRoles = (int) $conn->query("SELECT COUNT(*) AS n FROM system_roles")->fetch_assoc()['n'];
            if ($haveRoles === 0) {
                $sort = 10;
                $ins = $conn->prepare("INSERT IGNORE INTO system_roles (role_key, role_name, is_system, status, sort_order) VALUES (?,?,?,1,?)");
                foreach (mh_builtin_role_definitions() as $k => $d) {
                    $label = $d['label']; $sys = (int) $d['is_system'];
                    $ins->bind_param('ssii', $k, $label, $sys, $sort); $ins->execute(); $sort += 10;
                }
                $ins->close();
                // custom roles from the previous system_options storage
                try {
                    $res = $conn->query("SELECT option_value, option_label, is_active FROM system_options WHERE list_key = 'staff_roles_custom'");
                    $up = $conn->prepare("INSERT INTO system_roles (role_key, role_name, is_system, status, sort_order) VALUES (?,?,0,?,100)
                                          ON DUPLICATE KEY UPDATE role_name = IF(is_system = 0, VALUES(role_name), role_name), status = IF(is_system = 0, VALUES(status), status)");
                    while ($res && ($o = $res->fetch_assoc())) {
                        $k = (string) $o['option_value']; $l = (string) $o['option_label']; $a = (int) $o['is_active'];
                        $up->bind_param('ssi', $k, $l, $a); $up->execute();
                    }
                    $up->close();
                } catch (Throwable $e) { /* system_options absent on a brand-new install */ }
            }

            $havePerms = (int) $conn->query("SELECT COUNT(*) AS n FROM role_permissions")->fetch_assoc()['n'];
            if ($havePerms === 0) {
                $moduleOf = ['view_patient_masterlist' => 'patient_masterlist', 'edit_patient_profile' => 'patient_masterlist',
                    'walkin_registration' => 'walkin', 'view_stock' => 'inventory', 'add_medicine' => 'inventory',
                    'dispense_medicine' => 'inventory', 'view_dispensing_history' => 'inventory', 'manage_consultations' => 'checkups',
                    'manage_maternal_care' => 'maternal_care', 'manage_child_immunization' => 'immunization', 'manage_dental_records' => 'dental'];
                $saved = [];
                try {
                    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'module_roles\\_%'");
                    while ($res && ($s = $res->fetch_assoc())) { $saved[$s['setting_key']] = $s['setting_value']; }
                } catch (Throwable $e) {}
                $roleKeys = [];
                $res = $conn->query("SELECT role_key FROM system_roles");
                while ($res && ($r = $res->fetch_assoc())) { $roleKeys[] = $r['role_key']; }
                $ins = $conn->prepare("INSERT IGNORE INTO role_permissions (role_key, permission_key, allowed) VALUES (?,?,?)");
                foreach (mh_default_role_permissions() as $perm => $csv) {
                    $mk = $moduleOf[$perm] ?? null;
                    $list = ($mk && isset($saved['module_roles_' . $mk])) ? $saved['module_roles_' . $mk] : $csv;
                    $granted = array_map('trim', explode(',', $list));
                    foreach ($roleKeys as $rk) {
                        $allowed = ($rk === 'health_head' || in_array($rk, $granted, true)) ? 1 : 0;
                        $ins->bind_param('ssi', $rk, $perm, $allowed); $ins->execute();
                    }
                }
                $ins->close();
            }
        } catch (Throwable $e) {
            error_log('mh_roles_install: ' . $e->getMessage());
        }
        mh_roles_flush();
    }
}

if (!function_exists('mh_roles_upgrade_health_head')) {
    /**
     * One-time, idempotent: retire the old Super Admin role. Accounts become Health Head, the old
     * role rows are removed, and Health Head is registered as a built-in role. Same effect as
     * database/health_head_rbac_migration.sql (which also tightens Encoder / BHW permissions).
     * Called automatically the first time a legacy Super Admin session is seen, so nobody is locked out.
     */
    function mh_roles_upgrade_health_head(mysqli $conn)
    {
        $legacy = 'super' . '_admin';
        try {
            $conn->query("INSERT IGNORE INTO system_roles (role_key, role_name, is_system, status, sort_order) VALUES ('health_head','Health Head',1,1,10)");
            $st = $conn->prepare("UPDATE staff SET role = 'health_head' WHERE role = ?");
            $st->bind_param('s', $legacy); $st->execute(); $st->close();
            $st = $conn->prepare("DELETE FROM role_permissions WHERE role_key = ?");
            $st->bind_param('s', $legacy); $st->execute(); $st->close();
            $st = $conn->prepare("DELETE FROM system_roles WHERE role_key = ?");
            $st->bind_param('s', $legacy); $st->execute(); $st->close();
        } catch (Throwable $e) {
            error_log('mh_roles_upgrade_health_head: ' . $e->getMessage());
        }
        mh_roles_flush();
    }
}

/* ---------------------------------------------------------------------
 * 4. ROLE LISTS
 * ------------------------------------------------------------------ */
if (!function_exists('mh_role_definitions')) {
    /**
     * key => ['label','grid','builtin','active','id']  (built-ins first, then A-Z).
     * Inactive roles are omitted unless $includeInactive - they stay valid for existing accounts.
     */
    function mh_role_definitions($includeInactive = false)
    {
        $c = &mh_roles_cache();
        if ($c['roles'] === null) {
            $rows = [];
            if (mh_roles_db_ready()) {
                try {
                    $res = mh_roles_conn()->query("SELECT id, role_key, role_name, is_system, status FROM system_roles ORDER BY is_system DESC, sort_order ASC, role_name ASC");
                    while ($res && ($r = $res->fetch_assoc())) {
                        $label = trim((string) $r['role_name']);
                        $label = $label !== '' ? $label : ucwords(str_replace('_', ' ', $r['role_key']));
                        $rows[$r['role_key']] = ['id' => (int) $r['id'], 'label' => $label, 'grid' => $label,
                            'builtin' => (int) $r['is_system'] === 1, 'active' => (int) $r['status'] === 1];
                    }
                } catch (Throwable $e) { error_log('mh_role_definitions: ' . $e->getMessage()); }
            }
            if (!$rows) {   // tables missing -> seed list keeps login and pages working
                foreach (mh_builtin_role_definitions() as $k => $d) {
                    $rows[$k] = ['id' => 0, 'label' => $d['label'], 'grid' => $d['grid'], 'builtin' => (bool) $d['is_system'], 'active' => true];
                }
            }
            $c['roles'] = $rows;
        }
        if ($includeInactive) { return $c['roles']; }
        return array_filter($c['roles'], function ($r) { return $r['active']; });
    }
}

if (!function_exists('mh_custom_roles')) {
    /** Non-system roles, shaped like the old API: key => [id,key,label,active]. */
    function mh_custom_roles($includeInactive = false)
    {
        $out = [];
        foreach (mh_role_definitions($includeInactive) as $k => $d) {
            if (!$d['builtin']) { $out[$k] = ['id' => $d['id'], 'key' => $k, 'label' => $d['label'], 'active' => $d['active']]; }
        }
        return $out;
    }
}

if (!function_exists('mh_get_roles')) {
    /** role key => label from system_roles. Active roles only unless $includeInactive. */
    function mh_get_roles($includeInactive = false)
    {
        $out = [];
        foreach (mh_role_definitions($includeInactive) as $key => $def) { $out[$key] = $def['label']; }
        return $out;
    }
}

if (!function_exists('mh_get_module_grid_roles')) {
    function mh_get_module_grid_roles($includeInactive = false)
    {
        $out = [];
        foreach (mh_role_definitions($includeInactive) as $key => $def) { $out[$key] = $def['grid']; }
        return $out;
    }
}

if (!function_exists('mh_role_label')) {
    function mh_role_label($role)
    {
        $roles = mh_get_roles(true);
        $role  = (string) $role;
        return $roles[$role] ?? ucwords(str_replace('_', ' ', $role));
    }
}

if (!function_exists('mh_is_valid_role')) {
    function mh_is_valid_role($role) { return array_key_exists((string) $role, mh_role_definitions(true)); }
}

if (!function_exists('mh_has_role')) {
    /** Is $role (default: signed-in user's role) one of $roles?  (identity check, NOT a permission check) */
    function mh_has_role($roles, $role = null)
    {
        if ($role === null) { $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? ''; }
        return in_array((string) $role, (array) $roles, true);
    }
}

/* ---------------------------------------------------------------------
 * 5. PERMISSION CHECKS
 * ------------------------------------------------------------------ */
if (!function_exists('mh_role_permissions')) {
    /** @return array<string,bool> permission_key => true for every toggle the role holds. */
    function mh_role_permissions($role)
    {
        $role = (string) $role;
        $c = &mh_roles_cache();
        if (isset($c['perms'][$role])) { return $c['perms'][$role]; }
        $set = [];
        if ($role === 'health_head') {
            foreach (mh_permission_keys() as $k) { $set[$k] = true; }
        } elseif (mh_roles_db_ready()) {
            try {
                $st = mh_roles_conn()->prepare("SELECT permission_key FROM role_permissions WHERE role_key = ? AND allowed = 1");
                $st->bind_param('s', $role); $st->execute();
                $res = $st->get_result();
                while ($res && ($r = $res->fetch_assoc())) { $set[$r['permission_key']] = true; }
                $st->close();
            } catch (Throwable $e) { error_log('mh_role_permissions: ' . $e->getMessage()); }
        } else {
            foreach (mh_default_role_permissions() as $perm => $csv) {
                if (in_array($role, explode(',', $csv), true)) { $set[$perm] = true; }
            }
        }
        return $c['perms'][$role] = $set;
    }
}

if (!function_exists('mh_roles_with_permission')) {
    /** @return string[] role keys holding ANY of the listed permissions. */
    function mh_roles_with_permission(array $permissionKeys)
    {
        $out = [];
        foreach (array_keys(mh_role_definitions(true)) as $rk) {
            $held = mh_role_permissions($rk);
            foreach ($permissionKeys as $p) { if (!empty($held[$p])) { $out[] = $rk; break; } }
        }
        return $out;
    }
}

if (!function_exists('mh_role_holds_permission')) {
    /**
     * STRICT role-level lookup of ONE feature toggle.
     * True only when the role's matrix has exactly this key explicitly ON.
     * Missing row, 0, NULL, unknown role or unknown key => false (fail closed).
     * Never consults sibling toggles.
     */
    function mh_role_holds_permission($role, $permission_key)
    {
        $role = (string) $role;
        $permission_key = (string) $permission_key;
        if ($role === '' || $permission_key === '') { return false; }
        if ($role === 'health_head') { return true; }
        $held = mh_role_permissions($role);          // [key => true] for allowed=1 rows only
        return isset($held[$permission_key]) && $held[$permission_key] === true;
    }
}

if (!function_exists('mh_has_permission')) {
    /**
     * Does a role / the signed-in user hold ONE specific feature toggle?
     *   mh_has_permission('dispense_medicine')                 -> signed-in user
     *   mh_has_permission('use_ocr_scanner', $row['role'])     -> any role (role matrix only)
     *
     * Evaluation order for the signed-in user (3-state override model):
     *   0. health_head                         -> allow
     *   1. Per-account override (staff.can_*) that OWNS this exact key:
     *        0 (explicit deny)  -> DENY   (deny always wins, even if another owner says 1)
     *        1 (explicit grant) -> ALLOW
     *        NULL (inherit)     -> go to step 2
     *   2. The role's default for THIS key only (mh_role_holds_permission):
     *        explicit ON -> ALLOW;  missing / OFF / NULL -> DENY
     *
     * IMPORTANT (the bug this replaces): an inherited (NULL) override used to be resolved through
     * mh_role_default_grants(), which answered "does the role hold ANY key of the capability bundle?".
     * A role that only had `view_stock` therefore also passed `add_medicine`. Inheritance now looks
     * at the single requested key, so sibling toggles can never leak into each other.
     */
    function mh_has_permission($permission_key, $user_role = null)
    {
        $permission_key = (string) $permission_key;
        if ($permission_key === '') { return false; }

        $own = ($user_role === null);
        $caps = null;
        if ($own && function_exists('mh_refresh_staff_authority')) {
            $caps = mh_refresh_staff_authority();    // also re-syncs $_SESSION['role'] from the database
        }
        $role = $own ? (string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') : (string) $user_role;

        if ($role === 'health_head') { return true; }
        if ($role === '') { return false; }

        // Step 1: per-account override (signed-in user only; other roles are matrix-only).
        if ($own && is_array($caps) && function_exists('mh_capability_owners_of')) {
            $allow = false;
            foreach (mh_capability_owners_of($permission_key) as $cap) {
                if (!array_key_exists($cap, $caps) || $caps[$cap] === null) { continue; }   // NULL = inherit
                if ((int) $caps[$cap] === 0) { return false; }                                // explicit deny wins
                if ((int) $caps[$cap] === 1) { $allow = true; }                               // explicit grant
            }
            if ($allow) { return true; }
        }

        // Step 2: the role's own value for exactly this key. Anything but explicit ON is OFF.
        return mh_role_holds_permission($role, $permission_key);
    }
}

if (!function_exists('mh_has_any_permission')) {
    function mh_has_any_permission(array $keys, $user_role = null)
    {
        foreach ($keys as $k) { if (mh_has_permission($k, $user_role)) { return true; } }
        return false;
    }
}

/* ---------------------------------------------------------------------
 * 5b. DELEGATION BOUNDS (who may hand out what)
 * A staff member with `manage_staff` must never be able to give out more than they hold.
 * "Rank" is defined by the permission set: role A outranks-or-equals role B when the
 * signed-in user effectively holds EVERY toggle that B holds (mh_has_permission(), so the
 * actor's own per-account overrides count). Admin/Health Head tiers are Health Head-only.
 * ------------------------------------------------------------------ */
if (!function_exists('mh_permission_label')) {
    function mh_permission_label($key)
    {
        foreach (mh_permission_registry() as $items) {
            if (isset($items[$key])) { return $items[$key]['label']; }
        }
        return (string) $key;
    }
}

if (!function_exists('mh_actor_missing_for_role')) {
    /** Toggles of $targetRole the signed-in user does NOT hold (labels). Empty = actor may delegate it. */
    function mh_actor_missing_for_role($targetRole)
    {
        $missing = [];
        foreach (array_keys(mh_role_permissions((string) $targetRole)) as $k) {
            if (!mh_has_permission($k)) { $missing[] = mh_permission_label($k); }
        }
        return $missing;
    }
}

if (!function_exists('mh_delegation_check_role')) {
    /**
     * May the signed-in user ASSIGN $targetRole to an account?
     * @return array{0:bool,1:string}
     */
    function mh_delegation_check_role($targetRole)
    {
        $targetRole = (string) $targetRole;
        if (mh_is_health_head()) { return [true, '']; }
        if (!mh_is_valid_role($targetRole)) { return [false, 'Unknown role.']; }
        if (function_exists('mh_is_admin_tier') && mh_is_admin_tier($targetRole)) {
            return [false, 'Only a Health Head can assign the Admin or Health Head role.'];
        }
        $missing = mh_actor_missing_for_role($targetRole);
        if ($missing) {
            return [false, 'That role grants functions you do not hold yourself (' . implode(', ', array_slice($missing, 0, 4))
                . (count($missing) > 4 ? ', ...' : '') . '), so you cannot assign it.'];
        }
        return [true, ''];
    }
}

if (!function_exists('mh_actor_outranks_target')) {
    /** May the signed-in user change privileges of / reset the password of an account holding $targetRole? */
    function mh_actor_outranks_target($targetRole)
    {
        if (mh_is_health_head()) { return true; }
        if (function_exists('mh_is_admin_tier') && mh_is_admin_tier((string) $targetRole)) { return false; }
        return mh_actor_missing_for_role($targetRole) === [];
    }
}

if (!function_exists('mh_delegation_check_caps')) {
    /**
     * May the signed-in user switch these per-account overrides ON?
     * Only NEW grants are checked (value 1 where the target does not already have 1); deny (0)
     * and inherit (NULL) never add access, so they are always allowed.
     * @param array<string,int|null> $newCaps     column => null|0|1 requested
     * @param array<string,int|null> $currentCaps column => null|0|1 stored today (empty for a new account)
     * @return array{0:bool,1:string}
     */
    function mh_delegation_check_caps(array $newCaps, array $currentCaps = [])
    {
        if (mh_is_health_head()) { return [true, '']; }
        $registry = function_exists('mh_capability_registry') ? mh_capability_registry() : [];
        foreach ($newCaps as $cap => $val) {
            if ($val === null || (int) $val !== 1) { continue; }
            if (isset($currentCaps[$cap]) && (int) $currentCaps[$cap] === 1) { continue; }   // not a new grant
            if (!isset($registry[$cap])) { return [false, 'Unknown function.']; }
            $ok = mh_has_capability($cap);
            foreach ($registry[$cap]['grants'] as $perm) { $ok = $ok && mh_has_permission($perm); }
            if (!$ok) {
                return [false, 'You cannot grant "' . $registry[$cap]['label'] . '" because your own account does not hold it.'];
            }
        }
        return [true, ''];
    }
}

/* ---------------------------------------------------------------------
 * 6. ROLE + MATRIX MANAGEMENT (System Settings -> Staff Roles & Permissions)
 * ------------------------------------------------------------------ */
if (!function_exists('mh_role_key_from_label')) {
    /** "Triage Nurse" -> "triage_nurse" (a-z, 0-9, underscore; max 40). */
    function mh_role_key_from_label($label)
    {
        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $label)));
        return substr(trim((string) $key, '_'), 0, 40);
    }
}

if (!function_exists('mh_add_custom_role')) {
    /** @return array{0:bool,1:string} */
    function mh_add_custom_role(mysqli $conn, $label)
    {
        $label = trim((string) $label);
        if ($label === '') { return [false, 'Enter a role name.']; }
        if ((function_exists('mb_strlen') ? mb_strlen($label) : strlen($label)) > 60) { return [false, 'That role name is too long (maximum 60 characters).']; }
        $key = mh_role_key_from_label($label);
        if ($key === '') { return [false, 'The role name needs at least one letter or number.']; }
        if (!mh_roles_db_ready()) { return [false, 'Roles tables are not installed yet. Reload this page, or run database/roles_permissions_migration.sql.']; }

        $st = $conn->prepare("INSERT INTO system_roles (role_key, role_name, is_system, status, sort_order) VALUES (?, ?, 0, 1, 100)");
        $st->bind_param('ss', $key, $label);
        try { $ok = $st->execute(); } catch (Throwable $e) { $ok = false; }
        $dup = (!$ok && $conn->errno === 1062);
        $st->close();
        mh_roles_flush();
        if ($dup) { return [false, 'A role with that name already exists.']; }
        if (!$ok) { return [false, 'Could not add that role.']; }

        // every toggle starts OFF so a new role has no access until ticked
        $ins = $conn->prepare("INSERT IGNORE INTO role_permissions (role_key, permission_key, allowed) VALUES (?, ?, 0)");
        foreach (mh_permission_keys() as $p) { $ins->bind_param('ss', $key, $p); $ins->execute(); }
        $ins->close();
        mh_roles_flush();
        return [true, 'Role "' . $label . '" added with no access. Tick the features it should have, then save.'];
    }
}

if (!function_exists('mh_update_custom_role')) {
    /** Rename / show-hide a non-system role (key is never changed). */
    function mh_update_custom_role(mysqli $conn, $id, $label, $isActive)
    {
        $label = trim((string) $label);
        if ($label === '') { return [false, 'The role name cannot be blank.']; }
        $id = (int) $id; $isActive = $isActive ? 1 : 0;
        $st = $conn->prepare("UPDATE system_roles SET role_name = ?, status = ? WHERE id = ? AND is_system = 0");
        $st->bind_param('sii', $label, $isActive, $id);
        $ok = $st->execute(); $st->close();
        mh_roles_flush();
        return $ok ? [true, 'Role updated.'] : [false, 'Could not update that role.'];
    }
}

if (!function_exists('mh_delete_custom_role')) {
    /** Deletes a non-system role - refused while any account holds it. */
    function mh_delete_custom_role(mysqli $conn, $id)
    {
        $id = (int) $id;
        $st = $conn->prepare("SELECT role_key FROM system_roles WHERE id = ? AND is_system = 0 LIMIT 1");
        $st->bind_param('i', $id); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        if (!$row) { return [false, 'That role no longer exists or is a built-in role.']; }
        $key = $row['role_key'];
        $c = $conn->prepare("SELECT COUNT(*) AS n FROM staff WHERE role = ?");
        $c->bind_param('s', $key); $c->execute();
        $n = (int) ($c->get_result()->fetch_assoc()['n'] ?? 0); $c->close();
        if ($n > 0) { return [false, $n . ' staff account(s) still use this role. Reassign them first (or untick Shown to stop offering it).']; }
        $conn->begin_transaction();
        try {
            $d1 = $conn->prepare("DELETE FROM role_permissions WHERE role_key = ?"); $d1->bind_param('s', $key); $d1->execute(); $d1->close();
            $d2 = $conn->prepare("DELETE FROM system_roles WHERE id = ? AND is_system = 0"); $d2->bind_param('i', $id); $d2->execute(); $d2->close();
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); return [false, 'Could not delete that role.']; }
        mh_roles_flush();
        return [true, 'Role deleted.'];
    }
}

if (!function_exists('mh_save_role_permissions')) {
    /**
     * Persists the whole toggle matrix. $posted = [role_key => [permission_key, ...]] (ticked boxes).
     * health_head is immutable (always everything). Unknown roles/keys are ignored.
     * Guard rail: Admin can never lose Staff Management here, so the Admin tier can't lock itself out.
     * @return array{0:bool,1:string}
     */
    function mh_save_role_permissions(mysqli $conn, array $posted)
    {
        if (!mh_roles_db_ready()) { return [false, 'Roles tables are not installed yet.']; }
        $roles = mh_role_definitions(true);
        $keys  = mh_permission_keys();
        $conn->begin_transaction();
        try {
            $st = $conn->prepare("INSERT INTO role_permissions (role_key, permission_key, allowed) VALUES (?,?,?)
                                  ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)");
            foreach ($roles as $rk => $_) {
                if ($rk === 'health_head') { continue; }
                $ticked = isset($posted[$rk]) && is_array($posted[$rk]) ? $posted[$rk] : [];
                foreach ($keys as $pk) {
                    $allowed = in_array($pk, $ticked, true) ? 1 : 0;
                    if ($rk === 'admin' && $pk === 'manage_staff') { $allowed = 1; }
                    $st->bind_param('ssi', $rk, $pk, $allowed); $st->execute();
                }
            }
            $st->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('mh_save_role_permissions: ' . $e->getMessage());
            return [false, 'The database rejected the update. Nothing was saved.'];
        }
        mh_roles_flush();
        return [true, 'Permissions saved. They apply on the next click of every signed-in user.'];
    }
}
