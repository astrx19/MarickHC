<?php
if (!defined('MH_LOGIN_THROTTLE_LOADED')) {
define('MH_LOGIN_THROTTLE_LOADED', true);

define('MH_LT_WINDOW_SECONDS', 900);
define('MH_LT_MAX_PER_ACCOUNT', 5);
define('MH_LT_MAX_PER_IP', 20);

function mh_lt_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function mh_lt_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `scope` VARCHAR(10) NOT NULL,
            `identifier` VARCHAR(100) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_acct_time` (`scope`, `identifier`, `attempted_at`),
            KEY `idx_ip_time` (`ip_address`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) { error_log('mh_lt_ready: ' . $e->getMessage()); $ready = false; }
    return $ready;
}

function mh_lt_norm(string $username): string
{
    return mb_substr(mb_strtolower(trim($username)), 0, 100);
}

function mh_lt_blocked_seconds(mysqli $conn, string $scope, string $username): int
{
    if (!mh_lt_ready($conn)) { return 0; }
    try {
        $id = mh_lt_norm($username); $ip = mh_lt_ip(); $win = MH_LT_WINDOW_SECONDS;
        $st = $conn->prepare("SELECT
                SUM(scope = ? AND identifier = ?) AS acct_n,
                MIN(CASE WHEN scope = ? AND identifier = ? THEN attempted_at END) AS acct_first,
                SUM(ip_address = ?) AS ip_n,
                MIN(CASE WHEN ip_address = ? THEN attempted_at END) AS ip_first,
                NOW() AS now_ts
              FROM login_attempts
             WHERE attempted_at > (NOW() - INTERVAL $win SECOND)");
        if (!$st) { return 0; }
        $st->bind_param('ssssss', $scope, $id, $scope, $id, $ip, $ip);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        $wait = 0;
        $now = strtotime((string) $r['now_ts']);
        if ((int) $r['acct_n'] >= MH_LT_MAX_PER_ACCOUNT && $r['acct_first']) {
            $wait = max($wait, $win - ($now - strtotime((string) $r['acct_first'])));
        }
        if ((int) $r['ip_n'] >= MH_LT_MAX_PER_IP && $r['ip_first']) {
            $wait = max($wait, $win - ($now - strtotime((string) $r['ip_first'])));
        }
        return (int) max(0, $wait);
    } catch (Throwable $e) { error_log('mh_lt_blocked_seconds: ' . $e->getMessage()); return 0; }
}

function mh_lt_record_failure(mysqli $conn, string $scope, string $username): void
{
    if (!mh_lt_ready($conn)) { return; }
    try {
        $id = mh_lt_norm($username); $ip = mh_lt_ip();
        $st = $conn->prepare("INSERT INTO login_attempts (scope, identifier, ip_address) VALUES (?, ?, ?)");
        if ($st) { $st->bind_param('sss', $scope, $id, $ip); $st->execute(); $st->close(); }
        if (random_int(1, 50) === 1) {
            $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (Throwable $e) { error_log('mh_lt_record_failure: ' . $e->getMessage()); }
}

function mh_lt_clear(mysqli $conn, string $scope, string $username): void
{
    if (!mh_lt_ready($conn)) { return; }
    try {
        $id = mh_lt_norm($username);
        $st = $conn->prepare("DELETE FROM login_attempts WHERE scope = ? AND identifier = ?");
        if ($st) { $st->bind_param('ss', $scope, $id); $st->execute(); $st->close(); }
    } catch (Throwable $e) { error_log('mh_lt_clear: ' . $e->getMessage()); }
}

function mh_lt_message(int $seconds): string
{
    $m = max(1, (int) ceil($seconds / 60));
    return 'Too many failed sign-in attempts. Please wait about ' . $m . ' minute' . ($m === 1 ? '' : 's') . ' and try again.';
}
}
