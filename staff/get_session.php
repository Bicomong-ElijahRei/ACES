<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$id = $_GET['id'] ?? 0;
if (!$id) {
    echo json_encode(['error' => 'No session ID provided']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sessions WHERE session_id = ?");
$stmt->execute([$id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    echo json_encode(['error' => 'Session not found']);
    exit;
}

// Fetch subtopics
$stmt_sub = $pdo->prepare("
    SELECT subtopic_id, session_id, title, description, capacity,
           deadline, subtopic_date, subtopic_start_time, subtopic_end_time,
           subtopic_proctor, subtopic_location, is_required,
           required_for, visible_for, required_courses, visible_courses,
           attendance_type, is_deleted
    FROM subtopics
    WHERE session_id = ?
    ORDER BY subtopic_id
");
$stmt_sub->execute([$id]);
$subtopics = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);

// Process JSON fields
foreach ($subtopics as &$sub) {
    $sub['required_for']      = $sub['required_for']      ? json_decode($sub['required_for'], true)     : [];
    $sub['visible_for']       = $sub['visible_for']       ? json_decode($sub['visible_for'], true)      : [];
    $sub['required_courses']  = $sub['required_courses']  ? json_decode($sub['required_courses'], true) : [];
    $sub['visible_courses']   = $sub['visible_courses']   ? json_decode($sub['visible_courses'], true)  : [];
    
    if (!isset($sub['attendance_type']) || empty($sub['attendance_type'])) {
        $sub['attendance_type'] = 'physical';
    }
}

$session['subtopics'] = $subtopics;
echo json_encode($session);
exit;