<?php
if (!defined('MH_LOADED_INCLUDES_SMS_SMS_QUEUE_RUNNER')) {
define('MH_LOADED_INCLUDES_SMS_SMS_QUEUE_RUNNER', true);

require_once __DIR__ . '/../sms_helper.php';
require_once __DIR__ . '/sms_scheduler.php';

function mh_run_sms_queue(mysqli $conn, $triggeredBy = 'cron', $limit = 100)
{
    $summary = ['checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped_no_key' => false, 'paused_holiday' => false, 'details' => []];

    $keyProblem = function_exists('mh_sms_provider_problem') ? mh_sms_provider_problem() : 'includes/sms_helper.php not loaded.';
    if ($keyProblem !== null) {
        $summary['skipped_no_key'] = true;
        $summary['details'][] = ['id' => 0, 'patient_id' => 0, 'phone' => '', 'status' => 'failed', 'note' => $keyProblem];
        return $summary;
    }

    $limit = max(1, min(500, (int) $limit));

    if (function_exists('mh_sms_is_holiday') && mh_sms_is_holiday($conn, date('Y-m-d'))) {
        $summary['paused_holiday'] = true;
        $summary['details'][] = ['id' => 0, 'patient_id' => 0, 'phone' => '', 'status' => 'paused',
                                 'note' => 'Today is a declared clinic holiday - sending is paused; pending reminders stay queued.'];
        return $summary;
    }

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

if (!function_exists('mh_send_sms_queue_row')) {
function mh_send_sms_queue_row(mysqli $conn, $id, $triggeredBy = 'manual')
{
    $id = (int) $id;
    $problem = function_exists('mh_sms_provider_problem') ? mh_sms_provider_problem() : 'includes/sms_helper.php not loaded.';
    if ($problem !== null) { return ['ok' => false, 'message' => 'Cannot send: ' . $problem]; }
    if (function_exists('mh_sms_is_holiday') && mh_sms_is_holiday($conn, date('Y-m-d'))) {
        return ['ok' => false, 'message' => 'Today is a declared clinic holiday - sending is paused.'];
    }

    $st = $conn->prepare("SELECT id, patient_id, phone_number, message FROM scheduled_sms WHERE id = ? AND status = 'pending' LIMIT 1");
    if (!$st) { return ['ok' => false, 'message' => 'Database error: ' . $conn->error]; }
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) { return ['ok' => false, 'message' => 'That reminder is no longer pending (already sent or removed).']; }

    $lockName = 'mh_sms_row_' . $id;
    $lk = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lockName) . "', 0) AS l");
    if (!$lk || (int) ($lk->fetch_assoc()['l'] ?? 0) !== 1) { return ['ok' => false, 'message' => 'That reminder is already being sent.']; }

    $result = mh_send_sms($row['phone_number'], $row['message']);
    $status = $result['success'] ? 'sent' : 'failed';
    $log    = $result['success'] ? ('message_id: ' . $result['message_id']) : ('HTTP ' . $result['http_code'] . ': ' . $result['error']);

    $up = $conn->prepare("UPDATE scheduled_sms SET status = ?, response_log = ?, sent_at = NOW() WHERE id = ?");
    $up->bind_param('ssi', $status, $log, $id);
    $up->execute();
    $up->close();
    $conn->query("DO RELEASE_LOCK('" . $conn->real_escape_string($lockName) . "')");
    $lg = $conn->prepare("INSERT INTO sms_logs (scheduled_sms_id, patient_id, phone_number, message, status, response_log, triggered_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($lg) {
        $lg->bind_param('iisssss', $row['id'], $row['patient_id'], $row['phone_number'], $row['message'], $status, $log, $triggeredBy);
        $lg->execute();
        $lg->close();
    }
    return $result['success']
        ? ['ok' => true,  'message' => 'Reminder sent to ' . $row['phone_number'] . '.']
        : ['ok' => false, 'message' => 'Could not send: ' . $result['error']];
}
}
}