<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStudent();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// ---- Student details ----
$stmt = $pdo->prepare("SELECT u.full_name, s.section, s.program FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.student_id = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();
$full_name = $student['full_name'] ?? 'Student';
$first_name = explode(' ', $full_name)[0];

// ---- Current module ----
$stmt_module = $pdo->prepare("
    SELECT m.module_id, m.title, m.description, m.due_date
    FROM modules m
    JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0
    JOIN registrations r ON r.subtopic_id = sub.subtopic_id AND r.status IN ('assigned','auto_assigned')
    LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = r.student_id
    WHERE r.student_id = ? AND m.is_deleted = 0 AND (sp.completed IS NULL OR sp.completed = 0)
    ORDER BY m.due_date ASC LIMIT 1
");
$stmt_module->execute([$_SESSION['student_id']]);
$current_module = $stmt_module->fetch();

// ---- Upcoming sessions ----
$today = date('Y-m-d');
$stmt_sessions = $pdo->prepare("SELECT s.*, u.full_name as creator_name FROM sessions s LEFT JOIN users u ON s.created_by = u.user_id WHERE s.date >= ? AND s.is_deleted = 0 ORDER BY s.date ASC");
$stmt_sessions->execute([$today]);
$sessions = $stmt_sessions->fetchAll();
$student_id = $_SESSION['student_id'];
$all_dates = [];

foreach ($sessions as &$session) {
    $student_program = $student['program'] ?? '';
    $student_section = $student['section'] ?? '';

    $section_key = $student['program'] . '-' . $student['section'];
    $stmt_sub = $pdo->prepare("
        SELECT sub.*, 
               (SELECT COUNT(*) FROM registrations r JOIN students s ON r.student_id = s.student_id 
                WHERE r.subtopic_id = sub.subtopic_id AND s.program = ? AND s.section = ? 
                AND r.status IN ('assigned','auto_assigned')) AS section_attendee_count,
               (SELECT capacity FROM subtopic_section_capacity 
                WHERE subtopic_id = sub.subtopic_id AND section = ?) AS section_capacity,
               (SELECT COUNT(*) FROM registrations WHERE subtopic_id = sub.subtopic_id AND status IN ('assigned','auto_assigned')) AS attendee_count,
               (SELECT COUNT(*) FROM modules WHERE subtopic_id = sub.subtopic_id AND is_deleted = 0) AS module_count
        FROM subtopics sub 
        WHERE sub.session_id = ? AND sub.is_deleted = 0 
        ORDER BY sub.title
    ");
    $stmt_sub->execute([$student_program, $student_section, $section_key, $session['session_id']]);
    $all_subtopics = $stmt_sub->fetchAll();

    $visible_subtopics = [];
    foreach ($all_subtopics as $sub) {
        $visible = false; $required = false;
        $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
        $vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
        $req_sections = json_decode($sub['required_for'] ?? '[]', true) ?: [];
        $req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];

        $has_vis_restrictions = !empty($vis_sections) || !empty($vis_courses);
        $has_req_restrictions = !empty($req_sections) || !empty($req_courses);

        // Public
        if (!$has_vis_restrictions && !$has_req_restrictions) $visible = true;

        // Required (AND logic)
        if ($has_req_restrictions) {
            $match_req = true;
            if (!empty($req_courses) && !in_array($student_program, $req_courses)) $match_req = false;
            if (!empty($req_sections) && !in_array($student_section, $req_sections)) $match_req = false;
            if ($match_req) { $required = true; $visible = true; }
        }

        // Visible (AND logic)
        if ($has_vis_restrictions) {
            $match_vis = true;
            if (!empty($vis_courses) && !in_array($student_program, $vis_courses)) $match_vis = false;
            if (!empty($vis_sections) && !in_array($student_section, $vis_sections)) $match_vis = false;
            if ($match_vis) $visible = true;
        }

        if ($visible) {
            $sub_date_check = !empty($sub['subtopic_date']) ? $sub['subtopic_date'] : $session['date'];
            if ($sub_date_check >= $today) {
                $sub['required'] = $required;
                $visible_subtopics[] = $sub;
            }
        }
    }

    // Auto-register required subtopics
    $stmt_check_reg = $pdo->prepare("SELECT r.registration_id FROM registrations r JOIN subtopics st ON r.subtopic_id = st.subtopic_id WHERE st.session_id = ? AND r.student_id = ? AND r.status IN ('assigned','auto_assigned')");
    $stmt_check_reg->execute([$session['session_id'], $student_id]);
    $already_registered = (bool) $stmt_check_reg->fetch();

    foreach ($visible_subtopics as &$sub) {
        if (!$sub['required']) continue;
        if (!($session['allow_multiple'] ?? 0) && $already_registered) continue;

        $stmt_already = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND subtopic_id = ?");
        $stmt_already->execute([$student_id, $sub['subtopic_id']]);
        if ($stmt_already->fetch()) continue;

        $stmt_reg = $pdo->prepare("INSERT IGNORE INTO registrations (student_id, session_id, subtopic_id, status, registration_date) VALUES (?, ?, ?, 'auto_assigned', NOW())");
        $stmt_reg->execute([$student_id, $session['session_id'], $sub['subtopic_id']]);
        $already_registered = true;
    }
    unset($sub);

    $session['subtopics'] = $visible_subtopics;

    if (count($visible_subtopics) > 0) {
        foreach ($visible_subtopics as $v_sub) {
            $all_dates[] = !empty($v_sub['subtopic_date']) ? $v_sub['subtopic_date'] : $session['date'];
        }
    }

    $stmt_reg = $pdo->prepare("SELECT r.subtopic_id, st.title AS subtopic_title FROM registrations r JOIN subtopics st ON r.subtopic_id = st.subtopic_id WHERE r.student_id = ? AND r.session_id = ? AND r.status IN ('assigned','auto_assigned')");
    $stmt_reg->execute([$student_id, $session['session_id']]);
    $reg_rows = $stmt_reg->fetchAll(PDO::FETCH_ASSOC);
    $session['registered_subtopics']      = array_column($reg_rows, 'subtopic_id');
    $session['registered_subtopics_full'] = $reg_rows;
}
unset($session);
$all_dates = array_unique($all_dates);

// --- PAST SESSIONS HISTORY ---
$stmt_past_subs = $pdo->prepare("
    SELECT st.subtopic_id, st.title AS subtopic_title, st.visible_courses, st.visible_for,
           s.title AS session_title, COALESCE(st.subtopic_date, s.date) AS actual_date, s.phase
    FROM subtopics st JOIN sessions s ON st.session_id = s.session_id
    WHERE st.is_deleted = 0 AND s.is_deleted = 0 AND COALESCE(st.subtopic_date, s.date) < ?
    ORDER BY actual_date DESC
");
$stmt_past_subs->execute([$today]);
$past_subs = $stmt_past_subs->fetchAll();

$stmt_my_regs = $pdo->prepare("SELECT subtopic_id FROM registrations WHERE student_id = ?");
$stmt_my_regs->execute([$student_id]);
$my_regs = $stmt_my_regs->fetchAll(PDO::FETCH_COLUMN);

$stmt_my_atts = $pdo->prepare("SELECT subtopic_id, attendance_status FROM attendance WHERE student_id = ? AND is_deleted = 0");
$stmt_my_atts->execute([$student_id]);
$my_atts_raw = $stmt_my_atts->fetchAll(PDO::FETCH_ASSOC);
$my_atts = [];
foreach ($my_atts_raw as $a) { $my_atts[$a['subtopic_id']] = $a['attendance_status']; }

$past_completed = []; $past_missed = []; $past_not_reg = [];
foreach ($past_subs as $sub) {
    $vis_courses = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
    $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
    $is_visible = empty($vis_courses) && empty($vis_sections);
    if (!empty($vis_courses) || !empty($vis_sections)) {
        $match_vis = true;
        if (!empty($vis_courses) && !in_array($student['program'] ?? '', $vis_courses)) $match_vis = false;
        if (!empty($vis_sections) && !in_array($student['section'] ?? '', $vis_sections)) $match_vis = false;
        $is_visible = $match_vis;
    }
    if (!$is_visible) continue;

    $sub_id = $sub['subtopic_id'];
    $is_reg = in_array($sub_id, $my_regs);
    $att = $my_atts[$sub_id] ?? null;

    if ($is_reg) {
        if ($att === 'present') { $past_completed[] = $sub; } else { $past_missed[] = $sub; }
    } else { $past_not_reg[] = $sub; }
}

// ---- Progress Calculation ----
$stmt_sub_total = $pdo->prepare("SELECT COUNT(r.subtopic_id) FROM registrations r JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0 WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned')");
$stmt_sub_total->execute([$student_id]);
$total_subtopics_expected = (int)$stmt_sub_total->fetchColumn();

$stmt_sub_done = $pdo->prepare("SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0 JOIN registrations r ON r.subtopic_id = a.subtopic_id AND r.student_id = a.student_id AND r.status IN ('assigned','auto_assigned') WHERE a.student_id = ? AND a.attendance_status = 'present' AND a.is_deleted = 0");
$stmt_sub_done->execute([$student_id]);
$attended_subtopics = (int)$stmt_sub_done->fetchColumn();

$stmt_total_mods = $pdo->prepare("SELECT COUNT(DISTINCT m.module_id) FROM modules m JOIN registrations r ON m.subtopic_id = r.subtopic_id AND r.status IN ('assigned','auto_assigned') JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0 WHERE r.student_id = ? AND m.is_deleted = 0");
$stmt_total_mods->execute([$student_id]);
$total_modules_expected = (int)$stmt_total_mods->fetchColumn();

$stmt_done_mods = $pdo->prepare("SELECT COUNT(DISTINCT smp.module_id) FROM student_module_progress smp JOIN modules m ON smp.module_id = m.module_id AND m.is_deleted = 0 JOIN registrations r ON m.subtopic_id = r.subtopic_id AND r.status IN ('assigned','auto_assigned') JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0 JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0 WHERE r.student_id = ? AND smp.completed = 1");
$stmt_done_mods->execute([$student_id]);
$total_modules_done = (int)$stmt_done_mods->fetchColumn();

$total_items_expected = $total_subtopics_expected + $total_modules_expected;
$total_items_completed = $attended_subtopics + $total_modules_done;
if ($total_items_completed > $total_items_expected) $total_items_completed = $total_items_expected;
$progress_pct = $total_items_expected > 0 ? min(100, round(($total_items_completed / $total_items_expected) * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Sessions | ACES Student</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .hero-card { background: linear-gradient(135deg, #e6f5ed 0%, #c8ecd9 100%); border-radius: 14px; padding: 24px; border: 1px solid rgba(10,110,45,0.1); margin-bottom: 24px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-weekday { text-align: center; font-weight: 700; padding: 8px 4px; background: #f3f4f6; color: #6b7280; font-size: 0.7rem; text-transform: uppercase; border-radius: 6px; }
        .calendar-day { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 8px; cursor: pointer; font-size: 0.8rem; font-weight: 500; color: #6b7280; transition: all 0.15s; position: relative; }
        .calendar-day:hover:not(.empty) { background: #f3f4f6; }
        .calendar-day.empty { cursor: default; color: transparent; }
        .calendar-day.has-event { background: #dcfce7; color: #0a6e2d; font-weight: 700; }
        .calendar-day.has-event::after { content: ''; position: absolute; bottom: 4px; width: 4px; height: 4px; border-radius: 50%; background: #0a6e2d; }
        .calendar-day.today { background: #0a6e2d; color: #fff; }
        .modal-content { border-radius: 14px; border: none; overflow: hidden; }
        .clickable-card { transition: transform 0.2s, box-shadow 0.2s; }
        .clickable-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
<?php include '../includes/student_sidebar.php'; ?>
<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/student_header.php'; ?>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">
        
        <!-- HERO -->
        <div class="hero-card">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div class="flex-1">
                    <h1 class="text-2xl md:text-3xl font-extrabold text-[#0a6e2d] mb-2"><i class="fas fa-hand-pointer mr-2"></i>Select Your Subtopics</h1>
                    <p class="text-gray-700 text-sm md:text-base">Click a session card below to view available subtopics.</p>
                    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
                        <span class="inline-flex items-center gap-1 bg-green-100 text-green-700 px-3 py-1 rounded-full font-semibold"><i class="fas fa-calendar-check"></i> <?= $attended_subtopics ?>/<?= $total_subtopics_expected ?> Attendance</span>
                        <span class="inline-flex items-center gap-1 bg-purple-100 text-purple-700 px-3 py-1 rounded-full font-semibold"><i class="fas fa-book"></i> <?= $total_modules_done ?>/<?= $total_modules_expected ?> Modules</span>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="relative w-20 h-20 flex items-center justify-center">
                        <svg class="w-full h-full" viewBox="0 0 36 36">
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#ffffff" stroke-width="3" opacity="0.6"></circle>
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#0a6e2d" stroke-width="3" stroke-dasharray="<?= $progress_pct ?> 100" stroke-dashoffset="0" stroke-linecap="round"></circle>
                        </svg>
                        <div class="absolute text-base font-extrabold text-[#0a6e2d]"><?= $progress_pct ?>%</div>
                    </div>
                    <div class="text-sm">
                        <div class="font-bold text-gray-800">Overall Progress</div>
                        <div class="text-gray-600"><?= $total_items_completed ?> of <?= $total_items_expected ?> requirements</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CURRENT MODULE & CALENDAR -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
            <!-- Current Module -->
            <?php if ($current_module): ?>
                <a href="modules.php?module_id=<?= $current_module['module_id'] ?>" class="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 hover:border-green-300 hover:shadow-md transition overflow-hidden block" style="text-decoration:none; color:inherit;">
            <?php else: ?>
                <div class="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <?php endif; ?>
                <div class="bg-[#054018] px-5 py-3 flex items-center justify-between">
                    <span class="text-white font-bold text-sm"><i class="fas fa-book-open mr-2"></i>Current Module</span>
                    <?php if ($current_module): ?><span class="text-white/70 text-xs">Click to open →</span><?php endif; ?>
                </div>
                <div class="p-5">
                    <?php if ($current_module): ?>
                        <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($current_module['title']) ?></h4>
                        <p class="text-gray-600 text-sm mt-2 leading-relaxed"><?= nl2br(htmlspecialchars(substr($current_module['description'] ?? '', 0, 200))) ?></p>
                        <div class="flex items-center gap-2 mt-3 text-xs text-gray-500"><i class="fas fa-calendar-alt"></i><span>Due: <?= date('M d, Y', strtotime($current_module['due_date'])) ?></span></div>
                    <?php else: ?>
                        <div class="text-center py-4"><i class="fas fa-check-circle text-4xl text-green-400 mb-2"></i><p class="text-gray-500 text-sm">No pending modules. You're all caught up!</p></div>
                    <?php endif; ?>
                </div>
            <?php if ($current_module): ?></a><?php else: ?></div><?php endif; ?>

            <!-- Calendar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-[#054018] px-5 py-3 flex items-center justify-between">
                    <span class="text-white font-bold text-sm"><i class="fas fa-calendar-alt mr-2"></i><?= date('F Y') ?></span>
                    <div class="flex gap-1">
                        <button id="prevMonthBtn" class="text-white/70 hover:text-white p-1"><i class="fas fa-chevron-left text-xs"></i></button>
                        <button id="nextMonthBtn" class="text-white/70 hover:text-white p-1"><i class="fas fa-chevron-right text-xs"></i></button>
                    </div>
                </div>
                <div class="p-4" id="miniCalendar"></div>
                <div class="px-4 pb-4 text-xs text-gray-500 text-center"><i class="fas fa-info-circle mr-1"></i>Click a highlighted date to find the session</div>
            </div>
        </div>

        <!-- SESSION CARDS GRID -->
        <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-layer-group text-[#0a6e2d] mr-2"></i>Upcoming Sessions</h2>
        <?php if (count($sessions) == 0): ?>
            <div class="bg-white rounded-xl shadow-sm p-12 text-center"><i class="fas fa-inbox text-5xl text-gray-300 mb-4"></i><h3 class="text-lg font-semibold text-gray-700">No Upcoming Sessions</h3></div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-10" id="sessionGrid">
                <?php foreach ($sessions as $session): 
                    $session_date = strtotime($session['date']);
                    $visible_subs = $session['subtopics'];
                    $reg_count = count($session['registered_subtopics']);
                    $total_subs = count($visible_subs);
                    if ($total_subs === 0) continue;
                ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden cursor-pointer clickable-card flex flex-col h-full group session-card"
                         data-session-date="<?= $session['date'] ?>"
                         onclick='openSessionModal(<?= htmlspecialchars(json_encode([
                             'id' => $session['session_id'], 'title' => $session['title'], 'phase' => $session['phase'],
                             'date' => date('M d, Y', $session_date), 'time' => date('g:i A', strtotime($session['start_time'])) . ' - ' . date('g:i A', strtotime($session['end_time'])),
                             'location' => $session['location'], 'description' => $session['description'], 'image' => $session['image_path'],
                             'reg_count' => $reg_count, 'total_subs' => $total_subs, 'allow_multiple' => (int)($session['allow_multiple'] ?? 0),
                             'existing_name' => !empty($session['registered_subtopics_full']) ? $session['registered_subtopics_full'][0]['subtopic_title'] : '',
                             'registered_ids' => $session['registered_subtopics'],
                             'subtopics' => $visible_subs
                         ]), ENT_QUOTES, 'UTF-8') ?>)'>
                        
                        <div class="h-40 bg-gray-100 relative overflow-hidden flex items-center justify-center">
                            <?php if (!empty($session['image_path'])): ?>
                                <img src="../<?= htmlspecialchars($session['image_path']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                <div class="absolute inset-0 bg-black/20 group-hover:bg-black/10 transition-colors"></div>
                            <?php else: ?>
                                <div class="absolute inset-0 bg-gradient-to-br from-[#054018] to-[#0a6e2d] opacity-90"></div>
                                <i class="fas fa-calendar-alt text-5xl text-white/30 relative z-10"></i>
                            <?php endif; ?>
                            <div class="absolute top-3 left-3 z-10"><span class="text-[10px] font-bold uppercase px-2.5 py-1 rounded-md bg-white/90 text-[#0a6e2d] shadow-sm"><?= htmlspecialchars($session['phase']) ?></span></div>
                        </div>

                        <div class="p-5 flex-1 flex flex-col">
                            <h3 class="font-bold text-gray-900 leading-tight group-hover:text-[#0a6e2d] transition-colors line-clamp-2 mb-2"><?= htmlspecialchars($session['title']) ?></h3>
                            <div class="text-xs text-gray-500 mb-4 space-y-1">
                                <div><i class="far fa-calendar-alt mr-2"></i><?= date('M d, Y', $session_date) ?></div>
                                <div><i class="far fa-clock mr-2"></i><?= date('g:i A', strtotime($session['start_time'])) ?></div>
                            </div>
                            <div class="mt-auto flex justify-between items-center pt-4 border-t border-gray-100">
                                <div class="text-xs font-semibold text-gray-500"><i class="fas fa-list-ul mr-1"></i> <?= $total_subs ?> Subtopics</div>
                                <span class="text-sm font-bold <?= $reg_count > 0 ? 'text-green-600' : 'text-blue-600' ?>"><?= $reg_count ?>/<?= $total_subs ?> Registered</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- PAST SESSIONS HISTORY -->
        <div class="mt-8 mb-8">
            <h3 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-history text-[#0a6e2d] mr-2"></i>Past Sessions History</h3>
            <div class="flex border-b border-gray-200 mb-4 space-x-4">
                <button class="history-tab-sess font-bold text-sm pb-2 border-b-2 border-[#0a6e2d] text-[#0a6e2d]" data-target="sess-tab-missed"><i class="fas fa-times-circle text-red-500 mr-1"></i> Missed (<?= count($past_missed) ?>)</button>
                <button class="history-tab-sess font-semibold text-sm pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-800" data-target="sess-tab-not-reg"><i class="fas fa-minus-circle text-gray-400 mr-1"></i> Not Registered (<?= count($past_not_reg) ?>)</button>
                <button class="history-tab-sess font-semibold text-sm pb-2 border-b-2 border-transparent text-gray-500 hover:text-gray-800" data-target="sess-tab-completed"><i class="fas fa-check-circle text-green-500 mr-1"></i> Completed (<?= count($past_completed) ?>)</button>
            </div>

            <div id="sess-tab-missed" class="history-content-sess block bg-white rounded-xl shadow-sm border border-red-200 overflow-hidden">
                <?php if (empty($past_missed)): ?><div class="p-6 text-center text-gray-400">No missed subtopics. Great job!</div>
                <?php else: ?><ul class="divide-y divide-gray-100">
                    <?php foreach ($past_missed as $sub): ?>
                        <li class="p-4 flex justify-between items-center hover:bg-red-50 transition">
                            <div><p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sub['subtopic_title']) ?></p><p class="text-xs text-gray-500"><?= htmlspecialchars($sub['session_title']) ?> · <?= date('M d, Y', strtotime($sub['actual_date'])) ?></p></div>
                            <span class="bg-red-100 text-red-700 text-xs font-bold px-2.5 py-1 rounded-md border border-red-200">Missed / Absent</span>
                        </li>
                    <?php endforeach; ?></ul><?php endif; ?>
            </div>

            <div id="sess-tab-not-reg" class="history-content-sess hidden bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <?php if (empty($past_not_reg)): ?><div class="p-6 text-center text-gray-400">You registered for all past available subtopics.</div>
                <?php else: ?><ul class="divide-y divide-gray-100">
                    <?php foreach ($past_not_reg as $sub): ?>
                        <li class="p-4 flex justify-between items-center hover:bg-gray-50 transition">
                            <div><p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sub['subtopic_title']) ?></p><p class="text-xs text-gray-500"><?= htmlspecialchars($sub['session_title']) ?> · <?= date('M d, Y', strtotime($sub['actual_date'])) ?></p></div>
                            <span class="bg-gray-100 text-gray-600 text-xs font-bold px-2.5 py-1 rounded-md border border-gray-300">Unregistered</span>
                        </li>
                    <?php endforeach; ?></ul><?php endif; ?>
            </div>

            <div id="sess-tab-completed" class="history-content-sess hidden bg-white rounded-xl shadow-sm border border-green-200 overflow-hidden">
                <?php if (empty($past_completed)): ?><div class="p-6 text-center text-gray-400">No completed past subtopics yet.</div>
                <?php else: ?><ul class="divide-y divide-gray-100">
                    <?php foreach ($past_completed as $sub): ?>
                        <li class="p-4 flex justify-between items-center hover:bg-green-50 transition">
                            <div><p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($sub['subtopic_title']) ?></p><p class="text-xs text-gray-500"><?= htmlspecialchars($sub['session_title']) ?> · <?= date('M d, Y', strtotime($sub['actual_date'])) ?></p></div>
                            <span class="bg-green-100 text-green-700 text-xs font-bold px-2.5 py-1 rounded-md border border-green-200">Present</span>
                        </li>
                    <?php endforeach; ?></ul><?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- SESSION MODAL -->
<div class="modal fade" id="sessionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl rounded-2xl overflow-hidden">
            <div class="bg-[#054018] text-white p-6 relative">
                <button type="button" class="btn-close btn-close-white absolute top-4 right-4 z-10" data-bs-dismiss="modal"></button>
                <div class="flex items-center gap-4">
                    <div class="bg-white/20 p-3 rounded-xl hidden sm:block"><i class="fas fa-calendar-check text-3xl"></i></div>
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-green-300 mb-1 block" id="modalPhase"></span>
                        <h2 class="text-2xl font-bold leading-tight" id="modalTitle"></h2>
                        <div class="flex items-center gap-4 text-sm text-white/80 mt-2" id="modalMeta"></div>
                    </div>
                </div>
            </div>
            <div class="modal-body p-6 bg-gray-50" id="modalBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]);
}
const eventDates = <?= json_encode(array_values($all_dates)) ?>;
let currentYear = new Date().getFullYear();
let currentMonth = new Date().getMonth();

function generateCalendar(year, month) {
    const firstDay = new Date(year, month, 1); const startWeekday = firstDay.getDay(); const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date(); const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
    let html = '<div class="calendar-grid">'; ['S','M','T','W','T','F','S'].forEach(d => html += `<div class="calendar-weekday">${d}</div>`);
    for (let i = 0; i < startWeekday; i++) html += '<div class="calendar-day empty"></div>';
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        let cls = 'calendar-day'; if (eventDates.includes(dateStr)) cls += ' has-event'; if (dateStr === todayStr) cls += ' today';
        html += `<div class="${cls}" data-date="${dateStr}">${d}</div>`;
    }
    return html + '</div>';
}

function renderCalendar() {
    const cal = document.getElementById('miniCalendar'); if (!cal) return; cal.innerHTML = generateCalendar(currentYear, currentMonth);
    document.getElementById('prevMonthBtn')?.addEventListener('click', () => { currentMonth--; if (currentMonth < 0) { currentMonth = 11; currentYear--; } renderCalendar(); });
    document.getElementById('nextMonthBtn')?.addEventListener('click', () => { currentMonth++; if (currentMonth > 11) { currentMonth = 0; currentYear++; } renderCalendar(); });
    document.querySelectorAll('.calendar-day.has-event').forEach(day => {
        day.addEventListener('click', () => {
            const date = day.getAttribute('data-date'); if (!date) return;
            document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('selected')); day.classList.add('selected');
            filterByDate(date);
        });
    });
}

function filterByDate(date) {
    // Find the first session card for this date and trigger its click event to open the modal
    const firstCard = document.querySelector(`.session-card[data-session-date="${date}"]`);
    if (firstCard) {
        firstCard.click();
    }
}

function openSessionModal(data) {
    document.getElementById('modalTitle').textContent = data.title;
    document.getElementById('modalPhase').textContent = data.phase;
    document.getElementById('modalMeta').innerHTML = `<span><i class="far fa-calendar-alt mr-1"></i>${data.date}</span> <span><i class="far fa-clock mr-1"></i>${data.time}</span> <span><i class="fas fa-map-marker-alt mr-1"></i>${data.location || 'TBA'}</span>`;
    
    let html = '';
    if (data.description) html += `<p class="text-gray-600 text-sm mb-6">${escapeHtml(data.description)}</p>`;
    html += `<div class="grid grid-cols-1 md:grid-cols-2 gap-4">`;
    
    data.subtopics.forEach(sub => {
        // Convert the array to strings so the comparison works perfectly
        const reg_ids = (data.registered_ids || []).map(String);
        const is_registered = reg_ids.includes(String(sub.subtopic_id));
        // Use section-specific capacity when available
        const has_section_cap = (sub.section_capacity !== null && sub.section_capacity > 0);
        const display_cap = has_section_cap ? sub.section_capacity : sub.capacity;
        const display_count = has_section_cap ? sub.section_attendee_count : sub.attendee_count;
        const percent = display_cap > 0 ? Math.round((display_count / display_cap) * 100) : 0;
        const is_full = display_cap > 0 && display_count >= display_cap;
        
        let statusClass = 'bg-blue-100 text-blue-700 border-blue-200'; let statusText = 'Available';
        if (sub.required) { statusClass = 'bg-purple-100 text-purple-700 border-purple-200'; statusText = 'Required'; }
        if (is_registered) { statusClass = 'bg-green-100 text-green-700 border-green-200'; statusText = 'Registered'; }
        if (is_full && !is_registered) { statusClass = 'bg-red-100 text-red-700 border-red-200'; statusText = 'Full'; }

        const display_date = sub.subtopic_date ? new Date(sub.subtopic_date).toLocaleDateString() : data.date;
        const display_time = sub.subtopic_start_time ? sub.subtopic_start_time + ' - ' + sub.subtopic_end_time : data.time;

        html += `
        <div class="bg-white rounded-xl border ${is_registered ? 'border-green-500' : 'border-gray-200'} p-5 flex flex-col shadow-sm relative overflow-hidden">
            ${sub.image_path ? `<img src="../${sub.image_path}" class="w-full h-32 object-cover rounded-lg mb-4">` : ''}
            <div class="flex justify-between items-start mb-2">
                <h4 class="font-bold text-gray-800 text-lg">${sub.title}</h4>
                <span class="text-[10px] font-bold uppercase px-2 py-1 rounded-md border ${statusClass}">${statusText}</span>
            </div>
            <div class="flex flex-wrap gap-2 text-xs mb-3">
                <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded"><i class="fas fa-users mr-1"></i> ${display_count}/${display_cap}</span>
                <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded"><i class="fas fa-book mr-1"></i> ${sub.module_count} modules</span>
            </div>
            <div class="text-xs text-gray-500 mb-4 space-y-1">
                <div><i class="far fa-calendar-alt mr-1"></i> ${display_date} · ${display_time}</div>
                ${sub.deadline ? `<div class="text-amber-600 font-semibold"><i class="fas fa-hourglass-half mr-1"></i> Register by: ${new Date(sub.deadline).toLocaleDateString()}</div>` : ''}
                ${sub.subtopic_proctor ? `<div><i class="fas fa-user mr-1"></i> Proctor: ${sub.subtopic_proctor}</div>` : ''}
                ${sub.subtopic_location ? `<div><i class="fas fa-map-marker-alt mr-1"></i> ${sub.subtopic_location}</div>` : ''}
            </div>
            ${sub.description ? `<p class="text-xs text-gray-500 mb-4 flex-1 italic">${sub.description}</p>` : '<div class="flex-1"></div>'}
            
            <div class="mt-auto pt-3 border-t border-gray-100">
                ${is_registered ? `<button disabled class="w-full bg-green-50 text-green-700 py-2.5 rounded-lg font-bold text-sm border border-green-200"><i class="fas fa-check-circle mr-1"></i> Already Registered</button>` 
                : (is_full ? `<button disabled class="w-full bg-red-50 text-red-700 py-2.5 rounded-lg font-bold text-sm border border-red-200"><i class="fas fa-times-circle mr-1"></i> No Slots Available</button>` 
                : `
                <form method="POST" action="choose_subtopic.php" class="register-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="session_id" value="${data.id}">
                    <input type="hidden" name="subtopic_id" value="${sub.subtopic_id}">
                    ${data.reg_count > 0 && data.allow_multiple == 0 ? `<div class="text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-2 mb-2"><i class="fas fa-exchange-alt mr-1"></i> Replaces current pick: "${data.existing_name}"</div>` : ''}
                    <button type="submit" class="w-full ${data.reg_count > 0 && data.allow_multiple == 0 ? 'bg-amber-600 hover:bg-amber-700' : 'bg-[#0a6e2d] hover:bg-[#054018]'} text-white py-2.5 rounded-lg font-bold text-sm transition">
                        ${data.reg_count > 0 && data.allow_multiple == 0 ? '<i class="fas fa-exchange-alt mr-1"></i> Switch to this' : '<i class="fas fa-plus-circle mr-1"></i> Register Now'}
                    </button>
                </form>`)}
            </div>
        </div>`;
    });
    html += `</div>`;
    document.getElementById('modalBody').innerHTML = html;
    document.querySelectorAll('#modalBody .register-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (data.reg_count > 0 && data.allow_multiple == 0) {
                const newTitle = this.closest('.bg-white').querySelector('h4').textContent;
                if (!confirm(`You are switching from "${data.existing_name}" to "${newTitle}". Proceed?`)) {
                    e.preventDefault();
                    return;
                }
                
                // CRITICAL FIX: Inject the replace flag so the backend knows to swap
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'replace';
                hidden.value = '1';
                this.appendChild(hidden);
            }
            
            // Cleanup backdrop on submit
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            
            setTimeout(() => {
                const btn = this.querySelector('button[type="submit"]'); btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Processing...';
            }, 10);
        });
    });
    new bootstrap.Modal(document.getElementById('sessionModal')).show();
}

document.addEventListener('DOMContentLoaded', () => {
    renderCalendar();
    const sessTabs = document.querySelectorAll('.history-tab-sess');
    const sessContents = document.querySelectorAll('.history-content-sess');
    sessTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            sessTabs.forEach(t => { t.classList.remove('border-[#0a6e2d]', 'text-[#0a6e2d]', 'font-bold'); t.classList.add('border-transparent', 'text-gray-500', 'font-semibold'); });
            sessContents.forEach(c => c.classList.add('hidden'));
            this.classList.remove('border-transparent', 'text-gray-500', 'font-semibold'); this.classList.add('border-[#0a6e2d]', 'text-[#0a6e2d]', 'font-bold');
            document.getElementById(this.getAttribute('data-target')).classList.remove('hidden');
        });
    });
});
</script>
</body>
</html>