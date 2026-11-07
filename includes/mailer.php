<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE (see includes/auth_helpers.php).
if (!defined('MH_LOADED_INCLUDES_MAILER')) {
define('MH_LOADED_INCLUDES_MAILER', true);
/**
 * =====================================================================
 * MARICK HEALTH CENTER - MAIL CLIENT
 * =====================================================================
 * mh_send_email() sends through PHPMailer + SMTP (Hostinger: smtp.hostinger.com) whenever
 * config/mail.php has SMTP credentials AND PHPMailer is installed. Otherwise it falls back to
 * PHP's mail(), exactly like before, so localhost testing keeps working.
 *
 * Installing PHPMailer (pick ONE):
 *   A) No Composer (easiest on Hostinger): download PHPMailer from
 *      https://github.com/PHPMailer/PHPMailer/releases and upload ONLY its src/ folder to
 *      includes/PHPMailer/src/  (must contain PHPMailer.php, SMTP.php, Exception.php).
 *   B) Composer (SSH):  composer require phpmailer/phpmailer   in the project root
 *      -> vendor/autoload.php is picked up automatically.
 * ================================================================== */

function mh_mailer_load_phpmailer()
{
    if (class_exists('\PHPMailer\PHPMailer\PHPMailer', false)) { return true; }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) { require_once $autoload; }
    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer', false)) {
        $dir = __DIR__ . '/PHPMailer/src/';
        if (is_file($dir . 'PHPMailer.php') && is_file($dir . 'SMTP.php') && is_file($dir . 'Exception.php')) {
            require_once $dir . 'Exception.php';
            require_once $dir . 'PHPMailer.php';
            require_once $dir . 'SMTP.php';
        }
    }
    return class_exists('\PHPMailer\PHPMailer\PHPMailer', false);
}

function mh_mailer_smtp_configured()
{
    return defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== '' && MAIL_SMTP_USER !== '' && MAIL_SMTP_PASS !== '';
}


/**
 * Built-in SMTP client - used when SMTP credentials exist but the PHPMailer library is NOT installed, so password-reset
 * email works with nothing extra to download. Supports implicit TLS (port 465, "ssl") and STARTTLS (port 587, "tls"),
 * AUTH LOGIN, and sends a UTF-8 multipart (text + HTML) message.
 *
 * @param array|null $transcript  receives a readable, PASSWORD-FREE log of the SMTP conversation (for the Email Check page)
 * @return array [bool success, string|null error]
 */
