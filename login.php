<?php
require_once 'config/database.php';

// Optional: Secure session cookie settings (only if using HTTPS)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
}
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = $_POST['login_id'];
    $password = $_POST['password'];

    // Query: match either email (from users) or student_id (from students)
    $stmt = $pdo->prepare("
        SELECT u.*, s.is_deleted, s.student_id 
        FROM users u 
        LEFT JOIN students s ON u.user_id = s.user_id 
        WHERE u.email = ? OR s.student_id = ?
    ");
    $stmt->execute([$login_id, $login_id]);
    $user = $stmt->fetch();

    // Verify password
    if ($user && password_verify($password, $user['password_hash'])) {
        // If student and archived, deny login
        if ($user['role'] === 'student' && $user['is_deleted'] == 1) {
            header('Location: index.php?error=archived');
            exit;
        }

        // ---- EMAIL VERIFICATION CHECK ----
        // If student and not verified, deny login
        if ($user['role'] === 'student' && !$user['is_verified']) {
            header('Location: index.php?error=unverified');
            exit;
        }
        // ---- END EMAIL VERIFICATION CHECK ----

        // ---- IP LOGGING & NEW IP ALERT ----
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Insert log entry
        $stmt_ip = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, logged_at) VALUES (?, ?, NOW())");
        $stmt_ip->execute([$user['user_id'], $ip]);

        // Check if this IP has ever been used by this user before
        $stmt_check = $pdo->prepare("SELECT id FROM login_logs WHERE user_id = ? AND ip_address = ? AND id != ?");
        $stmt_check->execute([$user['user_id'], $ip, $pdo->lastInsertId()]);
        $is_new_ip = !$stmt_check->fetch();   // true if no previous row with same IP

        if ($is_new_ip) {
            // Send email alert
            require_once __DIR__ . '/includes/send_email.php';
            $subject = "New sign‑in from IP {$ip}";
            $body = "
                <p>Hi {$user['full_name']},</p>
                <p>A new sign‑in to your ACES account was detected from IP address <strong>{$ip}</strong> at " . date('Y-m-d H:i:s') . ".</p>
                <p>If this was you, no action is needed.</p>
                <p>If you don't recognise this sign‑in, please <strong>change your password immediately</strong> and contact ACES support.</p>
            ";

            try {
                sendEmail($user['email'], $subject, $body);
            } catch (Exception $e) {
                error_log("IP alert email failed for {$user['email']}: " . $e->getMessage());
            }
        }
        // ---- END IP LOGGING ----

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];

        // Store staff_role if the user is staff
        if ($user['role'] === 'staff') {
            $_SESSION['staff_role'] = $user['staff_role'] ?? 'viewer';
        } else {
            $_SESSION['staff_role'] = null;
        }

        if ($user['role'] === 'student') {
            $_SESSION['student_id'] = $user['student_id'];
        }
        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        if ($user['role'] === 'staff') {
            header('Location: staff/dashboard.php');
        } else {
            header('Location: student/dashboard.php');
        }
        exit;
    } else {
        // Invalid login
        header('Location: index.php?error=1');
        exit;
    }
}
// If not POST, redirect to index
header('Location: index.php');
exit;