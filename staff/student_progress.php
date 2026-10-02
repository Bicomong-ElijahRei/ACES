<?php
/**
 * ============================================================
 * ACES System — Student Progress (Session-Centric UI)
 * ============================================================
 * Modern, clean dashboard with:
 *   - Overview stat cards
 *   - Session cards (no more matrix)
 *   - Slide-in drawer for student details
 *   - Progressive disclosure
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

// ---------- OVERVIEW STATS ----------
$total_students = (int) $pdo->query("
    SELECT COUNT(*) FROM students WHERE is_deleted = 0
")->fetchColumn();

$total_sessions = (int) $pdo->query("
    SELECT COUNT(*) FROM sessions WHERE is_deleted = 0
")->fetchColumn();

// Average progress across all active students
$avg_progress_row = $pdo->query("
    SELECT 
      SUM(CASE WHEN a.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
      COUNT(*) AS total_count
    FROM registrations r
    LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id
    JOIN students s ON s.student_id = r.student_id
    WHERE s.is_deleted = 0
")->fetch();
$avg_progress = ($avg_progress_row['total_count'] > 0)
    ? round(($avg_progress_row['present_count'] / $avg_progress_row['total_count']) * 100)
    : 0;

// At-risk students (progress < 50%)
$at_risk = 0;
$at_risk_rows = $pdo->query("
    SELECT r.student_id, 
           COUNT(*) AS reg_count,
           SUM(CASE WHEN a.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count
    FROM registrations r
    LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id
    JOIN students s ON s.student_id = r.student_id
    WHERE s.is_deleted = 0
    GROUP BY r.student_id
")->fetchAll();
foreach ($at_risk_rows as $row) {
    if ($row['reg_count'] > 0) {
        $pct = ($row['present_count'] / $row['reg_count']) * 100;
        if ($pct < 50) $at_risk++;
    }
}

// ---------- SESSION CARDS DATA ----------
$sessions = $pdo->query("
    SELECT 
        s.session_id, s.title, s.phase, s.date,
        COUNT(DISTINCT st.subtopic_id) AS subtopic_count,
        COUNT(DISTINCT r.student_id)   AS student_count,
        SUM(CASE WHEN a.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN a.attendance_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN a.attendance_status = 'absent'  THEN 1 ELSE 0 END) AS absent_count,
        COUNT(a.attendance_id)         AS att_total
    FROM sessions s
    LEFT JOIN subtopics st ON st.session_id = s.session_id AND st.is_deleted = 0
    LEFT JOIN registrations r ON r.subtopic_id = st.subtopic_id
    LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = st.subtopic_id
    WHERE s.is_deleted = 0
    GROUP BY s.session_id
    ORDER BY s.date DESC, s.title
")->fetchAll();

// Compute avg progress per session
foreach ($sessions as &$sess) {
    $sess['avg_progress'] = ($sess['att_total'] > 0)
        ? round(($sess['present_count'] / $sess['att_total']) * 100)
        : 0;
}
unset($sess);

// ---------- FILTERS ----------
$courses  = $pdo->query("SELECT DISTINCT program FROM students WHERE is_deleted = 0 ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM students WHERE is_deleted = 0 ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
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
    <style>
        /* ============================================
           DESIGN SYSTEM
           ============================================ */
        :root {
            --primary: #0a6e2d;
            --primary-dark: #054018;
            --success: #10b981;
            --success-bg: #d1fae5;
            --warning: #f59e0b;
            --warning-bg: #fef3c7;
            --danger: #ef4444;
            --danger-bg: #fee2e2;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-600: #6b7280;
            --neutral-800: #1f2937;
        }

        html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* ---------- STAT CARDS ---------- */
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid var(--neutral-200);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); transform: translateY(-1px); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .stat-value { font-size: 26px; font-weight: 700; color: var(--neutral-800); line-height: 1; }
        .stat-label { font-size: 12px; color: var(--neutral-600); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; }

        /* ---------- SESSION CARDS ---------- */
        .session-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }
        .session-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }

        .phase-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .phase-Preparation   { background: #dbeafe; color: #1e40af; }
        .phase-Pre-Employment { background: #fef3c7; color: #92400e; }
        .phase-Career-Fair    { background: #f3e8ff; color: #6b21a8; }

        /* ---------- PROGRESS BAR ---------- */
        .progress-track {
            height: 8px;
            background: var(--neutral-100);
            border-radius: 9999px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.4s ease;
        }
        .progress-fill.green  { background: var(--success); }
        .progress-fill.amber  { background: var(--warning); }
        .progress-fill.red    { background: var(--danger); }

        /* ---------- STATUS PILLS ---------- */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-present  { background: var(--success-bg); color: #065f46; }
        .status-pending  { background: var(--warning-bg); color: #92400e; }
        .status-absent   { background: var(--danger-bg);  color: #991b1b; }
        .status-no-reg   { background: var(--neutral-100); color: var(--neutral-600); }

        /* ---------- DRAWER ---------- */
        .drawer-backdrop {
            position: fixed; inset: 0;
            background: rgba(0, 0, 0, 0.4);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 1099;
        }
        .drawer-backdrop.show { opacity: 1; pointer-events: auto; }

        .drawer {
            position: fixed;
            top: 0; right: 0;
            height: 100vh;
            width: 640px;
            max-width: 95vw;
            background: #fff;
            box-shadow: -4px 0 30px rgba(0,0,0,0.15);
            transform: translateX(100%);
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1100;
            display: flex;
            flex-direction: column;
        }
        .drawer.open { transform: translateX(0); }

        .drawer-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--neutral-200);
            background: var(--primary-dark);
            color: #fff;
        }
        .drawer-body {
            flex: 1;
            overflow-y: auto;
            background: var(--neutral-50);
        }

        /* ---------- STUDENT ROW IN DRAWER ---------- */
        .student-row {
            background: #fff;
            border-radius: 10px;
            margin-bottom: 10px;
            overflow: hidden;
            border: 1px solid var(--neutral-200);
            transition: all 0.15s;
        }
        .student-row:hover { border-color: var(--primary); }
        .student-row.expanded { border-color: var(--primary); box-shadow: 0 4px 12px rgba(10,110,45,0.1); }

        .student-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #16a34a);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
            font-size: 15px;
            flex-shrink: 0;
        }

        .student-row-header {
            padding: 14px 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .student-row-details {
            border-top: 1px solid var(--neutral-100);
            padding: 14px 18px 18px;
            background: var(--neutral-50);
            display: none;
        }
        .student-row.expanded .student-row-details { display: block; }

        .subtopic-breakdown {
            background: #fff;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 8px;
            border-left: 4px solid var(--neutral-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .subtopic-breakdown.present { border-left-color: var(--success); }
        .subtopic-breakdown.pending { border-left-color: var(--warning); }
        .subtopic-breakdown.absent  { border-left-color: var(--danger); }

        /* ---------- BUTTONS ---------- */
        .btn-status-cycle {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--neutral-200);
            background: #fff;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-status-cycle:hover { background: var(--neutral-50); border-color: var(--primary); color: var(--primary); }

        /* ---------- ANIMATIONS ---------- */
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.3s ease; }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 768px) {
            .drawer { width: 100vw; max-width: 100vw; }
            .stat-card { padding: 14px 16px; }
            .stat-value { font-size: 20px; }
            .stat-icon { width: 40px; height: 40px; font-size: 16px; }
        }
    </style>
</head>
<body class="bg-[#dcf3e6] h-screen flex flex-col md:flex-row overflow-hidden">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>

    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Page Title -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-[#0a6e2d]">Student Progress</h1>
                <p class="text-sm text-gray-500 mt-1">Overview of student attendance and module completion</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="attendance.php" class="bg-white hover:bg-gray-50 border border-gray-300 text-gray-800 text-sm font-semibold px-4 py-2 rounded-lg inline-flex items-center gap-2 transition">
                    <i class="fas fa-table text-[#0a6e2d]"></i> Manage Records
                </a>
                <a href="dashboard.php" class="text-[#0a6e2d] hover:text-green-800" title="Home">
                    <i class="fas fa-house text-2xl"></i>
                </a>
            </div>
        </div>

        <!-- ============================================
             LAYER 1: OVERVIEW STATS
             ============================================ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8 fade-in">

            <!-- Total Students -->
            <div class="stat-card">
                <div class="stat-icon bg-blue-100 text-blue-600">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $total_students ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>

            <!-- Total Sessions -->
            <div class="stat-card">
                <div class="stat-icon bg-purple-100 text-purple-600">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $total_sessions ?></div>
                    <div class="stat-label">Sessions</div>
                </div>
            </div>

            <!-- Average Progress -->
            <div class="stat-card">
                <div class="stat-icon bg-green-100 text-green-600">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $avg_progress ?>%</div>
                    <div class="stat-label">Avg Progress</div>
                </div>
            </div>

            <!-- At-Risk Students -->
            <div class="stat-card">
                <div class="stat-icon bg-red-100 text-red-600">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $at_risk ?></div>
                    <div class="stat-label">At Risk (&lt;50%)</div>
                </div>
            </div>

        </div>

        <!-- ============================================
             LAYER 2: SESSION CARDS
             ============================================ -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-800">
                <i class="fas fa-layer-group text-[#0a6e2d] mr-2"></i>Sessions
            </h2>
            <div class="relative">
                <input type="text" id="sessionSearch" placeholder="Search sessions..."
                       class="border border-gray-300 rounded-lg pl-10 pr-4 py-2 text-sm w-64 focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none">
                <i class="fas fa-search absolute left-3 top-3 text-gray-400 text-sm"></i>
            </div>
        </div>

        <?php if (empty($sessions)): ?>
            <div class="bg-white rounded-xl p-12 text-center border border-gray-200">
                <i class="fas fa-inbox text-5xl text-gray-300 mb-4"></i>
                <p class="text-gray-500">No sessions yet.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-5" id="sessionGrid">
                <?php foreach ($sessions as $sess):
                    $progressColor = $sess['avg_progress'] >= 75 ? 'green' : ($sess['avg_progress'] >= 40 ? 'amber' : 'red');
                    $phaseKey = str_replace(' ', '-', $sess['phase']);
                ?>
                    <div class="session-card fade-in" data-session-id="<?= $sess['session_id'] ?>"
                         data-title="<?= htmlspecialchars($sess['title']) ?>">

                        <!-- Header -->
                        <div class="p-5 border-b border-gray-100">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <h3 class="font-bold text-gray-800 text-base leading-snug flex-1">
                                    <?= htmlspecialchars($sess['title']) ?>
                                </h3>
                                <span class="phase-badge phase-<?= $phaseKey ?>">
                                    <?= htmlspecialchars($sess['phase']) ?>
                                </span>
                            </div>
                            <div class="text-xs text-gray-500 flex items-center gap-3">
                                <span><i class="far fa-calendar mr-1"></i><?= date('M d, Y', strtotime($sess['date'])) ?></span>
                                <span><i class="fas fa-list-ul mr-1"></i><?= $sess['subtopic_count'] ?> subtopics</span>
                            </div>
                        </div>

                        <!-- Progress + Stats -->
                        <div class="p-5 flex-1">
                            <div class="flex justify-between items-baseline mb-2">
                                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Average Progress</span>
                                <span class="text-lg font-bold text-gray-800"><?= $sess['avg_progress'] ?>%</span>
                            </div>
                            <div class="progress-track mb-4">
                                <div class="progress-fill <?= $progressColor ?>" style="width: <?= $sess['avg_progress'] ?>%"></div>
                            </div>

                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="bg-green-50 rounded-lg py-2">
                                    <div class="text-lg font-bold text-green-700"><?= (int)$sess['present_count'] ?></div>
                                    <div class="text-[10px] text-green-600 uppercase font-semibold">Present</div>
                                </div>
                                <div class="bg-amber-50 rounded-lg py-2">
                                    <div class="text-lg font-bold text-amber-700"><?= (int)$sess['pending_count'] ?></div>
                                    <div class="text-[10px] text-amber-600 uppercase font-semibold">Pending</div>
                                </div>
                                <div class="bg-red-50 rounded-lg py-2">
                                    <div class="text-lg font-bold text-red-700"><?= (int)$sess['absent_count'] ?></div>
                                    <div class="text-[10px] text-red-600 uppercase font-semibold">Absent</div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-xs text-gray-500">
                                <i class="fas fa-user-graduate mr-1"></i><?= $sess['student_count'] ?> students
                            </span>
                            <button class="view-session-btn text-sm font-semibold text-[#0a6e2d] hover:text-green-800 flex items-center gap-1">
                                View Progress <i class="fas fa-arrow-right text-xs"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>
</div>

<!-- ============================================
     LAYER 3: SLIDE-IN DRAWER
     ============================================ -->
<div class="drawer-backdrop" id="drawerBackdrop"></div>

<div class="drawer" id="studentDrawer">
    <div class="drawer-header">
        <div class="flex items-start justify-between gap-3 mb-2">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <span class="phase-badge phase-<?= '' ?>" id="drawerPhase" style="background: rgba(255,255,255,0.2); color: #fff;"></span>
                    <span class="text-xs opacity-75" id="drawerDate"></span>
                </div>
                <h2 class="text-xl font-bold truncate" id="drawerTitle">Session</h2>
            </div>
            <button id="drawerClose" class="text-white/80 hover:text-white p-1">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="text-xs opacity-80 mt-2" id="drawerMeta"></div>
    </div>

    <!-- Drawer Toolbar -->
    <div class="px-4 py-3 bg-white border-b border-gray-200 flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[180px]">
            <input type="text" id="drawerSearch" placeholder="Search students..."
                   class="w-full border border-gray-300 rounded-lg pl-9 pr-3 py-1.5 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none">
            <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-xs"></i>
        </div>
        <select id="drawerFilter" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm bg-white">
            <option value="">All students</option>
            <option value="at-risk">⚠️ At Risk (&lt;50%)</option>
            <option value="complete">✅ Complete (100%)</option>
            <option value="in-progress">🔄 In Progress</option>
        </select>
    </div>

    <div class="drawer-body p-4" id="drawerBody">
        <div class="text-center py-12 text-gray-400">
            <i class="fas fa-spinner fa-spin text-3xl mb-3"></i>
            <p>Loading students...</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';

// ============================================
// SESSION SEARCH FILTER
// ============================================
document.getElementById('sessionSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#sessionGrid .session-card').forEach(card => {
        const title = card.dataset.title.toLowerCase();
        card.style.display = title.includes(q) ? '' : 'none';
    });
});

// ============================================
// DRAWER LOGIC
// ============================================
const drawer = document.getElementById('studentDrawer');
const backdrop = document.getElementById('drawerBackdrop');
let currentSessionId = null;
let allStudents = [];
let pendingDrawerFilter = null;

function openDrawer(sessionId, title, filter) {
    currentSessionId = sessionId;
    pendingDrawerFilter = filter || null;

    document.getElementById('drawerTitle').textContent = title;
    document.getElementById('drawerBody').innerHTML = `
        <div class="text-center py-12 text-gray-400">
            <i class="fas fa-spinner fa-spin text-3xl mb-3"></i>
            <p>Loading students...</p>
        </div>`;

    // Update URL so refresh / sharing keeps state
    const url = new URL(window.location);
    url.searchParams.set('session', sessionId);
    if (filter) url.searchParams.set('filter', filter);
    history.replaceState({}, '', url);

    drawer.classList.add('open');
    backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
    loadSessionData(sessionId);
}

function closeDrawer() {
    drawer.classList.remove('open');
    backdrop.classList.remove('show');
    document.body.style.overflow = '';
    currentSessionId = null;
    allStudents = [];
    pendingDrawerFilter = null;

    // Clear URL params
    const url = new URL(window.location);
    url.searchParams.delete('session');
    url.searchParams.delete('filter');
    history.replaceState({}, '', url);
}

document.getElementById('drawerClose').addEventListener('click', closeDrawer);
backdrop.addEventListener('click', closeDrawer);

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
});

// ============================================
// LOAD SESSION DATA
// ============================================
function loadSessionData(sessionId) {
    fetch(`get_session_progress.php?session_id=${sessionId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('drawerBody').innerHTML =
                    `<div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-lg">
                        <i class="fas fa-exclamation-circle mr-2"></i>${data.error || 'Failed to load.'}
                    </div>`;
                return;
            }

            // Update drawer header meta
            document.getElementById('drawerPhase').textContent = data.session.phase;
            document.getElementById('drawerPhase').className = 'phase-badge phase-' + data.session.phase.replace(/ /g, '-');
            document.getElementById('drawerDate').textContent = data.session.date;
            document.getElementById('drawerMeta').innerHTML =
                `<i class="fas fa-user-graduate mr-1"></i> ${data.students.length} students · 
                 <i class="fas fa-list-ul mr-1"></i> ${data.subtopics.length} subtopics`;

            allStudents = data.students;
            renderStudents(allStudents);

            // Apply any pending filter from deep-link
            if (pendingDrawerFilter) {
                document.getElementById('drawerFilter').value = pendingDrawerFilter;
                pendingDrawerFilter = null;
                filterDrawer();
            }
        })
        .catch(err => {
            console.error(err);
            document.getElementById('drawerBody').innerHTML =
                `<div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-lg">
                    <i class="fas fa-exclamation-circle mr-2"></i>Network error.
                </div>`;
        });
}

// ============================================
// RENDER STUDENT LIST
// ============================================
function renderStudents(students) {
    const body = document.getElementById('drawerBody');

    if (students.length === 0) {
        body.innerHTML = `
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-user-slash text-4xl mb-3"></i>
                <p>No students match your filters.</p>
            </div>`;
        return;
    }

    body.innerHTML = students.map(st => {
        const initials = getInitials(st.full_name);
        const progressColor = st.progress_pct >= 75 ? 'green' : (st.progress_pct >= 40 ? 'amber' : 'red');
        const riskBadge = st.progress_pct < 50
            ? '<span class="status-pill status-absent ml-2"><i class="fas fa-exclamation-triangle"></i> At Risk</span>'
            : '';

        const subtopicRows = st.subtopics.map(sb => {
            const statusLabel = sb.registered
                ? (sb.attendance_status === 'present' ? 'Present'
                    : sb.attendance_status === 'pending' ? 'Pending'
                    : sb.attendance_status === 'absent' ? 'Absent'
                    : 'Not Recorded')
                : 'Not Registered';
            const statusClass = sb.registered
                ? (sb.attendance_status === 'present' ? 'present'
                    : sb.attendance_status === 'pending' ? 'pending'
                    : sb.attendance_status === 'absent' ? 'absent'
                    : '')
                : '';
            const attIcon = sb.attendance_status === 'present' ? '✅'
                : sb.attendance_status === 'pending' ? '⏳'
                : sb.attendance_status === 'absent' ? '❌'
                : sb.registered ? '○' : '—';

            const modInfo = sb.modules_total > 0
                ? `${sb.modules_completed}/${sb.modules_total} modules`
                : 'No modules';
            const modPct = sb.modules_total > 0
                ? Math.round((sb.modules_completed / sb.modules_total) * 100) : 0;

            return `
                <div class="subtopic-breakdown ${statusClass}">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm text-gray-800 truncate">${escapeHtml(sb.title)}</div>
                        <div class="text-xs text-gray-500 mt-0.5">
                            <i class="fas fa-book mr-1"></i>${modInfo}
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="status-pill status-${statusClass || 'no-reg'}">
                            ${attIcon} ${statusLabel}
                        </span>
                        ${sb.registered ? `
                            <button class="btn-status-cycle" 
                                    data-student-id="${st.student_id}"
                                    data-subtopic-id="${sb.subtopic_id}"
                                    data-current="${sb.attendance_status}">
                                <i class="fas fa-sync-alt"></i> Set
                            </button>` : ''}
                    </div>
                </div>`;
        }).join('');

        return `
            <div class="student-row" data-student-id="${st.student_id}">
                <div class="student-row-header">
                    <div class="student-avatar">${initials}</div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm text-gray-800 truncate">
                            ${escapeHtml(st.full_name)} ${riskBadge}
                        </div>
                        <div class="text-xs text-gray-500 mt-0.5">
                            ${escapeHtml(st.program)} · ${escapeHtml(st.section)}
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <div class="progress-track flex-1">
                                <div class="progress-fill ${progressColor}" style="width: ${st.progress_pct}%"></div>
                            </div>
                            <span class="text-xs font-bold text-gray-700 w-10 text-right">${st.progress_pct}%</span>
                        </div>
                        <div class="text-[11px] text-gray-500 mt-1">
                            ✅ ${st.attendance_summary.present}/${st.attendance_summary.total} attended
                            &nbsp;·&nbsp;
                            📘 ${st.modules_summary.completed}/${st.modules_summary.total} modules
                        </div>
                    </div>
                    <i class="fas fa-chevron-down text-gray-400 text-sm transition-transform row-chevron"></i>
                </div>
                <div class="student-row-details">
                    ${subtopicRows}
                </div>
            </div>`;
    }).join('');

    // Bind expand/collapse
    body.querySelectorAll('.student-row-header').forEach(header => {
        header.addEventListener('click', function() {
            const row = this.closest('.student-row');
            row.classList.toggle('expanded');
            const chevron = this.querySelector('.row-chevron');
            if (row.classList.contains('expanded')) {
                chevron.style.transform = 'rotate(180deg)';
            } else {
                chevron.style.transform = '';
            }
        });
    });

    // Bind status cycle
    body.querySelectorAll('.btn-status-cycle').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            cycleStatus(this);
        });
    });
}

// ============================================
// STATUS CYCLE
// ============================================
function cycleStatus(btn) {
    const current = btn.dataset.current;
    let next;
    if (current === 'not_recorded' || current === '') next = 'pending';
    else if (current === 'pending') next = 'present';
    else if (current === 'present') next = 'absent';
    else if (current === 'absent') {
        if (!confirm('Unregister this student from this subtopic?')) return;
        next = 'unregister';
    } else next = 'pending';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('student_id', btn.dataset.studentId);
    fd.append('subtopic_id', btn.dataset.subtopicId);
    fd.append('status', next);

    fetch('update_attendance_status.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                // Refresh drawer content
                loadSessionData(currentSessionId);
            } else {
                alert('Error: ' + (res.error || 'Unknown'));
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Set';
            }
        })
        .catch(err => {
            alert('Network error.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i> Set';
        });
}

// ============================================
// DRAWER FILTERS
// ============================================
document.getElementById('drawerSearch').addEventListener('input', filterDrawer);
document.getElementById('drawerFilter').addEventListener('change', filterDrawer);

function filterDrawer() {
    const q = document.getElementById('drawerSearch').value.toLowerCase();
    const filter = document.getElementById('drawerFilter').value;

    let filtered = allStudents.filter(st => {
        const matchesSearch = !q || st.full_name.toLowerCase().includes(q)
            || st.student_id.toLowerCase().includes(q);
        let matchesFilter = true;
        if (filter === 'at-risk') matchesFilter = st.progress_pct < 50;
        else if (filter === 'complete') matchesFilter = st.progress_pct === 100;
        else if (filter === 'in-progress') matchesFilter = st.progress_pct > 0 && st.progress_pct < 100;
        return matchesSearch && matchesFilter;
    });

    renderStudents(filtered);
}

// ============================================
// VIEW SESSION BUTTON
// ============================================
document.querySelectorAll('.view-session-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const card = this.closest('.session-card');
        openDrawer(card.dataset.sessionId, card.dataset.title);
    });
});

// ============================================
// HELPERS
// ============================================
function getInitials(name) {
    return name.split(' ').filter(Boolean).slice(0, 2).map(n => n[0]).join('').toUpperCase();
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[m]);
}
// ============================================
// DEEP-LINK HANDLER
// ============================================
// When arriving from dashboard with ?session=X,
// auto-open the drawer for that session.
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const sessionId = params.get('session');
    const filter = params.get('filter');

    if (sessionId) {
        const card = document.querySelector(`.session-card[data-session-id="${sessionId}"]`);
        if (card) {
            // Small delay to ensure session cards rendered
            setTimeout(() => {
                openDrawer(sessionId, card.dataset.title, filter);
            }, 100);
        }
    }
});
</script>
</body>
</html>