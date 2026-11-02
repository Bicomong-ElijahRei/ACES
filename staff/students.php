<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

// Block Viewer POST actions
if (isViewer() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: students.php?msg=error&detail=' . urlencode('Access denied'));
    exit;
}

// ---- CSRF check on any POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'] ?? '';

// Handle actions
$action = $_POST['action'] ?? '';

// --- Active student actions ---
if ($action === 'bulk_staff_verify' && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    // Update registration_source to 'staff_verified' for the selected students
    $stmt = $pdo->prepare("
        UPDATE users u 
        JOIN students s ON u.user_id = s.user_id 
        SET u.registration_source = 'staff_verified' 
        WHERE s.student_id IN ($placeholders)
    ");
    $stmt->execute($ids);
    header('Location: students.php?msg=verified');
    exit;
} elseif ($action === 'bulk_archive' && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE students SET is_deleted = 1 WHERE student_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: students.php?msg=archived');
    exit;
} elseif ($action === 'single_archive' && isset($_POST['id'])) {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("UPDATE students SET is_deleted = 1 WHERE student_id = ?");
    $stmt->execute([$id]);
    header('Location: students.php?msg=archived');
    exit;
} elseif ($action === 'update_student' && isset($_POST['student_id'])) {
    $original_student_id = $_POST['student_id'];
    $new_student_id      = trim($_POST['new_student_id'] ?? '');
    $first_name   = trim($_POST['first_name']);
    $last_name    = trim($_POST['last_name']);
    $middle_name  = trim($_POST['middle_name']);
    $course       = trim($_POST['course']);
    $section      = trim($_POST['section']);
    $email        = trim($_POST['email']);

    $full_name = $first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name;
    $full_name = preg_replace('/\s+/', ' ', $full_name);

    $student_id_to_update = $original_student_id;
    try {
        $pdo->beginTransaction();

        if (!empty($new_student_id) && $new_student_id !== $original_student_id) {
            $check = $pdo->prepare("SELECT student_id FROM students WHERE student_id = ?");
            $check->execute([$new_student_id]);
            if ($check->fetch()) {
                throw new Exception("Student number {$new_student_id} already exists.");
            }

            // DISABLE FK CHECKS so we can update the primary key across all tables
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $stmt = $pdo->prepare("UPDATE students SET student_id = ? WHERE student_id = ?");
            $stmt->execute([$new_student_id, $original_student_id]);

            $tables = ['attendance','registrations','student_module_progress','compliance','reminders_sent'];
            foreach ($tables as $tbl) {
                $stmt = $pdo->prepare("UPDATE {$tbl} SET student_id = ? WHERE student_id = ?");
                $stmt->execute([$new_student_id, $original_student_id]);
            }

            // RE-ENABLE FK CHECKS
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            $student_id_to_update = $new_student_id;
        }

        // NEW: Check if the email is already used by ANOTHER student
        $check_email = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != (SELECT user_id FROM students WHERE student_id = ?)");
        $check_email->execute([$email, $student_id_to_update]);
        if ($check_email->fetch()) {
            throw new Exception("The email address {$email} is already in use by another account.");
        }

        $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ? WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
        $stmt->execute([$email, $full_name, $student_id_to_update]);

        $stmt = $pdo->prepare("UPDATE students SET program = ?, section = ?, middle_name = ?, first_name = ?, last_name = ? WHERE student_id = ?");
        $stmt->execute([$course, $section, $middle_name, $first_name, $last_name, $student_id_to_update]);

        $pdo->commit();
        header('Location: students.php?msg=updated');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Update failed: " . $e->getMessage();
        header('Location: students.php?msg=error&detail=' . urlencode($error));
        exit;
    }

// --- Archive student actions ---
} elseif ($action === 'single_restore' && isset($_POST['id'])) {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("UPDATE students SET is_deleted = 0 WHERE student_id = ?");
    $stmt->execute([$id]);
    header('Location: students.php?msg=restored');
    exit;
} elseif ($action === 'bulk_restore' && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE students SET is_deleted = 0 WHERE student_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: students.php?msg=restored');
    exit;
} elseif ($action === 'single_delete' && isset($_POST['id'])) {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("SELECT user_id FROM students WHERE student_id = ?");
    $stmt->execute([$id]);
    $user_id = $stmt->fetchColumn();
    $stmt_del_student = $pdo->prepare("DELETE FROM students WHERE student_id = ?");
    $stmt_del_student->execute([$id]);
    if ($user_id) {
        $stmt_del_user = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt_del_user->execute([$user_id]);
    }
    header('Location: students.php?msg=deleted');
    exit;
} elseif ($action === 'bulk_delete' && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT user_id FROM students WHERE student_id IN ($placeholders)");
    $stmt->execute($ids);
    $user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($user_ids)) {
        $placeholders_u = implode(',', array_fill(0, count($user_ids), '?'));
        $stmt_del_users = $pdo->prepare("DELETE FROM users WHERE user_id IN ($placeholders_u)");
        $stmt_del_users->execute($user_ids);
    }
    $stmt_del = $pdo->prepare("DELETE FROM students WHERE student_id IN ($placeholders)");
    $stmt_del->execute($ids);
    header('Location: students.php?msg=deleted');
    exit;
}

