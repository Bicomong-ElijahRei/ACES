<?php
require_once '../config/database.php';

$message = '';
$success = false;

$token = $_GET['token'] ?? '';

if ($token) {
    $stmt = $pdo->prepare("SELECT user_id, email FROM users WHERE verification_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
    // Activate the account
    $stmt = $pdo->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);

    // ---- IP LOGGING FOR VERIFICATION ----
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt_log = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, logged_at) VALUES (?, ?, NOW())");
    $stmt_log->execute([$user['user_id'], $ip]);
    // ---- END IP LOGGING ----

    $message = "Your email <strong>{$user['email']}</strong> has been verified. You can now log in.";
    $success = true;
    } else {
        $message = "Invalid or expired verification link. Please contact support or register again.";
    }
} else {
    $message = "No verification token provided.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Email Verification | ACES</title>
</head>
<body class="bg-[radial-gradient(circle,_#2a5d1b_0%,_#0d1a0a_100%)] min-h-screen flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md p-8 rounded-sm shadow-2xl text-center">
        <img src="../assets/images/kld_logo.png" alt="Logo" class="w-14 h-14 mx-auto mb-4 object-contain">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Email Verification</h2>

        <div class="mb-6 <?= $success ? 'text-green-600' : 'text-red-600' ?> text-sm">
            <?= $message ?>
        </div>

        <?php if ($success): ?>
            <a href="../index.php" class="inline-block bg-[#00c07f] hover:bg-[#00a86f] text-white font-bold py-2 px-6 rounded-full transition-colors">
                Go to Login
            </a>
        <?php else: ?>
            <a href="../register.php" class="text-gray-600 underline text-sm">Back to Registration</a>
        <?php endif; ?>
    </div>
</body>
</html>