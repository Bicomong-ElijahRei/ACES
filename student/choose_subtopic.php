<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_id  = (int)$_POST['session_id'];
    $subtopic_id = (int)$_POST['subtopic_id'];
    $student_id  = $_SESSION['student_id'];

    // Fetch student's program & section
    $stmt_stu = $pdo->prepare("SELECT program, section FROM students WHERE student_id = ?");
    $stmt_stu->execute([$student_id]);
    $stu = $stmt_stu->fetch();
    $student_program = $stu['program'] ?? '';
    $student_section = $stu['section'] ?? '';

    // Check if already registered for this subtopic
    $check = $pdo->prepare("SELECT * FROM registrations WHERE student_id = ? AND subtopic_id = ?");
    $check->execute([$student_id, $subtopic_id]);
    if ($check->rowCount() > 0) {
        $_SESSION['message'] = 'You are already registered for this subtopic.';
        header('Location: sessions.php');
        exit;
    }

    // ---------- VISIBILITY / REQUIREMENT GATE ----------
    $stmt_vis = $pdo->prepare("SELECT visible_for, visible_courses, required_for, required_courses FROM subtopics WHERE subtopic_id = ?");
    $stmt_vis->execute([$subtopic_id]);
    $sub = $stmt_vis->fetch();

    if ($sub) {
        $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
        $vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
        $req_sections = json_decode($sub['required_for'] ?? '[]', true) ?: [];
        $req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];

        $allowed = false;

        // Public subtopic?
        if (empty($vis_sections) && empty($vis_courses) && empty($req_sections) && empty($req_courses)) {
            $allowed = true;
        }

        // Required?
        if (in_array($student_section, $req_sections) || in_array($student_program, $req_courses)) {
            $allowed = true;
        }

        // Explicitly visible?
        if (in_array($student_section, $vis_sections) || in_array($student_program, $vis_courses)) {
            $allowed = true;
        }

        if (!$allowed) {
            $_SESSION['message'] = 'This subtopic is not available for your program/section.';
            header('Location: sessions.php');
            exit;
        }
    }
    // ---------- END VISIBILITY GATE ----------

    // ---------- ONE SUBTOPIC PER SESSION ----------
    $stmt_allow = $pdo->prepare("SELECT allow_multiple FROM sessions WHERE session_id = ?");
    $stmt_allow->execute([$session_id]);
    $allow_multiple = (bool) $stmt_allow->fetchColumn();

    if (!$allow_multiple) {
        // Check if student already has ANY registration in this session
        $stmt_existing = $pdo->prepare("
            SELECT r.registration_id FROM registrations r
            JOIN subtopics st ON r.subtopic_id = st.subtopic_id
            WHERE st.session_id = ? AND r.student_id = ?
        ");
        $stmt_existing->execute([$session_id, $student_id]);
        if ($stmt_existing->fetch()) {
            $_SESSION['message'] = 'You have already selected a subtopic in this session. Only one is allowed.';
            header('Location: sessions.php');
            exit;
        }
    }
    // ---------- END ONE PER SESSION ----------

    // Check capacity
    $stmt = $pdo->prepare("SELECT capacity FROM subtopics WHERE subtopic_id = ?");
    $stmt->execute([$subtopic_id]);
    $capacity = $stmt->fetchColumn();
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE subtopic_id = ?");
    $stmt2->execute([$subtopic_id]);
    $current = $stmt2->fetchColumn();
    if ($current >= $capacity) {
        $_SESSION['message'] = 'This subtopic is full.';
        header('Location: sessions.php');
        exit;
    }

    // Insert registration
    $stmt3 = $pdo->prepare("INSERT INTO registrations (student_id, session_id, subtopic_id, status) VALUES (?, ?, ?, 'assigned')");
    $stmt3->execute([$student_id, $session_id, $subtopic_id]);

    $_SESSION['message'] = 'Successfully registered for subtopic.';
    header('Location: sessions.php');
    exit;
} else {
    header('Location: sessions.php');
    exit;
}