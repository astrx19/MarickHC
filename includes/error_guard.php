<?php
/**
 * Error safety net - no more blank white pages.
 *
 * Loaded by config/database.php, so it protects every page. When something fails that nobody anticipated (an undefined
 * function, a missing column, a crashed query ...) the visitor sees a clear "something went wrong" screen with a short
 * reference code, and the full technical detail goes to the server error log under that same code. Health Head / Admin
 * accounts also see the technical line on screen, to speed up fixing. Nothing sensitive is ever shown to anyone else.
 * AJAX / API requests get the same information as JSON instead of HTML.
 */
if (!function_exists('mh_error_ref')) {

    function mh_error_ref()
    {
        static $ref = null;
        if ($ref === null) { $ref = strtoupper(bin2hex(random_bytes(3))); }
        return $ref;
    }

    function mh_error_wants_json()
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return stripos($accept, 'application/json') !== false
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== ''
            || strpos($script, '/api/') !== false || strpos($script, '/ocr/') !== false;
    }

    function mh_error_render($type, $message, $file, $line)
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $ref = mh_error_ref();
            error_log('[MarickHealth] [' . $ref . '] ' . $type . ': ' . $message . ' in ' . $file . ':' . $line . ' (' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ')');
            if (!headers_sent()) { http_response_code(500); }

            $role = $_SESSION['role'] ?? '';
            $admin = $role !== '' && function_exists('mh_is_admin_tier') && mh_is_admin_tier($role);
            $detail = $admin ? $type . ': ' . $message . ' (' . basename($file) . ':' . $line . ')' : '';

            if (mh_error_wants_json()) {
                if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
                echo json_encode(['error' => 'Something went wrong on the server. Nothing was changed. Reference ' . $ref . '.', 'reference' => $ref]);
                return;
            }
            $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
            echo '<div style="position:fixed;inset:0;z-index:2147483647;background:#f1f5f9;display:flex;align-items:center;justify-content:center;padding:20px;font-family:system-ui,sans-serif;">'
               . '<div style="max-width:520px;background:#fff;border:1px solid #cbd5e1;border-top:5px solid #dc2626;border-radius:14px;padding:26px 28px;box-shadow:0 20px 50px rgba(15,23,42,.18);color:#1e293b;line-height:1.5;">'
               . '<h2 style="margin:0 0 8px;font-size:1.25rem;">Something went wrong</h2>'
               . '<p style="margin:0 0 10px;">The page could not finish. <strong>Nothing was lost or changed by this error.</strong> Please go back and try again.</p>'
               . '<p style="margin:0 0 14px;color:#475569;font-size:.9rem;">If it keeps happening, tell the system administrator this reference: <strong>' . $e($ref) . '</strong></p>'
               . ($detail !== '' ? '<details style="margin:0 0 14px;font-size:.82rem;color:#475569;"><summary>Technical detail (administrators only)</summary><code style="display:block;margin-top:6px;word-break:break-word;">' . $e($detail) . '</code></details>' : '')
               . '<button type="button" onclick="history.length>1?history.back():location.href=\'dashboard.php\'" style="padding:9px 20px;border:0;border-radius:999px;background:#4ca1af;color:#fff;font-weight:700;cursor:pointer;">Go back</button>'
               . '</div></div>';
        } catch (Throwable $inner) {
            // the safety net itself must never fail loudly
        }
    }

    set_exception_handler(function ($ex) {
        mh_error_render('Uncaught ' . get_class($ex), $ex->getMessage(), $ex->getFile(), $ex->getLine());
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            mh_error_render('Fatal error', $err['message'], $err['file'], $err['line']);
        }
    });
}
