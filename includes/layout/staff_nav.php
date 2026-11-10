<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../settings_helper.php';
require_once __DIR__ . '/../icons.php';

if (!function_exists('mh_layout_staff_config')) {
    function mh_layout_staff_config(): array
    {
        global $conn;

        $has_conn = isset($conn) && $conn instanceof mysqli;
        if ($has_conn) {
            mh_all_settings($conn);
        }

        $page = basename($_SERVER['PHP_SELF']);
        $role = $_SESSION['role'] ?? '';

        $can_supply   = mh_has_capability('can_manage_supply_masterlist');
        $can_dispense = mh_has_capability('can_manage_dispensing');
        $can_disp_log = mh_has_capability('can_view_dispensing_history');

        $show_inventory = ($can_supply || $can_dispense || $can_disp_log) && mh_module_visible('inventory', $role);
        $show_checkups  = mh_has_any_capability(['can_log_consultations', 'can_view_consultation_history']) && mh_module_visible('checkups', $role);
        $show_maternal  = mh_has_any_capability(['can_log_maternal', 'can_view_maternal_history']) && mh_module_visible('maternal_care', $role);
        $show_immun     = mh_has_any_capability(['can_log_immunization', 'can_view_immunization_records']) && mh_module_visible('immunization', $role);
        $show_dental    = mh_has_any_capability(['can_log_dental', 'can_view_dental_history']) && mh_module_visible('dental', $role);
        $show_queue     = mh_has_any_capability(['can_view_queue', 'can_manage_queue']);
        $show_area      = mh_has_capability('can_view_area_masterlist');
        $show_patients  = mh_has_permission('view_patient_masterlist') && mh_module_visible('patient_masterlist', $role);
        $show_staff     = mh_has_permission('manage_staff');
        $show_stats     = mh_has_permission('view_disease_statistics');
        $show_logs      = mh_has_permission('view_system_logs');
        $show_archives  = mh_has_permission('view_system_archives');

        $items = [];

        $items[] = [
            'label'  => mh_nav_label('dashboard', 'Dashboard'),
            'href'   => 'dashboard.php',
            'icon'   => 'grid',
            'active' => $page === 'dashboard.php',
        ];

        if ($show_patients) {
            $items[] = [
                'label'  => mh_module_label('patient_masterlist'),
                'href'   => 'patient_masterlist.php',
                'icon'   => 'users',
                'active' => in_array($page, ['patient_masterlist.php', 'view_patient.php', 'edit_patient.php'], true),
            ];
        }

        if ($show_inventory) {
            $children = [];
            if ($can_supply) {
                $children[] = ['label' => 'Supply Masterlist', 'href' => 'inventory.php', 'active' => $page === 'inventory.php'];
                $children[] = ['label' => 'Disposal History', 'href' => 'inventory_disposals.php', 'active' => $page === 'inventory_disposals.php'];
            }
            if ($can_dispense || $can_disp_log) {
                $children[] = [
                    'label'  => $can_dispense ? 'Dispense Medicine' : 'Dispensing History',
                    'href'   => 'dispense_medicine.php',
                    'active' => $page === 'dispense_medicine.php',
                ];
            }
            $items[] = [
                'label'    => mh_module_label('inventory'),
                'href'     => '#',
                'icon'     => 'package',
                'active'   => in_array($page, ['inventory.php', 'inventory_disposals.php', 'dispense_medicine.php'], true),
                'children' => $children,
                'id'       => 'invSubmenu',
            ];
        }

        if ($show_area) {
            $items[] = [
                'label'  => mh_nav_label('area_masterlist', 'Area Masterlist'),
                'href'   => 'area_masterlist.php',
                'icon'   => 'map-pin',
                'active' => $page === 'area_masterlist.php',
            ];
        }

        if ($show_queue) {
            $items[] = [
                'label'  => mh_nav_label('queue', 'Patient Queue'),
                'href'   => 'appointments.php',
                'icon'   => 'calendar',
                'active' => $page === 'appointments.php',
            ];
        }

        $departments = $has_conn ? mh_options($conn, 'clinic_departments', true) : [];
        if (!$departments) {
            $departments = [];
            foreach (['General Consultation', 'Dental Clinic', 'Maternal Care', 'Child Immunization'] as $fallback) {
                $departments[] = ['id' => 0, 'option_value' => $fallback];
            }
        }
        $dept_modules = [
            'checkups'      => [$show_checkups, 'activity'],
            'maternal_care' => [$show_maternal, 'heart'],
            'immunization'  => [$show_immun, 'droplet'],
            'dental'        => [$show_dental, 'smile'],
        ];
        foreach ($departments as $dept) {
            $dept_name   = (string) $dept['option_value'];
            $dept_module = mh_department_module($dept_name);
            if ($dept_module === 'inventory') {
                continue;
            }
            if ($dept_module !== null && isset($dept_modules[$dept_module])) {
                $show   = $dept_modules[$dept_module][0];
                $icon   = $dept_modules[$dept_module][1];
                $href   = mh_department_page($dept_name);
                $active = ($page === $href) || ($href === 'immunization.php' && $page === 'view_immunization.php');
            } elseif ($dept_module === null && (int) $dept['id'] > 0) {
                $show   = mh_dept_user_can($dept_name);
                $icon   = 'folder';
                $href   = 'department.php?id=' . (int) $dept['id'];
                $active = ($page === 'department.php' && (int) ($_GET['id'] ?? 0) === (int) $dept['id']);
            } else {
                continue;
            }
            if (!$show || ($dept_module !== null && mh_dept_blocked($dept_name))) {
                continue;
            }
            $items[] = ['label' => $dept_name, 'href' => $href, 'icon' => $icon, 'active' => $active];
        }

        if ($show_stats) {
            $items[] = [
                'label'  => 'Disease Statistics',
                'href'   => 'disease_statistics.php',
                'icon'   => 'shield',
                'active' => $page === 'disease_statistics.php',
            ];
        }

        $items[] = ['divider' => true];

        $items[] = [
            'label'  => mh_nav_label('profile', 'My Profile'),
            'href'   => 'profile.php',
            'icon'   => 'user',
            'active' => $page === 'profile.php',
        ];

        if ($show_staff) {
            $items[] = [
                'label'  => mh_nav_label('staff', 'Staff Management'),
                'href'   => 'staff_management.php',
                'icon'   => 'users',
                'active' => $page === 'staff_management.php',
            ];
        }
        if ($show_logs) {
            $items[] = [
                'label'  => mh_nav_label('logs', 'System Logs'),
                'href'   => 'activity_logs.php',
                'icon'   => 'file-text',
                'active' => $page === 'activity_logs.php',
            ];
        }
        if ($show_archives) {
            $items[] = [
                'label'  => mh_nav_label('archives', 'System Archives'),
                'href'   => 'archives.php',
                'icon'   => 'archive',
                'active' => $page === 'archives.php',
            ];
        }

        $footer = [];
        if (mh_is_health_head($role)) {
            if (mh_setting('show_db_seeder_sidebar', '0') === '1') {
                $footer[] = ['label' => 'Database Seeder', 'href' => 'db_seeder.php', 'icon' => 'database', 'active' => $page === 'db_seeder.php', 'class' => 'footer-link'];
            }
            $footer[] = ['label' => 'System Settings', 'href' => 'system_settings.php', 'icon' => 'settings', 'active' => $page === 'system_settings.php', 'class' => 'footer-link'];
        }
        $footer[] = [
            'label'  => 'Logout',
            'href'   => 'logout.php',
            'icon'   => 'log-out',
            'active' => false,
            'class'  => 'footer-link is-danger',
            'attrs'  => [
                'data-confirm'       => 'Are you sure you want to log out of the Staff Console?',
                'data-confirm-title' => 'Log out?',
                'data-confirm-ok'    => 'Log out',
            ],
        ];

        $staff_name = (string) ($_SESSION['full_name'] ?? 'Staff Member');
        $staff_role = (string) ($_SESSION['role'] ?? 'User');
        $user_id    = (int) ($_SESSION['user_id'] ?? 0);
        $photo_path = __DIR__ . '/../../assets/images/profile_pics/' . $user_id . '.jpg';
        if (is_file($photo_path)) {
            $avatar = '<img src="../assets/images/profile_pics/' . $user_id . '.jpg?t=' . (int) @filemtime($photo_path)
                . '" alt="Profile" class="topbar-avatar no-dim" width="40" height="40">';
        } else {
            $avatar = mh_avatar_html(mh_initials_from_name($staff_name), 40, $staff_name);
        }

        return [
            'brand'  => [
                'mark'     => '<span class="brand-mark">' . mh_icon('activity') . '</span>',
                'name'     => 'Marick Health',
                'subtitle' => 'Staff Portal',
            ],
            'items'  => $items,
            'footer' => $footer,
            'user'   => null,
            'topbar' => [
                'subtitle'  => 'Staff Console',
                'user_name' => $staff_name,
                'user_sub'  => ucwords(str_replace('_', ' ', $staff_role)),
                'avatar'    => $avatar,
                'aria'      => 'Staff navigation',
            ],
        ];
    }
}
