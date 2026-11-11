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
mh_sms_install($conn);

require_capability(['can_log_maternal', 'can_view_maternal_history']);

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('maternal_care');

mh_ensure_patient_name_columns($conn);
$can_log          = mh_has_capability('can_log_maternal');
$can_view_history = mh_has_capability('can_view_maternal_history');
$system_name      = $system_name ?? mh_setting('system_name');
$user_id          = $_SESSION['user_id'];

if (isset($_POST['add_prenatal']) && !$can_log) {
    mh_deny_access();
}

if (isset($_POST['add_prenatal'])) {
    $pn           = mh_read_patient_name_post();
    $patient_name = $pn['name'];
    $problem      = $pn['error'];
    $visit_date   = $_POST['visit_date'] ?? '';

    $queue_ctx_post = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'maternal_care');
    $patient_id     = $problem === null ? mh_queue_resolve_patient($conn, $queue_ctx_post, $pn) : null;
    if ($problem === null) { $problem = mh_maternal_sex_error($conn, $patient_id); }

    $vc = mh_clean_vitals(['bp' => $_POST['blood_pressure'] ?? '', 'weight' => $_POST['weight'] ?? '', 'height' => $_POST['height'] ?? '', 'bmi' => $_POST['bmi'] ?? '']);
    if ($problem === null && $vc['errors']) { $problem = implode(' ', $vc['errors']); }
    if ($problem === null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) { $problem = 'Please enter a valid visit date.'; }

    if ($problem !== null) {
        mh_cm_fail('maternal_care.php', 'maternal', $problem);
    }

    $bp     = mh_cm_nullable($vc['values']['bp']);
    $weight = mh_cm_nullable($vc['values']['weight']);
    $height = mh_cm_nullable($vc['values']['height']);
    $bmi    = mh_cm_nullable($vc['values']['bmi']);
    $gravida = mh_cm_text('gravida');
    $para    = mh_cm_text('para');
    $aog     = mh_cm_text('aog');
    $fh_fht  = mh_cm_text('fh_fht');
    $notes   = mh_cm_text('notes');
    $lmp = mh_cm_date('lmp');
    $edc = mh_cm_date('edc');
    $tt1 = mh_cm_date('tt1_date');
    $tt2 = mh_cm_date('tt2_date');
    $tt3 = mh_cm_date('tt3_date');
    $tt4 = mh_cm_date('tt4_date');
    $tt5 = mh_cm_date('tt5_date');
    $next_raw   = trim($_POST['next_visit_date'] ?? '');
    $next_visit = mh_cm_date('next_visit_date');

    try {
        $stmt = $conn->prepare("INSERT INTO maternal_records
            (patient_id, patient_name, midwife_id, visit_date, gravida, para, lmp, edc, aog, blood_pressure, weight, height, bmi, fh_fht, tt1_date, tt2_date, tt3_date, tt4_date, tt5_date, notes, next_visit_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { throw new RuntimeException('prepare failed: ' . $conn->error); }
        $stmt->bind_param('isissssssssssssssssss',
            $patient_id, $patient_name, $user_id, $visit_date, $gravida, $para, $lmp, $edc, $aog, $bp, $weight, $height, $bmi, $fh_fht,
            $tt1, $tt2, $tt3, $tt4, $tt5, $notes, $next_visit);
        if (!$stmt->execute()) { throw new RuntimeException('execute failed: ' . $stmt->error); }
        $record_id = (int) $conn->insert_id;
        $stmt->close();

        $_SESSION['success_msg'] = 'Prenatal record saved successfully!' . mh_cm_after_save($conn, [
            'ctx' => $queue_ctx_post, 'patient_id' => $patient_id, 'patient_name' => $patient_name, 'record_id' => $record_id,
            'noun' => 'prenatal record', 'log_action' => 'Added Prenatal Record', 'log_detail' => 'Logged maternal checkup for',
            'sms_label' => 'Prenatal Care', 'next_visit' => $next_raw,
        ]);
    } catch (Throwable $e) {
        error_log('[MarickHealth] prenatal save FAILED (staff #' . (int) $user_id . ', patient ' . var_export($patient_id, true) . '): ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        mh_cm_fail('maternal_care.php', 'maternal', 'The prenatal record could not be saved, so nothing was changed. Please try again. If it keeps happening, tell the system administrator.');
    }
    header('Location: maternal_care.php');
    exit();
}

$flash = mh_cm_take_flash();
$old   = mh_cm_take_old('maternal');
$queue = mh_cm_queue_prefill($conn, 'maternal_care', function (mysqli $conn, $pid, array $profile, $ctx) {
    $pf = [];
    $st = $conn->prepare("SELECT * FROM maternal_records WHERE patient_id = ? ORDER BY visit_date DESC, id DESC LIMIT 1");
    if ($st) {
        $st->bind_param('i', $pid);
        $st->execute();
        $last = $st->get_result()->fetch_assoc();
        $st->close();
        if ($last) {
            foreach (['gravida', 'para', 'lmp', 'edc', 'tt1_date', 'tt2_date', 'tt3_date', 'tt4_date', 'tt5_date', 'height'] as $k) {
                $v = (string) ($last[$k] ?? '');
                if ($v !== '' && strpos($v, '0000-00-00') !== 0) { $pf[$k] = $v; }
            }
            if (isset($pf['height'])) { $pf['height'] = mh_queue_number($pf['height']); }
            if (!empty($pf['lmp'])) {
                $days = (int) floor((time() - strtotime($pf['lmp'])) / 86400);
                if ($days >= 0 && $days <= 300) { $pf['aog'] = floor($days / 7) . ' wks'; }
            }
        }
    }
    $vit = mh_queue_today_vitals($conn, $pid);
    if ($vit) {
        $pf['blood_pressure'] = (string) ($vit['bp'] ?? '');
        $pf['weight']         = mh_queue_number($vit['weight'] ?? '');
        if (mh_queue_number($vit['height'] ?? '') !== '') { $pf['height'] = mh_queue_number($vit['height']); }
    }
    return $pf;
}, $can_log && $flash['success'] === '');

$prenatal_logs = !$can_view_history ? false : $conn->query("
    SELECT m.*, p.first_name, p.middle_name, p.last_name, s.last_name AS mw_name
    FROM maternal_records m
    LEFT JOIN patients p ON m.patient_id = p.id
    LEFT JOIN staff s ON m.midwife_id = s.id
    ORDER BY m.visit_date DESC, m.created_at DESC
");

require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'Maternal Care', 'late_styles' => ['css/dept_ui.css']]);
mh_layout_open();
echo mh_notify_script($flash['success'], 'success');
$today = date('Y-m-d');
?>
        <div class="dashboard-container du-wrap">
            <?php mh_cm_header('Prenatal Care Records', 'Prenatal visits and maternal health records.', 'searchPrenatal', "Search mother's name...", 'prenatalTable', 1, $can_view_history, $can_log, 'addPrenatalModal', 'New Prenatal Record'); ?>

            <div class="du-card">
                <?php if ($can_view_history): ?>
                <div class="table-scroll">
                <table class="unified-table cf-stack" id="prenatalTable">
                    <thead>
                        <tr><th>Date &amp; AOG</th><th>Mother's Name</th><th>Vitals</th><th>FH / FHT</th><th>Attending Midwife</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($prenatal_logs && $prenatal_logs->num_rows > 0): ?>
                            <?php while ($row = $prenatal_logs->fetch_assoc()): ?>
                            <tr>
                                <td data-label="Date" class="cf-nowrap">
                                    <strong><?php echo mh_h(date('M d, Y', strtotime($row['visit_date']))); ?></strong>
                                    <span class="cf-cell-sub">AOG: <?php echo mh_h($row['aog'] ?? '--'); ?></span>
                                </td>
                                <td data-label="Mother">
                                    <span class="cf-cell-main"><?php echo mh_h(mh_record_patient_label($row)); ?></span>
                                    <span class="cf-cell-sub">G: <?php echo mh_h($row['gravida'] ?? ''); ?> | P: <?php echo mh_h($row['para'] ?? ''); ?></span>
                                </td>
                                <td data-label="Vitals" class="cf-nowrap cf-cell-lines">
                                    <div><strong>BP:</strong> <span class="cf-tone-info"><?php echo mh_h($row['blood_pressure'] ?? '--'); ?></span></div>
                                    <div><strong>Wt:</strong> <?php echo mh_h($row['weight'] ?? '--'); ?> | <strong>Ht:</strong> <?php echo mh_h($row['height'] ?? '--'); ?></div>
                                    <div><strong>BMI:</strong> <span class="cf-tone-ok"><?php echo mh_h($row['bmi'] ?? '--'); ?></span></div>
                                </td>
                                <td data-label="FH / FHT"><strong class="cf-tone-bad"><?php echo mh_h($row['fh_fht'] ?? 'Not recorded'); ?></strong></td>
                                <td data-label="Midwife">
                                    <strong>MW. <?php echo mh_h($row['mw_name'] ?? ''); ?></strong>
                                    <?php if (!empty($row['notes'])): ?><span class="cf-cell-sub"><?php echo mh_h($row['notes']); ?></span><?php endif; ?>
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
                <div class="cf-notice">You can log new prenatal visits here, but your account does not include permission to view the history.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php mh_layout_close(); ?>

    <?php if ($can_log): ?>
    <?php mh_cm_modal_start('addPrenatalModal', 'PRENATAL RECORD FORM', $system_name); ?>
            <?php mh_cm_feedback(); ?>
            <?php mh_cm_ocr_box('Auto-fill from a scanned prenatal record (OCR)', 'ocr/maternal_scan.php', 'ocr_image'); ?>
            <?php if ($queue['ctx']) { mh_queue_render_banner($queue['ctx'], $queue['label'], !empty($queue['prefill'])); } ?>
            <form method="POST" data-cf-bmi data-cf-noun="patient" data-cf-reject-sex="male" data-cf-reject-message="Maternal Care is for female patients. {name} is registered as male.">
                <?php if ($queue['ctx']): ?><input type="hidden" name="apt_id" value="<?php echo (int) $queue['ctx']['id']; ?>"><?php endif; ?>

                <div class="cf-grid cf-grid-main">
                    <?php mh_render_patient_name_fields('Mother Name', $queue['name']); ?>
                    <div class="cf-field">
                        <label>Visit Date <span class="cf-req">*</span></label>
                        <input type="date" name="visit_date" value="<?php echo $today; ?>" max="<?php echo $today; ?>" required>
                    </div>
                </div>

                <div class="cf-section">Pregnancy Profile</div>
                <div class="cf-grid cf-c5">
                    <div class="cf-field"><label>G (Gravida)</label><input type="text" name="gravida" inputmode="numeric" placeholder="e.g., 2"></div>
                    <div class="cf-field"><label>P (Para)</label><input type="text" name="para" inputmode="numeric" placeholder="e.g., 1"></div>
                    <div class="cf-field"><label>LMP</label><input type="date" name="lmp" max="<?php echo $today; ?>"></div>
                    <div class="cf-field"><label>EDC</label><input type="date" name="edc"></div>
                    <div class="cf-field"><label>AOG</label><input type="text" name="aog" placeholder="e.g., 24 wks"></div>
                </div>

                <div class="cf-section">Tetanus Toxoid Status</div>
                <div class="cf-panel cf-panel-tt">
                    <div class="cf-grid cf-c5">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="cf-field"><label>TT<?php echo $i; ?> Date</label><input type="date" name="tt<?php echo $i; ?>_date" max="<?php echo $today; ?>"></div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="cf-section">Today's Vitals &amp; Exam</div>
                <div class="cf-panel cf-panel-vitals">
                    <div class="cf-grid cf-c4">
                        <div class="cf-field"><label>BP</label><input type="text" name="blood_pressure" inputmode="numeric" placeholder="e.g., 110/70"></div>
                        <div class="cf-field"><label>Weight (kg)</label><input type="number" step="0.1" min="0" name="weight" inputmode="decimal" data-cf-bmi-w placeholder="e.g., 65"></div>
                        <div class="cf-field"><label>Height (cm)</label><input type="number" step="0.1" min="0" name="height" inputmode="decimal" data-cf-bmi-h placeholder="e.g., 160"></div>
                        <div class="cf-field"><label>BMI</label><input type="text" name="bmi" data-cf-bmi-out placeholder="Auto-calculated" readonly></div>
                    </div>
                </div>

                <div class="cf-field"><label>FH / FHT (Fundal Height / Fetal Heart Tone)</label>
                    <input type="text" name="fh_fht" placeholder="e.g., 24 cm / 140 bpm"></div>
                <div class="cf-field"><label>Remarks / Notes</label>
                    <textarea name="notes" rows="2" placeholder="Any additional instructions or vitamins prescribed..."></textarea></div>

                <?php mh_cm_followup_field('If set, the patient automatically gets an SMS reminder the day before and the morning of this date.'); ?>
                <?php mh_cm_save_button('add_prenatal', 'Save Prenatal Record', 'Save prenatal visit?', 'Please double-check the details. Save this record?'); ?>
            </form>
    <?php mh_cm_modal_end(); ?>
    <?php endif; ?>

    <?php mh_cm_state(['modal' => 'addPrenatalModal', 'open' => $can_log && ($queue['open'] || !empty($old['values'])), 'prefill' => $queue['prefill'], 'old' => $old, 'success' => $flash['success'] !== '']); ?>
    <script src="../assets/js/clinical_forms.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/clinical_forms.js'); ?>"></script>
<?php mh_layout_end(); ?>
