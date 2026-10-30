<?php
/**
 * System Settings -> Departments tab (Health Head only; the page itself enforces require_health_head()).
 * Departments live in the `clinic_departments` masterlist; this adds the "function" each one performs
 * (department_settings) and keeps the sidebar, queue and walk-in registration in sync.
 */
require_once __DIR__ . '/settings_helper.php';

const MH_DEPT_LIST = 'clinic_departments';

/** Stores which roles may use a department. Matching the defaults = nothing stored (follows module access). */
function mh_dept_save_roles(mysqli $conn, string $dept, array $postedRoles)
{
    mh_department_settings($conn, true);   // use the function just saved when working out the defaults
    $roles = array_keys(array_filter(mh_role_definitions(), function ($d, $rk) { return $rk !== 'health_head'; }, ARRAY_FILTER_USE_BOTH));

    $matches_defaults = true;
    foreach ($roles as $rk) {
        if (in_array($rk, $postedRoles, true) !== (bool) mh_dept_role_default($dept, $rk)) { $matches_defaults = false; break; }
    }

    $st = $conn->prepare("DELETE FROM department_access WHERE dept_name = ?");
    $st->bind_param('s', $dept); $st->execute(); $st->close();
    if (!$matches_defaults) {
        $ins = $conn->prepare("INSERT INTO department_access (dept_name, role_key, allowed) VALUES (?, ?, ?)");
        foreach ($roles as $rk) {
            $allowed = in_array($rk, $postedRoles, true) ? 1 : 0;
            $ins->bind_param('ssi', $dept, $rk, $allowed);
            $ins->execute();
        }
        $ins->close();
    }
    unset($GLOBALS['mh_dept_role_cache']);
}

/** Toggle chips: which roles can use the department. $dept === '' = defaults for a new department. */
function mh_dept_role_pills(string $dept)
{
    echo '<div class="dpt-chips">';
    foreach (mh_role_definitions() as $rk => $def) {
        if ($rk === 'health_head') { continue; }
        $on = ($dept === '') ? mh_has_permission('manage_patient_queue', $rk) : mh_dept_role_allowed($dept, $rk);
        echo '<label class="dpt-chip"><input type="checkbox" name="dept_roles[]" value="' . htmlspecialchars($rk, ENT_QUOTES) . '"' . ($on ? ' checked' : '') . '>'
           . '<span>' . htmlspecialchars($def['label']) . '</span></label>';
    }
    echo '</div>';
}

