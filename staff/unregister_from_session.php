<?php
/**
 * ============================================================
 * ACES System — Unregister Student From Session (JSON)
 * ============================================================
 * POST-only. Removes all attendance + registrations for a
 * student across an entire session.
 *
 * Accepts:
 *   csrf_token   (required)
 *   student_id   (string, required)
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
$student_id = trim($_POST['student_id'] ?? '');
$session_id = (int)($_POST['session_id'] ?? 0);

if ($student_id === '' || !$session_id) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// ---- Do the unregistration in a transaction ----
try {
    $pdo->beginTransaction();

    // Delete attendance for this student and session
    $stmt_del_att = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_id = ?");
    $stmt_del_att->execute([$student_id, $session_id]);

    // Delete registrations for this student and session
    $stmt_del_reg = $pdo->prepare("DELETE FROM registrations WHERE student_id = ? AND session_id = ?");
    $stmt_del_reg->execute([$student_id, $session_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'removed' => [
            'attendance'   => $stmt_del_att->rowCount(),
            'registrations'=> $stmt_del_reg->rowCount(),
        ]
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Unregister from session failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Operation failed.']);
}