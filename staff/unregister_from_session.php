<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$student_id = $_POST['student_id'] ?? '';
$session_id = (int)($_POST['session_id'] ?? 0);

if (!$student_id || !$session_id) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

try {
    // Delete attendance records for this student and session
    $stmt_del_att = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_id = ?");
    $stmt_del_att->execute([$student_id, $session_id]);
    // Delete registrations for this student and session
    $stmt_del_reg = $pdo->prepare("DELETE FROM registrations WHERE student_id = ? AND session_id = ?");
    $stmt_del_reg->execute([$student_id, $session_id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}