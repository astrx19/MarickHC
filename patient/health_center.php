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

mh_pp_layout_start([
    'title'       => 'Health Center Info',
    'active'      => 'info',
    'patient'     => $patient,
    'system_name' => $system_name,
]);
?>

<div class="pp-hero" style="padding: 26px 30px;">
    <div>
        <h1 style="font-size: 1.4rem;"><?php echo mh_pp_e($system_name); ?></h1>
        <p>Everything you need to know about visiting your barangay health center.</p>
    </div>
</div>

<div style="max-width: 640px;">
    <?php mh_pp_render_center_card(true); ?>
</div>

<?php mh_pp_layout_end(); ?>
