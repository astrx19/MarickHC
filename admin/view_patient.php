<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';   // provides mh_check_module_enabled()
require_once __DIR__ . '/../includes/patient_profile_helper.php';
mh_check_module_enabled('patient_masterlist');   // module master switch
require_permission('view_patient_masterlist');

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: patient_masterlist.php");
    exit();
}

$patient_id = intval($_GET['id']);
$can_edit_patient = mh_has_permission('edit_patient_profile');

$st = $conn->prepare("SELECT * FROM patients WHERE id = ?");
$st->bind_param('i', $patient_id);
$st->execute();
$patient = $st->get_result()->fetch_assoc();
$st->close();
if (!$patient) {
    echo "Patient not found."; exit();
}
$bdate = new DateTime($patient['birthdate']);
$age = $bdate->diff(new DateTime('today'))->y;

/* ---------- additional contact numbers: add / remove (PRG: redirect after saving) ---------- */
$flash_ok = ''; $flash_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_contact']) || isset($_POST['delete_contact']))) {
    if (!$can_edit_patient) { mh_deny_access(); }
    if (!mh_csrf_check()) {
        $_SESSION['vp_flash'] = ['error', 'Your session security token is missing or expired. Please reload the page and try again.'];
    } elseif (isset($_POST['add_contact'])) {
        $err = mh_patient_contact_add($conn, $patient_id, $_POST['contact_name'] ?? '', $_POST['relationship'] ?? '', $_POST['contact_number'] ?? '', (int) $_SESSION['user_id']);
        if ($err === null) {
            $_SESSION['vp_flash'] = ['success', 'Additional contact saved.'];
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Added Patient Contact", "Added an additional contact number for Patient ID $patient_id.");
        } else {
            $_SESSION['vp_flash'] = ['error', $err];
        }
    } else {
        if (mh_patient_contact_delete($conn, $patient_id, (int) ($_POST['contact_id'] ?? 0))) {
            $_SESSION['vp_flash'] = ['success', 'Contact removed.'];
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Removed Patient Contact", "Removed an additional contact number for Patient ID $patient_id.");
        } else {
            $_SESSION['vp_flash'] = ['error', 'That contact no longer exists.'];
        }
    }
    header('Location: view_patient.php?id=' . $patient_id);
    exit();
}
if (!empty($_SESSION['vp_flash'])) {
    list($ftype, $fmsg) = $_SESSION['vp_flash'];
    unset($_SESSION['vp_flash']);
    if ($ftype === 'success') { $flash_ok = $fmsg; } else { $flash_err = $fmsg; }
}

