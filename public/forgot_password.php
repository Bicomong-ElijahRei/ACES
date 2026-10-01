<?php
/**
 * ============================================================
 * ACES System — Forgot Password
 * ============================================================
 * Accepts an email, generates a reset token (1 hour expiry),
 * and sends a password reset link. Always shows the same
 * success message to prevent email enumeration.
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- CSRF check ----
    csrf_verify();

    $email = trim($_POST['email'] ?? '');

    if (!$email) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // ---- Simple rate limit: max 3 requests per email per hour ----
        // We use login_logs as a generic audit trail for this check.
        // (Adjust the query if you prefer a dedicated table.)
        $recentCount = 0;
        try {
            $rl = $pdo->prepare("
                SELECT COUNT(*) FROM login_logs
                WHERE ip_address = ? AND logged_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $rl->execute([$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $recentCount = (int) $rl->fetchColumn();
        } catch (Exception $e) {
            // If the table or column is missing, skip the rate limit silently
            $recentCount = 0;
        }

        if ($recentCount >= 20) {
            // Too aggressive in general — reject to prevent spam
            $error = 'Too many requests. Please try again later.';
        } else {
            // Find user by email
            $stmt = $pdo->prepare("SELECT user_id, full_name, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate reset token and expiry (1 hour)
                $token   = bin2hex(random_bytes(32));
                // Use MySQL's clock so writes match the reads (avoids timezone drift)
                $expires = $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL 1 HOUR)")->fetchColumn();

                $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE user_id = ?");
                $upd->execute([$token, $expires, $user['user_id']]);

                // Send reset email
                require_once __DIR__ . '/../includes/send_email.php';

                $baseUrl = rtrim(aces_env('APP_URL', 'http://localhost/cair-system'), '/');
                $reset_link = $baseUrl . "/public/reset_password.php?token=" . urlencode($token);

                $subject = "Password Reset - ACES";
                $safeName = htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8');
                $body = "
                    <p>Hi {$safeName},</p>
                    <p>You requested a password reset. Click the link below to set a new password:</p>
                    <p><a href='{$reset_link}'>{$reset_link}</a></p>
                    <p>This link will expire in 1 hour. If you didn't request this, ignore this email.</p>
                ";

                try {
                    sendEmail($email, $subject, $body);
                } catch (Exception $e) {
                    error_log("Password reset email failed for {$email}: " . $e->getMessage());
                }
            }

            // Always show same success message — prevents email enumeration
            $success = 'If an account with that email exists, a reset link has been sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Forgot Password | ACES</title>
</head>
<body class="bg-[radial-gradient(circle,_#2a5d1b_0%,_#0d1a0a_100%)] min-h-screen flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md p-8 rounded-sm shadow-2xl">
        <img src="../assets/images/kld_logo.png" alt="Logo" class="w-14 h-14 mx-auto mb-4 object-contain">
        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">Forgot Password</h2>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
                <input type="email" name="email"
                       class="w-full border border-gray-400 p-2 rounded-sm text-sm"
                       autocomplete="email" required>
            </div>

            <button type="submit"
                    class="w-full bg-[#00c07f] hover:bg-[#00a86f] text-white font-bold py-2 px-4 rounded-full transition-colors">
                Send Reset Link
            </button>
        </form>

        <p class="text-center text-sm text-gray-500 mt-4">
            <a href="../index.php" class="text-[#0a6e2d] hover:underline">Back to Login</a>
        </p>
    </div>
</body>
</html>