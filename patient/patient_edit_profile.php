<?php
require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/patient_layout.php';
require_once __DIR__ . '/../includes/password_change_helpers.php';

$system_name = $system_name ?? mh_setting('system_name');

require_once __DIR__ . '/../includes/settings_helper.php';
$marick_streets = mh_streets($conn);   // Streets / Areas / Purok masterlist (database)

$patient_id = (int)$_SESSION['patient_id'];
$success_msg = ''; $error_msg = '';

/* Figure 58: "upload Display Photo". Stored as a file (assets/images/patient_pics/<id>.jpg), like staff photos:
   the ERD's patients table has no photo column, so none is added. Re-encoded with GD when available. */
if (isset($_POST['upload_photo'])) {
    $f = $_FILES['profile_pic'] ?? null;
    if (!$f || (int) $f['error'] === UPLOAD_ERR_NO_FILE) {
        $error_msg = 'Please choose a photo first.';
    } elseif ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $error_msg = 'The photo could not be uploaded. Please try again.';
    } elseif ($f['size'] > 3 * 1024 * 1024) {
        $error_msg = 'That photo is too large (maximum 3 MB).';
    } else {
        $info = @getimagesize($f['tmp_name']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            $error_msg = 'Only JPG or PNG photos are allowed.';
        } else {
            $dir = __DIR__ . '/../assets/images/patient_pics/';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $dest = $dir . $patient_id . '.jpg';
            $saved = false;
            if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
                $img = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
                if ($img) {
                    $w = imagesx($img); $h = imagesy($img);
                    $scale = min(1, 400 / max($w, $h));
                    $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
                    $out = imagecreatetruecolor($nw, $nh);
                    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
                    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    $saved = imagejpeg($out, $dest, 85);
                    imagedestroy($img); imagedestroy($out);
                }
            } else {
                $saved = move_uploaded_file($f['tmp_name'], $dest);
            }
            if ($saved) {
                $success_msg = 'Display photo updated.';
                logActivity($conn, $patient_id, 'patient', 'Profile Update', 'Patient #' . $patient_id . ' uploaded a new display photo.');
            } else {
                $error_msg = 'We could not save your photo right now. Please try again.';
            }
        }
    }
}

if (isset($_POST['update_profile'])) {
    $contact_raw  = trim($_POST['contact_number'] ?? '');
    $contact      = function_exists('mh_normalize_ph_mobile') ? mh_normalize_ph_mobile($contact_raw) : $contact_raw;
    $address_type = $_POST['address_type'] ?? mh_home_address_type();

    if ($contact === '') {
        $error_msg = 'Please enter an 11-digit Philippine mobile number starting with 09 (e.g. 09XXXXXXXXX).';
    } else {
        if (!mh_masterlist_has($conn, 'address_types', $address_type)) { $address_type = mh_home_address_type(); }
        if (mh_is_marick_resident($address_type)) {
            $house  = trim($_POST['house_number'] ?? '');
            $street = trim($_POST['marick_street'] ?? '');
            $address = mh_build_resident_address($house, $street);   // suffix comes from System Settings
        } else {
            $address = trim($_POST['outsider_address'] ?? '');
        }

    // ---- Password section FIRST: if it is wrong, nothing at all is changed ----
    $current_password = (string) ($_POST['current_password'] ?? '');
    $new_password     = (string) ($_POST['new_password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');
    $wants_password   = ($new_password !== '' || $confirm_password !== '');
    $pw_error = null;
    if ($wants_password) {
        $hs = $conn->prepare("SELECT password FROM patients WHERE id = ? LIMIT 1");
        $hs->bind_param('i', $patient_id);
        $hs->execute();
        $row = $hs->get_result()->fetch_assoc();
        $hs->close();
        $pw_error = mh_pw_change_check($row['password'] ?? '', $current_password, $new_password, $confirm_password, 'patient:' . $patient_id);
    }

    if ($pw_error !== null) {
        $error_msg = $pw_error . ' Your profile was not changed.';
    } else {
        $stmt = $conn->prepare("UPDATE patients SET contact_number = ?, address_type = ?, address = ? WHERE id = ?");
        $stmt->bind_param('sssi', $contact, $address_type, $address, $patient_id);

        if ($stmt->execute()) {
            $stmt->close();
            if ($wants_password) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $pw = $conn->prepare("UPDATE patients SET password = ? WHERE id = ?");
                $pw->bind_param('si', $hashed, $patient_id);
                $pw->execute();
                $pw->close();
                mh_pw_change_clear('patient:' . $patient_id);
                // Sign out every OTHER device that still has the old password's session open.
                if (function_exists('mh_ss_issue')) { mh_ss_issue('patient', $patient_id); }
                $success_msg = 'Profile and password updated successfully!';
            } else {
                $success_msg = 'Profile updated successfully!';
            }
        } else {
            error_log('patient_edit_profile update failed: ' . $conn->error);   // never show database errors to patients
            $error_msg = 'We could not save your profile right now. Please try again.';
            $stmt->close();
        }
    }
}
}

