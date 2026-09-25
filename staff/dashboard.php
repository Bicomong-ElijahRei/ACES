<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'];

// Overview stats
$active_sessions = $pdo->query("SELECT COUNT(*) FROM sessions WHERE is_deleted = 0")->fetchColumn();
$total_students  = $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0")->fetchColumn();

$att_present = $pdo->query("SELECT COUNT(*) FROM attendance WHERE attendance_status = 'present' AND is_deleted = 0")->fetchColumn();
$att_total   = $pdo->query("SELECT COUNT(*) FROM attendance WHERE is_deleted = 0")->fetchColumn();
$overall_att_pct = $att_total > 0 ? round(($att_present / $att_total) * 100) : 0;

// Correct module completion percentage
$total_submissions = $pdo->query("
    SELECT COUNT(*) FROM registrations r
    JOIN modules m ON m.subtopic_id = r.subtopic_id
    WHERE r.status IN ('assigned','auto_assigned') AND m.is_deleted = 0
")->fetchColumn();
$completed_submissions = $pdo->query("SELECT COUNT(*) FROM student_module_progress WHERE completed = 1")->fetchColumn();
$overall_mod_pct = $total_submissions > 0 ? round(($completed_submissions / $total_submissions) * 100) : 0;

// Modules data
$stmt_mod = $pdo->query("
    SELECT m.module_id, m.title, m.type,
        COUNT(sp.progress_id) as completed_count,
        (SELECT COUNT(*) FROM registrations r
         JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
         WHERE sub.subtopic_id = m.subtopic_id AND r.status IN ('assigned','auto_assigned')) as student_count
    FROM modules m
    LEFT JOIN student_module_progress sp ON m.module_id = sp.module_id AND sp.completed = 1
    WHERE m.is_deleted = 0
    GROUP BY m.module_id
    ORDER BY m.title
");
$modules = $stmt_mod->fetchAll();

// Student progress
$course_filter = isset($_GET['course']) ? $_GET['course'] : '';
$section_filter = isset($_GET['section']) ? $_GET['section'] : '';

$sql_students = "SELECT s.student_id, s.program, s.section, u.full_name
                 FROM students s
                 JOIN users u ON s.user_id = u.user_id
                 WHERE 1=1";
$params_students = [];
if ($course_filter) {
    $sql_students .= " AND s.program = ?";
    $params_students[] = $course_filter;
}
if ($section_filter) {
    $sql_students .= " AND s.section = ?";
    $params_students[] = $section_filter;
}
$stmt = $pdo->prepare($sql_students);
$stmt->execute($params_students);
$all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$studentProgress = [];
foreach ($all_students as $stu) {
    $sid = $stu['student_id'];

    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM registrations 
             WHERE student_id = ? AND status IN ('assigned','auto_assigned')) as total_reg,
            (SELECT COUNT(DISTINCT subtopic_id) FROM attendance 
             WHERE student_id = ? AND attendance_status = 'present' AND is_deleted = 0) as attended
    ");
    $stmt->execute([$sid, $sid]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM modules m
             JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id
             JOIN registrations r ON r.subtopic_id = sub.subtopic_id
             WHERE r.student_id = ? AND m.is_deleted = 0) as total_mod,
            (SELECT COUNT(*) FROM student_module_progress 
             WHERE student_id = ? AND completed = 1) as completed_mod
    ");
    $stmt->execute([$sid, $sid]);
    $mod = $stmt->fetch(PDO::FETCH_ASSOC);

    $studentProgress[] = [
        'student_id'    => $sid,
        'full_name'     => $stu['full_name'],
        'program'       => $stu['program'],
        'section'       => $stu['section'],
        'total_reg'     => (int)$att['total_reg'],
        'attended'      => (int)$att['attended'],
        'att_pct'       => $att['total_reg'] > 0 ? round(($att['attended'] / $att['total_reg']) * 100) : 0,
        'total_mod'     => (int)$mod['total_mod'],
        'completed_mod' => (int)$mod['completed_mod'],
        'mod_pct'       => $mod['total_mod'] > 0 ? round(($mod['completed_mod'] / $mod['total_mod']) * 100) : 0,
    ];
}

function progressBar($percent, $color = '#0a3e6d') {
    $percent = min(100, max(0, $percent));
    return '<div class="w-full bg-gray-200 rounded-full h-2">
        <div class="h-2 rounded-full" style="width: ' . $percent . '%; background-color: ' . $color . ';"></div>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard | ACES</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .tab-btn { cursor: pointer; }
        .tab-btn.active { font-weight: 700; border-bottom: 2px solid currentColor; }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">

        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">Dashboard</h1>
            <div class="flex items-center gap-3">
                <a href="calendar.php" class="text-[#0a6e2d] text-sm font-medium">Calendar →</a>
                <?php if (isAdmin()): ?>
                <button onclick="if(confirm('Auto‑assign unregistered students for all upcoming sessions?')) window.open('../cron/auto_assign.php','_blank')"
                        class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded">
                    <i class="fas fa-magic mr-1"></i> Auto‑Assign Now
                </button>
                <button onclick="if(confirm('Remove excess registrations that exceed capacity?')) window.location.href='purge_excess_registrations.php'"
                        class="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded ml-2">
                    <i class="fas fa-broom mr-1"></i> Purge Excess
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== OVERVIEW STATS ========== -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-3xl font-bold text-[#0a6e2d]"><?= $active_sessions ?></div>
                <div class="text-xs text-gray-500 mt-1">Active Sessions</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-3xl font-bold text-[#0a6e2d]"><?= $total_students ?></div>
                <div class="text-xs text-gray-500 mt-1">Total Students</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-3xl font-bold text-[#0a6e2d]"><?= $overall_att_pct ?>%</div>
                <div class="text-xs text-gray-500 mt-1">Overall Attendance</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <div class="text-3xl font-bold text-[#0a6e2d]"><?= $overall_mod_pct ?>%</div>
                <div class="text-xs text-gray-500 mt-1">Module Completion <span class="text-gray-400">(<?= $completed_submissions ?>/<?= $total_submissions ?>)</span></div>
            </div>
        </div>

        <!-- ========== 1. SESSION OVERVIEW (TABBED CHARTS) ========== -->
        <div class="bg-white rounded shadow-xl overflow-hidden mb-6">
            <div class="bg-[#054018] px-4 py-3 font-bold text-white">Session Overview</div>
            <div class="p-4">
                <div id="sessionChartsContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <p class="text-gray-500 col-span-full text-center py-4">Loading sessions...</p>
                </div>
            </div>
        </div>

        <!-- ========== 2. MODULES + STUDENT PROGRESS (SIDE BY SIDE) ========== -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Modules Activity -->
            <div class="bg-white rounded shadow-xl overflow-hidden">
                <div class="bg-[#054018] px-4 py-3 font-bold text-white flex justify-between items-center">
                    <span>Module Activity</span>
                    <span class="text-xs text-white/70"><?= count($modules) ?> modules</span>
                </div>
                <div class="p-4">
                    <?php if (empty($modules)): ?>
                        <p class="text-gray-400 text-sm">No modules have been created yet. Go to <strong>Module</strong> in the sidebar to add learning materials and assessments.</p>
                    <?php else: ?>
                        <div class="text-xs text-gray-500 mb-3">
                            <i class="fas fa-info-circle mr-1"></i> Completion progress per module
                        </div>
                        <?php foreach ($modules as $mod): ?>
                            <?php
                                $comp = (int)$mod['completed_count'];
                                $stud = (int)$mod['student_count'];
                                $modPct = $stud > 0 ? round(($comp / $stud) * 100) : 0;
                            ?>
                            <div class="mb-3">
                                <div class="flex items-center justify-between text-sm mb-1">
                                    <span class="text-gray-700 truncate font-medium"><?= htmlspecialchars($mod['title']) ?></span>
                                    <span class="text-xs text-gray-500"><?= $comp ?>/<?= $stud ?></span>
                                </div>
                                <p class="text-xs text-gray-500 mb-1">Completion</p>
                                <?= progressBar($modPct, $modPct >= 100 ? '#10b981' : ($modPct > 0 ? '#0a3e6d' : '#e5e7eb')) ?>
                                <div class="text-xs text-gray-400 mt-1"><?= $modPct ?>% · <?= $mod['type'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Student Progress Table -->
            <div class="bg-white rounded shadow-xl overflow-hidden">
                <div class="bg-[#054018] px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="font-bold text-white">Student Progress</div>
                    <form method="get" class="flex gap-2">
                        <select name="course" onchange="this.form.submit()" class="bg-white text-black rounded px-2 py-1 text-xs">
                            <option value="">All Courses</option>
                            <option value="BS Information Systems" <?= $course_filter == 'BS Information Systems' ? 'selected' : '' ?>>BSIS</option>
                            <option value="BS Psychology" <?= $course_filter == 'BS Psychology' ? 'selected' : '' ?>>BS Psychology</option>
                        </select>
                        <select name="section" onchange="this.form.submit()" class="bg-white text-black rounded px-2 py-1 text-xs">
                            <option value="">All Sections</option>
                            <?php
                            $sections = $pdo->query("SELECT DISTINCT section FROM students")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>" <?= $section_filter == $sec ? 'selected' : '' ?>><?= htmlspecialchars($sec) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white text-xs md:text-sm">
                        <thead class="text-gray-800 font-bold border-b">
                            <tr>
                                <th class="py-3 px-3 text-left">Student No.</th>
                                <th class="py-3 px-3 text-left">Name</th>
                                <th class="py-3 px-3 text-center">Att</th>
                                <th class="py-3 px-3 text-center">Mod</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600">
                            <?php foreach ($studentProgress as $sp): ?>
                                <tr class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="py-2 px-3 font-mono text-xs"><?= htmlspecialchars($sp['student_id']) ?></td>
                                    <td class="py-2 px-3 font-semibold text-xs"><?= htmlspecialchars($sp['full_name']) ?></td>
                                    <td class="py-2 px-3">
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs w-12 text-right"><?= $sp['attended'] ?>/<?= $sp['total_reg'] ?></span>
                                            <?= progressBar($sp['att_pct'], $sp['att_pct'] >= 100 ? '#10b981' : ($sp['att_pct'] > 0 ? '#f59e0b' : '#e5e7eb')) ?>
                                        </div>
                                    </td>
                                    <td class="py-2 px-3">
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs w-12 text-right"><?= $sp['completed_mod'] ?>/<?= $sp['total_mod'] ?></span>
                                            <?= progressBar($sp['mod_pct'], $sp['mod_pct'] >= 100 ? '#10b981' : ($sp['mod_pct'] > 0 ? '#0a3e6d' : '#e5e7eb')) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- Modal for Subtopic Breakdown -->
<div class="modal fade" id="subtopicBreakdownModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-green-700 text-white">
                <h5 class="modal-title">Subtopic Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="subtopicBreakdownBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let allSessionsData = {};
const chartInstances = {};
const modalChartInstances = {};

async function loadAllSessionCharts() {
    const container = document.getElementById('sessionChartsContainer');
    container.innerHTML = '<p class="text-gray-500 col-span-full text-center py-4">Loading sessions...</p>';
    try {
        const sessionsRes = await fetch('../api/get_sessions.php');
        const sessions = await sessionsRes.json();
        if (sessions.length === 0) {
            container.innerHTML = '<p class="text-gray-500 col-span-full text-center py-4">No sessions found.</p>';
            return;
        }
        container.innerHTML = '';
        for (const session of sessions) {
            const dataRes = await fetch(`../api/get_attendance_summary.php?session_id=${session.session_id}`);
            const data = await dataRes.json();
            allSessionsData[session.session_id] = { ...data, title: session.title };

            const card = document.createElement('div');
            card.className = 'border rounded-lg p-3 hover:shadow-md transition cursor-pointer';
            card.setAttribute('data-session-id', session.session_id);

            card.innerHTML = `
                <h4 class="font-medium text-sm text-gray-700 mb-2 truncate">${session.title}</h4>
                <div class="flex justify-center gap-2 mb-3">
                    <button class="tab-btn text-xs font-semibold text-gray-500 pb-1" data-tab="registration" data-session="${session.session_id}">Registration</button>
                    <button class="tab-btn active text-xs font-semibold text-green-700 pb-1" data-tab="attendance" data-session="${session.session_id}">Attendance</button>
                </div>
                <div style="height:160px; display:flex; align-items:center; justify-content:center;">
                    <canvas id="chart-${session.session_id}"></canvas>
                </div>
                <div id="stats-${session.session_id}" class="text-xs text-gray-700 mt-3 space-y-1"></div>
            `;
            container.appendChild(card);

            renderChart(session.session_id, 'attendance');

            card.querySelectorAll('.tab-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const sessionId = e.target.getAttribute('data-session');
                    const tab = e.target.getAttribute('data-tab');
                    card.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active', 'text-green-700'));
                    e.target.classList.add('active', 'text-green-700');
                    renderChart(sessionId, tab);
                });
            });

            card.addEventListener('click', (e) => {
                if (e.target.closest('.tab-btn')) return;
                const activeTab = card.querySelector('.tab-btn.active').getAttribute('data-tab');
                showSubtopicBreakdown(session.session_id, activeTab);
            });
        }
    } catch (error) {
        container.innerHTML = '<p class="text-red-500 col-span-full text-center py-4">Failed to load attendance data.</p>';
    }
}

function renderChart(sessionId, tab) {
    const data = allSessionsData[sessionId];
    if (!data) return;
    const canvas = document.getElementById(`chart-${sessionId}`);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    if (chartInstances[sessionId]) {
        chartInstances[sessionId].destroy();
    }

    let chartData;
    let statsHtml = '';
    if (tab === 'registration') {
        const reg = data.registration.registered;
        const unreg = data.registration.unregistered;
        const total = reg + unreg;
        chartData = {
            labels: ['Reg', 'Unreg'],
            datasets: [{
                data: [reg, unreg],
                backgroundColor: ['#10b981', '#e5e7eb'],
                borderColor: '#fff',
                borderWidth: 2
            }]
        };
        const regPct = total > 0 ? Math.round((reg / total) * 100) : 0;
        statsHtml = `
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span> Registered: ${reg} (${regPct}%)</div>
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-gray-300 inline-block"></span> Unregistered: ${unreg} (${100 - regPct}%)</div>
        `;
    } else {
        const present = data.attendance.present || 0;
        const absent = data.attendance.absent || 0;
        const pending = data.attendance.pending || 0;
        const total = present + absent + pending;
        chartData = {
            labels: ['Pres', 'Abs', 'Pend'],
            datasets: [{
                data: [present, absent, pending],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderColor: '#fff',
                borderWidth: 2
            }]
        };
        const presentPct = total > 0 ? Math.round((present / total) * 100) : 0;
        const absentPct = total > 0 ? Math.round((absent / total) * 100) : 0;
        const pendingPct = total > 0 ? Math.round((pending / total) * 100) : 0;
        statsHtml = `
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span> Present: ${present} (${presentPct}%)</div>
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-red-500 inline-block"></span> Absent: ${absent} (${absentPct}%)</div>
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-yellow-500 inline-block"></span> Pending: ${pending} (${pendingPct}%)</div>
        `;
    }

    document.getElementById(`stats-${sessionId}`).innerHTML = statsHtml;

    chartInstances[sessionId] = new Chart(ctx, {
        type: 'pie',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: true,
            layout: { padding: 0 },
            plugins: { legend: { display: false } }
        }
    });
}

function showSubtopicBreakdown(sessionId, tab) {
    const data = allSessionsData[sessionId];
    if (!data) return;
    const title = data.title || 'Session';
    let html = `<h6 class="mb-3">${title} – ${tab === 'registration' ? 'Registration by Subtopic' : 'Attendance by Subtopic'}</h6>`;
    const subtopics = tab === 'registration' ? data.subtopics_reg : data.subtopics_att;

    if (!subtopics || subtopics.length === 0) {
        html += '<p class="text-gray-500">No data available.</p>';
    } else {
        html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3">';
        subtopics.forEach((sub, idx) => {
            const canvasId = `modal-chart-${sessionId}-${idx}`;
            html += `<div class="border rounded p-2">
                <div class="font-medium text-sm">${sub.title}</div>
                <div style="height:120px;"><canvas id="${canvasId}"></canvas></div>
            </div>`;
        });
        html += '</div>';
    }

    document.getElementById('subtopicBreakdownBody').innerHTML = html;

    Object.keys(modalChartInstances).forEach(id => {
        if (modalChartInstances[id]) modalChartInstances[id].destroy();
    });

    if (subtopics && subtopics.length > 0) {
        subtopics.forEach((sub, idx) => {
            const canvasId = `modal-chart-${sessionId}-${idx}`;
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            let pieData;
            if (tab === 'registration') {
                const reg = sub.registered || 0;
                const unreg = (data.registration.total || 0) - reg;
                pieData = {
                    labels: ['Registered', 'Unregistered'],
                    datasets: [{
                        data: [reg, unreg],
                        backgroundColor: ['#10b981', '#e5e7eb'],
                        borderColor: '#fff',
                        borderWidth: 1
                    }]
                };
            } else {
                const present = sub.present || 0;
                const absent = sub.absent || 0;
                const pending = sub.pending || 0;
                pieData = {
                    labels: ['Present', 'Absent', 'Pending'],
                    datasets: [{
                        data: [present, absent, pending],
                        backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                        borderColor: '#fff',
                        borderWidth: 1
                    }]
                };
            }

            const ctx = canvas.getContext('2d');
            modalChartInstances[canvasId] = new Chart(ctx, {
                type: 'pie',
                data: pieData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: 0 },
                    plugins: { legend: { display: false } }
                }
            });
        });
    }

    new bootstrap.Modal(document.getElementById('subtopicBreakdownModal')).show();
}

document.addEventListener('DOMContentLoaded', () => {
    loadAllSessionCharts();
});
</script>
</body>
</html>