<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

// Student details
$stmt = $pdo->prepare("
    SELECT u.full_name, s.section, s.program
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

$full_name = $student['full_name'];
$name_parts = explode(' ', $full_name);
$first_name = $name_parts[0];

if (!isset($_SESSION['student_id'])) {
    $stmt_id = $pdo->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $stmt_id->execute([$_SESSION['user_id']]);
    $row = $stmt_id->fetch();
    $_SESSION['student_id'] = $row['student_id'];
}

// Current module
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

// Upcoming sessions
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

    // Fetch ALL subtopics for the session
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

    // Filter subtopics visible/required for this student
    $visible_subtopics = [];
    foreach ($all_subtopics as $sub) {
        $visible  = false;
        $required = false;

        $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
        $vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
        $req_sections = json_decode($sub['required_for'] ?? '[]', true) ?: [];
        $req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];

        // Public
        if (empty($vis_sections) && empty($vis_courses) && empty($req_sections) && empty($req_courses)) {
            $visible = true;
        }

        // Required → always visible
        if (in_array($student_section, $req_sections) || in_array($student_program, $req_courses)) {
            $required = true;
            $visible  = true;
        }

        // Explicitly visible
        if (in_array($student_section, $vis_sections) || in_array($student_program, $vis_courses)) {
            $visible = true;
        }

        if ($visible) {
            $sub['required'] = $required;
            $visible_subtopics[] = $sub;
        }
    }

    // Auto‑register required subtopics (respects allow_multiple)
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

    // Assign visible subtopics to the session
    $session['subtopics'] = $visible_subtopics;

    if (count($visible_subtopics) > 0) {
        $all_dates[] = $session['date'];
    }

    // Fetch registered subtopics for the student
    $stmt_reg = $pdo->prepare("
        SELECT subtopic_id FROM registrations WHERE student_id = ? AND session_id = ?
    ");
    $stmt_reg->execute([$student_id, $session['session_id']]);
    $session['registered_subtopics'] = $stmt_reg->fetchAll(PDO::FETCH_COLUMN);
}
unset($session); // break reference

$all_dates = array_unique($all_dates);

