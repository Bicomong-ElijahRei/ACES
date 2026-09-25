<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

// Get student details with user info
$stmt = $pdo->prepare("
    SELECT s.student_id, s.section, s.program, s.middle_name, s.suffix, s.mobile, s.telephone,
           u.full_name, u.email, u.user_id
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

if (!$student) {
    die("Student record not found.");
}

$message = '';
$error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $middle_name = trim($_POST['middle_name']);
    $suffix = trim($_POST['suffix']);
    $mobile = trim($_POST['mobile']);
    $telephone = trim($_POST['telephone']);

    if (empty($full_name) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Check if email is already taken by another user
        $stmt_check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt_check->execute([$email, $student['user_id']]);
        if ($stmt_check->fetch()) {
            $error = "Email is already used by another account.";
        } else {
            // Update users table
            $stmt_upd_user = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
            $stmt_upd_user->execute([$full_name, $email, $student['user_id']]);
            // Update students table
            $stmt_upd_student = $pdo->prepare("UPDATE students SET middle_name = ?, suffix = ?, mobile = ?, telephone = ? WHERE student_id = ?");
            $stmt_upd_student->execute([$middle_name, $suffix, $mobile, $telephone, $student['student_id']]);
            $_SESSION['account_message'] = "Profile updated successfully.";
            header("Location: account.php");
            exit;
        }
    }
}

// Handle password change (same as before)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new) || empty($confirm)) {
        $error = "All password fields are required.";
    } elseif ($new !== $confirm) {
        $error = "New password and confirmation do not match.";
    } elseif (strlen($new) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {
        $stmt_check = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt_check->execute([$student['user_id']]);
        $user = $stmt_check->fetch();
        if (password_verify($current, $user['password_hash'])) {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt_update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt_update->execute([$new_hash, $student['user_id']]);
            $_SESSION['account_message'] = "Password changed successfully.";
            header("Location: account.php");
            exit;
        } else {
            $error = "Current password is incorrect.";
        }
    }
}

// Retrieve flash message
if (isset($_SESSION['account_message'])) {
    $message = $_SESSION['account_message'];
    unset($_SESSION['account_message']);
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | ACES Student</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .header { background: #fff; border-bottom: 1px solid #ddd; padding: 10px 20px; }
        .info-card, .password-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .info-label {
            font-weight: 600;
            color: #0a3e6d;
            width: 140px;
        }
    </style>
</head>
<body class="h-full">
<div class="flex h-full">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-auto">
        <div class="header d-flex justify-content-between align-items-center">
            <div><strong>KLD</strong></div>
            <div><a href="dashboard.php" class="text-dark"><i class="fas fa-home fa-lg"></i></a></div>
        </div>
        <div class="flex-1 p-6">
            <h2 class="text-2xl font-bold mb-6">Account Settings</h2>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Editable Profile Information -->
            <div class="info-card">
                <h3 class="text-lg font-semibold mb-4 pb-2 border-b border-gray-200">Profile Information</h3>
                <form method="POST">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label fw-bold">Student ID (read-only)</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($student['student_id']) ?>" readonly>
                        </div>
                        <div>
                            <label class="form-label fw-bold">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($student['full_name']) ?>" required>
                        </div>
                        <div>
                            <label class="form-label fw-bold">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($student['middle_name']) ?>">
                        </div>
                        <div>
                            <label class="form-label fw-bold">Suffix</label>
                            <input type="text" name="suffix" class="form-control" value="<?= htmlspecialchars($student['suffix']) ?>">
                        </div>
                        <div>
                            <label class="form-label fw-bold">Program (read-only)</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($student['program']) ?>" readonly>
                        </div>
                        <div>
                            <label class="form-label fw-bold">Section (read-only)</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($student['section']) ?>" readonly>
                        </div>
                        <div>
                            <label class="form-label fw-bold">Email *</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($student['email']) ?>" required>
                        </div>
                        <div>
                            <label class="form-label fw-bold">Mobile Number</label>
                            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($student['mobile']) ?>">
                        </div>
                        <div>
                            <label class="form-label fw-bold">Telephone</label>
                            <input type="text" name="telephone" class="form-control" value="<?= htmlspecialchars($student['telephone']) ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </div>
                </form>
            </div>

            <!-- Change Password (same as before) -->
            <div class="password-card">
                <h3 class="text-lg font-semibold mb-4 pb-2 border-b border-gray-200">Change Password</h3>
                <form method="POST" class="max-w-md">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password (min. 6 characters)</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>