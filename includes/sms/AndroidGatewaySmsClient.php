<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE: PHP registers top-level functions at
// compile time, so require_once cannot stop a redeclare when one file is reached by two paths that differ
// only in letter case (/MARICK-HEALTH-SYSTEM-v2/ vs /marick-health-system-v2/ on macOS/Windows).
if (!defined('MH_LOADED_INCLUDES_SMS_ANDROIDGATEWAYSMSCLIENT')) {
define('MH_LOADED_INCLUDES_SMS_ANDROIDGATEWAYSMSCLIENT', true);
/**
 * =====================================================================
 * MARICK HEALTH CENTER - ANDROID SMS GATEWAY CLIENT
 * =====================================================================
 * Thin, dependency-free wrapper around the SMS Gateway API (Simpapp)
 * cloud endpoint - or any gateway with the same contract - over PHP cURL.
 *
 * REQUEST CONTRACT
 *   POST  <ANDROID_GATEWAY_URL>
 *   Content-Type: application/json
 *   X-API-Key: <saved API key>
 *   Body: {"phoneNumber": "+639XXXXXXXXX", "message": "..."}
 *
 * USAGE
 * -----
 *   require_once __DIR__ . '/../../config/android_gateway.php';
 *   require_once __DIR__ . '/../../includes/sms/SemaphoreSmsClient.php'; // for
 *       mh_normalize_ph_mobile() / mh_sms_result(), shared across providers
 *   require_once __DIR__ . '/../../includes/sms/AndroidGatewaySmsClient.php';
 *
 *   $result = mh_send_sms_android_gateway('09171234567', 'Hello!');
 *   if ($result['success']) { ... } else { echo $result['error']; }
 *
 * In normal use, code should not require this file directly - call
 * mh_send_sms() from includes/sms_helper.php instead, which loads
 * everything needed and routes to whichever provider is active.
 * ================================================================== */

/**
 * Converts any common Philippine mobile format to strict E.164 (+639XXXXXXXXX).
 *
 *   09184005379   -> +639184005379
 *   639184005379  -> +639184005379
 *   +639184005379 -> +639184005379
 *   9184005379    -> +639184005379
 *
 * @return string E.164 number, or '' when the input is not a valid PH mobile.
 */
if (!function_exists('mh_ph_to_e164_plus')) {
    function mh_ph_to_e164_plus($raw)
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);   // drops +, spaces, dashes, ()

        if (strlen($digits) === 11 && strncmp($digits, '09', 2) === 0) {
            $digits = '63' . substr($digits, 1);              // 09XXXXXXXXX
        } elseif (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '63' . $digits;                         // 9XXXXXXXXX
        }
        // else: expect 639XXXXXXXXX already

        return (strlen($digits) === 12 && strncmp($digits, '639', 3) === 0) ? '+' . $digits : '';
    }
}

/**
 * Sends one SMS through the Simpapp SMS Gateway API (V1 cloud endpoint).
 *
 *   POST  ANDROID_GATEWAY_URL
 *   Content-Type: application/json
 *   X-API-Key: <ANDROID_GATEWAY_KEY>
 *   Body: {"phoneNumber": "+639XXXXXXXXX", "message": "..."}
 *
 * @return array{
 *   success: bool,     // true only when HTTP 2xx AND the body does not report an error
 *   status: string,    // "Success", "Queued", or the exact error message - show this in the UI banner
 *   http_code: int,
 *   message_id: string|null,
 *   raw: array|null,   // decoded JSON body (null if the body was not JSON)
 *   error: string|null // same text as status on failure, null on success
 * }
 */
