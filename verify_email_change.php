<?php
require_once 'config/env.php';
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Catch the flash message from account.php
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Fetch current user data
$stmt = $pdo->prepare("SELECT pending_email, email_change_pin, email_pin_expiry FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Check if there is a pending request
if (!$user || empty($user['email_change_pin'])) {
    $error = "No email change request found. If you already verified, your email is updated. If not, please try again from Account Settings.";
    $form_allowed = false;
} else {
    $form_allowed = true;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pin = trim($_POST['pin']);

        if ($user['email_pin_expiry'] < date('Y-m-d H:i:s')) {
            $error = "The verification PIN has expired. Please try again.";
        } elseif ($user['email_change_pin'] !== $pin) {
            $error = "Incorrect PIN. Please check your email and try again.";
        } else {
            // Success
            $stmt_upd = $pdo->prepare("UPDATE users SET email = pending_email, pending_email = NULL, email_change_pin = NULL, email_pin_expiry = NULL WHERE user_id = ?");
            $stmt_upd->execute([$user_id]);
            $success = "Email successfully updated to " . htmlspecialchars($user['pending_email']);
            $form_allowed = false; 
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify Email Change</title>
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100 h-screen flex items-center justify-center p-4">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h2 class="text-2xl font-bold text-center mb-6">Enter Verification PIN</h2>
        
        <?php if ($flash): ?>
            <div class="bg-blue-50 text-blue-700 p-4 rounded-lg mb-4 text-sm border border-blue-200">
                <i class="fas fa-info-circle mr-2"></i><?= htmlspecialchars($flash['text']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-4 text-sm border border-red-200">
                <i class="fas fa-exclamation-circle mr-2"></i><?= $error ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-50 text-green-700 p-4 rounded-lg mb-4 text-sm border border-green-200">
                <i class="fas fa-check-circle mr-2"></i><?= $success ?>
                <br><br>
                <a href="<?= $_SESSION['role'] === 'staff' ? 'staff/account.php' : 'student/account.php' ?>" class="inline-block bg-[#0a6e2d] text-white font-bold px-4 py-2 rounded-lg mt-2 w-full text-center hover:bg-[#054018]">Return to Account</a>
            </div>
        <?php elseif ($form_allowed): ?>
            <form method="POST">
                <?= csrf_field() ?>
                <p class="text-sm text-gray-600 mb-4">We sent a 6-digit PIN to your new email address (<strong><?= htmlspecialchars($user['pending_email']) ?></strong>). It expires in 15 minutes.</p>
                <input type="text" name="pin" maxlength="6" placeholder="000000" required class="w-full text-center text-2xl tracking-widest border border-gray-300 rounded-lg p-3 mb-4 focus:ring-2 focus:ring-green-500 font-mono">
                <button type="submit" class="w-full bg-[#0a6e2d] text-white font-bold py-3 rounded-lg hover:bg-[#054018] transition">Verify & Change Email</button>
            </form>
        <?php else: ?>
            <a href="<?= $_SESSION['role'] === 'staff' ? 'staff/account.php' : 'student/account.php' ?>" class="block w-full text-center bg-gray-100 border border-gray-300 text-gray-700 font-bold py-3 rounded-lg hover:bg-gray-200 transition">Back to Account Settings</a>
        <?php endif; ?>
    </div>
</body>
</html>