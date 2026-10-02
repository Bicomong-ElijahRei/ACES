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
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        .status-pending { background-color: #fbbf24; color: #000; }
        .status-present { background-color: #10b981; color: #fff; }
        .status-absent  { background-color: #ef4444; color: #fff; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #0a6e2d !important;
            color: white !important;
            border: none !important;
        }
        .dataTables_wrapper .dataTables_filter input {
            border-radius: 9999px;
            padding: 0.25rem 1rem;
            border: 1px solid #ddd;
        }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans">

<div class="h-screen flex flex-col md:flex-row">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-10 overflow-y-auto no-scrollbar">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">Attendance Records</h1>
                    <p class="text-sm text-gray-500 mt-1">Bulk actions, filters, and archives</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="student_progress.php" class="bg-white hover:bg-gray-50 border border-gray-300 text-gray-800 text-sm font-semibold px-4 py-2 rounded-lg inline-flex items-center gap-2 transition">
                        <i class="fas fa-chart-line text-[#0a6e2d]"></i> View Student Progress
                    </a>
                    <a href="dashboard.php" class="text-[#0a6e2d]" title="Home">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/>
                        </svg>
                    </a>
                </div>
            </div>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">Selected records archived.<button type="button" class="absolute top-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">×</button></div>
            <?php elseif (isset($_GET['restored'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">Records restored.<button type="button" class="absolute top-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">×</button></div>
            <?php elseif (isset($_GET['permanently_deleted'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">Records permanently deleted.<button type="button" class="absolute top-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">×</button></div>
            <?php elseif (isset($_GET['status_updated'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">Attendance status updated.<button type="button" class="absolute top-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">×</button></div>
            <?php endif; ?>

            <!-- Main attendance table card -->
            <div class="bg-white rounded shadow-xl overflow-hidden mb-10">
                <?php if (!isViewer()): ?>
                <div class="bg-[#054018] px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex gap-2">
                        <button id="selectAllBtn" class="text-white text-xs md:text-sm font-semibold flex items-center gap-2"><i class="fa-regular fa-square"></i> Select All</button>
                        <select id="bulkStatusSelect" class="bg-white text-black rounded px-2 py-1 text-xs"><option value="">Change Status...</option><option value="pending">Pending</option><option value="present">Present</option><option value="absent">Absent</option></select>
                        <button id="bulkChangeStatusBtn" class="bg-yellow-600 hover:bg-yellow-700 text-white text-xs px-2 py-1 rounded">Apply Status</button>
                        <button id="bulkArchiveBtn" class="bg-red-600 hover:bg-red-700 text-white text-xs px-2 py-1 rounded">Archive Selected</button>
                        <span class="text-white text-xs ml-2">Selected: <span id="selectedCount">0</span></span>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="text-white text-xs md:text-sm flex items-center gap-1" data-bs-toggle="modal" data-bs-target="#filterModal"><i class="fa-solid fa-filter"></i> <span class="hidden sm:inline">Filter</span></button>
                        <button id="exportAttendanceBtn" class="text-white text-xs md:text-sm flex items-center gap-1"><i class="fa-solid fa-download"></i> Export</button>
                        <div class="relative">
                            <input type="text" id="searchInput" placeholder="Search" class="w-full max-w-[150px] md:max-w-[250px] py-1 px-3 rounded-full text-xs outline-none border border-white/30 bg-white/10 text-white placeholder-white/70">
                            <i class="fa-solid fa-magnifying-glass absolute right-3 top-2 text-white/50 text-[10px]"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="overflow-x-auto">
                    <table id="attendanceTable" class="min-w-full bg-white text-left text-xs md:text-sm">
                        <thead>
                            <tr class="text-gray-800 font-bold border-b border-gray-100">
                                <th class="py-4 px-4 w-10"><input type="checkbox" id="selectAllCheckbox"></th>
                                <th class="py-4 px-4">Student No.</th>
                                <th class="py-4 px-4">Student Name</th>
                                <th class="py-4 px-4">Date</th>
                                <th class="py-4 px-4">Course</th>
                                <th class="py-4 px-4">Section</th>
                                <th class="py-4 px-4">Subtopic / Session</th>
                                <th class="py-4 px-4 text-center">Status</th>
                                <?php if (!isViewer()): ?>
                                <th class="py-4 px-4">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600">
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
                                <tr class="border-b border-gray-50 hover:bg-gray-50 transition" data-id="<?= $att['attendance_id'] ?>">
                                    <td class="py-4 px-4 text-center"><input type="checkbox" class="row-checkbox" value="<?= $att['attendance_id'] ?>"></td>
                                    <td class="py-4 px-4 font-mono"><?= htmlspecialchars($att['student_id']) ?></td>
                                    <td class="py-4 px-4 font-semibold"><?= htmlspecialchars($att['full_name']) ?></td>
                                    <td class="py-4 px-4"><?= date('Y-m-d', strtotime($att['attendance_date'])) ?></td>
                                    <td class="py-4 px-4"><?= htmlspecialchars($att['program']) ?></td>
                                    <td class="py-4 px-4"><?= htmlspecialchars($att['section']) ?></td>
                                    <td class="py-4 px-4 italic"><?= htmlspecialchars($att['subtopic_title']) ?> (<?= htmlspecialchars($att['session_title']) ?>)</td>
                                    <td class="py-4 px-4 text-center"><span class="status-badge status-<?= $att['attendance_status'] ?>"><?= ucfirst($att['attendance_status']) ?></span></td>
                                    <?php if (!isViewer()): ?>
                                    <td class="py-4 px-4">
                                        <form method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="single_change">
                                            <input type="hidden" name="id" value="<?= $att['attendance_id'] ?>">
                                            <select name="status" class="text-xs border rounded px-2 py-1" onchange="this.form.submit()">
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
            <div class="mt-8">
                <div id="archiveHeader" class="flex justify-between items-center cursor-pointer bg-white rounded-t-lg px-6 py-3 shadow-md border border-gray-200">
                    <div class="font-bold text-[#0a6e2d]"><i class="fas fa-archive mr-2"></i> Archives <span class="bg-gray-500 text-white rounded-full px-2 py-0.5 text-xs ml-2"><?= $pdo->query("SELECT COUNT(*) FROM attendance WHERE is_deleted = 1")->fetchColumn() ?></span></div>
                    <i class="fas fa-chevron-down transition-transform duration-200 text-gray-500"></i>
                </div>
                <div id="archiveContent" class="hidden mt-1">
                    <div class="bg-white rounded-b-lg shadow-md overflow-hidden border border-t-0 border-gray-200">

                        <!-- Archive toolbar -->
                        <div class="bg-[#054018] px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex gap-2 items-center flex-wrap">
                                <?php if (!isViewer()): ?>
                                    <button id="archiveSelectAllBtn" class="text-white text-xs md:text-sm font-semibold flex items-center gap-2">
                                        <i class="fa-regular fa-square"></i> Select All
                                    </button>
                                    <button id="archiveBulkRestoreBtn" class="bg-green-600 hover:bg-green-700 text-white text-xs px-3 py-1 rounded">
                                        <i class="fas fa-undo"></i> Restore Selected
                                    </button>
                                    <button id="archiveBulkDeleteBtn" class="bg-red-600 hover:bg-red-700 text-white text-xs px-3 py-1 rounded">
                                        <i class="fas fa-trash-alt"></i> Delete Permanently
                                    </button>
                                    <span class="text-white text-xs ml-2">Selected: <span id="archiveSelectedCount">0</span></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex gap-2">
                                <button type="button"
                                        class="text-white text-xs md:text-sm flex items-center gap-1"
                                        data-bs-toggle="modal"
                                        data-bs-target="#archiveFilterModal">
                                    <i class="fas fa-filter"></i> Filter Archives
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table id="archiveTable" class="min-w-full bg-white text-sm">
                                <thead>
                                    <tr class="text-gray-800 font-bold border-b">
                                        <?php if (!isViewer()): ?>
                                            <th class="py-3 px-4 w-10">
                                                <input type="checkbox" id="archiveSelectAllCheckbox">
                                            </th>
                                        <?php endif; ?>
                                        <th class="py-3 px-4">Student No.</th>
                                        <th class="py-3 px-4">Student Name</th>
                                        <th class="py-3 px-4">Date</th>
                                        <th class="py-3 px-4">Subtopic</th>
                                        <th class="py-3 px-4">Status</th>
                                        <?php if (!isViewer()): ?>
                                            <th class="py-3 px-4">Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="text-gray-600">
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
                                        <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                            <?php if (!isViewer()): ?>
                                                <td class="py-3 px-4 text-center">
                                                    <input type="checkbox" class="archive-checkbox" value="<?= $arch['attendance_id'] ?>">
                                                </td>
                                            <?php endif; ?>
                                            <td class="py-3 px-4"><?= htmlspecialchars($arch['student_id']) ?></td>
                                            <td class="py-3 px-4"><?= htmlspecialchars($arch['full_name']) ?></td>
                                            <td class="py-3 px-4"><?= date('Y-m-d', strtotime($arch['attendance_date'])) ?></td>
                                            <td class="py-3 px-4"><?= htmlspecialchars($arch['subtopic_title']) ?></td>
                                            <td class="py-3 px-4">
                                                <span class="status-badge status-<?= $arch['attendance_status'] ?>">
                                                    <?= ucfirst($arch['attendance_status']) ?>
                                                </span>
                                            </td>
                                            <?php if (!isViewer()): ?>
                                                <td class="py-3 px-4">
                                                    <div class="flex gap-1">
                                                        <form method="POST" style="display:inline;">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="single_restore">
                                                            <input type="hidden" name="id" value="<?= $arch['attendance_id'] ?>">
                                                            <button type="submit"
                                                                    class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-xs"
                                                                    title="Restore this record">
                                                                <i class="fas fa-undo"></i> Restore
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display:inline;"
                                                              onsubmit="return confirmPermanentDelete(1);">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="single_permanent_delete">
                                                            <input type="hidden" name="id" value="<?= $arch['attendance_id'] ?>">
                                                            <button type="submit"
                                                                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-xs"
                                                                    title="Permanently delete this record">
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
            </div>
        </main>
    </div>
</div>

<!-- ================ MODALS ================ -->

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gray-100 p-4 border-b"><h5 class="modal-title text-lg font-bold">Filter Attendance Records</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body p-4"><form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-2 gap-4"><div><label class="block text-sm font-medium">Subtopic</label><select name="subtopic_id" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($subtopics as $sub): ?><option value="<?= $sub['subtopic_id'] ?>" <?= isset($_GET['subtopic_id']) && $_GET['subtopic_id'] == $sub['subtopic_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['title']) ?></option><?php endforeach; ?></select></div><div><label class="block text-sm font-medium">Course</label><select name="course" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($courses as $crs): ?><option value="<?= htmlspecialchars($crs) ?>" <?= isset($_GET['course']) && $_GET['course'] == $crs ? 'selected' : '' ?>><?= htmlspecialchars($crs) ?></option><?php endforeach; ?></select></div><div><label class="block text-sm font-medium">Section</label><select name="section" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($sections as $sec): ?><option value="<?= htmlspecialchars($sec) ?>" <?= isset($_GET['section']) && $_GET['section'] == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option><?php endforeach; ?></select></div><div><label class="block text-sm font-medium">Status</label><select name="status" class="w-full border rounded p-2"><option value="">All</option><option value="present" <?= isset($_GET['status']) && $_GET['status'] == 'present' ? 'selected' : '' ?>>Present</option><option value="pending" <?= isset($_GET['status']) && $_GET['status'] == 'pending' ? 'selected' : '' ?>>Pending</option><option value="absent" <?= isset($_GET['status']) && $_GET['status'] == 'absent' ? 'selected' : '' ?>>Absent</option></select></div><div class="col-span-2 text-right"><button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Apply Filters</button><a href="attendance.php" class="bg-gray-300 text-gray-800 px-4 py-2 rounded ml-2">Reset</a><button type="button" class="bg-gray-500 text-white px-4 py-2 rounded ml-2" data-bs-dismiss="modal">Cancel</button></div></form></div>
        </div>
    </div>
</div>

<!-- Archive Filter Modal -->
<div class="modal fade" id="archiveFilterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gray-100 p-4 border-b"><h5 class="modal-title text-lg font-bold">Filter Archive Records</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4"><form method="GET" id="archiveFilterForm" class="grid grid-cols-1 md:grid-cols-2 gap-4"><input type="hidden" name="archive_filter" value="1"><div><label>Subtopic</label><select name="arch_subtopic_id" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($subtopics as $sub): ?><option value="<?= $sub['subtopic_id'] ?>" <?= isset($_GET['arch_subtopic_id']) && $_GET['arch_subtopic_id'] == $sub['subtopic_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['title']) ?></option><?php endforeach; ?></select></div><div><label>Course</label><select name="arch_course" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($courses as $crs): ?><option value="<?= htmlspecialchars($crs) ?>" <?= isset($_GET['arch_course']) && $_GET['arch_course'] == $crs ? 'selected' : '' ?>><?= htmlspecialchars($crs) ?></option><?php endforeach; ?></select></div><div><label>Section</label><select name="arch_section" class="w-full border rounded p-2"><option value="">All</option><?php foreach ($sections as $sec): ?><option value="<?= htmlspecialchars($sec) ?>" <?= isset($_GET['arch_section']) && $_GET['arch_section'] == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option><?php endforeach; ?></select></div><div><label>Status</label><select name="arch_status" class="w-full border rounded p-2"><option value="">All</option><option value="present" <?= isset($_GET['arch_status']) && $_GET['arch_status'] == 'present' ? 'selected' : '' ?>>Present</option><option value="pending" <?= isset($_GET['arch_status']) && $_GET['arch_status'] == 'pending' ? 'selected' : '' ?>>Pending</option><option value="absent" <?= isset($_GET['arch_status']) && $_GET['arch_status'] == 'absent' ? 'selected' : '' ?>>Absent</option></select></div><div class="col-span-2 text-right"><button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Apply Filters</button><a href="attendance.php" class="bg-gray-300 text-gray-800 px-4 py-2 rounded ml-2">Reset All</a><button type="button" class="bg-gray-500 text-white px-4 py-2 rounded ml-2" data-bs-dismiss="modal">Cancel</button></div></form></div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportAttendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gray-100 p-4 border-b"><h5 class="modal-title text-lg font-bold">Export Attendance Records</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4"><div class="mb-4"><label class="block text-sm font-medium">Format</label><select id="exportFormat" class="w-full border rounded p-2"><option value="csv">CSV (Excel compatible)</option><option value="pdf">PDF (Print to PDF)</option></select></div><div class="mb-4"><label class="block text-sm font-medium">Export scope</label><select id="exportScope" class="w-full border rounded p-2"><option value="all">All filtered records</option><option value="selected">Only selected records (checked rows)</option></select></div><div class="mb-4"><label class="block text-sm font-medium">Columns to include</label><div id="exportColumnsList" class="border rounded p-2 max-h-60 overflow-y-auto"></div></div></div>
            <div class="modal-footer p-4 border-t flex justify-end gap-2"><button type="button" class="bg-gray-300 text-gray-800 px-4 py-2 rounded" data-bs-dismiss="modal">Cancel</button><button type="button" id="doExportAttendance" class="bg-green-600 text-white px-4 py-2 rounded">Export</button></div>
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
            columnsList.append('<div class="form-check"><input class="form-check-input export-col" type="checkbox" value="' + col.key + '" data-col-index="' + col.index + '" checked><label class="form-check-label">' + col.label + '</label></div>');
        });
        $('#exportAttendanceModal').modal('show');
    });

    $('#doExportAttendance').on('click', function() {
        var format = $('#exportFormat').val();
        var scope = $('#exportScope').val();
        var selectedCols = [];
        $('.export-col:checked').each(function() {
            selectedCols.push({ index: $(this).data('col-index'), key: $(this).val(), label: $(this).next('label').text() });
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

        var params = new URLSearchParams();
        params.append('format', format);
        params.append('ids', ids.join(','));
        params.append('cols', JSON.stringify(selectedCols.map(c => c.key)));

        if (format === 'csv') {
            window.location.href = 'export_attendance.php?' + params.toString();
        } else {
            window.open('export_attendance.php?' + params.toString(), '_blank');
        }
    });
});
</script>
</body>
</html>