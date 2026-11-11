<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/ui_helpers.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/patient_name_helper.php';
require_once __DIR__ . '/../includes/queue_helper.php';
require_once __DIR__ . '/../includes/sms_helper.php';
require_once __DIR__ . '/../includes/sms/sms_scheduler.php';
require_once __DIR__ . '/../includes/clinical_module_helper.php';
require_once __DIR__ . '/../includes/pagination_helper.php';
mh_sms_install($conn);

require_capability(['can_log_consultations', 'can_view_consultation_history']);

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('checkups');

mh_ensure_patient_name_columns($conn);
$can_log          = mh_has_capability('can_log_consultations');
$can_view_history = mh_has_capability('can_view_consultation_history');
$system_name      = $system_name ?? mh_setting('system_name');
$user_id          = $_SESSION['user_id'];

if (isset($_POST['add_consultation']) && !$can_log) {
    mh_deny_access();
}

if (isset($_POST['add_consultation'])) {
    $pn           = mh_read_patient_name_post();
    $patient_name = $pn['name'];
    $problem      = $pn['error'];
    $visit_date   = $_POST['visit_date'] ?? '';

    $temp_check = mh_clean_temperature($_POST['temp'] ?? '');
    if ($problem === null && !$temp_check['ok']) { $problem = $temp_check['error']; }
    $vc = mh_clean_vitals(['bp' => $_POST['bp'] ?? '', 'weight' => $_POST['weight'] ?? '', 'height' => $_POST['height'] ?? '']);
    if ($problem === null && $vc['errors']) { $problem = implode(' ', $vc['errors']); }
    if ($problem === null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) { $problem = 'Please enter a valid consultation date.'; }

    if ($problem !== null) {
        mh_cm_fail('checkups_consultations.php', 'checkups', $problem);
    }

    $queue_ctx_post = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'checkups');
    $patient_id     = mh_queue_resolve_patient($conn, $queue_ctx_post, $pn);

    $weight      = mh_cm_nullable($vc['values']['weight']);
    $height      = mh_cm_nullable($vc['values']['height']);
    $bp          = mh_cm_nullable($vc['values']['bp']);
    $temp        = mh_cm_nullable($temp_check['value']);
    $complaints  = trim($_POST['chief_complaints'] ?? '');
    $diagnosis   = trim($_POST['diagnosis'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');
    $next_raw    = trim($_POST['next_visit_date'] ?? '');
    $next_visit  = mh_cm_date('next_visit_date');

    if ($diagnosis !== '' && mh_setting('disease_auto_normalize_save') === '1') {
        require_once __DIR__ . '/../includes/disease_stats.php';
        $std = mh_disease_standardize_diagnosis($diagnosis, $conn);
        if (!empty($std['name']) && !empty($std['is_standard']) && stripos($diagnosis, $std['name']) === false) {
            $diagnosis = $std['name'] . ' (' . $diagnosis . ')';
        }
    }

    try {
        $stmt = $conn->prepare("INSERT INTO consultations (patient_id, patient_name, doctor_id, visit_date, weight, height, temp, bp, chief_complaints, diagnosis, prescription, notes, next_visit_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { throw new RuntimeException('prepare failed: ' . $conn->error); }
        $stmt->bind_param('isissssssssss', $patient_id, $patient_name, $user_id, $visit_date, $weight, $height, $temp, $bp,
            $complaints, $diagnosis, $prescription, $notes, $next_visit);
        if (!$stmt->execute()) { throw new RuntimeException('execute failed: ' . $stmt->error); }
        $record_id = (int) $conn->insert_id;
        $stmt->close();

        $_SESSION['success_msg'] = 'Consultation record saved successfully!' . mh_cm_after_save($conn, [
            'ctx' => $queue_ctx_post, 'patient_id' => $patient_id, 'patient_name' => $patient_name, 'record_id' => $record_id,
            'noun' => 'consultation', 'log_action' => 'Added Consultation', 'log_detail' => 'Logged general checkup for',
            'sms_label' => 'Check-up', 'next_visit' => $next_raw,
        ]);
    } catch (Throwable $e) {
        error_log('[MarickHealth] consultation save FAILED (staff #' . (int) $user_id . ', patient ' . var_export($patient_id, true) . '): ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        mh_cm_fail('checkups_consultations.php', 'checkups', 'The consultation record could not be saved, so nothing was changed. Please try again. If it keeps happening, tell the system administrator.');
    }
    header('Location: checkups_consultations.php');
    exit();
}

$flash   = mh_cm_take_flash();
$old     = mh_cm_take_old('checkups');
$queue   = mh_cm_queue_prefill($conn, 'checkups', function (mysqli $conn, $pid, array $profile, $ctx) {
    $pf = mh_cm_vitals_prefill($conn, $pid, ['weight' => 'weight', 'height' => 'height', 'temp' => 'temp', 'bp' => 'bp']);
    if (empty($pf['height'])) {
        $st = $conn->prepare("SELECT height FROM consultations WHERE patient_id = ? AND height IS NOT NULL AND height <> '' ORDER BY visit_date DESC, id DESC LIMIT 1");
        if ($st) {
            $st->bind_param('i', $pid);
            $st->execute();
            $pf['height'] = $st->get_result()->fetch_assoc()['height'] ?? '';
            $st->close();
        }
    }
    $pf['chief_complaints'] = mh_cm_reason_prefill($ctx);
    return $pf;
}, $can_log && $flash['success'] === '');

$search = mh_pg_search(['p.first_name', 'p.last_name', 'c.patient_name', "CONCAT_WS(' ', p.first_name, p.last_name)", 'c.diagnosis', 'c.chief_complaints'], $_GET['q'] ?? '');
$list = $can_view_history ? mh_pg_fetch(
    $conn,
    'c.*, p.first_name, p.middle_name, p.last_name, s.last_name AS doc_name',
    'consultations c LEFT JOIN patients p ON c.patient_id = p.id LEFT JOIN staff s ON c.doctor_id = s.id' . ($search['sql'] ? ' WHERE ' . $search['sql'] : ''),
    'c.visit_date DESC, c.created_at DESC, c.id DESC',
    $search['types'], $search['params']
) : ['rows' => [], 'pg' => mh_pg_state(0)];

require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'General Consultations', 'late_styles' => ['css/dept_ui.css']]);
mh_layout_open();
echo mh_notify_script($flash['success'], 'success');
?>
        <div class="dashboard-container du-wrap">
            <?php mh_cm_header('General Consultation Records', 'General consultations, vitals and clinical notes.', 'Search patient or diagnosis...', $can_view_history, $can_log, 'addCheckupModal', 'New Consultation Record'); ?>

            <div class="du-card">
                <?php if ($can_view_history): ?>
                <div class="table-scroll">
                <table class="unified-table cf-stack" id="checkupTable">
                    <thead>
                        <tr><th>Date</th><th>Patient Name</th><th>Vitals</th><th>Clinical Notes</th><th>Attending</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($list['rows']): ?>
                            <?php foreach ($list['rows'] as $row): ?>
                            <tr>
                                <td data-label="Date" class="cf-nowrap"><strong class="cf-tone-info"><?php echo mh_h(date('M d, Y', strtotime($row['visit_date']))); ?></strong></td>
                                <td data-label="Patient"><span class="cf-cell-main"><?php echo mh_h(mh_record_patient_label($row)); ?></span></td>
                                <td data-label="Vitals" class="cf-nowrap cf-cell-lines">
                                    <div><strong>Wt:</strong> <?php echo mh_h($row['weight'] ?? '--'); ?></div>
                                    <div><strong>Ht:</strong> <?php echo mh_h($row['height'] ?? '--'); ?></div>
                                    <div><strong>T:</strong> <span class="cf-tone-bad"><?php echo mh_h($row['temp'] ?? '--'); ?></span></div>
                                    <div><strong>BP:</strong> <span class="cf-tone-info"><?php echo mh_h($row['bp'] ?? '--'); ?></span></div>
                                </td>
                                <td data-label="Notes" class="cf-cell-lines">
                                    <div><strong>Complaint:</strong> <?php echo mh_h($row['chief_complaints'] ?? $row['symptoms'] ?? 'None recorded'); ?></div>
                                    <div class="cf-tone-ok"><strong>Diagnosis:</strong> <?php echo mh_h($row['diagnosis'] ?? 'N/A'); ?></div>
                                    <?php if (!empty($row['prescription'])): ?>
                                        <div class="cf-tone-warn"><strong>Rx:</strong> <?php echo mh_h($row['prescription']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Attending"><strong>Dr. <?php echo mh_h($row['doc_name'] ?? 'Unknown'); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="empty-state"><td colspan="5"><?php echo ($_GET['q'] ?? '') !== '' ? 'No consultations match your search.' : 'No consultations recorded yet.'; ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <?php mh_pg_render($list['pg'], ['noun' => 'consultations']); ?>
                <?php else: ?>
                <div class="cf-notice">You can log new consultations here, but your account does not include permission to view the history.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php mh_layout_close(); ?>

    <?php if ($can_log): ?>
    <?php mh_cm_modal_start('addCheckupModal', 'GENERAL CONSULTATION FORM', $system_name, true); ?>
            <?php mh_cm_feedback(); ?>
            <?php mh_cm_ocr_box('Auto-fill from a scanned medical record (OCR)', 'ocr/checkup_scan.php', 'document'); ?>
            <?php if ($queue['ctx']) { mh_queue_render_banner($queue['ctx'], $queue['label'], !empty($queue['prefill'])); } ?>
            <form method="POST" data-cf-noun="patient">
                <?php if ($queue['ctx']): ?><input type="hidden" name="apt_id" value="<?php echo (int) $queue['ctx']['id']; ?>"><?php endif; ?>

                <div class="cf-grid cf-grid-main">
                    <?php mh_render_patient_name_fields('Patient Name', $queue['name']); ?>
                    <div class="cf-field">
                        <label>Consultation Date <span class="cf-req">*</span></label>
                        <input type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="cf-section">Patient Vitals</div>
                <div class="cf-panel cf-panel-vitals">
                    <div class="cf-grid cf-c4">
                        <div class="cf-field"><label>Weight</label><input type="text" name="weight" inputmode="decimal" placeholder="e.g., 65 kg"></div>
                        <div class="cf-field"><label>Height</label><input type="text" name="height" inputmode="decimal" placeholder="e.g., 160 cm"></div>
                        <div class="cf-field"><label>Temp</label><input type="text" name="temp" inputmode="decimal" data-mh-decimal="2" maxlength="5" autocomplete="off" placeholder="e.g., 37.2"></div>
                        <div class="cf-field"><label>BP</label><input type="text" name="bp" inputmode="numeric" placeholder="e.g., 120/80"></div>
                    </div>
                </div>

                <div class="cf-section">Clinical Notes</div>
                <div class="cf-field"><label>Chief Complaints <span class="cf-req">*</span></label>
                    <textarea name="chief_complaints" rows="2" required placeholder="Why is the patient here today?"></textarea></div>
                <div class="cf-field"><label>Diagnosis / Findings <span class="cf-req">*</span></label>
                    <textarea name="diagnosis" rows="2" required class="cf-mark-ok" placeholder="Doctor's diagnosis..."></textarea></div>
                <div class="cf-field"><label>Prescription / Treatment</label>
                    <textarea name="prescription" rows="2" class="cf-mark-warn" placeholder="Medications given or prescribed..."></textarea></div>
                <div class="cf-field"><label>Remarks / Notes</label>
                    <textarea name="notes" rows="2" placeholder="Any general remarks before the checkup..."></textarea></div>

                <?php mh_cm_followup_field('If set, the patient automatically gets an SMS reminder the day before and the morning of this date.'); ?>
                <?php mh_cm_save_button('add_consultation', 'Save Consultation Record', 'Save consultation?', 'Please double-check the details. Save this record?'); ?>
            </form>
    <?php mh_cm_modal_end(); ?>
    <?php endif; ?>

    <?php mh_cm_state(['modal' => 'addCheckupModal', 'open' => $can_log && ($queue['open'] || !empty($old['values'])), 'prefill' => $queue['prefill'], 'old' => $old, 'success' => $flash['success'] !== '']); ?>
    <script src="../assets/js/clinical_forms.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/clinical_forms.js'); ?>"></script>
<?php mh_layout_end(); ?>
