<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();
if (isViewer() && isset($_POST['action'])) {
    header('Location: subtopics.php?error=Access denied');
    exit;
}

// ---- CSRF check on any POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'];

$action = $_POST['action'] ?? '';
$success = '';
$error = '';

if ($action === 'create') {
    $title = trim($_POST['title']);
    $phase = $_POST['phase'];
    $description = trim($_POST['description']);

    if (!$title || !$phase) {
        $error = "Title and phase are required.";
    } else {
        $allow_multiple = isset($_POST['allow_multiple']) ? 1 : 0;
        
        // --- IMAGE UPLOAD LOGIC ---
        $image_path = null;
        if (isset($_FILES['session_image']) && $_FILES['session_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['session_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = uniqid('sess_') . '.' . $ext;
                $upload_dir = __DIR__ . '/../assets/images/sessions/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                if (move_uploaded_file($_FILES['session_image']['tmp_name'], $upload_dir . $filename)) {
                    $image_path = 'assets/images/sessions/' . $filename;
                }
            }
        }
        // -------------------------

        $session_date = !empty($_POST['session_date']) ? $_POST['session_date'] : date('Y-m-d');
        $session_start = !empty($_POST['session_start_time']) ? $_POST['session_start_time'] : '08:00:00';
        $session_end = !empty($_POST['session_end_time']) ? $_POST['session_end_time'] : '17:00:00';

        $stmt = $pdo->prepare("INSERT INTO sessions (title, phase, date, start_time, end_time, description, allow_multiple, created_by, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $phase, $session_date, $session_start, $session_end, $description, $allow_multiple, $_SESSION['user_id'], $image_path]);
        $session_id = $pdo->lastInsertId();

        $subtopics_json = $_POST['subtopics_data'] ?? '';
        if ($subtopics_json) {
            $subtopics = json_decode($subtopics_json, true);
            foreach ($subtopics as $index => $sub) {
                $sub_title = trim($sub['title']);
                if (!$sub_title) continue;
                $sub_desc = trim($sub['description']);
                $capacity = (int)($sub['capacity'] ?? 0);
                $deadline = $sub['deadline'] ?: null;
                $sub_date = $sub['date'] ?: null;
                $sub_start = $sub['start_time'] ?: null;
                $sub_end = $sub['end_time'] ?: null;
                $sub_proctor = trim($sub['proctor']);
                $sub_location = trim($sub['location']);
                $is_required = !empty($sub['is_required']) ? 1 : 0;
                $attendance_type = $sub['attendance_type'] ?? 'physical';
                $visible_sections = isset($sub['visible_sections']) ? json_encode($sub['visible_sections']) : null;
                $visible_courses = isset($sub['visible_courses']) ? json_encode($sub['visible_courses']) : null;
                $required_sections = isset($sub['required_sections']) ? json_encode($sub['required_sections']) : null;
                $required_courses = isset($sub['required_courses']) ? json_encode($sub['required_courses']) : null;

                // --- SUBTOPIC IMAGE UPLOAD ---
                $sub_image_path = null;
                if (isset($_FILES["subtopic_image_$index"]) && $_FILES["subtopic_image_$index"]['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES["subtopic_image_$index"]['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $filename = uniqid('sub_') . '.' . $ext;
                        $upload_dir = __DIR__ . '/../assets/images/subtopics/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                        if (move_uploaded_file($_FILES["subtopic_image_$index"]['tmp_name'], $upload_dir . $filename)) {
                            $sub_image_path = 'assets/images/subtopics/' . $filename;
                        }
                    }
                }

                $stmt_sub = $pdo->prepare("
                    INSERT INTO subtopics (
                        session_id, title, description, capacity, deadline,
                        subtopic_date, subtopic_start_time, subtopic_end_time,
                        subtopic_proctor, subtopic_location, is_required,
                        visible_for, required_for, visible_courses, required_courses,
                        attendance_type, image_path
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_sub->execute([
                    $session_id, $sub_title, $sub_desc, $capacity, $deadline,
                    $sub_date, $sub_start, $sub_end, $sub_proctor, $sub_location, $is_required,
                    $visible_sections, $required_sections, $visible_courses, $required_courses,
                    $attendance_type, $sub_image_path
                ]);
                
                $new_subtopic_id = $pdo->lastInsertId();

                // --- AUTO ASSIGN FOR REQUIRED SUBTOPICS ---
                if ($is_required || !empty($sub['required_courses']) || !empty($sub['required_sections'])) {
                    $student_query = "SELECT student_id FROM students WHERE is_deleted = 0";
                    $s_params = [];
                    
                    if (!$is_required) {
                        if (!empty($sub['required_courses'])) {
                            $ph = implode(',', array_fill(0, count($sub['required_courses']), '?'));
                            $student_query .= " AND program IN ($ph)";
                            $s_params = array_merge($s_params, $sub['required_courses']);
                        }
                        if (!empty($sub['required_sections'])) {
                            $ph = implode(',', array_fill(0, count($sub['required_sections']), '?'));
                            $student_query .= " AND section IN ($ph)";
                            $s_params = array_merge($s_params, $sub['required_sections']);
                        }
                    }
                    
                    // NEW: Enforce allow_multiple
                    $stmt_allow = $pdo->prepare("SELECT allow_multiple FROM sessions WHERE session_id = ?");
                    $stmt_allow->execute([$session_id]);
                    $allow_multiple = (bool)$stmt_allow->fetchColumn();
                    
                    if (!$allow_multiple) {
                        $student_query .= " AND NOT EXISTS (
                            SELECT 1 FROM registrations r2 
                            JOIN subtopics st2 ON r2.subtopic_id = st2.subtopic_id 
                            WHERE r2.student_id = students.student_id 
                              AND st2.session_id = ? 
                              AND r2.status IN ('assigned','auto_assigned')
                        )";
                        $s_params[] = $session_id;
                    }
                    
                    $stmt_s = $pdo->prepare($student_query);
                    $stmt_s->execute($s_params);
                    $students_to_assign = $stmt_s->fetchAll(PDO::FETCH_COLUMN);
                    
                    // --- NEW: CAPACITY CHECK ---
                    if (!empty($students_to_assign) && $capacity > 0) {
                        // Count how many are already registered (e.g., if updating an existing subtopic)
                        $stmt_reg_count = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE subtopic_id = ? AND status IN ('assigned', 'auto_assigned')");
                        $stmt_reg_count->execute([$new_subtopic_id]);
                        $current_regs = (int)$stmt_reg_count->fetchColumn();
                        
                        // Calculate how many slots are left and slice the student array
                        $remaining_slots = max(0, $capacity - $current_regs);
                        $students_to_assign = array_slice($students_to_assign, 0, $remaining_slots);
                    }
                    // ----------------------------

                    if (!empty($students_to_assign)) {
                        // 1. Batch Insert Registrations (1 query instead of 2300)
                        $reg_placeholders = [];
                        $reg_values = [];
                        foreach ($students_to_assign as $sid) {
                            $reg_placeholders[] = "(?, ?, ?, 'assigned', NOW())";
                            array_push($reg_values, $sid, $session_id, $new_subtopic_id);
                        }
                        $sql_reg = "INSERT IGNORE INTO registrations (student_id, session_id, subtopic_id, status, registration_date) VALUES " . implode(',', $reg_placeholders);
                        $pdo->prepare($sql_reg)->execute($reg_values);

                        // 2. Fetch existing attendance (1 query)
                        $stmt_att_check = $pdo->prepare("SELECT student_id FROM attendance WHERE subtopic_id = ?");
                        $stmt_att_check->execute([$new_subtopic_id]);
                        $existing_att = $stmt_att_check->fetchAll(PDO::FETCH_COLUMN);

                        // 3. Batch Insert Attendance (1 query instead of 2300)
                        $att_placeholders = [];
                        $att_values = [];
                        foreach ($students_to_assign as $sid) {
                            if (!in_array($sid, $existing_att)) {
                                $att_placeholders[] = "(?, ?, ?, CURDATE(), 'pending', 0, 0)";
                                array_push($att_values, $sid, $session_id, $new_subtopic_id);
                            }
                        }
                        if (!empty($att_placeholders)) {
                            $sql_att = "INSERT IGNORE INTO attendance (student_id, session_id, subtopic_id, attendance_date, attendance_status, validated, is_deleted) VALUES " . implode(',', $att_placeholders);
                            $pdo->prepare($sql_att)->execute($att_values);
                        }
                    }
                }
                // --- END AUTO ASSIGN ---
            }
        }
        $success = "Session created successfully.";
    }

} elseif ($action === 'update') {
    $id = (int)$_POST['id'];
    $title = trim($_POST['title']);
    $phase = $_POST['phase'];
    $description = trim($_POST['description']);

    if (!$title || !$phase) {
        $error = "Title and phase are required.";
    } else {
        $allow_multiple = isset($_POST['allow_multiple']) ? 1 : 0;
        
        // --- IMAGE UPLOAD LOGIC ---
        $image_update_sql = "";
        $image_params = [];
        if (isset($_FILES['session_image']) && $_FILES['session_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['session_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = uniqid('sess_') . '.' . $ext;
                $upload_dir = __DIR__ . '/../assets/images/sessions/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                if (move_uploaded_file($_FILES['session_image']['tmp_name'], $upload_dir . $filename)) {
                    $image_path = 'assets/images/sessions/' . $filename;
                    $image_update_sql = ", image_path = ?";
                    $image_params[] = $image_path;
                }
            }
        }
        // -------------------------

        $session_date = !empty($_POST['session_date']) ? $_POST['session_date'] : date('Y-m-d');
        $session_start = !empty($_POST['session_start_time']) ? $_POST['session_start_time'] : '08:00:00';
        $session_end = !empty($_POST['session_end_time']) ? $_POST['session_end_time'] : '17:00:00';

        // Update the main session
        $stmt = $pdo->prepare("UPDATE sessions SET title = ?, phase = ?, date = ?, start_time = ?, end_time = ?, description = ?, allow_multiple = ? {$image_update_sql} WHERE session_id = ?");
        $params = array_merge([$title, $phase, $session_date, $session_start, $session_end, $description, $allow_multiple], $image_params, [$id]);
        $stmt->execute($params);

        $subtopics_json = $_POST['subtopics_data'] ?? '';
        $kept_subtopic_ids = [];
        
        if ($subtopics_json) {
            $subtopics = json_decode($subtopics_json, true);
            foreach ($subtopics as $index => $sub) {
                $sub_title = trim($sub['title']);
                if (!$sub_title) continue;
                
                $subtopic_id = !empty($sub['subtopic_id']) ? (int)$sub['subtopic_id'] : null;
                
                $sub_desc = trim($sub['description']);
                $capacity = (int)($sub['capacity'] ?? 0);
                $deadline = $sub['deadline'] ?: null;
                $sub_date = $sub['date'] ?: null;
                $sub_start = $sub['start_time'] ?: null;
                $sub_end = $sub['end_time'] ?: null;
                $sub_proctor = trim($sub['proctor']);
                $sub_location = trim($sub['location']);
                $is_required = !empty($sub['is_required']) ? 1 : 0;
                $attendance_type = $sub['attendance_type'] ?? 'physical';
                $visible_sections = isset($sub['visible_sections']) ? json_encode($sub['visible_sections']) : null;
                $visible_courses = isset($sub['visible_courses']) ? json_encode($sub['visible_courses']) : null;
                $required_sections = isset($sub['required_sections']) ? json_encode($sub['required_sections']) : null;
                $required_courses = isset($sub['required_courses']) ? json_encode($sub['required_courses']) : null;

                // --- SUBTOPIC IMAGE UPLOAD ---
                $sub_image_path = null;
                if (isset($_FILES["subtopic_image_$index"]) && $_FILES["subtopic_image_$index"]['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES["subtopic_image_$index"]['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $filename = uniqid('sub_') . '.' . $ext;
                        $upload_dir = __DIR__ . '/../assets/images/subtopics/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                        if (move_uploaded_file($_FILES["subtopic_image_$index"]['tmp_name'], $upload_dir . $filename)) {
                            $sub_image_path = 'assets/images/subtopics/' . $filename;
                        }
                    }
                }

                if ($subtopic_id) {
                    // UPDATE (COALESCE keeps the old image if $sub_image_path is NULL)
                    $stmt_sub = $pdo->prepare("
                        UPDATE subtopics SET 
                            title=?, description=?, capacity=?, deadline=?,
                            subtopic_date=?, subtopic_start_time=?, subtopic_end_time=?,
                            subtopic_proctor=?, subtopic_location=?, is_required=?,
                            visible_for=?, required_for=?, visible_courses=?, required_courses=?,
                            attendance_type=?, image_path = COALESCE(?, image_path), is_deleted=0
                        WHERE subtopic_id=? AND session_id=?
                    ");
                    $stmt_sub->execute([
                        $sub_title, $sub_desc, $capacity, $deadline,
                        $sub_date, $sub_start, $sub_end, $sub_proctor, $sub_location, $is_required,
                        $visible_sections, $required_sections, $visible_courses, $required_courses,
                        $attendance_type, $sub_image_path, $subtopic_id, $id
                    ]);
                } else {
                    // INSERT
                    $stmt_sub = $pdo->prepare("
                        INSERT INTO subtopics (
                            session_id, title, description, capacity, deadline,
                            subtopic_date, subtopic_start_time, subtopic_end_time,
                            subtopic_proctor, subtopic_location, is_required,
                            visible_for, required_for, visible_courses, required_courses,
                            attendance_type, image_path
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt_sub->execute([
                        $id, $sub_title, $sub_desc, $capacity, $deadline,
                        $sub_date, $sub_start, $sub_end, $sub_proctor, $sub_location, $is_required,
                        $visible_sections, $required_sections, $visible_courses, $required_courses,
                        $attendance_type, $sub_image_path
                    ]);
                    $subtopic_id = $pdo->lastInsertId();
                }

                // Track the actual ID (after INSERT/UPDATE so we have the real one)
                $kept_subtopic_ids[] = $subtopic_id;

                // --- AUTO ASSIGN FOR REQUIRED SUBTOPICS ---
                if ($is_required || !empty($sub['required_courses']) || !empty($sub['required_sections'])) {
                    $student_query = "SELECT student_id FROM students WHERE is_deleted = 0";
                    $s_params = [];
                    
                    if (!$is_required) {
                        if (!empty($sub['required_courses'])) {
                            $ph = implode(',', array_fill(0, count($sub['required_courses']), '?'));
                            $student_query .= " AND program IN ($ph)";
                            $s_params = array_merge($s_params, $sub['required_courses']);
                        }
                        if (!empty($sub['required_sections'])) {
                            $ph = implode(',', array_fill(0, count($sub['required_sections']), '?'));
                            $student_query .= " AND section IN ($ph)";
                            $s_params = array_merge($s_params, $sub['required_sections']);
                        }
                    }
                    
                    // NEW: Enforce allow_multiple
                    $stmt_allow = $pdo->prepare("SELECT allow_multiple FROM sessions WHERE session_id = ?");
                    $stmt_allow->execute([$id]);
                    $allow_multiple = (bool)$stmt_allow->fetchColumn();
                    
                    if (!$allow_multiple) {
                        $student_query .= " AND NOT EXISTS (
                            SELECT 1 FROM registrations r2 
                            JOIN subtopics st2 ON r2.subtopic_id = st2.subtopic_id 
                            WHERE r2.student_id = students.student_id 
                              AND st2.session_id = ? 
                              AND r2.status IN ('assigned','auto_assigned')
                        )";
                        $s_params[] = $id;
                    }
                    
                    $stmt_s = $pdo->prepare($student_query);
                    $stmt_s->execute($s_params);
                    $students_to_assign = $stmt_s->fetchAll(PDO::FETCH_COLUMN);
                    
                    // --- NEW: CAPACITY CHECK ---
                    if (!empty($students_to_assign) && $capacity > 0) {
                        // Count how many are already registered
                        $stmt_reg_count = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE subtopic_id = ? AND status IN ('assigned', 'auto_assigned')");
                        $stmt_reg_count->execute([$subtopic_id]);
                        $current_regs = (int)$stmt_reg_count->fetchColumn();
                        
                        // Calculate how many slots are left and slice the student array
                        $remaining_slots = max(0, $capacity - $current_regs);
                        $students_to_assign = array_slice($students_to_assign, 0, $remaining_slots);
                    }
                    // ----------------------------

                    if (!empty($students_to_assign)) {
                        // 1. Batch Insert Registrations
                        $reg_placeholders = [];
                        $reg_values = [];
                        foreach ($students_to_assign as $sid) {
                            $reg_placeholders[] = "(?, ?, ?, 'assigned', NOW())";
                            array_push($reg_values, $sid, $id, $subtopic_id);
                        }
                        $sql_reg = "INSERT IGNORE INTO registrations (student_id, session_id, subtopic_id, status, registration_date) VALUES " . implode(',', $reg_placeholders);
                        $pdo->prepare($sql_reg)->execute($reg_values);

                        // 2. Fetch existing attendance
                        $stmt_att_check = $pdo->prepare("SELECT student_id FROM attendance WHERE subtopic_id = ?");
                        $stmt_att_check->execute([$subtopic_id]);
                        $existing_att = $stmt_att_check->fetchAll(PDO::FETCH_COLUMN);

                        // 3. Batch Insert Attendance
                        $att_placeholders = [];
                        $att_values = [];
                        foreach ($students_to_assign as $sid) {
                            if (!in_array($sid, $existing_att)) {
                                $att_placeholders[] = "(?, ?, ?, CURDATE(), 'pending', 0, 0)";
                                array_push($att_values, $sid, $id, $subtopic_id);
                            }
                        }
                        if (!empty($att_placeholders)) {
                            $sql_att = "INSERT IGNORE INTO attendance (student_id, session_id, subtopic_id, attendance_date, attendance_status, validated, is_deleted) VALUES " . implode(',', $att_placeholders);
                            $pdo->prepare($sql_att)->execute($att_values);
                        }
                    }
                }
                // --- END AUTO ASSIGN ---
            }
        }
        
        // Soft delete removed subtopics
        $kept_ids_str = implode(',', array_filter($kept_subtopic_ids, fn($v) => $v !== null));
        if ($kept_ids_str) {
            $pdo->prepare("UPDATE subtopics SET is_deleted = 1 WHERE session_id = ? AND subtopic_id NOT IN ($kept_ids_str)")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE subtopics SET is_deleted = 1 WHERE session_id = ?")->execute([$id]);
        }
        $success = "Session updated successfully.";
    }

} elseif ($action === 'soft_delete') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE sessions SET is_deleted = 1 WHERE session_id = ?");
    $stmt->execute([$id]);
    $success = "Session moved to archives.";

} elseif ($action === 'restore') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE sessions SET is_deleted = 0 WHERE session_id = ?");
    $stmt->execute([$id]);
    $success = "Session restored.";

} elseif ($action === 'hard_delete') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("DELETE FROM sessions WHERE session_id = ?");
    $stmt->execute([$id]);
    $success = "Session permanently deleted.";

} elseif ($action === 'even_split') {
    $session_id = (int)$_POST['session_id'];

    $stmt = $pdo->prepare("SELECT subtopic_id FROM subtopics WHERE session_id = ? AND is_deleted = 0");
    $stmt->execute([$session_id]);
    $subtopic_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($subtopic_ids)) {
        $error = "No subtopics to distribute.";
    } else {
        $num_subtopics = count($subtopic_ids);
        foreach ($subtopic_ids as $sub_id) {
            // Fetch this subtopic's visibility rules
            $stmt_vis = $pdo->prepare("SELECT visible_courses, required_courses FROM subtopics WHERE subtopic_id = ?");
            $stmt_vis->execute([$sub_id]);
            $vis = $stmt_vis->fetch();
            $vis_courses = json_decode($vis['visible_courses'] ?? '[]', true) ?: [];
            $req_courses = json_decode($vis['required_courses'] ?? '[]', true) ?: [];

            // Build course filter
            $course_filter = array_unique(array_merge($vis_courses, $req_courses));
            
            if (empty($course_filter)) {
                // Public subtopic: count all active students
                $stmt = $pdo->query("SELECT program, section, COUNT(*) as cnt FROM students WHERE is_deleted = 0 GROUP BY program, section");
            } else {
                // Restricted subtopic: only count students in the allowed courses
                $placeholders = implode(',', array_fill(0, count($course_filter), '?'));
                $stmt = $pdo->prepare("SELECT program, section, COUNT(*) as cnt FROM students WHERE is_deleted = 0 AND program IN ($placeholders) GROUP BY program, section");
                $stmt->execute($course_filter);
            }
            
            $sections = $stmt->fetchAll();
            $total_cap = 0;

            // Clear previous capacities for this subtopic (so stale rows don't linger)
            $pdo->prepare("DELETE FROM subtopic_section_capacity WHERE subtopic_id = ?")->execute([$sub_id]);

            foreach ($sections as $row) {
                $section_key = $row['program'] . '-' . $row['section']; // Unique key combining program + section
                $cnt = (int)$row['cnt'];
                $per_subtopic = ceil($cnt / $num_subtopics);
                $total_cap += $per_subtopic;
                
                $stmt_cap = $pdo->prepare("
                    INSERT INTO subtopic_section_capacity (subtopic_id, section, capacity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE capacity = ?
                ");
                $stmt_cap->execute([$sub_id, $section_key, $per_subtopic, $per_subtopic]);
            }
            $stmt_upd = $pdo->prepare("UPDATE subtopics SET capacity = ? WHERE subtopic_id = ?");
            $stmt_upd->execute([$total_cap, $sub_id]);
        }
        $success = "Even distribution applied.";
    }
} elseif ($action === 'get_counts') {
    $sections = isset($_POST['sections']) ? json_decode($_POST['sections'], true) : [];
    $courses = isset($_POST['courses']) ? json_decode($_POST['courses'], true) : [];

    $params = [];
    $sql = "SELECT COUNT(*) FROM students WHERE is_deleted = 0";
    if (!empty($sections)) {
        $placeholders = implode(',', array_fill(0, count($sections), '?'));
        $sql .= " AND section IN ($placeholders)";
        $params = array_merge($params, $sections);
    }
    if (!empty($courses)) {
        $placeholders = implode(',', array_fill(0, count($courses), '?'));
        $sql .= " AND program IN ($placeholders)";
        $params = array_merge($params, $courses);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->fetchColumn();
    echo json_encode(['count' => $count]);
    exit;
}

// Fetch UPCOMING sessions
$today = date('Y-m-d');
$stmt_upcoming = $pdo->prepare("
    SELECT s.*, (SELECT COUNT(*) FROM subtopics WHERE session_id = s.session_id AND is_deleted = 0) as subtopic_count
    FROM sessions s
    WHERE s.is_deleted = 0 AND s.date >= ?
    ORDER BY s.date ASC
");
$stmt_upcoming->execute([$today]);
$upcoming_sessions = $stmt_upcoming->fetchAll();

// Fetch PAST sessions
$stmt_past = $pdo->prepare("
    SELECT s.*, (SELECT COUNT(*) FROM subtopics WHERE session_id = s.session_id AND is_deleted = 0) as subtopic_count
    FROM sessions s
    WHERE s.is_deleted = 0 AND s.date < ?
    ORDER BY s.date DESC
");
$stmt_past->execute([$today]);
$past_sessions = $stmt_past->fetchAll();

// Fetch deleted sessions
$stmt_deleted = $pdo->prepare("SELECT * FROM sessions WHERE is_deleted = 1 ORDER BY date DESC");
$stmt_deleted->execute();
$deleted_sessions = $stmt_deleted->fetchAll();

// Get lists for dropdowns/checkboxes
$sections = $pdo->query("SELECT DISTINCT section FROM students ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
$courses = $pdo->query("SELECT DISTINCT program FROM students ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections_json = json_encode($sections);
$courses_json = json_encode($courses);

// Build course → sections mapping (only sections that actually exist per course)
$course_sections = [];
$stmt_cs = $pdo->query("
    SELECT program, section 
    FROM students 
    WHERE is_deleted = 0 AND program IS NOT NULL AND section IS NOT NULL
    GROUP BY program, section
    ORDER BY program, section
");
foreach ($stmt_cs->fetchAll() as $row) {
    $course_sections[$row['program']][] = $row['section'];
}
$course_sections_json = json_encode($course_sections);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Modal Backdrop Fix */
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }

        /* Custom Accordion Animation */
        .accordion-content {
            transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out;
            max-height: 1000px;
            opacity: 1;
            overflow: hidden;
        }
        .accordion-content.collapsed {
            max-height: 0;
            opacity: 0;
        }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-[#0a6e2d]">Sessions & Subtopics</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage activities, capacities, and student visibility.</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if (!isViewer()): ?>
                        <button class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg inline-flex items-center shadow-sm transition-colors" type="button" data-bs-toggle="modal" data-bs-target="#notifyModal">
                            <i class="fas fa-envelope mr-2"></i> Notify Students
                        </button>
                        <button class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium py-2 px-4 rounded-lg inline-flex items-center shadow-sm transition-colors" data-bs-toggle="modal" data-bs-target="#createModal">
                            <i class="fas fa-plus mr-2"></i> Create Session
                        </button>
                    <?php endif; ?>
                    <a href="dashboard.php" class="text-[#0a6e2d] bg-white p-2 rounded-full shadow-sm hover:shadow-md transition-shadow">
                        <i class="fa-solid fa-house text-xl"></i>
                    </a>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                    <button type="button" class="ml-auto text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php elseif ($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="ml-auto text-red-700 hover:text-red-900" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- UPCOMING SESSIONS GRID -->
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-calendar-alt text-[#0a6e2d] mr-2"></i>Upcoming Sessions</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 mb-10">
                <?php foreach ($upcoming_sessions as $session): ?>
                    <?php
                    $stmt_sub = $pdo->prepare("SELECT * FROM subtopics WHERE session_id = ? AND is_deleted = 0 ORDER BY title");
                    $stmt_sub->execute([$session['session_id']]);
                    $subtopics = $stmt_sub->fetchAll();
                    
                    // Phase color mapping
                    $phaseColors = [
                        'Preparation' => 'bg-blue-100 text-blue-800 border-blue-200',
                        'Pre-Employment' => 'bg-purple-100 text-purple-800 border-purple-200',
                        'Career Fair' => 'bg-amber-100 text-amber-800 border-amber-200'
                    ];
                    $phaseClass = $phaseColors[$session['phase']] ?? 'bg-gray-100 text-gray-800 border-gray-200';
                    ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col h-full hover:shadow-md transition-shadow">
                        <!-- Card Header -->
                        <div class="p-5 border-b border-gray-100 bg-gray-50/50">
                            <div class="flex justify-between items-start mb-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wide border <?= $phaseClass ?>">
                                    <?= htmlspecialchars($session['phase']) ?>
                                </span>
                                <div class="flex gap-1">
                                    <?php if (!isViewer() && (isAdmin() || $session['created_by'] == $_SESSION['user_id'])): ?>
                                        <button class="notify-session-btn text-gray-400 hover:text-blue-600 p-1.5 rounded-md hover:bg-blue-50 transition-colors" data-session-id="<?= $session['session_id'] ?>" title="Notify students"><i class="fas fa-envelope"></i></button>
                                        <!-- CHANGED TO VIEW BUTTON -->
                                        <button class="view-session text-gray-400 hover:text-green-600 p-1.5 rounded-md hover:bg-green-50 transition-colors" data-id="<?= $session['session_id'] ?>" title="View session"><i class="fas fa-eye"></i></button>
                                        <button class="delete-session text-gray-400 hover:text-red-600 p-1.5 rounded-md hover:bg-red-50 transition-colors" data-id="<?= $session['session_id'] ?>" title="Archive"><i class="fas fa-trash-alt"></i></button>
                                    <?php elseif (!isViewer() && !isAdmin() && $session['created_by'] != $_SESSION['user_id']): ?>
                                        <span class="text-gray-400 text-xs italic" title="Created by another staff member">(other staff)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 leading-tight"><?= htmlspecialchars($session['title']) ?></h3>
                            
                            <!-- ADDED: Session Date Badge -->
                            <div class="text-xs font-bold text-[#0a6e2d] mt-2 mb-1">
                                <i class="far fa-calendar-alt mr-1"></i> <?= date('F d, Y', strtotime($session['date'])) ?>
                            </div>

                            <?php if ($session['description']): ?>
                                <p class="text-sm text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($session['description']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Subtopics List -->
                        <div class="p-5 flex-1 bg-white">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Subtopics (<?= count($subtopics) ?>)</h4>
                            </div>
                            
                            <?php if (empty($subtopics)): ?>
                                <div class="text-center py-6 text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-200">
                                    <i class="fas fa-folder-open text-2xl mb-2"></i>
                                    <p class="text-sm">No subtopics added yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($subtopics as $sub): ?>
                                        <div class="bg-gray-50 rounded-lg p-3 border border-gray-100 flex items-start justify-between gap-3">
                                            <div>
                                                <div class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($sub['title']) ?></div>
                                                <div class="flex flex-wrap gap-2 mt-2">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-white border border-gray-200 text-gray-600 shadow-sm">
                                                        <i class="fas fa-users mr-1 text-gray-400"></i> Cap: <?= $sub['capacity'] ?>
                                                    </span>
                                                    
                                                    <!-- ADDED COLOR-CODED ATTENDANCE BADGE -->
                                                    <?php 
                                                        $attType = $sub['attendance_type'] ?? 'physical';
                                                        $badgeClass = match($attType) {
                                                            'module' => 'bg-purple-100 text-purple-700 border-purple-200',
                                                            'online' => 'bg-blue-100 text-blue-700 border-blue-200',
                                                            default  => 'bg-green-100 text-green-700 border-green-200',
                                                        };
                                                        $badgeIcon = match($attType) {
                                                            'module' => 'fa-book',
                                                            'online' => 'fa-laptop',
                                                            default  => 'fa-building',
                                                        };
                                                    ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border <?= $badgeClass ?>">
                                                        <i class="fas <?= $badgeIcon ?> mr-1"></i> <?= ucfirst($attType) ?>
                                                    </span>
                                                    <!-- END BADGE -->
                                                    <?php if ($sub['deadline']): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-white border border-gray-200 text-gray-600 shadow-sm">
                                                            <i class="fas fa-clock mr-1 text-gray-400"></i> Due: <?= date('M d', strtotime($sub['deadline'])) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($sub['is_required']): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 border border-red-200">
                                                            <i class="fas fa-star mr-1"></i> Required
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($upcoming_sessions)): ?>
                    <div class="col-span-full text-center py-12 bg-white rounded-xl border border-gray-200 border-dashed">
                        <i class="fas fa-calendar-plus text-5xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900">No upcoming sessions</h3>
                        <p class="text-sm text-gray-500 mt-1">Click "Create Session" to get started.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PAST SESSIONS GRID -->
            <h2 class="text-xl font-bold text-gray-800 mb-4"><i class="fas fa-history text-gray-500 mr-2"></i>Past Sessions</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 mb-10 opacity-80 hover:opacity-100 transition-opacity">
                <?php foreach ($past_sessions as $session): ?>
                    <?php
                    $stmt_sub = $pdo->prepare("SELECT * FROM subtopics WHERE session_id = ? AND is_deleted = 0 ORDER BY title");
                    $stmt_sub->execute([$session['session_id']]);
                    $subtopics = $stmt_sub->fetchAll();
                    $phaseColors = [
                        'Preparation' => 'bg-blue-100 text-blue-800 border-blue-200',
                        'Pre-Employment' => 'bg-purple-100 text-purple-800 border-purple-200',
                        'Career Fair' => 'bg-amber-100 text-amber-800 border-amber-200'
                    ];
                    $phaseClass = $phaseColors[$session['phase']] ?? 'bg-gray-100 text-gray-800 border-gray-200';
                    ?>
                    <div class="bg-gray-50 rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col h-full hover:shadow-md transition-shadow">
                        <div class="p-5 border-b border-gray-200 bg-gray-100/50">
                            <div class="flex justify-between items-start mb-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wide border <?= $phaseClass ?>"><?= htmlspecialchars($session['phase']) ?></span>
                                <div class="flex gap-1">
                                    <?php if (!isViewer() && (isAdmin() || $session['created_by'] == $_SESSION['user_id'])): ?>
                                        <button class="view-session text-gray-400 hover:text-green-600 p-1.5 rounded-md hover:bg-green-50 transition-colors" data-id="<?= $session['session_id'] ?>" title="View session"><i class="fas fa-eye"></i></button>
                                        <button class="delete-session text-gray-400 hover:text-red-600 p-1.5 rounded-md hover:bg-red-50 transition-colors" data-id="<?= $session['session_id'] ?>" title="Archive"><i class="fas fa-trash-alt"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <h3 class="text-lg font-bold text-gray-700 leading-tight"><?= htmlspecialchars($session['title']) ?></h3>
                            <div class="text-xs font-bold text-gray-500 mt-2 mb-1"><i class="far fa-calendar-alt mr-1"></i> <?= date('F d, Y', strtotime($session['date'])) ?></div>
                        </div>
                        <div class="p-5 flex-1 bg-gray-50/50">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider">Subtopics (<?= count($subtopics) ?>)</h4>
                            </div>
                            <?php if (empty($subtopics)): ?>
                                <div class="text-center py-6 text-gray-400 bg-gray-100 rounded-lg border border-dashed border-gray-200"><p class="text-sm">No subtopics.</p></div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($subtopics as $sub): ?>
                                        <div class="bg-white rounded-lg p-3 border border-gray-200 flex items-start justify-between gap-3">
                                            <div>
                                                <div class="font-medium text-gray-700 text-sm"><?= htmlspecialchars($sub['title']) ?></div>
                                                <div class="flex flex-wrap gap-2 mt-2">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-gray-50 border border-gray-200 text-gray-500"><i class="fas fa-users mr-1 text-gray-400"></i> Cap: <?= $sub['capacity'] ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Archives Section -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div id="archiveHeader" class="px-6 py-4 flex justify-between items-center cursor-pointer hover:bg-gray-50 transition-colors">
                    <div class="flex items-center gap-2 font-bold text-gray-800">
                        <i class="fas fa-archive text-gray-400"></i> Archived Sessions 
                        <span class="bg-gray-100 text-gray-600 py-0.5 px-2.5 rounded-full text-xs font-semibold ml-1"><?= count($deleted_sessions) ?></span>
                    </div>
                    <i class="fas fa-chevron-down text-gray-400 transition-transform duration-200"></i>
                </div>
                
                <div id="archiveContent" class="hidden border-t border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                                    <th class="py-3 px-6">Session Title</th>
                                    <th class="py-3 px-6 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700 bg-white">
                                <?php foreach ($deleted_sessions as $session): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="py-4 px-6 font-medium text-gray-900"><?= htmlspecialchars($session['title']) ?></td>
                                        <td class="py-4 px-6 text-right">
                                            <div class="flex justify-end gap-2">
                                                <button class="restore-session bg-green-50 text-green-700 hover:bg-green-100 px-3 py-1 rounded-md text-xs font-medium transition-colors border border-green-200" data-id="<?= $session['session_id'] ?>"><i class="fas fa-undo-alt mr-1"></i> Restore</button>
                                                <button class="hard-delete-session bg-red-50 text-red-700 hover:bg-red-100 px-3 py-1 rounded-md text-xs font-medium transition-colors border border-red-200" data-id="<?= $session['session_id'] ?>"><i class="fas fa-times-circle mr-1"></i> Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($deleted_sessions)): ?>
                                    <tr><td colspan="2" class="text-center py-8 text-gray-500">No archived sessions.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ==================== NOTIFY MODAL ==================== -->
<div class="modal fade" id="notifyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-gray-50 border-b border-gray-200 p-4">
                <h5 class="text-lg font-bold text-gray-800"><i class="fas fa-envelope text-blue-500 mr-2"></i>Notify Students</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-6 bg-white">
                <form id="notifyForm" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Session <span class="text-red-500">*</span></label>
                        <select class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" id="notifySession" required>
                            <option value="">Select session…</option>
                            <?php foreach ($upcoming_sessions as $s): ?>
                                <option value="<?= $s['session_id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic (optional)</label>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 h-32 overflow-y-auto space-y-2" id="notifySubtopicGroup">
                            <p class="text-sm text-gray-500 italic">Select a session first to load subtopics...</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Course (optional) <span class="text-xs text-gray-500 font-normal">— check to enable sections</span></label>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 h-32 overflow-y-auto space-y-2" id="notifyCourseGroup">
                                <?php foreach ($courses as $c): ?>
                                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="<?= htmlspecialchars($c) ?>" class="notify-course-check rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]"> <span class="text-sm text-gray-700"><?= htmlspecialchars($c) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Section (optional)</label>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 h-32 overflow-y-auto space-y-2" id="notifySectionGroup">
                                <?php foreach ($sections as $sec): ?>
                                <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" value="<?= htmlspecialchars($sec) ?>" class="notify-section-check rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" disabled> <span class="text-sm text-gray-700"><?= htmlspecialchars($sec) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Custom Message (optional)</label>
                        <textarea class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" id="notifyMessage" rows="3" placeholder="Add any special instructions…"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-gray-50 border-t border-gray-200 p-4">
                <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="notifyForm" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm disabled:opacity-50" id="notifySendBtn" disabled>
                    <i class="fas fa-paper-plane mr-1"></i> Send Notifications
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== CREATE MODAL ==================== -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-[#0a6e2d] text-white p-4 border-0">
                <h5 class="text-lg font-bold"><i class="fas fa-plus-circle mr-2"></i>Create New Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-6 bg-gray-50">
                <form method="POST" id="createSessionForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">

                    <!-- Section 1: Session Details -->
                    <div class="bg-white rounded-xl p-5 mb-6 shadow-sm border border-gray-200">
                        <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-info-circle text-gray-400 mr-2"></i>Session Details</h6>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Session Title <span class="text-red-500">*</span></label>
                                <input type="text" name="title" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phase <span class="text-red-500">*</span></label>
                                <select name="phase" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                                    <option value="Preparation">Preparation</option>
                                    <option value="Pre-Employment">Pre-Employment</option>
                                    <option value="Career Fair">Career Fair</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Session Date <span class="text-red-500">*</span></label>
                                <input type="date" name="session_date" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Session Start Time <span class="text-red-500">*</span></label>
                                <input type="time" name="session_start_time" value="08:00" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Session End Time <span class="text-red-500">*</span></label>
                                <input type="time" name="session_end_time" value="17:00" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="Optional — describe the purpose of this session"></textarea>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Session Banner Image (Optional)</label>
                            <input type="file" name="session_image" accept="image/png, image/jpeg, image/webp" class="w-full border border-gray-300 rounded-lg p-2 bg-white text-sm focus:outline-none file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-[#0a6e2d] file:text-white hover:file:bg-[#054018]">
                            <p class="text-xs text-gray-500 mt-1">Recommended size: 1200x400px (JPG, PNG, WebP)</p>
                        </div>
                        <div class="mt-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="allow_multiple" value="1" id="create_allow_multiple" class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]">
                                <span class="text-sm text-gray-700">Allow multiple registrations per student in this session</span>
                            </label>
                        </div>
                    </div>

                    <!-- Section 2: Subtopics Builder -->
                    <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
                        <div class="flex justify-between items-center mb-4 pb-4 border-b border-gray-100">
                            <h6 class="font-bold text-gray-800 text-sm uppercase tracking-wider"><i class="fas fa-list-ul text-gray-400 mr-2"></i>Subtopics</h6>
                            <div class="flex gap-2">
                                <button type="button" id="even-split-create" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors border border-gray-300">
                                    <i class="fas fa-balance-scale mr-1 text-gray-500"></i> Even Split
                                </button>
                                <button type="button" id="add-subtopic" class="bg-[#0a6e2d] hover:bg-[#054018] text-white text-sm font-medium px-3 py-1.5 rounded-lg transition-colors shadow-sm">
                                    <i class="fas fa-plus mr-1"></i> Add Subtopic
                                </button>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mb-4">Each subtopic represents a specific activity within this session. Add at least one subtopic below.</p>
                        <div id="subtopics-container" class="space-y-4"></div>
                    </div>

                    <input type="hidden" name="subtopics_data" id="subtopics_data">
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium text-sm px-6 py-2.5 rounded-lg transition-colors" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium text-sm px-8 py-2.5 rounded-lg transition-colors shadow-sm">
                            <i class="fas fa-save mr-1"></i> Create Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ==================== SESSION VIEW/EDIT MODAL ==================== -->
<div class="modal fade" id="sessionModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-[#0a6e2d] text-white p-4 border-0">
                <h5 class="text-lg font-bold" id="sessionModalTitle"><i class="fas fa-eye mr-2"></i>View Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 md:p-6 bg-gray-50" id="sessionModalBody">
                <!-- Loaded dynamically via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- ==================== HIDDEN TEMPLATE ==================== -->
<div id="subtopic-template" style="display:none;">
    <div class="card mb-4 border border-gray-200 rounded-xl shadow-sm bg-white overflow-hidden">
        <!-- Subtopic Card Header -->
        <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 flex justify-between items-center">
            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Subtopic Configuration</span>
            <button type="button" class="text-red-500 hover:text-red-700 remove-subtopic text-sm font-medium"><i class="fas fa-trash-alt mr-1"></i> Remove</button>
        </div>
        
        <div class="p-4 md:p-5 space-y-4">
            <!-- Row 1: Title, Capacity, Deadline -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic Title <span class="text-red-500">*</span></label>
                    <input type="text" class="w-full border border-gray-300 rounded-lg p-2 subtopic-title focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="e.g., Resume Writing Workshop">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                    <input type="number" class="w-full border border-gray-300 rounded-lg p-2 subtopic-capacity focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="0" min="0">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Deadline</label>
                    <input type="datetime-local" class="w-full border border-gray-300 rounded-lg p-2 subtopic-deadline focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                </div>
            </div>

            <!-- Row 2: Date, Times, Proctor -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" class="w-full border border-gray-300 rounded-lg p-2 subtopic-date focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                    <input type="time" class="w-full border border-gray-300 rounded-lg p-2 subtopic-start-time focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                    <input type="time" class="w-full border border-gray-300 rounded-lg p-2 subtopic-end-time focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Proctor</label>
                    <input type="text" class="w-full border border-gray-300 rounded-lg p-2 subtopic-proctor focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="Facilitator name">
                </div>
            </div>

            <!-- Row 3: Location, Attendance Type -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                    <input type="text" class="w-full border border-gray-300 rounded-lg p-2 subtopic-location focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="e.g., Room 101">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Attendance Type</label>
                    <select class="w-full border border-gray-300 rounded-lg p-2 subtopic-attendance-type focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                        <option value="physical">Physical — student must be present</option>
                        <option value="online">Online — via Zoom/Google Meet</option>
                        <option value="module">Module — attendance marked when all modules complete</option>
                    </select>
                </div>
            </div>

            <!-- Row 4: Visibility & Requirements -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Visible to Courses</label>
                    <p class="text-[11px] text-gray-500 mb-2 leading-tight">Leave all unchecked to show this subtopic to <strong class="text-gray-700">ALL students</strong>.</p>
                    <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">
                        <?php foreach ($courses as $crs): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="<?= htmlspecialchars($crs) ?>" class="subtopic-visible-course rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]"> <?= htmlspecialchars($crs) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Visible to Sections</label>
                    <p class="text-xs text-gray-500 mb-2">Only checked sections will see this.</p>
                    <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">
                        <?php foreach ($sections as $sec): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="<?= htmlspecialchars($sec) ?>" class="subtopic-visible-section rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" disabled> <?= htmlspecialchars($sec) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Required for Courses</label>
                    <p class="text-[11px] text-gray-500 mb-2 leading-tight">Leave unchecked to make it <strong class="text-gray-700">Optional</strong>. Checked courses MUST attend.</p>
                    <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">
                        <?php foreach ($courses as $crs): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="<?= htmlspecialchars($crs) ?>" class="subtopic-required-course rounded border-gray-300 text-red-600 focus:ring-red-500"> <?= htmlspecialchars($crs) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Required for Sections</label>
                    <p class="text-xs text-gray-500 mb-2">Students in checked sections MUST attend.</p>
                    <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">
                        <?php foreach ($sections as $sec): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="<?= htmlspecialchars($sec) ?>" class="subtopic-required-section rounded border-gray-300 text-red-600 focus:ring-red-500" disabled> <?= htmlspecialchars($sec) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="flex items-center gap-2 cursor-pointer bg-red-50 border border-red-200 p-2 rounded-lg">
                    <input type="checkbox" class="subtopic-required-all rounded border-red-300 text-red-600 focus:ring-red-500 w-4 h-4">
                    <span class="text-sm font-bold text-red-700">Required for ALL students</span>
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea rows="2" class="w-full border border-gray-300 rounded-lg p-2 subtopic-description focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="Optional details about this subtopic"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic Image (Optional)</label>
                <input type="file" class="w-full border border-gray-300 rounded-lg p-2 subtopic-image bg-white text-sm focus:outline-none file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-[#0a6e2d] file:text-white hover:file:bg-[#054018]" accept="image/png, image/jpeg, image/webp">
                <p class="text-[11px] text-gray-500 mt-1">Will be displayed to students in the subtopic modal.</p>
            </div>
        </div>
    </div>
</div>
<!-- ==================== NOTIFY PROGRESS MODAL ==================== -->
<div class="modal fade" id="notifyProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-body p-8 text-center bg-white">
                <div class="mb-4">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 mb-3">
                        <i class="fas fa-paper-plane text-blue-600 text-2xl fa-bounce"></i>
                    </div>
                    <h5 class="text-xl font-bold text-gray-800">Sending Notifications</h5>
                    <p class="text-sm text-gray-500 mt-1">Please do not close this window.</p>
                </div>
                
                <!-- Progress Bar -->
                <div class="w-full bg-gray-200 rounded-full h-4 mb-2 overflow-hidden shadow-inner">
                    <div id="notifyProgressBar" class="bg-blue-600 h-4 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                
                <div class="flex justify-between text-sm font-bold text-gray-600 mt-2">
                    <span id="notifyProgressText">Connecting to SMTP...</span>
                    <span id="notifyProgressPercent">0%</span>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// CSRF token for JS-generated forms and fetch calls
const CSRF_TOKEN = '<?= csrf_token() ?>';

// Course → sections mapping (from PHP)
const COURSE_SECTIONS = <?= $course_sections_json ?>;

// ==================== BACKDROP CLEANUP ====================
document.addEventListener('hidden.bs.modal', function () {
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
});

// ==================== COURSE-FIRST HELPER ====================
function linkCourseToSections(cardElement) {
    const visCourses = cardElement.querySelectorAll('.subtopic-visible-course');
    const visSections = cardElement.querySelectorAll('.subtopic-visible-section');
    linkCheckboxGroups(visCourses, visSections);

    const reqCourses = cardElement.querySelectorAll('.subtopic-required-course');
    const reqSections = cardElement.querySelectorAll('.subtopic-required-section');
    linkCheckboxGroups(reqCourses, reqSections);
}

function linkCheckboxGroups(courseCheckboxes, sectionCheckboxes) {
    if (!courseCheckboxes.length || !sectionCheckboxes.length) return;

    function updateSections() {
        const checkedCourses = Array.from(courseCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);

        const validSections = new Set();
        checkedCourses.forEach(course => {
            (COURSE_SECTIONS[course] || []).forEach(sec => validSections.add(String(sec)));
        });

        sectionCheckboxes.forEach(cb => {
            const label = cb.closest('label');
            const isVisible = checkedCourses.length > 0 && validSections.has(String(cb.value));

            if (isVisible) {
                if (label) label.style.display = '';
                cb.disabled = false;
            } else {
                if (label) label.style.display = 'none';
                cb.disabled = true;
                cb.checked = false;
            }
        });
    }

    courseCheckboxes.forEach(cb => cb.addEventListener('change', updateSections));
    updateSections();
}

// ==================== NOTIFY MODAL COURSE-FIRST LINKAGE ====================
(function() {
    const notifyCourseCbs = document.querySelectorAll('#notifyCourseGroup .notify-course-check');
    const notifySectionCbs = document.querySelectorAll('#notifySectionGroup .notify-section-check');
    linkCheckboxGroups(notifyCourseCbs, notifySectionCbs);
})();

// ==================== CREATE MODAL – SUBTOPIC BUILDER ====================
const subtopicsContainer = document.getElementById('subtopics-container');
const addSubtopicBtn = document.getElementById('add-subtopic');
const templateEl = document.getElementById('subtopic-template');

function createSubtopicCard() {
    const card = templateEl.cloneNode(true);
    card.style.display = 'block';
    card.removeAttribute('id');
    card.querySelectorAll('input[type="text"], input[type="number"], input[type="date"], input[type="time"], input[type="datetime-local"], textarea').forEach(el => el.value = '');
    card.querySelectorAll('input[type="checkbox"]').forEach(el => el.checked = false);
    card.querySelector('.subtopic-capacity').value = '0';
    card.querySelector('.subtopic-attendance-type').value = 'physical';

    card.querySelector('.remove-subtopic').addEventListener('click', () => card.remove());

    const requiredAllCb = card.querySelector('.subtopic-required-all');
    const requiredSectionCbs = card.querySelectorAll('.subtopic-required-section');
    const requiredCourseCbs = card.querySelectorAll('.subtopic-required-course');

    requiredAllCb.addEventListener('change', function() {
        const checked = this.checked;
        requiredSectionCbs.forEach(cb => { cb.checked = checked; cb.disabled = checked; });
        requiredCourseCbs.forEach(cb => { cb.checked = checked; cb.disabled = checked; });
        if (!checked) {
            requiredSectionCbs.forEach(cb => { cb.checked = false; cb.disabled = true; });
            requiredCourseCbs.forEach(cb => { cb.checked = false; cb.disabled = false; });
        }
    });

    linkCourseToSections(card);

    return card;
}

addSubtopicBtn.addEventListener('click', () => {
    subtopicsContainer.appendChild(createSubtopicCard());
});

// ==================== CREATE FORM SUBMISSION ====================
document.getElementById('createSessionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const cards = subtopicsContainer.querySelectorAll('.card');
    const subtopics = [];
    cards.forEach(card => {
        const title = card.querySelector('.subtopic-title').value.trim();
        if (!title) return;
        subtopics.push({
            title,
            capacity: card.querySelector('.subtopic-capacity').value,
            deadline: card.querySelector('.subtopic-deadline').value,
            description: card.querySelector('.subtopic-description').value,
            date: card.querySelector('.subtopic-date').value,
            start_time: card.querySelector('.subtopic-start-time').value,
            end_time: card.querySelector('.subtopic-end-time').value,
            proctor: card.querySelector('.subtopic-proctor').value,
            location: card.querySelector('.subtopic-location').value,
            is_required: card.querySelector('.subtopic-required-all').checked ? 1 : 0,
            attendance_type: card.querySelector('.subtopic-attendance-type').value,
            visible_sections: Array.from(card.querySelectorAll('.subtopic-visible-section:checked')).map(cb => cb.value),
            visible_courses: Array.from(card.querySelectorAll('.subtopic-visible-course:checked')).map(cb => cb.value),
            required_sections: Array.from(card.querySelectorAll('.subtopic-required-section:checked')).map(cb => cb.value),
            required_courses: Array.from(card.querySelectorAll('.subtopic-required-course:checked')).map(cb => cb.value)
        });
    });
    document.getElementById('subtopics_data').value = JSON.stringify(subtopics);
    const formData = new FormData(this);
    // Append subtopic images with specific indexes matching the valid subtopics
    let fileIndex = 0;
    cards.forEach((card) => {
        const title = card.querySelector('.subtopic-title').value.trim();
        if (!title) return; // Skip empty cards exactly like the subtopics array does
        
        const fileInput = card.querySelector('.subtopic-image');
        if (fileInput && fileInput.files[0]) {
            formData.append(`subtopic_image_${fileIndex}`, fileInput.files[0]);
        }
        fileIndex++;
    });
    fetch('subtopics.php', { method: 'POST', body: formData })
        .then(() => location.reload())
        .catch(err => alert('Error: ' + err));
});

// ==================== EVEN SPLIT (CREATE MODAL) ====================
document.getElementById('even-split-create')?.addEventListener('click', function() {
    const cards = subtopicsContainer.querySelectorAll('.card');
    if (cards.length === 0) { alert('Add at least one subtopic first.'); return; }
    const allSections = new Set(), allCourses = new Set();
    cards.forEach(card => {
        card.querySelectorAll('.subtopic-visible-section:checked, .subtopic-required-section:checked').forEach(cb => allSections.add(cb.value));
        card.querySelectorAll('.subtopic-visible-course:checked, .subtopic-required-course:checked').forEach(cb => allCourses.add(cb.value));
    });
    fetch('subtopics.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_counts&csrf_token=${encodeURIComponent(CSRF_TOKEN)}&sections=${encodeURIComponent(JSON.stringify([...allSections]))}&courses=${encodeURIComponent(JSON.stringify([...allCourses]))}`
    })
    .then(r => r.json())
    .then(data => {
        const per = Math.ceil(data.count / cards.length);
        cards.forEach(card => { card.querySelector('.subtopic-capacity').value = per; });
        alert(`Capacities set to ${per} each (${data.count} students).`);
    })
    .catch(err => alert('Error: ' + err));
});

// ==================== VIEW SESSION MODAL ====================
document.querySelectorAll('.view-session').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;
        try {
            const resp = await fetch(`get_session.php?id=${id}`);
            let rawText = await resp.text();
            const jsonStart = rawText.indexOf('{');
            if (jsonStart > 0) rawText = rawText.substring(jsonStart);
            const data = JSON.parse(rawText);
            if (!data.subtopics) data.subtopics = [];

            // Build Read-Only View
            buildViewModal(data);

            // Show Modal
            const modal = new bootstrap.Modal(document.getElementById('sessionModal'));
            modal.show();
            modal._element.addEventListener('hidden.bs.modal', function() {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            });

        } catch(err) {
            console.error('View modal error:', err);
            document.getElementById('sessionModalBody').innerHTML = '<div class="alert alert-danger m-4">Error loading session. Check console.</div>';
            new bootstrap.Modal(document.getElementById('sessionModal')).show();
        }
    });
});

function buildViewModal(data) {
    document.getElementById('sessionModalTitle').innerHTML = `<i class="fas fa-eye mr-2"></i>View Session`;
    
    let html = `
    <div class="bg-white rounded-xl p-5 mb-6 shadow-sm border border-gray-200">
        <div class="flex justify-between items-start mb-4">
            <h6 class="font-bold text-gray-800 text-sm uppercase tracking-wider"><i class="fas fa-info-circle text-gray-400 mr-2"></i>Session Details</h6>
            <button type="button" id="btnEnableEdit" class="bg-blue-50 text-blue-700 hover:bg-blue-100 px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 border border-blue-200">
                <i class="fas fa-edit"></i> Edit Session
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Title</label>
                <div class="text-sm font-medium text-gray-900">${escapeHtml(data.title)}</div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Phase</label>
                <div class="text-sm font-medium text-gray-900">${escapeHtml(data.phase)}</div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Date & Time</label>
                <div class="text-sm font-medium text-gray-900">${data.date ? new Date(data.date).toLocaleDateString() : 'N/A'} | ${data.start_time} - ${data.end_time}</div>
            </div>
        </div>
        ${data.description ? `<div class="mt-4 pt-4 border-t border-gray-100"><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Description</label><p class="text-sm text-gray-700">${escapeHtml(data.description)}</p></div>` : ''}
    </div>

    <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
        <h6 class="font-bold text-gray-800 text-sm uppercase tracking-wider mb-4 border-b border-gray-100 pb-2"><i class="fas fa-list-ul text-gray-400 mr-2"></i>Subtopics (${data.subtopics.length})</h6>
        <div class="space-y-3">`;

    if (data.subtopics.length === 0) {
        html += `<div class="text-center py-4 text-gray-500 text-sm">No subtopics added.</div>`;
    } else {
        data.subtopics.forEach(sub => {
            html += `
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <div class="flex justify-between items-start mb-2">
                    <div class="font-bold text-gray-800">${escapeHtml(sub.title)}</div>
                    ${sub.is_required == 1 ? '<span class="bg-red-100 text-red-700 text-[10px] font-bold px-2 py-0.5 rounded border border-red-200">REQUIRED</span>' : ''}
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs text-gray-600">
                    <div><i class="fas fa-users text-gray-400 mr-1"></i> Cap: ${sub.capacity}</div>
                    <div><i class="fas fa-laptop text-gray-400 mr-1"></i> ${sub.attendance_type}</div>
                    <div><i class="fas fa-clock text-gray-400 mr-1"></i> ${sub.deadline ? new Date(sub.deadline).toLocaleDateString() : 'No deadline'}</div>
                    <div><i class="fas fa-user text-gray-400 mr-1"></i> ${sub.subtopic_proctor || 'TBA'}</div>
                </div>
            </div>`;
        });
    }

    html += `</div></div>`;

    document.getElementById('sessionModalBody').innerHTML = html;

    // Bind Edit Toggle
    document.getElementById('btnEnableEdit').addEventListener('click', function() {
        buildEditModal(data);
        document.getElementById('sessionModalTitle').innerHTML = `<i class="fas fa-edit mr-2"></i>Edit Session`;
    });
}

function buildEditModal(data) {
    let html = `
    <form method="POST" id="editSessionForm">
        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="${data.session_id}">
        
        <div class="bg-white rounded-xl p-5 mb-6 shadow-sm border border-gray-200">
            <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-info-circle text-gray-400 mr-2"></i>Session Details</h6>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(data.title)}" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phase <span class="text-red-500">*</span></label>
                    <select name="phase" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                        <option value="Preparation" ${data.phase=='Preparation'?'selected':''}>Preparation</option>
                        <option value="Pre-Employment" ${data.phase=='Pre-Employment'?'selected':''}>Pre-Employment</option>
                        <option value="Career Fair" ${data.phase=='Career Fair'?'selected':''}>Career Fair</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Session Date <span class="text-red-500">*</span></label>
                    <input type="date" name="session_date" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${data.date ? data.date.slice(0,10) : ''}" required>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Session Start Time <span class="text-red-500">*</span></label>
                    <input type="time" name="session_start_time" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${data.start_time || '08:00'}" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Session End Time <span class="text-red-500">*</span></label>
                    <input type="time" name="session_end_time" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${data.end_time || '17:00'}" required>
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">${escapeHtml(data.description)}</textarea>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Session Banner Image (Optional)</label>
                <input type="file" name="session_image" accept="image/png, image/jpeg, image/webp" class="w-full border border-gray-300 rounded-lg p-2 bg-white text-sm focus:outline-none file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-[#0a6e2d] file:text-white hover:file:bg-[#054018]">
                <p class="text-xs text-gray-500 mt-1">Upload a new image to replace the current one.</p>
            </div>
            <div class="mt-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="allow_multiple" value="1" class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" id="edit_allow_multiple" ${data.allow_multiple==1?'checked':''}>
                    <span class="text-sm text-gray-700">Allow multiple registrations per student in this session</span>
                </label>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
            <div class="flex justify-between items-center mb-4 pb-4 border-b border-gray-100">
                <h6 class="font-bold text-gray-800 text-sm uppercase tracking-wider"><i class="fas fa-list-ul text-gray-400 mr-2"></i>Subtopics</h6>
                <div class="flex gap-2">
                    <button type="button" id="even-split-btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors border border-gray-300"><i class="fas fa-balance-scale mr-1 text-gray-500"></i> Even Split</button>
                    <button type="button" id="add-subtopic-edit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white text-sm font-medium px-3 py-1.5 rounded-lg transition-colors shadow-sm"><i class="fas fa-plus mr-1"></i> Add Subtopic</button>
                </div>
            </div>
            <div id="edit-subtopics-container" class="space-y-4">`;

    data.subtopics.forEach(sub => {
        function safeArray(val) {
            if (Array.isArray(val)) return val;
            if (typeof val === 'string') {
                try { const parsed = JSON.parse(val); return Array.isArray(parsed) ? parsed : []; } catch(e) {}
            }
            return [];
        }
        let vis_sec = safeArray(sub.visible_for);
        let vis_crs = safeArray(sub.visible_courses);
        let req_sec = safeArray(sub.required_for);
        let req_crs = safeArray(sub.required_courses);
        const allSecReq = <?= $sections_json ?>.every(s => req_sec.includes(s));
        const allCrsReq = <?= $courses_json ?>.every(c => req_crs.includes(c));
        const allRequired = allSecReq && allCrsReq;

        html += `<div class="card mb-4 border border-gray-200 rounded-xl shadow-sm bg-white overflow-hidden">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 flex justify-between items-center">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Subtopic Configuration</span>
                <button type="button" class="text-red-500 hover:text-red-700 remove-subtopic-edit text-sm font-medium"><i class="fas fa-trash-alt mr-1"></i> Remove</button>
            </div>
            
            <div class="p-4 md:p-5 space-y-4">
                <input type="hidden" class="subtopic-id" value="${sub.subtopic_id || ''}">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic Title <span class="text-red-500">*</span></label>
                        <input type="text" class="w-full border border-gray-300 rounded-lg p-2 subtopic-title focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(sub.title)}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                        <input type="number" class="w-full border border-gray-300 rounded-lg p-2 subtopic-capacity focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${sub.capacity}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Deadline</label>
                        <input type="datetime-local" class="w-full border border-gray-300 rounded-lg p-2 subtopic-deadline focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${sub.deadline?sub.deadline.slice(0,16):''}">
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" class="w-full border border-gray-300 rounded-lg p-2 subtopic-date focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${sub.subtopic_date||''}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                        <input type="time" class="w-full border border-gray-300 rounded-lg p-2 subtopic-start-time focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${sub.subtopic_start_time||''}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                        <input type="time" class="w-full border border-gray-300 rounded-lg p-2 subtopic-end-time focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${sub.subtopic_end_time||''}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Proctor</label>
                        <input type="text" class="w-full border border-gray-300 rounded-lg p-2 subtopic-proctor focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(sub.subtopic_proctor)}">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                        <input type="text" class="w-full border border-gray-300 rounded-lg p-2 subtopic-location focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(sub.subtopic_location)}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Attendance Type</label>
                            <select class="w-full border border-gray-300 rounded-lg p-2 subtopic-attendance-type focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="physical" ${(sub.attendance_type||'physical')==='physical'?'selected':''}>Physical</option>
                            <option value="online" ${sub.attendance_type==='online'?'selected':''}>Online</option>
                            <option value="module" ${sub.attendance_type==='module'?'selected':''}>Module</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Visible to Courses</label>
                        <p class="text-[11px] text-gray-500 mb-2 leading-tight">Leave all unchecked to show this subtopic to <strong class="text-gray-700">ALL students</strong>.</p>
                        <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">`;
        <?= $courses_json ?>?.forEach(c => { html += `<label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="${c}" class="subtopic-visible-course rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" ${vis_crs.includes(c)?'checked':''}> ${c}</label>`; });
        html += `</div></div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Visible to Sections</label>
                        <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">`;
        const anyVisCourse = vis_crs.length > 0;
        <?= $sections_json ?>?.forEach(s => { html += `<label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="${s}" class="subtopic-visible-section rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" ${vis_sec.includes(s)?'checked':''} ${anyVisCourse?'':'disabled'}> ${s}</label>`; });
        html += `</div></div></div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Required for Courses</label>
                        <p class="text-[11px] text-gray-500 mb-2 leading-tight">Leave unchecked to make it <strong class="text-gray-700">Optional</strong>. Checked courses MUST attend.</p>
                        <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">`;
        <?= $courses_json ?>?.forEach(c => { html += `<label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="${c}" class="subtopic-required-course rounded border-gray-300 text-red-600 focus:ring-red-500" ${req_crs.includes(c)?'checked':''} ${allRequired?'disabled':''}> ${c}</label>`; });
        html += `</div></div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Required for Sections</label>
                        <div class="checkbox-group bg-white border border-gray-300 rounded-lg p-2 h-24 overflow-y-auto space-y-1">`;
        <?= $sections_json ?>?.forEach(s => { html += `<label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"><input type="checkbox" value="${s}" class="subtopic-required-section rounded border-gray-300 text-red-600 focus:ring-red-500" ${req_sec.includes(s)?'checked':''} ${(allRequired || req_crs.length > 0)?'':'disabled'}> ${s}</label>`; });
        html += `</div></div></div>

                <div class="mb-3">
                    <label class="flex items-center gap-2 cursor-pointer bg-red-50 border border-red-200 p-2 rounded-lg">
                        <input type="checkbox" class="subtopic-required-all rounded border-red-300 text-red-600 focus:ring-red-500 w-4 h-4" ${allRequired?'checked':''}>
                        <span class="text-sm font-bold text-red-700">Required for ALL students</span>
                    </label>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea rows="2" class="w-full border border-gray-300 rounded-lg p-2 subtopic-description focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">${escapeHtml(sub.description)}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic Image (Optional)</label>
                    <input type="file" class="w-full border border-gray-300 rounded-lg p-2 subtopic-image bg-white text-sm focus:outline-none file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-[#0a6e2d] file:text-white hover:file:bg-[#054018]" accept="image/png, image/jpeg, image/webp">
                    ${sub.image_path ? `<p class="text-[11px] text-green-600 mt-1"><i class="fas fa-check-circle"></i> Current image uploaded.</p>` : ''}
                </div>
            </div>
        </div>`;
    });

    html += `</div></div>
        <input type="hidden" name="subtopics_data" id="edit_subtopics_data">
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium text-sm px-6 py-2.5 rounded-lg transition-colors" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium text-sm px-8 py-2.5 rounded-lg transition-colors shadow-sm"><i class="fas fa-save mr-1"></i> Update Session</button>
        </div>
    </form>`;

    document.getElementById('sessionModalBody').innerHTML = html;
    const editContainer = document.getElementById('edit-subtopics-container');

    editContainer.querySelectorAll('.card').forEach(card => linkCourseToSections(card));

    document.getElementById('add-subtopic-edit')?.addEventListener('click', () => {
        const card = createSubtopicCard();
        card.querySelector('.remove-subtopic').classList.add('remove-subtopic-edit');
        card.querySelectorAll('.remove-subtopic-edit').forEach(b => b.addEventListener('click', function(){ this.closest('.card').remove(); }));
        editContainer.appendChild(card);
    });

    document.querySelectorAll('.remove-subtopic-edit').forEach(b => {
        b.addEventListener('click', function(){ this.closest('.card').remove(); });
    });

    document.querySelectorAll('.subtopic-required-all').forEach(cb => {
        cb.addEventListener('change', function() {
            const card = this.closest('.card');
            const checked = this.checked;
            const targets = card.querySelectorAll('.subtopic-required-section, .subtopic-required-course');
            targets.forEach(c => { c.checked = checked; c.disabled = checked; });
            if (!checked) {
                card.querySelectorAll('.subtopic-required-course').forEach(c => { c.checked = false; c.disabled = false; });
                card.querySelectorAll('.subtopic-required-section').forEach(c => { c.checked = false; c.disabled = true; });
            }
        });
    });

    document.getElementById('even-split-btn')?.addEventListener('click', () => {
        if (!confirm('Recalculate capacities for all subtopics?')) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action','even_split'); fd.append('session_id', data.session_id);
        fetch('subtopics.php',{method:'POST',body:fd}).then(()=>location.reload()).catch(alert);
    });

    document.getElementById('editSessionForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const cards = editContainer.querySelectorAll('.card');
        const subtopics = [];
        cards.forEach(card => {
            const title = card.querySelector('.subtopic-title').value.trim();
            if (!title) return;
            subtopics.push({
                subtopic_id: card.querySelector('.subtopic-id')?.value || null,
                title,
                capacity: card.querySelector('.subtopic-capacity').value,
                deadline: card.querySelector('.subtopic-deadline').value,
                description: card.querySelector('.subtopic-description').value,
                date: card.querySelector('.subtopic-date').value,
                start_time: card.querySelector('.subtopic-start-time').value,
                end_time: card.querySelector('.subtopic-end-time').value,
                proctor: card.querySelector('.subtopic-proctor').value,
                location: card.querySelector('.subtopic-location').value,
                is_required: card.querySelector('.subtopic-required-all').checked ? 1 : 0,
                attendance_type: card.querySelector('.subtopic-attendance-type').value,
                visible_sections: Array.from(card.querySelectorAll('.subtopic-visible-section:checked')).map(cb => cb.value),
                visible_courses: Array.from(card.querySelectorAll('.subtopic-visible-course:checked')).map(cb => cb.value),
                required_sections: Array.from(card.querySelectorAll('.subtopic-required-section:checked')).map(cb => cb.value),
                required_courses: Array.from(card.querySelectorAll('.subtopic-required-course:checked')).map(cb => cb.value)
            });
        });
        document.getElementById('edit_subtopics_data').value = JSON.stringify(subtopics);
        const fd = new FormData(this);
        
        let fileIndex = 0;
        cards.forEach((card) => {
            const title = card.querySelector('.subtopic-title').value.trim();
            if (!title) return;
            
            const fileInput = card.querySelector('.subtopic-image');
            if (fileInput && fileInput.files[0]) {
                fd.append(`subtopic_image_${fileIndex}`, fileInput.files[0]);
            }
            fileIndex++;
        });

        fetch('subtopics.php',{method:'POST',body:fd}).then(()=>location.reload()).catch(alert);
    });
}

