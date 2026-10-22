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

$has_vis_restrictions = !empty($vis_sections) || !empty($vis_courses);
$has_req_restrictions = !empty($req_sections) || !empty($req_courses);

// Public subtopic (no restrictions at all)
if (!$has_vis_restrictions && !$has_req_restrictions) {
    $allowed = true;
}

// Check required gate (AND logic: must match all specified filters)
if ($has_req_restrictions) {
    $match_req = true;
    if (!empty($req_courses) && !in_array($student_program, $req_courses)) $match_req = false;
    if (!empty($req_sections) && !in_array($student_section, $req_sections)) $match_req = false;
    if ($match_req) $allowed = true;
}

// Check visibility gate (AND logic)
if ($has_vis_restrictions) {
    $match_vis = true;
    if (!empty($vis_courses) && !in_array($student_program, $vis_courses)) $match_vis = false;
    if (!empty($vis_sections) && !in_array($student_section, $vis_sections)) $match_vis = false;
    if ($match_vis) $allowed = true;
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

// Track existing registration for deferred swap (do NOT delete yet)
$existing = null;
if (!$allow_multiple) {
    $stmt_existing = $pdo->prepare("
        SELECT r.registration_id, r.subtopic_id AS existing_subtopic_id, st.title AS existing_title,
               st.required_for, st.required_courses, st.is_required
        FROM registrations r
        JOIN subtopics st ON r.subtopic_id = st.subtopic_id
        WHERE st.session_id = ? AND r.student_id = ?
        LIMIT 1
    ");
    $stmt_existing->execute([$session_id, $student_id]);
    $existing = $stmt_existing->fetch();

    if ($existing) {
        // ---- BLOCK: Cannot swap OUT of a REQUIRED subtopic ----
        $existing_req_sections = json_decode($existing['required_for'] ?? '[]', true) ?: [];
        $existing_req_courses  = json_decode($existing['required_courses'] ?? '[]', true) ?: [];
        
        $existing_is_required = false;
        if ($existing['is_required']) {
            $existing_is_required = true;
        } else {
            $match_req = true;
            if (!empty($existing_req_courses) && !in_array($student_program, $existing_req_courses)) $match_req = false;
            if (!empty($existing_req_sections) && !in_array($student_section, $existing_req_sections)) $match_req = false;
            if ($match_req && (!empty($existing_req_courses) || !empty($existing_req_sections))) {
                $existing_is_required = true;
            }
        }
        
        if ($existing_is_required) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'text' => "You cannot switch out of \"{$existing['existing_title']}\" — it is a required subtopic for your program/section."
            ];
            header('Location: sessions.php');
            exit;
        }
        // ---- END REQUIRED BLOCK ----

        $replace = isset($_POST['replace']) && $_POST['replace'] === '1';

        if (!$replace) {
            $_SESSION['flash'] = [
                'type' => 'warning',
                'text' => "You already have \"{$existing['existing_title']}\" in this session. Confirm to switch."
            ];
            header('Location: sessions.php');
            exit;
        }
        // NOTE: Deletion deferred to the transaction block below (safer)
    }
}
// ---------- END ONE PER SESSION ----------

// ---------- CAPACITY CHECK (Global + Per-Section) ----------
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

// Per-section capacity enforcement (using program-section compound key)
$section_key = $student_program . '-' . $student_section;
$stmt_sec_cap = $pdo->prepare("
    SELECT capacity FROM subtopic_section_capacity 
    WHERE subtopic_id = ? AND section = ?
");
$stmt_sec_cap->execute([$subtopic_id, $section_key]);
$section_cap = $stmt_sec_cap->fetchColumn();

if ($section_cap !== false && (int)$section_cap > 0) {
    $stmt_sec_count = $pdo->prepare("
        SELECT COUNT(*) FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        WHERE r.subtopic_id = ? AND s.section = ? AND s.program = ?
    ");
    $stmt_sec_count->execute([$subtopic_id, $student_section, $student_program]);
    $section_current = (int)$stmt_sec_count->fetchColumn();

    if ($section_current >= (int)$section_cap) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'All slots for your section are full. Please choose another subtopic.'];
        header('Location: sessions.php');
        exit;
    }
}
// ---------- END CAPACITY ----------

// ---------- TRANSACTIONAL SWAP + INSERT ----------
try {
    $pdo->beginTransaction();

    // 1. If swapping, delete the old registration + related data (NOW it's safe)
    if ($existing) {
        $pdo->prepare("
            DELETE smp FROM student_module_progress smp
            JOIN modules m ON smp.module_id = m.module_id
            WHERE smp.student_id = ? AND m.subtopic_id = ?
        ")->execute([$student_id, $existing['existing_subtopic_id']]);

        $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND subtopic_id = ?")
            ->execute([$student_id, $existing['existing_subtopic_id']]);

        $pdo->prepare("DELETE FROM registrations WHERE registration_id = ?")
            ->execute([$existing['registration_id']]);
    }

    // 2. Re-verify capacity INSIDE the transaction (row-locked count)
    $stmt_recheck = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE subtopic_id = ? FOR UPDATE");
    $stmt_recheck->execute([$subtopic_id]);
    $current_locked = (int)$stmt_recheck->fetchColumn();

    if ($capacity > 0 && $current_locked >= $capacity) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'This subtopic just filled up. Please try another.'];
        header('Location: sessions.php');
        exit;
    }

    // 3. Insert the new registration
    $stmt3 = $pdo->prepare("
        INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
        VALUES (?, ?, ?, 'assigned', NOW())
    ");
    $stmt3->execute([$student_id, $session_id, $subtopic_id]);

    // 4. Create pending attendance record
    $att_check = $pdo->prepare("
        SELECT attendance_id FROM attendance 
        WHERE student_id = ? AND subtopic_id = ?
    ");
    $att_check->execute([$student_id, $subtopic_id]);
    if (!$att_check->fetch()) {
        $pdo->prepare("
            INSERT INTO attendance 
                (student_id, session_id, subtopic_id, attendance_date, attendance_status, validated, recorded_by, is_deleted)
            VALUES 
                (?, ?, ?, CURDATE(), 'pending', 0, NULL, 0)
        ")->execute([$student_id, $session_id, $subtopic_id]);
    }

    $pdo->commit();

    $_SESSION['flash'] = ['type' => 'success', 'text' => 'Successfully registered for the subtopic!'];
    header('Location: sessions.php');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Registration error: " . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'Registration failed. Please try again.'];
    header('Location: sessions.php');
    exit;
}