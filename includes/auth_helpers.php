<?php
if (!defined('MH_LOADED_INCLUDES_AUTH_HELPERS')) {
define('MH_LOADED_INCLUDES_AUTH_HELPERS', true);

function mh_absolute_url($relativePath)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    return $scheme . '://' . $host . $dir . '/' . ltrim($relativePath, '/');
}

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

}
