(function (window, document) {
    'use strict';
    var CFG = window.MH_LIVE;
    if (!CFG || window.mhLiveStarted) {
        return;
    }
    window.mhLiveStarted = true;
    var interval = CFG.interval || 5000, sig = null, pending = false, lastKey = Date.now(), lastAll = Date.now(), fails = 0, timer = null, applying = false;
    document.addEventListener('keydown', function () { lastKey = Date.now(); }, true);
    document.addEventListener('input', function () { lastKey = Date.now(); }, true);
    function busy() {
        if (document.querySelector('.modal-overlay.active, .mh-modal-backdrop, .du-modal:not([hidden]), .select2-container--open')) {
            return true;
        }
        var a = document.activeElement;
        if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.type !== 'button' && a.type !== 'submit') {
            return true;
        }
        if (Date.now() - lastKey < 4000) {
            return true;
        }
        var sel = window.getSelection && window.getSelection();
        if (sel && !sel.isCollapsed && String(sel).length > 2) {
            return true;
        }
        return false;
    }
    var pill = null;
    function showPill() {
        if (pill) {
            return;
        }
        pill = document.createElement('button');
        pill.type = 'button';
        pill.className = 'mh-live-pill';
        pill.innerHTML = 'The queue was updated &middot; <strong>Apply now</strong>';
        pill.addEventListener('click', function () { apply(true); });
        document.body.appendChild(pill);
    }
    function hidePill() { if (pill && pill.parentNode) {
        pill.parentNode.removeChild(pill);
    } pill = null; }
    function apply(force) {
        if (applying) {
            return;
        }
        if (!force && busy()) {
            pending = true;
            showPill();
            return;
        }
        applying = true;
        fetch(window.location.pathname + window.location.search, { credentials: 'same-origin', headers: { 'X-Requested-With': 'mh-live' } })
            .then(function (r) { if (!r.ok) {
            throw new Error('http ' + r.status);
        } return r.text(); })
            .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var swapped = 0;
            Array.prototype.forEach.call(document.querySelectorAll('[data-live]'), function (cur) {
                var fresh = doc.querySelector('[data-live="' + cur.getAttribute('data-live') + '"]');
                if (fresh && fresh.innerHTML !== cur.innerHTML) {
                    cur.innerHTML = fresh.innerHTML;
                    cur.classList.remove('mh-live-flash');
                    void cur.offsetWidth;
                    cur.classList.add('mh-live-flash');
                    swapped++;
                }
            });
            if (swapped) {
                document.dispatchEvent(new CustomEvent('mh:live-applied'));
            }
            pending = false;
            hidePill();
            lastAll = Date.now();
            if (swapped && window.mh_toast && !force) {
                window.mh_toast('Queue updated.', 'info', 1800);
            }
            return fetch(CFG.url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.sig) {
                sig = j.sig;
            } });
        })
            .catch(function () { })
            .then(function () { applying = false; });
    }
    function poll() {
        if (document.hidden) {
            schedule(interval * 2);
            return;
        }
        fetch(CFG.url + (sig ? '?sig=' + encodeURIComponent(sig) : ''), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (r.status === 401) {
            window.location.reload();
            return null;
        } return r.json(); })
            .then(function (j) {
            if (!j) {
                return;
            }
            fails = 0;
            if (sig === null) {
                sig = j.sig;
            }
            else if (j.changed) {
                sig = j.sig;
                pending = true;
            }
            if (pending) {
                apply(false);
            }
            else if (CFG.refreshAll && Date.now() - lastAll >= CFG.refreshAll && !busy()) {
                apply(false);
            }
        })
            .catch(function () { fails++; })
            .then(function () { schedule(fails > 2 ? Math.min(interval * 6, 30000) : interval); });
    }
    function schedule(ms) { clearTimeout(timer); timer = setTimeout(poll, ms); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) {
        schedule(200);
    } });
    window.addEventListener('beforeunload', function () { clearTimeout(timer); });
    schedule(1500);
})(window, document);
