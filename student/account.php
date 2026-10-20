<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStudent();

// Fetch student details
$stmt = $pdo->prepare("
    SELECT u.full_name, u.email, s.student_id, s.program, s.section, s.middle_name, s.suffix, s.telephone, s.mobile 
    FROM users u 
    JOIN students s ON u.user_id = s.user_id 
    WHERE s.student_id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: ../login.php');
    exit;
}

$success = '';
$error = '';

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    csrf_verify();
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $telephone = trim($_POST['telephone']);

    if (!$full_name || !$email) {
        $error = "Full Name and Email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $pdo->beginTransaction();
            // Update users table
            $stmt_upd_u = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
            $stmt_upd_u->execute([$full_name, $email, $_SESSION['student_id']]);
            
            // Update students table
            $stmt_upd_s = $pdo->prepare("UPDATE students SET mobile = ?, telephone = ? WHERE student_id = ?");
            $stmt_upd_s->execute([$mobile, $telephone, $_SESSION['student_id']]);
            
            $pdo->commit();
            $success = "Profile updated successfully.";
            
            // Refresh data
            $student['full_name'] = $full_name;
            $student['email'] = $email;
            $student['mobile'] = $mobile;
            $student['telephone'] = $telephone;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Update failed: " . $e->getMessage();
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    csrf_verify();
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (!$current || !$new || !$confirm) {
        $error = "All password fields are required.";
    } elseif ($new !== $confirm) {
        $error = "New passwords do not match.";
    } elseif (strlen($new) < 8) {
        $error = "New password must be at least 8 characters.";
    } else {
        $stmt_pw = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
        $stmt_pw->execute([$_SESSION['student_id']]);
        $hash = $stmt_pw->fetchColumn();

        if (!password_verify($current, $hash)) {
            $error = "Current password is incorrect.";
        } else {
            $new_hash = password_hash($new, PASSWORD_BCRYPT);
            $stmt_upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
            $stmt_upd->execute([$new_hash, $_SESSION['student_id']]);
            $success = "Password changed successfully.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | ACES Student</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Password Strength Meter */
        .strength-meter { height: 4px; border-radius: 2px; transition: all 0.3s; }
        .strength-weak { background-color: #ef4444; width: 25%; }
        .strength-fair { background-color: #f59e0b; width: 50%; }
        .strength-good { background-color: #3b82f6; width: 75%; }
        .strength-strong { background-color: #10b981; width: 100%; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/student_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/student_header.php'; ?>
    
    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-[#0a6e2d]">Account Settings</h1>
            <p class="text-sm text-gray-600 mt-1">Manage your personal information and security preferences.</p>
        </div>

        <!-- Flash Messages -->
        <?php if ($success): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                <button type="button" class="ml-auto text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php elseif ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="ml-auto text-red-700 hover:text-red-900" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <div class="max-w-4xl mx-auto space-y-8">
            
            <!-- Section 1: Profile Information -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50/50 flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-[#0a6e2d]/10 flex items-center justify-center text-[#0a6e2d]">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Profile Information</h3>
                        <p class="text-xs text-gray-500">Update your contact details. Academic details are locked.</p>
                    </div>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="p-6 space-y-6">
                        
                        <!-- Read-only Academic Info -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Student ID</label>
                            </div>
                            <div class="md:col-span-2">
                                <input type="text" value="<?= htmlspecialchars($student['student_id']) ?>" class="w-full border border-gray-200 rounded-lg p-2.5 bg-gray-100 text-gray-500 cursor-not-allowed font-mono text-sm" readonly>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Program</label>
                            </div>
                            <div class="md:col-span-2">
                                <input type="text" value="<?= htmlspecialchars($student['program']) ?>" class="w-full border border-gray-200 rounded-lg p-2.5 bg-gray-100 text-gray-500 cursor-not-allowed text-sm" readonly>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Section</label>
                            </div>
                            <div class="md:col-span-2">
                                <input type="text" value="<?= htmlspecialchars($student['section']) ?>" class="w-full border border-gray-200 rounded-lg p-2.5 bg-gray-100 text-gray-500 cursor-not-allowed text-sm" readonly>
                            </div>
                        </div>

                        <div class="border-t border-gray-100 my-2"></div>

                        <!-- Editable Info -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Full Name <span class="text-red-500">*</span></label>
                            </div>
                            <div class="md:col-span-2">
                                <input type="text" name="full_name" value="<?= htmlspecialchars($student['full_name']) ?>" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow text-sm" required>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Email Address <span class="text-red-500">*</span></label>
                            </div>
                            <div class="md:col-span-2">
                                <input type="email" name="email" value="<?= htmlspecialchars($student['email']) ?>" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow text-sm" required>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Mobile Number</label>
                            </div>
                            <div class="md:col-span-2">
                                <input type="text" name="mobile" value="<?= htmlspecialchars($student['mobile'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Telephone Number</label>
                            </div>
                            <div class="md:col-span-2">
                                <input type="text" name="telephone" value="<?= htmlspecialchars($student['telephone'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow text-sm">
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end">
                        <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium py-2 px-6 rounded-lg transition-colors shadow-sm text-sm">
                            <i class="fas fa-save mr-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Section 2: Security -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50/50 flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-amber-100 flex items-center justify-center text-amber-600">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Security</h3>
                        <p class="text-xs text-gray-500">Ensure your account is using a long, random password to stay secure.</p>
                    </div>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="p-6 space-y-6">
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Current Password</label>
                            </div>
                            <div class="md:col-span-2 relative">
                                <input type="password" name="current_password" id="current_password" class="w-full border border-gray-300 rounded-lg p-2.5 pr-10 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow text-sm" required>
                                <button type="button" class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600 toggle-password" data-target="current_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">New Password</label>
                                <p class="text-xs text-gray-500 mt-1">Minimum 8 characters.</p>
                            </div>
                            <div class="md:col-span-2">
                                <div class="relative">
                                    <input type="password" name="new_password" id="new_password" class="w-full border border-gray-300 rounded-lg p-2.5 pr-10 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow text-sm" required minlength="8">
                                    <button type="button" class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600 toggle-password" data-target="new_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <!-- Strength Meter -->
                                <div class="mt-2 flex gap-1 h-1">
                                    <div class="flex-1 rounded-full bg-gray-200" id="strength-1"></div>
                                    <div class="flex-1 rounded-full bg-gray-200" id="strength-2"></div>
                                    <div class="flex-1 rounded-full bg-gray-200" id="strength-3"></div>
                                    <div class="flex-1 rounded-full bg-gray-200" id="strength-4"></div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1" id="strength-text">Enter a password</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Confirm Password</label>
                            </div>
                            <div class="md:col-span-2 relative">
                                <input type="password" name="confirm_password" id="confirm_password" class="w-full border border-gray-300 rounded-lg p-2.5 pr-10 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow text-sm" required minlength="8">
                                <button type="button" class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600 toggle-password" data-target="confirm_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end">
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-medium py-2 px-6 rounded-lg transition-colors shadow-sm text-sm">
                            <i class="fas fa-key mr-1"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Password Visibility Toggles ---
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });

    // --- Password Strength Meter ---
    const newPassword = document.getElementById('new_password');
    const strengthBars = [
        document.getElementById('strength-1'),
        document.getElementById('strength-2'),
        document.getElementById('strength-3'),
        document.getElementById('strength-4')
    ];
    const strengthText = document.getElementById('strength-text');

    newPassword.addEventListener('input', function() {
        const val = this.value;
        let score = 0;
        
        if (val.length > 0) score = 1;
        if (val.length >= 8) score = 2;
        if (val.length >= 8 && /[A-Z]/.test(val) && /[0-9]/.test(val)) score = 3;
        if (val.length >= 10 && /[A-Z]/.test(val) && /[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) score = 4;

        // Reset bars
        strengthBars.forEach(bar => {
            bar.className = 'flex-1 rounded-full bg-gray-200';
        });

        // Update bars based on score
        const colors = ['bg-red-500', 'bg-amber-500', 'bg-blue-500', 'bg-green-500'];
        for (let i = 0; i < score; i++) {
            strengthBars[i].classList.remove('bg-gray-200');
            strengthBars[i].classList.add(colors[i]);
        }

        // Update text
        const texts = ['Enter a password', 'Weak', 'Fair', 'Good', 'Strong'];
        strengthText.textContent = texts[score];
    });
});
</script>
</body>
</html>