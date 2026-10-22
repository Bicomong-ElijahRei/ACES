<?php
/**
 * ============================================================
 * ACES System — Update Attendance Status (JSON)
 * ============================================================
 * POST-only AJAX endpoint. Called from student_progress.php
 * when staff clicks "Set" on a subtopic cell.
 *
 * Accepts:
 *   csrf_token   (required)
 *   student_id   (required)
 *   subtopic_id  (required)
 *   status       (pending|present|absent|unregister)
 *
 * Returns JSON: { success: bool, error?: string, ... }
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

// ---- Auth ----
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff' || ($_SESSION['staff_role'] ?? '') === 'viewer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ---- CSRF (non-dying variant so we can return JSON) ----
if (!csrf_verify(false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed.']);
    exit;
}

// ---- Input ----
$student_id  = trim($_POST['student_id']  ?? '');
$subtopic_id = (int)($_POST['subtopic_id'] ?? 0);
$status      = trim($_POST['status'] ?? '');

if ($student_id === '' || !$subtopic_id || $status === '') {
    echo json_encode(['success' => false, 'error' => 'Missing fields']);
    exit;
}

// ---- Resolve session_id from subtopic ----
$stmt = $pdo->prepare("SELECT session_id FROM subtopics WHERE subtopic_id = ? AND is_deleted = 0");
$stmt->execute([$subtopic_id]);
$session_id = $stmt->fetchColumn();

if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Subtopic not found']);
    exit;
}

// ---- Handle Unregister ----
if ($status === 'unregister') {
    try {
        $pdo->beginTransaction();

        // Delete module progress for modules under this subtopic
        $pdo->prepare("
            DELETE smp FROM student_module_progress smp
            JOIN modules m ON smp.module_id = m.module_id
            WHERE smp.student_id = ? AND m.subtopic_id = ?
        ")->execute([$student_id, $subtopic_id]);

        $pdo->prepare("DELETE FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?")
            ->execute([$student_id, $session_id, $subtopic_id]);

        $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?")
            ->execute([$student_id, $session_id, $subtopic_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'unregistered' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Unregister failed']);
    }
    exit;
}

// ---- Validate status ----
if (!in_array($status, ['pending', 'present', 'absent'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

// ---- Auto-register if not already registered ----
$check = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
$check->execute([$student_id, $session_id, $subtopic_id]);
if (!$check->fetch()) {
    // Enforce allow_multiple rule
    $stmt_allow = $pdo->prepare("SELECT allow_multiple FROM sessions WHERE session_id = ?");
    $stmt_allow->execute([$session_id]);
    $allow_multiple = (bool)$stmt_allow->fetchColumn();

    if (!$allow_multiple) {
        // Check if student has ANY other registration in this session
        $stmt_other = $pdo->prepare("
            SELECT COUNT(*) FROM registrations r
            JOIN subtopics st ON r.subtopic_id = st.subtopic_id
            WHERE r.student_id = ? AND st.session_id = ? AND r.subtopic_id != ?
        ");
        $stmt_other->execute([$student_id, $session_id, $subtopic_id]);
        if ((int)$stmt_other->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Student is already registered for another subtopic in this session. Unregister them first.']);
            exit;
        }
    }

    $insert = $pdo->prepare("
        INSERT IGNORE INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
        VALUES (?, ?, ?, 'assigned', NOW())
    ");
    $insert->execute([$student_id, $session_id, $subtopic_id]);
}

// ---- Update or insert attendance ----
$att = $pdo->prepare("SELECT attendance_id FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
$att->execute([$student_id, $session_id, $subtopic_id]);

if ($att->fetch()) {
    $update = $pdo->prepare("
        UPDATE attendance 
        SET attendance_status = ?, 
            validated = IF(? = 'pending', 0, 1), 
            auto_generated = 0, 
            recorded_by = ?
        WHERE student_id = ? AND session_id = ? AND subtopic_id = ?
    ");
    $update->execute([$status, $status, $_SESSION['user_id'], $student_id, $session_id, $subtopic_id]);
} else {
    $insert = $pdo->prepare("
        INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, validated, auto_generated, recorded_by, attendance_date)
        VALUES (?, ?, ?, ?, IF(? = 'pending', 0, 1), 0, ?, CURDATE())
    ");
    $insert->execute([$student_id, $session_id, $subtopic_id, $status, $status, $_SESSION['user_id']]);
}

echo json_encode(['success' => true]);