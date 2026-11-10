<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';
require_once __DIR__ . '/../includes/sms_helper.php';
require_once __DIR__ . '/../includes/patient_profile_helper.php';
require_once __DIR__ . '/../includes/patient_module_helper.php';
require_once __DIR__ . '/../includes/layout/layout.php';

mh_check_module_enabled('patient_masterlist');
require_permission('view_patient_masterlist');
$can_edit_patient = mh_has_permission('edit_patient_profile');
$can_walkin = mh_has_permission('walkin_registration');

$streets = mh_streets($conn);
$edit_state = null;
$error_msg = '';

function mh_ml_update_patient(mysqli $conn, array $in, array $streets)
{
    $id = (int) ($in['edit_id'] ?? 0);
    $first = mh_pm_clean_name($in['first_name'] ?? '');
    $last = mh_pm_clean_name($in['last_name'] ?? '');
    $birthdate = trim((string) ($in['birthdate'] ?? ''));
    $contact = mh_normalize_ph_mobile(trim((string) ($in['contact_number'] ?? '')));
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $type = trim((string) ($in['address_type'] ?? ''));

    $st = $conn->prepare("SELECT email, address_type FROM patients WHERE id = ? AND status = 1 LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $current = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$current) { return [false, 'That patient record was not found or is archived.']; }

    if (!mh_pm_valid_name($first) || !mh_pm_valid_name($last) || $first === '' || $last === '') {
        return [false, 'Names may only contain letters, spaces, hyphens, apostrophes and periods (max 50 characters).'];
    }
    if (!mh_pm_valid_birthdate($birthdate)) { return [false, 'Please enter a valid birthdate.']; }
    if ($contact === '') { return [false, 'Please enter an 11-digit Philippine mobile number starting with 09 (e.g. 09XXXXXXXXX).']; }
    if ($type === '' || (!mh_masterlist_has($conn, 'address_types', $type) && $type !== (string) $current['address_type'])) {
        return [false, 'Please choose a valid location type.'];
    }

    if (mh_is_marick_resident($type)) {
        $house = trim((string) ($in['house_number'] ?? ''));
        $street = trim((string) ($in['marick_street'] ?? ''));
        if ($house === '' || !in_array($street, $streets, true)) {
            return [false, 'Please enter the house number and choose a street from the list.'];
        }
        $address = mh_build_resident_address($house, $street);
    } else {
        $address = trim((string) ($in['outsider_address'] ?? ''));
        if ($address === '') { return [false, "Please enter the patient's full address."]; }
    }

    if ($email === '') {
        $email = mh_pm_is_placeholder_email($current['email']) ? (string) $current['email'] : mh_pm_placeholder_email($first, $last);
    } elseif (!mh_pm_valid_email($email)) {
        return [false, 'Please enter a valid email address ending strictly with .com (e.g. user@example.com).'];
    } elseif (strcasecmp($email, (string) $current['email']) !== 0) {
        $dup = $conn->prepare("SELECT id FROM patients WHERE email = ? AND id <> ? LIMIT 1");
        $dup->bind_param('si', $email, $id);
        $dup->execute();
        $taken = $dup->get_result()->num_rows > 0;
        $dup->close();
        if ($taken) { return [false, 'That email address is already used by another patient.']; }
    }

    $up = $conn->prepare("UPDATE patients SET first_name = ?, last_name = ?, birthdate = ?, contact_number = ?, email = ?, address_type = ?, address = ? WHERE id = ? AND status = 1");
    $up->bind_param('sssssssi', $first, $last, $birthdate, $contact, $email, $type, $address, $id);
    $ok = $up->execute();
    $up->close();
    if (!$ok) { return [false, 'The patient could not be updated. Please try again.']; }
    logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], 'Updated Patient', "Edited details for Patient ID $id.");
    return [true, 'Patient information updated successfully.'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (isset($_POST['archive_patient']) || isset($_POST['update_patient']))) {
    if (!$can_edit_patient) { mh_deny_access(); }

    if (!mh_csrf_check()) {
        http_response_code(403);
        $error_msg = 'Your session security token is missing or expired. Please reload the page and try again.';
    } elseif (isset($_POST['archive_patient'])) {
        $id = (int) ($_POST['patient_id'] ?? 0);
        $st = $conn->prepare("UPDATE patients SET status = 0 WHERE id = ? AND status = 1");
        $st->bind_param('i', $id);
        $st->execute();
        $changed = $st->affected_rows > 0;
        $st->close();
        if ($changed) {
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], 'Archived Patient', "Moved Patient ID $id to the archives.");
            mh_pm_flash_set('success', 'Patient moved to Archives.');
        } else {
            mh_pm_flash_set('error', 'That patient was already archived or no longer exists.');
        }
        mh_pm_redirect_self();
    } else {
        list($ok, $message) = mh_ml_update_patient($conn, $_POST, $streets);
        if ($ok) {
            mh_pm_flash_set('success', $message);
            mh_pm_redirect_self();
        }
        $error_msg = $message;
        $edit_state = array_intersect_key($_POST, array_flip(['edit_id', 'first_name', 'last_name', 'birthdate', 'contact_number', 'email', 'address_type', 'house_number', 'marick_street', 'outsider_address']));
    }
}

