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
    $contact_raw = trim((string) ($_POST['contact_number'] ?? ''));
    $contact    = function_exists('mh_normalize_ph_mobile') ? mh_normalize_ph_mobile($contact_raw) : $contact_raw;
    $email      = strtolower(trim((string) ($_POST['email'] ?? '')));
    $address    = trim((string) ($_POST['address'] ?? ''));

    if ($contact === '') {
        $error_msg = "Please enter an 11-digit Philippine mobile number starting with 09 (e.g. 09XXXXXXXXX).";
    } elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$/i', $email))) {
        $error_msg = "Please enter a valid email address ending strictly with .com (e.g. user@example.com).";
    } else {
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
}

// One query: every active patient + their additional contacts (secondary / "number ng anak"...) folded into one text column.
require_once __DIR__ . '/../includes/patient_profile_helper.php';
mh_ensure_patient_contacts_schema($conn);
$query = "SELECT p.*, pc.extra_contacts
            FROM patients p
            LEFT JOIN (SELECT patient_id,
                              GROUP_CONCAT(CONCAT(contact_name, IF(relationship IS NULL OR relationship = '', '', CONCAT(' (', relationship, ')')), ': ', contact_number)
                                           ORDER BY id SEPARATOR ' | ') AS extra_contacts
                         FROM patient_contacts GROUP BY patient_id) pc ON pc.patient_id = p.id
           WHERE p.status = 1
           ORDER BY p.last_name ASC";
$patients_result = $conn->query($query);

$patients = [];
$counts = [
    'all'              => 0,
    'marick'           => 0,
    'outsider'         => 0,
    'all_infants'      => 0,
    'marick_infants'   => 0,
    'outsider_infants' => 0,
];

