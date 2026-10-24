<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

// ---- Attendance Compliance ----
$stmt_total_reg = $pdo->prepare("SELECT COUNT(*) FROM registrations r JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0 WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned')");
$stmt_total_reg->execute([$_SESSION['student_id']]);
$total_registered_subtopics = (int)$stmt_total_reg->fetchColumn();

$stmt_attended = $pdo->prepare("SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0 JOIN registrations r ON r.subtopic_id = a.subtopic_id AND r.student_id = a.student_id AND r.status IN ('assigned','auto_assigned') WHERE a.student_id = ? AND a.attendance_status = 'present' AND a.is_deleted = 0");
$stmt_attended->execute([$_SESSION['student_id']]);
$attended_subtopics = (int)$stmt_attended->fetchColumn();

// Force values to be integers before calculating
$total_registered_subtopics = (int)$total_registered_subtopics;
$attended_subtopics = (int)$attended_subtopics;

$attendance_compliance_pct = $total_registered_subtopics > 0 ? round(($attended_subtopics / $total_registered_subtopics) * 100) : 0;

// ---- Module Progress ----
$stmt_registered = $pdo->prepare("SELECT DISTINCT r.subtopic_id FROM registrations r JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0 WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned')");
$stmt_registered->execute([$_SESSION['student_id']]);
$registered_ids = $stmt_registered->fetchAll(PDO::FETCH_COLUMN);

$modules = [];
$total_modules = 0;
$completed_modules = 0;
$subtopics_progress = [];