$patient = mh_pp_patient($conn, $patient_id);
if (!$patient) {
    header("Location: ../patient_logout.php");
    exit();
}

$current_house = ''; $current_street = '';
if (mh_is_marick_resident($patient['address_type'] ?? '') && ($parsed = mh_parse_resident_address($patient['address'] ?? ''))) {
    list($current_house, $current_street) = $parsed;
}

mh_pp_layout_start([
    'title'       => 'My Profile',
    'active'      => 'profile',
    'patient'     => $patient,
    'system_name' => $system_name,
]);
?>

<?php if ($success_msg): ?>
    <div class="pp-notice" style="background: rgba(16,185,129,0.12); border-color: rgba(16,185,129,0.3);">
        <?php echo mh_icon('check-circle'); ?>
        <div><p style="color: var(--text-main);"><?php echo mh_pp_e($success_msg); ?></p></div>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="pp-notice" style="background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.3);">
        <?php echo mh_icon('x-circle'); ?>
        <div><p style="color: var(--text-main);"><?php echo mh_pp_e($error_msg); ?></p></div>
    </div>
<?php endif; ?>

<div style="max-width: 640px;">
    <div class="unified-panel">
        <div class="panel-header">
            <h3><?php echo mh_icon('user'); ?> Edit Personal Profile</h3>
        </div>
        <div class="pp-section">
            <?php $mh_pic = __DIR__ . '/../assets/images/patient_pics/' . $patient_id . '.jpg'; ?>
            <form method="POST" enctype="multipart/form-data" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:22px; padding-bottom:18px; border-bottom:1px solid var(--border-color,#e2e8f0);">
                <?php if (is_file($mh_pic)): ?>
                    <img src="../assets/images/patient_pics/<?php echo (int) $patient_id; ?>.jpg?t=<?php echo (int) @filemtime($mh_pic); ?>" alt="Your display photo" style="width:64px; height:64px; border-radius:50%; object-fit:cover;">
                <?php endif; ?>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:4px;">Display Photo</label>
                    <input type="file" name="profile_pic" accept="image/jpeg,image/png">
                    <small style="display:block; opacity:.7;">JPG or PNG, up to 3 MB.</small>
                </div>
                <button type="submit" name="upload_photo" value="1" class="btn-soft" style="padding:8px 14px; border-radius:8px; cursor:pointer;">Upload Photo</button>
            </form>
            <form method="POST">
                <div class="form-group">
                    <label>Contact Number <span class="required">*</span></label>
                    <?php
                        $p_contact = $patient['contact_number'] ?? '';
                        if (strpos($p_contact, '+63') === 0) {
                            $p_contact = '0' . substr($p_contact, 3);
                        } elseif (strpos($p_contact, '63') === 0) {
                            $p_contact = '0' . substr($p_contact, 2);
                        }
                    ?>
                    <input type="tel" name="contact_number" value="<?php echo mh_pp_e($p_contact); ?>" required maxlength="11" pattern="^09[0-9]{9}$" placeholder="09XXXXXXXXX" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">
                </div>

                <div class="form-group">
                    <label>Location Type <span class="required">*</span></label>
                    <select name="address_type" id="req_address_type" <?php echo mh_home_type_attr(); ?> onchange="ppToggleAddressFields()" required>
                        <?php echo mh_masterlist_options($conn, 'address_types', ($patient['address_type'] ?? '') ?: mh_home_address_type(), '', [], mh_address_type_labels()); ?>
                    </select>
                </div>

                <div class="form-group" id="marickHouseGroup" style="<?php echo (!mh_is_marick_resident($patient['address_type'] ?? mh_home_address_type())) ? 'display:none;' : ''; ?>">
                    <label>House/Blk No. <span class="required">*</span></label>
                    <input type="text" name="house_number" id="req_house" value="<?php echo mh_pp_e($current_house); ?>" placeholder="e.g., Blk 1 Lot 2">
                </div>

                <div class="form-group" id="marickStreetGroup" style="<?php echo (!mh_is_marick_resident($patient['address_type'] ?? mh_home_address_type())) ? 'display:none;' : ''; ?>">
                    <label>Street Name <span class="required">*</span></label>
                    <select name="marick_street" id="req_street">
                        <option value="" disabled>Select street...</option>
                        <?php echo mh_masterlist_options($conn, 'streets', $current_street); ?>
                    </select>
                </div>

                <div class="form-group" id="outsiderAddressGroup" style="<?php echo mh_is_marick_resident($patient['address_type'] ?? mh_home_address_type()) ? 'display:none;' : ''; ?>">
                    <label>Full Address <span class="required">*</span></label>
                    <input type="text" name="outsider_address" id="req_out_address" value="<?php echo mh_pp_e($patient['address'] ?? ''); ?>" placeholder="House No., Street, Barangay, City">
                </div>

                <div class="form-section" style="margin-top: 6px;">
                    <div class="form-section-title">Change Password (Optional)</div>
                    <div class="form-group">
                        <label>Current Password <span class="required" id="curPwStar" style="display:none;">*</span></label>
                        <input type="password" name="current_password" id="curPw" autocomplete="current-password" placeholder="Required only when changing your password">
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" id="newPw" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Leave blank to keep current password">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" id="cfmPw" autocomplete="new-password" placeholder="Must match new password">
                    </div>
                </div>

                <button type="submit" name="update_profile" data-confirm="Save your profile changes?" data-confirm-title="Save profile?" data-confirm-type="info" data-confirm-ok="Save" class="btn-submit" style="width: 100%; margin-top: 20px;"><?php echo mh_icon('save'); ?> Save Profile Updates</button>
            </form>
        </div>
    </div>
</div>

<script>
    // The current password becomes required the moment a new one is typed (native validation runs
    // before the "Save profile?" confirmation, so people are told straight away).
    (function () {
        var cur = document.getElementById('curPw'), nw = document.getElementById('newPw'), cf = document.getElementById('cfmPw'), star = document.getElementById('curPwStar');
        function sync() {
            var need = nw.value !== '' || cf.value !== '';
            cur.required = need;
            cf.required = nw.value !== '';
            star.style.display = need ? 'inline' : 'none';
            cf.setCustomValidity(cf.value !== nw.value ? 'The new passwords do not match.' : '');
        }
        nw.addEventListener('input', sync); cf.addEventListener('input', sync);
    })();

    function ppToggleAddressFields() {
        var type   = document.getElementById('req_address_type').value;
        var hNum   = document.getElementById('req_house');
        var st     = document.getElementById('req_street');
        var outAdr = document.getElementById('req_out_address');

        var sel = document.getElementById('req_address_type');
        var isMarick = (sel.dataset.homeType || '').trim().toLowerCase() === String(type).trim().toLowerCase() && type !== '';
        document.getElementById('marickHouseGroup').style.display   = isMarick ? 'block' : 'none';
        document.getElementById('marickStreetGroup').style.display  = isMarick ? 'block' : 'none';
        document.getElementById('outsiderAddressGroup').style.display = isMarick ? 'none' : 'block';

        hNum.required   = isMarick;
        st.required     = isMarick;
        outAdr.required = !isMarick;
    }
</script>

<?php mh_pp_layout_end(); ?>
