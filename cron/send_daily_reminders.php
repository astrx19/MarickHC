<?php
/**
 * =====================================================================
 * cron/send_daily_reminders.php
 * =====================================================================
 * Sends every SMS reminder in `scheduled_sms` that is due today or
 * earlier and still `pending`.
 *
 * RUN IT ON A REAL SERVER (recommended)
 * --------------------------------------
 * Set up an OS-level scheduled task to run this once a day, early
 * morning, e.g. via cron (Linux):
 *
 *     # every day at 6:00 AM
 *     0 6 * * * /usr/bin/php /full/path/to/marick-health-system-v2/cron/send_daily_reminders.php >> /full/path/to/marick-health-system-v2/cron/reminders.log 2>&1
 *
 * or Windows Task Scheduler running:
 *     php.exe C:\path\to\marick-health-system-v2\cron\send_daily_reminders.php
 *
 * RUN IT LOCALLY FOR TESTING (this project's current setup)
 * -----------------------------------------------------------
 * Two ways, both supported by this same file:
 *
 *   1. CLI (XAMPP/WAMP's bundled PHP):
 *        php cron/send_daily_reminders.php
 *
 *   2. Browser, while logged in to the admin panel in the same browser:
 *        http://localhost/marick-health-system-v2/cron/send_daily_reminders.php
 *      or, without an admin session, using the shared key from
 *      System Settings (system_settings.cron_run_key):
 *        http://localhost/marick-health-system-v2/cron/send_daily_reminders.php?key=<cron_run_key from system_settings, 24+ chars>
 *
 * Either way this script is idempotent per row: once a scheduled_sms
 * row is marked 'sent' or 'failed' it is never picked up again, so
 * running it twice in a row (or every few minutes via Task Scheduler
 * for local testing) is safe.
 * ================================================================== */

// Running via CLI never needs session/cookie machinery, and doing this
// before anything else means a stray "headers already sent" warning
// can't leak into a browser response either.
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

error_reporting(E_ALL);
ini_set('display_errors', $isCli ? 1 : 0);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sms_helper.php';
require_once __DIR__ . '/../includes/sms/sms_queue_runner.php';

mh_sms_install($conn); // safe no-op if scheduled_sms / sms_logs already exist

/* ---------------------------------------------------------------------
 * Browser guard (CLI always bypasses this - see header comment above).
 * ------------------------------------------------------------------ */
if (!$isCli) {
    // Browser trigger is OFF unless an admin has stored a strong key in the database
    // (system_settings.cron_run_key, 24+ chars). A logged-in staff session is NOT enough.
    $storedKey = (string) CRON_RUN_KEY;
    $supplied  = (string) ($_GET['key'] ?? '');
    $authorized = ($storedKey !== '' && strlen($storedKey) >= 24 && $supplied !== ''
                   && hash_equals($storedKey, $supplied));

    if (!$authorized) {
        http_response_code(403);
        echo "Forbidden.\n";   // deliberately no hints, no key echo
        exit;
    }
}

if (function_exists('mh_module_is_enabled') && !mh_module_is_enabled('sms_reminders')) {
    echo "SMS Reminders module is turned off in System Settings > Module Access. Nothing sent.\n";
    exit(0);
}

/* ---------------------------------------------------------------------
 * Run the queue.
 * ------------------------------------------------------------------ */
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

if ($summary['checked'] === 0) {
    echo "  Nothing was due (no pending rows with scheduled_date <= today).\n";
} else {
    foreach ($summary['details'] as $d) {
        $icon = $d['status'] === 'sent' ? 'OK  ' : 'FAIL';
        echo "  [$icon] scheduled_sms #{$d['id']} -> patient #{$d['patient_id']} ({$d['phone']}): {$d['note']}\n";
    }
}

echo "[$finishedAt] Done.\n";

// Non-zero exit code on CLI when everything attempted failed, so a
// scheduler's own failure alerting can pick it up.
if ($isCli && $summary['checked'] > 0 && $summary['sent'] === 0) {
    exit(1);
}