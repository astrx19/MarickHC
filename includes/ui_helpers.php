<?php
/**
 * Shared UI helpers (Step 1 - unified notifications).
 *
 * Loaded automatically by includes/modal_assets.php, which every page already
 * includes inside <head>, so these are available anywhere in the page body.
 *
 *   mh_h($value)                        -> HTML/attribute-safe output (htmlspecialchars with sane flags)
 *   mh_js($value)                       -> safe JSON literal for inline <script>
 *   mh_notify_script($msg, $type)       -> <script> that shows ONE toast/alert on page load
 *   mh_flash_scripts($success, $error)  -> convenience: success toast + error alert
 *
 * Replaces the old hand-written
 *   echo "<script>... showCustomModal('Success!', '" . addslashes($msg) . "') ...</script>";
 * which broke on newlines/quotes and was copy-pasted per page.
 */

if (!function_exists('mh_js')) {
    /** JSON-encode for use inside an inline <script> (no </script> or quote break-outs). */
    function mh_js($value): string
    {
        $json = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        return $json === false ? 'null' : $json;
    }
}

if (!function_exists('mh_notify_script')) {
    /**
     * @param string|null $message  plain text (it is shown via textContent, never as HTML)
     * @param string      $type     success | info | warning | error
     */
    function mh_notify_script(?string $message, string $type = 'info', ?string $title = null): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return '';
        }
        $opts = $title !== null ? ',{title:' . mh_js($title) . '}' : '';
        return "<script>document.addEventListener('DOMContentLoaded',function(){mh_notify("
            . mh_js($message) . ',' . mh_js($type) . $opts . ");});</script>\n";
    }
}

if (!function_exists('mh_flash_scripts')) {
    function mh_flash_scripts(?string $success, ?string $error): string
    {
        return mh_notify_script($success, 'success') . mh_notify_script($error, 'error');
    }
}

if (!function_exists('mh_h')) {
    /** Escape for HTML text AND attribute values (quotes included). Use for every echoed database/user value. */
    function mh_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('mh_clean_temperature')) {
    /**
     * Body temperature in degrees Celsius: digits with an optional decimal part ("36", "36.8").
     * A trailing "C" / "°C" (what OCR or habit adds) is tolerated and dropped; anything else is rejected.
     * Empty input is fine (the field is optional).
     *
     * @return array ['ok' => bool, 'value' => string ('' when empty), 'error' => string|null]
     */
    function mh_clean_temperature($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') { return ['ok' => true, 'value' => '', 'error' => null]; }
        if (!preg_match('/^(\d{2}(?:\.\d{1,2})?)\s*(?:°\s*C|C)?$/iu', $raw, $m)) {
            return ['ok' => false, 'value' => '', 'error' => 'Temperature must be a number only, like 36.8 (degrees Celsius).'];
        }
        $v = (float) $m[1];
        if ($v < 30 || $v > 45) {
            return ['ok' => false, 'value' => '', 'error' => 'Temperature must be between 30 and 45 degrees Celsius (for example 36.8).'];
        }
        return ['ok' => true, 'value' => $m[1], 'error' => null];
    }
}
