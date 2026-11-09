<?php
/**
 * System Settings -> Disease Statistics & AI Surveillance tab (Health Head only).
 * Manages tracked illnesses, baseline spike thresholds, AI synonym normalization rules,
 * outbreak parameters, and data hygiene stop-words.
 */
require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/disease_stats.php';

/** Handles add / edit / delete, then redirects (mh_redirect is defined by system_settings.php). */
function mh_disease_tab_post(mysqli $conn, int $user_id, string $user_role)
{
    mh_disease_ensure_tables($conn);

    // 1. Outbreak Thresholds & Surveillance Settings
    if (isset($_POST['save_disease_surveillance_settings'])) {
        $autoNorm   = isset($_POST['disease_auto_normalize_save']) ? '1' : '0';
        $risingPct  = max(5, min(200, (int) ($_POST['disease_rising_threshold_pct'] ?? 25)));
        $spikePct   = max(20, min(500, (int) ($_POST['disease_spike_threshold_pct'] ?? 100)));
        $notifyHead = isset($_POST['disease_notify_health_head']) ? '1' : '0';

        $ok = mh_set_settings($conn, [
            'disease_auto_normalize_save'  => (string) $autoNorm,
            'disease_rising_threshold_pct' => (string) $risingPct,
            'disease_spike_threshold_pct'  => (string) $spikePct,
            'disease_notify_health_head'   => (string) $notifyHead,
        ], $user_id);

        if ($ok) {
            logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Updated disease surveillance parameters and outbreak thresholds.");
        }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? 'Disease surveillance parameters and outbreak thresholds saved.' : 'Could not save surveillance parameters.');
    }

    // 2. Data Hygiene & Stop-words Filter
    if (isset($_POST['save_disease_stopwords'])) {
        $rawWords = trim((string) ($_POST['disease_stop_words'] ?? ''));
        $parts = array_filter(array_map('trim', explode(',', $rawWords)));
        $cleaned = implode(', ', $parts);

        $ok = mh_set_settings($conn, [
            'disease_stop_words' => $cleaned,
        ], $user_id);

        if ($ok) {
            logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Updated disease data hygiene stop-words filter list.");
        }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? 'Data hygiene stop-words saved. Non-medical entries matching these terms will be ignored.' : 'Could not save stop-words.');
    }

    // 3. Tracked Conditions CRUD
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

    // 4. AI Standardization & Synonyms Mapping Rules CRUD
    if (isset($_POST['add_synonym_rule'])) {
        $canonical = trim((string) ($_POST['canonical_name'] ?? ''));
        $syns = trim((string) ($_POST['synonyms'] ?? ''));
        if ($canonical === '') {
            mh_redirect('disease', 'error', 'Enter a canonical clinical condition name.');
        }
        if ($syns === '') {
            mh_redirect('disease', 'error', 'Enter at least one synonym or colloquial term.');
        }
        $st = $conn->prepare("INSERT INTO disease_synonyms_map (canonical_name, synonyms) VALUES (?, ?)");
        $st->bind_param('ss', $canonical, $syns);
        $ok = $st->execute();
        $st->close();
        if ($ok) { logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Added synonym rule for \"$canonical\"."); }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? "Synonym rule for \"$canonical\" created." : 'Could not add synonym rule.');
    }
    if (isset($_POST['edit_synonym_rule'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $canonical = trim((string) ($_POST['canonical_name'] ?? ''));
        $syns = trim((string) ($_POST['synonyms'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($canonical === '' || $syns === '') {
            mh_redirect('disease', 'error', 'Canonical name and synonyms cannot be blank.');
        }
        $st = $conn->prepare("UPDATE disease_synonyms_map SET canonical_name = ?, synonyms = ?, is_active = ? WHERE id = ?");
        $st->bind_param('ssii', $canonical, $syns, $active, $id);
        $ok = $st->execute();
        $st->close();
        if ($ok) { logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Updated synonym rule #$id (\"$canonical\")."); }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? "Synonym rule for \"$canonical\" updated." : 'Could not update synonym rule.');
    }
    if (isset($_POST['delete_synonym_rule'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM disease_synonyms_map WHERE id = ?");
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        if ($ok) { logActivity($conn, $user_id, $user_role, 'Disease Tracking', "Removed synonym rule #$id."); }
        mh_redirect('disease', $ok ? 'success' : 'error', $ok ? 'Synonym rule deleted.' : 'Could not delete rule.');
    }
}

function mh_disease_tab_render(mysqli $conn)
{
    $h   = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $all = mh_disease_conditions($conn, false);
    $rules = mh_disease_synonyms_rules($conn, false);

    // Current settings
    $autoNorm   = mh_setting('disease_auto_normalize_save', '0') === '1';
    $risingPct  = (int) mh_setting('disease_rising_threshold_pct', '25');
    $spikePct   = (int) mh_setting('disease_spike_threshold_pct', '100');
    $notifyHead = mh_setting('disease_notify_health_head', '1') === '1';
    $stopWords  = (string) mh_setting('disease_stop_words', 'test, testing, qwe, asd, zxc, broken hearted, no condom included, none, n/a, na, nil, null, wala, walang sakit, walang reklamo, checkup, pacheck, normal, healthy, sample, dummy, ok, okay');

    $pre_name = trim((string) ($_GET['new'] ?? ''));
    $pre_name = mb_substr($pre_name, 0, 100);
    $pre_kw   = trim((string) ($_GET['kw'] ?? mb_strtolower($pre_name)));
    $pre_kw   = mb_substr($pre_kw, 0, 255);
    ?>
    <style>
        .dtt-form { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; align-items:end; }
        .dtt-form label.dtt-l { display:block; font-size:0.78rem; font-weight:700; color:var(--ss-muted, #64748b); text-transform:uppercase; letter-spacing:.04em; margin:0 0 5px; }
        .dtt-form input[type=text], .dtt-form input[type=number], .dtt-form textarea { width:100%; box-sizing:border-box; }
        .dtt-row { padding:14px 0; border-top:1px solid var(--ss-border, #e2e8f0); }
        .dtt-row:first-child { border-top:0; padding-top:0; }
        .dtt-act { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .dtt-act label { margin:0; font-weight:600; font-size:0.88rem; white-space:nowrap; }
        .dtt-act input[type=checkbox] { width:auto; }
        .dtt-del { background:var(--ss-err-bg, #fee2e2); color:var(--ss-err-tx, #991b1b); }
        .dtt-off { opacity:.6; }
        .dtt-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.75rem; font-weight:700; }
        .dtt-card-header h3 { margin:0 0 4px; font-size:1.15rem; color:var(--heading-color, #1e293b); }
        .dtt-card-header p { margin:0; font-size:0.86rem; color:var(--ss-muted, #64748b); line-height:1.45; }
    </style>

    <!-- SECTION 1: Outbreak Threshold & Surveillance Parameters -->
    <div class="card">
        <header class="dtt-card-header">
            <h3>Outbreak Thresholds &amp; Surveillance Parameters</h3>
            <p>Configure percentage triggers for rising trends and active spikes, automated consultation normalization, and Health Head outbreak alerts.</p>
        </header>
        <div class="body">
            <form method="POST" autocomplete="off" class="dtt-form">
                <?php echo mh_csrf_field(); ?>
                <div>
                    <label class="dtt-l">Rising Trend Threshold (+%)</label>
                    <input type="number" name="disease_rising_threshold_pct" value="<?php echo $risingPct; ?>" min="5" max="200" required>
                    <small style="font-size:0.75rem; color:var(--ss-muted, #64748b); display:block; margin-top:4px;">Flags trend when cases exceed 4-week average by this percentage (default +25%).</small>
                </div>
                <div>
                    <label class="dtt-l">Active Spike Threshold (+%)</label>
                    <input type="number" name="disease_spike_threshold_pct" value="<?php echo $spikePct; ?>" min="20" max="500" required>
                    <small style="font-size:0.75rem; color:var(--ss-muted, #64748b); display:block; margin-top:4px;">Outbreak trigger threshold over baseline average (default +100% or 2.0x).</small>
                </div>
                <div style="grid-column: 1 / -1; display:flex; flex-direction:column; gap:10px; padding:10px 0; border-top:1px solid var(--ss-border, #e2e8f0); border-bottom:1px solid var(--ss-border, #e2e8f0);">
                    <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                        <input type="checkbox" name="disease_notify_health_head" value="1" <?php echo $notifyHead ? 'checked' : ''; ?>>
                        <span>Trigger high-priority dashboard alert &amp; notifications to Health Head when an active outbreak is flagged</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                        <input type="checkbox" name="disease_auto_normalize_save" value="1" <?php echo $autoNorm ? 'checked' : ''; ?>>
                        <span>Auto-normalize on save (automatically map colloquial symptoms and Tagalog diagnoses to canonical names during consultations)</span>
                    </label>
                </div>
                <div>
                    <button type="submit" name="save_disease_surveillance_settings" class="btn btn-main">Save Surveillance Parameters</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SECTION 2: Data Hygiene & Stop-words Filter -->
    <div class="card" style="margin-top:18px;">
        <header class="dtt-card-header">
            <h3>Data Hygiene &amp; Stop-words Filter</h3>
            <p>Exclude non-medical test entries, conversational filler, and garbage strings from disease statistics, AI summaries, and the Other Diagnoses masterlist.</p>
        </header>
        <div class="body">
            <form method="POST" autocomplete="off">
                <?php echo mh_csrf_field(); ?>
                <div style="margin-bottom:12px;">
                    <label class="dtt-l" style="display:block; font-size:0.78rem; font-weight:700; color:var(--ss-muted, #64748b); text-transform:uppercase; margin-bottom:6px;">
                        Ignored Non-Clinical Terms (comma-separated)
                    </label>
                    <textarea name="disease_stop_words" rows="4" style="width:100%; box-sizing:border-box; padding:10px; border:1px solid var(--ss-border, #cbd5e1); border-radius:8px; font-family:monospace; font-size:0.88rem;" placeholder="e.g. test, broken hearted, no condom included, sample, wala"><?php echo $h($stopWords); ?></textarea>
                    <small style="color:var(--ss-muted, #64748b); font-size:0.8rem; display:block; margin-top:4px;">
                        Any recorded diagnosis or complaint matching these exact words or containing these phrases will be automatically filtered out from statistical dashboards.
                    </small>
                </div>
                <button type="submit" name="save_disease_stopwords" class="btn btn-main">Save Stop-words Filter</button>
            </form>
        </div>
    </div>

    <!-- SECTION 3: Tracked Conditions Manager -->
    <div class="card" style="margin-top:18px;">
        <header class="dtt-card-header">
            <h3>Track a New Condition</h3>
            <p>A consultation counts toward a condition when its diagnosis contains one of the keywords. Disease Statistics monitors active conditions and flags an active spike when weekly cases reach the baseline threshold and growth multiplier.</p>
        </header>
        <div class="body">
            <form method="POST" autocomplete="off" class="dtt-form">
                <?php echo mh_csrf_field(); ?>
                <div><label class="dtt-l">Condition name</label><input type="text" name="name" maxlength="100" required placeholder="e.g. Measles" value="<?php echo $h($pre_name); ?>"></div>
                <div><label class="dtt-l">Keywords</label><input type="text" name="keywords" maxlength="255" required placeholder="measles, rubeola, tigdas" value="<?php echo $h($pre_kw); ?>"></div>
                <div><label class="dtt-l">Spike at (cases/week)</label><input type="number" name="min_cases" value="3" min="1" max="999"></div>
                <div><label class="dtt-l">&times; the usual average</label><input type="number" name="spike_factor" value="2.0" min="1.1" max="9.9" step="0.1"></div>
                <div><button type="submit" name="add_condition" class="btn btn-main">Add condition</button></div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:18px;">
        <header class="dtt-card-header">
            <h3>Tracked Conditions List (<?php echo count($all); ?>)</h3>
            <p>Active conditions are continuously monitored on the Disease Statistics dashboard. Untick Active to stop surveillance without deleting history.</p>
        </header>
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

    <!-- SECTION 4: AI Standardization & Synonyms Mapping Rules -->
    <div class="card" style="margin-top:18px;">
        <header class="dtt-card-header">
            <h3>Add AI Standardization &amp; Synonym Mapping Rule</h3>
            <p>Define canonical clinical names and assign target synonyms, local Tagalog terms, or common abbreviations so the AI engine and regex matchers group them together automatically.</p>
        </header>
        <div class="body">
            <form method="POST" autocomplete="off" class="dtt-form">
                <?php echo mh_csrf_field(); ?>
                <div style="flex:1;">
                    <label class="dtt-l">Canonical Clinical Name</label>
                    <input type="text" name="canonical_name" maxlength="150" required placeholder="e.g. Upper Respiratory Tract Infection">
                </div>
                <div style="flex:2; grid-column:span 2;">
                    <label class="dtt-l">Target Synonyms / Colloquial Terms (comma-separated)</label>
                    <input type="text" name="synonyms" required placeholder="e.g. trangkaso, flu, sipon at ubo, ubo't sipon, colds">
                </div>
                <div>
                    <button type="submit" name="add_synonym_rule" class="btn btn-main">Add Synonym Rule</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:18px;">
        <header class="dtt-card-header">
            <h3>Standardization &amp; Synonym Rules (<?php echo count($rules); ?>)</h3>
            <p>Used by the clinical intelligence engine to categorize diagnoses and auto-classify complaints.</p>
        </header>
        <div class="body">
            <?php if (!$rules): ?><p style="margin:0;">No synonym mapping rules registered yet.</p><?php endif; ?>
            <?php foreach ($rules as $r): ?>
                <form method="POST" autocomplete="off" class="dtt-form dtt-row <?php echo $r['is_active'] ? '' : 'dtt-off'; ?>" style="grid-template-columns: minmax(180px, 1.2fr) minmax(220px, 2fr) auto;">
                    <?php echo mh_csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                    <div>
                        <label class="dtt-l">Canonical Name</label>
                        <input type="text" name="canonical_name" maxlength="150" required value="<?php echo $h($r['canonical_name']); ?>">
                    </div>
                    <div>
                        <label class="dtt-l">Synonyms / Terms</label>
                        <input type="text" name="synonyms" required value="<?php echo $h($r['synonyms']); ?>">
                    </div>
                    <div class="dtt-act" style="align-self:end;">
                        <label><input type="checkbox" name="is_active" value="1" <?php echo $r['is_active'] ? 'checked' : ''; ?>> Active</label>
                        <button type="submit" name="edit_synonym_rule" class="btn btn-main">Save</button>
                        <button type="submit" name="delete_synonym_rule" class="btn dtt-del" data-confirm="Delete this synonym rule?" data-confirm-title="Delete rule?" data-confirm-type="danger" data-confirm-ok="Delete">Delete</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