// Fetch filter values directly from the new configuration tables
$courses = $pdo->query("SELECT name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT name FROM sections ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Fetch active students
$sql_active = "SELECT s.student_id, s.program, s.section, s.first_name, s.last_name, s.middle_name, u.full_name, u.email, u.is_verified, u.verification_token, u.registration_source FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.is_deleted = 0";
$params = [];

// NEW: Filter by manual source
if (isset($_GET['source']) && $_GET['source'] === 'manual') {
    $sql_active .= " AND u.registration_source = 'manual' AND u.is_verified = 1";
}

if (isset($_GET['course']) && $_GET['course'] != '') {
    $sql_active .= " AND s.program = ?";
    $params[] = $_GET['course'];
}
if (isset($_GET['section']) && $_GET['section'] != '') {
    $sql_active .= " AND s.section = ?";
    $params[] = $_GET['section'];
}
$stmt_active = $pdo->prepare($sql_active);
$stmt_active->execute($params);
$active_students = $stmt_active->fetchAll();

// NEW: Fetch Past Subtopics for the Make-Up Dropdown
$past_subtopics = $pdo->query("
    SELECT st.subtopic_id, st.title as sub_title, s.title as sess_title, s.date 
    FROM subtopics st 
    JOIN sessions s ON st.session_id = s.session_id 
    WHERE st.is_deleted = 0 AND s.is_deleted = 0 AND s.date <= CURDATE()
    ORDER BY s.date DESC
")->fetchAll();

// Fetch archived students
$sql_archived = "SELECT s.student_id, s.program, s.section, s.first_name, s.last_name, s.middle_name, u.email, u.is_verified, u.verification_token FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.is_deleted = 1";
$arch_params = [];
if (isset($_GET['arch_course']) && $_GET['arch_course'] != '') {
    $sql_archived .= " AND s.program = ?";
    $arch_params[] = $_GET['arch_course'];
}
if (isset($_GET['arch_section']) && $_GET['arch_section'] != '') {
    $sql_archived .= " AND s.section = ?";
    $arch_params[] = $_GET['arch_section'];
}
$stmt_archived = $pdo->prepare($sql_archived);
$stmt_archived->execute($arch_params);
$archived_students = $stmt_archived->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Information | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <!-- Add Bootstrap CSS here for the modals to work -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <style>
        /* DataTables Overrides to match Tailwind */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { 
            background: #0a6e2d !important; 
            color: white !important; 
            border: none !important; 
            border-radius: 0.375rem;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #054018 !important;
            color: white !important;
            border: none !important;
        }
        .dataTables_wrapper .dataTables_info {
            font-size: 0.875rem;
            color: #6b7280;
            padding-top: 1rem;
        }
        .dataTables_length select {
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
            padding: 0.25rem 0.5rem;
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">

    <?php include '../includes/staff_sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/header.php'; ?> <!-- THIS CONTAINS THE HAMBURGER ICON -->
        
        <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">
            
            <!-- Page Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-[#0a6e2d]">Student Information</h1>
                <p class="text-sm text-gray-600 mt-1">Manage active students and view archived records.</p>
            </div>
            <a href="dashboard.php" class="text-[#0a6e2d] bg-white p-2 rounded-full shadow-sm hover:shadow-md transition-shadow">
                <i class="fa-solid fa-house text-xl"></i>
            </a>
        </div>

        <!-- NEW: FILTER NOTIFICATION -->
        <?php if (isset($_GET['source']) && $_GET['source'] === 'manual'): ?>
            <div class="mb-6 bg-blue-50 border-l-4 border-blue-500 text-blue-800 p-4 rounded-r-lg shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <i class="fas fa-filter text-blue-500 text-xl"></i>
                    <div>
                        <p class="font-bold">Filter Applied: Manual Registrations</p>
                        <p class="text-sm">Showing only students who self-registered and verified their email. Please review and click the blue checkmark to approve them.</p>
                    </div>
                </div>
                <a href="students.php" class="bg-white border border-blue-300 text-blue-700 hover:bg-blue-100 px-4 py-2 rounded-lg text-sm font-bold shadow-sm transition whitespace-nowrap">
                    Clear Filter
                </a>
            </div>
        <?php endif; ?>

        <!-- Flash Messages -->
        <?php if (isset($_GET['msg'])): ?>
            <div class="mb-6 animate-fade-in-down">
                <?php if ($_GET['msg'] == 'verified'): ?>
                    <div class="bg-purple-50 border-l-4 border-purple-500 text-purple-700 p-4 rounded-r-lg shadow-sm flex items-center gap-3">
                        <i class="fas fa-shield-alt"></i> Selected student(s) successfully verified and approved.
                    </div>
                <?php elseif ($_GET['msg'] == 'archived'): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm flex items-center gap-3">
                        <i class="fas fa-check-circle"></i> Student(s) archived.
                    </div>
                <?php elseif ($_GET['msg'] == 'restored'): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm flex items-center gap-3">
                        <i class="fas fa-check-circle"></i> Student(s) restored.
                    </div>
                <?php elseif ($_GET['msg'] == 'updated'): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm flex items-center gap-3">
                        <i class="fas fa-check-circle"></i> Student updated.
                    </div>
                <?php elseif ($_GET['msg'] == 'deleted'): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm flex items-center gap-3">
                        <i class="fas fa-check-circle"></i> Student(s) permanently deleted.
                    </div>
                <?php elseif ($_GET['msg'] == 'error'): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg shadow-sm flex items-center gap-3">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($_GET['detail'] ?? 'An error occurred.') ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Active Students Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-8">
            
            <!-- Modern Toolbar (Bootstrap Layout) -->
            <div class="p-3 border-bottom bg-white d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    
                    <!-- NEW: UNIFIED BULK ACTIONS DROPDOWN -->
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary fw-medium dropdown-toggle d-flex align-items-center gap-2 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-list-check"></i> Bulk Actions
                        </button>
                        <ul class="dropdown-menu shadow" style="font-size: 0.875rem;">
                            <li>
                                <button id="bulkVerifyBtn" class="dropdown-item text-primary fw-medium">
                                    <i class="fa-solid fa-user-check me-2"></i> Verify Selected
                                </button>
                            </li>
                            <li>
                                <button id="createMakeupBtn" class="dropdown-item text-warning fw-medium">
                                    <i class="fa-solid fa-calendar-plus me-2"></i> Create Make-Up
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button id="bulkArchiveBtn" class="dropdown-item text-danger fw-medium">
                                    <i class="fa-solid fa-box-archive me-2"></i> Archive Selected
                                </button>
                            </li>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary fw-medium d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#filterModal">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                    <button id="exportBtn" class="btn btn-sm btn-outline-secondary fw-medium d-flex align-items-center gap-2 shadow-sm">
                        <i class="fa-solid fa-download"></i> Export
                    </button>
                    
                    <a href="manage_courses.php" class="btn btn-sm btn-outline-secondary fw-medium d-flex align-items-center gap-2 shadow-sm">
                        <i class="fa-solid fa-graduation-cap text-success"></i> Manage Courses
                    </a>

                    <!-- NEW: CSV IMPORT BUTTON -->
                    <a href="import_students.php" class="btn btn-sm text-white fw-medium d-flex align-items-center gap-2 shadow-sm" style="background-color: #9333ea; border-color: #9333ea;">
                        <i class="fa-solid fa-file-csv"></i> Import CSV
                    </a>
                </div>
                
                <div class="position-relative w-100" style="max-width: 320px;">
                    <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="left: 10px; top: 50%; transform: translateY(-50%);"></i>
                    <input type="text" id="searchInput" placeholder="Search name or ID..." class="form-control form-control-sm ps-4">
                </div>
            </div>

            <!-- Table Container -->
            <div class="overflow-x-auto">
                <table id="studentsTable" class="min-w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                            <th class="py-4 px-6 w-10"><input type="checkbox" id="selectAllCheckbox" class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]"></th>
                            <th class="py-4 px-6">Student No.</th>
                            <th class="py-4 px-4">Last Name</th>
                            <th class="py-4 px-4">First Name</th>
                            <th class="py-4 px-4">Middle Name</th>
                            <th class="py-4 px-4">Course</th>
                            <th class="py-4 px-4">Section</th>
                            <th class="py-4 px-4">Email</th>
                            <th class="py-4 px-4">Status</th>
                            <th class="py-4 px-6 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700 bg-white">
                        <?php foreach ($active_students as $student): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150" data-id="<?= $student['student_id'] ?>">
                                <td class="py-4 px-6 text-center"><input type="checkbox" class="row-checkbox rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" value="<?= $student['student_id'] ?>"></td>
                                <td class="py-4 px-6 font-mono text-xs font-medium text-gray-600"><?= htmlspecialchars($student['student_id']) ?></td>
                                <td class="py-4 px-4 font-medium text-gray-900"><?= htmlspecialchars($student['last_name'] ?? '') ?></td>
                                <td class="py-4 px-4"><?= htmlspecialchars($student['first_name'] ?? '') ?></td>
                                <td class="py-4 px-4 text-gray-500"><?= htmlspecialchars($student['middle_name'] ?? '') ?></td>
                                <td class="py-4 px-4 text-xs"><?= htmlspecialchars($student['program']) ?></td>
                                <td class="py-4 px-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        <?= htmlspecialchars($student['section']) ?>
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-gray-500 text-xs"><?= htmlspecialchars($student['email']) ?></td>
                                <td class="py-4 px-4">
                                    <?php 
                                    $source = $student['registration_source'];
                                    
                                    if ($student['is_verified']): 
                                        if ($source === 'staff_verified'):
                                    ?>
                                        <!-- Verified via Staff Approval -->
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                            <i class="fas fa-shield-alt mr-1"></i> Staff Approved
                                        </span>
                                        <?php elseif ($source === 'csv'): ?>
                                        <!-- Verified via CSV (Trusted) -->
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-green-100 text-green-800 border border-green-200" title="Imported by Staff">
                                            <i class="fas fa-file-csv mr-1"></i> Verified (CSV)
                                        </span>
                                        <?php else: ?>
                                        <!-- Verified via Manual (Needs Review) -->
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-200" title="Self-registered via portal">
                                            <i class="fas fa-user-edit mr-1"></i> Verified (Manual)
                                        </span>
                                        <?php endif; ?>
                                        
                                    <?php elseif (!empty($student['verification_token'])): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                            <i class="fas fa-envelope mr-1"></i> Email Sent
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-600 border border-gray-200">
                                            <i class="fas fa-times-circle mr-1"></i> Not Verified
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        
                                        <!-- NEW: INDIVIDUAL VERIFY BUTTON -->
                                        <?php if ($student['registration_source'] === 'manual'): ?>
                                            <form method="POST" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="bulk_staff_verify">
                                                <input type="hidden" name="ids" value="<?= $student['student_id'] ?>">
                                                <button type="submit" class="text-white bg-blue-500 hover:bg-blue-600 p-2 rounded-lg transition-colors" title="Verify this student">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <button class="edit-student text-blue-600 hover:text-blue-900 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg transition-colors"
                                            data-student-id="<?= $student['student_id'] ?>"
                                            data-last="<?= htmlspecialchars($student['last_name'] ?? '') ?>"
                                            data-first="<?= htmlspecialchars($student['first_name'] ?? '') ?>"
                                            data-middle="<?= htmlspecialchars($student['middle_name']) ?>"
                                            data-course="<?= htmlspecialchars($student['program']) ?>"
                                            data-section="<?= htmlspecialchars($student['section']) ?>"
                                            data-email="<?= htmlspecialchars($student['email']) ?>"
                                            title="Edit">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="single_archive">
                                            <input type="hidden" name="id" value="<?= $student['student_id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-900 bg-red-50 hover:bg-red-100 p-2 rounded-lg transition-colors" onclick="return confirm('Archive this student?')" title="Archive">
                                                <i class="fa-solid fa-archive"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Archives Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div id="archiveHeader" class="px-6 py-4 flex justify-between items-center cursor-pointer hover:bg-gray-50 transition-colors">
                <div class="flex items-center gap-2 font-bold text-gray-800">
                    <i class="fas fa-archive text-gray-400"></i> Archived Students 
                    <span class="bg-gray-100 text-gray-600 py-0.5 px-2.5 rounded-full text-xs font-semibold ml-1"><?= count($archived_students) ?></span>
                </div>
                <i class="fas fa-chevron-down text-gray-400 transition-transform duration-200"></i>
            </div>
            
            <div id="archiveContent" class="hidden border-t border-gray-200">
                <div class="p-4 bg-gray-50 flex flex-wrap justify-between items-center gap-3">
                    <div class="flex gap-2">
                        <button type="button" id="bulkRestoreBtn" class="bg-white border border-gray-300 hover:bg-green-50 text-green-700 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 shadow-sm">
                            <i class="fas fa-undo-alt"></i> Restore
                        </button>
                        <button type="button" id="bulkDeleteBtn" class="bg-white border border-gray-300 hover:bg-red-50 text-red-600 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 shadow-sm">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </div>
                    <button type="button" class="bg-white border border-gray-300 hover:bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#archiveFilterModal">
                        <i class="fas fa-filter text-gray-500"></i> Filter
                    </button>
                </div>
                
                <div class="overflow-x-auto">
                    <table id="archiveTable" class="min-w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-white border-b border-gray-200">
                            <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                                <th class="py-3 px-6 w-10"><input type="checkbox" id="selectAllArchiveCheckbox" class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]"></th>
                                <th class="py-3 px-6">Student No.</th>
                                <th class="py-3 px-6">Full Name</th>
                                <th class="py-3 px-6">Course</th>
                                <th class="py-3 px-6">Section</th>
                                <th class="py-3 px-6">Email</th>
                                <th class="py-3 px-6 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700 bg-white">
                            <?php foreach ($archived_students as $arch): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="py-3 px-6 text-center"><input type="checkbox" class="archive-checkbox rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" value="<?= $arch['student_id'] ?>"></td>
                                <td class="py-3 px-6 font-mono text-xs"><?= htmlspecialchars($arch['student_id']) ?></td>
                                <td class="py-3 px-6 font-medium text-gray-900"><?= htmlspecialchars(trim(($arch['last_name'] ?? '') . ', ' . ($arch['first_name'] ?? ''), ', ')) ?></td>
                                <td class="py-3 px-6 text-xs"><?= htmlspecialchars($arch['program']) ?></td>
                                <td class="py-3 px-6"><span class="bg-gray-100 text-gray-800 py-0.5 px-2 rounded-full text-xs"><?= htmlspecialchars($arch['section']) ?></span></td>
                                <td class="py-3 px-6 text-xs text-gray-500"><?= htmlspecialchars($arch['email']) ?></td>
                                <td class="py-3 px-6 text-right">
                                    <div class="flex justify-end gap-2">
                                        <form method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="single_restore">
                                            <input type="hidden" name="id" value="<?= $arch['student_id'] ?>">
                                            <button type="submit" class="bg-green-50 text-green-700 hover:bg-green-100 px-3 py-1 rounded-md text-xs font-medium transition-colors border border-green-200">Restore</button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this student? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="single_delete">
                                            <input type="hidden" name="id" value="<?= $arch['student_id'] ?>">
                                            <button type="submit" class="bg-red-50 text-red-700 hover:bg-red-100 px-3 py-1 rounded-md text-xs font-medium transition-colors border border-red-200">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
                <div class="modal-header bg-gray-50 border-b border-gray-200 p-4">
                    <h5 class="text-lg font-bold text-gray-800"><i class="fas fa-filter text-gray-500 mr-2"></i>Filter Students</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-6">
                    <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Course</label>
                            <select name="course" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $crs): ?>
                                    <option value="<?= htmlspecialchars($crs) ?>" <?= isset($_GET['course']) && $_GET['course'] == $crs ? 'selected' : '' ?>><?= htmlspecialchars($crs) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                            <select name="section" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $sec): ?>
                                    <option value="<?= htmlspecialchars($sec) ?>" <?= isset($_GET['section']) && $_GET['section'] == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-2 flex justify-end gap-2 mt-4 pt-4 border-t border-gray-100">
                            <a href="students.php" class="bg-gray-100 text-gray-700 hover:bg-gray-200 px-4 py-2 rounded-lg text-sm font-medium transition-colors">Reset</a>
                            <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Apply Filters</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Archive Filter Modal -->
    <div class="modal fade" id="archiveFilterModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
                <div class="modal-header bg-gray-50 border-b border-gray-200 p-4">
                    <h5 class="text-lg font-bold text-gray-800"><i class="fas fa-filter text-gray-500 mr-2"></i>Filter Archives</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-6">
                    <form method="GET" id="archiveFilterForm" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <input type="hidden" name="archive_filter" value="1">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Course</label>
                            <select name="arch_course" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $crs): ?>
                                    <option value="<?= htmlspecialchars($crs) ?>" <?= isset($_GET['arch_course']) && $_GET['arch_course'] == $crs ? 'selected' : '' ?>><?= htmlspecialchars($crs) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                            <select name="arch_section" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $sec): ?>
                                    <option value="<?= htmlspecialchars($sec) ?>" <?= isset($_GET['arch_section']) && $_GET['arch_section'] == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-2 flex justify-end gap-2 mt-4 pt-4 border-t border-gray-100">
                            <a href="students.php" class="bg-gray-100 text-gray-700 hover:bg-gray-200 px-4 py-2 rounded-lg text-sm font-medium transition-colors">Reset All</a>
                            <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Apply Filters</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
                <div class="modal-header bg-gray-50 border-b border-gray-200 p-4">
                    <h5 class="text-lg font-bold text-gray-800">Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm">
                    <?= csrf_field() ?>
                    <div class="modal-body p-6 space-y-4">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="student_id" id="edit_student_id">
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                                <input type="text" name="last_name" id="edit_last" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                                <input type="text" name="first_name" id="edit_first" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Middle Name</label>
                            <input type="text" name="middle_name" id="edit_middle" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Course</label>
                                <input type="text" name="course" id="edit_course" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                                <input type="text" name="section" id="edit_section" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" id="edit_email" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                        </div>
                        
                        <div class="bg-blue-50 p-3 rounded-lg border border-blue-100">
                            <label class="block text-sm font-medium text-blue-800 mb-1">New Student No. <span class="text-xs font-normal">(leave blank to keep current)</span></label>
                            <input type="text" name="new_student_id" id="edit_new_student_id" class="w-full border border-blue-300 rounded-lg p-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Leave blank to keep current">
                        </div>
                    </div>
                    <div class="modal-footer bg-gray-50 border-t border-gray-200 p-4">
                        <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <!-- ... export modal content ... -->
    </div>

    <!-- NEW: Make-Up Scenario Modal -->
    <div class="modal fade" id="makeupModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
                <div class="modal-header bg-amber-50 border-b border-amber-200 p-4">
                    <h5 class="text-lg font-bold text-amber-800"><i class="fas fa-calendar-plus mr-2"></i>Create Make-Up Scenario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="makeupForm" method="POST" action="process_makeup.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="student_ids" id="makeup_student_ids">
                    <div class="modal-body p-6 space-y-4">
                        
                        <div class="bg-red-50 border border-red-200 p-3 rounded-lg text-sm text-red-800 mb-4">
                            <i class="fas fa-exclamation-triangle mr-1"></i> This will create a new Session, link it to the missed session, register the students, and set their attendance to Pending.
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">1. Which Session did they miss?</label>
                            <select name="missed_subtopic_id" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-amber-500 focus:border-amber-500">
                                <option value="">-- Select Past Session --</option>
                                <?php foreach ($past_subtopics as $ps): ?>
                                    <option value="<?= $ps['subtopic_id'] ?>">
                                        <?= date('M d, Y', strtotime($ps['date'])) ?> | <?= htmlspecialchars($ps['sess_title']) ?> - <?= htmlspecialchars($ps['sub_title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="border-t border-gray-100 my-4"></div>

                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">2. Make-Up Session Title</label>
                            <input type="text" name="title" required placeholder="e.g., Make-Up: Professional Etiquette" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-amber-500 focus:border-amber-500">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input type="date" name="date" required min="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 rounded-lg p-2 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phase</label>
                                <select name="phase" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="Preparation">Preparation</option>
                                    <option value="Pre-Employment">Pre-Employment</option>
                                    <option value="Career Fair">Career Fair</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                                <input type="time" name="start_time" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                                <input type="time" name="end_time" required class="w-full border border-gray-300 rounded-lg p-2 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-gray-50 border-t border-gray-200 p-4">
                        <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Generate Make-Up</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const CSRF_TOKEN = '<?= csrf_token() ?>';

    $(document).ready(function() {
        var table = $('#studentsTable').DataTable({
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 9] }],
            paging: true,
            searching: true,
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]], // <-- ADD THIS LINE
            language: { search: "" },
            dom: 'lrtip'
        });
        
        $('#searchInput').on('keyup', function() { table.search($(this).val()).draw(); });

        // --- NEW: Auto-search when arriving from a link (e.g., from a Support Ticket) ---
        const urlParams = new URLSearchParams(window.location.search);
        const searchParam = urlParams.get('search');
        if (searchParam) {
            $('#searchInput').val(searchParam);       // Fill the search box
            table.search(searchParam).draw();         // Filter the table instantly
        }
        // ---------------------------------------------------------------------------------

        $('#selectAllCheckbox').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('.row-checkbox:visible').prop('checked', isChecked);
        });
        
        $('#studentsTable tbody').on('change', '.row-checkbox', function() {
            var totalVisible = $('.row-checkbox:visible').length;
            var checkedVisible = $('.row-checkbox:visible:checked').length;
            $('#selectAllCheckbox').prop('checked', totalVisible > 0 && checkedVisible === totalVisible);
        });

        // NEW: BULK VERIFY LOGIC
        $('#bulkVerifyBtn').on('click', function() {
            var ids = [];
            $('.row-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
            if (ids.length === 0) { alert('Please select at least one visible student.'); return; }
            if (confirm('Verify and approve ' + ids.length + ' selected student(s)? This will mark them as officially verified by staff.')) {
                var form = $('<form method="POST"></form>');
                form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
                form.append('<input type="hidden" name="action" value="bulk_staff_verify">');
                form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
                $('body').append(form); form.submit();
            }
        });

        // NEW: MAKE-UP SCENARIO LOGIC
        $('#createMakeupBtn').on('click', function() {
            var ids = [];
            $('.row-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
            
            if (ids.length === 0) { 
                alert('Please select at least one student to create a make-up scenario for.'); 
                return; 
            }
            
            $('#makeup_student_ids').val(ids.join(','));
            $('#makeupModal').modal('show');
        });

        $('#bulkArchiveBtn').on('click', function() {
            var ids = [];
            $('.row-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
            if (ids.length === 0) { alert('Please select at least one visible student.'); return; }
            if (confirm('Archive ' + ids.length + ' selected students?')) {
                var form = $('<form method="POST"></form>');
                form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
                form.append('<input type="hidden" name="action" value="bulk_archive">');
                form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
                $('body').append(form); form.submit();
            }
        });

        // FIX: Use event delegation on the table so it works even after DataTables search/pagination
        $('#studentsTable').on('click', '.edit-student', function() {
            $('#edit_student_id').val($(this).data('student-id'));
            $('#edit_new_student_id').val($(this).data('student-id'));
            $('#edit_last').val($(this).data('last'));
            $('#edit_first').val($(this).data('first'));
            $('#edit_middle').val($(this).data('middle'));
            $('#edit_course').val($(this).data('course'));
            $('#edit_section').val($(this).data('section'));
            $('#edit_email').val($(this).data('email'));
            $('#editModal').modal('show');
        });

        $('#archiveHeader').on('click', function() {
            $('#archiveContent').toggleClass('hidden');
            $(this).find('.fa-chevron-down').toggleClass('rotate-180');
        });

        var archiveTable = $('#archiveTable').DataTable({ order: [[1, 'desc']], paging: false, info: false, searching: false });

        $('#selectAllArchiveCheckbox').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('.archive-checkbox:visible').prop('checked', isChecked);
        });
        
        $('#archiveTable tbody').on('change', '.archive-checkbox', function() {
            var total = $('.archive-checkbox:visible').length;
            var checked = $('.archive-checkbox:visible:checked').length;
            $('#selectAllArchiveCheckbox').prop('checked', total > 0 && checked === total);
        });

        $('#bulkRestoreBtn').on('click', function() {
            var ids = [];
            $('.archive-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
            if (ids.length === 0) { alert('Select at least one archived student.'); return; }
            if (confirm('Restore ' + ids.length + ' students?')) {
                var form = $('<form method="POST"></form>');
                form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
                form.append('<input type="hidden" name="action" value="bulk_restore">');
                form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
                $('body').append(form); form.submit();
            }
        });

        $('#bulkDeleteBtn').on('click', function() {
            var ids = [];
            $('.archive-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
            if (ids.length === 0) { alert('Select at least one archived student.'); return; }
            if (confirm('PERMANENTLY DELETE ' + ids.length + ' students? This cannot be undone!')) {
                var form = $('<form method="POST"></form>');
                form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
                form.append('<input type="hidden" name="action" value="bulk_delete">');
                form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
                $('body').append(form); form.submit();
            }
        });

        $('#exportBtn').on('click', function() { $('#exportModal').modal('show'); });
        
        $('#doExport').on('click', function() {
            var format = $('#exportFormat').val();
            var scope = $('#exportScope').val();
            var columns = [];
            if ($('#col_student_no').is(':checked')) columns.push('student_id');
            if ($('#col_last_name').is(':checked')) columns.push('last_name');
            if ($('#col_first_name').is(':checked')) columns.push('first_name');
            if ($('#col_middle_name').is(':checked')) columns.push('middle_name');
            if ($('#col_course').is(':checked')) columns.push('course');
            if ($('#col_section').is(':checked')) columns.push('section');
            if ($('#col_email').is(':checked')) columns.push('email');
            if (columns.length === 0) { alert('Please select at least one column.'); return; }

            var studentIds = [];
            if (scope === 'selected') {
                $('.row-checkbox:visible:checked').each(function() { studentIds.push($(this).val()); });
                if (studentIds.length === 0) { alert('Please select at least one student.'); return; }
            } else {
                table.rows({ filter: 'applied' }).every(function() { var id = $(this.node()).data('id'); if (id) studentIds.push(id); });
                if (studentIds.length === 0) { alert('No students to export.'); return; }
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_students.php';
            form.target = format === 'csv' ? '_self' : '_blank';
            form.style.display = 'none';

            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                <input type="hidden" name="action" value="${format === 'csv' ? 'export_csv' : 'export_pdf'}">
                <input type="hidden" name="ids" value="${studentIds.join(',')}">
                <input type="hidden" name="cols" value="${columns.join(',')}">
            `;

            document.body.appendChild(form);
            form.submit();
            form.remove();
        });
    });
    </script>
</body>
</html>