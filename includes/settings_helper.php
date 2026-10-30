<?php
// Definitions are wrapped in a constant-keyed if-block ON PURPOSE: PHP registers top-level functions at
// compile time, so require_once cannot stop a redeclare when one file is reached by two paths that differ
// only in letter case (/MARICK-HEALTH-SYSTEM-v2/ vs /marick-health-system-v2/ on macOS/Windows).
if (!defined('MH_LOADED_INCLUDES_SETTINGS_HELPER')) {
define('MH_LOADED_INCLUDES_SETTINGS_HELPER', true);
/**
 * =====================================================================
 * MARICK HEALTH CENTER - SETTINGS HELPER
 * =====================================================================
 * Single source of truth for:
 *   - branding text + uploaded asset paths   (system_settings table)
 *   - every dynamic dropdown / masterlist     (system_options table)
 *   - the UI colour theme                     (system_settings table)
 *
 * Every query here uses prepared statements.
 *
 * NO STATIC CONFIG FILES
 * ----------------------
 * Nothing is written to config/*.php any more. Credentials (SMS / OCR keys),
 * branding and every masterlist are read live from the database
 * (system_settings / system_options). The few legacy page variables
 * ($system_name, $hero_title, ...) are populated per-request by
 * mh_export_branding_globals(), which config/database.php calls.
 *
 * USAGE (new code)
 * ----------------
 *   require_once __DIR__ . '/../includes/settings_helper.php';
 *   mh_settings_boot($conn);
 *   echo mh_setting('system_name');
 *   foreach (mh_option_values($conn, 'delivery_types') as $v) { ... }
 */

require_once __DIR__ . '/roles.php';   // staff role list (mh_get_module_grid_roles(), ...)

if (!defined('MH_ROOT')) {
    define('MH_ROOT', dirname(__DIR__));
}

/* =====================================================================
 * 1. INSTALL / BOOT
 * ================================================================== */

/**
 * Creates the settings tables if they are not there yet and seeds the
 * defaults. Idempotent - safe to call on every page load.
 */
function mh_settings_install(mysqli $conn)
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key`   VARCHAR(64) NOT NULL,
            `setting_value` TEXT NULL,
            `updated_by`    INT NULL,
            `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `system_options` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `list_key`     VARCHAR(50)  NOT NULL,
            `option_value` VARCHAR(150) NOT NULL,
            `option_label` VARCHAR(150) NULL,
            `sort_order`   INT DEFAULT 0,
            `is_active`    TINYINT(1) DEFAULT 1,
            `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_list_value` (`list_key`, `option_value`),
            KEY `idx_list_active` (`list_key`, `is_active`, `sort_order`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // ---- seed key/value defaults (never overwrites an existing row) --
    $defaults = mh_setting_defaults();
    $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    if ($stmt) {
        foreach ($defaults as $k => $v) {
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();
    }

    // ---- seed masterlists (first install only) ------------------------
    $countRow = $conn->query("SELECT COUNT(*) AS c FROM system_options");
    $count    = $countRow ? (int) ($countRow->fetch_assoc()['c'] ?? 0) : 0;

    if ($count === 0) {
        mh_seed_default_options($conn);
    }
}

/**
 * Loads settings into the in-process cache. Call once per request.
 */
function mh_settings_boot(mysqli $conn, $install = false)
{
    if ($install) {
        mh_settings_install($conn);
    }
    mh_all_settings($conn, true);
}

/**
 * Factory defaults for every key/value setting.
 */
function mh_setting_defaults()
{
    return [
        'system_name'           => 'Barangay Marick Health Center',
        'header_title'          => 'Barangay Marick Health Center',
        'header_tagline'        => 'Health Records Management System',
        'hero_title'            => 'Quality Healthcare for the Marick Community.',
        'hero_subtitle'         => 'Providing accessible, compassionate, and comprehensive medical services to ensure the well-being of every resident in our barangay.',
        'global_announcement'   => '',
        'enable_online_booking' => '1',
        'logo_path'             => 'assets/images/logo.png',
        'background_path'       => 'assets/images/barangay_bg.png',
        'ocr_model'             => 'gemini-2.0-flash',
        'semaphore_api_key'     => '',
        'semaphore_sender_name' => 'SEMAPHORE',
        'sms_password_reset_enabled' => '0',
        // ---- SMS provider routing + Android SMS Gateway ---------------
        'sms_provider'          => 'semaphore', // 'semaphore' | 'android_gateway'
        'android_gateway_url'   => '',
        'android_gateway_key'   => '',
        // ---- Gemini OCR API key (DB-backed override of config/gemini.php) --
        'gemini_api_key'        => '',
        // ---- Browser-triggered cron key (empty = browser trigger disabled, CLI only) --
        'cron_run_key'          => '',
        // ---- Jurisdiction (Address Jurisdiction masterlist rule) -----------------
        'home_address_type'     => 'Marick',          // the Location Type that means "resident" (street + house no.)
        'outside_address_type'  => 'Outside Marick',  // default label for non-residents (free-text address)
        'home_address_suffix'   => 'Marick Subdivision, Cainta, Rizal',   // appended to resident addresses: "12 Aida St., <suffix>"
    ] + mh_theme_defaults() + mh_module_defaults();
}

/**
 * Factory default colour palette (matches the current hard-coded CSS).
 */
function mh_theme_defaults()
{
    return [
        'theme_primary'       => '#4ca1af',
        'theme_primary_hover' => '#3b828e',
        'theme_accent'        => '#38bdf8',
        'theme_sidebar_bg'    => '#0f172a',
        'theme_sidebar_hover' => '#1e293b',
        'theme_sidebar_text'  => '#cbd5e1',
        'theme_page_bg'       => '#f1f5f9',
        'theme_panel_bg'      => '#ffffff',
        'theme_body_text'     => '#334155',
        'theme_heading_text'  => '#1e293b',
        'theme_link'          => '#0ea5e9',
        'theme_button_bg'     => '#4ca1af',
        'theme_button_text'   => '#ffffff',
        'theme_border'        => '#e2e8f0',
    ];
}

/**
 * Human labels + grouping for the colour pickers in System Settings.
 */
function mh_theme_field_groups()
{
    return [
        'Brand & Accents' => [
            'theme_primary'       => 'Primary theme colour',
            'theme_primary_hover' => 'Primary (hover state)',
            'theme_accent'        => 'Accent / highlight',
            'theme_link'          => 'Links',
        ],
        'Sidebar' => [
            'theme_sidebar_bg'    => 'Sidebar background',
            'theme_sidebar_hover' => 'Sidebar hover / active row',
            'theme_sidebar_text'  => 'Sidebar navigation text',
        ],
        'Page & Text' => [
            'theme_page_bg'      => 'Page background',
            'theme_panel_bg'     => 'Panel / card background',
            'theme_body_text'    => 'Body text',
            'theme_heading_text' => 'Headings',
            'theme_border'       => 'Borders & dividers',
        ],
        'Buttons' => [
            'theme_button_bg'   => 'Button background',
            'theme_button_text' => 'Button text',
        ],
    ];
}

/* =====================================================================
 * 2. KEY/VALUE SETTINGS
 * ================================================================== */

/**
 * Returns every setting as an associative array (cached per request).
 */
function mh_all_settings(mysqli $conn = null, $refresh = false)
{
    static $cache = null;

    if ($cache !== null && !$refresh) {
        return $cache;
    }
    if ($conn === null) {
        return $cache ?? mh_setting_defaults();
    }

    $cache = mh_setting_defaults();

    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
        $res->free();
    }

    return $cache;
}

/**
 * Reads one setting. Falls back to the factory default, then $fallback.
 */
function mh_setting($key, $fallback = '')
{
    $all = mh_all_settings();
    if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
        return $all[$key];
    }
    $defaults = mh_setting_defaults();
    if (array_key_exists($key, $defaults) && $defaults[$key] !== '') {
        return $defaults[$key];
    }
    return $fallback;
}

