<?php
/**
 * ============================================================
 * ACES System — Toggle Student Module Task (JSON)
 * ============================================================
 * POST-only AJAX endpoint. Marks or unmarks a module as
 * completed for the logged-in student.
 *
 * Accepts:
 *   csrf_token  (required)
 *   module_id   (int, required)
 *   completed   (0 or 1)
 *
 * Returns JSON: { success: bool, error?: string }
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

// ---- POST only ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- Student only ----
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ---- CSRF check (JSON-safe) ----
if (!csrf_verify(false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed.']);
    exit;
}

// ---- Input ----
$student_id = $_SESSION['student_id'] ?? '';
$module_id  = (int)($_POST['module_id'] ?? 0);
$completed  = (int)($_POST['completed'] ?? 0);

if (!$student_id) {
    echo json_encode(['success' => false, 'error' => 'No student in session']);
    exit;
}

if (!$module_id) {
    echo json_encode(['success' => false, 'error' => 'Module ID missing']);
    exit;
}

try {
    // ---- Verify the module belongs to a subtopic the student is registered for ----
    $check = $pdo->prepare("
        SELECT 1
        FROM modules m
        JOIN registrations r ON r.subtopic_id = m.subtopic_id
        WHERE m.module_id = ? 
          AND r.student_id = ? 
          AND m.is_deleted = 0
        LIMIT 1
    ");
    $check->execute([$module_id, $student_id]);

    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'You are not allowed to complete this module']);
        exit;
    }

    // ---- Toggle completion ----
    if ($completed) {
        $stmt = $pdo->prepare("
            INSERT INTO student_module_progress (student_id, module_id, completed, completion_date)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE completed = 1, completion_date = NOW()
        ");
        $stmt->execute([$student_id, $module_id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE student_module_progress
            SET completed = 0, completion_date = NULL
            WHERE student_id = ? AND module_id = ?
        ");
        $stmt->execute([$student_id, $module_id]);
        // If no row existed, nothing to do — that's fine.
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('toggle_task failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Operation failed.']);
}