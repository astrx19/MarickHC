/* ==============================================================
   DENTAL - "New Dental Record" form  (Part 1)   assets/js/dental_visit.js
   Config comes from window.DN_VISIT (see admin/dental.php).
   - open / close the modal (also auto-opens from the queue's "Record visit" link)
   - registered-patient search (assets/js/mh_patient_search.js fires mh:patient-picked)
   - after a patient is picked: medical alerts + the "first visit -> complete the initial profile" check
   - tooth picker (FDI numbers), service ticks, validation with the shared red-field helpers
   - records table: search + "Details" rows
   ============================================================== */
(function () {
    'use strict';
    var CFG = window.DN_VISIT || {};
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    /* ---------- records table ---------- */
    var search = $('#dvSearch');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.toLowerCase().trim();
            $$('#dvTable tr.dv-row').forEach(function (tr) {
                var show = q === '' || (tr.getAttribute('data-hay') || '').indexOf(q) !== -1;
                tr.style.display = show ? '' : 'none';
                var d = tr.nextElementSibling;
                if (d && d.classList.contains('dv-detail')) { if (!show) { d.hidden = true; } d.style.display = show ? '' : 'none'; }
            });
        });
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.dv-more') : null;
        if (!b) { return; }
        var d = b.closest('tr').nextElementSibling;
        if (d && d.classList.contains('dv-detail')) {
            d.hidden = !d.hidden;
            b.setAttribute('aria-expanded', d.hidden ? 'false' : 'true');
            b.textContent = d.hidden ? 'Details' : 'Hide';
        }
    });

    /* ---------- the form ---------- */
    var modal = $('#dvModal'), form = $('#dvForm');
    if (!modal || !form) { return; }
    var teethSet = {}, toothSel = {}, currentSet = 'permanent', activePatient = 0, profileOk = false;

    function openModal() { modal.classList.add('active'); }
    function isDirty() {
        if (activePatient) { return true; }
        return !!($('#dvComplaint').value.trim() || $('#dvDiagnosis').value.trim() || $('#dvNotes').value.trim() || Object.keys(teethSet).length);
    }
    function closeModal() {
        var done = function () { modal.classList.remove('active'); };
        if (isDirty() && window.mh_confirm) {
            window.mh_confirm('What you entered for this record will be lost.', 'Close without saving?', 'warning', 'Yes, close').then(function (ok) { if (ok) { done(); } });
        } else { done(); }
    }
    var openBtn = $('#dvOpen');
    if (openBtn) { openBtn.addEventListener('click', openModal); }
    $('#dvClose').addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('active') && !document.querySelector('.mh-modal-backdrop') && !document.querySelector('.mh-ps-list:not([hidden])')) { closeModal(); }
    });

    /* ---------- tooth picker ---------- */
    function renderTeeth() {
        var host = $('#dvTeeth'), set = (CFG.teeth || {})[currentSet] || { upper: [], lower: [] };
        var cols = currentSet === 'permanent' ? 16 : 10;
        host.innerHTML = ['upper', 'lower'].map(function (arch) {
            return '<div class="dv-arch" style="grid-template-columns:repeat(' + cols + ',minmax(0,1fr))">' + (set[arch] || []).map(function (t) {
                return '<button type="button" class="dv-tooth' + (teethSet[t] ? ' is-on' : '') + '" data-t="' + t + '" aria-pressed="' + (teethSet[t] ? 'true' : 'false') + '">' + t + '</button>';
            }).join('') + '</div>';
        }).join('');
    }
    function opts(map, current) {
        var h = '<option value="">No change</option>';
        Object.keys(map || {}).forEach(function (k) { h += '<option value="' + esc(k) + '"' + (current === k ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; });
        return h + '<option value="-"' + (current === '-' ? ' selected' : '') + '>Remove (clear)</option>';
    }
    // One row per selected tooth: what it looks like AFTER this visit (updates the tooth chart + saves a history snapshot).
    function renderToothRows(keys) {
        var host = $('#dvToothRows');
        if (!keys.length) { host.hidden = true; host.innerHTML = ''; return; }
        host.hidden = false;
        host.innerHTML = '<div class="dv-tr-head"><strong>Tooth chart after this visit</strong> <span class="du-muted">(optional &ndash; leave "No change" if nothing changed)</span></div>'
            + keys.map(function (t) {
                var cur = toothSel[t] || {};
                return '<div class="dv-tr"><b>' + esc(t) + '</b>'
                     + '<select name="tooth_c[' + esc(t) + ']" data-t="' + esc(t) + '" data-k="c" aria-label="Condition of tooth ' + esc(t) + '">' + opts(CFG.conditions, cur.c) + '</select>'
                     + '<select name="tooth_s[' + esc(t) + ']" data-t="' + esc(t) + '" data-k="s" aria-label="Status of tooth ' + esc(t) + '">' + opts(CFG.statuses, cur.s) + '</select></div>';
            }).join('');
    }
    $('#dvToothRows').addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel.getAttribute || !sel.getAttribute('data-t')) { return; }
        var t = sel.getAttribute('data-t');
        toothSel[t] = toothSel[t] || {};
        toothSel[t][sel.getAttribute('data-k')] = sel.value;
    });
    function syncTeeth() {
        var keys = Object.keys(teethSet).sort(function (a, b) { return a - b; });
        $('#dvTeethInput').value = keys.join(',');
        $('#dvTeethChips').innerHTML = keys.map(function (t) { return '<span class="mh-badge mh-badge-info">' + esc(t) + '</span>'; }).join(' ');
        renderToothRows(keys);
    }
    $('#dvTeeth').addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.dv-tooth') : null;
        if (!b) { return; }
        var t = b.getAttribute('data-t');
        if (teethSet[t]) { delete teethSet[t]; } else { teethSet[t] = true; }
        b.classList.toggle('is-on', !!teethSet[t]);
        b.setAttribute('aria-pressed', teethSet[t] ? 'true' : 'false');
        syncTeeth();
    });
    $$('.dv-seg [data-set]').forEach(function (b) {
        b.addEventListener('click', function () {
            currentSet = b.getAttribute('data-set');
            $$('.dv-seg [data-set]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            renderTeeth();
        });
    });
    $('#dvTeethClear').addEventListener('click', function () { teethSet = {}; renderTeeth(); syncTeeth(); });
    renderTeeth();

    /* ---------- patient: alerts + first-visit gate ---------- */
    function setBody(on) { $('#dvBody').hidden = !on; }
    function loadSummary(id) {
        activePatient = id; profileOk = false;
        $('#dvAlerts').hidden = true; $('#dvGate').hidden = true; setBody(true);
        fetch('dental.php?ajax=summary&patient=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (activePatient !== id) { return; }
                if (!res.ok) { return; }
                var s = res.j;
                profileOk = !!s.profile_completed;
                if (s.alerts && s.alerts.length) {
                    $('#dvAlerts').innerHTML = '<strong>Medical alerts:</strong> ' + s.alerts.map(function (a) { return '<span class="mh-badge mh-badge-danger">' + esc(a) + '</span>'; }).join(' ');
                    $('#dvAlerts').hidden = false;
                }
                if (!profileOk) {
                    $('#dvGateLink').href = 'dental.php?patient=' + id + '&tab=A&return=visit' + (CFG.aptId ? '&apt_id=' + CFG.aptId : '');
                    var gs = $('#dvGateScan');
                    if (gs) { gs.href = 'dental.php?patient=' + id + '&tab=A&return=visit&scan=1' + (CFG.aptId ? '&apt_id=' + CFG.aptId : ''); }
                    $('#dvGate').hidden = false; setBody(false);
                }
            })
            .catch(function () { /* the server re-checks the profile on save, so a failed lookup is not fatal */ });
    }
    document.addEventListener('mh:patient-picked', function (e) { if (e.detail && e.detail.id) { loadSummary(parseInt(e.detail.id, 10)); } });
    document.addEventListener('mh:patient-unlinked', function () { activePatient = 0; $('#dvAlerts').hidden = true; $('#dvGate').hidden = true; setBody(true); });

    function showPicked(p) {   // same badge the search box draws, so its "x" still unlinks
        $('#pnPickedId').value = p.id;
        var badge = $('.mh-ps-badge', form);
        badge.hidden = false;
        badge.innerHTML = '<span>Patient <strong>#' + esc(p.id) + '</strong> &ndash; ' + esc(p.last_name) + ', ' + esc(p.first_name) + '</span><button type="button" class="mh-ps-clear" aria-label="Unlink patient">&times;</button>';
        loadSummary(parseInt(p.id, 10));
    }

    /* ---------- OCR: scan the paper record, review the rows, import / use them ---------- */
    var scanRows = [];
    function names(codes, map) { return (codes || []).map(function (c) { return (map && map[c]) || c; }); }
    function fillFormFromRow(r) {
        $('#dvDate').value = r.date || $('#dvDate').value;
        $$('input[name="routine[]"], input[name="other[]"]', form).forEach(function (cb) { cb.checked = (r.routine || []).concat(r.other || []).indexOf(cb.value) !== -1; });
        teethSet = {};
        String(r.teeth || '').split(/[\s,;\/]+/).forEach(function (t) { if (t) { teethSet[t] = true; } });
        renderTeeth(); syncTeeth();
        $('#dvReferral').value = r.referrals || '';
        $('#dvNotes').value = r.remarks || '';
        if (window.mh_toast) { window.mh_toast('Row copied into the form. Check it, add the reason for the visit, then save.', 'info'); }
        $('#dvComplaint').focus();
    }
    function renderScan() {
        var host = $('#dvScanReview');
        if (!host) { return; }
        if (!scanRows.length) { host.hidden = true; host.innerHTML = ''; return; }
        host.hidden = false;
        host.innerHTML = '<div class="dv-scan-head"><strong>Scanned treatment rows</strong> <span class="du-muted">- check each row against the paper, fix the date if needed, then import.</span></div>'
            + '<div class="du-table-wrap"><table class="du-table du-table-sm"><thead><tr><th></th><th>Date</th><th>Services</th><th>Teeth</th><th>Referral</th><th>Remarks</th><th></th></tr></thead><tbody>'
            + scanRows.map(function (r, i) {
                var svc = names(r.routine, CFG.routineNames).concat(names(r.other, CFG.otherNames)).join(', ') || '<span class="du-muted">none</span>';
                return '<tr><td><input type="checkbox" data-sel="' + i + '"' + (r.date ? ' checked' : '') + ' aria-label="Import row ' + (i + 1) + '"></td>'
                    + '<td><input type="date" data-date="' + i + '" value="' + esc(r.date || '') + '" max="' + new Date().toISOString().slice(0, 10) + '" data-mh-date></td>'
                    + '<td>' + (typeof svc === 'string' && svc.indexOf('<span') === 0 ? svc : esc(svc)) + '</td><td>' + esc(r.teeth || '') + '</td><td>' + esc(r.referrals || '') + '</td><td>' + esc(r.remarks || '') + '</td>'
                    + '<td style="white-space:nowrap;"><button type="button" class="du-btn du-btn-ghost du-btn-sm" data-use="' + i + '">Use in form</button></td></tr>';
            }).join('') + '</tbody></table></div>'
            + '<div class="dv-scan-actions"><button type="button" class="du-btn du-btn-primary du-btn-sm" id="dvImportBtn">Import selected as dental records</button>'
            + '<button type="button" class="du-btn du-btn-ghost du-btn-sm" id="dvScanClear">Discard scan</button></div>';
    }
    var scanReview = $('#dvScanReview');
    if (scanReview) {
        scanReview.addEventListener('change', function (e) {
            var d = e.target.getAttribute && e.target.getAttribute('data-date');
            if (d !== null && d !== undefined) { scanRows[parseInt(d, 10)].date = e.target.value; }
        });
        scanReview.addEventListener('click', function (e) {
            var use = e.target.closest ? e.target.closest('[data-use]') : null;
            if (use) { fillFormFromRow(scanRows[parseInt(use.getAttribute('data-use'), 10)]); return; }
            if (e.target.id === 'dvScanClear') { scanRows = []; renderScan(); return; }
            if (e.target.id !== 'dvImportBtn') { return; }
            if (!$('#pnPickedId').value) { window.mh_alert && window.mh_alert('Pick the patient first, then import.', 'Patient needed', 'warning'); return; }
            if (!profileOk) { $('#dvGate').hidden = false; window.mh_alert && window.mh_alert('This patient\'s initial dental profile has to be completed first.', 'First visit', 'warning'); return; }
            var chosen = [], bad = 0;
            $$('input[data-sel]', scanReview).forEach(function (cb) {
                if (!cb.checked) { return; }
                var r = scanRows[parseInt(cb.getAttribute('data-sel'), 10)];
                if (!r.date || (window.mhBirthdateProblem && window.mhBirthdateProblem(r.date))) { bad++; return; }
                chosen.push({ date: r.date, routine: r.routine || [], other: r.other || [], teeth: r.teeth || '', referrals: r.referrals || '', remarks: r.remarks || '' });
            });
            if (bad) { window.mh_alert && window.mh_alert(bad + ' selected row(s) have no valid date. Type the date from the paper, or untick the row.', 'Check the dates', 'warning'); return; }
            if (!chosen.length) { window.mh_alert && window.mh_alert('Tick at least one row to import.', 'Nothing selected', 'info'); return; }
            var go = function () { $('#dvImportPid').value = $('#pnPickedId').value; $('#dvImportRows').value = JSON.stringify(chosen); $('#dvImportForm').submit(); };
            if (window.mh_confirm) { window.mh_confirm('Add ' + chosen.length + ' dental record(s) to this patient\'s history?', 'Import scanned rows', 'info', 'Import').then(function (ok) { if (ok) { go(); } }); } else { go(); }
        });
    }
    function onScanned(merged) {
        if (merged.matched && !$('#pnPickedId').value) { showPicked(merged.matched); }
        scanRows = (merged.treatments || []).map(function (t) { return { date: t.date || '', routine: t.routine || [], other: t.other || [], teeth: t.teeth || '', referrals: t.referrals || '', remarks: t.remarks || '' }; });
        if (!scanRows.length) {
            window.mh_alert && window.mh_alert('No treatment rows were found. Scan the page that has the treatment table (date, routine care, other services, referrals, remarks).', 'Nothing to import', 'info');
        }
        renderScan();
    }
    var scanBtn = $('#dvScan');
    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            var opener = document.getElementById('dnOpenOcr');
            if (!opener) { return; }
            modal.classList.remove('active');                       // the scanner window needs the screen
            window.DN_ocrHandler = onScanned;
            window.DN_ocrClosed = function () { openModal(); };      // closing the scanner brings the form back
            opener.click();
        });
    }
    var gateScan = $('#dvGateScan');

    /* ---------- validation ---------- */
    form.addEventListener('submit', function (e) {
        window.mhClearErrors && window.mhClearErrors(form);
        var errs = [];
        function need(el, label, msg) { errs.push({ el: el, label: label, msg: msg }); }
        if (!$('#pnPickedId').value) { need($('.mh-ps-input', form), 'Patient', 'search and pick the registered patient.'); }
        else if (!profileOk) { e.preventDefault(); $('#dvGate').hidden = false; return; }
        var d = $('#dvDate').value;
        if (!d || (window.mhBirthdateProblem && window.mhBirthdateProblem(d))) { need($('#dvDate'), 'Visit date', 'enter a valid date (not in the future).'); }
        if (!$('#dvComplaint').value.trim()) { need($('#dvComplaint'), 'Reason for visit', 'is required.'); }
        var bp = $('#dvBp').value.trim();
        if (bp && !/^\d{2,3}\/\d{2,3}$/.test(bp)) { need($('#dvBp'), 'Blood pressure', 'use the form 120/80.'); }
        var t = $('#dvTemp').value.trim();
        if (t && !(/^\d{2}(\.\d{1,2})?$/.test(t) && +t >= 30 && +t <= 45)) { need($('#dvTemp'), 'Temperature', 'a number between 30 and 45, like 36.8.'); }
        var any = $$('input[name="routine[]"]:checked, input[name="other[]"]:checked', form).length || $('#dvDiagnosis').value.trim() || $('#dvNotes').value.trim() || $('#dvReferral').value.trim();
        if (!any) { need($('#dvDiagnosis'), 'Services / Diagnosis', 'tick a service, or write a diagnosis, notes or a referral.'); }
        var nx = $('#dvNext').value;
        if (nx && nx < d) { need($('#dvNext'), 'Next visit date', 'cannot be before the visit date.'); }
        if (errs.length) { e.preventDefault(); window.mhReport && window.mhReport(errs, 'Please check this dental record'); }
    });

    /* ---------- start-up: restore what was typed after a server-side error, or open for a queued patient ---------- */
    var old = CFG.old;
    if (old) {
        var set = function (id, v) { var el = $(id); if (el && v != null) { el.value = v; } };
        set('#dvDate', old.date); set('#dvBp', old.bp); set('#dvTemp', old.temp); set('#dvComplaint', old.complaint);
        set('#dvDiagnosis', old.diagnosis); set('#dvNotes', old.notes); set('#dvReferral', old.referral); set('#dvNext', old.next);
        (old.routine || []).concat(old.other || []).forEach(function (c) {
            var cb = form.querySelector('input[type=checkbox][value="' + String(c).replace(/"/g, '') + '"]');
            if (cb) { cb.checked = true; }
        });
        String(old.teeth || '').split(',').forEach(function (t) { t = t.trim(); if (t) { teethSet[t] = true; } });
        ['c', 's'].forEach(function (k) {
            var m = old['tooth_' + k] || {};
            Object.keys(m).forEach(function (t) { toothSel[t] = toothSel[t] || {}; toothSel[t][k] = m[t]; });
        });
        renderTeeth(); syncTeeth();
    }
    if (CFG.prefill) { showPicked(CFG.prefill); }
    if (CFG.open) { openModal(); }
})();
