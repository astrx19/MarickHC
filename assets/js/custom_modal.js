/* ==============================================================
   MARICK HEALTH CENTER - CUSTOM MODAL & NOTIFICATION ENGINE
   Promise-based replacements for native alert()/confirm()/prompt().

   Usage:
     await mh_alert('Saved successfully.', 'Success', 'success');
     if (await mh_confirm('Delete this record?', 'Are you sure?', 'danger')) { ... }
     const val = await mh_prompt('Enter a reason', 'Reason required', '', 'warning');
     mh_toast('Changes saved.', 'success');
   ============================================================== */
(function (window, document) {
    'use strict';

    if (window.mh_alert) { return; } // already loaded

    var ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
        danger: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
    };

    function validType(type) {
        return ICONS.hasOwnProperty(type) ? type : 'info';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = String(str == null ? '' : str);
        return div.innerHTML;
    }

    /**
     * Core modal builder. Resolves with:
     *   - boolean (true/false) for confirm-style modals
     *   - string|null for prompt-style modals
     *   - undefined for plain alerts
     */
    function buildModal(opts) {
        return new Promise(function (resolve) {
            var type = validType(opts.type);

            var backdrop = document.createElement('div');
            backdrop.className = 'mh-modal-backdrop';
            backdrop.setAttribute('role', opts.mode === 'alert' ? 'alertdialog' : 'dialog');
            backdrop.setAttribute('aria-modal', 'true');

            var card = document.createElement('div');
            card.className = 'mh-modal-card';

            var body = document.createElement('div');
            body.className = 'mh-modal-body';

            var icon = document.createElement('div');
            icon.className = 'mh-modal-icon mh-type-' + type;
            icon.innerHTML = ICONS[type];
            body.appendChild(icon);

            var title = document.createElement('h3');
            title.className = 'mh-modal-title';
            title.textContent = opts.title || '';
            body.appendChild(title);

            var message = document.createElement('p');
            message.className = 'mh-modal-message';
            if (opts.html) { message.innerHTML = opts.message || ''; }   // caller is responsible for escaping dynamic parts
            else { message.textContent = opts.message || ''; }
            body.appendChild(message);

            var input = null;
            if (opts.mode === 'prompt') {
                input = document.createElement('input');
                input.type = opts.inputType || 'text';
                input.className = 'mh-modal-input';
                input.value = opts.defaultValue || '';
                if (opts.placeholder) { input.placeholder = opts.placeholder; }
                body.appendChild(input);
            }

            card.appendChild(body);

            var actions = document.createElement('div');
            actions.className = 'mh-modal-actions';

            var settled = false;
            function close(result) {
                if (settled) { return; }
                settled = true;
                backdrop.classList.remove('mh-visible');
                document.removeEventListener('keydown', onKeydown);
                setTimeout(function () {
                    if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
                }, 180);
                resolve(result);
            }

            function onKeydown(e) {
                if (e.key === 'Escape') {
                    close(opts.mode === 'prompt' ? null : (opts.mode === 'alert' ? undefined : false));
                } else if (e.key === 'Enter' && opts.mode !== 'alert-multiline') {
                    e.preventDefault();
                    confirmBtn.click();
                }
            }

            if (opts.showCancel) {
                var cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'mh-btn-cancel';
                cancelBtn.textContent = opts.cancelText || 'Cancel';
                cancelBtn.addEventListener('click', function () {
                    close(opts.mode === 'prompt' ? null : false);
                });
                actions.appendChild(cancelBtn);
            }

            var confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = 'mh-btn-confirm mh-type-' + type;
            confirmBtn.textContent = opts.confirmText || 'OK';
            confirmBtn.addEventListener('click', function () {
                if (opts.mode === 'prompt') {
                    close(input.value);
                } else if (opts.mode === 'alert') {
                    close(undefined);
                } else {
                    close(true);
                }
            });
            actions.appendChild(confirmBtn);

            card.appendChild(actions);
            backdrop.appendChild(card);

            backdrop.addEventListener('mousedown', function (e) {
                if (e.target === backdrop && opts.dismissOnBackdrop !== false) {
                    close(opts.mode === 'prompt' ? null : (opts.mode === 'alert' ? undefined : false));
                }
            });

            document.body.appendChild(backdrop);
            document.addEventListener('keydown', onKeydown);

            // Force reflow so the transition plays, then reveal.
            requestAnimationFrame(function () {
                backdrop.classList.add('mh-visible');
                if (input) { input.focus(); input.select(); } else { confirmBtn.focus(); }
            });
        });
    }

    /**
     * mh_alert(message, title, type) -> Promise<void>
     * Resolves when the user dismisses the notice.
     */
    window.mh_alert = function (message, title, type) {
        return buildModal({
            mode: 'alert',
            message: message,
            title: title || 'Notification',
            type: type || 'info',
            confirmText: 'OK',
            showCancel: false
        });
    };

    /**
     * mh_confirm(message, title, type) -> Promise<boolean>
     * Resolves true if confirmed, false if canceled/dismissed.
     */
    window.mh_confirm = function (message, title, type, confirmText, options) {
        return buildModal({
            mode: 'confirm',
            html: !!(options && options.html),
            message: message,
            title: title || 'Are you sure?',
            type: type || 'warning',
            confirmText: confirmText || 'Confirm',
            cancelText: 'Cancel',
            showCancel: true
        });
    };

    /**
     * mh_prompt(message, title, defaultValue, type) -> Promise<string|null>
     * Resolves the entered string, or null if canceled.
     */
    window.mh_prompt = function (message, title, defaultValue, type) {
        return buildModal({
            mode: 'prompt',
            message: message,
            title: title || 'Please provide a value',
            type: type || 'info',
            defaultValue: defaultValue || '',
            confirmText: 'OK',
            cancelText: 'Cancel',
            showCancel: true
        });
    };

    /* ---------------- data-confirm: declarative confirmations ----------------
       <a href="logout.php" data-confirm="Log out?" data-confirm-title="Log out" data-confirm-ok="Log out">
       <form method="POST" data-confirm="Save this record?"> ... </form>
       <button type="submit" name="x" data-confirm="Delete?" data-confirm-type="danger">
       A button's own data-confirm wins over its form's. Optional: data-confirm-title,
       data-confirm-type (info|warning|danger|success), data-confirm-ok. */
    function confirmFrom(el) {
        var d = el.dataset;
        return window.mh_confirm(d.confirm, d.confirmTitle || 'Are you sure?', d.confirmType || 'warning', d.confirmOk || 'Confirm');
    }

    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-confirm]') : null;
        if (!a) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        confirmFrom(a).then(function (ok) { if (ok) { window.location.href = a.href; } });
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') { return; }
        if (form.__mhConfirmed) { form.__mhConfirmed = false; return; }
        var sub = e.submitter || null;
        var src = (sub && sub.hasAttribute('data-confirm')) ? sub : (form.hasAttribute('data-confirm') ? form : null);
        if (!src) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();   // other submit listeners run on the confirmed pass
        confirmFrom(src).then(function (ok) {
            if (!ok) { return; }
            form.__mhConfirmed = true;
            if (form.requestSubmit) { sub ? form.requestSubmit(sub) : form.requestSubmit(); } else { form.submit(); }
        });
    }, true);

    /* ---------------- Toasts ---------------- */
    var toastStack = null;
    function getToastStack() {
        if (!toastStack || !toastStack.parentNode) {
            toastStack = document.createElement('div');
            toastStack.className = 'mh-toast-stack';
            document.body.appendChild(toastStack);
        }
        return toastStack;
    }

    /**
     * mh_toast(message, type, duration) - non-blocking notification.
     */
    window.mh_toast = function (message, type, duration) {
        type = validType(type);
        duration = duration || 3500;

        var stack = getToastStack();

        // Same message already on screen? Do not stack a duplicate.
        var key = type + '|' + String(message == null ? '' : message);
        var existing = stack.querySelectorAll('.mh-toast');
        for (var i = 0; i < existing.length; i++) {
            if (existing[i].getAttribute('data-key') === key && !existing[i].classList.contains('mh-leaving')) { return; }
        }
        // Keep the stack readable: drop the oldest beyond 4.
        while (stack.children.length >= 4) { stack.removeChild(stack.firstChild); }

        var toast = document.createElement('div');
        toast.className = 'mh-toast mh-type-' + type;
        toast.setAttribute('data-key', key);
        toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        toast.style.setProperty('--mh-toast-dur', duration + 'ms');

        var icon = document.createElement('span');
        icon.className = 'mh-toast-icon mh-type-' + type;
        icon.innerHTML = ICONS[type];
        toast.appendChild(icon);

        var text = document.createElement('span');
        text.className = 'mh-toast-text';
        text.textContent = message;
        toast.appendChild(text);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'mh-toast-close';
        closeBtn.setAttribute('aria-label', 'Dismiss');
        closeBtn.innerHTML = '&times;';
        toast.appendChild(closeBtn);

        stack.appendChild(toast);

        var removed = false;
        function remove() {
            if (removed) { return; }
            removed = true;
            toast.classList.add('mh-leaving');
            setTimeout(function () {
                if (toast.parentNode) { toast.parentNode.removeChild(toast); }
            }, 250);
        }

        closeBtn.addEventListener('click', remove);
        // Hover pauses BOTH the countdown and the progress bar (see .mh-toast:hover::after), then resumes with the time left.
        var remaining = duration, startedAt = Date.now();
        var timer = setTimeout(remove, remaining);
        toast.addEventListener('mouseenter', function () {
            clearTimeout(timer);
            remaining = Math.max(remaining - (Date.now() - startedAt), 600);
        });
        toast.addEventListener('mouseleave', function () {
            startedAt = Date.now();
            timer = setTimeout(remove, remaining);
        });

        requestAnimationFrame(function () { toast.classList.add('mh-visible'); });
    };

})(window, document);
