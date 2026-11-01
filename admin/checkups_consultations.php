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
mh_sms_install($conn); // creates scheduled_sms / sms_logs on first use

// Granular: logging new entries and reading the history are separate permissions.
require_capability(['can_log_consultations', 'can_view_consultation_history']);

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('checkups');

mh_ensure_patient_name_columns($conn);   // adds patient_name / nullable patient_id if the migration was never run
$can_log          = mh_has_capability('can_log_consultations');
$can_view_history = mh_has_capability('can_view_consultation_history');

$system_name = $system_name ?? mh_setting('system_name');

$user_id = $_SESSION['user_id']; 

$success_msg = ""; 
$error_msg = "";

if (isset($_POST['add_consultation']) && !$can_log) {
    mh_deny_access(); // view-only accounts cannot create records
}

if(isset($_POST['add_consultation'])) {
    $pn = mh_read_patient_name_post();            // Last Name / First Name / MI
    $patient_name = $pn['name'];                   // stored as "Last, First M."
    $name_error   = $pn['error'];
    $visit_date   = $_POST['visit_date'] ?? '';

    // Temperature: numbers / decimals only (e.g. 36.8) - checked before anything is saved.
    $temp_check = mh_clean_temperature($_POST['temp'] ?? '');
    if ($name_error === null && !$temp_check['ok']) { $name_error = $temp_check['error']; }

    if ($name_error !== null) {
        $_SESSION['error_msg'] = $name_error;
        header("Location: checkups_consultations.php");
        exit();
    }

    // Typed name is stored as-is; patient_id only when exactly one
    // registered patient matches (else NULL).
    // Opened from Patient Queueing > Proceed? The queue entry decides the patient (name unchanged)
    // and is marked Completed once this consultation is saved.
    $queue_ctx_post = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'checkups');
    $patient_id = mh_queue_resolve_patient($conn, $queue_ctx_post, $pn);

    $txt = function ($k) { $v = trim($_POST[$k] ?? ''); return $v === '' ? null : $v; };
    $weight = $txt('weight'); $height = $txt('height'); $temp = ($temp_check['value'] === '' ? null : $temp_check['value']); $bp = $txt('bp');
    $complaints   = trim($_POST['chief_complaints'] ?? '');
    $diagnosis    = trim($_POST['diagnosis'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $next_visit_raw = trim($_POST['next_visit_date'] ?? '');
    $next_visit = preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_visit_raw) ? $next_visit_raw : null;

    $stmt = $conn->prepare("INSERT INTO consultations (patient_id, patient_name, doctor_id, visit_date, weight, height, temp, bp, chief_complaints, diagnosis, prescription, notes, next_visit_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isissssssssss', $patient_id, $patient_name, $user_id, $visit_date, $weight, $height, $temp, $bp,
        $complaints, $diagnosis, $prescription, $notes, $next_visit);

    if($stmt->execute()) {
        $record_id = $conn->insert_id;
        $_SESSION['success_msg'] = "Consultation record saved successfully!";
        if ($queue_ctx_post && $patient_id !== null && (int) $patient_id === (int) $queue_ctx_post['patient_id']
            && mh_queue_complete($conn, $queue_ctx_post['id'], $patient_id)) {
            $_SESSION['success_msg'] .= " Queue" . (!empty($queue_ctx_post['queue_number']) ? " #" . (int) $queue_ctx_post['queue_number'] : "") . " marked as Completed.";
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Update", "Completed queue item #" . (int) $queue_ctx_post['id'] . " after saving the consultation.");
        }
        logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Added Consultation", "Logged general checkup for " . $patient_name . ($patient_id ? " (Patient ID #$patient_id)." : " (not linked to a registered patient)."));

        if ($next_visit_raw !== '' && $patient_id === null) {
            $_SESSION['success_msg'] .= " (No SMS reminder queued: the typed name did not match exactly one registered patient.)";
        } elseif ($next_visit_raw !== '') {
            $sms = mh_schedule_followup_sms($conn, $patient_id, $next_visit_raw, 'Check-up', $record_id);
            if ($sms['queued'] > 0) {
                $_SESSION['success_msg'] .= " " . $sms['queued'] . " SMS reminder(s) queued for the patient's follow-up.";
            } elseif ($sms['skipped_reason']) {
                $_SESSION['success_msg'] .= " (No SMS reminder queued: " . $sms['skipped_reason'] . ")";
            }
        }
    } else {
        $_SESSION['error_msg'] = "Error saving record: " . $stmt->error;
    }
    $stmt->close();
    header("Location: checkups_consultations.php");
    exit();
}

if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']); 
}
if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Pre-fill when arriving from Walk-In / Queue (?walkin_id=).
$prefill_name_parts = [];
$queue_ctx = null; $queue_open_modal = false; $queue_prefill = []; $queue_label = '';
if ($can_log && $success_msg === '' && (isset($_GET['walkin_id']) || isset($_GET['apt_id']))) {
    $q_patient = (int) ($_GET['walkin_id'] ?? 0);
    $queue_ctx = mh_queue_open_context($conn, $_GET['apt_id'] ?? 0, 'checkups', $q_patient);
    if ($queue_ctx) { $q_patient = (int) $queue_ctx['patient_id']; }
    $profile = $q_patient > 0 ? mh_queue_patient_profile($conn, $q_patient) : null;
    if ($profile) {
        $queue_open_modal   = true;
        $prefill_name_parts = mh_patient_name_parts_by_id($conn, $q_patient);
        $queue_label        = $profile['last_name'] . ', ' . $profile['first_name'];
        $pf = [];

        // Vital signs taken today (Vital Signs page).
        $vit = mh_queue_today_vitals($conn, $q_patient);
        if ($vit) {
            $pf['weight'] = $vit['weight'] ?? '';
            $pf['height'] = $vit['height'] ?? '';
            $pf['temp']   = $vit['temp'] ?? '';
            $pf['bp']     = $vit['bp'] ?? '';
        }
        // No height today? Use the one from the last consultation.
        if (empty($pf['height'])) {
            $st = $conn->prepare("SELECT height FROM consultations WHERE patient_id = ? AND height IS NOT NULL AND height <> '' ORDER BY visit_date DESC, id DESC LIMIT 1");
            if ($st) {
                $st->bind_param('i', $q_patient); $st->execute();
                $pf['height'] = $st->get_result()->fetch_assoc()['height'] ?? '';
                $st->close();
            }
        }
        // The reason given when the patient joined the queue (skip the generic walk-in text).
        if ($queue_ctx && trim((string) $queue_ctx['reason']) !== '' && $queue_ctx['reason'] !== 'Walk-In Queue Registration') {
            $pf['chief_complaints'] = $queue_ctx['reason'];
        }
        $queue_prefill = mh_queue_clean_prefill($pf);
    }
}

