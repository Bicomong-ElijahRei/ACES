<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

// Student details (with safe fallback)
$stmt = $pdo->prepare("
    SELECT s.student_id, s.section, u.full_name, u.email
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id = ?
");
$stmt->execute([$_SESSION['student_id'] ?? '']);
$student = $stmt->fetch();

if (!$student) {
    header('Location: ../logout.php');
    exit;
}

$full_name = $student['full_name'];
$name_parts = explode(' ', $full_name);
$first_name = $name_parts[0];

// ---- Calendar logic with student‑specific data ----
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$first_day_of_month = "$year-$month-01";
$last_day_of_month = date('Y-m-t', strtotime($first_day_of_month));

// Fetch only sessions the student is registered for (active subtopics only)
$stmt_sessions = $pdo->prepare("
    SELECT DISTINCT s.session_id, s.title, s.date, s.start_time, s.end_time, s.proctor, s.location
    FROM sessions s
    JOIN registrations r ON s.session_id = r.session_id
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    WHERE r.student_id = ? AND s.is_deleted = 0 AND s.date BETWEEN ? AND ?
    ORDER BY s.date ASC
");
$stmt_sessions->execute([$_SESSION['student_id'], $first_day_of_month, $last_day_of_month]);
$sessions_by_date = [];
while ($row = $stmt_sessions->fetch()) {
    $day = date('j', strtotime($row['date']));
    $sessions_by_date[$day][] = $row;
}

// Holidays and school events (public)
$stmt_holidays = $pdo->prepare("SELECT date, name FROM holidays WHERE date BETWEEN ? AND ?");
$stmt_holidays->execute([$first_day_of_month, $last_day_of_month]);
$holidays = [];
while ($row = $stmt_holidays->fetch()) {
    $day = date('j', strtotime($row['date']));
    $holidays[$day][] = $row['name'];
}

$stmt_events = $pdo->prepare("SELECT date, name, description FROM school_events WHERE date BETWEEN ? AND ?");
$stmt_events->execute([$first_day_of_month, $last_day_of_month]);
$school_events = [];
while ($row = $stmt_events->fetch()) {
    $day = date('j', strtotime($row['date']));
    $school_events[$day][] = $row;
}

// Module due dates for this student (colour‑coded)
$stmt_modules_due = $pdo->prepare("
    SELECT m.due_date, m.title
    FROM modules m
    JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    JOIN registrations r ON r.subtopic_id = sub.subtopic_id AND r.student_id = ?
    WHERE m.is_deleted = 0 AND m.due_date BETWEEN ? AND ?
    ORDER BY m.due_date
");
$stmt_modules_due->execute([$_SESSION['student_id'], $first_day_of_month, $last_day_of_month]);
$modules_by_date = [];
while ($row = $stmt_modules_due->fetch()) {
    $day = date('j', strtotime($row['due_date']));
    $modules_by_date[$day][] = $row['title'];
}

// Student‑specific subtopics for modal (current month)
$stmt_registered_subtopics = $pdo->prepare("
    SELECT sub.subtopic_id, sub.title, sub.description, sub.subtopic_date,
           sub.subtopic_start_time, sub.subtopic_end_time,
           sub.subtopic_proctor, sub.subtopic_location,
           sub.attendance_type,
           s.title AS session_title, s.date, s.start_time, s.end_time
    FROM subtopics sub
    JOIN sessions s ON sub.session_id = s.session_id AND s.is_deleted = 0
    JOIN registrations r ON r.subtopic_id = sub.subtopic_id AND r.student_id = ?
    WHERE sub.is_deleted = 0 AND s.date BETWEEN ? AND ?
    ORDER BY s.date, sub.title
");
$stmt_registered_subtopics->execute([$_SESSION['student_id'], $first_day_of_month, $last_day_of_month]);
$my_subtopics_by_date = [];
while ($row = $stmt_registered_subtopics->fetch()) {
    $day = date('j', strtotime($row['date']));
    $my_subtopics_by_date[$day][] = $row;
}

// ---- Attendance Compliance ----
$stmt_total_reg = $pdo->prepare("
    SELECT COUNT(*) FROM registrations r
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned')
");
$stmt_total_reg->execute([$_SESSION['student_id']]);
$total_registered_subtopics = (int)$stmt_total_reg->fetchColumn();

$stmt_attended = $pdo->prepare("
    SELECT COUNT(DISTINCT a.subtopic_id)
    FROM attendance a
    JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    WHERE a.student_id = ? AND a.attendance_status = 'present' AND a.is_deleted = 0
");
$stmt_attended->execute([$_SESSION['student_id']]);
$attended_subtopics = (int)$stmt_attended->fetchColumn();

$attendance_compliance_pct = $total_registered_subtopics > 0
    ? round(($attended_subtopics / $total_registered_subtopics) * 100)
    : 0;

// ---- Module Progress (by subtopic) ----
$stmt_registered = $pdo->prepare("
    SELECT DISTINCT r.subtopic_id
    FROM registrations r
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    WHERE r.student_id = ?
");
$stmt_registered->execute([$_SESSION['student_id']]);
$registered_ids = $stmt_registered->fetchAll(PDO::FETCH_COLUMN);

$modules = [];
$total_modules = 0;
$completed_modules = 0;
$subtopics_progress = [];

if (!empty($registered_ids)) {
    $placeholders = implode(',', array_fill(0, count($registered_ids), '?'));
    $stmt_mod = $pdo->prepare("
        SELECT m.module_id, m.subtopic_id, m.title, m.due_date,
            IF(sp.completed, 1, 0) as completed
        FROM modules m
        LEFT JOIN student_module_progress sp
            ON sp.module_id = m.module_id AND sp.student_id = ?
        WHERE m.subtopic_id IN ($placeholders) AND m.is_deleted = 0
        ORDER BY m.subtopic_id
    ");
    $params = array_merge([$_SESSION['student_id']], $registered_ids);
    $stmt_mod->execute($params);
    $modules = $stmt_mod->fetchAll();

    $modules_by_subtopic = [];
    foreach ($modules as $mod) {
        $sid = $mod['subtopic_id'];
        $modules_by_subtopic[$sid][] = $mod;
        $total_modules++;
        if ($mod['completed']) $completed_modules++;
    }

    $stmt_sub = $pdo->prepare("SELECT subtopic_id, title FROM subtopics WHERE subtopic_id IN ($placeholders)");
    $stmt_sub->execute($registered_ids);
    $subtopic_titles = [];
    while ($row = $stmt_sub->fetch()) {
        $subtopic_titles[$row['subtopic_id']] = $row['title'];
    }

    foreach ($registered_ids as $sid) {
        $title = $subtopic_titles[$sid] ?? 'Unknown';
        $sub_mods = $modules_by_subtopic[$sid] ?? [];
        $sub_total = count($sub_mods);
        $sub_completed = 0;
        foreach ($sub_mods as $m) { if ($m['completed']) $sub_completed++; }
        $sub_percent = $sub_total > 0 ? round(($sub_completed / $sub_total) * 100) : 0;
        $subtopics_progress[] = [
            'subtopic_id' => $sid,
            'title' => $title,
            'total' => $sub_total,
            'completed' => $sub_completed,
            'percent' => $sub_percent,
            'modules' => $sub_mods
        ];
    }
}

$module_completion_pct = $total_modules > 0 ? round(($completed_modules / $total_modules) * 100) : 0;

// ---- TASK 10: Split tasks into To‑Do / Missing ----
$today = date('Y-m-d');
$todoTasks = [];
$missingTasks = [];
foreach ($modules as $mod) {
    if ($mod['completed']) continue;
    if (!$mod['due_date'] || $mod['due_date'] >= $today) {
        $todoTasks[] = $mod;
    } else {
        $ts = strtotime($mod['due_date']);
        if ($ts === false) {
            $todoTasks[] = $mod;
            continue;
        }
        $due = new DateTime($mod['due_date']);
        $now = new DateTime($today);
        $mod['days_overdue'] = $due->diff($now)->days;
        $missingTasks[] = $mod;
    }
}
$missingCount = count($missingTasks);
$todoCount    = count($todoTasks);

// Calendar helper (enhanced with module dots)
function generateCalendar($month, $year, $sessions_by_date, $holidays, $school_events, $modules_by_date) {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $firstDay = date('N', strtotime("$year-$month-01"));
    $calendar = '<div class="calendar-grid">';
    $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    foreach ($weekdays as $wd) {
        $calendar .= "<div class='calendar-weekday'>$wd</div>";
    }
    for ($i = 1; $i < $firstDay; $i++) {
        $calendar .= '<div class="calendar-day empty"></div>';
    }
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $day);
        $hasSession   = isset($sessions_by_date[$day]) && count($sessions_by_date[$day]) > 0;
        $hasHoliday   = isset($holidays[$day]) && count($holidays[$day]) > 0;
        $hasSchool    = isset($school_events[$day]) && count($school_events[$day]) > 0;
        $hasModule    = isset($modules_by_date[$day]) && count($modules_by_date[$day]) > 0;

        $eventClass = '';
        if ($hasSession) $eventClass .= ' has-session';
        if ($hasHoliday) $eventClass .= ' has-holiday';
        if ($hasSchool)  $eventClass .= ' has-school-event';
        if ($hasModule)  $eventClass .= ' has-module';

        $calendar .= "<div class='calendar-day$eventClass' data-date='$dateStr'>$day";
        if ($hasSession) $calendar .= '<i class="fas fa-calendar-alt event-icon session-icon"></i>';
        if ($hasHoliday) $calendar .= '<i class="fas fa-star event-icon holiday-icon"></i>';
        if ($hasSchool)  $calendar .= '<i class="fas fa-school event-icon school-icon"></i>';
        if ($hasModule)  $calendar .= '<i class="fas fa-file-alt event-icon module-icon"></i>';
        $calendar .= '</div>';
    }
    $calendar .= '</div>';
    return $calendar;
}

$calendarHtml = generateCalendar($month, $year, $sessions_by_date, $holidays, $school_events, $modules_by_date);

// Helper progress bar
function progressBar($percent, $color = '#0a3e6d') {
    $percent = min(100, max(0, $percent));
    return '<div class="w-full bg-gray-200 rounded-full h-2">
        <div class="h-2 rounded-full" style="width: ' . $percent . '%; background-color: ' . $color . ';"></div>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | ACES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-weekday { text-align: center; font-weight: 600; padding: 6px; background: #f0fdf4; color: #065f46; }
        .calendar-day { min-height: 90px; border: 1px solid #e2e8f0; padding: 4px; position: relative; cursor: pointer; }
        .calendar-day.empty { background: #f9fafb; cursor: default; }
        .calendar-day.has-session { border-top: 3px solid #10b981; }
        .calendar-day.has-holiday { border-top: 3px solid #ef4444; }
        .calendar-day.has-school-event { border-top: 3px solid #f59e0b; }
        .calendar-day.has-module { border-top: 3px solid #3b82f6; }
        .event-icon { font-size: 0.7rem; margin-right: 2px; }
        .session-icon { color: #10b981; }
        .holiday-icon { color: #ef4444; }
        .school-icon { color: #f59e0b; }
        .module-icon { color: #3b82f6; }
        .task-list { max-height: 400px; overflow-y: auto; }
        .task-item { display: flex; align-items: center; gap: 10px; padding: 8px; border-bottom: 1px solid #e5e7eb; }
        .subtopic-card { background: #fff; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .subtopic-tasks { margin-top: 10px; padding-left: 20px; }
        .expand-btn { cursor: pointer; color: #0a6e2d; }
        .event-item { margin-bottom: 12px; padding: 8px; border-left: 3px solid #0a6e2d; background: #f0fdf4; }
        .event-title { font-weight: 600; }
        .event-detail { font-size: 0.85rem; color: #4b5563; }
        .event-badge { font-size: 0.7rem; background: #0a6e2d; color: white; padding: 2px 6px; border-radius: 12px; margin-left: 8px; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .calendar-header a { text-decoration: none; padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; }
        .missing-task { border-left: 4px solid #ef4444; background: #fef2f2; }
        .missing-task:hover { background: #fde8e8; }
    </style>
</head>
<body class="h-full bg-[#dcf3e6] font-sans">
<div class="h-screen flex flex-col md:flex-row">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <div class="h-14 bg-white shadow flex items-center justify-between px-6 border-b">
            <div class="font-bold text-xl text-[#0a6e2d]">ACES</div>
            <a href="dashboard.php" class="text-gray-600"><i class="fas fa-home fa-lg"></i></a>
        </div>
        <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">
            <h2 class="text-2xl md:text-3xl font-bold text-[#0a6e2d] mb-6">Welcome, <?= htmlspecialchars($first_name) ?>!</h2>

            <!-- Calendar Card (enlarged) -->
            <div class="bg-white rounded shadow-xl overflow-hidden mb-6">
                <div class="bg-[#054018] px-4 py-3 font-bold text-white flex justify-between items-center">
                    <span><i class="fas fa-calendar-alt mr-2"></i>My Calendar</span>
                    <span class="text-xs text-white/70">
                        <i class="fas fa-circle" style="color:#10b981;"></i> Sessions &nbsp;
                        <i class="fas fa-circle" style="color:#3b82f6;"></i> Modules &nbsp;
                        <i class="fas fa-circle" style="color:#ef4444;"></i> Holidays &nbsp;
                        <i class="fas fa-circle" style="color:#f59e0b;"></i> Events
                    </span>
                </div>
                <div class="p-4">
                    <div class="calendar-header">
                        <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>">&lt; Prev</a>
                        <strong class="text-lg"><?= date('F Y', strtotime("$year-$month-01")) ?></strong>
                        <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>">Next &gt;</a>
                    </div>
                    <?= $calendarHtml ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- To‑Do / Tasks Card (Missing section always visible) -->
                <div class="bg-white rounded shadow-xl overflow-hidden">
                    <div class="bg-[#054018] px-4 py-3 font-bold text-white flex justify-between items-center">
                        <span>Tasks</span>
                        <span class="text-xs">
                            <?php if ($missingCount > 0): ?>
                                <span class="bg-red-500 text-white rounded-full px-2 py-0.5"><?= $missingCount ?> Missing</span>
                            <?php endif; ?>
                            <?php if ($todoCount > 0): ?>
                                <span class="bg-blue-500 text-white rounded-full px-2 py-0.5 ml-1"><?= $todoCount ?> To Do</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="p-4 task-list">
                        <?php if ($missingCount + $todoCount === 0): ?>
                            <p class="text-gray-500 text-sm">No tasks assigned yet.</p>
                        <?php else: ?>
                            <!-- Missing Section (always visible) -->
                            <?php if ($missingCount > 0): ?>
                                <div class="mb-4">
                                    <div class="font-semibold text-red-600 mb-2">
                                        <i class="fas fa-exclamation-triangle mr-1"></i> Missing (<?= $missingCount ?>)
                                    </div>
                                    <?php foreach ($missingTasks as $task): ?>
                                        <div class="task-item missing-task rounded mt-2">
                                            <i class="fas fa-circle text-red-500"></i>
                                            <a href="modules.php?module_id=<?= $task['module_id'] ?>"
                                               class="text-gray-800 hover:text-[#0a6e2d] flex-1 ml-2">
                                                <?= htmlspecialchars($task['title']) ?>
                                            </a>
                                            <span class="text-xs text-red-500 font-medium">
                                                <?= $task['days_overdue'] ?> day<?= $task['days_overdue'] > 1 ? 's' : '' ?> overdue
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- To‑Do Section (always visible) -->
                            <?php if ($todoCount > 0): ?>
                                <div>
                                    <div class="font-semibold text-blue-600 mb-2"><i class="fas fa-check-circle mr-1"></i> To Do (<?= $todoCount ?>)</div>
                                    <?php foreach ($todoTasks as $task): ?>
                                        <div class="task-item">
                                            <i class="fas fa-circle text-blue-400"></i>
                                            <a href="modules.php?module_id=<?= $task['module_id'] ?>"
                                               class="text-gray-800 hover:text-[#0a6e2d] flex-1 ml-2">
                                                <?= htmlspecialchars($task['title']) ?>
                                            </a>
                                            <?php if ($task['due_date']): ?>
                                                <span class="text-xs text-gray-500">Due <?= date('M d', strtotime($task['due_date'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- View All Tasks link -->
                            <div class="mt-3 text-right">
                                <a href="modules.php" class="text-sm text-[#0a6e2d] hover:underline">
                                    View All Tasks <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Attendance Compliance Card -->
                <div class="bg-white rounded shadow-xl overflow-hidden">
                    <div class="bg-[#054018] px-4 py-3 font-bold text-white">Attendance Compliance</div>
                    <div class="p-4">
                        <div class="text-sm text-gray-600 mb-2">
                            <?= $attended_subtopics ?> / <?= $total_registered_subtopics ?> subtopics attended
                        </div>
                        <p class="text-xs text-gray-500 mb-1">Attendance Progress</p>
                        <?= progressBar($attendance_compliance_pct, $attendance_compliance_pct >= 100 ? '#10b981' : ($attendance_compliance_pct > 0 ? '#f59e0b' : '#e5e7eb')) ?>
                        <div class="text-right text-sm font-medium mt-1"><?= $attendance_compliance_pct ?>%</div>
                    </div>
                </div>

                <!-- Module Completion Card -->
                <div class="bg-white rounded shadow-xl overflow-hidden">
                    <div class="bg-[#054018] px-4 py-3 font-bold text-white">Module Completion</div>
                    <div class="p-4">
                        <div class="text-sm text-gray-600 mb-2">
                            <?= $completed_modules ?> / <?= $total_modules ?> modules completed
                        </div>
                        <p class="text-xs text-gray-500 mb-1">Module Progress</p>
                        <?= progressBar($module_completion_pct, $module_completion_pct >= 100 ? '#10b981' : ($module_completion_pct > 0 ? '#0a3e6d' : '#e5e7eb')) ?>
                        <div class="text-right text-sm font-medium mt-1"><?= $module_completion_pct ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Per‑Sub‑topic Module Progress (Expanded View Button) -->
            <div class="bg-white rounded shadow-xl overflow-hidden mt-6">
                <div class="bg-[#054018] px-4 py-3 flex justify-between items-center">
                    <span class="font-bold text-white">Module Progress by Subtopic</span>
                    <span class="expand-btn text-white text-sm cursor-pointer" data-bs-toggle="modal" data-bs-target="#progressModal">
                        <i class="fas fa-expand-alt"></i> Expand
                    </span>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php if (!empty($subtopics_progress)): ?>
                            <?php foreach ($subtopics_progress as $sub): ?>
                                <div class="border rounded p-3">
                                    <h5 class="font-medium text-sm mb-1"><?= htmlspecialchars($sub['title']) ?></h5>
                                    <p class="text-xs text-gray-500 mb-1">Module Completion</p>
                                    <?= progressBar($sub['percent'], '#0a3e6d') ?>
                                    <div class="text-xs text-gray-600 mt-1"><?= $sub['percent'] ?>% (<?= $sub['completed'] ?>/<?= $sub['total'] ?>)</div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-gray-500 col-span-full">No subtopics registered yet. <a href="sessions.php" class="text-[#0a6e2d] underline">Register here</a>.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Daily Events Modal (student‑specific) -->
<div class="modal fade" id="dailyEventsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-green-700 text-white"><h5 class="modal-title">Events for <span id="modalDate"></span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="modalEventsList"></div></div></div>
</div>

<!-- Progress Modal -->
<div class="modal fade" id="progressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header bg-green-700 text-white"><h5 class="modal-title">Module Completion by Subtopic</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row">
        <?php if (!empty($subtopics_progress)): ?>
            <?php foreach ($subtopics_progress as $sub): ?>
                <div class="col-md-4 mb-3"><div class="subtopic-card"><h5><?= htmlspecialchars($sub['title']) ?></h5><p class="text-xs text-gray-500 mb-1">Module Completion</p><?= progressBar($sub['percent']) ?><div class="text-sm mt-1"><?= $sub['percent'] ?>% (<?= $sub['completed'] ?>/<?= $sub['total'] ?>)</div><div class="subtopic-tasks mt-2"><?php foreach ($sub['modules'] as $mod): ?><div class="task-item small"><i class="fas <?= $mod['completed'] ? 'fa-check-circle text-green-500' : 'fa-circle text-gray-300' ?>"></i><a href="modules.php?module_id=<?= $mod['module_id'] ?>" class="text-sm text-gray-800 hover:text-[#0a6e2d] ml-2"><?= htmlspecialchars($mod['title']) ?></a></div><?php endforeach; ?></div></div></div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No subtopics registered yet.</p>
        <?php endif; ?>
    </div></div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const mySubtopics = <?= json_encode($my_subtopics_by_date) ?>;
const holidays = <?= json_encode($holidays) ?>;
const schoolEvents = <?= json_encode($school_events) ?>;
const modulesByDate = <?= json_encode($modules_by_date) ?>;

document.querySelectorAll('.calendar-day:not(.empty)').forEach(day => {
    day.addEventListener('click', function() {
        const date = this.getAttribute('data-date');
        if (date) showDailyEvents(date);
    });
});

function showDailyEvents(date) {
    const displayDate = new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    document.getElementById('modalDate').innerText = displayDate;
    let html = '';
    const dayOfMonth = parseInt(date.split('-')[2], 10);

    if (mySubtopics[dayOfMonth]) {
        html += '<h6 class="fw-bold"><i class="fas fa-calendar-check text-green-600 mr-1"></i>My Sessions</h6>';
        mySubtopics[dayOfMonth].forEach(sub => {
            const isModule = sub.attendance_type === 'module';
            html += `<div class="event-item">
                <div class="event-title">${sub.title} ${isModule ? '<span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full ml-2">Module‑Based</span>' : ''}</div>
                <div class="event-detail">
                    ${isModule
                        ? '<div class="text-blue-700 mt-1"><i class="fas fa-laptop mr-1"></i> Complete all modules to be marked Present.</div>'
                        : `Session: ${sub.session_title}<br>Time: ${sub.start_time} – ${sub.end_time}<br>Proctor: ${sub.subtopic_proctor || 'TBA'}<br>Location: ${sub.subtopic_location || 'TBA'}`
                    }
                </div>
                <button onclick="sendReminder('${sub.subtopic_id}', '${displayDate}')" 
                class="text-xs text-blue-600 hover:underline mt-1">Remind me</button>
            </div>`;
        });
    }
    if (modulesByDate[dayOfMonth]) {
        html += '<h6 class="fw-bold mt-3"><i class="fas fa-file-alt text-blue-600 mr-1"></i>Modules Due</h6>';
        modulesByDate[dayOfMonth].forEach(title => {
            html += `<div class="event-item" style="border-left-color:#3b82f6; background:#eff6ff;"><div class="event-title">📘 ${title}</div></div>`;
        });
    }
    if (holidays[dayOfMonth]) {
        html += '<h6 class="fw-bold mt-3"><i class="fas fa-star text-red-600 mr-1"></i>Holidays</h6>';
        holidays[dayOfMonth].forEach(name => {
            html += `<div class="event-item" style="border-left-color:#ef4444; background:#fef2f2;"><div class="event-title">🎉 ${name}</div></div>`;
        });
    }
    if (schoolEvents[dayOfMonth]) {
        html += '<h6 class="fw-bold mt-3"><i class="fas fa-school text-orange-600 mr-1"></i>School Events</h6>';
        schoolEvents[dayOfMonth].forEach(ev => {
            html += `<div class="event-item" style="border-left-color:#f59e0b; background:#fff7ed;"><div class="event-title">${ev.name}</div>${ev.description ? `<div class="event-detail">${ev.description}</div>` : ''}</div>`;
        });
    }
        if (!html) html = '<p class="text-gray-500">No events for this day.</p>';
    document.getElementById('modalEventsList').innerHTML = html;
    new bootstrap.Modal(document.getElementById('dailyEventsModal')).show();
}

function sendReminder(subtopicId, date) {
    fetch('send_reminder.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `subtopic_id=${encodeURIComponent(subtopicId)}&date=${encodeURIComponent(date)}`
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
    })
    .catch(err => {
        alert('Reminder queued! Check your email shortly.');
    });
}
</script>
</body>
</html>