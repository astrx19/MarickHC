<?php
require_once __DIR__ . '/../icons.php';

if (!function_exists('mh_layout_patient_config')) {
    function mh_layout_patient_config(array $o): array
    {
        $active  = (string) ($o['active'] ?? '');
        $patient = is_array($o['patient'] ?? null) ? $o['patient'] : [];

        $first    = (string) ($patient['first_name'] ?? ($_SESSION['patient_name'] ?? ''));
        $last     = (string) ($patient['last_name'] ?? '');
        $fullname = trim($first . ' ' . $last);
        if ($fullname === '') {
            $fullname = 'Patient';
        }
        $initials   = mh_pp_initials($first, $last);
        $patient_no = mh_pp_patient_no($_SESSION['patient_id'] ?? 0);

        $links = [
            'dashboard' => ['dashboard.php', 'Dashboard', 'grid'],
            'history'   => ['records.php?tab=history', 'My Consultation History', 'file-text'],
            'medicines' => ['records.php?tab=medicines', 'Dispensed Medicines', 'package'],
            'profile'   => ['patient_edit_profile.php', 'My Profile', 'user'],
            'info'      => ['health_center.php', 'Health Center Info', 'home'],
        ];
        $items = [];
        foreach ($links as $key => [$href, $label, $icon]) {
            $items[] = ['label' => $label, 'href' => $href, 'icon' => $icon, 'active' => $active === $key];
        }

        $logo_v = (int) @filemtime(__DIR__ . '/../../assets/images/logo.png');

        return [
            'brand'  => [
                'mark'     => '<span class="brand-mark brand-logo"><img class="no-dim" src="../assets/images/logo.png?v=' . $logo_v . '" alt="Marick Health Center logo"></span>',
                'name'     => 'Marick Health',
                'subtitle' => 'Patient Portal',
            ],
            'items'  => $items,
            'footer' => [[
                'label'  => 'Logout',
                'href'   => '../patient_logout.php',
                'icon'   => 'log-out',
                'active' => false,
                'class'  => 'footer-link is-danger',
                'attrs'  => [
                    'data-confirm'       => 'Are you sure you want to log out of the Patient Portal?',
                    'data-confirm-title' => 'Log out?',
                    'data-confirm-ok'    => 'Log out',
                ],
            ]],
            'user'   => [
                'href'     => 'patient_edit_profile.php',
                'initials' => $initials,
                'name'     => $fullname,
                'sub'      => 'Patient ' . $patient_no,
            ],
            'topbar' => [
                'subtitle'  => 'Patient Portal',
                'user_name' => $fullname,
                'user_sub'  => $patient_no,
                'avatar'    => '<a href="patient_edit_profile.php" class="pp-avatar pp-avatar-lg" title="My Profile" aria-label="My Profile">' . mh_h($initials) . '</a>',
                'aria'      => 'Patient portal navigation',
            ],
        ];
    }
}
