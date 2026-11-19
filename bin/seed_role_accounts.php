<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/roles.php';

mh_roles_upgrade_health_head($conn);

$accounts = [
    ['Health',  'Head',      'health_head', 'healthhead',   'healthhead@marick.gov.ph', 'HealthHead@2026'],
    ['Encoder', 'Account',   'inventory',   'encoder1',     'encoder1@marick.gov.ph',   'Encoder@2026'],
    ['BHW',     'Account',   'bhw',         'bhw1',         'bhw1@marick.gov.ph',       'Bhw@2026'],
];

$exists = $conn->prepare("SELECT id FROM staff WHERE username = ? OR email = ? LIMIT 1");
$ins = $conn->prepare("INSERT INTO staff (first_name, middle_name, last_name, email, username, password, role, is_first_login)
                       VALUES (?, '', ?, ?, ?, ?, ?, 1)");

foreach ($accounts as [$first, $last, $role, $user, $email, $plain]) {
    $exists->bind_param('ss', $user, $email);
    $exists->execute();
    if ($exists->get_result()->num_rows > 0) { echo "skip   $user (already exists)\n"; continue; }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $ins->bind_param('ssssss', $first, $last, $email, $user, $hash, $role);
    echo $ins->execute() ? "created $user  [$role]  temp password: $plain\n" : "FAILED $user: " . $conn->error . "\n";
}
echo "\nDone. Every account must change its password at first login.\n";