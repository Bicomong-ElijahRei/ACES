<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$module_id = (int)($_GET['module_id'] ?? 0);
if (!$module_id) {
    echo json_encode(['success' => false, 'error' => 'Module ID required']);
    exit;
}

// Get the subtopic_id and verify this is an assessment
$stmt = $pdo->prepare("SELECT subtopic_id, type FROM modules WHERE module_id = ? AND is_deleted = 0");
$stmt->execute([$module_id]);
$mod = $stmt->fetch();
if (!$mod) {
    echo json_encode(['success' => false, 'error' => 'Module not found']);
    exit;
}
if ($mod['type'] !== 'assessment') {
    echo json_encode(['success' => false, 'error' => 'This module is not an assessment']);
    exit;
}
$subtopic_id = $mod['subtopic_id'];

// Get all students registered for this subtopic
$sql = "
    SELECT 
        s.student_id,
        u.full_name,
        s.program,
        s.section,
        COALESCE(sp.completed, 0) as completed,
        sp.completion_date,
        CASE WHEN sp.score IS NULL THEN NULL ELSE CAST(sp.score AS DECIMAL(5,2)) END as score
    FROM registrations r
    JOIN students s ON r.student_id = s.student_id AND s.is_deleted = 0
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN student_module_progress sp ON sp.student_id = s.student_id AND sp.module_id = ?
    WHERE r.subtopic_id = ? AND r.status IN ('assigned','auto_assigned')
    ORDER BY u.full_name
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$module_id, $subtopic_id]);
$results = $stmt->fetchAll();

echo json_encode(['success' => true, 'results' => $results]);