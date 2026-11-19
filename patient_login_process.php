<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_guard.php';
require_once __DIR__ . '/includes/login_throttle.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $mh_wait = mh_lt_blocked_seconds($conn, 'patient', $username);
    if ($mh_wait > 0) {
        header("Location: patient_login.php?error=locked&wait=" . (int) ceil($mh_wait / 60));
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $query = $stmt->get_result();

    if ($query && $query->num_rows > 0) {
        $patient = $query->fetch_assoc();
        $stmt->close();
        
        if (password_verify($password, $patient['password'])) {
            mh_lt_clear($conn, 'patient', $username);
            session_regenerate_id(true);

            $_SESSION['patient_id'] = $patient['id'];
            $_SESSION['patient_name'] = $patient['first_name'];
            mh_ss_issue('patient', $patient['id']);
            logActivity($conn, (int) $patient['id'], 'patient', 'Patient Login', 'Patient #' . (int) $patient['id'] . ' signed in to the portal.');
            
            header("Location: patient/dashboard.php");
            exit();
        } else {
            mh_lt_record_failure($conn, 'patient', $username);
            header("Location: patient_login.php?error=incorrect_password");
            exit();
        }
    } else {
        $stmt->close();
        mh_lt_record_failure($conn, 'patient', $username);
        header("Location: patient_login.php?error=not_found");
        exit();
    }
}
?>