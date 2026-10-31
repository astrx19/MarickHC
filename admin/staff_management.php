<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);   // never print SQL/PHP errors to the browser; they go to the error log
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once(__DIR__ . "/../config/database.php");
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../includes/settings_helper.php';

// Feature toggle 'manage_staff' (System Settings -> Staff Roles & Permissions). Health Head always passes.
require_permission('manage_staff');

$viewer_id       = (int) ($_SESSION['user_id'] ?? 0);
$viewer_is_super = mh_is_health_head();

// ---------------------------------------------------------------------
// Role tiers.
//   Health Head : may create/edit/reset ANY account, including Admins.
//   Admin       : may manage clinical staff only. Admin / Health Head
//                 accounts are read-only to them (enforced HERE, on the
//                 server - the hidden buttons are only a convenience).
// ---------------------------------------------------------------------
// The role list comes from the `system_roles` table via mh_get_roles() (includes/roles.php).
// A role created under System Settings -> Staff Roles & Permissions appears in both Account Role
// dropdowns automatically - there are no hardcoded <option> tags in this file.
// The value saved to `staff`.`role` is the key, e.g. 'inventory'.
$role_labels = mh_get_roles(true);   // every role, so existing accounts always show a proper label
$active_role_labels = mh_get_roles();   // roles that may be given to NEW accounts (custom roles can be hidden)
$assignable_roles = $viewer_is_super
    ? $active_role_labels
    : array_diff_key($active_role_labels, ['health_head' => 1, 'admin' => 1]);

$capability_registry = mh_capability_registry();
$has_status_col = mh_staff_ensure_status($conn);   // staff.status: 1 active / 0 archived
mh_department_ensure_table($conn);
$custom_depts = mh_dept_all($conn);   // every active department (System Settings -> Departments)
mh_staff_caps_upgrade($conn);   // one-time: per-account flags become NULL (inherit) / 1 (grant) / 0 (deny)
$caps_tristate = mh_caps_tristate_ready();

// What each role gives by default: used to prefill the checkboxes and to decide what is an override.
$role_default_caps = [];
foreach (array_keys(mh_role_definitions(true)) as $rk0) { $role_default_caps[$rk0] = mh_role_default_caps($rk0); }

// Which granular flag columns actually exist on `staff` yet? If
// database/granular_permissions_migration.sql has not been applied, the
// page still works (role-based) and shows a warning instead of fataling.
$existing_cap_cols = [];
if ($colRes = $conn->query("SHOW COLUMNS FROM `staff`")) {
    while ($c = $colRes->fetch_assoc()) { $existing_cap_cols[$c['Field']] = true; }
}
$cap_cols_ready   = array_values(array_filter(array_keys($capability_registry), function ($c) use ($existing_cap_cols) { return isset($existing_cap_cols[$c]); }));
$cap_cols_missing = count($cap_cols_ready) < count($capability_registry);

/**
 * Reads the posted Delegated Functions into [column => null|0|1] relative to the role's defaults:
 *   ticked and the role already gives it  -> NULL (inherit)
 *   ticked but the role does not          -> 1    (extra grant)
 *   unticked but the role gives it        -> 0    (explicit DENY - this is what removes access)
 *   unticked and the role does not        -> NULL
 * Admin tier, or a form that carries no checkboxes (the Add form), stores NULL = inherit the role.
 */
function mh_posted_capabilities(array $registry, $role, array $roleDefaults, $tristate)
{
    $out = [];
    $present = !empty($_POST['caps_present']) && !mh_is_admin_tier($role);
    $posted  = (isset($_POST['caps']) && is_array($_POST['caps'])) ? $_POST['caps'] : [];
    foreach ($registry as $col => $_) {
        if (!$present) { $out[$col] = $tristate ? null : 0; continue; }
        $ticked  = in_array($col, $posted, true);
        $default = !empty($roleDefaults[$role][$col]);
        if (!$tristate) { $out[$col] = $ticked ? 1 : 0; continue; }          // legacy: grants only
        $out[$col] = ($ticked === $default) ? null : ($ticked ? 1 : 0);
    }
    return $out;
}

/** "+can_x" (extra grant) / "-can_y" (explicit deny) labels for the activity log. */
function mh_caps_override_summary(array $caps)
{
    $out = [];
    foreach ($caps as $c => $v) { if ($v === 1) { $out[] = '+' . $c; } elseif ($v === 0 && $v !== null) { $out[] = '-' . $c; } }
    return $out;
}

$success_msg = "";
$error_msg = "";

/** Random one-time password (shown once to the admin, forces a change at first login). */
if (!function_exists('mh_generate_temp_password')) {
    function mh_generate_temp_password($len = 12)
    {
        $sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%&*?'];
        $all = implode('', $sets); $pw = [];
        foreach ($sets as $set) { $pw[] = $set[random_int(0, strlen($set) - 1)]; }
        while (count($pw) < $len) { $pw[] = $all[random_int(0, strlen($all) - 1)]; }
        for ($i = count($pw) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$pw[$i], $pw[$j]] = [$pw[$j], $pw[$i]]; }   // Fisher-Yates (CSPRNG)
        return implode('', $pw);
    }
}

/** Audit + 403 for a blocked privilege change (delegation limits / self-escalation). */
function mh_staff_privilege_denied($message, $logDetail)
{
    global $conn, $error_msg;
    http_response_code(403);
    $error_msg = $message;
    logActivity($conn, (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['role'] ?? ''), 'Privilege Change Blocked', $logDetail);
}

// ---------------------------------------------------------------------
// CSRF: every POST on this page (add / edit / reset) must carry a valid token.
// ---------------------------------------------------------------------
$mh_post_ok = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !mh_csrf_check()) {
    $mh_post_ok = false;
    http_response_code(403);
    $error_msg = "Your session security token is missing or expired. Please reload the page and try again.";
}

/* =====================================================================
 * ADD STAFF
 * ================================================================== */