/** Handles add / edit / delete. Always ends with a redirect (mh_redirect is defined by system_settings.php). */
function mh_depts_handle_post(mysqli $conn, int $user_id, string $user_role)
{
    mh_department_ensure_table($conn);
    $choices = mh_department_module_choices();

    $name = trim((string) ($_POST['dept_name'] ?? ''));
    $mod  = (string) ($_POST['module_key'] ?? '');
    $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
    if (!array_key_exists($mod, $choices)) { $mod = ''; }
    $nameLen = mb_strlen($name);

    /* ---------- ADD ---------- */
    if (isset($_POST['add_department'])) {
        if ($name === '' || $nameLen > 100) { mh_redirect('departments', 'error', 'Enter a department name (up to 100 characters).'); }
        [$ok, $msg] = mh_add_option($conn, MH_DEPT_LIST, $name);
        if (!$ok) { mh_redirect('departments', 'error', $msg); }
        $st = $conn->prepare("INSERT INTO department_settings (dept_name, module_key, description) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE module_key = VALUES(module_key), description = VALUES(description)");
        $st->bind_param('sss', $name, $mod, $desc); $st->execute(); $st->close();
        if (!empty($_POST['dept_roles_present'])) {
            mh_dept_save_roles($conn, $name, isset($_POST['dept_roles']) && is_array($_POST['dept_roles']) ? array_map('strval', $_POST['dept_roles']) : []);
        }
        logActivity($conn, $user_id, $user_role, 'Department Added', "Added department \"$name\".");
        mh_redirect('departments', 'success', "Department \"$name\" added. It now appears in the sidebar, walk-in registration and Patient Queue.");
    }

    /* ---------- EDIT / RENAME / HIDE ---------- */
    if (isset($_POST['edit_department'])) {
        $opt_id = (int) ($_POST['option_id'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '' || $nameLen > 100) { mh_redirect('departments', 'error', 'Enter a department name (up to 100 characters).'); }

        $st = $conn->prepare("SELECT option_value FROM system_options WHERE id = ? AND list_key = ?");
        $lk = MH_DEPT_LIST;
        $st->bind_param('is', $opt_id, $lk); $st->execute();
        $old = $st->get_result()->fetch_assoc(); $st->close();
        if (!$old) { mh_redirect('departments', 'error', 'That department no longer exists.'); }
        $old_name = $old['option_value'];

        $conn->begin_transaction();
        $st = $conn->prepare("UPDATE system_options SET option_value = ?, is_active = ? WHERE id = ? AND list_key = ?");
        $st->bind_param('siis', $name, $active, $opt_id, $lk);
        $ok  = $st->execute();
        $dup = ($conn->errno === 1062);
        $st->close();

        if ($ok && $name !== $old_name) {   // keep existing queue history attached to the renamed department
            $st = $conn->prepare("UPDATE appointments SET department = ? WHERE department = ?");
            $st->bind_param('ss', $name, $old_name);
            $ok = $st->execute(); $st->close();
        }
        if ($ok && $name !== $old_name) {
            $st = $conn->prepare("UPDATE department_visits SET department = ? WHERE department = ?");
            $st->bind_param('ss', $name, $old_name);
            $ok = $st->execute() || $conn->errno === 1146; $st->close();   // 1146 = visits table not created yet: nothing to rename
        }
        if ($ok && $name !== $old_name) {   // per-account exceptions follow the rename
            $st = $conn->prepare("UPDATE staff_department_access SET dept_name = ? WHERE dept_name = ?");
            $st->bind_param('ss', $name, $old_name); $ok = $st->execute(); $st->close();
        }
        if ($ok && $name !== $old_name) {
            $st = $conn->prepare("DELETE FROM department_access WHERE dept_name = ?");
            $st->bind_param('s', $old_name); $ok = $st->execute(); $st->close();
        }
        if ($ok) {
            $st = $conn->prepare("DELETE FROM department_settings WHERE dept_name = ?");
            $st->bind_param('s', $old_name); $ok = $st->execute(); $st->close();
        }
        if ($ok) {
            $st = $conn->prepare("INSERT INTO department_settings (dept_name, module_key, description) VALUES (?, ?, ?)
                                  ON DUPLICATE KEY UPDATE module_key = VALUES(module_key), description = VALUES(description)");
            $st->bind_param('sss', $name, $mod, $desc);
            $ok = $st->execute(); $st->close();
        }
        if ($ok) { $conn->commit(); } else { $conn->rollback(); }
        mh_masterlist_flush();
        if ($ok && !empty($_POST['dept_roles_present'])) {
            mh_dept_save_roles($conn, $name, isset($_POST['dept_roles']) && is_array($_POST['dept_roles']) ? array_map('strval', $_POST['dept_roles']) : []);
        }
        if (!$ok) { mh_redirect('departments', 'error', $dup ? 'A department with that name already exists.' : 'Could not save the changes. Nothing was changed.'); }
        logActivity($conn, $user_id, $user_role, 'Department Updated', "Updated department \"$old_name\"" . ($name !== $old_name ? " (renamed to \"$name\")" : '') . '.');
        mh_redirect('departments', 'success', "Department \"$name\" saved.");
    }

    /* ---------- DELETE (only when never used) ---------- */
    if (isset($_POST['delete_department'])) {
        $opt_id = (int) ($_POST['option_id'] ?? 0);
        $lk = MH_DEPT_LIST;
        $st = $conn->prepare("SELECT option_value FROM system_options WHERE id = ? AND list_key = ?");
        $st->bind_param('is', $opt_id, $lk); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        if (!$row) { mh_redirect('departments', 'error', 'That department no longer exists.'); }
        $dn = $row['option_value'];

        $st = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE department = ?");
        $st->bind_param('s', $dn); $st->execute();
        $used = (int) ($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
        if ($used > 0) { mh_redirect('departments', 'error', "\"$dn\" has $used queue record(s) and cannot be deleted. Untick Active to hide it instead."); }

        [$ok, $msg] = mh_delete_option($conn, $opt_id);
        if ($ok) {
            foreach (['department_settings', 'department_access', 'staff_department_access'] as $tbl) {
                $st = $conn->prepare("DELETE FROM $tbl WHERE dept_name = ?");   // fixed table names, value bound
                $st->bind_param('s', $dn); $st->execute(); $st->close();
            }
            logActivity($conn, $user_id, $user_role, 'Department Deleted', "Deleted department \"$dn\".");
        }
        mh_redirect('departments', $ok ? 'success' : 'error', $ok ? "Department \"$dn\" deleted." : $msg);
    }
}

/** Renders the tab body. */
function mh_depts_render(mysqli $conn)
{
    mh_department_ensure_table($conn);
    $h       = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $choices = mh_department_module_choices();
    $cfg     = mh_department_settings($conn, true);
    $rows    = mh_options($conn, MH_DEPT_LIST, false);
    $usage   = [];
    $res = $conn->query("SELECT department, COUNT(*) AS c FROM appointments GROUP BY department");
    while ($res && ($r = $res->fetch_assoc())) { $usage[$r['department']] = (int) $r['c']; }

    $n_total = count($rows); $n_active = 0; $n_custom = 0;
    foreach ($rows as $o) {
        if ($o['is_active']) { $n_active++; }
        if (mh_department_module($o['option_value']) === null) { $n_custom++; }
    }
    $fn_hint = [
        ''              => 'The page opens by guessing from the department name.',
        'checkups'      => 'Opens the Consultations page.',
        'dental'        => 'Opens Dental Records.',
        'maternal_care' => 'Opens Maternal Care.',
        'immunization'  => 'Opens Child Immunization.',
        'inventory'     => 'Handled by the Medicine Inventory menu.',
        'none'          => 'Gets its own page with today\'s queue and visit notes.',
    ];
    ?>
    <style>
        .dpt-stats { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; }
        .dpt-stat { background:var(--ss-card); border:1px solid var(--ss-border); border-radius:12px; padding:12px 18px; min-width:120px; }
        .dpt-stat b { display:block; font-size:1.5rem; color:var(--ss-heading); line-height:1.1; }
        .dpt-stat span { font-size:0.78rem; color:var(--ss-muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
        .dpt-form { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:16px; }
        .dpt-form .dpt-field label, .dpt-sec { display:block; font-size:0.8rem; font-weight:700; color:var(--ss-muted); text-transform:uppercase; letter-spacing:.04em; margin:0 0 6px; }
        .dpt-form input[type=text], .dpt-form select { width:100%; box-sizing:border-box; }
        .dpt-help { display:block; margin-top:5px; font-size:0.78rem; color:var(--ss-muted); }
        .dpt-full { grid-column:1 / -1; }
        .dpt-chips { display:flex; flex-wrap:wrap; gap:8px; }
        .dpt-chip { margin:0; cursor:pointer; }
        .dpt-chip input { position:absolute; opacity:0; width:1px; height:1px; }
        .dpt-chip span { display:inline-block; padding:6px 14px; border-radius:999px; border:1px solid var(--ss-border-strong, #cbd5e1); font-size:0.84rem; font-weight:600; color:var(--ss-text); background:var(--ss-input-bg, transparent); transition:all .12s; }
        .dpt-chip:hover span { border-color:var(--button-bg, #4ca1af); }
        .dpt-chip input:checked + span { background:var(--button-bg, #4ca1af); border-color:var(--button-bg, #4ca1af); color:var(--button-text, #fff); }
        .dpt-chip input:focus-visible + span { outline:2px solid var(--button-bg, #4ca1af); outline-offset:2px; }
        .dpt-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:14px; }
        .dpt-card { background:var(--ss-card); border:1px solid var(--ss-border); border-left:5px solid var(--dpt-color); border-radius:12px; overflow:hidden; }
        .dpt-card.is-hidden { opacity:.72; }
        .dpt-card > summary { list-style:none; cursor:pointer; padding:14px 16px; display:flex; align-items:center; gap:12px; }
        .dpt-card > summary::-webkit-details-marker { display:none; }
        .dpt-card > summary:hover { background:var(--ss-card-head); }
        .dpt-title { flex:1; min-width:0; }
        .dpt-title strong { display:block; font-size:1rem; color:var(--ss-heading); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .dpt-title small { color:var(--ss-muted); font-size:0.8rem; }
        .dpt-badge { padding:2px 10px; border-radius:999px; font-size:0.72rem; font-weight:700; white-space:nowrap; }
        .dpt-on { background:var(--ss-ok-bg, #dcfce7); color:var(--ss-ok-tx, #166534); }
        .dpt-off { background:var(--ss-pill-bg, #e2e8f0); color:var(--ss-pill-text, #475569); }
        .dpt-caret { color:var(--ss-muted); transition:transform .15s; }
        .dpt-card[open] .dpt-caret { transform:rotate(180deg); }
        .dpt-body { padding:16px; border-top:1px solid var(--ss-border); background:var(--ss-card-head); }
        .dpt-actions { display:flex; gap:8px; align-items:center; justify-content:space-between; margin-top:18px; flex-wrap:wrap; }
        .dpt-switch { display:inline-flex; align-items:center; gap:8px; margin:0 !important; font-weight:600; text-transform:none !important; letter-spacing:0 !important; color:var(--ss-text) !important; font-size:0.9rem !important; }
        .dpt-switch input { width:auto; }
        .dpt-del { background:var(--ss-err-bg, #fee2e2); color:var(--ss-err-tx, #991b1b); }
    </style>

    <div class="dpt-stats">
        <div class="dpt-stat"><b><?php echo $n_total; ?></b><span>Departments</span></div>
        <div class="dpt-stat"><b><?php echo $n_active; ?></b><span>Active</span></div>
        <div class="dpt-stat"><b><?php echo $n_total - $n_active; ?></b><span>Hidden</span></div>
        <div class="dpt-stat"><b><?php echo $n_custom; ?></b><span>Custom pages</span></div>
    </div>

    <div class="card">
        <header>
            <h3>Add a department</h3>
            <p>It appears in the staff sidebar, walk-in registration and Patient Queue right away. Pick what it <strong>does</strong> and <strong>who can use it</strong>.</p>
        </header>
        <div class="body">
            <form method="POST" autocomplete="off" class="dpt-form">
                <?php echo mh_csrf_field(); ?>
                <div class="dpt-field"><label>Name</label><input type="text" name="dept_name" maxlength="100" required placeholder="e.g. TB-DOTS"></div>
                <div class="dpt-field"><label>Function</label>
                    <select name="module_key" onchange="var h=document.getElementById('dptNewHint');h.textContent=h.dataset[this.value===''?'auto':this.value]||'';">
                        <?php foreach ($choices as $k => $label): ?><option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option><?php endforeach; ?>
                    </select>
                    <small class="dpt-help" id="dptNewHint" data-auto="<?php echo $h($fn_hint['']); ?>" <?php foreach ($fn_hint as $k => $t) { if ($k !== '') { echo 'data-' . $h($k) . '="' . $h($t) . '" '; } } ?>><?php echo $h($fn_hint['']); ?></small>
                </div>
                <div class="dpt-field"><label>Description (optional)</label><input type="text" name="description" maxlength="255" placeholder="Shown on the department page"></div>
                <div class="dpt-full">
                    <span class="dpt-sec">Who can use it</span>
                    <input type="hidden" name="dept_roles_present" value="1">
                    <?php mh_dept_role_pills(''); ?>
                    <small class="dpt-help">The Health Head always can. Fine-tune individual people under Staff Management &rarr; Edit &rarr; Departments.</small>
                </div>
                <div class="dpt-full"><button type="submit" name="add_department" class="btn btn-main">Add Department</button></div>
            </form>
        </div>
    </div>

    <h3 style="margin:26px 0 12px; color:var(--ss-heading);">Your departments</h3>
    <?php if (!$rows): ?><p style="color:var(--ss-muted);">No departments yet. Add the first one above.</p><?php endif; ?>
    <div class="dpt-grid">
    <?php foreach ($rows as $o):
        $name  = $o['option_value'];
        $c     = $cfg[strtolower($name)] ?? ['module_key' => '', 'description' => ''];
        $used  = $usage[$name] ?? 0;
        $mod   = mh_department_module($name);
        $fnlbl = $c['module_key'] === '' ? ('Auto' . ($mod ? ' - ' . ($choices[$mod] ?? $mod) : ' - custom page')) : ($choices[$c['module_key']] ?? $c['module_key']);
    ?>
        <details class="dpt-card <?php echo $o['is_active'] ? '' : 'is-hidden'; ?>" style="--dpt-color:<?php echo $h(mh_department_color($name)); ?>;">
            <summary>
                <div class="dpt-title">
                    <strong><?php echo $h($name); ?></strong>
                    <small><?php echo $h($fnlbl); ?> &middot; <?php echo (int) $used; ?> queue record<?php echo $used === 1 ? '' : 's'; ?></small>
                </div>
                <span class="dpt-badge <?php echo $o['is_active'] ? 'dpt-on' : 'dpt-off'; ?>"><?php echo $o['is_active'] ? 'Active' : 'Hidden'; ?></span>
                <span class="dpt-caret">&#9662;</span>
            </summary>
            <form method="POST" autocomplete="off" class="dpt-body">
                <?php echo mh_csrf_field(); ?>
                <input type="hidden" name="option_id" value="<?php echo (int) $o['id']; ?>">
                <input type="hidden" name="dept_roles_present" value="1">
                <div class="dpt-form">
                    <div class="dpt-field"><label>Name</label><input type="text" name="dept_name" maxlength="100" required value="<?php echo $h($name); ?>"></div>
                    <div class="dpt-field"><label>Function</label>
                        <select name="module_key">
                            <?php foreach ($choices as $k => $lbl): ?><option value="<?php echo $h($k); ?>" <?php echo $c['module_key'] === (string) $k ? 'selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="dpt-field dpt-full"><label>Description</label><input type="text" name="description" maxlength="255" value="<?php echo $h($c['description']); ?>"></div>
                    <div class="dpt-full">
                        <span class="dpt-sec">Who can use it</span>
                        <?php mh_dept_role_pills($name); ?>
                    </div>
                </div>
                <div class="dpt-actions">
                    <label class="dpt-switch"><input type="checkbox" name="is_active" value="1" <?php echo $o['is_active'] ? 'checked' : ''; ?>> Active (shown in sidebar and queue)</label>
                    <span style="display:flex; gap:8px;">
                        <button type="submit" name="delete_department" class="btn dpt-del"
                                data-confirm="Delete this department? This only works if it was never used in the queue." data-confirm-title="Delete department?" data-confirm-type="danger" data-confirm-ok="Delete">Delete</button>
                        <button type="submit" name="edit_department" class="btn btn-main">Save changes</button>
                    </span>
                </div>
                <?php if ($used > 0): ?><small class="dpt-help">Renaming keeps its <?php echo (int) $used; ?> queue record(s). Departments with queue records can be hidden but not deleted.</small><?php endif; ?>
            </form>
        </details>
    <?php endforeach; ?>
    </div>
    <?php
}
