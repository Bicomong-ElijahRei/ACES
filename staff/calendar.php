<?php
/**
 * ============================================================
 * ACES System — Staff Calendar (Redesigned)
 * ============================================================
 * Visual makeover only — all existing functionality preserved:
 *   - Month view with prev/next navigation
 *   - Add Event (holiday / school_event)
 *   - Click day -> detail modal with links
 *   - Sessions, Subtopics, Modules, Holidays, School Events
 *
 * Design principles applied:
 *   1. Simplicity — remove visual noise from cells
 *   2. Color-coding — one color per event type, used everywhere
 *   3. Progressive disclosure — compact cell -> detailed modal
 *   4. Responsive — adapts to smaller screens
 *   5. Accessibility — semantic color + text labels
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'] ?? '';

// ============================================================
// HANDLE ADD EVENT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $type        = $_POST['event_type'] ?? '';
    $date        = $_POST['event_date'] ?? '';
    $name        = trim($_POST['event_name'] ?? '');
    $description = trim($_POST['event_description'] ?? '');

    if ($date && $name) {
        if ($type === 'holiday') {
            $stmt = $pdo->prepare("INSERT INTO holidays (date, name) VALUES (?, ?)");
            $stmt->execute([$date, $name]);
        } elseif ($type === 'school_event') {
            $stmt = $pdo->prepare("INSERT INTO school_events (date, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$date, $name, $description]);
        }
    }

    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
    $year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');
    header("Location: calendar.php?month=$month&year=$year");
    exit;
}

// ============================================================
// MONTH NAVIGATION
// ============================================================
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$days_in_month      = date('t', $first_day_of_month);
$start_weekday      = date('w', $first_day_of_month); // 0=Sun

// ============================================================
// FETCH EVENTS FOR THIS MONTH
// ============================================================
$events = [];
$start_date = sprintf('%04d-%02d-01', $year, $month);
$end_date   = date('Y-m-t', strtotime($start_date));

// Sessions
$stmt = $pdo->prepare("
    SELECT session_id, title AS session_title, date, description, start_time, end_time, location, proctor
    FROM sessions
    WHERE is_deleted = 0 AND date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['date'];
    $events[$date][] = [
        'type'        => 'session',
        'title'       => $row['session_title'],
        'description' => $row['description'],
        'time'        => date('g:i A', strtotime($row['start_time'])) . ' – ' . date('g:i A', strtotime($row['end_time'])),
        'location'    => $row['location'],
        'proctor'     => $row['proctor'],
        'id'          => $row['session_id'],
    ];
}

// Subtopics
$stmt = $pdo->prepare("
    SELECT st.subtopic_id, st.title AS subtopic_title, st.description,
           st.subtopic_date, st.subtopic_start_time, st.subtopic_end_time,
           st.subtopic_location, st.subtopic_proctor,
           s.title AS session_title
    FROM subtopics st
    JOIN sessions s ON st.session_id = s.session_id
    WHERE st.is_deleted = 0 AND st.subtopic_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['subtopic_date'];
    $events[$date][] = [
        'type'        => 'subtopic',
        'title'       => $row['subtopic_title'],
        'description' => $row['description'],
        'time'        => date('g:i A', strtotime($row['subtopic_start_time'])) . ' – ' . date('g:i A', strtotime($row['subtopic_end_time'])),
        'location'    => $row['subtopic_location'],
        'proctor'     => $row['subtopic_proctor'],
        'session'     => $row['session_title'],
        'id'          => $row['subtopic_id'],
    ];
}

// Holidays
$stmt = $pdo->prepare("SELECT holiday_id, name, date FROM holidays WHERE date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['date'];
    $events[$date][] = [
        'type'        => 'holiday',
        'title'       => $row['name'],
        'description' => 'Holiday',
        'id'          => $row['holiday_id'],
    ];
}

// School events
$stmt = $pdo->prepare("SELECT event_id, name, description, date FROM school_events WHERE date BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['date'];
    $events[$date][] = [
        'type'        => 'school_event',
        'title'       => $row['name'],
        'description' => $row['description'],
        'id'          => $row['event_id'],
    ];
}

// Modules (due dates)
$stmt = $pdo->prepare("
    SELECT module_id, title, due_date, type, subtopic_id
    FROM modules
    WHERE is_deleted = 0 AND due_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch()) {
    $date = $row['due_date'];
    $events[$date][] = [
        'type'        => 'module',
        'title'       => $row['title'],
        'description' => "Due date for {$row['type']}",
        'subtopic_id' => $row['subtopic_id'],
        'id'          => $row['module_id'],
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
        /* ============================================================
           DESIGN TOKENS — used consistently everywhere
           ============================================================ */
        :root {
            --event-session:   #3b82f6;
            --event-session-bg: #eff6ff;
            --event-subtopic:  #10b981;
            --event-subtopic-bg: #ecfdf5;
            --event-module:    #8b5cf6;
            --event-module-bg: #f5f3ff;
            --event-holiday:   #ef4444;
            --event-holiday-bg: #fef2f2;
            --event-event:     #f59e0b;
            --event-event-bg:  #fff7ed;

            --primary: #0a6e2d;
            --primary-dark: #054018;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-400: #9ca3af;
            --neutral-600: #6b7280;
            --neutral-800: #1f2937;
        }

        html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* ============================================================
           CALENDAR GRID
           ============================================================ */
        .calendar-wrap {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            overflow: hidden;
            border: 1px solid var(--neutral-200);
        }
        .calendar-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .calendar-grid th {
            background: var(--primary-dark);
            color: #fff;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .calendar-grid td {
            border: 1px solid var(--neutral-200);
            vertical-align: top;
            height: 110px;
            padding: 8px;
            background: #fff;
            transition: background 0.15s;
            cursor: pointer;
            position: relative;
        }
        .calendar-grid td:hover { background: #f0fdf4; }
        .calendar-grid td.is-empty {
            background: var(--neutral-50);
            cursor: default;
        }
        .calendar-grid td.is-empty:hover { background: var(--neutral-50); }
        .calendar-grid td.is-today {
            background: #dcf3e6;
        }
        .calendar-grid td.is-today .day-num {
            background: var(--primary);
            color: #fff;
        }
        .day-num {
            font-weight: 700;
            font-size: 0.8rem;
            color: var(--neutral-800);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            margin-bottom: 6px;
        }

        /* Event pills inside cells */
        .event-pill {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.68rem;
            padding: 3px 7px;
            border-radius: 5px;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 500;
            transition: transform 0.1s;
        }
        .event-pill:hover { transform: translateX(1px); }

        .event-pill.session      { background: var(--event-session-bg);  color: #1e40af; }
        .event-pill.subtopic     { background: var(--event-subtopic-bg); color: #065f46; }
        .event-pill.module       { background: var(--event-module-bg);   color: #6b21a8; }
        .event-pill.holiday      { background: var(--event-holiday-bg);  color: #991b1b; }
        .event-pill.school_event { background: var(--event-event-bg);    color: #92400e; }

        .event-pill .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .event-pill.session .dot      { background: var(--event-session); }
        .event-pill.subtopic .dot     { background: var(--event-subtopic); }
        .event-pill.module .dot       { background: var(--event-module); }
        .event-pill.holiday .dot      { background: var(--event-holiday); }
        .event-pill.school_event .dot { background: var(--event-event); }

        .more-count {
            font-size: 0.65rem;
            color: var(--neutral-400);
            padding-left: 4px;
            font-weight: 600;
        }

        /* ============================================================
           LEGEND
           ============================================================ */
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--neutral-600);
            padding: 4px 10px;
            border-radius: 9999px;
            background: var(--neutral-50);
            border: 1px solid var(--neutral-200);
        }
        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        /* ============================================================
           MODAL
           ============================================================ */
        .modal-content { border-radius: 14px; border: none; overflow: hidden; }
        .modal-header-clean {
            background: var(--primary-dark);
            color: #fff;
            padding: 18px 24px;
            border: none;
        }
        .modal-body-clean {
            padding: 20px 24px;
            max-height: 65vh;
            overflow-y: auto;
            background: var(--neutral-50);
        }

        /* Event cards inside modal */
        .modal-event {
            background: #fff;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 12px;
            border-left: 4px solid var(--neutral-200);
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            transition: all 0.15s;
        }
        .modal-event:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }

        .modal-event.session      { border-left-color: var(--event-session); }
        .modal-event.subtopic     { border-left-color: var(--event-subtopic); }
        .modal-event.module       { border-left-color: var(--event-module); }
        .modal-event.holiday      { border-left-color: var(--event-holiday); }
        .modal-event.school_event { border-left-color: var(--event-event); }

        .modal-event-title {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--neutral-800);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        .modal-event-icon {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: #fff;
            flex-shrink: 0;
        }
        .modal-event-icon.session      { background: var(--event-session); }
        .modal-event-icon.subtopic     { background: var(--event-subtopic); }
        .modal-event-icon.module       { background: var(--event-module); }
        .modal-event-icon.holiday      { background: var(--event-holiday); }
        .modal-event-icon.school_event { background: var(--event-event); }

        .modal-event-meta {
            font-size: 0.8rem;
            color: var(--neutral-600);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 3px;
        }
        .modal-event-link {
            font-size: 0.78rem;
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 10px;
        }
        .modal-event-link:hover { color: var(--primary-dark); text-decoration: underline; }

        /* ============================================================
           NAVIGATION BUTTONS
           ============================================================ */
        .nav-btn {
            background: #fff;
            border: 1px solid var(--neutral-200);
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--neutral-800);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
            text-decoration: none;
        }
        .nav-btn:hover { background: var(--neutral-50); border-color: var(--primary); color: var(--primary); }

        .month-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .calendar-grid td { height: 80px; padding: 4px; }
            .day-num { width: 22px; height: 22px; font-size: 0.7rem; }
            .event-pill { font-size: 0.6rem; padding: 2px 5px; }
            .month-title { font-size: 1rem; }
        }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-[#0a6e2d]">
                    <i class="fas fa-calendar-alt mr-2"></i>Calendar
                </h1>
                <p class="text-sm text-gray-500 mt-1">Sessions, subtopics, modules, and events at a glance</p>
            </div>
            <a href="dashboard.php" class="text-[#0a6e2d] hover:text-green-800" title="Home">
                <i class="fas fa-house text-2xl"></i>
            </a>
        </div>

        <!-- Legend + Actions Bar -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2">
                <span class="legend-item"><span class="legend-dot" style="background:#3b82f6"></span> Sessions</span>
                <span class="legend-item"><span class="legend-dot" style="background:#10b981"></span> Subtopics</span>
                <span class="legend-item"><span class="legend-dot" style="background:#8b5cf6"></span> Modules</span>
                <span class="legend-item"><span class="legend-dot" style="background:#ef4444"></span> Holidays</span>
                <span class="legend-item"><span class="legend-dot" style="background:#f59e0b"></span> Events</span>
            </div>
            <?php if (!isViewer()): ?>
            <button class="bg-[#0a6e2d] hover:bg-green-800 text-white text-sm font-semibold px-4 py-2 rounded-lg inline-flex items-center gap-2 transition"
                    data-bs-toggle="modal" data-bs-target="#addEventModal">
                <i class="fas fa-plus"></i> Add Event
            </button>
            <?php endif; ?>
        </div>

        <!-- Month Navigation -->
        <div class="flex items-center justify-between mb-4">
            <a href="?month=<?= $month-1 ?>&year=<?= $year ?>" class="nav-btn">
                <i class="fas fa-chevron-left"></i> <span class="hidden sm:inline">Prev</span>
            </a>
            <span class="month-title"><?= date('F Y', $first_day_of_month) ?></span>
            <a href="?month=<?= $month+1 ?>&year=<?= $year ?>" class="nav-btn">
                <span class="hidden sm:inline">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        </div>

        <!-- Calendar Grid -->
        <div class="calendar-wrap">
            <table class="calendar-grid">
                <thead>
                    <tr>
                        <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $day_counter = 1;
                    $cell_count  = 0;
                    $weekday     = $start_weekday;
                    $today_str   = date('Y-m-d');

                    echo '<tr>';
                    for ($i = 0; $i < $weekday; $i++) {
                        echo '<td class="is-empty"></td>';
                        $cell_count++;
                    }

                    while ($day_counter <= $days_in_month) {
                        $date_str   = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);
                        $day_events = $events[$date_str] ?? [];
                        $is_today   = ($date_str === $today_str) ? ' is-today' : '';

                        // Sort events: sessions first, then subtopics, modules, holidays, events
                        usort($day_events, function($a, $b) {
                            $order = ['session'=>1,'subtopic'=>2,'module'=>3,'holiday'=>4,'school_event'=>5];
                            return ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9);
                        });
                        ?>
                        <td class="<?= $is_today ?>" data-date="<?= $date_str ?>">
                            <div class="day-num"><?= $day_counter ?></div>
                            <?php foreach (array_slice($day_events, 0, 3) as $ev): ?>
                                <div class="event-pill <?= $ev['type'] ?>" title="<?= htmlspecialchars($ev['title']) ?>">
                                    <span class="dot"></span>
                                    <span class="truncate"><?= htmlspecialchars($ev['title']) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($day_events) > 3): ?>
                                <div class="more-count">+<?= count($day_events) - 3 ?> more</div>
                            <?php endif; ?>
                        </td>
                        <?php
                        $cell_count++;
                        $day_counter++;
                        if ($cell_count % 7 == 0 && $day_counter <= $days_in_month) {
                            echo '</tr><tr>';
                        }
                    }

                    $remaining = 7 - ($cell_count % 7);
                    if ($remaining < 7) {
                        for ($i = 0; $i < $remaining; $i++) {
                            echo '<td class="is-empty"></td>';
                        }
                    }
                    echo '</tr>';
                    ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<!-- ============================================================
     ADD EVENT MODAL
     ============================================================ -->
