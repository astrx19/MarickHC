<?php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/icons.php';
// Load current module settings (enabled + roles) so the nav below can
// hide anything an admin has turned off or restricted. Harmless if this
// is the first call this request (mh_all_settings queries once and
// caches); harmless if $conn isn't set yet either (falls back to
// factory defaults, i.e. every module shown, same as before this
// feature existed).
if (isset($conn) && $conn instanceof mysqli) {
    mh_all_settings($conn);
}

$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? '';

// Visibility is driven by the feature toggles saved for the signed-in user's role
// (System Settings -> Staff Roles & Permissions -> table role_permissions), plus any
// per-account delegation flag - both resolved by mh_has_permission() (includes/roles.php).
// A module link also needs the module's master switch ON (mh_module_visible()).
// Each link depends on EXACTLY its own sub-permission (Delegated Functions in Staff Management,
// on top of the role's toggles). A per-account untick is an explicit deny, so e.g. an Inventory
// Staff with only "Medicine Dispensing" ticked sees no Supply Masterlist link.
$nav_can_supply   = mh_has_capability('can_manage_supply_masterlist');     // Supply / Stock Masterlist
$nav_can_dispense = mh_has_capability('can_manage_dispensing');            // Medicine Dispensing
$nav_can_disp_log = mh_has_capability('can_view_dispensing_history');      // Dispensing Log History
$nav_show_inventory = ($nav_can_supply || $nav_can_dispense || $nav_can_disp_log) && mh_module_visible('inventory', $user_role);
$nav_show_checkups  = mh_has_any_capability(['can_log_consultations', 'can_view_consultation_history'])     && mh_module_visible('checkups', $user_role);
$nav_show_maternal  = mh_has_any_capability(['can_log_maternal', 'can_view_maternal_history'])              && mh_module_visible('maternal_care', $user_role);
$nav_show_immun     = mh_has_any_capability(['can_log_immunization', 'can_view_immunization_records'])     && mh_module_visible('immunization', $user_role);
$nav_show_dental    = mh_has_any_capability(['can_log_dental', 'can_view_dental_history'])                  && mh_module_visible('dental', $user_role);
$nav_show_queue     = mh_has_any_capability(['can_view_queue', 'can_manage_queue']);
$nav_show_area      = mh_has_capability('can_view_area_masterlist');
$nav_show_patients  = mh_has_permission('view_patient_masterlist') && mh_module_visible('patient_masterlist', $user_role);
$nav_show_staff     = mh_has_permission('manage_staff');
$nav_show_stats     = mh_has_permission('view_disease_statistics');
$nav_show_logs      = mh_has_permission('view_system_logs');
$nav_show_archives  = mh_has_permission('view_system_archives');
?>

