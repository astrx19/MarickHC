<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db_backup.php';
try {
    $path = mh_db_backup_to_file($conn, 14);
    echo '[' . date('Y-m-d H:i:s') . '] Backup written: ' . $path . ' (' . round(filesize($path) / 1024) . " KB)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
