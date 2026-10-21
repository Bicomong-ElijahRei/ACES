<?php
require_once 'config/env.php';
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
// Add your specific auth redirect here if needed

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = trim($_POST['pin']);
    $user_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("SELECT pending_email, email_change_pin, email_pin_expiry FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email_change_pin'])) {
        $error = "No email change request found.";
    } elseif ($user['email_pin_expiry'] < date('Y-m-d H:i:s')) {
        $error = "The verification PIN has expired. Please try again.";
    } elseif ($user['email_change_pin'] !== $pin) {
        $error = "Incorrect PIN. Please check your email and try again.";
    } else {
        $stmt_upd = $pdo->prepare("UPDATE users SET email = pending_email, pending_email = NULL, email_change_pin = NULL, email_pin_expiry = NULL WHERE user_id = ?");
        $stmt_upd->execute([$user_id]);
        $success = "Email successfully updated to " . htmlspecialchars($user['pending_email']);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify Email Change</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="bg-gray-100 h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h2 class="text-2xl font-bold text-center mb-6">Enter Verification PIN</h2>
        <?php if ($error): ?><div class="bg-red-50 text-red-700 p-3 rounded mb-4"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-50 text-green-700 p-3 rounded mb-4">
                <?= $success ?><br><br>
                <a href="<?= $_SESSION['role'] === 'staff' ? 'staff/account.php' : 'student/account.php' ?>" class="font-bold underline">Return to Account</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <?= csrf_field() ?>
                <p class="text-sm text-gray-600 mb-4">We sent a 6-digit PIN to your new email address. It expires in 15 minutes.</p>
                <input type="text" name="pin" maxlength="6" placeholder="000000" required class="w-full text-center text-2xl tracking-widest border border-gray-300 rounded-lg p-3 mb-4 focus:ring-2 focus:ring-green-500">
                <button type="submit" class="w-full bg-[#0a6e2d] text-white font-bold py-3 rounded-lg">Verify & Change Email</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>