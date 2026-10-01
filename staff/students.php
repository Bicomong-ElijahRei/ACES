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
if ($action === 'bulk_archive' && isset($_POST['ids'])) {
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

            $stmt = $pdo->prepare("UPDATE students SET student_id = ? WHERE student_id = ?");
            $stmt->execute([$new_student_id, $original_student_id]);

            $tables = ['attendance','registrations','student_module_progress','compliance','reminders_sent'];
            foreach ($tables as $tbl) {
                $stmt = $pdo->prepare("UPDATE {$tbl} SET student_id = ? WHERE student_id = ?");
                $stmt->execute([$new_student_id, $original_student_id]);
            }
            $student_id_to_update = $new_student_id;
        }

        $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ? WHERE user_id = (SELECT user_id FROM students WHERE student_id = ?)");
        $stmt->execute([$email, $full_name, $student_id_to_update]);

        $stmt = $pdo->prepare("UPDATE students SET program = ?, section = ?, middle_name = ? WHERE student_id = ?");
        $stmt->execute([$course, $section, $middle_name, $student_id_to_update]);

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
    // Fetch user_id BEFORE deleting student
    $stmt = $pdo->prepare("SELECT user_id FROM students WHERE student_id = ?");
    $stmt->execute([$id]);
    $user_id = $stmt->fetchColumn();
    // Delete student (CASCADE will handle attendance, registrations, etc.)
    $stmt_del_student = $pdo->prepare("DELETE FROM students WHERE student_id = ?");
    $stmt_del_student->execute([$id]);
    // Delete user
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

