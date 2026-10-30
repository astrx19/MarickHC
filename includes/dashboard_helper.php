<?php
/**
 * =====================================================================
 * MARICK HEALTH CENTER - DASHBOARD LAYOUT HELPER
 * =====================================================================
 * Loads/saves each staff member's customized dashboard (which widgets
 * they've chosen to show, and in what order), on top of the widget
 * catalog defined in dashboard_widgets.php.
 *
 * USAGE
 * -----
 *   require_once __DIR__ . '/../includes/dashboard_helper.php';
 *   mh_dashboard_install($conn);
 *   $layout = mh_dashboard_get_layout($conn, $user_id, $role);
 *   // $layout = [ ['id' => 'walkin_queue', 'visible' => true, 'size' => 'small'], ... ]
 *   // in display order, already filtered to widgets this $role may see.
 */

require_once __DIR__ . '/roles.php';   // mh_is_builtin_role()
require_once __DIR__ . '/dashboard_widgets.php';

/** The only grid spans a widget may take. small = 1 column, medium = 2
 *  columns, large = full width. Anything else is rejected on save and
 *  falls back to the catalog's default on load. */
if (!defined('MH_DASHBOARD_SIZES')) {
    define('MH_DASHBOARD_SIZES', ['small', 'medium', 'large']);
}

/* =====================================================================
 * 1. INSTALL - idempotent, safe to call on every page load. Mirrors
 * mh_settings_install() / mh_sms_install() elsewhere in this project.
 * ================================================================== */
if (!function_exists('mh_dashboard_install')) {
    function mh_dashboard_install(mysqli $conn)
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS `user_dashboard_preferences` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `user_id`     INT NOT NULL,
                `layout_json` TEXT NOT NULL,
                `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

/* =====================================================================
 * 2. Widget ids a given role is allowed to see, in catalog (default)
 * order.
 * ================================================================== */
if (!function_exists('mh_dashboard_allowed_ids')) {
    function mh_dashboard_allowed_ids(string $role)
    {
        // Health Head sees everything the Admin tier sees.
        if ($role === 'health_head') { $role = 'admin'; }
        $ids = [];
        $custom = !mh_is_builtin_role($role);   // admin-created role (System Settings -> Staff Roles)
        if ($custom) {
            require_once __DIR__ . '/settings_helper.php';
            $c = function_exists('mh_auth_db') ? mh_auth_db() : null;
            if ($c instanceof mysqli) { mh_all_settings($c); }   // load Module Access before evaluating it
        }
        require_once __DIR__ . '/auth.php';
        $isMe = (function_exists('mh_current_role') && mh_current_role() !== '');
        foreach (mh_dashboard_catalog() as $id => $widget) {
            // Strict sub-permission: a widget that exposes records/stock needs the matching Delegated Function
            // (an unticked function is an explicit deny, even if the role would normally show the widget).
            if ($isMe && !empty($widget['caps']) && !mh_has_any_capability($widget['caps'])) { continue; }
            if (in_array($role, $widget['roles'], true)) {
                $ids[] = $id;
            } elseif ($custom && !empty($widget['module']) && mh_module_visible($widget['module'], $role)) {
                $ids[] = $id;   // custom roles get the widgets of modules Module Access opens for them
            }
        }
        return $ids;
    }
}

/* =====================================================================
 * 3. Fallback default layout: every widget the role may see, visible,
 * at its catalog default size, in catalog order. Used when a user has
 * no saved preferences yet.
 * ================================================================== */
if (!function_exists('mh_dashboard_default_layout')) {
    function mh_dashboard_default_layout(string $role)
    {
        $catalog = mh_dashboard_catalog();
        $layout  = [];
        foreach (mh_dashboard_allowed_ids($role) as $id) {
            $layout[] = ['id' => $id, 'visible' => true, 'size' => $catalog[$id]['size']];
        }
        return $layout;
    }
}

/* =====================================================================
 * 4. LOAD - merges the user's saved JSON with the catalog: saved
 * widgets keep their saved order/visibility/size, any catalog widget
 * the user has never saved a preference for (a brand new module, or a
 * first-time user) is appended as visible at its catalog default size
 * so new modules aren't silently hidden, and any saved id that no
 * longer exists in the catalog or isn't allowed for the current role
 * is dropped.
 * ================================================================== */
if (!function_exists('mh_dashboard_get_layout')) {
    function mh_dashboard_get_layout(mysqli $conn, int $user_id, string $role)
    {
        $catalog     = mh_dashboard_catalog();
        $allowed_ids = mh_dashboard_allowed_ids($role);

        $stmt = $conn->prepare("SELECT layout_json FROM user_dashboard_preferences WHERE user_id = ? LIMIT 1");
        $saved = null;
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $saved = json_decode($row['layout_json'], true);
            }
            $stmt->close();
        }

        if (!is_array($saved)) {
            return mh_dashboard_default_layout($role);
        }

        $layout = [];
        $seen   = [];
        foreach ($saved as $entry) {
            if (!is_array($entry) || empty($entry['id']) || !in_array($entry['id'], $allowed_ids, true)) {
                continue;
            }
            $size = $entry['size'] ?? '';
            if (!in_array($size, MH_DASHBOARD_SIZES, true)) {
                $size = $catalog[$entry['id']]['size'];
            }
            $layout[] = [
                'id'      => $entry['id'],
                'visible' => !empty($entry['visible']),
                'size'    => $size,
            ];
            $seen[$entry['id']] = true;
        }

        // Append any allowed widget the saved layout doesn't mention yet.
        foreach ($allowed_ids as $id) {
            if (!isset($seen[$id])) {
                $layout[] = ['id' => $id, 'visible' => true, 'size' => $catalog[$id]['size']];
            }
        }

        return $layout;
    }
}

/* =====================================================================
 * 5. SAVE - validates every id against the catalog + role, and every
 * size against MH_DASHBOARD_SIZES, before writing, so a tampered
 * request can't store arbitrary widget ids or grid spans.
 * ================================================================== */
if (!function_exists('mh_dashboard_save_layout')) {
    function mh_dashboard_save_layout(mysqli $conn, int $user_id, string $role, array $widgets)
    {
        $catalog     = mh_dashboard_catalog();
        $allowed_ids = mh_dashboard_allowed_ids($role);
        $clean = [];
        foreach ($widgets as $entry) {
            if (!is_array($entry) || empty($entry['id']) || !in_array($entry['id'], $allowed_ids, true)) {
                continue;
            }
            $size = $entry['size'] ?? '';
            if (!in_array($size, MH_DASHBOARD_SIZES, true)) {
                $size = $catalog[$entry['id']]['size'];
            }
            $clean[] = [
                'id'      => (string) $entry['id'],
                'visible' => !empty($entry['visible']),
                'size'    => $size,
            ];
        }

        if (empty($clean)) {
            return false;
        }

        $json = json_encode($clean);

        $stmt = $conn->prepare(
            "INSERT INTO user_dashboard_preferences (user_id, layout_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $user_id, $json);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

/* =====================================================================
 * 6. RESET - deletes the saved row so the next load falls back to
 * mh_dashboard_default_layout().
 * ================================================================== */
if (!function_exists('mh_dashboard_reset_layout')) {
    function mh_dashboard_reset_layout(mysqli $conn, int $user_id)
    {
        $stmt = $conn->prepare("DELETE FROM user_dashboard_preferences WHERE user_id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $user_id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}