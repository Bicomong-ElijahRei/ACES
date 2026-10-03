<?php
/**
 * ============================================================
 * ACES System — Verify Student Email
 * ============================================================
 * Called from the verification link in the student registration
 * email. Auto-activates the account on valid token.
 *
 * Checks:
 *   - Token exists
 *   - Token hasn't expired (24 hours)
 *   - Account isn't already verified
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

$message = '';
$success = false;
$already_verified = false;
$token   = trim($_GET['token'] ?? '');

if ($token === '') {
    $message = 'No verification token provided. Please use the link from your email.';
} else {
    $stmt = $pdo->prepare("
        SELECT user_id, email, full_name, is_verified, verification_expires
        FROM users
        WHERE verification_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $message = 'Invalid or expired verification link. Please contact support or register again.';
    } elseif ($user['is_verified']) {
        $already_verified = true;
        $message = 'This email has already been verified. You can log in below.';
        $success = true;
    } elseif (!empty($user['verification_expires']) && strtotime($user['verification_expires']) < time()) {
        $message = 'This verification link has expired. Please register again or contact support.';
    } else {
        // ---- Activate the account ----
        $stmt = $pdo->prepare("
            UPDATE users
            SET is_verified = 1,
                verification_token = NULL,
                verification_expires = NULL
            WHERE user_id = ?
        ");
        $stmt->execute([$user['user_id']]);

        // ---- IP logging for verification ----
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $stmt_log = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, logged_at) VALUES (?, ?, NOW())");
            $stmt_log->execute([$user['user_id'], $ip]);
        } catch (Exception $e) {
            error_log('Verification IP log failed: ' . $e->getMessage());
        }

        $message = 'Your email <strong>' . htmlspecialchars($user['email']) . '</strong> has been verified. You can now log in.';
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <title>Email Verification | ACES</title>
    <style>
        body {
            background: radial-gradient(circle, #2a5d1b 0%, #0d1a0a 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">

    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">

        <!-- Header -->
        <div class="bg-[#0a6e2d] px-6 py-5 text-white">
            <div class="flex items-center gap-3">
                <img src="../assets/images/kld_logo.png" alt="KLD" class="w-10 h-10 object-contain bg-white rounded-full p-0.5">
                <div>
                    <h1 class="text-base font-bold">ACES Student</h1>
                    <p class="text-xs opacity-80">Kolehiyo ng Lungsod ng Dasmariñas</p>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="p-8 text-center">

            <?php if ($success): ?>
                <!-- SUCCESS -->
                <div class="w-16 h-16 rounded-full <?= $already_verified ? 'bg-blue-100' : 'bg-green-100' ?> flex items-center justify-center mx-auto mb-4">
                    <i class="fas <?= $already_verified ? 'fa-info-circle text-blue-600' : 'fa-check-circle text-green-600' ?> text-3xl"></i>
                </div>
                <h2 class="text-xl font-bold <?= $already_verified ? 'text-blue-700' : 'text-[#0a6e2d]' ?> mb-2">
                    <?= $already_verified ? 'Already Verified' : 'Email Verified!' ?>
                </h2>
                <p class="text-gray-600 text-sm mb-6 leading-relaxed">
                    <?= $message ?>
                </p>
                <a href="../index.php"
                   class="inline-flex items-center gap-2 bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 px-6 rounded-lg transition">
                    <i class="fas fa-sign-in-alt"></i> Go to Login
                </a>

            <?php elseif (!empty($message)): ?>
                <!-- ERROR -->
                <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-exclamation-circle text-red-500 text-3xl"></i>
                </div>
                <h2 class="text-xl font-bold text-red-700 mb-2">Verification Failed</h2>
                <p class="text-gray-600 text-sm mb-6 leading-relaxed">
                    <?= $message ?>
                </p>
                <div class="flex flex-col gap-2 items-center">
                    <a href="../register.php"
                       class="inline-flex items-center gap-2 bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-2.5 px-6 rounded-lg transition text-sm">
                        <i class="fas fa-user-plus"></i> Register Again
                    </a>
                    <a href="../index.php" class="text-gray-500 text-xs hover:text-gray-700 underline">
                        Back to Login
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>

</body>
</html>