<?php
/**
 * Staff-only page guard (role tiers + delegated capabilities: see includes/auth.php). Included at the top of every protected
 * admin/*.php file. Session start, no-cache headers, the actual
 * logged-in check, and the redirect target for anonymous visitors all
 * live in includes/auth.php now - see that file for the session-key
 * details. This file keeps its original two helper functions
 * (mh_format_patient_name, require_roles) so none of the 25+ files
 * that already `require_once` this one need to change.
 */
require_once __DIR__ . '/auth.php';

require_staff_login();

if (!function_exists('mh_format_patient_name')) {
    /**
     * Formats a patient's name for display as "Last Name, First Name M."
     * (middle initial + period appended only when a middle name/initial
     * is on file). Used everywhere a patient name is listed: the walk-in
     * dropdown, the appointments queue, and the patient masterlists.
     */
    function mh_format_patient_name($firstName, $middleName = '', $lastName = '') {
        $firstName  = trim((string) $firstName);
        $middleName = trim((string) $middleName);
        $lastName   = trim((string) $lastName);

        $name = $lastName . ', ' . $firstName;
        if ($middleName !== '') {
            // Only the initial is shown, whether a full middle name or
            // just an initial was entered ("Reyes" -> "R.", "R" -> "R.").
            $initial = mb_strtoupper(mb_substr($middleName, 0, 1));
            $name .= ' ' . $initial . '.';
        }
        return $name;
    }
}

if (!function_exists('require_roles')) {
    /**
     * Role-only guard (kept for the many pages that call it). health_head
     * always passes. For pages that a delegated capability should also
     * open, call require_access($roles, 'can_manage_x') instead.
     */
    function require_roles($allowed_roles) {
        mh_refresh_staff_authority();
        if (mh_is_health_head() || in_array(mh_current_role(), $allowed_roles, true)) {
            return;
        }
        mh_deny_access();
    }
}
