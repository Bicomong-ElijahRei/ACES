<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: student_progress.php');
    exit;
}

csrf_verify();

$selected_data_json = $_POST['selected_data'] ?? '[]';
$selected_students = json_decode($selected_data_json, true);

$title_prefix = trim($_POST['title_prefix'] ?? 'Make-Up:');
$date = $_POST['date'] ?? '';
$phase = $_POST['phase'] ?? '';
$start_time = $_POST['start_time'] ?? '';
$end_time = $_POST['end_time'] ?? '';
$created_by = $_SESSION['user_id'];

if (empty($selected_students) || empty($date) || empty($start_time)) {
    header('Location: student_progress.php?msg=error&detail=' . urlencode('Missing required fields.'));
    exit;
}

// Group selected students by their missed_subtopic_id
$grouped_students = [];
foreach ($selected_students as $item) {
    $grouped_students[$item['subtopic_id']][] = $item['student_id'];
}

try {
    $pdo->beginTransaction();
    $session_count = 0;
    $student_count = 0;

    // Loop through each missed subtopic group
    foreach ($grouped_students as $missed_subtopic_id => $student_ids) {
        
        // Fetch the original subtopic title to append to the Make-Up title
        $stmt_title = $pdo->prepare("SELECT title FROM subtopics WHERE subtopic_id = ?");
        $stmt_title->execute([$missed_subtopic_id]);
        $orig_title = $stmt_title->fetchColumn() ?: 'Unknown';

        $full_title = $title_prefix . ' ' . $orig_title;

        // 1. Create the Make-Up Session for this specific missed subtopic
        $reason = trim($_POST['reason'] ?? 'Valid Excuse Submitted');
        $stmt = $pdo->prepare("INSERT INTO sessions (title, phase, date, start_time, end_time, created_by, is_makeup, makeup_reason) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
        $stmt->execute([$full_title, $phase, $date, $start_time, $end_time, $created_by, $reason]);
        $session_id = $pdo->lastInsertId();

        // 2. Create the Subtopic linked to the original missed subtopic
        $stmt = $pdo->prepare("INSERT INTO subtopics (session_id, title, capacity, makeup_for_subtopic_id, attendance_type) VALUES (?, ?, 200, ?, 'physical')");
        $stmt->execute([$session_id, $full_title, $missed_subtopic_id]);
        $new_subtopic_id = $pdo->lastInsertId();

        // 3. Register the students and create pending attendance
        // Use INSERT IGNORE to prevent duplicate key errors on registrations
        $stmt_reg = $pdo->prepare("INSERT IGNORE INTO registrations (student_id, session_id, subtopic_id, status) VALUES (?, ?, ?, 'assigned')");
        
        // Check for existing attendance before inserting to prevent duplicates
        $stmt_check_att = $pdo->prepare("SELECT attendance_id FROM attendance WHERE student_id = ? AND session_id = ? AND subtopic_id = ?");
        $stmt_att_insert = $pdo->prepare("INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, validated) VALUES (?, ?, ?, 'pending', 0)");
        $stmt_att_update = $pdo->prepare("UPDATE attendance SET attendance_status = 'pending', validated = 0 WHERE attendance_id = ?");

        foreach ($student_ids as $student_id) {
            $stmt_reg->execute([$student_id, $session_id, $new_subtopic_id]);
            
            $stmt_check_att->execute([$student_id, $session_id, $new_subtopic_id]);
            $existing_att_id = $stmt_check_att->fetchColumn();
            
            if ($existing_att_id) {
                $stmt_att_update->execute([$existing_att_id]);
            } else {
                $stmt_att_insert->execute([$student_id, $session_id, $new_subtopic_id]);
            }
            $student_count++;
        }
        $session_count++;
    }

    $pdo->commit();
    
    // Redirect with success
    header('Location: student_progress.php?msg=makeup_created&sessions=' . $session_count . '&students=' . $student_count);
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: student_progress.php?msg=error&detail=' . urlencode('Make-Up creation failed: ' . $e->getMessage()));
    exit;
}