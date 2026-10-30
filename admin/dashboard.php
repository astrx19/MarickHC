<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard_helper.php';
require_once __DIR__ . '/../includes/icons.php';

// Any signed-in staff account (built-in or custom role) may open this page; admin_auth.php already
// verified the login and re-synced the role. What each role SEES is controlled by Module Access.

$system_name = $system_name ?? mh_setting('system_name');

$today = date('Y-m-d');
$show_admin_pages = mh_is_admin_tier();

if(isset($_POST['add_holiday']) && $show_admin_pages) {
    $h_date = (string) ($_POST['holiday_date'] ?? '');
    $h_desc = trim((string) ($_POST['holiday_desc'] ?? ''));
    $stmt = $conn->prepare("INSERT INTO holidays (holiday_date, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)");
    $stmt->bind_param('ss', $h_date, $h_desc);
    $stmt->execute();
    $stmt->close();
    
    logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "System Settings", "Declared a holiday for $h_date ($h_desc).");
    header("Location: dashboard.php?cal=open");
    exit;
}

if(isset($_POST['remove_holiday']) && $show_admin_pages) {
    $h_date = (string) ($_POST['remove_date'] ?? '');
    $stmt = $conn->prepare("DELETE FROM holidays WHERE holiday_date = ?");
    $stmt->bind_param('s', $h_date);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "System Settings", "Removed holiday status for $h_date.");
    header("Location: dashboard.php?cal=open");
    exit;
}

