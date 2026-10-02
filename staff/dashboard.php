<?php
/**
 * ============================================================
 * ACES System — Staff Dashboard
 * ============================================================
 * - Overview stats
 * - Session Analytics (pie charts with tabs)
 * - Module Completion
 * - At-Risk Students
 * - Upcoming Sessions
 * - Quick Actions
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

// ---------- USER ----------
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'] ?? 'Staff';
$first_name = explode(' ', trim($staff_name))[0];

// ---------- OVERVIEW STATS ----------
$active_sessions = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE is_deleted = 0")->fetchColumn();
$total_students  = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0")->fetchColumn();

$att_present = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE attendance_status = 'present' AND is_deleted = 0")->fetchColumn();
$att_total   = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE is_deleted = 0")->fetchColumn();
$overall_att_pct = $att_total > 0 ? round(($att_present / $att_total) * 100) : 0;

$total_submissions = (int) $pdo->query("
    SELECT COUNT(*) FROM registrations r
    JOIN modules m ON m.subtopic_id = r.subtopic_id
    WHERE r.status IN ('assigned','auto_assigned') AND m.is_deleted = 0
")->fetchColumn();
$completed_submissions = (int) $pdo->query("SELECT COUNT(*) FROM student_module_progress WHERE completed = 1")->fetchColumn();
$overall_mod_pct = $total_submissions > 0 ? round(($completed_submissions / $total_submissions) * 100) : 0;

// ---------- SESSION LIST FOR CHARTS ----------
$sessions_for_charts = $pdo->query("
    SELECT session_id, title, phase, date
    FROM sessions
    WHERE is_deleted = 0
    ORDER BY date DESC, title
    LIMIT 6
")->fetchAll();

// ---------- AT-RISK STUDENTS ----------
$at_risk_students = $pdo->query("
    SELECT 
        s.student_id, s.program, s.section, u.full_name,
        COUNT(DISTINCT r.subtopic_id) AS reg_count,
        COALESCE(SUM(CASE WHEN a.attendance_status = 'present' THEN 1 ELSE 0 END), 0) AS present_count
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN registrations r ON r.student_id = s.student_id
    LEFT JOIN attendance a ON a.student_id = s.student_id AND a.subtopic_id = r.subtopic_id
    WHERE s.is_deleted = 0
    GROUP BY s.student_id, s.program, s.section, u.full_name
    HAVING COUNT(DISTINCT r.subtopic_id) > 0
    ORDER BY (COALESCE(SUM(CASE WHEN a.attendance_status = 'present' THEN 1 ELSE 0 END), 0) / COUNT(DISTINCT r.subtopic_id)) ASC, u.full_name
    LIMIT 5
")->fetchAll();

foreach ($at_risk_students as &$s) {
    $s['progress_pct'] = $s['reg_count'] > 0
        ? round(($s['present_count'] / $s['reg_count']) * 100)
        : 0;
    $s['initials'] = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', array_slice(explode(' ', $s['full_name']), 0, 2))));
}
unset($s);

// ---------- UPCOMING SESSIONS ----------
$upcoming = $pdo->query("
    SELECT session_id, title, phase, date
    FROM sessions
    WHERE is_deleted = 0 AND date >= CURDATE()
    ORDER BY date ASC
    LIMIT 3
")->fetchAll();

// ---------- RECENT MODULES ----------
$modules_summary = $pdo->query("
    SELECT 
        m.module_id, m.title, m.type,
        COUNT(DISTINCT r.student_id) AS student_count,
        COUNT(DISTINCT CASE WHEN sp.completed = 1 THEN sp.student_id END) AS completed_count
    FROM modules m
    LEFT JOIN subtopics st ON st.subtopic_id = m.subtopic_id
    LEFT JOIN registrations r ON r.subtopic_id = st.subtopic_id
    LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = r.student_id
    WHERE m.is_deleted = 0
    GROUP BY m.module_id
    ORDER BY m.created_at DESC
    LIMIT 5
")->fetchAll();

foreach ($modules_summary as &$m) {
    $m['pct'] = $m['student_count'] > 0
        ? round(($m['completed_count'] / $m['student_count']) * 100)
        : 0;
}
unset($m);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | ACES Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #0a6e2d;
            --primary-dark: #054018;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-600: #6b7280;
            --neutral-800: #1f2937;
        }

        html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Stat Cards */
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px 22px;
            border: 1px solid var(--neutral-200);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); transform: translateY(-1px); }
        .stat-icon {
            width: 46px; height: 46px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .stat-value { font-size: 24px; font-weight: 700; color: var(--neutral-800); line-height: 1; }
        .stat-label { font-size: 11px; color: var(--neutral-600); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; font-weight: 600; }

        /* Panels */
        .panel {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--neutral-200);
            overflow: hidden;
        }
        .panel-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--neutral-100);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .panel-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--neutral-800);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Progress Bars */
        .progress-track {
            height: 6px;
            background: var(--neutral-100);
            border-radius: 9999px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.4s ease;
        }
        .progress-fill.green { background: var(--success); }
        .progress-fill.amber { background: var(--warning); }
        .progress-fill.red   { background: var(--danger); }
        .progress-fill.blue  { background: #3b82f6; }

        /* Phase Badges */
        .phase-badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .phase-Preparation    { background: #dbeafe; color: #1e40af; }
        .phase-Pre-Employment { background: #fef3c7; color: #92400e; }
        .phase-Career-Fair    { background: #f3e8ff; color: #6b21a8; }

        /* At-Risk Row */
        .at-risk-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--neutral-100);
            transition: background 0.15s;
        }
        .at-risk-row:last-child { border-bottom: none; }
        .at-risk-row:hover { background: var(--neutral-50); }
        .at-risk-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
            font-size: 12px;
            flex-shrink: 0;
        }

        /* Quick Actions */
        .quick-action {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid var(--neutral-200);
            transition: all 0.15s;
            text-decoration: none;
            color: var(--neutral-800);
            font-size: 13px;
            font-weight: 500;
        }
        .quick-action:hover { border-color: var(--primary); color: var(--primary); background: #f0fdf4; }
        .quick-action-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        /* ============================================
           SESSION ANALYTICS — PIE CHART CARDS
           ============================================ */
        .analytics-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }
        .analytics-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 20px rgba(10,110,45,0.08);
            transform: translateY(-2px);
        }
        .analytics-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--neutral-100);
        }
        .analytics-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--neutral-800);
            line-height: 1.3;
            margin-bottom: 6px;
        }
        .analytics-meta {
            font-size: 11px;
            color: var(--neutral-600);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Tab Switcher */
        .chart-tabs {
            display: flex;
            gap: 4px;
            background: var(--neutral-100);
            padding: 3px;
            border-radius: 8px;
            margin: 14px 18px 0;
        }
        .chart-tab {
            flex: 1;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            color: var(--neutral-600);
            background: transparent;
            border: none;
            transition: all 0.15s;
        }
        .chart-tab.active {
            background: #fff;
            color: var(--primary);
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .chart-tab:hover:not(.active) {
            color: var(--neutral-800);
        }

        /* Chart Canvas */
        .chart-canvas-wrap {
            padding: 12px 18px;
            height: 180px;
            position: relative;
        }

        /* Legend */
        .chart-legend {
            padding: 0 18px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            color: var(--neutral-600);
        }
        .legend-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .legend-value {
            font-weight: 600;
            color: var(--neutral-800);
        }

        /* Card Footer */
        .analytics-footer {
            padding: 12px 18px;
            background: var(--neutral-50);
            border-top: 1px solid var(--neutral-100);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .btn-view-detail {
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
            background: transparent;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }
        .btn-view-detail:hover { color: #065f46; gap: 8px; }

        /* Modal */
        .modal-content { border-radius: 12px; border: none; overflow: hidden; }
        .modal-header-clean {
            padding: 20px 24px;
            background: var(--primary-dark);
            color: #fff;
            border: none;
        }
        .modal-body-clean {
            padding: 20px 24px;
            background: var(--neutral-50);
            max-height: 70vh;
            overflow-y: auto;
        }

        /* Subtopic mini-card inside modal */
        .subtopic-mini {
            background: #fff;
            border: 1px solid var(--neutral-200);
            border-radius: 10px;
            padding: 14px;
            transition: all 0.15s;
        }
        .subtopic-mini:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(10,110,45,0.06);
        }
        .subtopic-mini-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--neutral-800);
            margin-bottom: 10px;
            line-height: 1.3;
        }
        .subtopic-mini-chart {
            height: 120px;
            position: relative;
            margin-bottom: 10px;
        }
        .subtopic-mini-legend {
            display: flex;
            justify-content: center;
            gap: 12px;
            font-size: 11px;
            color: var(--neutral-600);
            flex-wrap: wrap;
        }

        /* Animations */
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.3s ease; }

        /* Responsive */
        @media (max-width: 768px) {
            .stat-card { padding: 14px 16px; }
            .stat-value { font-size: 20px; }
            .stat-icon { width: 40px; height: 40px; font-size: 15px; }
            .chart-canvas-wrap { height: 160px; }
        }
    </style>