if (!empty($registered_ids)) {
    $placeholders = implode(',', array_fill(0, count($registered_ids), '?'));
    $stmt_mod = $pdo->prepare("SELECT m.module_id, m.subtopic_id, m.title, m.due_date, IF(sp.completed, 1, 0) as completed FROM modules m LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = ? WHERE m.subtopic_id IN ($placeholders) AND m.is_deleted = 0 ORDER BY m.subtopic_id");
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

// --- PHASE BREAKDOWN LOGIC ---
$phases = ['Preparation', 'Pre-Employment', 'Career Fair'];
$phase_progress = [];
$student_id = $_SESSION['student_id'] ?? '';

foreach ($phases as $phase) {
    $stmt_sub_total = $pdo->prepare("SELECT COUNT(r.subtopic_id) FROM registrations r JOIN subtopics st ON r.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 WHERE r.student_id = ? AND s.phase = ? AND r.status IN ('assigned','auto_assigned')");
    $stmt_sub_total->execute([$student_id, $phase]);
    $sub_total = (int)$stmt_sub_total->fetchColumn();

    $stmt_sub_done = $pdo->prepare("SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a JOIN subtopics st ON a.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 JOIN registrations r ON r.subtopic_id = a.subtopic_id AND r.student_id = a.student_id AND r.status IN ('assigned','auto_assigned') WHERE a.student_id = ? AND a.attendance_status = 'present' AND a.is_deleted = 0 AND s.phase = ?");
    $stmt_sub_done->execute([$student_id, $phase]);
    $sub_done = (int)$stmt_sub_done->fetchColumn();

    $stmt_mod_total = $pdo->prepare("SELECT COUNT(DISTINCT m.module_id) FROM modules m JOIN subtopics st ON m.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 JOIN registrations r ON r.subtopic_id = st.subtopic_id AND r.status IN ('assigned','auto_assigned') WHERE r.student_id = ? AND m.is_deleted = 0 AND s.phase = ?");
    $stmt_mod_total->execute([$student_id, $phase]);
    $mod_total = (int)$stmt_mod_total->fetchColumn();

    $stmt_mod_done = $pdo->prepare("SELECT COUNT(DISTINCT smp.module_id) FROM student_module_progress smp JOIN modules m ON smp.module_id = m.module_id AND m.is_deleted = 0 JOIN subtopics st ON m.subtopic_id = st.subtopic_id AND st.is_deleted = 0 JOIN sessions s ON st.session_id = s.session_id AND s.is_deleted = 0 JOIN registrations r ON r.subtopic_id = st.subtopic_id AND r.status IN ('assigned','auto_assigned') WHERE r.student_id = ? AND smp.completed = 1 AND s.phase = ?");
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

function progressBar($percent, $color = '#0a6e2d') {
    $percent = min(100, max(0, $percent));
    return '<div class="w-full bg-gray-200 rounded-full h-2"><div class="h-2 rounded-full" style="width: ' . $percent . '%; background-color: ' . $color . ';"></div></div>';
}
// --- COMPLIANCE HISTORY & MISSED TRACKING ---
// 1. Get student's program and section
$stmt_stud = $pdo->prepare("SELECT program, section FROM students WHERE student_id = ?");
$stmt_stud->execute([$_SESSION['student_id']]);
$stud_info = $stmt_stud->fetch();
$program = $stud_info['program'] ?? '';
$section = $stud_info['section'] ?? '';

// 2. Fetch all relevant subtopics
$stmt_all_subs = $pdo->query("
    SELECT st.subtopic_id, st.title AS subtopic_title, st.deadline, st.visible_courses, st.visible_for,
           st.required_courses, st.required_for,
           s.title AS session_title, s.phase, s.date,
           COALESCE(st.subtopic_date, s.date) AS effective_date
    FROM subtopics st
    JOIN sessions s ON st.session_id = s.session_id
    WHERE st.is_deleted = 0 AND s.is_deleted = 0
    ORDER BY effective_date DESC
");
$all_subs = $stmt_all_subs->fetchAll();

// 3. Fetch student's registrations
$stmt_regs = $pdo->prepare("SELECT subtopic_id FROM registrations WHERE student_id = ? AND status IN ('assigned','auto_assigned')");
$stmt_regs->execute([$_SESSION['student_id']]);
$my_regs = $stmt_regs->fetchAll(PDO::FETCH_COLUMN);

// 4. Fetch student's attendance
$stmt_atts = $pdo->prepare("SELECT subtopic_id, attendance_status FROM attendance WHERE student_id = ? AND is_deleted = 0");
$stmt_atts->execute([$_SESSION['student_id']]);
$my_atts_raw = $stmt_atts->fetchAll(PDO::FETCH_ASSOC);
$my_atts = [];
foreach ($my_atts_raw as $a) {
    $my_atts[$a['subtopic_id']] = $a['attendance_status'];
}

$history_completed = [];
$history_missed = [];
$history_pending = [];
$history_not_registered = [];

foreach ($all_subs as $sub) {
    // Check visibility
    $vis_courses = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
    $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
    
    $vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
    $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
    $req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];
    $req_sections = json_decode($sub['required_for'] ?? '[]', true) ?: [];
    
    $has_vis = !empty($vis_courses) || !empty($vis_sections);
    $has_req = !empty($req_courses) || !empty($req_sections);
    
    $is_visible = false;
    
    if (!$has_vis && !$has_req) {
        $is_visible = true;
    } else {
        // AND logic for required
        if ($has_req) {
            $match = true;
            if (!empty($req_courses) && !in_array($program, $req_courses)) $match = false;
            if (!empty($req_sections) && !in_array($section, $req_sections)) $match = false;
            if ($match) $is_visible = true;
        }
        // AND logic for visible
        if ($has_vis) {
            $match = true;
            if (!empty($vis_courses) && !in_array($program, $vis_courses)) $match = false;
            if (!empty($vis_sections) && !in_array($section, $vis_sections)) $match = false;
            if ($match) $is_visible = true;
        }
    }
    
    if (!$is_visible) continue;

    $sub_id = $sub['subtopic_id'];
    $is_registered = in_array($sub_id, $my_regs);
    $att_status = $my_atts[$sub_id] ?? null;

    // Check if the session date is in the past
    $today = date('Y-m-d');
    // FIX: Use effective_date to account for subtopic date overrides
    $sub_date = $sub['effective_date']; 
    $is_past = ($sub_date < $today);

    if ($is_registered) {
        if ($att_status === 'present') {
            $history_completed[] = $sub;
        } elseif ($att_status === 'absent' || $is_past) {
            $history_missed[] = $sub;
        } else {
            $history_pending[] = $sub; 
        }
    } else {
        // FIX: Only count it as "Not Registered" if the event has already passed
        if ($is_past) {
            $history_not_registered[] = $sub;
        }
    }
}
// ---------------------------------------------
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Progress | ACES</title>
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
            <div class="mb-8 flex justify-between items-end">
                <div>
                    <h2 class="text-3xl font-extrabold text-[#0a6e2d]">Detailed Progress</h2>
                    <p class="text-gray-600 mt-1">View your complete breakdown of attendance and modules by phase.</p>
                </div>
                <a href="calendar.php" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg font-bold text-sm transition shadow-sm hidden md:inline-flex items-center gap-2">
                    <i class="fas fa-calendar-alt"></i> View Calendar
                </a>
            </div>

            <!-- TIER 1: QUICK SUMMARY CARDS -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                <!-- Attendance Summary -->
                <a href="sessions.php" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-5 clickable-card hover:border-blue-500 flex items-center justify-between group">
                    <div>
                        <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-1">Attendance</h3>
                        <div class="text-2xl font-extrabold text-gray-800"><?= $attended_subtopics ?> <span class="text-sm text-gray-400 font-medium">/ <?= $total_registered_subtopics ?></span></div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-extrabold text-blue-600"><?= $attendance_compliance_pct ?>%</div>
                        <div class="text-xs text-blue-500 group-hover:underline">Manage &rarr;</div>
                    </div>
                </a>

                <!-- Module Summary -->
                <a href="modules.php" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-5 clickable-card hover:border-purple-500 flex items-center justify-between group">
                    <div>
                        <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-1">Modules</h3>
                        <div class="text-2xl font-extrabold text-gray-800"><?= $completed_modules ?> <span class="text-sm text-gray-400 font-medium">/ <?= $total_modules ?></span></div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-extrabold text-purple-600"><?= $module_completion_pct ?>%</div>
                        <div class="text-xs text-purple-500 group-hover:underline">Manage &rarr;</div>
                    </div>
                </a>
            </div>

            <!-- TIER 2: PHASE BREAKDOWN -->
            <h3 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-layer-group text-[#0a6e2d] mr-2"></i>Career Phase Breakdown</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
                <?php foreach ($phase_progress as $phase_name => $data): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="font-bold text-gray-800 text-sm"><?= $phase_name ?></h4>
                            <span class="text-sm font-extrabold <?= $data['pct'] === 100 ? 'text-green-600' : 'text-[#0a6e2d]' ?>"><?= $data['pct'] ?>%</span>
                        </div>
                        <?= progressBar($data['pct'], $data['pct'] === 100 ? '#10b981' : '#0a6e2d') ?>
                        <div class="flex gap-3 text-[11px] font-semibold text-gray-500 mt-3">
                            <span><i class="fas fa-calendar-check text-blue-500"></i> <?= $data['sub_done'] ?>/<?= $data['sub_total'] ?> Sessions</span>
                            <span><i class="fas fa-book text-purple-500"></i> <?= $data['mod_done'] ?>/<?= $data['mod_total'] ?> Modules</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- TIER 3: DETAILED SUBTOPIC BREAKDOWN -->
            <h3 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-book-open text-[#0a6e2d] mr-2"></i>Module Breakdown by Subtopic</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <?php if (!empty($subtopics_progress)): ?>
                    <?php foreach ($subtopics_progress as $sub): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex flex-col h-full">
                            <div class="flex justify-between items-start mb-3">
                                <h4 class="font-bold text-sm text-gray-800 leading-tight pr-2"><?= htmlspecialchars($sub['title']) ?></h4>
                                <span class="text-xs font-bold text-[#0a6e2d] bg-green-50 px-2 py-1 rounded-md whitespace-nowrap"><?= $sub['percent'] ?>%</span>
                            </div>
                            <?= progressBar($sub['percent'], $sub['percent'] === 100 ? '#10b981' : '#0a6e2d') ?>
                            
                            <div class="mt-4 space-y-2 flex-1">
                                <?php if (empty($sub['modules'])): ?>
                                    <p class="text-xs text-gray-400 italic">No modules in this subtopic.</p>
                                <?php else: ?>
                                    <?php foreach ($sub['modules'] as $mod): ?>
                                        <a href="modules.php?module_id=<?= $mod['module_id'] ?>" class="flex items-start gap-2 text-xs border-b border-gray-100 pb-2 group hover:bg-gray-50 -mx-2 px-2 py-1 rounded transition-colors">
                                            <i class="fas <?= $mod['completed'] ? 'fa-check-circle text-green-500' : 'fa-circle text-gray-300' ?> mt-0.5 group-hover:scale-110 transition-transform"></i>
                                            <span class="text-gray-700 group-hover:text-[#0a6e2d] font-medium leading-tight flex-1"><?= htmlspecialchars($mod['title']) ?></span>
                                            <i class="fas fa-chevron-right text-[10px] text-gray-300 mt-0.5 group-hover:text-[#0a6e2d]"></i>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <a href="modules.php" class="mt-4 block text-center w-full bg-gray-50 hover:bg-[#0a6e2d] text-gray-600 hover:text-white text-xs font-bold py-2 rounded-lg transition-colors border border-gray-200 hover:border-[#0a6e2d]">
                                Go to Modules
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-full bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
                        <i class="fas fa-folder-open text-4xl mb-3 text-gray-300"></i>
                        <p>No subtopics registered yet.</p>
                        <a href="sessions.php" class="text-[#0a6e2d] font-bold hover:underline mt-2 inline-block">Register for Sessions</a>
                    </div>
                <?php endif; ?>
            </div>
                        <!-- COMPLIANCE HISTORY -->
            <div class="mt-8 mb-8">
                <h3 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-history text-[#0a6e2d] mr-2"></i>Compliance History</h3>
                
                <!-- Tabs -->
                <div class="flex border-b border-gray-200 mb-4 space-x-4">
                    <button class="history-tab font-bold text-sm pb-2 border-b-2 border-[#0a6e2d] text-[#0a6e2d]" data-target="tab-missed">
                        <i class="fas fa-times-circle text-red-500 mr-1"></i> Missed (<?= count($history_missed) ?>)
                    </button>
                    <button class="history-tab font-semibold text-sm pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-800" data-target="tab-pending">
                        <i class="fas fa-clock text-amber-500 mr-1"></i> Pending (<?= count($history_pending) ?>)
                    </button>
                    <button class="history-tab font-semibold text-sm pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-800" data-target="tab-not-reg">
                        <i class="fas fa-minus-circle text-gray-400 mr-1"></i> Not Registered (<?= count($history_not_registered) ?>)
                    </button>
                    <button class="history-tab font-semibold text-sm pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-800" data-target="tab-completed">
                        <i class="fas fa-check-circle text-green-500 mr-1"></i> Completed (<?= count($history_completed) ?>)
                    </button>
                </div>

                <!-- Content Panels -->
                <div id="tab-missed" class="history-content block bg-white rounded-xl shadow-sm border border-red-200 overflow-hidden">
                    <?php if (empty($history_missed)): ?>
                        <div class="p-6 text-center text-gray-400">No missed subtopics. Great job!</div>
                    <?php else: ?>
                        <ul class="divide-y divide-gray-100">
                            <?php foreach ($history_missed as $sub): ?>
                                <li class="p-4 flex justify-between items-center hover:bg-red-50 transition">
                                    <div>
                                        <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sub['subtopic_title']) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($sub['session_title']) ?> · <?= date('M d, Y', strtotime($sub['date'])) ?></p>
                                    </div>
                                    <span class="bg-red-100 text-red-700 text-xs font-bold px-2.5 py-1 rounded-md border border-red-200">Absent</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div id="tab-pending" class="history-content hidden bg-white rounded-xl shadow-sm border border-amber-200 overflow-hidden">
                    <?php if (empty($history_pending)): ?>
                        <div class="p-6 text-center text-gray-400">No pending subtopics.</div>
                    <?php else: ?>
                        <ul class="divide-y divide-gray-100">
                            <?php foreach ($history_pending as $sub): ?>
                                <li class="p-4 flex justify-between items-center hover:bg-amber-50 transition">
                                    <div>
                                        <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sub['subtopic_title']) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($sub['session_title']) ?> · <?= date('M d, Y', strtotime($sub['date'])) ?></p>
                                    </div>
                                    <span class="bg-amber-100 text-amber-700 text-xs font-bold px-2.5 py-1 rounded-md border border-amber-200">Pending</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div id="tab-not-reg" class="history-content hidden bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <?php if (empty($history_not_registered)): ?>
                        <div class="p-6 text-center text-gray-400">You registered for all available subtopics.</div>
                    <?php else: ?>
                        <ul class="divide-y divide-gray-100">
                            <?php foreach ($history_not_registered as $sub): ?>
                                <li class="p-4 flex justify-between items-center hover:bg-gray-50 transition">
                                    <div>
                                        <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sub['subtopic_title']) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($sub['session_title']) ?> · <?= date('M d, Y', strtotime($sub['date'])) ?></p>
                                    </div>
                                    <span class="bg-gray-100 text-gray-600 text-xs font-bold px-2.5 py-1 rounded-md border border-gray-300">Unregistered</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div id="tab-completed" class="history-content hidden bg-white rounded-xl shadow-sm border border-green-200 overflow-hidden">
                    <?php if (empty($history_completed)): ?>
                        <div class="p-6 text-center text-gray-400">No completed subtopics yet.</div>
                    <?php else: ?>
                        <ul class="divide-y divide-gray-100">
                            <?php foreach ($history_completed as $sub): ?>
                                <li class="p-4 flex justify-between items-center hover:bg-green-50 transition">
                                    <div>
                                        <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sub['subtopic_title']) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($sub['session_title']) ?> · <?= date('M d, Y', strtotime($sub['date'])) ?></p>
                                    </div>
                                    <span class="bg-green-100 text-green-700 text-xs font-bold px-2.5 py-1 rounded-md border border-green-200">Present</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SCRIPT FOR TABS -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tabs = document.querySelectorAll('.history-tab');
                const contents = document.querySelectorAll('.history-content');

                tabs.forEach(tab => {
                    tab.addEventListener('click', function() {
                        // Reset all tabs
                        tabs.forEach(t => {
                            t.classList.remove('border-[#0a6e2d]', 'text-[#0a6e2d]', 'font-bold');
                            t.classList.add('border-transparent', 'text-gray-500', 'font-semibold');
                        });
                        
                        // Hide all contents
                        contents.forEach(c => c.classList.add('hidden'));

                        // Activate clicked tab
                        this.classList.remove('border-transparent', 'text-gray-500', 'font-semibold');
                        this.classList.add('border-[#0a6e2d]', 'text-[#0a6e2d]', 'font-bold');

                        // Show target content
                        const targetId = this.getAttribute('data-target');
                        document.getElementById(targetId).classList.remove('hidden');
                    });
                });
            });
            </script>
        </main>
    </div>
</div>
</body>
</html>