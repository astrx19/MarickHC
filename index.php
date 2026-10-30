<?php
session_start();
require_once __DIR__ . '/includes/icons.php';

// Branding ($system_name, $hero_title, ...) and dropdowns come live from System Settings > Dropdown Masterlists.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings_helper.php';
$marick_streets = mh_streets($conn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - <?php echo htmlspecialchars($system_name); ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css'); ?>"> 
    <style>
        .custom-alert-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6); z-index: 99999; align-items: center;
            justify-content: center; backdrop-filter: blur(3px);
        }
        .custom-alert-overlay.active { display: flex; }
        .custom-alert-box {
            background: white; width: 90%; max-width: 450px; border-radius: 12px;
            padding: 30px 25px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }
        .custom-alert-box .alert-icon { display: flex; justify-content: center; margin-bottom: 15px; color: #ef4444; }
        .custom-alert-box .alert-icon .mh-icon { width: 56px; height: 56px; }
        .custom-alert-box h3 { margin: 0 0 10px 0; color: #1e293b; font-size: 1.4rem; font-family: Georgia, serif; }
        .custom-alert-box p { color: #64748b; font-size: 0.95rem; margin: 0 0 25px 0; line-height: 1.5; }
        
        .btn-alert-ok, .btn-alert-cancel, .btn-alert-confirm {
            border: none; padding: 12px 20px; border-radius: 6px; font-weight: bold;
            font-size: 1rem; cursor: pointer; transition: 0.2s; width: 100%;
        }
        .btn-alert-ok { background: #4ca1af; color: white; }
        .btn-alert-ok:hover { background: #3b828e; }
        
        .btn-alert-cancel { background: #f1f5f9; color: #475569; }
        .btn-alert-cancel:hover { background: #e2e8f0; }
        
        .btn-alert-confirm { background: #10b981; color: white; }
        .btn-alert-confirm:hover { background: #059669; }
    </style>
    <?php define('MH_ASSET_PATH', 'assets/'); include __DIR__ . '/includes/modal_assets.php'; ?>
</head>
<body class="bg-landing">

    <nav class="navbar">
        <a href="index.php" class="nav-brand">
            <img src="assets/images/logo.png?t=<?php echo time(); ?>" alt="System Logo">
            <h1><?php echo htmlspecialchars($system_name); ?></h1>
        </a>
        
        <div style="display: flex; gap: 15px; align-items: center;">
            <button onclick="openModal('faqModal')" style="background: transparent; color: #4ca1af; border: 2px solid #4ca1af; padding: 6px 18px; border-radius: 20px; font-weight: bold; cursor: pointer; transition: 0.3s; font-size: 0.95rem;">FAQ</button>
            <button onclick="openModal('registerModal')" style="background: transparent; color: #4ca1af; border: 2px solid #4ca1af; padding: 6px 18px; border-radius: 20px; font-weight: bold; cursor: pointer; transition: 0.3s; font-size: 0.95rem;">Register</button>
            <a href="patient_login.php" style="background: #4ca1af; color: white; border: 2px solid #4ca1af; padding: 6px 18px; border-radius: 20px; font-weight: bold; text-decoration: none; transition: 0.3s; font-size: 0.95rem;">Patient Login</a>
        </div>
    </nav>

    <?php if(!empty($global_announcement)): ?>
    <div style="background: #fee2e2; border: 1px solid #fca5a5; border-left: 5px solid #ef4444; padding: 15px 20px; border-radius: 8px; margin: 20px auto; max-width: 1100px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.1);">
        <span style="color:#ef4444; display:inline-flex;"><?php echo mh_icon('megaphone', 'mh-icon-xl'); ?></span>
        <div>
            <h4 style="margin: 0 0 3px 0; color: #991b1b; font-size: 1.1rem;">Clinic Announcement</h4>
            <p style="margin: 0; color: #b91c1c; font-size: 1rem; font-weight: 500;"><?php echo nl2br(htmlspecialchars($global_announcement)); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <header class="hero">
        <h2><?php echo htmlspecialchars($hero_title); ?></h2>
        <p><?php echo htmlspecialchars($hero_subtitle); ?></p>
    </header>

    <section class="info-section">
        <div class="info-card"><span class="info-icon"><?php echo mh_icon('map-pin'); ?></span><h3>Our Location</h3><p>Barangay Sto. Domingo,<br>Marick Subdivision,<br>Cainta, Rizal</p></div>
        <div class="info-card"><span class="info-icon"><?php echo mh_icon('clock'); ?></span><h3>Clinic Hours</h3><p><strong>Monday - Friday:</strong><br>8:00 AM - 5:00 PM<br><br><strong>Weekends & Holidays:</strong><br>Closed</p></div>
        <div class="info-card"><span class="info-icon"><?php echo mh_icon('activity'); ?></span><h3>Our Services</h3><p>• General Consultations<br>• Maternal & Prenatal Care<br>• Child Immunization<br>• Dental Services</p></div>
    </section>

    <div style="max-width: 1200px; margin: 40px auto 60px auto; padding: 0 20px;">
        
        <div style="text-align: center; margin-bottom: 25px;">
            <h2 style="color: #1e293b; font-family: Georgia, serif; font-size: 2rem; margin: 0 0 10px 0;">Find Us Here</h2>
            <p style="color: #64748b; font-size: 1.1rem; margin: 0;">Barangay Sto. Domingo, Marick Subdivision, Cainta, Rizal</p>
        </div>

        <div style="border-radius: 12px; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; background: white; line-height: 0;">
            <iframe 
                width="100%" 
                height="450" 
                frameborder="0" 
                scrolling="no" 
                marginheight="0" 
                marginwidth="0" 
                src="https://maps.google.com/maps?q=Sto.+Domingo+Barangay+Hall,+Marick+Subdivision,+Cainta,+Rizal&t=&z=17&ie=UTF8&iwloc=&output=embed" 
                style="border: none;">
            </iframe>
            
            <div style="background: #f8fafc; padding: 15px 20px; text-align: center; border-top: 1px solid #e2e8f0;">
                <a href="https://www.google.com/maps/search/?api=1&query=Sto.+Domingo+Barangay+Hall,+Marick+Subdivision,+Cainta,+Rizal" target="_blank" style="color: #4ca1af; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 1.05rem;">
                    <?php echo mh_icon('map-pin'); ?> Open in Google Maps App <?php echo mh_icon('arrow-right'); ?>
                </a>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p style="margin: 0 0 8px 0;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($system_name); ?>. All rights reserved.</p>
        <a href="admin/login.php" style="color: #64748b; text-decoration: none; font-size: 0.85rem;">Staff / Health Center Portal &rarr;</a>
    </footer>

    <div class="modal-overlay" id="faqModal">
        <div class="modal-content" style="max-width: 600px; text-align: left;">
            <button class="close-modal" onclick="closeModal('faqModal')">×</button>
            <div class="modal-header" style="text-align: center;">
                <img src="assets/images/logo.png" alt="Marick Logo">
                <h2>Frequently Asked Questions</h2>
                <p>Everything you need to know about our health center.</p>
            </div>
            <div style="margin-top: 20px;">
                <div style="margin-bottom: 20px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 15px;">
                    <h4 style="color: #0369a1; margin: 0 0 5px 0; font-size: 1.05rem; display:flex; align-items:center; gap:6px;"><?php echo mh_icon('clock'); ?> What are your operating hours?</h4>
                    <p style="color: #475569; margin: 0; font-size: 0.95rem; line-height: 1.5;">We are open Monday to Friday, from 8:00 AM to 5:00 PM. We are closed on weekends and regular holidays.</p>
                </div>
                <div style="margin-bottom: 20px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 15px;">
                    <h4 style="color: #0369a1; margin: 0 0 5px 0; font-size: 1.05rem; display:flex; align-items:center; gap:6px;"><?php echo mh_icon('users'); ?> Do you accept walk-in patients?</h4>
                    <p style="color: #475569; margin: 0; font-size: 0.95rem; line-height: 1.5;">Yes! Walk-in patients are welcome during clinic hours, and we always accommodate emergency checkups.</p>
                </div>
                <div style="margin-bottom: 10px;">
                    <h4 style="color: #0369a1; margin: 0 0 5px 0; font-size: 1.05rem; display:flex; align-items:center; gap:6px;"><?php echo mh_icon('droplet'); ?> Are vaccines free for infants?</h4>
                    <p style="color: #475569; margin: 0; font-size: 0.95rem; line-height: 1.5;">Yes, all mandatory routine immunizations (BCG, Hepatitis B, Pentavalent, OPV, etc.) are provided for free as part of the center's health program.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="registerModal">
        <div class="modal-content" style="max-width: 600px;">
            <button class="close-modal" onclick="closeModal('registerModal')">×</button>
            <div class="modal-header">
                <img src="assets/images/logo.png" alt="Marick Logo">
                <h2>Patient Registration</h2>
                <p id="step-indicator" style="color: #4ca1af; font-weight: bold;">Step 1 of 2: Personal Information</p>
            </div>
            <form action="register_process.php" method="POST" id="regForm">
                <?php echo mh_csrf_field(); ?>
                <div id="step1">
                    <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group"><label>First Name <span style="color:red;">*</span></label><input type="text" id="fname" name="first_name" required></div>
                        <div class="form-group"><label>Last Name <span style="color:red;">*</span></label><input type="text" id="lname" name="last_name" required></div>
                        <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name"></div>
                        <div class="form-group"><label>Suffix</label><input type="text" name="suffix" placeholder="e.g., Jr." maxlength="5" oninput="this.value = this.value.replace(/[^a-zA-Z.]/g, '')"></div>
                        
                        <div class="form-group"><label>Birthdate <span style="color:red;">*</span></label><input type="date" id="bdate" name="birthdate" required></div>
                        
                        <div class="form-group"><label>Biological Sex <span style="color:red;">*</span></label>
                            <div style="display: flex; gap: 15px; margin-top: 10px;">
                                <label style="cursor: pointer;"><input type="radio" name="sex" value="Male" required style="accent-color: #4ca1af;"> Male</label>
                                <label style="cursor: pointer;"><input type="radio" name="sex" value="Female" required style="accent-color: #4ca1af;"> Female</label>
                            </div>
                        </div>

                        <input type="tel" id="mobile" name="contact_number" required value="+63" maxlength="13" oninput="this.value = this.value.replace(/[^0-9+]/g, '')" style="display:none;"> 
                        <div class="form-group"><label>Contact Number <span style="color:red;">*</span></label><input type="tel" name="contact_number" required value="+63" maxlength="13" oninput="this.value = this.value.replace(/[^0-9+]/g, '')"></div>

                        <div class="form-group"><label>PhilHealth No. (Optional)</label><input type="text" name="philhealth_no" placeholder="00-000000000-0"></div>
                        <div class="form-group" style="grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 10px;"><label>Family No. (Optional)</label><input type="text" name="family_no" placeholder="If known"></div>
                    </div>
                    <button type="button" class="btn-modal-login" style="margin-top: 20px;" onclick="nextStep()">Next: Account Details <?php echo mh_icon('arrow-right'); ?></button>
                </div>

                <div id="step2" style="display: none;">
                    <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Email Address <span style="color:red;">*</span></label><input type="email" name="email" required placeholder="For verification & password recovery"></div>
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Username <span style="color:red;">*</span></label><input type="text" name="username" id="regUsername" required minlength="4" maxlength="50" pattern="[A-Za-z0-9._-]{4,50}" title="4-50 characters: letters, numbers, dots, underscores or hyphens" placeholder="Used to log in - no spaces" autocomplete="username"></div>
                        <div class="form-group"><label>Create Password <span style="color:red;">*</span></label><input type="password" id="regPwd" name="password" required placeholder="Min 8 characters"></div>
                        <div class="form-group"><label>Confirm Password <span style="color:red;">*</span></label><input type="password" id="regConfirmPwd" name="confirm_password" required placeholder="Type again"></div>
                        
                        <div class="form-group" style="grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 15px;">
                            <label>Location Type <span style="color:red;">*</span></label>
                            <select name="address_type" id="regAddressType" <?php echo mh_home_type_attr(); ?> onchange="toggleRegAddress()" required>
                                <option value="" disabled selected>Select location type...</option>
                                <?php echo mh_masterlist_options($conn, 'address_types', '', '', [], mh_address_type_labels()); ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="marickHouseGroup" style="display: none;"><label>House/Blk No. <span style="color:red;">*</span></label><input type="text" name="house_number" id="houseNumber" placeholder="e.g., Blk 1 Lot 2"></div>
                        <div class="form-group" id="marickStreetGroup" style="display: none;">
                            <label>Street Name <span style="color:red;">*</span></label>
                            <select name="marick_street" id="marickStreet">
                                <option value="" disabled selected>Select street...</option>
                                <?php echo mh_masterlist_options($conn, 'streets'); ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1; display: none;" id="outsiderAddressGroup"><label>Full Address <span style="color:red;">*</span></label><input type="text" name="outsider_address" id="outsiderAddress" placeholder="House No., Street, Barangay, City"></div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn-modal-login" style="background: #e2e8f0; color: #334155; width: 30%; margin-bottom: 0;" onclick="prevStep()"><?php echo mh_icon('arrow-left'); ?> Back</button>
                        <button type="submit" name="register_patient" class="btn-modal-login" style="width: 70%; margin-bottom: 0;">Submit Registration</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="custom-alert-overlay" id="customActionModal">
        <div class="custom-alert-box">
            <div class="alert-icon" id="customIcon"><?php echo mh_icon('alert-triangle'); ?></div>
            <h3 id="customTitle">Notice</h3>
            <p id="customMessage">Message goes here.</p>
            <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;" id="customButtons">
                </div>
        </div>
    </div>

    <script src="assets/js/script.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/script.js'); ?>"></script>

    <script>
        const MH_ICON_CHECK_CIRCLE = <?php echo json_encode(mh_icon('check-circle')); ?>;
        const MH_ICON_WARN = <?php echo json_encode(mh_icon('alert-triangle')); ?>;
        const MH_ICON_INFO = <?php echo json_encode(mh_icon('info')); ?>;
        const MH_ICON_RECORD = <?php echo json_encode(mh_icon('file-text')); ?>;

        function triggerCustomModal(title, message, icon, type) {
            document.getElementById('customTitle').innerText = title;
            document.getElementById('customMessage').innerHTML = message;
            document.getElementById('customIcon').innerHTML = icon;
            
            let btnContainer = document.getElementById('customButtons');
            
            btnContainer.innerHTML = `
                    <button type="button" class="btn-alert-ok" onclick="closeCustomModal()">OK</button>
                `;
            
            document.getElementById('customActionModal').classList.add('active');
        }

        function closeCustomModal() {
            document.getElementById('customActionModal').classList.remove('active');
            window.history.replaceState(null, null, window.location.pathname);
        }


        <?php if(isset($_GET['reg_status'])): ?>
            setTimeout(function() {
                <?php if($_GET['reg_status'] == 'success'): ?>
                    triggerCustomModal('Registration Successful!', 'You can now log in to your patient portal.', MH_ICON_CHECK_CIRCLE, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_email'): ?>
                    triggerCustomModal('Registration Failed', 'An account with this email or name already exists.<br>Please log in instead.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_username'): ?>
                    triggerCustomModal('Username Taken', 'That username is already in use. Please go back and choose a different one.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'cancelled'): ?>
                    triggerCustomModal('Registration Cancelled', 'Your registration process was cancelled.', MH_ICON_INFO, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_claimed'): ?>
                    triggerCustomModal('Record Already Claimed', 'This record has already been claimed. Please log in or contact administration.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'claim_no_phone'): ?>
                    triggerCustomModal('Visit the Health Center', 'We found your clinic record but it has no valid mobile number on file, so we cannot verify you online. Please visit the health center so staff can update it.', MH_ICON_INFO, 'alert');
                <?php elseif($_GET['reg_status'] == 'claim_sms_failed'): ?>
                    triggerCustomModal('Could Not Send Code', 'We could not send the verification code right now. Please try again later or contact the health center.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'claim_throttled'): ?>
                    triggerCustomModal('Too Many Attempts', 'Too many verification codes were requested. Please wait an hour or contact the health center.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'claim_expired'): ?>
                    triggerCustomModal('Verification Expired', 'Your verification session expired. Please start the registration again.', MH_ICON_INFO, 'alert');
                <?php elseif($_GET['reg_status'] == 'claim_locked'): ?>
                    triggerCustomModal('Verification Locked', 'Too many incorrect codes. Please start again or contact the health center.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_password'): ?>
                    triggerCustomModal('Check Your Password', 'Use at least 8 characters, and make sure both password fields match.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_input'): ?>
                    triggerCustomModal('Check Your Details', 'Please enter your name and a valid birthdate.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_csrf'): ?>
                    triggerCustomModal('Session Expired', 'Your form expired. Please reload the page and try again.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_address'): ?>
                    triggerCustomModal('Check Your Address', 'Please complete your address. Residents need a house number and a street from the list; everyone else can type their full address.', MH_ICON_WARN, 'alert');
                <?php elseif($_GET['reg_status'] == 'error_db'): ?>
                    triggerCustomModal('Registration Failed', 'Something went wrong. Please try again later.', MH_ICON_WARN, 'alert');
                <?php endif; ?>
            }, 300); 
        <?php endif; ?>
    </script>
</body>
</html>