<?php
/**
 * ============================================================
 * ACES System — Register Staff (Admin-only)
 * ============================================================
 * Only Super Admins can create new staff accounts.
 * - CSRF-protected
 * - Rate-limited (max 10 accounts per admin per hour)
 * - Verification email sent with 24-hour expiry
 * - Password is set by the new staff member via the link
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/send_email.php';

// ---- Admin only ----
if (!isAdmin()) {
    header('Location: ' . rtrim(aces_env('APP_URL', '../'), '/') . '/login.php');
    exit;
}

// ---- CSRF check on POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $staff_role = $_POST['staff_role'] ?? 'viewer';

    // ---- Validate ----
    if (!$full_name || !$email) {
        $error = 'Full name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($staff_role, ['admin', 'lead', 'viewer'], true)) {
        $error = 'Invalid role selected.';
    } else {
        // ---- Rate limit: max 10 accounts per admin per hour ----
        $rate_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM staff_action_log
            WHERE performed_by = ? 
              AND action = 'create' 
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $rate_stmt->execute([$_SESSION['user_id']]);
        $recent_count = (int)$rate_stmt->fetchColumn();

        if ($recent_count >= 10) {
            $error = 'Rate limit reached. You can create up to 10 staff accounts per hour. Please try again later.';
        } else {
            // ---- Check email uniqueness ----
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'A user with that email already exists.';
            } else {
                // ---- Create account ----
                try {
                    $pdo->beginTransaction();

                    // Generate token + random unguessable password placeholder
                    $token    = bin2hex(random_bytes(32));
                    $random_pw = bin2hex(random_bytes(24)); // 48-char random string
                    $hash     = password_hash($random_pw, PASSWORD_BCRYPT, ['cost' => 12]);

                    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

                    $stmt = $pdo->prepare("
                        INSERT INTO users
                        (email, password_hash, full_name, role, staff_role, is_verified, verification_token, verification_expires, is_active, created_by)
                        VALUES (?, ?, ?, 'staff', ?, 0, ?, ?, 1, ?)
                    ");
                    $stmt->execute([
                        $email,
                        $hash,
                        $full_name,
                        $staff_role,
                        $token,
                        $expires_at,
                        $_SESSION['user_id']
                    ]);

                    $new_user_id = $pdo->lastInsertId();

                    // ---- Audit log ----
                    $log_stmt = $pdo->prepare("
                        INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
                        VALUES (?, ?, 'create', ?)
                    ");
                    $log_stmt->execute([
                        $_SESSION['user_id'],
                        $new_user_id,
                        "Created staff account for {$full_name} ({$email}) with role {$staff_role}"
                    ]);

                    $pdo->commit();

                    // ---- Send verification email ----
                    $base_url = rtrim(aces_env('APP_URL', 'http://localhost/cair-system'), '/');
                    $verify_link = $base_url . '/public/verify.php?token=' . urlencode($token);

                    $subject = "ACES Staff Account - Verify Your Email";
                    $safe_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
                    $safe_role = htmlspecialchars($staff_role, ENT_QUOTES, 'UTF-8');

                    $body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px;'>
                            <h2 style='color:#0a6e2d;'>ACES Staff Account</h2>
                            <p>Dear {$safe_name},</p>
                            <p>An administrator has created a staff account for you on the ACES system.</p>
                            <p><strong>Role:</strong> {$safe_role}</p>
                            <p>Please click the button below to verify your email and set your password:</p>
                            <p style='text-align: center; margin: 24px 0;'>
                                <a href='{$verify_link}' 
                                   style='display:inline-block; padding:12px 28px; background:#0a6e2d; color:#fff; text-decoration:none; border-radius:6px; font-weight: 600;'>
                                    Verify Email &amp; Set Password
                                </a>
                            </p>
                            <p style='font-size:0.85em; color:#666;'>Or copy this link: <br>{$verify_link}</p>
                            <p style='font-size:0.8em; color:#999;'>This link will expire after 24 hours.</p>
                            <hr style='border: none; border-top: 1px solid #eee; margin: 24px 0;'>
                            <p style='font-size:0.8em; color:#666;'>ACES Unit, Kolehiyo ng Lungsod ng Dasmariñas</p>
                        </div>
                    ";

                    $result = sendEmail($email, $subject, $body);

                    if (!empty($result['success'])) {
                        $success = "Staff account created. A verification email has been sent to {$email}.";
                    } else {
                        $error = "Account created, but the verification email failed: " . ($result['message'] ?? 'Unknown error');
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("Staff creation failed: " . $e->getMessage());
                    $error = 'Failed to create staff account. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Staff | ACES</title>
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
            <div class="flex items-center gap-3 mb-2">
                <img src="../assets/images/kld_logo.png" alt="KLD" class="w-10 h-10 object-contain bg-white rounded-full p-0.5">
                <div>
                    <h1 class="text-lg font-bold">ACES Staff</h1>
                    <p class="text-xs opacity-80">Kolehiyo ng Lungsod ng Dasmariñas</p>
                </div>
            </div>
            <h2 class="text-xl font-bold mt-4">Register New Staff Account</h2>
        </div>

        <!-- Body -->
        <div class="p-8">

            <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-800 px-4 py-3 rounded-r-lg mb-5 flex items-start gap-3">
                    <i class="fas fa-check-circle text-green-500 mt-0.5"></i>
                    <span class="text-sm font-medium"><?= htmlspecialchars($success) ?></span>
                </div>
            <?php elseif ($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-800 px-4 py-3 rounded-r-lg mb-5 flex items-start gap-3">
                    <i class="fas fa-exclamation-circle text-red-500 mt-0.5"></i>
                    <span class="text-sm font-medium"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?= csrf_field() ?>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-1.5">
                        Full Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="full_name" 
                           class="form-input" 
                           placeholder="e.g. Juan Dela Cruz"
                           autocomplete="name" required>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-1.5">
                        Email Address <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="email" 
                           class="form-input" 
                           placeholder="e.g. juan.delacruz@kld.edu.ph"
                           autocomplete="email" required>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>A verification link will be sent here.
                    </p>
                </div>

                <div class="mb-6">
                    <label class="block text-gray-700 text-sm font-semibold mb-1.5">Role</label>
                    <select name="staff_role" class="form-input bg-white">
                        <option value="viewer">Viewer — Read-only access</option>
                        <option value="lead">Lead — Standard staff access</option>
                        <option value="admin">Admin — Full system access</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-shield-alt mr-1"></i>Admins can manage other staff accounts.
                    </p>
                </div>

                <button type="submit" 
                        class="w-full bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 px-4 rounded-lg transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-user-plus"></i> Create Staff Account
                </button>
            </form>

            <div class="mt-6 text-center">
                <a href="../staff/manage_staff.php" class="text-sm text-gray-500 hover:text-[#0a6e2d] font-medium">
                    <i class="fas fa-arrow-left mr-1"></i>Back to Manage Staff
                </a>
            </div>
        </div>
    </div>

</body>
</html>