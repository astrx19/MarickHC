<?php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
require_once __DIR__ . '/icons.php';

$staff_name = $_SESSION['full_name'] ?? 'Staff Member'; 
$staff_role = $_SESSION['role'] ?? 'User';

$system_name = $system_name ?? mh_setting('system_name');
?>

<div class="topbar">
    <div class="nav-brand-group">
        <!-- Sidebar toggle: collapses the sidebar to icon-only on desktop,
             slides it fully off-canvas on mobile (see toggleSidebar() in
             includes/admin_sidebar.php - it branches on screen width). -->
        <button class="sidebar-toggle-btn" id="sidebarCollapseBtn" onclick="toggleSidebar()" title="Collapse sidebar" aria-label="Collapse sidebar">
            <?php echo mh_icon('menu'); ?>
        </button>

        <img src="../assets/images/logo.png?t=<?php echo time(); ?>" alt="System Logo" style="width: 40px; height: 40px; object-fit: contain; border-radius: 10px;">

        <div style="line-height: 1.2;">
            <h2 style="margin: 0; font-size: 1.05rem; color: var(--heading-color); font-weight: 800; letter-spacing: -0.01em;" class="topbar-title">
                <?php echo htmlspecialchars($system_name); ?>
            </h2>
            <span style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted);">Staff Console</span>
        </div>
    </div>

    <div class="user-info">
        <!-- Dark / Light mode toggle -->
        <button type="button" class="theme-toggle" id="themeToggle" onclick="mhToggleTheme()" title="Toggle dark mode" aria-label="Toggle dark mode">
            <span class="theme-toggle-knob">
                <span class="icon-sun"><?php echo mh_icon('sun'); ?></span>
                <span class="icon-moon"><?php echo mh_icon('moon'); ?></span>
            </span>
        </button>

        <div style="text-align: right; line-height: 1.2;" class="user-text-info">
            <span style="font-weight: bold; color: var(--heading-color); display: block; font-size: 0.95rem;">
                <?php echo htmlspecialchars($staff_name); ?>
            </span>
            <span style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 600;">
                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $staff_role))); ?>
            </span>
        </div>
        
        <?php
        $mh_pp_file = __DIR__ . '/../assets/images/profile_pics/' . (int) ($_SESSION['user_id'] ?? 0) . '.jpg';
        if (is_file($mh_pp_file)): ?>
            <img src="../assets/images/profile_pics/<?php echo (int) ($_SESSION['user_id'] ?? 0); ?>.jpg?t=<?php echo (int) @filemtime($mh_pp_file); ?>"
                 alt="Profile" class="no-dim"
                 style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color, #e2e8f0);">
        <?php else: echo mh_avatar_html(mh_initials_from_name($staff_name), 40, $staff_name); endif; ?>
    </div>
</div>

<style>
@media (max-width: 992px) {
    /* Optional: shorten system title on tiny screens if space is tight */
    @media (max-width: 480px) {
        .topbar-title {
            font-size: 0.95rem !important;
        }
        .user-text-info {
            display: none !important; /* Hide user text labels, leave only avatar */
        }
    }
}
</style>

<script>
/**
 * Dark / light mode toggle. The instant "no flash" attribute set on
 * <html> happens earlier, in includes/theme_init.php (inlined in
 * <head> before the stylesheet loads) - this just handles the click
 * and persists the choice for next time.
 */
function mhToggleTheme() {
    var root = document.documentElement;
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
}
</script>
