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
require_capability(['can_log_maternal', 'can_view_maternal_history']);

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('maternal_care');

mh_ensure_patient_name_columns($conn);   // adds patient_name / nullable patient_id if the migration was never run
$can_log          = mh_has_capability('can_log_maternal');
$can_view_history = mh_has_capability('can_view_maternal_history');

$user_id = $_SESSION['user_id'];

$success_msg = ""; $error_msg = "";

if (isset($_POST['add_prenatal']) && !$can_log) {
    mh_deny_access(); // view-only accounts cannot create records
}

if(isset($_POST['add_prenatal'])) {
    $pn = mh_read_patient_name_post();            // Last Name / First Name / MI
    $patient_name = $pn['name'];                   // stored as "Last, First M."
    $name_error   = $pn['error'];
    $visit_date   = $_POST['visit_date'] ?? '';

    // Prenatal care is for female patients: refuse BEFORE anything is saved when the (registered) patient is male.
    if ($name_error === null) {
        $pre_ctx = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'maternal_care');
        $name_error = mh_maternal_sex_error($conn, mh_queue_resolve_patient($conn, $pre_ctx, $pn));
    }

    // Helpers: empty form value -> SQL NULL; ISO date check for date columns.
    $txt  = function ($k) { $v = trim($_POST[$k] ?? ''); return $v === '' ? null : $v; };
    $date = function ($k) { $v = trim($_POST[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };

    if ($name_error !== null) {
        $error_msg = $name_error;
    } else {
        // Typed name is stored as-is; patient_id only when exactly one
        // registered patient matches (else NULL).
        // Opened from Patient Queueing > Proceed? The queue entry decides the patient (name unchanged)
        // and is marked Completed once this record is saved.
        $queue_ctx_post = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'maternal_care');
        $patient_id = mh_queue_resolve_patient($conn, $queue_ctx_post, $pn);

        $gravida = $txt('gravida');  $para = $txt('para');  $aog = $txt('aog');
        $lmp = $date('lmp');         $edc = $date('edc');
        $tt1 = $date('tt1_date');    $tt2 = $date('tt2_date'); $tt3 = $date('tt3_date');
        $tt4 = $date('tt4_date');    $tt5 = $date('tt5_date');
        $bp = $txt('blood_pressure'); $weight = $txt('weight'); $height = $txt('height');
        $bmi = $txt('bmi');           $fh_fht = $txt('fh_fht');  $notes = $txt('notes');
        $next_visit_raw = trim($_POST['next_visit_date'] ?? '');
        $next_visit = $date('next_visit_date');

        $stmt = $conn->prepare("INSERT INTO maternal_records
            (patient_id, patient_name, midwife_id, visit_date, gravida, para, lmp, edc, aog, blood_pressure, weight, height, bmi, fh_fht, tt1_date, tt2_date, tt3_date, tt4_date, tt5_date, notes, next_visit_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isissssssssssssssssss',
            $patient_id, $patient_name, $user_id, $visit_date, $gravida, $para, $lmp, $edc, $aog, $bp, $weight, $height, $bmi, $fh_fht,
            $tt1, $tt2, $tt3, $tt4, $tt5, $notes, $next_visit);

        if($stmt->execute()) {
            $record_id = $conn->insert_id;
            $success_msg = "Prenatal record saved successfully!";
            if ($queue_ctx_post && $patient_id !== null && (int) $patient_id === (int) $queue_ctx_post['patient_id']
                && mh_queue_complete($conn, $queue_ctx_post['id'], $patient_id)) {
                $success_msg .= " Queue" . (!empty($queue_ctx_post['queue_number']) ? " #" . (int) $queue_ctx_post['queue_number'] : "") . " marked as Completed.";
                logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Patient Queueing Update", "Completed queue item #" . (int) $queue_ctx_post['id'] . " after saving the prenatal record.");
            }
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Added Prenatal Record", "Logged maternal checkup for " . $patient_name . ($patient_id ? " (Patient ID #$patient_id)." : " (not linked to a registered patient)."));

            if ($next_visit_raw !== '' && $patient_id === null) {
                $success_msg .= " (No SMS reminder queued: the typed name did not match exactly one registered patient.)";
            } elseif ($next_visit_raw !== '') {
                $sms = mh_schedule_followup_sms($conn, $patient_id, $next_visit_raw, 'Prenatal Care', $record_id);
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
    }
}

// Pre-fill when arriving from Walk-In / Queue (?walkin_id=).
$prefill_name_parts = [];
$queue_ctx = null; $queue_open_modal = false; $queue_prefill = []; $queue_label = '';
if ($can_log && $success_msg === '' && (isset($_GET['walkin_id']) || isset($_GET['apt_id']))) {
    $q_patient = (int) ($_GET['walkin_id'] ?? 0);
    $queue_ctx = mh_queue_open_context($conn, $_GET['apt_id'] ?? 0, 'maternal_care', $q_patient);
    if ($queue_ctx) { $q_patient = (int) $queue_ctx['patient_id']; }
    $profile = $q_patient > 0 ? mh_queue_patient_profile($conn, $q_patient) : null;
    if ($profile) {
        $queue_open_modal   = true;
        $prefill_name_parts = mh_patient_name_parts_by_id($conn, $q_patient);
        $queue_label        = $profile['last_name'] . ', ' . $profile['first_name'];
        $pf = [];

        // Latest prenatal record: pregnancy profile and tetanus doses carry over.
        $st = $conn->prepare("SELECT * FROM maternal_records WHERE patient_id = ? ORDER BY visit_date DESC, id DESC LIMIT 1");
        if ($st) {
            $st->bind_param('i', $q_patient); $st->execute();
            $last = $st->get_result()->fetch_assoc(); $st->close();
            if ($last) {
                foreach (['gravida','para','lmp','edc','tt1_date','tt2_date','tt3_date','tt4_date','tt5_date','height'] as $k) {
                    $v = (string) ($last[$k] ?? '');
                    if ($v !== '' && strpos($v, '0000-00-00') !== 0) { $pf[$k] = $v; }
                }
                if (isset($pf['height'])) { $pf['height'] = mh_queue_number($pf['height']); }
                // Age of gestation, counted from the LMP, as of today.
                if (!empty($pf['lmp'])) {
                    $days = (int) floor((time() - strtotime($pf['lmp'])) / 86400);
                    if ($days >= 0 && $days <= 300) { $pf['aog'] = floor($days / 7) . ' wks'; }
                }
            }
        }
        // Vital signs taken today (Vital Signs page) override the old height.
        $vit = mh_queue_today_vitals($conn, $q_patient);
        if ($vit) {
            $pf['blood_pressure'] = $vit['bp'] ?? '';
            $pf['weight'] = mh_queue_number($vit['weight']);
            if (mh_queue_number($vit['height']) !== '') { $pf['height'] = mh_queue_number($vit['height']); }
        }
        $queue_prefill = mh_queue_clean_prefill($pf);
    }
}

$prenatal_logs = !$can_view_history ? false : $conn->query("
    SELECT m.*, p.first_name, p.middle_name, p.last_name, s.last_name as mw_name 
    FROM maternal_records m 
    LEFT JOIN patients p ON m.patient_id = p.id 
    LEFT JOIN staff s ON m.midwife_id = s.id 
    ORDER BY m.visit_date DESC, m.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maternal Care - <?php echo htmlspecialchars($system_name ?? ''); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    
    
    <style>
        .section-title { font-size: 0.85rem; color: #4ca1af; text-transform: uppercase; font-weight: bold; letter-spacing: 1px; margin: 20px 0 10px 0; border-bottom: 2px solid var(--border-color, #cbd5e1); padding-bottom: 5px; }
        
        .grid-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
        .grid-5 label { font-size: 0.75rem; color: var(--text-muted, #64748b); text-transform: uppercase; }
        .grid-5 input { padding: 8px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 4px; width: 100%; font-size: 0.9rem; }
        
        .tt-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; background: #fffbeb; padding: 15px; border-radius: 8px; border: 1px dashed #fcd34d; }
        .tt-grid label { font-size: 0.8rem; color: #b45309; font-weight: bold; }
        .tt-grid input { padding: 6px; border: 1px solid #fde68a; border-radius: 4px; width: 100%; font-size: 0.85rem; }

        .vitals-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; background: #f0fdf4; padding: 15px; border-radius: 8px; border: 1px dashed #bbf7d0; }
        .vitals-grid label { font-size: 0.8rem; color: #166534; font-weight: bold; }
        .vitals-grid input { padding: 8px; border: 1px solid #bbf7d0; border-radius: 4px; width: 100%; font-size: 0.95rem; }


    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
    <link rel="stylesheet" href="../assets/css/dept_ui.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/dept_ui.css'); ?>">
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container du-wrap">
            
            <?php echo mh_flash_scripts($success_msg ?? '', $error_msg ?? ''); ?>

            <div class="du-pagehead">
                <div><h1>Prenatal Care Records</h1><p>Prenatal visits and maternal health records.</p></div>
                <div class="du-actions">
                        <?php if($can_view_history): ?>
                        <input type="text" id="searchPrenatal" class="search-box" placeholder="Search mother's name..." onkeyup="filterTable()">
                        <?php endif; ?>
                        <?php if($can_log): ?>
                        <button class="du-btn du-btn-primary" onclick="openModal('addPrenatalModal')"><?php echo mh_icon('plus'); ?> New Prenatal Record</button>
                        <?php endif; ?>
                    </div>
            </div>
            <div class="du-card">
                
                <?php if($can_view_history): ?>
                <div class="table-scroll">
                <table class="unified-table" id="prenatalTable">
                    <thead>
                        <tr>
                            <th>Date & AOG</th>
                            <th>Mother's Name</th>
                            <th>Vitals (BP/Wt/Ht/BMI)</th>
                            <th>FH / FHT</th>
                            <th>Attending Midwife</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($prenatal_logs && $prenatal_logs->num_rows > 0): ?>
                            <?php while($row = $prenatal_logs->fetch_assoc()): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <strong style="color: var(--text-muted, #64748b);"><?php echo date("M d, Y", strtotime($row['visit_date'])); ?></strong><br>
                                    <span style="font-size: 0.85rem; color: var(--text-muted, #64748b);">AOG: <?php echo htmlspecialchars($row['aog'] ?? '--'); ?></span>
                                </td>
                                <td>
                                    <strong style="color: var(--heading-color, #1e293b); font-size: 1.05rem;"><?php echo htmlspecialchars(mh_record_patient_label($row)); ?></strong><br>
                                    <span style="font-size: 0.8rem; color: var(--text-muted, #64748b);">G: <?php echo htmlspecialchars($row['gravida'] ?? ''); ?> | P: <?php echo htmlspecialchars($row['para'] ?? ''); ?></span>
                                </td>
                                <td style="white-space: nowrap; color: var(--text-main, #334155); font-size: 0.85rem; line-height: 1.6;">
                                    <strong>BP:</strong> <span style="color: #0369a1;"><?php echo htmlspecialchars($row['blood_pressure'] ?? '--'); ?></span><br>
                                    <strong>Wt:</strong> <?php echo htmlspecialchars($row['weight'] ?? '--'); ?> | <strong>Ht:</strong> <?php echo htmlspecialchars($row['height'] ?? '--'); ?><br>
                                    <strong>BMI:</strong> <span style="font-weight: bold; color: #166534;"><?php echo htmlspecialchars($row['bmi'] ?? '--'); ?></span>
                                </td>
                                <td>
                                    <strong style="color: #b91c1c; font-size: 1.1rem;"><?php echo htmlspecialchars($row['fh_fht'] ?? 'Not recorded'); ?></strong>
                                </td>
                                <td>
                                    <span style="display: block; font-weight: bold; color: var(--text-main, #334155);">MW. <?php echo htmlspecialchars($row['mw_name'] ?? ''); ?></span>
                                    <?php if(!empty($row['notes'])): ?>
                                        <span style="display:block; font-size: 0.75rem; color: var(--text-muted, #64748b); font-style: italic; margin-top: 5px;">"<?php echo htmlspecialchars($row['notes']); ?>"</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-state"><td colspan="5">No prenatal visits recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div style="padding: 40px 24px; text-align: center; color: var(--text-muted);">You can log new prenatal visits here, but your account does not include permission to view the history.</div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php if($can_log): /* intake form, vitals inputs, OCR box and save button exist only with the Log permission */ ?>
    <div class="modal-overlay" id="addPrenatalModal">
        <div class="modal-content du-form-modal" style="max-width: 800px; overflow: visible;">
            <span class="close-btn" onclick="closeModal('addPrenatalModal')">&times;</span>
            <div class="du-mhead"><h3><?php echo htmlspecialchars($system_name ?? ''); ?></h3><h2>PRENATAL RECORD FORM</h2></div>
            
            <!-- OCR Quick Fill Widget (toggle: use_ocr_scanner) -->
            <?php if (mh_has_permission('use_ocr_scanner')): ?>
            <div class="du-ocrbox">
                <input type="file" id="ocrFile" accept="image/*" style="display: none;" onchange="handleOcrFileSelected(this)">
                <div class="du-ocr-title"><?php echo mh_icon('camera'); ?> Auto-fill from a scanned prenatal record (OCR)</div>
                <p class="du-ocr-help">Upload a photo or scan and the form fills in. Review everything before saving.</p>
                <div class="du-ocr-row">
                    <button type="button" class="du-btn du-btn-ghost du-btn-sm" onclick="$('#ocrFile').click()">Choose file</button>
                    <span id="ocrFileName" class="du-ocr-file">No file chosen</span>
                    <button type="button" class="du-btn du-btn-primary du-btn-sm" onclick="triggerOcrScan()">Scan &amp; auto-fill</button>
                </div>
                <div id="ocrStatus" style="font-size: 0.85rem; margin-top: 10px; text-align: center; display: none;"></div>
                <div id="ocrMatchBadge" style="display:none; margin-top: 10px;"></div>
            </div>
            <?php endif; ?>

            <?php if ($queue_ctx) { mh_queue_render_banner($queue_ctx, $queue_label, !empty($queue_prefill)); } ?>
            <form method="POST">
                <?php if ($queue_ctx): ?><input type="hidden" name="apt_id" value="<?php echo (int) $queue_ctx['id']; ?>"><?php endif; ?>
                <div class="form-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 10px;">
                    <div class="form-group">
                        
                        <?php mh_render_patient_name_fields('Mother Name', $prefill_name_parts, 'width: 100%; box-sizing: border-box; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);'); ?>
                        
                        

                    </div>
                    <div class="form-group">
                        <label>Visit Date <span style="color:red;">*</span></label>
                        <input type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                </div>

                <div class="section-title">Pregnancy Profile</div>
                <div class="grid-5">
                    <div><label>G (Gravida)</label><input type="text" name="gravida" placeholder="e.g., 2"></div>
                    <div><label>P (Para)</label><input type="text" name="para" placeholder="e.g., 1"></div>
                    <div><label>LMP</label><input type="date" name="lmp" max="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label>EDC</label><input type="date" name="edc"></div>
                    <div><label>AOG</label><input type="text" name="aog" placeholder="e.g., 24 wks"></div>
                </div>

                <div class="section-title">Tetanus Toxoid Status</div>
                <div class="tt-grid">
                    <div><label>TT1 Date</label><input type="date" name="tt1_date" max="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label>TT2 Date</label><input type="date" name="tt2_date" max="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label>TT3 Date</label><input type="date" name="tt3_date" max="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label>TT4 Date</label><input type="date" name="tt4_date" max="<?php echo date('Y-m-d'); ?>"></div>
                    <div><label>TT5 Date</label><input type="date" name="tt5_date" max="<?php echo date('Y-m-d'); ?>"></div>
                </div>

                <div class="section-title">Today's Vitals & Exam</div>
                <div class="vitals-grid">
                    <div><label>DATE</label><input type="text" value="Auto-filled" disabled style="background:#e2e8f0; color:var(--text-muted, #64748b);"></div>
                    <div><label>BP</label><input type="text" name="blood_pressure" placeholder="e.g., 110/70"></div>
                    <div><label>WEIGHT (kg)</label><input type="number" step="0.1" name="weight" id="calcWt" placeholder="e.g., 65" onkeyup="calculateBMI()"></div>
                    <div><label>HEIGHT (cm)</label><input type="number" step="0.1" name="height" id="calcHt" placeholder="e.g., 160" onkeyup="calculateBMI()"></div>
                    <div><label>BMI</label><input type="text" name="bmi" id="calcBmi" placeholder="Auto-calculated" readonly style="background:#e2e8f0; font-weight:bold;"></div>
                </div>
                
                <div class="form-group" style="margin-top: 15px;">
                    <label style="font-weight: bold; color: var(--heading-color, #1e293b);">FH / FHT (Fundal Height / Fetal Heart Tone)</label>
                    <input type="text" name="fh_fht" placeholder="e.g., 24 cm / 140 bpm" style="width: 100%; padding: 12px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1); font-size: 1.05rem;">
                </div>

                <div class="form-group" style="margin-top: 15px; margin-bottom: 20px;">
                    <label>Remarks / Notes</label>
                    <textarea name="notes" rows="2" placeholder="Any additional instructions or vitamins prescribed..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);"></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Follow-up / Return Date <span style="color:var(--text-muted, #64748b); font-weight:normal;">(Optional)</span></label>
                    <input type="date" name="next_visit_date" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    <small style="color:var(--text-muted, #64748b);">If set, the patient automatically gets an SMS reminder the day before and the morning of this date.</small>
                </div>

                <button type="submit" class="du-btn du-btn-primary du-btn-block" name="add_prenatal" data-confirm="Please double-check the details. Save this record?" data-confirm-title="Save prenatal visit?" data-confirm-type="info" data-confirm-ok="Save">Save Prenatal Record</button>
            </form>
        </div>
    </div>

    <?php endif; // can_log ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        // A male patient picked from the search box cannot be added to Maternal Care.
        document.addEventListener('mh:patient-picked', function (e) {
            if (e.detail && String(e.detail.sex).toLowerCase() === 'male') {
                mh_alert('Maternal Care is for female patients. ' + e.detail.first_name + ' ' + e.detail.last_name + ' is registered as male.', 'Cannot add to Maternal Care', 'warning');
                var hid = document.getElementById('pnPickedId'); if (hid) { hid.value = ''; }
                var badge = document.querySelector('.mh-ps-badge'); if (badge) { badge.hidden = true; badge.innerHTML = ''; }
                ['pnLast', 'pnFirst', 'pnMI'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.value = ''; } });
            }
        });
        const MH_ICON_CHECK = <?php echo json_encode(mh_icon('check')); ?>;
        const MH_ICON_X = <?php echo json_encode(mh_icon('x')); ?>;
        const MH_ICON_CHECK_CIRCLE = <?php echo json_encode(mh_icon('check-circle')); ?>;
        const MH_ICON_X_CIRCLE = <?php echo json_encode(mh_icon('x-circle')); ?>;
        const MH_ICON_WARN = <?php echo json_encode(mh_icon('alert-triangle')); ?>;
        const MH_ICON_REFRESH = <?php echo json_encode(mh_icon('refresh-cw')); ?>;


        function handleOcrFileSelected(input) {
            if (input.files && input.files[0]) {
                $('#ocrFileName').text(input.files[0].name);
            } else {
                $('#ocrFileName').text('No file chosen');
            }
        }

        function triggerOcrScan() {
            let fileInput = document.getElementById('ocrFile');
            if (!fileInput.files || fileInput.files.length === 0) {
                mh_alert('Please select or capture an image of the immunization document first.', 'Notice', 'info');
                return;
            }

            let formData = new FormData();
            formData.append('ocr_image', fileInput.files[0]);

            let statusDiv = $('#ocrStatus');
            statusDiv.show().html('<span style="color: #0284c7; display:inline-flex; align-items:center; gap:6px;">' + MH_ICON_REFRESH + ' Processing document via OCR... Please wait.</span>');

            fetch('ocr/maternal_scan.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.html('<span style="color: #16a34a; display:inline-flex; align-items:center; gap:6px;">' + MH_ICON_CHECK_CIRCLE + ' Document scanned successfully! Fields auto-filled.</span>');
                    
                    if (data.fields.gravida) $('input[name="gravida"]').val(data.fields.gravida);
                    if (data.fields.para) $('input[name="para"]').val(data.fields.para);
                    if (data.fields.lmp) $('input[name="lmp"]').val(data.fields.lmp);
                    if (data.fields.edc) $('input[name="edc"]').val(data.fields.edc);
                    if (data.fields.aog) $('input[name="aog"]').val(data.fields.aog);
                    if (data.fields.blood_pressure) $('input[name="blood_pressure"]').val(data.fields.blood_pressure);
                    if (data.fields.weight) {
                        $('input[name="weight"]').val(data.fields.weight);
                        calculateBMI();
                    }
                    if (data.fields.height) {
                        $('input[name="height"]').val(data.fields.height);
                        calculateBMI();
                    }
                    if (data.fields.fh_fht) $('input[name="fh_fht"]').val(data.fields.fh_fht);
                    if (data.fields.notes) $('textarea[name="notes"]').val(data.fields.notes);

                    applyOcrPatientMatch(data.matched_patient, data.extracted_identity);
                } else {
                    statusDiv.html('<span style="color: #dc2626; display:inline-flex; align-items:center; gap:6px;">' + MH_ICON_X_CIRCLE + ' ' + (data.message || 'Failed to parse document.') + '</span>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                statusDiv.html('<span style="color: #dc2626; display:inline-flex; align-items:center; gap:6px;">' + MH_ICON_X_CIRCLE + ' An error occurred during OCR processing.</span>');
            });
        }

        // Pre-selects a matched patient (via select2) and shows a badge, or
        // offers a one-click hand-off to Walk-In New Registration when
        // nothing in the patients table matched what the OCR read.
        function applyOcrPatientMatch(matchedPatient, extractedIdentity) {
            const badge = $('#ocrMatchBadge');
            if (!badge.length) return;

            if (matchedPatient && matchedPatient.id) {
                mhSetPatientName(matchedPatient.first_name, matchedPatient.middle_name, matchedPatient.last_name);
                badge.show().html('<div style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:8px 12px; border-radius:6px; font-size:0.85rem;">'
                    + MH_ICON_CHECK_CIRCLE + ' Existing patient matched: <strong>' + escapeHtml(matchedPatient.full_name) + '</strong> (ID #' + matchedPatient.id + ')'
                    + '</div>');
            } else if (extractedIdentity && (extractedIdentity.first_name || extractedIdentity.last_name)) {
                const params = new URLSearchParams({
                    prefill_fname: extractedIdentity.first_name || '',
                    prefill_lname: extractedIdentity.last_name || '',
                    prefill_bdate: extractedIdentity.birthdate || ''
                });
                badge.show().html('<div style="background:#fff7ed; border:1px dashed #fb923c; color:#9a3412; padding:8px 12px; border-radius:6px; font-size:0.85rem;">'
                    + MH_ICON_WARN + ' No existing record found for this patient.<br>'
                    + '<a href="walkin.php?' + params.toString() + '" target="_blank" style="display:inline-block; margin-top:6px; background:#ea580c; color:white; padding:6px 12px; border-radius:4px; text-decoration:none; font-weight:bold;">Auto-fill into New Patient Registration</a>'
                    + '</div>');
            } else {
                badge.hide().html('');
            }
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        window.onload = function() {
            <?php if($queue_open_modal): ?>
                openModal('addPrenatalModal');   // name is pre-filled server-side; the rest from the patient's records
                var qp = <?php echo json_encode($queue_prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                Object.keys(qp).forEach(function (n) {
                    var el = document.querySelector('#addPrenatalModal form [name="' + n + '"]');
                    if (el && !el.readOnly) { el.value = qp[n]; }
                });
                calculateBMI();
            <?php endif; ?>
            <?php if($success_msg !== ''): ?>
                window.history.replaceState(null, '', window.location.pathname);   // drop ?walkin_id / apt_id once saved
            <?php endif; ?>
        };

        function calculateBMI() {
            var wt = parseFloat(document.getElementById('calcWt').value);
            var ht_cm = parseFloat(document.getElementById('calcHt').value);
            
            if(wt > 0 && ht_cm > 0) {
                var ht_m = ht_cm / 100;
                var bmi = wt / (ht_m * ht_m);
                document.getElementById('calcBmi').value = bmi.toFixed(2);
            } else {
                document.getElementById('calcBmi').value = "";
            }
        }

        function filterTable() {
            var input = document.getElementById("searchPrenatal").value.toUpperCase();
            var tr = document.getElementById("prenatalTable").getElementsByTagName("tr");
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