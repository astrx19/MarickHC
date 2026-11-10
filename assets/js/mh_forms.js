(function (window, document) {
    'use strict';
    if (window.mhReport) {
        return;
    }
    var EYE = '<svg class="on" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
        + '<svg class="off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function todayStr() { var t = new Date(); return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()); }
    function initEyes(root) {
        var list = (root || document).querySelectorAll('input[type="password"]:not([data-no-eye])');
        Array.prototype.forEach.call(list, function (inp) {
            var p = inp.parentNode;
            if (!p || p.classList.contains('mh-pw') || p.querySelector('.pw-toggle, .mh-eye')) {
                return;
            }
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
        if (!btn) {
            return;
        }
        var input = btn.parentNode.querySelector('input');
        if (!input) {
            return;
        }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('is-on', show);
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        input.focus();
    });
    function holder(el) {
        if (el.type === 'radio') {
            return el.closest('.form-group') || el.parentNode;
        }
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
        if (n && n.classList && n.classList.contains('mh-field-msg') && n.getAttribute('data-kind') === 'error') {
            n.parentNode.removeChild(n);
        }
    }
    window.mhClearErrors = function (root) {
        root = root || document;
        Array.prototype.forEach.call(root.querySelectorAll('.mh-invalid, .mh-invalid-group'), function (h) {
            h.classList.remove('mh-invalid', 'mh-invalid-group');
        });
        Array.prototype.forEach.call(root.querySelectorAll('.mh-field-msg[data-kind="error"]'), function (n) { n.parentNode.removeChild(n); });
    };
    window.mhMark = function (el, message) {
        if (!el) {
            return;
        }
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
        h.classList.remove('mh-shake');
        void h.offsetWidth;
        h.classList.add('mh-shake');
    };
    ['input', 'change'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            var t = e.target;
            if (t && t.nodeType === 1 && t.matches && t.matches('input, select, textarea')) {
                clearOne(t);
            }
        });
    });
    window.mhReport = function (errors, title) {
        if (!errors || !errors.length) {
            return Promise.resolve();
        }
        errors.forEach(function (er) { window.mhMark(er.el, er.msg); });
        var html = '<p style="margin:0 0 6px">Please correct ' + (errors.length === 1 ? 'this' : 'these ' + errors.length) + ' before continuing:</p><ul class="mh-error-list">'
            + errors.map(function (er) { return '<li><strong>' + esc(er.label) + '</strong> &ndash; ' + esc(er.msg) + '</li>'; }).join('')
            + '</ul>';
        var first = errors[0].el;
        var done = function () { try {
            first.focus({ preventScroll: false });
            if (first.scrollIntoView) {
                first.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
        catch (e) { } };
        if (window.mh_alert) {
            return window.mh_alert(html, title || 'Please check your details', 'warning', { html: true }).then(done);
        }
        done();
        return Promise.resolve();
    };
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.getAttribute || !t.hasAttribute('data-mh-digits')) {
            return;
        }
        var max = parseInt(t.getAttribute('data-mh-digits'), 10) || 12;
        var clean = String(t.value).replace(/\D+/g, '').slice(0, max);
        if (clean !== t.value) {
            t.value = clean;
        }
    });
    document.addEventListener('paste', function (e) {
        var t = e.target;
        if (!t || !t.getAttribute || !t.hasAttribute('data-mh-digits')) {
            return;
        }
        var max = parseInt(t.getAttribute('data-mh-digits'), 10) || 12;
        var text = (e.clipboardData || window.clipboardData).getData('text') || '';
        e.preventDefault();
        t.value = text.replace(/\D+/g, '').slice(0, max);
        t.dispatchEvent(new Event('input', { bubbles: true }));
    });
    function cleanDecimal(el) {
        var places = parseInt(el.getAttribute('data-mh-decimal'), 10);
        var v = String(el.value).replace(/[^0-9.]/g, '');
        var parts = v.split('.');
        v = parts.shift() + (parts.length ? '.' + parts.join('') : '');
        if (v.indexOf('.') > -1 && places >= 0) {
            var seg = v.split('.');
            v = seg[0] + '.' + seg[1].slice(0, places);
        }
        if (v !== el.value) {
            el.value = v;
        }
    }
    document.addEventListener('input', function (e) { if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-mh-decimal')) {
        cleanDecimal(e.target);
    } });
    document.addEventListener('paste', function (e) {
        var t = e.target;
        if (!t || !t.hasAttribute || !t.hasAttribute('data-mh-decimal')) {
            return;
        }
        e.preventDefault();
        t.value = (e.clipboardData || window.clipboardData).getData('text') || '';
        cleanDecimal(t);
        t.dispatchEvent(new Event('input', { bubbles: true }));
    });
    var MIN_DATE = '1900-01-01';
    function dateProblem(v) {
        if (!v) {
            return '';
        }
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
        if (!m) {
            return 'Enter a real date - the year must have exactly 4 digits.';
        }
        var d = new Date(+m[1], +m[2] - 1, +m[3]);
        if (d.getFullYear() !== +m[1] || d.getMonth() !== +m[2] - 1 || d.getDate() !== +m[3]) {
            return 'That date does not exist.';
        }
        if (v < MIN_DATE) {
            return 'The year cannot be earlier than 1900.';
        }
        if (v > todayStr()) {
            return 'The date cannot be in the future.';
        }
        return '';
    }
    window.mhBirthdateProblem = dateProblem;
    function isBirthField(el) { return el.type === 'date' && (el.hasAttribute('data-mh-date') || /birth|bday|bdate/i.test((el.name || '') + ' ' + (el.id || ''))); }
    function initDates() {
        Array.prototype.forEach.call(document.querySelectorAll('input[type="date"]'), function (el) {
            if (!isBirthField(el)) {
                return;
            }
            if (!el.getAttribute('min')) {
                el.setAttribute('min', MIN_DATE);
            }
            el.setAttribute('max', todayStr());
            el.setAttribute('data-mh-date', '1');
        });
    }
    function checkDateField(el) {
        if (!el.hasAttribute || !el.hasAttribute('data-mh-date')) {
            return;
        }
        var raw = el.value;
        var big = /^(\d{5,})-(\d{2})-(\d{2})$/.exec(raw);
        if (big) {
            el.value = '';
            window.mhMark(el, 'The year must have exactly 4 digits.');
            return;
        }
        var p = dateProblem(raw);
        if (p) {
            el.value = '';
            window.mhMark(el, p);
        }
    }
    document.addEventListener('change', function (e) { if (e.target && e.target.type === 'date') {
        checkDateField(e.target);
    } });
    document.addEventListener('blur', function (e) { if (e.target && e.target.type === 'date') {
        checkDateField(e.target);
    } }, true);
    function bindPasswordMatch(form) {
        var confirm = form.querySelector('input[name="confirm_password"]');
        var pwd = form.querySelector('input[name="new_password"]') || form.querySelector('input[name="password"]');
        if (!confirm || !pwd || confirm.getAttribute('data-mh-bound')) {
            return;
        }
        confirm.setAttribute('data-mh-bound', '1');
        var note = document.createElement('small');
        note.className = 'mh-field-msg';
        note.setAttribute('data-kind', 'match');
        note.setAttribute('aria-live', 'polite');
        note.style.display = 'none';
        var anchor = confirm.closest('.mh-pw') || confirm;
        anchor.parentNode.insertBefore(note, anchor.nextSibling);
        function update() {
            if (confirm.value === '') {
                note.style.display = 'none';
                confirm.classList.remove('mh-invalid');
                return;
            }
            var ok = confirm.value === pwd.value;
            note.style.display = 'block';
            note.className = 'mh-field-msg' + (ok ? ' ok' : '');
            note.textContent = ok ? 'Passwords match.' : 'Passwords do not match.';
            confirm.classList.toggle('mh-invalid', !ok);
        }
        pwd.addEventListener('input', update);
        confirm.addEventListener('input', update);
    }
    var RULES = [
        { re: /(^|[_\[\-])(bp|blood_?pressure)([_\]\-]|$)/i, kind: 'bp', max: 7, hint: '120/80' },
        { re: /(^|[_\[\-])(pulse|pulse_?rate|heart_?rate|resp(_?rate|iratory_?rate)?|rr|spo2|o2_?sat\w*|oxygen\w*)([_\]\-]|$)/i, kind: 'int', max: 3 },
        { re: /(^|[_\[\-])(temp|temperature)([_\]\-]|$)/i, kind: 'dec', places: 2, max: 5 },
        { re: /(^|[_\[\-])(birth_?)?(weight|height|wt|ht)([_\]\-]|$)/i, kind: 'dec', places: 2, max: 6 },
        { re: /(^|[_\[\-])(bmi)([_\]\-]|$)/i, kind: 'dec', places: 1, max: 5 }
    ];
    function ruleFor(el) {
        if (!el.matches || !el.matches('input')) {
            return null;
        }
        var t = (el.type || 'text').toLowerCase();
        if (t !== 'text' && t !== 'number' && t !== 'tel' && t !== 'search') {
            return null;
        }
        if (el.hasAttribute('data-mh-free')) {
            return null;
        }
        var key = (el.getAttribute('name') || '') + ' ' + (el.id || '');
        for (var i = 0; i < RULES.length; i++) {
            if (RULES[i].re.test(key.split(' ')[0]) || (el.id && RULES[i].re.test(el.id))) {
                return RULES[i];
            }
        }
        return null;
    }
    function tagNumeric(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('input'), function (el) {
            if (el.getAttribute('data-mh-num')) {
                return;
            }
            var r = ruleFor(el);
            if (!r) {
                return;
            }
            el.setAttribute('data-mh-num', r.kind);
            if (el.type === 'number') {
                el.type = 'text';
            }
            el.setAttribute('inputmode', r.kind === 'bp' ? 'numeric' : (r.kind === 'int' ? 'numeric' : 'decimal'));
            el.setAttribute('autocomplete', 'off');
            if (!el.getAttribute('maxlength')) {
                el.setAttribute('maxlength', String(r.max));
            }
            el.removeAttribute('min');
            el.removeAttribute('max');
            el.removeAttribute('step');
            var ph = el.getAttribute('placeholder') || '';
            if (ph) {
                el.setAttribute('placeholder', ph.replace(/\s*(kgs?|cms?|lbs?|°\s*c|bpm|mmhg)\b/gi, ''));
            }
            if (r.kind === 'dec' && !el.hasAttribute('data-mh-decimal')) {
                el.setAttribute('data-mh-decimal', String(r.places));
            }
            cleanNumeric(el);
        });
    }
    function sanitize(el, v) {
        var kind = el.getAttribute('data-mh-num');
        if (kind === 'int') {
            return v.replace(/\D+/g, '');
        }
        if (kind === 'bp') {
            v = v.replace(/[^0-9\/]/g, '');
            var i = v.indexOf('/');
            return i < 0 ? v : v.slice(0, i + 1) + v.slice(i + 1).replace(/\//g, '');
        }
        if (kind === 'dec') {
            v = v.replace(/[^0-9.]/g, '');
            var p = v.split('.');
            v = p.shift() + (p.length ? '.' + p.join('') : '');
            var places = parseInt(el.getAttribute('data-mh-decimal'), 10);
            if (isNaN(places)) {
                places = 2;
            }
            if (v.indexOf('.') > -1) {
                var q = v.split('.');
                v = q[0] + '.' + q[1].slice(0, places);
            }
            return v;
        }
        return v;
    }
    function cleanNumeric(el) { var c = sanitize(el, String(el.value)); if (c !== el.value) {
        el.value = c;
    } }
    document.addEventListener('beforeinput', function (e) {
        var el = e.target;
        if (!el || !el.getAttribute || !el.getAttribute('data-mh-num') || e.inputType.indexOf('insert') !== 0 || e.data == null) {
            return;
        }
        var next = String(el.value).slice(0, el.selectionStart) + e.data + String(el.value).slice(el.selectionEnd);
        if (sanitize(el, next) !== next) {
            e.preventDefault();
        }
    });
    document.addEventListener('input', function (e) { if (e.target && e.target.getAttribute && e.target.getAttribute('data-mh-num')) {
        cleanNumeric(e.target);
    } });
    document.addEventListener('change', function (e) { if (e.target && e.target.getAttribute && e.target.getAttribute('data-mh-num')) {
        cleanNumeric(e.target);
    } }, true);
    window.mhTagNumeric = tagNumeric;
    function labelFor(el) {
        if (el.id) {
            var l = document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(el.id) : el.id) + '"]');
            if (l) {
                return l;
            }
        }
        var wrap = el.closest('label');
        if (wrap) {
            return wrap;
        }
        if (el.type === 'radio' || el.type === 'checkbox') {
            var g = el.closest('.form-group, .paper-field, .reg-field, fieldset');
            if (g) {
                return g.querySelector('legend, label');
            }
        }
        var grp = el.closest('.form-group, .paper-field, .mh-field, .pr-field, .field');
        if (grp) {
            var labs = grp.querySelectorAll('label'), found = null;
            Array.prototype.forEach.call(labs, function (lb) { if (!found && (lb.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                found = lb;
            } });
            return found || labs[0] || null;
        }
        var prev = el.previousElementSibling;
        return (prev && prev.tagName === 'LABEL') ? prev : null;
    }
    function markRequired(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('input[required], select[required], textarea[required], [data-required]'), function (el) {
            var t = (el.type || '').toLowerCase();
            if (t === 'hidden' || t === 'submit' || t === 'button') {
                return;
            }
            var lb = labelFor(el);
            if (!lb || lb.getAttribute('data-mh-req')) {
                return;
            }
            if (/\*/.test(lb.textContent) || lb.querySelector('.required, .req, .mh-req')) {
                lb.setAttribute('data-mh-req', '1');
                return;
            }
            var star = document.createElement('span');
            star.className = 'mh-req';
            star.setAttribute('aria-hidden', 'true');
            star.textContent = '*';
            lb.appendChild(document.createTextNode(' '));
            lb.appendChild(star);
            lb.setAttribute('data-mh-req', '1');
        });
    }
    var reqObs = null;
    function watchRequired() {
        if (!window.MutationObserver || reqObs) {
            return;
        }
        var t = null;
        reqObs = new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { markRequired(); tagNumeric(); }, 60); });
        reqObs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['required'] });
    }
    function init() {
        tagNumeric();
        markRequired();
        watchRequired();
        initEyes();
        initDates();
        Array.prototype.forEach.call(document.querySelectorAll('form'), bindPasswordMatch);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
    window.addEventListener('pageshow', initDates);
})(window, document);
