<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStudent();

// Fetch student details (Added last_id_change)
$stmt = $pdo->prepare("
    SELECT u.full_name, u.email, u.pending_email, s.student_id, s.program, s.section, s.middle_name, s.suffix, s.telephone, s.mobile, s.last_id_change 
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

// --- Parse Name into separate fields ---
$full_name_raw = $student['full_name'] ?? '';
$middle_name = $student['middle_name'] ?? '';
$suffix = $student['suffix'] ?? '';

$parts = explode(' ', trim($full_name_raw));
if (!empty($middle_name)) {
    $middle_parts = explode(' ', trim($middle_name));
    foreach ($middle_parts as $mp) {
        $key = array_search($mp, $parts);
        if ($key !== false) unset($parts[$key]);
    }
    $parts = array_values($parts);
}
if (!empty($suffix) && ($key = array_search($suffix, $parts)) !== false) {
    unset($parts[$key]);
    $parts = array_values($parts);
}
$last_name = '';
$first_name = '';
if (count($parts) >= 2) {
    $last_name = array_pop($parts);
    $first_name = implode(' ', $parts);
} elseif (count($parts) == 1) {
    $last_name = $parts[0];
}
// ----------------------------------------

$success = '';
$error = '';

// Handle Canceling the Email Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_email_change') {
    csrf_verify();
    $stmt_cancel = $pdo->prepare("UPDATE users SET pending_email = NULL, email_change_pin = NULL, email_pin_expiry = NULL WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
    $stmt_cancel->execute([$_SESSION['student_id']]);
    $success = "Pending email change request has been canceled.";
    // Refresh the local student data
    $student['pending_email'] = null;
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    csrf_verify();
    
    // Combine separate name fields into full_name
    $first_name  = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name   = trim($_POST['last_name']);
    $suffix      = trim($_POST['suffix']);
    $full_name   = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name . ($suffix ? ' ' . $suffix : ''));

    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $telephone = trim($_POST['telephone']);
    $new_student_id = trim($_POST['student_id']);

    if (!$full_name || !$email || !$new_student_id) {
        $error = "Full Name, Email, and Student ID are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!str_ends_with(strtolower($email), '@kld.edu.ph')) {
        $error = "Email must be an official KLD email (must end in @kld.edu.ph).";
    } elseif (!preg_match('/^[a-zA-Z0-9\-]+$/', $new_student_id)) {
        $error = "Student ID can only contain letters, numbers, and dashes.";
    } else {
        // Check for email duplicates
        $stmt_dup = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != (SELECT user_id FROM students WHERE student_id = ?)");
        $stmt_dup->execute([$email, $_SESSION['student_id']]);
        
        if ($stmt_dup->fetch()) {
            $error = "That email address is already in use by another account.";
        } else {
            // --- 24-HOUR COOLDOWN CHECK ---
            $id_changed = ($new_student_id !== $student['student_id']);
            if ($id_changed && !empty($student['last_id_change'])) {
                $last_change = strtotime($student['last_id_change']);
                if (time() - $last_change < 86400) { // 86400 seconds = 24 hours
                    $hours_left = ceil((86400 - (time() - $last_change)) / 3600);
                    $error = "You can only change your Student ID once every 24 hours. Please wait {$hours_left} hour(s) or contact the ACES Unit.";
                    $id_changed = false; // Prevent the update logic from running
                }
            }

            if (!$error) {
                try {
                    $pdo->beginTransaction();

                    // 1. Handle Email Change (PIN Logic)
                    if ($email !== $student['email']) {
                        
                        // Check if there is ALREADY a pending request for this EXACT email
                        if (!empty($student['pending_email']) && strtolower($email) === strtolower($student['pending_email'])) {
                            $_SESSION['flash'] = ['type' => 'info', 'text' => 'A verification PIN was already sent to this email address. Please check your inbox.'];
                            header("Location: ../verify_email_change.php");
                            exit;
                        }

                        $pin = sprintf("%06d", mt_rand(1, 999999));
                        $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        $stmt_pin = $pdo->prepare("UPDATE users SET pending_email = ?, email_change_pin = ?, email_pin_expiry = ? WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
                        $stmt_pin->execute([$email, $pin, $expiry, $_SESSION['student_id']]);
                        
                        require_once __DIR__ . '/../includes/send_email.php';
                        $subject = "ACES - Verify Your New Email Address";
                        $body = "<p>Your 6-digit verification PIN is: <strong style='font-size:24px;'>{$pin}</strong></p><p>This PIN expires in 15 minutes.</p>";
                        sendEmail($email, $subject, $body);
                        
                        // CRITICAL FIX: Save the PIN to the database BEFORE redirecting!
                        $pdo->commit(); 

                        $_SESSION['flash'] = ['type' => 'info', 'text' => 'A verification PIN has been sent to your new email address.'];
                        header("Location: ../verify_email_change.php");
                        exit;
                    }

                    // 2. Handle Student ID Change (Dangerous FK Updates)
                    if ($id_changed) {
                        // Check if new ID is already taken by someone else
                        $check_id = $pdo->prepare("SELECT student_id FROM students WHERE student_id = ?");
                        $check_id->execute([$new_student_id]);
                        if ($check_id->fetch()) {
                            throw new Exception("Student ID {$new_student_id} is already registered to another student.");
                        }

                        // Disable FK checks to update the Primary Key across all tables safely
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                        $stmt_upd_s = $pdo->prepare("UPDATE students SET student_id = ?, mobile = ?, telephone = ?, last_id_change = NOW() WHERE student_id = ?");
                        $stmt_upd_s->execute([$new_student_id, $mobile, $telephone, $_SESSION['student_id']]);

                        // Update child tables
                        $tables = ['attendance','registrations','student_module_progress','compliance','reminders_sent'];
                        foreach ($tables as $tbl) {
                            $stmt_tbl = $pdo->prepare("UPDATE {$tbl} SET student_id = ? WHERE student_id = ?");
                            $stmt_tbl->execute([$new_student_id, $_SESSION['student_id']]);
                        }

                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                        
                        // Update the session so they don't get logged out immediately
                        $_SESSION['student_id'] = $new_student_id;
                        $student['student_id'] = $new_student_id;
                        $student['last_id_change'] = date('Y-m-d H:i:s');
                    } else {
                        // Normal update (ID not changed)
                        $stmt_upd_s = $pdo->prepare("UPDATE students SET mobile = ?, telephone = ? WHERE student_id = ?");
                        $stmt_upd_s->execute([$mobile, $telephone, $_SESSION['student_id']]);
                    }

                    // Update users table (Full Name only, email handled above)
                    $stmt_upd_u = $pdo->prepare("UPDATE users SET full_name = ? WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
                    $stmt_upd_u->execute([$full_name, $_SESSION['student_id']]);

                    // Update students table (Middle Name and Suffix)
                    $stmt_upd_name = $pdo->prepare("UPDATE students SET middle_name = ?, suffix = ? WHERE student_id = ?");
                    $stmt_upd_name->execute([$middle_name ?: null, $suffix ?: null, $_SESSION['student_id']]);
                    
                    $pdo->commit();
                    $success = "Profile updated successfully.";
                    
                    $student['full_name'] = $full_name;
                    $student['middle_name'] = $middle_name;
                    $student['suffix'] = $suffix;
                    $student['mobile'] = $mobile;
                    $student['telephone'] = $telephone;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); // Ensure checks are re-enabled even if it fails
                    $error = "Update failed: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Password Change (Unchanged)
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
        .strength-meter { height: 4px; border-radius: 2px; transition: all 0.3s; }
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

        <!-- PENDING EMAIL WARNING -->
        <?php if (!empty($student['pending_email'])): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 text-amber-800 p-4 rounded-r-lg shadow-sm mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <i class="fas fa-exclamation-triangle text-amber-500 text-xl"></i>
                    <div>
                        <p class="font-bold">Unverified Email Change Request</p>
                        <p class="text-sm">You requested to change your email to <strong><?= htmlspecialchars($student['pending_email']) ?></strong>. Please check your inbox for the 6-digit PIN.</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="../verify_email_change.php" class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-sm transition">Enter PIN</a>
                    <form method="POST" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_email_change">
                        <button type="submit" class="bg-white border border-amber-300 text-amber-700 hover:bg-amber-100 px-4 py-2 rounded-lg text-sm font-bold shadow-sm transition" onclick="return confirm('Cancel your email change request?');">Cancel</button>
                    </form>
                </div>
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
                <form method="POST" id="profileForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="p-6 space-y-6">
                        
                        <!-- Student ID (Now Editable) -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Student ID <span class="text-red-500">*</span></label>
                                <?php if (!empty($student['last_id_change'])): ?>
                                    <p class="text-xs text-amber-600 mt-1">You can only change this once every 24 hours.</p>
                                <?php endif; ?>
                            </div>
                            <div class="md:col-span-2">
                                <input type="text" name="student_id" id="student_id_input" value="<?= htmlspecialchars($student['student_id']) ?>" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow font-mono text-sm" required pattern="[a-zA-Z0-9\-]+" title="Only letters, numbers, and dashes are allowed.">
                                <p class="text-xs text-gray-500 mt-1">Be very careful. You can only change this once every 24 hours.</p>
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
                        <!-- Name Fields Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
                            <div class="md:text-right pt-2">
                                <label class="block text-sm font-bold text-gray-700">Full Name <span class="text-red-500">*</span></label>
                            </div>
                            <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-4 gap-3">
                                <div class="md:col-span-1">
                                    <label class="block text-[11px] font-semibold text-gray-500 mb-1">First Name</label>
                                    <input type="text" name="first_name" value="<?= htmlspecialchars($first_name) ?>" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                                </div>
                                <div class="md:col-span-1">
                                    <label class="block text-[11px] font-semibold text-gray-500 mb-1">Middle Name</label>
                                    <input type="text" name="middle_name" value="<?= htmlspecialchars($student['middle_name'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                </div>
                                <div class="md:col-span-1">
                                    <label class="block text-[11px] font-semibold text-gray-500 mb-1">Last Name</label>
                                    <input type="text" name="last_name" value="<?= htmlspecialchars($last_name) ?>" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                                </div>
                                <div class="md:col-span-1">
                                    <label class="block text-[11px] font-semibold text-gray-500 mb-1">Suffix</label>
                                    <input type="text" name="suffix" value="<?= htmlspecialchars($student['suffix'] ?? '') ?>" placeholder="e.g. Jr." class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                </div>
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
    // --- NEW: Warn before Student ID change ---
    const originalId = "<?= $student['student_id'] ?>";
    document.getElementById('profileForm').addEventListener('submit', function(e) {
        const newId = document.getElementById('student_id_input').value;
        if (newId !== originalId) {
            if (!confirm("⚠️ WARNING!\n\nYou are about to change your Student ID from:\n" + originalId + "\n\nTo:\n" + newId + "\n\nThis will immediately change your login credentials and update your records across the system. You can only do this once every 24 hours.\n\nAre you absolutely sure?")) {
                e.preventDefault();
                return false;
            }
        }
    });

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

        strengthBars.forEach(bar => {
            bar.className = 'flex-1 rounded-full bg-gray-200';
        });

        const colors = ['bg-red-500', 'bg-amber-500', 'bg-blue-500', 'bg-green-500'];
        for (let i = 0; i < score; i++) {
            strengthBars[i].classList.remove('bg-gray-200');
            strengthBars[i].classList.add(colors[i]);
        }

        const texts = ['Enter a password', 'Weak', 'Fair', 'Good', 'Strong'];
        strengthText.textContent = texts[score];
    });
});
</script>
</body>
</html>