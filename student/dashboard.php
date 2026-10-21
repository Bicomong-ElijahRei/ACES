<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

// Student details
$stmt = $pdo->prepare("SELECT u.full_name FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.student_id = ?");
$stmt->execute([$_SESSION['student_id'] ?? '']);
$student = $stmt->fetch();
$first_name = explode(' ', $student['full_name'] ?? 'Student')[0];

// ---- Attendance Compliance ----
$stmt_total_reg = $pdo->prepare("SELECT COUNT(*) FROM registrations r JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned')");
$stmt_total_reg->execute([$_SESSION['student_id']]);
$total_registered_subtopics = (int)$stmt_total_reg->fetchColumn();

$stmt_attended = $pdo->prepare("SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 WHERE a.student_id = ? AND a.attendance_status = 'present' AND a.is_deleted = 0");
$stmt_attended->execute([$_SESSION['student_id']]);
$attended_subtopics = (int)$stmt_attended->fetchColumn();

$attendance_compliance_pct = $total_registered_subtopics > 0 ? round(($attended_subtopics / $total_registered_subtopics) * 100) : 0;

// ---- Module Progress ----
$stmt_registered = $pdo->prepare("SELECT DISTINCT r.subtopic_id FROM registrations r JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 WHERE r.student_id = ?");
$stmt_registered->execute([$_SESSION['student_id']]);
$registered_ids = $stmt_registered->fetchAll(PDO::FETCH_COLUMN);

$modules = [];
$total_modules = 0;
$completed_modules = 0;

if (!empty($registered_ids)) {
    $placeholders = implode(',', array_fill(0, count($registered_ids), '?'));
    $stmt_mod = $pdo->prepare("SELECT m.module_id, m.subtopic_id, m.title, m.due_date, IF(sp.completed, 1, 0) as completed FROM modules m LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = ? WHERE m.subtopic_id IN ($placeholders) AND m.is_deleted = 0 ORDER BY m.subtopic_id");
    $params = array_merge([$_SESSION['student_id']], $registered_ids);
    $stmt_mod->execute($params);
    $modules = $stmt_mod->fetchAll();

    foreach ($modules as $mod) {
        $total_modules++;
        if ($mod['completed']) $completed_modules++;
    }
}
$module_completion_pct = $total_modules > 0 ? round(($completed_modules / $total_modules) * 100) : 0;

// --- PHASE BREAKDOWN LOGIC ---
$phases = ['Preparation', 'Pre-Employment', 'Career Fair'];
$phase_progress = [];
$student_id = $_SESSION['student_id'] ?? '';

foreach ($phases as $phase) {
    $stmt_sub_total = $pdo->prepare("SELECT COUNT(r.subtopic_id) FROM registrations r JOIN subtopics st ON r.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 WHERE r.student_id = ? AND s.phase = ?");
    $stmt_sub_total->execute([$student_id, $phase]);
    $sub_total = (int)$stmt_sub_total->fetchColumn();

    $stmt_sub_done = $pdo->prepare("SELECT COUNT(a.attendance_id) FROM attendance a JOIN subtopics st ON a.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 WHERE a.student_id = ? AND a.attendance_status = 'present' AND s.phase = ?");
    $stmt_sub_done->execute([$student_id, $phase]);
    $sub_done = (int)$stmt_sub_done->fetchColumn();

    $stmt_mod_total = $pdo->prepare("SELECT COUNT(DISTINCT m.module_id) FROM modules m JOIN subtopics st ON m.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 JOIN registrations r ON r.subtopic_id = st.subtopic_id WHERE r.student_id = ? AND m.is_deleted = 0 AND s.phase = ?");
    $stmt_mod_total->execute([$student_id, $phase]);
    $mod_total = (int)$stmt_mod_total->fetchColumn();

    $stmt_mod_done = $pdo->prepare("SELECT COUNT(DISTINCT smp.module_id) FROM student_module_progress smp JOIN modules m ON smp.module_id = m.module_id AND m.is_deleted = 0 JOIN subtopics st ON m.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 JOIN registrations r ON r.subtopic_id = st.subtopic_id WHERE r.student_id = ? AND smp.completed = 1 AND s.phase = ?");
    $stmt_mod_done->execute([$student_id, $phase]);
    $mod_done = (int)$stmt_mod_done->fetchColumn();

    $total_items = $sub_total + $mod_total;
    $done_items = $sub_done + $mod_done;
    $pct = $total_items > 0 ? min(100, round(($done_items / $total_items) * 100)) : 0;

    $phase_progress[$phase] = [
        'total' => $total_items, 'done' => $done_items, 'pct' => $pct,
        'sub_total' => $sub_total, 'sub_done' => $sub_done,
        'mod_total' => $mod_total, 'mod_done' => $mod_done
    ];
}

