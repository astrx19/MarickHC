<?php
if (!defined('MH_LOADED_PATIENT_MODULE_HELPER')) {
define('MH_LOADED_PATIENT_MODULE_HELPER', true);

require_once __DIR__ . '/ui_helpers.php';

function mh_pm_flash_set($type, $message)
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $_SESSION['mh_pm_flash'] = ['type' => (string) $type, 'message' => (string) $message];
}

function mh_pm_flash_pull()
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $flash = $_SESSION['mh_pm_flash'] ?? null;
    unset($_SESSION['mh_pm_flash']);
    return is_array($flash) ? $flash : null;
}

function mh_pm_redirect_self()
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ' . ($uri !== '' ? $uri : basename((string) ($_SERVER['PHP_SELF'] ?? ''))));
    exit();
}

function mh_pm_alert($type, $message, $strong = '')
{
    $map = [
        'success' => ['alert-success', 'check-circle'],
        'error'   => ['alert-error', 'x-circle'],
        'warn'    => ['alert-warn', 'alert-triangle'],
    ];
    list($class, $icon) = $map[$type] ?? $map['error'];
    return '<div class="alert ' . $class . ' pm-alert" role="alert">' . mh_icon($icon) . '<span>'
        . ($strong !== '' ? '<strong>' . mh_h($strong) . '</strong> ' : '') . mh_h($message) . '</span></div>';
}

function mh_pm_valid_email($email)
{
    $email = (string) $email;
    return strlen($email) <= 100
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.com$/i', $email) === 1;
}

function mh_pm_clean_name($value)
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
}

function mh_pm_valid_name($name, $required = true)
{
    $name = (string) $name;
    if ($name === '') { return !$required; }
    return mb_strlen($name) <= 50 && preg_match('/^[\p{L}][\p{L}\s\.\'\-]*$/u', $name) === 1;
}

function mh_pm_valid_birthdate($value)
{
    $d = DateTime::createFromFormat('Y-m-d', (string) $value);
    return $d && $d->format('Y-m-d') === $value && $d <= new DateTime('today') && $d >= new DateTime('-120 years');
}

function mh_pm_placeholder_email($first, $last)
{
    $slug = function ($v) { return preg_replace('/[^a-z0-9]/', '', strtolower((string) $v)); };
    return $slug($first) . '.' . $slug($last) . '.' . bin2hex(random_bytes(4)) . '@walkin.local';
}

function mh_pm_is_placeholder_email($email)
{
    return (bool) preg_match('/@walkin\.local$/i', (string) $email);
}

