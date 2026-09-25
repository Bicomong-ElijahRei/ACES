<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isStaff()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;

if (!$session_id) {
    $stmt = $pdo->query("SELECT session_id FROM sessions WHERE is_deleted = 0 ORDER BY date DESC LIMIT 1");
    $session = $stmt->fetch();
    $session_id = $session ? $session['session_id'] : 0;
}

// ----- Registration counts (unique students registered for any subtopic of this session) -----
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.student_id)
    FROM registrations r
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
    WHERE sub.session_id = ? AND sub.is_deleted = 0
");
$stmt->execute([$session_id]);
$registered = (int)$stmt->fetchColumn();

// Total students (expected attendees)
$total_students = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0")->fetchColumn();
$unregistered = max(0, $total_students - $registered);

// ----- Attendance counts (unique students per status) -----
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT CASE WHEN attendance_status = 'present' THEN student_id END) AS present,
        COUNT(DISTINCT CASE WHEN attendance_status = 'absent' THEN student_id END) AS absent,
        COUNT(DISTINCT CASE WHEN attendance_status = 'pending' THEN student_id END) AS pending
    FROM attendance
    WHERE session_id = ? AND is_deleted = 0
");
$stmt->execute([$session_id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);
$attendance = [
    'present' => (int)$att['present'],
    'absent'  => (int)$att['absent'],
    'pending' => (int)$att['pending']
];

// ----- Subtopic breakdown: registration per subtopic -----
$stmt = $pdo->prepare("
    SELECT 
        s.subtopic_id,
        s.title,
        COUNT(DISTINCT r.student_id) AS registered
    FROM subtopics s
    LEFT JOIN registrations r ON s.subtopic_id = r.subtopic_id
    WHERE s.session_id = ? AND s.is_deleted = 0
    GROUP BY s.subtopic_id, s.title
    ORDER BY s.title
");
$stmt->execute([$session_id]);
$subtopics_reg = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----- Subtopic breakdown: attendance per subtopic (original logic kept) -----
$stmt = $pdo->prepare("
    SELECT 
        s.subtopic_id,
        s.title,
        COUNT(DISTINCT CASE WHEN a.attendance_status = 'present' THEN a.student_id END) AS present,
        COUNT(DISTINCT CASE WHEN a.attendance_status = 'absent' THEN a.student_id END) AS absent,
        COUNT(DISTINCT CASE WHEN a.attendance_status = 'pending' THEN a.student_id END) AS pending,
        COUNT(DISTINCT a.student_id) AS total
    FROM subtopics s
    LEFT JOIN attendance a ON s.subtopic_id = a.subtopic_id AND a.is_deleted = 0
    WHERE s.session_id = ? AND s.is_deleted = 0
    GROUP BY s.subtopic_id, s.title
    ORDER BY s.title
");
$stmt->execute([$session_id]);
$subtopics_att = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'session_id'     => $session_id,
    'registration'   => [
        'registered'   => $registered,
        'unregistered' => $unregistered,
        'total'        => $total_students
    ],
    'attendance'     => $attendance,
    'subtopics_reg'  => $subtopics_reg,
    'subtopics_att'  => $subtopics_att
]);