<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

$success = '';
$error = '';
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    $error = "No verification token provided.";
} else {
    // Fetch user and student details by token
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.full_name, u.is_verified, u.password_hash, s.student_id, s.program, s.section, s.middle_name, s.first_name, s.last_name 
        FROM users u 
        LEFT JOIN students s ON u.user_id = s.user_id 
        WHERE u.verification_token = ?
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = "Invalid or expired verification link.";
    } elseif ($user['is_verified'] == 1) {
        $error = "This account is already verified. You can log in.";
    } else {
        // --- NAME PARSING LOGIC ---
        // We now pull directly from the new first_name and last_name columns
        $first_name_db = trim($user['first_name'] ?? '');
        $last_name_db = trim($user['last_name'] ?? '');
        $middle_name_db = trim($user['middle_name'] ?? '');

        $is_first_missing = empty($first_name_db);
        $is_last_missing = empty($last_name_db);
        // ---------------------------
        // Check if this user was imported via CSV (they have the default 'password' hash)
        // If they do NOT have the default password, they registered manually and already have a password!
        $is_imported = password_verify('password', $user['password_hash']);

        if (!$is_imported) {
            // AUTO-VERIFY MANUAL REGISTRANTS
            $update = $pdo->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE user_id = ?");
            $update->execute([$user['user_id']]);
            
            $success = "Email verified successfully! You can now log in with the password you created during registration.";
            $user = null; // Hide the form and show success message
        }
    }
}

