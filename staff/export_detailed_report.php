<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$student_ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
if (empty($student_ids)) {
    die('No students selected.');
}

// Fetch all data
$sessions = $pdo->query("SELECT session_id, title FROM sessions WHERE is_deleted = 0 ORDER BY date DESC")->fetchAll();

// Subtopics per session
$subtopics_by_session = [];
$all_subtopic_ids = [];
foreach ($sessions as $sess) {
    $stmt = $pdo->prepare("SELECT subtopic_id, title FROM subtopics WHERE session_id = ? AND is_deleted = 0 ORDER BY title");
    $stmt->execute([$sess['session_id']]);
    $subs = $stmt->fetchAll();
    $subtopics_by_session[$sess['session_id']] = $subs;
    foreach ($subs as $sub) $all_subtopic_ids[] = $sub['subtopic_id'];
}

// Modules per subtopic
$modules_by_subtopic = [];
if (!empty($all_subtopic_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_subtopic_ids), '?'));
    $stmt = $pdo->prepare("SELECT subtopic_id, module_id, title, type, due_date FROM modules WHERE subtopic_id IN ($placeholders) AND is_deleted = 0 ORDER BY due_date");
    $stmt->execute($all_subtopic_ids);
    while ($row = $stmt->fetch()) {
        $modules_by_subtopic[$row['subtopic_id']][] = $row;
    }
}

// Student data
$placeholders_students = implode(',', array_fill(0, count($student_ids), '?'));
$stmt = $pdo->prepare("
    SELECT s.student_id, u.full_name, s.program, s.section
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id IN ($placeholders_students)
    ORDER BY u.full_name
");
$stmt->execute($student_ids);
$students = $stmt->fetchAll();

// Registrations, attendance, module progress
$registrations = [];
$attendance = [];
$module_progress = [];

if (!empty($student_ids) && !empty($all_subtopic_ids)) {
    $reg_stmt = $pdo->prepare("SELECT student_id, subtopic_id FROM registrations WHERE student_id IN ($placeholders_students) AND subtopic_id IN ($placeholders)");
    $reg_stmt->execute(array_merge($student_ids, $all_subtopic_ids));
    while ($row = $reg_stmt->fetch()) {
        $registrations[$row['student_id']][$row['subtopic_id']] = true;
    }

    $att_stmt = $pdo->prepare("SELECT student_id, subtopic_id, attendance_status FROM attendance WHERE student_id IN ($placeholders_students) AND subtopic_id IN ($placeholders)");
    $att_stmt->execute(array_merge($student_ids, $all_subtopic_ids));
    while ($row = $att_stmt->fetch()) {
        $attendance[$row['student_id']][$row['subtopic_id']] = $row['attendance_status'];
    }

    $mod_stmt = $pdo->prepare("
        SELECT sp.student_id, m.subtopic_id, m.module_id, sp.completed
        FROM student_module_progress sp
        JOIN modules m ON sp.module_id = m.module_id
        WHERE sp.student_id IN ($placeholders_students) AND m.subtopic_id IN ($placeholders)
    ");
    $mod_stmt->execute(array_merge($student_ids, $all_subtopic_ids));
    while ($row = $mod_stmt->fetch()) {
        $module_progress[$row['student_id']][$row['subtopic_id']][$row['module_id']] = $row['completed'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Detailed Student Progress Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; font-size: 12px; }
        h1 { color: #0a3e6d; }
        .session-block { margin-bottom: 30px; border: 1px solid #ddd; border-radius: 8px; overflow: auto; }
        .session-title { background: #0a3e6d; color: white; padding: 10px; font-size: 1.2rem; }
        .subtopic { margin: 15px; border-left: 3px solid #0a3e6d; padding-left: 15px; }
        .subtopic-title { font-weight: bold; font-size: 1.1rem; margin-bottom: 10px; }
        .student-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 11px; }
        .student-table th, .student-table td { border: 1px solid #ddd; padding: 6px; text-align: left; vertical-align: top; }
        .student-table th { background: #f2f2f2; position: sticky; top: 0; }
        .status-present { color: green; font-weight: bold; }
        .status-pending { color: orange; }
        .status-absent { color: red; }
        .status-not-recorded { color: gray; }
        .status-not-registered { color: #aaa; font-style: italic; }
        .module-completed { color: green; }
        .module-not-completed { color: red; }
        .module-cell { font-size: 10px; }
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
            .student-table th { background: #f2f2f2; }
        }
    </style>
</head>
<body>
    <h1>Detailed Student Progress Report</h1>
    <p>Generated: <?= date('Y-m-d H:i:s') ?></p>
    <?php foreach ($sessions as $session): ?>
        <?php $subs = $subtopics_by_session[$session['session_id']] ?? []; ?>
        <?php if (empty($subs)) continue; ?>
        <div class="session-block">
            <div class="session-title"><?= htmlspecialchars($session['title']) ?></div>
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
                                    <th title="<?= htmlspecialchars($mod['title']) ?> (<?= $mod['type'] ?>)">
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
                                        case 'absent': $att_display = 'Absent'; $att_class = 'status-absent'; break;
                                        default: $att_display = 'Not Recorded'; $att_class = 'status-not-recorded';
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
                                        $completed = isset($module_progress[$student_id][$subtopic['subtopic_id']][$mod['module_id']]) && $module_progress[$student_id][$subtopic['subtopic_id']][$mod['module_id']] == 1;
                                        $status_text = $completed ? '✅ Completed' : '❌ Not completed';
                                        $status_class = $completed ? 'module-completed' : 'module-not-completed';
                                        ?>
                                        <td class="module-cell <?= $status_class ?>"><?= $status_text ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <div class="no-print" style="margin-top: 30px; text-align: center;">
        <button onclick="window.print();">Print / Save as PDF</button>
        <button onclick="window.close();">Close</button>
    </div>
</body>
</html>