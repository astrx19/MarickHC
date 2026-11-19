<?php
if (defined('MH_ROLES_LOADED')) { return; }
define('MH_ROLES_LOADED', true);

if (!function_exists('mh_permission_registry')) {
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

if (!function_exists('mh_builtin_role_definitions')) {
    function mh_builtin_role_definitions()
    {
        return [
            'health_head' => ['label' => 'Health Head',             'grid' => 'Health Head',  'is_system' => 1],
            'admin'       => ['label' => 'Admin',                   'grid' => 'Admin',        'is_system' => 1],
            'doctor'      => ['label' => 'Doctor',                  'grid' => 'Doctor',       'is_system' => 1],
            'midwife'     => ['label' => 'Midwife',                 'grid' => 'Midwife',      'is_system' => 1],
            'dental'      => ['label' => 'Dental',                  'grid' => 'Dental Staff', 'is_system' => 1],
            'bhw'         => ['label' => 'BHW',                     'grid' => 'BHW',          'is_system' => 1],
            'inventory'   => ['label' => 'Inventory / Encoder',     'grid' => 'Inventory / Encoder', 'is_system' => 0],
        ];
    }
}

if (!function_exists('mh_default_role_permissions')) {
    function mh_default_role_permissions()
    {
        return [
            'view_patient_masterlist'   => 'admin,doctor,bhw,midwife,dental',
            'edit_patient_profile'      => 'admin,doctor,midwife,dental',
            'walkin_registration'       => 'admin,doctor,midwife,dental,inventory',
            'use_ocr_scanner'           => 'admin,doctor,midwife,inventory',
            'view_stock'                => 'admin,inventory',
            'add_medicine'              => 'admin,inventory',
            'dispense_medicine'         => 'admin,inventory',
            'view_dispensing_history'   => 'admin,inventory',
            'view_patient_queue'        => 'admin,doctor,midwife,dental,inventory,bhw',
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
    function mh_is_builtin_role($role)
    {
        $all = mh_role_definitions(true);
        if (isset($all[(string) $role])) { return !empty($all[(string) $role]['builtin']); }
        return false;
    }
}

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

if (!function_exists('mh_roles_backfill_missing')) {
    function mh_roles_backfill_missing(mysqli $conn)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $have = [];
            $res = $conn->query("SELECT DISTINCT permission_key FROM role_permissions");
            while ($res && ($r = $res->fetch_assoc())) { $have[$r['permission_key']] = true; }
            $defaults = mh_default_role_permissions();
            $missing  = [];
            foreach (mh_permission_keys() as $k) { if (!isset($have[$k])) { $missing[] = $k; } }
            if (!$missing) { return; }
            $roleKeys = [];
            $res = $conn->query("SELECT role_key FROM system_roles");
            while ($res && ($r = $res->fetch_assoc())) { $roleKeys[] = $r['role_key']; }
            $ins = $conn->prepare("INSERT IGNORE INTO role_permissions (role_key, permission_key, allowed) VALUES (?,?,?)");
            if (!$ins) { return; }
            foreach ($missing as $perm) {
                $granted = array_map('trim', explode(',', (string) ($defaults[$perm] ?? '')));
                foreach ($roleKeys as $rk) {
                    $allowed = ($rk === 'health_head' || in_array($rk, $granted, true)) ? 1 : 0;
                    $ins->bind_param('ssi', $rk, $perm, $allowed);
                    $ins->execute();
                }
            }
            $ins->close();
            $c = &mh_roles_cache();
            $c['perms'] = [];
        } catch (Throwable $e) {
            error_log('mh_roles_backfill_missing: ' . $e->getMessage());
        }
    }
}

if (!function_exists('mh_roles_db_ready')) {
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
            if ($c['ready']) { mh_roles_backfill_missing($conn); }
        } catch (Throwable $e) {
            $c['ready'] = false;
        }
        return $c['ready'];
    }
}