// Fetch filter values
$courses = $pdo->query("SELECT DISTINCT program FROM students WHERE is_deleted = 0 ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM students WHERE is_deleted = 0 ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

// Fetch active students
$sql_active = "SELECT s.student_id, s.program, s.section, s.middle_name, u.full_name, u.email FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.is_deleted = 0";
$params = [];
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

// Fetch archived students
$sql_archived = "SELECT s.student_id, s.program, s.section, s.middle_name, u.full_name, u.email FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.is_deleted = 1";
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

// Parse student name into parts using middle name
function parseStudentName($full_name, $middle_name) {
    $parts = explode(' ', trim($full_name));

    if (!empty($middle_name)) {
        $middle_parts = explode(' ', trim($middle_name));
        foreach ($middle_parts as $mp) {
            $key = array_search($mp, $parts);
            if ($key !== false) {
                unset($parts[$key]);
            }
        }
        $parts = array_values($parts);
    }

    $last = '';
    $first = '';
    if (count($parts) >= 2) {
        $last = array_pop($parts);
        $first = implode(' ', $parts);
    } elseif (count($parts) == 1) {
        $last = $parts[0];
        $first = '';
    }

    return [
        'first'  => $first,
        'last'   => $last,
        'middle' => $middle_name
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Information | ACES Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1050; overflow: auto; }
        .modal.show { display: block; }
        .modal-dialog { margin: 1.75rem auto; max-width: 500px; }
        .modal-content { background: white; border-radius: 0.5rem; overflow: hidden; }
        .modal-header, .modal-footer { padding: 1rem; border-bottom: 1px solid #e5e7eb; }
        .modal-footer { border-top: 1px solid #e5e7eb; }
        .btn-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #0a6e2d !important; color: white !important; border: none !important; }
        .dataTables_wrapper .dataTables_filter input { border-radius: 9999px; padding: 0.25rem 1rem; border: 1px solid #ddd; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">

    <?php include '../includes/staff_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-10 overflow-y-auto no-scrollbar">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-[#0a6e2d]">Student Information</h1>
            <a href="dashboard.php" class="text-[#0a6e2d]"><i class="fa-solid fa-house text-2xl"></i></a>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] == 'archived'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">Student(s) archived.</div>
            <?php elseif ($_GET['msg'] == 'restored'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">Student(s) restored.</div>
            <?php elseif ($_GET['msg'] == 'updated'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">Student updated.</div>
            <?php elseif ($_GET['msg'] == 'deleted'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">Student(s) permanently deleted.</div>
            <?php elseif ($_GET['msg'] == 'error'): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?= htmlspecialchars($_GET['detail'] ?? 'An error occurred.') ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Active Students table -->
        <div class="bg-white rounded shadow-2xl overflow-hidden">
            <div class="bg-[#054018] px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                <button id="bulkArchiveBtn" class="text-white text-sm flex items-center gap-2"><i class="fa-solid fa-box-archive"></i> Archive Selected</button>
                <div class="flex gap-4">
                    <button type="button" class="text-white text-sm flex items-center gap-1" data-bs-toggle="modal" data-bs-target="#filterModal"><i class="fa-solid fa-filter"></i> Filter</button>
                    <button id="exportBtn" class="text-white text-sm flex items-center gap-1"><i class="fa-solid fa-download"></i> Export</button>
                    <div class="relative">
                        <input type="text" id="searchInput" placeholder="Search name or ID..." class="py-1 px-4 pr-10 rounded-full text-xs outline-none w-48 md:w-64 border border-transparent focus:border-green-400 bg-white/10 text-white placeholder-white/70">
                        <i class="fa-solid fa-magnifying-glass absolute right-3 top-2 text-white/50 text-[10px]"></i>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table id="studentsTable" class="min-w-full bg-white text-left text-sm">
                    <thead>
                        <tr class="text-gray-800 font-bold border-b">
                            <th class="py-4 px-6 w-10"><input type="checkbox" id="selectAllCheckbox"></th>
                            <th class="py-4 px-6">Student No.</th>
                            <th class="py-4 px-2">Last Name</th>
                            <th class="py-4 px-2">First Name</th>
                            <th class="py-4 px-2">Middle Name</th>
                            <th class="py-4 px-2">Course</th>
                            <th class="py-4 px-2">Section</th>
                            <th class="py-4 px-2">Email</th>
                            <th class="py-4 px-2 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600">
                        <?php foreach ($active_students as $student):
                            $name_parts = parseStudentName($student['full_name'], $student['middle_name']);
                        ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50 transition" data-id="<?= $student['student_id'] ?>">
                                <td class="py-4 px-6 text-center"><input type="checkbox" class="row-checkbox" value="<?= $student['student_id'] ?>"></td>
                                <td class="py-4 px-6 font-mono"><?= htmlspecialchars($student['student_id']) ?></td>
                                <td class="py-4 px-2"><?= htmlspecialchars($name_parts['last']) ?></td>
                                <td class="py-4 px-2"><?= htmlspecialchars($name_parts['first']) ?></td>
                                <td class="py-4 px-2"><?= htmlspecialchars($student['middle_name']) ?></td>
                                <td class="py-4 px-2"><?= htmlspecialchars($student['program']) ?></td>
                                <td class="py-4 px-2"><?= htmlspecialchars($student['section']) ?></td>
                                <td class="py-4 px-2"><?= htmlspecialchars($student['email']) ?></td>
                                <td class="py-4 px-2 text-center">
                                    <button class="edit-student text-blue-500 hover:text-blue-700 mr-2"
                                        data-student-id="<?= $student['student_id'] ?>"
                                        data-last="<?= htmlspecialchars($name_parts['last']) ?>"
                                        data-first="<?= htmlspecialchars($name_parts['first']) ?>"
                                        data-middle="<?= htmlspecialchars($student['middle_name']) ?>"
                                        data-course="<?= htmlspecialchars($student['program']) ?>"
                                        data-section="<?= htmlspecialchars($student['section']) ?>"
                                        data-email="<?= htmlspecialchars($student['email']) ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="single_archive">
                                        <input type="hidden" name="id" value="<?= $student['student_id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700" onclick="return confirm('Archive this student?')"><i class="fa-solid fa-archive"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Archives Section -->
        <div class="mt-8">
            <div id="archiveHeader" class="flex justify-between items-center cursor-pointer bg-white rounded-t-lg px-6 py-3 shadow-md border border-gray-200">
                <div class="font-bold text-[#0a6e2d]"><i class="fas fa-archive mr-2"></i> Archives <span class="bg-gray-500 text-white rounded-full px-2 py-0.5 text-xs ml-2"><?= count($archived_students) ?></span></div>
                <i class="fas fa-chevron-down transition-transform duration-200 text-gray-500"></i>
            </div>
            <div id="archiveContent" class="hidden mt-1">
                <div class="bg-white rounded-b-lg shadow-md overflow-hidden border border-t-0 border-gray-200">
                    <div class="p-3 bg-gray-50 border-b flex justify-between items-center">
                        <div>
                            <button type="button" id="bulkRestoreBtn" class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm flex items-center gap-1">
                                <i class="fas fa-undo-alt"></i> Restore Selected
                            </button>
                            <button type="button" id="bulkDeleteBtn" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm flex items-center gap-1 ml-2">
                                <i class="fas fa-trash-alt"></i> Delete Selected Permanently
                            </button>
                        </div>
                        <button type="button" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm flex items-center gap-1" data-bs-toggle="modal" data-bs-target="#archiveFilterModal">
                            <i class="fas fa-filter"></i> Filter Archives
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table id="archiveTable" class="min-w-full bg-white text-sm">
                            <thead>
                                <tr class="text-gray-800 font-bold border-b">
                                    <th class="py-3 px-4 w-10"><input type="checkbox" id="selectAllArchiveCheckbox"></th>
                                    <th class="py-3 px-4">Student No.</th>
                                    <th class="py-3 px-4">Full Name</th>
                                    <th class="py-3 px-4">Course</th>
                                    <th class="py-3 px-4">Section</th>
                                    <th class="py-3 px-4">Email</th>
                                    <th class="py-3 px-4">Action</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600">
                                <?php foreach ($archived_students as $arch): ?>
                                <tr>
                                    <td class="py-3 px-4 text-center"><input type="checkbox" class="archive-checkbox" value="<?= $arch['student_id'] ?>"></td>
                                    <td class="py-3 px-4 border-b"><?= htmlspecialchars($arch['student_id']) ?></td>
                                    <td class="py-3 px-4 border-b"><?= htmlspecialchars($arch['full_name']) ?></td>
                                    <td class="py-3 px-4 border-b"><?= htmlspecialchars($arch['program']) ?></td>
                                    <td class="py-3 px-4 border-b"><?= htmlspecialchars($arch['section']) ?></td>
                                    <td class="py-3 px-4 border-b"><?= htmlspecialchars($arch['email']) ?></td>
                                    <td class="py-3 px-4 border-b">
                                        <form method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="single_restore">
                                            <input type="hidden" name="id" value="<?= $arch['student_id'] ?>">
                                            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-xs">Restore</button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this student? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="single_delete">
                                            <input type="hidden" name="id" value="<?= $arch['student_id'] ?>">
                                            <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-xs ml-1">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Filter Modal -->
    <div class="modal" id="filterModal" tabindex="-1">
        <div class="modal-dialog modal-lg max-w-2xl">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center p-4 border-b">
                    <h5 class="text-lg font-bold">Filter Students</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium">Course</label><select name="course" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($courses as $crs): ?><option value="<?= htmlspecialchars($crs) ?>" <?= isset($_GET['course']) && $_GET['course'] == $crs ? 'selected' : '' ?>><?= htmlspecialchars($crs) ?></option><?php endforeach; ?></select></div>
                        <div><label class="block text-sm font-medium">Section</label><select name="section" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($sections as $sec): ?><option value="<?= htmlspecialchars($sec) ?>" <?= isset($_GET['section']) && $_GET['section'] == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option><?php endforeach; ?></select></div>
                        <div class="col-span-2 text-right"><button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Apply Filters</button><a href="students.php" class="bg-gray-300 text-gray-800 px-4 py-2 rounded ml-2">Reset</a><button type="button" class="bg-gray-500 text-white px-4 py-2 rounded ml-2" data-bs-dismiss="modal">Cancel</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Archive Filter Modal -->
    <div class="modal" id="archiveFilterModal" tabindex="-1">
        <div class="modal-dialog modal-lg max-w-2xl">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center p-4 border-b">
                    <h5 class="text-lg font-bold">Filter Archives</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <form method="GET" id="archiveFilterForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="hidden" name="archive_filter" value="1">
                        <div><label class="block text-sm font-medium">Course</label><select name="arch_course" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($courses as $crs): ?><option value="<?= htmlspecialchars($crs) ?>" <?= isset($_GET['arch_course']) && $_GET['arch_course'] == $crs ? 'selected' : '' ?>><?= htmlspecialchars($crs) ?></option><?php endforeach; ?></select></div>
                        <div><label class="block text-sm font-medium">Section</label><select name="arch_section" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($sections as $sec): ?><option value="<?= htmlspecialchars($sec) ?>" <?= isset($_GET['arch_section']) && $_GET['arch_section'] == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option><?php endforeach; ?></select></div>
                        <div class="col-span-2 text-right"><button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Apply Filters</button><a href="students.php" class="bg-gray-300 text-gray-800 px-4 py-2 rounded ml-2">Reset All</a><button type="button" class="bg-gray-500 text-white px-4 py-2 rounded ml-2" data-bs-dismiss="modal">Cancel</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center p-4 border-b">
                    <h5 class="text-lg font-bold">Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal">&times;</button>
                </div>
                <form method="POST" id="editForm">
                    <?= csrf_field() ?>
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="student_id" id="edit_student_id">
                        <div class="mb-3"><label class="block text-sm font-medium">Last Name</label><input type="text" name="last_name" id="edit_last" class="w-full border rounded p-2" required></div>
                        <div class="mb-3"><label class="block text-sm font-medium">First Name</label><input type="text" name="first_name" id="edit_first" class="w-full border rounded p-2" required></div>
                        <div class="mb-3"><label class="block text-sm font-medium">Middle Name</label><input type="text" name="middle_name" id="edit_middle" class="w-full border rounded p-2"></div>
                        <div class="mb-3"><label class="block text-sm font-medium">Course</label><input type="text" name="course" id="edit_course" class="w-full border rounded p-2" required></div>
                        <div class="mb-3"><label class="block text-sm font-medium">Section</label><input type="text" name="section" id="edit_section" class="w-full border rounded p-2" required></div>
                        <div class="mb-3"><label class="block text-sm font-medium">Email</label><input type="email" name="email" id="edit_email" class="w-full border rounded p-2" required></div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium">New Student No. <span class="text-xs text-gray-500">(leave blank to keep current)</span></label>
                            <input type="text" name="new_student_id" id="edit_new_student_id" class="w-full border rounded p-2" placeholder="Leave blank to keep current">
                        </div>
                    </div>
                    <div class="modal-footer p-4 border-t flex justify-end gap-2">
                        <button type="button" class="bg-gray-300 text-gray-800 px-4 py-2 rounded" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center p-4 border-b">
                    <h5 class="text-lg font-bold">Export Students</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3"><label class="block text-sm font-medium">Format</label><select id="exportFormat" class="w-full border rounded p-2"><option value="csv">CSV (Excel compatible)</option><option value="pdf">PDF (Print to PDF)</option></select></div>
                    <div class="mb-3"><label class="block text-sm font-medium">Export scope</label><select id="exportScope" class="w-full border rounded p-2"><option value="all">All filtered students</option><option value="selected">Only selected students (checked rows)</option></select></div>
                    <div class="mb-3"><label class="block text-sm font-medium">Columns to include</label>
                        <div class="space-y-1"><div><input type="checkbox" id="col_student_no" checked> <label>Student No.</label></div><div><input type="checkbox" id="col_last_name" checked> <label>Last Name</label></div><div><input type="checkbox" id="col_first_name" checked> <label>First Name</label></div><div><input type="checkbox" id="col_middle_name" checked> <label>Middle Name</label></div><div><input type="checkbox" id="col_course" checked> <label>Course</label></div><div><input type="checkbox" id="col_section" checked> <label>Section</label></div><div><input type="checkbox" id="col_email" checked> <label>Email</label></div></div>
                    </div>
                </div>
                <div class="modal-footer p-4 border-t flex justify-end gap-2">
                    <button type="button" class="bg-gray-300 text-gray-800 px-4 py-2 rounded" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="doExport" class="bg-green-600 text-white px-4 py-2 rounded">Export</button>
                </div>
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
            columnDefs: [{ orderable: false, targets: [0, 8] }],
            paging: true,
            searching: true,
            pageLength: 10,
            language: { search: "" },
            dom: 'lrtip'
        });
        $('#searchInput').on('keyup', function() { table.search($(this).val()).draw(); });

        $('#selectAllCheckbox').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('.row-checkbox:visible').prop('checked', isChecked);
        });
        $('#studentsTable tbody').on('change', '.row-checkbox', function() {
            var totalVisible = $('.row-checkbox:visible').length;
            var checkedVisible = $('.row-checkbox:visible:checked').length;
            $('#selectAllCheckbox').prop('checked', totalVisible > 0 && checkedVisible === totalVisible);
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

        $('.edit-student').on('click', function() {
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

            var params = new URLSearchParams();
            params.append('action', format === 'csv' ? 'export_csv' : 'export_pdf');
            params.append('ids', studentIds.join(','));
            params.append('cols', columns.join(','));
            if (format === 'csv') window.location.href = 'export_students.php?' + params.toString();
            else window.open('export_students.php?' + params.toString(), '_blank');
        });
    });
    </script>
</body>
</html>