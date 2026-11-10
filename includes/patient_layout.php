<?php

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/layout/layout.php';

if (!function_exists('mh_pp_e')) {
    function mh_pp_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mh_pp_date')) {

    function mh_pp_date($value, string $format = 'M d, Y'): string
    {
        if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$value);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('mh_pp_ago')) {

    function mh_pp_ago($value): string
    {
        if (empty($value)) { return ''; }
        try {
            $then = new DateTime(date('Y-m-d', strtotime((string)$value)));
            $now  = new DateTime('today');
        } catch (Throwable $e) {
            return '';
        }
        $days = (int)$now->diff($then)->format('%r%a') * -1;
        if ($days <= 0)  { return 'today'; }
        if ($days === 1) { return 'yesterday'; }
        if ($days < 31)  { return $days . ' days ago'; }
        $months = (int)floor($days / 30.44);
        if ($months < 12) { return $months . ($months === 1 ? ' month ago' : ' months ago'); }
        $years = (int)floor($days / 365.25);
        return $years . ($years === 1 ? ' year ago' : ' years ago');
    }
}

if (!function_exists('mh_pp_age')) {
    function mh_pp_age($birthdate): ?int
    {
        if (empty($birthdate)) { return null; }
        try {
            return (new DateTime((string)$birthdate))->diff(new DateTime('today'))->y;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('mh_pp_excerpt')) {

    function mh_pp_excerpt($text, int $max = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)($text ?? ''))));
        if ($text === '') { return ''; }
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
        }
        return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
    }
}

if (!function_exists('mh_pp_initials')) {
    function mh_pp_initials(?string $first, ?string $last = ''): string
    {
        $a = mb_strtoupper(mb_substr(trim((string)$first), 0, 1, 'UTF-8'), 'UTF-8');
        $b = mb_strtoupper(mb_substr(trim((string)$last), 0, 1, 'UTF-8'), 'UTF-8');
        $out = $a . $b;
        return $out !== '' ? $out : 'P';
    }
}

