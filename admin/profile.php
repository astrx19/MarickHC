<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../config/database.php';

// Any signed-in staff account (built-in or custom role) may open this page; admin_auth.php already
// verified the login and re-synced the role. What each role SEES is controlled by Module Access.

$system_name = $system_name ?? mh_setting('system_name');

$user_id = (int) $_SESSION['user_id'];
$success_msg = ""; 
$error_msg = "";

$stmt = $conn->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$staff_data = $stmt->get_result()->fetch_assoc();
$stmt->close();
if($staff_data) {
    $staff_name = $staff_data['first_name'] . ' ' . $staff_data['last_name'];
} else {
    $staff_data = ['id' => $user_id, 'first_name' => 'Staff', 'last_name' => 'Member', 'role' => $_SESSION['role'], 'email' => 'N/A'];
    $staff_name = "Staff Member";
}

if(isset($_POST['upload_photo'])){
    if(isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $target_dir = "../assets/images/profile_pics/";
        if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
        
        $target_file = $target_dir . $user_id . ".jpg";
        $imageFileType = strtolower(pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION));

        $check = getimagesize($_FILES["profile_pic"]["tmp_name"]);
        if($check !== false) {
            if(in_array($imageFileType, ["jpg", "jpeg", "png"])) {
                if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
                    $success_msg = "Profile picture updated successfully!";
                    logActivity($conn, $user_id, $_SESSION['role'], "Profile Update", "Uploaded a new profile picture.");
                } else {
                    $error_msg = "Sorry, there was an error saving your file.";
                }
            } else { $error_msg = "Sorry, only JPG, JPEG, & PNG files are allowed."; }
        } else { $error_msg = "File is not a valid image."; }
    }
}