// ==================== DELETE / RESTORE / ARCHIVE TOGGLE ====================
document.querySelectorAll('.delete-session').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Move this session to archives?')) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action','soft_delete'); fd.append('id', this.dataset.id);
        fetch('subtopics.php',{method:'POST',body:fd}).then(()=>location.reload()).catch(alert);
    });
});
document.querySelectorAll('.restore-session').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Restore this session?')) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action','restore'); fd.append('id', this.dataset.id);
        fetch('subtopics.php',{method:'POST',body:fd}).then(()=>location.reload()).catch(alert);
    });
});
document.querySelectorAll('.hard-delete-session').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('PERMANENTLY delete? This cannot be undone.')) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action','hard_delete'); fd.append('id', this.dataset.id);
        fetch('subtopics.php',{method:'POST',body:fd}).then(()=>location.reload()).catch(alert);
    });
});
document.getElementById('archiveHeader')?.addEventListener('click', function() {
    document.getElementById('archiveContent')?.classList.toggle('hidden');
    this.querySelector('.fa-chevron-down')?.classList.toggle('rotate-180');
});

// ==================== NOTIFY ====================
document.querySelectorAll('.notify-session-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (!confirm('Send email to ALL registered students?')) return;
        const orig = this.innerHTML;
        this.disabled = true; this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        fetch('notify_session.php',{
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`csrf_token=${encodeURIComponent(CSRF_TOKEN)}&session_id=${this.dataset.sessionId}`
        })
        .then(r=>r.json()).then(d=>{alert(d.message);this.innerHTML=orig;this.disabled=false;})
        .catch(e=>{alert('Error: '+e);this.innerHTML=orig;this.disabled=false;});
    });
});

