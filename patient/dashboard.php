<?php
require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/patient_layout.php';

$system_name = $system_name ?? mh_setting('system_name');
$global_announcement = $global_announcement ?? '';

$patient_id = (int)$_SESSION['patient_id'];
$patient    = mh_pp_patient($conn, $patient_id);

if (!$patient) {
    // Session points at a patient row that no longer exists.
    header("Location: ../patient_logout.php");
    exit();
}

$history   = mh_pp_history($conn, $patient_id);
$dispensed = mh_pp_dispensed($conn, $patient_id, 5);
$programs  = mh_pp_programs($conn, $patient_id);

$total_visits = 0;
foreach ($history as $h) {
    if (in_array($h['cat'], mh_pp_visit_categories(), true)) { $total_visits++; }
}
$recent_meds_count = mh_pp_recent_dispense_count($conn, $patient_id, 90);
$last_visit_date   = $history[0]['date'] ?? null;

$categories = mh_pp_categories();

mh_pp_layout_start([
    'title'       => 'Dashboard',
    'active'      => 'dashboard',
    'patient'     => $patient,
    'system_name' => $system_name,
]);
?>

<?php if (!empty($global_announcement)): ?>
    <div class="pp-notice">
        <?php echo mh_icon('megaphone', 'mh-icon-xl'); ?>
        <div>
            <h4>Notice from the Health Center</h4>
            <p><?php echo nl2br(mh_pp_e($global_announcement)); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="pp-hero">
    <div>
        <h1>Welcome back, <?php echo mh_pp_e($patient['first_name']); ?>!</h1>
        <p>Here's a quick look at your health records with <?php echo mh_pp_e($system_name); ?>.</p>
    </div>
    <div class="pp-hero-meta">
        <div>
            <span class="label">Patient ID</span>
            <span class="value"><?php echo mh_pp_e(mh_pp_patient_no($patient['id'])); ?></span>
        </div>
        <div>
            <span class="label">Last Consultation</span>
            <span class="value"><?php echo $last_visit_date ? mh_pp_e(mh_pp_date($last_visit_date, 'M d, Y')) : 'No visits yet'; ?></span>
        </div>
    </div>
</div>

<div class="pp-stats">
    <div class="stat-card pp-stat">
        <span class="stat-icon-badge"><?php echo mh_icon('activity'); ?></span>
        <h3>Total Health Center Visits</h3>
        <div class="number"><?php echo (int)$total_visits; ?></div>
        <div class="sub">General, dental &amp; maternal visits on record</div>
    </div>
    <div class="stat-card pp-stat">
        <span class="stat-icon-badge"><?php echo mh_icon('package'); ?></span>
        <h3>Recent Prescriptions</h3>
        <div class="number"><?php echo (int)$recent_meds_count; ?></div>
        <div class="sub">Medicines dispensed in the last 90 days</div>
    </div>
    <div class="stat-card pp-stat">
        <span class="stat-icon-badge"><?php echo mh_icon('shield'); ?></span>
        <h3>Program Enrollment</h3>
        <div class="number"><?php echo count($programs); ?></div>
        <div class="sub"><?php echo count($programs) > 0 ? 'Active health programs' : 'No programs enrolled yet'; ?></div>
    </div>
</div>

