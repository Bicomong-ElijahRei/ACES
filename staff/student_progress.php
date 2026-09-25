<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'];

// ---------- FETCH SESSIONS + GROUP SUBTOPICS ----------
$sessions = $pdo->query("
    SELECT DISTINCT s.session_id, s.title, s.date
    FROM sessions s
    JOIN subtopics st ON s.session_id = st.session_id AND st.is_deleted = 0
    JOIN registrations r ON r.subtopic_id = st.subtopic_id
    WHERE s.is_deleted = 0
    ORDER BY s.date DESC, s.title
")->fetchAll();

$subtopics_by_session = [];
$all_subtopics = [];          // flat list for backward compat
foreach ($sessions as $session) {
    $stmt = $pdo->prepare("SELECT subtopic_id, title FROM subtopics WHERE session_id = ? AND is_deleted = 0 ORDER BY title");
    $stmt->execute([$session['session_id']]);
    $subs = $stmt->fetchAll();
    foreach ($subs as &$sub) {
        $sub['session_title'] = $session['title'];
        $sub['session_id'] = $session['session_id'];
    }
    $subtopics_by_session[$session['session_id']] = $subs;
    $all_subtopics = array_merge($all_subtopics, $subs);
}
$subtopics = $all_subtopics;

$courses = $pdo->query("SELECT DISTINCT program FROM students WHERE is_deleted = 0 ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM students WHERE is_deleted = 0 ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

$subtopic_ids = array_column($subtopics, 'subtopic_id');

$total_modules_per_subtopic = [];
if (!empty($subtopic_ids)) {
    $placeholders = implode(',', array_fill(0, count($subtopic_ids), '?'));
    $stmt_mod_total = $pdo->prepare("SELECT subtopic_id, COUNT(*) as total FROM modules WHERE subtopic_id IN ($placeholders) AND is_deleted = 0 GROUP BY subtopic_id");
    $stmt_mod_total->execute($subtopic_ids);
    while ($row = $stmt_mod_total->fetch()) {
        $total_modules_per_subtopic[$row['subtopic_id']] = $row['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Progress | ACES Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .att-cell, .mod-cell { text-align: center; padding: 4px; min-width: 42px; }
        .att-cell { cursor: pointer; font-size: 0.75rem; font-weight: 600; }
        .mod-cell { font-size: 0.7rem; cursor: default; }
        .att-present { background: #d1fae5; color: #065f46; }
        .att-pending { background: #fef3c7; color: #92400e; }
        .att-absent { background: #fee2e2; color: #991b1b; }
        .att-not-recorded { background: #f3f4f6; color: #6b7280; }
        .att-no-reg { background: #f9fafb; color: #9ca3af; font-style: italic; }
        .mod-complete { background: #d1fae5; color: #065f46; }
        .mod-partial { background: #fef3c7; color: #92400e; }
        .mod-none { background: #fee2e2; color: #991b1b; }
        .mod-no-modules { background: #f3f4f6; color: #6b7280; }
        .session-header-row { background: #054018; color: white; cursor: pointer; }
        .session-header-row th { padding: 8px 12px; font-size: 0.95rem; }
        .subtopic-header-row th { background: #e5e7eb; font-size: 0.7rem; padding: 4px 4px; text-align: center; }
        .progress-badge { background: #0a6e2d; color: white; padding: 3px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
        .toggle-session-icon { margin-right: 6px; font-size: 1rem; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
<?php include '../includes/staff_sidebar.php'; ?>
<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-10 overflow-y-auto no-scrollbar">

        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">Student Progress – All Subtopics</h1>
            <a href="dashboard.php" class="text-[#0a6e2d]"><svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/></svg></a>
        </div>

        <?php if (empty($subtopics)): ?>
            <div class="bg-white rounded shadow-xl p-6 text-center text-gray-500">No subtopics with registrations found.</div>
        <?php else: ?>
            <!-- Action Bar -->
            <div class="bg-white rounded shadow-lg p-3 mb-4 flex flex-wrap items-center gap-3">
                <?php if (!isViewer()): ?>
                <div class="flex gap-2">
                    <select id="bulkStatusSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="">Bulk set status...</option>
                        <option value="pending">Pending</option>
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="unregister">Unregister</option>
                    </select>
                    <button id="bulkApplyStatusBtn" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded">Apply to Selected</button>
                    <button id="exportProgressBtn" class="bg-green-700 hover:bg-green-800 text-white text-sm px-3 py-1 rounded"><i class="fas fa-download mr-1"></i>Export</button>
                </div>
                <?php endif; ?>
                <button type="button" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1 rounded ml-auto" data-bs-toggle="modal" data-bs-target="#filterModal"><i class="fas fa-filter mr-1"></i>Filter Students</button>
                <button type="button" class="bg-gray-600 hover:bg-gray-700 text-white text-sm px-3 py-1 rounded" data-bs-toggle="modal" data-bs-target="#columnToggleModal"><i class="fas fa-columns mr-1"></i>Manage Columns</button>
            </div>

            <!-- Filter Modal (unchanged) -->
            <div class="modal fade" id="filterModal" tabindex="-1">
                <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-gray-100"><h5 class="modal-title">Filter Students</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><form id="filterForm" class="row g-3">
                    <div class="col-md-4"><label>Course</label><select id="filterCourse" class="form-select"><option value="">All</option><?php foreach ($courses as $crs): ?><option value="<?= htmlspecialchars($crs) ?>"><?= htmlspecialchars($crs) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label>Section</label><select id="filterSection" class="form-select"><option value="">All</option><?php foreach ($sections as $sec): ?><option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label>Status (any subtopic)</label><select id="filterStatus" class="form-select"><option value="">All</option><option value="Present">Present</option><option value="Pending">Pending</option><option value="Absent">Absent</option><option value="Not Recorded">Not Recorded</option><option value="Not Registered">Not Registered</option></select></div>
                    <div class="col-12 text-end"><button type="button" id="applyFiltersBtn" class="btn btn-primary">Apply</button><button type="button" id="resetFiltersBtn" class="btn btn-secondary">Reset</button><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button></div>
                </form></div></div></div>
            </div>

            <!-- Column Toggle Modal (grouped by session) -->
            <div class="modal fade" id="columnToggleModal" tabindex="-1">
                <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-gray-100"><h5 class="modal-title">Show/Hide Subtopic Pairs (Att / Mod)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><div class="column-toggle-list">
                    <?php foreach ($sessions as $session): ?>
                        <div class="subtopic-group mb-3">
                            <h6><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($session['title']) ?></h6>
                            <?php foreach ($subtopics_by_session[$session['session_id']] as $idx => $sub): ?>
                                <div class="form-check">
                                    <input class="form-check-input toggle-pair" type="checkbox"
                                           data-session="<?= $session['session_id'] ?>"
                                           data-subtopic="<?= $sub['subtopic_id'] ?>"
                                           id="pair_<?= $sub['subtopic_id'] ?>" checked>
                                    <label class="form-check-label" for="pair_<?= $sub['subtopic_id'] ?>">
                                        <?= htmlspecialchars($sub['title']) ?> (Att + Mod)
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div>
            </div>

            <!-- Main Table -->
            <div class="bg-white rounded shadow-xl overflow-hidden">
                <div class="bg-[#054018] px-4 py-3 font-bold text-white">Student Progress Matrix</div>
                <div class="overflow-x-auto">
                    <table id="progressTable" class="min-w-full bg-white text-left text-xs md:text-sm">
                        <thead>
                            <!-- Fixed info header row -->
                            <tr class="text-gray-800 font-bold border-b border-gray-100">
                                <th class="py-4 px-2 w-10" rowspan="2"><input type="checkbox" id="selectAllCheckbox"></th>
                                <th class="py-4 px-4" rowspan="2">Student No.</th>
                                <th class="py-4 px-4" rowspan="2">Student Name</th>
                                <th class="py-4 px-4" rowspan="2">Course</th>
                                <th class="py-4 px-4" rowspan="2">Section</th>
                                <?php
                                $col_offset = 5; // first subtopic sub‑column index
                                foreach ($sessions as $session):
                                    $subs = $subtopics_by_session[$session['session_id']] ?? [];
                                    $span = count($subs) * 2;
                                ?>
                                    <th class="text-center session-header-row" colspan="<?= $span ?>" data-session="<?= $session['session_id'] ?>">
                                        <i class="fas fa-minus-circle toggle-session-icon" data-session="<?= $session['session_id'] ?>"></i>
                                        <?= htmlspecialchars($session['title']) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="py-4 px-4 text-center" rowspan="2">Progress</th>
                                <th class="py-4 px-4 text-center" rowspan="2">Modules</th>
                            </tr>
                            <!-- Sub‑header row (Att / Mod) -->
                            <tr class="subtopic-header-row">
                                <?php foreach ($sessions as $session):
                                    foreach ($subtopics_by_session[$session['session_id']] ?? [] as $sub): ?>
                                        <th class="att-col" data-subtopic="<?= $sub['subtopic_id'] ?>">Att</th>
                                        <th class="mod-col" data-subtopic="<?= $sub['subtopic_id'] ?>">Mod</th>
                                <?php endforeach; endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600">
                            <?php
                            $sql_students = "SELECT DISTINCT s.student_id, s.program, s.section, u.full_name FROM students s JOIN users u ON s.user_id = u.user_id JOIN registrations r ON r.student_id = s.student_id WHERE s.is_deleted = 0";
                            $stmt_students = $pdo->prepare($sql_students);
                            $stmt_students->execute();
                            $students = $stmt_students->fetchAll();
                            $student_ids = array_column($students, 'student_id');

                            $reg_map = [];
                            if (!empty($student_ids) && !empty($subtopic_ids)) {
                                $ph_s = implode(',', array_fill(0, count($student_ids), '?'));
                                $ph_st = implode(',', array_fill(0, count($subtopic_ids), '?'));
                                $reg_stmt = $pdo->prepare("SELECT student_id, subtopic_id FROM registrations WHERE student_id IN ($ph_s) AND subtopic_id IN ($ph_st)");
                                $reg_stmt->execute(array_merge($student_ids, $subtopic_ids));
                                while ($row = $reg_stmt->fetch()) { $reg_map[$row['student_id']][] = $row['subtopic_id']; }
                            }

                            $att_map = [];
                            if (!empty($student_ids) && !empty($subtopic_ids)) {
                                $ph_s = implode(',', array_fill(0, count($student_ids), '?'));
                                $ph_st = implode(',', array_fill(0, count($subtopic_ids), '?'));
                                $att_stmt = $pdo->prepare("SELECT student_id, subtopic_id, attendance_status FROM attendance WHERE student_id IN ($ph_s) AND subtopic_id IN ($ph_st)");
                                $att_stmt->execute(array_merge($student_ids, $subtopic_ids));
                                while ($row = $att_stmt->fetch()) { $att_map[$row['student_id']][$row['subtopic_id']] = $row['attendance_status']; }
                            }

                            $completed_modules_map = [];
                            if (!empty($student_ids) && !empty($subtopic_ids)) {
                                $ph_s = implode(',', array_fill(0, count($student_ids), '?'));
                                $ph_st = implode(',', array_fill(0, count($subtopic_ids), '?'));
                                $mod_stmt = $pdo->prepare("SELECT sp.student_id, m.subtopic_id, COUNT(*) as completed_count FROM student_module_progress sp JOIN modules m ON sp.module_id = m.module_id WHERE sp.student_id IN ($ph_s) AND m.subtopic_id IN ($ph_st) AND sp.completed = 1 GROUP BY sp.student_id, m.subtopic_id");
                                $mod_stmt->execute(array_merge($student_ids, $subtopic_ids));
                                while ($row = $mod_stmt->fetch()) { $completed_modules_map[$row['student_id']][$row['subtopic_id']] = $row['completed_count']; }
                            }

                            foreach ($students as $student):
                                $registered = $reg_map[$student['student_id']] ?? [];
                                $accomplished_count = 0;
                                $total_registered = count($registered);
                                foreach ($all_subtopics as $sub) {
                                    if (in_array($sub['subtopic_id'], $registered)) {
                                        $att_status = $att_map[$student['student_id']][$sub['subtopic_id']] ?? 'not_recorded';
                                        $att_ok = ($att_status == 'present');
                                        $total_mods = $total_modules_per_subtopic[$sub['subtopic_id']] ?? 0;
                                        $completed_mods = $completed_modules_map[$student['student_id']][$sub['subtopic_id']] ?? 0;
                                        $mod_ok = ($total_mods == 0) || ($completed_mods == $total_mods);
                                        if ($att_ok && $mod_ok) $accomplished_count++;
                                    }
                                }
                                $progress = ($total_registered > 0) ? round(($accomplished_count / $total_registered) * 100) : 0;
                            ?>
                                <tr data-student-id="<?= $student['student_id'] ?>" data-course="<?= htmlspecialchars($student['program']) ?>" data-section="<?= htmlspecialchars($student['section']) ?>">
                                    <td class="py-3 px-2 text-center"><input type="checkbox" class="row-checkbox" value="<?= $student['student_id'] ?>"></td>
                                    <td class="py-3 px-4"><?= htmlspecialchars($student['student_id']) ?></td>
                                    <td class="py-3 px-4 font-semibold"><?= htmlspecialchars($student['full_name']) ?></td>
                                    <td class="py-3 px-4"><?= htmlspecialchars($student['program']) ?></td>
                                    <td class="py-3 px-4"><?= htmlspecialchars($student['section']) ?></td>
                                    <?php foreach ($all_subtopics as $sub):
                                        $has_reg = in_array($sub['subtopic_id'], $registered);
                                        $status = $att_map[$student['student_id']][$sub['subtopic_id']] ?? 'not_recorded';
                                        $total_mods = $total_modules_per_subtopic[$sub['subtopic_id']] ?? 0;
                                        $completed_mods = $completed_modules_map[$student['student_id']][$sub['subtopic_id']] ?? 0;

                                        // Attendance cell
                                        if (!$has_reg) {
                                            $att_text = '—'; $att_class = 'att-no-reg';
                                        } else {
                                            switch ($status) {
                                                case 'present': $att_text = '✅'; $att_class = 'att-present'; break;
                                                case 'pending': $att_text = '⏳'; $att_class = 'att-pending'; break;
                                                case 'absent': $att_text = '❌'; $att_class = 'att-absent'; break;
                                                default: $att_text = '○'; $att_class = 'att-not-recorded'; break;
                                            }
                                        }
                                        // Module cell
                                        if (!$has_reg || $total_mods == 0) {
                                            $mod_text = '—'; $mod_class = 'mod-no-modules';
                                        } elseif ($completed_mods == $total_mods) {
                                            $mod_text = '✅'; $mod_class = 'mod-complete';
                                        } elseif ($completed_mods > 0) {
                                            $mod_text = "📘 $completed_mods/$total_mods"; $mod_class = 'mod-partial';
                                        } else {
                                            $mod_text = '❌'; $mod_class = 'mod-none';
                                        }
                                    ?>
                                        <td class="att-cell <?= $att_class ?>" data-student-id="<?= $student['student_id'] ?>" data-subtopic-id="<?= $sub['subtopic_id'] ?>" data-has-reg="<?= $has_reg?'1':'0' ?>" data-current="<?= $status ?>"><?= $att_text ?></td>
                                        <td class="mod-cell <?= $mod_class ?>" title="<?= ($total_mods > 0) ? "$completed_mods of $total_mods modules completed" : 'No modules' ?>"><?= $mod_text ?></td>
                                    <?php endforeach; ?>
                                    <td class="py-3 px-4 text-center"><span class="progress-badge"><?= $progress ?>%</span></td>
                                    <td class="py-3 px-4 text-center"><button class="bg-blue-500 hover:bg-blue-600 text-white text-xs px-2 py-1 rounded view-modules" data-student-id="<?= $student['student_id'] ?>" data-student-name="<?= htmlspecialchars($student['full_name']) ?>"><i class="fas fa-list"></i> View</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modules Modal (unchanged) -->
<div class="modal fade" id="modulesModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-green-700 text-white"><h5 class="modal-title">Modules & Assessments</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="modulesModalBody">Loading...</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Export Modal (unchanged) -->
<?php if (!isViewer()): ?>
<div class="modal fade" id="exportProgressModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-gray-100"><h5 class="modal-title">Export Student Progress</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Format</label><select id="exportFormat" class="form-select"><option value="csv">CSV (Excel compatible) – flat table</option><option value="pdf">PDF (Print to PDF) – flat table</option><option value="detailed">Detailed Report (HTML) – sessions, subtopics, modules</option></select></div><div class="mb-3"><label>Export scope</label><select id="exportScope" class="form-select"><option value="all">All filtered students</option><option value="selected">Only selected students (checked rows)</option></select></div><div class="mb-3"><label>Columns to include</label><div id="exportColumnsList" class="border p-2" style="max-height:300px;overflow-y:auto;"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" id="doExportProgress" class="btn btn-primary">Export</button></div></div></div></div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    var table = $('#progressTable').DataTable({ order: [[1, 'asc']], columnDefs: [{ orderable: false, targets: [0] }] });
    var currentCourse = '', currentSection = '', currentStatus = '';

    // Session toggle
    $('.session-header-row').on('click', function(e) {
        if ($(e.target).hasClass('toggle-session-icon')) {
            var sessionId = $(this).data('session');
            var $icon = $(this).find('.toggle-session-icon');
            // Find all att/mod columns for this session
            // Use the data-session on the icon, toggle the col visibility
            // Simplified: toggle all columns belonging to this session
            table.columns().every(function() {
                var header = $(this.header());
                if (header.closest('th').data('session') == sessionId || header.closest('th.subtopic-col')?.data('session') == sessionId) {
                    this.visible(!this.visible());
                }
            });
            $icon.toggleClass('fa-minus-circle fa-plus-circle');
        }
    });

    // Pair toggle (Att + Mod together)
    $('.toggle-pair').on('change', function() {
        var subtopicId = $(this).data('subtopic');
        var checked = $(this).prop('checked');
        // Find the two columns (Att and Mod) whose data-subtopic = subtopicId
        table.columns().every(function() {
            var header = $(this.header());
            if (header.data('subtopic') == subtopicId) {
                this.visible(checked);
            }
        });
    });

    function applyAllFilters() {
        var rows = table.rows().nodes(), filteredRows = [];
        $.each(rows, function(i, row) {
            var $r = $(row), show = true;
            if (currentCourse && $r.data('course') !== currentCourse) show = false;
            if (currentSection && $r.data('section') !== currentSection) show = false;
            if (show) filteredRows.push(row);
        });
        if (currentStatus) {
            var statusFilteredRows = [];
            $.each(filteredRows, function(i, row) {
                var has = false;
                $(row).find('.att-cell:visible').each(function() {
                    if ($(this).data('current') === currentStatus.toLowerCase()) { has = true; return false; }
                });
                if (currentStatus === 'Not Registered') {
                    $(row).find('.att-cell:visible').each(function() {
                        if ($(this).data('has-reg') == 0) { has = true; return false; }
                    });
                }
                if (has) statusFilteredRows.push(row);
            });
            filteredRows = statusFilteredRows;
        }
        table.rows().every(function() { $(this.node()).hide(); });
        $.each(filteredRows, function(i, row) { $(row).show(); });
    }

    $('#applyFiltersBtn').off('click').on('click', function() {
        currentCourse = $('#filterCourse').val();
        currentSection = $('#filterSection').val();
        currentStatus = $('#filterStatus').val();
        applyAllFilters();
        $('#filterModal').modal('hide');
    });
    $('#resetFiltersBtn').off('click').on('click', function() {
        $('#filterCourse').val(''); $('#filterSection').val(''); $('#filterStatus').val('');
        currentCourse = ''; currentSection = ''; currentStatus = '';
        table.rows().every(function() { $(this.node()).show(); });
        applyAllFilters();
        $('#filterModal').modal('hide');
    });
    applyAllFilters();
    table.on('draw', function() { applyAllFilters(); });

    $('#selectAllCheckbox').on('change', function() {
        var isChecked = $(this).prop('checked');
        $('.row-checkbox:visible').prop('checked', isChecked);
    });
    $(document).on('change', '.row-checkbox', function() {
        $('#selectAllCheckbox').prop('checked', $('.row-checkbox:visible:checked').length === $('.row-checkbox:visible').length);
    });
    table.on('draw', function() {
        $('#selectAllCheckbox').prop('checked', $('.row-checkbox:visible:checked').length === $('.row-checkbox:visible').length);
    });

    $(document).on('click', '.view-modules', function() {
        var studentId = $(this).data('student-id'), studentName = $(this).data('student-name');
        $('#modulesModal .modal-title').text('Modules & Assessments: ' + studentName);
        $('#modulesModalBody').html('<div class="text-center">Loading...</div>');
        $('#modulesModal').modal('show');
        $.ajax({ url: 'get_student_modules.php', type: 'GET', data: { student_id: studentId }, dataType: 'json',
            success: function(res) {
                if (res.success && res.modules.length > 0) {
                    var html = '', currentSubtopic = '';
                    res.modules.forEach(function(mod) {
                        if (mod.subtopic_title !== currentSubtopic) {
                            if (currentSubtopic !== '') html += '</div>';
                            html += '<div class="subtopic-header"><i class="fas fa-folder-open"></i> ' + escapeHtml(mod.subtopic_title) + '</div><div class="module-list-group">';
                            currentSubtopic = mod.subtopic_title;
                        }
                        var statusIcon = mod.completed ? '✔️ <span class="text-success">Completed</span>' : '❌ <span class="text-danger">Not completed</span>';
                        html += '<div class="list-group-item"><i class="fas fa-file-alt"></i> <strong>' + escapeHtml(mod.module_title) + '</strong> (' + mod.type + ')<br><small>' + statusIcon + (mod.due_date ? ' | Due: ' + mod.due_date : '') + '</small></div>';
                    });
                    html += '</div>';
                    $('#modulesModalBody').html(html);
                } else { $('#modulesModalBody').html('<div class="alert alert-info">No modules or assessments found for this student.</div>'); }
            },
            error: function() { $('#modulesModalBody').html('<div class="alert alert-danger">Error loading modules.</div>'); }
        });
    });

    function escapeHtml(str) { return str.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }

    <?php if (!isViewer()): ?>
    $(document).on('click', '.att-cell', function() {
        var $cell = $(this), studentId = $cell.data('student-id'), subtopicId = $cell.data('subtopic-id'), currentStatus = $cell.data('current'), newStatus = '';
        if (currentStatus === 'not_recorded') newStatus = 'pending';
        else if (currentStatus === 'pending') newStatus = 'present';
        else if (currentStatus === 'present') newStatus = 'absent';
        else if (currentStatus === 'absent') { if (confirm('Unregister this student from this subtopic?')) newStatus = 'unregister'; else return; }
        else newStatus = 'pending';
        $.ajax({ url: 'update_attendance_status.php', type: 'POST', data: { student_id: studentId, subtopic_id: subtopicId, status: newStatus }, dataType: 'json',
            success: function(res) { if (res.success) location.reload(); else alert('Error: ' + (res.error || 'Unknown')); },
            error: function() { alert('Network error.'); }
        });
    });

    $('#bulkApplyStatusBtn').on('click', function() {
        var newStatus = $('#bulkStatusSelect').val();
        if (!newStatus) { alert('Please select a status.'); return; }
        var $checkedRows = $('.row-checkbox:visible:checked');
        if ($checkedRows.length === 0) { alert('Please select at least one student.'); return; }
        if (!confirm('Apply "' + newStatus + '" to all visible subtopics for selected students?')) return;
        var updates = [];
        $checkedRows.each(function() {
            var sid = $(this).val();
            $(this).closest('tr').find('.att-cell:visible').each(function() {
                if (newStatus === 'unregister' || $(this).data('has-reg') == 1) updates.push({ student_id: sid, subtopic_id: $(this).data('subtopic-id') });
            });
        });
        if (updates.length === 0) { alert('No attendance cells found.'); return; }
        $.ajax({ url: 'bulk_update_attendance.php', type: 'POST', contentType: 'application/json', data: JSON.stringify({ updates: updates, status: newStatus }), dataType: 'json',
            success: function(res) { if (res.success) { alert('Updated ' + res.updated + ' records.'); location.reload(); } else alert('Error: ' + (res.error || 'Unknown')); },
            error: function() { alert('Network error.'); }
        });
    });

    $('#exportProgressBtn').on('click', function() {
        var columnsList = $('#exportColumnsList'); columnsList.empty();
        [{ key: 'student_no', label: 'Student No.', index: 1 }, { key: 'student_name', label: 'Student Name', index: 2 }, { key: 'course', label: 'Course', index: 3 }, { key: 'section', label: 'Section', index: 4 }, { key: 'progress', label: 'Progress (%)', index: table.columns().count() - 2 }, { key: 'modules_btn', label: 'Modules Button', index: table.columns().count() - 1 }].forEach(function(col) { columnsList.append('<div class="form-check"><input class="form-check-input export-col" type="checkbox" value="' + col.key + '" data-col-index="' + col.index + '" data-col-type="fixed" checked><label class="form-check-label">' + col.label + '</label></div>'); });
        table.columns().every(function(index) {
            if (index >= 5 && index < table.columns().count() - 2) {
                var header = $(this.header());
                var label = header.closest('th').data('subtopic') ? header.text() + ' (' + (header.hasClass('att-col') ? 'Att' : 'Mod') + ')' : header.text();
                columnsList.append('<div class="form-check"><input class="form-check-input export-col" type="checkbox" value="subtopic_' + index + '" data-col-index="' + index + '" data-col-type="subtopic" checked><label class="form-check-label">' + label + '</label></div>');
            }
        });
        $('#exportProgressModal').modal('show');
    });

    $('#doExportProgress').on('click', function() {
        var format = $('#exportFormat').val(), scope = $('#exportScope').val();
        if (format === 'detailed') {
            var studentIds = []; if (scope === 'selected') { $('.row-checkbox:visible:checked').each(function() { studentIds.push($(this).val()); }); if (studentIds.length === 0) { alert('Please select at least one student.'); return; } } else { table.rows({ search: 'applied' }).every(function() { var id = $(this.node()).data('student-id'); if (id) studentIds.push(id); }); if (studentIds.length === 0) { alert('No students to export.'); return; } }
            window.open('export_detailed_report.php?ids=' + studentIds.join(','), '_blank'); return;
        }
        var selectedCols = []; $('.export-col:checked').each(function() { selectedCols.push({ index: $(this).data('col-index'), type: $(this).data('col-type'), label: $(this).next('label').text() }); });
        if (selectedCols.length === 0) { alert('Please select at least one column.'); return; }
        var studentIds = []; if (scope === 'selected') { $('.row-checkbox:visible:checked').each(function() { studentIds.push($(this).val()); }); if (studentIds.length === 0) { alert('Please select at least one student.'); return; } } else { table.rows({ search: 'applied' }).every(function() { var id = $(this.node()).data('student-id'); if (id) studentIds.push(id); }); if (studentIds.length === 0) { alert('No students to export.'); return; } }
        var exportData = []; table.rows({ search: 'applied' }).every(function() { var row = this.node(), sid = $(row).data('student-id'); if (scope === 'selected' && !studentIds.includes(sid)) return; var $r = $(row), rd = { student_id: sid }; selectedCols.forEach(function(c) { var $c = $r.find('td').eq(c.index); if (c.type === 'subtopic') { rd[c.label] = $c.text().trim(); } else { rd[c.label] = $c.text().trim(); } }); exportData.push(rd); });
        if (exportData.length === 0) { alert('No data to export.'); return; }
        $.ajax({ url: 'export_progress.php', type: 'POST', contentType: 'application/json', data: JSON.stringify({ format: format, columns: selectedCols.map(function(c) { return c.label; }), data: exportData }), dataType: 'json',
            success: function(res) { if (res.success) { if (format === 'csv') { var blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8;' }); var link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = 'student_progress.csv'; document.body.appendChild(link); link.click(); document.body.removeChild(link); } else { var win = window.open(); win.document.write(res.html); win.document.close(); } } else alert('Export failed: ' + res.error); },
            error: function() { alert('Network error.'); }
        });
    });
    <?php endif; ?>
});
</script>
</body>
</html>