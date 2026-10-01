<?php
/**
 * ============================================================
 * ACES System — Notify Session (send to all registered students)
 * ============================================================
 * POST-only. Accepts:
 *   session_id  (int, required)
 *   csrf_token  (required)
 *
 * Sends an email to every student registered for any subtopic
 * of the given session who has not yet been manually notified.
 *
 * Returns JSON: { success: bool, message: string, sent: int, errors: [] }
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/send_email.php';

header('Content-Type: application/json');

if (!isStaff()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ---- CSRF check ----
$token    = $_POST['csrf_token'] ?? '';
$expected = $_SESSION['csrf_token'] ?? '';
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed.']);
    exit;
}

$session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Missing session ID.']);
    exit;
}

// ---- Fetch session details ----
$stmt = $pdo->prepare("SELECT title, date, start_time, end_time FROM sessions WHERE session_id = ? AND is_deleted = 0");
$stmt->execute([$session_id]);
$session = $stmt->fetch();
if (!$session) {
    echo json_encode(['success' => false, 'error' => 'Session not found.']);
    exit;
}

// ---- Get all subtopics of this session ----
$stmt = $pdo->prepare("
    SELECT subtopic_id, title, description, subtopic_location, subtopic_proctor,
           subtopic_date, subtopic_start_time, subtopic_end_time
    FROM subtopics
    WHERE session_id = ? AND is_deleted = 0
");
$stmt->execute([$session_id]);
$subtopics = $stmt->fetchAll();

if (empty($subtopics)) {
    echo json_encode(['success' => true, 'message' => 'No subtopics in this session.']);
    exit;
}

$total_sent = 0;
$errors = [];

foreach ($subtopics as $sub) {
    $subtopic_id = $sub['subtopic_id'];

    // ---- Students registered for this subtopic, not yet manually notified ----
    $stmt = $pdo->prepare("
        SELECT u.email, u.full_name, s.student_id
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN reminders_sent rs ON rs.subtopic_id = r.subtopic_id
                                    AND rs.student_id = r.student_id
                                    AND rs.type = 'manual_notify'
        WHERE r.subtopic_id = ?
          AND s.is_deleted = 0
          AND rs.id IS NULL
    ");
    $stmt->execute([$subtopic_id]);
    $students = $stmt->fetchAll();

    if (empty($students)) continue;

    // ---- Build email ----
    $safeTitle    = htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8');
    $safeSession  = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8');
    $safeLocation = htmlspecialchars($sub['subtopic_location'] ?? '', ENT_QUOTES, 'UTF-8');
    $safeProctor  = htmlspecialchars($sub['subtopic_proctor'] ?? '', ENT_QUOTES, 'UTF-8');
    $safeDesc     = htmlspecialchars($sub['description'] ?? '', ENT_QUOTES, 'UTF-8');

    $displayDate = date('M d, Y', strtotime($sub['subtopic_date'] ?: $session['date']));
    $displayStart = htmlspecialchars($sub['subtopic_start_time'] ?? '', ENT_QUOTES, 'UTF-8');
    $displayEnd   = htmlspecialchars($sub['subtopic_end_time'] ?? '', ENT_QUOTES, 'UTF-8');

    $subject = "Update: {$safeTitle} (Session: {$safeSession})";
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px;'>
            <h2 style='color:#0a6e2d'>ACES Session Update</h2>
            <p>Hi [Student],</p>
            <p>There is an update for <b>{$safeTitle}</b> in <b>{$safeSession}</b>.</p>
            <table style='width:100%; border-collapse:collapse; margin:15px 0'>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Subtopic</td><td colspan='2' style='padding:8px'>{$safeTitle}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Date</td><td colspan='2' style='padding:8px'>{$displayDate}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Time</td><td colspan='2' style='padding:8px'>{$displayStart} – {$displayEnd}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Location</td><td colspan='2' style='padding:8px'>{$safeLocation}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Proctor</td><td colspan='2' style='padding:8px'>{$safeProctor}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Details</td><td colspan='2' style='padding:8px'>{$safeDesc}</td></tr>
            </table>
            <p>Log in to ACES for more details.</p>
            <p style='font-size:0.8em; color:#666'>ACES Unit, KLD</p>
        </div>
    ";

    foreach ($students as $student) {
        // Personalize
        $personalBody = str_replace('[Student]', htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8'), $body);

        $result = sendEmail($student['email'], $subject, $personalBody);
        if (!empty($result['success'])) {
            $stmt_log = $pdo->prepare("INSERT INTO reminders_sent (subtopic_id, student_id, type) VALUES (?, ?, 'manual_notify')");
            $stmt_log->execute([$subtopic_id, $student['student_id']]);
            $total_sent++;
        } else {
            $errors[] = $student['email'] . ': ' . ($result['message'] ?? 'unknown error');
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => "$total_sent email(s) sent.",
    'sent'    => $total_sent,
    'errors'  => $errors
]);