<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'];

// Handle adding a new event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $type = $_POST['event_type'];
    $date = $_POST['event_date'];
    $name = trim($_POST['event_name']);
    $description = trim($_POST['event_description']);

    if ($type === 'holiday') {
        $stmt = $pdo->prepare("INSERT INTO holidays (date, name) VALUES (?, ?)");
        $stmt->execute([$date, $name]);
    } elseif ($type === 'school_event') {
        $stmt = $pdo->prepare("INSERT INTO school_events (date, name, description) VALUES (?, ?, ?)");
        $stmt->execute([$date, $name, $description]);
    }
    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
    header("Location: calendar.php?month=$month&year=$year");
    exit;
}

// Get month/year from GET, default to current
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day_of_month);
$start_weekday = date('w', $first_day_of_month); // 0=Sun

// Fetch events
$events = [];
$start_date = "$year-$month-01";
$end_date = date('Y-m-t', strtotime($start_date));

// Sessions
$stmt = $pdo->prepare("SELECT session_id, title as session_title, date, description, start_time, end_time, location, proctor FROM sessions WHERE is_deleted = 0 AND date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['date'];
    $events[$date][] = [
        'type' => 'session',
        'title' => $row['session_title'],
        'description' => $row['description'],
        'time' => date('g:i A', strtotime($row['start_time'])) . ' - ' . date('g:i A', strtotime($row['end_time'])),
        'location' => $row['location'],
        'proctor' => $row['proctor'],
        'id' => $row['session_id']
    ];
}

// Subtopics
$stmt = $pdo->prepare("
    SELECT st.subtopic_id, st.title as subtopic_title, st.description, 
           st.subtopic_date, st.subtopic_start_time, st.subtopic_end_time, 
           st.subtopic_location, st.subtopic_proctor, 
           s.title as session_title 
    FROM subtopics st 
    JOIN sessions s ON st.session_id = s.session_id 
    WHERE st.is_deleted = 0 AND st.subtopic_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['subtopic_date'];
    $events[$date][] = [
        'type' => 'subtopic',
        'title' => $row['subtopic_title'],
        'description' => $row['description'],
        'time' => date('g:i A', strtotime($row['subtopic_start_time'])) . ' - ' . date('g:i A', strtotime($row['subtopic_end_time'])),
        'location' => $row['subtopic_location'],
        'proctor' => $row['subtopic_proctor'],
        'session' => $row['session_title'],
        'id' => $row['subtopic_id']
    ];
}

// Holidays
$stmt = $pdo->prepare("SELECT holiday_id, name, date FROM holidays WHERE date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['date'];
    $events[$date][] = [
        'type' => 'holiday',
        'title' => $row['name'],
        'description' => 'Holiday',
        'id' => $row['holiday_id']
    ];
}

// School events
$stmt = $pdo->prepare("SELECT event_id, name, description, date FROM school_events WHERE date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['date'];
    $events[$date][] = [
        'type' => 'school_event',
        'title' => $row['name'],
        'description' => $row['description'],
        'id' => $row['event_id']
    ];
}

// Modules (due dates)
$stmt = $pdo->prepare("SELECT module_id, title, due_date, type, subtopic_id FROM modules WHERE is_deleted = 0 AND due_date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['due_date'];
    $events[$date][] = [
        'type' => 'module',
        'title' => $row['title'],
        'description' => "Due date for {$row['type']}",
        'subtopic_id' => $row['subtopic_id'],
        'id' => $row['module_id']
    ];
}

