/* Dental module front-end: tabs, odontogram, caries risk, multi-page OCR. No external libraries. */
(function () {
    'use strict';
    var CFG = window.DN_CFG || {};
    var D = window.DN || null;
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var dirty = false, submitting = false;

    /* ------------------------------------------------------------------
     * LIST PAGE helpers
     * ---------------------------------------------------------------- */
    var pf = $('#dnPatientFilter'), ps = $('#dnPatientSelect');
    if (pf && ps) {
        pf.addEventListener('input', function () {
            var q = pf.value.toLowerCase();
            for (var i = 1; i < ps.options.length; i++) { ps.options[i].hidden = q !== '' && ps.options[i].text.toLowerCase().indexOf(q) === -1; }
        });
    }
    var cs = $('#dnChartSearch');
    if (cs) {
        cs.addEventListener('input', function () {
            var q = cs.value.toLowerCase();
            $$('#dnChartTable tbody tr[data-name]').forEach(function (tr) { tr.style.display = (q === '' || tr.dataset.name.indexOf(q) !== -1) ? '' : 'none'; });
        });
    }

    /* ------------------------------------------------------------------
     * CHART PAGE
     * ---------------------------------------------------------------- */
    var form = $('#dnForm');
    var odo = {}, sel = {}, view = 'both', multi = false;
    var group = 'adult', answersBy = { child: {}, adult: {} }, finalTouched = false;

    function markDirty() {
        dirty = true;
        var d = $('#dnDirty'); if (d) { d.hidden = false; }
    }

    if (D && form) { initChart(); }

    function initChart() {
        /* ---- tabs ---- */
        var TABS = ['A', 'B', 'C', 'D', 'E'];
        var current = D.tab || 'A';
        function showTab(t, noScroll) {
            if (TABS.indexOf(t) === -1) { t = 'A'; }
            current = t;
            $$('.du-panel').forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === t); });
            $$('.du-tab').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === t); });
            var at = $('#dnActiveTab'); if (at) { at.value = t; }
            var i = TABS.indexOf(t);
            $('#dnPrev').disabled = i === 0; $('#dnNext').disabled = i === TABS.length - 1;
            var sv = $('#dnSave'); if (sv) { sv.style.display = (t === 'E') ? 'none' : ''; }
            var dd = $('#dnDirty'); if (dd && t === 'E') { dd.hidden = true; } else if (dd && dirty) { dd.hidden = false; }
            try { history.replaceState(null, '', location.pathname + '?patient=' + D.patientId + '&tab=' + t); } catch (e) { /* ignore */ }
            if (!noScroll) { window.scrollTo({ top: 0, behavior: 'smooth' }); }
        }
        $$('.du-tab').forEach(function (b) { b.addEventListener('click', function () { showTab(b.dataset.tab); }); });
        $('#dnPrev').addEventListener('click', function () { showTab(TABS[Math.max(0, TABS.indexOf(current) - 1)]); });
        $('#dnNext').addEventListener('click', function () { showTab(TABS[Math.min(TABS.length - 1, TABS.indexOf(current) + 1)]); });
        showTab(current, true);

        /* ---- conditional fields + medical alerts ---- */
        function updateConditionals() {
            $$('[data-dn-when]').forEach(function (el) {
                var parts = el.dataset.dnWhen.split('=');
                var c = form.querySelector('input[name="' + parts[0] + '"]:checked');
                el.classList.toggle('du-hidden', !(c && c.value === parts[1]));
            });
        }
        var ALERT_KEYS = ['high_bp', 'diabetes', 'heart_disease', 'heart_attack', 'heart_murmur', 'heart_surgery', 'stroke', 'bleeding', 'asthma', 'epilepsy', 'fainting', 'kidney', 'hepatitis', 'tuberculosis', 'cancer'];
        function labelOf(input) { var s = input.parentNode.querySelector('span'); return s ? s.textContent.trim() : ''; }
        function updateAlerts() {
            var out = [];
            ALERT_KEYS.forEach(function (k) { var c = form.querySelector('input[name="mh[cond][' + k + ']"]'); if (c && c.checked) { out.push(labelOf(c)); } });
            var hiv = form.querySelector('input[name="mh[cond][hiv]"]'); if (hiv && hiv.checked) { out.push('Infectious disease flagged - see medical history'); }
            $$('input[name^="mh[allergy]["]', form).forEach(function (c) { if (c.checked) { out.push('Allergy: ' + labelOf(c)); } });
            var ao = form.querySelector('input[name="mh[allergy_other]"]'); if (ao && ao.value.trim()) { out.push('Allergy: ' + ao.value.trim()); }
            var pg = form.querySelector('input[name="mh[women][pregnant]"]:checked');
            if (pg && pg.value === 'y') { var pm = form.querySelector('input[name="mh[pregnant_months]"]'); out.push('Pregnant' + (pm && pm.value.trim() ? ' (' + pm.value.trim() + ' mo)' : '')); }
            var med = form.querySelector('input[name="mh[q][medicine]"]:checked'); if (med && med.value === 'y') { out.push('Taking medicine'); }
            var box = $('#dnAlerts');
            box.innerHTML = out.length ? out.map(function (a) { return '<span class="du-alert-chip">&#9888; ' + esc(a) + '</span>'; }).join('') : '<span class="du-muted">No medical alerts recorded.</span>';
        }
        form.addEventListener('change', function (e) { updateConditionals(); if (e.target.name && e.target.name.indexOf('mh[') === 0) { updateAlerts(); } });
        form.addEventListener('input', function () { markDirty(); });
        form.addEventListener('change', function () { markDirty(); });
        form.addEventListener('submit', function () { submitting = true; });
        window.addEventListener('beforeunload', function (e) { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });
        updateConditionals(); updateAlerts();

        /* ---- odontogram ---- */
        odo = JSON.parse(JSON.stringify(D.odontogram || {}));
        var cond = D.conditions, stat = D.statuses;
        function kindOf(t) { return Number(t) < 50 ? 'permanent' : 'primary'; }
        function icon(color) {
            return '<svg class="du-t-ico" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="14" fill="' + color + '" stroke="#334155" stroke-width="1.5"/>' +
                   '<circle cx="16" cy="16" r="8" fill="#fff" stroke="#334155" stroke-width="1.2"/><circle cx="16" cy="16" r="3" fill="#334155" opacity=".5"/></svg>';
        }
        function toothHTML(t) {
            var v = odo[t] || {}, k = kindOf(t), c = cond[v.c], s = stat[v.s];
            var ccode = c ? (k === 'permanent' ? c[1] : c[2]) : '', scode = s ? (k === 'permanent' ? s[1] : s[2]) : '';
            var tip = 'Tooth ' + t + (c ? ' - ' + c[0] : '') + (s ? ' - ' + s[0] : '');
            return '<button type="button" class="du-tooth' + (v.s ? ' st-' + v.s : '') + (sel[t] ? ' is-sel' : '') + '" data-t="' + t + '" title="' + esc(tip) + '">' +
                   '<span class="du-t-num">' + t + '</span>' + icon(c ? c[3] : '#e2e8f0') +
                   '<span class="du-t-cell">' + esc(ccode) + '</span><span class="du-t-cell">' + esc(scode) + '</span></button>';
        }
        function rowHTML(list) {
            var h = Math.floor(list.length / 2);
            return '<div class="du-row">' + list.slice(0, h).map(toothHTML).join('') + '<span class="du-mid"></span>' + list.slice(h).map(toothHTML).join('') + '</div>';
        }
        function renderChart() {
            var html = '';
            ['permanent', 'primary'].forEach(function (set) {
                if (view !== 'both' && view !== set) { return; }
                html += '<div class="du-arch"><h5>' + (set === 'permanent' ? 'Permanent teeth (11-48)' : 'Primary / deciduous teeth (51-85)') + '</h5>' +
                        rowHTML(D.teeth[set].upper) + rowHTML(D.teeth[set].lower) + '</div>';
            });
            $('#dnChart').innerHTML = html;
            $('#dnOdoInput').value = JSON.stringify(odo);
            renderPicker(); renderCounts();
        }
        function selected() { return Object.keys(sel).filter(function (t) { return sel[t]; }); }
        function renderPicker() {
            var s = selected(), can = D.canEdit && s.length > 0;
            $('#dnPickerTitle').textContent = s.length === 0 ? 'No tooth selected' : (s.length === 1 ? 'Tooth ' + s[0] + ' (' + kindOf(s[0]) + ')' : s.length + ' teeth selected');
            var kinds = {}; s.forEach(function (t) { kinds[kindOf(t)] = true; });
            var codeIdx = function (def, perm, prim) { return kinds.permanent && kinds.primary ? def[perm] + ' / ' + def[prim] : (kinds.primary ? def[prim] : def[perm]); };
            var has = function (key, field) { return s.length > 0 && s.every(function (t) { return (odo[t] || {})[field] === key; }); };
            $('#dnCondOpts').innerHTML = Object.keys(cond).map(function (k) {
                return '<button type="button" class="du-opt' + (has(k, 'c') ? ' is-on' : '') + '" data-c="' + k + '"' + (can ? '' : ' disabled') + '><code>' + esc(s.length ? codeIdx(cond[k], 1, 2) : cond[k][1] + ' / ' + cond[k][2]) + '</code> ' + esc(cond[k][0]) + '</button>';
            }).join('');
            $('#dnStatOpts').innerHTML = Object.keys(stat).map(function (k) {
                return '<button type="button" class="du-opt' + (has(k, 's') ? ' is-on' : '') + '" data-s="' + k + '"' + (can ? '' : ' disabled') + '><code>' + esc(s.length ? codeIdx(stat[k], 1, 2) : stat[k][1] + ' / ' + stat[k][2]) + '</code> ' + esc(stat[k][0]) + '</button>';
            }).join('');
            $('#dnSelClear').disabled = !can;
        }
        var counts = {};
        function renderCounts() {
            var n = { carious_p: 0, carious_d: 0, restored: 0, sealed: 0, extraction: 0, missing_p: 0, missing_d: 0, unerupted_p: 0, unerupted_d: 0, rf: 0 };
            Object.keys(odo).forEach(function (t) {
                var v = odo[t], per = kindOf(t) === 'permanent';
                if (v.c === 'carious') { per ? n.carious_p++ : n.carious_d++; }
                if (['gic_restored', 'composite', 'amalgam', 'crown'].indexOf(v.c) !== -1) { n.restored++; }
                if (v.c === 'gic_sealed') { n.sealed++; }
                if (v.c === 'extraction') { n.extraction++; }
                if (v.s === 'missing') { per ? n.missing_p++ : n.missing_d++; }
                if (v.s === 'unerupted') { per ? n.unerupted_p++ : n.unerupted_d++; }
                if (v.s === 'root_fragment') { n.rf++; }
            });
            counts = n;
            var chips = [['Carious', n.carious_p + n.carious_d], ['Restored', n.restored], ['Sealed', n.sealed], ['For extraction', n.extraction],
                         ['Missing', n.missing_p + n.missing_d], ['Unerupted', n.unerupted_p + n.unerupted_d], ['Root fragments', n.rf]];
            $('#dnCounts').innerHTML = chips.map(function (c) { return '<span class="du-count"><b>' + c[1] + '</b>' + esc(c[0]) + '</span>'; }).join('');
        }
        $('#dnChart').addEventListener('click', function (e) {
            var b = e.target.closest('.du-tooth'); if (!b) { return; }
            var t = b.dataset.t, additive = multi || e.shiftKey || e.ctrlKey || e.metaKey;
            if (additive) { sel[t] = !sel[t]; } else { var was = sel[t] && selected().length === 1; sel = {}; sel[t] = !was; }
            renderChart();
        });
        function applyField(field, key) {
            var s = selected(); if (!s.length || !D.canEdit) { return; }
            var all = s.every(function (t) { return (odo[t] || {})[field] === key; });
            s.forEach(function (t) {
                odo[t] = odo[t] || { c: '', s: '' };
                odo[t][field] = all ? '' : key;
                if (!odo[t].c && !odo[t].s) { delete odo[t]; }
            });
            markDirty(); renderChart();
        }
        $('#dnPicker').addEventListener('click', function (e) {
            var b = e.target.closest('.du-opt'); if (!b || b.disabled) { return; }
            if (b.dataset.c) { applyField('c', b.dataset.c); } else if (b.dataset.s) { applyField('s', b.dataset.s); }
        });
        $('#dnSelClear').addEventListener('click', function () { selected().forEach(function (t) { delete odo[t]; }); markDirty(); renderChart(); });
        $('#dnAllSound').addEventListener('click', function () {
            if (!D.canEdit) { return; }
            var all = [].concat(D.teeth.permanent.upper, D.teeth.permanent.lower, D.teeth.primary.upper, D.teeth.primary.lower);
            all.forEach(function (t) { odo[t] = odo[t] || { c: '', s: '' }; if (!odo[t].s) { odo[t].s = 'sound'; } });
            markDirty(); renderChart();
        });
        $('#dnResetAll').addEventListener('click', function () {
            if (!D.canEdit || !Object.keys(odo).length) { return; }
            if (confirm('Clear every tooth on the chart?')) { odo = {}; sel = {}; markDirty(); renderChart(); }
        });
        $('#dnMulti').addEventListener('change', function (e) { multi = e.target.checked; });
        $$('#dnArchView button').forEach(function (b) {
            b.addEventListener('click', function () { view = b.dataset.view; $$('#dnArchView button').forEach(function (x) { x.classList.toggle('is-active', x === b); }); renderChart(); });
        });
        $('#dnArchView button[data-view="both"]').classList.add('is-active');
        $('#dnCopyCounts').addEventListener('click', function () {
            if (!D.canEdit) { return; }
            var set = function (name, v) { var el = form.querySelector('[name="' + name + '"]'); if (el) { el.value = v; el.classList.add('du-ocr-hit'); } };
            set('oral_health[caries_teeth]', counts.carious_p + counts.carious_d);
            var anyPerm = Object.keys(odo).some(function (t) { return kindOf(t) === 'permanent'; });
            var anyPrim = Object.keys(odo).some(function (t) { return kindOf(t) === 'primary'; });
            if (anyPerm || (D.age != null && D.age >= 6)) { set('oral_health[permanent_teeth]', Math.max(0, 32 - counts.missing_p - counts.unerupted_p)); }
            if (anyPrim || (D.age != null && D.age <= 12)) { set('oral_health[deciduous_teeth]', Math.max(0, 20 - counts.missing_d - counts.unerupted_d)); }
            markDirty();
            this.textContent = 'Copied to Oral Health Status (tab B)';
        });
        renderChart();

        /* ---- caries risk ---- */
        group = D.cariesGroup === 'child' ? 'child' : 'adult';
        answersBy[group] = JSON.parse(JSON.stringify(D.cariesAnswers || {}));
        finalTouched = !!D.cariesFinal;
        if (D.cariesFinal) { $('#dnFinal').value = D.cariesFinal; }

        function evaluate(grp, answers) {
            var secs = {}, H = 0, L = 0;
            Object.keys(D.caries[grp]).forEach(function (letter) {
                var h = 0, l = 0;
                D.caries[grp][letter].items.forEach(function (it) {
                    var a = answers[it.q]; if (a !== 'yes' && a !== 'no') { return; }
                    if (a === it.high) { h++; } else { l++; }
                });
                secs[letter] = { high: h, low: l, result: (h + l === 0) ? null : ((h >= l && h > 0) ? 'high' : 'low') };
                H += h; L += l;
            });
            var overall = (H + L === 0) ? null : (H >= L ? 'high' : (L - H >= 5 ? 'low' : 'moderate'));
            return { sections: secs, high: H, low: L, overall: overall };
        }
        function renderCaries() {
            var ans = answersBy[group] || (answersBy[group] = {});
            var html = '';
            Object.keys(D.caries[group]).forEach(function (letter) {
                var sec = D.caries[group][letter];
                html += '<div class="du-csec"><h3>' + esc(sec.title) + '</h3>';
                sec.items.forEach(function (it) {
                    var n = it.q.slice(1);
                    html += '<div class="du-crow" data-q="' + it.q + '"><div class="du-ctext">' + esc(n + '. ' + it.text) + '<small>High-risk answer: ' + (it.high === 'yes' ? 'Yes' : 'No') + '</small></div>' +
                            '<div class="du-yn"><label><input type="radio" name="caries[' + it.q + ']" value="yes"' + (ans[it.q] === 'yes' ? ' checked' : '') + '><span>Yes</span></label>' +
                            '<label><input type="radio" name="caries[' + it.q + ']" value="no"' + (ans[it.q] === 'no' ? ' checked' : '') + '><span>No</span></label></div></div>';
                });
                html += '</div>';
            });
            $('#dnCaries').innerHTML = html;
            $$('#dnCariesGroup button').forEach(function (b) { b.classList.toggle('is-active', b.dataset.group === group); });
            $('#dnCariesGroupInput').value = group;
            renderSummary();
        }
        function renderSummary() {
            var ans = answersBy[group] || {}, ev = evaluate(group, ans);
            var label = { high: 'HIGH', low: 'LOW', moderate: 'BETWEEN', null: '-' };
            var box = function (title, r, sub) { return '<div class="du-sumbox ' + (r ? 'is-' + r : '') + '"><span>' + esc(title) + '</span><b>' + label[r] + '</b><small>' + esc(sub) + '</small></div>'; };
            var s = ev.sections;
            $('#dnSumGrid').innerHTML =
                box('Disease factors', s.A.result, s.A.high + ' high / ' + s.A.low + ' low') +
                box('Socio-economic & behavioral', s.B.result, s.B.high + ' high / ' + s.B.low + ' low') +
                box('Protective factors', s.C.result, s.C.high + ' high / ' + s.C.low + ' low') +
                box('Overall (calculated)', ev.overall, ev.overall === 'moderate' ? 'Use clinical judgment' : ev.high + ' high / ' + ev.low + ' low');
            $$('#dnCaries .du-crow').forEach(function (row) {
                var q = row.dataset.q, a = ans[q], item = null;
                D.caries[group][q.charAt(0)].items.forEach(function (it) { if (it.q === q) { item = it; } });
                var old = row.querySelector('.du-pill'); if (old) { old.remove(); }
                if (item && (a === 'yes' || a === 'no')) {
                    var hi = a === item.high, p = document.createElement('span');
                    p.className = 'du-pill ' + (hi ? 'du-risk-high' : 'du-risk-low'); p.textContent = hi ? 'High' : 'Low'; p.style.marginLeft = '8px';
                    row.querySelector('.du-ctext').appendChild(p);
                }
            });
            var fin = $('#dnFinal');
            if (!finalTouched) { fin.value = (ev.overall === 'low' || ev.overall === 'high') ? ev.overall : ''; }
        }
        $('#dnCaries').addEventListener('change', function (e) {
            if (e.target.type !== 'radio') { return; }
            var q = e.target.name.replace(/^caries\[(.+)\]$/, '$1');
            answersBy[group][q] = e.target.value; renderSummary();
        });
        $('#dnFinal').addEventListener('change', function () { finalTouched = true; });
        $$('#dnCariesGroup button').forEach(function (b) {
            b.addEventListener('click', function () {
                var g = b.dataset.group; if (g === group || !D.canEdit) { return; }
                var has = Object.keys(answersBy[group] || {}).length > 0;
                if (has && !confirm('Switching the age form keeps the answers you entered for the other form, but only the form on screen is saved. Switch?')) { return; }
                group = g; renderCaries(); markDirty();
            });
        });
        renderCaries();

        /* ---- treatment: use a scanned row ---- */
        window.DN_useScannedTreatment = function (t) {
            var set = function (id, v) { var el = document.getElementById(id); if (el) { el.value = v || ''; } };
            if (t.date) { set('dnTDate', t.date); }
            set('dnTTeeth', t.teeth); set('dnTRefer', t.referrals); set('dnTRemarks', t.remarks);
            $$('#dnTreatForm input[type=checkbox]').forEach(function (c) { c.checked = (t.routine || []).indexOf(c.dataset.code) !== -1 || (t.other || []).indexOf(c.dataset.code) !== -1; });
            showTab('E');
        };
        window.DN_applyOcr = applyOcr;
        window.DN_showTab = showTab;
        window.DN_odoSet = function (map) {
            Object.keys(map).forEach(function (t) {
                var v = map[t], cur = odo[t] || { c: '', s: '' };
                odo[t] = { c: v.c || cur.c, s: v.s || cur.s };
            });
            renderChart();
        };
        window.DN_setCaries = function (grp, answers, date) {
            if (grp === 'child' || grp === 'adult') { group = grp; }
            answersBy[group] = Object.assign({}, answersBy[group] || {}, answers);
            if (date) { var d = $('#dnCariesDate'); if (d) { d.value = date; } }
            finalTouched = false; renderCaries();
        };

        // A scan started on the list page is waiting for this chart
        try {
            var pending = sessionStorage.getItem('dnOcr');
            if (pending) { sessionStorage.removeItem('dnOcr'); applyOcr(JSON.parse(pending)); }
        } catch (e) { /* ignore */ }
    }

    /* ------------------------------------------------------------------
     * Apply merged OCR output to the open chart
     * ---------------------------------------------------------------- */
    function norm(s) { return String(s || '').toLowerCase().replace(/[^a-z\u00f1]/g, ''); }
    function dotToName(key) { var p = key.split('.'); return p[0] + p.slice(1).map(function (x) { return '[' + x + ']'; }).join(''); }
    function setByName(name, val) {
        var els = $$('[name="' + name + '"]', form);
        if (!els.length) { return false; }
        var first = els[0];
        if (first.type === 'radio') {
            var want = (String(val).toLowerCase() === 'yes' || val === 'y') ? 'y' : ((String(val).toLowerCase() === 'no' || val === 'n') ? 'n' : String(val));
            els.forEach(function (r) { r.checked = (r.value === want); if (r.checked) { r.parentNode.classList.add('du-ocr-hit'); } });
        } else if (first.type === 'checkbox') {
            first.checked = true; first.parentNode.classList.add('du-ocr-hit');
        } else {
            first.value = val; first.classList.add('du-ocr-hit');
        }
        return true;
    }
    function banner(html, warn) { var b = $('#dnOcrBanner'); if (!b) { return; } b.className = 'du-ocr-banner' + (warn ? ' du-warn' : ''); b.innerHTML = html; b.hidden = false; b.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }

    function applyOcr(m) {
        if (!D || !form || !D.canEdit) { return; }
        var f = m.fields || {};
        var sl = f['patient.last_name'], sf = f['patient.first_name'];
        if (sl && sf && (norm(sl) !== norm(D.patientLast) || norm(sf) !== norm(D.patientFirst))) {
            if (!confirm('The scanned forms look like they belong to "' + sf + ' ' + sl + '", but this chart is for "' + D.patientFirst + ' ' + D.patientLast + '".\n\nFill this chart anyway?')) { return; }
        }
        var filled = 0;
        Object.keys(f).forEach(function (k) { if (k.indexOf('patient.') === 0) { return; } if (setByName(dotToName(k), f[k])) { filled++; } });
        var teeth = Object.keys(m.odontogram || {}).length;
        if (teeth && window.DN_odoSet) { window.DN_odoSet(m.odontogram); }
        var cq = Object.keys(m.caries || {}).length;
        if (cq && window.DN_setCaries) { window.DN_setCaries(m.caries_group, m.caries, m.caries_date); }
        var trs = m.treatments || [];
        if (trs.length) { renderScannedTreatments(trs); }
        $$('input[type=radio]', form).length && form.dispatchEvent(new Event('change', { bubbles: true }));
        markDirty();
        banner('<strong>Scan results added to this chart:</strong> ' + filled + ' form field(s), ' + teeth + ' tooth chart entr' + (teeth === 1 ? 'y' : 'ies') + ', ' + cq + ' caries answer(s)' +
               (trs.length ? ', ' + trs.length + ' treatment row(s) (see tab E)' : '') + '. Highlighted fields came from the scan &mdash; <b>please check them against the paper form, then press Save chart.</b> Handwriting can be misread.', false);
    }

    function renderScannedTreatments(rows) {
        var box = $('#dnScannedTreat'); if (!box) { return; }
        window.DN_scanRows = rows;
        box.hidden = false;
        box.innerHTML = '<h2 class="du-card-title">Scanned treatment rows <small>- check, then use one to fill the form above</small></h2><div class="du-table-wrap"><table class="du-table du-table-sm"><thead><tr><th>Date</th><th>Routine</th><th>Other</th><th>Tooth</th><th>Referrals</th><th>Remarks</th><th></th></tr></thead><tbody>' +
            rows.map(function (r, i) {
                return '<tr><td>' + esc(r.date || '?') + '</td><td>' + esc((r.routine || []).join(', ')) + '</td><td>' + esc((r.other || []).join(', ')) + '</td><td>' + esc(r.teeth) + '</td><td>' + esc(r.referrals) + '</td><td>' + esc(r.remarks) +
                       '</td><td><button type="button" class="du-btn du-btn-ghost du-btn-sm" data-use="' + i + '">Use this row</button></td></tr>';
            }).join('') + '</tbody></table></div>';
        box.onclick = function (e) { var b = e.target.closest('[data-use]'); if (b && window.DN_useScannedTreatment) { window.DN_useScannedTreatment(window.DN_scanRows[Number(b.dataset.use)]); } };
    }

    /* ------------------------------------------------------------------
     * OCR MODAL (list + chart pages)
     * ---------------------------------------------------------------- */
    var modal = $('#dnOcrModal');
    if (modal && CFG.canOcr) {
        var files = [], running = false;
        var fileInput = $('#dnFiles'), listEl = $('#dnFileList'), resEl = $('#dnOcrResult'), startBtn = $('#dnOcrStart');
        var openers = $$('#dnOpenOcr');
        function openModal() { modal.hidden = false; document.body.style.overflow = 'hidden'; }
        function closeModal() { if (running && !confirm('A scan is still running. Close anyway?')) { return; } modal.hidden = true; document.body.style.overflow = ''; }
        openers.forEach(function (b) { b.addEventListener('click', openModal); });
        $('#dnOcrClose').addEventListener('click', closeModal); $('#dnOcrCancel').addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { closeModal(); } });

        function renderFiles() {
            listEl.innerHTML = files.map(function (f, i) {
                var thumb = (f.file.type.indexOf('image/') === 0 && f.file.type !== 'image/heic' && f.file.type !== 'image/heif') ? '<img alt="" src="' + f.url + '">' : '<span style="width:44px;text-align:center;">&#128196;</span>';
                return '<li data-i="' + i + '">' + thumb + '<span class="du-fname">' + esc((i + 1) + '. ' + f.file.name) + '</span><span class="du-fstat ' + f.cls + '">' + esc(f.status) + '</span>' +
                       (running ? '' : '<button type="button" class="du-frm" data-rm="' + i + '" aria-label="Remove">&times;</button>') + '</li>';
            }).join('');
            startBtn.disabled = running || files.length === 0;
        }
        function addFiles(list) {
            Array.prototype.forEach.call(list, function (file) {
                if (files.length >= 8) { return; }
                files.push({ file: file, url: (file.type.indexOf('image/') === 0) ? URL.createObjectURL(file) : '', status: 'Ready', cls: '' });
            });
            renderFiles();
        }
        fileInput.addEventListener('change', function () { addFiles(fileInput.files); fileInput.value = ''; });
        var drop = $('#dnDrop');
        ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
        drop.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });
        // The <label> opens the file picker natively; no click handler (a preventDefault here blocked the picker).
        listEl.addEventListener('click', function (e) { var b = e.target.closest('[data-rm]'); if (b && !running) { files.splice(Number(b.dataset.rm), 1); renderFiles(); } });

        // Big phone photos are shrunk in the browser first: faster upload, same legibility.
        function shrink(file) {
            return new Promise(function (resolve) {
                if (file.type.indexOf('image/') !== 0 || file.size < 1500000 || file.type === 'image/heic' || file.type === 'image/heif') { return resolve(file); }
                var img = new Image(), url = URL.createObjectURL(file);
                img.onload = function () {
                    var max = 2400, s = Math.min(1, max / Math.max(img.width, img.height));
                    var c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    URL.revokeObjectURL(url);
                    c.toBlob(function (b) { resolve(b ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.88);
                };
                img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
                img.src = url;
            });
        }

        var merged;
        function mergeInto(r) {
            Object.keys(r.fields || {}).forEach(function (k) { if (!(k in merged.fields)) { merged.fields[k] = r.fields[k]; } });
            Object.keys(r.odontogram || {}).forEach(function (t) {
                var cur = merged.odontogram[t] || { c: '', s: '' }, v = r.odontogram[t];
                merged.odontogram[t] = { c: cur.c || v.c, s: cur.s || v.s };
            });
            Object.keys(r.caries || {}).forEach(function (q) { if (!(q in merged.caries)) { merged.caries[q] = r.caries[q]; } });
            if (r.caries_group && !merged.caries_group) { merged.caries_group = r.caries_group; }
            if (r.caries_date && !merged.caries_date) { merged.caries_date = r.caries_date; }
            (r.treatments || []).forEach(function (t) { var j = JSON.stringify(t); if (!merged._seen[j]) { merged._seen[j] = 1; merged.treatments.push(t); } });
            if (r.matched_patient && !merged.matched) { merged.matched = r.matched_patient; }
        }

        startBtn.addEventListener('click', async function () {
            if (running || !files.length) { return; }
            running = true; merged = { fields: {}, odontogram: {}, caries: {}, caries_group: '', caries_date: '', treatments: [], matched: null, _seen: {} };
            resEl.hidden = true; files.forEach(function (f) { f.status = 'Waiting'; f.cls = ''; }); renderFiles();
            var okPages = 0;
            for (var i = 0; i < files.length; i++) {
                files[i].status = 'Reading...'; files[i].cls = 'run'; renderFiles();
                try {
                    var blob = await shrink(files[i].file);
                    var fd = new FormData(); fd.append('ocr_file', blob, blob.name || files[i].file.name);
                    var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 170000);
                    var resp = await fetch(CFG.scanUrl, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctl.signal });
                    clearTimeout(timer);
                    var data; try { data = await resp.json(); } catch (e) { data = { success: false, error: 'The server sent an unexpected reply (HTTP ' + resp.status + ').' }; }
                    if (data.success) {
                        mergeInto(data); okPages++;
                        var n = Object.keys(data.fields || {}).length + Object.keys(data.odontogram || {}).length + Object.keys(data.caries || {}).length + (data.treatments || []).length;
                        files[i].status = n ? 'Done - ' + n + ' item(s)' : 'Done - nothing readable'; files[i].cls = n ? 'ok' : '';
                    } else { files[i].status = data.error || 'Failed'; files[i].cls = 'err'; }
                } catch (e) { files[i].status = (e && e.name === 'AbortError') ? 'Timed out' : 'Network error'; files[i].cls = 'err'; }
                renderFiles();
            }
            running = false; renderFiles(); finish(okPages);
        });

        function finish(okPages) {
            delete merged._seen;
            var total = Object.keys(merged.fields).length + Object.keys(merged.odontogram).length + Object.keys(merged.caries).length + merged.treatments.length;
            resEl.hidden = false;
            if (!okPages || !total) {
                resEl.innerHTML = '<strong>Nothing could be read.</strong> Check the photos are sharp, well lit and show the whole page, then try again. Some pages may also have failed (see the list above).';
                return;
            }
            if (CFG.onChart) {
                resEl.innerHTML = '<strong>Done.</strong> ' + total + ' item(s) read from ' + okPages + ' page(s). They are being added to this chart now.';
                applyOcr(merged);
                setTimeout(function () { modal.hidden = true; document.body.style.overflow = ''; }, 900);
                return;
            }
            // List page: go to the matching patient's chart (or let the user choose)
            var go = function (id) { try { sessionStorage.setItem('dnOcr', JSON.stringify(merged)); } catch (e) { /* ignore */ } location.href = 'dental.php?patient=' + id; };
            if (merged.matched) {
                resEl.innerHTML = '<strong>Done.</strong> ' + total + ' item(s) read. These forms match <b>' + esc(merged.matched.full_name) + '</b>.<div style="margin-top:10px;"><button type="button" class="du-btn du-btn-primary" id="dnGoMatched">Open ' + esc(merged.matched.first_name) + '\'s chart and review</button></div>';
                $('#dnGoMatched').addEventListener('click', function () { go(merged.matched.id); });
            } else {
                var opts = ps ? ps.innerHTML : '';
                var nm = ((merged.fields['patient.first_name'] || '') + ' ' + (merged.fields['patient.last_name'] || '')).trim();
                resEl.innerHTML = '<strong>Done.</strong> ' + total + ' item(s) read' + (nm ? ' for <b>' + esc(nm) + '</b>' : '') + ', but no registered patient matched. Choose the patient (register them first if they are new):' +
                    '<div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;"><select id="dnPickPatient" style="flex:1 1 240px;">' + opts + '</select><button type="button" class="du-btn du-btn-primary" id="dnGoPicked">Open chart and review</button></div>';
                $('#dnGoPicked').addEventListener('click', function () { var v = $('#dnPickPatient').value; if (v) { go(v); } else { alert('Choose a patient first.'); } });
            }
        }
        renderFiles();
    }
})();
