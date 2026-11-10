(function (window, document) {
    'use strict';
    if (window.mh_notify) {
        return;
    }
    function normType(t) {
        t = String(t || 'info').toLowerCase();
        if (t === 'error') {
            return 'danger';
        }
        if (t === 'warn') {
            return 'warning';
        }
        return ['success', 'warning', 'danger', 'info'].indexOf(t) > -1 ? t : 'info';
    }
    window.mh_notify = function (message, type, opts) {
        opts = opts || {};
        type = normType(type);
        if (type === 'danger') {
            return window.mh_alert(message, opts.title || 'Something went wrong', 'danger');
        }
        window.mh_toast(message, type, opts.duration || (type === 'warning' ? 5500 : 4000));
        return Promise.resolve();
    };
    window.showCustomModal = function (title, message, type) {
        return window.mh_notify(message, type === 'error' ? 'error' : 'success', { title: title });
    };
    window.closeCustomModal = function () { };
    window.mh_setLoading = function (btn, on) {
        if (!btn) {
            return;
        }
        btn.classList.toggle('is-loading', on !== false);
        if (btn.tagName === 'BUTTON') {
            btn.setAttribute('aria-busy', on !== false ? 'true' : 'false');
        }
    };
    document.addEventListener('submit', function (e) {
        var form = e.target, sub = e.submitter;
        if (e.defaultPrevented || !form || form.tagName !== 'FORM' || !sub) {
            return;
        }
        if (form.hasAttribute('data-no-loading') || sub.hasAttribute('data-no-loading')) {
            return;
        }
        var target = sub.formTarget || form.target;
        if (target && target !== '_self') {
            return;
        }
        window.mh_setLoading(sub, true);
        setTimeout(function () { window.mh_setLoading(sub, false); }, 10000);
    }, false);
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) {
            return;
        }
        Array.prototype.forEach.call(document.querySelectorAll('.is-loading'), function (b) { window.mh_setLoading(b, false); });
    });
    window.mhSelect2 = function (target, options) {
        var $ = window.jQuery;
        if (!$ || !$.fn || !$.fn.select2) {
            return null;
        }
        return $(target).each(function () {
            var $el = $(this);
            if ($el.data('select2')) {
                return;
            }
            var parent = $el.closest('.modal-content, .du-modal-box, .universal-modal-content');
            var hasEmpty = $el.find('option').filter(function () { return this.value === ''; }).length > 0;
            var cfg = {
                width: '100%',
                placeholder: $el.attr('data-placeholder') || 'Search…',
                allowClear: hasEmpty && !$el.prop('required'),
                dropdownParent: parent.length ? parent : $(document.body),
                language: { noResults: function () { return 'No matches found'; } }
            };
            $el.select2($.extend(cfg, options || {}));
        });
    };
    document.addEventListener('DOMContentLoaded', function () {
        window.mhSelect2('select[data-mh-search]');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || e.defaultPrevented) {
            return;
        }
        if (document.querySelector('.mh-modal-backdrop') || document.querySelector('.select2-container--open')) {
            return;
        }
        var open = Array.prototype.filter.call(document.querySelectorAll('.modal-overlay.active'), function (m) { return !m.hasAttribute('data-static'); });
        if (!open.length) {
            return;
        }
        open[open.length - 1].classList.remove('active');
        document.body.style.overflow = '';
    });
})(window, document);
