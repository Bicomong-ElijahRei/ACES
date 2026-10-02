<?php
/**
 * ============================================================
 * ACES System — Verify Staff Account (Token → Set Password)
 * ============================================================
 * Handles:
 *   - Link from verification email
 *   - Token validation + expiry check (24 hours)
 *   - Password set + account verification
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$token   = trim($_GET['token'] ?? '');
$success = false;
$error   = '';
$user    = null;

if ($token === '') {
    $error = 'No verification token provided. Please use the link from your email.';
} else {
    $stmt = $pdo->prepare("
        SELECT user_id, email, full_name, role, staff_role, is_verified, verification_expires
        FROM users
        WHERE verification_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = 'Invalid verification link. Please contact an administrator.';
    } elseif ($user['is_verified']) {
        $error = 'This account has already been verified. You can log in below.';
        $user = null;
    } elseif (!empty($user['verification_expires']) && strtotime($user['verification_expires']) < time()) {
        $error = 'This verification link has expired. Please ask an administrator to send a new one.';
        $user = null;
    }
}

// ---- Handle password submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && !$user['is_verified']) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare("
            UPDATE users
            SET password_hash = ?,
                is_verified = 1,
                verification_token = NULL,
                verification_expires = NULL
            WHERE user_id = ?
        ");
        $stmt->execute([$hash, $user['user_id']]);

        // Audit log
        try {
            $log = $pdo->prepare("
                INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
                VALUES (?, ?, 'verify', 'Staff verified own email and set password')
            ");
            $log->execute([$user['user_id'], $user['user_id']]);
        } catch (Exception $e) {
            error_log('Audit log failed on verify: ' . $e->getMessage());
        }

        $success = true;
        $user = null; // prevent form re-render
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: radial-gradient(circle, #2a5d1b 0%, #0d1a0a 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .form-input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.15s;
        }
        .form-input:focus {
            outline: none;
            border-color: #0a6e2d;
            box-shadow: 0 0 0 3px rgba(10,110,45,0.1);
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">

        <!-- Header -->
        <div class="bg-[#0a6e2d] px-8 py-6 text-white">
            <div class="flex items-center gap-3">
                <img src="../assets/images/kld_logo.png" alt="KLD" class="w-10 h-10 object-contain bg-white rounded-full p-0.5">
                <div>
                    <h1 class="text-lg font-bold">ACES Staff</h1>
                    <p class="text-xs opacity-80">Kolehiyo ng Lungsod ng Dasmariñas</p>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="p-8">

            <?php if ($success): ?>
                <!-- SUCCESS STATE -->
                <div class="text-center">
                    <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-600 text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-[#0a6e2d] mb-2">Account Verified!</h2>
                    <p class="text-gray-600 text-sm mb-6">
                        Your password has been set and your email is verified. You can now log in.
                    </p>
                    <a href="../login.php"
                       class="inline-flex items-center gap-2 bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 px-6 rounded-lg transition">
                        <i class="fas fa-sign-in-alt"></i> Go to Login
                    </a>
                </div>

            <?php elseif ($error): ?>
                <!-- ERROR STATE -->
                <div class="text-center">
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-exclamation-circle text-red-500 text-3xl"></i>
                    </div>
                    <h2 class="text-xl font-bold text-red-700 mb-2">Verification Failed</h2>
                    <p class="text-gray-600 text-sm mb-6"><?= htmlspecialchars($error) ?></p>
                    <a href="../login.php"
                       class="inline-flex items-center gap-2 bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 px-6 rounded-lg transition">
                        <i class="fas fa-sign-in-alt"></i> Go to Login
                    </a>
                </div>

            <?php elseif ($user): ?>
                <!-- SET PASSWORD FORM -->
                <div class="mb-5">
                    <h2 class="text-xl font-bold text-[#0a6e2d] mb-1">Set Your Password</h2>
                    <p class="text-gray-600 text-sm">
                        Welcome, <strong><?= htmlspecialchars($user['full_name']) ?></strong>!
                        Create a password for your staff account.
                    </p>
                    <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
                        <i class="fas fa-envelope text-gray-400"></i>
                        <span><?= htmlspecialchars($user['email']) ?></span>
                    </div>
                </div>

                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-semibold mb-1.5">
                            New Password <span class="text-red-500">*</span>
                        </label>
                        <input type="password"
                               name="password"
                               class="form-input"
                               placeholder="At least 6 characters"
                               required minlength="6"
                               autocomplete="new-password">
                    </div>

                    <div class="mb-5">
                        <label class="block text-gray-700 text-sm font-semibold mb-1.5">
                            Confirm Password <span class="text-red-500">*</span>
                        </label>
                        <input type="password"
                               name="confirm_password"
                               class="form-input"
                               placeholder="Re-enter your password"
                               required autocomplete="new-password">
                    </div>

                    <button type="submit"
                            class="w-full bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 px-4 rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fas fa-lock"></i> Set Password &amp; Verify Account
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </div>

</body>
</html>