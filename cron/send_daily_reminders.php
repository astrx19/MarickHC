<?php

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

error_reporting(E_ALL);
ini_set('display_errors', $isCli ? 1 : 0);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sms_helper.php';
require_once __DIR__ . '/../includes/sms/sms_queue_runner.php';

mh_sms_install($conn);

if (!$isCli) {
    $storedKey = (string) CRON_RUN_KEY;
    $supplied  = (string) ($_GET['key'] ?? '');
    $authorized = ($storedKey !== '' && strlen($storedKey) >= 24 && $supplied !== ''
                   && hash_equals($storedKey, $supplied));

    if (!$authorized) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

if (function_exists('mh_module_is_enabled') && !mh_module_is_enabled('sms_reminders')) {
    echo "SMS Reminders module is turned off in System Settings > Module Access. Nothing sent.\n";
    exit(0);
}

$startedAt = date('Y-m-d H:i:s');
$summary   = mh_run_sms_queue($conn, 'cron', 200);
$finishedAt = date('Y-m-d H:i:s');

if ($summary['skipped_no_key']) {
    echo "[$startedAt] SMS reminder run SKIPPED - Semaphore is not configured.\n";
    echo "  " . ($summary['details'][0]['note'] ?? 'Unknown configuration problem.') . "\n";
    echo "  Fix this in System Settings > SMS Reminders, then re-run.\n";
    exit($isCli ? 1 : 0);
}

echo "[$startedAt] SMS reminder run started.\n";
echo "  Checked: {$summary['checked']}   Sent: {$summary['sent']}   Failed: {$summary['failed']}\n";

if (!empty($summary['paused_holiday'])) {
    echo "  Today is a declared clinic holiday - nothing sent; pending reminders stay queued for the next open day.\n";
} elseif ($summary['checked'] === 0) {
    echo "  Nothing was due (no pending rows with scheduled_date <= today).\n";
} else {
    foreach ($summary['details'] as $d) {
        $icon = $d['status'] === 'sent' ? 'OK  ' : 'FAIL';
        echo "  [$icon] scheduled_sms #{$d['id']} -> patient #{$d['patient_id']} ({$d['phone']}): {$d['note']}\n";
    }
}

echo "[$finishedAt] Done.\n";

if ($isCli && $summary['checked'] > 0 && $summary['sent'] === 0) {
    exit(1);
}