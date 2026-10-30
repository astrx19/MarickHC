<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_guard.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $query = $stmt->get_result();

    if ($query && $query->num_rows > 0) {
        $patient = $query->fetch_assoc();
        $stmt->close();
        
        if (password_verify($password, $patient['password'])) {
            // New session id on login (session-fixation protection).
            session_regenerate_id(true);

            $_SESSION['patient_id'] = $patient['id'];
            $_SESSION['patient_name'] = $patient['first_name'];
            mh_ss_issue('patient', $patient['id']);   // invalidates this account's other sessions
            
            header("Location: patient/dashboard.php");
            exit();
        } else {
            header("Location: patient_login.php?error=incorrect_password");
            exit();
        }
    } else {
        $stmt->close();
        header("Location: patient_login.php?error=not_found");
        exit();
    }
}
?>