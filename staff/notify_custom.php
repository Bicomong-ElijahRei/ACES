<?php
/**
 * ============================================================
 * ACES System — Custom Notify (send email to filtered students)
 * ============================================================
 * POST-only. Accepts:
 *   session_id  (int, required)
 *   subtopics   (JSON array of subtopic_ids, optional)
 *   courses     (JSON array of program names, optional)
 *   sections    (JSON array of section names, optional)
 *   message     (string, optional)
 *   csrf_token  (required)
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
    exit(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

// ---- CSRF check ----
$token = $_POST['csrf_token'] ?? '';
$expected = $_SESSION['csrf_token'] ?? '';
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'CSRF validation failed.']));
}

// ---- Parse session_id ----
$session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
if (!$session_id) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Session required.']));
}

/**
 * Safely decode a JSON-encoded array from POST.
 * Accepts either a JSON string OR a real array (defensive).
 */
function aces_post_array(string $key): array {
    if (!isset($_POST[$key])) return [];
    $raw = $_POST[$key];

    // Already an array (e.g., form-style multi-select)
    if (is_array($raw)) return array_values(array_filter($raw, 'strlen'));

    // JSON string
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'strlen'));
        }
    }

    return [];
}

$subtopics = aces_post_array('subtopics');
$courses   = aces_post_array('courses');
$sections  = aces_post_array('sections');
$message   = trim($_POST['message'] ?? '');

// ---- Build recipient query ----
$sql = "SELECT DISTINCT u.email, u.full_name, s.student_id, sub.title AS subtopic_title, sub.subtopic_id
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
        WHERE r.session_id = ? AND sub.is_deleted = 0 AND s.is_deleted = 0";

$params = [$session_id];

if (!empty($subtopics)) {
    $ph = implode(',', array_fill(0, count($subtopics), '?'));
    $sql .= " AND sub.subtopic_id IN ($ph)";
    $params = array_merge($params, $subtopics);
}
if (!empty($courses)) {
    $ph = implode(',', array_fill(0, count($courses), '?'));
    $sql .= " AND s.program IN ($ph)";
    $params = array_merge($params, $courses);
}
if (!empty($sections)) {
    $ph = implode(',', array_fill(0, count($sections), '?'));
    $sql .= " AND s.section IN ($ph)";
    $params = array_merge($params, $sections);
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipients = $stmt->fetchAll();

if (empty($recipients)) {
    exit(json_encode(['success' => false, 'error' => 'No matching students.']));
}

// ---- Build email ----
$subject = "ACES Notification – Session Update";
$body = "<div style='font-family:Arial; max-width:600px'>
    <h2 style='color:#0a6e2d'>ACES Notification</h2>
    <p>You are receiving this because you are registered for a session.</p>
    <p><strong>Session:</strong> (details below)</p>
    <hr>";

if ($message) {
    $body .= "<p><strong>Message from ACES Staff:</strong></p><p>" . nl2br(htmlspecialchars($message)) . "</p><hr>";
}

$body .= "<p>Log in to ACES for more details.</p>
    <p style='font-size:0.8em; color:#666'>ACES Unit, KLD</p></div>";

// ---- Send emails ----
$sent = 0;
$errors = [];
foreach ($recipients as $r) {
    $personalBody = str_replace('[Student]', htmlspecialchars($r['full_name']), $body);
    $result = sendEmail($r['email'], $subject, $personalBody);
    if (!empty($result['success'])) {
        $sent++;
    } else {
        $errors[] = $r['email'] . ': ' . ($result['message'] ?? 'unknown error');
    }
}

echo json_encode([
    'success' => true,
    'message' => "$sent email(s) sent.",
    'sent'    => $sent,
    'errors'  => $errors
]);