<?php
/**
 * Shared UI helpers (Step 1 - unified notifications).
 *
 * Loaded automatically by includes/modal_assets.php, which every page already
 * includes inside <head>, so these are available anywhere in the page body.
 *
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
