<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE: PHP registers top-level functions at
// compile time, so require_once cannot stop a redeclare when one file is reached by two paths that differ
// only in letter case (/MARICK-HEALTH-SYSTEM-v2/ vs /marick-health-system-v2/ on macOS/Windows).
if (!defined('MH_LOADED_INCLUDES_SMS_SEMAPHORESMSCLIENT')) {
define('MH_LOADED_INCLUDES_SMS_SEMAPHORESMSCLIENT', true);
/**
 * =====================================================================
 * MARICK HEALTH CENTER - SEMAPHORE SMS CLIENT
 * =====================================================================
 * Thin, dependency-free wrapper around the Semaphore SMS API
 * (https://semaphore.co/docs) using PHP cURL.
 *
 * USAGE
 * -----
 *   require_once __DIR__ . '/../../config/semaphore.php';
 *   require_once __DIR__ . '/../../includes/sms/SemaphoreSmsClient.php';
 *
 *   $result = mh_send_sms_semaphore('09171234567', 'Hello from Marick Health Center!');
 *   if ($result['success']) { ... } else { echo $result['error']; }
 *
 * NOTE: application code should call mh_send_sms() from
 * includes/sms_helper.php instead of this function directly - that
 * wrapper routes to Semaphore or the Android SMS Gateway depending on
 * the active provider configured in System Settings. This file only
 * implements the Semaphore side of that routing.
 *
 * This file also owns:
 *   - mh_normalize_ph_mobile()   - shared phone-number sanitizer used by
 *                                  both the form-side scheduler and the
 *                                  cron sender, so both agree on format.
 *   - mh_sms_install()           - creates scheduled_sms / sms_logs if
 *                                  they don't exist yet (safe to call on
 *                                  every request, like mh_settings_install()).
 *   - mh_semaphore_get_balance() - account credit balance, for the SMS
 *                                  dashboard.
 * ================================================================== */

/* =====================================================================
 * 1. SENDING
 * ================================================================== */

/**
 * Sends one SMS via the Semaphore API.
 *
 * @param string $phoneNumber Philippine mobile number, any of the formats
 *                             mh_normalize_ph_mobile() accepts (this
 *                             function normalizes it itself).
 * @param string $message     The SMS body. Semaphore bills per 160-char
 *                             segment (or 60 for unicode); long messages
 *                             are still accepted and simply cost more.
 * @param string|null $senderName Overrides SEMAPHORE_SENDER_NAME for this
 *                             one message. Leave null to use the configured default.
 *
 * @return array{
 *   success: bool,
 *   http_code: int,
 *   message_id: string|null,
 *   raw: array|null,        // decoded JSON body from Semaphore
 *   error: string|null
 * }
 */
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
        'number'     => $normalizedPhone, // Semaphore accepts 09XXXXXXXXX
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

    // Semaphore returns a JSON array on success (one element per recipient
    // when comma-separated numbers are sent - always one here), or a JSON
    // object with an "error"/"message" key on failure.
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

/**
 * Account credit balance (for the SMS dashboard). Semaphore's /account
 * endpoint returns { account_name, credit_balance, status, ... }.
 *
 * @return array{success:bool, balance?:string, raw?:array, error?:string}
 */
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
        return ['success' => true, 'balance' => (string) $decoded['credit_balance'], 'raw' => $decoded];
    }

    $errMsg = 'Semaphore rejected the request (HTTP ' . $httpCode . ').';
    if (is_array($decoded) && isset($decoded['message'])) {
        $errMsg = is_array($decoded['message']) ? implode(' ', $decoded['message']) : (string) $decoded['message'];
    }
    return ['success' => false, 'error' => $errMsg];
}

/* =====================================================================
 * 2. PHONE NUMBER NORMALIZATION
 * ================================================================== */

/**
 * Normalizes a Philippine mobile number to the 09XXXXXXXXX format that
 * Semaphore's API expects. Accepts (and fixes) the common variants staff
 * actually type: "+639171234567", "639171234567", "09171234567",
 * "9171234567", with stray spaces/dashes/parentheses.
 *
 * @return string "09XXXXXXXXX" (11 digits) or '' if not a recognizable
 *                Philippine mobile number.
 */
function mh_normalize_ph_mobile($raw)
{
    $digits = preg_replace('/\D+/', '', (string) $raw);
    if ($digits === '') {
        return '';
    }

    // 63 9XXXXXXXXX (12 digits, with or without a leading + already stripped)
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '63' && substr($digits, 2, 1) === '9') {
        $digits = '0' . substr($digits, 2);
    }
    // 9XXXXXXXXX (10 digits, missing the leading 0)
    elseif (strlen($digits) === 10 && substr($digits, 0, 1) === '9') {
        $digits = '0' . $digits;
    }

    // Final shape: exactly 11 digits, starting with "09".
    if (strlen($digits) === 11 && substr($digits, 0, 2) === '09') {
        return $digits;
    }

    return '';
}

/**
 * Same number in E.164 (639XXXXXXXXX, no "+"), which some carriers/tools
 * prefer. Semaphore itself accepts 09XXXXXXXXX, so this is only exposed
 * for logging / display purposes.
 */
function mh_to_e164_ph($normalized09)
{
    if (strlen($normalized09) === 11 && substr($normalized09, 0, 1) === '0') {
        return '63' . substr($normalized09, 1);
    }
    return '';
}

/* =====================================================================
 * 3. TABLE BOOTSTRAP
 * ================================================================== */

/**
 * Creates scheduled_sms and sms_logs if they don't already exist.
 * Idempotent - safe to call on every request, mirroring
 * mh_settings_install() in includes/settings_helper.php. The full
 * migration also lives in database/sms_schema.sql for a one-time
 * explicit run.
 */
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
