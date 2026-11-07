<?php
/**
 * Email Check (admin only): is the system able to send email, and if not, exactly why?
 * Open /admin/email_check.php as Health Head / Admin, type any address (e.g. your own), press "Send test email".
 * Shows the SMTP conversation with the password removed. Safe to leave installed; it never prints secrets.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';   // config/mail.php needs mh_get_option() for the sender name
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!function_exists('mh_is_admin_tier') || !mh_is_admin_tier($_SESSION['role'] ?? '')) {
    http_response_code(403);
    exit('Admins only.');
}

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$result = null; $sent_to = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    if (!mh_csrf_check()) {
        $result = ['success' => false, 'error' => 'Your session security token expired. Reload the page and try again.', 'method' => '', 'transcript' => []];
    } else {
        $sent_to = trim((string) ($_POST['to'] ?? ''));
        $result = mh_send_email_test($sent_to);
        if (function_exists('logActivity')) {
            logActivity($conn, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? ''), 'Email Test', 'Sent a test email (' . ($result['success'] ? 'accepted by the mail server' : 'failed') . ').');
        }
    }
}
$st = mh_mailer_status();
$ok = function ($b) { return $b ? '<span class="ok">OK</span>' : '<span class="bad">Missing</span>'; };
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Email check</title>
<style>
body{font-family:system-ui,sans-serif;max-width:860px;margin:32px auto;padding:0 16px;color:#1e293b;line-height:1.5}
table{border-collapse:collapse;width:100%;margin:10px 0 18px}td,th{border:1px solid #cbd5e1;padding:8px 12px;text-align:left;vertical-align:top}th{background:#f1f5f9;width:34%}
.ok{color:#047857;font-weight:700}.bad{color:#b91c1c;font-weight:700}code,pre{background:#f1f5f9;border-radius:6px}code{padding:2px 6px}
pre{padding:12px;overflow:auto;font-size:.82rem}.box{border:1px solid #cbd5e1;border-radius:12px;padding:14px 18px;margin:16px 0}
.good{background:#ecfdf5;border-color:#a7f3d0}.warn{background:#fffbeb;border-color:#fde68a}.err{background:#fef2f2;border-color:#fecaca}
input[type=email]{padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;width:min(360px,100%)}button{padding:9px 18px;border:0;border-radius:999px;background:#4ca1af;color:#fff;font-weight:700;cursor:pointer}
</style></head><body>
<h1>Email check</h1>
<p>Password-reset links and codes are sent by email. This page shows whether sending is set up, and sends a real test message.</p>

<h2>Current setup</h2>
<table>
<tr><th>How email is sent</th><td><strong><?php echo $h($st['method']); ?></strong></td></tr>
<tr><th>Login file <code>config/mail.local.php</code></th><td><?php echo $ok($st['local_file']); ?><?php echo $st['local_file'] ? '' : ' &mdash; this is where the mailbox email and password go (see the steps below)'; ?></td></tr>
<tr><th>SMTP host / port / mode</th><td><?php echo $st['host'] !== '' ? $h($st['host'] . ' : ' . $st['port'] . ' (' . $st['secure'] . ')') : '<span class="bad">not set</span>'; ?></td></tr>
<tr><th>Mailbox login (user)</th><td><?php echo $st['user'] !== '' ? $h($st['user']) : '<span class="bad">not set</span>'; ?> &nbsp; password: <?php echo $st['pass_set'] ? '<span class="ok">set</span>' : '<span class="bad">not set</span>'; ?></td></tr>
<tr><th>Sends "From"</th><td><?php echo $h($st['from']); ?>
    <?php if ($st['from_local']): ?><br><span class="bad">This is a placeholder address. Real email servers reject it.</span><?php elseif ($st['smtp_configured'] && !$st['from_ok']): ?><br><span class="bad">It should be exactly the same address as the mailbox login.</span><?php endif; ?></td></tr>
<tr><th>PHPMailer library</th><td><?php echo $st['phpmailer'] ? '<span class="ok">installed</span>' : 'not installed &mdash; fine, the built-in sender is used instead'; ?></td></tr>
</table>

<?php if (!$st['smtp_configured']): ?>
<div class="box warn"><strong>Email is not set up yet.</strong> Do this once:
<ol>
<li>In Hostinger <em>hPanel &rarr; Emails</em>, create a mailbox such as <code>no-reply@marickhealthcenter.com</code> and note its password.</li>
<li>In <em>File Manager</em>, open the project's <code>config</code> folder, copy <code>mail.local.php.example</code> to a new file named <code>mail.local.php</code>, and fill it in:
<pre>return [
    'smtp_host'    =&gt; 'smtp.hostinger.com',
    'smtp_port'    =&gt; 465,
    'smtp_secure'  =&gt; 'ssl',
    'smtp_user'    =&gt; 'no-reply@marickhealthcenter.com',
    'smtp_pass'    =&gt; 'THE MAILBOX PASSWORD',
    'from_address' =&gt; 'no-reply@marickhealthcenter.com',
    'from_name'    =&gt; 'Marick Health Center',
];</pre></li>
<li>Come back here and press <strong>Send test email</strong> with your own address.</li>
</ol>
<small>Using Gmail instead? <code>smtp.gmail.com</code>, port 465, <code>ssl</code>, your Gmail address as both user and from_address, and a Google <em>App Password</em> (Google Account &rarr; Security &rarr; 2-Step Verification &rarr; App passwords) as the password.</small></div>
<?php endif; ?>

<h2>Send a test email</h2>
<form method="POST" class="box">
    <?php echo mh_csrf_field(); ?>
    <label for="to"><strong>Send to</strong> (your own address, e.g. your Gmail):</label><br>
    <input type="email" id="to" name="to" required pattern="^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$" title="Email must end strictly with .com (e.g. you@gmail.com)" value="<?php echo $h($sent_to); ?>" placeholder="you@gmail.com">
    <button type="submit" name="send_test" value="1">Send test email</button>
</form>

<?php if ($result): ?>
<div class="box <?php echo $result['success'] ? 'good' : 'err'; ?>">
    <?php if ($result['success']): ?>
        <strong class="ok">The mail server accepted the message</strong> (<?php echo $h($result['method']); ?>). Check the inbox of <?php echo $h($sent_to); ?> &mdash; <strong>and the Spam / Junk folder</strong>, the first message from a new sender is often filed there. Mark it &ldquo;Not spam&rdquo;.
    <?php else: ?>
        <strong class="bad">It did not send.</strong><br><?php echo $h($result['error']); ?>
    <?php endif; ?>
    <?php if (!empty($result['transcript'])): ?><details style="margin-top:10px;"><summary>What happened (technical details, no password)</summary><pre><?php echo $h(implode("\n", $result['transcript'])); ?></pre></details><?php endif; ?>
</div>
<?php endif; ?>
<p><small>Delete this page when you are done if you prefer: it is only for setup and troubleshooting.</small></p>
</body></html>