</head>
<body class="bg-[#dcf3e6] h-screen flex flex-col md:flex-row overflow-hidden">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>

    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Welcome -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6 fade-in">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-[#0a6e2d]">Welcome back, <?= htmlspecialchars($first_name) ?>! 👋</h1>
                <p class="text-sm text-gray-500 mt-1">Here's what's happening with ACES today.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="student_progress.php"
                   class="bg-white hover:bg-gray-50 border border-gray-300 text-gray-800 text-sm font-semibold px-4 py-2 rounded-lg inline-flex items-center gap-2 transition">
                    <i class="fas fa-chart-line text-[#0a6e2d]"></i> View Student Progress
                </a>
                <a href="subtopics.php"
                   class="bg-[#0a6e2d] hover:bg-green-800 text-white text-sm font-semibold px-4 py-2 rounded-lg inline-flex items-center gap-2 transition">
                    <i class="fas fa-plus"></i> Create Session
                </a>
            </div>
        </div>

        <!-- ============================================
             OVERVIEW STATS
             ============================================ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8 fade-in">
            <div class="stat-card">
                <div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-calendar-alt"></i></div>
                <div>
                    <div class="stat-value"><?= $active_sessions ?></div>
                    <div class="stat-label">Active Sessions</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-value"><?= $total_students ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-user-check"></i></div>
                <div>
                    <div class="stat-value"><?= $overall_att_pct ?>%</div>
                    <div class="stat-label">Attendance</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-amber-100 text-amber-600"><i class="fas fa-book-open"></i></div>
                <div>
                    <div class="stat-value"><?= $overall_mod_pct ?>%</div>
                    <div class="stat-label">Modules</div>
                </div>
            </div>
        </div>

        <!-- ============================================
             SESSION ANALYTICS (PIE CHARTS)
             ============================================ -->
        <div class="panel mb-6 fade-in">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fas fa-chart-pie text-[#0a6e2d]"></i> Session Analytics
                </div>
                <a href="student_progress.php" class="text-xs text-[#0a6e2d] font-semibold hover:underline">
                    Full Progress View <i class="fas fa-arrow-right text-[10px] ml-1"></i>
                </a>
            </div>

            <?php if (empty($sessions_for_charts)): ?>
                <div class="p-12 text-center text-gray-400">
                    <i class="fas fa-chart-pie text-4xl mb-3"></i>
                    <p>No sessions to analyze yet.</p>
                </div>
            <?php else: ?>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" id="analyticsGrid">
                        <?php foreach ($sessions_for_charts as $sess):
                            $phaseKey = str_replace(' ', '-', $sess['phase']);
                        ?>
                            <div class="analytics-card fade-in"
                                 data-session-id="<?= $sess['session_id'] ?>"
                                 data-title="<?= htmlspecialchars($sess['title']) ?>"
                                 data-phase="<?= htmlspecialchars($sess['phase']) ?>">

                                <!-- Header -->
                                <div class="analytics-header">
                                    <div class="analytics-title"><?= htmlspecialchars($sess['title']) ?></div>
                                    <div class="analytics-meta">
                                        <span class="phase-badge phase-<?= $phaseKey ?>"><?= htmlspecialchars($sess['phase']) ?></span>
                                        <span><i class="far fa-calendar mr-1"></i><?= date('M d, Y', strtotime($sess['date'])) ?></span>
                                    </div>
                                </div>

                                <!-- Tabs -->
                                <div class="chart-tabs">
                                    <button class="chart-tab active" data-tab="attendance" data-session="<?= $sess['session_id'] ?>">
                                        <i class="fas fa-user-check mr-1"></i>Attendance
                                    </button>
                                    <button class="chart-tab" data-tab="registration" data-session="<?= $sess['session_id'] ?>">
                                        <i class="fas fa-user-plus mr-1"></i>Registration
                                    </button>
                                </div>

                                <!-- Chart -->
                                <div class="chart-canvas-wrap">
                                    <canvas id="chart-<?= $sess['session_id'] ?>"></canvas>
                                </div>

                                <!-- Legend -->
                                <div class="chart-legend" id="legend-<?= $sess['session_id'] ?>">
                                    <div class="legend-item text-xs text-gray-400">
                                        <span>Loading…</span>
                                    </div>
                                </div>

                                <!-- Footer -->
                                <div class="analytics-footer">
                                    <span class="text-xs text-gray-500" id="total-<?= $sess['session_id'] ?>">
                                        —
                                    </span>
                                    <button class="btn-view-detail"
                                            data-session-id="<?= $sess['session_id'] ?>"
                                            data-title="<?= htmlspecialchars($sess['title']) ?>"
                                            data-phase="<?= htmlspecialchars($sess['phase']) ?>">
                                        View Subtopic Breakdown <i class="fas fa-arrow-right text-[10px]"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================
             MAIN GRID
             ============================================ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

            <!-- LEFT (2/3): Recent Modules -->
            <div class="lg:col-span-2 space-y-6">
                <div class="panel fade-in">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-book text-[#0a6e2d]"></i> Recent Modules
                        </div>
                        <a href="modules.php" class="text-xs text-[#0a6e2d] font-semibold hover:underline">
                            Manage <i class="fas fa-arrow-right text-[10px] ml-1"></i>
                        </a>
                    </div>
                    <div class="p-4">
                        <?php if (empty($modules_summary)): ?>
                            <div class="text-center py-8 text-gray-400">
                                <i class="fas fa-book text-3xl mb-2"></i>
                                <p class="text-sm">No modules created yet.</p>
                                <a href="modules.php" class="text-xs text-[#0a6e2d] font-semibold hover:underline mt-2 inline-block">
                                    Create your first module →
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($modules_summary as $m):
                                    $pct = $m['pct'];
                                    $color = $pct >= 75 ? 'green' : ($pct >= 40 ? 'amber' : 'blue');
                                ?>
                                    <div>
                                        <div class="flex items-center justify-between text-sm mb-1.5">
                                            <span class="font-medium text-gray-700 truncate pr-2">
                                                <?= htmlspecialchars($m['title']) ?>
                                                <span class="text-[10px] text-gray-400 font-normal ml-1">
                                                    (<?= $m['type'] ?>)
                                                </span>
                                            </span>
                                            <span class="text-xs text-gray-500 whitespace-nowrap">
                                                <?= (int)$m['completed_count'] ?>/<?= (int)$m['student_count'] ?>
                                            </span>
                                        </div>
                                        <div class="progress-track">
                                            <div class="progress-fill <?= $color ?>" style="width: <?= $pct ?>%"></div>
                                        </div>
                                        <div class="text-[11px] text-gray-400 mt-1"><?= $pct ?>% complete</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT (1/3): At-Risk, Upcoming, Quick Actions -->
            <div class="space-y-6">

                <!-- At-Risk -->
                <div class="panel fade-in">
                    <div class="panel-header">
                        <div class="panel-title text-red-600">
                            <i class="fas fa-exclamation-triangle"></i> At-Risk Students
                        </div>
                        <span class="text-xs text-gray-400"><?= count($at_risk_students) ?></span>
                    </div>
                    <?php if (empty($at_risk_students)): ?>
                        <div class="text-center py-8 text-gray-400">
                            <i class="fas fa-check-circle text-3xl text-green-400 mb-2"></i>
                            <p class="text-sm">Everyone is on track! 🎉</p>
                        </div>
                    <?php else: ?>
                        <div>
                            <?php foreach ($at_risk_students as $ar):
                                $pct = $ar['progress_pct'];
                                $color = $pct >= 75 ? 'green' : ($pct >= 40 ? 'amber' : 'red');
                            ?>
                                <div class="at-risk-row">
                                    <div class="at-risk-avatar"><?= $ar['initials'] ?></div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-gray-800 truncate">
                                            <?= htmlspecialchars($ar['full_name']) ?>
                                        </div>
                                        <div class="text-[11px] text-gray-500 truncate">
                                            <?= htmlspecialchars($ar['program']) ?> · <?= htmlspecialchars($ar['section']) ?>
                                        </div>
                                        <div class="progress-track mt-1.5">
                                            <div class="progress-fill <?= $color ?>" style="width: <?= $pct ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="text-sm font-bold text-red-600 whitespace-nowrap"><?= $pct ?>%</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="px-4 py-3 border-t border-gray-100 bg-gray-50">
                            <a href="student_progress.php" class="text-xs text-[#0a6e2d] font-semibold hover:underline w-full text-center block">
                                View all students →
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Upcoming -->
                <div class="panel fade-in">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-clock text-[#0a6e2d]"></i> Upcoming
                        </div>
                        <a href="calendar.php" class="text-xs text-[#0a6e2d] font-semibold hover:underline">
                            Calendar
                        </a>
                    </div>
                    <?php if (empty($upcoming)): ?>
                        <div class="text-center py-6 text-gray-400">
                            <p class="text-sm">No upcoming sessions.</p>
                        </div>
                    <?php else: ?>
                        <div class="p-3 space-y-2">
                            <?php foreach ($upcoming as $u):
                                $phaseKey = str_replace(' ', '-', $u['phase']);
                                $daysLeft = (int)ceil((strtotime($u['date']) - time()) / 86400);
                            ?>
                                <div class="p-3 bg-gray-50 rounded-lg border border-gray-100 hover:border-green-200 transition">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <div class="text-sm font-semibold text-gray-800 flex-1 min-w-0 truncate">
                                            <?= htmlspecialchars($u['title']) ?>
                                        </div>
                                        <span class="text-[10px] font-bold text-[#0a6e2d] whitespace-nowrap">
                                            <?= $daysLeft > 0 ? "in $daysLeft d" : 'today' ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="phase-badge phase-<?= $phaseKey ?>">
                                            <?= htmlspecialchars($u['phase']) ?>
                                        </span>
                                        <span class="text-[11px] text-gray-500">
                                            <?= date('M d', strtotime($u['date'])) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions (Admin Only) -->
                <?php if (isAdmin()): ?>
                <div class="panel fade-in">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-bolt text-[#0a6e2d]"></i> Quick Actions
                        </div>
                    </div>
                    <div class="p-3 space-y-2">
                        <button onclick="runAutoAssign()" class="quick-action w-full text-left">
                            <div class="quick-action-icon bg-amber-100 text-amber-600"><i class="fas fa-magic"></i></div>
                            <div class="flex-1">
                                <div class="font-semibold">Auto-Assign Now</div>
                                <div class="text-[11px] text-gray-500">Assign unregistered students</div>
                            </div>
                            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
                        </button>
                        <button onclick="runPurgeExcess()" class="quick-action w-full text-left">
                            <div class="quick-action-icon bg-red-100 text-red-600"><i class="fas fa-broom"></i></div>
                            <div class="flex-1">
                                <div class="font-semibold">Purge Excess</div>
                                <div class="text-[11px] text-gray-500">Remove over-capacity registrations</div>
                            </div>
                            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
                        </button>
                        <a href="manage_staff.php" class="quick-action">
                            <div class="quick-action-icon bg-blue-100 text-blue-600"><i class="fas fa-user-shield"></i></div>
                            <div class="flex-1">
                                <div class="font-semibold">Manage Staff</div>
                                <div class="text-[11px] text-gray-500">Roles & permissions</div>
                            </div>
                            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </main>
</div>

<!-- ============================================
     SUBTOPIC BREAKDOWN MODAL
     ============================================ -->
<div class="modal fade" id="subtopicModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header-clean">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <h2 class="text-lg font-bold mb-1" id="modalTitle">Session</h2>
                        <div class="flex items-center gap-2">
                            <span class="phase-badge" id="modalPhase" style="background: rgba(255,255,255,0.2); color: #fff;"></span>
                            <span class="text-xs opacity-75" id="modalDate"></span>
                        </div>
                    </div>
                    <button type="button" class="text-white/80 hover:text-white" data-bs-dismiss="modal">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <!-- Tab switcher inside modal -->
                <div class="chart-tabs mt-4" style="background: rgba(255,255,255,0.15);">
                    <button class="chart-tab active" data-modal-tab="attendance" style="color: rgba(255,255,255,0.7);">
                        <i class="fas fa-user-check mr-1"></i>Attendance
                    </button>
                    <button class="chart-tab" data-modal-tab="registration" style="color: rgba(255,255,255,0.7);">
                        <i class="fas fa-user-plus mr-1"></i>Registration
                    </button>
                </div>
            </div>
            <div class="modal-body-clean" id="modalBody">
                <div class="text-center py-12 text-gray-400">
                    <i class="fas fa-spinner fa-spin text-3xl mb-3"></i>
                    <p>Loading breakdown…</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';

// ============================================
// CHART STATE
// ============================================
const allSessionsData = {};  // sessionId -> { registration, attendance, subtopics_reg, subtopics_att, title }
const sessionCharts = {};    // sessionId -> Chart instance (session card chart)
let currentModalCharts = []; // Chart instances in modal (destroyed on close)
let currentModalSessionId = null;
let currentModalTab = 'attendance';

// ============================================
// LOAD ALL SESSION DATA + RENDER CARDS
// ============================================
async function loadAllSessionCharts() {
    const cards = document.querySelectorAll('.analytics-card');

    for (const card of cards) {
        const sessionId = card.dataset.sessionId;
        try {
            const res = await fetch(`../api/get_attendance_summary.php?session_id=${sessionId}`);
            const data = await res.json();

            allSessionsData[sessionId] = {
                ...data,
                title: card.dataset.title,
                phase: card.dataset.phase,
            };

            renderCardChart(sessionId, 'attendance');
        } catch (err) {
            console.error(`Failed to load session ${sessionId}:`, err);
            card.querySelector('.chart-canvas-wrap').innerHTML =
                '<div class="text-center text-red-500 text-xs py-8">Failed to load</div>';
        }
    }
}

// ============================================
// RENDER SESSION CARD CHART
// ============================================
function renderCardChart(sessionId, tab) {
    const data = allSessionsData[sessionId];
    if (!data) return;

    const canvas = document.getElementById(`chart-${sessionId}`);
    if (!canvas) return;

    // Destroy old chart
    if (sessionCharts[sessionId]) {
        sessionCharts[sessionId].destroy();
    }

    let chartData, legendHtml, totalText;

    if (tab === 'registration') {
        const reg   = data.registration?.registered   ?? 0;
        const unreg = data.registration?.unregistered ?? 0;
        const total = reg + unreg;
        const regPct = total > 0 ? Math.round((reg / total) * 100) : 0;

        chartData = {
            labels: ['Registered', 'Unregistered'],
            datasets: [{
                data: [reg, unreg],
                backgroundColor: ['#10b981', '#e5e7eb'],
                borderWidth: 0,
                hoverOffset: 6,
            }]
        };

        legendHtml = `
            <div class="legend-item">
                <span><span class="legend-dot" style="background:#10b981"></span>Registered</span>
                <span class="legend-value">${reg} (${regPct}%)</span>
            </div>
            <div class="legend-item">
                <span><span class="legend-dot" style="background:#e5e7eb"></span>Unregistered</span>
                <span class="legend-value">${unreg} (${100 - regPct}%)</span>
            </div>`;

        totalText = `${total} students total`;

    } else {
        const present = data.attendance?.present ?? 0;
        const absent  = data.attendance?.absent  ?? 0;
        const pending = data.attendance?.pending ?? 0;
        const total = present + absent + pending;
        const pPct = total > 0 ? Math.round((present / total) * 100) : 0;
        const aPct = total > 0 ? Math.round((absent / total) * 100) : 0;
        const qPct = total > 0 ? Math.round((pending / total) * 100) : 0;

        chartData = {
            labels: ['Present', 'Absent', 'Pending'],
            datasets: [{
                data: [present, absent, pending],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderWidth: 0,
                hoverOffset: 6,
            }]
        };

        legendHtml = `
            <div class="legend-item">
                <span><span class="legend-dot" style="background:#10b981"></span>Present</span>
                <span class="legend-value">${present} (${pPct}%)</span>
            </div>
            <div class="legend-item">
                <span><span class="legend-dot" style="background:#f59e0b"></span>Pending</span>
                <span class="legend-value">${pending} (${qPct}%)</span>
            </div>
            <div class="legend-item">
                <span><span class="legend-dot" style="background:#ef4444"></span>Absent</span>
                <span class="legend-value">${absent} (${aPct}%)</span>
            </div>`;

        totalText = `${total} attendance records`;
    }

    document.getElementById(`legend-${sessionId}`).innerHTML = legendHtml;
    document.getElementById(`total-${sessionId}`).textContent = totalText;

    sessionCharts[sessionId] = new Chart(canvas.getContext('2d'), {
        type: 'pie',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1f2937',
                    padding: 10,
                    cornerRadius: 6,
                    titleFont: { size: 12 },
                    bodyFont: { size: 12 },
                    callbacks: {
                        label: (ctx) => ` ${ctx.label}: ${ctx.raw}`
                    }
                }
            },
            animation: { animateRotate: true, duration: 700 },
        }
    });
}

// ============================================
// BIND TAB SWITCHERS ON CARDS
// ============================================
document.querySelectorAll('.chart-tab[data-session]').forEach(tab => {
    tab.addEventListener('click', function() {
        const sessionId = this.dataset.session;
        const tabType   = this.dataset.tab;

        // Toggle active class in same card
        this.closest('.chart-tabs').querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');

        renderCardChart(sessionId, tabType);
    });
});

// ============================================
// OPEN SUBTOPIC BREAKDOWN MODAL
// ============================================
document.querySelectorAll('.btn-view-detail').forEach(btn => {
    btn.addEventListener('click', function() {
        const sessionId = this.dataset.sessionId;
        const title     = this.dataset.title;
        const phase     = this.dataset.phase;

        openSubtopicModal(sessionId, title, phase);
    });
});

function openSubtopicModal(sessionId, title, phase) {
    currentModalSessionId = sessionId;
    currentModalTab = 'attendance';

    // Reset modal header
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalPhase').textContent = phase;
    document.getElementById('modalPhase').className = 'phase-badge phase-' + phase.replace(/ /g, '-');

    const data = allSessionsData[sessionId];
    const sessionDate = data && data.title ? (data.date || '') : '';
    document.getElementById('modalDate').textContent = '';

    // Reset modal tabs
    document.querySelectorAll('.chart-tab[data-modal-tab]').forEach(t => t.classList.remove('active'));
    document.querySelector('.chart-tab[data-modal-tab="attendance"]').classList.add('active');

    renderModalBody();

    // Show modal
    new bootstrap.Modal(document.getElementById('subtopicModal')).show();
}

// ============================================
// RENDER MODAL BODY (GRID OF SUBTOPIC PIE CHARTS)
// ============================================
function renderModalBody() {
    const data = allSessionsData[currentModalSessionId];
    if (!data) {
        document.getElementById('modalBody').innerHTML =
            '<div class="text-center text-gray-400 py-12">No data available.</div>';
        return;
    }

    const tab = currentModalTab;
    const subtopics = tab === 'registration' ? (data.subtopics_reg || []) : (data.subtopics_att || []);

    // Destroy old modal charts
    currentModalCharts.forEach(c => c.destroy());
    currentModalCharts = [];

    if (!subtopics.length) {
        document.getElementById('modalBody').innerHTML = `
            <div class="text-center text-gray-400 py-12">
                <i class="fas fa-chart-pie text-4xl mb-3"></i>
                <p>No subtopic data available for this view.</p>
            </div>`;
        return;
    }

    let html = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">';

    subtopics.forEach((sub, idx) => {
        const canvasId = `modal-chart-${currentModalSessionId}-${idx}`;
        let legendInner = '';

        if (tab === 'registration') {
            const reg = sub.registered || 0;
            legendInner = `
                <span><span class="legend-dot" style="background:#10b981"></span>${reg} reg.</span>
                <span><span class="legend-dot" style="background:#e5e7eb"></span>Rest</span>`;
        } else {
            const p = sub.present || 0;
            const a = sub.absent  || 0;
            const q = sub.pending || 0;
            legendInner = `
                <span><span class="legend-dot" style="background:#10b981"></span>${p}</span>
                <span><span class="legend-dot" style="background:#f59e0b"></span>${q}</span>
                <span><span class="legend-dot" style="background:#ef4444"></span>${a}</span>`;
        }

        html += `
            <div class="subtopic-mini">
                <div class="subtopic-mini-title">${escapeHtml(sub.title)}</div>
                <div class="subtopic-mini-chart"><canvas id="${canvasId}"></canvas></div>
                <div class="subtopic-mini-legend">${legendInner}</div>
            </div>`;
    });

    html += '</div>';
    document.getElementById('modalBody').innerHTML = html;

    // Now create the charts
    subtopics.forEach((sub, idx) => {
        const canvas = document.getElementById(`modal-chart-${currentModalSessionId}-${idx}`);
        if (!canvas) return;

        let chartData;
        if (tab === 'registration') {
            const reg = sub.registered || 0;
            const unreg = (data.registration?.total || 0) - reg;
            chartData = {
                labels: ['Registered', 'Unregistered'],
                datasets: [{
                    data: [reg, Math.max(0, unreg)],
                    backgroundColor: ['#10b981', '#e5e7eb'],
                    borderWidth: 0,
                }]
            };
        } else {
            const p = sub.present || 0;
            const a = sub.absent  || 0;
            const q = sub.pending || 0;
            chartData = {
                labels: ['Present', 'Absent', 'Pending'],
                datasets: [{
                    data: [p, a, q],
                    backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                    borderWidth: 0,
                }]
            };
        }

        const chart = new Chart(canvas.getContext('2d'), {
            type: 'pie',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                animation: { animateRotate: true, duration: 600 },
            }
        });
        currentModalCharts.push(chart);
    });
}

// ============================================
// MODAL TAB SWITCHER
// ============================================
document.querySelectorAll('.chart-tab[data-modal-tab]').forEach(tab => {
    tab.addEventListener('click', function() {
        currentModalTab = this.dataset.modalTab;

        document.querySelectorAll('.chart-tab[data-modal-tab]').forEach(t => {
            t.classList.remove('active');
            t.style.color = 'rgba(255,255,255,0.7)';
        });
        this.classList.add('active');
        this.style.color = '#fff';

        renderModalBody();
    });
});

// ============================================
// HELPERS
// ============================================
function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[m]);
}

function runAutoAssign() {
    if (!confirm('Auto-assign unregistered students for all upcoming sessions?\n\nThis may send emails to students.')) return;
    window.open('../cron/auto_assign.php?secret=<?= urlencode(aces_env('CRON_SECRET', '')) ?>', '_blank');
}

function runPurgeExcess() {
    if (!confirm('Remove registrations that exceed subtopic capacity?')) return;
    window.location.href = 'purge_excess_registrations.php';
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', loadAllSessionCharts);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 