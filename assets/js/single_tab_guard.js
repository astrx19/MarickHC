(function () {
    'use strict';
    if (!('BroadcastChannel' in window)) {
        return;
    }
    var tag = document.currentScript;
    var scope = (tag && tag.dataset && tag.dataset.scope) || 'account';
    var me = Math.random().toString(36).slice(2) + Date.now().toString(36);
    var ch = new BroadcastChannel('mh_single_tab_' + scope);
    var overlay = null;
    function claim() { ch.postMessage({ type: 'claim', id: me }); }
    function lock() {
        if (overlay) {
            return;
        }
        overlay = document.createElement('div');
        overlay.setAttribute('role', 'alertdialog');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(15,23,42,.92);' +
            'display:flex;align-items:center;justify-content:center;padding:20px;font-family:system-ui,sans-serif;';
        overlay.innerHTML =
            '<div style="background:#fff;color:#1e293b;max-width:420px;width:100%;border-radius:12px;padding:28px;text-align:center;">' +
                '<h3 style="margin:0 0 10px;font-size:1.3rem;">Account open in another tab</h3>' +
                '<p style="margin:0 0 20px;line-height:1.5;color:#475569;">Only one tab can use this account at a time. ' +
                'Continue here and the other tab will pause.</p>' +
                '<button type="button" style="background:#0ea5e9;color:#fff;border:0;border-radius:8px;padding:12px 22px;' +
                'font-weight:700;font-size:1rem;cursor:pointer;">Use this tab</button></div>';
        overlay.querySelector('button').addEventListener('click', function () {
            document.body.removeChild(overlay);
            overlay = null;
            claim();
        });
        document.body.appendChild(overlay);
    }
    ch.onmessage = function (e) {
        if (e.data && e.data.type === 'claim' && e.data.id !== me) {
            lock();
        }
    };
    window.addEventListener('pageshow', function (e) { if (e.persisted) {
        claim();
    } });
    claim();
})();
