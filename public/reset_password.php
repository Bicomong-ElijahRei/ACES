<?php
require_once '../config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$show_form = false;

if ($token) {
    // Validate token
    $stmt = $pdo->prepare("SELECT user_id, email FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $show_form = true;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!$password || strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif ($password !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE user_id = ?");
                $stmt->execute([$hashed, $user['user_id']]);
                $success = 'Password updated successfully. You may now <a href="../index.php" class="text-[#0a6e2d] underline">log in</a>.';
                $show_form = false;
            }
        }
    } else {
        $error = 'Invalid or expired reset link. Please request a new one.';
    }
} else {
    $error = 'No reset token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Reset Password | ACES</title>
</head>
<body class="bg-[radial-gradient(circle,_#2a5d1b_0%,_#0d1a0a_100%)] min-h-screen flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md p-8 rounded-sm shadow-2xl">
        <img src="../assets/images/kld_logo.png" alt="Logo" class="w-14 h-14 mx-auto mb-4 object-contain">
        <h2 class="text-xl font-bold text-gray-800 mb-4 text-center">Reset Password</h2>
        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($show_form): ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <input type="password" name="password" class="w-full border border-gray-400 p-2 rounded-sm text-sm" required minlength="6">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                    <input type="password" name="confirm_password" class="w-full border border-gray-400 p-2 rounded-sm text-sm" required minlength="6">
                </div>
                <button type="submit" class="w-full bg-[#00c07f] hover:bg-[#00a86f] text-white font-bold py-2 px-4 rounded-full transition-colors">
                    Update Password
                </button>
            </form>
        <?php endif; ?>
        <p class="text-center text-sm text-gray-500 mt-4"><a href="../index.php" class="text-[#0a6e2d] hover:underline">Back to Login</a></p>
    </div>
</body>
</html>