// Handle Password Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && $user['is_verified'] == 0) {
    csrf_verify();
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Robust Server-Side Validation matching the JS
    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter (A-Z).";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter (a-z).";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number (0-9).";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        // 1. Update the missing fields in the students table
        $update_fields = [];
        $update_params = [];

        if (isset($_POST['student_id']) && !empty(trim($_POST['student_id']))) {
            $update_fields[] = "student_id = ?";
            $update_params[] = trim($_POST['student_id']);
        }
        if (isset($_POST['program']) && !empty(trim($_POST['program']))) {
            $update_fields[] = "program = ?";
            $update_params[] = trim($_POST['program']);
        }
        if (isset($_POST['section']) && !empty(trim($_POST['section']))) {
            $update_fields[] = "section = ?";
            $update_params[] = trim($_POST['section']);
        }

        if (!empty($update_fields)) {
            $update_params[] = $user['user_id'];
            $stmt_student = $pdo->prepare("UPDATE students SET " . implode(", ", $update_fields) . " WHERE user_id = ?");
            $stmt_student->execute($update_params);
        }

        // 2. Update the missing name in the users and students tables
        $new_first = trim($_POST['first_name'] ?? '');
        $new_last = trim($_POST['last_name'] ?? '');
        
        $final_first = !empty($new_first) ? $new_first : $first_name_db;
        $final_last = !empty($new_last) ? $new_last : $last_name_db;
        $final_middle = $middle_name_db;
        
        $new_name = trim("$final_first $final_middle $final_last");
        if (!empty($new_name) && $new_name !== $user['full_name']) {
            // Update the display name
            $stmt_user_name = $pdo->prepare("UPDATE users SET full_name = ? WHERE user_id = ?");
            $stmt_user_name->execute([$new_name, $user['user_id']]);
            
            // Update the explicit columns in the students table
            $stmt_student_name = $pdo->prepare("UPDATE students SET first_name = ?, last_name = ? WHERE user_id = ?");
            $stmt_student_name->execute([$final_first, $final_last, $user['user_id']]);
        }
        
        // 3. Update user to verified and set their new password
        $update = $pdo->prepare("UPDATE users SET password_hash = ?, is_verified = 1, verification_token = NULL WHERE user_id = ?");
        $update->execute([$hash, $user['user_id']]);
        
        $success = "Account verified successfully! You can now log in with your new password.";
        $user = null; // Hide the form
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account | ACES</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl border border-gray-200 w-full max-w-lg overflow-hidden">
        <div class="bg-[#0a6e2d] p-6 text-center">
            <h2 class="text-2xl font-bold text-white mb-1"><i class="fas fa-shield-check mr-2"></i>Student Verification</h2>
            <?php if ($user): ?>
                <p class="text-green-100 text-sm">Welcome, <?= htmlspecialchars($user['full_name']) ?>!</p>
            <?php endif; ?>
        </div>
        
        <div class="p-8">
            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-lg text-center mb-6">
                    <i class="fas fa-check-circle text-3xl mb-2 block"></i>
                    <p class="font-bold"><?= $success ?></p>
                </div>
                <a href="../login.php" class="block w-full text-center bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 rounded-lg transition">Go to Login</a>
            
            <?php elseif ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-lg text-center mb-6">
                    <i class="fas fa-exclamation-circle text-3xl mb-2 block"></i>
                    <p class="font-bold"><?= $error ?></p>
                </div>
                <a href="../login.php" class="block w-full text-center bg-gray-600 hover:bg-gray-700 text-white font-bold py-3 rounded-lg transition">Go to Login</a>

            <?php elseif ($user): ?>
                <form method="POST" class="space-y-4">
                    <?= csrf_field() ?>
                    
                    <?php 
                    // Check for missing fields
                    $is_id_missing = (empty($user['student_id']) || strpos($user['student_id'], 'TEMP-') === 0);
                    $is_program_missing = (empty($user['program']) || $user['program'] === 'Unassigned');
                    $is_section_missing = (empty($user['section']) || $user['section'] === 'Unassigned');
                    $is_name_missing = (empty($user['full_name']) || $user['full_name'] === explode('@', $user['email'])[0]);
                    $has_missing = $is_id_missing || $is_program_missing || $is_section_missing || $is_name_missing;
                    ?>

                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Your Profile Details</h4>
                            <?php if ($has_missing): ?>
                                <span class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded font-bold"><i class="fas fa-edit mr-1"></i>Action Required</span>
                            <?php else: ?>
                                <span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded font-bold"><i class="fas fa-lock mr-1"></i>Read Only</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($has_missing): ?>
                            <p class="text-xs text-amber-600 mb-3 font-medium"><i class="fas fa-exclamation-triangle mr-1"></i> Some details were not provided by the ACES Staff. Please complete them below to finish your profile.</p>
                        <?php else: ?>
                            <p class="text-xs text-gray-500 mb-3">These details were imported by the ACES Staff. You can update them in your Account Settings after logging in.</p>
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-2 gap-3">
                            <!-- NAME -->
                            <div class="col-span-2 grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs <?= $is_first_missing ? 'text-amber-700 font-bold' : 'text-gray-500' ?>">First Name <?= $is_first_missing ? '<span class="text-red-500">*</span>' : '' ?></label>
                                    <?php if ($is_first_missing): ?>
                                        <input type="text" name="first_name" required class="w-full bg-white border border-amber-300 rounded p-2 text-sm focus:ring-amber-500">
                                    <?php else: ?>
                                        <input type="text" value="<?= htmlspecialchars($first_name_db) ?>" disabled class="w-full bg-gray-100 border border-gray-300 rounded p-2 text-sm text-gray-600 cursor-not-allowed">
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="text-xs <?= $is_last_missing ? 'text-amber-700 font-bold' : 'text-gray-500' ?>">Last Name <?= $is_last_missing ? '<span class="text-red-500">*</span>' : '' ?></label>
                                    <?php if ($is_last_missing): ?>
                                        <input type="text" name="last_name" required class="w-full bg-white border border-amber-300 rounded p-2 text-sm focus:ring-amber-500">
                                    <?php else: ?>
                                        <input type="text" value="<?= htmlspecialchars($last_name_db) ?>" disabled class="w-full bg-gray-100 border border-gray-300 rounded p-2 text-sm text-gray-600 cursor-not-allowed">
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- STUDENT ID -->
                            <div>
                                <label class="text-xs <?= $is_id_missing ? 'text-amber-700 font-bold' : 'text-gray-500' ?>">Student ID <?= $is_id_missing ? '<span class="text-red-500">*</span>' : '' ?></label>
                                <?php if ($is_id_missing): ?>
                                    <input type="text" name="student_id" required placeholder="e.g. 2023-1-99999" class="w-full bg-white border border-amber-300 rounded p-2 text-sm focus:ring-amber-500">
                                <?php else: ?>
                                    <input type="text" value="<?= htmlspecialchars($user['student_id']) ?>" disabled class="w-full bg-gray-100 border border-gray-300 rounded p-2 text-sm text-gray-600 cursor-not-allowed">
                                <?php endif; ?>
                            </div>

                            <!-- PROGRAM -->
                            <div>
                                <label class="text-xs <?= $is_program_missing ? 'text-amber-700 font-bold' : 'text-gray-500' ?>">Program <?= $is_program_missing ? '<span class="text-red-500">*</span>' : '' ?></label>
                                <?php if ($is_program_missing): ?>
                                    <input type="text" name="program" required placeholder="e.g. BS Information Systems" class="w-full bg-white border border-amber-300 rounded p-2 text-sm focus:ring-amber-500">
                                <?php else: ?>
                                    <input type="text" value="<?= htmlspecialchars($user['program']) ?>" disabled class="w-full bg-gray-100 border border-gray-300 rounded p-2 text-sm text-gray-600 cursor-not-allowed">
                                <?php endif; ?>
                            </div>

                            <!-- SECTION -->
                            <div class="col-span-2">
                                <label class="text-xs <?= $is_section_missing ? 'text-amber-700 font-bold' : 'text-gray-500' ?>">Section <?= $is_section_missing ? '<span class="text-red-500">*</span>' : '' ?></label>
                                <?php if ($is_section_missing): ?>
                                    <input type="text" name="section" required placeholder="e.g. 401" class="w-full bg-white border border-amber-300 rounded p-2 text-sm focus:ring-amber-500">
                                <?php else: ?>
                                    <input type="text" value="<?= htmlspecialchars($user['section']) ?>" disabled class="w-full bg-gray-100 border border-gray-300 rounded p-2 text-sm text-gray-600 cursor-not-allowed">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-gray-100">
                        <h4 class="text-sm font-bold text-gray-800 mb-3">Set Your Password</h4>
                        <div class="space-y-4">
                            
                            <!-- PASSWORD INPUT WITH TOGGLE AND STRENGTH METER -->
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">New Password</label>
                                <div class="relative">
                                    <input type="password" name="password" id="password_input" required minlength="8" class="w-full border border-gray-300 rounded-lg p-2.5 pr-10 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                    <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none toggle-password" data-target="password_input">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                
                                <!-- STRENGTH METER -->
                                <div class="mt-2 flex gap-1 h-1.5">
                                    <div class="flex-1 rounded-full bg-gray-200 transition-colors" id="strength-1"></div>
                                    <div class="flex-1 rounded-full bg-gray-200 transition-colors" id="strength-2"></div>
                                    <div class="flex-1 rounded-full bg-gray-200 transition-colors" id="strength-3"></div>
                                    <div class="flex-1 rounded-full bg-gray-200 transition-colors" id="strength-4"></div>
                                </div>
                                <p class="text-[11px] text-gray-500 mt-1" id="strength-text">Min. 8 chars, 1 upper, 1 lower, 1 number.</p>
                            </div>

                            <!-- CONFIRM PASSWORD INPUT WITH TOGGLE -->
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Confirm Password</label>
                                <div class="relative">
                                    <input type="password" name="confirm_password" id="confirm_password_input" required minlength="8" class="w-full border border-gray-300 rounded-lg p-2.5 pr-10 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                    <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none toggle-password" data-target="confirm_password_input">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 rounded-lg transition shadow-sm mt-4">
                        <i class="fas fa-check-circle mr-2"></i> Activate My Account
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // --- PASSWORD TOGGLE VISIBILITY ---
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
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

        // --- PASSWORD STRENGTH METER LOGIC ---
        const passwordInput = document.getElementById('password_input');
        const submitBtn = document.querySelector('button[type="submit"]');
        const strengthBars = [
            document.getElementById('strength-1'),
            document.getElementById('strength-2'),
            document.getElementById('strength-3'),
            document.getElementById('strength-4')
        ];
        const strengthText = document.getElementById('strength-text');

        // Initially disable the submit button until password is strong enough
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const val = this.value;
                let score = 0;

                // 4 Criteria for a "Strong" password
                if (val.length >= 8) score++;
                if (/[A-Z]/.test(val)) score++;
                if (/[a-z]/.test(val)) score++;
                if (/[0-9]/.test(val)) score++;

                // Reset bars
                strengthBars.forEach(bar => bar.className = 'flex-1 rounded-full bg-gray-200 transition-colors');

                let colorClass = '';
                let text = '';
                let canSubmit = false;

                if (val.length === 0) {
                    text = 'Min. 8 chars, 1 upper, 1 lower, 1 number.';
                    strengthText.className = 'text-[11px] text-gray-500 mt-1';
                } else if (score <= 1) {
                    colorClass = 'bg-red-500';
                    text = 'Weak - Too short or missing criteria';
                    strengthText.className = 'text-[11px] text-red-500 mt-1 font-bold';
                } else if (score === 2 || score === 3) {
                    colorClass = 'bg-amber-500';
                    text = 'Normal - Keep going!';
                    strengthText.className = 'text-[11px] text-amber-500 mt-1 font-bold';
                } else if (score === 4) {
                    colorClass = 'bg-green-500';
                    text = 'Strong - Good to go!';
                    strengthText.className = 'text-[11px] text-green-600 mt-1 font-bold';
                    canSubmit = true; // All criteria met
                }

                // Fill the bars based on the score
                for (let i = 0; i < score; i++) {
                    strengthBars[i].classList.remove('bg-gray-200');
                    strengthBars[i].classList.add(colorClass);
                }

                strengthText.textContent = text;

                // Enable or disable the submit button
                if (submitBtn) {
                    submitBtn.disabled = !canSubmit;
                    if (!canSubmit) {
                        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                }
            });
        }

        // Client-side password match check AND button buffering
        document.querySelector('form').addEventListener('submit', function(e) {
            let password = document.getElementById('password_input').value;
            let confirm = document.getElementById('confirm_password_input').value;
            
            if (password !== confirm) {
                alert('Passwords do not match.');
                e.preventDefault(); // Stop submission
                return false;
            } else {
                // Passwords match! Disable the button so they can't double-click
                const btn = this.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Activating...';
                    btn.classList.add('opacity-75', 'cursor-not-allowed');
                }
            }
        });
    </script>
</body>
</html>