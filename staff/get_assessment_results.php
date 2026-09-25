<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$module_id = $_GET['module_id'] ?? 0;
if (!$module_id) {
    echo json_encode(['success' => false, 'error' => 'Module ID required']);
    exit;
}

// Get the subtopic_id for this module
$stmt = $pdo->prepare("SELECT subtopic_id FROM modules WHERE module_id = ?");
$stmt->execute([$module_id]);
$subtopic_id = $stmt->fetchColumn();
if (!$subtopic_id) {
    echo json_encode(['success' => false, 'error' => 'Module not found']);
    exit;
}

// Get all students registered for this subtopic
$sql = "
    SELECT 
        s.student_id,
        u.full_name,
        s.program,
        s.section,
        COALESCE(sp.completed, 0) as completed,
        sp.completion_date,
        sp.score
    FROM registrations r
    JOIN students s ON r.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN student_module_progress sp ON sp.student_id = s.student_id AND sp.module_id = ?
    WHERE r.subtopic_id = ? AND s.is_deleted = 0
    GROUP BY s.student_id
    ORDER BY u.full_name
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$module_id, $subtopic_id]);
$results = $stmt->fetchAll();

echo json_encode(['success' => true, 'results' => $results]);