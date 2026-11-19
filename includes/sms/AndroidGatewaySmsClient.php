<?php
if (!defined('MH_LOADED_INCLUDES_SMS_ANDROIDGATEWAYSMSCLIENT')) {
define('MH_LOADED_INCLUDES_SMS_ANDROIDGATEWAYSMSCLIENT', true);

if (!function_exists('mh_ph_to_e164_plus')) {
    function mh_ph_to_e164_plus($raw)
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if (strlen($digits) === 11 && strncmp($digits, '09', 2) === 0) {
            $digits = '63' . substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '63' . $digits;
        }

        return (strlen($digits) === 12 && strncmp($digits, '639', 3) === 0) ? '+' . $digits : '';
    }
}

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

    $e164 = mh_ph_to_e164_plus($phoneNumber);
    if ($e164 === '') {
        return $fail('Not a valid Philippine mobile number: "' . $phoneNumber . '". Use 09XXXXXXXXX, 639XXXXXXXXX or +639XXXXXXXXX.');
    }

    $message = trim((string) $message);
    if ($message === '') {
        return $fail('The SMS message is empty.');
    }

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

    $verifySsl = defined('ANDROID_GATEWAY_SSL_VERIFY') ? (bool) ANDROID_GATEWAY_SSL_VERIFY : false;

    $ch = curl_init($gatewayUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

    $body     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $curlNo   = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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

    $data = json_decode($body, true);
    $data = is_array($data) ? $data : null;

    $apiStatus = ($data !== null && isset($data['status']) && is_string($data['status']))
        ? strtolower(trim($data['status'])) : '';

    $smsId = '';
    foreach (['smsId', 'id', 'message_id', 'messageId'] as $k) {
        if ($data !== null && isset($data[$k]) && is_scalar($data[$k]) && (string) $data[$k] !== '') {
            $smsId = (string) $data[$k];
            break;
        }
    }

    $httpOk     = ($httpCode >= 200 && $httpCode < 300);
    $statusOk   = in_array($apiStatus, ['queued', 'sent', 'success', 'accepted'], true);
    $isAccepted = $httpOk && ($statusOk || $smsId !== '');

    if ($isAccepted) {
        $label = $apiStatus !== '' ? ucfirst($apiStatus) : 'Success';
        return [
            'success'    => true,
            'status'     => $label,
            'http_code'  => $httpCode,
            'message_id' => $smsId !== '' ? $smsId : null,
            'raw'        => $data,
            'error'      => null,
        ];
    }

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

function mh_send_sms_android_gateway($phoneNumber, $message)
{
    return send_sms_simpapp($phoneNumber, $message);
}

if (!function_exists('mh_android_gateway_log_error')) {
    function mh_android_gateway_log_error($phoneNumber, $errMsg)
    {
        error_log('[Marick Health][Android SMS Gateway] Failed sending to ' . $phoneNumber . ': ' . $errMsg);
    }
}
}
