<?php

if (!function_exists('mh_js')) {

    function mh_js($value): string
    {
        $json = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        return $json === false ? 'null' : $json;
    }
}

if (!function_exists('mh_notify_script')) {

    function mh_notify_script(?string $message, string $type = 'info', ?string $title = null): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return '';
        }
        $opts = $title !== null ? ',{title:' . mh_js($title) . '}' : '';
        return "<script>document.addEventListener('DOMContentLoaded',function(){mh_notify("
            . mh_js($message) . ',' . mh_js($type) . $opts . ");});</script>\n";
    }
}

if (!function_exists('mh_flash_scripts')) {
    function mh_flash_scripts(?string $success, ?string $error): string
    {
        return mh_notify_script($success, 'success') . mh_notify_script($error, 'error');
    }
}

if (!function_exists('mh_h')) {

    function mh_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('mh_clean_temperature')) {

    function mh_clean_temperature($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') { return ['ok' => true, 'value' => '', 'error' => null]; }
        if (!preg_match('/^(\d{2}(?:\.\d{1,2})?)\s*(?:°\s*C|C)?$/iu', $raw, $m)) {
            return ['ok' => false, 'value' => '', 'error' => 'Temperature must be a number only, like 36.8 (degrees Celsius).'];
        }
        $v = (float) $m[1];
        if ($v < 30 || $v > 45) {
            return ['ok' => false, 'value' => '', 'error' => 'Temperature must be between 30 and 45 degrees Celsius (for example 36.8).'];
        }
        return ['ok' => true, 'value' => $m[1], 'error' => null];
    }
}

if (!function_exists('mh_clean_vitals')) {

    function mh_clean_vitals(array $raw)
    {

        $spec = [
            'temp'         => ['Temperature', 30, 45, 2, 'c|°\s*c'],
            'pulse'        => ['Pulse rate', 20, 250, 0, 'bpm'],
            'resp_rate'    => ['Respiratory rate', 4, 80, 0, 'bpm|cpm'],
            'spo2'         => ['Oxygen saturation (SpO2)', 50, 100, 0, '%'],
            'weight'       => ['Weight', 0.3, 500, 2, 'kgs?'],
            'height'       => ['Height', 20, 260, 2, 'cms?'],
            'birth_weight' => ['Birth weight', 0.3, 10, 3, 'kgs?'],
            'birth_height' => ['Birth height', 20, 70, 2, 'cms?'],
            'bmi'          => ['BMI', 5, 90, 1, ''],
        ];
        $values = []; $errors = [];
        foreach ($raw as $key => $v) {
            $v = trim((string) $v);
            if ($key === 'bp') {
                if ($v === '') { $values[$key] = ''; continue; }
                if (!preg_match('/^(\d{2,3})\s*\/\s*(\d{2,3})\s*(?:mmhg)?$/i', $v, $m) || (int) $m[1] < 50 || (int) $m[1] > 260 || (int) $m[2] < 30 || (int) $m[2] > 160 || (int) $m[1] <= (int) $m[2]) {
                    $errors[] = 'Blood pressure must be numbers like 120/80 (top number higher than the bottom).'; $values[$key] = ''; continue;
                }
                $values[$key] = (int) $m[1] . '/' . (int) $m[2];
                continue;
            }
            if (!isset($spec[$key])) { $values[$key] = $v; continue; }
            list($label, $min, $max, $dec, $unit) = $spec[$key];
            if ($v === '') { $values[$key] = ''; continue; }
            $rx = '/^(\d+(?:\.\d+)?)\s*(?:' . ($unit !== '' ? $unit : '(?!)') . ')?$/iu';
            if (!preg_match($rx, $v, $m) || ($dec === 0 && strpos($m[1], '.') !== false)) {
                $errors[] = $label . ' must be ' . ($dec === 0 ? 'a whole number' : 'a number') . ' only (no letters or symbols).'; $values[$key] = ''; continue;
            }
            $n = (float) $m[1];
            if ($n < $min || $n > $max) { $errors[] = $label . ' must be between ' . $min . ' and ' . $max . '.'; $values[$key] = ''; continue; }
            $values[$key] = $dec === 0 ? (string) (int) $n : rtrim(rtrim(number_format($n, $dec, '.', ''), '0'), '.');
        }
        return ['values' => $values, 'errors' => $errors];
    }
}
