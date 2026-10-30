<?php
require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/patient_layout.php';

$system_name = $system_name ?? mh_setting('system_name');

$patient_id = (int)$_SESSION['patient_id'];
$patient    = mh_pp_patient($conn, $patient_id);

if (!$patient) {
    header("Location: ../patient_logout.php");
    exit();
}

$tab = ($_GET['tab'] ?? 'history') === 'medicines' ? 'medicines' : 'history';
$categories = mh_pp_categories();

mh_pp_layout_start([
    'title'       => $tab === 'medicines' ? 'Dispensed Medicines' : 'Consultation History',
    'active'      => $tab === 'medicines' ? 'medicines' : 'history',
    'patient'     => $patient,
    'system_name' => $system_name,
]);
?>

<div class="pp-tabs">
    <a href="records.php?tab=history" class="pp-tab <?php echo $tab === 'history' ? 'active' : ''; ?>">
        <?php echo mh_icon('file-text'); ?> My Consultation History
    </a>
    <a href="records.php?tab=medicines" class="pp-tab <?php echo $tab === 'medicines' ? 'active' : ''; ?>">
        <?php echo mh_icon('package'); ?> Dispensed Medicines
    </a>
</div>

<?php if ($tab === 'medicines'): ?>

    <?php $dispensed = mh_pp_dispensed($conn, $patient_id); ?>
    <div class="unified-panel">
        <div class="panel-header">
            <h3><?php echo mh_icon('package'); ?> Dispensed Medicine Log</h3>
            <span class="panel-subtitle"><?php echo count($dispensed); ?> record<?php echo count($dispensed) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="pp-section">
            <?php if (empty($dispensed)): ?>
                <div class="pp-empty">
                    <?php echo mh_icon('inbox'); ?>
                    <h4>No medicines dispensed yet</h4>
                    <p>Medicines issued to you at the health center will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="unified-table">
                        <thead>
                            <tr>
                                <th>Date Dispensed</th>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Issued By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dispensed as $m): ?>
                                <tr>
                                    <td><?php echo mh_pp_e(mh_pp_date($m['dispense_date'], 'M d, Y')); ?></td>
                                    <td>
                                        <strong><?php echo mh_pp_e($m['item_name']); ?></strong>
                                        <?php if (!empty($m['brand_name'])): ?><br><span style="color: var(--text-muted); font-size: 0.82rem;"><?php echo mh_pp_e($m['brand_name']); ?></span><?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$m['quantity']; ?> <?php echo mh_pp_e($m['unit'] ?? ''); ?></td>
                                    <td><?php echo mh_pp_e(mh_pp_staff_name($m)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

    <?php $history = mh_pp_history($conn, $patient_id); ?>
    <div class="unified-panel">
        <div class="pp-filters" id="ppRecordFilters">
            <button type="button" class="pp-filter-btn active" data-filter="all">All Records</button>
            <?php foreach ($categories as $key => $cat): ?>
                <button type="button" class="pp-filter-btn" data-filter="<?php echo mh_pp_e($key); ?>"><?php echo mh_pp_e($cat['label']); ?></button>
            <?php endforeach; ?>
        </div>
        <div class="pp-section" id="ppRecordList">
            <?php if (empty($history)): ?>
                <div class="pp-empty">
                    <?php echo mh_icon('inbox'); ?>
                    <h4>No medical records found</h4>
                    <p>You don't have any completed visits or records yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($history as $r): $cat = $categories[$r['cat']] ?? null; ?>
                    <div class="pp-record-card" data-cat="<?php echo mh_pp_e($r['cat']); ?>">
                        <div class="pp-record-head">
                            <div>
                                <?php if ($cat): ?><span class="status-pill <?php echo mh_pp_e($cat['class']); ?>"><?php echo mh_pp_e($cat['label']); ?></span><?php endif; ?>
                                <strong style="margin-left: 8px; color: var(--heading-color);"><?php echo mh_pp_e($r['title']); ?></strong>
                            </div>
                            <span class="pp-timeline-date"><?php echo mh_icon('calendar'); ?> <?php echo mh_pp_e(mh_pp_date($r['date'], 'l, F d, Y')); ?></span>
                        </div>
                        <div class="pp-record-fields">
                            <?php foreach ($r['details'] as $label => $value): if (empty($value)) { continue; } ?>
                                <div>
                                    <span class="k"><?php echo mh_pp_e($label); ?></span>
                                    <span class="v"><?php echo nl2br(mh_pp_e($value)); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="pp-timeline-attending" style="margin-top: 12px;"><?php echo mh_icon('user'); ?> Attended by <?php echo mh_pp_e($r['attending']); ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="pp-empty" id="ppNoFilterMatch" style="display: none;">
                    <?php echo mh_icon('inbox'); ?>
                    <h4>No records match this filter</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        (function () {
            var buttons = document.querySelectorAll('#ppRecordFilters .pp-filter-btn');
            var cards   = document.querySelectorAll('#ppRecordList .pp-record-card');
            var empty   = document.getElementById('ppNoFilterMatch');

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    buttons.forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    var filter = btn.getAttribute('data-filter');
                    var visible = 0;
                    cards.forEach(function (card) {
                        var match = filter === 'all' || card.getAttribute('data-cat') === filter;
                        card.style.display = match ? '' : 'none';
                        if (match) { visible++; }
                    });
                    if (empty) { empty.style.display = visible === 0 ? 'block' : 'none'; }
                });
            });
        })();
    </script>

<?php endif; ?>

<?php mh_pp_layout_end(); ?>
