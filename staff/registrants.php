<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'];

// Fetch data for dropdowns
$sessions = $pdo->query("SELECT session_id, title FROM sessions WHERE is_deleted = 0 ORDER BY title")->fetchAll();
$subtopics = $pdo->query("SELECT subtopic_id, title, session_id FROM subtopics WHERE is_deleted = 0 ORDER BY title")->fetchAll();
$courses = $pdo->query("SELECT DISTINCT program FROM students ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM students ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

// Build query
$sql = "SELECT r.registration_id, r.student_id, r.status, r.registration_date,
               u.full_name, st.program, st.section,
               sub.title as subtopic_title, sub.subtopic_id,
               sess.title as session_title, sess.session_id
        FROM registrations r
        JOIN students st ON r.student_id = st.student_id
        JOIN users u ON st.user_id = u.user_id
        JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
        JOIN sessions sess ON r.session_id = sess.session_id
        WHERE 1=1";
$params = [];

if (!empty($_GET['session_id'])) { $sql .= " AND r.session_id = ?"; $params[] = $_GET['session_id']; }
if (!empty($_GET['subtopic_id'])) { $sql .= " AND r.subtopic_id = ?"; $params[] = $_GET['subtopic_id']; }
if (!empty($_GET['course'])) { $sql .= " AND st.program = ?"; $params[] = $_GET['course']; }
if (!empty($_GET['section'])) { $sql .= " AND st.section = ?"; $params[] = $_GET['section']; }
if (!empty($_GET['status'])) { $sql .= " AND r.status = ?"; $params[] = $_GET['status']; }

$sql .= " ORDER BY r.registration_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registrants = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrants | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* DataTables Overrides */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #0a6e2d !important; color: white !important; border: none !important; border-radius: 0.375rem;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #054018 !important; color: white !important; border: none !important;
        }
        .dataTables_wrapper .dataTables_info { font-size: 0.875rem; color: #6b7280; padding-top: 1rem; }
        .dataTables_length select { border-radius: 0.375rem; border: 1px solid #d1d5db; padding: 0.25rem 0.5rem; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-[#0a6e2d]">Registrants Masterlist</h1>
                <p class="text-sm text-gray-600 mt-1">View, filter, and export student activity registrations.</p>
            </div>
            <a href="dashboard.php" class="text-[#0a6e2d] bg-white p-2 rounded-full shadow-sm hover:shadow-md transition-shadow">
                <i class="fa-solid fa-house text-xl"></i>
            </a>
        </div>

        <!-- Filter / Action Toolbar -->
        <div class="bg-white p-4 rounded-t-xl border border-gray-200 shadow-sm mb-4">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Session</label>
                    <select name="session_id" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= $s['session_id'] ?>" <?= isset($_GET['session_id']) && $_GET['session_id'] == $s['session_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Subtopic</label>
                    <select name="subtopic_id" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                        <option value="">All Subtopics</option>
                        <?php foreach ($subtopics as $sub): ?>
                            <option value="<?= $sub['subtopic_id'] ?>" <?= isset($_GET['subtopic_id']) && $_GET['subtopic_id'] == $sub['subtopic_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Course</label>
                    <select name="course" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= isset($_GET['course']) && $_GET['course'] == $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Section</label>
                    <select name="section" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?= htmlspecialchars($sec) ?>" <?= isset($_GET['section']) && $_GET['section'] == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors w-full shadow-sm">Apply</button>
                    <a href="registrants.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors w-full text-center">Reset</a>
                </div>
            </form>
        </div>

        <!-- Main Table Card -->
        <div class="bg-white rounded-b-xl shadow-sm border border-t-0 border-gray-200 overflow-hidden mb-10">
            
            <!-- Table Header Bar -->
            <div class="p-4 border-b border-gray-200 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gray-50">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold text-gray-800">Masterlist</span>
                    <span class="bg-[#0a6e2d]/10 text-[#0a6e2d] py-0.5 px-2.5 rounded-full text-xs font-bold"><?= count($registrants) ?> records</span>
                </div>
                <div class="flex items-center gap-3">
                    <a href="export_registrants_csv.php?<?= http_build_query($_GET) ?>" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm flex items-center gap-2">
                        <i class="fas fa-file-csv text-green-600"></i> Export CSV
                    </a>
                    <div class="relative w-full md:w-64">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" id="searchInput" placeholder="Search name or ID..." class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="overflow-x-auto">
                <table id="registrantsTable" class="min-w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-white border-b border-gray-200">
                        <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                            <th class="py-4 px-6">Student</th>
                            <th class="py-4 px-4">Course</th>
                            <th class="py-4 px-4">Section</th>
                            <th class="py-4 px-4">Session</th>
                            <th class="py-4 px-4">Subtopic</th>
                            <th class="py-4 px-4 text-center">Reg. Date</th>
                            <th class="py-4 px-6 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700 bg-white">
                        <?php foreach ($registrants as $reg): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-3">
                                        <div class="h-9 w-9 flex-shrink-0 rounded-full bg-[#0a6e2d]/10 text-[#0a6e2d] flex items-center justify-center font-bold text-xs">
                                            <?= strtoupper(substr($reg['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-gray-900"><?= htmlspecialchars($reg['full_name']) ?></div>
                                            <div class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($reg['student_id']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-4 text-xs"><?= htmlspecialchars($reg['program']) ?></td>
                                <td class="py-4 px-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"><?= htmlspecialchars($reg['section']) ?></span>
                                </td>
                                <td class="py-4 px-4 text-xs font-medium text-gray-800"><?= htmlspecialchars($reg['session_title']) ?></td>
                                <td class="py-4 px-4 text-xs text-gray-600 italic"><?= htmlspecialchars($reg['subtopic_title']) ?></td>
                                <td class="py-4 px-4 text-center text-xs"><?= date('M d, Y', strtotime($reg['registration_date'])) ?></td>
                                <td class="py-4 px-6 text-center">
                                    <?php 
                                        $statusClass = 'bg-gray-100 text-gray-800';
                                        if ($reg['status'] === 'assigned') $statusClass = 'bg-blue-100 text-blue-800';
                                        elseif ($reg['status'] === 'pending') $statusClass = 'bg-yellow-100 text-yellow-800';
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= $statusClass ?>">
                                        <?= ucfirst($reg['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        </div>
    </main>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    var table = $('#registrantsTable').DataTable({
        order: [[5, 'desc']], // Sort by Reg Date column
        paging: true,
        searching: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        language: { search: "" },
        dom: 'lrtip' // Hides default search, uses custom one
    });
    
    // Custom search input
    $('#searchInput').on('keyup', function() { 
        table.search($(this).val()).draw(); 
    });
});
</script>
</body>
</html>