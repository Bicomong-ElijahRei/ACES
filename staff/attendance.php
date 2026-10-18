<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

// Block Viewer POST actions
if (isViewer() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: attendance.php?error=Access denied');
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
$staff_name = $staff['full_name'];

// Handle bulk actions
$action = $_POST['action'] ?? '';
if ($action === 'bulk_delete' && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE attendance SET is_deleted = 1 WHERE attendance_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: attendance.php?deleted=1');
    exit;
} elseif ($action === 'bulk_restore' && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE attendance SET is_deleted = 0 WHERE attendance_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: attendance.php?restored=1');
    exit;
} elseif ($action === 'single_restore' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE attendance SET is_deleted = 0 WHERE attendance_id = ?");
    $stmt->execute([$id]);
    header('Location: attendance.php?restored=1');
    exit;
} elseif ($action === 'single_permanent_delete' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("DELETE FROM attendance WHERE attendance_id = ?");
    $stmt->execute([$id]);
    header('Location: attendance.php?permanently_deleted=1');
    exit;
} elseif ($action === 'bulk_permanent_delete' && isset($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM attendance WHERE attendance_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: attendance.php?permanently_deleted=1');
    exit;
} elseif ($action === 'change_status' && isset($_POST['ids']) && isset($_POST['new_status'])) {
    $ids = explode(',', $_POST['ids']);
    $new_status = $_POST['new_status'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE attendance SET attendance_status = ?, validated = IF(? = 'present', 1, 0), auto_generated = 0 WHERE attendance_id IN ($placeholders)");
    $stmt->execute([$new_status, $new_status, ...$ids]);
    header('Location: attendance.php?status_updated=1');
    exit;
} elseif ($action === 'single_change' && isset($_POST['id']) && isset($_POST['status'])) {
    $id = (int)$_POST['id'];
    $new_status = $_POST['status'];
    $stmt = $pdo->prepare("UPDATE attendance SET attendance_status = ?, validated = IF(? = 'present', 1, 0), auto_generated = 0 WHERE attendance_id = ?");
    $stmt->execute([$new_status, $new_status, $id]);
    header('Location: attendance.php?status_updated=1');
    exit;
}

// Get filter values
$subtopics = $pdo->query("SELECT subtopic_id, title FROM subtopics ORDER BY title")->fetchAll();
$courses   = $pdo->query("SELECT DISTINCT program FROM students ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections  = $pdo->query("SELECT DISTINCT section FROM students ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Verification | ACES Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* DataTables Overrides */
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
        .dataTables_length select {
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
            padding: 0.25rem 0.5rem;
        }
        .dataTables_wrapper .dataTables_info {
            font-size: 0.875rem;
            color: #6b7280;
            padding-top: 1rem;
        }

        /* Modal Backdrop Fix */
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans">

<div class="h-screen flex flex-col md:flex-row">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/header.php'; ?>
        
        <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">
            
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-[#0a6e2d]">Attendance Records</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage, filter, and verify student attendance.</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="student_progress.php" class="bg-white hover:bg-gray-50 border border-gray-300 text-gray-700 text-sm font-semibold px-4 py-2 rounded-lg inline-flex items-center gap-2 transition shadow-sm">
                        <i class="fas fa-chart-line text-[#0a6e2d]"></i> View Student Progress
                    </a>
                    <a href="dashboard.php" class="text-[#0a6e2d] bg-white p-2 rounded-full shadow-sm hover:shadow-md transition-shadow" title="Home">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_GET['deleted'])): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> Selected records archived.
                    <button type="button" class="ml-auto text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php elseif (isset($_GET['restored'])): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> Records restored.
                    <button type="button" class="ml-auto text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php elseif (isset($_GET['permanently_deleted'])): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle"></i> Records permanently deleted.
                    <button type="button" class="ml-auto text-red-700 hover:text-red-900" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php elseif (isset($_GET['status_updated'])): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> Attendance status updated.
                    <button type="button" class="ml-auto text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Main Attendance Table Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-10">
                
                <!-- Toolbar -->
                <div class="p-4 border-b border-gray-200 flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-white">
                    <div class="flex flex-wrap items-center gap-2">
                        <?php if (!isViewer()): ?>
                            <button id="selectAllBtn" class="bg-gray-50 text-gray-700 hover:bg-gray-100 border border-gray-300 px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 shadow-sm">
                                <i class="fa-regular fa-square-check text-gray-500"></i> Select All
                            </button>
                            <div class="h-6 w-px bg-gray-300 mx-1 hidden sm:block"></div>
                            <select id="bulkStatusSelect" class="bg-white border border-gray-300 text-gray-700 rounded-lg px-3 py-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                <option value="">Change Status...</option>
                                <option value="pending">Pending</option>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                            </select>
                            <button id="bulkChangeStatusBtn" class="bg-[#0a6e2d] hover:bg-[#054018] text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors shadow-sm">
                                Apply Status
                            </button>
                            <button id="bulkArchiveBtn" class="bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 text-sm font-medium px-3 py-2 rounded-lg transition-colors shadow-sm flex items-center gap-2">
                                <i class="fa-solid fa-box-archive"></i> Archive
                            </button>
                            <span class="text-gray-500 text-sm ml-2">Selected: <span id="selectedCount" class="font-bold text-gray-800">0</span></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <button type="button" class="bg-gray-50 text-gray-700 hover:bg-gray-100 border border-gray-300 px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#filterModal">
                            <i class="fa-solid fa-filter text-gray-500"></i> Filter
                        </button>
                        <button id="exportAttendanceBtn" class="bg-gray-50 text-gray-700 hover:bg-gray-100 border border-gray-300 px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 shadow-sm">
                            <i class="fa-solid fa-download text-gray-500"></i> Export
                        </button>
                        <div class="relative w-full md:w-64">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text" id="searchInput" placeholder="Search records..." class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d] transition-shadow">
                        </div>
                    </div>
                </div>

                <!-- Table Container -->
                <div class="overflow-x-auto">
                    <table id="attendanceTable" class="min-w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                                <th class="py-4 px-4 w-10 text-center"><input type="checkbox" id="selectAllCheckbox" class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]"></th>
                                <th class="py-4 px-4">Student No.</th>
                                <th class="py-4 px-4">Student Name</th>
                                <th class="py-4 px-4">Date</th>
                                <th class="py-4 px-4">Course</th>
                                <th class="py-4 px-4">Section</th>
                                <th class="py-4 px-4">Subtopic / Session</th>
                                <th class="py-4 px-4 text-center">Status</th>
                                <?php if (!isViewer()): ?>
                                <th class="py-4 px-4 text-center">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700 bg-white">
                            <?php
                            $sql = "SELECT a.attendance_id, a.attendance_status, a.attendance_date,
                                           st.student_id, u.full_name, st.program, st.section,
                                           sub.title as subtopic_title, sess.title as session_title,
                                           sess.date as session_date
                                    FROM attendance a
                                    JOIN students st ON a.student_id = st.student_id
                                    JOIN users u ON st.user_id = u.user_id
                                    JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id
                                    JOIN sessions sess ON a.session_id = sess.session_id
                                    WHERE a.is_deleted = 0";
                            $params = [];
                            if (!empty($_GET['subtopic_id'])) { $sql .= " AND a.subtopic_id = ?"; $params[] = $_GET['subtopic_id']; }
                            if (!empty($_GET['course']))     { $sql .= " AND st.program = ?";      $params[] = $_GET['course']; }
                            if (!empty($_GET['section']))    { $sql .= " AND st.section = ?";      $params[] = $_GET['section']; }
                            if (!empty($_GET['status']))     { $sql .= " AND a.attendance_status = ?"; $params[] = $_GET['status']; }
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($params);
                            $attendances = $stmt->fetchAll();
                            ?>
                            <?php foreach ($attendances as $att): ?>
                                <tr class="hover:bg-gray-50 transition-colors duration-150" data-id="<?= $att['attendance_id'] ?>">
                                    <td class="py-4 px-4 text-center"><input type="checkbox" class="row-checkbox rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" value="<?= $att['attendance_id'] ?>"></td>
                                    <td class="py-4 px-4 font-mono text-xs text-gray-600"><?= htmlspecialchars($att['student_id']) ?></td>
                                    <td class="py-4 px-4 font-medium text-gray-900"><?= htmlspecialchars($att['full_name']) ?></td>
                                    <td class="py-4 px-4 text-xs"><?= date('M d, Y', strtotime($att['attendance_date'])) ?></td>
                                    <td class="py-4 px-4 text-xs"><?= htmlspecialchars($att['program']) ?></td>
                                    <td class="py-4 px-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <?= htmlspecialchars($att['section']) ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-4 text-xs">
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($att['subtopic_title']) ?></span><br>
                                        <span class="text-gray-500"><?= htmlspecialchars($att['session_title']) ?></span>
                                    </td>
                                    <td class="py-4 px-4 text-center">
                                        <?php 
                                            $badgeClass = 'bg-gray-100 text-gray-800';
                                            if ($att['attendance_status'] === 'present') $badgeClass = 'bg-green-100 text-green-800';
                                            elseif ($att['attendance_status'] === 'absent') $badgeClass = 'bg-red-100 text-red-800';
                                            elseif ($att['attendance_status'] === 'pending') $badgeClass = 'bg-yellow-100 text-yellow-800';
                                        ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= $badgeClass ?>">
                                            <?= ucfirst($att['attendance_status']) ?>
                                        </span>
                                    </td>
                                    <?php if (!isViewer()): ?>
                                    <td class="py-4 px-4 text-center">
                                        <form method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="single_change">
                                            <input type="hidden" name="id" value="<?= $att['attendance_id'] ?>">
                                            <select name="status" class="text-xs border border-gray-300 rounded-md px-2 py-1 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" onchange="this.form.submit()">
                                                <option value="pending" <?= $att['attendance_status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="present" <?= $att['attendance_status'] == 'present' ? 'selected' : '' ?>>Present</option>
                                                <option value="absent"  <?= $att['attendance_status'] == 'absent'  ? 'selected' : '' ?>>Absent</option>
                                            </select>
                                        </form>
                                    </td>
                                    <?php endif; ?>
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
                        <i class="fas fa-archive text-gray-400"></i> Archived Records 
                        <span class="bg-gray-100 text-gray-600 py-0.5 px-2.5 rounded-full text-xs font-semibold ml-1"><?= $pdo->query("SELECT COUNT(*) FROM attendance WHERE is_deleted = 1")->fetchColumn() ?></span>
                    </div>
                    <i class="fas fa-chevron-down text-gray-400 transition-transform duration-200"></i>
                </div>
                
                <div id="archiveContent" class="hidden border-t border-gray-200">
                    
                    <!-- Archive Toolbar -->
                    <div class="p-4 bg-gray-50 flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-gray-200">
                        <div class="flex flex-wrap items-center gap-2">
                            <?php if (!isViewer()): ?>
                                <button id="archiveSelectAllBtn" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors flex items-center gap-2 shadow-sm">
                                    <i class="fa-regular fa-square-check text-gray-500"></i> Select All
                                </button>
                                <button id="archiveBulkRestoreBtn" class="bg-white border border-gray-300 hover:bg-green-50 text-green-700 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors flex items-center gap-2 shadow-sm">
                                    <i class="fas fa-undo"></i> Restore
                                </button>
                                <button id="archiveBulkDeleteBtn" class="bg-white border border-gray-300 hover:bg-red-50 text-red-600 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors flex items-center gap-2 shadow-sm">
                                    <i class="fas fa-trash-alt"></i> Delete Permanently
                                </button>
                                <span class="text-gray-500 text-sm ml-2">Selected: <span id="archiveSelectedCount" class="font-bold text-gray-800">0</span></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="bg-white border border-gray-300 hover:bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#archiveFilterModal">
                                <i class="fas fa-filter text-gray-500"></i> Filter
                            </button>
                        </div>
                    </div>

                    <!-- Archive Table -->
                    <div class="overflow-x-auto">
                        <table id="archiveTable" class="min-w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-white border-b border-gray-200">
                                <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                                    <?php if (!isViewer()): ?>
                                        <th class="py-3 px-4 w-10 text-center">
                                            <input type="checkbox" id="archiveSelectAllCheckbox" class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]">
                                        </th>
                                    <?php endif; ?>
                                    <th class="py-3 px-4">Student No.</th>
                                    <th class="py-3 px-4">Student Name</th>
                                    <th class="py-3 px-4">Date</th>
                                    <th class="py-3 px-4">Subtopic</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                    <?php if (!isViewer()): ?>
                                        <th class="py-3 px-4 text-right">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700 bg-white">
                                <?php
                                $sql_arch = "SELECT a.attendance_id, a.attendance_status, a.attendance_date,
                                                    st.student_id, u.full_name, sub.title as subtopic_title
                                            FROM attendance a
                                            JOIN students st ON a.student_id = st.student_id
                                            JOIN users u ON st.user_id = u.user_id
                                            JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id
                                            WHERE a.is_deleted = 1";
                                $params_arch = [];
                                if (!empty($_GET['arch_subtopic_id'])) { $sql_arch .= " AND a.subtopic_id = ?";        $params_arch[] = $_GET['arch_subtopic_id']; }
                                if (!empty($_GET['arch_course']))      { $sql_arch .= " AND st.program = ?";           $params_arch[] = $_GET['arch_course']; }
                                if (!empty($_GET['arch_section']))     { $sql_arch .= " AND st.section = ?";           $params_arch[] = $_GET['arch_section']; }
                                if (!empty($_GET['arch_status']))      { $sql_arch .= " AND a.attendance_status = ?"; $params_arch[] = $_GET['arch_status']; }
                                $sql_arch .= " ORDER BY a.attendance_date DESC";
                                $stmt_arch = $pdo->prepare($sql_arch);
                                $stmt_arch->execute($params_arch);
                                $archived = $stmt_arch->fetchAll();
                                ?>
                                <?php foreach ($archived as $arch): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <?php if (!isViewer()): ?>
                                            <td class="py-3 px-4 text-center">
                                                <input type="checkbox" class="archive-checkbox rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" value="<?= $arch['attendance_id'] ?>">
                                            </td>
                                        <?php endif; ?>
                                        <td class="py-3 px-4 font-mono text-xs text-gray-600"><?= htmlspecialchars($arch['student_id']) ?></td>
                                        <td class="py-3 px-4 font-medium text-gray-900"><?= htmlspecialchars($arch['full_name']) ?></td>
                                        <td class="py-3 px-4 text-xs"><?= date('M d, Y', strtotime($arch['attendance_date'])) ?></td>
                                        <td class="py-3 px-4 text-xs"><?= htmlspecialchars($arch['subtopic_title']) ?></td>
                                        <td class="py-3 px-4 text-center">
                                            <?php 
                                                $archBadge = 'bg-gray-100 text-gray-800';
                                                if ($arch['attendance_status'] === 'present') $archBadge = 'bg-green-100 text-green-800';
                                                elseif ($arch['attendance_status'] === 'absent') $archBadge = 'bg-red-100 text-red-800';
                                                elseif ($arch['attendance_status'] === 'pending') $archBadge = 'bg-yellow-100 text-yellow-800';
                                            ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= $archBadge ?>">
                                                <?= ucfirst($arch['attendance_status']) ?>
                                            </span>
                                        </td>
                                        <?php if (!isViewer()): ?>
                                            <td class="py-3 px-4 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <form method="POST" style="display:inline;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="single_restore">
                                                        <input type="hidden" name="id" value="<?= $arch['attendance_id'] ?>">
                                                        <button type="submit" class="bg-green-50 text-green-700 hover:bg-green-100 px-3 py-1 rounded-md text-xs font-medium transition-colors border border-green-200" title="Restore this record">
                                                            <i class="fas fa-undo"></i> Restore
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirmPermanentDelete(1);">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="single_permanent_delete">
                                                        <input type="hidden" name="id" value="<?= $arch['attendance_id'] ?>">
                                                        <button type="submit" class="bg-red-50 text-red-700 hover:bg-red-100 px-3 py-1 rounded-md text-xs font-medium transition-colors border border-red-200" title="Permanently delete this record">
                                                            <i class="fas fa-trash-alt"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- ================ MODALS ================ -->

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-gray-50 border-b border-gray-200 p-4">
                <h5 class="text-lg font-bold text-gray-800"><i class="fas fa-filter text-gray-500 mr-2"></i>Filter Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-6">
                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic</label>
                        <select name="subtopic_id" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="">All Subtopics</option>
                            <?php foreach ($subtopics as $sub): ?>
                                <option value="<?= $sub['subtopic_id'] ?>" <?= isset($_GET['subtopic_id']) && $_GET['subtopic_id'] == $sub['subtopic_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="">All Statuses</option>
                            <option value="present" <?= isset($_GET['status']) && $_GET['status'] == 'present' ? 'selected' : '' ?>>Present</option>
                            <option value="pending" <?= isset($_GET['status']) && $_GET['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="absent" <?= isset($_GET['status']) && $_GET['status'] == 'absent' ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </div>
                    <div class="col-span-2 flex justify-end gap-2 mt-4 pt-4 border-t border-gray-100">
                        <a href="attendance.php" class="bg-gray-100 text-gray-700 hover:bg-gray-200 px-4 py-2 rounded-lg text-sm font-medium transition-colors">Reset</a>
                        <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Archive Filter Modal -->
<div class="modal fade" id="archiveFilterModal" tabindex="-1" aria-hidden="true">
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic</label>
                        <select name="arch_subtopic_id" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="">All Subtopics</option>
                            <?php foreach ($subtopics as $sub): ?>
                                <option value="<?= $sub['subtopic_id'] ?>" <?= isset($_GET['arch_subtopic_id']) && $_GET['arch_subtopic_id'] == $sub['subtopic_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="arch_status" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="">All Statuses</option>
                            <option value="present" <?= isset($_GET['arch_status']) && $_GET['arch_status'] == 'present' ? 'selected' : '' ?>>Present</option>
                            <option value="pending" <?= isset($_GET['arch_status']) && $_GET['arch_status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="absent" <?= isset($_GET['arch_status']) && $_GET['arch_status'] == 'absent' ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </div>
                    <div class="col-span-2 flex justify-end gap-2 mt-4 pt-4 border-t border-gray-100">
                        <a href="attendance.php" class="bg-gray-100 text-gray-700 hover:bg-gray-200 px-4 py-2 rounded-lg text-sm font-medium transition-colors">Reset All</a>
                        <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportAttendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-gray-50 border-b border-gray-200 p-4">
                <h5 class="text-lg font-bold text-gray-800">Export Attendance Records</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-6 space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Format</label>
                        <select id="exportFormat" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="csv">CSV (Excel compatible)</option>
                            <option value="pdf">PDF (Print to PDF)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Export scope</label>
                        <select id="exportScope" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="all">All filtered records</option>
                            <option value="selected">Only selected records</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Columns to include</label>
                    <div id="exportColumnsList" class="bg-gray-50 border border-gray-200 rounded-lg p-4 max-h-60 overflow-y-auto grid grid-cols-2 gap-2"></div>
                </div>
            </div>
            <div class="modal-footer bg-gray-50 border-t border-gray-200 p-4">
                <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="doExportAttendance" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Export</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // CSRF token for JS-generated forms
    const CSRF_TOKEN = '<?= csrf_token() ?>';

    // Double confirmation for permanent deletion
    function confirmPermanentDelete(count) {
        var msg = 'PERMANENT DELETION\n\n' +
                  'You are about to permanently delete ' + count + ' record(s).\n\n' +
                  'This action CANNOT be undone.\n\n' +
                  'Click OK to confirm.';
        return confirm(msg);
    }

$(document).ready(function() {
    var columnDefs = <?= isViewer() ? '[]' : '[{ orderable: false, targets: [0, 7, 8] }]' ?>;
    var table = $('#attendanceTable').DataTable({
        order: [[3, 'desc']],
        columnDefs: columnDefs,
        paging: true,
        searching: true,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]], // <-- ADD THIS LINE
        language: { search: "" },
        dom: 'lrtip'
    });
    $('#searchInput').on('keyup', function() { table.search($(this).val()).draw(); });

    $('#archiveTable').DataTable({
        order: [[<?= isViewer() ? 1 : 2 ?>, 'desc']],
        paging: false,
        info: false,
        searching: false,
        columnDefs: <?= isViewer() ? '[{ orderable: false, targets: [] }]' : '[{ orderable: false, targets: [0, 6] }]' ?>,
        language: { emptyTable: "No archived records." }
    });

    <?php if (!isViewer()): ?>
    function updateSelectedCount() {
        var count = $('.row-checkbox:visible:checked').length;
        $('#selectedCount').text(count);
    }

    $('#selectAllCheckbox').on('change', function() {
        var isChecked = $(this).prop('checked');
        $('.row-checkbox:visible').prop('checked', isChecked);
        updateSelectedCount();
    });

    $('#attendanceTable tbody').on('change', '.row-checkbox', function() {
        updateSelectedCount();
        var visibleRows = $('.row-checkbox:visible').length;
        var checkedVisible = $('.row-checkbox:visible:checked').length;
        $('#selectAllCheckbox').prop('checked', visibleRows > 0 && checkedVisible === visibleRows);
    });

    $('#selectAllBtn').on('click', function() {
        $('.row-checkbox:visible').prop('checked', true);
        $('#selectAllCheckbox').prop('checked', true);
        updateSelectedCount();
    });

    $('#bulkChangeStatusBtn').on('click', function() {
        var newStatus = $('#bulkStatusSelect').val();
        var ids = [];
        $('.row-checkbox:visible:checked').each(function() {
            ids.push($(this).val());
        });
        if (!newStatus) { alert('Please select a status first.'); return; }
        if (ids.length === 0) { alert('Please select at least one attendance record.'); return; }
        if (!confirm('Change status of ' + ids.length + ' selected record(s) to "' + newStatus + '"?')) return;

        var form = $('<form method="POST"></form>');
        form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
        form.append('<input type="hidden" name="action" value="change_status">');
        form.append('<input type="hidden" name="new_status" value="' + newStatus + '">');
        form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
        $('body').append(form);
        form.submit();
    });

    $('#bulkArchiveBtn').on('click', function() {
        var ids = [];
        $('.row-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
        if (ids.length === 0) { alert('Please select at least one record.'); return; }
        if (!confirm('Archive ' + ids.length + ' selected record(s)?')) return;

        var form = $('<form method="POST"></form>');
        form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
        form.append('<input type="hidden" name="action" value="bulk_delete">');
        form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
        $('body').append(form);
        form.submit();
    });
    <?php endif; ?>

    $('#archiveHeader').on('click', function() {
        $('#archiveContent').toggleClass('hidden');
        $(this).find('.fa-chevron-down').toggleClass('rotate-180');
    });

    <?php if (!isViewer()): ?>
    // ============================================
    // Archive bulk selection
    // ============================================
    function updateArchiveSelectedCount() {
        var count = $('.archive-checkbox:visible:checked').length;
        $('#archiveSelectedCount').text(count);
    }

    $('#archiveSelectAllCheckbox').on('change', function() {
        var isChecked = $(this).prop('checked');
        $('.archive-checkbox:visible').prop('checked', isChecked);
        updateArchiveSelectedCount();
    });

    $('#archiveTable tbody').on('change', '.archive-checkbox', function() {
        updateArchiveSelectedCount();
        var visibleRows = $('.archive-checkbox:visible').length;
        var checkedVisible = $('.archive-checkbox:visible:checked').length;
        $('#archiveSelectAllCheckbox').prop('checked', visibleRows > 0 && checkedVisible === visibleRows);
    });

    $('#archiveSelectAllBtn').on('click', function() {
        $('.archive-checkbox:visible').prop('checked', true);
        $('#archiveSelectAllCheckbox').prop('checked', true);
        updateArchiveSelectedCount();
    });

    $('#archiveBulkRestoreBtn').on('click', function() {
        var ids = [];
        $('.archive-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
        if (ids.length === 0) { alert('Please select at least one archived record.'); return; }
        if (!confirm('Restore ' + ids.length + ' selected record(s)?')) return;

        var form = $('<form method="POST"></form>');
        form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
        form.append('<input type="hidden" name="action" value="bulk_restore">');
        form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
        $('body').append(form);
        form.submit();
    });

    $('#archiveBulkDeleteBtn').on('click', function() {
        var ids = [];
        $('.archive-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
        if (ids.length === 0) { alert('Please select at least one archived record.'); return; }

        // First confirmation
        if (!confirm('WARNING: Permanent Deletion\n\nYou are about to permanently delete ' + ids.length + ' record(s).\n\nThis action CANNOT be undone.\n\nContinue?')) return;

        // Second confirmation (typing required)
        var typed = prompt('Type DELETE (all caps) to confirm permanent deletion of ' + ids.length + ' record(s):');
        if (typed !== 'DELETE') {
            if (typed !== null) alert('Confirmation text did not match. Deletion cancelled.');
            return;
        }

        var form = $('<form method="POST"></form>');
        form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
        form.append('<input type="hidden" name="action" value="bulk_permanent_delete">');
        form.append('<input type="hidden" name="ids" value="' + ids.join(',') + '">');
        $('body').append(form);
        form.submit();
    });
    <?php endif; ?>

    // Export modal trigger
    $('#exportAttendanceBtn').on('click', function() {
        var columnsList = $('#exportColumnsList');
        columnsList.empty();
        [
            { label: 'Student No.', key: 'student_no', index: 1 },
            { label: 'Student Name', key: 'student_name', index: 2 },
            { label: 'Date', key: 'date', index: 3 },
            { label: 'Course', key: 'course', index: 4 },
            { label: 'Section', key: 'section', index: 5 },
            { label: 'Subtopic / Session', key: 'subtopic_session', index: 6 },
            { label: 'Status', key: 'status', index: 7 }
        ].forEach(function(col) {
            columnsList.append('<label class="flex items-center gap-2 cursor-pointer"><input class="export-col rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="checkbox" value="' + col.key + '" data-col-index="' + col.index + '" checked><span class="text-sm text-gray-700">' + col.label + '</span></label>');
        });
        $('#exportAttendanceModal').modal('show');
    });

    $('#doExportAttendance').on('click', function() {
        var format = $('#exportFormat').val();
        var scope = $('#exportScope').val();
        var selectedCols = [];
        $('.export-col:checked').each(function() {
            selectedCols.push({ index: $(this).data('col-index'), key: $(this).val(), label: $(this).next('span').text() });
        });
        if (selectedCols.length === 0) { alert('Please select at least one column.'); return; }

        var ids = [];
        if (scope === 'selected') {
            $('.row-checkbox:visible:checked').each(function() { ids.push($(this).val()); });
            if (ids.length === 0) { alert('Please select at least one record.'); return; }
        } else {
            table.rows({ filter: 'applied' }).every(function() {
                ids.push($(this.node()).data('id'));
            });
            if (ids.length === 0) { alert('No records to export.'); return; }
        }

        // Build POST form (CSRF-safe)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_attendance.php';
        form.target = format === 'pdf' ? '_blank' : '_self';
        form.style.display = 'none';

        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
            <input type="hidden" name="format" value="${format}">
            <input type="hidden" name="ids" value="${ids.join(',')}">
            <input type="hidden" name="cols" value='${JSON.stringify(selectedCols.map(c => c.key))}'>
        `;

        document.body.appendChild(form);
        form.submit();
        form.remove();
    });
});
</script>
</body>
</html>