/**
 * Writes one setting (prepared statement, upsert).
 */
function mh_set_setting(mysqli $conn, $key, $value, $userId = null)
{
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
    );
    if (!$stmt) { return false; }

    $uid = $userId !== null ? (int) $userId : null;
    $stmt->bind_param('ssi', $key, $value, $uid);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Writes many settings in one transaction.
 */
function mh_set_settings(mysqli $conn, array $pairs, $userId = null)
{
    $conn->begin_transaction();
    try {
        foreach ($pairs as $k => $v) {
            if (!mh_set_setting($conn, $k, (string) $v, $userId)) {
                throw new RuntimeException('Failed writing setting: ' . $k);
            }
        }
        $conn->commit();
        mh_all_settings($conn, true);
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

/* =====================================================================
 * 3. MASTERLISTS / DROPDOWN OPTIONS
 * ================================================================== */

/**
 * The lists an admin can manage, with friendly names and where they show.
 */
function mh_managed_lists()
{
    return [
        'streets' => [
            'label' => 'Streets / Areas / Purok',
            'hint'  => 'Public registration, walk-in, patient masterlist filter and the patient profile editor.',
        ],
        'address_types' => [
            'label' => 'Address Jurisdiction',
            'hint'  => 'Location Type in registration, walk-in and the profile editor. "Marick" = resident (street + house no.); any other value asks for a full address.',
        ],
        'clinic_departments' => [
            'label' => 'Clinic Departments',
            'hint'  => 'Walk-in registration and the Patient Queueing department filter. Rename, link to a module and hide departments under Departments in the sidebar.',
        ],
        'inv_categories' => [
            'label' => 'Inventory Categories',
            'hint'  => 'Item category in Medicine Inventory (add form, filter, edit).',
        ],
        'delivery_types' => [
            'label' => 'Types of Delivery',
            'hint'  => 'Child Immunization form.',
        ],
        'breastfeeding_types' => [
            'label' => 'Types of Breastfeeding',
            'hint'  => 'Child Immunization form.',
        ],
        'vaccine_categories' => [
            'label' => 'Vaccine Categories',
            'hint'  => 'Vaccine Name when logging a vaccine on a child\'s immunization record.',
        ],
        'birth_places' => [
            'label' => 'Place of Delivery / Birth Place',
            'hint'  => 'Child Immunization form.',
        ],
    ];
}

/**
 * Full option rows for a list (id, value, label, sort_order, is_active).
 */
function mh_options(mysqli $conn, $listKey, $activeOnly = true)
{
    $sql = "SELECT id, list_key, option_value, option_label, sort_order, is_active
            FROM system_options
            WHERE list_key = ?";
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    // Masterlist dropdowns are always alphabetical (A-Z); the old manual
    // sort_order column is kept in the table but no longer used for display.
    $sql .= " ORDER BY option_value ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }

    $stmt->bind_param('s', $listKey);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

/**
 * Flat list of active option values - the common case for a <select>.
 */
function mh_option_values(mysqli $conn, $listKey)
{
    $out = [];
    foreach (mh_options($conn, $listKey, true) as $row) {
        $out[] = $row['option_value'];
    }
    return $out;
}

/* =====================================================================
 * CENTRAL MASTERLIST ACCESS  (use these everywhere - never hardcode <option>s)
 *
 * Every dropdown that an admin can manage under System Settings >
 * Dropdown Masterlists reads through mh_masterlist(). That reads the
 * `system_options` table directly, so an add / rename / hide in System
 * Settings is visible on the very next page load of ANY page. Nothing
 * here depends on the generated config/*.php mirror files being writable.
 *
 * If a list is empty (or the table can't be read) the built-in defaults
 * from mh_masterlist_defaults() are used, so a form never renders with an
 * empty dropdown.
 * ================================================================== */

/** Built-in fallback values for every managed list. Also the first-run seed. */
function mh_masterlist_defaults()
{
    return [
        'streets'            => ['Aida','Cattleya','Cora','Daffodil','Delia','Emma','Flora',
                                 'Gracia','Irma','Jaguar','Joey','Leonard','Ritchie','Vicenta'],
        'address_types'      => [mh_home_address_type(), mh_outside_address_type()],
        'clinic_departments' => ['General Consultation','Dental Clinic','Maternal Care','Child Immunization'],
        'inv_categories'     => ['Medicine','Vaccine','Medical Supply','Equipment'],
        'delivery_types'     => ['Normal Spontaneous Delivery','Caesarean Section','Assisted Breech',
                                 'Forceps Delivery','Vacuum Extraction'],
        'breastfeeding_types'=> ['Exclusive Breastfeeding','Mixed Feeding','Formula Feeding'],
        'vaccine_categories' => ['BCG','Hepatitis B','Pentavalent','Oral Polio Vaccine (OPV)',
                                 'Inactivated Polio Vaccine (IPV)','Pneumococcal Conjugate (PCV)',
                                 'Measles-Rubella (MR)','MMR','Tetanus Toxoid'],
        'birth_places'       => ['Hospital','Lying-in Clinic','Barangay Health Center','Home'],
    ];
}

/** Per-request cache; cleared whenever an option is added / edited / deleted. */
function mh_masterlist_flush()
{
    mh_masterlist_cache(null, null, true);
}

function mh_masterlist_cache($key, $value = null, $flush = false)
{
    static $cache = [];
    if ($flush) { $cache = []; return null; }
    if ($value !== null) { $cache[$key] = $value; }
    return $cache[$key] ?? null;
}

/**
 * Active values of a masterlist (alphabetical), straight from the database,
 * falling back to the built-in defaults when empty / unreachable.
 *
 * @return string[]
 */
function mh_masterlist($conn, $listKey)
{
    $hit = mh_masterlist_cache($listKey);
    if ($hit !== null) { return $hit; }

    $vals = [];
    if ($conn instanceof mysqli) {
        try { $vals = mh_option_values($conn, $listKey); } catch (Throwable $e) { $vals = []; }
    }
    if (!$vals) {
        $defaults = mh_masterlist_defaults();
        $vals = $defaults[$listKey] ?? [];
    }
    mh_masterlist_cache($listKey, $vals);
    return $vals;
}

/* ---- one named helper per managed list ---------------------------------- */
function mh_clinic_departments($conn = null)   { return mh_masterlist($conn, 'clinic_departments'); }
function mh_streets($conn = null)              { return mh_masterlist($conn, 'streets'); }
function mh_address_types($conn = null)        { return mh_masterlist($conn, 'address_types'); }
function mh_inventory_categories($conn = null) { return mh_masterlist($conn, 'inv_categories'); }
function mh_delivery_types($conn = null)       { return mh_masterlist($conn, 'delivery_types'); }
function mh_breastfeeding_types($conn = null)  { return mh_masterlist($conn, 'breastfeeding_types'); }
function mh_vaccine_categories($conn = null)   { return mh_masterlist($conn, 'vaccine_categories'); }
function mh_birth_places($conn = null)         { return mh_masterlist($conn, 'birth_places'); }

/** True when $value is a currently active option of $listKey (server-side check for posted values). */
function mh_masterlist_has($conn, $listKey, $value)
{
    return in_array((string) $value, mh_masterlist($conn, $listKey), true);
}
function mh_is_clinic_department($conn, $dept) { return mh_masterlist_has($conn, 'clinic_departments', $dept); }

/**
 * <option> tags for any masterlist. Keeps $selected visible even when that
 * value was later hidden/removed, so editing an old record never blanks it.
 *
 * @param array $extra  trailing static options, [value => label] (e.g. an "Other" entry)
 * @param array $labels optional [value => display label] overrides
 */
function mh_masterlist_options($conn, $listKey, $selected = '', $placeholder = '', array $extra = [], array $labels = [])
{
    $vals = mh_masterlist($conn, $listKey);
    $selected = (string) $selected;
    $html = $placeholder !== '' ? '<option value="">' . htmlspecialchars($placeholder) . '</option>' : '';
    $found = false;
    foreach ($vals as $v) {
        $isSel = ($v === $selected);
        if ($isSel) { $found = true; }
        $html .= '<option value="' . htmlspecialchars($v) . '"' . ($isSel ? ' selected' : '') . '>'
               . htmlspecialchars($labels[$v] ?? $v) . '</option>';
    }
    foreach ($extra as $v => $label) {
        if ((string) $v === $selected) { $found = true; }
        $html .= '<option value="' . htmlspecialchars($v) . '"' . ((string) $v === $selected ? ' selected' : '') . '>'
               . htmlspecialchars($label) . '</option>';
    }
    if ($selected !== '' && !$found) {
        $html .= '<option value="' . htmlspecialchars($selected) . '" selected>' . htmlspecialchars($selected) . '</option>';
    }
    return $html;
}

/** <option> tags for the Clinic Departments list (kept for existing callers). */
function mh_department_options($conn, $selected = '', $withPlaceholder = '')
{
    return mh_masterlist_options($conn, 'clinic_departments', $selected, $withPlaceholder);
}

/**
 * Address Jurisdiction (all values come from System Settings, never from literals).
 *   home_address_type    the Location Type that means "resident" (default "Marick")
 *   outside_address_type default label for non-residents (default "Outside Marick")
 *   home_address_suffix  text appended to a resident's street address
 */
function mh_home_address_type()
{
    $v = trim((string) mh_setting('home_address_type', 'Marick'));
    return $v !== '' ? $v : 'Marick';
}
function mh_outside_address_type()
{
    $v = trim((string) mh_setting('outside_address_type', 'Outside Marick'));
    return $v !== '' ? $v : 'Outside Marick';
}
function mh_home_address_suffix()
{
    return trim((string) mh_setting('home_address_suffix', 'Marick Subdivision, Cainta, Rizal'));
}

/**
 * Is this Location Type the home jurisdiction (resident = street + house number)?
 * Any other value (Outside Marick, Outsider, one an admin adds) means a free-text full address.
 * Code that branches on residency must call this instead of comparing to a literal.
 */
function mh_is_marick_resident($addressType)
{
    return strcasecmp(trim((string) $addressType), mh_home_address_type()) === 0;
}

/** Display labels for the Location Type select: the home value reads "<home> Resident". */
function mh_address_type_labels()
{
    $home = mh_home_address_type();
    return [$home => $home . ' Resident'];
}

/** "12 Aida St., Marick Subdivision, Cainta, Rizal" - one place that builds a resident address. */
function mh_build_resident_address($house, $street)
{
    $suffix = mh_home_address_suffix();
    return trim(trim((string) $house) . ' ' . trim((string) $street)) . ' St.' . ($suffix !== '' ? ', ' . $suffix : '');
}

/** Reverse of mh_build_resident_address(): [house, street] or null when the text is not in that shape. */
function mh_parse_resident_address($address)
{
    if (preg_match('/^(\S+)\s+(.+?)\s+St\.(?:,|$)/u', (string) $address, $m)) {
        return [$m[1], $m[2]];
    }
    return null;
}

/** Attribute for the Location Type <select>: lets JavaScript know which value means "resident". */
function mh_home_type_attr()
{
    return 'data-home-type="' . htmlspecialchars(mh_home_address_type(), ENT_QUOTES) . '"';
}

/** Display name of a module: the name typed under System Settings -> Module Access, else the built-in label. */
function mh_module_label($key)
{
    $custom = trim((string) mh_setting('module_label_' . $key, ''));
    if ($custom !== '') { return $custom; }
    $reg = mh_module_registry();
    return $reg[$key]['label'] ?? (string) $key;
}

/** Modules a department can be linked to (value => label). '' = guess from the name, 'none' = generic. */
function mh_department_module_choices()
{
    return [
        ''              => 'Auto-detect from the department name',
        'checkups'      => 'Consultations / Checkups',
        'dental'        => 'Dental Records',
        'maternal_care' => 'Maternal Care',
        'immunization'  => 'Child Immunization',
        'inventory'     => 'Medicine Inventory / Dispensing',
        'none'          => 'Custom department page (queue + visit notes)',
    ];
}

/** Creates the department settings table when missing (also in database/departments_migration.sql). */
function mh_department_ensure_table($conn)
{
    if (!($conn instanceof mysqli)) { return false; }
    $ok = (bool) $conn->query("CREATE TABLE IF NOT EXISTS department_settings (
        dept_name VARCHAR(100) NOT NULL,
        module_key VARCHAR(30) NOT NULL DEFAULT '',
        description VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (dept_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Who may use a custom department: per role, and per-account exceptions.
    $conn->query("CREATE TABLE IF NOT EXISTS department_access (
        dept_name VARCHAR(100) NOT NULL,
        role_key VARCHAR(50) NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (dept_name, role_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS staff_department_access (
        staff_id INT NOT NULL,
        dept_name VARCHAR(100) NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (staff_id, dept_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $ok;
}

/** @return array<string,array{module_key:string,description:string}> keyed by lower-case department name */
function mh_department_settings($conn = null, $refresh = false)
{
    if (!$refresh && isset($GLOBALS['mh_dept_settings_cache'])) { return $GLOBALS['mh_dept_settings_cache']; }
    $map = [];
    $conn = $conn ?: ($GLOBALS['conn'] ?? null);
    if ($conn instanceof mysqli) {
        $res = @$conn->query("SELECT dept_name, module_key, description FROM department_settings");   // false when the table is not created yet
        while ($res && ($r = $res->fetch_assoc())) {
            $map[strtolower($r['dept_name'])] = ['module_key' => (string) $r['module_key'], 'description' => (string) ($r['description'] ?? '')];
        }
    }
    return $GLOBALS['mh_dept_settings_cache'] = $map;
}

/** Active departments with no module page of their own (they use admin/department.php). @return array<int,array{id:int,name:string}> */
function mh_custom_departments($conn = null)
{
    $conn = $conn ?: ($GLOBALS['conn'] ?? null);
    $out = [];
    if (!($conn instanceof mysqli)) { return $out; }
    foreach (mh_options($conn, 'clinic_departments', true) as $o) {
        if (mh_department_module($o['option_value']) === null) { $out[] = ['id' => (int) $o['id'], 'name' => $o['option_value']]; }
    }
    return $out;
}

/** @return array<string,array<string,int>> lower dept => role_key => 0|1 (only departments that have been configured) */
function mh_dept_role_map($conn = null, $refresh = false)
{
    if (!$refresh && isset($GLOBALS['mh_dept_role_cache'])) { return $GLOBALS['mh_dept_role_cache']; }
    $map = [];
    $conn = $conn ?: ($GLOBALS['conn'] ?? null);
    if ($conn instanceof mysqli) {
        $res = @$conn->query("SELECT dept_name, role_key, allowed FROM department_access");
        while ($res && ($r = $res->fetch_assoc())) { $map[strtolower($r['dept_name'])][$r['role_key']] = (int) $r['allowed']; }
    }
    return $GLOBALS['mh_dept_role_cache'] = $map;
}

/** Role default when nobody configured the department: its module's access, or (custom) queue processors. */
function mh_dept_role_default($dept, $role)
{
    $role = strtolower((string) $role);
    if ($role === 'health_head') { return true; }
    $m = mh_department_module($dept);
    if ($m === null) { return mh_has_permission('manage_patient_queue', $role); }
    return mh_module_visible($m, $role);
}

/** Does this ROLE use the department? Configured under System Settings -> Departments, else the default above. */
function mh_dept_role_allowed($dept, $role)
{
    $role = strtolower((string) $role);
    if ($role === 'health_head') { return true; }
    $map = mh_dept_role_map()[strtolower(trim((string) $dept))] ?? null;
    if ($map !== null) { return !empty($map[$role]); }
    return mh_dept_role_default($dept, $role);
}

/** Has someone switched this department OFF for the person / role? (module-linked departments can be restricted, never widened) */
function mh_dept_blocked($dept, $role = null, $staffId = null)
{
    $sessRole = strtolower((string) ($_SESSION['role'] ?? ''));
    $role = strtolower((string) ($role ?? $sessRole));
    if ($role === 'health_head') { return false; }
    $k = strtolower(trim((string) $dept));
    if ($staffId !== null || $role === $sessRole) {
        $ov = mh_dept_staff_overrides($staffId ?? (int) ($_SESSION['user_id'] ?? 0));
        if (array_key_exists($k, $ov)) { return $ov[$k] === 0; }
    }
    $map = mh_dept_role_map()[$k] ?? null;
    return $map !== null && empty($map[$role]);
}

/** Every active department. @return array<int,array{id:int,name:string}> */
function mh_dept_all($conn = null)
{
    $conn = $conn ?: ($GLOBALS['conn'] ?? null);
    $out = [];
    if (!($conn instanceof mysqli)) { return $out; }
    foreach (mh_options($conn, 'clinic_departments', true) as $o) { $out[] = ['id' => (int) $o['id'], 'name' => $o['option_value']]; }
    return $out;
}

/** @return array<string,int> lower dept => 0|1 : per-account exceptions to the role setting */
function mh_dept_staff_overrides($staffId, $conn = null)
{
    static $cache = [];
    $staffId = (int) $staffId;
    if (isset($cache[$staffId])) { return $cache[$staffId]; }
    $map = [];
    $conn = $conn ?: ($GLOBALS['conn'] ?? null);
    if ($conn instanceof mysqli && $staffId > 0) {
        $st = @$conn->prepare("SELECT dept_name, allowed FROM staff_department_access WHERE staff_id = ?");
        if ($st) {
            $st->bind_param('i', $staffId); $st->execute();
            $res = $st->get_result();
            while ($res && ($r = $res->fetch_assoc())) { $map[strtolower($r['dept_name'])] = (int) $r['allowed']; }
            $st->close();
        }
    }
    return $cache[$staffId] = $map;
}

/** May this person (default: whoever is signed in) use the custom department? */
function mh_dept_user_can($dept, $role = null, $staffId = null)
{
    $role    = strtolower((string) ($role ?? ($_SESSION['role'] ?? '')));
    $staffId = $staffId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($role === 'health_head') { return true; }
    $ov = mh_dept_staff_overrides($staffId);
    $k  = strtolower(trim((string) $dept));
    if (array_key_exists($k, $ov)) { return $ov[$k] === 1; }
    return mh_dept_role_allowed($dept, $role);
}

/** One steady colour per department name (queue badges, dashboard). */
function mh_department_color($name)
{
    $palette = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#6366f1'];
    return $palette[abs(crc32(strtolower(trim((string) $name)))) % count($palette)];
}

/** Adds staff.status (1 = active, 0 = archived) the first time it is needed. Returns true when the column exists. */
function mh_staff_ensure_status($conn = null)
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    $conn = $conn ?: ($GLOBALS['conn'] ?? null);
    if (!($conn instanceof mysqli)) { return $ready = false; }
    $res = @$conn->query("SHOW COLUMNS FROM `staff` LIKE 'status'");
    if ($res && $res->num_rows > 0) { return $ready = true; }
    @$conn->query("ALTER TABLE `staff` ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL");
    $res = @$conn->query("SHOW COLUMNS FROM `staff` LIKE 'status'");
    return $ready = ($res && $res->num_rows > 0);
}

/** Built-in sidebar items whose names can be changed (key => default name). */
function mh_nav_label_defaults()
{
    return [
        'dashboard'       => 'Dashboard',
        'area_masterlist' => 'Area Masterlist',
        'queue'           => 'Patient Queue',
        'profile'         => 'My Profile',
        'staff'           => 'Staff Management',
        'logs'            => 'System Logs',
        'archives'        => 'System Archives',
    ];
}

/** "JC" from first + last name (never a photo). */
function mh_initials($first, $last = '')
{
    $a = mb_strtoupper(mb_substr(trim((string) $first), 0, 1));
    $b = mb_strtoupper(mb_substr(trim((string) $last), 0, 1));
    return ($a . $b) !== '' ? $a . $b : '?';
}

/** Initials from one display name: first word + last word ("Maria Dela Cruz" -> "MC"). */
function mh_initials_from_name($name)
{
    $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) { return '?'; }
    return mh_initials($parts[0], count($parts) > 1 ? $parts[count($parts) - 1] : '');
}

/** Round initials avatar (patient privacy: patient faces are never displayed). */
function mh_avatar_html($initials, $size = 40, $seed = '')
{
    $size  = max(20, (int) $size);
    $color = mh_department_color($seed !== '' ? $seed : $initials);
    return '<span class="mh-avatar" aria-hidden="true" style="display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto;'
         . ' width:' . $size . 'px; height:' . $size . 'px; border-radius:50%; background:' . $color . '; color:#fff; font-weight:700;'
         . ' font-size:' . round($size * 0.38) . 'px; letter-spacing:.5px; vertical-align:middle; user-select:none;">'
         . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Sidebar / page name for built-in items; editable under System Settings -> Module Access. */
function mh_nav_label($key, $default)
{
    $custom = trim((string) mh_setting('nav_label_' . $key, ''));
    return $custom !== '' ? $custom : $default;
}

/** @return string|null module key from mh_module_registry(), or null */
function mh_department_module($dept)
{
    // 1) The module explicitly chosen for this department under Departments (wins).
    $cfg = mh_department_settings()[strtolower(trim((string) $dept))] ?? null;
    if ($cfg && $cfg['module_key'] !== '') {
        return $cfg['module_key'] === 'none' ? null : $cfg['module_key'];
    }
    // 2) Otherwise guess from the name, as before.
    $d = strtolower((string) $dept);
    $rules = [   // order matters: first match wins (same order the queue always used)
        'checkups'      => ['general', 'consultation', 'check-up', 'checkup'],
        'dental'        => ['dental'],
        'maternal_care' => ['maternal', 'prenatal'],
        'immunization'  => ['immunization', 'child'],
        'inventory'     => ['inventory', 'pharmacy', 'medicine', 'dispens'],
    ];
    foreach ($rules as $module => $words) {
        foreach ($words as $w) { if (strpos($d, $w) !== false) { return $module; } }
    }
    return null;
}

/** Page a queue item should open when staff press "Proceed" (module-based). */
function mh_department_page($dept)
{
    $pages = [
        'checkups' => 'checkups_consultations.php', 'dental' => 'dental.php',
        'maternal_care' => 'maternal_care.php',     'immunization' => 'immunization.php',
        'inventory' => 'dispense_medicine.php',
    ];
    $m = mh_department_module($dept);
    return $m ? $pages[$m] : null;
}

/**
 * Departments a role may see in the Patient Queueing screen.
 * Roles holding the 'queue_all_departments' toggle see everything. Everyone else sees a
 * department only if its module is ON and the role (or a delegated
 * capability) holds that module's feature toggles.
 *
 * @param string[] $candidates every department name to evaluate
 * @return string[]|null null = no restriction
 */
function mh_departments_visible_to($role, array $candidates)
{
    $role = strtolower((string) $role);
    // Toggle "See every department in the queue" (Staff Roles & Permissions) = unrestricted.
    if ($role === 'health_head' || mh_has_permission('queue_all_departments', $role)) { return null; }
    // Everyone else sees a department only if it maps to a module they may open.
    $out = [];
    foreach ($candidates as $dept) {
        $m = mh_department_module($dept);
        if ($m === null) {          // custom department: role setting + per-account exception
            $same = ($role === strtolower((string) ($_SESSION['role'] ?? '')));
            if ($same ? mh_dept_user_can($dept, $role) : mh_dept_role_allowed($dept, $role)) { $out[] = $dept; }
        } elseif (mh_module_visible($m, $role) && !mh_dept_blocked($dept, $role)) { $out[] = $dept; }
    }
    return $out;
}

/**
 * Renders a <select> from a masterlist.
 *
 * @param string $name     input name
 * @param string $listKey  masterlist key
 * @param string $selected currently selected value
 * @param array  $attrs    ['required' => true, 'id' => '...', 'class' => '...', 'placeholder' => '...']
 */
function mh_render_select(mysqli $conn, $name, $listKey, $selected = '', array $attrs = [])
{
    $values      = mh_option_values($conn, $listKey);
    $placeholder = $attrs['placeholder'] ?? '-- Select --';

    $html = '<select name="' . htmlspecialchars($name) . '"';
    if (!empty($attrs['id']))    { $html .= ' id="' . htmlspecialchars($attrs['id']) . '"'; }
    if (!empty($attrs['class'])) { $html .= ' class="' . htmlspecialchars($attrs['class']) . '"'; }
    if (!empty($attrs['style'])) { $html .= ' style="' . htmlspecialchars($attrs['style']) . '"'; }
    if (!empty($attrs['required'])) { $html .= ' required'; }
    $html .= '>';

    $html .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';

    $found = false;
    foreach ($values as $value) {
        $isSel = ((string) $value === (string) $selected);
        if ($isSel) { $found = true; }
        $html .= '<option value="' . htmlspecialchars($value) . '"' . ($isSel ? ' selected' : '') . '>'
               . htmlspecialchars($value) . '</option>';
    }

    // Keep a historical value visible even if it was removed from the list,
    // so editing an old record never silently blanks the field.
    if ($selected !== '' && !$found) {
        $html .= '<option value="' . htmlspecialchars($selected) . '" selected>'
               . htmlspecialchars($selected) . ' (no longer listed)</option>';
    }

    $html .= '</select>';
    return $html;
}

/** Adds an option. Returns [bool, message]. */
function mh_add_option(mysqli $conn, $listKey, $value, $sortOrder = null)
{
    $value = trim($value);
    if ($value === '') {
        return [false, 'Please type a value before adding it.'];
    }
    $len = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($len > 150) {
        return [false, 'That value is too long (maximum 150 characters).'];
    }

    if ($sortOrder === null) {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 AS nxt FROM system_options WHERE list_key = ?");
        $stmt->bind_param('s', $listKey);
        $stmt->execute();
        $sortOrder = (int) ($stmt->get_result()->fetch_assoc()['nxt'] ?? 10);
        $stmt->close();
    }

    $stmt = $conn->prepare(
        "INSERT INTO system_options (list_key, option_value, sort_order, is_active)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE is_active = 1, sort_order = VALUES(sort_order)"
    );
    if (!$stmt) { return [false, 'Database error while adding the option.']; }

    $sortOrder = (int) $sortOrder;
    $stmt->bind_param('ssi', $listKey, $value, $sortOrder);
    $ok = $stmt->execute();
    $stmt->close();
    mh_masterlist_flush();

    return $ok ? [true, 'Added "' . $value . '".'] : [false, 'Could not add that option.'];
}

/** Renames / shows-hides an option by id. ($sortOrder is ignored: lists are alphabetical.) */
function mh_update_option(mysqli $conn, $id, $value, $sortOrder, $isActive)
{
    $value = trim($value);
    if ($value === '') {
        return [false, 'The value cannot be blank.'];
    }

    $stmt = $conn->prepare(
        "UPDATE system_options SET option_value = ?, is_active = ? WHERE id = ?"
    );
    if (!$stmt) { return [false, 'Database error while saving the option.']; }

    $id        = (int) $id;
    $isActive  = $isActive ? 1 : 0;
    $stmt->bind_param('sii', $value, $isActive, $id);
    $ok = $stmt->execute();
    $stmt->close();
    mh_masterlist_flush();

    if (!$ok && $conn->errno === 1062) {
        return [false, 'That value already exists in this list.'];
    }
    return $ok ? [true, 'Option updated.'] : [false, 'Could not update that option.'];
}

/** Permanently removes an option by id. */
function mh_delete_option(mysqli $conn, $id)
{
    $stmt = $conn->prepare("DELETE FROM system_options WHERE id = ?");
    if (!$stmt) { return [false, 'Database error while deleting the option.']; }

    $id = (int) $id;
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $rows = $stmt->affected_rows;
    $stmt->close();
    mh_masterlist_flush();

    if ($ok && $rows > 0) { return [true, 'Option removed.']; }
    return [false, 'That option no longer exists.'];
}

/**
 * Seeds the masterlists with the factory defaults (first install only).
 */
function mh_seed_default_options(mysqli $conn)
{
    $defaults = mh_masterlist_defaults();
    $seed = $defaults;

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO system_options (list_key, option_value, sort_order, is_active) VALUES (?, ?, ?, 1)"
    );
    if (!$stmt) { return; }

    foreach ($seed as $listKey => $values) {
        $order = 0;
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') { continue; }
            $order += 10;
            $stmt->bind_param('ssi', $listKey, $value, $order);
            $stmt->execute();
        }
    }
    $stmt->close();
}

/* =====================================================================
 * 4. MODULE ACCESS CONTROL
 * ================================================================== *
 * Lets an admin turn a whole feature off system-wide (a master on/off
 * switch) and/or restrict which staff roles may open it, without
 * touching code. Two flat keys per module live in `system_settings`,
 * right alongside every other setting:
 *   module_enabled_<key>  -> '1' or '0'
 *   module_roles_<key>    -> comma-separated role list, e.g. "admin,bhw"
 *
 * (No config/module_settings.php snapshot exists any more; read live.)
 *
 * Enforcement:
 *   - includes/admin_sidebar.php calls mh_module_visible() to hide the
 *     nav link for a disabled/restricted module.
 *   - Each module's own page calls mh_check_module_enabled('<key>') right
 *     after require_roles(), so a direct URL hit is blocked too, not just
 *     the nav link.
 *
 * SAFETY NET: the 'admin' role can never be excluded by the per-module
 * role list (see mh_module_allowed_roles() below). Every module still
 * has its own master on/off switch, which *does* apply to admins too -
 * that is the intentional "turn this feature off for everyone" case -
 * but there is no combination of checkboxes that can lock every admin
 * out of a page, since System Settings itself is never gated and can
 * always flip a module back on.
 * ================================================================== */

/**
 * One row per toggleable module: display label, a short hint describing
 * where it lives, the physical page(s) it covers (for reference/docs),
 * and the role set it ships enabled with (chosen to match the access the
 * app already granted via the sidebar, so nothing changes for an
 * existing install until an admin actually edits this tab).
 */
function mh_module_registry()
{
    return [
        'checkups' => [
            'label'         => 'Consultations / Checkups',
            'hint'          => 'Doctor consultation notes and vitals on admin/checkups_consultations.php.',
            'pages'         => ['checkups_consultations.php'],
            'default_roles' => ['admin', 'doctor'],
            'permissions'   => ['manage_consultations'],
        ],
        'dental' => [
            'label'         => 'Dental Records',
            'hint'          => 'Dental charting and visit notes on admin/dental.php.',
            'pages'         => ['dental.php'],
            'default_roles' => ['admin', 'dental'],
            'permissions'   => ['manage_dental_records'],
        ],
        'maternal_care' => [
            'label'         => 'Maternal Care',
            'hint'          => 'Prenatal record cards on admin/maternal_care.php.',
            'pages'         => ['maternal_care.php'],
            'default_roles' => ['admin', 'bhw', 'midwife'],
            'permissions'   => ['manage_maternal_care'],
        ],
        'immunization' => [
            'label'         => 'Child Immunization',
            'hint'          => 'The Child Immunization Form on admin/immunization.php and admin/view_immunization.php.',
            'pages'         => ['immunization.php', 'view_immunization.php'],
            'default_roles' => ['admin', 'bhw', 'midwife'],
            'permissions'   => ['manage_child_immunization'],
        ],
        'inventory' => [
            'label'         => 'Medicine Inventory',
            'hint'          => 'Stock masterlist and dispensing on admin/inventory.php and admin/dispense_medicine.php.',
            'pages'         => ['inventory.php', 'dispense_medicine.php'],
            'default_roles' => ['admin', 'bhw', 'inventory'],
            'permissions'   => ['view_stock', 'add_medicine', 'dispense_medicine', 'view_dispensing_history'],
        ],
        'walkin' => [
            'label'         => 'Walk-In Registration',
            'hint'          => 'Adding a patient to today\'s queue on admin/walkin.php.',
            'pages'         => ['walkin.php'],
            'default_roles' => ['admin', 'doctor', 'bhw', 'midwife', 'dental'],
            'permissions'   => ['walkin_registration'],
        ],
        'patient_masterlist' => [
            'label'         => 'Patient Masterlist & Records',
            'hint'          => 'Patient list and patient profile on admin/patient_masterlist.php and admin/view_patient.php.',
            'pages'         => ['patient_masterlist.php', 'view_patient.php'],
            'default_roles' => ['admin', 'doctor', 'bhw', 'midwife', 'dental'],
            'permissions'   => ['view_patient_masterlist'],
        ],
        'sms_reminders' => [
            'label'         => 'SMS Reminders',
            'hint'          => 'The Semaphore follow-up queue on admin/sms_dashboard.php (turning this off does not stop the queue itself, only staff access to the dashboard).',
            'pages'         => ['sms_dashboard.php'],
            'default_roles' => ['admin'],
        ],
    ];
}

/**
 * Every staff role the app currently issues, for the checkbox matrix.
 * Single source of truth: includes/roles.php (mh_role_definitions()).
 */
function mh_module_all_roles()
{
    return mh_get_module_grid_roles();
}

/* Staff roles + permission toggles now live in includes/roles.php (tables system_roles /
 * role_permissions): mh_get_roles(), mh_add_custom_role(), mh_has_permission(), ... */

/** The module_enabled_* / module_roles_* factory defaults, folded into mh_setting_defaults(). */
function mh_module_defaults()
{
    $out = [];
    foreach (mh_module_registry() as $key => $mod) {
        $out['module_enabled_' . $key] = '1';
        $out['module_roles_' . $key]   = implode(',', $mod['default_roles']);
    }
    return $out;
}

/** Whether a module's master switch is on. Unknown keys are never blocked. */
function mh_module_is_enabled($key)
{
    if (!array_key_exists($key, mh_module_registry())) {
        return true;
    }
    return mh_setting('module_enabled_' . $key, '1') === '1';
}

/**
 * The roles allowed into a module, always including 'admin' regardless
 * of what was saved (see the safety-net note above the registry).
 */
function mh_module_allowed_roles($key)
{
    $registry = mh_module_registry();
    if (!array_key_exists($key, $registry)) {
        return array_keys(mh_module_all_roles());
    }

    // Database-driven: a role may open the module when it holds ANY of the module's
    // feature toggles (System Settings -> Staff Roles & Permissions). health_head always.
    $perms = $registry[$key]['permissions'] ?? [];
    if ($perms && mh_roles_db_ready()) {
        $roles = mh_roles_with_permission($perms);
        if (!in_array('health_head', $roles, true)) { $roles[] = 'health_head'; }
        return array_values(array_unique($roles));
    }

    // Fallback (tables not installed yet, or a module with no toggles such as SMS Reminders):
    // the saved / default role list, as before.
    $csv   = mh_setting('module_roles_' . $key, implode(',', $registry[$key]['default_roles']));
    $roles = array_values(array_filter(array_map('trim', explode(',', $csv)), function ($r) { return $r !== ''; }));
    foreach (['admin', 'health_head'] as $tier) {
        if (!in_array($tier, $roles, true)) { $roles[] = $tier; }
    }
    return $roles;
}

/** True when $role may open $key today (module on AND role permitted). */
function mh_module_visible($key, $role)
{
    if (!mh_module_is_enabled($key)) {
        return false; // master switch off - blocks every role, admin included
    }
    // Signed-in user + a module made of Delegated Functions (inventory, consultations, immunization,
    // maternal, dental): the EFFECTIVE per-account capabilities decide, so an unticked function
    // (explicit deny) removes access even when the role would otherwise grant it.
    require_once __DIR__ . '/auth.php';
    $modCaps = mh_capabilities_for_module($key);
    if ($modCaps && (string) $role === mh_current_role()) {
        return mh_has_any_capability($modCaps);
    }
    if (in_array((string) $role, mh_module_allowed_roles($key), true)) {
        return true;
    }
    // Delegated access: a staff member holding ANY of the module's granular
    // flags (e.g. can_log_dental or can_view_dental_history) may open the
    // module even though their primary role is not on its role list; each
    // page then enforces the specific sub-function. The master on/off
    // switch above still applies.
    // (auth.php is idempotent; the OCR/JSON endpoints don't load it otherwise.)
    require_once __DIR__ . '/auth.php';
    $caps = mh_capabilities_for_module($key);
    return $caps && mh_has_any_capability($caps);
}

/**
 * Page guard: call this right after require_roles() at the top of a
 * module's page (or its AJAX/OCR endpoints). Prints a friendly notice
 * and exits when the module is off or this session's role isn't
 * permitted; otherwise it returns silently and the page continues.
 */
function mh_check_module_enabled($key)
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    // The in-process settings cache (mh_all_settings()) is only populated
    // once something calls it with a live $conn. Most module pages load
    // config/database.php before this guard runs but never otherwise
    // touch settings_helper.php, so without this the guard would silently
    // check against factory defaults instead of what the admin actually
    // saved. Cheap: mh_all_settings() only queries once per request and
    // caches the result for every later mh_setting()/mh_module_*() call
    // (including includes/admin_sidebar.php's, further down the page).
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        mh_all_settings($conn);
    }

    $role = $_SESSION['role'] ?? '';

    if (mh_module_visible($key, $role)) {
        return;
    }

    $registry = mh_module_registry();
    $label    = $registry[$key]['label'] ?? $key;
    $enabled  = mh_module_is_enabled($key);

    http_response_code(403);
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Module Unavailable</title></head><body>";
    echo "<div style='padding:60px 20px;text-align:center;font-family:sans-serif;color:#334155;'>";
    echo "<h2 style='color:#1e293b;'>Module Unavailable</h2>";
    if (!$enabled) {
        echo "<p>" . htmlspecialchars($label) . " has been turned off in System Settings. Ask an administrator to re-enable it under System Settings &rarr; Module Access.</p>";
    } else {
        echo "<p>" . htmlspecialchars($label) . " is not available to your account's role. Ask an administrator to grant access under System Settings &rarr; Module Access.</p>";
    }
    echo "<a href='dashboard.php'>Return to Dashboard</a>";
    echo "</div></body></html>";
    exit();
}

/**
 * Same guard, JSON-flavoured, for AJAX/OCR endpoints that respond with
 * `Content-Type: application/json` and can't render an HTML notice.
 * Returns true (and has already emitted the JSON response + exited) when
 * blocked, so callers only need: `if (mh_check_module_enabled_json('x')) exit;`
 * - though since this function itself calls exit(), the `if` is defensive
 * only and never actually needed by a correct caller.
 */
function mh_check_module_enabled_json($key)
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        mh_all_settings($conn);
    }

    $role = $_SESSION['role'] ?? '';

    if (mh_module_visible($key, $role)) {
        return false;
    }

    $registry = mh_module_registry();
    $label    = $registry[$key]['label'] ?? $key;
    $enabled  = mh_module_is_enabled($key);

    $message = !$enabled
        ? $label . ' has been turned off in System Settings.'
        : $label . ' is not available to your account\'s role.';

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message, 'message' => $message]);
    exit();
}

