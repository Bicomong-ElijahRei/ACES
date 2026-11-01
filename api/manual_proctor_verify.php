<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$pin = $_POST['pin'] ?? '';
$subtopic_id = (int)($_POST['subtopic_id'] ?? 0);
$student_id = trim($_POST['student_id'] ?? '');

if (empty($pin) || empty($subtopic_id) || empty($student_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing data.']);
    exit;
}

// 1. Verify the PIN
$stmt = $pdo->prepare("SELECT session_id FROM subtopics WHERE subtopic_id = ? AND proctor_pin = ? AND is_deleted = 0");
$stmt->execute([$subtopic_id, $pin]);
$subtopic = $stmt->fetch();

if (!$subtopic) {
    echo json_encode(['success' => false, 'message' => 'Invalid Proctor PIN.']);
    exit;
}
$session_id = $subtopic['session_id'];

// 2. Insert or Update Attendance
$check_att = $pdo->prepare("SELECT attendance_id FROM attendance WHERE student_id = ? AND subtopic_id = ?");
$check_att->execute([$student_id, $subtopic_id]);

if ($check_att->rowCount() > 0) {
    $att_id = $check_att->fetchColumn();
    $pdo->prepare("UPDATE attendance SET attendance_status = 'present', validated = 1, attendance_date = NOW() WHERE attendance_id = ?")
        ->execute([$att_id]);
} else {
    $pdo->prepare("INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, validated) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$student_id, $session_id, $subtopic_id]);
}

echo json_encode(['success' => true]);