<div class="pp-grid">
    <div>
        <div class="unified-panel">
            <div class="panel-header">
                <h3><?php echo mh_icon('file-text'); ?> Recent Medical Consultations</h3>
                <a href="records.php?tab=history" class="btn-view-record">View All</a>
            </div>
            <div class="pp-section">
                <?php if (empty($history)): ?>
                    <div class="pp-empty">
                        <?php echo mh_icon('inbox'); ?>
                        <h4>No consultations yet</h4>
                        <p>Your visit history will appear here after your first check-up.</p>
                    </div>
                <?php else: ?>
                    <div class="pp-timeline">
                        <?php foreach (array_slice($history, 0, 5) as $r): $cat = $categories[$r['cat']] ?? null; ?>
                            <div class="pp-timeline-item">
                                <span class="pp-timeline-dot"></span>
                                <div class="pp-timeline-head">
                                    <span class="pp-timeline-title"><?php echo mh_pp_e($r['title']); ?></span>
                                    <span class="pp-timeline-date"><?php echo mh_pp_e(mh_pp_date($r['date'], 'M d, Y')); ?></span>
                                </div>
                                <?php if ($cat): ?>
                                    <span class="status-pill <?php echo mh_pp_e($cat['class']); ?>"><?php echo mh_pp_e($cat['label']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($r['summary'])): ?>
                                    <div class="pp-timeline-summary"><?php echo mh_pp_e($r['summary']); ?></div>
                                <?php endif; ?>
                                <div class="pp-timeline-attending"><?php echo mh_icon('user'); ?> <?php echo mh_pp_e($r['attending']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="unified-panel">
            <div class="panel-header">
                <h3><?php echo mh_icon('package'); ?> Dispensed Medicine Log</h3>
                <a href="records.php?tab=medicines" class="btn-view-record">View All</a>
            </div>
            <div class="pp-section">
                <?php if (empty($dispensed)): ?>
                    <div class="pp-empty">
                        <?php echo mh_icon('inbox'); ?>
                        <h4>No medicines dispensed yet</h4>
                        <p>Medicines issued to you at the health center will be listed here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($dispensed as $m): ?>
                        <div class="pp-med-row">
                            <span class="pp-med-icon"><?php echo mh_icon('package'); ?></span>
                            <div>
                                <div class="pp-med-name"><?php echo mh_pp_e($m['item_name']); ?><?php echo !empty($m['brand_name']) ? ' (' . mh_pp_e($m['brand_name']) . ')' : ''; ?></div>
                                <div class="pp-med-meta">Dispensed <?php echo mh_pp_e(mh_pp_date($m['dispense_date'], 'M d, Y')); ?> by <?php echo mh_pp_e(mh_pp_staff_name($m)); ?></div>
                            </div>
                            <div class="pp-med-qty">
                                <?php echo (int)$m['quantity']; ?> <?php echo mh_pp_e($m['unit'] ?? ''); ?>
                                <small>Qty</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <div class="unified-panel">
            <div class="panel-header">
                <h3><?php echo mh_icon('user'); ?> My Profile</h3>
                <a href="patient_edit_profile.php" class="btn-view-record">Edit</a>
            </div>
            <div class="pp-section">
                <div class="pp-field">
                    <span class="k">Full Name</span>
                    <span class="v"><?php echo mh_pp_e(trim($patient['first_name'] . ' ' . ($patient['middle_name'] ?? '') . ' ' . $patient['last_name'])); ?></span>
                </div>
                <div class="pp-field">
                    <span class="k">Date of Birth</span>
                    <span class="v">
                        <?php echo mh_pp_e(mh_pp_date($patient['birthdate'] ?? null)); ?>
                        <?php $age = mh_pp_age($patient['birthdate'] ?? null); ?>
                        <?php if ($age !== null): ?><span style="color: var(--text-muted); font-weight: 600; font-size: 0.85rem;">(<?php echo (int)$age; ?> yrs old)</span><?php endif; ?>
                    </span>
                </div>
                <div class="pp-field">
                    <span class="k">Contact Number</span>
                    <span class="v"><?php echo mh_pp_e($patient['contact_number'] ?? '—'); ?></span>
                </div>
                <div class="pp-field">
                    <span class="k">Address</span>
                    <span class="v"><?php echo mh_pp_e($patient['address'] ?? 'No address recorded'); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($programs)): ?>
        <div class="unified-panel">
            <div class="panel-header">
                <h3><?php echo mh_icon('shield'); ?> Program Status</h3>
            </div>
            <div class="pp-section">
                <div class="pp-programs">
                    <?php foreach ($programs as $p): ?>
                        <div class="pp-program">
                            <span class="icn"><?php echo mh_icon($p['icon']); ?></span>
                            <div>
                                <strong><?php echo mh_pp_e($p['label']); ?></strong>
                                <div class="status"><?php echo mh_pp_e($p['status']); ?></div>
                                <div class="detail"><?php echo mh_pp_e($p['detail']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php mh_pp_render_center_card(false); ?>
    </div>
</div>

<?php mh_pp_layout_end(); ?>
