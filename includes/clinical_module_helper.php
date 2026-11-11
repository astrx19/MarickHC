<?php

if (!function_exists('mh_cm_text')) {

    function mh_cm_text($key)
    {
        $v = trim((string) ($_POST[$key] ?? ''));
        return $v === '' ? null : $v;
    }
}

if (!function_exists('mh_cm_date')) {

    function mh_cm_date($key)
    {
        $v = trim((string) ($_POST[$key] ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}

if (!function_exists('mh_cm_nullable')) {

    function mh_cm_nullable($v)
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}

if (!function_exists('mh_cm_int_or_null')) {

    function mh_cm_int_or_null($v)
    {
        return ($v === null || trim((string) $v) === '') ? null : (int) $v;
    }
}

if (!function_exists('mh_cm_take_flash')) {

    function mh_cm_take_flash()
    {
        $out = ['success' => '', 'error' => ''];
        foreach (['success', 'error'] as $k) {
            if (isset($_SESSION[$k . '_msg'])) {
                $out[$k] = (string) $_SESSION[$k . '_msg'];
                unset($_SESSION[$k . '_msg']);
            }
        }
        return $out;
    }
}

if (!function_exists('mh_cm_fail')) {

    function mh_cm_fail($page, $form, $message, $conn = null)
    {
        $old = [];
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'add_') === 0 || $k === 'csrf_token') { continue; }
            if (is_array($v)) {
                $old[$k . '[]'] = array_map(function ($x) { return is_scalar($x) ? (string) $x : ''; }, array_values($v));
            } elseif (is_scalar($v)) {
                $old[$k] = (string) $v;
            }
        }
        $_SESSION['cm_old'][$form] = ['values' => $old, 'error' => (string) $message];
        $apt = (int) ($_POST['apt_id'] ?? 0);
        header('Location: ' . $page . ($apt > 0 ? '?apt_id=' . $apt : ''));
        exit();
    }
}

if (!function_exists('mh_cm_take_old')) {

    function mh_cm_take_old($form)
    {
        $o = $_SESSION['cm_old'][$form] ?? null;
        unset($_SESSION['cm_old'][$form]);
        return is_array($o) ? $o : ['values' => [], 'error' => ''];
    }
}

if (!function_exists('mh_cm_queue_prefill')) {

    function mh_cm_queue_prefill(mysqli $conn, $department, callable $build, $enabled = true)
    {
        $out = ['ctx' => null, 'open' => false, 'prefill' => [], 'name' => [], 'label' => '', 'profile' => null];
        if (!$enabled || (!isset($_GET['walkin_id']) && !isset($_GET['apt_id']))) { return $out; }

        $patientId = (int) ($_GET['walkin_id'] ?? 0);
        $ctx = mh_queue_open_context($conn, $_GET['apt_id'] ?? 0, $department, $patientId);
        if ($ctx) { $patientId = (int) $ctx['patient_id']; }
        $profile = $patientId > 0 ? mh_queue_patient_profile($conn, $patientId) : null;
        if (!$profile) { return $out; }

        $pf = $build($conn, $patientId, $profile, $ctx);
        $out['ctx']     = $ctx;
        $out['open']    = true;
        $out['profile'] = $profile;
        $out['name']    = mh_patient_name_parts_by_id($conn, $patientId);
        $out['label']   = $profile['last_name'] . ', ' . $profile['first_name'];
        $out['prefill'] = mh_queue_clean_prefill((array) $pf);
        return $out;
    }
}

if (!function_exists('mh_cm_vitals_prefill')) {

    function mh_cm_vitals_prefill(mysqli $conn, $patientId, array $map, $numeric = false)
    {
        $vit = mh_queue_today_vitals($conn, $patientId);
        $out = [];
        if (!$vit) { return $out; }
        foreach ($map as $column => $field) {
            $v = $vit[$column] ?? '';
            $out[$field] = $numeric ? mh_queue_number($v) : (string) $v;
        }
        return $out;
    }
}

if (!function_exists('mh_cm_reason_prefill')) {

    function mh_cm_reason_prefill($ctx)
    {
        if (!$ctx) { return ''; }
        $reason = trim((string) ($ctx['reason'] ?? ''));
        return ($reason !== '' && $reason !== 'Walk-In Queue Registration') ? $reason : '';
    }
}

