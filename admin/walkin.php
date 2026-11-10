<?php
$guard = __DIR__ . '/../includes/error_guard.php';
if (is_file($guard)) {
    require_once $guard;
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/queue_helper.php';
require_once __DIR__ . '/../includes/patient_duplicate_helper.php';
require_once __DIR__ . '/../includes/patient_module_helper.php';
require_once __DIR__ . '/../includes/layout/layout.php';

mh_check_module_enabled('walkin');
require_permission('walkin_registration');

$clinic_closed = mh_clinic_closed_reason($conn);
$user_id = $_SESSION['user_id'];
$error_msg = '';
$dup = null;
$old = [];

$prefill = [
    'first_name' => mh_pm_clean_name($_GET['prefill_fname'] ?? ''),
    'last_name'  => mh_pm_clean_name($_GET['prefill_lname'] ?? ''),
    'birthdate'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_GET['prefill_bdate'] ?? '')) ? trim($_GET['prefill_bdate']) : '',
];
$reopen_new = ($prefill['first_name'] !== '' || $prefill['last_name'] !== '' || $prefill['birthdate'] !== '');
if ($reopen_new) { $old = array_filter($prefill, 'strlen'); }

function mh_walkin_validate_new_patient(mysqli $conn, array $in, &$error)
{
    $fname = mh_pm_clean_name($in['first_name'] ?? '');
    $mname = mh_pm_clean_name($in['middle_name'] ?? '');
    $lname = mh_pm_clean_name($in['last_name'] ?? '');
    $bdate = trim((string) ($in['birthdate'] ?? ''));
    $sex = (string) ($in['sex'] ?? '');
    $contact = mh_normalize_ph_mobile(trim((string) ($in['contact_number'] ?? '')));
    $type = trim((string) ($in['address_type'] ?? ''));
    if (!mh_masterlist_has($conn, 'address_types', $type)) { $type = mh_home_address_type(); }
    $realEmail = strtolower(trim((string) ($in['email'] ?? '')));

    if ($fname === '' || $lname === '') {
        $error = 'First and last name are required.';
    } elseif (!mh_pm_valid_name($fname) || !mh_pm_valid_name($lname) || !mh_pm_valid_name($mname, false)) {
        $error = 'Names may only contain letters, spaces, hyphens, apostrophes and periods (max 50 characters).';
    } elseif (!mh_pm_valid_birthdate($bdate)) {
        $error = 'Please enter a valid birthdate.';
    } elseif (!in_array($sex, ['Male', 'Female'], true)) {
        $error = "Please select the patient's sex.";
    } elseif ($contact === '') {
        $error = 'Please enter an 11-digit Philippine mobile number starting with 09 (e.g. 09XXXXXXXXX).';
    }

    $address = '';
    if ($error === '') {
        if (mh_is_marick_resident($type)) {
            $house = trim((string) ($in['house_number'] ?? ''));
            $street = trim((string) ($in['marick_street'] ?? ''));
            if ($house === '' || !mh_masterlist_has($conn, 'streets', $street)) {
                $error = 'Please enter the house number and choose a street from the list.';
            } else {
                $address = mh_build_resident_address($house, $street);
            }
        } else {
            $address = trim((string) ($in['outsider_address'] ?? ''));
            if ($address === '') { $error = "Please enter the patient's full address."; }
        }
    }
    if ($error === '' && $realEmail !== '' && !mh_pm_valid_email($realEmail)) {
        $error = 'Please enter a valid email address ending strictly with .com, or leave it blank.';
    }
    if ($error !== '') { return null; }

    return [
        'fname' => $fname, 'mname' => $mname, 'lname' => $lname, 'bdate' => $bdate, 'sex' => $sex,
        'contact' => $contact, 'contact_norm' => $contact, 'address_type' => $type, 'address' => $address,
        'email' => $realEmail !== '' ? $realEmail : mh_pm_placeholder_email($fname, $lname), 'real_email' => $realEmail,
    ];
}

