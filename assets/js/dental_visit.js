(function () {
    'use strict';
    var CFG = window.DN_VISIT || {};
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var esc = function (v) { return window.mhEsc(v); };

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

    var modal = $('#dvModal'), form = $('#dvForm');
    if (!modal || !form) { return; }
    var activePatient = 0, profileOk = true;

    function openModal() { modal.classList.add('active'); }
    function isDirty() {
        if (activePatient) { return true; }
        return !!($('#dvNotes').value.trim() || $('#dvTooth').value.trim() || $('#dvRx').value.trim());
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
    var cancelBtn = $('#dvCancel');
    if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('active') && !document.querySelector('.mh-modal-backdrop') && !document.querySelector('.mh-ps-list:not([hidden])')) { closeModal(); }
    });

    function setBody(on) { $('#dvBody').hidden = !on; }
    function loadSummary(id) {
        activePatient = id; profileOk = true;
        $('#dvAlerts').hidden = true; setBody(true);
        fetch('dental.php?ajax=summary&patient=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (activePatient !== id) { return; }
                if (!res.ok) { return; }
                var s = res.j;
                fillSheet(s.sheet);
                loadHistory(id);
                if (s.alerts && s.alerts.length) {
                    $('#dvAlerts').innerHTML = '<strong>Medical alerts:</strong> ' + s.alerts.map(function (a) { return '<span class="mh-badge mh-badge-danger">' + esc(a) + '</span>'; }).join(' ');
                    $('#dvAlerts').hidden = false;
                }
            })
            .catch(function () { });
    }
    document.addEventListener('mh:patient-picked', function (e) { if (e.detail && e.detail.id) { loadSummary(parseInt(e.detail.id, 10)); } });
    document.addEventListener('mh:patient-unlinked', function () { activePatient = 0; if (typeof window.mhSetPatientName === 'function') { window.mhSetPatientName('', '', ''); } $('#dvAlerts').hidden = true; setBody(true); fillSheet(null); clearHistory(); });

    function showPicked(p) {
        $('#pnPickedId').value = p.id;
        if (typeof window.mhSetPatientName === 'function') { window.mhSetPatientName(p.first_name, p.middle_name, p.last_name); }
        var badge = $('.mh-ps-badge', form);
        badge.hidden = false;
        badge.innerHTML = '<span>Patient <strong>#' + esc(p.id) + '</strong> &ndash; ' + esc(p.last_name) + ', ' + esc(p.first_name) + '</span><button type="button" class="mh-ps-clear" aria-label="Unlink patient">&times;</button>';
        loadSummary(parseInt(p.id, 10));
    }

    var scanRows = [];
    function names(codes, map) { return (codes || []).map(function (c) { return (map && map[c]) || c; }); }
    function fillFormFromRow(r) {
        $('#dvDate').value = r.date || $('#dvDate').value;
        $$('input[name="routine[]"], input[name="other[]"]', form).forEach(function (cb) { cb.checked = (r.routine || []).concat(r.other || []).indexOf(cb.value) !== -1; });
        $('#dvTooth').value = String(r.teeth || '').split(/[\s,;\/]+/).filter(Boolean).join(', ');
        $('#dvNotes').value = [r.referrals ? 'Referral: ' + r.referrals : '', r.remarks || ''].filter(Boolean).join('. ');
        syncLive();
        if (window.mh_toast) { window.mh_toast('Row copied into the form. Check it, then save.', 'info'); }
        $('#dvNotes').focus();
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
            modal.classList.remove('active');
            window.DN_ocrHandler = onScanned;
            window.DN_ocrClosed = function () { openModal(); };
            opener.click();
            var picked = $('#dvOcrFile'), target = document.getElementById('dnFiles');
            if (picked && picked.files && picked.files.length && target && window.DataTransfer) {
                try {
                    var dt = new DataTransfer();
                    Array.prototype.forEach.call(picked.files, function (f) { dt.items.add(f); });
                    target.files = dt.files;
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                    picked.value = '';
                    setTimeout(function () { var go = document.getElementById('dnOcrStart'); if (go && !go.disabled) { go.click(); } }, 60);
                } catch (err) { }
            }
        });
    }

    /* ---- the center's paper Dental Record: header, patient block, procedure history ---- */
    var SHEET_IDS = { family_no: 'dvsFamily', others: 'dvsOthers', member_no: 'dvsMember', name: 'dvsName', birthday: 'dvsBirthday', address: 'dvsAddress', age: 'dvsAge', sex: 'dvsSex', contact: 'dvsContact' };
    function fillSheet(sh) {
        Object.keys(SHEET_IDS).forEach(function (k) {
            var el = document.getElementById(SHEET_IDS[k]);
            if (el) { el.value = sh && sh[k] != null ? sh[k] : ''; }
        });
    }
    function histCell(v) { return v ? esc(v) : '&nbsp;'; }
    function clearHistory() {
        $('#dvHistBody').innerHTML = '<tr class="dvs-empty"><td colspan="4">Pick a patient to show the procedure history.</td></tr>';
    }
    function loadHistory(id) {
        var host = $('#dvHistBody');
        host.innerHTML = '<tr class="dvs-empty"><td colspan="4">Loading the procedure history...</td></tr>';
        fetch('dental.php?ajax=history&patient=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (activePatient !== id) { return; }
                var rows = res.ok && res.j && res.j.rows ? res.j.rows : [];
                if (!rows.length) { host.innerHTML = '<tr class="dvs-empty"><td colspan="4">No procedures recorded yet.</td></tr>'; return; }
                host.innerHTML = rows.map(function (r) {
                    return '<tr><td>' + histCell(r.date_label) + '</td><td>' + histCell(r.procedure) + '</td><td>' + histCell(r.tooth) + '</td><td>' + histCell(r.remarks) + '</td></tr>';
                }).join('');
            })
            .catch(function () { if (activePatient === id) { host.innerHTML = '<tr class="dvs-empty"><td colspan="4">The history could not be loaded.</td></tr>'; } });
    }
    function sheetName(code) { return (CFG.sheetNames || {})[code] || (CFG.routineNames || {})[code] || (CFG.otherNames || {})[code] || code; }
    function syncLive() {
        var proc = [];
        $$('input[name="routine[]"]:checked, input[name="other[]"]:checked', form).forEach(function (cb) { proc.push(sheetName(cb.value)); });
        var d = $('#dvDate').value, dl = d;
        if (/^\d{4}-\d{2}-\d{2}$/.test(d)) { var dt = new Date(d + 'T00:00:00'); dl = dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }); }
        var set = function (id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } };
        set('dvLiveDate', dl || ''); set('dvLiveProc', proc.join(', ')); set('dvLiveRem', $('#dvNotes').value.trim());
    }
    function procOptions(current) {
        var h = '<option value="">Select procedure</option>';
        var names = CFG.sheetNames || {};
        Object.keys(names).forEach(function (k) { h += '<option value="' + esc(k) + '"' + (current === k ? ' selected' : '') + '>' + esc(names[k]) + '</option>'; });
        return h;
    }
    function addProcRow(v) {
        v = v || {};
        var tr = document.createElement('tr');
        tr.className = 'dvs-newrow';
        tr.innerHTML = '<td><input type="date" name="prow_date[]" max="' + new Date().toISOString().slice(0, 10) + '" value="' + esc(v.date || $('#dvDate').value || '') + '" aria-label="Procedure date"></td>'
            + '<td><select name="prow_proc[]" aria-label="Procedure">' + procOptions(v.proc || '') + '</select></td>'
            + '<td><input type="text" name="prow_tooth[]" maxlength="60" value="' + esc(v.tooth || '') + '" placeholder="e.g. 16, 17" aria-label="Tooth number"></td>'
            + '<td><div class="dvs-rowend"><input type="text" name="prow_remarks[]" maxlength="1000" value="' + esc(v.remarks || '') + '" aria-label="Remarks">'
            + '<button type="button" class="dvs-del" aria-label="Remove this row">&times;</button></div></td>';
        $('#dvHistRows').appendChild(tr);
        return tr;
    }
    var addRowBtn = $('#dvAddRow');
    if (addRowBtn) { addRowBtn.addEventListener('click', function () { var tr = addProcRow(); var f = tr.querySelector('select'); if (f) { f.focus(); } }); }
    $('#dvHistRows').addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.dvs-del') : null;
        if (b) { var tr = b.closest('tr'); if (tr) { tr.parentNode.removeChild(tr); } }
    });
    form.addEventListener('input', syncLive);
    form.addEventListener('change', syncLive);

    form.addEventListener('submit', function (e) {
        window.mhClearErrors && window.mhClearErrors(form);
        var errs = [];
        function need(el, label, msg) { errs.push({ el: el, label: label, msg: msg }); }
        if (!$('#pnPickedId').value) { need($('.mh-ps-input', form), 'Patient', 'search and pick the registered patient.'); }
        var d = $('#dvDate').value;
        if (!d || (window.mhBirthdateProblem && window.mhBirthdateProblem(d))) { need($('#dvDate'), 'Visit date', 'enter a valid date (not in the future).'); }
        var rowFilled = false;
        $$('#dvHistRows tr').forEach(function (tr) {
            var rp = tr.querySelector('[name="prow_proc[]"]'), rt = tr.querySelector('[name="prow_tooth[]"]'), rr = tr.querySelector('[name="prow_remarks[]"]');
            if (rp.value || rt.value.trim() || rr.value.trim()) { rowFilled = true; }
        });
        var any = $$('input[name="routine[]"]:checked, input[name="other[]"]:checked', form).length || $('#dvNotes').value.trim() || rowFilled;
        if (!any) { need($('#dvNotes'), 'Dental findings', 'tick Routine Check-up or Extraction, add a procedure row, or write remarks.'); }
        var nx = $('#dvNext').value;
        if (nx && nx < d) { need($('#dvNext'), 'Next visit date', 'cannot be before the visit date.'); }
        $$('#dvHistRows tr').forEach(function (tr, i) {
            var rd = tr.querySelector('[name="prow_date[]"]'), rp = tr.querySelector('[name="prow_proc[]"]');
            var rt = tr.querySelector('[name="prow_tooth[]"]'), rr = tr.querySelector('[name="prow_remarks[]"]');
            if (!(rp.value || rt.value.trim() || rr.value.trim())) { return; }
            if (!rd.value || (window.mhBirthdateProblem && window.mhBirthdateProblem(rd.value))) { need(rd, 'Procedure history row ' + (i + 1) + ' date', 'enter a valid date (not in the future).'); }
            if (!rp.value && !rr.value.trim()) { need(rp, 'Procedure history row ' + (i + 1), 'pick a procedure or write remarks.'); }
        });
        if (errs.length) { e.preventDefault(); window.mhReport && window.mhReport(errs, 'Please check this dental record'); }
    });

    var old = CFG.old;
    if (old) {
        var set = function (id, v) { var el = $(id); if (el && v != null) { el.value = v; } };
        set('#dvDate', old.date); set('#dvNotes', old.notes); set('#dvNext', old.next); set('#dvRx', old.rx); set('#dvTooth', String(old.teeth || '').split(',').join(', '));
        if (old.route) { var rc = form.querySelector('input[name="route_dispensing"]'); if (rc) { rc.checked = true; } }
        (old.routine || []).concat(old.other || []).forEach(function (c) {
            var cb = form.querySelector('input[type=checkbox][value="' + String(c).replace(/"/g, '') + '"]');
            if (cb) { cb.checked = true; }
        });
        var pr = old.prow || {}, pn = Math.max((pr.date || []).length, (pr.proc || []).length, (pr.tooth || []).length, (pr.remarks || []).length);
        for (var pi = 0; pi < pn; pi++) {
            addProcRow({ date: (pr.date || [])[pi], proc: (pr.proc || [])[pi], tooth: (pr.tooth || [])[pi], remarks: (pr.remarks || [])[pi] });
        }
        syncLive();
    }
    syncLive();
    if (CFG.prefill) { showPicked(CFG.prefill); }
    if (CFG.open) { openModal(); }
})();