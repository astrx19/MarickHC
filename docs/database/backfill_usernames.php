<?php
// CLI ONLY - never executable through a web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * =====================================================================
 * database/backfill_usernames.php
 * =====================================================================
 * ONE-TIME MIGRATION. Run this once, right after applying
 * database/auth_schema.sql, and before anyone tries to log in again.
 *
 * WHY THIS IS A PHP SCRIPT AND NOT PLAIN SQL
 * -------------------------------------------
 * Every existing staff/patient row has a NULL username the moment the
 * ALTER TABLE runs (username login didn't exist before). Deriving a
 * *unique* username from each person's email/name and resolving
 * collisions (two "Juan Dela Cruz"s, etc.) needs a loop and a bit of
 * logic that's awkward to express as a single UPDATE - hence PHP.
 *
 * WHAT IT DOES
 * ------------
 * For every row in `staff` and `patients` where username IS NULL:
 *   1. Uses the part of their email before the "@" as a starting point
 *      (falls back to first-initial+lastname if the email is unusable).
 *   2. Strips it down to [a-z0-9._-], lowercase.
 *   3. If that's already taken (in EITHER table, since login should be
 *      unambiguous), appends 1, 2, 3... until it isn't.
 *   4. Saves it.
 *
 * Nothing here touches passwords or any other column. Safe to re-run -
 * rows that already have a username are skipped.
 *
 * RUN IT
 * ------
 *   CLI:      php database/backfill_usernames.php
 *   Browser:  http://localhost/marick-health-system-v2/database/backfill_usernames.php
 *             (guarded the same way as cron/send_daily_reminders.php -
 *             either a logged-in admin session, or ?key=... matching
 *             CRON_RUN_KEY from config/semaphore.php, reused here rather
 *             than inventing a second shared secret.)
 * ================================================================== */

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }
error_reporting(E_ALL);
ini_set('display_errors', $isCli ? 1 : 0);

require_once __DIR__ . '/../config/database.php';

if (!$isCli) {
    $authorized = false;
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!empty($_SESSION['user_id'])) { $authorized = true; }

    if (!$authorized && is_readable(__DIR__ . '/../config/semaphore.php')) {
        require_once __DIR__ . '/../config/semaphore.php';
        $suppliedKey = $_GET['key'] ?? '';
        if ($suppliedKey !== '' && defined('CRON_RUN_KEY') && hash_equals(CRON_RUN_KEY, $suppliedKey)) {
            $authorized = true;
        }
    }

    if (!$authorized) {
        http_response_code(403);
        echo "Forbidden. Run this from the CLI, while logged in as admin, or with ?key=...\n";
        exit;
    }
}

/**
 * Turns an email/name into a lowercase [a-z0-9._-] slug, non-empty.
 */
function mh_slugify_username($raw)
{
    $slug = strtolower(trim((string) $raw));
    $slug = preg_replace('/[^a-z0-9._-]/', '', $slug);
    $slug = trim($slug, '._-');
    if ($slug === '') { $slug = 'user'; }
    return substr($slug, 0, 40); // leave room for a numeric suffix under the 50-char column
}

/**
 * True if $username is not already used by anyone in either table
 * (optionally excluding one row, so re-checking a candidate mid-loop works).
 */
function mh_username_taken(mysqli $conn, $username)
{
    foreach (['staff', 'patients'] as $table) {
        $stmt = $conn->prepare("SELECT id FROM `$table` WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($found) { return true; }
    }
    return false;
}

function mh_next_available_username(mysqli $conn, $base)
{
    $candidate = $base;
    $suffix = 1;
    while (mh_username_taken($conn, $candidate)) {
        $candidate = $base . $suffix;
        $suffix++;
    }
    return $candidate;
}

$totalUpdated = 0;

foreach (['staff', 'patients'] as $table) {
    echo "== $table ==\n";
    $res = $conn->query("SELECT id, first_name, last_name, email FROM `$table` WHERE username IS NULL OR username = ''");
    if (!$res) {
        echo "  Query failed: " . $conn->error . "\n";
        continue;
    }

    while ($row = $res->fetch_assoc()) {
        $emailLocalPart = '';
        if (!empty($row['email']) && strpos($row['email'], '@') !== false) {
            $emailLocalPart = explode('@', $row['email'])[0];
        }
        $base = $emailLocalPart !== ''
            ? mh_slugify_username($emailLocalPart)
            : mh_slugify_username(substr($row['first_name'] ?? '', 0, 1) . ($row['last_name'] ?? ''));

        $username = mh_next_available_username($conn, $base);

        $upd = $conn->prepare("UPDATE `$table` SET username = ? WHERE id = ?");
        $upd->bind_param('si', $username, $row['id']);
        if ($upd->execute()) {
            echo "  #{$row['id']} ({$row['email']}) -> $username\n";
            $totalUpdated++;
        } else {
            echo "  #{$row['id']} FAILED: " . $conn->error . "\n";
        }
        $upd->close();
    }
}

echo "\nDone. $totalUpdated account(s) given a username.\n";
if ($totalUpdated > 0) {
    echo "Tell affected staff/patients their new username before they next try to log in\n"
       . "(it's shown above, and it's also visible to admins in Staff Management / Patient Masterlist).\n";
}
