<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings_helper.php';   // provides mh_check_module_enabled()
mh_check_module_enabled('patient_masterlist');   // module master switch
require_permission('view_patient_masterlist');

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: patient_masterlist.php");
    exit();
}

$patient_id = intval($_GET['id']);
// Every lookup below binds the patient id as a parameter.
$by_patient = function (string $sql) use ($conn, $patient_id) {
    $st = $conn->prepare($sql);
    if (!$st) { return false; }
    $st->bind_param('i', $patient_id);
    $st->execute();
    $res = $st->get_result();
    $st->close();
    return $res;
};

$query = $by_patient("SELECT * FROM patients WHERE id = ?");
if(!$query || $query->num_rows == 0) {
    echo "Patient not found."; exit();
}
$patient = $query->fetch_assoc();
$bdate = new DateTime($patient['birthdate']);
$age = $bdate->diff(new DateTime('today'))->y;

$history = [];
$consults = $by_patient("SELECT c.*, s.last_name as doc_name FROM consultations c LEFT JOIN staff s ON c.doctor_id = s.id WHERE c.patient_id = ?");
if($consults) {
    while($row = $consults->fetch_assoc()) {
        $history[] = [
            'type' => 'General Consult',
            'date' => $row['visit_date'],
            'attending' => 'Dr. ' . ($row['doc_name'] ?? 'Unknown'),
            'details' => "<b>Diagnosis:</b> " . htmlspecialchars($row['diagnosis'] ?? 'N/A') . "<br><b>Complaint:</b> " . htmlspecialchars($row['chief_complaints'] ?? $row['symptoms'] ?? 'None'),
            'color' => '#3b82f6', 'bg' => '#eff6ff'
        ];
    }
}

$dental = $by_patient("SELECT d.*, s.last_name as doc_name FROM dental_records d LEFT JOIN staff s ON d.dentist_id = s.id WHERE d.patient_id = ?");
if($dental) {
    while($row = $dental->fetch_assoc()) {
        $history[] = [
            'type' => 'Dental Clinic',
            'date' => $row['visit_date'],
            'attending' => 'Dr. ' . ($row['doc_name'] ?? 'Unknown'),
            'details' => "<b>Procedure:</b> " . htmlspecialchars($row['procedure_done'] ?? 'N/A') . "<br><b>Tooth No:</b> " . htmlspecialchars($row['tooth_number'] ?? 'N/A'),
            'color' => '#f59e0b', 'bg' => '#fffbeb'
        ];
    }
}

$maternal = $by_patient("SELECT m.*, s.last_name as doc_name FROM maternal_records m LEFT JOIN staff s ON m.midwife_id = s.id WHERE m.patient_id = ?");
if($maternal) {
    while($row = $maternal->fetch_assoc()) {
        $history[] = [
            'type' => 'Prenatal Care',
            'date' => $row['visit_date'],
            'attending' => 'MW. ' . ($row['doc_name'] ?? 'Unknown'),
            'details' => "<b>AOG:</b> " . htmlspecialchars($row['aog'] ?? $row['gestational_weeks'] ?? 'N/A') . "<br><b>BP:</b> " . htmlspecialchars($row['blood_pressure'] ?? 'N/A'),
            'color' => '#ec4899', 'bg' => '#fdf2f8'
        ];
    }
}

