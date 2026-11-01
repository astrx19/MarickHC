<?php
/**
 * Helpers for the free-text "patient name" inputs on the Consultation,
 * Dental, Maternal Care and Child Immunization forms.
 *
 * The typed name is ALWAYS stored in <table>.patient_name. As a courtesy
 * we also try to link the record to a registered patient so the Patient
 * Portal history and SMS follow-up reminders keep working - but ONLY
 * when exactly one active patient matches the typed name. Two patients
 * called "Juan Dela Cruz" never get auto-linked (a clinical record must
 * not silently attach to the wrong person); the record is saved by name
 * with patient_id = NULL instead.
 *
 * Set MH_AUTOLINK_PATIENT_NAMES to false (before including this file) to
 * store names only and never link.
 */
if (!defined('MH_AUTOLINK_PATIENT_NAMES')) {
    define('MH_AUTOLINK_PATIENT_NAMES', true);
}

if (!function_exists('mh_clean_patient_name')) {
    /** Trim, collapse whitespace, drop control chars, cap at 150 chars. */
    function mh_clean_patient_name($raw)
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $raw);
        $name = preg_replace('/\s+/u', ' ', $name);
        return mb_substr(trim($name), 0, 150);
    }
}

if (!function_exists('mh_validate_patient_name')) {
    /** @return string|null  error message, or null when the name is OK. */
    function mh_validate_patient_name($name)
    {
        if ($name === '' || mb_strlen($name) < 2) {
            return 'Please enter the patient\'s full name.';
        }
        if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\s.\'\-,]*$/u', $name)) {
            return 'Patient name may only contain letters, spaces, periods, commas, hyphens and apostrophes.';
        }
        return null;
    }
}

if (!function_exists('mh_find_patient_id_by_name')) {
    /**
     * @return int|null  the patient id when EXACTLY ONE active patient
     *                   matches (First Last / First Middle Last / Last First /
     *                   Last First Middle, ignoring case, commas and periods).
     */
    function mh_find_patient_id_by_name(mysqli $conn, $name)
    {
        if (!MH_AUTOLINK_PATIENT_NAMES) { return null; }

        $norm = mb_strtolower(preg_replace('/\s+/u', ' ', str_replace([',', '.'], ' ', (string) $name)));
        $norm = trim($norm);
        if ($norm === '') { return null; }

        $n = function ($expr) {
            return "REPLACE(REPLACE(LOWER($expr), ',', ' '), '.', ' ')";
        };
        $f = 'first_name'; $m = "NULLIF(middle_name,'')"; $l = 'last_name';
        $sql = "SELECT id FROM patients WHERE status = 1 AND ("
             . $n("CONCAT_WS(' ', $f, $l)")     . " = ? OR "
             . $n("CONCAT_WS(' ', $f, $m, $l)") . " = ? OR "
             . $n("CONCAT_WS(' ', $l, $f)")     . " = ? OR "
             . $n("CONCAT_WS(' ', $l, $f, $m)") . " = ?) LIMIT 2";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { return null; }
        $stmt->bind_param('ssss', $norm, $norm, $norm, $norm);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }
}

if (!function_exists('mh_patient_full_name_by_id')) {
    /** "First Middle Last" for pre-filling the text input from ?walkin_id= / ?patient_id=. */
    function mh_patient_full_name_by_id(mysqli $conn, $id)
    {
        $id = (int) $id;
        if ($id <= 0) { return ''; }
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM patients WHERE id = ? LIMIT 1");
        if (!$stmt) { return ''; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$p) { return ''; }
        return mh_clean_patient_name(trim($p['first_name'] . ' ' . ($p['middle_name'] ?? '') . ' ' . $p['last_name']));
    }
}

if (!function_exists('mh_record_patient_label')) {
    /**
     * Display name for a record row fetched with LEFT JOIN patients p:
     * the registered patient's formatted name when linked, otherwise the
     * typed patient_name stored on the record.
     */
    function mh_record_patient_label(array $row)
    {
        if (!empty($row['last_name']) || !empty($row['first_name'])) {
            return mh_format_patient_name($row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? '');
        }
        $typed = trim((string) ($row['patient_name'] ?? ''));
        return $typed !== '' ? $typed : 'Unknown patient';
    }
}

