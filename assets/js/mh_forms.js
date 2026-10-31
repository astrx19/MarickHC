/* ==============================================================
   MARICK HEALTH CENTER - SHARED FORM HELPERS  (Step 4)
   assets/js/mh_forms.js   (loaded by includes/modal_assets.php, after mh_ui.js)

   Automatic on every page:
     - password fields get the show/hide "eye" button (skip with data-no-eye)
     - Confirm-password fields get a live "match / don't match" message
     - <input data-mh-digits="12">  digits only, hard maximum of 12 (PhilHealth)
     - birthdate dates are limited to 1900-01-01 .. today (no 234234-year, no future)
   API (window):
     mhClearErrors(root)                     remove every red highlight + inline message
     mhMark(el, message)                     red highlight (+ optional inline message) on a field
     mhReport(errors, title)                 one modal that lists ALL problems; focuses the first
   An "error" is { el: <element>, label: 'Birthdate', msg: 'must be a real date ...' }.
   ============================================================== */
(function (window, document) {
    'use strict';
    if (window.mhReport) { return; }

    var EYE = '<svg class="on" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
            + '<svg class="off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function todayStr() { var t = new Date(); return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()); }

    /* ---------- password eye ---------- */
    function initEyes(root) {
        var list = (root || document).querySelectorAll('input[type="password"]:not([data-no-eye])');
        Array.prototype.forEach.call(list, function (inp) {
            var p = inp.parentNode;
            if (!p || p.classList.contains('mh-pw') || p.querySelector('.pw-toggle, .mh-eye')) { return; }
            var wrap = document.createElement('div');
            wrap.className = 'mh-pw';
            p.insertBefore(wrap, inp);
            wrap.appendChild(inp);
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mh-eye';
            b.setAttribute('aria-label', 'Show password');
            b.setAttribute('aria-pressed', 'false');
            b.innerHTML = EYE;
            wrap.appendChild(b);
        });
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.mh-eye') : null;
        if (!btn) { return; }
        var input = btn.parentNode.querySelector('input');
        if (!input) { return; }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('is-on', show);
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        input.focus();
    });

    /* ---------- red highlight + inline message ---------- */
    function holder(el) {   // what gets the red outline (radio groups outline their container)
        if (el.type === 'radio') { return el.closest('.form-group') || el.parentNode; }
        return el;
    }
    function msgAnchor(el) {
        var h = holder(el);
        return h.closest('.mh-pw') || h;
    }
    function clearOne(el) {
        var h = holder(el);
        h.classList.remove('mh-invalid', 'mh-invalid-group');
        var a = msgAnchor(el), n = a.nextElementSibling;
        if (n && n.classList && n.classList.contains('mh-field-msg') && n.getAttribute('data-kind') === 'error') { n.parentNode.removeChild(n); }
    }
    window.mhClearErrors = function (root) {
        root = root || document;
        Array.prototype.forEach.call(root.querySelectorAll('.mh-invalid, .mh-invalid-group'), function (h) {
            h.classList.remove('mh-invalid', 'mh-invalid-group');
        });
        Array.prototype.forEach.call(root.querySelectorAll('.mh-field-msg[data-kind="error"]'), function (n) { n.parentNode.removeChild(n); });
    };
    window.mhMark = function (el, message) {
        if (!el) { return; }
        var h = holder(el);
        h.classList.add(el.type === 'radio' ? 'mh-invalid-group' : 'mh-invalid');
        if (message) {
            var a = msgAnchor(el), n = a.nextElementSibling;
            if (!(n && n.classList && n.classList.contains('mh-field-msg') && n.getAttribute('data-kind') === 'error')) {
                n = document.createElement('small');
                n.className = 'mh-field-msg';
                n.setAttribute('data-kind', 'error');
                n.setAttribute('role', 'alert');
                a.parentNode.insertBefore(n, a.nextSibling);
            }
            n.textContent = message;
        }
        h.classList.remove('mh-shake'); void h.offsetWidth; h.classList.add('mh-shake');
    };
    // typing in a flagged field clears its flag
    ['input', 'change'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            var t = e.target;
            if (t && t.nodeType === 1 && t.matches && t.matches('input, select, textarea')) { clearOne(t); }
        });
    });

    /* One modal listing EVERY problem (so people fix them in one go), then focus the first field. */
    window.mhReport = function (errors, title) {
        if (!errors || !errors.length) { return Promise.resolve(); }
        errors.forEach(function (er) { window.mhMark(er.el, er.msg); });
        var html = '<p style="margin:0 0 6px">Please correct ' + (errors.length === 1 ? 'this' : 'these ' + errors.length) + ' before continuing:</p><ul class="mh-error-list">'
            + errors.map(function (er) { return '<li><strong>' + esc(er.label) + '</strong> &ndash; ' + esc(er.msg) + '</li>'; }).join('')
            + '</ul>';
        var first = errors[0].el;
        var done = function () { try { first.focus({ preventScroll: false }); if (first.scrollIntoView) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); } } catch (e) {} };
        if (window.mh_alert) {
            return window.mh_alert(html, title || 'Please check your details', 'warning', { html: true }).then(done);
        }
        done();
        return Promise.resolve();
    };

    /* ---------- digits-only fields (PhilHealth = 12) ---------- */
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.getAttribute || !t.hasAttribute('data-mh-digits')) { return; }
        var max = parseInt(t.getAttribute('data-mh-digits'), 10) || 12;
        var clean = String(t.value).replace(/\D+/g, '').slice(0, max);
        if (clean !== t.value) { t.value = clean; }
    });
    document.addEventListener('paste', function (e) {   // pasting "12-3456789012-3" keeps the digits
        var t = e.target;
        if (!t || !t.getAttribute || !t.hasAttribute('data-mh-digits')) { return; }
        var max = parseInt(t.getAttribute('data-mh-digits'), 10) || 12;
        var text = (e.clipboardData || window.clipboardData).getData('text') || '';
        e.preventDefault();
        t.value = text.replace(/\D+/g, '').slice(0, max);
        t.dispatchEvent(new Event('input', { bubbles: true }));
    });

    /* ---------- birthdate guard: 1900-01-01 .. today ---------- */
    var MIN_DATE = '1900-01-01';
    function dateProblem(v) {
        if (!v) { return ''; }
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
        if (!m) { return 'Enter a real date - the year must have exactly 4 digits.'; }
        var d = new Date(+m[1], +m[2] - 1, +m[3]);
        if (d.getFullYear() !== +m[1] || d.getMonth() !== +m[2] - 1 || d.getDate() !== +m[3]) { return 'That date does not exist.'; }
        if (v < MIN_DATE) { return 'The year cannot be earlier than 1900.'; }
        if (v > todayStr()) { return 'The date cannot be in the future.'; }
        return '';
    }
    window.mhBirthdateProblem = dateProblem;
    function isBirthField(el) { return el.type === 'date' && (el.hasAttribute('data-mh-date') || /birth|bday|bdate/i.test((el.name || '') + ' ' + (el.id || ''))); }
    function initDates() {
        Array.prototype.forEach.call(document.querySelectorAll('input[type="date"]'), function (el) {
            if (!isBirthField(el)) { return; }
            if (!el.getAttribute('min')) { el.setAttribute('min', MIN_DATE); }
            el.setAttribute('max', todayStr());   // refresh: a page left open overnight still has the right "today"
            el.setAttribute('data-mh-date', '1');
        });
    }
    function checkDateField(el) {
        if (!el.hasAttribute || !el.hasAttribute('data-mh-date')) { return; }
        var raw = el.value;
        // Chrome lets people type a 5-6 digit year ("234234-05-10"). Keep 4 digits so the message is clear.
        var big = /^(\d{5,})-(\d{2})-(\d{2})$/.exec(raw);
        if (big) { el.value = ''; window.mhMark(el, 'The year must have exactly 4 digits.'); return; }
        var p = dateProblem(raw);
        if (p) { el.value = ''; window.mhMark(el, p); }
    }
    document.addEventListener('change', function (e) { if (e.target && e.target.type === 'date') { checkDateField(e.target); } });
    document.addEventListener('blur', function (e) { if (e.target && e.target.type === 'date') { checkDateField(e.target); } }, true);

    /* ---------- confirm-password: live "match" text ---------- */
    function bindPasswordMatch(form) {
        var confirm = form.querySelector('input[name="confirm_password"]');
        var pwd = form.querySelector('input[name="new_password"]') || form.querySelector('input[name="password"]');
        if (!confirm || !pwd || confirm.getAttribute('data-mh-bound')) { return; }
        confirm.setAttribute('data-mh-bound', '1');
        var note = document.createElement('small');
        note.className = 'mh-field-msg';
        note.setAttribute('data-kind', 'match');
        note.setAttribute('aria-live', 'polite');
        note.style.display = 'none';
        var anchor = confirm.closest('.mh-pw') || confirm;
        anchor.parentNode.insertBefore(note, anchor.nextSibling);
        function update() {
            if (confirm.value === '') { note.style.display = 'none'; confirm.classList.remove('mh-invalid'); return; }
            var ok = confirm.value === pwd.value;
            note.style.display = 'block';
            note.className = 'mh-field-msg' + (ok ? ' ok' : '');
            note.textContent = ok ? 'Passwords match.' : 'Passwords do not match.';
            confirm.classList.toggle('mh-invalid', !ok);
        }
        pwd.addEventListener('input', update);
        confirm.addEventListener('input', update);
    }

    function init() {
        initEyes();
        initDates();
        Array.prototype.forEach.call(document.querySelectorAll('form'), bindPasswordMatch);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
    window.addEventListener('pageshow', initDates);

})(window, document);