function mh_pm_resolve_address($address, array $streets)
{
    $head = trim(explode(',', trim((string) $address))[0]);
    if ($head === '' || !$streets) { return ['', $head]; }
    usort($streets, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
    foreach ($streets as $street) {
        $q = preg_quote($street, '/');
        if (preg_match('/^(.*?)\s*\b' . $q . '\s+St\.?$/iu', $head, $m)) {
            return [$street, trim($m[1])];
        }
    }
    foreach ($streets as $street) {
        $q = preg_quote($street, '/');
        if (preg_match('/(^|\s)' . $q . '(\s|$)/iu', $head)) {
            return [$street, trim(preg_replace('/(^|\s)' . $q . '(\s|$)/iu', ' ', $head))];
        }
    }
    return ['', $head];
}

function mh_pm_empty_counts()
{
    return ['all' => 0, 'marick' => 0, 'outsider' => 0, 'infants' => 0];
}

function mh_pm_tally(array &$counts, array $row)
{
    $counts['all']++;
    $counts[$row['loc']]++;
    if ($row['is_infant']) { $counts['infants']++; }
}

function mh_pm_classify(array $row, array $streets = [])
{
    $age = mh_calculate_patient_age($row['birthdate'] ?? '');
    $isMarick = mh_is_marick_resident($row['address_type'] ?? '');
    $address = (string) ($row['address'] ?? '');
    $street = '';
    $house = '';
    $display = $address;
    if ($isMarick) {
        list($street, $house) = mh_pm_resolve_address($address, $streets);
        $display = trim(explode(',', $address)[0]);
    }
    $contact = (string) ($row['contact_number'] ?? '');
    $digits = preg_replace('/\D+/', '', $contact);
    if (strpos($digits, '63') === 0 && strlen($digits) === 12) { $digits = '0' . substr($digits, 2); }

    $row['age_display'] = $age['text'];
    $row['is_infant'] = $age['is_infant'];
    $row['age_months'] = $age['months'];
    $row['is_marick'] = $isMarick;
    $row['loc'] = $isMarick ? 'marick' : 'outsider';
    $row['street'] = $street;
    $row['house'] = $house;
    $row['display_address'] = $display;
    $row['search'] = mb_strtolower(trim(implode(' ', [
        $row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? '',
        $contact, $digits, $display, $row['extra_contacts'] ?? '',
    ])));
    return $row;
}

function mh_pm_location_badge(array $row)
{
    $class = $row['is_marick'] ? 'pm-badge--marick' : 'pm-badge--outsider';
    $label = $row['display_address'] !== '' ? $row['display_address'] : ($row['is_marick'] ? 'Marick Resident' : 'Outsider');
    return '<span class="pm-badge ' . $class . '" title="' . mh_h($label) . '">' . mh_h($label) . '</span>';
}

function mh_pm_age_badge(array $row)
{
    if ($row['is_infant']) {
        $m = (int) $row['age_months'];
        $title = $m <= 0 ? 'Younger than one month' : $m . ' month' . ($m === 1 ? '' : 's') . ' old';
        return '<span class="pm-badge pm-badge--baby" title="' . mh_h($title) . '">' . mh_h($row['age_display']) . '</span>';
    }
    return '<span class="pm-age">' . mh_h($row['age_display']) . '</span>';
}

function mh_pm_row_attrs(array $row)
{
    return 'data-pm-row data-loc="' . mh_h($row['loc']) . '"'
        . ' data-street="' . mh_h(mb_strtoupper($row['street'])) . '"'
        . ' data-infant="' . ($row['is_infant'] ? '1' : '0') . '"'
        . ' data-search="' . mh_h($row['search']) . '"';
}

function mh_pm_render_filters(array $o)
{
    $counts = $o['counts'];
    $streets = $o['streets'] ?? [];
    $target = $o['target'];
    $id = $o['id'];
    $page = (int) ($o['page_size'] ?? 0);
    $tabs = ['all' => 'All Patients', 'marick' => 'Marick Residents', 'outsider' => 'Outsiders'];

    $html = '<div class="pm-filters" id="' . mh_h($id) . '" data-pm-filters data-pm-target="' . mh_h($target) . '"'
        . ' data-pm-summary="#' . mh_h($id) . 'Summary" data-pm-empty="#' . mh_h($id) . 'Empty" data-pm-more="#' . mh_h($id) . 'More"'
        . ' data-pm-page="' . $page . '">';

    $html .= '<div class="pm-tabs" role="tablist" aria-label="Patient location">';
    foreach ($tabs as $key => $label) {
        $html .= '<button type="button" class="pm-tab" role="tab" data-pm-loc="' . $key . '" aria-selected="' . ($key === 'all' ? 'true' : 'false') . '">'
            . '<span>' . mh_h($label) . '</span><span class="pm-count" data-pm-count="loc:' . $key . '">' . (int) $counts[$key] . '</span></button>';
    }
    $html .= '</div>';

    $html .= '<div class="pm-toolbar">';
    if ($streets) {
        $html .= '<div class="pm-street" data-pm-street-box hidden><select data-pm-street aria-label="Filter by street or purok">'
            . '<option value="">All streets and puroks</option>';
        foreach ($streets as $street) {
            $html .= '<option value="' . mh_h(mb_strtoupper($street)) . '">' . mh_h($street) . '</option>';
        }
        $html .= '</select></div>';
    }
    $html .= '<div class="pm-pills" role="group" aria-label="Age group">'
        . '<button type="button" class="pm-pill" data-pm-age="all" aria-pressed="true">All ages <span class="pm-count" data-pm-count="age:all">' . (int) $counts['all'] . '</span></button>'
        . '<button type="button" class="pm-pill" data-pm-age="infants" aria-pressed="false">Babies under 1 yr <span class="pm-count" data-pm-count="age:infants">' . (int) $counts['infants'] . '</span></button>'
        . '</div>';
    $html .= '<input type="search" class="search-box pm-search" data-pm-search placeholder="' . mh_h($o['placeholder'] ?? 'Search by name or contact') . '" aria-label="Search patients" autocomplete="off">';
    $html .= '</div></div>';
    return $html;
}

function mh_pm_render_footer($id)
{
    return '<div class="pm-empty" id="' . mh_h($id) . 'Empty" hidden>No patients match the selected filters.</div>'
        . '<div class="pm-foot"><span id="' . mh_h($id) . 'Summary" aria-live="polite"></span>'
        . '<button type="button" class="btn-secondary pm-more" id="' . mh_h($id) . 'More" hidden>Show more</button></div>';
}

}