function mh_walkin_existing_patient_error(mysqli $conn, $patientId)
{
    if ($patientId <= 0) { return 'Please select a valid patient.'; }
    $st = $conn->prepare("SELECT id FROM patients WHERE id = ? AND status = 1 LIMIT 1");
    $st->bind_param('i', $patientId);
    $st->execute();
    $missing = $st->get_result()->num_rows === 0;
    $st->close();
    return $missing ? 'That patient record was not found or is archived. Please select another patient.' : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['process_walkin'])) {
    if (!mh_csrf_check()) {
        http_response_code(403);
        $error_msg = 'Your session security token is missing or expired. Please reload the page and try again.';
    } elseif ($clinic_closed !== null) {
        $error_msg = $clinic_closed;
    } else {
        $department = trim($_POST['department'] ?? '');
        $is_new = (($_POST['patient_type'] ?? 'existing') === 'new');
        $patient_id = 0;
        $new_patient = null;

        $old = ['department' => $department];
        if ($is_new) {
            $reopen_new = true;
            foreach (['first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'contact_number', 'email', 'address_type', 'house_number', 'marick_street', 'outsider_address'] as $k) {
                $old[$k] = trim((string) ($_POST[$k] ?? ''));
            }
        }

        $error_msg = (string) mh_queue_department_problem($conn, $department);
        if ($error_msg === '') {
            if ($is_new) {
                $new_patient = mh_walkin_validate_new_patient($conn, $_POST, $error_msg);
            } else {
                $patient_id = (int) ($_POST['existing_patient_id'] ?? 0);
                $error_msg = mh_walkin_existing_patient_error($conn, $patient_id);
            }
        }

        if ($error_msg === '') {
            mh_ensure_queue_schema($conn);
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            try {
                $conn->begin_transaction();

                if ($new_patient !== null) {
                    $dup = mh_pd_check_new_patient($conn, $new_patient, (($_POST['confirm_not_duplicate'] ?? '') === '1'), true);
                    if ($dup !== null) { $conn->rollback(); }
                }

                if ($dup === null) {
                    if ($new_patient !== null) {
                        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                        $ins = $conn->prepare(
                            "INSERT INTO patients (first_name, middle_name, last_name, birthdate, sex, contact_number, address_type, address, email, password)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $ins->bind_param('ssssssssss', $new_patient['fname'], $new_patient['mname'], $new_patient['lname'], $new_patient['bdate'],
                            $new_patient['sex'], $new_patient['contact'], $new_patient['address_type'], $new_patient['address'], $new_patient['email'], $password);
                        $ins->execute();
                        $patient_id = (int) $conn->insert_id;
                        $ins->close();
                        logActivity($conn, $user_id, $_SESSION['role'], 'Walk-in Registration',
                            'Quick-registered new patient: ' . mh_format_patient_name($new_patient['fname'], $new_patient['mname'], $new_patient['lname']) . '.');
                    }

                    list($queue_no, $queue_err) = mh_enqueue_patient($conn, $patient_id, $department);
                    if ($queue_no === null) {
                        $conn->rollback();
                        $error_msg = $queue_err;
                    } else {
                        if (!logActivity($conn, $user_id, $_SESSION['role'], 'Walk-In Queue', "Added Patient ID $patient_id to $department queue as " . mh_queue_label($department, $queue_no) . ' (Waiting).')) {
                            throw new RuntimeException('Audit log write failed');
                        }
                        $conn->commit();
                        header('Location: appointments.php?queued=' . $queue_no . '&dept=' . urlencode($department));
                        exit();
                    }
                }
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) { $ignored = null; }
                if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062) {
                    $error_msg = "This patient (or email) was just registered by another staff member. Please use the 'Existing Patient' tab and search again.";
                } else {
                    error_log('Walk-in queue failed: ' . $e->getMessage());
                    $error_msg = 'The patient could not be added to the queue. Nothing was saved. Please try again.';
                }
            }
        }
    }
}

$patients_list = $conn->query("SELECT id, first_name, middle_name, last_name, birthdate, sex FROM patients WHERE status = 1 ORDER BY last_name ASC, first_name ASC");
$state = ['old' => $old, 'reopenNew' => $reopen_new, 'homeType' => mh_home_address_type()];

