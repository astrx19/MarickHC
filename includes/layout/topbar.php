<header class="topbar">
    <div class="topbar-brand">
        <button type="button" class="sidebar-toggle-btn" id="sidebarCollapseBtn" data-sidebar-toggle
                title="Toggle navigation" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false">
            <?php echo mh_icon('menu'); ?>
        </button>
        <img class="topbar-logo no-dim" src="../assets/images/logo.png?v=<?php echo mh_layout_version('images/logo.png'); ?>" alt="" width="40" height="40">
        <div class="topbar-heading">
            <h2 class="topbar-title"><?php echo mh_h($topbar['title']); ?></h2>
            <span class="topbar-subtitle"><?php echo mh_h($topbar['subtitle']); ?></span>
        </div>
    </div>

    <div class="topbar-actions">
        <button type="button" class="theme-toggle" id="themeToggle" data-theme-toggle title="Toggle dark mode" aria-label="Toggle dark mode">
            <span class="theme-toggle-knob">
                <span class="icon-sun"><?php echo mh_icon('sun'); ?></span>
                <span class="icon-moon"><?php echo mh_icon('moon'); ?></span>
            </span>
        </button>
        <div class="user-text-info">
            <span class="topbar-user-name"><?php echo mh_h($topbar['user_name']); ?></span>
            <span class="topbar-user-sub"><?php echo mh_h($topbar['user_sub']); ?></span>
        </div>
        <?php echo $topbar['avatar']; ?>
    </div>
</header>
<main class="page-body" id="pageBody">
