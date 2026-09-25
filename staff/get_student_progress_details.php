<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$student_id = $_GET['student_id'] ?? '';
if (!$student_id) {
    echo '<div class="alert alert-danger">Invalid student ID.</div>';
    exit;
}

// Get all sessions with subtopics and attendance status for this student
$sql = "
    SELECT s.title as session_title, sub.title as subtopic_title,
           a.attendance_status, a.attendance_date
    FROM registrations r
    JOIN sessions s ON r.session_id = s.session_id
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
    LEFT JOIN attendance a ON a.student_id = r.student_id 
        AND a.session_id = r.session_id 
        AND a.subtopic_id = r.subtopic_id
    WHERE r.student_id = ?
    ORDER BY s.date DESC, sub.title
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$student_id]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo '<div class="alert alert-info">No registrations found for this student.</div>';
    exit;
}

// Group by session
$sessions = [];
foreach ($rows as $row) {
    $sess = $row['session_title'];
    if (!isset($sessions[$sess])) {
        $sessions[$sess] = [];
    }
    $sessions[$sess][] = $row;
}
?>
<div class="table-responsive">
    <table class="table table-sm table-bordered">
        <thead>
            <tr>
                <th>Session</th>
                <th>Subtopic</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $session_name => $subtopics): ?>
                <?php $first = true; ?>
                <?php foreach ($subtopics as $sub): ?>
                    <tr>
                        <?php if ($first): ?>
                            <td rowspan="<?= count($subtopics) ?>"><?= htmlspecialchars($session_name) ?></td>
                            <?php $first = false; ?>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($sub['subtopic_title']) ?></td>
                        <td>
                            <?php
                            $status = $sub['attendance_status'] ?? 'not recorded';
                            if ($status == 'present') echo '<span class="badge bg-success">Present</span>';
                            elseif ($status == 'pending') echo '<span class="badge bg-warning">Pending</span>';
                            elseif ($status == 'absent') echo '<span class="badge bg-danger">Absent</span>';
                            else echo '<span class="badge bg-secondary">Not recorded</span>';
                            ?>
                        </td>
                        <td><?= $sub['attendance_date'] ? date('Y-m-d', strtotime($sub['attendance_date'])) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>