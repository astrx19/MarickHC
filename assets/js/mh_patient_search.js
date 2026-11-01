/* ==============================================================
   MARICK HEALTH CENTER - "FIND A REGISTERED PATIENT" PICKER  (Step 6)
   assets/js/mh_patient_search.js

   Enhances every <div class="mh-ps"> rendered by mh_render_patient_name_fields():
   type 2+ letters of a name -> pick the registered patient -> the Last / First / MI boxes are filled,
   the exact patient id is remembered (hidden #pnPickedId) and the event "mh:patient-picked" fires
   with {id, first_name, middle_name, last_name, birthdate, age, sex} so each page can react.
   Editing the name afterwards un-links the patient (the name no longer matches the pick).
   ============================================================== */
(function (window, document) {
    'use strict';
    if (window.mhPatientSearchReady) { return; }
    window.mhPatientSearchReady = true;

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

    function init(root) {
        var input = root.querySelector('.mh-ps-input');
        var list  = root.querySelector('.mh-ps-list');
        var badge = root.querySelector('.mh-ps-badge');
        var hid   = document.getElementById('pnPickedId');
        var url   = root.getAttribute('data-url') || 'api/patient_search.php';
        if (!input || !list) { return; }
        var items = [], active = -1, seq = 0, pickedName = '';

        function hide() { list.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); }
        function show() { list.hidden = false; input.setAttribute('aria-expanded', 'true'); }
        function mark() {
            Array.prototype.forEach.call(list.children, function (li, i) { li.classList.toggle('is-active', i === active); li.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
            if (active >= 0 && list.children[active]) { list.children[active].scrollIntoView({ block: 'nearest' }); }
        }
        function render(rows, q) {
            items = rows; active = -1;
            if (!rows.length) { list.innerHTML = '<li class="mh-ps-empty">No registered patient matches "' + esc(q) + '". You can still type a new name below.</li>'; show(); return; }
            list.innerHTML = rows.map(function (r, i) {
                var mi = r.middle_name ? ' ' + esc(r.middle_name.charAt(0).toUpperCase()) + '.' : '';
                return '<li role="option" data-i="' + i + '"><strong>' + esc(r.last_name) + ', ' + esc(r.first_name) + mi + '</strong>'
                     + '<span>' + (r.age !== null ? esc(r.age) + ' yrs' : '') + (r.sex ? ' &middot; ' + esc(r.sex) : '') + ' &middot; #' + esc(r.id) + '</span></li>';
            }).join('');
            show();
        }
        function pick(r) {
            if (typeof window.mhSetPatientName === 'function') { window.mhSetPatientName(r.first_name, r.middle_name, r.last_name); }
            if (hid) { hid.value = r.id; }
            pickedName = (document.getElementById('pnLast') || {}).value + '|' + (document.getElementById('pnFirst') || {}).value;
            input.value = '';
            hide();
            if (badge) {
                badge.hidden = false;
                badge.innerHTML = '<span>Linked to registered patient <strong>#' + esc(r.id) + '</strong> &ndash; ' + esc(r.last_name) + ', ' + esc(r.first_name) + '</span>'
                                + '<button type="button" class="mh-ps-clear" aria-label="Unlink patient">&times;</button>';
            }
            document.dispatchEvent(new CustomEvent('mh:patient-picked', { detail: r }));
        }
        function unlink() {
            if (hid) { hid.value = ''; }
            if (badge) { badge.hidden = true; badge.innerHTML = ''; }
            document.dispatchEvent(new CustomEvent('mh:patient-unlinked'));
        }

        var search = debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { hide(); return; }
            var mine = ++seq;
            fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (mine !== seq) { return; }   // a newer keystroke already replaced this answer
                    if (!res.ok) { list.innerHTML = '<li class="mh-ps-empty">' + esc(res.j.error || 'Search is unavailable.') + '</li>'; show(); return; }
                    render(res.j.results || [], q);
                })
                .catch(function () { if (mine === seq) { list.innerHTML = '<li class="mh-ps-empty">Could not reach the server. Check your connection.</li>'; show(); } });
        }, 250);

        input.addEventListener('input', search);
        input.addEventListener('keydown', function (e) {
            if (list.hidden) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); mark(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); mark(); }
            else if (e.key === 'Enter') { if (active >= 0 && items[active]) { e.preventDefault(); pick(items[active]); } else if (items.length === 1) { e.preventDefault(); pick(items[0]); } else { e.preventDefault(); } }
            else if (e.key === 'Escape') { e.stopPropagation(); hide(); }
        });
        list.addEventListener('mousedown', function (e) {   // mousedown: fires before the input's blur hides the list
            var li = e.target.closest ? e.target.closest('li[data-i]') : null;
            if (li) { e.preventDefault(); pick(items[parseInt(li.getAttribute('data-i'), 10)]); }
        });
        input.addEventListener('blur', function () { setTimeout(hide, 120); });
        if (badge) { badge.addEventListener('click', function (e) { if (e.target.classList.contains('mh-ps-clear')) { unlink(); } }); }

        // Typing over the filled-in name means it is no longer the picked patient.
        ['pnLast', 'pnFirst', 'pnMI'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.addEventListener('input', function () {
                var now = (document.getElementById('pnLast') || {}).value + '|' + (document.getElementById('pnFirst') || {}).value;
                if (hid && hid.value && now !== pickedName) { unlink(); }
            }); }
        });
    }

    function boot() { Array.prototype.forEach.call(document.querySelectorAll('.mh-ps'), init); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