$contacts   = mh_patient_contacts($conn, $patient_id);
$history    = mh_patient_history($conn, $patient_id);
$dispensed  = mh_patient_dispense_history($conn, $patient_id);
$HISTORY_VISIBLE = 8;   // newest records shown first; the rest sit behind "Show older records"
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Profile - <?php echo htmlspecialchars(mh_setting('system_name')); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .profile-header { margin-bottom: 25px; }
        .profile-header h1 { margin: 0 0 5px 0; color: var(--heading-color, #1e293b); font-size: 2rem; font-family: Georgia, serif; text-transform: uppercase; }
        .profile-header p { margin: 0; color: var(--text-muted, #64748b); font-size: 0.95rem; }

        .patient-grid { display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start; }
        .patient-side { display: grid; gap: 25px; }
        @media (max-width: 1024px) { .patient-grid { grid-template-columns: 1fr; } }

        .detail-group { margin-bottom: 15px; border-bottom: 1px dashed var(--border-color, #e2e8f0); padding-bottom: 10px; }
        .detail-group:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-label { display: block; color: var(--text-muted, #64748b); font-size: 0.8rem; font-weight: bold; text-transform: uppercase; margin-bottom: 3px; letter-spacing: 0.5px; }
        .detail-value { color: var(--heading-color, #0f172a); font-size: 1.05rem; font-weight: 500; }

        /* contacts */
        .contact-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding: 10px 0; border-bottom: 1px dashed var(--border-color, #e2e8f0); }
        .contact-row:last-of-type { border-bottom: none; }
        .contact-row .who { font-weight: 600; color: var(--heading-color, #0f172a); }
        .contact-row .rel { font-size: .78rem; color: var(--text-muted, #64748b); }
        .contact-add { display:grid; gap:10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border-color, #e2e8f0); }

        /* timeline */
        .timeline { position: relative; padding-left: 30px; margin-top: 15px; }
        .timeline::before { content: ''; position: absolute; left: 7px; top: 0; bottom: 0; width: 2px; background: var(--border-color, #e2e8f0); }
        .timeline-item { position: relative; margin-bottom: 22px; }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-item[hidden] { display: none; }
        .timeline-dot { position: absolute; left: -30px; top: 5px; width: 16px; height: 16px; border-radius: 50%; border: 3px solid var(--bg-card, #fff); box-shadow: 0 0 0 2px var(--border-color, #e2e8f0); }
        .timeline-card { background: var(--bg-card, #fff); border: 1px solid var(--border-color, #cbd5e1); border-radius: 12px; padding: 15px 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        .timeline-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; border-bottom: 1px solid var(--border-color, #e2e8f0); padding-bottom: 10px; }
        .timeline-type { font-weight: bold; font-size: 0.8rem; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; }
        .timeline-date { color: var(--text-muted, #64748b); font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .timeline-body { font-size: 0.93rem; color: var(--text-main, #334155); line-height: 1.5; }
        .kv { display: grid; grid-template-columns: minmax(120px, 34%) 1fr; gap: 4px 14px; padding: 3px 0; }
        .kv dt { color: var(--text-muted, #64748b); font-weight: 600; font-size: .82rem; }
        .kv dd { margin: 0; color: var(--text-main, #334155); word-break: break-word; }
        .tl-more[hidden] { display: none; }
        .tl-toggle { margin-top: 8px; background: none; border: 0; padding: 4px 0; font-weight: 700; font-size: .85rem; color: var(--primary, #4ca1af); cursor: pointer; }
        .tl-toggle:hover { text-decoration: underline; }
        .timeline-attending { margin-top: 10px; font-size: 0.85rem; color: var(--text-muted, #64748b); font-style: italic; }
        .tl-older { display: block; margin: 18px auto 0; }
        .empty-state { text-align: center; padding: 36px 20px; color: var(--text-muted, #64748b); }
        .empty-state h3 { margin: 0 0 5px 0; color: var(--heading-color, #334155); }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">

            <a href="patient_masterlist.php" class="btn-back" style="display: inline-block; margin-bottom: 15px; color: var(--primary, #4ca1af); text-decoration: none; font-weight: bold;">&larr; Back to Masterlist</a>

            <div class="profile-header" style="display:flex; align-items:center; gap:18px;">
              <?php echo mh_avatar_html(mh_initials($patient['first_name'], $patient['last_name']), 72, $patient['first_name'] . ' ' . $patient['last_name']); ?>
              <div>
                <h1><?php echo mh_h($patient['last_name'] . ', ' . $patient['first_name'] . ' ' . ($patient['middle_name'] ?? '')); ?></h1>
                <p>Patient ID: #<?php echo str_pad($patient['id'], 5, '0', STR_PAD_LEFT); ?> | Registered: <?php echo date("F d, Y", strtotime($patient['created_at'])); ?></p>

                <div style="margin-top: 10px;">
                    <?php if(mh_is_marick_resident($patient['address_type'] ?? '')): ?>
                        <span style="background: #d1fae5; color: #047857; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; border: 1px solid #a7f3d0;"><?php echo mh_icon('home'); ?> <?php echo htmlspecialchars(mh_home_address_type()); ?> Resident</span>
                    <?php else: ?>
                        <span style="background: #e0f2fe; color: #0369a1; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; border: 1px solid #bae6fd;"><?php echo mh_icon('globe'); ?> Outsider</span>
                    <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="patient-grid">

                <div class="patient-side">
                <div class="unified-panel">
                    <div class="panel-controls">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);"><?php echo mh_icon('user'); ?> Personal Information</h3>
                        <?php if ($can_edit_patient): ?>
                        <a href="patient_masterlist.php?q=<?php echo urlencode($patient['last_name']); ?>" class="btn-view-record" style="text-decoration: none; display: inline-block; text-align: center;" title="Edit this patient from the Masterlist">Edit in Masterlist</a>
                        <?php endif; ?>
                    </div>
                    <div style="padding: 20px;">

                        <div class="detail-group">
                            <span class="detail-label">Date of Birth / Age</span>
                            <span class="detail-value"><?php echo date("F d, Y", strtotime($patient['birthdate'])); ?> <span style="color: var(--text-muted, #64748b); font-weight: normal;">(<?php echo (int) $age; ?> Years Old)</span></span>
                        </div>

                        <div class="detail-group">
                            <span class="detail-label">Contact Number</span>
                            <span class="detail-value"><?php echo mh_icon('phone'); ?> <?php echo mh_h($patient['contact_number']); ?></span>
                        </div>

                        <?php if(!empty($patient['email']) && !preg_match('/@walkin\.local$/i', $patient['email'])): ?>
                        <div class="detail-group">
                            <span class="detail-label">Email Address</span>
                            <span class="detail-value"><?php echo mh_icon('mail'); ?> <?php echo mh_h($patient['email']); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="detail-group">
                            <span class="detail-label">Location Details</span>
                            <span class="detail-value"><?php echo mh_icon('map-pin'); ?> <?php echo mh_h($patient['address'] ?? 'No address recorded'); ?></span>
                        </div>

                    </div>
                </div>

                <div class="unified-panel">
                    <div class="panel-controls">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);"><?php echo mh_icon('users'); ?> Additional Contacts</h3>
                    </div>
                    <div style="padding: 8px 20px 20px;">
                        <?php if ($contacts): foreach ($contacts as $c): ?>
                            <div class="contact-row">
                                <div>
                                    <div class="who"><?php echo mh_h($c['contact_name']); ?> <?php if (!empty($c['relationship'])): ?><span class="rel">&middot; <?php echo mh_h($c['relationship']); ?></span><?php endif; ?></div>
                                    <div class="rel"><?php echo mh_icon('phone'); ?> <?php echo mh_h($c['contact_number']); ?></div>
                                </div>
                                <?php if ($can_edit_patient): ?>
                                <form method="POST" style="margin:0;">
                                    <?php echo mh_csrf_field(); ?>
                                    <input type="hidden" name="contact_id" value="<?php echo (int) $c['id']; ?>">
                                    <button type="submit" name="delete_contact" class="btn-outline-red" data-confirm="Remove <?php echo mh_h($c['contact_name']); ?> (<?php echo mh_h($c['contact_number']); ?>) from this patient?" data-confirm-title="Remove contact?" data-confirm-type="danger" data-confirm-ok="Remove">Remove</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; else: ?>
                            <p style="margin: 14px 0 4px; color: var(--text-muted, #64748b); font-size:.9rem;">No additional contact numbers yet. Add a parent, guardian or child so the clinic can reach the family.</p>
                        <?php endif; ?>

                        <?php if ($can_edit_patient && count($contacts) < 5): ?>
                        <form method="POST" class="contact-add">
                            <?php echo mh_csrf_field(); ?>
                            <div class="form-group" style="margin:0;"><label>Contact person</label>
                                <input type="text" name="contact_name" maxlength="100" required placeholder="e.g., Maria Santos"></div>
                            <div class="form-group" style="margin:0;"><label>Relationship</label>
                                <input type="text" name="relationship" maxlength="50" list="relOptions" placeholder="e.g., Daughter / Mother / Guardian">
                                <datalist id="relOptions"><option value="Parent"><option value="Son / Daughter"><option value="Spouse"><option value="Sibling"><option value="Guardian"><option value="Relative"><option value="Neighbor"></datalist></div>
                            <div class="form-group" style="margin:0;"><label>Mobile number</label>
                                <input type="tel" name="contact_number" required maxlength="16" inputmode="tel" placeholder="09123456789 or +639123456789"></div>
                            <button type="submit" name="add_contact" class="btn-submit btn-sm">Add contact</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                </div>

                <div class="patient-side">
                <div class="unified-panel">
                    <div class="panel-controls" style="background: var(--bg-hover, #f8fafc); border-bottom: 1px solid var(--border-color, #cbd5e1);">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);"><?php echo mh_icon('folder'); ?> Complete Medical History <small style="font-weight:600; color: var(--text-muted, #64748b);">(<?php echo count($history); ?>)</small></h3>
                    </div>

                    <div style="padding: 20px;">
                        <?php if (count($history) > 0): ?>
                            <div class="timeline" id="historyTimeline">
                                <?php foreach ($history as $n => $rec):
                                    $rgb = implode(',', $rec['color']);
                                    $fields  = $rec['fields'];
                                    $summary = array_slice($fields, 0, 3, true);
                                    $more    = array_slice($fields, 3, null, true);
                                ?>
                                    <div class="timeline-item"<?php echo $n >= $HISTORY_VISIBLE ? ' hidden data-older="1"' : ''; ?>>
                                        <div class="timeline-dot" style="background-color: rgb(<?php echo $rgb; ?>);"></div>
                                        <div class="timeline-card">
                                            <div class="timeline-header">
                                                <span class="timeline-type" style="background: rgba(<?php echo $rgb; ?>, .14); color: rgb(<?php echo $rgb; ?>); border: 1px solid rgba(<?php echo $rgb; ?>, .4);"><?php echo mh_h($rec['type']); ?></span>
                                                <span class="timeline-date"><?php echo mh_icon('calendar'); ?> <?php echo $rec['date'] ? date("M d, Y", strtotime($rec['date'])) : 'No date'; ?><?php echo !empty($rec['time']) ? ' &middot; ' . mh_h($rec['time']) : ''; ?></span>
                                            </div>
                                            <div class="timeline-body">
                                                <?php if (!$fields): ?>
                                                    <em style="color: var(--text-muted, #64748b);">No details were recorded for this visit.</em>
                                                <?php else: ?>
                                                    <dl style="margin:0;">
                                                        <?php foreach ($summary as $label => $val): ?>
                                                            <div class="kv"><dt><?php echo mh_h($label); ?></dt><dd><?php echo nl2br(mh_h($val)); ?></dd></div>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                    <?php if ($more): ?>
                                                    <dl class="tl-more" hidden style="margin:0;">
                                                        <?php foreach ($more as $label => $val): ?>
                                                            <div class="kv"><dt><?php echo mh_h($label); ?></dt><dd><?php echo nl2br(mh_h($val)); ?></dd></div>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                    <button type="button" class="tl-toggle" aria-expanded="false" data-more="<?php echo count($more); ?>">See more (<?php echo count($more); ?>)</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="timeline-attending">Attending: <strong><?php echo mh_h($rec['attending']); ?></strong></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($history) > $HISTORY_VISIBLE): ?>
                                <button type="button" class="btn-secondary btn-sm tl-older" id="showOlder">Show older records (<?php echo count($history) - $HISTORY_VISIBLE; ?> more)</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div style="display:flex; justify-content:center; margin-bottom: 10px; opacity:.6;"><?php echo mh_icon('file-text', 'mh-icon-xl'); ?></div>
                                <h3>No Medical History</h3>
                                <p style="margin: 0;">This patient has not had any consultations or procedures logged yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="unified-panel">
                    <div class="panel-controls" style="background: var(--bg-hover, #f8fafc); border-bottom: 1px solid var(--border-color, #cbd5e1);">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);"><?php echo mh_icon('package'); ?> Dispensed Medicine History <small style="font-weight:600; color: var(--text-muted, #64748b);">(<?php echo count($dispensed); ?>)</small></h3>
                    </div>
                    <div style="overflow-x:auto;">
                    <?php if ($dispensed): ?>
                        <table class="unified-table">
                            <thead><tr><th>Date</th><th>Medicine</th><th>Quantity</th><th>Batch / Lot</th><th>Given by</th></tr></thead>
                            <tbody>
                            <?php foreach ($dispensed as $d): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo date('M d, Y', strtotime($d['dispense_date'])); ?><br><small style="color: var(--text-muted, #64748b);"><?php echo date('g:i A', strtotime($d['dispense_date'])); ?></small></td>
                                    <td><strong><?php echo mh_h($d['item_name']); ?></strong><?php if (!empty($d['brand_name'])): ?><br><small style="color: var(--text-muted, #64748b);"><?php echo mh_h($d['brand_name']); ?></small><?php endif; ?></td>
                                    <td style="white-space:nowrap;"><?php echo mh_h(rtrim(rtrim(number_format((float) $d['quantity'], 2, '.', ''), '0'), '.')); ?> <?php echo mh_h($d['unit']); ?></td>
                                    <td><?php echo mh_h($d['batch_number'] ?: '—'); ?><?php if (!empty($d['is_fefo_override'])): ?> <span class="mh-badge mh-badge-warning" title="An earlier-expiring batch still had stock">FEFO override</span><?php endif; ?></td>
                                    <td><?php echo mh_h(mh_ph_staff($d['staff_first'], $d['staff_last'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state"><h3>No Medicine Dispensed</h3><p style="margin:0;">Nothing has been dispensed to this patient yet.</p></div>
                    <?php endif; ?>
                    </div>
                </div>
                </div>

            </div>
        </div>
    </div>

    <?php echo mh_flash_scripts($flash_ok, $flash_err); ?>
    <script>
        // "See more" on a record, and "Show older records" for long histories.
        document.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('.tl-toggle') : null;
            if (t) {
                var more = t.previousElementSibling;
                var open = more.hasAttribute('hidden');
                if (open) { more.removeAttribute('hidden'); } else { more.setAttribute('hidden', ''); }
                t.setAttribute('aria-expanded', open ? 'true' : 'false');
                t.textContent = open ? 'See less' : 'See more (' + t.getAttribute('data-more') + ')';
                return;
            }
            if (e.target.id === 'showOlder') {
                document.querySelectorAll('#historyTimeline [data-older]').forEach(function (el) { el.removeAttribute('hidden'); });
                e.target.parentNode.removeChild(e.target);
            }
        });
    </script>
</body>
</html>
