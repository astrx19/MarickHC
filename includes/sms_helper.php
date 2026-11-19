<?php

require_once __DIR__ . '/../config/semaphore.php';
require_once __DIR__ . '/../config/android_gateway.php';
require_once __DIR__ . '/../config/sms_provider.php';

require_once __DIR__ . '/sms/SemaphoreSmsClient.php';
require_once __DIR__ . '/sms/AndroidGatewaySmsClient.php';

if (!function_exists('mh_send_sms')) {
    function mh_send_sms($phoneNumber, $message, $senderName = null)
    {
        $provider = mh_active_sms_provider();

        $result = ($provider === 'android_gateway')
            ? mh_send_sms_android_gateway($phoneNumber, $message)
            : mh_send_sms_semaphore($phoneNumber, $message, $senderName);

        $result['provider'] = $provider;
        return $result;
    }
}

if (!function_exists('mh_sms_provider_problem')) {
    function mh_sms_provider_problem()
    {
        $provider = mh_active_sms_provider();
        return ($provider === 'android_gateway')
            ? android_gateway_problem()
            : semaphore_api_key_problem();
    }
}
