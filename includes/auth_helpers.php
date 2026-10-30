<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE: PHP registers top-level functions at
// compile time, so require_once cannot stop a redeclare when one file is reached by two paths that differ
// only in letter case (/MARICK-HEALTH-SYSTEM-v2/ vs /marick-health-system-v2/ on macOS/Windows).
if (!defined('MH_LOADED_INCLUDES_AUTH_HELPERS')) {
define('MH_LOADED_INCLUDES_AUTH_HELPERS', true);
/**
 * =====================================================================
 * SHARED USERNAME + PASSWORD-RESET HELPERS
 * =====================================================================
 * Used by:
 *   - register_process.php   (username uniqueness at signup)
 *   - admin/staff_management.php (auto-generated staff usernames)
 *   - forgot_password.php / reset_password.php use includes/password_reset_helpers.php
 *
 * This project has two separate login tables - `staff` and `patients` -
 * with no shared "users" table, so every helper here that deals with an
 * account takes/returns which table it came from.
 */

/**
 * Builds an absolute URL to another script at the project root (this
 * project has no router - forgot_password.php / reset_password.php
 * both live at the repo root, same level as index.php).
 */
function mh_absolute_url($relativePath)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    return $scheme . '://' . $host . $dir . '/' . ltrim($relativePath, '/');
}

/**
 * True if $username is free in BOTH staff and patients (login has to be
 * unambiguous across the whole system, not just within one table).
 *
 * @param mysqli $conn
 * @param string $username
 * @param string|null $excludeTable  Skip this table's own row when checking...
 * @param int|null    $excludeId     ...specifically this id (for "is my current
 *                                    username still available" on an edit form).
 */
function mh_username_available(mysqli $conn, $username, $excludeTable = null, $excludeId = null)
{
    $username = trim((string) $username);
    if ($username === '') {
        return false;
    }

    foreach (['staff', 'patients'] as $table) {
        if ($table === $excludeTable && $excludeId !== null) {
            $stmt = $conn->prepare("SELECT id FROM `$table` WHERE username = ? AND id != ? LIMIT 1");
            $stmt->bind_param('si', $username, $excludeId);
        } else {
            $stmt = $conn->prepare("SELECT id FROM `$table` WHERE username = ? LIMIT 1");
            $stmt->bind_param('s', $username);
        }
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($found) {
            return false;
        }
    }

    return true;
}

/**
 * Slugifies then de-duplicates a candidate username (used to
 * auto-generate staff usernames in admin/staff_management.php).
 */
function mh_generate_unique_username(mysqli $conn, $baseText)
{
    $base = strtolower(trim((string) $baseText));
    $base = preg_replace('/[^a-z0-9._-]/', '', $base);
    $base = trim($base, '._-');
    if ($base === '') { $base = 'user'; }
    $base = substr($base, 0, 40);

    $candidate = $base;
    $suffix = 1;
    while (!mh_username_available($conn, $candidate)) {
        $candidate = $base . $suffix;
        $suffix++;
    }
    return $candidate;
}

/**
 * Looks up an account by username OR email, checking patients first
 * then staff. Used by both the login pages' "does this look valid"
 * validation path and the forgot-password flow.
 *
 * @return array{table:string, row:array}|null
 */
function mh_find_account_by_identifier(mysqli $conn, $identifier)
{
    $identifier = trim((string) $identifier);
    if ($identifier === '') {
        return null;
    }

    foreach (['patients', 'staff'] as $table) {
        $stmt = $conn->prepare("SELECT * FROM `$table` WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return ['table' => $table, 'row' => $row];
        }
    }

    return null;
}

/* Password-reset token functions moved to includes/password_reset_helpers.php (unified email/SMS table). */
}
