<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php'; 

require_permission('view_system_logs'); 

$system_name = $system_name ?? mh_setting('system_name');

$logs_query = $conn->query("
    SELECT l.*, s.first_name, s.last_name, s.role 
    FROM activity_logs l 
    LEFT JOIN staff s ON l.user_id = s.id 
    ORDER BY l.created_at DESC
");
$actions_query = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");
$unique_actions = [];
if($actions_query) {
    while($row = $actions_query->fetch_assoc()) 
        $unique_actions[] = $row['action'];
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
            border: 1px solid;
            margin-top: 4px;
        }
        .role-admin { background: #e0f2fe; color: #0284c7; border-color: #7dd3fc; }
        .role-doctor { background: #d1fae5; color: #059669; border-color: #6ee7b7; }
        .role-midwife { background: #fce7f3; color: #db2777; border-color: #fbcfe8; }
        .role-dental { background: #fef3c7; color: #d97706; border-color: #fde68a; }
        .role-bhw { background: #f1f5f9; color: var(--text-main, #334155); border-color: var(--border-color, #cbd5e1); }
        .role-inventory { background: #ffedd5; color: #c2410c; border-color: #fed7aa; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <div class="unified-panel">
                <div class="panel-controls" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <h3 style="margin:0; font-size: 1.2rem; color: var(--heading-color, #1e293b);"> Audit Trail</h3>
                    
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="actionFilter" class="search-box" onchange="filterLogs()" style="width: auto; cursor: pointer; padding: 10px 15px; background: var(--bg-hover, #f8fafc);">
                            <option value="All">All Actions</option>
                            <?php foreach($unique_actions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action); ?>"><?php echo htmlspecialchars($action); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" id="searchLog" class="search-box" placeholder=" Search logs..." onkeyup="filterLogs()" style="width: 250px;">
                    </div>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="unified-table" id="logTable">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($logs_query && $logs_query->num_rows > 0): ?>
                                <?php while($row = $logs_query->fetch_assoc()): 
                                    $role_class = "role-" . strtolower($row['role'] ?? 'bhw');
                                    $user_name = $row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'System / Unknown';
                                ?>
                                <tr>
                                    <td style="color: var(--text-muted, #64748b); font-size: 0.9rem; white-space: nowrap;">
                                        <?php echo date("M d, Y - h:i A", strtotime($row['created_at'])); ?>
                                    </td>
                                    <td>
                                        <strong style="color: var(--heading-color, #1e293b); display: block;"><?php echo htmlspecialchars($user_name); ?></strong>
                                        <span class="role-badge <?php echo $role_class; ?>"><?php echo htmlspecialchars($row['role'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td>
                                        <strong style="color: var(--heading-color, #1e293b);"><?php echo htmlspecialchars($row['action']); ?></strong>
                                    </td>
                                    <td style="color: var(--text-main, #334155); line-height: 1.5;">
                                        <?php echo htmlspecialchars($row['details']); ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align:center; padding: 40px; color: var(--text-muted, #64748b);">No system logs found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        function filterLogs() {
            var searchInput = document.getElementById("searchLog").value.toUpperCase();
            var actionFilter = document.getElementById("actionFilter").value.toUpperCase();
            var tr = document.getElementById("logTable").getElementsByTagName("tr");

            for (var i = 1; i < tr.length; i++) {
                var tdUser = tr[i].getElementsByTagName("td")[1];
                var tdAction = tr[i].getElementsByTagName("td")[2];
                var tdDetails = tr[i].getElementsByTagName("td")[3];

                if (tdUser && tdAction && tdDetails) {
                    var txtUser = tdUser.textContent || tdUser.innerText;
                    var txtAction = tdAction.textContent || tdAction.innerText;
                    var txtDetails = tdDetails.textContent || tdDetails.innerText;

                    var matchesSearch = (txtUser.toUpperCase().indexOf(searchInput) > -1 || 
                                         txtAction.toUpperCase().indexOf(searchInput) > -1 || 
                                         txtDetails.toUpperCase().indexOf(searchInput) > -1);
                    
                    var matchesAction = (actionFilter === "ALL" || txtAction.toUpperCase().trim() === actionFilter);

                    if (matchesSearch && matchesAction) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                        
                    }
                }
            }
        }
    </script>
</body>
</html>
