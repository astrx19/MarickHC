(function (window, document) {
    'use strict';
    if (window.mhClinicalForms) { return; }

    var state = {};
    var stateEl = document.getElementById('cmState');
    if (stateEl) {
        try { state = JSON.parse(stateEl.textContent) || {}; } catch (e) { state = {}; }
    }
    var icons = state.icons || {};

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function openModal(id) {
        var m = document.getElementById(id);
        if (m) { m.classList.add('active'); }
    }

    function closeModal(id) {
        var m = document.getElementById(id);
        if (!m) { return; }
        m.classList.remove('active');
        try {
            var u = new URL(window.location.href);
            if (u.searchParams.has('walkin_id') || u.searchParams.has('apt_id')) {
                u.searchParams.delete('walkin_id');
                u.searchParams.delete('apt_id');
                window.history.replaceState(null, '', u.pathname + (u.search || ''));
            }
        } catch (e) {}
    }

    window.openModal = openModal;
    window.closeModal = closeModal;

    function fire(el, type) {
        el.dispatchEvent(new Event(type, { bubbles: true }));
    }

    function setField(el, value) {
        if (!el || el.readOnly || el.disabled) { return; }
        var v = String(value);
        if (el.tagName === 'SELECT') {
            var want = v.trim().toLowerCase();
            var hit = null;
            Array.prototype.forEach.call(el.options, function (o) {
                if (!hit && o.value.trim().toLowerCase() === want) { hit = o; }
            });
            if (!hit) {
                hit = new Option(v.trim(), v.trim());
                el.add(hit);
            }
            el.value = hit.value;
        } else if (el.type === 'radio' || el.type === 'checkbox') {
            el.checked = el.value === v;
        } else {
            el.value = v;
        }
        fire(el, 'input');
    }

    function rowHost(form, name) {
        return form.querySelector('[data-cf-rows]') && form.querySelector('[data-cf-rows] [name="' + name + '"]')
            ? form.querySelector('[data-cf-rows]') : null;
    }

    function addRow(host) {
        var tpl = host.querySelector('template');
        var body = host.querySelector('tbody');
        if (!tpl || !body) { return null; }
        body.appendChild(tpl.content.cloneNode(true));
        return body.lastElementChild;
    }

    function fill(form, values, onlyEmpty) {
        if (!form || !values) { return; }
        Object.keys(values).forEach(function (name) {
            var val = values[name];
            if (Array.isArray(val)) {
                var host = rowHost(form, name);
                var els = form.querySelectorAll('[name="' + name + '"]');
                if (host) {
                    while (els.length < val.length) { addRow(host); els = form.querySelectorAll('[name="' + name + '"]'); }
                }
                val.forEach(function (v, i) { if (els[i] && v !== '' && v != null) { setField(els[i], v); } });
                return;
            }
            if (val === '' || val == null) { return; }
            var field = form.querySelectorAll('[name="' + name + '"]');
            if (!field.length) { return; }
            if (field[0].type === 'radio') {
                Array.prototype.forEach.call(field, function (r) { r.checked = r.value === String(val); });
                return;
            }
            if (onlyEmpty && field[0].value) { return; }
            setField(field[0], val);
        });
        fire(form, 'cf:filled');
    }

    function feedback(form, html, kind) {
        var box = form.closest('.cf-modal') ? form.closest('.cf-modal').querySelector('[data-cf-feedback]') : null;
        if (!box) { return; }
        box.className = 'cf-feedback ' + (kind === 'ok' ? 'cf-feedback-ok' : 'cf-feedback-err') + (html ? ' is-on' : '');
        box.innerHTML = html || '';
        if (html && box.scrollIntoView) { box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }

    function fieldLabel(el) {
        var wrap = el.closest('.cf-field') || el.parentElement;
        var lab = wrap ? wrap.querySelector('label, .cf-name-sub') : null;
        var text = lab ? lab.textContent : (el.getAttribute('placeholder') || el.name || 'This field');
        return text.replace(/\*/g, '').replace(/\(optional\)/i, '').trim();
    }

    document.addEventListener('invalid', function (e) {
        var el = e.target;
        var form = el.form;
        if (!form || !form.closest('.cf-modal')) { return; }
        e.preventDefault();
        el.classList.add('cf-invalid');
        var missing = [];
        Array.prototype.forEach.call(form.elements, function (f) {
            if (f.willValidate && !f.validity.valid) {
                f.classList.add('cf-invalid');
                missing.push(fieldLabel(f));
            }
        });
        var unique = missing.filter(function (v, i) { return missing.indexOf(v) === i; });
        feedback(form, 'Please complete the required fields:<ul>' + unique.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>');
        var first = form.querySelector('.cf-invalid');
        if (first && first.focus) { first.focus({ preventScroll: false }); }
    }, true);

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (el.classList && el.classList.contains('cf-invalid') && (!el.willValidate || el.validity.valid)) {
            el.classList.remove('cf-invalid');
            var form = el.form;
            if (form && !form.querySelector('.cf-invalid')) { feedback(form, ''); }
        }
    });

    function recalcBmi(form) {
        var w = form.querySelector('[data-cf-bmi-w]');
        var h = form.querySelector('[data-cf-bmi-h]');
        var o = form.querySelector('[data-cf-bmi-out]');
        if (!w || !h || !o) { return; }
        var wt = parseFloat(w.value), ht = parseFloat(h.value);
        o.value = wt > 0 && ht > 0 ? (wt / Math.pow(ht / 100, 2)).toFixed(2) : '';
    }

    function showMatch(box, matched, identity, noun) {
        if (!box) { return; }
        if (matched && matched.id) {
            if (typeof window.mhSetPatientName === 'function') {
                window.mhSetPatientName(matched.first_name, matched.middle_name, matched.last_name);
            }
            var hid = document.getElementById('pnPickedId');
            if (hid) { hid.value = matched.id; }
            box.hidden = false;
            box.className = 'cf-badge cf-badge-ok';
            box.innerHTML = (icons.ok || '') + ' Existing patient matched: <strong>' + esc(matched.full_name) + '</strong> (ID #' + esc(matched.id) + ')';
        } else if (identity && (identity.first_name || identity.last_name)) {
            var p = new URLSearchParams({
                prefill_fname: identity.first_name || '',
                prefill_lname: identity.last_name || '',
                prefill_bdate: identity.birthdate || ''
            });
            box.hidden = false;
            box.className = 'cf-badge cf-badge-warn';
            box.innerHTML = (icons.warn || '') + ' No existing record found for this ' + esc(noun || 'patient') + '.<br>'
                + '<a class="du-btn du-btn-ghost du-btn-sm" href="walkin.php?' + p.toString() + '" target="_blank" rel="noopener">Register as a new patient</a>';
        } else {
            box.hidden = true;
            box.innerHTML = '';
        }
    }

    function runOcr(box) {
        var fileInput = box.querySelector('[data-cf-ocr-file]');
        var status = box.querySelector('[data-cf-ocr-status]');
        var btn = box.querySelector('[data-cf-ocr-run]');
        var form = box.closest('.cf-modal').querySelector('form');
        var setStatus = function (cls, text) { status.className = 'cf-status ' + cls; status.textContent = text; };

        if (!fileInput.files || !fileInput.files.length) {
            setStatus('is-err', 'Please select an image file first.');
            return;
        }
        var data = new FormData();
        data.append(box.getAttribute('data-field'), fileInput.files[0]);
        setStatus('is-run', 'Analyzing document... Please wait.');
        btn.disabled = true;

        fetch(box.getAttribute('data-endpoint'), { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                btn.disabled = false;
                if (!res || res.success === false || res.error) {
                    setStatus('is-err', (res && (res.error || res.message)) || 'The document could not be read. Please try another photo.');
                    return;
                }
                var fields = res.fields || res.extracted_fields || {};
                fill(form, fields);
                var ident = res.extracted_identity || {};
                var bd = form.querySelector('[data-cf-pick="birthdate"]');
                if (bd && !bd.value && ident.birthdate) { setField(bd, ident.birthdate); }
                showMatch(box.querySelector('[data-cf-ocr-badge]'), res.matched_patient, ident, form.getAttribute('data-cf-noun'));
                setStatus('is-ok', 'Form populated. Please review every field before saving.');
            })
            .catch(function () {
                btn.disabled = false;
                setStatus('is-err', 'A network error occurred. Please try again.');
            });
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-cf-open],[data-cf-close],[data-cf-close-form],[data-cf-ocr-run],[data-cf-row-add],[data-cf-row-remove]');
        if (!t) { return; }
        if (t.hasAttribute('data-cf-open')) { openModal(t.getAttribute('data-cf-open')); return; }
        if (t.hasAttribute('data-cf-close')) { closeModal(t.getAttribute('data-cf-close')); return; }
        if (t.hasAttribute('data-cf-close-form')) {
            var m = t.closest('.modal-overlay');
            if (m) { closeModal(m.id); }
            return;
        }
        if (t.hasAttribute('data-cf-ocr-run')) { runOcr(t.closest('[data-cf-ocr]')); return; }
        if (t.hasAttribute('data-cf-row-add')) {
            var host = t.closest('form').querySelector('[data-cf-rows]');
            var row = host && addRow(host);
            var first = row && row.querySelector('input');
            if (first) { first.focus(); }
            return;
        }
        var tr = t.closest('tr');
        var body = tr && tr.parentElement;
        if (body && body.children.length > 1) { tr.remove(); } else if (tr) {
            Array.prototype.forEach.call(tr.querySelectorAll('input'), function (i) { i.value = ''; });
        }
    });

    document.addEventListener('input', function (e) {
        var t = e.target;
        var form = t.form;
        if (form && form.hasAttribute('data-cf-bmi') && t.matches('[data-cf-bmi-w],[data-cf-bmi-h]')) { recalcBmi(form); }
    });

    document.addEventListener('cf:filled', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-cf-bmi')) { recalcBmi(e.target); }
    });

    document.addEventListener('mh:patient-picked', function (e) {
        var d = e.detail || {};
        var form = document.querySelector('.cf-modal form');
        if (!form) { return; }
        var reject = form.getAttribute('data-cf-reject-sex');
        if (reject && String(d.sex || '').toLowerCase() === reject) {
            var msg = form.getAttribute('data-cf-reject-message') || 'This patient cannot be added here.';
            if (typeof window.mh_alert === 'function') {
                window.mh_alert(msg.replace('{name}', (d.first_name || '') + ' ' + (d.last_name || '')), 'Cannot add record', 'warning');
            }
            var hid = document.getElementById('pnPickedId');
            if (hid) { hid.value = ''; }
            var badge = document.querySelector('.mh-ps-badge');
            if (badge) { badge.hidden = true; badge.innerHTML = ''; }
            ['pnLast', 'pnFirst', 'pnMI'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.value = ''; } });
            return;
        }
        var bd = form.querySelector('[data-cf-pick="birthdate"]');
        var sx = form.querySelector('[data-cf-pick="sex"]');
        if (bd && d.birthdate) { setField(bd, d.birthdate); }
        if (sx && d.sex) { setField(sx, d.sex); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var open = document.querySelector('.modal-overlay.active .cf-modal');
        var dirty = open && open.querySelector('form') && open.querySelector('form').getAttribute('data-cf-dirty') === '1';
        if (open && !dirty) { closeModal(open.parentElement.id); }
    });

    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (form && form.closest && form.closest('.cf-modal')) { form.setAttribute('data-cf-dirty', '1'); }
    });

    function init() {
        if (state.modal && state.open) {
            openModal(state.modal);
            var form = document.querySelector('#' + state.modal + ' form');
            if (form) {
                fill(form, state.prefill || {});
                fill(form, (state.old && state.old.values) || {});
                if (state.old && state.old.error) { feedback(form, esc(state.old.error)); }
            }
        }
        if (state.success) {
            try { window.history.replaceState(null, '', window.location.pathname); } catch (e) {}
        }
    }

    window.mhClinicalForms = { fill: fill, open: openModal, close: closeModal };

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})(window, document);
