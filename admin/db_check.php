<?php
/**
 * Admin-only diagnostic: which database driver is in use and do the Step 2 queries work?
 * Open /admin/db_check.php while logged in as Health Head / Admin. Safe to delete afterwards.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';

if (!function_exists('mh_is_admin_tier') || !mh_is_admin_tier($_SESSION['role'] ?? '')) {
    http_response_code(403);
    exit('Admins only.');
}

$d = mh_db_driver();
$checks = [
    'Active patients'              => mh_db_value('SELECT COUNT(*) FROM patients WHERE status = 1', [], 'ERROR'),
    'Inventory rows'               => mh_db_value('SELECT COUNT(*) FROM inventory', [], 'ERROR'),
    'Dental records'               => mh_db_value('SELECT COUNT(*) FROM dental_records', [], 'ERROR'),
    "maternal_records.next_visit_date exists" => mh_db_table_has_column('maternal_records', 'next_visit_date') ? 'yes' : 'NO',
    "child_immunization_records.next_visit_date exists" => mh_db_table_has_column('child_immunization_records', 'next_visit_date') ? 'yes' : 'NO',
];
function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Database check</title>
<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;color:#1e293b}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #cbd5e1;padding:8px 12px;text-align:left}
.ok{color:#047857;font-weight:700}.bad{color:#b91c1c;font-weight:700}code{background:#f1f5f9;padding:2px 6px;border-radius:4px}</style></head>
<body>
<h1>Database check</h1>
<p>Driver in use: <strong class="<?php echo strpos($d['driver'], 'PDO (') === 0 ? 'ok' : 'bad'; ?>"><?php echo h($d['driver']); ?></strong></p>
<?php if ($d['error']): ?><p>Why PDO was not used: <code><?php echo h($d['error']); ?></code></p>
<p>The pages still work through the mysqli fallback. To use PDO, fix the reason above (usually the MySQL socket path or the pdo_mysql extension).</p><?php endif; ?>
<table><tr><th>Check</th><th>Result</th></tr>
<?php foreach ($checks as $label => $val): ?>
<tr><td><?php echo h($label); ?></td><td class="<?php echo ($val === 'ERROR' || $val === 'NO') ? 'bad' : 'ok'; ?>"><?php echo h($val); ?></td></tr>
<?php endforeach; ?>
</table>
<p>Any <span class="bad">ERROR</span> or <span class="bad">NO</span> means that table or column is missing from your database (not a code problem) - see <code>database/</code> for the matching migration.</p>
</body></html>
