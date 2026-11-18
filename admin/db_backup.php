<?php
/**
 * Database backup DOWNLOAD endpoint (Paper REQ78). Health Head only. The UI is System Settings > Database Backup.
 * Restore by importing the .sql in phpMyAdmin (see docs/BACKUP_RECOVERY.md).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_backup.php';

if (!mh_is_health_head()) { mh_deny_access(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_backup'])) {
    if (!mh_csrf_check()) { http_response_code(400); exit('Invalid request. Go back and try again.'); }
    @set_time_limit(0);
    $name = 'marick_backup_' . date('Ymd_His') . '.sql';
    logActivity($conn, (int) $_SESSION['user_id'], (string) $_SESSION['role'], 'Database Backup', 'Downloaded a full database backup (' . $name . ').');
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    mh_db_dump($conn, function ($s) { echo $s; flush(); });
    exit;
}

// Anything other than the download POST goes to the Database Backup tab in System Settings.
header('Location: system_settings.php?tab=backup');
exit;
