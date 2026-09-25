<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$student_id = $_GET['student_id'] ?? '';
if (!$student_id) {
    echo json_encode(['success' => false, 'error' => 'No student ID']);
    exit;
}

// Fetch all modules/assessments for subtopics the student is registered for
$sql = "
    SELECT 
        sub.title as subtopic_title,
        m.module_id,
        m.title as module_title,
        m.type,
        m.due_date,
        COALESCE(sp.completed, 0) as completed
    FROM registrations r
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
    JOIN modules m ON m.subtopic_id = sub.subtopic_id
    LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = r.student_id
    WHERE r.student_id = ? AND m.is_deleted = 0
    ORDER BY sub.title, m.due_date
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$student_id]);
$modules = $stmt->fetchAll();

echo json_encode(['success' => true, 'modules' => $modules]);