if (!function_exists('mh_cm_after_save')) {

    function mh_cm_after_save(mysqli $conn, array $o)
    {
        $msg    = '';
        $ctx    = $o['ctx'] ?? null;
        $pid    = $o['patient_id'] ?? null;
        $record = (int) ($o['record_id'] ?? 0);
        $userId = $_SESSION['user_id'] ?? 0;
        $role   = $_SESSION['role'] ?? 'staff';
        try {
            if (!empty($o['patient_created'])) {
                $msg .= ' The child was added to the Patient Masterlist.';
            }
            if ($ctx && $pid !== null && (int) $pid === (int) $ctx['patient_id'] && mh_queue_complete($conn, $ctx['id'], $pid)) {
                $label = !empty($ctx['queue_number']) ? ' ' . mh_queue_label($ctx['department'] ?? '', $ctx['queue_number']) : '';
                $msg .= ' Queue' . $label . ' marked as Completed.';
                logActivity($conn, $userId, $role, 'Patient Queueing Update', 'Completed queue item #' . (int) $ctx['id'] . ' after saving the ' . $o['noun'] . '.');
            }
            logActivity($conn, $userId, $role, $o['log_action'], $o['log_detail'] . ' ' . $o['patient_name'] . ($pid ? " (Patient ID #$pid)." : ' (not linked to a registered patient).'));

            $next = trim((string) ($o['next_visit'] ?? ''));
            if ($next !== '' && $pid === null) {
                $msg .= ' (No SMS reminder queued: the typed name did not match exactly one registered patient.)';
            } elseif ($next !== '') {
                $sms = mh_schedule_followup_sms($conn, $pid, $next, $o['sms_label'], $record);
                if ($sms['queued'] > 0) {
                    $msg .= ' ' . $sms['queued'] . " SMS reminder(s) queued for the patient's follow-up.";
                } elseif ($sms['skipped_reason']) {
                    $msg .= ' (No SMS reminder queued: ' . $sms['skipped_reason'] . ')';
                }
            }
        } catch (Throwable $e) {
            error_log('[MarickHealth] ' . $o['noun'] . ' saved (#' . $record . ') but a follow-up step failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            $msg .= ' (The record was saved, but a follow-up step - queue update or SMS reminder - could not be completed.)';
        }
        return $msg;
    }
}

if (!function_exists('mh_cm_state')) {

    function mh_cm_state(array $state)
    {
        $state['icons'] = [
            'ok'   => mh_icon('check-circle'),
            'warn' => mh_icon('alert-triangle'),
        ];
        echo '<script type="application/json" id="cmState">'
            . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . "</script>\n";
    }
}

if (!function_exists('mh_cm_modal_start')) {

    function mh_cm_modal_start($id, $heading, $systemName, $narrow = false)
    {
        ?>
    <div class="modal-overlay" id="<?php echo mh_h($id); ?>">
        <div class="modal-content cf-modal<?php echo $narrow ? ' cf-modal-narrow' : ''; ?>" role="dialog" aria-modal="true" aria-label="<?php echo mh_h($heading); ?>">
            <span class="close-btn" data-cf-close="<?php echo mh_h($id); ?>" role="button" aria-label="Close">&times;</span>
            <div class="cf-head"><h3><?php echo mh_h($systemName); ?></h3><h2><?php echo mh_h($heading); ?></h2></div>
        <?php
    }
}

if (!function_exists('mh_cm_modal_end')) {

    function mh_cm_modal_end()
    {
        echo "        </div>\n    </div>\n";
    }
}

if (!function_exists('mh_cm_ocr_box')) {

    function mh_cm_ocr_box($title, $endpoint, $fileField, $accept = 'image/*')
    {
        if (!mh_has_permission('use_ocr_scanner')) { return; }
        ?>
            <div class="cf-ocr" data-cf-ocr data-endpoint="<?php echo mh_h($endpoint); ?>" data-field="<?php echo mh_h($fileField); ?>">
                <div class="cf-ocr-title"><?php echo mh_icon('camera'); ?> <?php echo mh_h($title); ?></div>
                <p class="cf-ocr-help">Upload a photo or scan and the form fills in. Review everything before saving.</p>
                <div class="cf-ocr-row">
                    <input type="file" data-cf-ocr-file accept="<?php echo mh_h($accept); ?>" capture="environment">
                    <button type="button" class="du-btn du-btn-primary du-btn-sm" data-cf-ocr-run>Scan &amp; auto-fill</button>
                </div>
                <div class="cf-status" data-cf-ocr-status aria-live="polite"></div>
                <div class="cf-badge" data-cf-ocr-badge hidden></div>
            </div>
        <?php
    }
}

if (!function_exists('mh_cm_feedback')) {

    function mh_cm_feedback()
    {
        echo '            <div class="cf-feedback cf-feedback-err" data-cf-feedback role="alert"></div>' . "\n";
    }
}

if (!function_exists('mh_cm_followup_field')) {

    function mh_cm_followup_field($help)
    {
        ?>
                <div class="cf-field">
                    <label>Follow-up / Return Date <span class="cf-opt">(Optional)</span></label>
                    <input type="date" name="next_visit_date" min="<?php echo date('Y-m-d'); ?>">
                    <span class="cf-help"><?php echo mh_h($help); ?></span>
                </div>
        <?php
    }
}

if (!function_exists('mh_cm_save_button')) {

    function mh_cm_save_button($name, $label, $confirmTitle, $confirmText)
    {
        ?>
                <div class="cf-actions">
                    <button type="button" class="du-btn du-btn-ghost" data-cf-close-form>Cancel</button>
                    <button type="submit" class="du-btn du-btn-primary" name="<?php echo mh_h($name); ?>" value="1" data-confirm="<?php echo mh_h($confirmText); ?>" data-confirm-title="<?php echo mh_h($confirmTitle); ?>" data-confirm-type="info" data-confirm-ok="Save"><?php echo mh_h($label); ?></button>
                </div>
        <?php
    }
}

if (!function_exists('mh_cm_header')) {

    function mh_cm_header($title, $subtitle, $searchPlaceholder, $canView, $canLog, $modalId, $newLabel)
    {
        ?>
            <div class="du-pagehead">
                <div><h1><?php echo mh_h($title); ?></h1><p><?php echo mh_h($subtitle); ?></p></div>
                <div class="du-actions">
                    <?php if ($canView): ?>
                    <form method="get" class="du-actions" role="search">
                        <input type="search" name="q" class="search-box" value="<?php echo mh_h($_GET['q'] ?? ''); ?>" placeholder="<?php echo mh_h($searchPlaceholder); ?>" aria-label="<?php echo mh_h($searchPlaceholder); ?>">
                    </form>
                    <?php endif; ?>
                    <?php if ($canLog): ?>
                    <button type="button" class="du-btn du-btn-primary" data-cf-open="<?php echo mh_h($modalId); ?>"><?php echo mh_icon('plus'); ?> <?php echo mh_h($newLabel); ?></button>
                    <?php endif; ?>
                </div>
            </div>
        <?php

    }
}
