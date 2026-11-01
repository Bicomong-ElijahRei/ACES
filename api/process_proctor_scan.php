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

// 2. Verify the Student & Fetch Eligibility Data
$stmt = $pdo->prepare("SELECT first_name, last_name, program, section FROM students WHERE student_id = ? AND is_deleted = 0");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student ID not found in system.']);
    exit;
}
$full_name = $student['first_name'] . ' ' . $student['last_name'];

// 3. CHECK ELIGIBILITY (Course Restrictions)
$stmt_sub = $pdo->prepare("SELECT visible_courses FROM subtopics WHERE subtopic_id = ?");
$stmt_sub->execute([$subtopic_id]);
$sub_rules = $stmt_sub->fetch();

$vis_courses = json_decode($sub_rules['visible_courses'] ?? '[]', true) ?: [];

// If the subtopic has restrictions and the student doesn't match, reject them!
if (!empty($vis_courses) && !in_array($student['program'], $vis_courses)) {
    echo json_encode(['success' => false, 'message' => "Access Denied: This student belongs to {$student['program']} and is not eligible for this session."]);
    exit;
}

// 4. CHECK FOR DOUBLE SCAN
$check_att = $pdo->prepare("SELECT attendance_id, attendance_status FROM attendance WHERE student_id = ? AND subtopic_id = ?");
$check_att->execute([$student_id, $subtopic_id]);
$existing_att = $check_att->fetch();

if ($existing_att && $existing_att['attendance_status'] === 'present') {
    echo json_encode(['success' => false, 'message' => "Already Scanned! {$full_name} is already marked Present."]);
    exit;
}

// 5. STRICT CHECK: Is the student registered for this subtopic?
$check_reg = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND subtopic_id = ?");
$check_reg->execute([$student_id, $subtopic_id]);
if ($check_reg->rowCount() == 0) {
    echo json_encode(['success' => false, 'message' => "Access Denied: {$full_name} is NOT registered for this subtopic."]);
    exit;
}

// 6. Mark Attendance (Only reaches here if registered)
if ($existing_att) {
    $pdo->prepare("UPDATE attendance SET attendance_status = 'present', validated = 1, attendance_date = NOW() WHERE attendance_id = ?")
        ->execute([$existing_att['attendance_id']]);
} else {
    $pdo->prepare("INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, validated) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$student_id, $session_id, $subtopic_id]);
}

echo json_encode(['success' => true, 'student_name' => $full_name]);