function mh_smtp_native_send($to, $subject, $bodyHtml, $bodyText, &$transcript = null)
{
    $transcript = [];
    $log = function ($line) use (&$transcript) { $transcript[] = $line; };
    $host = MAIL_SMTP_HOST; $port = (int) MAIL_SMTP_PORT; $secure = MAIL_SMTP_SECURE;
    $from = MAIL_FROM_ADDRESS; $fromName = MAIL_FROM_NAME;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $log("CONNECT $host:$port ($secure) failed: $errstr ($errno)");
        return [false, "Could not connect to $host on port $port ($errstr). Check the host name and port, or the hosting may block this port - try the other option (465 + ssl, or 587 + tls)."];
    }
    stream_set_timeout($fp, 15);
    $log("CONNECTED to $host:$port ($secure)");

    $read = function () use ($fp, $log) {
        $reply = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $reply .= $line;
            $log('SERVER: ' . rtrim($line));
            if (strlen($line) < 4 || $line[3] !== '-') { break; }   // "250-..." continues, "250 ..." ends the reply
        }
        return $reply;
    };
    $send = function ($cmd, array $okCodes, $shown = null) use ($fp, $read, $log) {
        fwrite($fp, $cmd . "\r\n");
        $log('CLIENT: ' . ($shown !== null ? $shown : $cmd));
        $reply = $read();
        $code = (int) substr($reply, 0, 3);
        return [in_array($code, $okCodes, true), $reply, $code];
    };
    $fail = function ($msg) use ($fp) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); return [false, $msg]; };

    $greet = $read();
    if ((int) substr($greet, 0, 3) !== 220) { return $fail('The mail server did not greet us properly: ' . trim($greet)); }
    $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';

    list($ok, $r) = $send('EHLO ' . $ehlo, [250]);
    if (!$ok) { return $fail('EHLO was refused: ' . trim($r)); }
    if ($secure === 'tls') {
        list($ok, $r) = $send('STARTTLS', [220]);
        if (!$ok) { return $fail('The server refused STARTTLS: ' . trim($r)); }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { return $fail('Could not switch the connection to TLS (certificate or protocol problem).'); }
        $log('TLS ENABLED');
        list($ok, $r) = $send('EHLO ' . $ehlo, [250]);
        if (!$ok) { return $fail('EHLO after TLS was refused: ' . trim($r)); }
    }

    list($ok, $r) = $send('AUTH LOGIN', [334]);
    if (!$ok) { return $fail('The server does not accept AUTH LOGIN: ' . trim($r)); }
    list($ok, $r) = $send(base64_encode(MAIL_SMTP_USER), [334], '[username]');
    if (!$ok) { return $fail('The mailbox user name was rejected: ' . trim($r)); }
    list($ok, $r, $code) = $send(base64_encode(MAIL_SMTP_PASS), [235], '[password hidden]');
    if (!$ok) { return $fail($code === 535
        ? 'Login failed (535): the mailbox email or password in config/mail.local.php is wrong. Use the FULL email address and that mailbox\'s own password (for Gmail: an App Password, not the normal password).'
        : 'Login was refused: ' . trim($r)); }

    list($ok, $r) = $send('MAIL FROM:<' . $from . '>', [250]);
    if (!$ok) { return $fail('The sender address was rejected: ' . trim($r) . ' - from_address must be the same mailbox as smtp_user.'); }
    list($ok, $r) = $send('RCPT TO:<' . $to . '>', [250, 251]);
    if (!$ok) { return $fail('The recipient was rejected: ' . trim($r)); }
    list($ok, $r) = $send('DATA', [354]);
    if (!$ok) { return $fail('The server refused to accept the message: ' . trim($r)); }

    $b = 'mh-' . bin2hex(random_bytes(10));
    $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
    $enc = function ($t) { return '=?UTF-8?B?' . base64_encode($t) . '?='; };
    $msg  = 'Date: ' . date('r') . "\r\n";
    $msg .= 'From: ' . $enc($fromName) . ' <' . $from . ">\r\n";
    $msg .= 'To: <' . $to . ">\r\n";
    $msg .= 'Reply-To: <' . $from . ">\r\n";
    $msg .= 'Subject: ' . $enc($subject) . "\r\n";
    $msg .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n";
    $msg .= "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bodyText), 76, "\r\n");
    $msg .= "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bodyHtml), 76, "\r\n");
    $msg .= "--$b--\r\n";
    fwrite($fp, $msg . "\r\n.\r\n");
    $log('CLIENT: [message ' . strlen($msg) . ' bytes]');
    $reply = $read();
    $accepted = ((int) substr($reply, 0, 3) === 250);
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    return $accepted ? [true, null] : [false, 'The server did not accept the message: ' . trim($reply)];
}

/** What is configured right now (no secrets) - used by admin/email_check.php. */
function mh_mailer_status()
{
    $phpmailer = mh_mailer_load_phpmailer();
    $smtp = mh_mailer_smtp_configured();
    $user = defined('MAIL_SMTP_USER') ? MAIL_SMTP_USER : '';
    return [
        'method'          => $smtp ? ($phpmailer ? 'PHPMailer over SMTP' : 'Built-in SMTP client') : 'PHP mail() fallback (not reliable on hosting)',
        'smtp_configured' => $smtp,
        'phpmailer'       => $phpmailer,
        'host'            => defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : '',
        'port'            => defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : '',
        'secure'          => defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : '',
        'user'            => $user !== '' ? substr($user, 0, 2) . '***' . substr($user, (int) strrpos($user, '@')) : '',
        'pass_set'        => defined('MAIL_SMTP_PASS') && MAIL_SMTP_PASS !== '',
        'from'            => defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '',
        'from_ok'         => defined('MAIL_FROM_ADDRESS') && $user !== '' && strcasecmp(MAIL_FROM_ADDRESS, $user) === 0,
        'from_local'      => defined('MAIL_FROM_ADDRESS') && preg_match('/\.local$/i', MAIL_FROM_ADDRESS),
        'local_file'      => is_file(__DIR__ . '/../config/mail.local.php'),
    ];
}

