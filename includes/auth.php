<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
    header("Pragma: no-cache");
    header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");
}

if (isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = $_SESSION['staff_id'];
}
if (isset($_SESSION['user_role']) && !isset($_SESSION['role'])) {
    $_SESSION['role'] = $_SESSION['user_role'];
}

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/roles.php';

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
    function require_staff_login()
    {
        if (!mh_staff_logged_in()) {
            header("Location: " . mh_admin_login_url());
            exit();
        }
        mh_ss_enforce('staff');
        mh_refresh_staff_authority();
    }
}

if (!function_exists('require_patient_login')) {
    function require_patient_login()
    {
        if (!mh_patient_logged_in()) {
            header("Location: ../index.php");
            exit();
        }
    }
}


if (!function_exists('mh_capability_registry')) {
    function mh_capability_registry()
    {
        return [
            'can_manage_supply_masterlist' => ['group' => 'Medicine Inventory', 'label' => 'Supply / Stock Masterlist',
                'hint' => 'View/edit stock levels, packaging types and item master records', 'module' => 'inventory', 'perms' => ['view_stock', 'add_medicine'], 'grants' => ['view_stock', 'add_medicine']],
            'can_manage_dispensing'        => ['group' => 'Medicine Inventory', 'label' => 'Medicine Dispensing',
                'hint' => 'Record patient dispensing and deduct active stock', 'module' => 'inventory', 'perms' => ['dispense_medicine'], 'grants' => ['dispense_medicine']],
            'can_view_dispensing_history'  => ['group' => 'Medicine Inventory', 'label' => 'Dispensing Log History',
                'hint' => 'Read-only view of past dispensing records', 'module' => 'inventory', 'perms' => ['view_dispensing_history'], 'grants' => ['view_dispensing_history']],

            'can_log_consultations'        => ['group' => 'General Consultations', 'label' => 'Log Consultations',
                'hint' => 'Record new consultation / checkup entries', 'module' => 'checkups', 'perms' => ['manage_consultations'], 'grants' => ['manage_consultations']],
            'can_view_consultation_history'=> ['group' => 'General Consultations', 'label' => 'View Consultation History',
                'hint' => 'Read-only view of past consultation entries', 'module' => 'checkups', 'perms' => ['manage_consultations'], 'grants' => []],

            'can_log_immunization'         => ['group' => 'Child Immunization', 'label' => 'Log Immunization',
                'hint' => 'Create immunization forms, administer vaccines, log child consults', 'module' => 'immunization', 'perms' => ['manage_child_immunization'], 'grants' => ['manage_child_immunization']],
            'can_view_immunization_records'=> ['group' => 'Child Immunization', 'label' => 'View Immunization Records',
                'hint' => 'Read-only view of immunization lists and vaccination history', 'module' => 'immunization', 'perms' => ['manage_child_immunization'], 'grants' => []],

            'can_log_maternal'             => ['group' => 'Maternal Care', 'label' => 'Log Prenatal Visits',
                'hint' => 'Record new prenatal checkups', 'module' => 'maternal_care', 'perms' => ['manage_maternal_care'], 'grants' => ['manage_maternal_care']],
            'can_view_maternal_history'    => ['group' => 'Maternal Care', 'label' => 'View Prenatal History',
                'hint' => 'Read-only view of past prenatal records', 'module' => 'maternal_care', 'perms' => ['manage_maternal_care'], 'grants' => []],

            'can_log_dental'               => ['group' => 'Dental Records', 'label' => 'Log Dental Visits',
                'hint' => 'Record new dental visits', 'module' => 'dental', 'perms' => ['manage_dental_records'], 'grants' => ['manage_dental_records']],
            'can_view_dental_history'      => ['group' => 'Dental Records', 'label' => 'View Dental History',
                'hint' => 'Read-only view of past dental visits', 'module' => 'dental', 'perms' => ['manage_dental_records'], 'grants' => []],

            'can_view_queue'               => ['group' => 'Patient Queue', 'label' => 'View Patient Queue',
                'hint' => 'See incoming and scheduled queue items (no actions)', 'module' => null, 'perms' => ['view_patient_queue', 'manage_patient_queue'], 'grants' => ['view_patient_queue']],
            'can_manage_queue'             => ['group' => 'Patient Queue', 'label' => 'Process Patient Queue',
                'hint' => 'Approve, proceed, reschedule or reject queue items', 'module' => null, 'perms' => ['manage_patient_queue'], 'grants' => ['manage_patient_queue']],

            'can_view_area_masterlist'     => ['group' => 'Area Masterlist', 'label' => 'View Area Masterlist',
                'hint' => 'Resident / outsider counts per area', 'module' => null, 'perms' => ['view_area_masterlist'], 'grants' => ['view_area_masterlist']],
        ];
    }
}

if (!function_exists('mh_capabilities_for_module')) {
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
    function mh_is_admin_tier($role = null)
    {
        return in_array($role ?? mh_current_role(), ['health_head', 'admin'], true);
    }
}

if (!function_exists('mh_auth_db')) {
    function mh_auth_db()
    {
        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
            (function () {
                global $conn;
                require_once __DIR__ . '/../config/database.php';
            })();
        }
        return $GLOBALS['conn'] ?? null;
    }
}

if (!function_exists('mh_caps_tristate_ready')) {
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

        if ($row) {
            try {
                $stA = $conn->prepare("SELECT `status` FROM `staff` WHERE `id` = ? LIMIT 1");
                if ($stA) {
                    $stA->bind_param('i', $id); $stA->execute();
                    $sa = $stA->get_result()->fetch_assoc();
                    $stA->close();
                    if ($sa && $sa['status'] !== null && (int) $sa['status'] === 0) { $row = null; }
                }
            } catch (Throwable $eA) { }
        }

        if (($row['role'] ?? '') === 'super' . '_admin') {
            mh_roles_upgrade_health_head($conn);
            $row['role'] = 'health_head';
        }

        if (($row['role'] ?? '') === 'encoder') {
            mh_roles_merge_encoder_into_inventory($conn);
            $row['role'] = 'inventory';
        }

        if (!$row) {
            session_unset();
            header('Location: ' . mh_admin_login_url());
            exit();
        }

        $_SESSION['role'] = $_SESSION['user_role'] = $row['role'];
        foreach ($caps as $c => $_) {
            if (!array_key_exists($c, $row) || $row[$c] === null) { continue; }
            $v = (int) $row[$c];
            if ($v === 0 && !mh_caps_tristate_ready()) { continue; }
            $caps[$c] = $v;
        }
        return $caps;
    }
}

if (!function_exists('mh_role_default_grants')) {
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
    function require_access(array $allowedRoles, $capability = null)
    {
        mh_refresh_staff_authority();
        if (!mh_can_access($allowedRoles, $capability)) { mh_deny_access(); }
    }
}

if (!function_exists('require_capability')) {
    function require_capability($capability, $json = false)
    {
        mh_refresh_staff_authority();
        if (!mh_has_any_capability((array) $capability)) { mh_deny_access($json); }
    }
}

if (!function_exists('require_permission')) {
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

if (!function_exists('mh_is_super_admin')) {
    function mh_is_super_admin($role = null) { return mh_is_health_head($role); }
}
if (!function_exists('require_super_admin')) {
    function require_super_admin() { require_health_head(); }
}