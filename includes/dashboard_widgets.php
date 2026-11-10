<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - DASHBOARD WIDGET CATALOG
 * =====================================================================
 * Single source of truth for every widget that can appear on
 * admin/dashboard.php. Each entry in mh_dashboard_catalog() describes
 * one module's widget (title, icon, accent color, which roles may see
 * it, how wide it renders, and which function draws its body), and
 * every widget has a small mh_widget_xxx() renderer below that echoes
 * its inner HTML.
 *
 * Adding a 10th widget later means: add one entry to the catalog array
 * and write one mh_widget_xxx() function. admin/dashboard.php,
 * includes/dashboard_helper.php and admin/api/save_dashboard.php never
 * need to change.
 *
 * Renderers only ECHO the widget body (the scrollable content area).
 * The header (title/icon/drag handle/remove button) is drawn once,
 * generically, by admin/dashboard.php.
 *
 * 'icon' values are names understood by mh_icon() (includes/icons.php),
 * not raw emoji - see that file for the full icon set.
 */

require_once __DIR__ . '/icons.php';

if (!function_exists('mh_wq_all')) {
    /**
     * Dashboard query helpers: PREPARED statements on the site's existing mysqli connection ($conn).
     * (Positional ? only; values are bound as strings and MySQL coerces them.)
     * Failures are logged with the real MySQL error and return an empty result instead of a blank page.
     */
    function mh_wq_all(mysqli $conn, $sql, array $params = [])
    {
        $stmt = @$conn->prepare($sql);
        if (!$stmt) {
            error_log('[MarickHealth] dashboard SQL prepare failed: ' . $conn->error . ' | ' . preg_replace('/\s+/', ' ', $sql));
            return [];
        }
        if ($params) {
            $params = array_values($params);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        }
        if (!$stmt->execute()) {
            error_log('[MarickHealth] dashboard SQL failed: ' . $stmt->error . ' | ' . preg_replace('/\s+/', ' ', $sql));
            $stmt->close();
            return [];
        }
        $res  = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}
if (!function_exists('mh_wq_one')) {
    function mh_wq_one(mysqli $conn, $sql, array $params = [])
    {
        $rows = mh_wq_all($conn, $sql, $params);
        return $rows ? $rows[0] : null;
    }
}
if (!function_exists('mh_wq_value')) {
    function mh_wq_value(mysqli $conn, $sql, array $params = [], $default = 0)
    {
        $row = mh_wq_one($conn, $sql, $params);
        return $row ? reset($row) : $default;
    }
}

if (!function_exists('mh_dashboard_catalog')) {
    /**
     * @return array<string, array{
     *   title:string, icon:string, color:string, module_page:string,
     *   module_label:string, roles:string[], size:string, render:string
     * }>
     * Order of this array is the DEFAULT widget order for a user who
     * has never customized their dashboard. 'size' here is the
     * fallback/default span - a user's saved layout can override it
     * per widget (see includes/dashboard_helper.php).
     */
    function mh_dashboard_catalog()
    {
        return [
            'active_queue' => [
                'title'         => "Today's Active Queue",
                'icon'          => 'users',
                'color'         => '#0ea5e9',
                'module_page'   => 'appointments.php',
                'module'        => 'walkin',   // kept from the old walk-in widget so custom roles with that Module Access still get it
                'module_label'  => 'Patient Queue',
                'roles'         => ['admin', 'doctor', 'bhw', 'midwife', 'dental'],   // union of the two widgets it replaces
                'size'          => 'medium',
                'render'        => 'mh_widget_active_queue',
            ],
            'department_queues' => [
                'title'         => "Departments - Waiting Today",
                'icon'          => 'folder',
                'color'         => '#6366f1',
                'module_page'   => 'appointments.php',
                'module_label'  => 'Patient Queue',
                'roles'         => ['admin', 'doctor', 'bhw', 'midwife', 'dental', 'inventory'],
                'size'          => 'small',
                'render'        => 'mh_widget_department_queues',
            ],
            'disease_alerts' => [
                'title'         => "Disease Alerts",
                'icon'          => 'shield',
                'color'         => '#dc2626',
                'module_page'   => 'disease_statistics.php',
                'module_label'  => 'Disease Statistics',
                'roles'         => ['admin', 'doctor'],
                'size'          => 'small',
                'render'        => 'mh_widget_disease_alerts',
            ],
            'immunization_due' => [
                'title'         => 'Immunizations Due This Week',
                'icon'          => 'droplet',
                'color'         => '#8b5cf6',
                'module_page'   => 'immunization.php',
                'module'        => 'immunization',
                'module_label'  => 'Child Immunization',
                'caps'          => ['can_view_immunization_records'],   // also needs this Delegated Function (strict sub-permission)
                'roles'         => ['admin', 'doctor', 'midwife'],
                'size'          => 'small',
                'render'        => 'mh_widget_immunization_due',
            ],
            'maternal_followups' => [
                'title'         => 'Maternal Care Follow-Ups',
                'icon'          => 'heart',
                'color'         => '#ec4899',
                'module_page'   => 'maternal_care.php',
                'module'        => 'maternal_care',
                'module_label'  => 'Maternal Care',
                'caps'          => ['can_view_maternal_history'],   // also needs this Delegated Function (strict sub-permission)
                'roles'         => ['admin', 'doctor', 'midwife'],
                'size'          => 'small',
                'render'        => 'mh_widget_maternal_followups',
            ],
            'inventory_alerts' => [
                'title'         => 'Low Stock & Expiring Medicine',
                'icon'          => 'package',
                'color'         => '#ef4444',
                'module_page'   => 'inventory.php',
                'module'        => 'inventory',
                'module_label'  => 'Medicine Inventory',
                'caps'          => ['can_manage_supply_masterlist'],   // also needs this Delegated Function (strict sub-permission)
                'roles'         => ['admin', 'inventory'],
                'size'          => 'medium',
                'render'        => 'mh_widget_inventory_alerts',
            ],
            'dental_today' => [
                'title'         => "Today's Dental Records",
                'icon'          => 'smile',
                'color'         => '#06b6d4',
                'module_page'   => 'dental.php',
                'module'        => 'dental',
                'module_label'  => 'Dental Clinic',
                'caps'          => ['can_view_dental_history'],   // also needs this Delegated Function (strict sub-permission)
                'roles'         => ['admin', 'doctor', 'midwife', 'dental'],
                'size'          => 'small',
                'render'        => 'mh_widget_dental_today',
            ],
            'sms_queue' => [
                'title'         => 'SMS Reminders',
                'icon'          => 'message',
                'color'         => '#f59e0b',
                'module_page'   => 'sms_dashboard.php',
                'module'        => 'sms_reminders',
                'module_label'  => 'SMS Reminders',
                'roles'         => ['admin'],
                'size'          => 'small',
                'render'        => 'mh_widget_sms_queue',
            ],
            'patient_registrations' => [
                'title'         => 'Patient Masterlist',
                'icon'          => 'users',
                'color'         => '#10b981',
                'module_page'   => 'patient_masterlist.php',
                'module'        => 'patient_masterlist',
                'module_label'  => 'Patient Masterlist',
                'roles'         => ['admin', 'doctor', 'midwife', 'dental'],
                'size'          => 'small',
                'render'        => 'mh_widget_patient_registrations',
            ],
            'system_activity' => [
                'title'         => 'System Activity Log',
                'icon'          => 'activity',
                'color'         => '#64748b',
                'module_page'   => 'activity_logs.php',
                'module_label'  => 'System Logs',
                'roles'         => ['admin'],
                'size'          => 'medium',
                'render'        => 'mh_widget_system_activity',
            ],
        ];
    }
}

/* =====================================================================
 * Small shared helper: does a table exist? Used by widgets that read
 * from tables installed lazily by another module (e.g. the SMS tables,
 * which are only created the first time admin/sms_dashboard.php runs).
 * ================================================================== */
if (!function_exists('mh_table_exists')) {
    function mh_table_exists(mysqli $conn, string $table)
    {
        static $cache = [];
        $key = strtolower($table);
        if (!isset($cache[$key])) {
            $row = mh_wq_one($conn, 'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $cache[$key] = $row && (int) $row['n'] > 0;
        }
        return $cache[$key];   // cached for the request
    }
}

/**
 * `next_visit_date` on consultations / maternal_records /
 * child_immunization_records is only added by the SMS follow-up
 * migration (database/sms_schema.sql's ALTER TABLE ... ADD COLUMN IF
 * NOT EXISTS), not the base schema.sql. A fresh install that hasn't
 * run that migration yet won't have the column, so any widget that
 * reads it checks first instead of throwing a SQL error.
 */
if (!function_exists('mh_column_exists')) {
    function mh_column_exists(mysqli $conn, string $table, string $column)
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (!isset($cache[$key])) {
            $row = mh_wq_one($conn, 'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            $cache[$key] = $row && (int) $row['n'] > 0;
        }
        return $cache[$key];   // cached for the request
    }
}

/* =====================================================================
 * 1. TODAY'S ACTIVE QUEUE  (walk-ins + scheduled consultations, one list)
 *
 * Walk-ins and scheduled visits live in the SAME `appointments` table. A walk-in is the row that
 * mh_enqueue_patient() (includes/queue_helper.php) writes with reason = 'Walk-In Queue Registration';
 * everything else is a scheduled consultation. The filter, department visibility and ordering below
 * deliberately mirror admin/appointments.php so this widget can never disagree with the Patient Queue page.
 * ================================================================== */
if (!defined('MH_WALKIN_REASON')) {
    define('MH_WALKIN_REASON', 'Walk-In Queue Registration');
}
if (!function_exists('mh_widget_active_queue')) {
    function mh_widget_active_queue(mysqli $conn, array $ctx)
    {
        require_once __DIR__ . '/settings_helper.php';   // mh_all_settings(), mh_clinic_departments(), mh_options()
        require_once __DIR__ . '/queue_helper.php';      // mh_queue_label() -> "GC-001"

        $today    = $ctx['today'];
        $limit    = 8;                                                    // rows shown; the rest is "+N more"
        $statuses = ['Pending', 'Rescheduled', 'Waiting', 'Approved'];    // same set the Patient Queue page lists

        // --- Departments this staff member may see (same rule as the Patient Queue page) ---
        mh_all_settings($conn);   // load Module Access before evaluating it
        $cands = mh_clinic_departments($conn);
        foreach (mh_wq_all($conn, "SELECT DISTINCT department FROM appointments WHERE appointment_date = ?", [$today]) as $r) {
            $cands[] = $r['department'];
        }
        $hidden = [];
        foreach (mh_options($conn, 'clinic_departments', false) as $opt) {
            if (!(int) $opt['is_active']) { $hidden[] = $opt['option_value']; }
        }
        $visible = mh_departments_visible_to(
            strtolower($_SESSION['role'] ?? ''),
            array_values(array_diff(array_unique($cands), $hidden))
        );   // null = every department

        $where  = "a.appointment_date = ? AND a.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
        $params = array_merge([$today], $statuses);
        if ($visible !== null) {
            if ($visible) {
                $where .= " AND a.department IN (" . implode(',', array_fill(0, count($visible), '?')) . ")";
                foreach ($visible as $v) { $params[] = (string) $v; }
            } else {
                $where .= " AND 1 = 0";
            }
        }

        // --- Totals for the chips (walk-in vs scheduled) ---
        $walkin_total = 0; $sched_total = 0;
        foreach (mh_wq_all($conn,
            "SELECT (a.reason = ?) AS is_walkin, COUNT(*) AS c
               FROM appointments a
              WHERE $where
              GROUP BY is_walkin",
            array_merge([MH_WALKIN_REASON], $params)
        ) as $r) {
            if ((int) $r['is_walkin'] === 1) { $walkin_total = (int) $r['c']; } else { $sched_total += (int) $r['c']; }
        }
        $total = $walkin_total + $sched_total;

        // --- The list itself, in the same order as the Patient Queue page ---
        $rows = $total === 0 ? [] : mh_wq_all($conn,
            "SELECT a.id, a.department, a.status, a.queue_number,
                    (a.reason = ?) AS is_walkin,
                    p.first_name, p.last_name
               FROM appointments a
               JOIN patients p ON a.patient_id = p.id
              WHERE $where
              ORDER BY CASE a.status WHEN 'Pending' THEN 1 WHEN 'Rescheduled' THEN 2 ELSE 3 END ASC,
                       COALESCE(a.queue_number, 9999) ASC,
                       a.id ASC
              LIMIT " . (int) $limit,
            array_merge([MH_WALKIN_REASON], $params)
        );
        ?>
        <div class="widget-chip-row" style="margin-bottom: 12px;">
            <span class="widget-chip chip-blue"><?php echo $total; ?> In Queue</span>
            <span class="queue-tag queue-tag-walkin"><?php echo $walkin_total; ?> Walk-in</span>
            <span class="queue-tag queue-tag-sched"><?php echo $sched_total; ?> Scheduled</span>
        </div>
        <table class="data-table widget-table">
            <thead><tr><th>Queue #</th><th>Patient</th><th>Department</th><th>Type</th></tr></thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): $walkin = ((int) $row['is_walkin'] === 1); ?>
                        <tr>
                            <td style="white-space: nowrap; font-weight: bold; color: #0ea5e9;">
                                <?php echo $row['queue_number'] ? htmlspecialchars(mh_queue_label($row['department'], $row['queue_number']), ENT_QUOTES, 'UTF-8') : '&mdash;'; ?>
                            </td>
                            <td style="font-weight: bold; color: var(--heading-color, #0f172a);">
                                <?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="color: var(--text-muted); font-size: 0.85rem;"><span style="display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; background:<?php echo mh_department_color($row['department']); ?>;"></span><?php echo htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="queue-tag <?php echo $walkin ? 'queue-tag-walkin' : 'queue-tag-sched'; ?>"><?php echo $walkin ? 'Walk-in' : 'Scheduled'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" class="widget-empty">No patients in the queue right now.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="queue-widget-footer">
            <span class="widget-list-meta"><?php echo $total > $limit ? '+' . ($total - $limit) . ' more waiting' : ''; ?></span>
            <a class="queue-widget-viewall" href="appointments.php">View Full Queue <?php echo mh_icon('arrow-right'); ?></a>
        </div>
        <?php
    }
}

/* =====================================================================
 * 2b. DEPARTMENTS - WAITING TODAY (every department, including custom ones)
 * ================================================================== */
if (!function_exists('mh_widget_department_queues')) {
    function mh_widget_department_queues(mysqli $conn, array $ctx)
    {
        $today = $ctx['today'];
        $counts = [];
        foreach (mh_wq_all($conn, "SELECT department, COUNT(*) AS c FROM appointments
                            WHERE appointment_date = ? AND status IN ('Waiting', 'Approved') GROUP BY department", [$today]) as $r) {
            $counts[$r['department']] = (int) $r['c'];
        }
        $all     = array_column(mh_options($conn, 'clinic_departments', true), 'option_value');
        $visible = mh_departments_visible_to($_SESSION['role'] ?? '', $all);   // null = every department
        if ($visible !== null) { $all = array_values(array_intersect($all, $visible)); }
        $ids = [];
        foreach (mh_options($conn, 'clinic_departments', true) as $o) { $ids[$o['option_value']] = (int) $o['id']; }
        ?>
        <table class="data-table widget-table">
            <thead><tr><th>Department</th><th style="text-align:right;">Waiting</th></tr></thead>
            <tbody>
            <?php if (!$all): ?>
                <tr><td colspan="2" class="widget-empty">No departments available.</td></tr>
            <?php endif; ?>
            <?php foreach ($all as $dn):
                $page = mh_department_page($dn);
                $href = $page ?: ('department.php?id=' . ($ids[$dn] ?? 0));
                if ($page === 'dispense_medicine.php') { $href = 'appointments.php?dept=' . urlencode($dn); }
                $n = $counts[$dn] ?? 0;
            ?>
                <tr>
                    <td><a href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>" style="color:inherit; text-decoration:none; font-weight:700;">
                        <span style="display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:7px; background:<?php echo mh_department_color($dn); ?>;"></span><?php echo htmlspecialchars($dn); ?></a></td>
                    <td style="text-align:right; font-weight:800; color:<?php echo $n > 0 ? mh_department_color($dn) : 'var(--text-muted)'; ?>;"><?php echo $n; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

/* =====================================================================
 * 2c. DISEASE ALERTS (spikes / rising illnesses, counts only)
 * ================================================================== */
if (!function_exists('mh_widget_disease_alerts')) {
    function mh_widget_disease_alerts(mysqli $conn, array $ctx)
    {
        if (!mh_has_permission('view_disease_statistics')) { echo '<p class="widget-empty">Not available for your account.</p>'; return; }
        require_once __DIR__ . '/disease_stats.php';
        $s = mh_disease_compute($conn, $ctx['today']);
        $flag = array_values(array_filter($s['conditions'], function ($c) { return $c['status'] !== 'normal'; }));
        if (!$flag) { echo '<p class="widget-empty">No unusual increases this week.</p>'; return; }
        ?>
        <table class="data-table widget-table">
            <thead><tr><th>Condition</th><th style="text-align:right;">This week</th><th style="text-align:right;">Usual</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($flag, 0, 5) as $c): $hot = $c['status'] === 'spike'; ?>
                <tr>
                    <td><a href="disease_statistics.php?cond=<?php echo (int) $c['id']; ?>#detail" style="color:inherit; text-decoration:none; font-weight:700;"><?php echo htmlspecialchars($c['name']); ?></a>
                        <span style="margin-left:6px; font-size:.7rem; font-weight:700; padding:1px 8px; border-radius:999px; background:<?php echo $hot ? '#fee2e2' : '#fef3c7'; ?>; color:<?php echo $hot ? '#991b1b' : '#92400e'; ?>;"><?php echo $hot ? 'SPIKE' : 'RISING'; ?></span></td>
                    <td style="text-align:right; font-weight:800;"><?php echo (int) $c['cur']; ?></td>
                    <td style="text-align:right;"><?php echo htmlspecialchars((string) $c['prev_avg']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

/* =====================================================================
 * 3. CHILD IMMUNIZATION - DUE THIS WEEK
 * ================================================================== */
if (!function_exists('mh_widget_immunization_due')) {
    function mh_widget_immunization_due(mysqli $conn, array $ctx)
    {
        if (!mh_column_exists($conn, 'child_immunization_records', 'next_visit_date')) {
            ?>
            <p class="widget-empty">Follow-up dates aren't set up yet.
                Run <code>database/sms_schema.sql</code> to enable due-date tracking.</p>
            <?php
            return;
        }

        $today    = $ctx['today'];
        $week_end = $ctx['week_end'];

        $rows = mh_wq_all($conn, "
            SELECT p.id AS patient_id, p.first_name, p.last_name, c.next_visit_date
            FROM child_immunization_records c
            JOIN patients p ON c.patient_id = p.id
            WHERE c.next_visit_date BETWEEN ? AND ?
            ORDER BY c.next_visit_date ASC LIMIT 5
        ", [$today, $week_end]);
        $count = (int) mh_wq_value($conn, "SELECT COUNT(*) FROM child_immunization_records WHERE next_visit_date BETWEEN ? AND ?", [$today, $week_end]);
        ?>
        <div class="widget-stat">
            <div class="widget-stat-number" style="color:#8b5cf6;"><?php echo number_format($count); ?></div>
            <div class="widget-stat-label">Due This Week</div>
        </div>
        <ul class="widget-list">
            <?php if ($rows): ?>
                <?php foreach ($rows as $row): ?>
                    <li>
                        <a href="view_patient.php?id=<?php echo (int) $row['patient_id']; ?>">
                            <?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?>
                        </a>
                        <span class="widget-list-meta"><?php echo htmlspecialchars(date('M d', strtotime($row['next_visit_date']))); ?></span>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="widget-empty">No immunizations due this week.</li>
            <?php endif; ?>
        </ul>
        <?php
    }
}

/* =====================================================================
 * 4. MATERNAL CARE - PRE/POST-NATAL FOLLOW-UPS
 * ================================================================== */
if (!function_exists('mh_widget_maternal_followups')) {
    function mh_widget_maternal_followups(mysqli $conn, array $ctx)
    {
        if (!mh_column_exists($conn, 'maternal_records', 'next_visit_date')) {
            ?>
            <p class="widget-empty">Follow-up dates aren't set up yet.
                Run <code>database/sms_schema.sql</code> to enable due-date tracking.</p>
            <?php
            return;
        }

        $today    = $ctx['today'];
        $week_end = $ctx['week_end'];

        $rows = mh_wq_all($conn, "
            SELECT p.first_name, p.last_name, m.next_visit_date
            FROM maternal_records m
            JOIN patients p ON m.patient_id = p.id
            WHERE m.next_visit_date BETWEEN ? AND ?
            ORDER BY m.next_visit_date ASC LIMIT 5
        ", [$today, $week_end]);
        $count = (int) mh_wq_value($conn, "SELECT COUNT(*) FROM maternal_records WHERE next_visit_date BETWEEN ? AND ?", [$today, $week_end]);
        ?>
        <div class="widget-stat">
            <div class="widget-stat-number" style="color:#ec4899;"><?php echo number_format($count); ?></div>
            <div class="widget-stat-label">Follow-Ups Due This Week</div>
        </div>
        <ul class="widget-list">
            <?php if ($rows): ?>
                <?php foreach ($rows as $row): ?>
                    <li>
                        <span><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></span>
                        <span class="widget-list-meta"><?php echo htmlspecialchars(date('M d', strtotime($row['next_visit_date']))); ?></span>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="widget-empty">No maternal follow-ups due this week.</li>
            <?php endif; ?>
        </ul>
        <?php
    }
}

/* =====================================================================
 * 5. MEDICINE INVENTORY - LOW STOCK & EXPIRING
 * ================================================================== */
if (!function_exists('mh_widget_inventory_alerts')) {
    function mh_widget_inventory_alerts(mysqli $conn, array $ctx)
    {
        $today        = $ctx['today'];
        $expiry_limit = date('Y-m-d', strtotime($today . ' +30 days'));

        $counts = mh_wq_one($conn, "
            SELECT SUM(quantity <= 20) AS low_stock,
                   SUM(expiry_date IS NOT NULL AND expiry_date BETWEEN ? AND ?) AS expiring
            FROM inventory WHERE status = 1
        ", [$today, $expiry_limit]);
        $low_stock = (int) ($counts['low_stock'] ?? 0);
        $expiring  = (int) ($counts['expiring'] ?? 0);

        $rows = mh_wq_all($conn, "
            SELECT item_name, quantity, unit, expiry_date FROM inventory
            WHERE status = 1 AND (quantity <= 20 OR (expiry_date IS NOT NULL AND expiry_date BETWEEN ? AND ?))
            ORDER BY quantity ASC LIMIT 5
        ", [$today, $expiry_limit]);
        ?>
        <div class="widget-chip-row" style="margin-bottom: 12px;">
            <span class="widget-chip chip-red"><?php echo $low_stock; ?> Low Stock</span>
            <span class="widget-chip chip-amber"><?php echo $expiring; ?> Expiring in 30 Days</span>
        </div>
        <table class="data-table widget-table">
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td style="font-weight: bold; color: var(--heading-color, #0f172a);"><?php echo htmlspecialchars($row['item_name']); ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($row['quantity'] <= 20): ?>
                                    <span class="widget-chip chip-red"><?php echo (int) $row['quantity']; ?> <?php echo htmlspecialchars($row['unit']); ?> left</span>
                                <?php elseif (!empty($row['expiry_date'])): ?>
                                    <span class="widget-chip chip-amber">Exp <?php echo htmlspecialchars(date('M d', strtotime($row['expiry_date']))); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td class="widget-empty">All inventory levels are healthy!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}

/* =====================================================================
 * 6. DENTAL CLINIC - TODAY'S RECORDS
 * ================================================================== */
if (!function_exists('mh_widget_dental_today')) {
    function mh_widget_dental_today(mysqli $conn, array $ctx)
    {
        $today = $ctx['today'];

        $count = (int) mh_wq_value($conn, "SELECT COUNT(*) FROM dental_records WHERE visit_date = ?", [$today]);

        $rows = mh_wq_all($conn, "
            SELECT p.first_name, p.last_name, d.procedure_done
            FROM dental_records d
            JOIN patients p ON d.patient_id = p.id
            WHERE d.visit_date = ?
            ORDER BY d.created_at DESC LIMIT 5
        ", [$today]);
        ?>
        <div class="widget-stat">
            <div class="widget-stat-number" style="color:#06b6d4;"><?php echo number_format($count); ?></div>
            <div class="widget-stat-label">Dental Records Today</div>
        </div>
        <ul class="widget-list">
            <?php if ($rows): ?>
                <?php foreach ($rows as $row): ?>
                    <li>
                        <span><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></span>
                        <span class="widget-list-meta"><?php echo htmlspecialchars($row['procedure_done']); ?></span>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="widget-empty">No dental visits logged today.</li>
            <?php endif; ?>
        </ul>
        <?php
    }
}

/* =====================================================================
 * 7. SMS REMINDERS - PENDING QUEUE
 * ================================================================== */
if (!function_exists('mh_widget_sms_queue')) {
    function mh_widget_sms_queue(mysqli $conn, array $ctx)
    {
        if (!mh_table_exists($conn, 'scheduled_sms')) {
            ?>
            <p class="widget-empty">SMS Reminders hasn't been set up yet.
                <a href="sms_dashboard.php">Open SMS Reminders</a> to initialize it.</p>
            <?php
            return;
        }

        $today = $ctx['today'];

        $pending = (int) mh_wq_value($conn, "SELECT COUNT(*) FROM scheduled_sms WHERE status = 'pending'");

        $failed_today = 0;
        if (mh_table_exists($conn, 'sms_logs')) {
            // range instead of DATE(created_at) = ... so idx_sms_status_created can be used
            $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
            $failed_today = (int) mh_wq_value($conn, 
                "SELECT COUNT(*) FROM sms_logs WHERE status = 'failed' AND created_at >= ? AND created_at < ?",
                [$today, $tomorrow]
            );
        }

        $key_problem = null;
        if (file_exists(__DIR__ . '/sms_helper.php')) {
            require_once __DIR__ . '/sms_helper.php';
            if (function_exists('mh_sms_provider_problem')) {
                $key_problem = mh_sms_provider_problem();
            }
        }
        ?>
        <div class="widget-stat">
            <div class="widget-stat-number" style="color:#f59e0b;"><?php echo number_format($pending); ?></div>
            <div class="widget-stat-label">Pending Reminders</div>
        </div>
        <div class="widget-chip-row">
            <span class="widget-chip <?php echo $failed_today > 0 ? 'chip-red' : 'chip-slate'; ?>">
                <?php echo $failed_today; ?> Failed Today
            </span>
            <?php if ($key_problem === null): ?>
                <span class="widget-chip chip-green">API Key OK</span>
            <?php else: ?>
                <span class="widget-chip chip-red">Key Not Configured</span>
            <?php endif; ?>
        </div>
        <?php
    }
}

/* =====================================================================
 * 8. PATIENT MASTERLIST - RECENT REGISTRATIONS
 * ================================================================== */
if (!function_exists('mh_widget_patient_registrations')) {
    function mh_widget_patient_registrations(mysqli $conn, array $ctx)
    {
        $week_ago = date('Y-m-d', strtotime($ctx['today'] . ' -7 days'));

        $totals = mh_wq_one($conn, "SELECT COUNT(*) AS total, SUM(created_at >= ?) AS recent FROM patients WHERE status = 1", [$week_ago]);
        $total  = (int) ($totals['total'] ?? 0);
        $recent = (int) ($totals['recent'] ?? 0);

        $rows = mh_wq_all($conn, "SELECT first_name, last_name, created_at FROM patients WHERE status = 1 ORDER BY created_at DESC LIMIT 4");
        ?>
        <div class="widget-stat">
            <div class="widget-stat-number" style="color:#10b981;"><?php echo number_format($total); ?></div>
            <div class="widget-stat-label">Total Active Patients</div>
        </div>
        <div class="widget-chip-row" style="margin-bottom: 8px;">
            <span class="widget-chip chip-green"><?php echo $recent; ?> New This Week</span>
        </div>
        <ul class="widget-list">
            <?php if ($rows): ?>
                <?php foreach ($rows as $row): ?>
                    <li>
                        <span><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></span>
                        <span class="widget-list-meta"><?php echo htmlspecialchars(date('M d', strtotime($row['created_at']))); ?></span>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="widget-empty">No patient registrations yet.</li>
            <?php endif; ?>
        </ul>
        <?php
    }
}

/* =====================================================================
 * 9. SYSTEM ACTIVITY LOG
 * ================================================================== */
if (!function_exists('mh_widget_system_activity')) {
    function mh_widget_system_activity(mysqli $conn, array $ctx)
    {
        $rows = mh_wq_all($conn, "SELECT action, details, user_role, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 6");
        ?>
        <ul class="activity-feed">
            <?php if ($rows): ?>
                <?php foreach ($rows as $log):
                    $icon = 'file-text';
                    $action_lc = strtolower($log['action']);
                    if (strpos($action_lc, 'login') !== false) $icon = 'log-out';
                    if (strpos($action_lc, 'stock') !== false) $icon = 'package';
                    if (strpos($action_lc, 'staff') !== false) $icon = 'user';
                    if (strpos($action_lc, 'patient') !== false) $icon = 'users';
                    ?>
                    <li class="activity-item">
                        <div class="activity-icon"><?php echo mh_icon($icon); ?></div>
                        <div class="activity-details">
                            <p><?php echo htmlspecialchars($log['details']); ?></p>
                            <div class="activity-meta">
                                <span class="activity-role"><?php echo htmlspecialchars($log['user_role']); ?></span> •
                                <?php echo htmlspecialchars(date('M d, g:i A', strtotime($log['created_at']))); ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="widget-empty">No recent activity found.</p>
            <?php endif; ?>
        </ul>
        <?php
    }
}