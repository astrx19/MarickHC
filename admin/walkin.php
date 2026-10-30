<?php
// Error guard: logs every error, shows a friendly message (not a blank page) on fatal errors, and shows
// details on screen only on localhost / admin/debug_walkin.php. Falls back to log-only if the file is missing.
$__guard = __DIR__ . '/../includes/error_guard.php';
if (is_file($__guard)) {
    require_once $__guard;
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/settings_helper.php';   // provides mh_check_module_enabled()
mh_check_module_enabled('walkin');   // module master switch
require_permission('walkin_registration');   // page load

$system_name = $system_name ?? mh_setting('system_name');

require_once __DIR__ . '/../includes/queue_helper.php';
require_once __DIR__ . '/../includes/patient_duplicate_helper.php';   // smart duplicate detection (name+birthdate / email / mobile)
// Straight from the Clinic Departments masterlist (database), not a cached config file.
$clinic_departments = mh_clinic_departments($conn);
$marick_streets     = mh_streets($conn);   // Streets / Areas / Purok masterlist (database)

$user_id = $_SESSION['user_id'];
$error_msg = "";

// ---------------------------------------------------------------------
// OCR hand-off: when an OCR scan (immunization/checkup/maternal) finds
// no existing patient match, its "Auto-fill into New Patient
// Registration" button links here with the extracted identity so the
// front desk doesn't have to retype it.
// ---------------------------------------------------------------------
$prefill_fname = htmlspecialchars(trim($_GET['prefill_fname'] ?? ''), ENT_QUOTES);
$prefill_lname = htmlspecialchars(trim($_GET['prefill_lname'] ?? ''), ENT_QUOTES);
$prefill_bdate_raw = trim($_GET['prefill_bdate'] ?? '');
$prefill_bdate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_bdate_raw) ? $prefill_bdate_raw : '';
$has_prefill = ($prefill_fname !== '' || $prefill_lname !== '' || $prefill_bdate !== '');

/* =====================================================================
 * WALK-IN INTAKE  (first-come, first-served)
 *   1. permission + CSRF
 *   2. validate department (active + its module ON) and the patient input - all BEFORE touching the database
 * (duplicates: same first+last name+birthdate / used email / same mobile+name are caught INSIDE the transaction)
 *   3. ONE transaction: [create patient] -> take the next queue number -> insert 'Waiting' row -> audit log
 *   4. commit only if everything succeeded; any failure rolls the whole thing back (no orphan patients)
 * ================================================================== */
