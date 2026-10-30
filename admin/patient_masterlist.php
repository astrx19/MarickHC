<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php'; 
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/settings_helper.php';   // provides mh_check_module_enabled()
mh_check_module_enabled('patient_masterlist');   // module master switch
require_permission('view_patient_masterlist');   // page load
$can_edit_patient = mh_has_permission('edit_patient_profile');

$system_name = $system_name ?? mh_setting('system_name');

require_once __DIR__ . '/../includes/settings_helper.php';
$marick_streets = mh_streets($conn);   // Streets / Areas / Purok masterlist (database)

$success_msg = "";
$error_msg = "";

if ((isset($_POST['archive_patient']) || isset($_POST['update_patient'])) && !$can_edit_patient) {
    mh_deny_access();   // form submit level
}

if(isset($_POST['archive_patient'])) {
    $patient_id = intval($_POST['patient_id']);
    
    $stmt = $conn->prepare("UPDATE patients SET status = 0 WHERE id = ?");
    $stmt->bind_param('i', $patient_id);
    $ok = $stmt->execute();
    $stmt->close();
    
    if($ok) {
        $success_msg = "Patient successfully moved to Archives!";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Archived Patient", "Moved Patient ID $patient_id to the archives.");
    } else {
        $error_msg = "Error archiving patient: " . $conn->error;
    }
}