// How many sessions has the student registered in?
$registered_sessions_count = $pdo->prepare("SELECT COUNT(DISTINCT session_id) FROM registrations WHERE student_id = ?");
$registered_sessions_count->execute([$_SESSION['student_id']]);
$reg_sessions = $registered_sessions_count->fetchColumn();
$total_sessions = count($sessions);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions | ACES Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-weekday { text-align: center; font-weight: 600; padding: 6px; background: #f0fdf4; color: #065f46; font-size: 0.8rem; }
        .calendar-day { min-height: 50px; border: 1px solid #e5e7eb; padding: 4px; position: relative; cursor: pointer; font-size: 0.8rem; }
        .calendar-day.empty { background: #f9fafb; cursor: default; }
        .calendar-day.has-event { background: #e9ecef; font-weight: 700; }
        .calendar-day.has-event::after { content: "•"; position: absolute; bottom: 2px; left: 50%; transform: translateX(-50%); color: #0a6e2d; font-size: 1rem; }
        .calendar-day.selected { background: #0a6e2d; color: white; }
        .calendar-day:hover { background: #e2e8f0; }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/student_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <div class="h-14 bg-white shadow flex items-center justify-between px-6 border-b">
        <div class="font-bold text-xl text-[#0a6e2d]">ACES</div>
        <a href="dashboard.php" class="text-gray-600"><i class="fas fa-home fa-lg"></i></a>
    </div>

    <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">

        <!-- ============= OPTION B HERO BANNER ============= -->
        <div class="bg-gradient-to-r from-[#e6f5ed] to-[#c8ecd9] rounded-xl p-6 mb-6 shadow-lg border border-green-100">
            <div class="flex flex-col md:flex-row gap-6 items-center">
                <div class="flex-1 text-center md:text-left">
                    <h1 class="text-3xl md:text-4xl font-extrabold text-[#0a6e2d] mb-2">
                        <i class="fas fa-hand-pointer mr-2"></i>Select Your Subtopics
                    </h1>
                    <p class="text-lg text-gray-700 font-medium">
                        Please choose <span class="underline decoration-[#0a6e2d] decoration-2">one subtopic</span> for each session below.
                    </p>
                    <div class="mt-3 text-sm text-gray-600 flex items-center justify-center md:justify-start gap-2">
                        <i class="fas fa-check-circle text-green-600"></i>
                        <span>Registered for <strong><?= $reg_sessions ?></strong> of <strong><?= $total_sessions ?></strong> sessions</span>
                    </div>
                </div>
                <!-- Compact calendar inline -->
                <div class="w-full md:w-48 bg-white rounded-xl shadow p-4 border border-gray-200">
                    <div class="text-center font-bold text-sm text-[#0a6e2d]"><?= date('F Y') ?></div>
                    <div class="mt-1 text-xs text-gray-500 text-center">Tap a date to expand subtopics</div>
                    <div class="mt-2 text-xs text-center text-gray-400">
                        <i class="fas fa-calendar-alt text-3xl text-green-200"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Module + Full Calendar below -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Clickable Current Module Card -->
            <?php if ($current_module): ?>
            <a href="modules.php?module_id=<?= $current_module['module_id'] ?>" class="md:col-span-2 bg-white rounded shadow-xl overflow-hidden hover:shadow-2xl transition block" style="text-decoration: none; color: inherit;">
            <?php else: ?>
            <div class="md:col-span-2 bg-white rounded shadow-xl overflow-hidden">
            <?php endif; ?>
                <div class="bg-[#054018] px-4 py-3 font-bold text-white text-sm md:text-base">Current Module</div>
                <div class="p-4">
                    <?php if ($current_module): ?>
                        <h4 class="font-semibold text-lg"><?= htmlspecialchars($current_module['title']) ?></h4>
                        <p class="text-gray-600 mt-1"><?= nl2br(htmlspecialchars($current_module['description'] ?? '')) ?></p>
                        <p class="text-sm text-gray-500 mt-2"><i class="fas fa-calendar-alt"></i> Due: <?= date('M d, Y', strtotime($current_module['due_date'])) ?></p>
                        <span class="text-xs text-green-700 font-medium mt-2 inline-block"><i class="fas fa-arrow-right"></i> Open module</span>
                    <?php else: ?>
                        <p class="text-gray-500">No pending modules. You're all caught up!</p>
                    <?php endif; ?>
                </div>
            <?php if ($current_module): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>

            <!-- Full Mini Calendar Card -->
            <div class="bg-white rounded shadow-xl overflow-hidden">
                <div class="bg-[#054018] px-4 py-3 font-bold text-white text-sm md:text-base">Calendar</div>
                <div class="p-4" id="miniCalendar"></div>
            </div>
        </div>

        <!-- Sessions & Subtopics (unchanged, includes capacity warning) -->
        <?php if (count($sessions) == 0): ?>
            <div class="bg-white rounded shadow-xl p-6 text-center text-gray-500">No upcoming sessions.</div>
        <?php else: ?>
            <?php foreach ($sessions as $session): ?>
                <div class="bg-white rounded shadow-xl overflow-hidden mb-6" data-session-date="<?= $session['date'] ?>">
                    <div class="bg-[#054018] px-4 py-3 flex justify-between items-center">
                        <h3 class="text-white font-bold text-lg"><i class="fas fa-calendar-alt mr-2"></i><?= htmlspecialchars($session['title']) ?></h3>
                        <span class="text-white/80 text-sm"><?= date('M d, Y', strtotime($session['date'])) ?> | <?= $session['start_time'] ?> - <?= $session['end_time'] ?></span>
                    </div>
                    <div class="p-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php if (count($session['subtopics']) == 0): ?>
                                <div class="col-span-full text-gray-500">No subtopics available yet.</div>
                            <?php else: ?>
                                <?php foreach ($session['subtopics'] as $sub): ?>
                                    <?php
                                    $percent_full = ($sub['capacity'] > 0) ? round(($sub['attendee_count'] / $sub['capacity']) * 100) : 0;
                                    if ($percent_full >= 100) {
                                        $capacity_color = '#ef4444';
                                        $capacity_label = 'Full';
                                    } elseif ($percent_full >= 70) {
                                        $capacity_color = '#f59e0b';
                                        $capacity_label = 'Filling up';
                                    } else {
                                        $capacity_color = '#10b981';
                                        $capacity_label = 'Available';
                                    }
                                    ?>
                                    <div class="border rounded-lg p-3 subtopic-card cursor-pointer" data-subtopic-id="<?= $sub['subtopic_id'] ?>" data-session-date="<?= $session['date'] ?>">
                                        <div class="subtopic-title flex justify-between items-center font-medium text-sm mb-2">
                                            <?= htmlspecialchars($sub['title']) ?>
                                            <i class="fas fa-chevron-down text-gray-500 text-xs"></i>
                                        </div>
                                        <div class="subtopic-details hidden text-xs text-gray-600 space-y-2">
                                        <?php if (($sub['attendance_type'] ?? 'physical') === 'module'): ?>
                                            <div class="bg-blue-50 border border-blue-200 rounded px-3 py-2 text-blue-700 font-medium">
                                                <i class="fas fa-laptop mr-1"></i> Module‑Based – complete all modules to be marked Present.
                                            </div>
                                        <?php else: ?>
                                            <div><span class="font-semibold">Date &amp; Time:</span> <?= date('M d, Y', strtotime($session['date'])) ?> | <?= $session['start_time'] ?> - <?= $session['end_time'] ?></div>
                                            <div><span class="font-semibold">Proctor:</span> <?= htmlspecialchars($session['proctor'] ?? $session['creator_name'] ?? 'TBA') ?></div>
                                            <div><span class="font-semibold">Location:</span> <?= htmlspecialchars($session['location'] ?? 'TBA') ?></div>
                                        <?php endif; ?>
                                            <div class="flex items-center gap-2">
                                                <span class="font-semibold">Attendees:</span>
                                                <span class="px-2 py-0.5 rounded-full text-white text-xs font-semibold" style="background-color:<?= $capacity_color ?>;"><?= $sub['attendee_count'] ?> / <?= $sub['capacity'] ?></span>
                                                <span class="text-xs font-medium" style="color:<?= $capacity_color ?>;"><?= $capacity_label ?></span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-1.5"><div class="h-1.5 rounded-full" style="width:<?= $percent_full ?>%; background-color:<?= $capacity_color ?>;"></div></div>
                                            <?php if ($sub['module_count'] > 0): ?><div><i class="fas fa-book text-gray-400 mr-1"></i><?= $sub['module_count'] ?> module(s)</div><?php endif; ?>
                                            <?php if (!empty($sub['description'])): ?><div class="text-gray-500 italic mt-1"><?= nl2br(htmlspecialchars($sub['description'])) ?></div><?php endif; ?>
                                            <div class="mt-2">
                                                <?php if (in_array($sub['subtopic_id'], $session['registered_subtopics'])): ?>
                                                    <button class="bg-gray-300 text-gray-800 py-1 px-3 rounded text-xs w-full" disabled>Already Registered</button>
                                                <?php elseif ($percent_full >= 100): ?>
                                                    <button class="bg-red-500 text-white py-1 px-3 rounded text-xs w-full" disabled>Full – No Slots</button>
                                                <?php else: ?>
                                                    <form method="POST" action="choose_subtopic.php">
                                                        <input type="hidden" name="session_id" value="<?= $session['session_id'] ?>">
                                                        <input type="hidden" name="subtopic_id" value="<?= $sub['subtopic_id'] ?>">
                                                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white py-1 px-3 rounded text-xs w-full">Register</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const eventDates = <?= json_encode($all_dates) ?>;
    let currentYear = new Date().getFullYear();
    let currentMonth = new Date().getMonth();

    function generateCalendar(year, month) {
        const firstDay = new Date(year, month, 1);
        const startWeekday = firstDay.getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        let html = `<div class="flex justify-between items-center mb-2">
                        <button class="btn btn-sm btn-outline-secondary" id="prevMonthBtn">&lt;</button>
                        <span class="font-bold">${firstDay.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}</span>
                        <button class="btn btn-sm btn-outline-secondary" id="nextMonthBtn">&gt;</button>
                    </div>`;
        html += `<div class="calendar-grid">`;
        ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => html += `<div class="calendar-weekday">${d}</div>`);
        for (let i = 0; i < startWeekday; i++) html += `<div class="calendar-day empty"></div>`;
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const hasEvent = eventDates.includes(dateStr);
            html += `<div class="calendar-day${hasEvent ? ' has-event' : ''}" data-date="${dateStr}">${d}</div>`;
        }
        html += `</div>`;
        return html;
    }

    function renderCalendar() {
        const cal = document.getElementById('miniCalendar');
        if (!cal) return;
        cal.innerHTML = generateCalendar(currentYear, currentMonth);
        document.getElementById('prevMonthBtn')?.addEventListener('click', () => { currentMonth--; if (currentMonth < 0) { currentMonth = 11; currentYear--; } renderCalendar(); });
        document.getElementById('nextMonthBtn')?.addEventListener('click', () => { currentMonth++; if (currentMonth > 11) { currentMonth = 0; currentYear++; } renderCalendar(); });
        document.querySelectorAll('.calendar-day:not(.empty)').forEach(day => {
            day.addEventListener('click', () => {
                const date = day.getAttribute('data-date');
                if (date) {
                    filterByDate(date);
                    document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('selected'));
                    day.classList.add('selected');
                }
            });
        });
    }

    function filterByDate(date) {
        document.querySelectorAll('.subtopic-card').forEach(card => {
            card.querySelector('.subtopic-details')?.classList.add('hidden');
            card.querySelector('.subtopic-title i')?.classList.replace('fa-chevron-up','fa-chevron-down');
        });
        document.querySelectorAll(`.subtopic-card[data-session-date="${date}"]`).forEach(card => {
            card.querySelector('.subtopic-details')?.classList.remove('hidden');
            card.querySelector('.subtopic-title i')?.classList.replace('fa-chevron-down','fa-chevron-up');
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        renderCalendar();
        document.querySelectorAll('.subtopic-title').forEach(title => {
            title.addEventListener('click', function(e) {
                e.stopPropagation();
                const card = this.closest('.subtopic-card');
                const details = card.querySelector('.subtopic-details');
                const icon = this.querySelector('i');
                const hidden = details.classList.contains('hidden');
                if (hidden) {
                    details.classList.remove('hidden');
                    icon.classList.replace('fa-chevron-down','fa-chevron-up');
                } else {
                    details.classList.add('hidden');
                    icon.classList.replace('fa-chevron-up','fa-chevron-down');
                }
            });
        });
    });
</script>
</body>
</html>