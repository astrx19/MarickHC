<?php

if (!function_exists('mh_ocr_json_error')) {

    function mh_ocr_json_error($message, $httpCode = 0, array $extra = [])
    {
        global $mh_ocr_style;
        if ($httpCode) {
            http_response_code((int) $httpCode);
        }
        if ($mh_ocr_style === 'message') {
            $payload = ['success' => false, 'message' => $message];
        } elseif ($mh_ocr_style === 'bare') {
            $payload = ['error' => $message];
        } else {
            $payload = ['success' => false, 'error' => $message];
        }
        echo json_encode($payload + $extra);
        exit;
    }
}

if (!function_exists('mh_ocr_preflight')) {

    function mh_ocr_preflight($style = 'error')
    {
        global $mh_ocr_style;
        $mh_ocr_style = $style;

        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        header('Content-Type: application/json');
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');

        if (!isset($_SESSION['user_id'])) {
            mh_ocr_json_error('Your session has expired. Please log in again.', 401);
        }
    }
}

if (!function_exists('mh_ocr_begin')) {

    function mh_ocr_begin(array $o)
    {
        global $mh_ocr_module;
        $mh_ocr_module = (string) ($o['module'] ?? '');

        require_once __DIR__ . '/../vision_ocr_helper.php';
        require_once __DIR__ . '/../../config/vision.php';

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            mh_ocr_json_error('Invalid request method.');
        }

        $upload = vision_ocr_get_uploaded_file($o['field']);
        if (empty($upload['success'])) {

            foreach (['document', 'ocr_image', 'ocr_file'] as $alt) {
                if ($alt !== $o['field']) {
                    $u = vision_ocr_get_uploaded_file($alt);
                    if (!empty($u['success'])) {
                        $upload = $u;
                        break;
                    }
                }
            }
        }

        if (empty($upload['success'])) {
            mh_ocr_json_error($upload['error'] ?? 'The file could not be uploaded.');
        }
        return $upload;
    }
}

if (!function_exists('mh_ocr_extract')) {

    function mh_ocr_extract(array $upload, $prompt, array $schema)
    {
        $result = gemini_ocr_extract_fields($upload['path'], $upload['mime_type'], $prompt, $schema);
        if (!empty($result['success'])) {

            global $conn, $mh_ocr_module;
            if (isset($conn) && $conn instanceof mysqli && function_exists('logActivity') && !empty($_SESSION['user_id'])) {
                $n = 0;
                foreach ($result['fields'] as $v) { if ($v !== '' && $v !== null && $v !== []) { $n++; } }
                logActivity($conn, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? 'staff'), 'OCR Scan', ($mh_ocr_module ?: 'Form') . ' scan: ' . $n . ' fields read.');
            }
            return $result['fields'];
        }

        $transient = in_array(($result['http_code'] ?? 0), [0, 429, 500, 502, 503, 504], true);
        $extra = [];
        foreach (['http_code', 'model', 'debug'] as $k) {
            if (!empty($result[$k])) {
                $extra[$k] = $result[$k];
            }
        }
        mh_ocr_json_error($result['error'] ?? 'The scan failed.', $transient ? 503 : 0, $extra);
    }
}

if (!function_exists('mh_ocr_normalize_dates')) {

    function mh_ocr_normalize_dates(array $fields, array $keys)
    {
        foreach ($keys as $key) {
            if (empty($fields[$key])) {
                continue;
            }
            $normalized = function_exists('vision_ocr_normalize_date')
                ? vision_ocr_normalize_date($fields[$key])
                : (function_exists('gemini_ocr_normalize_date') ? gemini_ocr_normalize_date($fields[$key]) : '');
            if ($normalized !== '') {
                $fields[$key] = $normalized;
            } else {
                unset($fields[$key]);
            }
        }
        return $fields;
    }
}

if (!function_exists('mh_ocr_take_identity')) {

    function mh_ocr_take_identity(array &$fields)
    {
        $identity = [
            'first_name'  => $fields['first_name'] ?? '',
            'middle_name' => $fields['middle_name'] ?? '',
            'last_name'   => $fields['last_name'] ?? '',
            'birthdate'   => '',
        ];
        unset($fields['first_name'], $fields['middle_name'], $fields['last_name']);

        if (!empty($fields['birthdate'])) {
            $bday = function_exists('vision_ocr_normalize_date')
                ? vision_ocr_normalize_date($fields['birthdate'])
                : (function_exists('gemini_ocr_normalize_date') ? gemini_ocr_normalize_date($fields['birthdate']) : '');
            if ($bday !== '') {
                $identity['birthdate'] = $bday;
            }
        }
        unset($fields['birthdate']);
        return $identity;
    }
}

if (!function_exists('mh_ocr_match')) {

    function mh_ocr_match(array $identity)
    {
        global $conn;
        if (empty($identity['first_name']) || empty($identity['last_name']) || !($conn instanceof mysqli)) {
            return null;
        }
        try {
            $m = function_exists('vision_ocr_match_patient')
                ? vision_ocr_match_patient($conn, $identity['first_name'], $identity['last_name'], $identity['birthdate'] ?? '')
                : (function_exists('gemini_ocr_match_patient') ? gemini_ocr_match_patient($conn, $identity['first_name'], $identity['last_name'], $identity['birthdate'] ?? '') : null);
            if (!$m) {
                return null;
            }
            return [
                'id'          => (int) $m['id'],
                'full_name'   => $m['full_name'],
                'first_name'  => $m['first_name'],
                'middle_name' => $m['middle_name'] ?? '',
                'last_name'   => $m['last_name'],
                'birthdate'   => $m['birthdate'],
            ];
        } catch (Throwable $e) {
            if (function_exists('vision_ocr_log_error')) {
                vision_ocr_log_error('matcher', 'Exception', 'mh_ocr_match error: ' . $e->getMessage());
            } else {
                @error_log('[MarickHealth][OCR] mh_ocr_match error: ' . $e->getMessage());
            }
            return null;
        }
    }
}