// ---- BUILD UPCOMING EVENTS ----
$upcoming_events = [];
$today_str = date('Y-m-d');

// Sessions
$stmt_up_sess = $pdo->prepare("SELECT s.session_id, s.title, s.date, s.start_time as time, st.title as subtopic FROM registrations r JOIN subtopics st ON r.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 WHERE r.student_id = ? AND s.date >= ? AND s.date <= DATE_ADD(?, INTERVAL 30 DAY) ORDER BY s.date ASC");
$stmt_up_sess->execute([$_SESSION['student_id'], $today_str, $today_str]);
foreach($stmt_up_sess->fetchAll() as $row) {
    $upcoming_events[] = [
        'type' => 'session', 'title' => $row['title'], 'subtitle' => $row['subtopic'],
        'date' => $row['date'], 'time' => date('M d, Y • g:i A', strtotime($row['date'] . ' ' . $row['time'])),
        'timestamp' => strtotime($row['date']), 'link' => 'sessions.php'
    ];
}

// Modules
foreach ($modules as $mod) {
    if ($mod['completed']) continue;
    $due_date = $mod['due_date'];
    $ts = $due_date ? strtotime($due_date) : (time() + 86400 * 30);
    
    if ($due_date && $ts <= strtotime($today_str . ' +30 days')) {
        $upcoming_events[] = [
            'type' => 'module', 'title' => $mod['title'], 'subtitle' => 'Module Task',
            'date' => $due_date, 'time' => 'Due ' . date('M d, Y', $ts),
            'timestamp' => $ts, 'is_overdue' => ($due_date < $today_str), 'link' => 'modules.php?module_id=' . $mod['module_id']
        ];
    }
}

usort($upcoming_events, function($a, $b) { return $a['timestamp'] <=> $b['timestamp']; });
$upcoming_events = array_slice($upcoming_events, 0, 4);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | ACES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .clickable-card { transition: transform 0.2s, box-shadow 0.2s; }
        .clickable-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="h-full bg-[#dcf3e6] font-sans overflow-hidden">