if (!function_exists('mh_roles_install')) {
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
                try {
                    $res = $conn->query("SELECT option_value, option_label, is_active FROM system_options WHERE list_key = 'staff_roles_custom'");
                    $up = $conn->prepare("INSERT INTO system_roles (role_key, role_name, is_system, status, sort_order) VALUES (?,?,0,?,100)
                                          ON DUPLICATE KEY UPDATE role_name = IF(is_system = 0, VALUES(role_name), role_name), status = IF(is_system = 0, VALUES(status), status)");
                    while ($res && ($o = $res->fetch_assoc())) {
                        $k = (string) $o['option_value']; $l = (string) $o['option_label']; $a = (int) $o['is_active'];
                        $up->bind_param('ssi', $k, $l, $a); $up->execute();
                    }
                    $up->close();
                } catch (Throwable $e) { }
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

if (!function_exists('mh_roles_merge_encoder_into_inventory')) {
    function mh_roles_merge_encoder_into_inventory(mysqli $conn)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $need = false;
            foreach (["SELECT 1 FROM system_roles WHERE role_key = 'encoder' LIMIT 1",
                      "SELECT 1 FROM staff WHERE role = 'encoder' LIMIT 1",
                      "SELECT 1 FROM role_permissions WHERE role_key = 'encoder' LIMIT 1"] as $q) {
                $r = @$conn->query($q);
                if ($r && $r->num_rows > 0) { $need = true; break; }
            }
            if (!$need) { return; }

            $conn->query("UPDATE staff SET role = 'inventory' WHERE role = 'encoder'");

            $conn->query("INSERT IGNORE INTO system_roles (role_key, role_name, is_system, status, sort_order) VALUES ('inventory','Inventory / Encoder',0,1,70)");
            $conn->query("UPDATE system_roles SET role_name = 'Inventory / Encoder' WHERE role_key = 'inventory' AND role_name IN ('Inventory Staff','Inventory')");

            foreach (['walkin_registration', 'use_ocr_scanner'] as $perm) {
                $st = $conn->prepare("INSERT INTO role_permissions (role_key, permission_key, allowed) VALUES ('inventory', ?, 1) ON DUPLICATE KEY UPDATE allowed = 1");
                if ($st) { $st->bind_param('s', $perm); $st->execute(); $st->close(); }
            }

            $conn->query("DELETE FROM role_permissions WHERE role_key = 'encoder'");
            $conn->query("DELETE FROM system_roles WHERE role_key = 'encoder'");

            $res = @$conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'module\\_roles\\_%'");
            while ($res && ($row = $res->fetch_assoc())) {
                $list = array_values(array_filter(array_map('trim', explode(',', (string) $row['setting_value'])), 'strlen'));
                if (!in_array('encoder', $list, true)) { continue; }
                $list = array_values(array_diff($list, ['encoder']));
                if (!in_array('inventory', $list, true)) { $list[] = 'inventory'; }
                $val = implode(',', $list); $key = $row['setting_key'];
                $up = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                if ($up) { $up->bind_param('ss', $val, $key); $up->execute(); $up->close(); }
            }
        } catch (Throwable $e) {
            error_log('mh_roles_merge_encoder_into_inventory: ' . $e->getMessage());
        }
        mh_roles_flush();
    }
}

if (!function_exists('mh_role_definitions')) {
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
            if (!$rows) {
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
    function mh_has_role($roles, $role = null)
    {
        if ($role === null) { $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? ''; }
        return in_array((string) $role, (array) $roles, true);
    }
}

if (!function_exists('mh_role_permissions')) {
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
    function mh_role_holds_permission($role, $permission_key)
    {
        $role = (string) $role;
        $permission_key = (string) $permission_key;
        if ($role === '' || $permission_key === '') { return false; }
        if ($role === 'health_head') { return true; }
        $held = mh_role_permissions($role);
        return isset($held[$permission_key]) && $held[$permission_key] === true;
    }
}

if (!function_exists('mh_has_permission')) {
    function mh_has_permission($permission_key, $user_role = null)
    {
        $permission_key = (string) $permission_key;
        if ($permission_key === '') { return false; }

        $own = ($user_role === null);
        $caps = null;
        if ($own && function_exists('mh_refresh_staff_authority')) {
            $caps = mh_refresh_staff_authority();
        }
        $role = $own ? (string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') : (string) $user_role;

        if ($role === 'health_head') { return true; }
        if ($role === '') { return false; }

        if ($own && is_array($caps) && function_exists('mh_capability_owners_of')) {
            $allow = false;
            foreach (mh_capability_owners_of($permission_key) as $cap) {
                if (!array_key_exists($cap, $caps) || $caps[$cap] === null) { continue; }
                if ((int) $caps[$cap] === 0) { return false; }
                if ((int) $caps[$cap] === 1) { $allow = true; }
            }
            if ($allow) { return true; }
        }

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
    function mh_actor_outranks_target($targetRole)
    {
        if (mh_is_health_head()) { return true; }
        if (function_exists('mh_is_admin_tier') && mh_is_admin_tier((string) $targetRole)) { return false; }
        return mh_actor_missing_for_role($targetRole) === [];
    }
}

if (!function_exists('mh_delegation_check_caps')) {
    function mh_delegation_check_caps(array $newCaps, array $currentCaps = [])
    {
        if (mh_is_health_head()) { return [true, '']; }
        $registry = function_exists('mh_capability_registry') ? mh_capability_registry() : [];
        foreach ($newCaps as $cap => $val) {
            if ($val === null || (int) $val !== 1) { continue; }
            if (isset($currentCaps[$cap]) && (int) $currentCaps[$cap] === 1) { continue; }
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

if (!function_exists('mh_role_key_from_label')) {
    function mh_role_key_from_label($label)
    {
        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $label)));
        return substr(trim((string) $key, '_'), 0, 40);
    }
}

if (!function_exists('mh_add_custom_role')) {
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

        $ins = $conn->prepare("INSERT IGNORE INTO role_permissions (role_key, permission_key, allowed) VALUES (?, ?, 0)");
        foreach (mh_permission_keys() as $p) { $ins->bind_param('ss', $key, $p); $ins->execute(); }
        $ins->close();
        mh_roles_flush();
        return [true, 'Role "' . $label . '" added with no access. Tick the features it should have, then save.'];
    }
}

if (!function_exists('mh_update_custom_role')) {
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