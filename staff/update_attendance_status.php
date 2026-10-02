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
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
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
    $delReg = $pdo->prepare("DELETE FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $delReg->execute([$student_id, $session_id, $subtopic_id]);

    $delAtt = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $delAtt->execute([$student_id, $session_id, $subtopic_id]);

    echo json_encode(['success' => true, 'unregistered' => true]);
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
    $insert = $pdo->prepare("
        INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
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
        SET attendance_status = ?, recorded_by = ?, attendance_date = NOW()
        WHERE student_id = ? AND session_id = ? AND subtopic_id = ?
    ");
    $update->execute([$status, $_SESSION['user_id'], $student_id, $session_id, $subtopic_id]);
} else {
    $insert = $pdo->prepare("
        INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, recorded_by, attendance_date)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $insert->execute([$student_id, $session_id, $subtopic_id, $status, $_SESSION['user_id']]);
}

echo json_encode(['success' => true]);