<?php
require_once '../config/database.php';

$token = $_GET['token'] ?? '';
$success = false;
$error   = '';
$user    = null;

if ($token) {
    // Look up user with this token
    $stmt = $pdo->prepare("SELECT user_id, email, full_name, is_verified FROM users WHERE verification_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = 'Invalid or expired verification link. Please contact an administrator.';
    } elseif ($user['is_verified']) {
        $error = 'This account has already been verified. You can log in below.';
    }
}

// Handle password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && !$user['is_verified']) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, is_verified = 1, verification_token = NULL WHERE user_id = ?");
        $stmt->execute([$hash, $user['user_id']]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account | ACES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#dcf3e6] h-screen flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl p-8 w-full max-w-md">
        <?php if ($success): ?>
            <div class="text-center">
                <i class="fas fa-check-circle text-green-500 text-5xl mb-4"></i>
                <h2 class="text-2xl font-bold text-[#0a6e2d] mb-2">Account Verified!</h2>
                <p class="text-gray-600 mb-4">Your password has been set. You can now log in.</p>
                <a href="../login.php" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-6 rounded">
                    Go to Login
                </a>
            </div>
        <?php elseif ($error): ?>
            <div class="text-center">
                <i class="fas fa-exclamation-circle text-red-500 text-5xl mb-4"></i>
                <h2 class="text-2xl font-bold text-red-700 mb-2">Verification Failed</h2>
                <p class="text-gray-600 mb-4"><?= htmlspecialchars($error) ?></p>
                <a href="../login.php" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-6 rounded">
                    Go to Login
                </a>
            </div>
        <?php elseif ($user): ?>
            <h2 class="text-2xl font-bold text-[#0a6e2d] mb-4">Set Your Password</h2>
            <p class="text-gray-600 mb-4">Welcome, <?= htmlspecialchars($user['full_name']) ?>. Please create a password for your staff account.</p>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">Password *</label>
                    <input type="password" name="password" class="w-full border border-gray-300 rounded-lg p-2.5" required minlength="6">
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">Confirm Password *</label>
                    <input type="password" name="confirm_password" class="w-full border border-gray-300 rounded-lg p-2.5" required>
                </div>
                <button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded w-full">
                    Set Password &amp; Verify Account
                </button>
            </form>
        <?php else: ?>
            <div class="text-center">
                <p class="text-gray-600">No verification token provided.</p>
                <a href="login.php" class="text-[#0a6e2d] hover:underline">Go to Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>