if(isset($_POST['update_patient'])) {
    $edit_id = intval($_POST['edit_id']);
    $first_name = trim((string) ($_POST['first_name'] ?? ''));
    $last_name  = trim((string) ($_POST['last_name'] ?? ''));
    $dob        = (string) ($_POST['birthdate'] ?? ($_POST['date_of_birth'] ?? ''));
    $contact    = trim((string) ($_POST['contact_number'] ?? ''));
    $email      = trim((string) ($_POST['email'] ?? ''));
    $address    = trim((string) ($_POST['address'] ?? ''));

    $stmt = $conn->prepare("UPDATE patients SET first_name = ?, last_name = ?, birthdate = ?,
                                   contact_number = ?, email = ?, address = ? WHERE id = ?");
    $stmt->bind_param('ssssssi', $first_name, $last_name, $dob, $contact, $email, $address, $edit_id);
    $ok = $stmt->execute();
    $stmt->close();

    if($ok) {
        $success_msg = "Patient information updated successfully!";
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Updated Patient", "Edited details for Patient ID $edit_id.");
    } else {
        $error_msg = "Error updating patient: " . $conn->error;
    }
}

$query = "SELECT * FROM patients WHERE status = 1 ORDER BY last_name ASC";
$patients_result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Masterlist - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    
    
    <style>
        /* Page-specific only — shared table/panel/badge/modal/button styling
           now comes from admin_theme.css (see .unified-panel, .unified-table,
           .badge, .filter-toolbar, .modal-overlay, .btn-outline-*). */
        .action-group { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .patient-name { font-weight: bold; color: var(--heading-color, #1e293b); font-size: 1.02rem; }

        .btn-walkin {
            background: #0ea5e9; color: white; border: none; padding: 10px 20px;
            border-radius: 25px; font-weight: 600; cursor: pointer; transition: 0.2s;
            text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 6px rgba(14, 165, 233, 0.25);
        }
        .btn-walkin:hover { background: #0284c7; transform: translateY(-2px); box-shadow: 0 6px 12px rgba(14, 165, 233, 0.3); }

        .btn-save { background: #10b981; color: white; border: none; padding: 12px 25px; border-radius: 20px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: 0.2s; width: 100%; margin-top: 10px; box-shadow: 0 4px 6px rgba(16,185,129,0.2); }
        .btn-save:hover { background: #059669; transform: translateY(-2px); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="masterlist-container">

            <div class="unified-panel">
                <div class="panel-controls">
                    <h3 style="margin:0; font-size: 1.2rem; color: var(--heading-color, #1e293b);">Masterlist Directory</h3>
                    
                    <div class="filter-toolbar">
                        <select id="areaFilter" onchange="filterTable()">
                            <option value="">All Locations</option>
                            <?php 
                            if(!empty($marick_streets)) {
                                foreach($marick_streets as $street) {
                                    $filter_value = strtoupper($street) . " ST"; 
                                    echo "<option value=\"" . htmlspecialchars($filter_value) . "\">" . htmlspecialchars($street) . " St.</option>";
                                }
                            }
                            ?>
                        </select>
                        
                        <input type="text" id="searchInput" class="search-box" placeholder="Search patient by name..." onkeyup="filterTable()">
                        
                        <a href="walkin.php" class="btn-walkin"><?php echo mh_icon('plus'); ?> Walk-In Registration</a>
                    </div>
                </div>
                
                <div class="table-scroll">
                <table class="unified-table" id="patientsTable">
                    <thead>
                        <tr>
                            <th>Patient Name</th>
                            <th>Age</th>
                            <th>Contact No.</th>
                            <th>Address Area</th>
                            <th>Registered On</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($patients_result && $patients_result->num_rows > 0): ?>
                            <?php while($row = $patients_result->fetch_assoc()): 
                                $age = "N/A";
                                if(!empty($row['date_of_birth']) || !empty($row['birthdate'])) {
                                    $dob_val = !empty($row['birthdate']) ? $row['birthdate'] : $row['date_of_birth'];
                                    $dob = new DateTime($dob_val);
                                    $now = new DateTime();
                                    $age = $now->diff($dob)->y . " yrs";
                                }

                                $raw_address = $row['address'];
                                
                                if (mh_is_marick_resident($row['address_type'] ?? '')) {   // home jurisdiction from System Settings
                                    $parts = explode(',', $raw_address);
                                    $display_address = trim($parts[0]); 
                                    $badge_class = "badge-marick"; 
                                } else {
                                    $display_address = $raw_address;
                                    $badge_class = "badge-outsider"; 
                                }
                            ?>
                                <tr>
                                    <td class="patient-name"><span style="display:inline-flex; align-items:center; gap:10px;"><?php echo mh_avatar_html(mh_initials($row['first_name'], $row['last_name']), 32, $row['first_name'] . ' ' . $row['last_name']); ?><span><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></span></span></td>
                                    <td><?php echo $age; ?></td>
                                    <td><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></td>
                                    
                                    <td>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo htmlspecialchars($display_address); ?>
                                        </span>
                                    </td>
                                    
                                    <td><?php echo date("M d, Y", strtotime($row['created_at'])); ?></td>
                                    
                                    <td style="text-align: center;">
                                        <div class="row-actions" style="justify-content: center;">
                                        <a href="view_patient.php?id=<?php echo $row['id']; ?>" class="btn-outline-blue">View Profile</a>
                                        
                                        <?php if ($can_edit_patient): ?>
                                        <button type="button" class="btn-outline-green" 
                                            data-id="<?php echo $row['id']; ?>"
                                            data-fname="<?php echo htmlspecialchars($row['first_name']); ?>"
                                            data-lname="<?php echo htmlspecialchars($row['last_name']); ?>"
                                            data-bdate="<?php echo htmlspecialchars($row['birthdate'] ?? $row['date_of_birth'] ?? ''); ?>"
                                            data-contact="<?php echo htmlspecialchars($row['contact_number'] ?? ''); ?>"
                                            data-email="<?php echo htmlspecialchars($row['email'] ?? ''); ?>"
                                            data-address="<?php echo htmlspecialchars($row['address'] ?? ''); ?>"
                                            onclick="openEditModal(this)">Edit</button>

                                        <form id="archiveForm_<?php echo $row['id']; ?>" method="POST" style="margin:0;">
                                            <input type="hidden" name="patient_id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="archive_patient" value="1">
                                            <button type="button" class="btn-outline-red" onclick="confirmArchive(<?php echo $row['id']; ?>)">Archive</button>
                                        </form>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-state"><td colspan="6"><em>No active patients found in the masterlist.</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

        </div>
    </div>

    <div class="modal-overlay" id="editModal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('editModal')">&times;</span>
            <h3 style="margin:0; color: var(--heading-color, #1e293b); font-size: 1.4rem; display:flex; align-items:center; gap:8px;"><?php echo mh_icon('edit'); ?> Edit Patient Information</h3>
            <hr style="border:0; border-top: 1px solid var(--border-color, #cbd5e1); margin-bottom: 25px;">
            
            <form method="POST" action="">
                <input type="hidden" name="edit_id" id="edit_id">
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" id="edit_fname" required 
                               pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" 
                               oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')">
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" id="edit_lname" required 
                               pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" 
                               oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="birthdate" id="edit_bdate" required max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="tel" name="contact_number" id="edit_contact" required maxlength="13" pattern="^\+63[0-9]{10}$" 
                               title="Must start with +63 followed by exactly 10 digits" 
                               oninput="if(!this.value.startsWith('+63')){ this.value='+63'; } this.value = '+63' + this.value.substring(3).replace(/[^0-9]/g, '');">
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="edit_email">
                </div>

                <div class="form-group">
                    <label>Address / Location Details</label>
                    <input type="text" name="address" id="edit_address" required>
                </div>

                <div style="text-align: right;">
                    <button type="submit" name="update_patient" data-confirm="Save changes to this patient record?" data-confirm-title="Save changes?" data-confirm-type="warning" data-confirm-ok="Save" class="btn-save"><?php echo mh_icon('save'); ?> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.onload = function() {
            <?php if($success_msg): ?>
                mh_notify(<?php echo mh_js($success_msg); ?>, 'success');
                window.history.replaceState(null, null, window.location.pathname);
            <?php endif; ?>

            <?php if($error_msg): ?>
                mh_notify(<?php echo mh_js($error_msg); ?>, 'error');
            <?php endif; ?>
        };

        async function confirmArchive(patientId) {
            const ok = await mh_confirm(
                'They will be removed from this list but saved in System Archives.',
                'Archive this patient?', 'danger', 'Yes, Archive'
            );
            if (ok) {
                document.getElementById('archiveForm_' + patientId).submit();
            }
        }

        function openEditModal(button) {
            document.getElementById('edit_id').value = button.getAttribute('data-id');
            document.getElementById('edit_fname').value = button.getAttribute('data-fname');
            document.getElementById('edit_lname').value = button.getAttribute('data-lname');
            document.getElementById('edit_bdate').value = button.getAttribute('data-bdate');
            document.getElementById('edit_contact').value = button.getAttribute('data-contact');
            document.getElementById('edit_email').value = button.getAttribute('data-email');
            document.getElementById('edit_address').value = button.getAttribute('data-address');
            
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function filterTable() {
            var searchInput = document.getElementById("searchInput").value.toUpperCase();
            var areaFilter = document.getElementById("areaFilter").value.toUpperCase();
            var tr = document.getElementById("patientsTable").getElementsByTagName("tr");
            
            for (var i = 1; i < tr.length; i++) {
                var nameCol = tr[i].getElementsByTagName("td")[0];
                var areaCol = tr[i].getElementsByTagName("td")[3];
                
                if (nameCol && areaCol) {
                    var nameText = nameCol.textContent || nameCol.innerText;
                    var areaText = areaCol.textContent || areaCol.innerText;
                    
                    var matchesSearch = nameText.toUpperCase().indexOf(searchInput) > -1;
                    var matchesArea = areaFilter === "" || areaText.toUpperCase().indexOf(areaFilter) > -1;
                    
                    if (matchesSearch && matchesArea) {
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