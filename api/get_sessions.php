<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

// This endpoint can be called without a student context (staff) or with ?student_id=... (student)
$student_id = $_GET['student_id'] ?? '';
$sessions = [];

if ($student_id) {
    // Student context: fetch their program & section
    $stmt = $pdo->prepare("SELECT program, section FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $stu = $stmt->fetch();
    $student_program = $stu['program'] ?? '';
    $student_section = $stu['section'] ?? '';

    // Fetch all active sessions
    $stmt = $pdo->query("
        SELECT s.session_id, s.title, s.date, s.start_time, s.end_time
        FROM sessions s
        WHERE s.is_deleted = 0
        ORDER BY s.date ASC
    ");
    $all_sessions = $stmt->fetchAll();

    // Filter sessions that have at least one subtopic visible to this student
    foreach ($all_sessions as $session) {
        $stmt_sub = $pdo->prepare("
            SELECT visible_for, visible_courses, required_for, required_courses
            FROM subtopics
            WHERE session_id = ? AND is_deleted = 0
        ");
        $stmt_sub->execute([$session['session_id']]);
        $subtopics = $stmt_sub->fetchAll();

        foreach ($subtopics as $sub) {
            $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
            $vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
            $req_sections = json_decode($sub['required_for'] ?? '[]', true) ?: [];
            $req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];

            // Public?
            if (empty($vis_sections) && empty($vis_courses) && empty($req_sections) && empty($req_courses)) {
                $sessions[] = $session;
                break;
            }
            // Required?
            if (in_array($student_section, $req_sections) || in_array($student_program, $req_courses)) {
                $sessions[] = $session;
                break;
            }
            // Explicitly visible?
            if (in_array($student_section, $vis_sections) || in_array($student_program, $vis_courses)) {
                $sessions[] = $session;
                break;
            }
        }
    }
} else {
    // Staff context (or no student filter): return all active sessions
    $stmt = $pdo->query("
        SELECT session_id, title, date, start_time, end_time
        FROM sessions
        WHERE is_deleted = 0
        ORDER BY date ASC
    ");
    $sessions = $stmt->fetchAll();
}

header('Content-Type: application/json');
echo json_encode($sessions);