/** Sends a test message and returns the SMTP conversation (passwords removed). Used only by the admin Email Check page. */
function mh_send_email_test($to)
{
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$/i', $to)) { return ['success' => false, 'error' => 'Enter a valid email address ending strictly with .com.', 'method' => '', 'transcript' => []]; }
    $html = '<p>This is a test message from <strong>' . htmlspecialchars((string) MAIL_FROM_NAME) . '</strong>. If you can read it, password-reset emails will arrive too.</p>';
    $text = 'This is a test message from ' . MAIL_FROM_NAME . '. If you can read it, password-reset emails will arrive too.';
    $subject = 'Test email - ' . MAIL_FROM_NAME;

    if (mh_mailer_smtp_configured()) {
        // The built-in client gives a full readable transcript, and it is the same SMTP login PHPMailer would use.
        list($ok, $err) = mh_smtp_native_send($to, $subject, $html, $text, $transcript);
        return ['success' => $ok, 'error' => $err, 'method' => 'SMTP (' . MAIL_SMTP_HOST . ':' . MAIL_SMTP_PORT . ')', 'transcript' => $transcript];
    }
    $res = mh_send_email($to, $subject, $html, $text);
    return ['success' => $res['success'], 'error' => $res['error'], 'method' => 'PHP mail() fallback',
            'transcript' => ['No SMTP login is configured, so PHP mail() was used. "Accepted" only means PHP handed it over - hosting often drops these.']];
}

/**
 * Sends one email.
 *
 * @return array{success: bool, error: string|null}
 */
function mh_send_email($to, $subject, $bodyHtml, $bodyText = null)
{
    $to = trim((string) $to);
    // One recipient, no CR/LF/commas (header injection), sane length. Checked BEFORE PHPMailer sees it,
    // so a bad address can never turn into an exception message or a stray SMTP error.
    if ($to === '' || strlen($to) > 254 || preg_match('/[\r\n,;<>\s]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$/i', $to)) {
        return ['success' => false, 'error' => 'Not a valid email address (must end strictly with .com).'];
    }
    if (!defined('MAIL_FROM_ADDRESS')) {
        return ['success' => false, 'error' => 'config/mail.php was not loaded (MAIL_FROM_ADDRESS is undefined).'];
    }
    $subject = trim(preg_replace('/[\r\n]+/', ' ', (string) $subject));   // a subject can never carry extra headers
    if ($subject === '') {
        return ['success' => false, 'error' => 'Email subject is empty.'];
    }
    if ($bodyText === null) {
        $bodyText = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml))));
    }

    /* ---- Preferred path: PHPMailer over SMTP ---- */
    if (mh_mailer_smtp_configured() && mh_mailer_load_phpmailer()) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_SMTP_USER;
            $mail->Password   = MAIL_SMTP_PASS;
            $mail->SMTPSecure = (MAIL_SMTP_SECURE === 'tls')
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = MAIL_SMTP_PORT;
            $mail->Timeout    = 15;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mail->addReplyTo(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = $bodyText;
            $mail->send();
            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            // ErrorInfo can name the SMTP host/user, so it goes to the log only - never to visitors.
            // The address is masked in the log: it is personal data and the log is not a mailbox.
            $maskedTo = substr($to, 0, 1) . '***' . substr($to, (int) strrpos($to, '@'));
            error_log('mh_send_email SMTP failure for ' . $maskedTo . ': ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
            return ['success' => false, 'error' => 'SMTP send failed (see the server error log).'];
        }
    }

    /* ---- SMTP credentials present but PHPMailer not installed: built-in SMTP client ---- */
    if (mh_mailer_smtp_configured()) {
        list($ok, $err) = mh_smtp_native_send($to, $subject, $bodyHtml, $bodyText, $transcript);
        if ($ok) { return ['success' => true, 'error' => null]; }
        $maskedTo = substr($to, 0, 1) . '***' . substr($to, (int) strrpos($to, '@'));
        error_log('mh_send_email built-in SMTP failure for ' . $maskedTo . ': ' . $err);
        return ['success' => false, 'error' => 'SMTP send failed (see the server error log, or open admin/email_check.php).'];
    }

    /* ---- Fallback: PHP mail() (localhost / SMTP not configured yet) ---- */
    $boundary = 'mh-' . bin2hex(random_bytes(12));
    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Reply-To: ' . MAIL_FROM_ADDRESS,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $bodyText . "\r\n\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $bodyHtml . "\r\n\r\n";
    $body .= "--$boundary--";
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (@mail($to, $encodedSubject, $body, implode("\r\n", $headers))) {
        return ['success' => true, 'error' => null];
    }
    $error = 'No SMTP credentials/PHPMailer found and PHP mail() failed. Configure config/mail.local.php and install PHPMailer '
           . '(see the header of includes/mailer.php), or rely on MAIL_DEBUG_MODE on localhost.';
    error_log('mh_send_email failed for ' . substr($to, 0, 1) . '***' . substr($to, (int) strrpos($to, '@')) . ': ' . $error);
    return ['success' => false, 'error' => $error];
}
}
