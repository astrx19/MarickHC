(function () {
    'use strict';

    var BREAKPOINT = 992;
    var root = document.documentElement;
    var sidebar = null;
    var toggle = null;

    function isMobile() {
        return window.innerWidth <= BREAKPOINT;
    }

    function store(key, value) {
        try { localStorage.setItem(key, value); } catch (e) {}
    }

    function setToggleState() {
        if (!toggle || !sidebar) { return; }
        var mobile = isMobile();
        var open = mobile ? sidebar.classList.contains('mobile-open') : !sidebar.classList.contains('collapsed');
        var label = mobile
            ? (open ? 'Close navigation' : 'Open navigation')
            : (open ? 'Collapse sidebar' : 'Expand sidebar');
        toggle.setAttribute('title', label);
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function setDrawer(open) {
        sidebar.classList.toggle('mobile-open', open);
        root.classList.toggle('nav-open', open);
        setToggleState();
    }

    function setCollapsed(collapsed) {
        sidebar.classList.toggle('collapsed', collapsed);
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        root.classList.toggle('sidebar-collapsed', collapsed);
        store('sidebarCollapsed', collapsed ? 'true' : 'false');
        setToggleState();
    }

    function toggleSidebar() {
        if (isMobile()) {
            setDrawer(!sidebar.classList.contains('mobile-open'));
        } else {
            setCollapsed(!sidebar.classList.contains('collapsed'));
        }
    }

    function toggleSubmenu(link) {
        var item = link.parentElement;
        if (!isMobile() && sidebar.classList.contains('collapsed')) {
            setCollapsed(false);
        }
        var open = item.classList.toggle('open');
        link.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function toggleTheme() {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        store('theme', next);
    }

    window.mhToggleTheme = toggleTheme;

    function init() {
        sidebar = document.getElementById('appSidebar');
        toggle = document.getElementById('sidebarCollapseBtn');
        if (!sidebar) { return; }

        if (!isMobile() && root.classList.contains('sidebar-collapsed')) {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }
        setToggleState();

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) { return; }

            var themeBtn = target.closest('[data-theme-toggle]');
            if (themeBtn) { toggleTheme(); return; }

            var sidebarBtn = target.closest('[data-sidebar-toggle]');
            if (sidebarBtn) { toggleSidebar(); return; }

            var submenuBtn = target.closest('[data-submenu-toggle]');
            if (submenuBtn) { event.preventDefault(); toggleSubmenu(submenuBtn); return; }

            if (isMobile() && sidebar.classList.contains('mobile-open')) {
                if (target.closest('.sidebar-nav a') || target.closest('#sidebarBackdrop') || !target.closest('#appSidebar')) {
                    setDrawer(false);
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === ' ' && event.target instanceof Element && event.target.matches('[data-submenu-toggle]')) {
                event.preventDefault();
                toggleSubmenu(event.target);
                return;
            }
            if (event.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                setDrawer(false);
                if (toggle) { toggle.focus(); }
            }
        });

        var wasMobile = isMobile();
        window.addEventListener('resize', function () {
            var mobile = isMobile();
            if (mobile === wasMobile) { return; }
            wasMobile = mobile;
            if (mobile) {
                sidebar.classList.remove('collapsed');
            } else {
                setDrawer(false);
                var saved = false;
                try { saved = localStorage.getItem('sidebarCollapsed') === 'true'; } catch (e) {}
                sidebar.classList.toggle('collapsed', saved);
            }
            setToggleState();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
