<?php
set_time_limit(0); 
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$step = 1;
$success = '';
$error = '';
$headers = [];
$preview_data = [];
$temp_file = '';
$mapping = [];

// ==========================================
// MANUAL ENTRY HANDLER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_add') {
    $student_id = trim($_POST['student_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = strtolower(trim($_POST['email']));
    $program = trim($_POST['program']);
    $section = trim($_POST['section']);
    
    if (empty($student_id) || empty($email) || empty($first_name) || empty($last_name)) {
        $error = "All fields are required for manual entry.";
    } else {
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? UNION SELECT user_id FROM students WHERE student_id = ?");
        $check->execute([$email, $student_id]);
        if ($check->rowCount() > 0) {
            $error = "Duplicate Error: Student ID ($student_id) or Email ($email) already exists.";
        } else {
            $default_password = password_hash('password', PASSWORD_BCRYPT);
            $full_name = "$first_name $last_name";
            
            $stmt_user = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role, is_verified) VALUES (?, ?, ?, 'student', 1)");
            $stmt_user->execute([$email, $default_password, $full_name]);
            $user_id = $pdo->lastInsertId();

            $stmt_student = $pdo->prepare("INSERT INTO students (student_id, user_id, section, program, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_student->execute([$student_id, $user_id, $section, $program, $first_name, $last_name]);
            $success = "Student <strong>$full_name</strong> added successfully!";
        }
    }
}

// ==========================================
// STEP 1: HANDLE FILE UPLOAD
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $temp_file = __DIR__ . '/../uploads/temp_import.csv';
            if (move_uploaded_file($_FILES['csv_file']['tmp_name'], $temp_file)) {
                $step = 2;
                if (($handle = fopen($temp_file, "r")) !== FALSE) {
                    $headers = fgetcsv($handle, 1000, ",");
                    fclose($handle);
                }
            } else {
                $error = "Failed to move uploaded file.";
            }
        } else {
            $error = "Invalid file type. Please upload a .csv file.";
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// ==========================================
// STEP 2: PREVIEW & VALIDATE DATA
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview_import') {
    $step = 3;
    $mapping = [
        'student_id' => (int)$_POST['map_student_id'],
        'first_name' => (int)$_POST['map_first_name'],
        'last_name'  => (int)$_POST['map_last_name'],
        'email'      => (int)$_POST['map_email'],
        'program'    => (int)$_POST['map_program'],
        'section'    => (int)$_POST['map_section']
    ];
    $start_row = max(1, (int)$_POST['start_row']);
    
    $temp_file = __DIR__ . '/../uploads/temp_import.csv';
    
    if (file_exists($temp_file)) {
        $handle = fopen($temp_file, "r");
        $row_count = 0;
        $seen_ids = [];
        $seen_emails = [];
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row_count++;
            if ($row_count < $start_row) continue;
            
            // Extract only if mapped, otherwise set default
            $email = strtolower(trim($data[$mapping['email']] ?? ''));
            $student_id = ($mapping['student_id'] !== -1) ? trim($data[$mapping['student_id']] ?? '') : '';
            $first_name = ($mapping['first_name'] !== -1) ? trim($data[$mapping['first_name']] ?? '') : '';
            $middle_name = (isset($mapping['middle_name']) && $mapping['middle_name'] !== -1) ? trim($data[$mapping['middle_name']] ?? '') : '';
            $last_name = ($mapping['last_name'] !== -1) ? trim($data[$mapping['last_name']] ?? '') : '';
            $program = ($mapping['program'] !== -1) ? trim($data[$mapping['program']] ?? '') : 'Unassigned';
            $section = ($mapping['section'] !== -1) ? trim($data[$mapping['section']] ?? '') : 'Unassigned';
            
            // Generate temp ID if not provided
            if (empty($student_id)) {
                $student_id = 'TEMP-' . strtoupper(substr(md5(uniqid()), 0, 6));
            }
            
            $status = 'valid';
            $reason = 'Ready to import';
            
            // Validation Checks (Email is the only strictly required one)
            if (empty($email)) {
                $status = 'invalid';
                $reason = 'Missing Email Address';
            } 
            // Check for duplicates inside the CSV itself
            elseif (in_array($student_id, $seen_ids) || in_array($email, $seen_emails)) {
                $status = 'invalid';
                $reason = 'Duplicate entry inside this CSV file';
            } 
                        // CHECK FOR CLOSELY RELATED COURSES / SECTIONS
            if ($status === 'valid') {
                $valid_courses = $pdo->query("SELECT name FROM courses")->fetchAll(PDO::FETCH_COLUMN);
                $valid_sections = $pdo->query("SELECT name FROM sections")->fetchAll(PDO::FETCH_COLUMN);
                
                $is_course_valid = false;
                foreach ($valid_courses as $vc) {
                    if (strcasecmp(trim($vc), trim($program)) == 0) { $is_course_valid = true; break; }
                }
                
                $is_section_valid = false;
                foreach ($valid_sections as $vs) {
                    if (strcasecmp(trim($vs), trim($section)) == 0) { $is_section_valid = true; break; }
                }

                if (!$is_course_valid || !$is_section_valid) {
                    $status = 'warning';
                    $reason = "Course/Section does not exactly match system data. Will be saved as typed.";
                }
            }
            // Check for duplicates in the Database
            else {
                $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? UNION SELECT user_id FROM students WHERE student_id = ?");
                $check->execute([$email, $student_id]);
                if ($check->rowCount() > 0) {
                    $status = 'existing';
                    $reason = 'Email or Student ID already exists in system';
                }
            }
            
            if ($status === 'valid') {
                $seen_ids[] = $student_id;
                $seen_emails[] = $email;
            }
            
            $preview_data[] = [
                'row' => $row_count,
                'student_id' => $student_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'program' => $program,
                'section' => $section,
                'status' => $status,
                'reason' => $reason
            ];
        }
        fclose($handle);
    } else {
        $error = "Temporary file not found.";
        $step = 1;
    }
}