<div class="h-screen flex flex-col md:flex-row overflow-hidden">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/student_header.php'; ?>
        
        <main class="flex-1 p-6 overflow-y-auto no-scrollbar w-full">
            
            <div class="mb-8">
                <h2 class="text-3xl font-extrabold text-[#0a6e2d]">Welcome back, <?= htmlspecialchars($first_name) ?>! 👋</h2>
                <p class="text-gray-600 mt-1">Here is your at-a-glance summary.</p>
            </div>

            <!-- TIER 1: THE HERO METRICS (Overall Progress & Up Next) -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <!-- Overall Progress Hero Card -->
                <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-8 flex flex-col md:flex-row items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-2">Overall Compliance</h3>
                        <p class="text-sm text-gray-500 mb-6 max-w-md">This combines your attendance and module completion across all ACES phases.</p>
                        <a href="progress.php" class="inline-flex items-center gap-2 bg-[#0a6e2d] hover:bg-[#054018] text-white px-5 py-2.5 rounded-lg font-bold text-sm transition">
                            View Detailed Breakdown <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <!-- Circular Progress Ring -->
                    <?php 
                        // Quick calculation for the ring
                        $total_expected = $total_registered_subtopics + $total_modules;
                        $total_done = $attended_subtopics + $completed_modules;
                        $overall_pct = $total_expected > 0 ? min(100, round(($total_done / $total_expected) * 100)) : 0;
                    ?>
                    <div class="relative w-32 h-32 flex items-center justify-center mt-6 md:mt-0">
                        <svg class="w-full h-full" viewBox="0 0 36 36">
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#e5e7eb" stroke-width="4"></circle>
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#0a6e2d" stroke-width="4" stroke-dasharray="<?= $overall_pct ?> 100" stroke-dashoffset="0" stroke-linecap="round"></circle>
                        </svg>
                        <div class="absolute text-3xl font-extrabold text-[#0a6e2d]"><?= $overall_pct ?>%</div>
                    </div>
                </div>

                <!-- Up Next Panel -->
                <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden flex flex-col">
                    <div class="bg-[#054018] px-5 py-4 font-bold text-white flex justify-between items-center">
                        <span><i class="fas fa-bell mr-2"></i>Up Next</span>
                        <a href="calendar.php" class="text-[10px] bg-white/20 px-2 py-1 rounded hover:bg-white/30 transition uppercase">View Calendar</a>
                    </div>
                    <div class="flex-1 overflow-y-auto custom-scrollbar max-h-[300px]">
                        <?php if (empty($upcoming_events)): ?>
                            <div class="p-8 text-center text-gray-400">
                                <i class="fas fa-mug-hot text-4xl mb-3 text-gray-300"></i>
                                <p class="text-sm">You're all caught up!</p>
                            </div>
                        <?php else: ?>
                            <ul class="divide-y divide-gray-100">
                                <?php foreach (array_slice($upcoming_events, 0, 4) as $ev): ?>
                                    <?php 
                                        $is_session = ($ev['type'] === 'session');
                                        $is_overdue = $ev['is_overdue'] ?? false;
                                        $bg_color = $is_session ? 'bg-blue-100 text-blue-600' : ($is_overdue ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600');
                                        $icon = $is_session ? 'fa-calendar-check' : 'fa-book';
                                    ?>
                                    <a href="<?= $ev['link'] ?>" class="block p-4 hover:bg-gray-50 transition-colors group">
                                        <div class="flex items-start gap-3">
                                            <div class="h-8 w-8 rounded-full <?= $bg_color ?> flex items-center justify-center flex-shrink-0 mt-1">
                                                <i class="fas <?= $icon ?> text-xs"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-bold text-gray-800 truncate"><?= htmlspecialchars($ev['title']) ?></p>
                                                <div class="flex items-center gap-2 mt-1 text-xs font-semibold text-gray-500">
                                                    <i class="far fa-calendar-alt"></i> <?= $ev['time'] ?>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TIER 2: THE SCAN METRICS (Phase Summaries) -->
            <h3 class="text-lg font-bold text-gray-800 mb-4">Phase Summary</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <?php foreach ($phase_progress as $phase_name => $data): ?>
                    <a href="sessions.php" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-5 clickable-card hover:border-[#0a6e2d]">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="font-bold text-gray-800"><?= $phase_name ?></h4>
                            <span class="text-lg font-extrabold <?= $data['pct'] === 100 ? 'text-green-600' : 'text-[#0a6e2d]' ?>"><?= $data['pct'] ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2 mb-3">
                            <div class="h-2 rounded-full <?= $data['pct'] === 100 ? 'bg-green-500' : 'bg-[#0a6e2d]' ?>" style="width: <?= $data['pct'] ?>%"></div>
                        </div>
                        <div class="flex gap-3 text-xs font-semibold text-gray-500">
                            <span><i class="fas fa-calendar-check text-blue-500"></i> <?= $data['sub_done'] ?>/<?= $data['sub_total'] ?> Sessions</span>
                            <span><i class="fas fa-book text-purple-500"></i> <?= $data['mod_done'] ?>/<?= $data['mod_total'] ?> Modules</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

        </main>
    </div>
</div>
</body>
</html>