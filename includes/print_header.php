<?php

if (!function_exists('mh_print_header')) {

    function mh_print_header(array $o = []): void
    {
        static $assets_done = false;

        $esc   = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $state = function_exists('mh_layout_state') ? mh_layout_state() : [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $is_patient = (($state['portal'] ?? 'staff') === 'patient');

        $clinic = trim((string) ($o['clinic'] ?? ($state['system_name'] ?? '')));
        if ($clinic === '' && function_exists('mh_setting')) {
            $clinic = trim((string) mh_setting('system_name'));
        }
        if ($clinic === '') {
            $clinic = 'Barangay Marick Health Center';
        }

        $title = trim((string) ($o['title'] ?? ($state['print_title'] ?? ($state['title'] ?? ''))));
        $title = trim(preg_replace('/\s*-\s*Print\s*$/i', '', $title));
        if ($title === '') {
            $title = 'Report';
        } elseif (!preg_match('/report$/i', $title)) {
            $title .= ' Report';
        }
        $note = trim((string) ($o['note'] ?? ($state['print_note'] ?? '')));

        if (isset($o['printed_by'])) {
            $by = trim((string) $o['printed_by']);
        } elseif ($is_patient) {
            $by = trim((string) ($_SESSION['patient_name'] ?? ''));
        } else {
            $by = trim((string) ($_SESSION['full_name'] ?? ''));
            if ($by === '') {
                $by = trim(((string) ($_SESSION['first_name'] ?? '')) . ' ' . ((string) ($_SESSION['last_name'] ?? '')));
            }
            if ($by === '') {
                $by = trim((string) ($_SESSION['username'] ?? ''));
            }
        }
        if ($by === '') {
            $by = $is_patient ? 'Patient' : 'Staff Member';
        }

        if (isset($o['role'])) {
            $role = trim((string) $o['role']);
        } elseif ($is_patient) {
            $role = 'Patient';
        } else {
            $raw  = (string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
            $role = $raw === '' ? '' : (function_exists('mh_role_label') ? (string) mh_role_label($raw) : ucwords(str_replace('_', ' ', $raw)));
        }
        if ($role === '') {
            $role = 'User';
        }

        $now_date = date('F j, Y');
        $now_time = date('g:i A');

        $logo = '../assets/images/logo.png';

        if (!$assets_done) {
            $assets_done = true;
            ?>
<style>
    .mh-print-header { display: none; }
    body.print-view .mh-print-header { display: block; }
    .mh-print-header { color: #000; font-family: Arial, Helvetica, "Liberation Sans", sans-serif; margin: 0 0 6mm; padding: 0 0 3mm; border-bottom: 2px solid #000; }
    .mh-print-header .mph-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 8mm; }
    .mh-print-header .mph-id { display: flex; align-items: center; gap: 4mm; min-width: 0; }
    .mh-print-header .mph-logo { width: 16mm; height: 16mm; object-fit: contain; filter: grayscale(1) contrast(1.2); }
    .mh-print-header .mph-clinic { margin: 0; font-size: 10pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .mh-print-header .mph-title { margin: 1mm 0 0; font-size: 17pt; font-weight: 700; line-height: 1.15; }
    .mh-print-header .mph-note { margin: 1.5mm 0 0; font-size: 9pt; }
    .mh-print-header .mph-meta { border-collapse: collapse; font-size: 9pt; flex: 0 0 auto; }
    .mh-print-header .mph-meta th, .mh-print-header .mph-meta td { border: 1px solid #000; padding: 1mm 2.5mm; text-align: left; white-space: nowrap; }
    .mh-print-header .mph-meta th { background: #e6e6e6; font-weight: 700; text-transform: uppercase; font-size: 8pt; }
</style>
<script>
(function () {
    var reopen = [];
    window.addEventListener('beforeprint', function () {
        var now = new Date();
        var d = now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        var t = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        document.querySelectorAll('[data-print-date]').forEach(function (el) { el.textContent = d; });
        document.querySelectorAll('[data-print-time]').forEach(function (el) { el.textContent = t; });
        reopen = [];
        document.querySelectorAll('details:not([open])').forEach(function (el) { el.setAttribute('open', ''); reopen.push(el); });
    });
    window.addEventListener('afterprint', function () {
        reopen.forEach(function (el) { el.removeAttribute('open'); });
        reopen = [];
    });
})();
</script>
            <?php
        }
        ?>
<div class="mh-print-header" role="banner">
    <div class="mph-row">
        <div class="mph-id">
            <img class="mph-logo" src="<?php echo $esc($logo); ?>" alt="" onerror="this.style.display='none'">
            <div>
                <p class="mph-clinic"><?php echo $esc($clinic); ?></p>
                <h1 class="mph-title"><?php echo $esc($title); ?></h1>
                <?php if ($note !== ''): ?><p class="mph-note"><?php echo $esc($note); ?></p><?php endif; ?>
            </div>
        </div>
        <table class="mph-meta" aria-label="Print details">
            <tr><th>Date Printed</th><td data-print-date><?php echo $esc($now_date); ?></td></tr>
            <tr><th>Time Printed</th><td data-print-time><?php echo $esc($now_time); ?></td></tr>
            <tr><th>Printed By</th><td><?php echo $esc($by); ?></td></tr>
            <tr><th>Role</th><td><?php echo $esc($role); ?></td></tr>
        </table>
    </div>
</div>
        <?php
    }
}