<style>
    /* Submenu: a softly inset pill list nested under its parent pill,
       rather than a hard-edged dark block bleeding to the sidebar edge. */
    .sidebar-submenu {
        list-style: none;
        padding: 4px 0 4px 14px;
        margin: 2px 0 2px 0;
        display: none;
    }

    .sidebar-submenu li a {
        display: flex; align-items: center;
        padding: 8px 16px 8px 34px !important;
        font-size: 0.82rem !important;
        color: var(--sidebar-text, #94a3b8) !important;
        font-weight: 600 !important;
        border-radius: var(--radius-pill);
        position: relative;
    }
    .sidebar-submenu li a::before {
        content: "";
        position: absolute; left: 16px; top: 50%; width: 5px; height: 5px;
        border-radius: 50%; background: currentColor; opacity: 0.6; transform: translateY(-50%);
    }

    .sidebar-submenu li a:hover, .sidebar-submenu li a.active {
        color: #fff !important;
        background: rgba(255, 255, 255, 0.07) !important;
    }
    .sidebar-submenu li a.active { background: var(--nav-pill-bg) !important; }

    .nav-caret {
        margin-left: auto;
        transition: transform 0.2s ease;
        display: inline-flex;
    }
    .nav-caret .mh-icon { width: 15px; height: 15px; }

    .has-submenu.open .nav-caret {
        transform: rotate(180deg);
    }
    
    .sidebar.collapsed .nav-caret, 
    .sidebar.collapsed .nav-text {
        display: none; 
    }
    .sidebar.collapsed .sidebar-submenu { display: none !important; }

    /* Menu longer than the window? Keep it scrollable with a visible thin
       scrollbar (the default one vanishes against the dark sidebar). */
    .sidebar-nav { min-height: 0; scrollbar-width: thin; scrollbar-color: #475569 transparent; }
    .sidebar-nav::-webkit-scrollbar { width: 6px; }
    .sidebar-nav::-webkit-scrollbar-thumb { background: #475569; border-radius: 6px; }

    /* Pinned footer links (System Settings + Logout) */
    .sidebar-footer { display: flex; flex-direction: column; gap: 2px; }
    .sidebar-footer a.footer-link { color: var(--sidebar-text, #cbd5e1) !important; }
    .sidebar-footer a.footer-link:hover { background: rgba(255,255,255,0.06); color: #f1f5f9 !important; }
    .sidebar-footer a.footer-link.active { background: var(--nav-pill-bg); color: #fff !important; box-shadow: inset 0 0 0 1px rgba(var(--primary-rgb, 76, 161, 175), 0.35); }
</style>

<div class="sidebar" id="appSidebar">
<div class="sidebar-header">
    <span class="brand-mark"><?php echo mh_icon('activity'); ?></span>
    <div class="brand-text nav-text">
        <h2>Marick Health</h2>
        <p class="brand-subtitle">Staff Portal</p>
    </div>
</div>
    <div class="sidebar-nav">
        <ul>
            <li><a href="dashboard.php" title="Dashboard" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <span class="nav-icon"><?php echo mh_icon('grid'); ?></span>
                <span class="nav-text"><?php echo htmlspecialchars(mh_nav_label('dashboard', 'Dashboard')); ?></span>
            </a></li>
            
            <?php if($nav_show_patients): ?>
            <li><a href="patient_masterlist.php" title="<?php echo htmlspecialchars(mh_module_label('patient_masterlist')); ?>" class="<?php echo ($current_page == 'patient_masterlist.php' || $current_page == 'view_patient.php' || $current_page == 'edit_patient.php') ? 'active' : ''; ?>">
                <span class="nav-icon"><?php echo mh_icon('users'); ?></span>
                <span class="nav-text"><?php echo htmlspecialchars(mh_module_label('patient_masterlist')); ?></span>
            </a></li>
            <?php endif; ?>

            <?php if($nav_show_inventory): 
                $inv_active = in_array($current_page, ['inventory.php', 'dispense_medicine.php']);
            ?>
                <li class="has-submenu <?php echo $inv_active ? 'open' : ''; ?>">
                    <a href="#" onclick="toggleSubmenu(event, 'invSubmenu')" title="Medicine Inventory" class="<?php echo $inv_active ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo mh_icon('package'); ?></span>
                        <span class="nav-text" style="display: flex; width: 100%; align-items: center;">
                            <?php echo htmlspecialchars(mh_module_label('inventory')); ?> <span class="nav-caret"><?php echo mh_icon('chevron-down'); ?></span>
                        </span>
                    </a>
                    <ul id="invSubmenu" class="sidebar-submenu" style="display: <?php echo $inv_active ? 'block' : 'none'; ?>;">
                        <?php if($nav_can_supply): ?>
                        <li><a href="inventory.php" class="<?php echo ($current_page == 'inventory.php') ? 'active' : ''; ?>">Supply Masterlist</a></li>
                        <?php endif; ?>
                        <?php if($nav_can_dispense || $nav_can_disp_log): ?>
                        <li><a href="dispense_medicine.php" class="<?php echo ($current_page == 'dispense_medicine.php') ? 'active' : ''; ?>"><?php echo $nav_can_dispense ? 'Dispense Medicine' : 'Dispensing History'; ?></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if($nav_show_area): ?>
                <li><a href="area_masterlist.php" title="Area Masterlist" class="<?php echo ($current_page == 'area_masterlist.php') ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo mh_icon('map-pin'); ?></span>
                    <span class="nav-text"><?php echo htmlspecialchars(mh_nav_label('area_masterlist', 'Area Masterlist')); ?></span>
                </a></li>
            <?php endif; ?>

            <?php if($nav_show_queue): ?>
                <li><a href="appointments.php" title="Patient Queue" class="<?php echo ($current_page == 'appointments.php') ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo mh_icon('calendar'); ?></span>
                    <span class="nav-text"><?php echo htmlspecialchars(mh_nav_label('queue', 'Patient Queue')); ?></span>
                </a></li>
            <?php endif; ?>

            <?php
            // Departments come from System Settings -> Departments. Each active department gets its own
            // sidebar link: a department linked to a module opens that module's page, any other opens the
            // generic department page (queue + visit notes). Labels are the department names.
            $nav_depts = (isset($conn) && $conn instanceof mysqli) ? mh_options($conn, 'clinic_departments', true) : [];
            if (!$nav_depts) {   // safety net: database unreachable / list emptied -> the original four
                $nav_depts = [];
                foreach (['General Consultation', 'Dental Clinic', 'Maternal Care', 'Child Immunization'] as $fallback_name) {
                    $nav_depts[] = ['id' => 0, 'option_value' => $fallback_name];
                }
            }
            $nav_dept_modules = [   // module => [may this user see it?, icon]
                'checkups'      => [$nav_show_checkups, 'activity'],
                'maternal_care' => [$nav_show_maternal, 'heart'],
                'immunization'  => [$nav_show_immun,    'droplet'],
                'dental'        => [$nav_show_dental,   'smile'],
            ];
            foreach ($nav_depts as $nd):
                $nd_name   = (string) $nd['option_value'];
                $nd_module = mh_department_module($nd_name);
                if ($nd_module === 'inventory') { continue; }   // covered by the Medicine Inventory menu above
                if ($nd_module !== null && isset($nav_dept_modules[$nd_module])) {
                    $nd_show   = $nav_dept_modules[$nd_module][0];
                    $nd_icon   = $nav_dept_modules[$nd_module][1];
                    $nd_href   = mh_department_page($nd_name);
                    $nd_active = ($current_page === $nd_href) || ($nd_href === 'immunization.php' && $current_page === 'view_immunization.php');
                } elseif ($nd_module === null && (int) $nd['id'] > 0) {
                    $nd_show   = mh_dept_user_can($nd_name);
                    $nd_icon   = 'folder';
                    $nd_href   = 'department.php?id=' . (int) $nd['id'];
                    $nd_active = ($current_page === 'department.php' && (int) ($_GET['id'] ?? 0) === (int) $nd['id']);
                } else { continue; }
                if (!$nd_show || ($nd_module !== null && mh_dept_blocked($nd_name))) { continue; }
            ?>
                <li><a href="<?php echo htmlspecialchars($nd_href, ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($nd_name, ENT_QUOTES); ?>" class="<?php echo $nd_active ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo mh_icon($nd_icon); ?></span>
                    <span class="nav-text"><?php echo htmlspecialchars($nd_name); ?></span>
                </a></li>
            <?php endforeach; ?>

            <?php if($nav_show_stats): ?>
                <li><a href="disease_statistics.php" title="Disease Statistics" class="<?php echo ($current_page == 'disease_statistics.php') ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo mh_icon('shield'); ?></span>
                    <span class="nav-text">Disease Statistics</span>
                </a></li>
            <?php endif; ?>

            <hr style="border: 0; border-top: 1px dashed #334155; margin: 15px 20px;">
            
            <li><a href="profile.php" title="My Profile" class="<?php echo ($current_page == 'profile.php') ? 'active' : ''; ?>">
                <span class="nav-icon"><?php echo mh_icon('user'); ?></span>
                <span class="nav-text"><?php echo htmlspecialchars(mh_nav_label('profile', 'My Profile')); ?></span>
            </a></li>

            <?php if($nav_show_staff): ?>
                <li><a href="staff_management.php" title="Staff Management" class="<?php echo ($current_page == 'staff_management.php') ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo mh_icon('users'); ?></span>
                    <span class="nav-text"><?php echo htmlspecialchars(mh_nav_label('staff', 'Staff Management')); ?></span>
                </a></li>
            <?php endif; ?>
            <?php if($nav_show_logs): ?>
                <li><a href="activity_logs.php" title="System Logs" class="<?php echo ($current_page == 'activity_logs.php') ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo mh_icon('file-text'); ?></span>
                    <span class="nav-text"><?php echo htmlspecialchars(mh_nav_label('logs', 'System Logs')); ?></span>
                </a></li>
            <?php endif; ?>
            <?php if($nav_show_archives): ?>
                <li><a href="archives.php" title="System Archives" class="<?php echo ($current_page == 'archives.php') ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo mh_icon('archive'); ?></span>
                    <span class="nav-text"><?php echo htmlspecialchars(mh_nav_label('archives', 'System Archives')); ?></span>
                </a></li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="sidebar-footer">
        <?php if(mh_is_health_head($user_role)): ?>
        <!-- Pinned (not inside the scrolling nav) so a Health Head never has
             to scroll the menu to find System Settings. Page itself is also
             guarded server-side by require_health_head(). -->
        <a href="system_settings.php" title="System Settings" class="footer-link <?php echo ($current_page == 'system_settings.php') ? 'active' : ''; ?>">
            <span class="nav-icon"><?php echo mh_icon('settings'); ?></span>
            <span class="nav-text">System Settings</span>
        </a>
        <?php endif; ?>
        <a href="logout.php" title="Logout" style="color: #ef4444 !important;" data-confirm="Are you sure you want to log out of the Staff Console?" data-confirm-title="Log out?" data-confirm-ok="Log out">
            <span class="nav-icon"><?php echo mh_icon('log-out'); ?></span>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</div>

<script src="../assets/js/single_tab_guard.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/single_tab_guard.js'); ?>" data-scope="staff-<?php echo (int) ($_SESSION['staff_id'] ?? 0); ?>" defer></script>
<script>
    // Restore the collapsed/expanded state saved from a previous page
    // before the user sees anything else on this page - runs immediately
    // (not on DOMContentLoaded) since this <script> tag sits right after
    // #appSidebar in the markup, so the class lands as early as possible
    // and the sidebar doesn't visibly "snap" from expanded to collapsed
    // a moment after the page paints.
    (function () {
        try {
            if (window.innerWidth > 992 && localStorage.getItem('sidebarCollapsed') === 'true') {
                document.getElementById('appSidebar').classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    })();
    // The toggle button itself lives in includes/admin_topbar.php, which
    // is included (and thus parsed) after this file on every admin page,
    // so its label is synced once the full page has loaded rather than
    // from the IIFE above.
    document.addEventListener('DOMContentLoaded', mhUpdateCollapseBtnLabel);

    function mhUpdateCollapseBtnLabel() {
        const btn = document.getElementById('sidebarCollapseBtn');
        if (!btn) return;
        const sidebar = document.getElementById('appSidebar');
        const label = sidebar.classList.contains('collapsed') ? 'Expand sidebar' : 'Collapse sidebar';
        btn.setAttribute('title', label);
        btn.setAttribute('aria-label', label);
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('appSidebar');
        
        // If we are on a mobile screen width (<= 992px), toggle the mobile slide-in class
        if (window.innerWidth <= 992) {
            sidebar.classList.toggle('mobile-open');
        } else {
            // Otherwise, use standard desktop collapse toggle
            const isCollapsed = sidebar.classList.toggle('collapsed');
            document.body.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('sidebarCollapsed', isCollapsed); } catch (e) {}
            mhUpdateCollapseBtnLabel();
        }
    }

    function toggleSubmenu(event, submenuId) {
        event.preventDefault();
        const sidebar = document.getElementById('appSidebar');
        
        if (sidebar.classList.contains('collapsed') && window.innerWidth > 992) {
            toggleSidebar();
        }

        const submenu = document.getElementById(submenuId);
        const parentLi = submenu.parentElement;
        
        if (submenu.style.display === "block") {
            submenu.style.display = "none";
            parentLi.classList.remove("open");
        } else {
            submenu.style.display = "block";
            parentLi.classList.add("open");
        }
    }

    // Optional: Close mobile sidebar automatically when clicking outside on small screens
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 992) {
            const sidebar = document.getElementById('appSidebar');
            const toggleBtn = document.querySelector('.sidebar-toggle-btn');
            
            if (sidebar.classList.contains('mobile-open') && 
                !sidebar.contains(event.target) && 
                toggleBtn && !toggleBtn.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        }
    });
</script>