$consultations = !$can_view_history ? false : $conn->query("
    SELECT c.*, p.first_name, p.middle_name, p.last_name, s.last_name as doc_name, s.role 
    FROM consultations c 
    LEFT JOIN patients p ON c.patient_id = p.id 
    LEFT JOIN staff s ON c.doctor_id = s.id 
    ORDER BY c.visit_date DESC, c.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Consultations - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .vitals-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; background: var(--bg-hover); padding: 15px; border-radius: 8px; border: 1px dashed var(--border-color, #cbd5e1); margin-bottom: 15px;}
        .vitals-grid label { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .vitals-grid input { padding: 8px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 4px; width: 100%; font-size: 0.95rem; background: var(--bg-input); color: var(--text-main); }
        .clinical-notes { color: var(--text-main); font-size: 0.9rem; line-height: 1.5; }
        .clinical-notes strong { color: var(--heading-color, #0f172a); }
        
        .filter-radios { display: flex; gap: 10px; align-items: center;}
        .filter-radios label { cursor: pointer; display: flex; align-items: center; gap: 4px; font-weight: bold; color: var(--text-main); font-size: 0.9rem;}
        .filter-radios input[type="radio"] { accent-color: #4ca1af; cursor: pointer; width: 15px; height: 15px; margin: 0;}
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
    <link rel="stylesheet" href="../assets/css/dept_ui.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/dept_ui.css'); ?>">
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container du-wrap">
            
            <div class="du-pagehead">
                <div><h1>General Consultation Records</h1><p>General consultations, vitals and clinical notes.</p></div>
                <div class="du-actions">
                        <?php if($can_view_history): ?>
                        <input type="text" id="searchCheckup" class="search-box" placeholder="Search patient..." onkeyup="filterTable()">
                        <?php endif; ?>
                        <?php if($can_log): ?>
                        <button class="du-btn du-btn-primary" onclick="openModal('addCheckupModal')"><?php echo mh_icon('plus'); ?> New Consultation Record</button>
                        <?php endif; ?>
                    </div>
            </div>
            <div class="du-card">
                
                <?php if($can_view_history): ?>
                <div class="table-scroll">
                <table class="unified-table" id="checkupTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Patient Name</th>
                            <th>Vitals (Wt/Ht/Temp/BP)</th>
                            <th>Clinical Notes</th>
                            <th>Attending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($consultations && $consultations->num_rows > 0): ?>
                            <?php while($row = $consultations->fetch_assoc()): ?>
                            <tr>
                                <td style="white-space: nowrap;"><strong style="color: var(--primary, #4ca1af);"><?php echo date("M d, Y", strtotime($row['visit_date'])); ?></strong></td>
                                <td><strong style="color: var(--heading-color, #0f172a); font-size: 1.05rem;"><?php echo htmlspecialchars(mh_record_patient_label($row)); ?></strong></td>
                                
                                <td style="white-space: nowrap; color: var(--text-main); font-size: 0.85rem; line-height: 1.6;">
                                    <strong>Wt:</strong> <?php echo htmlspecialchars($row['weight'] ?? '--'); ?><br>
                                    <strong>Ht:</strong> <?php echo htmlspecialchars($row['height'] ?? '--'); ?><br>
                                    <strong>T:</strong> <span class="text-danger"><?php echo htmlspecialchars($row['temp'] ?? '--'); ?></span><br>
                                    <strong>BP:</strong> <span class="text-info"><?php echo htmlspecialchars($row['bp'] ?? '--'); ?></span>
                                </td>
                                
                                <td class="clinical-notes" style="max-width: 300px;">
                                    <div style="margin-bottom: 5px;"><strong>Complaint:</strong> <?php echo htmlspecialchars($row['chief_complaints'] ?? $row['symptoms'] ?? 'None recorded'); ?></div>
                                    <div class="text-success" style="margin-bottom: 5px;"><strong>Diagnosis:</strong> <?php echo htmlspecialchars($row['diagnosis'] ?? 'N/A'); ?></div>
                                    <?php if(!empty($row['prescription'])): ?>
                                        <div class="text-warning"><strong>Rx:</strong> <?php echo htmlspecialchars($row['prescription']); ?></div>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <span style="display: block; font-weight: bold; color: var(--heading-color, #334155);">Dr. <?php echo htmlspecialchars($row['doc_name'] ?? 'Unknown'); ?></span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-state"><td colspan="5">No consultations recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div style="padding: 40px 24px; text-align: center; color: var(--text-muted);">You can log new consultations here, but your account does not include permission to view the history.</div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php if($can_log): /* intake form, vitals inputs, OCR box and save button exist only with the Log permission */ ?>
    <div class="modal-overlay" id="addCheckupModal">
        <div class="modal-content du-form-modal" style="max-width: 700px;">
            <span class="close-btn" onclick="closeModal('addCheckupModal')">&times;</span>
            <div class="du-mhead"><h3><?php echo htmlspecialchars($system_name ?? ''); ?></h3><h2>GENERAL CONSULTATION FORM</h2></div>
            
            <!-- AI OCR Scanner Integration Box (toggle: use_ocr_scanner) -->
            <?php if (mh_has_permission('use_ocr_scanner')): ?>
            <div class="du-ocrbox">
                <div class="du-ocr-title"><?php echo mh_icon('camera'); ?> Auto-fill from a scanned medical record (OCR)</div>
                <p class="du-ocr-help">Upload a photo or scan and the form fills in. Review everything before saving.</p>
                <div class="du-ocr-row">
                    <input type="file" id="ocr_file" accept="image/*">
                    <button type="button" id="ocr_btn" class="du-btn du-btn-primary du-btn-sm" onclick="runOCR()">Scan &amp; auto-fill</button>
                </div>
                <small id="ocr_status" class="du-ocr-status"></small>
                <div id="ocr_match_badge" style="display:none; margin-top: 8px;"></div>
            </div>
            <?php endif; ?>

            <?php if ($queue_ctx) { mh_queue_render_banner($queue_ctx, $queue_label, !empty($queue_prefill)); } ?>
            <form method="POST">
                <?php if ($queue_ctx): ?><input type="hidden" name="apt_id" value="<?php echo (int) $queue_ctx['id']; ?>"><?php endif; ?>
                <div class="form-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                    
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            
                        </div>

                        <?php mh_render_patient_name_fields('Patient Name', $prefill_name_parts, 'width: 100%; box-sizing: border-box; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 1rem;'); ?>


                    </div>
                    <div class="form-group">
                        <label>Consultation Date <span style="color:red;">*</span></label>
                        <input type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    </div>
                </div>

                <label style="font-weight: bold; color: var(--heading-color, #1e293b); font-size: 0.95rem; display: block; margin-bottom: 5px;">Patient Vitals</label>
                <div class="vitals-grid">
                    <div><label>Weight</label><input type="text" name="weight" placeholder="e.g., 65 kg"></div>
                    <div><label>Height</label><input type="text" name="height" placeholder="e.g., 160 cm"></div>
                    <div><label>Temp</label><input type="text" inputmode="decimal" data-mh-decimal="2" maxlength="5" autocomplete="off" name="temp" placeholder="e.g., 37.2"></div>
                    <div><label>BP</label><input type="text" name="bp" placeholder="e.g., 120/80"></div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Remarks / Notes</label>
                    <textarea name="notes" rows="2" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;" placeholder="Any general remarks before the checkup..."></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Chief Complaints <span style="color:red;">*</span></label>
                    <textarea name="chief_complaints" rows="2" required placeholder="Why is the patient here today?" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;"></textarea>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Diagnosis / Findings <span style="color:red;">*</span></label>
                    <textarea name="diagnosis" rows="2" required placeholder="Doctor's diagnosis..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; border-left: 4px solid #10b981;"></textarea>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Prescription / Treatment</label>
                    <textarea name="prescription" rows="2" placeholder="Medications given or prescribed..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1; border-left: 4px solid #f59e0b;"></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Follow-up / Return Date <span style="color: var(--text-muted); font-weight:normal;">(Optional)</span></label>
                    <input type="date" name="next_visit_date" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                    <small style="color: var(--text-muted);">If set, the patient automatically gets an SMS reminder the day before and the morning of this date.</small>
                </div>

                <button type="submit" class="du-btn du-btn-primary du-btn-block" name="add_consultation" data-confirm="Please double-check the details. Save this record?" data-confirm-title="Save consultation?" data-confirm-type="info" data-confirm-ok="Save">Save Consultation Record</button>
            </form>
        </div>
    </div>

    <?php endif; // can_log ?>


    <script>
        const MH_ICON_CHECK = <?php echo json_encode(mh_icon('check-circle')); ?>;
        const MH_ICON_WARN = <?php echo json_encode(mh_icon('alert-triangle')); ?>;

        window.onload = function() {
            <?php if($queue_open_modal): ?>
                openModal('addCheckupModal');   // name is pre-filled server-side; vitals etc. from the patient's records
                var qp = <?php echo json_encode($queue_prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                Object.keys(qp).forEach(function (n) {
                    var el = document.querySelector('#addCheckupModal form [name="' + n + '"]');
                    if (el) { el.value = qp[n]; }
                });
            <?php endif; ?>

            <?php if($success_msg): ?>
                mh_notify(<?php echo mh_js($success_msg); ?>, 'success');
                window.history.replaceState(null, null, window.location.pathname);
            <?php endif; ?>

            <?php if($error_msg): ?>
                mh_notify(<?php echo mh_js($error_msg); ?>, 'error');
            <?php endif; ?>
        };

        async function runOCR() {
            let fileInput = document.getElementById('ocr_file');
            let statusText = document.getElementById('ocr_status');
            let scanBtn = document.getElementById('ocr_btn');

            if(fileInput.files.length === 0) {
                await mh_alert('Please select an image file first.', 'Notice', 'info');
                return;
            }

            let formData = new FormData();
            formData.append('document', fileInput.files[0]);

            statusText.style.color = "#d97706";
            statusText.innerText = "Analyzing document... Please wait.";
            scanBtn.disabled = true;

            fetch('ocr/checkup_scan.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                scanBtn.disabled = false;
                
                if(data.error) {
                    statusText.style.color = "#dc2626";
                    statusText.innerText = "Error: " + data.error;
                    return;
                }

                statusText.style.color = "#10b981";
                statusText.innerText = "Success! Form populated. Feel free to review or edit fields.";

                const fieldsToMap = ['weight', 'height', 'temp', 'bp', 'chief_complaints', 'diagnosis', 'prescription', 'notes'];

                fieldsToMap.forEach(field => {
                    let element = document.querySelector(`[name="${field}"]`);
                    if (element && data[field]) {
                        element.value = data[field];
                        element.dispatchEvent(new Event('input', { bubbles: true }));   // numeric fields (temp) clean themselves
                    }
                });

                applyOcrPatientMatch(data.matched_patient, data.extracted_identity);
            })
            .catch(err => {
                console.error(err);
                statusText.style.color = "#dc2626";
                statusText.innerText = "A network error occurred. Please try again.";
                scanBtn.disabled = false;
            });
        }

        // Shared by all three OCR-powered modals on this page's siblings:
        // pre-selects a matched patient and shows a badge, or offers a
        // one-click hand-off to Walk-In New Registration when nothing matched.
        function applyOcrPatientMatch(matchedPatient, extractedIdentity) {
            const badge = document.getElementById('ocr_match_badge');
            if (!badge) return;

            if (matchedPatient && matchedPatient.id) {
                mhSetPatientName(matchedPatient.first_name, matchedPatient.middle_name, matchedPatient.last_name);
                badge.style.display = 'block';
                badge.innerHTML = '<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:8px 12px; border-radius:6px; font-size:0.85rem;">'
                    + MH_ICON_CHECK + ' Existing patient matched: <strong>' + escapeHtml(matchedPatient.full_name) + '</strong> (ID #' + matchedPatient.id + ')'
                    + '</div>';
            } else if (extractedIdentity && (extractedIdentity.first_name || extractedIdentity.last_name)) {
                const params = new URLSearchParams({
                    prefill_fname: extractedIdentity.first_name || '',
                    prefill_lname: extractedIdentity.last_name || '',
                    prefill_bdate: extractedIdentity.birthdate || ''
                });
                badge.style.display = 'block';
                badge.innerHTML = '<div style="background:#fff7ed; border:1px dashed #fb923c; color:#9a3412; padding:8px 12px; border-radius:6px; font-size:0.85rem;">'
                    + MH_ICON_WARN + ' No existing record found for this patient.<br>'
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

        function filterTable() {
            var input = document.getElementById("searchCheckup").value.toUpperCase();
            var tr = document.getElementById("checkupTable").getElementsByTagName("tr");
            for (var i = 1; i < tr.length; i++) {
                var td = tr[i].getElementsByTagName("td")[1];
                if (td) {
                    var txtValue = td.textContent || td.innerText;
                    tr[i].style.display = txtValue.toUpperCase().indexOf(input) > -1 ? "" : "none";
                }
            }
        }
        
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    </script>
</body>
</html>