$events_json = json_encode($events);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar | ACES Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .calendar-table { width: 100%; border-collapse: collapse; }
        .calendar-table th { background: #054018; color: #fff; padding: 10px; text-align: center; font-weight: 600; font-size: 0.85rem; }
        .calendar-table td { border: 1px solid #d1d5db; vertical-align: top; height: 100px; width: 14.28%; padding: 6px; background: #fff; transition: 0.15s; cursor: pointer; }
        .calendar-table td:hover { background: #f0fdf4; }
        .calendar-table td.today { background: #dcf3e6; border: 2px solid #0a6e2d; }
        .calendar-table td.empty { background: #f9fafb; cursor: default; }
        .day-number { font-weight: 700; margin-bottom: 4px; font-size: 0.85rem; }
        .event-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 2px; }
        .event-dot.session { background: #3b82f6; }
        .event-dot.subtopic { background: #10b981; }
        .event-dot.holiday { background: #ef4444; }
        .event-dot.school_event { background: #f59e0b; }
        .event-dot.module { background: #8b5cf6; }
        .event-label { font-size: 0.65rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; color: #4b5563; }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
        .event-item { margin-bottom: 12px; padding: 10px; border-left: 4px solid #0a6e2d; background: #f0fdf4; border-radius: 0 8px 8px 0; }
        .event-item.session { border-left-color: #3b82f6; background: #eff6ff; }
        .event-item.subtopic { border-left-color: #10b981; background: #ecfdf5; }
        .event-item.holiday { border-left-color: #ef4444; background: #fef2f2; }
        .event-item.school_event { border-left-color: #f59e0b; background: #fff7ed; }
        .event-item.module { border-left-color: #8b5cf6; background: #f5f3ff; }
        .event-link { font-size: 0.75rem; color: #0a6e2d; text-decoration: underline; margin-top: 4px; display: inline-block; }
        .event-link:hover { color: #054018; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">

        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">
                <i class="fas fa-calendar-alt mr-2"></i>Calendar
            </h1>
            <a href="dashboard.php" class="text-[#0a6e2d]">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/></svg>
            </a>
        </div>

        <!-- Legend & Controls -->
        <div class="bg-white rounded-lg shadow-md p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-3 text-xs">
                <span><span class="event-dot session"></span> Sessions</span>
                <span><span class="event-dot subtopic"></span> Subtopics</span>
                <span><span class="event-dot module"></span> Modules</span>
                <span><span class="event-dot holiday"></span> Holidays</span>
                <span><span class="event-dot school_event"></span> Events</span>
            </div>
            <button class="bg-green-700 hover:bg-green-800 text-white text-sm px-4 py-2 rounded-lg" data-bs-toggle="modal" data-bs-target="#addEventModal">
                <i class="fas fa-plus mr-1"></i> Add Event
            </button>
        </div>

        <!-- Month Navigation -->
        <div class="flex items-center justify-between mb-3">
            <a href="?month=<?= $month-1 ?>&year=<?= $year ?>" class="bg-white border px-3 py-1.5 rounded-lg text-sm hover:bg-gray-50">
                <i class="fas fa-chevron-left mr-1"></i> Prev
            </a>
            <span class="text-lg font-bold text-[#0a6e2d]"><?= date('F Y', $first_day_of_month) ?></span>
            <a href="?month=<?= $month+1 ?>&year=<?= $year ?>" class="bg-white border px-3 py-1.5 rounded-lg text-sm hover:bg-gray-50">
                Next <i class="fas fa-chevron-right ml-1"></i>
            </a>
        </div>

        <!-- Calendar Table -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden">
            <table class="calendar-table">
                <thead>
                    <tr>
                        <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $day_counter = 1;
                    $cell_count = 0;
                    $weekday = $start_weekday;
                    $today_str = date('Y-m-d');

                    // FIX: open the first row before any cells
                    echo '<tr>';

                    for ($i = 0; $i < $weekday; $i++) {
                        echo '<td class="empty"></td>';
                        $cell_count++;
                    }

                    while ($day_counter <= $days_in_month) {
                        $date_str = sprintf("%04d-%02d-%02d", $year, $month, $day_counter);
                        $day_events = $events[$date_str] ?? [];
                        $is_today = ($date_str === $today_str) ? ' today' : '';
                        ?>
                        <td class="<?= $is_today ?>" data-date="<?= $date_str ?>">
                            <div class="day-number"><?= $day_counter ?></div>
                            <?php foreach (array_slice($day_events, 0, 3) as $ev): ?>
                                <span class="event-label">
                                    <span class="event-dot <?= $ev['type'] ?>"></span>
                                    <?= htmlspecialchars($ev['title']) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (count($day_events) > 3): ?>
                                <span class="text-xs text-gray-400">+<?= count($day_events) - 3 ?> more</span>
                            <?php endif; ?>
                        </td>
                        <?php
                        $cell_count++;
                        $day_counter++;
                        // If we’ve completed a row (7 cells) and there are more days to come, close current row and start a new one
                        if ($cell_count % 7 == 0 && $day_counter <= $days_in_month) {
                            echo '</tr><tr>';
                        }
                    }

                    // Fill the remaining cells of the last row with empty cells
                    $remaining = 7 - ($cell_count % 7);
                    if ($remaining < 7) {
                        for ($i = 0; $i < $remaining; $i++) {
                            echo '<td class="empty"></td>';
                        }
                    }
                    echo '</tr>'; // close the last row
                    ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<!-- Add Event Modal -->
<div class="modal fade" id="addEventModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-green-700 text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-plus mr-2"></i>Add Event to Calendar</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="add_event" value="1">
                    <div class="mb-3">
                        <label class="form-label">Event Type</label>
                        <select name="event_type" class="form-select" required>
                            <option value="holiday">Holiday</option>
                            <option value="school_event">School Event</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="event_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Name / Title</label>
                        <input type="text" name="event_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description (optional)</label>
                        <textarea name="event_description" rows="2" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn bg-green-700 text-white hover:bg-green-800">Add Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Day Detail Modal (WITH DIRECT LINKS) -->
<div class="modal fade" id="dayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-green-700 text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-day mr-2"></i><span id="modalDate"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody">Loading...</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
var allEvents = <?= $events_json ?>;

$(document).ready(function() {
    $('.calendar-table td[data-date]').on('click', function() {
        var date = $(this).data('date');
        var events = allEvents[date] || [];
        var formattedDate = new Date(date + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        $('#modalDate').text(formattedDate);
        var html = '';
        if (events.length === 0) {
            html = '<div class="text-gray-500 text-center py-4">No events scheduled for this day.</div>';
        } else {
            events.forEach(function(ev) {
                var details = '';
                var link = '';
                if (ev.type === 'session') {
                    details = `<div class="font-semibold text-blue-700">📅 ${escapeHtml(ev.title)}</div>
                               <div class="text-sm text-gray-600 mt-1"><i class="far fa-clock mr-1"></i> ${ev.time}</div>
                               <div class="text-sm text-gray-600"><i class="fas fa-map-marker-alt mr-1"></i> ${escapeHtml(ev.location)}</div>
                               <div class="text-sm text-gray-600"><i class="fas fa-user mr-1"></i> ${escapeHtml(ev.proctor)}</div>
                               ${ev.description ? `<div class="text-sm text-gray-500 mt-1">${escapeHtml(ev.description)}</div>` : ''}`;
                    link = `<a href="subtopics.php" class="event-link"><i class="fas fa-external-link-alt mr-1"></i> Manage Sessions & Subtopics</a>`;
                } else if (ev.type === 'subtopic') {
                    details = `<div class="font-semibold text-green-700">📘 ${escapeHtml(ev.title)}</div>
                               <div class="text-sm text-gray-500">Session: ${escapeHtml(ev.session)}</div>
                               <div class="text-sm text-gray-600 mt-1"><i class="far fa-clock mr-1"></i> ${ev.time}</div>
                               <div class="text-sm text-gray-600"><i class="fas fa-map-marker-alt mr-1"></i> ${escapeHtml(ev.location)}</div>
                               <div class="text-sm text-gray-600"><i class="fas fa-user mr-1"></i> ${escapeHtml(ev.proctor)}</div>`;
                    link = `<a href="subtopics.php" class="event-link"><i class="fas fa-external-link-alt mr-1"></i> Manage Sessions & Subtopics</a>`;
                } else if (ev.type === 'holiday') {
                    details = `<div class="font-semibold text-red-700">🎉 ${escapeHtml(ev.title)}</div>
                               <div class="text-sm text-gray-600">Holiday</div>`;
                } else if (ev.type === 'school_event') {
                    details = `<div class="font-semibold text-orange-700">🏫 ${escapeHtml(ev.title)}</div>
                               ${ev.description ? `<div class="text-sm text-gray-600 mt-1">${escapeHtml(ev.description)}</div>` : ''}`;
                } else if (ev.type === 'module') {
                    details = `<div class="font-semibold text-purple-700">📝 ${escapeHtml(ev.title)}</div>
                               <div class="text-sm text-gray-600">Due date for ${escapeHtml(ev.description)}</div>`;
                    link = `<a href="modules.php" class="event-link"><i class="fas fa-external-link-alt mr-1"></i> Manage Modules</a>`;
                }
                html += `<div class="event-item ${ev.type}">
                            ${details}
                            ${link}
                         </div>`;
            });
        }
        $('#modalBody').html(html);
        $('#dayModal').modal('show');
    });

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
});
</script>
</body>
</html>