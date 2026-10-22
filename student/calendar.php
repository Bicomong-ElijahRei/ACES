<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

// ---- Calendar Logic ----
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

// Fetch sessions (Using COALESCE for effective_date so icons appear on the correct day)
$stmt_sessions = $pdo->prepare("
    SELECT DISTINCT s.session_id, s.title, s.date, s.start_time, s.end_time, s.proctor, s.location,
           COALESCE(sub.subtopic_date, s.date) AS effective_date
    FROM sessions s
    JOIN registrations r ON s.session_id = r.session_id
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    WHERE r.student_id = ? AND s.is_deleted = 0 AND COALESCE(sub.subtopic_date, s.date) BETWEEN ? AND ?
      AND r.status IN ('assigned','auto_assigned')
    ORDER BY effective_date ASC
");
$stmt_sessions->execute([$_SESSION['student_id'], $first_day_of_month, $last_day_of_month]);
$sessions_by_date = [];
while ($row = $stmt_sessions->fetch()) {
    $day = date('j', strtotime($row['effective_date']));
    $sessions_by_date[$day][] = $row;
}

// Fetch holidays
$stmt_holidays = $pdo->prepare("SELECT date, name FROM holidays WHERE date BETWEEN ? AND ?");
$stmt_holidays->execute([$first_day_of_month, $last_day_of_month]);
$holidays = [];
while ($row = $stmt_holidays->fetch()) {
    $day = date('j', strtotime($row['date']));
    $holidays[$day][] = $row['name'];
}

// Fetch school events
$stmt_events = $pdo->prepare("SELECT date, name, description FROM school_events WHERE date BETWEEN ? AND ?");
$stmt_events->execute([$first_day_of_month, $last_day_of_month]);
$school_events = [];
while ($row = $stmt_events->fetch()) {
    $day = date('j', strtotime($row['date']));
    $school_events[$day][] = $row;
}

// Fetch modules due
$stmt_modules_due = $pdo->prepare("
    SELECT m.due_date, m.title, m.module_id
    FROM modules m
    JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
    JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0
    JOIN registrations r ON r.subtopic_id = sub.subtopic_id AND r.student_id = ? AND r.status IN ('assigned','auto_assigned')
    WHERE m.is_deleted = 0 AND m.due_date BETWEEN ? AND ?
    ORDER BY m.due_date
");
$stmt_modules_due->execute([$_SESSION['student_id'], $first_day_of_month, $last_day_of_month]);
$modules_by_date = [];
while ($row = $stmt_modules_due->fetch()) {
    if (empty($row['due_date'])) continue;
    $day = date('j', strtotime($row['due_date']));
    $modules_by_date[$day][] = $row; 
}

// Fetch subtopics for the modal
$stmt_registered_subtopics = $pdo->prepare("
    SELECT sub.subtopic_id, sub.title, sub.description, sub.subtopic_date,
           sub.subtopic_start_time, sub.subtopic_end_time,
           sub.subtopic_proctor, sub.subtopic_location, sub.attendance_type,
           s.session_id, s.title AS session_title, s.date, s.start_time, s.end_time
    FROM subtopics sub
    JOIN sessions s ON sub.session_id = s.session_id AND s.is_deleted = 0
    JOIN registrations r ON r.subtopic_id = sub.subtopic_id AND r.student_id = ? AND r.status IN ('assigned','auto_assigned')
    WHERE sub.is_deleted = 0 AND COALESCE(sub.subtopic_date, s.date) BETWEEN ? AND ?
    ORDER BY s.date, sub.title
");
$stmt_registered_subtopics->execute([$_SESSION['student_id'], $first_day_of_month, $last_day_of_month]);
$my_subtopics_by_date = [];
while ($row = $stmt_registered_subtopics->fetch()) {
    $effective = !empty($row['subtopic_date']) ? $row['subtopic_date'] : $row['date'];
    if (empty($effective)) continue;
    $day = date('j', strtotime($effective));
    $my_subtopics_by_date[$day][] = $row;
}

// Helper function to build the calendar HTML
function generateCalendar($month, $year, $sessions_by_date, $holidays, $school_events, $modules_by_date) {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $firstDay = date('N', strtotime("$year-$month-01"));
    $calendar = '<div class="calendar-grid">';
    $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    foreach ($weekdays as $wd) { $calendar .= "<div class='calendar-weekday'>$wd</div>"; }
    for ($i = 1; $i < $firstDay; $i++) { $calendar .= '<div class="calendar-day empty"></div>'; }
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $day);
        $hasSession = isset($sessions_by_date[$day]);
        $hasHoliday = isset($holidays[$day]);
        $hasSchool  = isset($school_events[$day]);
        $hasModule  = isset($modules_by_date[$day]);

        $eventClass = '';
        if ($hasSession) $eventClass .= ' has-session';
        if ($hasHoliday) $eventClass .= ' has-holiday';
        if ($hasSchool)  $eventClass .= ' has-school-event';
        if ($hasModule)  $eventClass .= ' has-module';

        $calendar .= "<div class='calendar-day$eventClass' data-date='$dateStr'>";
        $calendar .= "<div class='day-number'>$day</div>";
        $calendar .= "<div class='event-icons'>";
        if ($hasSession) $calendar .= '<i class="fas fa-calendar-alt event-icon text-green-500"></i>';
        if ($hasHoliday) $calendar .= '<i class="fas fa-star event-icon text-red-500"></i>';
        if ($hasSchool)  $calendar .= '<i class="fas fa-school event-icon text-amber-500"></i>';
        if ($hasModule)  $calendar .= '<i class="fas fa-file-alt event-icon text-blue-500"></i>';
        $calendar .= "</div></div>";
    }
    $calendar .= '</div>';
    return $calendar;
}

$calendarHtml = generateCalendar($month, $year, $sessions_by_date, $holidays, $school_events, $modules_by_date);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>My Calendar | ACES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-weekday { text-align: center; font-weight: 700; padding: 12px 0; background: #f0fdf4; color: #065f46; border-radius: 6px; }
        
        /* Merged Calendar Cell CSS */
        .calendar-day { 
            min-height: 120px; 
            border: 1px solid #e2e8f0; 
            padding: 8px; 
            position: relative; 
            cursor: pointer; 
            transition: all 0.2s; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; /* Pushes icons to bottom */
            overflow: hidden; /* Prevents jumbling */
            border-radius: 6px;
        }
        .calendar-day:hover { background: #f8fafc; }
        .calendar-day.empty { background: #f8fafc; cursor: default; border: none; }
        .calendar-day.has-session { border-top: 4px solid #10b981; }
        .calendar-day.has-holiday { border-top: 4px solid #ef4444; }
        .calendar-day.has-school-event { border-top: 4px solid #f59e0b; }
        .calendar-day.has-module { border-top: 4px solid #3b82f6; }
        
        .day-number { font-weight: 700; font-size: 1.1rem; color: #374151; }
        .event-icons { display: flex; flex-wrap: wrap; gap: 4px; margin-top: auto; align-items: flex-end; }
        .event-icon { font-size: 1rem; }
        
        /* Modal Z-Index Fix */
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
    </style>
</head>
<body class="h-full bg-[#dcf3e6] font-sans overflow-hidden">
<div class="h-screen flex flex-col md:flex-row overflow-hidden">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/student_header.php'; ?>
        <main class="flex-1 p-6 overflow-y-auto">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden w-full">
                <div class="bg-[#054018] px-5 py-4 font-bold text-white flex justify-between items-center">
                    <span class="text-lg"><i class="fas fa-calendar-alt mr-2"></i>My Calendar</span>
                    <div class="text-xs flex gap-4">
                        <span><i class="fas fa-circle text-green-400"></i> Sessions</span>
                        <span><i class="fas fa-circle text-blue-400"></i> Modules</span>
                        <span><i class="fas fa-circle text-red-400"></i> Holidays</span>
                        <span><i class="fas fa-circle text-amber-400"></i> Events</span>
                    </div>
                </div>
                <div class="p-6">
                    <div class="flex justify-between items-center mb-6">
                        <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="text-sm px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg font-bold text-gray-700 transition">&lt; Prev</a>
                        <strong class="text-2xl text-gray-800"><?= date('F Y', strtotime("$year-$month-01")) ?></strong>
                        <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>" class="text-sm px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg font-bold text-gray-700 transition">Next &gt;</a>
                    </div>
                    <?= $calendarHtml ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Daily Events Modal -->
<div class="modal fade" id="dailyEventsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-xl border-0 shadow-xl overflow-hidden">
            <div class="modal-header bg-[#054018] text-white border-0 py-3">
                <h5 class="modal-title font-bold"><i class="fas fa-calendar-day mr-2"></i>Events for <span id="modalDate"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-gray-50" id="modalEventsList"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const mySubtopics = <?= json_encode($my_subtopics_by_date, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const holidays = <?= json_encode($holidays, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const schoolEvents = <?= json_encode($school_events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const modulesByDate = <?= json_encode($modules_by_date, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

    if (mySubtopics[dayOfMonth] && Array.isArray(mySubtopics[dayOfMonth])) {
        html += '<h6 class="fw-bold text-sm mb-3 text-[#0a6e2d] uppercase tracking-wider"><i class="fas fa-calendar-check mr-1"></i>My Sessions</h6>';
        mySubtopics[dayOfMonth].forEach(sub => {
            const isModule = sub.attendance_type === 'module';
            // Deep-Link to specific session_id
            html += `<a href="sessions.php?session_id=${sub.session_id}" class="block mb-3 p-4 bg-white border border-green-200 rounded-lg shadow-sm hover:border-[#0a6e2d] hover:shadow-md transition group">
                <div class="flex justify-between items-start mb-2">
                    <div class="font-bold text-base text-gray-800 group-hover:text-[#0a6e2d] flex items-center gap-2">
                        <i class="fas fa-calendar-check text-green-600"></i> ${sub.title}
                    </div>
                    ${isModule ? '<span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded uppercase font-bold border border-blue-200">Module</span>' : ''}
                </div>
                <div class="text-sm text-gray-600 leading-relaxed">
                    ${isModule
                        ? '<span class="text-blue-700 block mt-1"><i class="fas fa-laptop mr-1"></i> Complete all modules to be marked Present.</span>'
                        : `<div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-2">
                            <span><i class="fas fa-layer-group text-gray-400 mr-1"></i> ${sub.session_title}</span>
                            <span><i class="far fa-clock text-gray-400 mr-1"></i> ${sub.start_time} – ${sub.end_time}</span>
                            <span><i class="fas fa-user text-gray-400 mr-1"></i> ${sub.subtopic_proctor || 'TBA'}</span>
                            <span><i class="fas fa-map-marker-alt text-gray-400 mr-1"></i> ${sub.subtopic_location || 'TBA'}</span>
                           </div>`
                    }
                </div>
                <div class="mt-3 text-right text-xs font-bold text-[#0a6e2d] uppercase tracking-wider group-hover:underline">Go to Session &rarr;</div>
            </a>`;
        });
    }
    if (modulesByDate[dayOfMonth] && Array.isArray(modulesByDate[dayOfMonth])) {
        html += '<h6 class="fw-bold text-sm mt-5 mb-3 text-blue-700 uppercase tracking-wider"><i class="fas fa-file-alt mr-1"></i>Modules Due</h6>';
        modulesByDate[dayOfMonth].forEach(mod => {
            // Deep-Link to specific module_id
            html += `<a href="modules.php?module_id=${mod.module_id}" class="block mb-2 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800 font-bold hover:bg-blue-100 hover:border-blue-300 transition group flex justify-between items-center">
                <div><i class="fas fa-book mr-2 group-hover:text-blue-600"></i> ${mod.title}</div>
                <span class="text-xs uppercase text-blue-500">&rarr;</span>
            </a>`;
        });
    }
    if (holidays[dayOfMonth] && Array.isArray(holidays[dayOfMonth])) {
        html += '<h6 class="fw-bold text-sm mt-5 mb-3 text-red-700 uppercase tracking-wider"><i class="fas fa-star mr-1"></i>Holidays</h6>';
        holidays[dayOfMonth].forEach(name => {
            html += `<div class="mb-2 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800 font-medium"><i class="fas fa-star mr-2 text-red-500"></i> ${name}</div>`;
        });
    }
    if (schoolEvents[dayOfMonth] && Array.isArray(schoolEvents[dayOfMonth])) {
        html += '<h6 class="fw-bold text-sm mt-5 mb-3 text-amber-700 uppercase tracking-wider"><i class="fas fa-school mr-1"></i>School Events</h6>';
        schoolEvents[dayOfMonth].forEach(ev => {
            html += `<div class="mb-2 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 font-medium"><i class="fas fa-school mr-2 text-amber-500"></i> ${ev.name} ${ev.description ? `<span class="block text-xs font-normal mt-1 text-amber-700">${ev.description}</span>` : ''}</div>`;
        });
    }
    if (!html) html = '<div class="text-center py-8 text-gray-400"><i class="fas fa-mug-hot text-4xl mb-3"></i><p>No events for this day.</p></div>';
    document.getElementById('modalEventsList').innerHTML = html;
    new bootstrap.Modal(document.getElementById('dailyEventsModal')).show();
}
</script>
</body>
</html>