const notifySession = document.getElementById('notifySession');
notifySession?.addEventListener('change', function() {
    const id = this.value;
    const container = document.getElementById('notifySubtopicGroup');
    container.innerHTML = '';
    
    if (!id) {
        container.innerHTML = '<p class="text-sm text-gray-500 italic">Select a session first to load subtopics...</p>';
        return;
    }
    
    fetch(`get_session.php?id=${id}`).then(r=>r.json()).then(data => {
        if (data.subtopics && data.subtopics.length > 0) {
            data.subtopics.forEach(s => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 cursor-pointer';
                label.innerHTML = `
                    <input type="checkbox" value="${s.subtopic_id}" class="notify-subtopic-check rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]">
                    <span class="text-sm text-gray-700">${s.title}</span>
                `;
                container.appendChild(label);
            });
        } else {
            container.innerHTML = '<p class="text-sm text-gray-500 italic">No subtopics found for this session.</p>';
        }
    }).catch(err => {
        container.innerHTML = '<p class="text-sm text-red-500 italic">Error loading subtopics.</p>';
    });
});
document.getElementById('notifySendBtn') && (function(){
    const ns = document.getElementById('notifySession'), nb = document.getElementById('notifySendBtn');
    ns.addEventListener('change', ()=> nb.disabled = !ns.value);
    nb.disabled = !ns.value;
})();