if ($mh_post_ok && isset($_POST['add_staff'])) {
    $fname   = trim($_POST['first_name'] ?? '');
    $lname   = trim($_POST['last_name'] ?? '');
    $mname   = trim($_POST['middle_name'] ?? '');
    $suffix  = trim($_POST['suffix'] ?? '');
    $bdate   = $_POST['birthdate'] ?? '';
    $contact = trim($_POST['contact_number'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $role    = (string) ($_POST['role'] ?? '');

    // Requested per-account overrides, computed BEFORE anything is written.
    $caps = array_intersect_key(mh_posted_capabilities($capability_registry, $role, $role_default_caps, $caps_tristate), array_flip($cap_cols_ready));
    list($role_ok, $role_why) = mh_delegation_check_role($role);          // may the actor hand out THIS role?
    list($caps_ok, $caps_why) = mh_delegation_check_caps($caps, []);       // ...and each extra function?

    if (!array_key_exists($role, $assignable_roles)) {
        $error_msg = "You are not allowed to create an account with that role.";
    } elseif (!$role_ok) {
        mh_staff_privilege_denied($role_why, "Tried to create a '$role' account beyond their own permissions.");
    } elseif (!$caps_ok) {
        mh_staff_privilege_denied($caps_why, "Tried to grant functions they do not hold while creating an account.");
    } else {
        $raw_temp_password = mh_generate_temp_password();
        $hashed_password = password_hash($raw_temp_password, PASSWORD_DEFAULT);

        // Login is username-based: first initial + last name, de-duplicated.
        $username = mh_generate_unique_username($conn, substr($fname, 0, 1) . $lname);

        $chk = $conn->prepare("SELECT id FROM staff WHERE email = ? LIMIT 1");
        $chk->bind_param('s', $email);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($exists) {
            $error_msg = "That email address is already registered to an account.";
        } else {
            $cap_sql_cols = $caps ? ', ' . implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($caps))) : '';
            $cap_sql_ph   = $caps ? ', ' . implode(', ', array_fill(0, count($caps), '?')) : '';
            $stmt = $conn->prepare(
                "INSERT INTO staff (first_name, last_name, middle_name, suffix, birthdate, contact_number, email, username, password, role, is_first_login{$cap_sql_cols})
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1{$cap_sql_ph})"
            );
            $bind = array_merge([$fname, $lname, $mname, $suffix, $bdate, $contact, $email, $username, $hashed_password, $role], array_values($caps));
            $stmt->bind_param('ssssssssss' . str_repeat('i', count($caps)), ...$bind);
            if ($stmt->execute()) {
                $success_msg = "Staff account created successfully!<br>Username: <b>" . htmlspecialchars($username) . "</b><br>One-time password (shown once, must be changed at first login): <b>" . htmlspecialchars($raw_temp_password) . "</b>";
                $granted = mh_caps_override_summary($caps);
                logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Added Staff",
                    "Created new $role account for $fname $lname (username: $username)." . ($granted ? " Function overrides: " . implode(', ', $granted) . "." : ""));
            } else {
                error_log('Add staff failed: ' . $stmt->error);
                $error_msg = "The account could not be created. Please check the details and try again.";
            }
            $stmt->close();
        }
    }
}

/* =====================================================================
 * EDIT STAFF
 *   - Editing YOURSELF: basic profile fields only. Any attempt to change your own role,
 *     status or delegated-function overrides is refused (403) and logged.
 *   - Editing SOMEONE ELSE: role / override changes must stay within the actor's own
 *     permissions and may only target accounts the actor outranks (mh_delegation_*).
 * ================================================================== */
