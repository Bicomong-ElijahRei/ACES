<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/send_email.php';

if (!isStaff()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Missing session ID.']);
    exit;
}

// Fetch session details
$stmt = $pdo->prepare("SELECT title, date, start_time, end_time FROM sessions WHERE session_id = ? AND is_deleted = 0");
$stmt->execute([$session_id]);
$session = $stmt->fetch();
if (!$session) {
    echo json_encode(['success' => false, 'error' => 'Session not found.']);
    exit;
}

// Get all subtopics of this session
$stmt = $pdo->prepare("SELECT subtopic_id, title, description, subtopic_location, subtopic_proctor, subtopic_date, subtopic_start_time, subtopic_end_time FROM subtopics WHERE session_id = ? AND is_deleted = 0");
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

    // Students registered for this subtopic, not yet manually notified
    $stmt = $pdo->prepare("
        SELECT u.email, u.full_name, s.student_id
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN reminders_sent rs ON rs.subtopic_id = r.subtopic_id
                                    AND rs.student_id = r.student_id
                                    AND rs.type = 'manual_notify'
        WHERE r.subtopic_id = ? AND rs.id IS NULL
    ");
    $stmt->execute([$subtopic_id]);
    $students = $stmt->fetchAll();

    if (empty($students)) continue;

    $subject = "Update: {$sub['title']} (Session: {$session['title']})";
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px;'>
            <h2 style='color:#0a6e2d'>ACES Session Update</h2>
            <p>There is an update for <b>{$sub['title']}</b> in <b>{$session['title']}</b>.</p>
            <table style='width:100%; border-collapse:collapse; margin:15px 0'>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Subtopic</td><td colspan='2' style='padding:8px'>{$sub['title']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Date</td><td colspan='2' style='padding:8px'>" . date('M d, Y', strtotime($sub['subtopic_date'] ?: $session['date'])) . "</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Time</td><td colspan='2' style='padding:8px'>{$sub['subtopic_start_time']} – {$sub['subtopic_end_time']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Location</td><td colspan='2' style='padding:8px'>{$sub['subtopic_location']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Proctor</td><td colspan='2' style='padding:8px'>{$sub['subtopic_proctor']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold'>Details</td><td colspan='2' style='padding:8px'>{$sub['description']}</td></tr>
            </table>
            <p>Log in to ACES for more details.</p>
            <p style='font-size:0.8em; color:#666'>ACES Unit, KLD</p>
        </div>
    ";

    foreach ($students as $student) {
        $result = sendEmail($student['email'], $subject, $body);
        if ($result['success']) {
            $stmt_log = $pdo->prepare("INSERT INTO reminders_sent (subtopic_id, student_id, type) VALUES (?, ?, 'manual_notify')");
            $stmt_log->execute([$subtopic_id, $student['student_id']]);
            $total_sent++;
        } else {
            $errors[] = $student['email'] . ': ' . $result['message'];
        }
    }
}

echo json_encode([
    'success' => true,
    'message'  => "$total_sent email(s) sent.",
    'sent'     => $total_sent,
    'errors'   => $errors
]);