<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - SMS HELPER (PROVIDER ROUTER)
 * =====================================================================
 * Single entry point the rest of the app should use to send an SMS:
 *
 *   require_once __DIR__ . '/../includes/sms_helper.php';
 *   $result = mh_send_sms('09171234567', 'Hello from Marick Health Center!');
 *   if ($result['success']) { ... } else { echo $result['error']; }
 *
 * mh_send_sms() reads the active provider (System Settings > SMS
 * Reminders > Active SMS Provider) and routes to:
 *   - mh_send_sms_semaphore()       (includes/sms/SemaphoreSmsClient.php)
 *   - mh_send_sms_android_gateway() (includes/sms/AndroidGatewaySmsClient.php)
 *
 * This file replaces the old pattern of requiring config/semaphore.php +
 * includes/sms/SemaphoreSmsClient.php directly - just require this one
 * file instead. Everything it pulls in is safe to require multiple
 * times (require_once) and has no side effects beyond defining
 * constants/functions, so it is safe to add to any page or script.
 * ================================================================== */

require_once __DIR__ . '/../config/semaphore.php';
require_once __DIR__ . '/../config/android_gateway.php';
require_once __DIR__ . '/../config/sms_provider.php';

require_once __DIR__ . '/sms/SemaphoreSmsClient.php';       // mh_send_sms_semaphore(), mh_normalize_ph_mobile(), mh_sms_result(), mh_sms_install(), mh_semaphore_get_balance()
require_once __DIR__ . '/sms/AndroidGatewaySmsClient.php';  // mh_send_sms_android_gateway()

if (!function_exists('mh_send_sms')) {
    /**
     * Sends one SMS via whichever provider is currently active.
     *
     * @param string      $phoneNumber Philippine mobile number, any format
     *                                 mh_normalize_ph_mobile() accepts.
     * @param string      $message     The SMS body.
     * @param string|null $senderName  Semaphore-only: overrides the
     *                                 configured sender name for this one
     *                                 message. Ignored by the Android
     *                                 SMS Gateway provider.
     *
     * @return array{
     *   success: bool,
     *   http_code: int,
     *   message_id: string|null,
     *   raw: array|null,
     *   error: string|null,
     *   provider: string
     * }
     */
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

/**
 * Human-readable problem with whichever provider is currently active, or
 * NULL when it looks correctly configured. Lets dashboards/widgets show
 * one health check regardless of which provider is selected.
 */
if (!function_exists('mh_sms_provider_problem')) {
    function mh_sms_provider_problem()
    {
        $provider = mh_active_sms_provider();
        return ($provider === 'android_gateway')
            ? android_gateway_problem()
            : semaphore_api_key_problem();
    }
}
