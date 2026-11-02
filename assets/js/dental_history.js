/* ==============================================================
   DENTAL - Tooth chart history (Part 2)    assets/js/dental_history.js
   Reads dental.php?ajax=snapshots and shows what changed between two dates.
   ============================================================== */
(function () {
    'use strict';
    var host = document.getElementById('dnHistory');
    if (!host) { return; }
    var body = document.getElementById('dnHistoryBody');
    var pid = host.getAttribute('data-patient');
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmt(d) { var t = new Date(String(d).replace(' ', 'T')); return isNaN(t) ? d : t.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }

    var data = null;
    function label(entry) {
        if (!entry) { return 'Nothing recorded'; }
        var parts = [];
        if (entry.c) { parts.push(data.conditions[entry.c] || entry.c); }
        if (entry.s) { parts.push(data.statuses[entry.s] || entry.s); }
        return parts.length ? parts.join(' + ') : 'Nothing recorded';
    }
    function sourceName(s) { return s.source === 'visit' ? 'Dental record' : 'Chart saved'; }
    function optionText(s) { return fmt(s.taken_at) + ' - ' + sourceName(s) + (s.by ? ' (' + s.by + ')' : ''); }

    function renderDiff(fromIdx, toIdx) {
        var list = data.snapshots, a = list[fromIdx], b = list[toIdx], out = '';
        if (!a || !b) { return ''; }
        var teeth = {};
        Object.keys(a.odontogram).concat(Object.keys(b.odontogram)).forEach(function (t) { teeth[t] = true; });
        var rows = Object.keys(teeth).sort(function (x, y) { return x - y; }).filter(function (t) { return label(a.odontogram[t]) !== label(b.odontogram[t]); });
        if (!rows.length) { return '<p class="du-muted">No tooth changed between these two dates.</p>'; }
        out += '<div class="du-table-wrap"><table class="du-table du-table-sm"><thead><tr><th>Tooth</th><th>Before</th><th>After</th></tr></thead><tbody>';
        rows.forEach(function (t) {
            out += '<tr><td><b>' + esc(t) + '</b></td><td>' + esc(label(a.odontogram[t])) + '</td><td><strong>' + esc(label(b.odontogram[t])) + '</strong></td></tr>';
        });
        return out + '</tbody></table></div>';
    }

    function render() {
        var list = data.snapshots;
        if (!list.length) { body.innerHTML = '<p class="du-muted">No tooth chart has been saved for this patient yet. Save the chart (or record a visit that updates a tooth) and its history starts here.</p>'; return; }
        if (list.length === 1) {
            var only = list[0], keys = Object.keys(only.odontogram).sort(function (x, y) { return x - y; });
            body.innerHTML = '<p class="du-muted">One snapshot so far (' + esc(optionText(only)) + '). Changes will be listed here after the next update.</p>'
                + '<div class="dv-chips">' + keys.map(function (t) { return '<span class="mh-badge mh-badge-info">' + esc(t) + ': ' + esc(label(only.odontogram[t])) + '</span>'; }).join(' ') + '</div>';
            return;
        }
        var opt = function (sel) { return list.map(function (s, i) { return '<option value="' + i + '"' + (i === sel ? ' selected' : '') + '>' + esc(optionText(s)) + '</option>'; }).join(''); };
        body.innerHTML = '<div class="dv-cmp"><label>Compare <select id="dnHFrom">' + opt(1) + '</select></label>'
            + '<label>with <select id="dnHTo">' + opt(0) + '</select></label></div><div id="dnHDiff"></div>'
            + '<h4 class="du-sub" style="margin-top:16px;">All snapshots</h4><ul class="dv-snaplist">' + list.map(function (s) {
                return '<li><strong>' + esc(fmt(s.taken_at)) + '</strong> &middot; ' + esc(sourceName(s)) + (s.by ? ' &middot; ' + esc(s.by) : '')
                     + (s.visit ? '<br><small class="du-muted">Dental record: ' + esc(s.visit) + '</small>' : '') + '</li>'; }).join('') + '</ul>';
        var from = document.getElementById('dnHFrom'), to = document.getElementById('dnHTo'), diff = document.getElementById('dnHDiff');
        var upd = function () { diff.innerHTML = renderDiff(parseInt(from.value, 10), parseInt(to.value, 10)); };
        from.addEventListener('change', upd); to.addEventListener('change', upd); upd();
    }

    fetch('dental.php?ajax=snapshots&patient=' + encodeURIComponent(pid), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.error) { body.innerHTML = '<p class="du-muted">' + esc(j.error) + '</p>'; return; } data = j; render(); })
        .catch(function () { body.innerHTML = '<p class="du-muted">The history could not be loaded. Reload the page to try again.</p>'; });
})();
