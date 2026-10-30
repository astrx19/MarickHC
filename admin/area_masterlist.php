<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';

require_permission('view_area_masterlist');

// Home jurisdiction comes from System Settings (Home Address Type), never from a literal.
$home_type = mh_home_address_type();

$st = $conn->prepare("SELECT COUNT(*) AS count FROM patients WHERE status = 1 AND address_type = ?");
$st->bind_param('s', $home_type);
$st->execute();
$marick_total = (int) ($st->get_result()->fetch_assoc()['count'] ?? 0);
$st->close();

// Everyone else (including patients with no Location Type) counts as an outsider.
$st = $conn->prepare("SELECT COUNT(*) AS count FROM patients WHERE status = 1 AND (address_type IS NULL OR address_type <> ?)");
$st->bind_param('s', $home_type);
$st->execute();
$outsider_total = (int) ($st->get_result()->fetch_assoc()['count'] ?? 0);
$st->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <title>Area Masterlist - Marick Health Center</title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">

            <div class="stats-grid">
                <div class="stat-card" style="border-top-color: #4ca1af;">
                    <h3>Total <?php echo htmlspecialchars($home_type); ?> Residents</h3>
                    <div class="number"><?php echo $marick_total; ?></div>
                </div>
                <div class="stat-card" style="border-top-color: #f59e0b;">
                    <h3>Total Outsiders</h3>
                    <div class="number"><?php echo $outsider_total; ?></div>
                </div>
                <div class="stat-card" style="border-top-color: #64748b;">
                    <h3>Total Registered Patients</h3>
                    <div class="number"><?php echo ($marick_total + $outsider_total); ?></div>
                </div>
            </div>

            <div class="unified-panel">
                <div class="panel-controls">
                    <h2 style="margin:0; font-size:1.3rem; color:var(--heading-color, #1e293b);">Area Demographics</h2>
                    <input type="text" id="searchInput" class="search-box" onkeyup="filterTable()" placeholder="Search street or area...">
                </div>

                <table id="areaTable" class="unified-table">
                    <thead>
                        <tr>
                            <th>Area / Street Name</th>
                            <th>Jurisdiction</th>
                            <th>Total Registered Patients</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Residents per STREET (not per full address): parse the street out of each resident address.
                        $st = $conn->prepare("SELECT address FROM patients WHERE status = 1 AND address_type = ?");
                        $st->bind_param('s', $home_type);
                        $st->execute();
                        $res = $st->get_result();
                        $per_street = [];
                        while ($r = $res->fetch_assoc()) {
                            $parsed = mh_parse_resident_address($r['address'] ?? '');
                            $name = $parsed ? strtoupper($parsed[1]) : 'UNSPECIFIED';
                            $per_street[$name] = ($per_street[$name] ?? 0) + 1;
                        }
                        $st->close();
                        $area_rows = [];
                        foreach ($per_street as $name => $count) { $area_rows[] = ['street_name' => $name, 'resident_count' => $count]; }
                        usort($area_rows, function ($x, $y) {
                            return [$y['resident_count'], $x['street_name']] <=> [$x['resident_count'], $y['street_name']];
                        });

                        if($area_rows):
                            foreach($area_rows as $row): 
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['street_name']); ?> ST.</strong></td>
                            <td><span class="badge-marick"><?php echo htmlspecialchars(strtoupper($home_type)); ?> JURISDICTION</span></td>
                            <td style="font-size: 1.1rem; font-weight: bold; color: var(--heading-color, #1e293b);">
                                <?php echo $row['resident_count']; ?> <span style="font-size:0.85rem; color:var(--text-muted, #64748b); font-weight:normal;">patients</span>
                            </td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                            <tr><td colspan="3" style="text-align:center; padding: 30px; color:var(--text-muted, #64748b);">No areas recorded yet.</td></tr>
                        <?php endif; ?>
                        
                        <?php if($outsider_total > 0): ?>
                        <tr style="background-color: var(--bg-hover, #f8fafc);">
                            <td><strong>VARIOUS LOCATIONS</strong></td>
                            <td><span class="badge-outsider">OUTSIDER</span></td>
                            <td style="font-size: 1.1rem; font-weight: bold; color: var(--heading-color, #1e293b);">
                                <?php echo $outsider_total; ?> <span style="font-size:0.85rem; color:var(--text-muted, #64748b); font-weight:normal;">patients</span>
                            </td>
                        </tr>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function filterTable() {
            var input = document.getElementById("searchInput").value.toUpperCase();
            var tr = document.getElementById("areaTable").getElementsByTagName("tr");
            
            for (var i = 1; i < tr.length; i++) {
                var tdArea = tr[i].getElementsByTagName("td")[0];
                var tdJurisdiction = tr[i].getElementsByTagName("td")[1];
                
                if (tdArea || tdJurisdiction) {
                    var textValue = (tdArea.textContent || tdArea.innerText) + " " + (tdJurisdiction.textContent || tdJurisdiction.innerText);
                    if (textValue.toUpperCase().indexOf(input) > -1) {
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