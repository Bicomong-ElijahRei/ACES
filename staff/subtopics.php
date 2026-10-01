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
        $stmt = $pdo->prepare("INSERT INTO sessions (title, phase, description, allow_multiple, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $phase, $description, $allow_multiple, $_SESSION['user_id']]);
        $session_id = $pdo->lastInsertId();

        $subtopics_json = $_POST['subtopics_data'] ?? '';
        if ($subtopics_json) {
            $subtopics = json_decode($subtopics_json, true);
            foreach ($subtopics as $sub) {
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

                $stmt_sub = $pdo->prepare("
                    INSERT INTO subtopics (
                        session_id, title, description, capacity, deadline,
                        subtopic_date, subtopic_start_time, subtopic_end_time,
                        subtopic_proctor, subtopic_location, is_required,
                        visible_for, required_for,
                        visible_courses, required_courses,
                        attendance_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_sub->execute([
                    $session_id, $sub_title, $sub_desc, $capacity, $deadline,
                    $sub_date, $sub_start, $sub_end,
                    $sub_proctor, $sub_location, $is_required,
                    $visible_sections, $required_sections,
                    $visible_courses, $required_courses,
                    $attendance_type
                ]);
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
        $stmt = $pdo->prepare("UPDATE sessions SET title = ?, phase = ?, description = ?, allow_multiple = ? WHERE session_id = ?");
        $stmt->execute([$title, $phase, $description, $allow_multiple, $id]);

        $stmt_del = $pdo->prepare("DELETE FROM subtopics WHERE session_id = ?");
        $stmt_del->execute([$id]);

        $subtopics_json = $_POST['subtopics_data'] ?? '';
        if ($subtopics_json) {
            $subtopics = json_decode($subtopics_json, true);
            foreach ($subtopics as $sub) {
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

                $stmt_sub = $pdo->prepare("
                    INSERT INTO subtopics (
                        session_id, title, description, capacity, deadline,
                        subtopic_date, subtopic_start_time, subtopic_end_time,
                        subtopic_proctor, subtopic_location, is_required,
                        visible_for, required_for,
                        visible_courses, required_courses,
                        attendance_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_sub->execute([
                    $id, $sub_title, $sub_desc, $capacity, $deadline,
                    $sub_date, $sub_start, $sub_end,
                    $sub_proctor, $sub_location, $is_required,
                    $visible_sections, $required_sections,
                    $visible_courses, $required_courses,
                    $attendance_type
                ]);
            }
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

    $stmt = $pdo->prepare("SELECT subtopic_id FROM subtopics WHERE session_id = ?");
    $stmt->execute([$session_id]);
    $subtopic_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($subtopic_ids)) {
        $error = "No subtopics to distribute.";
    } else {
        $stmt = $pdo->query("SELECT section, COUNT(*) as cnt FROM students GROUP BY section");
        $sections = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $num_subtopics = count($subtopic_ids);
        foreach ($subtopic_ids as $sub_id) {
            $total_cap = 0;
            foreach ($sections as $section => $cnt) {
                $per_subtopic = ceil($cnt / $num_subtopics);
                $total_cap += $per_subtopic;
                $stmt_cap = $pdo->prepare("
                    INSERT INTO subtopic_section_capacity (subtopic_id, section, capacity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE capacity = ?
                ");
                $stmt_cap->execute([$sub_id, $section, $per_subtopic, $per_subtopic]);
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
    $sql = "SELECT COUNT(*) FROM students WHERE 1=1";
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

// Fetch active sessions
$stmt = $pdo->prepare("
    SELECT s.*, (SELECT COUNT(*) FROM subtopics WHERE session_id = s.session_id) as subtopic_count
    FROM sessions s
    WHERE s.is_deleted = 0
    ORDER BY s.date DESC
");
$stmt->execute();
$active_sessions = $stmt->fetchAll();

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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .session-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; transition: 0.2s; }
        .session-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .session-card .card-header { background: #054018; color: white; padding: 14px 16px; border-radius: 10px 10px 0 0; display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .session-card .card-header .title-block { flex: 1; min-width: 0; }
        .session-card .card-header .title-block h3 { font-size: 0.9rem; font-weight: 700; line-height: 1.3; word-break: break-word; }
        .session-card .card-header .title-block .phase { font-size: 0.7rem; opacity: 0.8; }
        .session-card .card-body { padding: 16px; }
        .session-card .action-btns { display: flex; gap: 6px; align-items: center; }
        .session-card .action-btns button { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 6px 10px; border-radius: 6px; font-size: 0.9rem; cursor: pointer; transition: 0.15s; }
        .session-card .action-btns button:hover { background: rgba(255,255,255,0.3); border-color: rgba(255,255,255,0.5); }
        .subtopic-badge { background: #e5e7eb; padding: 2px 8px; border-radius: 12px; font-size: 0.65rem; margin-left: 4px; }
        .required-badge { background: #dc2626; color: white; }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
        .modal-content { border-radius: 12px; overflow: hidden; }
        .modal-backdrop { background-color: rgba(0,0,0,0.6); }
        .btn-blue-600 { background-color: #2563eb; color: #fff; }
        .btn-blue-600:hover { background-color: #1d4ed8; }
        .checkbox-group { max-height: 140px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 12px; background: #fff; }
        .checkbox-group label { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; padding: 3px 0; cursor: pointer; }
        .checkbox-group label:hover { background: #f0fdf4; border-radius: 4px; padding-left: 4px; }
        .checkbox-group input[type="checkbox"] { width: 16px; height: 16px; accent-color: #0a6e2d; cursor: pointer; }
        .checkbox-group input[type="checkbox"]:disabled { opacity: 0.4; cursor: not-allowed; }
        .help-text { font-size: 0.75rem; color: #6b7280; margin-top: 2px; }
        .field-label { font-size: 0.85rem; font-weight: 600; color: #374151; margin-bottom: 4px; display: block; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans">

<div class="h-screen flex flex-col md:flex-row">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-10 overflow-y-auto no-scrollbar">

            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">Sessions</h1>
                <a href="dashboard.php" class="text-[#0a6e2d]">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/>
                    </svg>
                </a>
            </div>

            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 relative"><?= htmlspecialchars($success) ?><button type="button" class="absolute top-1 right-3 text-xl leading-none" onclick="this.parentElement.remove()">&times;</button></div>
            <?php elseif ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 relative"><?= htmlspecialchars($error) ?><button type="button" class="absolute top-1 right-3 text-xl leading-none" onclick="this.parentElement.remove()">&times;</button></div>
            <?php endif; ?>

            <?php if (!isViewer()): ?>
            <div class="mb-4 text-right">
                <button class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-flex items-center ml-2" type="button" data-bs-toggle="modal" data-bs-target="#notifyModal">
                    <i class="fas fa-envelope mr-2"></i> Notify
                </button>
                <button class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded inline-flex items-center" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="fas fa-plus mr-2"></i> Create Session
                </button>
            </div>
            <?php endif; ?>

            <!-- Active Sessions – GRID 3 columns -->
            <?php if (count($active_sessions) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
                    <?php foreach ($active_sessions as $session): ?>
                        <?php
                        $stmt_sub = $pdo->prepare("SELECT * FROM subtopics WHERE session_id = ? ORDER BY title");
                        $stmt_sub->execute([$session['session_id']]);
                        $subtopics = $stmt_sub->fetchAll();
                        ?>
                        <div class="session-card">
                            <div class="card-header">
                                <div class="title-block">
                                    <h3><?= htmlspecialchars($session['title']) ?></h3>
                                    <div class="phase"><?= htmlspecialchars($session['phase']) ?></div>
                                </div>
                                <div class="action-btns">
                                    <?php if (!isViewer() && (isAdmin() || $session['created_by'] == $_SESSION['user_id'])): ?>
                                        <button class="notify-session-btn" data-session-id="<?= $session['session_id'] ?>" title="Notify students"><i class="fas fa-envelope"></i></button>
                                        <button class="edit-session" data-id="<?= $session['session_id'] ?>" data-bs-toggle="modal" data-bs-target="#editModal" title="Edit session"><i class="fas fa-edit"></i></button>
                                        <button class="delete-session" data-id="<?= $session['session_id'] ?>" title="Archive"><i class="fas fa-trash-alt"></i></button>
                                    <?php elseif (!isViewer() && !isAdmin() && $session['created_by'] != $_SESSION['user_id']): ?>
                                        <span class="text-white/50 text-xs">(other staff)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body text-sm">
                                <?php if ($session['description']): ?>
                                    <p class="text-gray-500 text-xs mb-3"><?= htmlspecialchars($session['description']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($subtopics)): ?>
                                    <div class="text-xs font-semibold text-gray-600 mb-2">Subtopics</div>
                                    <?php foreach ($subtopics as $sub): ?>
                                        <div class="border-l-4 border-green-600 pl-3 py-1 mb-2 bg-gray-50 rounded-r">
                                            <div class="text-xs font-medium"><?= htmlspecialchars($sub['title']) ?></div>
                                            <div class="flex flex-wrap items-center gap-1 mt-1">
                                                <?php if ($sub['is_required']): ?>
                                                    <span class="subtopic-badge required-badge">Required</span>
                                                <?php endif; ?>
                                                <span class="subtopic-badge">Cap: <?= $sub['capacity'] ?></span>
                                                <?php if ($sub['deadline']): ?>
                                                    <span class="subtopic-badge">Deadline: <?= date('M d', strtotime($sub['deadline'])) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-xs text-gray-400 italic">No subtopics added.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded shadow-xl p-6 text-center text-gray-500 mb-8">No sessions yet. Click "Create Session" to start.</div>
            <?php endif; ?>

            <!-- Archives -->
            <div class="mt-10">
                <div id="archiveHeader" class="flex justify-between items-center cursor-pointer bg-white rounded-t-lg px-6 py-3 shadow-md border border-gray-200">
                    <div class="font-bold text-[#0a6e2d]"><i class="fas fa-archive mr-2"></i> Archives <span class="bg-gray-500 text-white rounded-full px-2 py-0.5 text-xs ml-2"><?= count($deleted_sessions) ?></span></div>
                    <i class="fas fa-chevron-down transition-transform duration-200 text-gray-500"></i>
                </div>
                <div id="archiveContent" class="hidden mt-1">
                    <div class="bg-white rounded-b-lg shadow-md overflow-hidden border border-t-0 border-gray-200 p-4">
                        <?php if (count($deleted_sessions) > 0): ?>
                            <?php foreach ($deleted_sessions as $session): ?>
                                <div class="flex justify-between items-center py-2 border-b">
                                    <span><?= htmlspecialchars($session['title']) ?></span>
                                    <div class="flex gap-2">
                                        <button class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-xs restore-session" data-id="<?= $session['session_id'] ?>"><i class="fas fa-undo-alt"></i> Restore</button>
                                        <button class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-xs hard-delete-session" data-id="<?= $session['session_id'] ?>"><i class="fas fa-times-circle"></i> Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-gray-500 text-sm">No archived sessions.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ==================== NOTIFY MODAL ==================== -->
            <div class="modal fade" id="notifyModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-blue-600 text-white">
                            <h5 class="modal-title"><i class="fas fa-envelope mr-2"></i>Notify Students</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 bg-gray-50">
                            <form id="notifyForm">
                                <div class="mb-3">
                                    <label class="form-label">Session <span class="text-red-500">*</span></label>
                                    <select class="form-control" id="notifySession" required>
                                        <option value="">Select session…</option>
                                        <?php foreach ($active_sessions as $s): ?>
                                            <option value="<?= $s['session_id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Subtopic (optional)</label>
                                    <select class="form-control" id="notifySubtopic" multiple size="3"></select>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Course (optional) <span class="text-xs text-gray-500">— check at least 1 to enable sections</span></label>
                                        <div class="checkbox-group" id="notifyCourseGroup">
                                            <?php foreach ($courses as $c): ?>
                                            <label><input type="checkbox" value="<?= htmlspecialchars($c) ?>" class="notify-course-check"> <span><?= htmlspecialchars($c) ?></span></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Section (optional)</label>
                                        <div class="checkbox-group" id="notifySectionGroup">
                                            <?php foreach ($sections as $sec): ?>
                                            <label><input type="checkbox" value="<?= htmlspecialchars($sec) ?>" class="notify-section-check" disabled> <span><?= htmlspecialchars($sec) ?></span></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Custom Message (optional)</label>
                                    <textarea class="form-control" id="notifyMessage" rows="3" placeholder="Add any special instructions…"></textarea>
                                </div>
                                <div class="text-right">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-blue-600 text-white" id="notifySendBtn" disabled>
                                        <i class="fas fa-paper-plane mr-1"></i> Send
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ==================== CREATE MODAL ==================== -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-2xl">
            <div class="modal-header bg-gradient-to-r from-[#054018] to-[#0a6e2d] text-white p-4 border-0">
                <h5 class="modal-title text-xl font-bold"><i class="fas fa-plus-circle mr-2"></i>Create New Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 md:p-6 bg-gray-50">
                <form method="POST" id="createSessionForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">

                    <div class="bg-white rounded-lg p-4 mb-4 shadow-sm">
                        <h6 class="font-semibold text-gray-700 mb-3"><i class="fas fa-info-circle mr-1"></i>Session Details</h6>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <span class="field-label">Session Title <span class="text-red-500">*</span></span>
                                <input type="text" name="title" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-green-500 focus:border-green-500" required>
                                <p class="help-text">Enter a descriptive name for this career session.</p>
                            </div>
                            <div>
                                <span class="field-label">Phase <span class="text-red-500">*</span></span>
                                <select name="phase" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-green-500" required>
                                    <option value="Preparation">Preparation</option>
                                    <option value="Pre-Employment">Pre-Employment</option>
                                    <option value="Career Fair">Career Fair</option>
                                </select>
                                <p class="help-text">Select which career preparation phase this session belongs to.</p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <span class="field-label">Description</span>
                            <textarea name="description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-2 focus:ring-green-500" placeholder="Optional — describe the purpose of this session"></textarea>
                        </div>
                        <div class="form-check mt-3">
                            <input type="checkbox" name="allow_multiple" value="1" id="create_allow_multiple" class="form-check-input">
                            <label class="form-check-label text-sm" for="create_allow_multiple">Allow multiple registrations per student in this session</label>
                            <p class="help-text ml-5">If checked, students can register for more than one subtopic within this session.</p>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <div class="flex justify-between items-center mb-4">
                            <h6 class="font-semibold text-gray-700"><i class="fas fa-list-ul mr-1"></i>Subtopics</h6>
                            <div class="flex gap-2">
                                <button type="button" id="even-split-create" class="bg-blue-500 hover:bg-blue-600 text-white text-sm px-3 py-1.5 rounded-lg transition">
                                    <i class="fas fa-balance-scale mr-1"></i> Even Split
                                </button>
                                <button type="button" id="add-subtopic" class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1.5 rounded-lg transition">
                                    <i class="fas fa-plus mr-1"></i> Add Subtopic
                                </button>
                            </div>
                        </div>
                        <p class="help-text mb-3">Each subtopic represents a specific activity within this session. Add at least one subtopic below.</p>
                        <div id="subtopics-container" class="space-y-4"></div>
                    </div>

                    <input type="hidden" name="subtopics_data" id="subtopics_data">
                    <div class="mt-6 text-right">
                        <button type="button" class="text-gray-600 bg-gray-200 hover:bg-gray-300 font-medium text-sm px-5 py-2 rounded-lg mr-2 transition" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-bold text-sm px-6 py-2.5 rounded-lg transition">
                            <i class="fas fa-save mr-1"></i> Create Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ==================== EDIT MODAL ==================== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-2xl">
            <div class="modal-header bg-gradient-to-r from-[#054018] to-[#0a6e2d] text-white p-4 border-0">
                <h5 class="modal-title text-xl font-bold"><i class="fas fa-edit mr-2"></i>Edit Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 md:p-6 bg-gray-50" id="editModalBody"></div>
        </div>
    </div>
</div>

<!-- ==================== HIDDEN TEMPLATE ==================== -->
<div id="subtopic-template" style="display:none;">
    <div class="card mb-3 border border-gray-200 rounded-lg">
        <div class="card-body p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                <div>
                    <span class="field-label">Subtopic Title <span class="text-red-500">*</span></span>
                    <input type="text" class="form-control subtopic-title" placeholder="e.g., Resume Writing Workshop">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="field-label">Capacity</span>
                        <input type="number" class="form-control subtopic-capacity" value="0" min="0">
                        <p class="help-text">Maximum number of students. Use Even Split for auto‑calculation.</p>
                    </div>
                    <div>
                        <span class="field-label">Deadline</span>
                        <input type="datetime-local" class="form-control subtopic-deadline">
                        <p class="help-text">Students must register before this date/time.</p>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                <div><span class="field-label">Date</span><input type="date" class="form-control subtopic-date"><p class="help-text">Overrides session date if set.</p></div>
                <div><span class="field-label">Start Time</span><input type="time" class="form-control subtopic-start-time"></div>
                <div><span class="field-label">End Time</span><input type="time" class="form-control subtopic-end-time"></div>
                <div><span class="field-label">Proctor</span><input type="text" class="form-control subtopic-proctor" placeholder="Facilitator name"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                <div><span class="field-label">Location</span><input type="text" class="form-control subtopic-location" placeholder="e.g., Room 101"></div>
                <div><span class="field-label">Attendance Type</span><select class="form-select subtopic-attendance-type"><option value="physical">Physical — student must be present</option><option value="module">Module — attendance marked when all modules complete</option></select></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                <div>
                    <span class="field-label">Visible to Courses</span>
                    <p class="help-text">Only checked courses will see this subtopic. Leave all unchecked to show to everyone.</p>
                    <div class="checkbox-group">
                        <?php foreach ($courses as $crs): ?>
                        <label><input type="checkbox" value="<?= htmlspecialchars($crs) ?>" class="subtopic-visible-course"> <span><?= htmlspecialchars($crs) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <span class="field-label">Visible to Sections</span>
                    <p class="help-text">Only checked sections will see this subtopic. Leave all unchecked to show to everyone.</p>
                    <div class="checkbox-group">
                        <?php foreach ($sections as $sec): ?>
                        <label><input type="checkbox" value="<?= htmlspecialchars($sec) ?>" class="subtopic-visible-section" disabled> <span><?= htmlspecialchars($sec) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                <div>
                    <span class="field-label">Required for Courses</span>
                    <p class="help-text">Students in checked courses MUST attend this subtopic.</p>
                    <div class="checkbox-group">
                        <?php foreach ($courses as $crs): ?>
                        <label><input type="checkbox" value="<?= htmlspecialchars($crs) ?>" class="subtopic-required-course"> <span><?= htmlspecialchars($crs) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <span class="field-label">Required for Sections</span>
                    <p class="help-text">Students in checked sections MUST attend this subtopic.</p>
                    <div class="checkbox-group">
                        <?php foreach ($sections as $sec): ?>
                        <label><input type="checkbox" value="<?= htmlspecialchars($sec) ?>" class="subtopic-required-section" disabled> <span><?= htmlspecialchars($sec) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" class="subtopic-required-all w-4 h-4 accent-red-600">
                    <span class="text-sm font-semibold text-red-600">Required for ALL students</span>
                </label>
                <p class="help-text ml-6">When checked, this subtopic becomes mandatory for every student regardless of section or course.</p>
            </div>
            <div class="mb-2">
                <span class="field-label">Description</span>
                <textarea rows="2" class="form-control subtopic-description" placeholder="Optional details about this subtopic"></textarea>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger remove-subtopic"><i class="fas fa-trash-alt mr-1"></i> Remove</button>
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
        // Which courses are checked right now?
        const checkedCourses = Array.from(courseCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);

        // Build set of sections valid for those courses
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

// ==================== EDIT MODAL – LOAD SESSION DATA ====================
document.querySelectorAll('.edit-session').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;
        try {
            const resp = await fetch(`get_session.php?id=${id}`);
            let rawText = await resp.text();
            const jsonStart = rawText.indexOf('{');
            if (jsonStart > 0) rawText = rawText.substring(jsonStart);
            const data = JSON.parse(rawText);
            if (!data.subtopics) data.subtopics = [];

            let html = `
            <form method="POST" id="editSessionForm">
                <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="${data.session_id}">
                <div class="bg-white rounded-lg p-4 mb-4 shadow-sm">
                    <h6 class="font-semibold text-gray-700 mb-3"><i class="fas fa-info-circle mr-1"></i>Session Details</h6>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <span class="field-label">Title <span class="text-red-500">*</span></span>
                            <input type="text" name="title" class="w-full border border-gray-300 rounded-lg p-2.5" value="${escapeHtml(data.title)}" required>
                        </div>
                        <div>
                            <span class="field-label">Phase <span class="text-red-500">*</span></span>
                            <select name="phase" class="w-full border border-gray-300 rounded-lg p-2.5" required>
                                <option value="Preparation" ${data.phase=='Preparation'?'selected':''}>Preparation</option>
                                <option value="Pre-Employment" ${data.phase=='Pre-Employment'?'selected':''}>Pre-Employment</option>
                                <option value="Career Fair" ${data.phase=='Career Fair'?'selected':''}>Career Fair</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="field-label">Description</span>
                        <textarea name="description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5">${escapeHtml(data.description)}</textarea>
                    </div>
                    <div class="form-check mt-3">
                        <input type="checkbox" name="allow_multiple" value="1" class="form-check-input" id="edit_allow_multiple" ${data.allow_multiple==1?'checked':''}>
                        <label class="form-check-label text-sm" for="edit_allow_multiple">Allow multiple registrations per student in this session</label>
                    </div>
                </div>

                <div class="bg-white rounded-lg p-4 shadow-sm">
                    <div class="flex justify-between items-center mb-4">
                        <h6 class="font-semibold text-gray-700"><i class="fas fa-list-ul mr-1"></i>Subtopics</h6>
                        <div class="flex gap-2">
                            <button type="button" id="even-split-btn" class="bg-blue-500 hover:bg-blue-600 text-white text-sm px-3 py-1.5 rounded-lg">Even Split</button>
                            <button type="button" id="add-subtopic-edit" class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1.5 rounded-lg">Add Subtopic</button>
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

                html += `<div class="card mb-3 border border-gray-200 rounded-lg"><div class="card-body p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                        <div><span class="field-label">Subtopic Title *</span><input type="text" class="form-control subtopic-title" value="${escapeHtml(sub.title)}"></div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><span class="field-label">Capacity</span><input type="number" class="form-control subtopic-capacity" value="${sub.capacity}"></div>
                            <div><span class="field-label">Deadline</span><input type="datetime-local" class="form-control subtopic-deadline" value="${sub.deadline?sub.deadline.slice(0,16):''}"></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                        <div><span class="field-label">Date</span><input type="date" class="form-control subtopic-date" value="${sub.subtopic_date||''}"></div>
                        <div><span class="field-label">Start</span><input type="time" class="form-control subtopic-start-time" value="${sub.subtopic_start_time||''}"></div>
                        <div><span class="field-label">End</span><input type="time" class="form-control subtopic-end-time" value="${sub.subtopic_end_time||''}"></div>
                        <div><span class="field-label">Proctor</span><input type="text" class="form-control subtopic-proctor" value="${escapeHtml(sub.subtopic_proctor)}"></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div><span class="field-label">Location</span><input type="text" class="form-control subtopic-location" value="${escapeHtml(sub.subtopic_location)}"></div>
                        <div><span class="field-label">Attendance Type</span><select class="form-select subtopic-attendance-type"><option value="physical" ${(sub.attendance_type||'physical')==='physical'?'selected':''}>Physical</option><option value="module" ${sub.attendance_type==='module'?'selected':''}>Module</option></select></div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div><span class="field-label">Visible to Courses</span><div class="checkbox-group">`;
                <?= $courses_json ?>?.forEach(c => { html += `<label><input type="checkbox" value="${c}" class="subtopic-visible-course" ${vis_crs.includes(c)?'checked':''}> ${c}</label>`; });
                html += `</div></div>
                        <div><span class="field-label">Visible to Sections</span><div class="checkbox-group">`;
                const anyVisCourse = vis_crs.length > 0;
                <?= $sections_json ?>?.forEach(s => { html += `<label><input type="checkbox" value="${s}" class="subtopic-visible-section" ${vis_sec.includes(s)?'checked':''} ${anyVisCourse?'':'disabled'}> ${s}</label>`; });
                html += `</div></div></div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div><span class="field-label">Required for Courses</span><div class="checkbox-group">`;
                <?= $courses_json ?>?.forEach(c => { html += `<label><input type="checkbox" value="${c}" class="subtopic-required-course" ${req_crs.includes(c)?'checked':''} ${allRequired?'disabled':''}> ${c}</label>`; });
                html += `</div></div>
                        <div><span class="field-label">Required for Sections</span><div class="checkbox-group">`;
                <?= $sections_json ?>?.forEach(s => { html += `<label><input type="checkbox" value="${s}" class="subtopic-required-section" ${req_sec.includes(s)?'checked':''} ${(allRequired || req_crs.length > 0)?'':'disabled'}> ${s}</label>`; });
                html += `</div></div></div>

                    <div class="mb-3"><label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" class="subtopic-required-all w-4 h-4 accent-red-600" ${allRequired?'checked':''}> <span class="text-sm font-semibold text-red-600">Required for ALL students</span></label></div>

                    <div class="mb-2"><span class="field-label">Description</span><textarea rows="2" class="form-control subtopic-description">${escapeHtml(sub.description)}</textarea></div>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-subtopic-edit">Remove</button>
                </div></div>`;
            });

            html += `</div></div>
                <input type="hidden" name="subtopics_data" id="edit_subtopics_data">
                <div class="mt-6 text-right">
                    <button type="button" class="text-gray-600 bg-gray-200 hover:bg-gray-300 font-medium text-sm px-5 py-2 rounded-lg mr-2 transition" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-bold text-sm px-6 py-2.5 rounded-lg transition"><i class="fas fa-save mr-1"></i> Update Session</button>
                </div>
            </form>`;

            document.getElementById('editModalBody').innerHTML = html;
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
                fetch('subtopics.php',{method:'POST',body:fd}).then(()=>location.reload()).catch(alert);
            });

            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            modal.show();
            modal._element.addEventListener('hidden.bs.modal', function() {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            });

        } catch(err) {
            console.error('Edit modal error:', err);
            document.getElementById('editModalBody').innerHTML = '<div class="alert alert-danger">Error loading session. Check console.</div>';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
    });
});

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
    document.getElementById('notifySubtopic').innerHTML = '';
    if (!id) return;
    fetch(`get_session.php?id=${id}`).then(r=>r.json()).then(data => {
        if (data.subtopics) data.subtopics.forEach(s=>{
            const o = document.createElement('option'); o.value=s.subtopic_id; o.textContent=s.title;
            document.getElementById('notifySubtopic').appendChild(o);
        });
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
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Sending…';
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('session_id', document.getElementById('notifySession').value);
    const selSub = Array.from(document.getElementById('notifySubtopic').selectedOptions).map(o=>o.value);
    if (selSub.length) fd.append('subtopics', JSON.stringify(selSub));
    const selCrs = Array.from(document.querySelectorAll('#notifyCourseGroup input[type="checkbox"]:checked')).map(cb => cb.value);
    if (selCrs.length) fd.append('courses', JSON.stringify(selCrs));
    const selSec = Array.from(document.querySelectorAll('#notifySectionGroup input[type="checkbox"]:checked')).map(cb => cb.value);
    if (selSec.length) fd.append('sections', JSON.stringify(selSec));
    fd.append('message', document.getElementById('notifyMessage').value);
    fetch('notify_custom.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
        if (d.message) {
            alert('✅ ' + d.message);
            bootstrap.Modal.getInstance(document.getElementById('notifyModal'))?.hide();
        } else if (d.error) {
            alert('❌ ' + d.error);
        } else {
            alert('❓ Unexpected response: ' + JSON.stringify(d));
        }
        btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Send'; btn.disabled = false;
    }).catch(e=>{
        alert('Error: '+e);
        btn.innerHTML='<i class="fas fa-paper-plane mr-1"></i> Send';
        btn.disabled=false;
    });
});

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]);
}
</script>
</body>
</html>