function send_sms_simpapp($phoneNumber, $message)
{
    $fail = function ($text, $httpCode = 0, $raw = null) {
        return [
            'success'    => false,
            'status'     => $text,
            'http_code'  => (int) $httpCode,
            'message_id' => null,
            'raw'        => $raw,
            'error'      => $text,
        ];
    };

    if (!function_exists('curl_init')) {
        return $fail('The PHP cURL extension is not enabled. Enable extension=curl in php.ini.');
    }

    // The database (System Settings) is the only source of truth; no config file is involved.
    $gatewayUrl = '';
    $gatewayKey = '';
    if (function_exists('mh_setting')) {
        $gatewayUrl = trim((string) mh_setting('android_gateway_url', ''));
        $gatewayKey = trim((string) mh_setting('android_gateway_key', ''));
    }
    if ($gatewayUrl === '' && defined('ANDROID_GATEWAY_URL')) { $gatewayUrl = trim((string) ANDROID_GATEWAY_URL); }
    if ($gatewayKey === '' && defined('ANDROID_GATEWAY_KEY')) { $gatewayKey = trim((string) ANDROID_GATEWAY_KEY); }

    if ($gatewayUrl === '' || !preg_match('#^https?://#i', $gatewayUrl)) {
        return $fail('The gateway endpoint URL is missing or invalid. Set it in System Settings > SMS Reminders.');
    }
    if ($gatewayKey === '' && stripos($gatewayUrl, 'cloudfunctions.net') !== false) {
        return $fail('The Simpapp API key is missing. Paste it in System Settings > SMS Reminders > Gateway API Key.');
    }

    // 1. Strict E.164 phone number.
    $e164 = mh_ph_to_e164_plus($phoneNumber);
    if ($e164 === '') {
        return $fail('Not a valid Philippine mobile number: "' . $phoneNumber . '". Use 09XXXXXXXXX, 639XXXXXXXXX or +639XXXXXXXXX.');
    }

    $message = trim((string) $message);
    if ($message === '') {
        return $fail('The SMS message is empty.');
    }

    // 2. Request body - key names must be exactly "phoneNumber" and "message".
    $jsonBody = json_encode(
        ['phoneNumber' => $e164, 'message' => $message],
        JSON_UNESCAPED_UNICODE
    );
    if ($jsonBody === false) {
        return $fail('Could not encode the SMS as JSON: ' . json_last_error_msg());
    }

    $apiKey = $gatewayKey;

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Key: ' . $apiKey,
    ];

    // Local XAMPP on macOS usually has no CA bundle, which makes https calls fail
    // with cURL error 60. Peer verification is therefore OFF by default here.
    // On a production server, set ANDROID_GATEWAY_SSL_VERIFY to true.
    $verifySsl = defined('ANDROID_GATEWAY_SSL_VERIFY') ? (bool) ANDROID_GATEWAY_SSL_VERIFY : false;

    $ch = curl_init($gatewayUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // a redirect would drop the POST body/headers
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

    // 3. Execute + capture diagnostics.
    $body     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $curlNo   = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 3a. Local / network failure (no HTTP response at all).
    if ($body === false) {
        $text = 'Could not reach the SMS gateway: ' . ($curlErr !== '' ? $curlErr : 'unknown cURL failure') . ' (cURL error ' . $curlNo . ').';
        if ($curlNo === 60 || $curlNo === 77) {
            $text .= ' Local SSL certificate problem - set ANDROID_GATEWAY_SSL_VERIFY to false for XAMPP.';
        } elseif ($curlNo === 6) {
            $text .= ' DNS lookup failed - check this computer\'s internet connection.';
        } elseif ($curlNo === 28) {
            $text .= ' The request timed out after 15 seconds.';
        }
        mh_android_gateway_log_error($e164, $text);
        return $fail($text, $httpCode);
    }

    // 3b. Decode the body.
    $data = json_decode($body, true);
    $data = is_array($data) ? $data : null;

    $apiStatus = ($data !== null && isset($data['status']) && is_string($data['status']))
        ? strtolower(trim($data['status'])) : '';

    // Simpapp answers HTTP 200 + {"smsId": "...", "status": "queued", ...}.
    $smsId = '';
    foreach (['smsId', 'id', 'message_id', 'messageId'] as $k) {
        if ($data !== null && isset($data[$k]) && is_scalar($data[$k]) && (string) $data[$k] !== '') {
            $smsId = (string) $data[$k];
            break;
        }
    }

    // 3c. SUCCESS: HTTP 2xx AND (status is queued/sent/success OR an smsId came back).
    //     "queued" is the normal success state - it is NOT an error.
    $httpOk     = ($httpCode >= 200 && $httpCode < 300);
    $statusOk   = in_array($apiStatus, ['queued', 'sent', 'success', 'accepted'], true);
    $isAccepted = $httpOk && ($statusOk || $smsId !== '');

    if ($isAccepted) {
        $label = $apiStatus !== '' ? ucfirst($apiStatus) : 'Success';
        return [
            'success'    => true,
            'status'     => $label,                       // e.g. "Queued"
            'http_code'  => $httpCode,
            'message_id' => $smsId !== '' ? $smsId : null,
            'raw'        => $data,
            'error'      => null,
        ];
    }

    // 3d. FAILURE: use the API's own wording whenever it gave one.
    $apiError = null;
    if ($data !== null) {
        foreach (['error', 'errors', 'detail', 'message'] as $k) {
            if (!empty($data[$k])) {
                $apiError = is_array($data[$k]) ? json_encode($data[$k], JSON_UNESCAPED_UNICODE) : (string) $data[$k];
                break;
            }
        }
    }
    if ($apiError === null) {
        if ($httpCode === 401 || $httpCode === 403) {
            $apiError = 'Invalid or missing API key (HTTP ' . $httpCode . '). Re-copy it from the Simpapp app.';
        } elseif ($httpCode === 429) {
            $apiError = 'Too many requests (HTTP 429). Wait a moment and try again.';
        } elseif ($httpCode >= 500) {
            $apiError = 'The gateway had a server error (HTTP ' . $httpCode . ').';
        } elseif ($httpOk) {
            $apiError = 'The gateway answered HTTP ' . $httpCode . ' but the response had no status or smsId.';
        } else {
            $apiError = 'The gateway rejected the request (HTTP ' . $httpCode . ').';
        }
        if (trim($body) !== '') {
            $apiError .= ' Raw response: ' . substr(trim($body), 0, 300);
        }
    }
    mh_android_gateway_log_error($e164, 'HTTP ' . $httpCode . ' - ' . $apiError);
    return $fail($apiError, $httpCode, $data);
}

/**
 * Router entry point (called by mh_send_sms() in includes/sms_helper.php).
 * Kept so the scheduler, cron and password-reset code keep working unchanged.
 */
function mh_send_sms_android_gateway($phoneNumber, $message)
{
    return send_sms_simpapp($phoneNumber, $message);
}

/**
 * Writes gateway-unreachable / rejected errors to the PHP error log so
 * they show up in the server's system logs, not just in the SMS UI.
 */
if (!function_exists('mh_android_gateway_log_error')) {
    function mh_android_gateway_log_error($phoneNumber, $errMsg)
    {
        error_log('[Marick Health][Android SMS Gateway] Failed sending to ' . $phoneNumber . ': ' . $errMsg);
    }
}
}