if (!function_exists('mh_read_patient_name_post')) {
    /**
     * Reads the Last Name / First Name / MI inputs.
     * @return array{name:string,last:string,first:string,mi:string,error:?string}
     *   name = "Last, First M." (what is stored in <table>.patient_name)
     */
    function mh_read_patient_name_post()
    {
        $part = function ($k) { return mh_clean_patient_name($_POST[$k] ?? ''); };
        $last  = mb_substr($part('last_name'), 0, 60);
        $first = mb_substr($part('first_name'), 0, 60);
        $mi    = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', (string) ($_POST['middle_initial'] ?? '')), 0, 1));

        $error = null;
        $ok = '/^[\p{L}\p{M}][\p{L}\p{M}\s.\'\-]*$/u';
        if ($last === '' || $first === '') {
            $error = 'Please enter the patient\'s last name and first name.';
        } elseif (!preg_match($ok, $last) || !preg_match($ok, $first)) {
            $error = 'Names may only contain letters, spaces, periods, hyphens and apostrophes.';
        }
        $name = $last . ', ' . $first . ($mi !== '' ? ' ' . $mi . '.' : '');
        return ['name' => $name, 'last' => $last, 'first' => $first, 'mi' => $mi, 'error' => $error];
    }
}

if (!function_exists('mh_find_patient_id_by_parts')) {
    /** The patient id when EXACTLY ONE active patient has this last name + first name (+ middle initial when given). */
    function mh_find_patient_id_by_parts(mysqli $conn, $last, $first, $mi = '')
    {
        if (!MH_AUTOLINK_PATIENT_NAMES) { return null; }
        $norm = function ($v) { return trim(preg_replace('/\s+/u', ' ', str_replace(['.', ','], '', mb_strtolower((string) $v)))); };
        $l = $norm($last); $f = $norm($first); $m = mb_strtolower(trim((string) $mi));
        if ($l === '' || $f === '') { return null; }

        $col = function ($c) { return "REPLACE(REPLACE(LOWER(TRIM($c)),'.',''),',','')"; };
        $sql = "SELECT id FROM patients WHERE status = 1 AND " . $col('last_name') . " = ? AND " . $col('first_name') . " = ?";
        if ($m !== '') { $sql .= " AND (middle_name IS NULL OR middle_name = '' OR LOWER(LEFT(TRIM(middle_name), 1)) = ?)"; }
        $sql .= " LIMIT 2";

        $stmt = $conn->prepare($sql);
        if (!$stmt) { return null; }
        if ($m !== '') { $stmt->bind_param('sss', $l, $f, $m); } else { $stmt->bind_param('ss', $l, $f); }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }
}

if (!function_exists('mh_patient_name_parts_by_id')) {
    /** @return array{last:string,first:string,mi:string} for pre-filling the name boxes from ?walkin_id= */
    function mh_patient_name_parts_by_id(mysqli $conn, $id)
    {
        $out = ['last' => '', 'first' => '', 'mi' => ''];
        $id = (int) $id;
        if ($id <= 0) { return $out; }
        $stmt = $conn->prepare("SELECT first_name, middle_name, last_name FROM patients WHERE id = ? LIMIT 1");
        if (!$stmt) { return $out; }
        $stmt->bind_param('i', $id); $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$p) { return $out; }
        return ['last' => (string) $p['last_name'], 'first' => (string) $p['first_name'],
                'mi' => mb_strtoupper(mb_substr(trim((string) ($p['middle_name'] ?? '')), 0, 1))];
    }
}

