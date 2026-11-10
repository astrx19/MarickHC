(function (window, document) {
    'use strict';

    var modal = document.getElementById('editModal');
    var form = document.getElementById('editForm');
    if (!modal || !form) {
        return;
    }
    var typeSelect = document.getElementById('edit_address_type');
    var homeFields = [].slice.call(form.querySelectorAll('[data-address-home]'));
    var otherFields = [].slice.call(form.querySelectorAll('[data-address-other]'));
    var fields = ['edit_id', 'first_name', 'last_name', 'birthdate', 'contact_number', 'email', 'address_type', 'house_number', 'marick_street', 'outsider_address'];

    function isHome() {
        var home = (form.getAttribute('data-home-type') || '').trim().toLowerCase();
        return home !== '' && typeSelect.value.trim().toLowerCase() === home;
    }

    function toggle(group, active) {
        group.forEach(function (box) {
            box.hidden = !active;
            [].forEach.call(box.querySelectorAll('input, select'), function (el) {
                el.disabled = !active;
                el.required = active;
            });
        });
    }

    function syncAddress() {
        var home = isHome();
        toggle(homeFields, home);
        toggle(otherFields, !home);
    }

    function normalizeContact(value) {
        var digits = String(value || '').replace(/\D/g, '');
        if (digits.indexOf('63') === 0 && digits.length === 12) {
            digits = '0' + digits.slice(2);
        }
        return digits.slice(0, 11);
    }

    function fill(data) {
        fields.forEach(function (name) {
            var el = form.elements[name];
            if (!el || data[name] === undefined || data[name] === null) {
                return;
            }
            if (el.tagName === 'SELECT' && data[name] !== '' && ![].some.call(el.options, function (o) { return o.value === data[name]; })) {
                el.add(new Option(data[name], data[name]));
            }
            el.value = data[name];
        });
        form.elements.contact_number.value = normalizeContact(form.elements.contact_number.value);
        syncAddress();
    }

    function open(data) {
        form.reset();
        fill(data);
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        form.elements.first_name.focus();
    }

    function close() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        var edit = e.target.closest('[data-edit-patient]');
        if (edit) {
            try {
                open(JSON.parse(edit.getAttribute('data-edit-patient')));
            } catch (err) {
                window.mh_notify('This patient record could not be opened for editing.', 'error');
            }
            return;
        }
        if (e.target.closest('[data-close-modal="editModal"]') || e.target === modal) {
            close();
        }
    });

    typeSelect.addEventListener('change', syncAddress);
    form.elements.contact_number.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 11);
    });
    [form.elements.first_name, form.elements.last_name].forEach(function (el) {
        el.addEventListener('input', function () {
            this.value = this.value.replace(/[^A-Za-zñÑ\s\-.']/g, '');
        });
    });

    var stateNode = document.getElementById('mlState');
    var state = null;
    try {
        state = stateNode ? JSON.parse(stateNode.textContent) : null;
    } catch (err) {
        state = null;
    }
    if (state) {
        document.addEventListener('DOMContentLoaded', function () { open(state); });
    }
})(window, document);
