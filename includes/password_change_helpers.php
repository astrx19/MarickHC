<?php
/**
 * Shared rules for "change my password" on the Profile pages (patient + staff).  Step 4.
 *
 *   $err = mh_pw_change_check($storedHash, $current, $new, $confirm, 'patient:12');
 *   if ($err !== null)  -> show it, change nothing
 *   else                -> save password_hash($new, PASSWORD_DEFAULT), then mh_pw_change_clear($bucket)
 *
 * Wrong "current password" guesses are counted per account for 15 minutes (5 allowed), so a
 * hijacked, still-open session cannot be used to guess the old password.
 * Compatible with PHP 7.2+.
 */

if (!defined('MH_PWCHG_MAX_FAILS'))   { define('MH_PWCHG_MAX_FAILS', 5); }
if (!defined('MH_PWCHG_WINDOW_SECS')) { define('MH_PWCHG_WINDOW_SECS', 900); }

if (!function_exists('mh_pw_change_locked')) {
    function mh_pw_change_locked($bucket)
    {
        $now  = time();
        $list = isset($_SESSION['mh_pwchg'][$bucket]) ? $_SESSION['mh_pwchg'][$bucket] : [];
        $list = array_values(array_filter($list, function ($t) use ($now) { return ($now - (int) $t) < MH_PWCHG_WINDOW_SECS; }));
        $_SESSION['mh_pwchg'][$bucket] = $list;
        return count($list) >= MH_PWCHG_MAX_FAILS;
    }
}
if (!function_exists('mh_pw_change_fail')) {
    function mh_pw_change_fail($bucket)  { $_SESSION['mh_pwchg'][$bucket][] = time(); }
}
if (!function_exists('mh_pw_change_clear')) {
    function mh_pw_change_clear($bucket) { unset($_SESSION['mh_pwchg'][$bucket]); }
}

if (!function_exists('mh_pw_change_check')) {
    /** @return string|null  a user-facing error, or null when everything is acceptable */
    function mh_pw_change_check($storedHash, $current, $new, $confirm, $bucket)
    {
        if (mh_pw_change_locked($bucket)) {
            return 'Too many incorrect current-password attempts. Please wait 15 minutes and try again.';
        }
        if ($current === '') {
            return 'Enter your current password to set a new one.';
        }
        if (!is_string($storedHash) || $storedHash === '' || !password_verify($current, $storedHash)) {
            mh_pw_change_fail($bucket);
            return 'Your current password is incorrect.';
        }
        if (strlen($new) < 8) {
            return 'Your new password must be at least 8 characters long.';
        }
        if (strlen($new) > 72) {
            return 'Your new password must be 72 characters or fewer.';
        }
        if (!hash_equals($new, $confirm)) {
            return 'The new password and its confirmation do not match.';
        }
        if (hash_equals($current, $new)) {
            return 'Your new password must be different from your current one.';
        }
        return null;
    }
}
