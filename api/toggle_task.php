<?php
require_once '../config/database.php';
session_start();

// Only allow logged-in students
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$student_id = $_SESSION['student_id']; // must be stored in session
$module_id = $_POST['module_id'] ?? 0;
$completed = $_POST['completed'] ?? 0;

if (!$module_id) {
    echo json_encode(['error' => 'Module ID missing']);
    exit;
}

try {
    // Check if the module belongs to a subtopic the student is registered for (optional, but good)
    $check = $pdo->prepare("
        SELECT 1 FROM modules m
        JOIN registrations r ON r.subtopic_id = m.subtopic_id
        WHERE m.module_id = ? AND r.student_id = ?
    ");
    $check->execute([$module_id, $student_id]);
    if (!$check->fetch()) {
        echo json_encode(['error' => 'You are not allowed to complete this module']);
        exit;
    }

    // Insert or update progress
    if ($completed) {
        $stmt = $pdo->prepare("
            INSERT INTO student_module_progress (student_id, module_id, completed, completion_date)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE completed = 1, completion_date = NOW()
        ");
    } else {
        $stmt = $pdo->prepare("
            UPDATE student_module_progress
            SET completed = 0, completion_date = NULL
            WHERE student_id = ? AND module_id = ?
        ");
        $stmt->execute([$student_id, $module_id]);
        // If no row exists, we can just skip (it's fine)
        $stmt->rowCount(); // ignore
        $stmt = null;
    }
    if ($completed) {
        $stmt->execute([$student_id, $module_id]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}