// ==========================================
// STEP 3: EXECUTE IMPORT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'execute_import') {
    $temp_file = __DIR__ . '/../uploads/temp_import.csv';
    $mapping = json_decode($_POST['mapping'], true);
    $start_row = (int)$_POST['start_row'];
    
    if (file_exists($temp_file) && $mapping) {
        $handle = fopen($temp_file, "r");
        $row_count = 0;
        $success_count = 0;
        $skip_count = 0;
        $default_password = password_hash('password', PASSWORD_BCRYPT);

        try {
            $pdo->beginTransaction();
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row_count++;
                if ($row_count < $start_row) continue;
                
                $email = strtolower(trim($data[$mapping['email']] ?? ''));
                $student_id = ($mapping['student_id'] !== -1) ? trim($data[$mapping['student_id']] ?? '') : '';
                $first_name = ($mapping['first_name'] !== -1) ? trim($data[$mapping['first_name']] ?? '') : '';
                $middle_name = (isset($mapping['middle_name']) && $mapping['middle_name'] !== -1) ? trim($data[$mapping['middle_name']] ?? '') : '';
                $last_name = ($mapping['last_name'] !== -1) ? trim($data[$mapping['last_name']] ?? '') : '';
                $program = ($mapping['program'] !== -1) ? trim($data[$mapping['program']] ?? '') : 'Unassigned';
                $section = ($mapping['section'] !== -1) ? trim($data[$mapping['section']] ?? '') : 'Unassigned';

                if (empty($email)) { $skip_count++; continue; }

                $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? UNION SELECT user_id FROM students WHERE student_id = ?");
                $check->execute([$email, $student_id]);
                if ($check->rowCount() > 0) { $skip_count++; continue; }

                // Generate temp ID if still empty
                if (empty($student_id)) {
                    $student_id = 'TEMP-' . strtoupper(substr(md5(uniqid()), 0, 6));
                }

                // Fallback name logic
                $full_name = trim("$first_name $middle_name $last_name");
                if (empty($full_name)) {
                    $full_name = explode('@', $email)[0]; // Use part before @ if no name
                }

                // 1. Generate a unique verification token
                $token = bin2hex(random_bytes(32));
                
                // 2. Insert user as UNVERIFIED (is_verified = 0)
                $stmt_user = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role, is_verified, verification_token) VALUES (?, ?, ?, 'student', 0, ?)");
                $stmt_user->execute([$email, $default_password, $full_name, $token]);
                $user_id = $pdo->lastInsertId();

                                // 3. SEND THE EMAIL
                require_once __DIR__ . '/../includes/send_email.php';
                
                // Assuming verify.php is in the public folder or root
                $verify_link = "https://aces.site.je/public/verify_student.php?token=" . $token;
                
                $subject = "Welcome to ACES - Please Verify Your Account";
                $message = "
                    <div style='font-family: Arial, sans-serif; color: #333;'>
                        <h2 style='color: #0a6e2d;'>Hello $full_name,</h2>
                        <p>Your ACES account has been successfully created.</p>
                        <p>Please click the button below to verify your email address and set your password for the ACES Activity Tracking System:</p>
                        <p style='margin: 20px 0;'>
                            <a href='$verify_link' style='background-color: #0a6e2d; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Verify My Account</a>
                        </p>
                        <p>If the button doesn't work, copy and paste this link into your browser:</p>
                        <p><a href='$verify_link'>$verify_link</a></p>
                        <br>
                        <p>Thank you,</p>
                        <p><strong>KLD - ACES Unit</strong></p>
                    </div>
                ";
                
                // Try-catch ensures that if the SMTP fails (e.g. rate limit),
                // the database transaction does NOT roll back.
                try {
                    sendEmail($email, $subject, $message);
                } catch (Exception $e) {
                    // Optional: Log the failure here
                }

                $stmt_student = $pdo->prepare("INSERT INTO students (student_id, user_id, section, program, first_name, last_name, middle_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_student->execute([$student_id, $user_id, $section, $program, $first_name, $last_name, $middle_name]);
                $success_count++;
            }
            $pdo->commit();
            fclose($handle);
            unlink($temp_file); 
            
            $success = "Import Complete! Added: <strong>$success_count</strong>. Skipped (invalid/duplicates): <strong>$skip_count</strong>.";
            $step = 1; 
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Students | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .status-valid { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-invalid { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .status-existing { background-color: #fef08a; color: #854d0e; border: 1px solid #fde047; }
        .status-warning { background-color: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    
    <main class="flex-1 p-4 md:p-8 overflow-y-auto">
        <div class="max-w-6xl mx-auto">
            
            <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-check-circle text-xl"></i> <div><?= $success ?></div>
                </div>
            <?php elseif ($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-xl"></i> <div><?= $error ?></div>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-[#0a6e2d] px-6 py-4 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-white"><i class="fas fa-users mr-2"></i>Student Enrollment Manager</h2>
                    <a href="students.php" class="text-white/80 hover:text-white text-sm"><i class="fas fa-arrow-left mr-1"></i> Back to Students</a>
                </div>

                <div class="border-b border-gray-200 bg-gray-50 px-4 pt-3">
                    <ul class="nav nav-tabs border-0" id="mainTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active border-0 border-bottom border-2 border-transparent rounded-t-lg font-bold text-sm py-2 px-4 text-[#0a6e2d] border-[#0a6e2d]" id="csv-tab" data-bs-toggle="tab" data-bs-target="#csv-pane" type="button" role="tab"><i class="fas fa-file-csv mr-1"></i> Bulk CSV Import</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link border-0 border-bottom border-2 border-transparent rounded-t-lg font-bold text-sm py-2 px-4 text-gray-500 hover:text-gray-700" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual-pane" type="button" role="tab"><i class="fas fa-keyboard mr-1"></i> Manual Entry</button>
                        </li>
                    </ul>
                </div>

                <div class="tab-content p-6">
                    
                    <!-- TAB 1: CSV IMPORT -->
                    <div class="tab-pane fade show active" id="csv-pane" role="tabpanel">
                        
                        <!-- STEP 1: UPLOAD -->
                        <?php if ($step === 1): ?>
                            <form method="POST" enctype="multipart/form-data" class="space-y-6 max-w-2xl mx-auto py-8">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="upload_csv">
                                <div class="text-center mb-6">
                                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-50 text-blue-500 mb-3"><i class="fas fa-cloud-upload-alt text-2xl"></i></div>
                                    <h3 class="text-lg font-bold text-gray-800">Upload Student CSV</h3>
                                    <p class="text-sm text-gray-500">Select a .csv file to map and import student data.</p>
                                </div>
                                <input type="file" name="csv_file" accept=".csv" required class="w-full border border-gray-300 rounded-lg p-3 bg-gray-50 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-[#0a6e2d] file:text-white hover:file:bg-[#054018]">
                                <button type="submit" class="w-full bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 rounded-lg transition shadow-sm">Upload & Map Columns</button>
                            </form>
                        <?php endif; ?>

                        <!-- STEP 2: COLUMN MAPPING & START ROW -->
                        <?php if ($step === 2 && !empty($headers)): ?>
                            <form method="POST" class="space-y-6">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="preview_import">

                                <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg text-sm mb-6">
                                    <i class="fas fa-info-circle mr-1"></i> Only <strong>Email</strong> is strictly required. You can select "-- Do Not Import --" for fields you want to skip.
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <!-- Start Row -->
                                    <div class="md:col-span-3 bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                                        <label class="block text-sm font-bold text-yellow-800 mb-1">Data Starts on Row:</label>
                                        <input type="number" name="start_row" value="2" min="1" required class="w-full md:w-32 border border-yellow-300 rounded-lg p-2 focus:ring-yellow-500">
                                        <p class="text-xs text-yellow-700 mt-1">Set to <strong>2</strong> if your CSV has headers on the first row. Set to <strong>1</strong> if data starts immediately.</p>
                                    </div>

                                    <!-- Mapping Dropdowns -->
                                    <?php 
                                    $fields = [
                                        'map_student_id' => ['label' => 'Student ID / Number', 'match' => ['id', 'student'], 'required' => false],
                                        'map_first_name' => ['label' => 'First Name', 'match' => ['first', 'fname'], 'required' => false],
                                        'map_middle_name'=> ['label' => 'Middle Name', 'match' => ['middle', 'mname'], 'required' => false],
                                        'map_last_name'  => ['label' => 'Last Name', 'match' => ['last', 'lname', 'surname'], 'required' => false],
                                        'map_email'      => ['label' => 'KLD Email Address', 'match' => ['email'], 'required' => true],
                                        'map_program'    => ['label' => 'Program / Course', 'match' => ['course', 'program'], 'required' => false],
                                        'map_section'    => ['label' => 'Section', 'match' => ['section'], 'required' => false]
                                    ];
                                    foreach ($fields as $name => $info): 
                                    ?>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 mb-1"><?= $info['label'] ?> <?= $info['required'] ? '<span class="text-red-500">*</span>' : '' ?></label>
                                        <select name="<?= $name ?>" <?= $info['required'] ? 'required' : '' ?> class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d]">
                                            <?php if (!$info['required']): ?>
                                                <option value="-1">-- Do Not Import --</option>
                                            <?php endif; ?>
                                            <?php foreach ($headers as $index => $header): 
                                                $isMatch = false;
                                                foreach($info['match'] as $keyword) { if (stripos($header, $keyword) !== false) { $isMatch = true; break; } }
                                            ?>
                                                <option value="<?= $index ?>" <?= $isMatch ? 'selected' : '' ?>><?= htmlspecialchars($header) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="pt-6 flex justify-between items-center border-t border-gray-100">
                                    <a href="import_students.php" class="text-gray-500 hover:text-gray-700 font-medium text-sm"><i class="fas fa-times mr-1"></i> Cancel</a>
                                    <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 px-8 rounded-lg transition shadow-sm"><i class="fas fa-search mr-2"></i> Preview & Validate Data</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <!-- STEP 3: PREVIEW & CONFIRM -->
                        <?php if ($step === 3): ?>
                            <div class="mb-6 flex justify-between items-end">
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Validation Results</h3>
                                    <p class="text-sm text-gray-500">Review the data below before confirming the import.</p>
                                </div>
                                <div class="flex gap-4 text-sm">
                                    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-green-500"></span> Valid</span>
                                    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-orange-400"></span> Warning</span>
                                    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-yellow-400"></span> Existing</span>
                                    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-red-500"></span> Invalid</span>
                                </div>
                            </div>

                            <div class="overflow-x-auto border border-gray-200 rounded-lg max-h-[500px]">
                                <table class="min-w-full text-left text-sm whitespace-nowrap">
                                    <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                                        <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                                            <th class="py-3 px-4">Row</th>
                                            <th class="py-3 px-4">Student ID</th>
                                            <th class="py-3 px-4">Name</th>
                                            <th class="py-3 px-4">Email</th>
                                            <th class="py-3 px-4">Course / Section</th>
                                            <th class="py-3 px-4 w-1/3">Status / Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <?php foreach ($preview_data as $row): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="py-3 px-4 text-gray-400 font-mono text-xs">#<?= $row['row'] ?></td>
                                                <td class="py-3 px-4 font-mono font-medium"><?= htmlspecialchars($row['student_id']) ?></td>
                                                <td class="py-3 px-4"><?= htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) ?: '<em class="text-gray-400">No Name Provided</em>' ?></td>
                                                <td class="py-3 px-4 text-gray-500 text-xs"><?= htmlspecialchars($row['email']) ?></td>
                                                <td class="py-3 px-4 text-xs"><?= htmlspecialchars($row['program']) ?> - <?= htmlspecialchars($row['section']) ?></td>
                                                <td class="py-3 px-4">
                                                    <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-md text-xs font-bold <?= 'status-' . $row['status'] ?>">
                                                        <?php if ($row['status'] === 'valid'): ?><i class="fas fa-check-circle"></i>
                                                        <?php elseif ($row['status'] === 'existing'): ?><i class="fas fa-exclamation-triangle"></i>
                                                        <?php elseif ($row['status'] === 'warning'): ?><i class="fas fa-exclamation-circle"></i>
                                                        <?php else: ?><i class="fas fa-times-circle"></i><?php endif; ?>
                                                        <?= $row['reason'] ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- CONFIRMATION FORM WITH LOADING -->
                            <form id="importForm" method="POST" class="pt-6 flex justify-between items-center border-t border-gray-100 mt-6" onsubmit="showLoading(event)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="execute_import">
                                <input type="hidden" name="start_row" value="<?= htmlspecialchars($_POST['start_row']) ?>">
                                <input type="hidden" name="mapping" value="<?= htmlspecialchars(json_encode($mapping)) ?>">
                                
                                <a href="import_students.php" class="text-gray-500 hover:text-gray-700 font-medium text-sm"><i class="fas fa-undo mr-1"></i> Start Over</a>
                                <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-3 px-8 rounded-lg transition shadow-sm"><i class="fas fa-file-import mr-2"></i> Confirm & Execute Import</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 2: MANUAL ENTRY -->
                    <div class="tab-pane fade" id="manual-pane" role="tabpanel">
                        <form method="POST" class="space-y-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="manual_add">
                            
                            <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg text-sm mb-4">
                                <i class="fas fa-keyboard mr-1"></i> Type in a single student's details below. The system will automatically check for duplicate IDs or Emails.
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 bg-gray-50 p-4 rounded-xl border border-gray-200">
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Student ID <span class="text-red-500">*</span></label>
                                    <input type="text" name="student_id" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] text-sm" placeholder="e.g. 2023-1-99999">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="first_name" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="last_name" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] text-sm">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                                    <input type="email" name="email" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] text-sm" placeholder="student@kld.edu.ph">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Course <span class="text-red-500">*</span></label>
                                    <input type="text" name="program" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Section <span class="text-red-500">*</span></label>
                                    <input type="text" name="section" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] text-sm">
                                </div>
                                <div class="md:col-span-3 flex justify-end mt-2">
                                    <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-2.5 px-6 rounded-lg transition shadow-sm text-sm"><i class="fas fa-plus-circle mr-1"></i> Add Student</button>
                                </div>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- LOADING OVERLAY -->
    <div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; flex-direction: column; align-items: center; justify-content: center;">
        <div class="spinner-border text-light mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
        <h3 class="text-white font-bold text-xl mb-2">Importing Students & Sending Emails...</h3>
        <p class="text-gray-300">Please do not close this window. This may take a few minutes depending on batch size.</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showLoading(event) {
            event.preventDefault(); // Stop normal form submission
            
            // Show a confirmation dialog first
            if (confirm("Are you sure you want to import these students? This will send verification emails to them.")) {
                // If confirmed, show the loading screen
                document.getElementById('loadingOverlay').style.display = 'flex';
                
                // Submit the form after a tiny delay so the UI updates
                setTimeout(() => {
                    event.target.submit();
                }, 100);
            }
        }
    </script>
</body>
</html>