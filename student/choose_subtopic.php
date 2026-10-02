<?php
/**
 * ============================================================
 * ACES System — Choose Subtopic (Registration Handler)
 * ============================================================
 * POST-only. CSRF-protected.
 *
 * Accepts:
 *   csrf_token  (required)
 *   session_id  (int, required)
 *   subtopic_id (int, required)
 *
 * Validates:
 *   - Student not already registered for the subtopic
 *   - Subtopic is visible / required for the student's program+section
 *   - Session's allow_multiple rule (if false, only 1 subtopic per session)
 *   - Capacity not exceeded
 *
 * On success: inserts registration + redirects to sessions.php
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStudent();

// ---- POST only ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: sessions.php');
    exit;
}

// ---- CSRF check ----
csrf_verify();

// ---- Read inputs ----
$session_id  = (int)($_POST['session_id']  ?? 0);
$subtopic_id = (int)($_POST['subtopic_id'] ?? 0);
$student_id  = $_SESSION['student_id'];

if (!$session_id || !$subtopic_id) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'Invalid registration request.'];
    header('Location: sessions.php');
    exit;
}

// ---- Student's program & section ----
$stmt_stu = $pdo->prepare("SELECT program, section FROM students WHERE student_id = ?");
$stmt_stu->execute([$student_id]);
$stu = $stmt_stu->fetch();
$student_program = $stu['program'] ?? '';
$student_section = $stu['section'] ?? '';

// ---- Already registered? ----
$check = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND subtopic_id = ?");
$check->execute([$student_id, $subtopic_id]);
if ($check->fetch()) {
    $_SESSION['flash'] = ['type' => 'info', 'text' => 'You are already registered for this subtopic.'];
    header('Location: sessions.php');
    exit;
}

// ---------- VISIBILITY / REQUIREMENT GATE ----------
$stmt_vis = $pdo->prepare("
    SELECT visible_for, visible_courses, required_for, required_courses
    FROM subtopics
    WHERE subtopic_id = ? AND is_deleted = 0
");
$stmt_vis->execute([$subtopic_id]);
$sub = $stmt_vis->fetch();

if (!$sub) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'Subtopic not found.'];
    header('Location: sessions.php');
    exit;
}

$vis_sections = json_decode($sub['visible_for']     ?? '[]', true) ?: [];
$vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
$req_sections = json_decode($sub['required_for']    ?? '[]', true) ?: [];
$req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];

$allowed = false;

// Public subtopic
if (empty($vis_sections) && empty($vis_courses) && empty($req_sections) && empty($req_courses)) {
    $allowed = true;
}

// Required for this section/program
if (in_array($student_section, $req_sections) || in_array($student_program, $req_courses)) {
    $allowed = true;
}

// Explicitly visible
if (in_array($student_section, $vis_sections) || in_array($student_program, $vis_courses)) {
    $allowed = true;
}

if (!$allowed) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'This subtopic is not available for your program/section.'];
    header('Location: sessions.php');
    exit;
}
// ---------- END VISIBILITY GATE ----------

// ---------- ONE SUBTOPIC PER SESSION ----------
$stmt_allow = $pdo->prepare("SELECT allow_multiple FROM sessions WHERE session_id = ?");
$stmt_allow->execute([$session_id]);
$allow_multiple = (bool) $stmt_allow->fetchColumn();

if (!$allow_multiple) {
    $stmt_existing = $pdo->prepare("
        SELECT r.registration_id, r.subtopic_id AS existing_subtopic_id, st.title AS existing_title
        FROM registrations r
        JOIN subtopics st ON r.subtopic_id = st.subtopic_id
        WHERE st.session_id = ? AND r.student_id = ?
        LIMIT 1
    ");
    $stmt_existing->execute([$session_id, $student_id]);
    $existing = $stmt_existing->fetch();

    if ($existing) {
        $replace = isset($_POST['replace']) && $_POST['replace'] === '1';

        if (!$replace) {
            $_SESSION['flash'] = [
                'type' => 'warning',
                'text' => "You already have \"{$existing['existing_title']}\" in this session. Confirm to switch."
            ];
            header('Location: sessions.php');
            exit;
        }

        // Swap: remove old registration + any linked attendance
        $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND subtopic_id = ?")
            ->execute([$student_id, $existing['existing_subtopic_id']]);
        $pdo->prepare("DELETE FROM registrations WHERE registration_id = ?")
            ->execute([$existing['registration_id']]);
    }
}
// ---------- END ONE PER SESSION ----------

// ---------- CAPACITY CHECK ----------
$stmt = $pdo->prepare("SELECT capacity FROM subtopics WHERE subtopic_id = ?");
$stmt->execute([$subtopic_id]);
$capacity = (int)$stmt->fetchColumn();

$stmt2 = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE subtopic_id = ?");
$stmt2->execute([$subtopic_id]);
$current = (int)$stmt2->fetchColumn();

if ($capacity > 0 && $current >= $capacity) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'This subtopic is already full.'];
    header('Location: sessions.php');
    exit;
}
// ---------- END CAPACITY ----------

// ---------- INSERT REGISTRATION ----------
$stmt3 = $pdo->prepare("
    INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
    VALUES (?, ?, ?, 'assigned', NOW())
");
$stmt3->execute([$student_id, $session_id, $subtopic_id]);

$_SESSION['flash'] = ['type' => 'success', 'text' => 'Successfully registered for the subtopic!'];
header('Location: sessions.php');
exit;