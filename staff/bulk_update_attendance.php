<?php
/**
 * ============================================================
 * ACES System — Bulk Update Attendance (JSON)
 * ============================================================
 * POST-only AJAX endpoint. Accepts raw JSON body:
 *   {
 *     "csrf_token": "...",
 *     "status": "present|pending|absent|unregister",
 *     "updates": [ { student_id, subtopic_id }, ... ]
 *   }
 *
 * Returns JSON: { success: bool, updated: int, errors: [] }
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

// ---- Read JSON body ----
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// ---- CSRF check (from JSON body, not $_POST) ----
$submittedToken = $input['csrf_token'] ?? '';
$expectedToken  = $_SESSION['csrf_token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed.']);
    exit;
}

// ---- Validate input ----
$updates = $input['updates'] ?? [];
$status  = $input['status']  ?? '';

if (empty($updates) || !is_array($updates)) {
    echo json_encode(['success' => false, 'error' => 'No updates']);
    exit;
}

$validStatuses = ['pending', 'present', 'absent', 'unregister'];
if (!in_array($status, $validStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

// ---- Process updates ----
$success = 0;
$errors  = [];

foreach ($updates as $item) {
    $studentId  = trim($item['student_id']  ?? '');
    $subtopicId = (int)($item['subtopic_id'] ?? 0);

    if ($studentId === '' || !$subtopicId) {
        $errors[] = 'Missing data';
        continue;
    }

    // Resolve session_id
    $stmt = $pdo->prepare("SELECT session_id FROM subtopics WHERE subtopic_id = ? AND is_deleted = 0");
    $stmt->execute([$subtopicId]);
    $sessionId = $stmt->fetchColumn();

    if (!$sessionId) {
        $errors[] = "Subtopic $subtopicId not found";
        continue;
    }

    // Handle Unregister
    if ($status === 'unregister') {
        $delReg = $pdo->prepare("DELETE FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
        $delReg->execute([$studentId, $sessionId, $subtopicId]);

        $delAtt = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
        $delAtt->execute([$studentId, $sessionId, $subtopicId]);

        $success++;
        continue;
    }

    // Auto-register if needed
    $check = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $check->execute([$studentId, $sessionId, $subtopicId]);

    if (!$check->fetch()) {
        $insReg = $pdo->prepare("
            INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
            VALUES (?, ?, ?, 'assigned', NOW())
        ");
        $insReg->execute([$studentId, $sessionId, $subtopicId]);
    }

    // Update or insert attendance
    $att = $pdo->prepare("SELECT attendance_id FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
    $att->execute([$studentId, $sessionId, $subtopicId]);

    if ($att->fetch()) {
        $upd = $pdo->prepare("
            UPDATE attendance
            SET attendance_status = ?, recorded_by = ?, attendance_date = NOW()
            WHERE student_id = ? AND session_id = ? AND subtopic_id = ?
        ");
        $upd->execute([$status, $_SESSION['user_id'], $studentId, $sessionId, $subtopicId]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, recorded_by, attendance_date)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([$studentId, $sessionId, $subtopicId, $status, $_SESSION['user_id']]);
    }

    $success++;
}

echo json_encode(['success' => true, 'updated' => $success, 'errors' => $errors]);