if ($mh_post_ok && isset($_POST['edit_staff'])) {
    $staff_id = (int) ($_POST['staff_id'] ?? 0);
    $fname    = trim($_POST['first_name'] ?? '');
    $lname    = trim($_POST['last_name'] ?? '');
    $mname    = trim($_POST['middle_name'] ?? '');
    $suffix   = trim($_POST['suffix'] ?? '');
    $bdate    = $_POST['birthdate'] ?? '';
    $contact  = trim($_POST['contact_number'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $posted_role = (string) ($_POST['role'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $is_self  = ($staff_id === $viewer_id && $viewer_id > 0);

    // Target row incl. its CURRENT per-account overrides (prepared; column names come from the code registry).
    $sel_cols = $cap_cols_ready ? ', ' . implode(', ', array_map(function ($c) { return "`$c`"; }, $cap_cols_ready)) : '';
    $t = $conn->prepare("SELECT role{$sel_cols} FROM staff WHERE id = ? LIMIT 1");
    $t->bind_param('i', $staff_id);
    $t->execute();
    $target = $t->get_result()->fetch_assoc();
    $t->close();

    $current_caps = [];
    if ($target) { foreach ($cap_cols_ready as $c) { $current_caps[$c] = ($target[$c] === null) ? null : (int) $target[$c]; } }

    if (!$target) {
        $error_msg = "That staff account no longer exists.";
    } elseif (mh_is_admin_tier($target['role']) && !$viewer_is_super && !$is_self) {
        $error_msg = "Only a Health Head can modify Admin or Health Head accounts.";
    } else {
        $role = $target['role'];          // default: the role does not change
        $write_privileges = false;        // role + overrides are written ONLY after every check below passes
        $caps = [];

        if ($is_self) {
            // ---- Block self-editing of privileges ----------------------------------
            $tamper = [];
            if ($posted_role !== '' && $posted_role !== $target['role']) { $tamper[] = 'role'; }
            if (isset($_POST['status']) || isset($_POST['role_id'])) { $tamper[] = 'status/role id'; }
            if (!empty($_POST['caps_present']) && !mh_is_admin_tier($target['role'])) {
                $ticked_now = (isset($_POST['caps']) && is_array($_POST['caps'])) ? $_POST['caps'] : [];
                foreach ($capability_registry as $col => $_) {
                    $effective = array_key_exists($col, $current_caps) && $current_caps[$col] !== null
                        ? ((int) $current_caps[$col] === 1)                       // explicit override
                        : !empty($role_default_caps[$target['role']][$col]);       // inherited from the role
                    if (in_array($col, $ticked_now, true) !== $effective) { $tamper[] = 'delegated functions'; break; }
                }
            }
            if ($tamper) {
                mh_staff_privilege_denied(
                    "You cannot change your own " . implode(' or ', array_unique($tamper)) . ". Ask another administrator.",
                    "Attempted self-edit of: " . implode(', ', array_unique($tamper)) . " (account #$staff_id)."
                );
            }
        } else {
            // ---- Editing another account: work out whether privileges change --------
            $role = ($posted_role !== '') ? $posted_role : $target['role'];
            $role_changed = ($role !== $target['role']);
            if ($role_changed && !array_key_exists($role, $assignable_roles)) {
                $error_msg = "You are not allowed to assign that role.";
            } else {
                $caps_posted = !empty($_POST['caps_present']) && !mh_is_admin_tier($role);
                if ($role_changed || $caps_posted) {
                    $caps = array_intersect_key(mh_posted_capabilities($capability_registry, $role, $role_default_caps, $caps_tristate), array_flip($cap_cols_ready));
                    $caps_changed = $role_changed || ($caps !== array_intersect_key($current_caps, $caps));
                    if ($role_changed || $caps_changed) {
                        $write_privileges = true;
                        if (!mh_actor_outranks_target($target['role'])) {
                            mh_staff_privilege_denied(
                                "You cannot change the privileges of an account that holds functions you do not have.",
                                "Tried to change privileges of account #$staff_id (role {$target['role']}) above their own level."
                            );
                            $write_privileges = false;
                        }
                        if ($error_msg === "" && $role_changed) {
                            list($ok1, $why1) = mh_delegation_check_role($role);
                            if (!$ok1) { mh_staff_privilege_denied($why1, "Tried to assign role '$role' to account #$staff_id beyond their own permissions."); $write_privileges = false; }
                        }
                        if ($error_msg === "") {
                            list($ok2, $why2) = mh_delegation_check_caps($caps, $role_changed ? [] : $current_caps);
                            if (!$ok2) { mh_staff_privilege_denied($why2, "Tried to grant functions to account #$staff_id that they do not hold."); $write_privileges = false; }
                        }
                    }
                }
            }
        }

        if ($error_msg === "" && $role !== $target['role'] && $target['role'] === 'health_head') {
            $c = $conn->query("SELECT COUNT(*) AS n FROM staff WHERE role = 'health_head'")->fetch_assoc();
            if ((int) $c['n'] <= 1) { $error_msg = "There must always be at least one Health Head."; }
        }

        if ($error_msg === "") {
            $chk = $conn->prepare("SELECT id FROM staff WHERE email = ? AND id != ? LIMIT 1");
            $chk->bind_param('si', $email, $staff_id);
            $chk->execute();
            $email_taken = $chk->get_result()->num_rows > 0;
            $chk->close();

            if ($email_taken) {
                $error_msg = "That email address is already in use by another staff member.";
            } elseif ($username === '' || !preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username)) {
                $error_msg = "Username must be 4-50 characters: letters, numbers, dots, underscores or hyphens.";
            } elseif (!mh_username_available($conn, $username, 'staff', $staff_id)) {
                $error_msg = "That username is already taken by another account.";
            } else {
                // Basic profile fields always; role + overrides ONLY when $write_privileges (never for self).
                $set   = ['first_name=?', 'last_name=?', 'middle_name=?', 'suffix=?', 'birthdate=?', 'contact_number=?', 'email=?', 'username=?'];
                $types = 'ssssssss';
                $vals  = [$fname, $lname, $mname, $suffix, $bdate, $contact, $email, $username];
                if ($write_privileges) {
                    $set[] = 'role=?'; $types .= 's'; $vals[] = $role;
                    foreach ($caps as $col => $v) { $set[] = "`$col`=?"; $types .= 'i'; $vals[] = $v; }
                }
                $types .= 'i'; $vals[] = $staff_id;
                $stmt = $conn->prepare("UPDATE staff SET " . implode(', ', $set) . " WHERE id=?");
                $stmt->bind_param($types, ...$vals);
                if ($stmt->execute()) {
                    $success_msg = "Staff account updated successfully!" . ($is_self ? " (Profile details only - your own role and functions cannot be changed here.)" : "");
                    // Custom-department access: per-account exceptions to the role's setting
                    if (!$is_self && mh_actor_outranks_target($target['role'])) {
                        $final_role = $write_privileges ? $role : $target['role'];
                        if (mh_is_admin_tier($final_role)) {
                            $d = $conn->prepare("DELETE FROM staff_department_access WHERE staff_id = ?");
                            $d->bind_param('i', $staff_id); $d->execute(); $d->close();
                        } elseif (!empty($_POST['dept_access_present'])) {
                            $ticked = (isset($_POST['dept_access']) && is_array($_POST['dept_access'])) ? array_map('strval', $_POST['dept_access']) : [];
                            foreach (mh_dept_all($conn) as $cd) {
                                $nm = $cd['name'];
                                if (!mh_dept_user_can($nm)) { continue; }          // you cannot hand out a department you cannot use
                                $want = in_array($nm, $ticked, true);
                                if ($want === mh_dept_role_allowed($nm, $final_role)) {   // same as the role -> no exception needed
                                    $d = $conn->prepare("DELETE FROM staff_department_access WHERE staff_id = ? AND dept_name = ?");
                                    $d->bind_param('is', $staff_id, $nm);
                                } else {
                                    $flag = $want ? 1 : 0;
                                    $d = $conn->prepare("INSERT INTO staff_department_access (staff_id, dept_name, allowed) VALUES (?, ?, ?)
                                                         ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)");
                                    $d->bind_param('isi', $staff_id, $nm, $flag);
                                }
                                $d->execute(); $d->close();
                            }
                        }
                    }
                    $granted = $write_privileges ? mh_caps_override_summary($caps) : [];
                    logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Updated Staff",
                        "Updated account #$staff_id ($fname $lname)" . ($write_privileges
                            ? ", role: $role. Function overrides: " . ($granted ? implode(', ', $granted) : 'none (inherits role)') . "."
                            : ". Privileges unchanged."));
                } else {
                    error_log('Update staff failed: ' . $stmt->error);
                    $error_msg = "The account could not be updated. Please check the details and try again.";
                }
                $stmt->close();
            }
        }
    }
}

/* =====================================================================
 * RESET PASSWORD
 *   Resetting someone's password hands over their login, so it is treated as a privilege
 *   change: you may only reset accounts you outrank (or your own).
 * ================================================================== */
if ($mh_post_ok && isset($_POST['reset_password'])) {
    $reset_id = (int) ($_POST['reset_staff_id'] ?? 0);

    $q = $conn->prepare("SELECT role FROM staff WHERE id = ? LIMIT 1");
    $q->bind_param('i', $reset_id);
    $q->execute();
    $user_data = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$user_data) {
        $error_msg = "That staff account no longer exists.";
    } elseif (mh_is_admin_tier($user_data['role']) && !$viewer_is_super && $reset_id !== $viewer_id) {
        $error_msg = "Only a Health Head can reset an Admin or Health Head password.";
    } elseif ($reset_id !== $viewer_id && !mh_actor_outranks_target($user_data['role'])) {
        mh_staff_privilege_denied(
            "You cannot reset the password of an account that holds functions you do not have.",
            "Tried to reset the password of account #$reset_id (role {$user_data['role']}) above their own level."
        );
    } else {
        $raw_temp_password = mh_generate_temp_password();
        $default_pass = password_hash($raw_temp_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE staff SET password = ?, is_first_login = 1 WHERE id = ?");
        $stmt->bind_param('si', $default_pass, $reset_id);
        if ($stmt->execute()) {
            $success_msg = "Password reset. One-time password (shown once; they must change it at next login): <b>" . htmlspecialchars($raw_temp_password) . "</b>";
            logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Password Reset", "Reset password for Staff ID #$reset_id.");
        } else {
            error_log('Staff password reset failed: ' . $stmt->error);
            $error_msg = "The password could not be reset. Please try again.";
        }
        $stmt->close();
    }
}

/* =====================================================================
 * ARCHIVE STAFF (soft delete: cannot sign in, history is kept, restorable from System Archives)
 * ================================================================== */
if ($mh_post_ok && isset($_POST['archive_staff'])) {
    $arch_id = (int) ($_POST['archive_staff_id'] ?? 0);

    $q = $conn->prepare("SELECT role, first_name, last_name FROM staff WHERE id = ? LIMIT 1");
    $q->bind_param('i', $arch_id);
    $q->execute();
    $target = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$has_status_col) {
        $error_msg = "Archiving is not available yet: the database could not add the status column.";
    } elseif (!$target) {
        $error_msg = "That staff account no longer exists.";
    } elseif ($arch_id === $viewer_id) {
        $error_msg = "You cannot archive your own account.";
    } elseif (mh_is_admin_tier($target['role']) && !$viewer_is_super) {
        $error_msg = "Only a Health Head can archive an Admin or Health Head account.";
    } elseif (!mh_actor_outranks_target($target['role'])) {
        mh_staff_privilege_denied(
            "You cannot archive an account that holds functions you do not have.",
            "Tried to archive account #$arch_id (role {$target['role']}) above their own level."
        );
    } else {
        $last_head = false;
        if ($target['role'] === 'health_head') {
            $c = $conn->query("SELECT COUNT(*) AS n FROM staff WHERE role = 'health_head' AND status = 1")->fetch_assoc();
            $last_head = ((int) $c['n'] <= 1);
        }
        if ($last_head) {
            $error_msg = "This is the only active Health Head. Make another account a Health Head before archiving it.";
        } else {
            $stmt = $conn->prepare("UPDATE staff SET status = 0, archived_at = NOW(), session_token = NULL WHERE id = ?");
            $stmt->bind_param('i', $arch_id);
            if ($stmt->execute()) {
                $success_msg = htmlspecialchars($target['first_name'] . ' ' . $target['last_name']) . " was archived and can no longer sign in. You can restore the account under System Archives.";
                logActivity($conn, $_SESSION['user_id'], $_SESSION['role'], "Archived Staff", "Archived Staff ID #$arch_id ({$target['first_name']} {$target['last_name']}).");
            } else {
                error_log('Staff archive failed: ' . $stmt->error);
                $error_msg = "The account could not be archived. Please try again.";
            }
            $stmt->close();
        }
    }
}

/**
 * Delegated Functions picker: one compact row per module, each function a
 * small toggle chip. The long hint text lives in the chip's tooltip (title)
 * so the list stays short. JS hooks kept: input[data-cap], .cap-box, .is-locked.
 */
function mh_render_capability_checkboxes(array $registry, $idPrefix)
{
    $groups = [];
    foreach ($registry as $col => $def) { $groups[$def['group'] ?? 'Other'][$col] = $def; }

    echo '<div class="cap-groups">';
    foreach ($groups as $groupName => $items) {
        echo '<div class="cap-group"><div class="cap-group-title">' . htmlspecialchars($groupName) . '</div><div class="cap-grid">';
        foreach ($items as $col => $def) {
            $id = $idPrefix . $col;
            echo '<label class="cap-item" for="' . htmlspecialchars($id) . '" title="' . htmlspecialchars($def['hint']) . '">'
               . '<input type="checkbox" name="caps[]" value="' . htmlspecialchars($col) . '" id="' . htmlspecialchars($id) . '" data-cap>'
               . '<span class="cap-tick" aria-hidden="true"></span>'
               . '<span class="cap-label">' . htmlspecialchars($def['label']) . '</span>'
               . '</label>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../includes/theme_init.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - Marick Health Center</title>
    <link rel="stylesheet" href="../assets/css/admin_theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin_theme.css'); ?>">
    <style>
        .staff-grid { display: block; width: 100%; }   /* directory now spans the full width; the add form lives in #addStaffModal */
        
        .role-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; border: 1px solid; display: inline-block; background: #f1f5f9; color: #475569; border-color: #cbd5e1; }   /* default look for custom roles */
        .role-health_head { background: #ede9fe; color: #6d28d9; border-color: #ddd6fe; }
        .role-admin { background: #f1f5f9; color: var(--text-main, #334155); border-color: var(--border-color, #cbd5e1); }
        .role-doctor { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .role-midwife { background: #fce7f3; color: #be185d; border-color: #fbcfe8; }
        .role-dental { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .role-bhw { background: #d1fae5; color: #047857; border-color: #a7f3d0; }
        .role-inventory { background: #ffedd5; color: #c2410c; border-color: #fed7aa; }

        /* ---------- Delegated Functions picker (compact) ---------- */
        .cap-box { grid-column: 1 / -1; border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 14px; background: var(--bg-hover, #f8fafc); }
        .cap-box h4 { margin: 0 0 2px; font-size: 0.95rem; font-weight: 800; color: var(--heading-color, #1e293b); }
        .cap-box p.cap-note { margin: 0 0 10px; font-size: 0.76rem; line-height: 1.4; color: var(--text-muted, #64748b); }

        .cap-groups { display: grid; gap: 10px; }
        .cap-group-title { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted, #64748b); margin: 0 0 5px; }
        .cap-grid { display: flex; flex-wrap: wrap; gap: 6px; }

        .cap-item { position: relative; display: inline-flex; align-items: center; gap: 6px; margin: 0; padding: 5px 11px 5px 8px;
                    cursor: pointer; text-transform: none; font-weight: 600; font-size: 0.78rem; line-height: 1.2;
                    color: var(--text-main, #334155); background: var(--bg-card, #fff);
                    border: 1px solid var(--border-color, #e2e8f0); border-radius: 999px;
                    transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
        .cap-item:hover { border-color: var(--primary, #4ca1af); }
        .cap-item input[data-cap] { position: absolute; opacity: 0; width: 1px !important; height: 1px !important; padding: 0 !important; margin: 0 !important; border: 0 !important; pointer-events: none; }
        .cap-tick { width: 14px; height: 14px; flex-shrink: 0; border-radius: 50%; border: 1.5px solid var(--text-muted, #94a3b8); position: relative; transition: all .15s ease; }
        .cap-tick::after { content: ""; position: absolute; left: 3.5px; top: 1px; width: 4px; height: 7px; border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg) scale(0); transition: transform .15s ease; }

        /* selected */
        .cap-item:has(input:checked) { background: var(--primary, #4ca1af); border-color: var(--primary, #4ca1af); color: #fff; }
        .cap-item input:checked ~ .cap-tick { background: rgba(255,255,255,.25); border-color: #fff; }
        .cap-item input:checked ~ .cap-tick::after { transform: rotate(45deg) scale(1); }
        .cap-item input:focus-visible ~ .cap-tick { outline: 2px solid var(--primary, #4ca1af); outline-offset: 2px; }

        /* locked (Admin / Health Head hold every function) */
        .cap-box.is-locked .cap-item { opacity: 0.6; cursor: not-allowed; }

        .cap-chip { display: inline-block; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; border-radius: 12px; padding: 1px 8px; font-size: 0.68rem; font-weight: 600; margin: 1px 2px 1px 0; white-space: nowrap; }
        .cap-chip.all { background: #ede9fe; color: #6d28d9; border-color: #ddd6fe; }
        .protected-note { color: var(--text-muted, #64748b); font-size: 0.75rem; font-style: italic; }
    </style>
    <?php include __DIR__ . '/../includes/modal_assets.php'; ?>
</head>
<body>

    <?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

    <div class="main-content">
        <?php include(__DIR__ . '/../includes/admin_topbar.php'); ?>

        <div class="dashboard-container">
            
            <?php 
            if($success_msg) echo "<div class='alert alert-success' style='background:#d1fae5; color:#047857; padding:15px; border-radius:8px; border:1px solid #a7f3d0; margin-bottom:20px;'>" . mh_icon('check-circle') . " $success_msg</div>";
            if($error_msg) echo "<div class='alert alert-error' style='background:#fee2e2; color:#b91c1c; padding:15px; border-radius:8px; border:1px solid #fecaca; margin-bottom:20px;'>" . mh_icon('x-circle') . " $error_msg</div>";
            if($cap_cols_missing) echo "<div class='alert alert-error'>" . mh_icon('alert-triangle') . " Granular permission columns are missing from the <code>staff</code> table, so per-function permissions cannot be saved yet. Run <code>database/granular_permissions_migration.sql</code> once.</div>";
            ?>

            <div class="staff-grid">
                
                <div class="unified-panel" style="margin-bottom: 0;">
                    <div class="panel-controls">
                        <h3 style="margin:0; font-size: 1.1rem; color: var(--heading-color, #1e293b);"> Current Staff Directory</h3>
                        <button type="button" class="btn-add" onclick="openAddStaffModal()">+ Add New Staff Member</button>
                    </div>
                    
                    <div class="table-scroll">
                        <table class="unified-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Delegated Functions</th>
                                    <th>Contact</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $staff_list = $conn->query("SELECT * FROM staff" . ($has_status_col ? " WHERE status = 1" : "") . " ORDER BY role ASC, last_name ASC");
                                if($staff_list && $staff_list->num_rows > 0):
                                    while($row = $staff_list->fetch_assoc()):
                                        $role_class = "role-" . strtolower($row['role']);
                                        $row_is_admin_tier = mh_is_admin_tier($row['role']);
                                        $row_locked = $row_is_admin_tier && !$viewer_is_super;
                                        $row_caps = [];
                                        // EFFECTIVE access: the per-account flag when set (1 grant / 0 deny), else the role's default
                                        $row_eff = [];
                                        foreach ($capability_registry as $cc => $cd) {
                                            $flag = $row[$cc] ?? null;
                                            $on = ($flag !== null && ($caps_tristate || (int) $flag === 1))
                                                ? ((int) $flag === 1)
                                                : !empty($role_default_caps[$row['role']][$cc]);
                                            if ($on) { $row_caps[] = $cd['label']; $row_eff[] = $cc; }
                                        }
                                        $row_payload = [
                                            'id' => (int) $row['id'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
                                            'middle_name' => $row['middle_name'] ?? '', 'suffix' => $row['suffix'] ?? '',
                                            'birthdate' => $row['birthdate'] ?? '', 'contact' => $row['contact_number'] ?? '',
                                            'email' => $row['email'], 'role' => $row['role'], 'username' => $row['username'] ?? '',
                                            'self' => ((int) $row['id'] === $viewer_id),
                                            'caps' => $row_eff,
                                            'depts' => array_values(array_map(function ($cd) { return $cd['name']; },
                                                array_filter($custom_depts, function ($cd) use ($row) { return mh_dept_user_can($cd['name'], $row['role'], (int) $row['id']); }))),
                                        ];
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--heading-color, #1e293b);"><?php echo htmlspecialchars($row['last_name'] . ", " . $row['first_name']); ?></strong><br>
                                        <span style="color: var(--text-muted, #64748b); font-size: 0.8rem;"><?php echo htmlspecialchars($row['email']); ?></span><br>
                                        <span style="color: #4ca1af; font-size: 0.78rem; font-weight: 600;">@<?php echo htmlspecialchars($row['username'] ?? '—'); ?></span>
                                    </td>
                                    <td><span class="role-badge <?php echo $role_class; ?>"><?php echo htmlspecialchars($role_labels[$row['role']] ?? $row['role']); ?></span></td>
                                    <td style="max-width: 240px;">
                                        <?php if ($row_is_admin_tier): ?>
                                            <span class="cap-chip all">All (by role)</span>
                                        <?php elseif ($row_caps): foreach ($row_caps as $lbl): ?>
                                            <span class="cap-chip"><?php echo htmlspecialchars($lbl); ?></span>
                                        <?php endforeach; else: ?>
                                            <span style="color:var(--text-muted, #64748b); font-size:0.78rem;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if($row['is_first_login'] == 1): ?>
                                            <span class="status-pill status-pending">Pending Setup</span>
                                        <?php else: ?>
                                            <span class="status-pill status-active">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <?php if ($row_locked): ?>
                                            <span class="protected-note">Health Head only</span>
                                        <?php else: ?>
                                            <button type="button" class="btn-view-record" data-staff="<?php echo htmlspecialchars(json_encode($row_payload), ENT_QUOTES); ?>" onclick="openEditModal(this)">Edit</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                    endwhile; 
                                else: 
                                ?>
                                    <tr class="empty-state"><td colspan="6">No staff members found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="modal-overlay" id="addStaffModal">
        <div class="modal-content" style="max-width: 560px; max-height: 92vh; overflow-y: auto;">
            <span class="close-btn" onclick="closeModal('addStaffModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:var(--heading-color, #1e293b); font-family: Georgia, serif;">Add Staff Member</h2>
            </div>
            <form method="POST">
<?php echo mh_csrf_field(); ?>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Last Name <span style="color:red;">*</span></label>
                                    <input type="text" name="last_name" required pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')">
                                </div>
                                <div class="form-group">
                                    <label>First Name <span style="color:red;">*</span></label>
                                    <input type="text" name="first_name" required pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')">
                                </div>
                                
                                <div class="form-group">
                                    <label>Middle Name</label>
                                    <input type="text" name="middle_name" pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')">
                                </div>
                                <div class="form-group">
                                    <label>Suffix <span style="color:var(--text-muted, #64748b); font-weight:normal;">(e.g., Jr)</span></label>
                                    <input type="text" name="suffix" maxlength="5" oninput="this.value = this.value.replace(/[^a-zA-Z.]/g, '')">
                                </div>

                                <div class="form-group">
                                    <label>Birthdate <span style="color:red;">*</span></label>
                                    <input type="date" name="birthdate" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Contact No. <span style="color:red;">*</span></label>
                                    <input type="tel" name="contact_number" required value="+63" maxlength="13" pattern="^\+63[0-9]{10}$" placeholder="+639123456789" oninput="this.value = this.value.replace(/[^0-9+]/g, ''); if(this.value.length < 3) this.value = '+63';">
                                </div>

                                <div class="form-group full-width">
                                    <label>Email Address <span style="color:red;">*</span></label>
                                    <input type="email" name="email" required placeholder="staff@marick.com">
                                </div>
                                <p style="grid-column: 1 / -1; margin: -6px 0 0; font-size: 0.82rem; color: var(--text-muted, #64748b);">
                                    A login username and temporary password are generated automatically once you click Create Account below.
                                </p>
                                
                                <div class="form-group full-width">
                                    <label>Account Role <span style="color:red;">*</span></label>
                                    <select name="role" id="add_role" required>
                                        <option value="" disabled selected><?php echo $assignable_roles ? 'Select assigned role...' : 'No roles available - add one in System Settings'; ?></option>
                                        <?php foreach ($assignable_roles as $rk => $rl): ?>
                                            <option value="<?php echo htmlspecialchars($rk); ?>"><?php echo htmlspecialchars($rl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <p style="grid-column: 1 / -1; margin: -2px 0 0; font-size: 0.8rem; color: var(--text-muted, #64748b);">
                                    Picking a role fills in what that role can do. Untick anything this person should <strong>not</strong> have.
                                </p>

                                <div class="cap-box full-width" id="add_cap_box" hidden style="margin-bottom: 8px;">
                                    <h4>Functions for this role</h4>
                                    <p class="cap-note" id="add_cap_note">Pick a role to see its functions.</p>
                                    <input type="hidden" name="caps_present" value="1">
                                    <?php mh_render_capability_checkboxes($capability_registry, 'add_'); ?>
                                </div>
                            </div>
                            
                            <button type="submit" name="add_staff" data-confirm="Create this staff account?" data-confirm-title="Create account?" data-confirm-type="info" data-confirm-ok="Create" class="btn-submit" style="width: 100%; margin-top: 15px;">Create Account</button>
                        </form>
        </div>
    </div>

    <div class="modal-overlay" id="editStaffModal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close-btn" onclick="closeModal('editStaffModal')">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="margin:0; color:var(--heading-color, #1e293b); font-family: Georgia, serif;">Edit Staff Profile</h2>
            </div>
            
            <form method="POST">
<?php echo mh_csrf_field(); ?>
                <input type="hidden" name="staff_id" id="edit_staff_id">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Last Name <span style="color:red;">*</span></label>
                        <input type="text" name="last_name" id="edit_lname" required pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    <div class="form-group">
                        <label>First Name <span style="color:red;">*</span></label>
                        <input type="text" name="first_name" id="edit_fname" required pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" id="edit_mname" pattern="[A-Za-zñÑ\s\-]+" title="Only letters are allowed" oninput="this.value = this.value.replace(/[^A-Za-zñÑ\s\-]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    <div class="form-group">
                        <label>Suffix <span style="color:var(--text-muted, #64748b); font-weight:normal;"></span></label>
                        <input type="text" name="suffix" id="edit_suffix" maxlength="5" oninput="this.value = this.value.replace(/[^a-zA-Z.]/g, '')" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>

                    <div class="form-group">
                        <label>Birthdate <span style="color:red;">*</span></label>
                        <input type="date" name="birthdate" id="edit_bdate" max="<?php echo date('Y-m-d'); ?>" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    
                    <div class="form-group">
                        <label>Contact No. <span style="color:red;">*</span></label>
                        <input type="tel" name="contact_number" id="edit_contact" required value="+63" maxlength="13" pattern="^\+63[0-9]{10}$" oninput="this.value = this.value.replace(/[^0-9+]/g, ''); if(this.value.length < 3) this.value = '+63';" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>

                    <div class="form-group full-width">
                        <label>Email Address <span style="color:red;">*</span></label>
                        <input type="email" name="email" id="edit_email" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>

                    <div class="form-group full-width">
                        <label>Username (Login ID) <span style="color:red;">*</span></label>
                        <input type="text" name="username" id="edit_username" required minlength="4" maxlength="50" pattern="[A-Za-z0-9._-]{4,50}" title="4-50 characters: letters, numbers, dots, underscores or hyphens" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                    </div>
                    
                    <div class="form-group full-width" style="margin-bottom: 20px;">
                        <label>Account Role <span style="color:red;">*</span></label>
                        <select name="role" id="edit_role" required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color, #cbd5e1);">
                            <?php foreach ($role_labels as $rk => $rl): ?>
                                <option value="<?php echo htmlspecialchars($rk); ?>" <?php echo array_key_exists($rk, $assignable_roles) ? '' : 'disabled hidden'; ?>><?php echo htmlspecialchars($rl); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small id="edit_role_note" style="display:none; color:var(--text-muted, #64748b);">You cannot change your own role.</small>
                    </div>

                    <div class="cap-box full-width" id="edit_cap_box" style="margin-bottom: 20px;">
                        <h4>Delegated Functions</h4>
                        <p class="cap-note" id="edit_cap_note">Ticked = allowed, unticked = blocked for this person (even if their role normally allows it). Changing the role resets to that role's defaults.</p>
                        <input type="hidden" name="caps_present" value="1">
                        <?php mh_render_capability_checkboxes($capability_registry, 'edit_'); ?>
                    </div>

                    <?php if ($custom_depts): ?>
                    <div class="cap-box full-width" id="edit_dept_box" data-self="0" style="margin-bottom: 20px;">
                        <h4>Departments</h4>
                        <p class="cap-note">Ticked = this person can use the department (sidebar link and patient queue). Unticking blocks it for this person even if their role allows it. Changing the role resets to that role's setting under System Settings &rarr; Departments.</p>
                        <input type="hidden" name="dept_access_present" value="1">
                        <div class="cap-grid">
                            <?php foreach ($custom_depts as $i => $cd): ?>
                                <label class="cap-item" for="edit_dept_<?php echo (int) $i; ?>">
                                    <input type="checkbox" name="dept_access[]" value="<?php echo htmlspecialchars($cd['name'], ENT_QUOTES); ?>" id="edit_dept_<?php echo (int) $i; ?>" data-cap="dept" data-dept>
                                    <span class="cap-tick" aria-hidden="true"></span>
                                    <span class="cap-label"><?php echo htmlspecialchars($cd['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <button type="submit" name="edit_staff" data-confirm="Save changes to this account? Role and permission changes apply immediately." data-confirm-title="Save account changes?" data-confirm-type="warning" data-confirm-ok="Save" class="btn-submit" style="width: 100%; padding: 15px; background: #3b82f6; border-color: #2563eb; color:white; border-radius:6px; cursor:pointer; font-weight:bold;">Save Changes</button>
            </form>

            <hr style="border: 0; border-top: 1px dashed var(--border-color, #cbd5e1); margin: 25px 0;">

            <form method="POST" id="resetPasswordForm">
            <?php echo mh_csrf_field(); ?>
                <input type="hidden" name="reset_staff_id" id="reset_staff_id_input">
                <button type="submit" name="reset_password" id="resetPasswordBtn" class="btn-submit" style="width: 100%; padding: 12px; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; font-size: 0.95rem; border-radius:6px; cursor:pointer; font-weight:bold;"><?php echo mh_icon('alert-triangle'); ?> Reset Password</button>
            </form>

            <form method="POST" id="archiveStaffForm" style="margin-top: 12px;">
            <?php echo mh_csrf_field(); ?>
                <input type="hidden" name="archive_staff_id" id="archive_staff_id_input">
                <button type="submit" name="archive_staff" id="archiveStaffBtn" class="btn-submit" style="width: 100%; padding: 12px; background: transparent; color: var(--text-muted, #64748b); border: 1px solid var(--border-color, #cbd5e1); font-size: 0.95rem; border-radius:6px; cursor:pointer; font-weight:600;"><?php echo mh_icon('archive'); ?> Archive Account</button>
                <small style="display:block; margin-top:6px; color: var(--text-muted, #64748b);">Blocks sign-in and hides the account from this list. Their records stay intact and you can restore the account from System Archives.</small>
            </form>
            
        </div>
    </div>

    <script>
        document.getElementById('archiveStaffBtn').addEventListener('click', async function (e) {
            e.preventDefault();
            const btn = e.currentTarget;
            const ok = await mh_confirm("Archive this account? They will be signed out and unable to log in until you restore them.", 'Archive Account', 'danger');
            if (ok) { document.getElementById('archiveStaffForm').requestSubmit(btn); }
        });

        document.getElementById('resetPasswordBtn').addEventListener('click', async function (e) {
            e.preventDefault();
            const btn = e.currentTarget;
            const ok = await mh_confirm("Are you sure you want to reset this user's password?", 'Reset Password', 'danger');
            // requestSubmit(btn) preserves name="reset_password" in the POST data,
            // unlike form.submit() which would drop it.
            if (ok) { document.getElementById('resetPasswordForm').requestSubmit(btn); }
        });

        // role_key => {capability: bool} - what each role gives with no per-account override
        const ROLE_DEFAULT_CAPS = <?php echo json_encode($role_default_caps, JSON_FORCE_OBJECT); ?>;

        // role_key => [custom departments that role may use]
        const ROLE_DEPT_DEFAULTS = <?php
            $rdd = [];
            foreach ($role_labels as $rk => $_rl) {
                $rdd[$rk] = array_values(array_map(function ($cd) { return $cd['name']; },
                    array_filter($custom_depts, function ($cd) use ($rk) { return mh_dept_role_allowed($cd['name'], $rk); })));
            }
            echo json_encode($rdd, JSON_FORCE_OBJECT);
        ?>;
        function deptBoxes() { return document.querySelectorAll('#edit_dept_box input[data-dept]'); }
        function syncDeptLock() {
            const box = document.getElementById('edit_dept_box'); if (!box) return;
            const role = document.getElementById('edit_role').value;
            const tier = (role === 'admin' || role === 'health_head');
            const self = box.dataset.self === '1';
            deptBoxes().forEach(cb => { if (tier) cb.checked = true; cb.disabled = tier || self; });
            const f = document.querySelector('#editStaffModal input[name="dept_access_present"]'); if (f) f.disabled = tier || self;
            box.classList.toggle('is-locked', tier || self);
        }
        function applyRoleDefaultDepts(role) {
            const d = ROLE_DEPT_DEFAULTS[role] || [];
            deptBoxes().forEach(cb => { cb.checked = d.includes(cb.value); });
            syncDeptLock();
        }

        function applyRoleDefaultCaps(role, boxId) {
            const d = ROLE_DEFAULT_CAPS[role] || {};
            document.querySelectorAll('#' + (boxId || 'edit_cap_box') + ' input[data-cap]').forEach(cb => { cb.checked = !!d[cb.value]; });
        }

        function openEditModal(btn) {
            const d = JSON.parse(btn.getAttribute('data-staff'));
            document.getElementById('edit_staff_id').value = d.id;
            document.getElementById('reset_staff_id_input').value = d.id;
            document.getElementById('archive_staff_id_input').value = d.id;
            document.getElementById('archiveStaffForm').style.display = d.self ? 'none' : '';   // never your own account

            document.getElementById('edit_fname').value = d.first_name;
            document.getElementById('edit_lname').value = d.last_name;
            document.getElementById('edit_mname').value = d.middle_name;
            document.getElementById('edit_suffix').value = d.suffix;
            document.getElementById('edit_bdate').value = d.birthdate;
            document.getElementById('edit_email').value = d.email;
            document.getElementById('edit_username').value = d.username;

            const roleSel = document.getElementById('edit_role');
            roleSel.value = d.role;
            roleSel.disabled = d.self;                       // can't change own tier
            document.getElementById('edit_role_note').style.display = d.self ? 'block' : 'none';
            if (d.self) {                                    // disabled selects aren't submitted
                let h = document.getElementById('edit_role_hidden');
                if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'role'; h.id = 'edit_role_hidden'; roleSel.form.appendChild(h); }
                h.value = d.role;
            } else {
                const h = document.getElementById('edit_role_hidden'); if (h) h.remove();
            }

            document.querySelectorAll('#edit_cap_box input[data-cap]').forEach(cb => { cb.checked = d.caps.includes(cb.value); });
            syncCapBox('edit_role', 'edit_cap_box', 'edit_cap_note');
            const deptBox = document.getElementById('edit_dept_box');
            if (deptBox) {
                deptBox.dataset.self = d.self ? '1' : '0';
                deptBoxes().forEach(cb => { cb.checked = (d.depts || []).includes(cb.value); });
                syncDeptLock();
            }
            // Editing yourself: profile fields only. Lock the function picker and do not submit it at all
            // (the server also refuses any attempt to change your own role / functions).
            const capsFlag = document.querySelector('#editStaffModal input[name="caps_present"]');
            if (capsFlag) capsFlag.disabled = d.self;
            document.querySelectorAll('#edit_cap_box input[data-cap]').forEach(cb => { if (d.self) cb.disabled = true; });
            document.getElementById('edit_cap_box').classList.toggle('is-locked', !!d.self || document.getElementById('edit_cap_box').classList.contains('is-locked'));

            let safeContact = d.contact;
            if (safeContact && safeContact.startsWith('0')) {
                safeContact = '+63' + safeContact.substring(1);
            } else if (!safeContact || !safeContact.startsWith('+63')) {
                safeContact = '+63' + (safeContact || '');
            }
            document.getElementById('edit_contact').value = safeContact;

            document.getElementById('editStaffModal').classList.add('active');
        }

        // Admin / Health Head implicitly hold every function, so the
        // checkboxes are locked (and shown ticked) for those roles.
        function syncCapBox(roleSelectId, boxId, noteId) {
            const role = document.getElementById(roleSelectId).value;
            const box = document.getElementById(boxId);
            const tier = (role === 'admin' || role === 'health_head');
            const wasLocked = box.classList.contains('is-locked');
            box.classList.toggle('is-locked', tier);
            box.querySelectorAll('input[data-cap]').forEach(cb => {
                cb.disabled = tier;
                if (tier) cb.checked = true;
                else if (wasLocked) cb.checked = false;   // leaving the admin tier: start clean
            });
            if (noteId) {
                const n = document.getElementById(noteId);
                if (n && tier) n.textContent = 'Admin and Health Head accounts include every function automatically.';
                else if (n) n.textContent = 'Ticked = allowed, unticked = blocked for this person (even if their role normally allows it). Changing the role resets to that role\'s defaults.';
            }
        }
        document.getElementById('edit_role').addEventListener('change', () => {
            syncCapBox('edit_role', 'edit_cap_box', 'edit_cap_note');
            applyRoleDefaultCaps(document.getElementById('edit_role').value);   // new role -> that role's defaults
            applyRoleDefaultDepts(document.getElementById('edit_role').value);
        });

        // Add Staff: choosing a role reveals that role's functions, pre-ticked, so the creator sees exactly what it grants.
        (function () {
            const sel = document.getElementById('add_role');
            const box = document.getElementById('add_cap_box');
            if (!sel || !box) { return; }
            sel.addEventListener('change', () => {
                box.hidden = !sel.value;
                if (!sel.value) { return; }
                applyRoleDefaultCaps(sel.value, 'add_cap_box');
                syncCapBox('add_role', 'add_cap_box', 'add_cap_note');   // admin tier: everything ticked + locked
                box.classList.remove('is-locked', 'is-locked-off');
                if (sel.value === 'admin' || sel.value === 'health_head') { box.classList.add('is-locked'); }
            });
        })();

        function openAddStaffModal() {
            const rs = document.getElementById('add_role'), cb = document.getElementById('add_cap_box');
            if (rs && cb) { rs.selectedIndex = 0; cb.hidden = true; }   // start clean every time
            document.getElementById('addStaffModal').classList.add('active');
            var f = document.querySelector('#addStaffModal input[name="last_name"]');
            if (f) { f.focus(); }
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
    </script>
</body>
</html>