if (!function_exists('mh_render_patient_name_fields')) {
    /**
     * Last Name / First Name / MI inputs (ids pnLast, pnFirst, pnMI; names last_name, first_name, middle_initial).
     * $label e.g. "Patient Name", $inputStyle = inline CSS for the inputs ('' = inherit the form's own styling).
     * Also defines window.mhSetPatientName(first, middle, last) used by the OCR autofill.
     */
    function mh_render_patient_name_fields($label, array $prefill = [], $inputStyle = '')
    {
        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $letters = "this.value = this.value.replace(/[^\\p{L}\\p{M}\\s.\\-',]/gu, '')";
        $st = $inputStyle !== '' ? ' style="' . $e($inputStyle) . '"' : '';
        ?>
        <label><?php echo $e($label); ?> <span style="color:red;">*</span></label>

        <?php /* Step 6: pick an already-registered patient instead of retyping the name. */ ?>
        <div class="mh-ps" data-url="api/patient_search.php">
            <input type="search" class="mh-ps-input" placeholder="Search registered patients by name..." autocomplete="off" role="combobox" aria-expanded="false" aria-label="Search registered patients">
            <ul class="mh-ps-list" role="listbox" hidden></ul>
            <div class="mh-ps-badge" hidden></div>
            <input type="hidden" name="picked_patient_id" id="pnPickedId" value="">
        </div>

        <div style="display:grid; grid-template-columns: 2fr 2fr 0.7fr; gap:10px;">
            <div><span class="mh-name-sub" style="display:block; font-size:0.75rem; opacity:.75; margin-bottom:3px;">Last Name</span>
                <input type="text" name="last_name" id="pnLast" placeholder="Last name" required maxlength="60" autocomplete="off" value="<?php echo $e($prefill['last'] ?? ''); ?>"<?php echo $st; ?> oninput="<?php echo $e($letters); ?>"></div>
            <div><span class="mh-name-sub" style="display:block; font-size:0.75rem; opacity:.75; margin-bottom:3px;">First Name</span>
                <input type="text" name="first_name" id="pnFirst" placeholder="First name" required maxlength="60" autocomplete="off" value="<?php echo $e($prefill['first'] ?? ''); ?>"<?php echo $st; ?> oninput="<?php echo $e($letters); ?>"></div>
            <div><span class="mh-name-sub" style="display:block; font-size:0.75rem; opacity:.75; margin-bottom:3px;">MI</span>
                <input type="text" name="middle_initial" id="pnMI" placeholder="M" maxlength="1" autocomplete="off" value="<?php echo $e($prefill['mi'] ?? ''); ?>"<?php echo $st; ?> oninput="this.value = this.value.replace(/[^\p{L}]/gu, '').toUpperCase()"></div>
        </div>
        <script>
            window.mhSetPatientName = function (first, middle, last) {
                var set = function (id, v) { var el = document.getElementById(id); if (el) { el.value = v || ''; } };
                set('pnLast', last); set('pnFirst', first);
                set('pnMI', (middle || '').trim().charAt(0).toUpperCase());
            };
        </script>
        <script src="../assets/js/mh_patient_search.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/mh_patient_search.js'); ?>" defer></script>
        <?php
    }
}

if (!function_exists('mh_ensure_patient_name_columns')) {
    /**
     * The clinical forms store the typed name in <table>.patient_name and allow patient_id to be NULL
     * (database/rbac_and_names_migration.sql, part 3). If that migration was never run, add the columns
     * here so saving works instead of failing with "Unknown column 'patient_name'".
     */
    function mh_ensure_patient_name_columns(mysqli $conn)
    {
        if (!empty($_SESSION['mh_pn_cols_ok'])) { return; }
        $allOk = true;
        foreach (['consultations', 'maternal_records', 'child_immunization_records', 'dental_records'] as $t) {
            $res = @$conn->query("SHOW COLUMNS FROM `$t` LIKE 'patient_name'");   // fixed table names
            if (!$res) { $allOk = false; continue; }
            if ($res->num_rows === 0) {
                $ok = @$conn->query("ALTER TABLE `$t` MODIFY COLUMN `patient_id` INT NULL, ADD COLUMN `patient_name` VARCHAR(150) NULL AFTER `patient_id`");
                if (!$ok) { error_log("mh_ensure_patient_name_columns($t): " . $conn->error); $allOk = false; }
                continue;
            }
            $pid = @$conn->query("SHOW COLUMNS FROM `$t` LIKE 'patient_id'");
            $row = $pid ? $pid->fetch_assoc() : null;
            if ($row && strtoupper((string) $row['Null']) === 'NO') {
                if (!@$conn->query("ALTER TABLE `$t` MODIFY COLUMN `patient_id` INT NULL")) { $allOk = false; }
            }
        }
        if ($allOk) { $_SESSION['mh_pn_cols_ok'] = true; }
    }
}