usort($history, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

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
        .profile-header h1 { margin: 0 0 5px 0; color: #1e293b; font-size: 2rem; font-family: Georgia, serif; text-transform: uppercase; }
        .profile-header p { margin: 0; color: #64748b; font-size: 0.95rem; }
    
        .patient-grid { display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start; }
        @media (max-width: 1024px) { .patient-grid { grid-template-columns: 1fr; } }

        .detail-group { margin-bottom: 15px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 10px; }
        .detail-group:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-label { display: block; color: #64748b; font-size: 0.8rem; font-weight: bold; text-transform: uppercase; margin-bottom: 3px; letter-spacing: 0.5px; }
        .detail-value { color: #0f172a; font-size: 1.05rem; font-weight: 500; }

        .timeline { position: relative; padding-left: 30px; margin-top: 15px; }
        .timeline::before { content: ''; position: absolute; left: 7px; top: 0; bottom: 0; width: 2px; background: #e2e8f0; }
        
        .timeline-item { position: relative; margin-bottom: 25px; }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-dot { position: absolute; left: -30px; top: 5px; width: 16px; height: 16px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 0 2px #e2e8f0; }
        
        .timeline-card { background: white; border: 1px solid #cbd5e1; border-radius: 10px; padding: 15px 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .timeline-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; }
        .timeline-type { font-weight: bold; font-size: 0.85rem; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;}
        .timeline-date { color: #64748b; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .timeline-body { font-size: 0.95rem; color: #334155; line-height: 1.5; }
        .timeline-attending { margin-top: 10px; font-size: 0.85rem; color: #64748b; font-style: italic; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <a href="patient_masterlist.php" class="btn-back" style="display: inline-block; margin-bottom: 15px; color: #4ca1af; text-decoration: none; font-weight: bold;">&larr; Back to Masterlist</a>

            <div class="profile-header" style="display:flex; align-items:center; gap:18px;">
              <?php echo mh_avatar_html(mh_initials($patient['first_name'], $patient['last_name']), 72, $patient['first_name'] . ' ' . $patient['last_name']); ?>
              <div>
                <h1><?php echo htmlspecialchars($patient['last_name'] . ', ' . $patient['first_name'] . ' ' . ($patient['middle_name'] ?? '')); ?></h1>
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
                
                <div class="unified-panel">
                    <div class="panel-controls">
                        <h3 style="margin:0; font-size: 1.1rem; color: #1e293b;"><?php echo mh_icon('user'); ?> Personal Information</h3>
                        <a href="edit_patient.php?id=<?php echo $patient_id; ?>" class="btn-view-record" style="text-decoration: none; display: inline-block; text-align: center;">Edit</a>
                    </div>
                    <div style="padding: 20px;">
                        
                        <div class="detail-group">
                            <span class="detail-label">Date of Birth / Age</span>
                            <span class="detail-value"><?php echo date("F d, Y", strtotime($patient['birthdate'])); ?> <span style="color: #64748b; font-weight: normal;">(<?php echo $age; ?> Years Old)</span></span>
                        </div>
                        
                        <div class="detail-group">
                            <span class="detail-label">Contact Number</span>
                            <span class="detail-value"><?php echo mh_icon('phone'); ?> <?php echo htmlspecialchars($patient['contact_number']); ?></span>
                        </div>

                        <?php if(!empty($patient['email'])): ?>
                        <div class="detail-group">
                            <span class="detail-label">Email Address</span>
                            <span class="detail-value"><?php echo mh_icon('mail'); ?> <?php echo htmlspecialchars($patient['email']); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="detail-group">
                            <span class="detail-label">Location Details</span>
                            <span class="detail-value"><?php echo mh_icon('map-pin'); ?> <?php echo htmlspecialchars($patient['address'] ?? 'No address recorded'); ?></span>
                        </div>

                    </div>
                </div>

                <div class="unified-panel">
                    <div class="panel-controls" style="background: #f8fafc; border-bottom: 1px solid #cbd5e1;">
                        <h3 style="margin:0; font-size: 1.1rem; color: #1e293b;"><?php echo mh_icon('folder'); ?> Complete Medical History</h3>
                    </div>
                    
                    <div style="padding: 20px;">
                        <?php if(count($history) > 0): ?>
                            <div class="timeline">
                                <?php foreach($history as $record): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot" style="background-color: <?php echo $record['color']; ?>;"></div>
                                        
                                        <div class="timeline-card">
                                            <div class="timeline-header">
                                                <span class="timeline-type" style="background-color: <?php echo $record['bg']; ?>; color: <?php echo $record['color']; ?>; border: 1px solid <?php echo $record['color']; ?>;">
                                                    <?php echo $record['type']; ?>
                                                </span>
                                                <span class="timeline-date"><?php echo mh_icon('calendar'); ?> <?php echo date("M d, Y", strtotime($record['date'])); ?></span>
                                            </div>
                                            <div class="timeline-body">
                                                <?php echo $record['details']; ?>
                                            </div>
                                            <div class="timeline-attending">
                                                Attending: <strong><?php echo htmlspecialchars($record['attending']); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                                <div style="display:flex; justify-content:center; margin-bottom: 10px; color:#94a3b8;"><?php echo mh_icon('file-text', 'mh-icon-xl'); ?></div>
                                <h3 style="margin: 0 0 5px 0; color: #334155;">No Medical History</h3>
                                <p style="margin: 0;">This patient has not had any consultations or procedures logged yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

</body>
</html>