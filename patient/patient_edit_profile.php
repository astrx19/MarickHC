<?php
require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/patient_layout.php';

$system_name = $system_name ?? mh_setting('system_name');

require_once __DIR__ . '/../includes/settings_helper.php';
$marick_streets = mh_streets($conn);   // Streets / Areas / Purok masterlist (database)

$patient_id = (int)$_SESSION['patient_id'];
$success_msg = ''; $error_msg = '';

if (isset($_POST['update_profile'])) {
    $contact      = trim($_POST['contact_number'] ?? '');
    $address_type = $_POST['address_type'] ?? mh_home_address_type();

    if (!mh_masterlist_has($conn, 'address_types', $address_type)) { $address_type = mh_home_address_type(); }
    if (mh_is_marick_resident($address_type)) {
        $house  = trim($_POST['house_number'] ?? '');
        $street = trim($_POST['marick_street'] ?? '');
        $address = mh_build_resident_address($house, $street);   // suffix comes from System Settings
    } else {
        $address = trim($_POST['outsider_address'] ?? '');
    }

    $stmt = $conn->prepare("UPDATE patients SET contact_number = ?, address_type = ?, address = ? WHERE id = ?");
    $stmt->bind_param('sssi', $contact, $address_type, $address, $patient_id);

    if ($stmt->execute()) {
        $stmt->close();
        $new_password = $_POST['new_password'] ?? '';
        if (!empty($new_password)) {
            if ($new_password === ($_POST['confirm_password'] ?? '')) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $pw = $conn->prepare("UPDATE patients SET password = ? WHERE id = ?");
                $pw->bind_param('si', $hashed, $patient_id);
                $pw->execute();
                $pw->close();
                $success_msg = 'Profile and password updated successfully!';
            } else {
                $error_msg = 'Passwords do not match. Profile updated, but password was not changed.';
            }
        } else {
            $success_msg = 'Profile updated successfully!';
        }
    } else {
        $error_msg = 'Error updating profile: ' . $conn->error;
        $stmt->close();
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
            <form method="POST">
                <div class="form-group">
                    <label>Contact Number <span class="required">*</span></label>
                    <input type="tel" name="contact_number" value="<?php echo mh_pp_e($patient['contact_number'] ?? '+63'); ?>" required maxlength="13" oninput="this.value = this.value.replace(/[^0-9+]/g, '')">
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
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="Leave blank to keep current password">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" placeholder="Must match new password">
                    </div>
                </div>

                <button type="submit" name="update_profile" data-confirm="Save your profile changes?" data-confirm-title="Save profile?" data-confirm-type="info" data-confirm-ok="Save" class="btn-submit" style="width: 100%; margin-top: 20px;"><?php echo mh_icon('save'); ?> Save Profile Updates</button>
            </form>
        </div>
    </div>
</div>

<script>
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
