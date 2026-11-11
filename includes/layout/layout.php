<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../icons.php';
require_once __DIR__ . '/../settings_helper.php';
require_once __DIR__ . '/../ui_helpers.php';
require_once __DIR__ . '/staff_nav.php';
require_once __DIR__ . '/patient_nav.php';

if (!function_exists('mh_layout_state')) {
    function mh_layout_state(?array $set = null): array
    {
        static $state = ['portal' => 'staff', 'title' => '', 'system_name' => '', 'late_styles' => []];
        if ($set !== null) {
            $state = array_merge($state, $set);
        }
        return $state;
    }
}

if (!function_exists('mh_layout_version')) {
    function mh_layout_version(string $relative): int
    {
        return (int) @filemtime(dirname(__DIR__, 2) . '/assets/' . ltrim($relative, '/'));
    }
}

if (!function_exists('mh_layout_head')) {
    function mh_layout_head(array $o = []): void
    {
        global $system_name;

        $portal = (($o['portal'] ?? 'staff') === 'patient') ? 'patient' : 'staff';
        $name   = (string) ($o['system_name'] ?? ($system_name ?: mh_setting('system_name')));
        $title  = trim((string) ($o['title'] ?? ''));
        mh_layout_state(['portal' => $portal, 'title' => $title, 'system_name' => $name, 'late_styles' => (array) ($o['late_styles'] ?? [])]);

        $styles = array_merge(['css/admin_theme.css', 'css/shell.css'], (array) ($o['styles'] ?? []));

        echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
        echo "<meta charset=\"UTF-8\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        include __DIR__ . '/../theme_init.php';
        echo '<title>' . mh_h($title === '' ? $name : $title . ' - ' . $name) . "</title>\n";
        foreach ($styles as $href) {
            echo '<link rel="stylesheet" href="../assets/' . mh_h($href) . '?v=' . mh_layout_version($href) . "\">\n";
        }
        foreach ((array) ($o['external_styles'] ?? []) as $url) {
            echo '<link rel="stylesheet" href="' . mh_h($url) . "\">\n";
        }
    }
}

if (!function_exists('mh_layout_open')) {
    function mh_layout_open(array $o = []): void
    {
        $state  = mh_layout_state();
        $portal = $state['portal'];

        include __DIR__ . '/../modal_assets.php';

        // Pages pass 'late_styles' to mh_layout_head(); accept it there (stored in the state) or here, without duplicates.
        $late = array_values(array_unique(array_merge((array) ($state['late_styles'] ?? []), (array) ($o['late_styles'] ?? []))));
        foreach ($late as $href) {
            echo '<link rel="stylesheet" href="../assets/' . mh_h($href) . '?v=' . mh_layout_version($href) . "\">\n";
        }

        echo '<script src="../assets/js/shell.js?v=' . mh_layout_version('js/shell.js') . "\" defer></script>\n";
        $scope = $portal === 'patient'
            ? 'patient-' . (int) ($_SESSION['patient_id'] ?? 0)
            : 'staff-' . (int) ($_SESSION['staff_id'] ?? 0);
        echo '<script src="../assets/js/single_tab_guard.js?v=' . mh_layout_version('js/single_tab_guard.js')
            . '" data-scope="' . mh_h($scope) . "\" defer></script>\n";
        echo "</head>\n";

        $body_class = trim(($portal === 'patient' ? 'patient-portal ' : '') . (string) ($o['body_class'] ?? ''));
        echo '<body' . ($body_class !== '' ? ' class="' . mh_h($body_class) . '"' : '') . (isset($o['body_attr']) ? ' ' . $o['body_attr'] : '') . ">\n";

        if (isset($o['shell']) && $o['shell'] === false) {
            return;
        }

        $config = $portal === 'patient' ? mh_layout_patient_config($o) : mh_layout_staff_config();
        $name   = $state['system_name'];

        $brand   = $config['brand'];
        $nav     = $config['items'];
        $footer  = $config['footer'];
        $user    = $config['user'] ?? null;
        $topbar  = $config['topbar'];
        $topbar['title'] = $name;

        echo "<a class=\"skip-link\" href=\"#pageBody\">Skip to main content</a>\n";
        include __DIR__ . '/sidebar.php';
        echo "<div class=\"sidebar-backdrop\" id=\"sidebarBackdrop\" aria-hidden=\"true\"></div>\n";
        echo "<div class=\"main-content\">\n";
        include __DIR__ . '/topbar.php';
    }
}

if (!function_exists('mh_layout_close')) {
    function mh_layout_close(): void
    {
        $state    = mh_layout_state();
        $name     = $state['system_name'];
        $subtitle = $state['portal'] === 'patient' ? 'Patient Portal' : 'Staff Portal';
        include __DIR__ . '/footer.php';
        echo "</div>\n";
    }
}

if (!function_exists('mh_layout_end')) {
    function mh_layout_end(): void
    {
        echo "</body>\n</html>\n";
    }
}

if (!function_exists('mh_layout_nav_link')) {
    function mh_layout_nav_link(array $item, string $extra_class = ''): void
    {
        $classes = trim($extra_class . ' ' . ($item['class'] ?? '') . ' ' . (!empty($item['active']) ? 'active' : ''));
        $attrs = '';
        foreach ((array) ($item['attrs'] ?? []) as $key => $val) {
            $attrs .= ' ' . mh_h($key) . '="' . mh_h($val) . '"';
        }
        echo '<a href="' . mh_h($item['href']) . '" title="' . mh_h($item['label']) . '"'
            . ($classes !== '' ? ' class="' . mh_h($classes) . '"' : '')
            . (!empty($item['active']) ? ' aria-current="page"' : '')
            . $attrs . '>';
        echo '<span class="nav-icon">' . mh_icon($item['icon']) . '</span>';
        echo '<span class="nav-text">' . mh_h($item['label']) . '</span>';
        echo '</a>';
    }
}