<?php
/**
 * ============================================================
 * ACES System — Student Sessions & Subtopic Selection (Redesigned)
 * ============================================================
 * Design principles applied:
 *   1. Clear visual hierarchy (date, title, status prominent)
 *   2. Progressive disclosure (collapsed cards → expand for details)
 *   3. Progress tracking (how many sessions registered)
 *   4. Frictionless CTAs (clear "Register" buttons)
 *   5. Responsive event cards (mobile-friendly)
 *   6. Immediate feedback (capacity bars, status badges)
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStudent();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// ---- Student details ----
$stmt = $pdo->prepare("
    SELECT u.full_name, s.section, s.program
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();
$full_name = $student['full_name'] ?? 'Student';
$first_name = explode(' ', $full_name)[0];

// ---- Current module (first incomplete) ----
$stmt_module = $pdo->prepare("
    SELECT m.module_id, m.title, m.description, m.due_date
    FROM modules m
    JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id
    JOIN registrations r ON r.subtopic_id = sub.subtopic_id
    LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = r.student_id
    WHERE r.student_id = ?
      AND m.is_deleted = 0
      AND sub.is_deleted = 0
      AND (sp.completed IS NULL OR sp.completed = 0)
    ORDER BY m.due_date ASC
    LIMIT 1
");
$stmt_module->execute([$_SESSION['student_id']]);
$current_module = $stmt_module->fetch();

// ---- Upcoming sessions ----
$today = date('Y-m-d');
$stmt_sessions = $pdo->prepare("
    SELECT s.*, u.full_name as creator_name
    FROM sessions s
    LEFT JOIN users u ON s.created_by = u.user_id
    WHERE s.date >= ?
    ORDER BY s.date ASC
");
$stmt_sessions->execute([$today]);
$sessions = $stmt_sessions->fetchAll();
$student_id = $_SESSION['student_id'];
$all_dates = [];

foreach ($sessions as &$session) {
    $student_program = $student['program'] ?? '';
    $student_section = $student['section'] ?? '';

    $stmt_sub = $pdo->prepare("
        SELECT sub.*,
               (SELECT COUNT(*) FROM registrations WHERE subtopic_id = sub.subtopic_id) AS attendee_count,
               (SELECT COUNT(*) FROM modules WHERE subtopic_id = sub.subtopic_id AND is_deleted = 0) AS module_count
        FROM subtopics sub
        WHERE sub.session_id = ? AND sub.is_deleted = 0
        ORDER BY sub.title
    ");
    $stmt_sub->execute([$session['session_id']]);
    $all_subtopics = $stmt_sub->fetchAll();

    // Filter visible subtopics
    $visible_subtopics = [];
    foreach ($all_subtopics as $sub) {
        $visible  = false;
        $required = false;

        $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
        $vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
        $req_sections = json_decode($sub['required_for'] ?? '[]', true) ?: [];
        $req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];

        if (empty($vis_sections) && empty($vis_courses) && empty($req_sections) && empty($req_courses)) {
            $visible = true;
        }
        if (in_array($student_section, $req_sections) || in_array($student_program, $req_courses)) {
            $required = true;
            $visible  = true;
        }
        if (in_array($student_section, $vis_sections) || in_array($student_program, $vis_courses)) {
            $visible = true;
        }

        if ($visible) {
            $sub['required'] = $required;
            $visible_subtopics[] = $sub;
        }
    }

    // Auto-register required subtopics
    $stmt_check_reg = $pdo->prepare("
        SELECT r.registration_id FROM registrations r
        JOIN subtopics st ON r.subtopic_id = st.subtopic_id
        WHERE st.session_id = ? AND r.student_id = ?
    ");
    $stmt_check_reg->execute([$session['session_id'], $student_id]);
    $already_registered = (bool) $stmt_check_reg->fetch();

    foreach ($visible_subtopics as &$sub) {
        if (!$sub['required']) continue;
        if (!($session['allow_multiple'] ?? 0) && $already_registered) continue;

        $stmt_already = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND subtopic_id = ?");
        $stmt_already->execute([$student_id, $sub['subtopic_id']]);
        if ($stmt_already->fetch()) continue;

        $stmt_reg = $pdo->prepare("
            INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
            VALUES (?, ?, ?, 'auto_assigned', NOW())
        ");
        $stmt_reg->execute([$student_id, $session['session_id'], $sub['subtopic_id']]);
        $already_registered = true;
    }
    unset($sub);

    $session['subtopics'] = $visible_subtopics;

    if (count($visible_subtopics) > 0) {
        $all_dates[] = $session['date'];
    }

    $stmt_reg = $pdo->prepare("
        SELECT subtopic_id FROM registrations WHERE student_id = ? AND session_id = ?
    ");
    $stmt_reg->execute([$student_id, $session['session_id']]);
    $session['registered_subtopics'] = $stmt_reg->fetchAll(PDO::FETCH_COLUMN);
}
unset($session);

$all_dates = array_unique($all_dates);

// ---- Registration progress (only counts upcoming sessions) ----
$total_sessions = count($sessions);

// Count how many of the UPCOMING sessions this student has registered in
$reg_sessions = 0;
foreach ($sessions as $s) {
    if (!empty($s['registered_subtopics'])) {
        $reg_sessions++;
    }
}

// Cap percentage at 100% to avoid edge cases
$progress_pct = $total_sessions > 0
    ? min(100, round(($reg_sessions / $total_sessions) * 100))
    : 0;
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions | ACES Student</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* ============================================================
           DESIGN SYSTEM — matches staff pages
           ============================================================ */
        :root {
            --primary: #0a6e2d;
            --primary-dark: #054018;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-400: #9ca3af;
            --neutral-600: #6b7280;
            --neutral-800: #1f2937;
        }

        /* ---------- SUBTOPIC CARDS ---------- */
        .subtopic-card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            transition: all 0.2s;
        }
        .subtopic-card:hover { border-color: var(--primary); box-shadow: 0 4px 12px rgba(10,110,45,0.08); }
        .subtopic-card.is-registered { border-color: var(--success); background: #f0fdf4; }
        .subtopic-card.is-full { opacity: 0.75; }

        /* ---------- CAPACITY BAR ---------- */
        .capacity-track { height: 6px; background: var(--neutral-100); border-radius: 9999px; overflow: hidden; }
        .capacity-fill { height: 100%; border-radius: 9999px; transition: width 0.4s ease; }

        /* ---------- STATUS PILLS ---------- */
        .status-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 9999px;
            font-size: 11px; font-weight: 600;
        }
        .status-registered { background: #d1fae5; color: #065f46; }
        .status-available   { background: #dbeafe; color: #1e40af; }
        .status-filling     { background: #fef3c7; color: #92400e; }
        .status-full        { background: #fee2e2; color: #991b1b; }
        .status-required    { background: #ede9fe; color: #5b21b6; }

        /* ---------- SESSION CARDS ---------- */
        .session-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            transition: all 0.2s;
            margin-bottom: 20px;
        }
        .session-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.06); }

        .session-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: #fff;
            padding: 18px 22px;
        }

        /* ---------- DATE BADGE ---------- */
        .date-badge {
            width: 60px; height: 60px;
            border-radius: 12px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .date-badge .month { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.9; }
        .date-badge .day { font-size: 24px; font-weight: 800; line-height: 1; }

        /* ---------- PROGRESS RING ---------- */
        .progress-ring { transition: stroke-dashoffset 0.5s ease-out; }

        /* ---------- CALENDAR ---------- */
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-weekday {
            text-align: center; font-weight: 700; padding: 8px 4px;
            background: var(--neutral-100); color: var(--neutral-600);
            font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em;
            border-radius: 6px;
        }
        .calendar-day {
            aspect-ratio: 1;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px; cursor: pointer; font-size: 0.8rem;
            font-weight: 500; color: var(--neutral-600);
            transition: all 0.15s;
            position: relative;
        }
        .calendar-day:hover:not(.empty) { background: var(--neutral-100); }
        .calendar-day.empty { cursor: default; color: transparent; }
        .calendar-day.has-event {
            background: #dcfce7; color: var(--primary);
            font-weight: 700;
        }
        .calendar-day.has-event::after {
            content: ''; position: absolute; bottom: 4px;
            width: 4px; height: 4px; border-radius: 50%;
            background: var(--primary);
        }
        .calendar-day.today {
            background: var(--primary); color: #fff;
        }
        .calendar-day.selected {
            background: var(--warning); color: #fff;
            box-shadow: 0 0 0 2px rgba(245,158,11,0.3);
        }

        /* ---------- HERO PROGRESS ---------- */
        .hero-card {
            background: linear-gradient(135deg, #e6f5ed 0%, #c8ecd9 100%);
            border-radius: 14px;
            padding: 24px;
            border: 1px solid rgba(10,110,45,0.1);
            margin-bottom: 24px;
        }

        /* ---------- MODAL ---------- */
        .modal-content { border-radius: 14px; border: none; overflow: hidden; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/student_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/student_header.php'; ?>

    <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">

        <!-- ============================================================
             HERO: Welcome + Progress
             ============================================================ -->
        <div class="hero-card">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div class="flex-1">
                    <h1 class="text-2xl md:text-3xl font-extrabold text-[#0a6e2d] mb-2">
                        <i class="fas fa-hand-pointer mr-2"></i>Select Your Subtopics
                    </h1>
                    <p class="text-gray-700 text-sm md:text-base">
                        Choose <strong>one subtopic</strong> for each upcoming session. Required subtopics are auto-assigned.
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
                        <span class="status-pill status-registered">
                            <i class="fas fa-check-circle"></i> <?= $reg_sessions ?> registered
                        </span>
                        <span class="status-pill status-available">
                            <i class="fas fa-calendar-alt"></i> <?= $total_sessions ?> upcoming
                        </span>
                    </div>
                </div>

                <!-- Progress ring -->
                <div class="flex items-center gap-4">
                    <div class="relative w-20 h-20 flex items-center justify-center">
                        <svg class="w-full h-full" viewBox="0 0 36 36">
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#ffffff" stroke-width="3" opacity="0.6"></circle>
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#0a6e2d" stroke-width="3"
                                    stroke-dasharray="<?= $progress_pct ?> 100" stroke-dashoffset="0"
                                    stroke-linecap="round" class="progress-ring"></circle>
                        </svg>
                        <div class="absolute text-base font-extrabold text-[#0a6e2d]"><?= $progress_pct ?>%</div>
                    </div>
                    <div class="text-sm">
                        <div class="font-bold text-gray-800">Your Progress</div>
                        <div class="text-gray-600"><?= $reg_sessions ?> of <?= $total_sessions ?> sessions</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             TWO-COLUMN: Current Module + Calendar
             ============================================================ -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">

            <!-- Current Module -->
            <?php if ($current_module): ?>
                <a href="modules.php?module_id=<?= $current_module['module_id'] ?>"
                   class="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 hover:border-green-300 hover:shadow-md transition overflow-hidden block" style="text-decoration:none; color:inherit;">
            <?php else: ?>
                <div class="md:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <?php endif; ?>

                <div class="bg-[#054018] px-5 py-3 flex items-center justify-between">
                    <span class="text-white font-bold text-sm">
                        <i class="fas fa-book-open mr-2"></i>Current Module
                    </span>
                    <?php if ($current_module): ?>
                        <span class="text-white/70 text-xs">Click to open →</span>
                    <?php endif; ?>
                </div>
                <div class="p-5">
                    <?php if ($current_module): ?>
                        <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($current_module['title']) ?></h4>
                        <p class="text-gray-600 text-sm mt-2 leading-relaxed">
                            <?= nl2br(htmlspecialchars(substr($current_module['description'] ?? '', 0, 200))) ?>
                        </p>
                        <div class="flex items-center gap-2 mt-3 text-xs text-gray-500">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Due: <?= date('M d, Y', strtotime($current_module['due_date'])) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle text-4xl text-green-400 mb-2"></i>
                            <p class="text-gray-500 text-sm">No pending modules. You're all caught up!</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php if ($current_module): ?></a><?php else: ?></div><?php endif; ?>

            <!-- Mini Calendar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-[#054018] px-5 py-3 flex items-center justify-between">
                    <span class="text-white font-bold text-sm">
                        <i class="fas fa-calendar-alt mr-2"></i><?= date('F Y') ?>
                    </span>
                    <div class="flex gap-1">
                        <button id="prevMonthBtn" class="text-white/70 hover:text-white p-1">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </button>
                        <button id="nextMonthBtn" class="text-white/70 hover:text-white p-1">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </button>
                    </div>
                </div>
                <div class="p-4" id="miniCalendar"></div>
                <div class="px-4 pb-4 text-xs text-gray-500 text-center">
                    <i class="fas fa-info-circle mr-1"></i>Click a highlighted date to expand its sessions
                </div>
            </div>
        </div>

        <!-- ============================================================
             SESSIONS LIST
             ============================================================ -->
        <?php if (count($sessions) == 0): ?>
            <div class="bg-white rounded-xl shadow-sm p-12 text-center">
                <i class="fas fa-inbox text-5xl text-gray-300 mb-4"></i>
                <h3 class="text-lg font-semibold text-gray-700">No Upcoming Sessions</h3>
                <p class="text-gray-500 text-sm mt-1">Check back soon for new career development activities.</p>
            </div>
        <?php else: ?>
            <?php foreach ($sessions as $session):
                $session_date = strtotime($session['date']);
                $is_registered_session = !empty($session['registered_subtopics']);
                $visible_subs = $session['subtopics'];
            ?>
                <div class="session-card" data-session-date="<?= $session['date'] ?>">

                    <!-- Session Header -->
                    <div class="session-header">
                        <div class="flex flex-wrap items-center gap-4">
                            <!-- Date badge -->
                            <div class="date-badge">
                                <span class="month"><?= date('M', $session_date) ?></span>
                                <span class="day"><?= date('d', $session_date) ?></span>
                            </div>

                            <!-- Title + meta -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded"
                                          style="background: rgba(255,255,255,0.2);">
                                        <?= htmlspecialchars($session['phase'] ?? 'Session') ?>
                                    </span>
                                    <?php if ($is_registered_session): ?>
                                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-green-400/30">
                                            <i class="fas fa-check mr-1"></i>Registered
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-lg font-bold leading-tight truncate">
                                    <?= htmlspecialchars($session['title']) ?>
                                </h3>
                                <div class="text-xs text-white/80 mt-1 flex flex-wrap items-center gap-3">
                                    <span><i class="far fa-clock mr-1"></i><?= date('g:i A', strtotime($session['start_time'])) ?> – <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                                    <?php if (!empty($session['location'])): ?>
                                        <span><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($session['location']) ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-list-ul mr-1"></i><?= count($visible_subs) ?> subtopic<?= count($visible_subs) !== 1 ? 's' : '' ?></span>
                                </div>
                            </div>

                            <!-- Session-level status -->
                            <div class="text-right">
                                <?php
                                $registered_count = count($session['registered_subtopics']);
                                $total_subs = count($visible_subs);
                                ?>
                                <div class="text-xs uppercase tracking-wider text-white/70">Registered</div>
                                <div class="text-xl font-bold"><?= $registered_count ?>/<?= $total_subs ?></div>
                            </div>
                        </div>

                        <?php if (!empty($session['description'])): ?>
                            <p class="text-white/80 text-xs mt-3 leading-relaxed">
                                <?= htmlspecialchars(substr($session['description'], 0, 180)) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Subtopic Grid -->
                    <div class="p-4 md:p-5">
                        <?php if (count($visible_subs) == 0): ?>
                            <div class="text-center py-6 text-gray-500 text-sm">
                                <i class="fas fa-hourglass-half mr-2"></i>No subtopics available yet.
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($visible_subs as $sub):
                                    $is_registered = in_array($sub['subtopic_id'], $session['registered_subtopics']);
                                    $percent_full = ($sub['capacity'] > 0)
                                        ? round(($sub['attendee_count'] / $sub['capacity']) * 100)
                                        : 0;
                                    $is_full = $percent_full >= 100;

                                    if ($is_registered) {
                                        $status_class = 'status-registered';
                                        $status_label = 'Registered';
                                        $status_icon = 'fa-check-circle';
                                        $bar_color = '#10b981';
                                    } elseif ($sub['required']) {
                                        $status_class = 'status-required';
                                        $status_label = 'Required';
                                        $status_icon = 'fa-star';
                                        $bar_color = '#8b5cf6';
                                    } elseif ($is_full) {
                                        $status_class = 'status-full';
                                        $status_label = 'Full';
                                        $status_icon = 'fa-times-circle';
                                        $bar_color = '#ef4444';
                                    } elseif ($percent_full >= 70) {
                                        $status_class = 'status-filling';
                                        $status_label = 'Filling up';
                                        $status_icon = 'fa-exclamation-circle';
                                        $bar_color = '#f59e0b';
                                    } else {
                                        $status_class = 'status-available';
                                        $status_label = 'Available';
                                        $status_icon = 'fa-check';
                                        $bar_color = '#10b981';
                                    }

                                    $card_class = 'subtopic-card';
                                    if ($is_registered) $card_class .= ' is-registered';
                                    if ($is_full && !$is_registered) $card_class .= ' is-full';
                                ?>
                                    <div class="<?= $card_class ?>"
                                         data-subtopic-id="<?= $sub['subtopic_id'] ?>"
                                         data-session-date="<?= $session['date'] ?>">

                                        <!-- Card header -->
                                        <div class="p-4 subtopic-title cursor-pointer">
                                            <div class="flex items-start justify-between gap-2 mb-2">
                                                <h4 class="font-bold text-sm text-gray-800 leading-snug flex-1 min-w-0">
                                                    <?= htmlspecialchars($sub['title']) ?>
                                                </h4>
                                                <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform subtopic-chevron flex-shrink-0 mt-1"></i>
                                            </div>

                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="status-pill <?= $status_class ?>">
                                                    <i class="fas <?= $status_icon ?>"></i> <?= $status_label ?>
                                                </span>
                                                <?php if (($sub['attendance_type'] ?? 'physical') === 'module'): ?>
                                                    <span class="status-pill" style="background:#dbeafe; color:#1e40af;">
                                                        <i class="fas fa-laptop"></i> Module-based
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Capacity bar -->
                                            <div class="mt-3 flex items-center gap-2">
                                                <div class="capacity-track flex-1">
                                                    <div class="capacity-fill" style="width: <?= min(100, $percent_full) ?>%; background: <?= $bar_color ?>;"></div>
                                                </div>
                                                <span class="text-[11px] font-semibold text-gray-500 whitespace-nowrap">
                                                    <?= $sub['attendee_count'] ?>/<?= $sub['capacity'] ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Expandable details -->
                                        <div class="subtopic-details hidden border-t border-gray-100 bg-gray-50 px-4 py-3 text-xs text-gray-600 space-y-2">
                                            <?php if (($sub['attendance_type'] ?? 'physical') === 'module'): ?>
                                                <div class="flex items-start gap-2">
                                                    <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                                                    <span>Complete all modules to be marked <strong>Present</strong> automatically.</span>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex items-start gap-2">
                                                    <i class="far fa-clock text-gray-400 mt-0.5"></i>
                                                    <span><?= date('M d, Y', $session_date) ?> · <?= date('g:i A', strtotime($session['start_time'])) ?> – <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($session['proctor']) || !empty($session['creator_name'])): ?>
                                                <div class="flex items-start gap-2">
                                                    <i class="fas fa-user text-gray-400 mt-0.5"></i>
                                                    <span>Proctor: <?= htmlspecialchars($session['proctor'] ?? $session['creator_name']) ?></span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($session['location'])): ?>
                                                <div class="flex items-start gap-2">
                                                    <i class="fas fa-map-marker-alt text-gray-400 mt-0.5"></i>
                                                    <span><?= htmlspecialchars($session['location']) ?></span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($sub['module_count'] > 0): ?>
                                                <div class="flex items-start gap-2">
                                                    <i class="fas fa-book text-gray-400 mt-0.5"></i>
                                                    <span><?= $sub['module_count'] ?> module<?= $sub['module_count'] !== 1 ? 's' : '' ?> included</span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($sub['description'])): ?>
                                                <div class="pt-2 border-t border-gray-200 mt-2 text-gray-500 italic">
                                                    <?= nl2br(htmlspecialchars($sub['description'])) ?>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Action button -->
                                            <div class="pt-3">
                                                <?php if ($is_registered): ?>
                                                    <button disabled class="w-full bg-green-100 text-green-700 py-2 rounded-lg text-xs font-semibold cursor-default">
                                                        <i class="fas fa-check-circle mr-1"></i>Already Registered
                                                    </button>
                                                <?php elseif ($is_full): ?>
                                                    <button disabled class="w-full bg-red-100 text-red-700 py-2 rounded-lg text-xs font-semibold cursor-not-allowed">
                                                        <i class="fas fa-times-circle mr-1"></i>No Slots Available
                                                    </button>
                                                <?php else: ?>
                                                    <form method="POST" action="choose_subtopic.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="session_id" value="<?= $session['session_id'] ?>">
                                                        <input type="hidden" name="subtopic_id" value="<?= $sub['subtopic_id'] ?>">
                                                        <button type="submit" class="w-full bg-[#0a6e2d] hover:bg-[#054018] text-white py-2 rounded-lg text-xs font-semibold transition">
                                                            <i class="fas fa-plus-circle mr-1"></i>Register for this Subtopic
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const eventDates = <?= json_encode(array_values($all_dates)) ?>;
let currentYear = new Date().getFullYear();
let currentMonth = new Date().getMonth();

function generateCalendar(year, month) {
    const firstDay = new Date(year, month, 1);
    const startWeekday = firstDay.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;

    let html = '<div class="calendar-grid">';
    ['S','M','T','W','T','F','S'].forEach(d => html += `<div class="calendar-weekday">${d}</div>`);

    for (let i = 0; i < startWeekday; i++) html += '<div class="calendar-day empty"></div>';

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const hasEvent = eventDates.includes(dateStr);
        const isToday = dateStr === todayStr;
        let cls = 'calendar-day';
        if (hasEvent) cls += ' has-event';
        if (isToday) cls += ' today';
        html += `<div class="${cls}" data-date="${dateStr}">${d}</div>`;
    }
    html += '</div>';
    return html;
}

function renderCalendar() {
    const cal = document.getElementById('miniCalendar');
    if (!cal) return;
    cal.innerHTML = generateCalendar(currentYear, currentMonth);

    document.getElementById('prevMonthBtn')?.addEventListener('click', () => {
        currentMonth--;
        if (currentMonth < 0) { currentMonth = 11; currentYear--; }
        renderCalendar();
    });
    document.getElementById('nextMonthBtn')?.addEventListener('click', () => {
        currentMonth++;
        if (currentMonth > 11) { currentMonth = 0; currentYear++; }
        renderCalendar();
    });

    document.querySelectorAll('.calendar-day.has-event').forEach(day => {
        day.addEventListener('click', () => {
            const date = day.getAttribute('data-date');
            if (!date) return;
            document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('selected'));
            day.classList.add('selected');
            filterByDate(date);
        });
    });
}