document.getElementById('notifyForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('notifySendBtn');
    btn.disabled = true; 
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Sending…';
    
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('session_id', document.getElementById('notifySession').value);
    
    const selSub = Array.from(document.querySelectorAll('#notifySubtopicGroup .notify-subtopic-check:checked')).map(cb => cb.value);
    if (selSub.length) fd.append('subtopics', JSON.stringify(selSub));
    
    const selCrs = Array.from(document.querySelectorAll('#notifyCourseGroup input[type="checkbox"]:checked')).map(cb => cb.value);
    if (selCrs.length) fd.append('courses', JSON.stringify(selCrs));
    
    const selSec = Array.from(document.querySelectorAll('#notifySectionGroup input[type="checkbox"]:checked')).map(cb => cb.value);
    if (selSec.length) fd.append('sections', JSON.stringify(selSec));
    
    fd.append('message', document.getElementById('notifyMessage').value);

    // Hide the form modal and show the progress modal
    bootstrap.Modal.getInstance(document.getElementById('notifyModal'))?.hide();
    const progressModal = new bootstrap.Modal(document.getElementById('notifyProgressModal'));
    progressModal.show();

    // Simulate Progress Bar
    const progressBar = document.getElementById('notifyProgressBar');
    const progressText = document.getElementById('notifyProgressText');
    const progressPercent = document.getElementById('notifyProgressPercent');
    let progress = 0;
    
    const simulatedInterval = setInterval(() => {
        if (progress < 90) {
            progress += Math.floor(Math.random() * 8) + 2; // Increase by 2-10%
            progressBar.style.width = progress + '%';
            progressPercent.textContent = progress + '%';
            
            if (progress > 20) progressText.textContent = "Sending emails...";
            if (progress > 60) progressText.textContent = "Processing final batch...";
        }
    }, 600); // Updates every 0.6 seconds

    // Send the request
    fetch('notify_custom.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
        // Finish the progress bar
        clearInterval(simulatedInterval);
        progressBar.style.width = '100%';
        progressPercent.textContent = '100%';
        progressText.textContent = 'Completed!';
        
        setTimeout(() => {
            progressModal.hide();
            btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Send';
            btn.disabled = false;
            
            if (d.message) {
                alert('✅ ' + d.message);
            } else if (d.error) {
                alert('❌ ' + d.error);
            }
        }, 800); // Wait 0.8s at 100% before showing the success alert
    }).catch(e=>{
        clearInterval(simulatedInterval);
        progressModal.hide();
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Send';
        btn.disabled = false;
        alert('Error: '+e);
    });
});

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]);
}
// ==================== DEEP-LINK HANDLER ====================
// If arriving from dashboard with ?view_session=X, auto-open the view modal
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const viewSessionId = urlParams.get('view_session');

    if (viewSessionId) {
        const btn = document.querySelector(`.view-session[data-id="${viewSessionId}"]`);
        if (btn) {
            // Small delay to ensure everything is rendered
            setTimeout(() => {
                btn.click();
            }, 100);
        }
    }
});
</script>
</body>
</html>