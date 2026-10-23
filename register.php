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

// Fetch dynamic courses and sections
$courses_list = $pdo->query("SELECT * FROM courses ORDER BY name")->fetchAll();
$sections_list = $pdo->query("SELECT * FROM sections ORDER BY name")->fetchAll();

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
    $email       = strtolower(trim($_POST['email'] ?? ''));
    $username    = trim($_POST['username']    ?? '');
    $password    = $_POST['password']         ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';
    $terms       = isset($_POST['terms']);

    // Basic validation
    if (!$first_name || !$last_name || !$course || !$section || !$student_id || !$email || !$password || !$confirm) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (substr($email, -11) !== '@kld.edu.ph') {
        $error = "Registration is restricted to official KLD email addresses (must end in @kld.edu.ph).";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif (!preg_match('/^[0-9\-]+$/', $mobile)) {
        $error = "Please enter a valid mobile number (numbers only).";
    } elseif (!preg_match('/^[a-zA-Z0-9\-]+$/', $student_id)) {
        $error = "Student Number can only contain letters, numbers, and dashes.";
    } elseif (!$terms) {
        $error = "You must agree to the Terms and Conditions.";
    } else {
        // Build full name
        $full_name = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name . ($suffix ? ' ' . $suffix : ''));

        // Convert empty username to NULL (prevents UNIQUE KEY violation on second registration)
        if ($username === '') {
            $username = null;
        }

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

            // Check if username already exists
            if (!empty($username)) {
                $check3 = $pdo->prepare("SELECT student_id FROM students WHERE username = ?");
                $check3->execute([$username]);
                if ($check3->fetch()) {
                    throw new Exception("Username is already taken. Please choose another one.");
                }
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
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $stmt_token = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expires = ? WHERE user_id = ?");
            $stmt_token->execute([$token, $expires, $user_id]);     

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
                        <select name="course" id="courseSelect" required class="border border-gray-300 p-2.5 rounded-lg text-sm bg-gray-50 focus:bg-white appearance-none cursor-pointer">
                            <option value="">Select Course</option>
                            <?php foreach ($courses_list as $c): ?>
                                <option value="<?= htmlspecialchars($c['name']) ?>" <?= (isset($_POST['course']) && $_POST['course'] == $c['name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Year & Section <span class="text-red-500">*</span></label>
                        <select name="section" id="sectionSelect" required class="border border-gray-300 p-2.5 rounded-lg text-sm bg-gray-50 focus:bg-white appearance-none cursor-pointer">
                            <option value="">Select Course First</option>
                        </select>
                    </div>
                    <div class="flex flex-col">
                        <label class="text-sm font-semibold text-gray-600 mb-1.5">Student Number <span class="text-red-500">*</span></label>
                        <input type="text" name="student_id" id="student_id_input" value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>" placeholder="2023-2-000677" required pattern="[a-zA-Z0-9\-]+" title="Only letters, numbers, and dashes are allowed." class="border border-gray-300 p-2.5 rounded-lg text-sm transition-all bg-gray-50 focus:bg-white">
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
        // Client-side password match check AND button buffering
        document.querySelector('form').addEventListener('submit', function(e) {
            let password = document.querySelector('input[name="password"]').value;
            let confirm = document.querySelector('input[name="confirm_password"]').value;
            let studentId = document.getElementById('student_id_input').value;
            
            if (password !== confirm) {
                alert('Passwords do not match.');
                e.preventDefault(); // Stop submission
                return false;
            } else {
                // NEW: Confirm the Student ID to prevent typos
                if (!confirm("Please double-check your Student Number:\n\n" + studentId + "\n\nIs this exactly correct?")) {
                    e.preventDefault();
                    return false;
                }

                // Passwords match! Disable the button so they can't double-click
                const btn = this.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Registering...';
                    btn.classList.add('opacity-75', 'cursor-not-allowed');
                    // Remove the hover effects so it looks properly disabled
                    btn.classList.remove('hover:-translate-y-0.5', 'hover:shadow-lg', 'hover:bg-[#00a86f]');
                }
            }
        });

        // Dynamic Course -> Section Dropdowns
        const coursesData = <?= json_encode($courses_list) ?>;
        const sectionsData = <?= json_encode($sections_list) ?>;
        const courseSelect = document.getElementById('courseSelect');
        const sectionSelect = document.getElementById('sectionSelect');

        // Grab previously selected values (for repopulating after a validation error)
        const oldCourse = "<?= htmlspecialchars($_POST['course'] ?? '') ?>";
        const oldSection = "<?= htmlspecialchars($_POST['section'] ?? '') ?>";

        function populateSections(courseName, selectedSection = '') {
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            
            if (!courseName) {
                sectionSelect.innerHTML = '<option value="">Select Course First</option>';
                return;
            }

            // Find the course ID
            const courseObj = coursesData.find(c => c.name === courseName);
            if (!courseObj) return;

            // Filter sections for this course
            const filteredSections = sectionsData.filter(s => s.course_id == courseObj.id);
            
            if (filteredSections.length === 0) {
                sectionSelect.innerHTML = '<option value="">No sections available</option>';
                return;
            }

            filteredSections.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.name;
                opt.textContent = s.name;
                if (s.name === selectedSection) opt.selected = true;
                sectionSelect.appendChild(opt);
            });
        }

        // Run on page load (this restores the dropdowns if the form fails validation)
        if (oldCourse) {
            populateSections(oldCourse, oldSection);
        }

        // Run on change
        courseSelect.addEventListener('change', function() {
            populateSections(this.value);
        });
   </script>
</body>
</html>