<?php
/**
 * ============================================================
 * ACES System — Detailed Student Progress Report (HTML/Print)
 * ============================================================
 * POST-only, CSRF-protected. Generates a print-optimized HTML
 * report showing sessions -> subtopics -> per-student rows with
 * attendance status + module completion.
 *
 * Accepts:
 *   ids        (comma-separated student IDs)
 *   csrf_token (required)
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

// ---- POST only ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

csrf_verify();

$ids_raw = $_POST['ids'] ?? '';
$student_ids = array_values(array_filter(array_map('trim', explode(',', $ids_raw)), 'strlen'));

if (empty($student_ids)) {
    http_response_code(400);
    exit('No students selected.');
}

// ---- Fetch sessions ----
$sessions = $pdo->query("
    SELECT session_id, title
    FROM sessions
    WHERE is_deleted = 0
    ORDER BY date DESC
")->fetchAll();

// ---- Subtopics per session ----
$subtopics_by_session = [];
$all_subtopic_ids = [];
foreach ($sessions as $sess) {
    $stmt = $pdo->prepare("
        SELECT subtopic_id, title
        FROM subtopics
        WHERE session_id = ? AND is_deleted = 0
        ORDER BY title
    ");
    $stmt->execute([$sess['session_id']]);
    $subs = $stmt->fetchAll();
    $subtopics_by_session[$sess['session_id']] = $subs;
    foreach ($subs as $sub) $all_subtopic_ids[] = $sub['subtopic_id'];
}

// ---- Modules per subtopic ----
$modules_by_subtopic = [];
if (!empty($all_subtopic_ids)) {
    $ph = implode(',', array_fill(0, count($all_subtopic_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT subtopic_id, module_id, title, type, due_date
        FROM modules
        WHERE subtopic_id IN ($ph) AND is_deleted = 0
        ORDER BY due_date
    ");
    $stmt->execute($all_subtopic_ids);
    while ($row = $stmt->fetch()) {
        $modules_by_subtopic[$row['subtopic_id']][] = $row;
    }
}

// ---- Fetch students ----
$ph_stu = implode(',', array_fill(0, count($student_ids), '?'));
$stmt = $pdo->prepare("
    SELECT s.student_id, u.full_name, s.program, s.section
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id IN ($ph_stu) AND s.is_deleted = 0
    ORDER BY u.full_name
");
$stmt->execute($student_ids);
$students = $stmt->fetchAll();

if (empty($students)) {
    http_response_code(404);
    exit('No matching students.');
}

// ---- Fetch registrations, attendance, module progress ----
$registrations   = [];
$attendance      = [];
$module_progress = [];

if (!empty($all_subtopic_ids)) {
    $ph_sub = implode(',', array_fill(0, count($all_subtopic_ids), '?'));

    $reg_stmt = $pdo->prepare("
        SELECT student_id, subtopic_id
        FROM registrations
        WHERE student_id IN ($ph_stu) AND subtopic_id IN ($ph_sub)
    ");
    $reg_stmt->execute(array_merge($student_ids, $all_subtopic_ids));
    while ($row = $reg_stmt->fetch()) {
        $registrations[$row['student_id']][$row['subtopic_id']] = true;
    }

    $att_stmt = $pdo->prepare("
        SELECT student_id, subtopic_id, attendance_status
        FROM attendance
        WHERE student_id IN ($ph_stu) AND subtopic_id IN ($ph_sub)
    ");
    $att_stmt->execute(array_merge($student_ids, $all_subtopic_ids));
    while ($row = $att_stmt->fetch()) {
        $attendance[$row['student_id']][$row['subtopic_id']] = $row['attendance_status'];
    }

    $mod_stmt = $pdo->prepare("
        SELECT sp.student_id, m.subtopic_id, m.module_id, sp.completed
        FROM student_module_progress sp
        JOIN modules m ON sp.module_id = m.module_id
        WHERE sp.student_id IN ($ph_stu) AND m.subtopic_id IN ($ph_sub)
    ");
    $mod_stmt->execute(array_merge($student_ids, $all_subtopic_ids));
    while ($row = $mod_stmt->fetch()) {
        $module_progress[$row['student_id']][$row['subtopic_id']][$row['module_id']] = $row['completed'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed Student Progress Report</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 24px;
            color: #1f2937;
            background: #fff;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 20px;
            border-bottom: 3px solid #0a6e2d;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .header img { height: 60px; width: auto; }
        .header-content { flex: 1; }
        .header h1 {
            color: #0a6e2d;
            font-size: 22px;
            margin: 0 0 4px 0;
        }
        .header .subtitle {
            font-size: 13px;
            color: #6b7280;
        }
        .header .meta {
            text-align: right;
            font-size: 11px;
            color: #6b7280;
        }
        .header .meta strong { color: #0a6e2d; }

        .session-block {
            margin-bottom: 28px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            page-break-inside: avoid;
        }
        .session-title {
            background: #054018;
            color: #fff;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .subtopic {
            padding: 16px 18px;
            border-bottom: 1px solid #f3f4f6;
        }
        .subtopic:last-child { border-bottom: none; }
        .subtopic-title {
            font-weight: 700;
            font-size: 13px;
            color: #054018;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .subtopic-title::before {
            content: '';
            width: 4px;
            height: 16px;
            background: #10b981;
            border-radius: 2px;
        }

        .student-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .student-table th {
            background: #f9fafb;
            color: #374151;
            font-weight: 600;
            padding: 8px 10px;
            text-align: left;
            border: 1px solid #e5e7eb;
            font-size: 10.5px;
        }
        .student-table td {
            padding: 7px 10px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .student-table tbody tr:nth-child(even) td {
            background: #fafbfc;
        }
        .student-table tbody tr:hover td {
            background: #f0fdf4;
        }

        .status-present       { color: #065f46; font-weight: 600; }
        .status-pending       { color: #92400e; font-weight: 600; }
        .status-absent        { color: #991b1b; font-weight: 600; }
        .status-not-recorded  { color: #6b7280; font-style: italic; }
        .status-not-registered{ color: #9ca3af; font-style: italic; }

        .module-completed     { color: #065f46; font-weight: 600; }
        .module-not-completed { color: #991b1b; }

        .footer {
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #9ca3af;
            text-align: center;
        }

        .no-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            gap: 8px;
            z-index: 100;
        }
        .no-print button {
            background: #0a6e2d;
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.15s;
        }
        .no-print button:hover {
            background: #054018;
            transform: translateY(-1px);
        }
        .no-print button.secondary {
            background: #6b7280;
        }

        @media print {
            body { margin: 0; padding: 15px; }
            .no-print { display: none; }
            .session-block { page-break-inside: avoid; }
            .student-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .session-title { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="header">
        <?php if (file_exists(__DIR__ . '/../assets/images/kld_logo.png')): ?>
            <img src="../assets/images/kld_logo.png" alt="KLD">
        <?php endif; ?>
        <div class="header-content">
            <h1>Detailed Student Progress Report</h1>
            <div class="subtitle">Kolehiyo ng Lungsod ng Dasmariñas · ACES Unit</div>
        </div>
        <div class="meta">
            Generated: <strong><?= date('M d, Y g:i A') ?></strong><br>
            Students: <strong><?= count($students) ?></strong>
        </div>
        <?php if (file_exists(__DIR__ . '/../assets/images/aces_logo.png')): ?>
            <img src="../assets/images/aces_logo.png" alt="ACES">
        <?php endif; ?>
    </div>

    <!-- Body -->
    <?php $rendered_any = false; ?>
    <?php foreach ($sessions as $session): ?>
        <?php $subs = $subtopics_by_session[$session['session_id']] ?? []; ?>
        <?php if (empty($subs)) continue; ?>
        <?php $rendered_any = true; ?>

        <div class="session-block">
            <div class="session-title">
                <i>📅</i> <?= htmlspecialchars($session['title']) ?>
            </div>

            <?php foreach ($subs as $subtopic): ?>
                <?php $modules = $modules_by_subtopic[$subtopic['subtopic_id']] ?? []; ?>
                <div class="subtopic">
                    <div class="subtopic-title"><?= htmlspecialchars($subtopic['title']) ?></div>

                    <table class="student-table">
                        <thead>
                            <tr>
                                <th>Student No.</th>
                                <th>Student Name</th>
                                <th>Course</th>
                                <th>Section</th>
                                <th>Attendance</th>
                                <?php foreach ($modules as $mod): ?>
                                    <th title="<?= htmlspecialchars($mod['title']) ?> (<?= htmlspecialchars($mod['type']) ?>)">
                                        <?= htmlspecialchars($mod['title']) ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <?php
                                $student_id = $student['student_id'];
                                $is_registered = isset($registrations[$student_id][$subtopic['subtopic_id']]);
                                $att_status = $attendance[$student_id][$subtopic['subtopic_id']] ?? 'not_recorded';

                                if (!$is_registered) {
                                    $att_display = 'Not Registered';
                                    $att_class = 'status-not-registered';
                                } else {
                                    switch ($att_status) {
                                        case 'present': $att_display = 'Present'; $att_class = 'status-present'; break;
                                        case 'pending': $att_display = 'Pending'; $att_class = 'status-pending'; break;
                                        case 'absent':  $att_display = 'Absent';  $att_class = 'status-absent';  break;
                                        default:        $att_display = 'Not Recorded'; $att_class = 'status-not-recorded';
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($student_id) ?></td>
                                    <td><?= htmlspecialchars($student['full_name']) ?></td>
                                    <td><?= htmlspecialchars($student['program']) ?></td>
                                    <td><?= htmlspecialchars($student['section']) ?></td>
                                    <td class="<?= $att_class ?>"><?= $att_display ?></td>

                                    <?php foreach ($modules as $mod): ?>
                                        <?php
                                        $completed = !empty($module_progress[$student_id][$subtopic['subtopic_id']][$mod['module_id']]);
                                        $status_text  = $completed ? '✅ Completed' : '❌ Not done';
                                        $status_class = $completed ? 'module-completed' : 'module-not-completed';
                                        ?>
                                        <td class="<?= $status_class ?>"><?= $status_text ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$rendered_any): ?>
        <div style="text-align:center; padding:60px 20px; color:#6b7280;">
            <div style="font-size:48px; margin-bottom:12px;">📭</div>
            <p>No sessions or subtopics found to report on.</p>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="footer">
        ACES Unit · Kolehiyo ng Lungsod ng Dasmariñas · <?= date('Y') ?>
    </div>

    <!-- Print/Close buttons -->
    <div class="no-print">
        <button class="secondary" onclick="window.close();">Close</button>
        <button onclick="window.print();">🖨️ Print / Save PDF</button>
    </div>

    <script>
        // Auto-open print dialog shortly after load
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>