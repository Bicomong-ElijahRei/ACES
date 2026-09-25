<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/send_email.php';

if (!isAdmin()) {
    header('Location: ../login.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $staff_role = $_POST['staff_role'] ?? 'viewer';

    if (!$full_name || !$email) {
        $error = 'Full name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'A user with that email already exists.';
        } else {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role, staff_role, is_verified, verification_token, is_active, created_by)
                                   VALUES (?, ?, ?, 'staff', ?, 0, ?, 1, ?)");
            $stmt->execute([
                $email,
                password_hash('temporary', PASSWORD_DEFAULT), // placeholder password
                $full_name,
                $staff_role,
                $token,
                $_SESSION['user_id']
            ]);

            // Log the action
            $new_user_id = $pdo->lastInsertId();
            $log_stmt = $pdo->prepare("INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
                                       VALUES (?, ?, 'create', ?)");
            $log_stmt->execute([
                $_SESSION['user_id'],
                $new_user_id,
                "Created staff account for $full_name ($email) with role $staff_role"
            ]);

            // Build verification link
            $verify_link = "http://localhost/cair-system/public/verify.php?token=$token";
            $subject = "ACES Staff Account - Verify Your Email";
            $body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px;'>
                    <h2 style='color:#0a6e2d;'>ACES Staff Account</h2>
                    <p>Dear {$full_name},</p>
                    <p>An administrator has created a staff account for you on the ACES system.</p>
                    <p><strong>Role:</strong> {$staff_role}</p>
                    <p>Please click the link below to verify your email and set your password:</p>
                    <p><a href='{$verify_link}' style='display:inline-block; padding:10px 20px; background:#0a6e2d; color:#fff; text-decoration:none; border-radius:5px;'>Verify Email &amp; Set Password</a></p>
                    <p>This link will expire after 24 hours.</p>
                    <p style='font-size:0.8em; color:#666;'>ACES Unit, KLD</p>
                </div>
            ";

            $result = sendEmail($email, $subject, $body);
            if ($result['success']) {
                $success = "Staff account created. A verification email has been sent to $email.";
            } else {
                $error = "Account created, but verification email failed: " . $result['message'];
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
</head>
<body class="bg-[#dcf3e6] h-screen flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl p-8 w-full max-w-md">
        <h2 class="text-2xl font-bold text-[#0a6e2d] mb-4">Register New Staff</h2>
        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Full Name *</label>
                <input type="text" name="full_name" class="w-full border border-gray-300 rounded-lg p-2.5" required>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Email *</label>
                <input type="email" name="email" class="w-full border border-gray-300 rounded-lg p-2.5" required>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Role</label>
                <select name="staff_role" class="w-full border border-gray-300 rounded-lg p-2.5">
                    <option value="viewer">Viewer</option>
                    <option value="lead">Lead</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded w-full">
                <i class="fas fa-user-plus mr-2"></i> Create Staff Account
            </button>
        </form>
        <div class="mt-4 text-center">
            <a href="../staff/dashboard.php" class="text-sm text-gray-600 hover:text-[#0a6e2d]">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>