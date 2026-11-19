<?php
require_once __DIR__ . '/auth.php';

require_staff_login();

if (!function_exists('mh_format_patient_name')) {
    function mh_format_patient_name($firstName, $middleName = '', $lastName = '') {
        $firstName  = trim((string) $firstName);
        $middleName = trim((string) $middleName);
        $lastName   = trim((string) $lastName);

        $name = $lastName . ', ' . $firstName;
        if ($middleName !== '') {
            $initial = mb_strtoupper(mb_substr($middleName, 0, 1));
            $name .= ' ' . $initial . '.';
        }
        return $name;
    }
}

if (!function_exists('require_roles')) {
    function require_roles($allowed_roles) {
        mh_refresh_staff_authority();
        if (mh_is_health_head() || in_array(mh_current_role(), $allowed_roles, true)) {
            return;
        }
        mh_deny_access();
    }
}
