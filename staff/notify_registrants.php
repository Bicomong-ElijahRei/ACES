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

$subtopic_id = isset($_POST['subtopic_id']) ? (int)$_POST['subtopic_id'] : 0;

if (!$subtopic_id) {
    echo json_encode(['success' => false, 'error' => 'Missing subtopic ID.']);
    exit;
}

// Fetch subtopic details
$stmt = $pdo->prepare("
    SELECT sub.title, sub.description, sub.subtopic_location, sub.subtopic_proctor,
           sub.subtopic_date, sub.subtopic_start_time, sub.subtopic_end_time,
           s.title AS session_title, s.date AS session_date, s.start_time, s.end_time
    FROM subtopics sub
    JOIN sessions s ON sub.session_id = s.session_id
    WHERE sub.subtopic_id = ? AND sub.is_deleted = 0
");
$stmt->execute([$subtopic_id]);
$subtopic = $stmt->fetch();

if (!$subtopic) {
    echo json_encode(['success' => false, 'error' => 'Subtopic not found.']);
    exit;
}

// Fetch registered students who haven't been manually notified yet for this subtopic
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

if (empty($students)) {
    echo json_encode(['success' => true, 'message' => 'No new students to notify (all already notified).']);
    exit;
}

// Build email content
$subject = "Update: {$subtopic['subtopic_title']}";
$body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <h2 style='color: #0a6e2d;'>ACES Session Update</h2>
        <p>Dear student,</p>
        <p>There is an update for the following subtopic you are registered for:</p>
        <table style='border-collapse: collapse; width: 100%; margin: 15px 0;'>
            <tr><td style='padding: 8px; background: #f0f0f0; font-weight: bold;'>Subtopic</td><td style='padding: 8px;'>{$subtopic['title']}</td></tr>
            <tr><td style='padding: 8px; background: #f0f0f0; font-weight: bold;'>Session</td><td style='padding: 8px;'>{$subtopic['session_title']}</td></tr>
            <tr><td style='padding: 8px; background: #f0f0f0; font-weight: bold;'>Date</td><td style='padding: 8px;'>" . date('M d, Y', strtotime($subtopic['subtopic_date'] ?: $subtopic['session_date'])) . "</td></tr>
            <tr><td style='padding: 8px; background: #f0f0f0; font-weight: bold;'>Time</td><td style='padding: 8px;'>{$subtopic['subtopic_start_time']} – {$subtopic['subtopic_end_time']}</td></tr>
            <tr><td style='padding: 8px; background: #f0f0f0; font-weight: bold;'>Location</td><td style='padding: 8px;'>{$subtopic['subtopic_location']}</td></tr>
            <tr><td style='padding: 8px; background: #f0f0f0; font-weight: bold;'>Proctor</td><td style='padding: 8px;'>{$subtopic['subtopic_proctor']}</td></tr>
        </table>
        <p>{$subtopic['description']}</p>
        <p>Please log in to ACES for more details.</p>
        <p style='font-size: 0.8em; color: #666;'>This is an automated message from ACES Unit, KLD.</p>
    </div>
";

$sent = 0;
$errors = [];

foreach ($students as $student) {
    $result = sendEmail($student['email'], $subject, $body);
    if ($result['success']) {
        // Log the reminder
        $stmt_log = $pdo->prepare("INSERT INTO reminders_sent (subtopic_id, student_id, type) VALUES (?, ?, 'manual_notify')");
        $stmt_log->execute([$subtopic_id, $student['student_id']]);
        $sent++;
    } else {
        $errors[] = $student['email'] . ': ' . $result['message'];
    }
}

echo json_encode([
    'success' => true,
    'message' => "$sent email(s) sent successfully.",
    'sent'    => $sent,
    'errors'  => $errors
]);