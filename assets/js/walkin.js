(function (window, document) {
    'use strict';

    var form = document.getElementById('walkinForm');
    if (!form) {
        return;
    }
    var state = { old: {}, reopenNew: false, homeType: '' };
    try {
        state = JSON.parse(document.getElementById('walkinState').textContent) || state;
    } catch (err) {
        state = { old: {}, reopenNew: false, homeType: '' };
    }

    var typeInput = document.getElementById('patientTypeInput');
    var existingCard = document.getElementById('form-existing');
    var newCard = document.getElementById('form-new');
    var modeButtons = [].slice.call(document.querySelectorAll('[data-wk-mode]'));
    var addressType = document.getElementById('req_address_type');
    var requiredWhenNew = ['req_fname', 'req_lname', 'req_bdate', 'req_sex', 'req_contact'];

    function setEnabled(container, enabled) {
        [].forEach.call(container.querySelectorAll('input, select, textarea'), function (el) {
            el.disabled = !enabled;
        });
    }

    function isHome() {
        var home = String(state.homeType || '').trim().toLowerCase();
        return home !== '' && addressType.value.trim().toLowerCase() === home;
    }

    function toggleGroup(selector, active, required) {
        [].forEach.call(newCard.querySelectorAll(selector), function (box) {
            box.hidden = !active;
            [].forEach.call(box.querySelectorAll('input, select'), function (el) {
                el.disabled = !active;
                el.required = active && required;
            });
        });
    }

    function syncAddress() {
        var home = isHome();
        toggleGroup('[data-address-home]', home, true);
        toggleGroup('[data-address-other]', !home, true);
    }

    function setMode(mode) {
        var isNew = mode === 'new';
        typeInput.value = isNew ? 'new' : 'existing';
        modeButtons.forEach(function (btn) {
            btn.setAttribute('aria-selected', btn.getAttribute('data-wk-mode') === mode ? 'true' : 'false');
        });
        existingCard.hidden = isNew;
        newCard.hidden = !isNew;
        setEnabled(existingCard, !isNew);
        setEnabled(newCard, isNew);
        requiredWhenNew.forEach(function (id) {
            document.getElementById(id).required = isNew;
        });
        if (isNew) {
            syncAddress();
        }
    }

    function useExisting(id) {
        var $ = window.jQuery;
        setMode('existing');
        if ($ && $('#patientSelect').length) {
            $('#patientSelect').val(String(id)).trigger('change');
        } else {
            document.getElementById('patientSelect').value = String(id);
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    modeButtons.forEach(function (btn) {
        btn.addEventListener('click', function () { setMode(btn.getAttribute('data-wk-mode')); });
    });
    addressType.addEventListener('change', syncAddress);

    form.addEventListener('input', function (e) {
        var el = e.target;
        if (el.id === 'req_contact') {
            el.value = el.value.replace(/\D/g, '').slice(0, 11);
        } else if (el.hasAttribute('data-letters')) {
            el.value = el.value.replace(/[^A-Za-zñÑ\s\-.']/g, '');
        }
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-use-existing]');
        if (btn) {
            useExisting(btn.getAttribute('data-use-existing'));
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        Object.keys(state.old || {}).forEach(function (name) {
            var el = form.elements[name];
            if (el && state.old[name] !== '') {
                el.value = state.old[name];
            }
        });
        setMode(state.reopenNew ? 'new' : 'existing');
        var $ = window.jQuery;
        if ($ && $('#patientSelect').length) {
            if (typeof window.mhSelect2 === 'function') {
                window.mhSelect2('#patientSelect', { placeholder: 'Search masterlist' });
            } else {
                $('#patientSelect').select2({ placeholder: 'Search masterlist', allowClear: true });
            }
        }
    });
})(window, document);