$flash = mh_pm_flash_pull();
if ($error_msg !== '') { $flash = ['type' => 'error', 'message' => $error_msg]; }

mh_ensure_patient_contacts_schema($conn);
$result = $conn->query(
    "SELECT p.id, p.first_name, p.middle_name, p.last_name, p.birthdate, p.contact_number, p.email, p.address_type, p.address, p.created_at, pc.extra_contacts
       FROM patients p
       LEFT JOIN (SELECT patient_id,
                         GROUP_CONCAT(CONCAT(contact_name, IF(relationship IS NULL OR relationship = '', '', CONCAT(' (', relationship, ')')), ': ', contact_number) ORDER BY id SEPARATOR ' | ') AS extra_contacts
                    FROM patient_contacts GROUP BY patient_id) pc ON pc.patient_id = p.id
      WHERE p.status = 1
      ORDER BY p.last_name ASC, p.first_name ASC"
);

$patients = [];
$counts = mh_pm_empty_counts();
while ($result && ($row = $result->fetch_assoc())) {
    $row = mh_pm_classify($row, $streets);
    mh_pm_tally($counts, $row);
    $patients[] = $row;
}

mh_layout_head(['title' => 'Patient Masterlist', 'styles' => ['css/patient_modules.css']]);
mh_layout_open();
?>
        <div class="pm-page masterlist-container">
            <div class="pm-head">
                <div>
                    <h1 class="pm-title">Patient Masterlist</h1>
                    <p class="pm-sub">Browse Marick residents and outside patients, and find babies by age in months.</p>
                </div>
                <?php if ($can_walkin): ?>
                <div class="pm-head-actions">
                    <a href="walkin.php" class="btn-add"><?php echo mh_icon('plus'); ?> Walk-In Registration</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($flash) { echo mh_pm_alert($flash['type'], $flash['message']); } ?>

            <div class="unified-panel">
                <div class="pm-panel-head">
                    <?php echo mh_pm_render_filters([
                        'id' => 'mlFilters', 'target' => '#patientsTable', 'counts' => $counts, 'streets' => $streets,
                        'page_size' => 50, 'placeholder' => 'Search name, contact or address',
                    ]); ?>
                </div>

                <div class="table-scroll">
                    <table class="unified-table pm-table" id="patientsTable">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Age</th>
                                <th>Contact</th>
                                <th>Address</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$patients): ?>
                            <tr class="empty-state"><td colspan="6">No active patients found in the masterlist.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($patients as $p): ?>
                            <tr <?php echo mh_pm_row_attrs($p); ?>>
                                <td class="pm-cell-main" data-label="Patient">
                                    <span class="pm-person">
                                        <?php echo mh_avatar_html(mh_initials($p['first_name'], $p['last_name']), 34, $p['first_name'] . ' ' . $p['last_name']); ?>
                                        <span class="pm-name"><?php echo mh_h($p['last_name'] . ', ' . $p['first_name']); ?></span>
                                    </span>
                                </td>
                                <td data-label="Age"><?php echo mh_pm_age_badge($p); ?></td>
                                <td data-label="Contact">
                                    <span>
                                        <?php echo mh_h($p['contact_number'] !== '' ? $p['contact_number'] : 'N/A'); ?>
                                        <?php if (!empty($p['extra_contacts'])): ?>
                                            <span class="pm-muted pm-extra" title="<?php echo mh_h($p['extra_contacts']); ?>"><?php echo mh_h(str_replace(' | ', ', ', $p['extra_contacts'])); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="Address"><?php echo mh_pm_location_badge($p); ?></td>
                                <td data-label="Registered"><?php echo mh_h(date('M d, Y', strtotime($p['created_at']))); ?></td>
                                <td class="pm-cell-actions" data-label="Actions">
                                    <div class="pm-actions">
                                        <a href="view_patient.php?id=<?php echo (int) $p['id']; ?>" class="pm-act pm-act--info">View</a>
                                        <?php if ($can_edit_patient): ?>
                                        <button type="button" class="pm-act pm-act--success" data-edit-patient="<?php echo mh_h(json_encode([
                                            'edit_id' => (int) $p['id'], 'first_name' => $p['first_name'], 'last_name' => $p['last_name'],
                                            'birthdate' => $p['birthdate'], 'contact_number' => $p['contact_number'],
                                            'email' => mh_pm_is_placeholder_email($p['email']) ? '' : $p['email'],
                                            'address_type' => $p['address_type'], 'house_number' => $p['house'],
                                            'marick_street' => $p['street'], 'outsider_address' => $p['is_marick'] ? '' : $p['address'],
                                        ], JSON_UNESCAPED_UNICODE)); ?>">Edit</button>
                                        <form method="POST">
                                            <?php echo mh_csrf_field(); ?>
                                            <input type="hidden" name="patient_id" value="<?php echo (int) $p['id']; ?>">
                                            <button type="submit" name="archive_patient" value="1" class="pm-act pm-act--danger"
                                                data-confirm="They will be removed from this list but saved in System Archives."
                                                data-confirm-title="Archive this patient?" data-confirm-type="danger" data-confirm-ok="Yes, Archive">Archive</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo mh_pm_render_footer('mlFilters'); ?>
            </div>
        </div>
    <?php mh_layout_close(); ?>

    <?php if ($can_edit_patient): ?>
    <div class="modal-overlay" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-content pm-modal">
            <span class="close-btn" data-close-modal="editModal" aria-label="Close">&times;</span>
            <h3 id="editModalTitle">Edit Patient Information</h3>
            <hr class="pm-modal-rule">
            <form method="POST" id="editForm" data-home-type="<?php echo mh_h(mh_home_address_type()); ?>">
                <?php echo mh_csrf_field(); ?>
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="pm-grid">
                    <div class="form-group">
                        <label for="edit_first_name">First name</label>
                        <input type="text" name="first_name" id="edit_first_name" required maxlength="50" pattern="[A-Za-zñÑ\s\-\.']+" title="Only letters are allowed">
                    </div>
                    <div class="form-group">
                        <label for="edit_last_name">Last name</label>
                        <input type="text" name="last_name" id="edit_last_name" required maxlength="50" pattern="[A-Za-zñÑ\s\-\.']+" title="Only letters are allowed">
                    </div>
                    <div class="form-group">
                        <label for="edit_birthdate">Date of birth</label>
                        <input type="date" name="birthdate" id="edit_birthdate" required max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="edit_contact_number">Contact number</label>
                        <input type="tel" name="contact_number" id="edit_contact_number" required maxlength="11" pattern="^09[0-9]{9}$" placeholder="09XXXXXXXXX" title="Must be an 11-digit mobile number starting with 09" inputmode="numeric">
                    </div>
                    <div class="form-group pm-span">
                        <label for="edit_email">Email address <span class="pm-opt">(optional)</span></label>
                        <input type="email" name="email" id="edit_email" pattern="^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$" title="Email must end strictly with .com" placeholder="user@example.com">
                    </div>
                    <div class="form-group pm-span">
                        <label for="edit_address_type">Location type</label>
                        <select name="address_type" id="edit_address_type" required>
                            <?php echo mh_masterlist_options($conn, 'address_types', mh_home_address_type(), '', [], mh_address_type_labels()); ?>
                        </select>
                    </div>
                    <div class="form-group" data-address-home>
                        <label for="edit_house_number">House or block no.</label>
                        <input type="text" name="house_number" id="edit_house_number" placeholder="e.g. Blk 1 Lot 2">
                    </div>
                    <div class="form-group" data-address-home>
                        <label for="edit_marick_street">Street</label>
                        <select name="marick_street" id="edit_marick_street">
                            <option value="">Select street</option>
                            <?php foreach ($streets as $street): ?>
                                <option value="<?php echo mh_h($street); ?>"><?php echo mh_h($street); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group pm-span" data-address-other>
                        <label for="edit_outsider_address">Full address</label>
                        <input type="text" name="outsider_address" id="edit_outsider_address" placeholder="House No., Street, Barangay, City">
                    </div>
                </div>
                <button type="submit" name="update_patient" value="1" class="btn-submit pm-submit"
                    data-confirm="Save changes to this patient record?" data-confirm-title="Save changes?" data-confirm-type="warning" data-confirm-ok="Save">
                    <?php echo mh_icon('save'); ?> Save Changes
                </button>
            </form>
        </div>
    </div>
    <script type="application/json" id="mlState"><?php echo json_encode($edit_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
    <script src="../assets/js/patient_masterlist.js?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/js/patient_masterlist.js'); ?>" defer></script>
    <?php endif; ?>
    <script src="../assets/js/patient_filters.js?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/js/patient_filters.js'); ?>" defer></script>
<?php mh_layout_end(); ?>
