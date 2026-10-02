<?php
/**
 * ============================================================
 * ACES System — Unregister Student From Subtopic (JSON)
 * ============================================================
 * POST-only. Removes attendance + registration for one student
 * from a single subtopic.
 *
 * Accepts:
 *   csrf_token   (required)
 *   student_id   (string, required)
 *   subtopic_id  (int, required)
 *   session_id   (int, required)
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- CSRF (JSON-safe) ----
if (!csrf_verify(false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed.']);
    exit;
}

// ---- Input ----
$student_id  = trim($_POST['student_id'] ?? '');
$subtopic_id = (int)($_POST['subtopic_id'] ?? 0);
$session_id  = (int)($_POST['session_id']  ?? 0);

if ($student_id === '' || !$subtopic_id || !$session_id) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// ---- Do the unregistration in a transaction ----
try {
    $pdo->beginTransaction();

    // Delete attendance
    $stmt_del_att = $pdo->prepare("
        DELETE FROM attendance
        WHERE student_id = ? AND session_id = ? AND subtopic_id = ?
    ");
    $stmt_del_att->execute([$student_id, $session_id, $subtopic_id]);

    // Delete registration
    $stmt_del_reg = $pdo->prepare("
        DELETE FROM registrations
        WHERE student_id = ? AND session_id = ? AND subtopic_id = ?
    ");
    $stmt_del_reg->execute([$student_id, $session_id, $subtopic_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'removed' => [
            'attendance'    => $stmt_del_att->rowCount(),
            'registrations' => $stmt_del_reg->rowCount(),
        ]
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Unregister student failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Operation failed.']);
}