<div class="modal fade" id="addEventModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header-clean">
                <div class="flex items-center justify-between">
                    <h5 class="modal-title text-lg font-bold">
                        <i class="fas fa-calendar-plus mr-2"></i>Add Event
                    </h5>
                    <button type="button" class="text-white/80 hover:text-white" data-bs-dismiss="modal">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <input type="hidden" name="add_event" value="1">
                    <div class="mb-3">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Event Type</label>
                        <select name="event_type" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none" required>
                            <option value="holiday">🎉 Holiday</option>
                            <option value="school_event">🏫 School Event</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Date</label>
                        <input type="date" name="event_date" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none" required>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Name / Title</label>
                        <input type="text" name="event_name" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none" required>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Description (optional)</label>
                        <textarea name="event_description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none"></textarea>
                    </div>
                </div>
                <div class="p-4 border-t border-gray-200 flex justify-end gap-2">
                    <button type="button" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold px-4 py-2 rounded-lg transition" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="bg-[#0a6e2d] hover:bg-green-800 text-white font-semibold px-4 py-2 rounded-lg transition">
                        <i class="fas fa-save mr-1"></i> Add Event
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     DAY DETAIL MODAL
     ============================================================ -->
<div class="modal fade" id="dayModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header-clean">
                <div class="flex items-center justify-between">
                    <div>
                        <h5 class="modal-title text-lg font-bold" id="modalDate">—</h5>
                        <p class="text-xs opacity-75 mt-0.5" id="modalEventCount"></p>
                    </div>
                    <button type="button" class="text-white/80 hover:text-white" data-bs-dismiss="modal">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body-clean" id="modalBody">
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-spinner fa-spin text-2xl"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ALL_EVENTS = <?= $events_json ?>;

const EVENT_META = {
    session:      { icon: 'fa-calendar-check',   label: 'Session' },
    subtopic:     { icon: 'fa-book',             label: 'Subtopic' },
    module:       { icon: 'fa-file-alt',         label: 'Module' },
    holiday:      { icon: 'fa-star',             label: 'Holiday' },
    school_event: { icon: 'fa-school',           label: 'School Event' }
};

$(document).ready(function() {
    $('.calendar-grid td[data-date]').on('click', function() {
        const date = $(this).data('date');
        const events = ALL_EVENTS[date] || [];
        const formatted = new Date(date + 'T12:00:00').toLocaleDateString('en-US', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });

        $('#modalDate').text(formatted);
        $('#modalEventCount').text(events.length + ' event' + (events.length !== 1 ? 's' : ''));

        const body = document.getElementById('modalBody');

        if (events.length === 0) {
            body.innerHTML = `
                <div class="text-center py-12">
                    <i class="fas fa-calendar-times text-4xl text-gray-300 mb-3"></i>
                    <p class="text-gray-500">No events scheduled for this day.</p>
                </div>`;
            new bootstrap.Modal(document.getElementById('dayModal')).show();
            return;
        }

        body.innerHTML = events.map(ev => {
            const meta = EVENT_META[ev.type] || { icon: 'fa-circle', label: ev.type };
            const link = getEventLink(ev);
            const details = getEventDetails(ev);
            return `
                <div class="modal-event ${ev.type}">
                    <div class="modal-event-title">
                        <span class="modal-event-icon ${ev.type}">
                            <i class="fas ${meta.icon}"></i>
                        </span>
                        ${escapeHtml(ev.title)}
                    </div>
                    ${details}
                    ${link}
                </div>`;
        }).join('');

        new bootstrap.Modal(document.getElementById('dayModal')).show();
    });

    function getEventDetails(ev) {
        let html = '';
        if (ev.time) {
            html += `<div class="modal-event-meta"><i class="far fa-clock text-gray-400"></i>${ev.time}</div>`;
        }
        if (ev.location) {
            html += `<div class="modal-event-meta"><i class="fas fa-map-marker-alt text-gray-400"></i>${escapeHtml(ev.location)}</div>`;
        }
        if (ev.proctor) {
            html += `<div class="modal-event-meta"><i class="fas fa-user text-gray-400"></i>${escapeHtml(ev.proctor)}</div>`;
        }
        if (ev.session) {
            html += `<div class="modal-event-meta"><i class="fas fa-folder-open text-gray-400"></i>Session: ${escapeHtml(ev.session)}</div>`;
        }
        if (ev.description && ev.type !== 'holiday') {
            html += `<div class="modal-event-meta mt-2 text-gray-500 italic">${escapeHtml(ev.description)}</div>`;
        }
        return html;
    }

    function getEventLink(ev) {
        if (ev.type === 'session' || ev.type === 'subtopic') {
            return `<a href="subtopics.php" class="modal-event-link"><i class="fas fa-external-link-alt"></i> Manage Sessions & Subtopics</a>`;
        }
        if (ev.type === 'module') {
            return `<a href="modules.php" class="modal-event-link"><i class="fas fa-external-link-alt"></i> Manage Modules</a>`;
        }
        return '';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }
});
</script>
</body>
</html>