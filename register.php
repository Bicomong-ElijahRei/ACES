<?php
/**
 * ============================================================
 * ACES System — Student Registration
 * ============================================================
 * Handles both GET (show form) and POST (create account).
 * Sends email verification link after successful registration.
 * ============================================================
 */

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- CSRF check ----
    csrf_verify();

    // Get form data (with null-safe defaults)
    $first_name  = trim($_POST['first_name']  ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $suffix      = trim($_POST['suffix']      ?? '');
    $course      = trim($_POST['course']      ?? '');
    $section     = trim($_POST['section']     ?? '');
    $student_id  = trim($_POST['student_id']  ?? '');
    $telephone   = trim($_POST['telephone']   ?? '');
    $mobile      = trim($_POST['mobile']      ?? '');
    $email       = trim($_POST['email']       ?? '');
    $username    = trim($_POST['username']    ?? '');
    $password    = $_POST['password']         ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';
    $terms       = isset($_POST['terms']);

    // Basic validation
    if (!$first_name || !$last_name || !$course || !$section || !$student_id || !$email || !$password || !$confirm) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!str_ends_with($email, '@kld.edu.ph')) {
        $error = "Registration is restricted to official KLD email addresses (must end in @kld.edu.ph).";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif (!$terms) {
        $error = "You must agree to the Terms and Conditions.";
    } else {
        // Build full name
        $full_name = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name . ($suffix ? ' ' . $suffix : ''));

        $pdo->beginTransaction();
        try {
            // Check if email already exists
            $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                throw new Exception("Email already registered.");
            }

            // Check if student_id already exists
            $check2 = $pdo->prepare("SELECT student_id FROM students WHERE student_id = ?");
            $check2->execute([$student_id]);
            if ($check2->fetch()) {
                throw new Exception("Student number already registered.");
            }

            // Insert into users (role = student) — is_verified defaults to 0
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role) VALUES (?, ?, ?, 'student')");
            $stmt->execute([$email, $hashed, $full_name]);
            $user_id = $pdo->lastInsertId();

            // Insert into students
            $stmt2 = $pdo->prepare("INSERT INTO students 
                (student_id, user_id, section, program, middle_name, suffix, telephone, mobile, username) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->execute([
                $student_id,
                $user_id,
                $section,
                $course,
                $middle_name,
                $suffix,
                $telephone,
                $mobile,
                $username
            ]);

            // ---- EMAIL VERIFICATION ----
            $token = bin2hex(random_bytes(32));
            $stmt_token = $pdo->prepare("UPDATE users SET verification_token = ? WHERE user_id = ?");
            $stmt_token->execute([$token, $user_id]);

            $pdo->commit();

            // Send verification email
            require_once __DIR__ . '/includes/send_email.php';

            $baseUrl = rtrim(aces_env('APP_URL', 'http://localhost/cair-system'), '/');
            $verify_link = $baseUrl . "/public/verify_student.php?token=" . urlencode($token);

            $subject = "Verify your ACES account";
            $safeFirst = htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8');
            $body = "
                <p>Hi {$safeFirst},</p>
                <p>Thank you for registering! Please click the link below to verify your email address:</p>
                <p><a href='{$verify_link}'>{$verify_link}</a></p>
                <p>If you didn't create this account, please ignore this email.</p>
            ";

            // Attempt email send; do not prevent login if it fails (just log)
            try {
                sendEmail($email, $subject, $body);
            } catch (Exception $e) {
                error_log("Verification email failed for {$email}: " . $e->getMessage());
            }

            header('Location: index.php?registered=1');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <title>Student Registration | ACES</title>
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        input:focus, select:focus {
            outline: none;
            border-color: #00c07f !important;
            box-shadow: 0 0 0 3px rgba(0, 192, 127, 0.15) !important;
        }
    </style>
</head>
<body class="bg-[radial-gradient(circle,_#2a5d1b_0%,_#0d1a0a_100%)] min-h-screen flex items-center justify-center p-4 py-10">

   <div class="bg-white w-full max-w-4xl p-6 md:p-10 rounded-2xl shadow-2xl">

        <!-- Header -->
        <div class="flex items-center gap-4 border-b border-gray-200 pb-5 mb-8">
            <img src="assets/images/kld_logo.png" alt="Logo" class="w-16 h-16 object-contain drop-shadow-md">
            <div>
                <h1 class="text-xl font-extrabold text-gray-800 uppercase leading-tight tracking-wide">
                    Kolehiyo ng Lungsod ng Dasmariñas
                </h1>
                <p class="text-sm text-gray-500 font-medium mt-1">Alumni Career, Enhancement and Services</p>
            </div>
        </div>

        <h2 class="text-center text-[#00c07f] text-2xl font-bold mb-8 tracking-wide">CREATE AN ACCOUNT</h2>

        <!-- Error Messages -->
        <?php if ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-r-lg mb-6 flex items-center gap-3 shadow-sm">
                <i class="fas fa-exclamation-circle text-lg"></i>
                <span class="text-sm font-medium"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrf_field() ?>

            <!-- Student Information -->
            <div class="mb-10">
                <h3 class="text-gray-800 text-lg font-bold border-b-2 border-gray-100 pb-2 mb-5 flex items-center gap-2">
                    <i class="fas fa-user-graduate text-[#00c07f]"></i> Student Information
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-5">
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">First Name <span class="text-red-500">*</span></label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Middle Name</label>
                        <input type="text" name="middle_name" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>" class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Last Name <span class="text-red-500">*</span></label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Suffix Name</label>
                        <input type="text" name="suffix" value="<?= htmlspecialchars($_POST['suffix'] ?? '') ?>" placeholder="e.g., Jr." class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Course <span class="text-red-500">*</span></label>
                        <select name="course" required class="border border-gray-300 p-2.5 rounded-lg text-sm bg-gray-50 focus:bg-white appearance-none cursor-pointer">
                            <option value="">Select Course</option>
                            <option <?= (isset($_POST['course']) && $_POST['course'] == 'BS Information Systems') ? 'selected' : '' ?>>BS Information Systems</option>
                            <option <?= (isset($_POST['course']) && $_POST['course'] == 'BS Psychology') ? 'selected' : '' ?>>BS Psychology</option>
                            <option <?= (isset($_POST['course']) && $_POST['course'] == 'BS Midwifery') ? 'selected' : '' ?>>BS Midwifery</option>
                            <option <?= (isset($_POST['course']) && $_POST['course'] == 'BS Engineering') ? 'selected' : '' ?>>BS Engineering</option>
                        </select>
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Year & Section <span class="text-red-500">*</span></label>
                        <input type="text" name="section" value="<?= htmlspecialchars($_POST['section'] ?? '') ?>" placeholder="e.g., 401" required class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Student Number <span class="text-red-500">*</span></label>
                        <input type="text" name="student_id" value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>" placeholder="2023-2-000677" required class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                </div>
            </div>

            <!-- Contact Details -->
            <div class="mb-10">
                <h3 class="text-gray-800 text-lg font-bold border-b-2 border-gray-100 pb-2 mb-5 flex items-center gap-2">
                    <i class="fas fa-address-book text-[#00c07f]"></i> Contact Details
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Telephone No.</label>
                        <input type="text" name="telephone" value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" placeholder="02-123-4567" class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Mobile No. <span class="text-red-500">*</span></label>
                        <input type="text" name="mobile" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>" placeholder="09XXXXXXXXX" required class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="student@kld.edu.ph" required pattern=".+@kld\.edu\.ph" title="Email must end with @kld.edu.ph" class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                </div>
            </div>

            <!-- Account Details -->
            <div class="mb-10">
                <h3 class="text-gray-800 text-lg font-bold border-b-2 border-gray-100 pb-2 mb-5 flex items-center gap-2">
                    <i class="fas fa-shield-alt text-[#00c07f]"></i> Account Details
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Username (optional)</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Choose a username" class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Password <span class="text-red-500">*</span></label>
                        <input type="password" name="password" required minlength="6" class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Confirm Password <span class="text-red-500">*</span></label>
                        <input type="password" name="confirm_password" required minlength="6" class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
                    </div>
                </div>
            </div>

            <!-- Terms and Submit -->
            <div class="flex flex-col md:flex-row items-center justify-between gap-6 mt-8 pt-6 border-t border-gray-100">
                <div class="flex gap-3 max-w-xl">
                    <input type="checkbox" id="terms" name="terms" required <?= isset($_POST['terms']) ? 'checked' : '' ?> class="mt-1 w-4 h-4 text-[#00c07f] rounded focus:ring-[#00c07f] cursor-pointer">
                    <label for="terms" class="text-xs text-gray-500 leading-relaxed cursor-pointer">
                        By checking the box during registration, you acknowledge that you have read and agreed to these 
                        <a href="public/policies.php#terms" target="_blank" class="text-[#0a6e2d] font-bold hover:underline">Terms and Conditions</a> 
                        and 
                        <a href="public/policies.php#privacy" target="_blank" class="text-[#0a6e2d] font-bold hover:underline">Data Privacy Policy</a>.
                    </label>
                </div>
                <button type="submit" class="bg-[#00c07f] hover:bg-[#00a86f] text-white font-bold py-3 px-10 rounded-full shadow-md hover:shadow-lg transition-all transform hover:-translate-y-0.5 whitespace-nowrap">
                    Register
                </button>
            </div>
        </form>

        <div class="text-center mt-8 pt-6 border-t border-gray-100">
            <p class="text-sm text-gray-500">Already have an account? <a href="index.php" class="text-[#0a6e2d] font-bold hover:underline">Back to Login</a></p>
        </div>
   </div>

   <script>
        // Simple client-side password match check
        document.querySelector('form').addEventListener('submit', function(e) {
            let password = document.querySelector('input[name="password"]').value;
            let confirm = document.querySelector('input[name="confirm_password"]').value;
            if (password !== confirm) {
                alert('Passwords do not match.');
                e.preventDefault();
            }
        });
   </script>
</body>
</html>