/**
 * Guard for the OCR scan endpoints (admin/ocr/*.php). Two independent checks:
 *   1. the module's master switch is ON (System Settings -> Module Access), and
 *   2. the caller holds the 'use_ocr_scanner' feature toggle (Staff Roles & Permissions).
 * Responds with a JSON 403 and exits when either fails. The role does NOT need access to the
 * clinical module itself - the toggle alone is what permits scanning.
 */
function mh_require_ocr_access_json($moduleKey)
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    require_once __DIR__ . '/auth.php';
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) { mh_all_settings($conn); }

    if (!mh_module_is_enabled($moduleKey)) {
        $label = mh_module_registry()[$moduleKey]['label'] ?? $moduleKey;
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $label . ' has been turned off in System Settings.']);
        exit();
    }
    require_permission('use_ocr_scanner', true);   // JSON 403 via mh_deny_access(true)
}

/* =====================================================================
 * 5. THEME CSS GENERATION
 * ================================================================== */

/** Validates a #rrggbb string, returning $fallback when it is not one. */
function mh_sanitize_color($value, $fallback = '#000000')
{
    $value = trim((string) $value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtolower($value);
    }
    if (preg_match('/^#[0-9a-fA-F]{3}$/', $value)) {
        return strtolower('#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3]);
    }
    return $fallback;
}

/** #rrggbb -> "r, g, b" so the CSS can build rgba() shadows. */
function mh_hex_to_rgb_triplet($hex)
{
    $hex = ltrim(mh_sanitize_color($hex), '#');
    return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
}

/**
 * Builds the CSS custom-property block plus the override rules that make
 * the existing hard-coded styles actually follow those variables.
 */
function mh_build_theme_css(array $settings = null)
{
    $defaults = mh_theme_defaults();
    $s = [];
    foreach ($defaults as $key => $default) {
        $raw = $settings[$key] ?? mh_setting($key, $default);
        $s[$key] = mh_sanitize_color($raw, $default);
    }

    $primaryRgb = mh_hex_to_rgb_triplet($s['theme_primary']);
    $stamp      = date('Y-m-d H:i:s');

    $css  = "/* Generated by admin/system_settings.php on {$stamp}. Edits here are overwritten. */\n";
    $css .= ":root {\n";
    $css .= "    --primary: {$s['theme_primary']};\n";
    $css .= "    --primary-color: {$s['theme_primary']};\n";
    $css .= "    --primary-hover: {$s['theme_primary_hover']};\n";
    $css .= "    --primary-rgb: {$primaryRgb};\n";
    $css .= "    --accent-color: {$s['theme_accent']};\n";
    $css .= "    --link-color: {$s['theme_link']};\n";
    $css .= "    --slate-bg: {$s['theme_sidebar_bg']};\n";
    $css .= "    --sidebar-bg: {$s['theme_sidebar_bg']};\n";
    $css .= "    --sidebar-hover: {$s['theme_sidebar_hover']};\n";
    $css .= "    --sidebar-text: {$s['theme_sidebar_text']};\n";
    $css .= "    --page-bg: {$s['theme_page_bg']};\n";
    $css .= "    --panel-bg: {$s['theme_panel_bg']};\n";
    $css .= "    --text-color: {$s['theme_body_text']};\n";
    $css .= "    --heading-color: {$s['theme_heading_text']};\n";
    $css .= "    --border-color: {$s['theme_border']};\n";
    $css .= "    --button-bg: {$s['theme_button_bg']};\n";
    $css .= "    --button-text: {$s['theme_button_text']};\n";
    $css .= "}\n\n";

    // The existing stylesheets hard-code most of these colours, so bind
    // the real selectors to the variables here.
    $css .= "body { background-color: var(--page-bg); color: var(--text-color); }\n";
    $css .= "h1, h2, h3, h4, h5, h6 { color: var(--heading-color); }\n";
    $css .= ".sidebar { background-color: var(--sidebar-bg) !important; }\n";
    $css .= ".sidebar-header { border-bottom-color: var(--sidebar-hover) !important; }\n";
    $css .= ".sidebar-header h2 { color: var(--accent-color) !important; }\n";
    $css .= ".sidebar-nav li a, .sidebar-footer a { color: var(--sidebar-text) !important; }\n";
    $css .= ".sidebar-nav li a:hover, .sidebar-nav li a.active {\n";
    $css .= "    background-color: var(--sidebar-hover) !important;\n";
    $css .= "    color: #ffffff !important;\n";
    $css .= "    border-left-color: var(--accent-color) !important;\n";
    $css .= "}\n";
    $css .= ".sidebar-submenu { background: var(--sidebar-bg) !important; }\n";
    $css .= ".sidebar-submenu li a:hover, .sidebar-submenu li a.active { background: var(--sidebar-hover) !important; }\n";
    $css .= ".unified-panel, .stat-card, .dashboard-card { background: var(--panel-bg); border-color: var(--border-color); }\n";
    $css .= ".unified-table th { color: var(--heading-color); }\n";
    $css .= ".unified-table td, .unified-table th { border-color: var(--border-color); }\n";
    $css .= "a { color: var(--link-color); }\n";
    $css .= ".btn-submit, .btn-primary, button[type=\"submit\"].btn-submit {\n";
    $css .= "    background-color: var(--button-bg);\n";
    $css .= "    border-color: var(--button-bg);\n";
    $css .= "    color: var(--button-text);\n";
    $css .= "}\n";
    $css .= ".btn-submit:hover, .btn-primary:hover { background-color: var(--primary-hover); border-color: var(--primary-hover); }\n";
    $css .= ".navbar .nav-brand h1 { color: var(--heading-color); }\n";
    $css .= ".dropbtn { border-color: var(--primary); color: var(--primary); }\n";

    return $css;
}

/**
 * Writes the generated CSS into a managed block at the end of
 * assets/css/admin_theme.css and assets/css/style.css (and as a
 * standalone assets/css/theme_vars.css for anyone who prefers a <link>).
 *
 * The block sits between markers, so the hand-written CSS above it is
 * never touched and re-saving simply replaces the block.
 *
 * @return array{written:array, failed:array}
 */
function mh_write_theme_css($css)
{
    $begin = '/* ===== MARICK THEME VARIABLES - AUTO-GENERATED, DO NOT EDIT BELOW ===== */';
    $end   = '/* ===== END MARICK THEME VARIABLES ===== */';
    $block = "\n\n" . $begin . "\n" . $css . $end . "\n";

    $targets = [
        MH_ROOT . '/assets/css/admin_theme.css',
        MH_ROOT . '/assets/css/style.css',
    ];

    $written = [];
    $failed  = [];

    foreach ($targets as $file) {
        if (!is_file($file)) { $failed[] = basename($file) . ' (missing)'; continue; }

        $existing = file_get_contents($file);
        if ($existing === false) { $failed[] = basename($file) . ' (unreadable)'; continue; }

        $startPos = strpos($existing, $begin);
        if ($startPos !== false) {
            $endPos = strpos($existing, $end, $startPos);
            $base   = ($endPos !== false)
                ? rtrim(substr($existing, 0, $startPos)) . substr($existing, $endPos + strlen($end))
                : rtrim(substr($existing, 0, $startPos));
        } else {
            $base = $existing;
        }

        $result = rtrim($base) . $block;

        if (@file_put_contents($file, $result) === false) {
            $failed[] = basename($file) . ' (not writable - chmod 664)';
        } else {
            $written[] = basename($file);
        }
    }

    // Standalone copy, handy for <link rel="stylesheet" href="theme_vars.css">
    @file_put_contents(MH_ROOT . '/assets/css/theme_vars.css', $css);

    return ['written' => $written, 'failed' => $failed];
}

/**
 * Inline <style> block. Drop this into any page's <head> when you want
 * the theme to apply immediately, without waiting on browser CSS cache.
 */
function mh_theme_style_tag()
{
    return "<style id=\"mh-theme-vars\">\n" . mh_build_theme_css() . "</style>\n";
}

/* =====================================================================
 * 6. RUNTIME CONFIGURATION (credentials + branding) - DATABASE ONLY
 * ================================================================== */

/**
 * Records a missing / invalid configuration value WITHOUT breaking the page
 * and WITHOUT ever writing the value itself (only the key name) anywhere.
 * Goes to the PHP error log and, de-duplicated to once per hour per message,
 * to the System Logs (activity_logs, action "Config Warning").
 */
function mh_log_config_error($key, $context = '')
{
    static $seen = [];
    $msg = 'Missing or invalid configuration: ' . preg_replace('/[^A-Za-z0-9_.\- ]/', '', (string) $key)
         . ($context !== '' ? ' (' . preg_replace('/[^A-Za-z0-9_.,\- ]/', '', (string) $context) . ')' : '');
    if (isset($seen[$msg])) { return; }
    $seen[$msg] = true;

    @error_log('[MarickHealth] ' . $msg);

    $conn = $GLOBALS['conn'] ?? null;
    if (!($conn instanceof mysqli)) { return; }
    try {
        $chk = $conn->prepare(
            "SELECT 1 FROM activity_logs
              WHERE action = 'Config Warning' AND details = ?
                AND created_at > (NOW() - INTERVAL 1 HOUR) LIMIT 1"
        );
        if ($chk) {
            $chk->bind_param('s', $msg);
            $chk->execute();
            $chk->store_result();
            $already = $chk->num_rows > 0;
            $chk->close();
            if ($already) { return; }
        }
        $ins = $conn->prepare(
            "INSERT INTO activity_logs (user_id, user_role, action, details, ip_address)
             VALUES (0, 'system', 'Config Warning', ?, NULL)"
        );
        if ($ins) {
            $ins->bind_param('s', $msg);
            $ins->execute();
            $ins->close();
        }
    } catch (Throwable $e) {
        // Logging must never take the page down.
    }
}

/**
 * Reads one configuration value (API key, URL, flag) from the database.
 * Same store as mh_setting(); this is the accessor for CREDENTIALS because it
 * trims, treats blank as "not configured", and logs when a required value is
 * missing (key name only, never the value).
 *
 * @param bool $required Log a system-log warning when blank.
 */
function mh_get_option($key, $default = '', $required = false)
{
    $val = '';
    try {
        $val = trim((string) mh_setting($key, ''));
    } catch (Throwable $e) {
        $val = '';
    }
    if ($val === '') {
        if ($required) { mh_log_config_error($key); }
        return $default;
    }
    return $val;
}

/**
 * Display-safe hint for a stored secret: never the value, only that one exists
 * and its last 4 characters. Used as the form placeholder instead of echoing keys.
 */
function mh_mask_secret($value)
{
    $value = (string) $value;
    if ($value === '') { return 'Not set - paste the key here'; }
    return '******** saved (ends in ' . substr($value, -4) . ') - leave blank to keep';
}

/**
 * Populates the handful of legacy page variables ($system_name, ...) from the
 * database for the current request. Replaces the old generated
 * config/settings.php. Uses $GLOBALS so it works from any include scope.
 */
function mh_export_branding_globals()
{
    $map = [
        'system_name'         => 'system_name',
        'header_title'        => 'header_title',
        'header_tagline'      => 'header_tagline',
        'hero_title'          => 'hero_title',
        'hero_subtitle'       => 'hero_subtitle',
        'global_announcement' => 'global_announcement',
        'logo_path'           => 'system_logo',
        'background_path'     => 'system_background',
    ];
    foreach ($map as $settingKey => $var) {
        $GLOBALS[$var] = (string) mh_setting($settingKey, '');
    }
    $GLOBALS['enable_online_booking'] = (int) mh_setting('enable_online_booking', '1');
}

/* =====================================================================
 * 7. IMAGE UPLOADS (logo / background)
 * ================================================================== */

/**
 * Handles one branding image upload.
 *
 * @param string $field     $_FILES key
 * @param string $relTarget e.g. 'assets/images/logo.png'
 * @return array{ok:bool, message:string, path?:string}
 */
function mh_handle_image_upload($field, $relTarget, array $allowedExt = ['png', 'jpg', 'jpeg', 'webp'])
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'message' => '']; // nothing chosen - not an error
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed (PHP error code ' . $_FILES[$field]['error']
            . '). The file may be larger than upload_max_filesize.'];
    }

    $tmp = $_FILES[$field]['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'The uploaded file could not be verified.'];
    }

    if (filesize($tmp) > 5 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Image is too large. Please use a file under 5 MB.'];
    }

    // Verify it really is an image, not a renamed script.
    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'message' => 'That file is not a valid image.'];
    }

    $typeToExt = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
    ];
    $realExt = $typeToExt[$info[2]] ?? '';
    if ($realExt === '' || !in_array($realExt, $allowedExt, true)) {
        return ['ok' => false, 'message' => 'Unsupported image format. Allowed: ' . strtoupper(implode(', ', $allowedExt)) . '.'];
    }

    // Keep the canonical filename the rest of the app already points at,
    // so no other page needs editing. Convert to PNG when required.
    $targetExt  = strtolower(pathinfo($relTarget, PATHINFO_EXTENSION));
    $targetFull = MH_ROOT . '/' . $relTarget;

    $dir = dirname($targetFull);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'message' => 'Could not create the images folder.'];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'message' => 'The folder ' . dirname($relTarget) . ' is not writable. Run: chmod 775 ' . dirname($relTarget)];
    }

    if ($realExt === $targetExt || ($realExt === 'jpg' && $targetExt === 'jpeg')) {
        if (!@move_uploaded_file($tmp, $targetFull)) {
            return ['ok' => false, 'message' => 'Could not save the image to ' . $relTarget . '.'];
        }
    } else {
        // Re-encode into the extension the app expects (usually .png).
        if (!function_exists('imagecreatefromjpeg')) {
            return ['ok' => false, 'message' => 'The PHP GD extension is not enabled, so images cannot be converted. '
                . 'Please upload a ' . strtoupper($targetExt) . ' file, or enable php_gd in php.ini.'];
        }

        $src = null;
        if ($realExt === 'jpg')       { $src = @imagecreatefromjpeg($tmp); }
        elseif ($realExt === 'webp')  { $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : null; }
        elseif ($realExt === 'gif')   { $src = @imagecreatefromgif($tmp); }
        elseif ($realExt === 'png')   { $src = @imagecreatefrompng($tmp); }

        if (!$src) {
            return ['ok' => false, 'message' => 'Could not convert that image to ' . strtoupper($targetExt)
                . '. Please upload a ' . strtoupper($targetExt) . ' file instead.'];
        }

        if (function_exists('imagepalettetotruecolor')) { imagepalettetotruecolor($src); }
        imagealphablending($src, false);
        imagesavealpha($src, true);

        $saved = ($targetExt === 'png') ? @imagepng($src, $targetFull) : @imagejpeg($src, $targetFull, 90);
        imagedestroy($src);

        if (!$saved) {
            return ['ok' => false, 'message' => 'Could not write the converted image to ' . $relTarget . '.'];
        }
    }

    @chmod($targetFull, 0644);

    return ['ok' => true, 'message' => 'Image updated.', 'path' => $relTarget];
}

/* =====================================================================
 * 8. CSRF
 * ================================================================== */

function mh_csrf_token()
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (empty($_SESSION['mh_csrf'])) {
        $_SESSION['mh_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mh_csrf'];
}

function mh_csrf_check()
{
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $sent = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['mh_csrf']) && is_string($sent) && hash_equals($_SESSION['mh_csrf'], $sent);
}

function mh_csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(mh_csrf_token()) . '">';
}
}