mh_layout_head(['title' => 'Walk-In Registration', 'styles' => ['css/patient_modules.css'], 'external_styles' => ['https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css']]);
mh_layout_open();
$closedAttr = $clinic_closed !== null ? ' disabled title="The clinic is closed today"' : '';
?>
        <div class="pm-page wk-wrap">
            <div class="pm-head">
                <div>
                    <h1 class="pm-title">Walk-In Queue</h1>
                    <p class="pm-sub">Add a patient who is at the clinic to today's queue.</p>
                </div>
            </div>

            <?php if ($dup): ?>
            <div class="pm-dup <?php echo $dup['level'] === 'block' ? 'pm-dup--block' : ''; ?>" role="alert">
                <div class="pm-dup-title"><?php echo mh_icon('x-circle'); ?> <?php echo mh_h($dup['title']); ?></div>
                <p><?php echo mh_h($dup['message']); ?></p>
                <?php if (!empty($dup['select_id'])): ?>
                    <button type="button" class="pm-act pm-act--solid" data-use-existing="<?php echo (int) $dup['select_id']; ?>">Use this patient</button>
                <?php endif; ?>
            </div>
            <?php elseif ($error_msg !== ''): echo mh_pm_alert('error', $error_msg); endif; ?>

            <?php if ($clinic_closed !== null) { echo mh_pm_alert('warn', $clinic_closed, 'Walk-in registration is closed.'); } ?>

            <form method="POST" id="walkinForm">
                <?php echo mh_csrf_field(); ?>
                <div class="wk-route">
                    <h3>Where does this patient need to go?</h3>
                    <select name="department" id="walkinDepartment" required aria-label="Clinic department"<?php echo $closedAttr; ?>>
                        <option value="" disabled selected>Select clinic department</option>
                        <?php echo mh_department_options($conn); ?>
                    </select>
                </div>

                <div class="wk-switch" role="tablist" aria-label="Patient type">
                    <button type="button" role="tab" id="btn-existing" data-wk-mode="existing" aria-selected="true">Existing Patient</button>
                    <button type="button" role="tab" id="btn-new" data-wk-mode="new" aria-selected="false">New Registration</button>
                </div>
                <input type="hidden" name="patient_type" id="patientTypeInput" value="existing">

                <section class="wk-card" id="form-existing">
                    <h3>Select Registered Patient</h3>
                    <select name="existing_patient_id" id="patientSelect" aria-label="Registered patient" style="width:100%">
                        <option value="" disabled selected>Search masterlist</option>
                        <?php while ($patients_list && ($p = $patients_list->fetch_assoc())): ?>
                            <option value="<?php echo (int) $p['id']; ?>"><?php echo mh_h(mh_format_patient_name($p['first_name'], $p['middle_name'] ?? '', $p['last_name'])); ?> (<?php echo mh_h(mh_format_patient_age($p['birthdate'])); ?>, <?php echo mh_h($p['sex']); ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </section>

                <section class="wk-card" id="form-new" hidden>
                    <h3>Quick Registration</h3>
                    <div class="pm-grid">
                        <div class="wk-names">
                            <div class="form-group"><label for="req_fname">First name <span class="pm-req">*</span></label>
                                <input type="text" name="first_name" id="req_fname" maxlength="50" value="<?php echo mh_h($prefill['first_name']); ?>" data-letters></div>
                            <div class="form-group"><label for="req_mname">Middle name <span class="pm-opt">(optional)</span></label>
                                <input type="text" name="middle_name" id="req_mname" maxlength="50" placeholder="M.I." data-letters></div>
                            <div class="form-group"><label for="req_lname">Last name <span class="pm-req">*</span></label>
                                <input type="text" name="last_name" id="req_lname" maxlength="50" value="<?php echo mh_h($prefill['last_name']); ?>" data-letters></div>
                        </div>
                        <div class="form-group"><label for="req_bdate">Birthdate <span class="pm-req">*</span></label>
                            <input type="date" name="birthdate" id="req_bdate" value="<?php echo mh_h($prefill['birthdate']); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="form-group"><label for="req_sex">Sex <span class="pm-req">*</span></label>
                            <select name="sex" id="req_sex">
                                <option value="" disabled selected>Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select></div>
                        <div class="form-group pm-span"><label for="req_contact">Contact number <span class="pm-req">*</span></label>
                            <input type="tel" name="contact_number" id="req_contact" placeholder="09XXXXXXXXX" maxlength="11" pattern="^09[0-9]{9}$" inputmode="numeric"></div>
                        <div class="form-group pm-span"><label for="req_email">Email address <span class="pm-opt">(optional, for Patient Portal access)</span></label>
                            <input type="email" name="email" id="req_email" placeholder="patient@email.com" pattern="^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$" title="Email must end strictly with .com"></div>

                        <?php if ($dup && $dup['level'] === 'confirm'): ?>
                        <div class="wk-confirm"><label><input type="checkbox" name="confirm_not_duplicate" value="1"> <span>I checked the existing record above. This is a different person.</span></label></div>
                        <?php endif; ?>

                        <div class="form-group pm-span wk-rule"><label for="req_address_type">Location type <span class="pm-req">*</span></label>
                            <select name="address_type" id="req_address_type">
                                <?php echo mh_masterlist_options($conn, 'address_types', mh_home_address_type(), '', [], mh_address_type_labels()); ?>
                            </select></div>
                        <div class="form-group" data-address-home><label for="req_house">House or block no. <span class="pm-req">*</span></label>
                            <input type="text" name="house_number" id="req_house" placeholder="e.g. Blk 1 Lot 2"></div>
                        <div class="form-group" data-address-home><label for="req_street">Street <span class="pm-req">*</span></label>
                            <select name="marick_street" id="req_street">
                                <option value="" disabled selected>Select street</option>
                                <?php echo mh_masterlist_options($conn, 'streets'); ?>
                            </select></div>
                        <div class="form-group pm-span" data-address-other hidden><label for="req_out_address">Full address <span class="pm-req">*</span></label>
                            <input type="text" name="outsider_address" id="req_out_address" placeholder="House No., Street, Barangay, City"></div>
                    </div>
                </section>

                <button type="submit" name="process_walkin" value="1" class="btn-submit wk-submit"<?php echo $closedAttr; ?>
                    data-confirm="Add this patient to the queue?" data-confirm-title="Add to queue?" data-confirm-type="info" data-confirm-ok="Add to queue">
                    Add to Queue
                </button>
            </form>
        </div>
    <?php mh_layout_close(); ?>

    <script type="application/json" id="walkinState"><?php echo json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../assets/js/walkin.js?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/js/walkin.js'); ?>" defer></script>
<?php mh_layout_end(); ?>
