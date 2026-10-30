<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE: PHP registers top-level functions at
// compile time, so require_once cannot stop a redeclare when one file is reached by two paths that differ
// only in letter case (/MARICK-HEALTH-SYSTEM-v2/ vs /marick-health-system-v2/ on macOS/Windows).
if (!defined('MH_LOADED_INCLUDES_SMS_SMS_QUEUE_RUNNER')) {
define('MH_LOADED_INCLUDES_SMS_SMS_QUEUE_RUNNER', true);
/**
 * =====================================================================
 * SMS QUEUE RUNNER
 * =====================================================================
 * One function, called from two places:
 *   - cron/send_daily_reminders.php  (scheduled task / CLI / browser)
 *   - admin/sms_dashboard.php        ("Run Pending SMS Queue Now" button)
 *
 * Keeping the actual send-and-log logic in one place means both callers
 * always behave identically.
 */

require_once __DIR__ . '/../sms_helper.php';

/**
 * Sends every `scheduled_sms` row that is due (scheduled_date <= today)
 * and still `pending`, via mh_send_sms(), then records the outcome in
 * both `scheduled_sms` (status/response_log/sent_at) and `sms_logs`
 * (a permanent, append-only history).
 *
 * @param mysqli $conn
 * @param string $triggeredBy 'cron' or 'manual' - stored in sms_logs.triggered_by
 * @param int    $limit       Safety cap per run (a single Semaphore call per row).
 *
 * @return array{
 *   checked: int,
 *   sent: int,
 *   failed: int,
 *   skipped_no_key: bool,
 *   details: array<int, array{id:int, patient_id:int, phone:string, status:string, note:string}>
 * }
 */
function mh_run_sms_queue(mysqli $conn, $triggeredBy = 'cron', $limit = 100)
{
    $summary = ['checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped_no_key' => false, 'details' => []];

    // Fail fast with one clear message rather than N identical failures.
    $keyProblem = function_exists('mh_sms_provider_problem') ? mh_sms_provider_problem() : 'includes/sms_helper.php not loaded.';
    if ($keyProblem !== null) {
        $summary['skipped_no_key'] = true;
        $summary['details'][] = ['id' => 0, 'patient_id' => 0, 'phone' => '', 'status' => 'failed', 'note' => $keyProblem];
        return $summary;
    }

    $limit = max(1, min(500, (int) $limit));

    $stmt = $conn->prepare(
        "SELECT id, patient_id, phone_number, message
         FROM scheduled_sms
         WHERE status = 'pending' AND scheduled_date <= CURDATE()
         ORDER BY scheduled_date ASC, id ASC
         LIMIT ?"
    );
    if (!$stmt) {
        $summary['details'][] = ['id' => 0, 'patient_id' => 0, 'phone' => '', 'status' => 'failed', 'note' => 'Database error: ' . $conn->error];
        return $summary;
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $updateStmt = $conn->prepare(
        "UPDATE scheduled_sms SET status = ?, response_log = ?, sent_at = NOW() WHERE id = ?"
    );
    $logStmt = $conn->prepare(
        "INSERT INTO sms_logs (scheduled_sms_id, patient_id, phone_number, message, status, response_log, triggered_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($rows as $row) {
        $summary['checked']++;

        $result = mh_send_sms($row['phone_number'], $row['message']);
        $status = $result['success'] ? 'sent' : 'failed';
        $log    = $result['success']
            ? ('message_id: ' . $result['message_id'])
            : ('HTTP ' . $result['http_code'] . ': ' . $result['error']);

        if ($updateStmt) {
            $updateStmt->bind_param('ssi', $status, $log, $row['id']);
            $updateStmt->execute();
        }
        if ($logStmt) {
            $logStmt->bind_param(
                'iisssss',
                $row['id'], $row['patient_id'], $row['phone_number'], $row['message'], $status, $log, $triggeredBy
            );
            $logStmt->execute();
        }

        if ($result['success']) { $summary['sent']++; } else { $summary['failed']++; }

        $summary['details'][] = [
            'id'         => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'phone'      => $row['phone_number'],
            'status'     => $status,
            'note'       => $log,
        ];
    }

    if ($updateStmt) { $updateStmt->close(); }
    if ($logStmt) { $logStmt->close(); }

    return $summary;
}
}