if (!function_exists('mh_pp_patient_no')) {

    function mh_pp_patient_no($id): string
    {
        return '#' . str_pad((string)(int)$id, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('mh_pp_rows')) {
    function mh_pp_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
    {
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) { return []; }
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $res  = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            error_log('[patient portal] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('mh_pp_row')) {
    function mh_pp_row(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
    {
        $rows = mh_pp_rows($conn, $sql, $types, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('mh_pp_has_column')) {
    function mh_pp_has_column(mysqli $conn, string $table, string $column): bool
    {
        $row = mh_pp_row(
            $conn,
            "SELECT 1 AS ok FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            'ss',
            [$table, $column]
        );
        return $row !== null;
    }
}

if (!function_exists('mh_pp_patient')) {
    function mh_pp_patient(mysqli $conn, int $patient_id): ?array
    {
        return mh_pp_row($conn, "SELECT * FROM patients WHERE id = ? LIMIT 1", 'i', [$patient_id]);
    }
}

if (!function_exists('mh_pp_staff_name')) {

    function mh_pp_staff_name(array $r): string
    {
        $first = trim((string)($r['first_name'] ?? ''));
        $last  = trim((string)($r['last_name'] ?? ''));
        if ($first === '' && $last === '') { return 'Health center staff'; }
        $role   = strtolower((string)($r['role'] ?? ''));
        switch ($role) {
            case 'doctor': case 'dental': $prefix = 'Dr. '; break;
            case 'midwife':               $prefix = 'Midwife '; break;
            case 'bhw':                   $prefix = 'BHW '; break;
            default:                      $prefix = '';
        }
        return $prefix . trim($first . ' ' . $last);
    }
}

if (!function_exists('mh_pp_categories')) {
    function mh_pp_categories(): array
    {
        return [
            'general'      => ['label' => 'General Consult', 'class' => 'cat-general',      'icon' => 'activity'],
            'dental'       => ['label' => 'Dental',          'class' => 'cat-dental',       'icon' => 'smile'],
            'maternal'     => ['label' => 'Maternal Care',   'class' => 'cat-maternal',     'icon' => 'heart'],
            'immunization' => ['label' => 'Immunization',    'class' => 'cat-immunization', 'icon' => 'droplet'],
        ];
    }
}

if (!function_exists('mh_pp_visit_categories')) {
    function mh_pp_visit_categories(): array
    {
        return ['general', 'dental', 'maternal'];
    }
}

if (!function_exists('mh_pp_history')) {
    function mh_pp_history(mysqli $conn, int $pid): array
    {
        $out = [];

        $rows = mh_pp_rows($conn,
            "SELECT c.id, c.visit_date, c.chief_complaints, c.diagnosis, c.prescription,
                    s.first_name, s.last_name, s.role
               FROM consultations c
               LEFT JOIN staff s ON s.id = c.doctor_id
              WHERE c.patient_id = ?
              ORDER BY c.visit_date DESC, c.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $out[] = [
                'cat'       => 'general',
                'date'      => $r['visit_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'General Consultation',
                'attending' => mh_pp_staff_name($r),
                'summary'   => mh_pp_excerpt($r['chief_complaints'] ?: $r['diagnosis'], 150),
                'details'   => [
                    'Chief complaint'       => $r['chief_complaints'],
                    'Diagnosis'             => $r['diagnosis'],
                    'Prescription / advice' => $r['prescription'],
                ],
            ];
        }

        $rows = mh_pp_rows($conn,
            "SELECT d.id, d.visit_date, d.procedure_done, d.tooth_number, d.notes,
                    s.first_name, s.last_name, s.role
               FROM dental_records d
               LEFT JOIN staff s ON s.id = d.dentist_id
              WHERE d.patient_id = ?
              ORDER BY d.visit_date DESC, d.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $summary = (string)$r['procedure_done'];
            if (!empty($r['tooth_number'])) { $summary .= ' (tooth ' . $r['tooth_number'] . ')'; }
            $out[] = [
                'cat'       => 'dental',
                'date'      => $r['visit_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'Dental Visit',
                'attending' => mh_pp_staff_name($r),
                'summary'   => mh_pp_excerpt($summary, 150),
                'details'   => [
                    'Procedure' => $r['procedure_done'],
                    'Tooth'     => $r['tooth_number'],
                    'Notes'     => $r['notes'],
                ],
            ];
        }

        $rows = mh_pp_rows($conn,
            "SELECT m.id, m.visit_date, m.aog, m.blood_pressure, m.edc, m.notes,
                    s.first_name, s.last_name, s.role
               FROM maternal_records m
               LEFT JOIN staff s ON s.id = m.midwife_id
              WHERE m.patient_id = ?
              ORDER BY m.visit_date DESC, m.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $parts = [];
            if (!empty($r['aog']))            { $parts[] = 'AOG ' . $r['aog']; }
            if (!empty($r['blood_pressure'])) { $parts[] = 'BP ' . $r['blood_pressure']; }
            $out[] = [
                'cat'       => 'maternal',
                'date'      => $r['visit_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'Prenatal Check-up',
                'attending' => mh_pp_staff_name($r),
                'summary'   => $parts ? implode(', ', $parts) : mh_pp_excerpt($r['notes'], 150),
                'details'   => [
                    'Age of gestation'  => $r['aog'],
                    'Blood pressure'    => $r['blood_pressure'],
                    'Expected delivery' => !empty($r['edc']) ? mh_pp_date($r['edc'], 'F j, Y') : '',
                    'Notes'             => $r['notes'],
                ],
            ];
        }

        $rows = mh_pp_rows($conn,
            "SELECT i.id, i.vaccine_name, i.dose_number, i.date_administered, i.notes,
                    s.first_name, s.last_name, s.role
               FROM immunization_records i
               LEFT JOIN staff s ON s.id = i.health_worker_id
              WHERE i.patient_id = ?
              ORDER BY i.date_administered DESC, i.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $dose = !empty($r['dose_number']) ? 'Dose ' . $r['dose_number'] : '';
            $out[] = [
                'cat'       => 'immunization',
                'date'      => $r['date_administered'],
                'sort'      => (int)$r['id'],
                'title'     => trim((string)$r['vaccine_name']) ?: 'Vaccination',
                'attending' => mh_pp_staff_name($r),
                'summary'   => $dose !== '' ? $dose : mh_pp_excerpt($r['notes'], 150),
                'details'   => [
                    'Vaccine' => $r['vaccine_name'],
                    'Dose'    => $r['dose_number'],
                    'Notes'   => $r['notes'],
                ],
            ];
        }

        $rows = mh_pp_rows($conn,
            "SELECT l.id, l.consultation_date, l.chief_complaints, l.remarks,
                    s.first_name, s.last_name, s.role
               FROM child_consultation_logs l
               JOIN child_immunization_records r ON r.id = l.child_record_id
               LEFT JOIN staff s ON s.id = r.attending_staff_id
              WHERE r.patient_id = ?
              ORDER BY l.consultation_date DESC, l.id DESC LIMIT 500", 'i', [$pid]);
        foreach ($rows as $r) {
            $out[] = [
                'cat'       => 'immunization',
                'date'      => $r['consultation_date'],
                'sort'      => (int)$r['id'],
                'title'     => 'Child Health Consultation',
                'attending' => mh_pp_staff_name($r),
                'summary'   => mh_pp_excerpt($r['chief_complaints'] ?: $r['remarks'], 150),
                'details'   => [
                    'Chief complaint' => $r['chief_complaints'],
                    'Remarks'         => $r['remarks'],
                ],
            ];
        }

        usort($out, function ($a, $b) {
            $c = strcmp((string)$b['date'], (string)$a['date']);
            return $c !== 0 ? $c : ($b['sort'] <=> $a['sort']);
        });

        return $out;
    }
}

if (!function_exists('mh_pp_dispensed')) {
    function mh_pp_dispensed(mysqli $conn, int $pid, int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        return mh_pp_rows($conn,
            "SELECT d.id, d.quantity, d.dispense_date,
                    i.item_name, i.brand_name, i.unit,
                    s.first_name, s.last_name, s.role
               FROM dispensations d
               JOIN inventory i ON i.id = d.inventory_id
               LEFT JOIN staff s ON s.id = d.dispensed_by
              WHERE d.patient_id = ?
              ORDER BY d.dispense_date DESC, d.id DESC
              LIMIT $limit", 'i', [$pid]);
    }
}

if (!function_exists('mh_pp_recent_dispense_count')) {

    function mh_pp_recent_dispense_count(mysqli $conn, int $pid, int $days = 90): int
    {
        $days = max(1, $days);
        $row  = mh_pp_row($conn,
            "SELECT COUNT(*) AS c FROM dispensations
              WHERE patient_id = ? AND dispense_date >= (NOW() - INTERVAL $days DAY)", 'i', [$pid]);
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('mh_pp_programs')) {
    function mh_pp_programs(mysqli $conn, int $pid): array
    {
        $programs = [];

        $child = mh_pp_row($conn,
            "SELECT bcg_date, hep_b_24hrs_date, penta_opv_3_date, penta_1_opv_1_date,
                    penta_2_opv_2_date, pcv_1_date, pcv_2_date, pcv_3_date, mr_date, mmr_date
               FROM child_immunization_records
              WHERE patient_id = ? ORDER BY id DESC LIMIT 1", 'i', [$pid]);
        if ($child) {
            $done = 0;
            foreach ($child as $v) {
                if (!empty($v) && $v !== '0000-00-00') { $done++; }
            }
            $programs[] = [
                'label'  => 'Child Immunization',
                'status' => 'Enrolled',
                'detail' => $done . ' of ' . count($child) . ' scheduled vaccines recorded',
                'icon'   => 'droplet',
            ];
        }

        $mat = mh_pp_row($conn,
            "SELECT aog, edc, visit_date FROM maternal_records
              WHERE patient_id = ? ORDER BY visit_date DESC, id DESC LIMIT 1", 'i', [$pid]);
        if ($mat) {
            $detail = !empty($mat['edc'])
                ? 'Expected delivery ' . mh_pp_date($mat['edc'], 'M d, Y')
                : 'Last check-up ' . mh_pp_date($mat['visit_date'], 'M d, Y');
            $programs[] = [
                'label'  => 'Maternal Care',
                'status' => 'Enrolled',
                'detail' => $detail,
                'icon'   => 'heart',
            ];
        }

        $imm = mh_pp_row($conn,
            "SELECT COUNT(*) AS c, MAX(date_administered) AS last_date
               FROM immunization_records WHERE patient_id = ?", 'i', [$pid]);
        if ($imm && (int)$imm['c'] > 0) {
            $programs[] = [
                'label'  => 'Vaccinations',
                'status' => (int)$imm['c'] . ((int)$imm['c'] === 1 ? ' dose' : ' doses') . ' on record',
                'detail' => 'Latest ' . mh_pp_date($imm['last_date'], 'M d, Y'),
                'icon'   => 'shield',
            ];
        }

        return $programs;
    }
}

if (!function_exists('mh_pp_center_info')) {
    function mh_pp_center_info(): array
    {
        global $health_center_hours, $health_center_hotline;
        require_once __DIR__ . '/queue_helper.php';

        $daysStr  = function_exists('mh_operating_days_summary') ? mh_operating_days_summary() : 'Monday – Friday';
        $hoursStr = function_exists('mh_operating_hours_summary') ? mh_operating_hours_summary() : '8:00 AM – 5:00 PM';

        $hours = [
            ['days' => $daysStr, 'time' => $hoursStr],
        ];
        if (!empty($health_center_hours) && is_string($health_center_hours)) {
            $hours = [['days' => 'Open', 'time' => $health_center_hours]];
        }

        $hotlines = [
            ['label' => 'National Emergency Hotline', 'number' => '911', 'tel' => '911'],
        ];
        if (!empty($health_center_hotline) && is_string($health_center_hotline)) {
            array_unshift($hotlines, [
                'label'  => 'Barangay Health Center',
                'number' => $health_center_hotline,
                'tel'    => preg_replace('/[^0-9+]/', '', $health_center_hotline),
            ]);
        }

        return [
            'hours'    => $hours,
            'services' => [
                ['name' => 'Walk-in Consultations', 'icon' => 'activity', 'desc' => 'General check-ups and consultations with our doctor and health workers.'],
                ['name' => 'Child Immunization',    'icon' => 'droplet',  'desc' => 'Routine childhood vaccines and growth monitoring.'],
                ['name' => 'Maternal & Prenatal Care', 'icon' => 'heart', 'desc' => 'Prenatal check-ups and maternal health monitoring with our midwife.'],
                ['name' => 'Dental Services',       'icon' => 'smile',    'desc' => 'Dental consultations and basic procedures.'],
                ['name' => 'Medicine Dispensing',   'icon' => 'package',  'desc' => 'Medicines issued by health center staff, subject to available stock.'],
            ],
            'hotlines' => $hotlines,
        ];
    }
}

if (!function_exists('mh_pp_render_center_card')) {
    function mh_pp_render_center_card(bool $detailed = false): void
    {
        $info = mh_pp_center_info();
        ?>
        <div class="unified-panel">
            <div class="panel-header">
                <h3><?php echo mh_icon('home'); ?> Health Center Information</h3>
            </div>

            <div class="pp-section">
                <h4 class="pp-section-title">Operating hours</h4>
                <?php foreach ($info['hours'] as $h): ?>
                    <div class="pp-hours-row">
                        <span class="pp-hours-icon"><?php echo mh_icon('clock'); ?></span>
                        <span class="pp-hours-days"><?php echo mh_pp_e($h['days']); ?></span>
                        <span class="pp-hours-time"><?php echo mh_pp_e($h['time']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pp-section">
                <h4 class="pp-section-title">Available services</h4>
                <ul class="pp-services<?php echo $detailed ? ' is-detailed' : ''; ?>">
                    <?php foreach ($info['services'] as $s): ?>
                        <li>
                            <span class="pp-service-icon"><?php echo mh_icon($s['icon']); ?></span>
                            <span>
                                <strong><?php echo mh_pp_e($s['name']); ?></strong>
                                <?php if ($detailed): ?>
                                    <small><?php echo mh_pp_e($s['desc']); ?></small>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="pp-section">
                <div class="pp-emergency">
                    <div class="pp-emergency-head">
                        <?php echo mh_icon('alert-triangle'); ?>
                        <strong>Emergency hotline</strong>
                    </div>
                    <?php foreach ($info['hotlines'] as $hl): ?>
                        <a class="pp-hotline" href="tel:<?php echo mh_pp_e($hl['tel']); ?>">
                            <span><?php echo mh_pp_e($hl['label']); ?></span>
                            <span class="pp-hotline-number"><?php echo mh_icon('phone'); ?> <?php echo mh_pp_e($hl['number']); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <p class="pp-emergency-note">For life-threatening emergencies, go to the nearest hospital right away.</p>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('mh_pp_layout_start')) {
    function mh_pp_layout_start(array $o): void
    {
        $system_name = (string) ($o['system_name'] ?? mh_setting('system_name'));
        mh_layout_head([
            'portal'      => 'patient',
            'title'       => (string) ($o['title'] ?? 'Patient Portal'),
            'system_name' => $system_name,
            'styles'      => ['css/patient_portal.css'],
        ]);
        mh_layout_open($o);
        echo "<div class=\"pp-page\">\n";
    }
}

if (!function_exists('mh_pp_layout_end')) {
    function mh_pp_layout_end(): void
    {
        echo "</div>\n";
        mh_layout_close();
        mh_layout_end();
    }
}
