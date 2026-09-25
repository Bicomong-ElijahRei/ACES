<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$updates = $input['updates'] ?? [];
$status = $input['status'] ?? '';

if (empty($updates)) {
    echo json_encode(['success' => false, 'error' => 'No updates']);
    exit;
}

$success = 0;
$errors = [];

foreach ($updates as $item) {
    $studentId = $item['student_id'] ?? '';
    $subtopicId = $item['subtopic_id'] ?? '';
    if (!$studentId || !$subtopicId) {
        $errors[] = 'Missing data';
        continue;
    }

    // Get session_id
    $stmt = $pdo->prepare("SELECT session_id FROM subtopics WHERE subtopic_id = ?");
    $stmt->execute([$subtopicId]);
    $sessionId = $stmt->fetchColumn();
    if (!$sessionId) {
        $errors[] = "Subtopic $subtopicId not found";
        continue;
    }

    if ($status === 'unregister') {
        // Delete registration and attendance
        $delReg = $pdo->prepare("DELETE FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
        $delReg->execute([$studentId, $sessionId, $subtopicId]);
        $delAtt = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
        $delAtt->execute([$studentId, $sessionId, $subtopicId]);
        $success++;
        continue;
    }

    // Normal status update
    if (!in_array($status, ['pending', 'present', 'absent'])) {
        $errors[] = "Invalid status $status";
        continue;
    }

    // Auto-register if needed
    $check = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $check->execute([$studentId, $sessionId, $subtopicId]);
    if (!$check->fetch()) {
        $insReg = $pdo->prepare("INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date) VALUES (?, ?, ?, 'assigned', NOW())");
        $insReg->execute([$studentId, $sessionId, $subtopicId]);
    }

    // Update attendance
    $att = $pdo->prepare("SELECT attendance_id FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $att->execute([$studentId, $sessionId, $subtopicId]);
    if ($att->fetch()) {
        $upd = $pdo->prepare("UPDATE attendance SET attendance_status = ?, recorded_by = ?, attendance_date = NOW() WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
        $upd->execute([$status, $_SESSION['user_id'], $studentId, $sessionId, $subtopicId]);
    } else {
        $ins = $pdo->prepare("INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, recorded_by, attendance_date) VALUES (?, ?, ?, ?, ?, NOW())");
        $ins->execute([$studentId, $sessionId, $subtopicId, $status, $_SESSION['user_id']]);
    }
    $success++;
}

echo json_encode(['success' => true, 'updated' => $success, 'errors' => $errors]);