if(isset($_POST['update_profile']) && mh_is_admin_tier()){
    $fname   = trim((string) ($_POST['first_name'] ?? ''));
    $lname   = trim((string) ($_POST['last_name'] ?? ''));
    $mname   = trim((string) ($_POST['middle_name'] ?? ''));
    $suffix  = trim((string) ($_POST['suffix'] ?? ''));
    $bdate   = (string) ($_POST['birthdate'] ?? '');
    $contact = trim((string) ($_POST['contact_number'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ''));
    
    $st = $conn->prepare("SELECT id FROM staff WHERE email = ? AND id <> ?");
    $st->bind_param('si', $email, $user_id);
    $st->execute();
    $email_taken = $st->get_result()->num_rows > 0;
    $st->close();
    if($email_taken){
        $error_msg = "That email address is already in use by another account.";
    } else {
        $st = $conn->prepare("UPDATE staff SET first_name = ?, last_name = ?, middle_name = ?, suffix = ?,
                              birthdate = ?, contact_number = ?, email = ? WHERE id = ?");
        $st->bind_param('sssssssi', $fname, $lname, $mname, $suffix, $bdate, $contact, $email, $user_id);
        $ok = $st->execute();
        $st->close();
        
        if($ok){
            $success_msg = "Personal details updated successfully!";
            logActivity($conn, $user_id, $_SESSION['role'], "Profile Update", "Updated personal account details.");
            
            $st = $conn->prepare("SELECT * FROM staff WHERE id = ?");
            $st->bind_param('i', $user_id);
            $st->execute();
            $staff_data = $st->get_result()->fetch_assoc();
            $st->close();
            $staff_name = $staff_data['first_name'] . ' ' . $staff_data['last_name'];
        } else {
            $error_msg = "Error updating profile. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .profile-grid { display: grid; grid-template-columns: 350px 1fr; gap: 25px; align-items: start; }
        @media (max-width: 768px) { .profile-grid { grid-template-columns: 1fr; } }
        
        .profile-avatar-large { width: 180px; height: 180px; border-radius: 50%; object-fit: cover; border: 4px solid var(--border-color, #cbd5e1); margin: 0 auto 20px auto; display: block; box-shadow: 0 4px 10px rgba(0,0,0,0.05);}
        
        .detail-group { margin-bottom: 20px; border-bottom: 1px dashed var(--border-color, #cbd5e1); padding-bottom: 15px; }
        .detail-group:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-label { display: block; color: var(--text-muted, #64748b); font-size: 0.85rem; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; letter-spacing: 0.5px; }
        .detail-value { color: var(--heading-color, #1e293b); font-size: 1.1rem; font-weight: 600; }
        
        .upload-btn-wrapper { position: relative; overflow: hidden; display: inline-block; width: 100%; text-align: center; }
        .upload-btn-wrapper input[type=file] { font-size: 100px; position: absolute; left: 0; top: 0; opacity: 0; cursor: pointer; height: 100%; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <?php 
            if($success_msg) echo "<div style='background:#d1fae5; color:#047857; padding:15px; border-radius:8px; border:1px solid #a7f3d0; margin-bottom:20px;'>" . mh_icon('check-circle') . " $success_msg</div>";
            if($error_msg) echo "<div style='background:#fee2e2; color:#b91c1c; padding:15px; border-radius:8px; border:1px solid #fecaca; margin-bottom:20px;'>" . mh_icon('x-circle') . " $error_msg</div>";
            ?>

            <div class="profile-grid">
                
                <div class="unified-panel" style="margin-bottom:0; text-align:center;">
                    <div class="panel-controls" style="justify-content:center; background: var(--bg-hover, #f8fafc);">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);">Profile Picture</h3>
                    </div>
                    <div style="padding: 30px 20px;">
                        
                        <?php $mh_pp_file = __DIR__ . '/../assets/images/profile_pics/' . (int) $user_id . '.jpg'; ?>
                        <?php if (is_file($mh_pp_file)): ?>
                            <img src="../assets/images/profile_pics/<?php echo (int) $user_id; ?>.jpg?t=<?php echo (int) @filemtime($mh_pp_file); ?>"
                                 alt="Profile" class="profile-avatar-large">
                        <?php else: ?>
                            <div style="display:flex; justify-content:center;"><?php echo mh_avatar_html(mh_initials_from_name($staff_name), 180, $staff_name); ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data" style="margin-top: 20px;">
                            <div class="upload-btn-wrapper">
                                <button type="button" class="btn-secondary" style="width: 100%; box-sizing: border-box;"><?php echo mh_icon('image'); ?> Upload Photo</button>
                                <input type="file" name="profile_pic" accept="image/png, image/jpeg, image/jpg" onchange="this.form.submit()">
                            </div>
                            <input type="hidden" name="upload_photo" value="1">
                        </form>
                        
                        <p style="font-size: 0.8rem; color: var(--text-muted, #64748b); margin-top: 10px;">Supported formats: JPG, PNG</p>
                    </div>
                </div>

                <div class="unified-panel" style="margin-bottom:0;">
                    <div class="panel-controls">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);">Personal Details</h3>
                    </div>
                    <div style="padding: 30px;">
                        
                        <div class="detail-group">
                            <div class="detail-label">Full Name</div>
                            <div class="detail-value" style="font-size: 1.4rem;">
                                <?php echo htmlspecialchars($staff_data['first_name'] . ' ' . ($staff_data['middle_name'] ?? '') . ' ' . $staff_data['last_name'] . ' ' . ($staff_data['suffix'] ?? '')); ?>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-muted, #64748b); margin-top: 5px;">
                                Registered Account | ID: #<?php echo str_pad($staff_data['id'], 5, '0', STR_PAD_LEFT); ?>
                            </div>
                        </div>
                        
                        <div class="detail-group">
                            <div class="detail-label">Current Role</div>
                            <div class="detail-value" style="color: #4ca1af; text-transform: uppercase;">
                                <?php echo htmlspecialchars($staff_data['role']); ?>
                            </div>
                        </div>

                        <div class="detail-group">
                            <div class="detail-label">Email Address (Login ID)</div>
                            <div class="detail-value"><?php echo htmlspecialchars($staff_data['email']); ?></div>
                        </div>

                        <div class="detail-group">
                            <div class="detail-label">Contact Number</div>
                            <div class="detail-value"><?php echo htmlspecialchars($staff_data['contact_number'] ?? 'N/A'); ?></div>
                        </div>

                        <div class="detail-group" style="margin-bottom: 25px;">
                            <div class="detail-label">Birthdate / Age</div>
                            <div class="detail-value">
                                <?php 
                                if(!empty($staff_data['birthdate']) && $staff_data['birthdate'] != '0000-00-00') {
                                    $bdate = new DateTime($staff_data['birthdate']); 
                                    $age = $bdate->diff(new DateTime('today'))->y;
                                    echo date("F d, Y", strtotime($staff_data['birthdate'])) . " ($age Years Old)";
                                } else {
                                    echo "N/A";
                                }
                                ?>
                            </div>
                        </div>

                        <?php if(mh_is_admin_tier()): ?>
                            <button class="btn-secondary" style="width: 100%; box-sizing: border-box;" onclick="openEditProfileModal()"><?php echo mh_icon('edit'); ?> Edit Personal Details</button>
                        <?php endif; ?>
                        
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php if(mh_is_admin_tier()): ?>
    <div class="modal-overlay" id="editProfileModal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close-btn" onclick="closeModal('editProfileModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:var(--heading-color, #1e293b); font-family: Georgia, serif;">Edit Personal Details</h2>
            </div>
            
            <form method="POST">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($staff_data['last_name']); ?>" required pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($staff_data['first_name']); ?>" required pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" value="<?php echo htmlspecialchars($staff_data['middle_name'] ?? ''); ?>" pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    <div class="form-group">
                        <label>Suffix</label>
                        <input type="text" name="suffix" value="<?php echo htmlspecialchars($staff_data['suffix'] ?? ''); ?>" maxlength="5" oninput="this.value = this.value.replace(/[^a-zA-Z.]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>

                    <div class="form-group">
                        <label>Birthdate *</label>
                        <input type="date" name="birthdate" value="<?php echo htmlspecialchars($staff_data['birthdate'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    
                    <div class="form-group">
                        <label>Contact No. *</label>
                        <input type="tel" name="contact_number" value="<?php echo htmlspecialchars($staff_data['contact_number'] ?? '+63'); ?>" required maxlength="13" pattern="^\+63[0-9]{10}$" oninput="this.value = this.value.replace(/[^0-9+]/g, ''); if(this.value.length < 3) this.value = '+63';" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1; margin-bottom: 20px;">
                        <label>Email Address *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($staff_data['email']); ?>" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                </div>
                
                <button type="submit" name="update_profile" class="btn-submit" style="width: 100%; padding: 15px; background: #3b82f6; color: white; border-radius: 6px; border: none; font-weight: bold; cursor: pointer;">Save Changes</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openEditProfileModal() {
            document.getElementById('editProfileModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
    </script>
</body>
</html>