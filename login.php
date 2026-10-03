<?php
/**
 * ============================================================
 * ACES System — Login Handler
 * ============================================================
 * POST-only endpoint. The actual login form lives in index.php.
 *
 * Flow:
 *   1. Verify CSRF token
 *   2. Look up user by email OR student_id
 *   3. password_verify()
 *   4. Check archived / unverified (for students)
 *   5. Log IP + alert on new IP
 *   6. Regenerate session, set $_SESSION, redirect by role
 * ============================================================
 */

require_once __DIR__ . '/config/database.php';

// Secure session cookies (HTTPS only)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
}
session_start();

// CSRF protection
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- CSRF check ----
    csrf_verify();

    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    // ---- Basic input sanity ----
    if ($login_id === '' || $password === '') {
        header('Location: index.php?error=1');
        exit;
    }

    // Query: match either email (from users) or student_id (from students)
    $stmt = $pdo->prepare("
        SELECT u.*, s.is_deleted, s.student_id 
        FROM users u 
        LEFT JOIN students s ON u.user_id = s.user_id 
        WHERE u.email = ? OR s.student_id = ?
        LIMIT 1
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

        // If student and not verified, deny login
        if ($user['role'] === 'student' && !$user['is_verified']) {
            header('Location: index.php?error=unverified');
            exit;
        }

        // ---- IP logging & new-IP alert ----
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $stmt_ip = $pdo->prepare("
            INSERT INTO login_logs (user_id, ip_address, logged_at)
            VALUES (?, ?, NOW())
        ");
        $stmt_ip->execute([$user['user_id'], $ip]);
        $new_log_id = $pdo->lastInsertId();

        // Check if this IP has ever been used by this user before
        $stmt_check = $pdo->prepare("
            SELECT id FROM login_logs
            WHERE user_id = ? AND ip_address = ? AND id != ?
            LIMIT 1
        ");
        $stmt_check->execute([$user['user_id'], $ip, $new_log_id]);
        $is_new_ip = !$stmt_check->fetch();

        if ($is_new_ip) {
            require_once __DIR__ . '/includes/send_email.php';
            $subject = "New sign-in from IP {$ip}";
            $body = "
                <p>Hi " . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . ",</p>
                <p>A new sign-in to your ACES account was detected from IP address
                   <strong>" . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . "</strong>
                   at " . date('Y-m-d H:i:s') . ".</p>
                <p>If this was you, no action is needed.</p>
                <p>If you don't recognise this sign-in, please
                   <strong>change your password immediately</strong>
                   and contact ACES support.</p>
            ";

            try {
                sendEmail($user['email'], $subject, $body);
            } catch (Exception $e) {
                error_log("IP alert email failed for {$user['email']}: " . $e->getMessage());
            }
        }
        // ---- End IP logging ----

        // Handle "remember me" checkbox
        if (!empty($_POST['remember'])) {
            require_once __DIR__ . '/includes/session.php';
            aces_set_remember_cookie($user['user_id'], $pdo, (int) aces_env('SESSION_REMEMBER_DAYS', 30), isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        } else {
            // Clear any existing cookie if user didn't check the box
            require_once __DIR__ . '/includes/session.php';
            aces_clear_remember_cookie();
        }

        // Regenerate session ID (prevent fixation) BEFORE writing session data
        session_regenerate_id(true);

        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];

        if ($user['role'] === 'staff') {
            $_SESSION['staff_role'] = $user['staff_role'] ?? 'viewer';
        } else {
            $_SESSION['staff_role'] = null;
        }

        if ($user['role'] === 'student') {
            $_SESSION['student_id'] = $user['student_id'];
        }

        // Rotate CSRF token on login (best practice)
        csrf_rotate();

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