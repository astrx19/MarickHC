<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

function out($m)  { fwrite(STDOUT, $m . PHP_EOL); }
function fail($m, $code = 1) { fwrite(STDERR, 'ERROR: ' . $m . PHP_EOL); exit($code); }

$opts = getopt('', ['username::', 'email::', 'first::', 'last::', 'generate', 'force', 'help']);
if (isset($opts['help'])) { out(trim(substr(file_get_contents(__FILE__), 0, 2000))); exit(0); }
$force    = isset($opts['force']);
$generate = isset($opts['generate']);

$res = $conn->query("SELECT COUNT(*) AS c FROM staff WHERE role = 'health_head'");
$existing = $res ? (int) $res->fetch_assoc()['c'] : 0;
if ($existing > 0 && !$force) {
    out("A health_head account already exists ($existing). Nothing to do. Use --force to add another.");
    exit(0);
}

function ask($prompt, $default = '')
{
    fwrite(STDOUT, $prompt . ($default !== '' ? " [$default]" : '') . ': ');
    $v = trim((string) fgets(STDIN));
    return $v !== '' ? $v : $default;
}
function ask_hidden($prompt)
{
    fwrite(STDOUT, $prompt . ': ');
    $win = (stripos(PHP_OS, 'WIN') === 0);
    if (!$win) { @system('stty -echo'); }
    $v = trim((string) fgets(STDIN));
    if (!$win) { @system('stty echo'); }
    fwrite(STDOUT, PHP_EOL);
    return $v;
}
function password_problem($p)
{
    if (strlen($p) < 12)               { return 'at least 12 characters'; }
    if (!preg_match('/[a-z]/', $p))    { return 'a lowercase letter'; }
    if (!preg_match('/[A-Z]/', $p))    { return 'an uppercase letter'; }
    if (!preg_match('/\d/', $p))       { return 'a digit'; }
    if (!preg_match('/[^A-Za-z0-9]/', $p)) { return 'a symbol'; }
    return null;
}
function generate_password($len = 20)
{
    $sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%^&*-_=+?'];
    $all  = implode('', $sets);
    $pw   = '';
    foreach ($sets as $s) { $pw .= $s[random_int(0, strlen($s) - 1)]; }
    while (strlen($pw) < $len) { $pw .= $all[random_int(0, strlen($all) - 1)]; }
    $chars = str_split($pw);
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $t = $chars[$i]; $chars[$i] = $chars[$j]; $chars[$j] = $t;
    }
    return implode('', $chars);
}

$first    = $opts['first']    ?? ask('First name', 'Super');
$last     = $opts['last']     ?? ask('Last name', 'Admin');
$email    = $opts['email']    ?? ask('Email');
$username = $opts['username'] ?? ask('Username');

if (!filter_var($email, FILTER_VALIDATE_EMAIL))        { fail('Invalid email address.'); }
if (!preg_match('/^[A-Za-z0-9_.\-]{4,50}$/', $username)) { fail('Username must be 4-50 chars: letters, digits, dot, dash, underscore.'); }
if (in_array(strtolower($username), ['admin', 'administrator', 'root', 'superadmin', 'healthhead', 'test'], true)) {
    fail('Choose a non-default username (not "admin", "root", ...).');
}

$mustChange = 0;
$envPw = getenv('MH_ADMIN_PASSWORD');
if ($generate) {
    $password   = generate_password();
    $mustChange = 1;
} elseif ($envPw !== false && $envPw !== '') {
    $password = $envPw;
} else {
    $password = ask_hidden('Password (12+ chars, mixed case, digit, symbol)');
    $confirm  = ask_hidden('Confirm password');
    if (!hash_equals($password, $confirm)) { fail('Passwords do not match.'); }
}
if (($problem = password_problem($password)) !== null) { fail('Password needs ' . $problem . '.'); }

$chk = $conn->prepare('SELECT id FROM staff WHERE email = ? OR username = ? LIMIT 1');
$chk->bind_param('ss', $email, $username);
$chk->execute(); $chk->store_result();
if ($chk->num_rows > 0) { fail('A staff account with that email or username already exists.'); }
$chk->close();

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
if ($hash === false) { fail('Password hashing failed.'); }
$oneTimePassword = $generate ? $password : null;
unset($password, $confirm, $envPw);

$role    = 'health_head';
$contact = '';
$stmt = $conn->prepare(
    'INSERT INTO staff (first_name, last_name, contact_number, email, username, password, role, is_first_login)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) { fail('Database error preparing insert (is the schema installed?).'); }
$stmt->bind_param('sssssssi', $first, $last, $contact, $email, $username, $hash, $role, $mustChange);
if (!$stmt->execute()) { fail('Insert failed: ' . ($stmt->errno === 1062 ? 'duplicate email or username.' : 'database error.')); }
$newId = (int) $stmt->insert_id;
$stmt->close();

logActivity($conn, $newId, $role, 'Health Head Provisioned', 'Created via bin/create_admin.php (CLI).');

out('');
out("Health Head created: username '$username' (id $newId).");
if ($generate) {
    out('');
    out('One-time password (store it in a password manager now, it is NOT saved anywhere):');
    out('  ' . $oneTimePassword);
}
out($mustChange ? 'You will be required to choose a new password at first login.' : 'You can log in now.');
exit(0);
