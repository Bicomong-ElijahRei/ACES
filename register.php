<?php
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $first_name   = trim($_POST['first_name']);
    $middle_name  = trim($_POST['middle_name']);
    $last_name    = trim($_POST['last_name']);
    $suffix       = trim($_POST['suffix']);
    $course       = trim($_POST['course']);
    $section      = trim($_POST['section']);
    $student_id   = trim($_POST['student_id']);
    $telephone    = trim($_POST['telephone']);
    $mobile       = trim($_POST['mobile']);
    $email        = trim($_POST['email']);
    $username     = trim($_POST['username']);
    $password     = $_POST['password'];
    $confirm      = $_POST['confirm_password'];
    $terms        = isset($_POST['terms']);

    // Basic validation
    if (!$first_name || !$last_name || !$course || !$section || !$student_id || !$email || !$password || !$confirm) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif (!$terms) {
        $error = "You must agree to the Terms and Conditions.";
    } else {
        // Build full name
        $full_name = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name . ($suffix ? ' ' . $suffix : ''));

        if (!$error) {
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

                // Insert into users (role = student) – is_verified defaults to 0
                $hashed = password_hash($password, PASSWORD_DEFAULT);
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
                // Generate token and save it
                $token = bin2hex(random_bytes(32));
                $stmt_token = $pdo->prepare("UPDATE users SET verification_token = ? WHERE user_id = ?");
                $stmt_token->execute([$token, $user_id]);

                $pdo->commit();

                // Send verification email
                require_once __DIR__ . '/includes/send_email.php';
                $verify_link = "http://localhost/cair-system/public/verify_student.php?token=$token";
                $subject = "Verify your ACES account";
                $body = "
                    <p>Hi {$first_name},</p>
                    <p>Thank you for registering! Please click the link below to verify your email address:</p>
                    <p><a href='{$verify_link}'>{$verify_link}</a></p>
                    <p>If you didn't create this account, please ignore this email.</p>
                ";

                // Attempt email send; do not prevent login if it fails (just log)
                try {
                    sendEmail($email, $subject, $body);
                } catch (Exception $e) {
                    // Log error but don't stop the flow – account was created
                    error_log("Verification email failed for {$email}: " . $e->getMessage());
                }

                header('Location: index.php?registered=1');
                exit;
                // ---- END EMAIL VERIFICATION ----
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Registration failed: " . $e->getMessage();
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
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Student Registration | ACES</title>
</head>
<body class="bg-[radial-gradient(circle,_#2a5d1b_0%,_#0d1a0a_100%)] min-h-screen flex items-center justify-center p-4">

   <div class="bg-white w-full max-w-4xl p-6 md:p-8 rounded-sm shadow-2xl">
        
        <div class="flex items-center gap-4 border-b border-gray-100 pb-4 mb-6">
            <img src="assets/images/kld_logo.png" alt="Logo" class="w-14 h-14 object-contain">
            <h1 class="text-lg font-bold text-gray-800 uppercase leading-tight">
                Kolehiyo ng Lungsod ng Dasmariñas
            </h1>
        </div>

        <h2 class="text-center text-gray-500 text-xl mb-6">Register</h2>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- Student Information -->
            <div class="mb-8">
                <h3 class="text-emerald-600 text-sm font-semibold border-b border-gray-100 mb-4">Student Information</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                        <input type="text" name="first_name" required class="border border-gray-400 p-2 rounded-sm text-sm focus:outline-none focus:border-emerald-500">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Middle Name</label>
                        <input type="text" name="middle_name" class="border border-gray-400 p-2 rounded-sm text-sm focus:outline-none focus:border-emerald-500">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                        <input type="text" name="last_name" required class="border border-gray-400 p-2 rounded-sm text-sm focus:outline-none focus:border-emerald-500">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Suffix Name</label>
                        <input type="text" name="suffix" placeholder="e.g., Jr." class="border border-gray-400 p-2 rounded-sm text-sm focus:outline-none focus:border-emerald-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Course <span class="text-red-500">*</span></label>
                        <select name="course" required class="border border-gray-400 p-2 rounded-sm text-sm bg-white">
                            <option value="">Select Course</option>
                            <option>BS Information Systems</option>
                            <option>BS Psychology</option>
                            <option>BS Midwifery</option>
                            <option>BS Engineering</option>
                        </select>
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Year & Section <span class="text-red-500">*</span></label>
                        <input type="text" name="section" placeholder="e.g., 401" required class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Student Number <span class="text-red-500">*</span></label>
                        <input type="text" name="student_id" placeholder="2023-2-000677" required class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                </div>
            </div>

            <!-- Contact Details -->
            <div class="mb-8">
                <h3 class="text-emerald-600 text-sm font-semibold border-b border-gray-100 mb-4">Contact Details</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Telephone No.</label>
                        <input type="text" name="telephone" placeholder="02-123-4567" class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Mobile No. <span class="text-red-500">*</span></label>
                        <input type="text" name="mobile" placeholder="09XXXXXXXXX" required class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" placeholder="student@kld.edu.ph" required class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                </div>
            </div>

            <!-- Account Details -->
            <div class="mb-8">
                <h3 class="text-emerald-600 text-sm font-semibold border-b border-gray-100 mb-4">Account Details</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Username (optional)</label>
                        <input type="text" name="username" placeholder="Choose a username" class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                        <input type="password" name="password" required class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-xs font-medium text-gray-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                        <input type="password" name="confirm_password" required class="border border-gray-400 p-2 rounded-sm text-sm">
                    </div>
                </div>
            </div>

            <!-- Terms and Submit -->
            <div class="flex flex-col md:flex-row items-end justify-between gap-4 mt-6">
                <div class="flex gap-3 max-w-lg">
                    <input type="checkbox" id="terms" name="terms" required class="mt-1">
                    <label for="terms" class="text-[10px] text-gray-500 leading-tight">
                        By checking the box during registration, you acknowledge that you have read and agreed to these <strong>Terms and Conditions.</strong>
                    </label>
                </div>
                <button type="submit" class="bg-[#00c07f] hover:bg-[#00a86f] text-white font-bold py-2 px-8 rounded-full transition-colors">
                    Register
                </button>
            </div>
        </form>
        <p class="text-center text-sm text-gray-500 mt-4"><a href="index.php" class="text-[#0a6e2d] hover:underline">Back to Login</a></p>
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