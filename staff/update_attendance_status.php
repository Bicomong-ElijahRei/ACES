<?php
require_once '../config/database.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$student_id = $_POST['student_id'] ?? '';
$subtopic_id = $_POST['subtopic_id'] ?? '';
$status = $_POST['status'] ?? '';

if (!$student_id || !$subtopic_id || !$status) {
    echo json_encode(['success' => false, 'error' => 'Missing fields']);
    exit;
}

// Get session_id
$stmt = $pdo->prepare("SELECT session_id FROM subtopics WHERE subtopic_id = ?");
$stmt->execute([$subtopic_id]);
$session_id = $stmt->fetchColumn();
if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Subtopic not found']);
    exit;
}

// Handle Unregister
if ($status === 'unregister') {
    // Delete registration
    $delReg = $pdo->prepare("DELETE FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $delReg->execute([$student_id, $session_id, $subtopic_id]);
    // Delete attendance
    $delAtt = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $delAtt->execute([$student_id, $session_id, $subtopic_id]);
    echo json_encode(['success' => true, 'unregistered' => true]);
    exit;
}

// Otherwise, normal status update (pending/present/absent)
if (!in_array($status, ['pending', 'present', 'absent'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

// Auto-register if not already registered
$check = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
$check->execute([$student_id, $session_id, $subtopic_id]);
if (!$check->fetch()) {
    $insert = $pdo->prepare("INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date) VALUES (?, ?, ?, 'assigned', NOW())");
    $insert->execute([$student_id, $session_id, $subtopic_id]);
}

// Update or insert attendance
$att = $pdo->prepare("SELECT attendance_id FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
$att->execute([$student_id, $session_id, $subtopic_id]);
if ($att->fetch()) {
    $update = $pdo->prepare("UPDATE attendance SET attendance_status = ?, recorded_by = ?, attendance_date = NOW() WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $update->execute([$status, $_SESSION['user_id'], $student_id, $session_id, $subtopic_id]);
} else {
    $insert = $pdo->prepare("INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, recorded_by, attendance_date) VALUES (?, ?, ?, ?, ?, NOW())");
    $insert->execute([$student_id, $session_id, $subtopic_id, $status, $_SESSION['user_id']]);
}

echo json_encode(['success' => true]);