<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$pin = $_POST['pin'] ?? '';
$subtopic_id = (int)($_POST['subtopic_id'] ?? 0);
$student_id = trim($_POST['student_id'] ?? '');

if (empty($pin) || empty($subtopic_id) || empty($student_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing data.']);
    exit;
}

// 1. Verify the PIN
$stmt = $pdo->prepare("SELECT session_id FROM subtopics WHERE subtopic_id = ? AND proctor_pin = ? AND is_deleted = 0");
$stmt->execute([$subtopic_id, $pin]);
$subtopic = $stmt->fetch();

if (!$subtopic) {
    echo json_encode(['success' => false, 'message' => 'Invalid Proctor PIN.']);
    exit;
}
$session_id = $subtopic['session_id'];

// 2. Verify the Student exists
$stmt = $pdo->prepare("SELECT first_name, last_name FROM students WHERE student_id = ? AND is_deleted = 0");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student ID not found in system.']);
    exit;
}
$full_name = $student['first_name'] . ' ' . $student['last_name'];

// 3. CHECK FOR DOUBLE SCAN
$check_att = $pdo->prepare("SELECT attendance_id, attendance_status FROM attendance WHERE student_id = ? AND subtopic_id = ?");
$check_att->execute([$student_id, $subtopic_id]);
$existing_att = $check_att->fetch();

if ($existing_att && $existing_att['attendance_status'] === 'present') {
    echo json_encode(['success' => false, 'message' => "Already Scanned! {$full_name} is already marked Present."]);
    exit;
}

// 4. STRICT CHECK: Is the student registered for this subtopic?
$check_reg = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND subtopic_id = ?");
$check_reg->execute([$student_id, $subtopic_id]);
if ($check_reg->rowCount() == 0) {
    echo json_encode(['success' => false, 'message' => "Access Denied: {$full_name} is NOT registered for this subtopic."]);
    exit;
}

// 5. Mark Attendance
if ($existing_att) {
    $pdo->prepare("UPDATE attendance SET attendance_status = 'present', validated = 1, attendance_date = NOW() WHERE attendance_id = ?")
        ->execute([$existing_att['attendance_id']]);
} else {
    $pdo->prepare("INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, validated) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$student_id, $session_id, $subtopic_id]);
}

// 6. MAKE-UP SUBSTITUTION LOGIC
$stmt_mk = $pdo->prepare("SELECT makeup_for_subtopic_id FROM subtopics WHERE subtopic_id = ?");
$stmt_mk->execute([$subtopic_id]);
$makeup_for = $stmt_mk->fetchColumn();

if ($makeup_for) {
    $stmt_fix = $pdo->prepare("UPDATE attendance SET attendance_status = 'present', validated = 1 WHERE student_id = ? AND subtopic_id = ?");
    $stmt_fix->execute([$student_id, $makeup_for]);

    if ($stmt_fix->rowCount() === 0) {
        $stmt_orig_session = $pdo->prepare("SELECT session_id FROM subtopics WHERE subtopic_id = ?");
        $stmt_orig_session->execute([$makeup_for]);
        $orig_session_id = $stmt_orig_session->fetchColumn();

        if ($orig_session_id) {
            $pdo->prepare("INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, validated) VALUES (?, ?, ?, 'present', 1)")
                ->execute([$student_id, $orig_session_id, $makeup_for]);
        }
    }
}

echo json_encode(['success' => true, 'student_name' => $full_name]);