<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$course = $_GET['course'] ?? '';
$section = $_GET['section'] ?? '';
$subtopic_id = (int)($_GET['subtopic_id'] ?? 0);

if (!$subtopic_id) {
    echo json_encode(['success' => false, 'error' => 'Please select a subtopic.']);
    exit;
}

$sql = "
    SELECT s.student_id, s.first_name, s.last_name, s.program, s.section 
    FROM students s
    JOIN attendance a ON s.student_id = a.student_id
    WHERE a.subtopic_id = ? AND a.attendance_status = 'absent' AND s.is_deleted = 0
";

$params = [$subtopic_id];

if (!empty($course)) {
    $sql .= " AND s.program = ?";
    $params[] = $course;
}
if (!empty($section)) {
    $sql .= " AND s.section = ?";
    $params[] = $section;
}

$sql .= " ORDER BY s.last_name, s.first_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

echo json_encode(['success' => true, 'students' => $students]);