function filterByDate(date) {
    // Collapse all subtopics first
    document.querySelectorAll('.subtopic-details').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.subtopic-chevron').forEach(el => el.style.transform = '');

    // Expand matching session
    document.querySelectorAll(`.subtopic-card[data-session-date="${date}"]`).forEach(card => {
        const details = card.querySelector('.subtopic-details');
        const chevron = card.querySelector('.subtopic-chevron');
        if (details) details.classList.remove('hidden');
        if (chevron) chevron.style.transform = 'rotate(180deg)';
    });

    // Scroll to first matching session
    const firstCard = document.querySelector(`.subtopic-card[data-session-date="${date}"]`);
    if (firstCard) {
        firstCard.closest('.session-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderCalendar();

    // Toggle subtopic details on click
    document.querySelectorAll('.subtopic-title').forEach(title => {
        title.addEventListener('click', function(e) {
            e.stopPropagation();
            const card = this.closest('.subtopic-card');
            const details = card.querySelector('.subtopic-details');
            const chevron = card.querySelector('.subtopic-chevron');
            const hidden = details.classList.contains('hidden');
            if (hidden) {
                details.classList.remove('hidden');
                chevron.style.transform = 'rotate(180deg)';
            } else {
                details.classList.add('hidden');
                chevron.style.transform = '';
            }
        });
    });
});
</script>
</body>
</html>