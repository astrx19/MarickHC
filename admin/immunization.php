<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/patient_name_helper.php';
require_once __DIR__ . '/../includes/queue_helper.php';
require_once __DIR__ . '/../includes/sms_helper.php';
require_once __DIR__ . '/../includes/sms/sms_scheduler.php';
mh_sms_install($conn);

$system_name = $system_name ?? mh_setting('system_name');

// Granular: logging new entries and reading the history are separate permissions.
require_capability(['can_log_immunization', 'can_view_immunization_records']);

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('immunization');

mh_ensure_patient_name_columns($conn);   // adds patient_name / nullable patient_id if the migration was never run
$can_log          = mh_has_capability('can_log_immunization');
$can_view_history = mh_has_capability('can_view_immunization_records');

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'staff';
$success_msg = ""; 
$error_msg = "";

function null_if_empty($val) {
    $val = trim($val);
    return ($val === '') ? NULL : $val;
}

if (isset($_POST['add_child_immunization']) && !$can_log) {
    mh_deny_access(); // view-only accounts cannot create records
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_child_immunization'])) {
    // Free-text name. $patient_id is only set when EXACTLY ONE registered
    // patient matches the typed name (otherwise NULL - record saved by name).
    $pn = mh_read_patient_name_post();            // Last Name / First Name / MI
    $patient_name = $pn['name'];                   // stored as "Last, First M."
    $name_error   = $pn['error'];

    if ($name_error !== null) {
        $error_msg = $name_error;
    } else {
        // Opened from Patient Queueing > Proceed? Then the queue entry decides the patient (when the name is unchanged)
        // and is marked Completed once this record is saved.
        $queue_ctx_post = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'immunization');
        $patient_id = mh_queue_resolve_patient($conn, $queue_ctx_post, $pn);
        $family_no             = null_if_empty($_POST['family_no'] ?? '');
        $others_header         = null_if_empty($_POST['others_header'] ?? '');
        $newborn_screening_date  = null_if_empty($_POST['newborn_screening_date'] ?? '');
        $tt_of_mother_date       = null_if_empty($_POST['tt_of_mother_date'] ?? '');
        $birth_time              = null_if_empty($_POST['birth_time'] ?? '');
        $birth_weight            = null_if_empty($_POST['birth_weight'] ?? '');
        $birth_height            = null_if_empty($_POST['birth_height'] ?? '');
        $birth_place             = null_if_empty($_POST['birth_place'] ?? '');
        $type_of_delivery        = null_if_empty($_POST['type_of_delivery'] ?? '');
        $place_of_delivery       = null_if_empty($_POST['place_of_delivery'] ?? '');
        $type_of_breastfeeding   = null_if_empty($_POST['type_of_breastfeeding'] ?? '');

        $mother_name             = null_if_empty($_POST['mother_name'] ?? '');
        $mother_bday             = null_if_empty($_POST['mother_bday'] ?? '');
        $mother_age              = !empty($_POST['mother_age']) ? intval($_POST['mother_age']) : NULL;
        $father_name             = null_if_empty($_POST['father_name'] ?? '');
        $father_bday             = null_if_empty($_POST['father_bday'] ?? '');
        $father_age              = !empty($_POST['father_age']) ? intval($_POST['father_age']) : NULL;
        $attending_physician     = null_if_empty($_POST['attending_physician'] ?? '');
        $no_of_children          = !empty($_POST['no_of_children']) ? intval($_POST['no_of_children']) : NULL;
        $contact_no              = null_if_empty($_POST['contact_no'] ?? '');

        $bcg_date                = null_if_empty($_POST['bcg_date'] ?? '');
        $hep_b_24hrs_date        = null_if_empty($_POST['hep_b_24hrs_date'] ?? '');
        $penta_opv_3_date        = null_if_empty($_POST['penta_opv_3_date'] ?? '');
        $penta_1_opv_1_date      = null_if_empty($_POST['penta_1_opv_1_date'] ?? '');
        $penta_2_opv_2_date      = null_if_empty($_POST['penta_2_opv_2_date'] ?? '');
        $pcv_1_date              = null_if_empty($_POST['pcv_1_date'] ?? '');
        $pcv_2_date              = null_if_empty($_POST['pcv_2_date'] ?? '');
        $pcv_3_date              = null_if_empty($_POST['pcv_3_date'] ?? '');
        $others_immunization     = null_if_empty($_POST['others_immunization'] ?? '');
        $mr_date                 = null_if_empty($_POST['mr_date'] ?? '');
        $mmr_date                = null_if_empty($_POST['mmr_date'] ?? '');
        $next_visit_date         = null_if_empty($_POST['next_visit_date'] ?? '');

        $stmt = $conn->prepare("INSERT INTO child_immunization_records 
            (patient_id, patient_name, attending_staff_id, family_no, others_header, newborn_screening_date, tt_of_mother_date, 
             birth_time, birth_weight, birth_height, birth_place, type_of_delivery, place_of_delivery, 
             type_of_breastfeeding, mother_name, mother_bday, mother_age, father_name, father_bday, father_age, 
             attending_physician, no_of_children, contact_no, bcg_date, hep_b_24hrs_date, penta_opv_3_date, 
             penta_1_opv_1_date, penta_2_opv_2_date, pcv_1_date, pcv_2_date, pcv_3_date, others_immunization, mr_date, mmr_date, next_visit_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt) {
            $stmt->bind_param("isisssssssssssssissisisssssssssssss", 
                $patient_id, $patient_name, $user_id, $family_no, $others_header, $newborn_screening_date, $tt_of_mother_date, 
                $birth_time, $birth_weight, $birth_height, $birth_place, $type_of_delivery, $place_of_delivery, 
                $type_of_breastfeeding, $mother_name, $mother_bday, $mother_age, $father_name, $father_bday, $father_age, 
                $attending_physician, $no_of_children, $contact_no, $bcg_date, $hep_b_24hrs_date, $penta_opv_3_date, 
                $penta_1_opv_1_date, $penta_2_opv_2_date, $pcv_1_date, $pcv_2_date, $pcv_3_date, $others_immunization, $mr_date, $mmr_date, $next_visit_date
            );

            if ($stmt->execute()) {
                $child_record_id = $conn->insert_id;

                if (isset($_POST['consultation_date']) && is_array($_POST['consultation_date'])) {
                    $consult_stmt = $conn->prepare("INSERT INTO child_consultation_logs 
                        (child_record_id, consultation_date, weight, height, temp, chief_complaints, remarks) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)");

                    if ($consult_stmt) {
                        foreach ($_POST['consultation_date'] as $idx => $c_date_val) {
                            $c_date = null_if_empty($c_date_val ?? '');
                            if ($c_date !== NULL) {
                                $c_wt   = null_if_empty($_POST['consultation_weight'][$idx] ?? '');
                                $c_ht   = null_if_empty($_POST['consultation_height'][$idx] ?? '');
                                $c_temp = null_if_empty($_POST['consultation_temp'][$idx] ?? '');
                                $c_cc   = null_if_empty($_POST['consultation_cc'][$idx] ?? '');
                                $c_rem  = null_if_empty($_POST['consultation_remarks'][$idx] ?? '');

                                $consult_stmt->bind_param("issssss", $child_record_id, $c_date, $c_wt, $c_ht, $c_temp, $c_cc, $c_rem);
                                $consult_stmt->execute();
                            }
                        }
                        $consult_stmt->close();
                    }
                }

                $success_msg = "Child Immunization Record saved successfully!";
                if ($queue_ctx_post && $patient_id !== null && (int) $patient_id === (int) $queue_ctx_post['patient_id']
                    && mh_queue_complete($conn, $queue_ctx_post['id'], $patient_id)) {
                    $success_msg .= " Queue" . (!empty($queue_ctx_post['queue_number']) ? " #" . (int) $queue_ctx_post['queue_number'] : "") . " marked as Completed.";
                    if (function_exists('logActivity')) {
                        logActivity($conn, $user_id, $user_role, "Patient Queueing Update", "Completed queue item #" . (int) $queue_ctx_post['id'] . " after saving the immunization record.");
                    }
                }
                if (function_exists('logActivity')) {
                    logActivity($conn, $user_id, $user_role, "Added Child Immunization Record", "Logged record for " . $patient_name . ($patient_id ? " (Patient ID #$patient_id)." : " (not linked to a registered patient)."));
                }

                if ($next_visit_date !== null && $patient_id === null) {
                    $success_msg .= " (No SMS reminder queued: the typed name did not match exactly one registered patient.)";
                } elseif ($next_visit_date !== null) {
                    $sms = mh_schedule_followup_sms($conn, $patient_id, $next_visit_date, 'Immunization', $child_record_id);
                    if ($sms['queued'] > 0) {
                        $success_msg .= " " . $sms['queued'] . " SMS reminder(s) queued for the patient's follow-up.";
                    } elseif ($sms['skipped_reason']) {
                        $success_msg .= " (No SMS reminder queued: " . $sms['skipped_reason'] . ")";
                    }
                }
            } else {
                $error_msg = "Error saving record: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_msg = "Database prepare error: " . $conn->error;
        }
    }
}

// ---------------------------------------------------------------------------------------------
// Arriving from Patient Queueing > Proceed (?walkin_id=<patient>&apt_id=<queue entry>):
// open the form, fill the name and carry over what the records already hold.
// ---------------------------------------------------------------------------------------------
$queue_ctx = null; $queue_open_modal = false; $queue_prefill = []; $queue_label = '';
$prefill_name_parts = [];
if ($can_log && $success_msg === '' && (isset($_GET['walkin_id']) || isset($_GET['apt_id']))) {
    $q_patient = (int) ($_GET['walkin_id'] ?? 0);
    $queue_ctx = mh_queue_open_context($conn, $_GET['apt_id'] ?? 0, 'immunization', $q_patient);
    if ($queue_ctx) { $q_patient = (int) $queue_ctx['patient_id']; }
    $profile = $q_patient > 0 ? mh_queue_patient_profile($conn, $q_patient) : null;
    if ($profile) {
        $queue_open_modal   = true;
        $prefill_name_parts = mh_patient_name_parts_by_id($conn, $q_patient);
        $queue_label        = $profile['last_name'] . ', ' . $profile['first_name'];

        $pf = [
            'family_no'  => $profile['family_no'] ?? '',
            'contact_no' => $profile['contact_number'] ?? '',
        ];
        // Latest immunization card on file: parents, birth details and the doses already given.
        $st = $conn->prepare("SELECT * FROM child_immunization_records WHERE patient_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($st) {
            $st->bind_param('i', $q_patient); $st->execute();
            $last = $st->get_result()->fetch_assoc(); $st->close();
            if ($last) {
                foreach (['family_no','others_header','newborn_screening_date','tt_of_mother_date','birth_weight','birth_height',
                          'birth_place','type_of_delivery','place_of_delivery','type_of_breastfeeding','mother_name','mother_bday',
                          'mother_age','father_name','father_bday','father_age','no_of_children','contact_no',
                          'bcg_date','hep_b_24hrs_date','penta_opv_3_date','penta_1_opv_1_date','penta_2_opv_2_date',
                          'pcv_1_date','pcv_2_date','pcv_3_date','others_immunization','mr_date','mmr_date'] as $k) {
                    $v = (string) ($last[$k] ?? '');
                    if ($v !== '' && strpos($v, '0000-00-00') !== 0) { $pf[$k] = $v; }
                }
                if (!empty($last['birth_time'])) { $pf['birth_time'] = substr((string) $last['birth_time'], 0, 5); }
            }
        }
        // Today's consultation row: date, vitals taken today and the reason given at registration.
        $pf['consultation_date[]'] = date('Y-m-d');
        $vit = mh_queue_today_vitals($conn, $q_patient);
        if ($vit) {
            $pf['consultation_weight[]'] = mh_queue_number($vit['weight']);
            $pf['consultation_height[]'] = mh_queue_number($vit['height']);
            $pf['consultation_temp[]']   = mh_queue_number($vit['temp']);
        }
        if ($queue_ctx && trim((string) $queue_ctx['reason']) !== '' && $queue_ctx['reason'] !== 'Walk-In Queue Registration') {
            $pf['consultation_cc[]'] = $queue_ctx['reason'];
        }
        $queue_prefill = mh_queue_clean_prefill($pf);
    }
}

$immunization_logs = !$can_view_history ? false : $conn->query("
    SELECT c.*, p.first_name, p.middle_name, p.last_name, p.birthdate, p.sex, s.last_name as staff_name 
    FROM child_immunization_records c 
    LEFT JOIN patients p ON c.patient_id = p.id 
    LEFT JOIN staff s ON c.attending_staff_id = s.id 
    ORDER BY c.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Immunization Form - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    
    <style>
        .form-section-title { font-size: 0.8rem; color: #0284c7; text-transform: uppercase; font-weight: bold; letter-spacing: 1px; margin: 15px 0 8px 0; border-bottom: 2px solid #bae6fd; padding-bottom: 3px; }
        .paper-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .paper-grid label, .form-group label { font-size: 0.75rem; color: var(--text-main, #334155); text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 3px; }
        .paper-grid input, .paper-grid select, .form-group input, .form-group select, .form-group textarea { padding: 6px 10px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 4px; width: 100%; font-size: 0.85rem; background: var(--bg-card, #ffffff); box-sizing: border-box; }
        
        .imm-box { background: var(--bg-hover, #f8fafc); border: 1px solid var(--border-color, #cbd5e1); border-radius: 6px; padding: 10px; }
        .imm-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .imm-table td { border: 1px solid var(--border-color, #cbd5e1); padding: 6px; font-size: 0.8rem; background: var(--bg-card, #ffffff); }
        .imm-table td label { font-size: 0.7rem; color: var(--text-main, #334155); font-weight: bold; display: block; }
        .imm-table td input { width: 100%; border: none; font-size: 0.8rem; padding: 2px; background: transparent; outline: none; box-sizing: border-box; }

        .consult-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .consult-table th { background: #f1f5f9; border: 1px solid var(--border-color, #cbd5e1); font-size: 0.75rem; padding: 6px; text-align: left; color: var(--text-main, #334155); }
        .consult-table td { border: 1px solid var(--border-color, #cbd5e1); padding: 4px; }
        .consult-table input { width: 100%; border: none; padding: 4px; font-size: 0.85rem; outline: none; box-sizing: border-box; }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: var(--bg-card, #ffffff); border-radius: 10px; padding: 25px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .close-btn { position: absolute; top: 15px; right: 20px; font-size: 24px; font-weight: bold; color: var(--text-muted, #64748b); cursor: pointer; }
        .close-btn:hover { color: var(--heading-color, #1e293b); }

        .custom-popup-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; justify-content: center; align-items: center; z-index: 99999; }
        .custom-popup-box { background: var(--bg-card, #ffffff); width: 420px; padding: 35px 25px; border-radius: 12px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .custom-popup-icon { width: 75px; height: 75px; border: 3px solid #d1fae5; background: #f0fdf4; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; color: #10b981; }
        .custom-popup-icon .mh-icon { width: 36px; height: 36px; }
        .custom-popup-icon.error { border: 3px solid #fee2e2; background: #fef2f2; color: #ef4444; }

        .ocr-box { border: 2px dashed #38bdf8; background: #f0f9ff; border-radius: 10px; padding: 20px; text-align: center; margin-bottom: 20px; }
        .ocr-title { color: #0284c7; font-weight: bold; font-size: 1.05rem; margin-bottom: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .ocr-file-wrapper { display: flex; justify-content: center; align-items: center; margin-bottom: 12px; }
        .ocr-file-input { font-size: 0.85rem; color: var(--text-main, #334155); }
        .btn-ocr { background: #0080c8; color: white; border: none; padding: 10px 24px; font-weight: bold; font-size: 0.9rem; border-radius: 6px; cursor: pointer; transition: background 0.2s ease; }
        .btn-ocr:hover { background: #006ca9; }
        .ocr-status { margin-top: 10px; font-size: 0.85rem; font-weight: 600; color: #0369a1; }
        .btn-remove-row { background: #ef4444; color: #fff; border: none; border-radius: 4px; padding: 2px 8px; font-size: 0.75rem; cursor: pointer; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
    <link rel="stylesheet" href="../assets/css/dept_ui.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/dept_ui.css'); ?>">
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container du-wrap">
            <?php 
            if (!empty($success_msg)) { 
                echo "<script>document.addEventListener('DOMContentLoaded', function() { showCustomModal('Success!', " . json_encode($success_msg) . ", 'success'); });</script>"; 
            }
            if (!empty($error_msg)) { 
                echo "<script>document.addEventListener('DOMContentLoaded', function() { showCustomModal('Error!', " . json_encode($error_msg) . ", 'error'); });</script>"; 
            }
            ?>

            <div class="du-pagehead">
                <div><h1>Child Immunization Records</h1><p>Child immunization records, vaccines and visit history.</p></div>
                <div class="du-actions">
                        <?php if($can_view_history): ?>
                        <input type="text" id="searchChild" class="search-box" placeholder="Search child name..." onkeyup="filterTable()">
                        <?php endif; ?>
                        <?php if($can_log): ?>
                        <button class="du-btn du-btn-primary" onclick="openModal('addChildModal')"><?php echo mh_icon('plus'); ?> New Immunization Form</button>
                        <?php endif; ?>
                    </div>
            </div>
            <div class="du-card">
                
                <?php if($can_view_history): ?>
                <div class="table-scroll">
                <table class="unified-table" id="childTable">
                    <thead>
                        <tr>
                            <th>Child Name</th>
                            <th>Birthdate &amp; Sex</th>
                            <th>Parents / Contact</th>
                            <th>Birth Info</th>
                            <th>Attending Staff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($immunization_logs && $immunization_logs->num_rows > 0) {
                            while ($row = $immunization_logs->fetch_assoc()) {
                                $child_full_name = htmlspecialchars(mh_record_patient_label($row));
                                $fam_no = htmlspecialchars($row['family_no'] ?? '--');
                                $bday = !empty($row['birthdate']) ? date("M d, Y", strtotime($row['birthdate'])) : '--';
                                $c_sex = htmlspecialchars($row['sex'] ?? '');
                                $m_name = htmlspecialchars($row['mother_name'] ?? '--');
                                $c_no = htmlspecialchars($row['contact_no'] ?? '--');
                                $b_wt = htmlspecialchars($row['birth_weight'] ?? '--');
                                $b_ht = htmlspecialchars($row['birth_height'] ?? '--');
                                $b_place = htmlspecialchars($row['birth_place'] ?? '--');
                                $st_name = htmlspecialchars($row['staff_name'] ?? 'System');

                                echo "<tr>";
                                echo "<td><strong style='color: var(--heading-color, #1e293b); font-size: 1.05rem;'>{$child_full_name}</strong><br><span style='font-size: 0.8rem; color: var(--text-muted, #64748b);'>Family No: {$fam_no}</span></td>";
                                echo "<td>{$bday}<br><span style='font-size: 0.8rem; color: #0284c7; font-weight: bold;'>{$c_sex}</span></td>";
                                echo "<td style='font-size: 0.85rem;'><strong>Mother:</strong> {$m_name}<br><strong>Contact:</strong> {$c_no}</td>";
                                echo "<td style='font-size: 0.85rem;'><strong>Wt:</strong> {$b_wt} | <strong>Ht:</strong> {$b_ht}<br><strong>Place:</strong> {$b_place}</td>";
                                echo "<td><span style='font-weight: bold; color: var(--text-main, #334155);'>{$st_name}</span></td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr class='empty-state'><td colspan='5'>No child immunization records found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div style="padding: 40px 24px; text-align: center; color: var(--text-muted);">You can log new immunization forms here, but your account does not include permission to view the history.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Custom Modal Popup -->
    <div class="custom-popup-overlay" id="customPopupModal">
        <div class="custom-popup-box">
            <div class="custom-popup-icon" id="customPopupIcon"><?php echo mh_icon('check'); ?></div>
            <div class="custom-popup-title" id="customPopupTitle" style="font-size:1.3rem; font-weight:bold; margin-bottom:10px;">Success!</div>
            <div class="custom-popup-message" id="customPopupMessage" style="color:var(--text-muted, #64748b); margin-bottom:20px;">Message...</div>
            <button class="custom-popup-btn" onclick="closeCustomModal()" style="background:#0284c7; color:white; border:none; padding:8px 25px; border-radius:4px; cursor:pointer;">OK</button>
        </div>
    </div>

    <!-- Add Child Record Modal -->
    <?php if($can_log): /* intake form, vitals inputs, OCR box and save button exist only with the Log permission */ ?>
    <div class="modal-overlay" id="addChildModal">
        <div class="modal-content du-form-modal">
            <span class="close-btn" onclick="closeModal('addChildModal')">&times;</span>
            
            <div class="du-mhead"><h3><?php echo htmlspecialchars($system_name ?? ''); ?></h3><h2>CHILD IMMUNIZATION FORM</h2></div>

            <!-- OCR Scanner Area (toggle: use_ocr_scanner) -->
            <?php if (mh_has_permission('use_ocr_scanner')): ?>
            <div class="du-ocrbox">
                <div class="du-ocr-title"><?php echo mh_icon('camera'); ?> Auto-fill from a scanned immunization card (OCR)</div>
                <p class="du-ocr-help">Upload a photo or scan and the form fills in. Review everything before saving.</p>
                <div class="du-ocr-row">
                    <input type="file" id="ocr_file" class="ocr-file-input" accept="image/*,.pdf">
                    <button type="button" class="du-btn du-btn-primary du-btn-sm btn-ocr" onclick="scanChildCardOCR()">Scan &amp; auto-fill</button>
                </div>
                <div id="ocrStatus" class="ocr-status"></div>
                <div id="ocrMatchBadge" style="display:none; margin-top: 10px; text-align:left;"></div>
            </div>
            <?php endif; ?>

            <?php if ($queue_ctx) { mh_queue_render_banner($queue_ctx, $queue_label, count($queue_prefill) > 1); } ?>
            <form method="POST" id="childForm">
                <?php if ($queue_ctx): ?><input type="hidden" name="apt_id" value="<?php echo (int) $queue_ctx['id']; ?>"><?php endif; ?>
                <div class="paper-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr; margin-bottom: 10px;">
                    <div><label>Family No:</label><input type="text" name="family_no"></div>
                    <div><label>Others:</label><input type="text" name="others_header"></div>
                    <div><label>Newborn Screening Date:</label><input type="date" name="newborn_screening_date"></div>
                    <div><label>TT of Mother / Date:</label><input type="date" name="tt_of_mother_date"></div>
                </div>

                <div class="form-section-title">Child Information</div>
                <div class="paper-grid" style="grid-template-columns: 3fr 1fr; margin-bottom: 10px;">
                    <div>
                        
                        <?php mh_render_patient_name_fields('Child / Patient Name', $prefill_name_parts); ?>
                    </div>
                    <div><label>Birth Time:</label><input type="time" name="birth_time"></div>
                </div>

                <div class="paper-grid" style="grid-template-columns: 1fr 1fr 2fr; margin-bottom: 10px;">
                    <div><label>Birth Weight:</label><input type="text" name="birth_weight" placeholder="e.g., 3.1 kg"></div>
                    <div><label>Birth Height:</label><input type="text" name="birth_height" placeholder="e.g., 49 cm"></div>
                    <div><label>Birth Place (Hospital, Lying-in, House):</label>
                        <select name="birth_place"><?php echo mh_masterlist_options($conn, 'birth_places', '', '-- Select --'); ?></select></div>
                </div>

                <div class="paper-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 10px;">
                    <div><label>Type of Delivery:</label>
                        <select name="type_of_delivery"><?php echo mh_masterlist_options($conn, 'delivery_types', '', '-- Select --'); ?></select></div>
                    <div><label>Type of Breastfeeding:</label>
                        <select name="type_of_breastfeeding"><?php echo mh_masterlist_options($conn, 'breastfeeding_types', '', '-- Select --'); ?></select></div>
                </div>

                <div class="form-section-title">Parent &amp; Family Details</div>
                <div class="paper-grid" style="grid-template-columns: 2fr 1fr 1fr; margin-bottom: 8px;">
                    <div><label>Mother's Name:</label><input type="text" name="mother_name"></div>
                    <div><label>Mother's Bday:</label><input type="date" name="mother_bday"></div>
                    <div><label>Mother's Age:</label><input type="number" name="mother_age"></div>
                </div>
                <div class="paper-grid" style="grid-template-columns: 2fr 1fr 1fr; margin-bottom: 8px;">
                    <div><label>Father's Name:</label><input type="text" name="father_name"></div>
                    <div><label>Father's Bday:</label><input type="date" name="father_bday"></div>
                    <div><label>Father's Age:</label><input type="number" name="father_age"></div>
                </div>
                <div class="paper-grid" style="grid-template-columns: 2fr 1fr 1fr; margin-bottom: 10px;">
                    <div><label>Attending Physician / Staff:</label><input type="text" name="attending_physician"></div>
                    <div><label>No. of Children:</label><input type="number" name="no_of_children"></div>
                    <div><label>Contact No:</label><input type="text" name="contact_no"></div>
                </div>

                <div class="form-section-title">Immunization Record (Dates Administered)</div>
                <div class="imm-box">
                    <table class="imm-table">
                        <tr>
                            <td><label>BCG</label><input type="date" name="bcg_date"></td>
                            <td><label>Hepa B W/N 24 Hrs.</label><input type="date" name="hep_b_24hrs_date"></td>
                            <td><label>Penta 3, OPV 3</label><input type="date" name="penta_opv_3_date"></td>
                        </tr>
                        <tr>
                            <td><label>Penta 1, OPV 1</label><input type="date" name="penta_1_opv_1_date"></td>
                            <td><label>Penta 2, OPV 2</label><input type="date" name="penta_2_opv_2_date"></td>
                            <td><label>PCV 3</label><input type="date" name="pcv_3_date"></td>
                        </tr>
                        <tr>
                            <td><label>PCV 1</label><input type="date" name="pcv_1_date"></td>
                            <td><label>PCV 2</label><input type="date" name="pcv_2_date"></td>
                            <td><label>Others</label><input type="text" name="others_immunization" placeholder="Specify..."></td>
                        </tr>
                        <tr>
                            <td><label>MR</label><input type="date" name="mr_date"></td>
                            <td><label>MMR</label><input type="date" name="mmr_date"></td>
                            <td></td>
                        </tr>
                    </table>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                    <div class="form-section-title" style="margin: 0; border: none;">Consultation &amp; Monitoring Record</div>
                    <button type="button" onclick="addConsultationRow()" style="background: #10b981; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 0.8rem; cursor: pointer; font-weight: bold;">+ Add Row</button>
                </div>
                
                <table class="consult-table" id="consultationTable">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Consultation Date</th>
                            <th style="width: 12%;">Weight</th>
                            <th style="width: 12%;">Height</th>
                            <th style="width: 12%;">Temp</th>
                            <th style="width: 22%;">Chief Complaints</th>
                            <th style="width: 17%;">Remarks</th>
                            <th style="width: 5%;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="date" name="consultation_date[]"></td>
                            <td><input type="text" name="consultation_weight[]" placeholder="kg"></td>
                            <td><input type="text" name="consultation_height[]" placeholder="cm"></td>
                            <td><input type="text" name="consultation_temp[]" placeholder="°C"></td>
                            <td><input type="text" name="consultation_cc[]"></td>
                            <td><input type="text" name="consultation_remarks[]"></td>
                            <td style="text-align: center;"><button type="button" class="btn-remove-row" onclick="removeConsultationRow(this)"><?php echo mh_icon('x'); ?></button></td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top: 20px;">
                    <label style="font-weight: bold; color: var(--heading-color, #1e293b);">Follow-up / Return Date <span style="color:var(--text-muted, #64748b); font-weight:normal;">(Optional)</span></label>
                    <input type="date" name="next_visit_date" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    <small style="color:var(--text-muted, #64748b); display:block; margin-top:4px;">If set, the patient's guardian automatically gets an SMS reminder the day before and the morning of this date.</small>
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit" class="du-btn du-btn-primary du-btn-block" name="add_child_immunization" data-confirm="Create this immunization record? Please double-check the details." data-confirm-title="Save immunization record?" data-confirm-type="info" data-confirm-ok="Save">Save Child Immunization Record</button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; // can_log ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        const MH_ICON_CHECK = <?php echo json_encode(mh_icon('check')); ?>;
        const MH_ICON_X = <?php echo json_encode(mh_icon('x')); ?>;
        const MH_ICON_CHECK_CIRCLE = <?php echo json_encode(mh_icon('check-circle')); ?>;
        const MH_ICON_WARN = <?php echo json_encode(mh_icon('alert-triangle')); ?>;

        function showCustomModal(title, message, type) {
            $('#customPopupTitle').text(title);
            $('#customPopupMessage').text(message);
            if (type === 'error') {
                $('#customPopupIcon').addClass('error').html(MH_ICON_X);
            } else {
                $('#customPopupIcon').removeClass('error').html(MH_ICON_CHECK);
            }
            $('#customPopupModal').css('display', 'flex');
        }

        function closeCustomModal() { $('#customPopupModal').hide(); }
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function filterTable() {
            let input = document.getElementById("searchChild").value.toUpperCase();
            let tr = document.getElementById("childTable").getElementsByTagName("tr");
            for (let i = 1; i < tr.length; i++) {
                let td = tr[i].getElementsByTagName("td")[0];
                if (td) {
                    tr[i].style.display = (td.textContent || td.innerText).toUpperCase().indexOf(input) > -1 ? "" : "none";
                }
            }
        }

        function addConsultationRow() {
            const row = `
                <tr>
                    <td><input type="date" name="consultation_date[]"></td>
                    <td><input type="text" name="consultation_weight[]" placeholder="kg"></td>
                    <td><input type="text" name="consultation_height[]" placeholder="cm"></td>
                    <td><input type="text" name="consultation_temp[]" placeholder="°C"></td>
                    <td><input type="text" name="consultation_cc[]"></td>
                    <td><input type="text" name="consultation_remarks[]"></td>
                    <td style="text-align: center;"><button type="button" class="btn-remove-row" onclick="removeConsultationRow(this)"><?php echo mh_icon('x'); ?></button></td>
                </tr>`;
            $('#consultationTable tbody').append(row);
        }

        function removeConsultationRow(btn) {
            if ($('#consultationTable tbody tr').length > 1) {
                $(btn).closest('tr').remove();
            } else {
                $(btn).closest('tr').find('input').val('');
            }
        }

        async function scanChildCardOCR() {
            const fileInput = document.getElementById('ocr_file');
            const statusDiv = document.getElementById('ocrStatus');

            if (!fileInput.files || fileInput.files.length === 0) {
                statusDiv.style.color = '#ef4444';
                statusDiv.innerText = 'Please select an image file first!';
                return;
            }

            const imageFile = fileInput.files[0];
            statusDiv.style.color = '#0284c7';
            statusDiv.innerText = 'Scanning document via Gemini AI... Please wait...';

            const formData = new FormData();
            formData.append('ocr_file', imageFile);

            try {
                const response = await fetch('ocr/immunization_scan.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    statusDiv.style.color = '#10b981';
                    statusDiv.innerText = 'Extraction complete! Fields populated - please review before saving.';

                    autoFillChildForm(result.fields || {});
                    applyOcrPatientMatch(result.matched_patient, result.extracted_identity);
                } else {
                    statusDiv.style.color = '#ef4444';
                    statusDiv.innerText = result.error || 'Failed to extract data from document.';
                }
            } catch (err) {
                console.error(err);
                statusDiv.style.color = '#ef4444';
                statusDiv.innerText = 'Network error or server failed to respond.';
            }
        }

        // Pre-selects a matched patient (via select2) and shows a badge, or
        // offers a one-click hand-off to Walk-In New Registration when
        // nothing in the patients table matched what the OCR read.
        function applyOcrPatientMatch(matchedPatient, extractedIdentity) {
            const badge = document.getElementById('ocrMatchBadge');
            if (!badge) return;

            if (matchedPatient && matchedPatient.id) {
                mhSetPatientName(matchedPatient.first_name, matchedPatient.middle_name, matchedPatient.last_name);
                badge.style.display = 'block';
                badge.innerHTML = '<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:8px 12px; border-radius:6px; font-size:0.85rem;">'
                    + MH_ICON_CHECK_CIRCLE + ' Existing patient matched: <strong>' + escapeHtml(matchedPatient.full_name) + '</strong> (ID #' + matchedPatient.id + ')'
                    + '</div>';
            } else if (extractedIdentity && (extractedIdentity.first_name || extractedIdentity.last_name)) {
                const params = new URLSearchParams({
                    prefill_fname: extractedIdentity.first_name || '',
                    prefill_lname: extractedIdentity.last_name || '',
                    prefill_bdate: extractedIdentity.birthdate || ''
                });
                badge.style.display = 'block';
                badge.innerHTML = '<div style="background:#fff7ed; border:1px dashed #fb923c; color:#9a3412; padding:8px 12px; border-radius:6px; font-size:0.85rem;">'
                    + MH_ICON_WARN + ' No existing record found for this child.<br>'
                    + '<a href="walkin.php?' + params.toString() + '" target="_blank" style="display:inline-block; margin-top:6px; background:#ea580c; color:white; padding:6px 12px; border-radius:4px; text-decoration:none; font-weight:bold;">Auto-fill into New Patient Registration</a>'
                    + '</div>';
            } else {
                badge.style.display = 'none';
                badge.innerHTML = '';
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        // Populates the Child Immunization Form directly from the
        // structured field data returned by ocr/immunization_scan.php.
        // Every key in `fields` matches a form input's name="" exactly,
        // so no client-side parsing/guessing is needed.
        function autoFillChildForm(fields) {
            for (const [name, value] of Object.entries(fields)) {
                if (value === undefined || value === null || value === '') continue;
                const input = document.querySelector(`#childForm [name="${name}"]`);
                if (input) {
                    if (input.tagName === 'SELECT') {
                        // Masterlist dropdowns: match the scanned text to an existing
                        // option (case-insensitive); if it isn't listed, keep it as an
                        // extra option so the scanned value is never silently lost.
                        const want = String(value).trim().toLowerCase();
                        let hit = Array.from(input.options).find(o => o.value.trim().toLowerCase() === want);
                        if (!hit) {
                            hit = new Option(String(value).trim(), String(value).trim());
                            input.add(hit);
                        }
                        input.value = hit.value;
                    } else {
                        input.value = value;
                    }
                }
            }
        }

        // Opened from Patient Queueing > Proceed: show the form with the patient's details already filled in.
        const QUEUE_PREFILL = <?php echo json_encode($queue_prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($queue_open_modal): ?>
            openModal('addChildModal');
            autoFillChildForm(QUEUE_PREFILL);
            <?php endif; ?>
            <?php if ($success_msg !== ''): ?>
            window.history.replaceState(null, '', window.location.pathname);   // drop ?walkin_id / apt_id once saved
            <?php endif; ?>
        });
    </script>
</body>
</html>