$dup = null;              // duplicate-patient panel (see includes/patient_duplicate_helper.php)
$old = [];                // posted values, so the New Registration form is refilled after an error
$reopen_new = $has_prefill;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_walkin'])) {
    require_permission('walkin_registration');   // form submit level

    if (!mh_csrf_check()) {
        http_response_code(403);
        $error_msg = "Your session security token is missing or expired. Please reload the page and try again.";
    } else {
        $department   = trim($_POST['department'] ?? '');
        $patient_type = (($_POST['patient_type'] ?? 'existing') === 'new') ? 'new' : 'existing';
        $patient_id   = 0;
        $new_patient  = null;   // validated fields for a brand-new patient

        $old['department'] = $department;
        if ($patient_type === 'new') {
            $reopen_new = true;
            foreach (['first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'contact_number', 'email',
                      'address_type', 'house_number', 'marick_street', 'outsider_address'] as $k) {
                $old[$k] = trim((string) ($_POST[$k] ?? ''));
            }
        }

        // The target department must be an ACTIVE Clinic Department whose module is switched on.
        $error_msg = (string) mh_queue_department_problem($conn, $department);

        if ($error_msg === '' && $patient_type === 'existing') {
            $patient_id = (int) ($_POST['existing_patient_id'] ?? 0);
            if ($patient_id <= 0) {
                $error_msg = "Please select a valid patient.";
            } else {
                $chk = $conn->prepare("SELECT id FROM patients WHERE id = ? AND status = 1 LIMIT 1");
                $chk->bind_param('i', $patient_id);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) { $error_msg = "That patient record was not found or is archived. Please select another patient."; }
                $chk->close();
            }
        } elseif ($error_msg === '') {
            $fname = mh_pd_clean_name($_POST['first_name'] ?? '');
            $mname = mh_pd_clean_name($_POST['middle_name'] ?? '');
            $lname = mh_pd_clean_name($_POST['last_name'] ?? '');
            $bdate = trim($_POST['birthdate'] ?? '');
            $sex = (string) ($_POST['sex'] ?? '');
            $contact_raw = trim($_POST['contact_number'] ?? '');
            $contact_norm = mh_normalize_ph_mobile($contact_raw);   // 09XXXXXXXXX or ''
            $address_type = trim($_POST['address_type'] ?? '');
            if (!mh_masterlist_has($conn, 'address_types', $address_type)) { $address_type = mh_home_address_type(); }

            $name_ok = '/^[\p{L}][\p{L}\s\.\'\-]*$/u';
            $d = DateTime::createFromFormat('Y-m-d', $bdate);
            if ($fname === '' || $lname === '') {
                $error_msg = "First and last name are required.";
            } elseif (!preg_match($name_ok, $fname) || !preg_match($name_ok, $lname) || ($mname !== '' && !preg_match($name_ok, $mname))
                      || mb_strlen($fname) > 50 || mb_strlen($lname) > 50 || mb_strlen($mname) > 50) {
                $error_msg = "Names may only contain letters, spaces, hyphens, apostrophes and periods (max 50 characters).";
            } elseif (!$d || $d->format('Y-m-d') !== $bdate || $d > new DateTime('today') || $d < new DateTime('-120 years')) {
                $error_msg = "Please enter a valid birthdate.";
            } elseif (!in_array($sex, ['Male', 'Female'], true)) {
                $error_msg = "Please select the patient's sex.";
            } elseif ($contact_norm === '') {
                $error_msg = "Please enter a valid Philippine mobile number (e.g. 09171234567 or +639171234567).";
            }

            $address = '';
            if ($error_msg === '') {
                if (mh_is_marick_resident($address_type)) {
                    $house  = trim($_POST['house_number'] ?? '');
                    $street = trim($_POST['marick_street'] ?? '');
                    if ($house === '' || !mh_masterlist_has($conn, 'streets', $street)) {
                        $error_msg = "Please enter the house number and choose a street from the list.";
                    } else {
                        $address = mh_build_resident_address($house, $street);
                    }
                } else {
                    $address = trim($_POST['outsider_address'] ?? '');
                    if ($address === '') { $error_msg = "Please enter the patient's full address."; }
                }
            }

            // Email is OPTIONAL. Only its FORMAT is checked here; whether it is already used is
            // decided (together with the name/birthdate/mobile checks) inside the transaction below.
            $real_email = strtolower(trim($_POST['email'] ?? ''));
            if ($error_msg === '' && $real_email !== '' && (!filter_var($real_email, FILTER_VALIDATE_EMAIL) || strlen($real_email) > 100)) {
                $error_msg = "Please enter a valid email address, or leave it blank.";
            }
            if ($error_msg === '') {
                // No email given: placeholder so the NOT NULL UNIQUE column is satisfied (never mailed).
                $email = ($real_email !== '') ? $real_email
                       : preg_replace('/[^a-z0-9]/', '', strtolower($fname)) . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($lname))
                         . '.' . bin2hex(random_bytes(4)) . '@walkin.local';
                $contact = '+63' . substr($contact_norm, 1);   // stored the same way as existing records (+639XXXXXXXXX)
                $new_patient = compact('fname', 'mname', 'lname', 'bdate', 'sex', 'contact', 'contact_norm', 'address_type', 'address', 'email', 'real_email');
            }
        }

        if ($error_msg === '') {
            mh_ensure_queue_schema($conn);                         // DDL commits implicitly, so do it BEFORE the transaction
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // SQL errors become exceptions -> caught below
            try {
                $conn->begin_transaction();

                // Duplicate check INSIDE the transaction with row locks, so two staff members
                // registering the same person at the same moment can't both get through.
                if ($new_patient !== null) {
                    $confirmed = (($_POST['confirm_not_duplicate'] ?? '') === '1');
                    $dup = mh_pd_check_new_patient($conn, $new_patient, $confirmed, true);
                    if ($dup !== null) { $conn->rollback(); }
                }

                if ($dup === null) {
                    if ($new_patient !== null) {
                        // Walk-ins have no portal login; the random password is never used (the portal claim flow sets a real one).
                        $pw = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                        $ins = $conn->prepare(
                            "INSERT INTO patients (first_name, middle_name, last_name, birthdate, sex, contact_number, address_type, address, email, password)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $ins->bind_param('ssssssssss', $new_patient['fname'], $new_patient['mname'], $new_patient['lname'], $new_patient['bdate'],
                            $new_patient['sex'], $new_patient['contact'], $new_patient['address_type'], $new_patient['address'], $new_patient['email'], $pw);
                        $ins->execute();
                        $patient_id = (int) $conn->insert_id;
                        $ins->close();
                        $log_name = mh_format_patient_name($new_patient['fname'], $new_patient['mname'], $new_patient['lname']);
                        logActivity($conn, $user_id, $_SESSION['role'], "Walk-in Registration", "Quick-registered new patient: $log_name.");
                    }

                    // Next number for this department + day, then the 'Waiting' row - same transaction.
                    list($queue_no, $queue_err) = mh_enqueue_patient($conn, $patient_id, $department);
                    if ($queue_no === null) {
                        $conn->rollback();                            // nothing is kept (not even a just-created patient)
                        $error_msg = $queue_err;
                    } else {
                        if (!logActivity($conn, $user_id, $_SESSION['role'], "Walk-In Queue", "Added Patient ID $patient_id to $department queue as #$queue_no (Waiting).")) {
                            throw new RuntimeException('Audit log write failed');   // keep patient + queue + log all-or-nothing
                        }
                        $conn->commit();
                        header("Location: appointments.php?queued=" . $queue_no . "&dept=" . urlencode($department));
                        exit();
                    }
                }
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062) {
                    // Unique key hit (email/username): someone else registered this person a moment ago.
                    $error_msg = "This patient (or email) was just registered by another staff member. Please use the 'Existing Patient' tab and search again.";
                } else {
                    error_log('Walk-in queue failed: ' . $e->getMessage());
                    $error_msg = "The patient could not be added to the queue. Nothing was saved - please try again.";
                }
            }
        }
    }
}
$patients_list = $conn->query("SELECT id, first_name, middle_name, last_name, birthdate, sex FROM patients WHERE status = 1 ORDER BY last_name ASC");   // active patients only
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-In Registration - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .walkin-container { max-width: 800px; margin: 0 auto; }
        .type-selector { display: flex; gap: 15px; margin-bottom: 25px; }
        .type-btn { flex: 1; padding: 15px; text-align: center; border: 2px solid #cbd5e1; border-radius: 8px; background: white; color: #475569; font-weight: bold; font-size: 1.1rem; cursor: pointer; transition: 0.2s; }
        .type-btn.active { border-color: #3b82f6; background: #eff6ff; color: #1d4ed8; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.1); }
        .form-section { display: none; padding: 25px; background: white; border-radius: 12px; border: 1px solid #cbd5e1; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .form-section.active { display: block; }
        .routing-section { background: #fffbeb; border: 1px dashed #f59e0b; padding: 25px; border-radius: 12px; margin-bottom: 25px; }
        @media (max-width: 640px) {
            .name-row { grid-template-columns: 1fr !important; }
        }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container walkin-container">
            
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="margin: 0 0 5px 0; color: #1e293b; font-family: Georgia, serif; font-size: 2rem;">Walk-In Queue</h1>
                <p style="margin: 0; color: #64748b; font-size: 1rem;">Process a patient who is currently at the clinic and add them to the queue.</p>
            </div>

            <?php if ($dup): $is_block = ($dup['level'] === 'block'); ?>
            <div style="padding:18px; border-radius:10px; margin-bottom:20px; border:1px solid <?php echo $is_block ? '#fecaca' : '#fde68a'; ?>; background:<?php echo $is_block ? '#fee2e2' : '#fffbeb'; ?>; color:<?php echo $is_block ? '#991b1b' : '#92400e'; ?>;">
                <div style="font-weight:bold; font-size:1.05rem; margin-bottom:6px; display:flex; align-items:center; gap:8px;"><?php echo mh_icon('x-circle'); ?> <?php echo htmlspecialchars($dup['title']); ?></div>
                <div style="line-height:1.55;"><?php echo htmlspecialchars($dup['message']); ?></div>
                <?php if (!empty($dup['select_id'])): ?>
                    <button type="button" onclick="mhUseExisting(<?php echo (int) $dup['select_id']; ?>)" style="margin-top:12px; padding:10px 16px; border:0; border-radius:6px; background:#0ea5e9; color:#fff; font-weight:bold; cursor:pointer;">Use this patient (Existing Patient tab)</button>
                <?php endif; ?>
            </div>
            <?php elseif ($error_msg): ?>
            <div class='alert alert-error' style='background:#fee2e2; color:#b91c1c; padding:15px; border-radius:8px; border:1px solid #fecaca; margin-bottom:20px;'><?php echo mh_icon('x-circle'); ?> <?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo mh_csrf_field(); ?>
                <div class="routing-section">
                    <h3 style="margin: 0 0 15px 0; color: #b45309; display: flex; align-items: center; gap: 10px;">
                        Where does this patient need to go?
                    </h3>
                    <select name="department" required style="width: 100%; padding: 15px; border-radius: 8px; border: 1px solid #fde68a; font-size: 1.1rem; font-weight: bold; color: var(--text-main, #92400e); background: var(--bg-card, white);">
                        <option value="" disabled selected>Select Clinic Department...</option>
                        <?php echo mh_department_options($conn); ?>
                    </select>
                </div>

                <div class="type-selector">
                    <div class="type-btn active" id="btn-existing" onclick="toggleForm('existing')">Existing Patient</div>
                    <div class="type-btn" id="btn-new" onclick="toggleForm('new')">New Registration</div>
                </div>

                <input type="hidden" name="patient_type" id="patientTypeInput" value="existing">

                <div class="form-section active" id="form-existing">
                    <h3 style="margin: 0 0 15px 0; color: #1e293b;">Select Registered Patient</h3>
                    <select name="existing_patient_id" id="patientSelect" style="width: 100%;">
                        <option value="" disabled selected>Search masterlist...</option>
                        <?php 
                        if($patients_list && $patients_list->num_rows > 0): 
                            while($p = $patients_list->fetch_assoc()): 
                                $age = date_diff(date_create($p['birthdate']), date_create('today'))->y;
                        ?>
                                <option value="<?php echo $p['id']; ?>">
                                    <?php echo htmlspecialchars(mh_format_patient_name($p['first_name'], $p['middle_name'] ?? '', $p['last_name'])); ?> 
                                    (<?php echo $age; ?> yrs, <?php echo htmlspecialchars($p['sex']); ?>)
                                </option>
                        <?php 
                            endwhile; 
                        endif; 
                        ?>
                    </select>
                </div>

                <div class="form-section" id="form-new">
                    <h3 style="margin: 0 0 15px 0; color: #1e293b;">Quick Registration</h3>
                    
                    <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div class="name-row" style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 0.6fr 1fr; gap: 15px;">
                            <div class="form-group"><label>First Name <span style="color:red;">*</span></label><input type="text" name="first_name" id="req_fname" value="<?php echo $prefill_fname; ?>" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-ñÑ]/g, '')" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;"></div>
                            <div class="form-group"><label>Middle Name <span style="color:#94a3b8; font-weight:normal;">(Optional)</span></label><input type="text" name="middle_name" id="req_mname" placeholder="M.I." oninput="this.value = this.value.replace(/[^a-zA-Z\s\-ñÑ]/g, '')" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;"></div>
                            <div class="form-group"><label>Last Name <span style="color:red;">*</span></label><input type="text" name="last_name" id="req_lname" value="<?php echo $prefill_lname; ?>" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-ñÑ]/g, '')" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;"></div>
                        </div>
                        <div class="form-group"><label>Birthdate <span style="color:red;">*</span></label><input type="date" name="birthdate" id="req_bdate" value="<?php echo htmlspecialchars($prefill_bdate); ?>" max="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;"></div>                        
                        <div class="form-group">
                            <label>Sex <span style="color:red;">*</span></label>
                            <select name="sex" id="req_sex" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                <option value="" disabled selected>Select...</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Contact Number <span style="color:red;">*</span></label><input type="tel" name="contact_number" id="req_contact" value="+63" maxlength="13" oninput="this.value = this.value.replace(/[^0-9+]/g, '')" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;"></div>
                        
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Email Address <span style="color:#94a3b8; font-weight:normal;">(Optional - for Patient Portal access)</span></label>
                            <input type="email" name="email" id="req_email" placeholder="patient@email.com" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>

                        <?php if ($dup && $dup['level'] === 'confirm'): ?>
                        <div class="form-group" style="grid-column: 1 / -1; background:#fffbeb; border:1px dashed #f59e0b; padding:12px 14px; border-radius:8px;">
                            <label style="display:flex; gap:10px; align-items:flex-start; font-weight:bold; color:#92400e; cursor:pointer;">
                                <input type="checkbox" name="confirm_not_duplicate" value="1" style="margin-top:3px;">
                                <span>I checked the existing record above - this is a DIFFERENT person.</span>
                            </label>
                        </div>
                        <?php endif; ?>

                        <div class="form-group" style="grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 15px;">
                            <label>Location Type <span style="color:red;">*</span></label>
                            <select name="address_type" id="req_address_type" <?php echo mh_home_type_attr(); ?> onchange="toggleAddressFields()" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                <?php echo mh_masterlist_options($conn, 'address_types', mh_home_address_type(), '', [], mh_address_type_labels()); ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="marickHouseGroup"><label>House/Blk No. <span style="color:red;">*</span></label><input type="text" name="house_number" id="req_house" placeholder="e.g., Blk 1 Lot 2" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;"></div>
                        
                        <div class="form-group" id="marickStreetGroup">
                            <label>Street Name <span style="color:red;">*</span></label>
                            <select name="marick_street" id="req_street" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                <option value="" disabled selected>Select street...</option>
                                <?php echo mh_masterlist_options($conn, 'streets'); ?>
                            </select>
                        </div>
                        
                        <div class="form-group" style="grid-column: 1 / -1; display: none;" id="outsiderAddressGroup">
                            <label>Full Address <span style="color:red;">*</span></label>
                            <input type="text" name="outsider_address" id="req_out_address" placeholder="House No., Street, Barangay, City" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                    </div>
                </div>

                <button type="submit" name="process_walkin" data-confirm="Add this patient to the queue?" data-confirm-title="Add to queue?" data-confirm-type="info" data-confirm-ok="Add to queue" class="btn-submit" style="width: 100%; padding: 18px; font-size: 1.2rem; background: #0ea5e9; border-color: #0284c7; box-shadow: 0 4px 6px rgba(14, 165, 233, 0.2);">
                    Add to Queue & Go to Appointments Dashboard
                </button>
            </form>
            
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        // Jump to the Existing Patient tab with a given patient selected (used by the duplicate panel).
        function mhUseExisting(id) {
            if (window.jQuery && jQuery('#patientSelect').length) { jQuery('#patientSelect').val(String(id)).trigger('change'); }
            toggleForm('existing');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        document.addEventListener('DOMContentLoaded', function () {
            var old = <?php echo json_encode($old, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            // After a failed submit, put back what the staff member typed (the page reloads on POST).
            Object.keys(old).forEach(function (k) {
                var el = document.querySelector('[name="' + k + '"]');
                if (el && old[k] !== '') { el.value = old[k]; }
            });
            <?php if ($reopen_new): ?>
            // Came from an OCR scan with no match, or the New Registration form was just submitted.
            toggleForm('new');
            <?php endif; ?>
        });
    </script>

</body>
</html>