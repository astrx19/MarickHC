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
 * Sends one email.
 *
 * @return array{success: bool, error: string|null}
 */
function mh_send_email($to, $subject, $bodyHtml, $bodyText = null)
{
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Not a valid email address: "' . $to . '".'];
    }
    if (!defined('MAIL_FROM_ADDRESS')) {
        return ['success' => false, 'error' => 'config/mail.php was not loaded (MAIL_FROM_ADDRESS is undefined).'];
    }
    $subject = trim((string) $subject);
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
            error_log('mh_send_email SMTP failure for ' . $to . ': ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
            return ['success' => false, 'error' => 'SMTP send failed (see the server error log).'];
        }
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
    error_log('mh_send_email failed for ' . $to . ': ' . $error);
    return ['success' => false, 'error' => $error];
}
}
