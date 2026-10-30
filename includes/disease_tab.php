<?php
/**
 * System Settings -> Disease Tracking tab (Health Head only: the page enforces require_health_head()).
 * Which illnesses Disease Statistics watches, the keywords that identify them in diagnoses, and the spike thresholds.
 */
require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/disease_stats.php';

/** Handles add / edit / delete, then redirects (mh_redirect is defined by system_settings.php). */
function mh_disease_tab_post(mysqli $conn, int $user_id, string $user_role)
{
    mh_disease_ensure_tables($conn);
    $name = trim((string) ($_POST['name'] ?? ''));
    $kw   = mb_substr(trim((string) ($_POST['keywords'] ?? '')), 0, 255);
    $min  = max(1, min(999, (int) ($_POST['min_cases'] ?? 3)));
    $fac  = max(1.1, min(9.9, (float) ($_POST['spike_factor'] ?? 2)));

    if (isset($_POST['add_condition']) || isset($_POST['edit_condition'])) {
        if ($name === '' || mb_strlen($name) > 100) { mh_redirect('disease', 'error', 'Enter a condition name (up to 100 characters).'); }
        if ($kw === '' || mh_disease_regex($kw) === null) { mh_redirect('disease', 'error', 'Enter at least one keyword (2+ letters), separated by commas.'); }
    }
    if (isset($_POST['add_condition'])) {
        $st = $conn->prepare("INSERT INTO tracked_conditions (name, keywords, min_cases, spike_factor) VALUES (?, ?, ?, ?)");
        $st->bind_param('ssid', $name, $kw, $min, $fac);
        $ok = $st->execute(); $dup = ($conn->errno === 1062); $st->close();
        if ($ok) { logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Added tracked condition \"$name\"."); }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? "Now tracking \"$name\"." : ($dup ? 'That condition is already tracked.' : 'Could not save. Please try again.'));
    }
    if (isset($_POST['edit_condition'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        $st = $conn->prepare("UPDATE tracked_conditions SET name = ?, keywords = ?, min_cases = ?, spike_factor = ?, is_active = ? WHERE id = ?");
        $st->bind_param('ssidii', $name, $kw, $min, $fac, $active, $id);
        $ok = $st->execute(); $dup = ($conn->errno === 1062); $st->close();
        if ($ok) { logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Updated tracked condition \"$name\"."); }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? "\"$name\" saved." : ($dup ? 'Another condition already uses that name.' : 'Could not save. Please try again.'));
    }
    if (isset($_POST['delete_condition'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM tracked_conditions WHERE id = ?");
        $st->bind_param('i', $id); $ok = $st->execute(); $st->close();
        if ($ok) { logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Removed tracked condition #$id."); }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? 'Condition removed from tracking.' : 'Could not remove it.');
    }
}

function mh_disease_tab_render(mysqli $conn)
{
    $h   = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $all = mh_disease_conditions($conn, false);
    $pre_name = trim((string) ($_GET['new'] ?? ''));               // "Track this" link from Disease Statistics
    $pre_name = mb_substr($pre_name, 0, 100);
    ?>
    <style>
        .dtt-form { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; align-items:end; }
        .dtt-form label.dtt-l { display:block; font-size:0.78rem; font-weight:700; color:var(--ss-muted); text-transform:uppercase; letter-spacing:.04em; margin:0 0 5px; }
        .dtt-form input[type=text], .dtt-form input[type=number] { width:100%; box-sizing:border-box; }
        .dtt-row { padding:14px 0; border-top:1px solid var(--ss-border); }
        .dtt-row:first-child { border-top:0; padding-top:0; }
        .dtt-act { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .dtt-act label { margin:0; font-weight:600; font-size:0.88rem; white-space:nowrap; }
        .dtt-act input[type=checkbox] { width:auto; }
        .dtt-del { background:var(--ss-err-bg, #fee2e2); color:var(--ss-err-tx, #991b1b); }
        .dtt-off { opacity:.6; }
    </style>

    <div class="card">
        <header>
            <h3>Track a new condition</h3>
            <p>A consultation counts toward a condition when its diagnosis contains one of the keywords (whole words, separated by commas). Disease Statistics only lists conditions that have logged cases, and flags a spike when this week's cases reach the threshold <em>and</em> the multiple of the recent 4-week average.</p>
        </header>
        <div class="body">
            <form method="POST" autocomplete="off" class="dtt-form">
                <?php echo mh_csrf_field(); ?>
                <div><label class="dtt-l">Condition name</label><input type="text" name="name" maxlength="100" required placeholder="e.g. Measles" value="<?php echo $h($pre_name); ?>"></div>
                <div><label class="dtt-l">Keywords</label><input type="text" name="keywords" maxlength="255" required placeholder="measles, rubeola" value="<?php echo $h(mb_strtolower($pre_name)); ?>"></div>
                <div><label class="dtt-l">Spike at (cases/week)</label><input type="number" name="min_cases" value="3" min="1" max="999"></div>
                <div><label class="dtt-l">&times; the usual average</label><input type="number" name="spike_factor" value="2.0" min="1.1" max="9.9" step="0.1"></div>
                <div><button type="submit" name="add_condition" class="btn btn-main">Add condition</button></div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:18px;">
        <header><h3>Tracked conditions (<?php echo count($all); ?>)</h3><p>Untick Active to stop watching a condition without deleting it.</p></header>
        <div class="body">
            <?php if (!$all): ?><p style="margin:0;">Nothing is tracked yet.</p><?php endif; ?>
            <?php foreach ($all as $c): ?>
                <form method="POST" autocomplete="off" class="dtt-form dtt-row <?php echo $c['is_active'] ? '' : 'dtt-off'; ?>">
                    <?php echo mh_csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                    <div><label class="dtt-l">Name</label><input type="text" name="name" maxlength="100" required value="<?php echo $h($c['name']); ?>"></div>
                    <div><label class="dtt-l">Keywords</label><input type="text" name="keywords" maxlength="255" required value="<?php echo $h($c['keywords']); ?>"></div>
                    <div><label class="dtt-l">Spike at</label><input type="number" name="min_cases" value="<?php echo (int) $c['min_cases']; ?>" min="1" max="999"></div>
                    <div><label class="dtt-l">&times; average</label><input type="number" name="spike_factor" value="<?php echo $h($c['spike_factor']); ?>" min="1.1" max="9.9" step="0.1"></div>
                    <div class="dtt-act">
                        <label><input type="checkbox" name="is_active" value="1" <?php echo $c['is_active'] ? 'checked' : ''; ?>> Active</label>
                        <button type="submit" name="edit_condition" class="btn btn-main">Save</button>
                        <button type="submit" name="delete_condition" class="btn dtt-del" data-confirm="Stop tracking this condition?" data-confirm-title="Stop tracking?" data-confirm-type="danger" data-confirm-ok="Stop tracking">Delete</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
