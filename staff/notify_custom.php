<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/send_email.php';

if (!isStaff()) {
    http_response_code(403);
    exit(json_encode(['success'=>false,'error'=>'Unauthorized']));
}
header('Content-Type: application/json');

$session_id = (int)$_POST['session_id'] ?? 0;
if (!$session_id) exit(json_encode(['success'=>false,'error'=>'Session required']));

// Optional filters
$subtopics  = isset($_POST['subtopics'])  ? $_POST['subtopics']  : [];
$courses    = isset($_POST['courses'])    ? $_POST['courses']    : [];
$sections   = isset($_POST['sections'])   ? $_POST['sections']   : [];
$message    = trim($_POST['message'] ?? '');

// Build recipient query
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

// Exclude already manually notified?
// For simplicity we skip duplicate check for custom notify. You can add later.

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipients = $stmt->fetchAll();

if (empty($recipients)) {
    echo json_encode(['success'=>false,'error'=>'No matching students.']);
    exit;
}

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

$sent = 0;
$errors = [];
foreach ($recipients as $r) {
    // Personalize a bit
    $personalBody = str_replace('[Student]', $r['full_name'], $body);
    $result = sendEmail($r['email'], $subject, $personalBody);
    if ($result['success']) {
        $sent++;
    } else {
        $errors[] = $r['email'].': '.$result['message'];
    }
}

echo json_encode(['success'=>true, 'message'=>"$sent email(s) sent.", 'sent'=>$sent, 'errors'=>$errors]);