<?php
if (!defined('MH_LOADED_INCLUDES_SMS_SEMAPHORESMSCLIENT')) {
define('MH_LOADED_INCLUDES_SMS_SEMAPHORESMSCLIENT', true);

function mh_send_sms_semaphore($phoneNumber, $message, $senderName = null)
{
    if (!function_exists('curl_init')) {
        return mh_sms_result(false, 0, null, null, 'The PHP cURL extension is not enabled. Enable php_curl in php.ini.');
    }

    if (!defined('SEMAPHORE_API_KEY')) {
        return mh_sms_result(false, 0, null, null, 'SMS is not configured. Open System Settings > SMS Reminders.');
    }

    $keyProblem = function_exists('semaphore_api_key_problem') ? semaphore_api_key_problem() : null;
    if ($keyProblem !== null) {
        return mh_sms_result(false, 0, null, null, $keyProblem);
    }

    $normalizedPhone = mh_normalize_ph_mobile($phoneNumber);
    if ($normalizedPhone === '') {
        return mh_sms_result(false, 0, null, null, 'Not a valid Philippine mobile number: "' . $phoneNumber . '".');
    }

    $message = trim((string) $message);
    if ($message === '') {
        return mh_sms_result(false, 0, null, null, 'The SMS message is empty.');
    }

    $sender = $senderName !== null && trim($senderName) !== '' ? trim($senderName) : SEMAPHORE_SENDER_NAME;
    $base   = defined('SEMAPHORE_API_BASE') ? SEMAPHORE_API_BASE : 'https://api.semaphore.co/api/v4';

    $payload = [
        'apikey'     => SEMAPHORE_API_KEY,
        'number'     => $normalizedPhone,
        'message'    => $message,
        'sendername' => $sender,
    ];

    $ch = curl_init($base . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => defined('SEMAPHORE_HTTP_TIMEOUT') ? SEMAPHORE_HTTP_TIMEOUT : 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $body     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return mh_sms_result(false, $httpCode, null, null, 'Network error calling Semaphore: ' . ($curlErr ?: 'unknown cURL failure'));
    }

    $decoded = json_decode($body, true);

    if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && isset($decoded[0]['message_id'])) {
        return mh_sms_result(true, $httpCode, (string) $decoded[0]['message_id'], $decoded, null);
    }

    $errMsg = 'Semaphore rejected the request (HTTP ' . $httpCode . ').';
    if (is_array($decoded)) {
        if (isset($decoded['message'])) {
            $errMsg = is_array($decoded['message']) ? implode(' ', $decoded['message']) : (string) $decoded['message'];
        } elseif (isset($decoded['error'])) {
            $errMsg = (string) $decoded['error'];
        }
    } elseif ($body !== '') {
        $errMsg .= ' Raw response: ' . substr($body, 0, 300);
    }

    return mh_sms_result(false, $httpCode, null, is_array($decoded) ? $decoded : null, $errMsg);
}

if (!function_exists('mh_sms_result')) {
    function mh_sms_result($success, $httpCode, $messageId, $raw, $error)
    {
        return [
            'success'    => $success,
            'http_code'  => $httpCode,
            'message_id' => $messageId,
            'raw'        => $raw,
            'error'      => $error,
        ];
    }
}

function mh_semaphore_get_balance()
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'The PHP cURL extension is not enabled.'];
    }
    if (!defined('SEMAPHORE_API_KEY')) {
        return ['success' => false, 'error' => 'SMS is not configured. Open System Settings > SMS Reminders.'];
    }
    $keyProblem = function_exists('semaphore_api_key_problem') ? semaphore_api_key_problem() : null;
    if ($keyProblem !== null) {
        return ['success' => false, 'error' => $keyProblem];
    }

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['mh_sem_balance']) && (time() - (int) $_SESSION['mh_sem_balance']['at']) < 35) {
        $cached = $_SESSION['mh_sem_balance']['result'];
        if (!$cached['success']) { $cached['error'] .= ' (Showing the last result - wait about a minute before checking again.)'; }
        return $cached;
    }
    $remember = function (array $r) {
        if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION['mh_sem_balance'] = ['at' => time(), 'result' => $r]; }
        return $r;
    };

    $base = defined('SEMAPHORE_API_BASE') ? SEMAPHORE_API_BASE : 'https://api.semaphore.co/api/v4';
    $url  = $base . '/account?apikey=' . urlencode(SEMAPHORE_API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => defined('SEMAPHORE_HTTP_TIMEOUT') ? SEMAPHORE_HTTP_TIMEOUT : 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'error' => 'Network error calling Semaphore: ' . ($curlErr ?: 'unknown cURL failure')];
    }

    $decoded = json_decode($body, true);
    if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && isset($decoded['credit_balance'])) {
        return $remember(['success' => true, 'balance' => (string) $decoded['credit_balance'], 'raw' => $decoded]);
    }

    $errMsg = 'Semaphore rejected the request (HTTP ' . $httpCode . ').';
    if ($httpCode === 429) {
        $errMsg = 'Semaphore allows only 2 balance checks per minute. Wait about a minute and try again. (Sending reminders is not affected.)';
    } elseif (is_array($decoded) && isset($decoded['message'])) {
        $errMsg = is_array($decoded['message']) ? implode(' ', $decoded['message']) : (string) $decoded['message'];
    }
    return $remember(['success' => false, 'error' => $errMsg]);
}

function mh_normalize_ph_mobile($raw)
{
    $digits = preg_replace('/\D+/', '', (string) $raw);
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) === 12 && substr($digits, 0, 2) === '63' && substr($digits, 2, 1) === '9') {
        $digits = '0' . substr($digits, 2);
    }
    elseif (strlen($digits) === 10 && substr($digits, 0, 1) === '9') {
        $digits = '0' . $digits;
    }

    if (strlen($digits) === 11 && substr($digits, 0, 2) === '09') {
        return $digits;
    }

    return '';
}

function mh_to_e164_ph($normalized09)
{
    if (strlen($normalized09) === 11 && substr($normalized09, 0, 1) === '0') {
        return '63' . substr($normalized09, 1);
    }
    return '';
}

function mh_sms_install(mysqli $conn)
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `scheduled_sms` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `patient_id`       INT NOT NULL,
            `phone_number`     VARCHAR(20) NOT NULL,
            `message`          TEXT NOT NULL,
            `scheduled_date`   DATE NOT NULL,
            `status`           ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            `response_log`     TEXT NULL,
            `source_module`    VARCHAR(50) NULL,
            `source_record_id` INT NULL,
            `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `sent_at`          TIMESTAMP NULL,
            KEY `idx_status_date` (`status`, `scheduled_date`),
            KEY `idx_patient` (`patient_id`),
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `sms_logs` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `scheduled_sms_id` INT NULL,
            `patient_id`       INT NULL,
            `phone_number`     VARCHAR(20) NOT NULL,
            `message`          TEXT NOT NULL,
            `status`           ENUM('sent','failed') NOT NULL,
            `response_log`     TEXT NULL,
            `triggered_by`     VARCHAR(20) NOT NULL DEFAULT 'cron',
            `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_created` (`created_at`),
            FOREIGN KEY (`scheduled_sms_id`) REFERENCES `scheduled_sms`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE SET NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
}