if ($patients_result) {
    while ($row = $patients_result->fetch_assoc()) {
        $dob_val = !empty($row['birthdate']) ? $row['birthdate'] : ($row['date_of_birth'] ?? '');
        $age_info = mh_calculate_patient_age($dob_val);

        $row['age_display'] = $age_info['text'];
        $row['is_infant']   = $age_info['is_infant'];
        $row['age_years']   = $age_info['years'];
        $row['age_months']  = $age_info['months'];

        $is_marick = mh_is_marick_resident($row['address_type'] ?? '');
        $row['is_marick']         = $is_marick;
        $row['location_category'] = $is_marick ? 'marick' : 'outsider';

        $raw_address = (string) ($row['address'] ?? '');
        $street_name = '';

        if ($is_marick) {
            $parsed = mh_parse_resident_address($raw_address);
            if ($parsed && !empty($parsed[1])) {
                $street_name = $parsed[1];
            } else {
                if (!empty($marick_streets)) {
                    foreach ($marick_streets as $mst) {
                        if (stripos($raw_address, $mst) !== false) {
                            $street_name = $mst;
                            break;
                        }
                    }
                }
            }
            $parts = explode(',', $raw_address);
            $row['display_address'] = trim($parts[0]);
            $row['badge_class']     = 'badge-marick';
        } else {
            $row['display_address'] = $raw_address;
            $row['badge_class']     = 'badge-outsider';
        }
        $row['street_name'] = $street_name;

        // Tally category counters
        $counts['all']++;
        if ($is_marick) {
            $counts['marick']++;
            if ($row['is_infant']) {
                $counts['marick_infants']++;
                $counts['all_infants']++;
            }
        } else {
            $counts['outsider']++;
            if ($row['is_infant']) {
                $counts['outsider_infants']++;
                $counts['all_infants']++;
            }
        }

        $patients[] = $row;
    }
}
?>
<?php
require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'Patient Masterlist']);
?>
    <style>
        /* Page-specific styling */
        .action-group { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .patient-name { font-weight: bold; color: var(--heading-color, #1e293b); font-size: 1.02rem; }
        .btn-walkin {
            background: #0ea5e9; color: white; border: none; padding: 10px 20px;
            border-radius: 25px; font-weight: 600; cursor: pointer; transition: 0.2s;
            text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 6px rgba(14, 165, 233, 0.25);
            white-space: nowrap;
        }
        .btn-walkin:hover { background: #0284c7; transform: translateY(-2px); box-shadow: 0 6px 12px rgba(14, 165, 233, 0.3); }
        .btn-save { background: #10b981; color: white; border: none; padding: 12px 25px; border-radius: 20px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: 0.2s; width: 100%; margin-top: 10px; box-shadow: 0 4px 6px rgba(16,185,129,0.2); }
        .btn-save:hover { background: #059669; transform: translateY(-2px); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        /* Main Category Tabs */
        .masterlist-nav-tabs {
            display: flex;
            gap: 6px;
            border-bottom: 2px solid var(--border-color, #e2e8f0);
            margin-bottom: 16px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .masterlist-nav-tabs::-webkit-scrollbar { display: none; }
        .ml-tab-btn {
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 10px 18px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-muted, #64748b);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            white-space: nowrap;
            margin-bottom: -2px;
            border-radius: 8px 8px 0 0;
        }
        .ml-tab-btn:hover {
            color: var(--heading-color, #1e293b);
            background: rgba(0, 0, 0, 0.02);
        }
        .ml-tab-btn.active {
            color: #0284c7;
            border-bottom-color: #0284c7;
            background: rgba(14, 165, 233, 0.06);
        }
        .tab-badge {
            background: var(--bg-hover, #f1f5f9);
            color: var(--text-muted, #64748b);
            font-size: 0.74rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            border: 1px solid var(--border-color, #e2e8f0);
            transition: all 0.2s;
        }
        .ml-tab-btn.active .tab-badge {
            background: #e0f2fe;
            color: #0369a1;
            border-color: #bae6fd;
        }
        /* Sub-filter Bar */
        .subfilter-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 10px;
            padding: 12px 14px;
            background: var(--bg-hover, #f8fafc);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 10px;
        }
        .subfilter-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .subfilter-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-left: auto;
        }
        /* Age Filter Pills */
        .age-filter-pills {
            display: inline-flex;
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #cbd5e1);
            border-radius: 20px;
            padding: 2px;
            gap: 2px;
        }
        .pill-btn {
            border: none;
            background: transparent;
            padding: 6px 14px;
            border-radius: 16px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-muted, #64748b);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .pill-btn:hover {
            color: var(--heading-color, #1e293b);
        }
        .pill-btn.active {
            background: #0ea5e9;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(14, 165, 233, 0.3);
        }
        .pill-badge {
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 10px;
        }
        .pill-btn.active .pill-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }
        /* Infant Badge in Table */
        .badge-infant {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
    </style>
<?php mh_layout_open(); ?>

        <div class="masterlist-container">

            <!-- Main Location Categories Tabs -->
            <div class="masterlist-nav-tabs" role="tablist">
                <button type="button" class="ml-tab-btn active" data-loc="all" onclick="setLocationFilter('all')" role="tab">
                    <span class="tab-label">All Locations</span>
                    <span class="tab-badge" id="badgeAll"><?php echo $counts['all']; ?></span>
                </button>
                <button type="button" class="ml-tab-btn" data-loc="marick" onclick="setLocationFilter('marick')" role="tab">
                    <span class="tab-label">Marick Residents</span>
                    <span class="tab-badge" id="badgeMarick"><?php echo $counts['marick']; ?></span>
                </button>
                <button type="button" class="ml-tab-btn" data-loc="outsider" onclick="setLocationFilter('outsider')" role="tab">
                    <span class="tab-label">Outsiders</span>
                    <span class="tab-badge" id="badgeOutsider"><?php echo $counts['outsider']; ?></span>
                </button>
            </div>

            <div class="unified-panel">
                <div class="panel-controls" style="flex-direction: column; align-items: stretch; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h3 style="margin:0; font-size: 1.25rem; color: var(--heading-color, #1e293b); font-family: Georgia, serif;">Masterlist Directory</h3>
                            <p style="margin:2px 0 0; font-size: 0.85rem; color: var(--text-muted, #64748b);" id="directorySubtitle">
                                Viewing <strong>All Locations</strong> across Barangay Sto. Domingo and outside residents.
                            </p>
                        </div>
                        <a href="walkin.php" class="btn-walkin"><?php echo mh_icon('plus'); ?> Walk-In Registration</a>
                    </div>
                    
                    <div class="subfilter-bar">
                        <div class="subfilter-left">
                            <!-- Main Location Filter Dropdown (Reorganized with Optgroups) -->
                            <select id="areaFilter" onchange="onLocationDropdownChange(this.value)" style="min-width: 220px;" aria-label="Filter location">
                                <option value="all">All Locations (<?php echo $counts['all']; ?>)</option>
                                <optgroup label="Marick Residents (Brgy. Sto. Domingo) — <?php echo $counts['marick']; ?> patients">
                                    <option value="marick:all">All Marick Residents (<?php echo $counts['marick']; ?>)</option>
                                    <?php 
                                    if(!empty($marick_streets)) {
                                        foreach($marick_streets as $street) {
                                            $sLabel = $street . (stripos($street, 'St') === false && stripos($street, 'Purok') === false && stripos($street, 'Zone') === false ? ' St.' : '');
                                            echo "<option value=\"marick:" . htmlspecialchars(strtoupper(trim($street))) . "\">&nbsp;&nbsp;- " . htmlspecialchars($sLabel) . "</option>";
                                        }
                                    }
                                    ?>
                                </optgroup>
                                <optgroup label="Outsiders (Outside Sto. Domingo) — <?php echo $counts['outsider']; ?> patients">
                                    <option value="outsider:all">All Outsiders (<?php echo $counts['outsider']; ?>)</option>
                                </optgroup>
                            </select>

                            <!-- Internal Street/Purok dropdown (visible when Marick Residents tab is active) -->
                            <div id="streetFilterContainer" style="display: none;">
                                <select id="streetFilter" onchange="filterTable()" style="min-width: 200px;" aria-label="Filter by street or purok in Marick">
                                    <option value="">All Streets &amp; Puroks in Sto. Domingo</option>
                                    <?php 
                                    if(!empty($marick_streets)) {
                                        foreach($marick_streets as $street) {
                                            $sLabel = $street . (stripos($street, 'St') === false && stripos($street, 'Purok') === false && stripos($street, 'Zone') === false ? ' St.' : '');
                                            echo "<option value=\"" . htmlspecialchars(strtoupper(trim($street))) . "\">" . htmlspecialchars($sLabel) . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Dedicated Babies / Infants Sub-Filter -->
                            <div class="age-filter-pills" role="group" aria-label="Age sub-filter">
                                <button type="button" class="pill-btn active" id="ageAllBtn" onclick="setAgeFilter('all')">
                                    All Ages
                                </button>
                                <button type="button" class="pill-btn" id="ageInfantBtn" onclick="setAgeFilter('infants')">
                                    Babies / Infants (&lt; 1 yr)
                                    <span class="pill-badge" id="infantCountBadge"><?php echo $counts['all_infants']; ?></span>
                                </button>
                            </div>
                        </div>

                        <div class="subfilter-right">
                            <input type="text" id="searchInput" class="search-box" placeholder="Search patient by name or contact..." onkeyup="filterTable()" style="min-width: 240px;">
                        </div>
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
                        <?php if(!empty($patients)): ?>
                            <?php foreach($patients as $row): ?>
                                <tr data-location-type="<?php echo $row['location_category']; ?>"
                                    data-street="<?php echo htmlspecialchars(strtoupper($row['street_name'])); ?>"
                                    data-address="<?php echo htmlspecialchars(strtoupper($row['display_address'])); ?>"
                                    data-is-infant="<?php echo $row['is_infant'] ? '1' : '0'; ?>"
                                    data-age-years="<?php echo $row['age_years']; ?>"
                                    data-age-months="<?php echo $row['age_months']; ?>">
                                    <td class="patient-name">
                                        <span style="display:inline-flex; align-items:center; gap:10px;">
                                            <?php echo mh_avatar_html(mh_initials($row['first_name'], $row['last_name']), 32, $row['first_name'] . ' ' . $row['last_name']); ?>
                                            <span><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['is_infant']): ?>
                                            <span class="badge-infant" title="Infant under 1 year old (<?php echo $row['age_months']; ?> month<?php echo $row['age_months'] === 1 ? '' : 's'; ?> old)">
                                                <?php echo htmlspecialchars($row['age_display']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="font-weight: 600; color: var(--heading-color, #1e293b);"><?php echo htmlspecialchars($row['age_display']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?>
                                        <?php if (!empty($row['extra_contacts'])): ?>
                                            <div style="font-size:0.78rem; color: var(--text-muted, #64748b); margin-top:3px; max-width:240px;" title="<?php echo mh_h($row['extra_contacts']); ?>"><?php echo mh_icon('users'); ?> <?php echo mh_h(str_replace(' | ', ' · ', $row['extra_contacts'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $row['badge_class']; ?>">
                                            <?php echo htmlspecialchars($row['display_address']); ?>
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
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty-state"><td colspan="6"><em>No active patients found in the masterlist.</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

        </div>
    <?php mh_layout_close(); ?>

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
                        <input type="tel" name="contact_number" id="edit_contact" required maxlength="11" pattern="^09[0-9]{9}$" 
                               placeholder="09XXXXXXXXX"
                               title="Must be an 11-digit mobile number starting with 09" 
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);">
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="edit_email" pattern="^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$" title="Email must end strictly with .com (e.g. user@example.com)" placeholder="user@example.com">
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
            var rawContact = button.getAttribute('data-contact') || '';
            if (rawContact.startsWith('+63')) {
                rawContact = '0' + rawContact.substring(3);
            } else if (rawContact.startsWith('63')) {
                rawContact = '0' + rawContact.substring(2);
            }
            document.getElementById('edit_contact').value = rawContact.replace(/[^0-9]/g, '').slice(0, 11);
            document.getElementById('edit_email').value = button.getAttribute('data-email');
            document.getElementById('edit_address').value = button.getAttribute('data-address');
            
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        let currentLocFilter = 'all';    // 'all' | 'marick' | 'outsider'
        let currentStreetFilter = '';     // specific street uppercase
        let currentAgeFilter = 'all';     // 'all' | 'infants'

        const infantCounts = <?php echo json_encode([
            'all'      => $counts['all_infants'],
            'marick'   => $counts['marick_infants'],
            'outsider' => $counts['outsider_infants'],
        ]); ?>;

        function setLocationFilter(loc) {
            currentLocFilter = loc;

            // Update main tab active states
            document.querySelectorAll('.ml-tab-btn').forEach(btn => {
                const isActive = btn.getAttribute('data-loc') === loc;
                btn.classList.toggle('active', isActive);
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            // Update subtitle description
            const sub = document.getElementById('directorySubtitle');
            if (sub) {
                if (loc === 'marick') {
                    sub.innerHTML = 'Viewing <strong>Marick Residents</strong> (Barangay Sto. Domingo internal streets, puroks, and zones).';
                } else if (loc === 'outsider') {
                    sub.innerHTML = 'Viewing <strong>Outsiders</strong> (patients residing outside Barangay Sto. Domingo).';
                } else {
                    sub.innerHTML = 'Viewing <strong>All Locations</strong> across Barangay Sto. Domingo and outside residents.';
                }
            }

            // Toggle street dropdown container for Marick Residents
            const streetBox = document.getElementById('streetFilterContainer');
            if (streetBox) {
                streetBox.style.display = (loc === 'marick') ? 'block' : 'none';
                if (loc !== 'marick') {
                    const sf = document.getElementById('streetFilter');
                    if (sf) sf.value = '';
                    currentStreetFilter = '';
                }
            }

            // Dynamically sync the infant counter badge with current location scope
            const infantBadge = document.getElementById('infantCountBadge');
            if (infantBadge) {
                infantBadge.textContent = infantCounts[loc] ?? infantCounts['all'];
            }

            // Keep location dropdown synchronized
            const locDropdown = document.getElementById('areaFilter');
            if (locDropdown) {
                if (loc === 'all') locDropdown.value = 'all';
                else if (loc === 'marick' && !currentStreetFilter) locDropdown.value = 'marick:all';
                else if (loc === 'outsider') locDropdown.value = 'outsider:all';
            }

            filterTable();
        }

        function onLocationDropdownChange(val) {
            if (!val || val === 'all') {
                currentStreetFilter = '';
                setLocationFilter('all');
            } else if (val === 'outsider:all') {
                currentStreetFilter = '';
                setLocationFilter('outsider');
            } else if (val.startsWith('marick:')) {
                const street = val.substring(7);
                currentStreetFilter = (street === 'all') ? '' : street;
                setLocationFilter('marick');
                const sf = document.getElementById('streetFilter');
                if (sf) sf.value = currentStreetFilter;
            }
        }

        function setAgeFilter(age) {
            currentAgeFilter = age;
            const allBtn = document.getElementById('ageAllBtn');
            const infBtn = document.getElementById('ageInfantBtn');
            if (allBtn) allBtn.classList.toggle('active', age === 'all');
            if (infBtn) infBtn.classList.toggle('active', age === 'infants');
            filterTable();
        }

        function filterTable() {
            const searchInput = (document.getElementById("searchInput").value || '').trim().toUpperCase();
            const streetVal = (document.getElementById("streetFilter") ? document.getElementById("streetFilter").value : '').toUpperCase();
            const rows = document.querySelectorAll("#patientsTable tbody tr");

            let visibleCount = 0;
            rows.forEach(tr => {
                if (tr.classList.contains('empty-state') || tr.id === 'noResultsRow') return;

                const nameCol = tr.getElementsByTagName("td")[0];
                const contactCol = tr.getElementsByTagName("td")[2];
                const areaCol = tr.getElementsByTagName("td")[3];
                if (!nameCol) return;

                const nameText = (nameCol.textContent || nameCol.innerText).toUpperCase();
                const contactText = contactCol ? (contactCol.textContent || contactCol.innerText).toUpperCase() : '';
                const areaText = areaCol ? (areaCol.textContent || areaCol.innerText).toUpperCase() : '';
                const locType = tr.getAttribute('data-location-type') || '';
                const trStreet = (tr.getAttribute('data-street') || '').toUpperCase();
                const isInfant = tr.getAttribute('data-is-infant') === '1';

                // 1. Name & Contact Search Match
                const matchesSearch = searchInput === "" || nameText.includes(searchInput) || contactText.includes(searchInput) || areaText.includes(searchInput);

                // 2. Location Category Match
                let matchesLocation = true;
                if (currentLocFilter === 'marick') {
                    matchesLocation = (locType === 'marick');
                    if (matchesLocation && streetVal !== '') {
                        matchesLocation = trStreet.includes(streetVal) || areaText.includes(streetVal);
                    }
                } else if (currentLocFilter === 'outsider') {
                    matchesLocation = (locType === 'outsider');
                }

                // 3. Age / Infant Filter Match (< 1 year old)
                let matchesAge = true;
                if (currentAgeFilter === 'infants') {
                    matchesAge = isInfant;
                }

                const show = matchesSearch && matchesLocation && matchesAge;
                tr.style.display = show ? "" : "none";
                if (show) visibleCount++;
            });

            // Dynamic "No matching patients" row
            let noResults = document.getElementById('noResultsRow');
            if (visibleCount === 0) {
                if (!noResults) {
                    noResults = document.createElement('tr');
                    noResults.id = 'noResultsRow';
                    noResults.innerHTML = '<td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted,#64748b);"><em>No patients match the selected location, age, or search criteria.</em></td>';
                    const tbody = document.querySelector("#patientsTable tbody");
                    if (tbody) tbody.appendChild(noResults);
                }
                noResults.style.display = '';
            } else if (noResults) {
                noResults.style.display = 'none';
            }
        }

        // URL parameters pre-filtering (e.g. ?tab=marick | ?tab=outsider | ?infants=1 | ?q=Santos)
        (function () {
            const params = new URLSearchParams(window.location.search);
            const tabParam = params.get('tab');
            if (tabParam === 'marick' || tabParam === 'outsider') {
                setLocationFilter(tabParam);
            }
            if (params.get('infants') === '1') {
                setAgeFilter('infants');
            }
            const q = params.get('q');
            const box = document.getElementById('searchInput');
            if (q && box) {
                box.value = q;
                filterTable();
            }
        })();
    </script>
<?php mh_layout_end(); ?>