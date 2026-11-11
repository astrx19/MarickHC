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

require_capability(['can_log_immunization', 'can_view_immunization_records']);

require_once __DIR__ . '/../includes/settings_helper.php';
mh_check_module_enabled('immunization');

require_once __DIR__ . '/../includes/patient_profile_helper.php';
mh_ensure_patient_name_columns($conn);
$can_log          = mh_has_capability('can_log_immunization');
$can_view_history = mh_has_capability('can_view_immunization_records');
$system_name      = $system_name ?? mh_setting('system_name');
$user_id          = $_SESSION['user_id'] ?? 0;

if (isset($_POST['add_child_immunization']) && !$can_log) {
    mh_deny_access();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_child_immunization'])) {
    $pn           = mh_read_patient_name_post();
    $patient_name = $pn['name'];
    $problem      = $pn['error'];

    $textFields = ['family_no', 'others_header', 'newborn_screening_date', 'tt_of_mother_date', 'birth_time', 'birth_place', 'type_of_delivery',
        'place_of_delivery', 'type_of_breastfeeding', 'mother_name', 'mother_bday', 'father_name', 'father_bday', 'attending_physician', 'contact_no',
        'bcg_date', 'hep_b_24hrs_date', 'penta_opv_3_date', 'penta_1_opv_1_date', 'penta_2_opv_2_date', 'pcv_1_date', 'pcv_2_date', 'pcv_3_date',
        'others_immunization', 'mr_date', 'mmr_date', 'next_visit_date'];
    $v = [];
    foreach ($textFields as $k) { $v[$k] = mh_cm_text($k); }
    $mother_age     = !empty($_POST['mother_age']) ? (int) $_POST['mother_age'] : null;
    $father_age     = !empty($_POST['father_age']) ? (int) $_POST['father_age'] : null;
    $no_of_children = !empty($_POST['no_of_children']) ? (int) $_POST['no_of_children'] : null;

    $clean_temps = [];
    foreach ((array) ($_POST['consultation_temp'] ?? []) as $i => $raw) {
        $tc = mh_clean_temperature($raw);
        if ($problem === null && !$tc['ok']) { $problem = $tc['error']; }
        $clean_temps[$i] = $tc['value'];
    }
    $vcb = mh_clean_vitals(['birth_weight' => $_POST['birth_weight'] ?? '', 'birth_height' => $_POST['birth_height'] ?? '']);
    if ($problem === null && $vcb['errors']) { $problem = implode(' ', $vcb['errors']); }
    $birth_weight = mh_cm_nullable($vcb['values']['birth_weight']);
    $birth_height = mh_cm_nullable($vcb['values']['birth_height']);

    $clean_wts = [];
    $clean_hts = [];
    foreach ((array) ($_POST['consultation_weight'] ?? []) as $i => $raw) {
        $r = mh_clean_vitals(['weight' => $raw, 'height' => ($_POST['consultation_height'][$i] ?? '')]);
        if ($problem === null && $r['errors']) { $problem = 'Check-up row ' . ((int) $i + 1) . ': ' . implode(' ', $r['errors']); }
        $clean_wts[$i] = $r['values']['weight'];
        $clean_hts[$i] = $r['values']['height'];
    }

    $queue_ctx_post = null;
    $patient_id     = null;
    $child_created_id = null;
    if ($problem === null) {
        $queue_ctx_post = mh_queue_open_context($conn, $_POST['apt_id'] ?? 0, 'immunization');
        $patient_id     = mh_queue_resolve_patient($conn, $queue_ctx_post, $pn);
        if ($patient_id === null) {
            list($new_pid, $problem, $was_created) = mh_register_child_patient(
                $conn, $pn, $_POST['child_birthdate'] ?? '', $_POST['child_sex'] ?? '', $v['contact_no'], $v['family_no']
            );
            if ($new_pid !== null) {
                $patient_id = $new_pid;
                if ($was_created) { $child_created_id = $new_pid; }
            }
        }
    }
    if ($problem !== null) {
        mh_cm_fail('immunization.php', 'immunization', $problem);
    }

    $rollbackChild = function () use ($conn, &$child_created_id) {
        if (!empty($child_created_id)) {
            $rb = @$conn->prepare('DELETE FROM patients WHERE id = ?');
            if ($rb) { $rb->bind_param('i', $child_created_id); $rb->execute(); $rb->close(); }
        }
    };

    try {
        $stmt = $conn->prepare("INSERT INTO child_immunization_records
            (patient_id, patient_name, attending_staff_id, family_no, others_header, newborn_screening_date, tt_of_mother_date,
             birth_time, birth_weight, birth_height, birth_place, type_of_delivery, place_of_delivery,
             type_of_breastfeeding, mother_name, mother_bday, mother_age, father_name, father_bday, father_age,
             attending_physician, no_of_children, contact_no, bcg_date, hep_b_24hrs_date, penta_opv_3_date,
             penta_1_opv_1_date, penta_2_opv_2_date, pcv_1_date, pcv_2_date, pcv_3_date, others_immunization, mr_date, mmr_date, next_visit_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { throw new RuntimeException('prepare failed: ' . $conn->error); }
        $stmt->bind_param('isisssssssssssssissisisssssssssssss',
            $patient_id, $patient_name, $user_id, $v['family_no'], $v['others_header'], $v['newborn_screening_date'], $v['tt_of_mother_date'],
            $v['birth_time'], $birth_weight, $birth_height, $v['birth_place'], $v['type_of_delivery'], $v['place_of_delivery'],
            $v['type_of_breastfeeding'], $v['mother_name'], $v['mother_bday'], $mother_age, $v['father_name'], $v['father_bday'], $father_age,
            $v['attending_physician'], $no_of_children, $v['contact_no'], $v['bcg_date'], $v['hep_b_24hrs_date'], $v['penta_opv_3_date'],
            $v['penta_1_opv_1_date'], $v['penta_2_opv_2_date'], $v['pcv_1_date'], $v['pcv_2_date'], $v['pcv_3_date'], $v['others_immunization'],
            $v['mr_date'], $v['mmr_date'], $v['next_visit_date']);
        if (!$stmt->execute()) { throw new RuntimeException('execute failed: ' . $stmt->error); }
        $child_record_id = (int) $conn->insert_id;
        $stmt->close();

        $consult = $conn->prepare("INSERT INTO child_consultation_logs (child_record_id, consultation_date, weight, height, temp, chief_complaints, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($consult) {
            foreach ((array) ($_POST['consultation_date'] ?? []) as $i => $dateRaw) {
                $c_date = mh_cm_nullable($dateRaw);
                if ($c_date === null) { continue; }
                $c_wt   = mh_cm_nullable($clean_wts[$i] ?? '');
                $c_ht   = mh_cm_nullable($clean_hts[$i] ?? '');
                $c_temp = mh_cm_nullable($clean_temps[$i] ?? '');
                $c_cc   = mh_cm_nullable($_POST['consultation_cc'][$i] ?? '');
                $c_rem  = mh_cm_nullable($_POST['consultation_remarks'][$i] ?? '');
                $consult->bind_param('issssss', $child_record_id, $c_date, $c_wt, $c_ht, $c_temp, $c_cc, $c_rem);
                $consult->execute();
            }
            $consult->close();
        }

        $_SESSION['success_msg'] = 'Child Immunization Record saved successfully!' . mh_cm_after_save($conn, [
            'ctx' => $queue_ctx_post, 'patient_id' => $patient_id, 'patient_name' => $patient_name, 'record_id' => $child_record_id,
            'noun' => 'immunization record', 'log_action' => 'Added Child Immunization Record', 'log_detail' => 'Logged record for',
            'sms_label' => 'Immunization', 'next_visit' => (string) $v['next_visit_date'], 'patient_created' => $child_created_id !== null,
        ]);
    } catch (Throwable $e) {
        error_log('[MarickHealth] immunization save FAILED (staff #' . (int) $user_id . '): ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        $rollbackChild();
        mh_cm_fail('immunization.php', 'immunization', 'The record could not be saved, so nothing was changed. Please try again. If it keeps happening, tell the system administrator.');
    }
    header('Location: immunization.php');
    exit();
}

$flash = mh_cm_take_flash();
$old   = mh_cm_take_old('immunization');
$queue = mh_cm_queue_prefill($conn, 'immunization', function (mysqli $conn, $pid, array $profile, $ctx) {
    $pf = [
        'family_no'       => $profile['family_no'] ?? '',
        'contact_no'      => $profile['contact_number'] ?? '',
        'child_birthdate' => (string) ($profile['birthdate'] ?? ''),
        'child_sex'       => (string) ($profile['sex'] ?? ''),
    ];
    $st = $conn->prepare("SELECT * FROM child_immunization_records WHERE patient_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    if ($st) {
        $st->bind_param('i', $pid);
        $st->execute();
        $last = $st->get_result()->fetch_assoc();
        $st->close();
        if ($last) {
            foreach (['family_no', 'others_header', 'newborn_screening_date', 'tt_of_mother_date', 'birth_weight', 'birth_height',
                      'birth_place', 'type_of_delivery', 'place_of_delivery', 'type_of_breastfeeding', 'mother_name', 'mother_bday',
                      'mother_age', 'father_name', 'father_bday', 'father_age', 'no_of_children', 'contact_no',
                      'bcg_date', 'hep_b_24hrs_date', 'penta_opv_3_date', 'penta_1_opv_1_date', 'penta_2_opv_2_date',
                      'pcv_1_date', 'pcv_2_date', 'pcv_3_date', 'others_immunization', 'mr_date', 'mmr_date'] as $k) {
                $val = (string) ($last[$k] ?? '');
                if ($val !== '' && strpos($val, '0000-00-00') !== 0) { $pf[$k] = $val; }
            }
            if (!empty($last['birth_time'])) { $pf['birth_time'] = substr((string) $last['birth_time'], 0, 5); }
        }
    }
    $pf['consultation_date[]'] = date('Y-m-d');
    $pf += mh_cm_vitals_prefill($conn, $pid, ['weight' => 'consultation_weight[]', 'height' => 'consultation_height[]', 'temp' => 'consultation_temp[]'], true);
    $pf['consultation_cc[]'] = mh_cm_reason_prefill($ctx);
    return $pf;
}, $can_log && $flash['success'] === '');

$immunization_logs = !$can_view_history ? false : $conn->query("
    SELECT c.*, p.first_name, p.middle_name, p.last_name, p.birthdate, p.sex, s.last_name AS staff_name
    FROM child_immunization_records c
    LEFT JOIN patients p ON c.patient_id = p.id
    LEFT JOIN staff s ON c.attending_staff_id = s.id
    ORDER BY c.created_at DESC
");

require_once __DIR__ . '/../includes/layout/layout.php';
mh_layout_head(['title' => 'Child Immunization Form', 'late_styles' => ['css/dept_ui.css']]);
mh_layout_open();
echo mh_notify_script($flash['success'], 'success');
$today = date('Y-m-d');
$rowHtml = function () {
    return '<tr>'
        . '<td data-label="Date"><input type="date" name="consultation_date[]" aria-label="Consultation date"></td>'
        . '<td data-label="Weight"><input type="text" name="consultation_weight[]" inputmode="decimal" placeholder="kg" aria-label="Weight"></td>'
        . '<td data-label="Height"><input type="text" name="consultation_height[]" inputmode="decimal" placeholder="cm" aria-label="Height"></td>'
        . '<td data-label="Temp"><input type="text" name="consultation_temp[]" inputmode="decimal" data-mh-decimal="2" maxlength="5" autocomplete="off" placeholder="C" aria-label="Temperature"></td>'
        . '<td data-label="Complaints"><input type="text" name="consultation_cc[]" aria-label="Chief complaints"></td>'
        . '<td data-label="Remarks"><input type="text" name="consultation_remarks[]" aria-label="Remarks"></td>'
        . '<td class="cf-rows-x"><button type="button" class="du-btn du-btn-danger du-btn-sm" data-cf-row-remove aria-label="Remove row">' . mh_icon('x') . '</button></td>'
        . '</tr>';
};
$vaccine = function ($label, $name, $type = 'date', $ph = '') {
    return '<div class="cf-field"><label>' . mh_h($label) . '</label><input type="' . $type . '" name="' . $name . '"' . ($ph !== '' ? ' placeholder="' . mh_h($ph) . '"' : '') . '></div>';
};
?>
        <div class="dashboard-container du-wrap">
            <?php mh_cm_header('Child Immunization Records', 'Child immunization records, vaccines and visit history.', 'searchChild', 'Search child name...', 'childTable', 0, $can_view_history, $can_log, 'addChildModal', 'New Immunization Form'); ?>

            <div class="du-card">
                <?php if ($can_view_history): ?>
                <div class="table-scroll">
                <table class="unified-table cf-stack" id="childTable">
                    <thead>
                        <tr><th>Child Name</th><th>Birthdate &amp; Sex</th><th>Parents / Contact</th><th>Birth Info</th><th>Attending Staff</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($immunization_logs && $immunization_logs->num_rows > 0): ?>
                            <?php while ($row = $immunization_logs->fetch_assoc()): ?>
                            <tr>
                                <td data-label="Child">
                                    <span class="cf-cell-main"><?php echo mh_h(mh_record_patient_label($row)); ?></span>
                                    <span class="cf-cell-sub">Family No: <?php echo mh_h($row['family_no'] ?? '--'); ?></span>
                                </td>
                                <td data-label="Birthdate">
                                    <?php echo mh_h(!empty($row['birthdate']) ? date('M d, Y', strtotime($row['birthdate'])) : '--'); ?>
                                    <span class="cf-cell-sub cf-tone-info"><?php echo mh_h($row['sex'] ?? ''); ?></span>
                                </td>
                                <td data-label="Parents" class="cf-cell-lines"><div><strong>Mother:</strong> <?php echo mh_h($row['mother_name'] ?? '--'); ?></div><div><strong>Contact:</strong> <?php echo mh_h($row['contact_no'] ?? '--'); ?></div></td>
                                <td data-label="Birth" class="cf-cell-lines"><div><strong>Wt:</strong> <?php echo mh_h($row['birth_weight'] ?? '--'); ?> | <strong>Ht:</strong> <?php echo mh_h($row['birth_height'] ?? '--'); ?></div><div><strong>Place:</strong> <?php echo mh_h($row['birth_place'] ?? '--'); ?></div></td>
                                <td data-label="Staff"><strong><?php echo mh_h($row['staff_name'] ?? 'System'); ?></strong></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-state"><td colspan="5">No child immunization records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="cf-notice">You can log new immunization forms here, but your account does not include permission to view the history.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php mh_layout_close(); ?>

    <?php if ($can_log): ?>
    <?php mh_cm_modal_start('addChildModal', 'CHILD IMMUNIZATION FORM', $system_name); ?>
            <?php mh_cm_feedback(); ?>
            <?php mh_cm_ocr_box('Auto-fill from a scanned immunization card (OCR)', 'ocr/immunization_scan.php', 'ocr_file', 'image/*,.pdf'); ?>
            <?php if ($queue['ctx']) { mh_queue_render_banner($queue['ctx'], $queue['label'], count($queue['prefill']) > 3); } ?>
            <form method="POST" id="childForm" data-cf-noun="child">
                <?php if ($queue['ctx']): ?><input type="hidden" name="apt_id" value="<?php echo (int) $queue['ctx']['id']; ?>"><?php endif; ?>

                <div class="cf-grid cf-c4">
                    <?php echo $vaccine('Family No', 'family_no', 'text'), $vaccine('Others', 'others_header', 'text'), $vaccine('Newborn Screening Date', 'newborn_screening_date'), $vaccine('TT of Mother / Date', 'tt_of_mother_date'); ?>
                </div>

                <div class="cf-section">Child Information</div>
                <div class="cf-grid cf-grid-main">
                    <?php mh_render_patient_name_fields('Child / Patient Name', $queue['name']); ?>
                    <div class="cf-field"><label>Birth Time</label><input type="time" name="birth_time"></div>
                </div>
                <div class="cf-grid cf-c2">
                    <div class="cf-field"><label>Child's Birthdate <span class="cf-req">*</span></label>
                        <input type="date" id="childBirthdate" name="child_birthdate" min="1900-01-01" max="<?php echo $today; ?>" data-mh-date data-cf-pick="birthdate"></div>
                    <div class="cf-field"><label>Sex <span class="cf-req">*</span></label>
                        <select name="child_sex" id="childSex" data-cf-pick="sex">
                            <option value="">Select...</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select></div>
                </div>
                <span class="cf-help">Needed to add a new child to the Patient Masterlist. Already-registered children are matched automatically.</span>

                <div class="cf-grid cf-c3">
                    <div class="cf-field"><label>Birth Weight</label><input type="text" name="birth_weight" inputmode="decimal" placeholder="e.g., 3.1 kg"></div>
                    <div class="cf-field"><label>Birth Height</label><input type="text" name="birth_height" inputmode="decimal" placeholder="e.g., 49 cm"></div>
                    <div class="cf-field"><label>Birth Place</label><select name="birth_place"><?php echo mh_masterlist_options($conn, 'birth_places', '', '-- Select --'); ?></select></div>
                </div>
                <div class="cf-grid cf-c2">
                    <div class="cf-field"><label>Type of Delivery</label><select name="type_of_delivery"><?php echo mh_masterlist_options($conn, 'delivery_types', '', '-- Select --'); ?></select></div>
                    <div class="cf-field"><label>Type of Breastfeeding</label><select name="type_of_breastfeeding"><?php echo mh_masterlist_options($conn, 'breastfeeding_types', '', '-- Select --'); ?></select></div>
                </div>

                <div class="cf-section">Parent &amp; Family Details</div>
                <div class="cf-grid cf-c3">
                    <?php echo $vaccine("Mother's Name", 'mother_name', 'text'), $vaccine("Mother's Birthdate", 'mother_bday'), $vaccine("Mother's Age", 'mother_age', 'number'); ?>
                    <?php echo $vaccine("Father's Name", 'father_name', 'text'), $vaccine("Father's Birthdate", 'father_bday'), $vaccine("Father's Age", 'father_age', 'number'); ?>
                    <?php echo $vaccine('Attending Physician / Staff', 'attending_physician', 'text'), $vaccine('No. of Children', 'no_of_children', 'number'), $vaccine('Contact No', 'contact_no', 'text'); ?>
                </div>

                <div class="cf-section">Immunization Record (Dates Administered)</div>
                <div class="cf-imm">
                    <?php echo $vaccine('BCG', 'bcg_date'), $vaccine('Hepa B within 24 hrs', 'hep_b_24hrs_date'), $vaccine('Penta 3, OPV 3', 'penta_opv_3_date'),
                        $vaccine('Penta 1, OPV 1', 'penta_1_opv_1_date'), $vaccine('Penta 2, OPV 2', 'penta_2_opv_2_date'), $vaccine('PCV 3', 'pcv_3_date'),
                        $vaccine('PCV 1', 'pcv_1_date'), $vaccine('PCV 2', 'pcv_2_date'), $vaccine('Others', 'others_immunization', 'text', 'Specify...'),
                        $vaccine('MR', 'mr_date'), $vaccine('MMR', 'mmr_date'); ?>
                </div>

                <div class="cf-section-row">
                    <div class="cf-section">Consultation &amp; Monitoring Record</div>
                    <button type="button" class="du-btn du-btn-ghost du-btn-sm" data-cf-row-add><?php echo mh_icon('plus'); ?> Add Row</button>
                </div>
                <div data-cf-rows>
                    <table class="cf-rows">
                        <thead><tr><th>Date</th><th>Weight</th><th>Height</th><th>Temp</th><th>Chief Complaints</th><th>Remarks</th><th></th></tr></thead>
                        <tbody><?php echo $rowHtml(); ?></tbody>
                    </table>
                    <template><?php echo $rowHtml(); ?></template>
                </div>

                <div style="height:14px"></div>
                <?php mh_cm_followup_field("If set, the patient's guardian automatically gets an SMS reminder the day before and the morning of this date."); ?>
                <?php mh_cm_save_button('add_child_immunization', 'Save Child Immunization Record', 'Save immunization record?', 'Create this immunization record? Please double-check the details.'); ?>
            </form>
    <?php mh_cm_modal_end(); ?>
    <?php endif; ?>

    <?php mh_cm_state(['modal' => 'addChildModal', 'open' => $can_log && ($queue['open'] || !empty($old['values'])), 'prefill' => $queue['prefill'], 'old' => $old, 'success' => $flash['success'] !== '']); ?>
    <script src="../assets/js/clinical_forms.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/clinical_forms.js'); ?>"></script>
<?php mh_layout_end(); ?>