$holidays_query = $conn->query("SELECT holiday_date, description FROM holidays");
$holidays_data = [];
if($holidays_query) {
    while($row = $holidays_query->fetch_assoc()) {
        $holidays_data[$row['holiday_date']] = $row['description'];
    }
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

$stmt = $conn->prepare("SELECT first_name FROM staff WHERE id = ?");
$uid_int = (int) $user_id;
$stmt->bind_param('i', $uid_int);
$stmt->execute();
$user_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$first_name = $user_row ? $user_row['first_name'] : "Admin";

$cal_appointments = $conn->query("
    SELECT a.appointment_date, p.first_name, p.last_name, a.department 
    FROM appointments a 
    JOIN patients p ON a.patient_id = p.id 
    WHERE a.status IN ('Waiting', 'Approved', 'Pending', 'Rescheduled')
    ORDER BY a.id ASC
");

$calendar_data = [];
if($cal_appointments) {
    while($row = $cal_appointments->fetch_assoc()) {
        $date = $row['appointment_date']; 
        if(!isset($calendar_data[$date])) { $calendar_data[$date] = []; }
        $calendar_data[$date][] = [
            'name' => $row['last_name'] . ', ' . $row['first_name'],
            'dept' => $row['department']
        ];
    }
}

/* =====================================================================
 * CUSTOMIZABLE WIDGET DASHBOARD
 * ================================================================== */
mh_dashboard_install($conn);

$widget_catalog = mh_dashboard_catalog();
$widget_layout  = mh_dashboard_get_layout($conn, (int) $user_id, $user_role);
$widget_ctx     = [
    'today'    => $today,
    'week_end' => date('Y-m-d', strtotime($today . ' +7 days')),
    'user_id'  => $user_id,
    'role'     => $user_role,
];

// Widgets not in the user's saved layout at all yet (should not normally
// happen after mh_dashboard_get_layout()'s merge, but kept as a safety
// net) never reach the page, so the "+ Add Widget" list can only ever
// offer widgets that are already in $widget_layout with visible=false.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($system_name ?? mh_setting('system_name')); ?></title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        /* body background now comes from admin_theme.css's var(--bg-body) -
           a hardcoded #f1f5f9 here used to win the cascade (same selector,
           declared later than the stylesheet) and pin the dashboard to a
           light background even with dark mode toggled on. */
        .dashboard-container { max-width: 1400px; margin: 40px auto; padding: 0 30px; }

        .welcome-banner {
            position: relative; overflow: hidden; isolation: isolate;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a4f 55%, var(--primary, #4ca1af) 130%);
            color: white; padding: 32px 34px; border-radius: var(--radius-xl); margin-bottom: 25px;
            box-shadow: var(--shadow-lg); border: 1px solid var(--glass-border);
            display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
        }
        /* Decorative glow blobs - purely cosmetic, sit behind the text via z-index/isolation above */
        .welcome-banner::before, .welcome-banner::after {
            content: ""; position: absolute; z-index: -1; border-radius: 50%; filter: blur(10px);
        }
        .welcome-banner::before { width: 220px; height: 220px; background: rgba(56, 189, 248, 0.35); top: -110px; right: -60px; }
        .welcome-banner::after { width: 160px; height: 160px; background: rgba(76, 161, 175, 0.45); bottom: -90px; right: 140px; }
        .welcome-banner .welcome-copy { position: relative; }
        .welcome-banner .welcome-eyebrow {
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800;
            letter-spacing: 0.1em; text-transform: uppercase; color: #7dd3fc; margin-bottom: 8px;
        }
        /* Explicit overrides: the global "h1,h2,h3{color:var(--heading-color)}" rule in
           admin_theme.css directly targets these elements, and a directly-matched color
           always beats an inherited one regardless of specificity - so without this the
           heading rendered dark navy on the dark navy banner background (invisible). */
        .welcome-banner h1 { margin: 0 0 8px 0; font-size: 2rem; font-weight: 800; letter-spacing: -0.01em; color: #ffffff; }
        .welcome-banner p { margin: 0; color: #e2e8f0; font-size: 1.02rem; position: relative; }
        .welcome-banner p strong { color: #ffffff; }
        .welcome-banner .welcome-icon {
            position: relative; flex-shrink: 0; width: 64px; height: 64px; border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, 0.12); backdrop-filter: blur(6px); border: 1px solid var(--glass-border);
            display: flex; align-items: center; justify-content: center; color: #fff;
        }
        .welcome-banner .welcome-icon .mh-icon { width: 28px; height: 28px; }

        /* ---------------------------------------------------------------
         * Dashboard toolbar (Customize / Add Widget / Save / Reset)
         * ------------------------------------------------------------- */
        .dash-toolbar { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .dash-toolbar button { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 8px; border: 1px solid var(--border-color, #cbd5e1); background: var(--bg-card); color: var(--text-main); font-weight: bold; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        .dash-toolbar button .mh-icon { width: 15px; height: 15px; }
        .dash-toolbar button:hover { background: var(--bg-hover); border-color: var(--text-muted); }
        .dash-toolbar button.btn-primary-toolbar { background: var(--primary, #4ca1af); color: white; border-color: var(--primary, #4ca1af); }
        .dash-toolbar button.btn-primary-toolbar:hover { background: var(--primary-hover, #3b828e); }
        .dash-toolbar .edit-only { display: none; }
        body.dashboard-edit-mode .dash-toolbar .edit-only { display: inline-flex; }
        body.dashboard-edit-mode .dash-toolbar .view-only { display: none; }

        .dash-edit-banner { display: none; align-items: center; gap: 10px; background: rgba(59, 130, 246, 0.12); border: 1px dashed #93c5fd; color: var(--text-main); padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 500; }
        .dash-edit-banner .mh-icon { width: 15px; height: 15px; flex-shrink: 0; vertical-align: -2px; }
        body.dashboard-edit-mode .dash-edit-banner { display: flex; }

        /* ---------------------------------------------------------------
         * Widget grid
         * ------------------------------------------------------------- */
        .widget-grid { display: grid; grid-template-columns: repeat(3, 1fr); grid-auto-flow: dense; gap: 20px; margin-bottom: 30px; }
        @media (max-width: 1024px) { .widget-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px) { .widget-grid { grid-template-columns: 1fr; } }

        .dash-widget { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border-color, #e2e8f0); box-shadow: var(--shadow-md); overflow: hidden; display: flex; flex-direction: column; grid-column: span 1; transition: box-shadow 0.2s ease, transform 0.2s ease; }
        .dash-widget:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
        .widget-span-medium { grid-column: span 2; }
        .widget-span-large { grid-column: 1 / -1; }
        @media (max-width: 1024px) { .widget-span-medium, .widget-span-large { grid-column: span 2; } }
        @media (max-width: 640px) { .widget-span-medium, .widget-span-large { grid-column: span 1; } }

        .dash-widget.hidden-widget { display: none; }
        .dash-widget.dragging { opacity: 0.4; }
        body.dashboard-edit-mode .dash-widget { border: 1.5px dashed var(--text-muted); }
        body.dashboard-edit-mode .dash-widget:hover { border-color: #4ca1af; }

        .widget-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--border-color, #e2e8f0); background: var(--bg-hover); }
        .widget-title { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .widget-title h3 { margin: 0; font-size: 1rem; color: var(--heading-color, #1e293b); font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .widget-icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; background: rgba(76, 161, 175, 0.12); color: var(--primary, #4ca1af); flex-shrink: 0; }
        .widget-icon .mh-icon { width: 18px; height: 18px; }
        .drag-handle { display: none; cursor: grab; color: var(--text-muted); user-select: none; line-height: 1; }
        .drag-handle .mh-icon { width: 16px; height: 16px; }
        body.dashboard-edit-mode .drag-handle { display: inline-flex; }
        .drag-handle:active { cursor: grabbing; }

        .widget-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .widget-link { display: inline-flex; align-items: center; gap: 4px; font-size: 0.8rem; color: #3b82f6; text-decoration: none; font-weight: bold; white-space: nowrap; }
        .widget-link .mh-icon { width: 13px; height: 13px; }
        body.dashboard-edit-mode .widget-link { display: none; }

        .widget-resize-btn, .widget-remove-btn { display: none; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; border: none; cursor: pointer; flex-shrink: 0; }
        .widget-resize-btn .mh-icon, .widget-remove-btn .mh-icon { width: 14px; height: 14px; }
        .widget-resize-btn { background: rgba(14, 165, 233, 0.16); color: #0ea5e9; }
        .widget-resize-btn:hover { background: rgba(14, 165, 233, 0.28); }
        .widget-remove-btn { background: rgba(220, 38, 38, 0.14); color: #ef4444; }
        .widget-remove-btn:hover { background: rgba(220, 38, 38, 0.26); }
        body.dashboard-edit-mode .widget-resize-btn, body.dashboard-edit-mode .widget-remove-btn { display: flex; }

        .widget-body { padding: 18px; flex: 1; }
        .widget-table.data-table { margin: 0; }
        .widget-table.data-table th, .widget-table.data-table td { padding: 8px 4px; font-size: 0.85rem; }

        .widget-stat { text-align: center; padding: 6px 0 14px 0; }
        .widget-stat-number { font-size: 2.2rem; font-weight: bold; line-height: 1; color: var(--heading-color, #0f172a); }
        .widget-stat-label { margin-top: 6px; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: bold; letter-spacing: 0.3px; }

        .widget-chip-row { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .widget-chip { padding: 5px 13px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.01em; white-space: nowrap; box-shadow: inset 0 0 0 1px rgba(15,23,42,0.06); }

        .widget-list { list-style: none; margin: 10px 0 0 0; padding: 0; }
        .widget-list li { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px dashed var(--border-color, #e2e8f0); font-size: 0.85rem; }
        .widget-list li:last-child { border-bottom: none; }
        .widget-list li a { color: var(--heading-color, #0f172a); font-weight: bold; text-decoration: none; }
        .widget-list li a:hover { color: #3b82f6; }
        .widget-list-meta { color: var(--text-muted); font-size: 0.78rem; white-space: nowrap; }

        .widget-empty { text-align: center; padding: 18px 5px; color: var(--text-muted); font-style: italic; font-size: 0.85rem; }

        .data-table { font-size: 0.95rem; width: 100%; border-collapse: collapse; }
        .data-table th { background-color: var(--bg-hover); color: var(--text-muted); font-weight: bold; text-transform: uppercase; padding: 15px; text-align: left; }
        .data-table td { padding: 15px; border-bottom: 1px solid var(--border-subtle, #f1f5f9); }
        .data-table tr:hover { background-color: var(--bg-hover); }

        .activity-feed { list-style: none; padding: 0; margin: 0; }
        .activity-item { padding: 12px 0; border-bottom: 1px dashed var(--border-color, #cbd5e1); display: flex; gap: 12px; }
        .activity-item:last-child { border-bottom: none; padding-bottom: 0; }
        .activity-icon { display: flex; background: rgba(76, 161, 175, 0.12); width: 32px; height: 32px; align-items: center; justify-content: center; border-radius: 50%; flex-shrink: 0; color: var(--primary, #4ca1af); }
        .activity-icon .mh-icon { width: 16px; height: 16px; }
        .activity-details p { margin: 0 0 4px 0; color: var(--text-main); font-size: 0.88rem; line-height: 1.4; }
        .activity-meta { font-size: 0.72rem; color: var(--text-muted); }
        .activity-role { font-weight: bold; color: #0ea5e9; text-transform: uppercase; }

        /* ---------------------------------------------------------------
         * Dashboard two-column layout: main widgets (queues/stats) on the
         * left, the clinic calendar pinned in a dedicated right-hand
         * column so staff can glance at today's agenda while working the
         * queues on the left, instead of scrolling to a full-width strip
         * at the very bottom of the page.
         * ------------------------------------------------------------- */
        .dashboard-columns { display: grid; grid-template-columns: 1fr 320px; gap: 30px; align-items: start; }
        .dashboard-side { display: flex; flex-direction: column; gap: 20px; position: sticky; top: 90px; }
        @media (max-width: 1024px) {
            .dashboard-columns { grid-template-columns: 1fr; }
            .dashboard-side { position: static; }
        }

        .unified-panel { margin-bottom: 0; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-color, #e2e8f0); box-shadow: var(--shadow-md); overflow: hidden;}
        .panel-controls { padding: 15px 25px; border-bottom: 1px solid var(--border-color, #e2e8f0);}
        .cal-panel-header {
            display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
            background: var(--bg-hover); border-top: 4px solid var(--primary, #4ca1af);
        }
        .cal-panel-header h3 { margin: 0; font-size: 1.05rem; color: var(--heading-color, #1e293b); }
        .cal-panel-header span { font-size: 0.78rem; color: var(--text-muted); }

        .cal-widget { background: var(--bg-card); color: var(--heading-color, #1e293b); border-radius: 12px; padding: 15px; font-family: 'Segoe UI', sans-serif; cursor: pointer; transition: 0.2s; border: 1px solid var(--border-color, #e2e8f0); box-shadow: var(--shadow-md); }
        .cal-widget:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); border-color: var(--primary, #4ca1af); }

        /* Appointment / "current day" chips drawn by renderCalendars() in
           the script below - classed so they follow the dark-mode tokens
           instead of a hardcoded light background + dark text pair. */
        .appt-chip { display: block; background: var(--bg-hover); color: var(--text-main); padding: 5px 6px; border-radius: 4px; font-size: 0.8rem; margin-top: 4px; border-left: 2px solid #3b82f6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
        .appt-chip strong { color: var(--heading-color, #0f172a); }
        .today-chip { background: rgba(14, 165, 233, 0.16); color: #0369a1; padding: 4px 6px; border-radius: 4px; font-size: 0.75rem; margin-top: 5px; margin-bottom: 5px; border-left: 2px solid #0284c7; font-weight: bold; }
        html[data-theme="dark"] .today-chip { color: #7dd3fc; }
        
        .cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-weight: bold; color: var(--heading-color, #1e293b); }
        .cal-header button { background: none; border: none; color: var(--text-muted); font-size: 1.1rem; cursor: pointer; transition: 0.2s; padding: 0 5px;}
        .cal-header button:hover { color: var(--heading-color, #1e293b); }
        
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
        .cal-day-name { font-size: 0.7rem; font-weight: bold; color: var(--text-muted); margin-bottom: 5px; text-transform: uppercase;}
        .cal-day { font-size: 0.85rem; padding: 4px 0; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; margin: auto; transition: 0.2s; color: var(--text-main); position: relative;}
        .cal-day.empty { visibility: hidden; }
        .cal-day:not(.empty):not(.today):hover { background: var(--bg-hover); }
        .cal-day.today { background: #4ca1af; color: white; font-weight: bold; }
        
        .appt-dot { display: block; width: 5px; height: 5px; background-color: #0ea5e9; border-radius: 50%; position: absolute; bottom: 2px; left: 50%; transform: translateX(-50%); }
        .cal-day.today .appt-dot { background-color: white; }
        
        .holiday-dot { background-color: #ef4444; }

        .universal-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .universal-modal.active { display: flex; }
        .universal-modal-content { background: var(--bg-card); width: 90%; max-width: 900px; height: 85vh; border-radius: 12px; padding: 0; position: relative; border: 1px solid var(--border-color, #cbd5e1); color: var(--text-main); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); display: flex; flex-direction: column; overflow: hidden;}
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color, #cbd5e1); padding: 15px 25px; background: var(--bg-hover); }
        .modal-header h2 { margin: 0; font-size: 1.2rem; color: var(--heading-color, #1e293b); display: flex; align-items: center; gap: 10px;}
        .modal-header h2 .mh-icon { width: 20px; height: 20px; }
        .modal-close { font-size: 1.5rem; color: var(--text-muted); cursor: pointer; background: none; border: none; line-height: 1; }
        .modal-close:hover { color: #b91c1c; }

        #calModal .universal-modal-content { height: auto; max-height: 90vh; padding: 30px; overflow-y: auto;}
        #calModal .modal-header { padding: 0 0 15px 0; background: transparent; border-bottom: 2px solid var(--border-color, #e2e8f0); margin-bottom: 20px;}

        .large-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px;}
        .large-cal-day-name { text-align: center; padding: 10px; font-weight: bold; color: var(--text-muted); border-bottom: 2px solid var(--border-color, #e2e8f0); text-transform: uppercase; font-size: 0.85rem;}
        .large-cal-day { min-height: 100px; padding: 10px; border-radius: 8px; background: var(--bg-hover); border: 1px solid var(--border-color, #e2e8f0); transition: 0.2s; display: flex; flex-direction: column; }
        .large-cal-day:hover { background: var(--bg-hover); border-color: var(--text-muted); }
        .large-cal-day.today { border: 2px solid #4ca1af; background: var(--bg-card); }
        .large-cal-number { font-size: 1.1rem; font-weight: bold; margin-bottom: 5px; color: var(--text-main); }
        .large-cal-day.today .large-cal-number { color: #4ca1af; }

        .appt-block { cursor: pointer; transition: 0.2s; }
        .appt-block:hover { filter: brightness(0.9); transform: scale(1.02); }
        
        .holiday-cell { background: rgba(239, 68, 68, 0.14) !important; border-color: #fca5a5 !important; }
        .holiday-label { background: #ef4444; color: white; padding: 4px 6px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 5px; text-transform: uppercase; text-align: center;}
        .holiday-label .mh-icon { width: 11px; height: 11px; }

        /* ---------------------------------------------------------------
         * Add Widget modal (lists currently hidden widgets)
         * ------------------------------------------------------------- */
        #addWidgetModal .universal-modal-content { height: auto; max-height: 80vh; max-width: 560px; }
        #addWidgetModal .modal-body { padding: 10px 25px 25px 25px; overflow-y: auto; }
        .add-widget-row { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--border-subtle, #f1f5f9); }
        .add-widget-row:last-child { border-bottom: none; }
        .add-widget-icon { display: flex; width: 40px; height: 40px; align-items: center; justify-content: center; background: rgba(76, 161, 175, 0.12); border-radius: 10px; flex-shrink: 0; color: var(--primary, #4ca1af); }
        .add-widget-icon .mh-icon { width: 20px; height: 20px; }
        .add-widget-info { flex: 1; min-width: 0; }
        .add-widget-info strong { display: block; color: var(--heading-color, #1e293b); font-size: 0.95rem; }
        .add-widget-info span { color: var(--text-muted); font-size: 0.8rem; }
        .add-widget-btn { padding: 8px 16px; border-radius: 6px; border: none; background: var(--primary, #4ca1af); color: white; font-weight: bold; font-size: 0.85rem; cursor: pointer; flex-shrink: 0; }
        .add-widget-btn:hover { background: var(--primary-hover, #3b828e); }
        #addWidgetEmpty { text-align: center; color: var(--text-muted); font-style: italic; padding: 30px 0; display: none; }

        .holiday-manage-panel { margin-top: 30px; border-top: 2px solid var(--border-color, #e2e8f0); padding-top: 20px; }
        .holiday-manage-panel .holiday-forms { display: flex; gap: 40px; background: var(--bg-hover); padding: 20px; border-radius: 8px; flex-wrap: wrap; }
        .holiday-manage-panel form { flex: 1; min-width: 240px; }
        .holiday-manage-panel h4 { margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; }
        .holiday-manage-panel input, .holiday-manage-panel select {
            padding: 10px; border: 1px solid var(--border-color, #cbd5e1); border-radius: 6px;
            background: var(--bg-input); color: var(--text-main);
        }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <div class="welcome-banner">
                <div class="welcome-copy">
                    <span class="welcome-eyebrow"><?php echo mh_icon('activity'); ?> Staff Dashboard</span>
                    <h1>Welcome back, <?php echo htmlspecialchars($first_name); ?>!</h1>
                    <p>Here is what is happening at <strong><?php echo htmlspecialchars($system_name ?? mh_setting('system_name')); ?></strong> today.</p>
                </div>
                <span class="welcome-icon"><?php echo mh_icon('grid'); ?></span>
            </div>

            <div class="dash-toolbar">
                <button type="button" class="view-only" id="customizeBtn" onclick="mhToggleCustomize()"><?php echo mh_icon('sliders'); ?> Customize Dashboard</button>

                <button type="button" class="edit-only" onclick="mhOpenAddWidgetModal()"><?php echo mh_icon('plus'); ?> Add Widget</button>
                <button type="button" class="edit-only" onclick="mhResetLayout()"><?php echo mh_icon('rotate-ccw'); ?> Reset Default</button>
                <button type="button" class="edit-only btn-primary-toolbar" onclick="mhSaveLayout()"><?php echo mh_icon('save'); ?> Save Layout</button>
                <button type="button" class="edit-only" onclick="mhToggleCustomize()"><?php echo mh_icon('check'); ?> Done</button>
            </div>

            <div class="dash-edit-banner">
                <?php echo mh_icon('edit'); ?> Customize mode is on — drag <?php echo mh_icon('move'); ?> to reorder widgets, use <?php echo mh_icon('maximize'); ?> to resize, or <?php echo mh_icon('x'); ?> to remove one. Don't forget to Save Layout.
            </div>

            <div class="dashboard-columns">

                <div class="widget-grid" id="widgetGrid">
                    <?php
                    $size_class_map = ['small' => '', 'medium' => 'widget-span-medium', 'large' => 'widget-span-large'];
                    foreach ($widget_layout as $entry):
                        $wid = $entry['id'];
                        if (!isset($widget_catalog[$wid])) { continue; }
                        $widget  = $widget_catalog[$wid];
                        $visible = !empty($entry['visible']);
                        $size    = $entry['size'] ?? $widget['size'];
                        $span_class = $size_class_map[$size] ?? '';
                    ?>
                        <div class="dash-widget <?php echo $span_class; ?><?php echo $visible ? '' : ' hidden-widget'; ?>"
                             data-widget-id="<?php echo htmlspecialchars($wid); ?>"
                             data-widget-title="<?php echo htmlspecialchars($widget['title']); ?>"
                             data-widget-icon="<?php echo htmlspecialchars(mh_icon($widget['icon'])); ?>"
                             data-widget-module="<?php echo htmlspecialchars($widget['module_label']); ?>"
                             data-widget-size="<?php echo htmlspecialchars($size); ?>">
                            <div class="widget-header" style="border-top: 4px solid <?php echo htmlspecialchars($widget['color']); ?>;">
                                <div class="widget-title">
                                    <span class="drag-handle" title="Drag to reorder"><?php echo mh_icon('move'); ?></span>
                                    <span class="widget-icon"><?php echo mh_icon($widget['icon']); ?></span>
                                    <h3><?php echo htmlspecialchars($widget['title']); ?></h3>
                                </div>
                                <div class="widget-actions">
                                    <a class="widget-link" href="<?php echo htmlspecialchars($widget['module_page']); ?>">Open <?php echo mh_icon('arrow-right'); ?></a>
                                    <button type="button" class="widget-resize-btn" title="Resize widget" onclick="mhCycleSize(this)"><?php echo mh_icon('maximize'); ?></button>
                                    <button type="button" class="widget-remove-btn" title="Remove widget" onclick="mhRemoveWidget(this)"><?php echo mh_icon('x'); ?></button>
                                </div>
                            </div>
                            <div class="widget-body">
                                <?php call_user_func($widget['render'], $conn, $widget_ctx); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <aside class="dashboard-side">

                    <div class="unified-panel">
                        <div class="panel-controls cal-panel-header">
                            <h3>This Week at a Glance</h3>
                            <span>Click the calendar to open the full clinic agenda</span>
                        </div>
                        <div style="padding: 20px;">
                            <div class="cal-widget" onclick="openCalendarModal()" style="border:none; box-shadow:none; padding:0;">
                                <div class="cal-header">
                                    <button onclick="changeMonth(-1, event)">&lt;</button>
                                    <span id="mini-month-year" style="font-size: 1rem; letter-spacing: 0.5px;"></span>
                                    <button onclick="changeMonth(1, event)">&gt;</button>
                                </div>
                                <div class="cal-grid" id="mini-cal-grid"></div>
                            </div>
                        </div>
                    </div>

                    <div class="cal-widget" onclick="openCalendarModal()">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; color: var(--primary, #4ca1af);">
                            <span style="font-weight: bold; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 8px;"><?php echo mh_icon('calendar'); ?> Full Clinic Agenda</span>
                        </div>
                        <p style="margin:0; color: var(--text-muted); font-size:0.85rem; line-height:1.5;">
                            View the month-by-month appointment calendar<?php echo $show_admin_pages ? ', and declare or remove holidays.' : '.'; ?>
                        </p>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: 0.75rem; color: var(--primary, #4ca1af); font-weight: 500;">
                            <span>Open full calendar</span>
                            <span style="display:inline-flex;"><?php echo mh_icon('arrow-right'); ?></span>
                        </div>
                    </div>

                </aside>

            </div>

        </div>
    </div>

    <div class="universal-modal cal-modal" id="calModal">
        <div class="universal-modal-content">
            <div class="modal-header">
                <h2><span style="color: var(--primary, #4ca1af); margin-right:10px; display:inline-flex; vertical-align:-4px;"><?php echo mh_icon('calendar'); ?></span> Clinic Agenda</h2>
                <div class="cal-header" style="margin: 0; min-width: 200px; justify-content: space-between;">
                    <button onclick="changeMonth(-1, event)">&lt;</button>
                    <span id="large-month-year" style="font-size: 1.2rem; color: var(--heading-color, #1e293b);"></span>
                    <button onclick="changeMonth(1, event)">&gt;</button>
                </div>
                <button class="modal-close" onclick="closeCalendarModal()">&times;</button>
            </div>
            
            <div class="large-cal-grid" id="large-cal-grid">
                </div>

            <?php if($show_admin_pages): ?>
            <div class="holiday-manage-panel">
                <div class="holiday-forms">
                    <form method="POST">
                        <h4 class="text-danger"><?php echo mh_icon('flag'); ?> Declare Holiday (Close Clinic)</h4>
                        <div style="display: flex; gap: 10px;">
                            <input type="date" name="holiday_date" required>
                            <input type="text" name="holiday_desc" placeholder="Event Name (e.g., Christmas)" required style="flex: 1;">
                            <button type="submit" name="add_holiday" data-confirm="Mark this date as a holiday?" data-confirm-title="Mark holiday?" data-confirm-type="warning" data-confirm-ok="Mark holiday" class="btn-submit" style="background: #ef4444; border-radius: 6px;">Mark Holiday</button>
                        </div>
                    </form>

                    <form method="POST">
                        <h4>Remove Holiday (Open Clinic)</h4>
                        <div style="display: flex; gap: 10px;">
                            <select name="remove_date" required style="flex: 1;">
                                <option value="" disabled selected>Select active holiday to remove...</option>
                                <?php foreach($holidays_data as $date => $desc): ?>
                                    <option value="<?php echo $date; ?>"><?php echo date("M d, Y", strtotime($date)) . " - " . htmlspecialchars($desc); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="remove_holiday" data-confirm="Remove this holiday?" data-confirm-title="Remove holiday?" data-confirm-type="warning" data-confirm-ok="Remove" class="btn-secondary" style="border-radius: 6px; font-weight: bold;">Remove</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <div class="universal-modal" id="addWidgetModal">
        <div class="universal-modal-content">
            <div class="modal-header">
                <h2><span style="color:#4ca1af; margin-right:8px; display:inline-flex; vertical-align:-4px;"><?php echo mh_icon('plus-circle'); ?></span> Add Widget</h2>
                <button class="modal-close" onclick="closeAddWidgetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="addWidgetList"></div>
                <p id="addWidgetEmpty">Every available widget is already on your dashboard.</p>
            </div>
        </div>
    </div>

    <script>
        /* =================================================================
         * CALENDAR (unchanged behavior from the original dashboard)
         * ============================================================== */
        const calendarData = <?php echo json_encode($calendar_data); ?>;
        const holidaysData = <?php echo json_encode($holidays_data); ?>; 
        const MH_ICON_FLAG = <?php echo json_encode(mh_icon('flag')); ?>;
        
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        const dayNames = ["S", "M", "T", "W", "T", "F", "S"];
        
        let currentDate = new Date();
        let displayMonth = currentDate.getMonth();
        let displayYear = currentDate.getFullYear();

        function renderCalendars() {
            document.getElementById('mini-month-year').innerText = `${monthNames[displayMonth].substring(0,3)} ${displayYear}`;
            document.getElementById('large-month-year').innerText = `${monthNames[displayMonth]} ${displayYear}`;

            const firstDay = new Date(displayYear, displayMonth, 1).getDay();
            const daysInMonth = new Date(displayYear, displayMonth + 1, 0).getDate();
            
            const isCurrentMonth = (displayMonth === currentDate.getMonth() && displayYear === currentDate.getFullYear());
            const todayDate = currentDate.getDate();

            let strMonth = String(displayMonth + 1).padStart(2, '0');

            let miniHtml = '';
            dayNames.forEach(day => { miniHtml += `<div class="cal-day-name">${day}</div>`; });
            for(let i=0; i<firstDay; i++) { miniHtml += `<div class="cal-day empty"></div>`; }
            for(let d=1; d<=daysInMonth; d++) {
                let classes = "cal-day";
                if(isCurrentMonth && d === todayDate) classes += " today";
                
                let strDay = String(d).padStart(2, '0');
                let dateKey = `${displayYear}-${strMonth}-${strDay}`;
                
                let isHoliday = holidaysData[dateKey] !== undefined;
                
                let dotHtml = '';
                if(isHoliday) {
                    dotHtml = `<span class='appt-dot holiday-dot'></span>`; 
                } else if(calendarData[dateKey]) {
                    dotHtml = `<span class='appt-dot'></span>`; 
                }
                
                miniHtml += `<div class="${classes}">${d}${dotHtml}</div>`;
            }
            document.getElementById('mini-cal-grid').innerHTML = miniHtml;

            let largeHtml = '';
            dayNames.forEach(day => { largeHtml += `<div class="large-cal-day-name">${day}</div>`; });
            for(let i=0; i<firstDay; i++) { largeHtml += `<div></div>`; } 
            
            for(let d=1; d<=daysInMonth; d++) {
                let classes = "large-cal-day";
                if(isCurrentMonth && d === todayDate) classes += " today";
                
                let strDay = String(d).padStart(2, '0');
                let dateKey = `${displayYear}-${strMonth}-${strDay}`;
                let isHoliday = holidaysData[dateKey] !== undefined;

                if(isHoliday) classes += " holiday-cell"; 
                
                let apptHtml = ""; 
                if(isCurrentMonth && d === todayDate && !isHoliday) {
                    apptHtml += `<div class="today-chip">Current Day</div>`;
                }

                if(isHoliday) {
                    apptHtml += `<div class="holiday-label">${MH_ICON_FLAG} ${holidaysData[dateKey]}</div>`;
                } else if(calendarData[dateKey]) {
                    calendarData[dateKey].forEach(appt => {
                        apptHtml += `
                        <div class="appt-block appt-chip" onclick="window.location.href='appointments.php'" title="${appt.name} (${appt.dept})">
                            <strong>${appt.name}</strong>
                        </div>`;
                    });
                }

                largeHtml += `
                    <div class="${classes}">
                        <div class="large-cal-number">${d}</div>
                        ${apptHtml}
                    </div>
                `;
            }
            document.getElementById('large-cal-grid').innerHTML = largeHtml;
        }

        function changeMonth(step, event) {
            if(event) event.stopPropagation(); 
            displayMonth += step;
            if(displayMonth < 0) { displayMonth = 11; displayYear--; }
            else if(displayMonth > 11) { displayMonth = 0; displayYear++; }
            renderCalendars();
        }

        function openCalendarModal() {
            document.getElementById('calModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeCalendarModal() {
            document.getElementById('calModal').classList.remove('active');
            document.body.style.overflow = '';
            window.history.replaceState({}, document.title, "dashboard.php");
        }

        renderCalendars();

        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if(urlParams.get('cal') === 'open') {
                openCalendarModal();
            }
        }

        /* =================================================================
         * CUSTOMIZABLE DASHBOARD
         * ============================================================== */
        const widgetGrid = document.getElementById('widgetGrid');
        let mhDraggingEl = null;

        function mhToggleCustomize() {
            document.body.classList.toggle('dashboard-edit-mode');
            const editing = document.body.classList.contains('dashboard-edit-mode');
            widgetGrid.querySelectorAll('.dash-widget').forEach(el => {
                el.setAttribute('draggable', editing ? 'true' : 'false');
            });
        }

        // --- Drag & drop reordering (native HTML5 D&D, no dependencies) ---
        widgetGrid.addEventListener('dragstart', function(e) {
            const widget = e.target.closest('.dash-widget');
            if (!widget) return;
            mhDraggingEl = widget;
            widget.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        widgetGrid.addEventListener('dragend', function() {
            if (mhDraggingEl) mhDraggingEl.classList.remove('dragging');
            mhDraggingEl = null;
        });

        widgetGrid.addEventListener('dragover', function(e) {
            if (!mhDraggingEl) return;
            e.preventDefault();
            const afterEl = mhGetDragAfterElement(widgetGrid, e.clientX, e.clientY);
            if (afterEl == null) {
                widgetGrid.appendChild(mhDraggingEl);
            } else {
                widgetGrid.insertBefore(mhDraggingEl, afterEl);
            }
        });

        function mhGetDragAfterElement(container, x, y) {
            const els = [...container.querySelectorAll('.dash-widget:not(.dragging):not(.hidden-widget)')];
            let closest = { offset: Number.NEGATIVE_INFINITY, element: null };
            els.forEach(el => {
                const box = el.getBoundingClientRect();
                const centerX = box.left + box.width / 2;
                const centerY = box.top + box.height / 2;
                // Treat this as "above" the cursor if it's in an earlier row,
                // or the same row but to the left - works for a wrapping grid.
                const sameRow = Math.abs(centerY - y) < box.height / 2;
                const offset = sameRow ? (x - centerX) : (y - centerY) * 1000;
                if (offset < 0 && offset > closest.offset) {
                    closest = { offset, element: el };
                }
            });
            return closest.element;
        }

        // --- Remove / Resize / Add widgets ---
        function mhRemoveWidget(btn) {
            const widget = btn.closest('.dash-widget');
            if (widget) widget.classList.add('hidden-widget');
        }

        const MH_SIZE_CYCLE = { small: 'medium', medium: 'large', large: 'small' };
        const MH_SIZE_CLASS = { small: '', medium: 'widget-span-medium', large: 'widget-span-large' };

        function mhCycleSize(btn) {
            const widget = btn.closest('.dash-widget');
            if (!widget) return;
            const current = widget.dataset.widgetSize || 'small';
            const next = MH_SIZE_CYCLE[current] || 'small';

            Object.values(MH_SIZE_CLASS).forEach(cls => { if (cls) widget.classList.remove(cls); });
            if (MH_SIZE_CLASS[next]) widget.classList.add(MH_SIZE_CLASS[next]);
            widget.dataset.widgetSize = next;
        }

        function mhOpenAddWidgetModal() {
            const listEl = document.getElementById('addWidgetList');
            const emptyEl = document.getElementById('addWidgetEmpty');
            listEl.innerHTML = '';

            const hidden = widgetGrid.querySelectorAll('.dash-widget.hidden-widget');
            if (hidden.length === 0) {
                emptyEl.style.display = 'block';
            } else {
                emptyEl.style.display = 'none';
                hidden.forEach(el => {
                    const row = document.createElement('div');
                    row.className = 'add-widget-row';
                    row.innerHTML = `
                        <div class="add-widget-icon">${el.dataset.widgetIcon}</div>
                        <div class="add-widget-info">
                            <strong>${el.dataset.widgetTitle}</strong>
                            <span>${el.dataset.widgetModule}</span>
                        </div>
                        <button type="button" class="add-widget-btn">Add</button>
                    `;
                    row.querySelector('.add-widget-btn').addEventListener('click', function() {
                        mhAddWidget(el.dataset.widgetId);
                    });
                    listEl.appendChild(row);
                });
            }

            document.getElementById('addWidgetModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeAddWidgetModal() {
            document.getElementById('addWidgetModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function mhAddWidget(widgetId) {
            const widget = widgetGrid.querySelector(`.dash-widget[data-widget-id="${widgetId}"]`);
            if (!widget) return;
            widget.classList.remove('hidden-widget');
            widgetGrid.appendChild(widget); // newly added widgets land at the end
            mhOpenAddWidgetModal(); // refresh the list in place
        }

        // --- Save / Reset ---
        function mhCollectLayout() {
            return [...widgetGrid.querySelectorAll('.dash-widget')].map(el => ({
                id: el.dataset.widgetId,
                visible: !el.classList.contains('hidden-widget'),
                size: el.dataset.widgetSize || 'small',
            }));
        }

        async function mhSaveLayout() {
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = 'Saving…';

            try {
                const r = await fetch('api/save_dashboard.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save', widgets: mhCollectLayout() })
                });
                const data = await r.json();
                btn.disabled = false;
                btn.innerHTML = data.success ? 'Saved!' : 'Save Layout';
                if (!data.success) { await mh_alert(data.message || 'Could not save the layout.', 'Save Failed', 'danger'); }
                if (data.success) { setTimeout(() => { btn.innerHTML = originalText; }, 1500); }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = originalText;
                await mh_alert('Could not reach the server. Please try again.', 'Connection Error', 'danger');
            }
        }

        async function mhResetLayout() {
            const ok = await mh_confirm('Reset your dashboard to the default widgets and order? This cannot be undone.', 'Reset Dashboard?', 'warning');
            if (!ok) return;

            try {
                const r = await fetch('api/save_dashboard.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reset' })
                });
                const data = await r.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    await mh_alert(data.message || 'Could not reset the layout.', 'Reset Failed', 'danger');
                }
            } catch (err) {
                await mh_alert('Could not reach the server. Please try again.', 'Connection Error', 'danger');
            }
        }
    </script>
</body>
</html>