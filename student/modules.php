<?php
/**
 * ============================================================
 * ACES System — Student Modules & Assessments
 * ============================================================
 * Redesigned for clarity, motivation, and preserved functionality.
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStudent();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$student_id = $_SESSION['student_id'];
$user_id    = $_SESSION['user_id'];

// ---- Student Info ----
$stmt = $pdo->prepare("
    SELECT u.full_name, s.student_id, s.program, s.section
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();
$student_name = $student['full_name'] ?? 'Student';

$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;
$action    = $_POST['action'] ?? '';

// ---------- Handle module / empty assessment completion ----------
if ($action === 'mark_complete' && $module_id) {
    $stmt = $pdo->prepare("SELECT module_id, type, content, subtopic_id FROM modules WHERE module_id = ? AND is_deleted = 0");
    $stmt->execute([$module_id]);
    $mod = $stmt->fetch();
    if ($mod) {
        $allow = false;
        if ($mod['type'] === 'module') {
            $allow = true;
        } elseif ($mod['type'] === 'assessment') {
            $raw = $mod['content'];
            if (strpos($raw, 'quiz:') !== 0 && strpos($raw, 'link:') !== 0) {
                $allow = true;
            }
        }
        if ($allow) {
            $check = $pdo->prepare("SELECT progress_id FROM student_module_progress WHERE student_id = ? AND module_id = ?");
            $check->execute([$student_id, $module_id]);
            if ($check->fetch()) {
                $upd = $pdo->prepare("UPDATE student_module_progress SET completed = 1, completion_date = NOW() WHERE student_id = ? AND module_id = ?");
                $upd->execute([$student_id, $module_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO student_module_progress (student_id, module_id, completed, completion_date) VALUES (?, ?, 1, NOW())");
                $ins->execute([$student_id, $module_id]);
            }

            // TASK 14: Auto-attendance for module-based subtopics
            $subtopic_id = $mod['subtopic_id'];
            $stmtSub = $pdo->prepare("SELECT attendance_type, session_id FROM subtopics WHERE subtopic_id = ? AND is_deleted = 0");
            $stmtSub->execute([$subtopic_id]);
            $sub = $stmtSub->fetch();

            if ($sub && $sub['attendance_type'] === 'module') {
                $stmtTotalMods = $pdo->prepare("SELECT COUNT(*) FROM modules WHERE subtopic_id = ? AND is_deleted = 0");
                $stmtTotalMods->execute([$subtopic_id]);
                $totalModules = (int)$stmtTotalMods->fetchColumn();

                $stmtCompleted = $pdo->prepare("
                    SELECT COUNT(*) FROM modules m
                    JOIN student_module_progress sp ON sp.module_id = m.module_id
                    WHERE m.subtopic_id = ? AND sp.student_id = ? AND sp.completed = 1 AND m.is_deleted = 0
                ");
                $stmtCompleted->execute([$subtopic_id, $student_id]);
                $completedModules = (int)$stmtCompleted->fetchColumn();

                if ($completedModules >= $totalModules && $totalModules > 0) {
                    $stmtReg = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND subtopic_id = ?");
                    $stmtReg->execute([$student_id, $subtopic_id]);
                    if ($stmtReg->fetch()) {
                        $stmtAtt = $pdo->prepare("SELECT attendance_id, attendance_status, auto_generated FROM attendance WHERE student_id = ? AND subtopic_id = ? AND is_deleted = 0");
                        $stmtAtt->execute([$student_id, $subtopic_id]);
                        $existing = $stmtAtt->fetch();

                        if ($existing) {
                            if ($existing['auto_generated'] == 1 && $existing['attendance_status'] !== 'present') {
                                $stmtUpdAtt = $pdo->prepare("UPDATE attendance SET attendance_status = 'present', validated = 1, auto_generated = 1, recorded_by = ? WHERE attendance_id = ?");
                                $stmtUpdAtt->execute([$user_id, $existing['attendance_id']]);
                            }
                        } else {
                            $stmtInsAtt = $pdo->prepare("
                                INSERT INTO attendance (student_id, session_id, subtopic_id, attendance_status, validated, auto_generated, recorded_by, attendance_date)
                                VALUES (?, ?, ?, 'present', 1, 1, ?, NOW())
                            ");
                            $stmtInsAtt->execute([$student_id, $sub['session_id'], $subtopic_id, $user_id]);
                        }
                    }
                }
            }

            header("Location: modules.php?module_id=$module_id&completed=1");
            exit;
        }
    }
}

// ---------- Handle assessment submission ----------
if ($action === 'submit_assessment' && $module_id) {
    $answers = $_POST['answers'] ?? [];
    $stmt = $pdo->prepare("SELECT content, type FROM modules WHERE module_id = ? AND is_deleted = 0");
    $stmt->execute([$module_id]);
    $module = $stmt->fetch();
    if ($module && $module['type'] == 'assessment' && strpos($module['content'], 'quiz:') === 0) {
        $quiz_json = substr($module['content'], 5);
        $questions = json_decode($quiz_json, true);
        $score = 0;
        $total_points = 0;
        foreach ($questions as $idx => $q) {
            $points = floatval($q['points'] ?? 1);
            $total_points += $points;
            $user_answer = $answers[$idx] ?? '';
            $correct = false;
            if ($q['type'] == 'multiple_choice' || $q['type'] == 'true_false') {
                if (trim($user_answer) == trim($q['correct'])) $correct = true;
            }
            if ($correct) $score += $points;
        }
        $score_percent = ($total_points > 0) ? round(($score / $total_points) * 100) : 0;
        $check = $pdo->prepare("SELECT progress_id FROM student_module_progress WHERE student_id = ? AND module_id = ?");
        $check->execute([$student_id, $module_id]);
        if ($check->fetch()) {
            $upd = $pdo->prepare("UPDATE student_module_progress SET completed = 1, score = ?, completion_date = NOW() WHERE student_id = ? AND module_id = ?");
            $upd->execute([$score_percent, $student_id, $module_id]);
        } else {
            $ins = $pdo->prepare("INSERT INTO student_module_progress (student_id, module_id, completed, score, completion_date) VALUES (?, ?, 1, ?, NOW())");
            $ins->execute([$student_id, $module_id, $score_percent]);
        }
        header("Location: modules.php?module_id=$module_id&submitted=1");
        exit;
    }
}

// ---------- Fetch all accessible modules ----------
$stmt = $pdo->prepare("
    SELECT DISTINCT m.module_id, m.title, m.description, m.type, m.content, m.due_date,
           sub.title AS subtopic_title, sub.subtopic_id,
           sp.completed, sp.score
    FROM registrations r
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
    JOIN modules m ON m.subtopic_id = sub.subtopic_id
    LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = r.student_id
    WHERE r.student_id = ? AND m.is_deleted = 0 AND sub.is_deleted = 0
    ORDER BY m.due_date ASC, m.module_id ASC
");
$stmt->execute([$student_id]);
$all_modules = $stmt->fetchAll();

$total_modules     = count($all_modules);
$completed_modules = 0;
$modules_by_subtopic = [];
foreach ($all_modules as $mod) {
    if ($mod['completed']) $completed_modules++;
    $modules_by_subtopic[$mod['subtopic_title']][] = $mod;
}
$progress_percent = $total_modules > 0 ? round(($completed_modules / $total_modules) * 100) : 0;

function getPreview($content, $type) {
    if ($type == 'module') {
        if (strpos($content, 'file:') === 0) return '[File] ' . basename(substr($content, 5));
        if (strpos($content, 'link:') === 0) return '[Link] ' . substr($content, 5);
        if (strpos($content, 'text:') === 0) return substr(strip_tags(substr($content, 5)), 0, 50) . '...';
        return 'Module content';
    } else {
        if (strpos($content, 'link:') === 0) return '[External Assessment]';
        if (strpos($content, 'quiz:') === 0) return '[Quiz] ' . substr_count($content, '"text"') . ' questions';
        return 'Assessment';
    }
}
function getIconClass($type, $content) {
    if ($type == 'module') {
        if (strpos($content, 'file:') === 0) return 'fa-file-alt';
        if (strpos($content, 'link:') === 0) return 'fa-external-link-alt';
        if (strpos($content, 'text:') === 0) return 'fa-align-left';
        return 'fa-book';
    } else {
        if (strpos($content, 'link:') === 0) return 'fa-link';
        if (strpos($content, 'quiz:') === 0) return 'fa-question-circle';
        return 'fa-clipboard-list';
    }
}

// ========== DETAIL VIEW ==========
if ($module_id) {
    $current_module = null;
    $index = -1;
    foreach ($all_modules as $i => $mod) {
        if ($mod['module_id'] == $module_id) {
            $current_module = $mod;
            $index = $i;
            break;
        }
    }
    if (!$current_module) {
        header("Location: modules.php");
        exit;
    }

    $prev_module = ($index > 0) ? $all_modules[$index - 1] : null;
    $next_module = ($index < $total_modules - 1) ? $all_modules[$index + 1] : null;

    // Content rendering
    $content_html = '';
    $empty_assessment = false;
    $is_pdf = false;
    $file_path = '';

    if ($current_module['type'] == 'module') {
        $content_raw = $current_module['content'];
        if (strpos($content_raw, 'file:') === 0) {
            $file = substr($content_raw, 5);
            $file_path = "../uploads/$file";
            if (file_exists($file_path)) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($ext) == 'pdf') {
                    $is_pdf = true;
                    $content_html = '
                    <div id="pdfViewer" class="border rounded-lg overflow-hidden bg-gray-100">
                        <div class="flex items-center justify-between bg-gray-800 text-white px-4 py-2 text-sm">
                            <button id="prevPageBtn" class="bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded disabled:opacity-50">&laquo; Prev</button>
                            <span id="pageCounter">Page <span id="currentPage">1</span> / <span id="totalPages">?</span></span>
                            <button id="nextPageBtn" class="bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded disabled:opacity-50">Next &raquo;</button>
                        </div>
                        <div class="flex justify-center p-4">
                            <canvas id="pdfCanvas" class="max-w-full shadow-lg"></canvas>
                        </div>
                    </div>';
                } else {
                    $content_html = '<a href="' . $file_path . '" target="_blank" class="inline-flex items-center gap-2 bg-green-700 text-white px-5 py-3 rounded-lg font-semibold hover:bg-green-800 transition"><i class="fas fa-download"></i> Download File</a>';
                }
            } else {
                $content_html = '<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-3 rounded">File not found.</div>';
            }
        } elseif (strpos($content_raw, 'link:') === 0) {
            $url = substr($content_raw, 5);
            $content_html = '<iframe src="' . htmlspecialchars($url) . '" width="100%" height="600px" frameborder="0" class="rounded-lg border border-gray-200"></iframe>';
        } elseif (strpos($content_raw, 'text:') === 0) {
            $text = substr($content_raw, 5);
            $content_html = '<div class="prose max-w-none leading-relaxed">' . nl2br(htmlspecialchars($text)) . '</div>';
        } else {
            $content_html = '<div class="text-gray-500 italic">No content available.</div>';
        }
    } else {
        $content_raw = $current_module['content'];
        if (strpos($content_raw, 'link:') === 0) {
            $url = substr($content_raw, 5);
            $content_html = '<iframe src="' . htmlspecialchars($url) . '" width="100%" height="600px" frameborder="0" class="rounded-lg border border-gray-200"></iframe>';
        } elseif (strpos($content_raw, 'quiz:') === 0) {
            $quiz_json = substr($content_raw, 5);
            $questions = json_decode($quiz_json, true);
            if (is_array($questions)) {
                if ($current_module['completed']) {
                    $content_html = '<div class="bg-green-50 border-l-4 border-green-500 text-green-800 p-4 rounded-r-lg"><i class="fas fa-check-circle mr-2"></i>You have already completed this assessment. Score: <strong>' . ($current_module['score'] ?? 'N/A') . '%</strong></div>';
                } else {
                    $content_html = '<form method="POST" id="quizForm">';
                    $content_html .= csrf_field();
                    $content_html .= '<input type="hidden" name="action" value="submit_assessment">';
                    foreach ($questions as $idx => $q) {
                        $content_html .= '<div class="mb-5 p-5 border border-gray-200 rounded-xl bg-gray-50/50">';
                        $content_html .= '<label class="font-semibold text-gray-800 text-base">' . htmlspecialchars($q['text']) . '</label>';
                        $content_html .= '<span class="ml-2 text-xs text-gray-500">(' . ($q['points'] ?? 1) . ' pts)</span><br>';
                        $content_html .= '<div class="mt-3 space-y-2">';
                        if ($q['type'] == 'multiple_choice') {
                            foreach ($q['options'] as $opt) {
                                $content_html .= '<label class="flex items-center gap-3 p-3 bg-white border border-gray-200 rounded-lg hover:border-green-300 cursor-pointer transition"><input type="radio" name="answers[' . $idx . ']" value="' . htmlspecialchars($opt) . '" class="w-4 h-4 accent-green-600"><span>' . htmlspecialchars($opt) . '</span></label>';
                            }
                        } elseif ($q['type'] == 'true_false') {
                            $content_html .= '<label class="flex items-center gap-3 p-3 bg-white border border-gray-200 rounded-lg hover:border-green-300 cursor-pointer transition"><input type="radio" name="answers[' . $idx . ']" value="True" class="w-4 h-4 accent-green-600"><span>True</span></label>';
                            $content_html .= '<label class="flex items-center gap-3 p-3 bg-white border border-gray-200 rounded-lg hover:border-green-300 cursor-pointer transition"><input type="radio" name="answers[' . $idx . ']" value="False" class="w-4 h-4 accent-green-600"><span>False</span></label>';
                        } elseif ($q['type'] == 'short_answer') {
                            $content_html .= '<textarea name="answers[' . $idx . ']" class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-green-500 outline-none" rows="3"></textarea>';
                        }
                        $content_html .= '</div></div>';
                    }
                    $content_html .= '<button type="submit" class="w-full sm:w-auto bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-8 rounded-lg transition">Submit Assessment</button>';
                    $content_html .= '</form>';
                }
            } else {
                $content_html = '<div class="bg-red-50 border border-red-200 text-red-800 p-3 rounded">Invalid quiz format.</div>';
            }
        } else {
            $content_html = '<div class="text-gray-500 italic">No content available.</div>';
            $empty_assessment = true;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en" class="h-full">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($current_module['title']) ?> | ACES Student</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <script>
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        </script>
        <style>
            html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
            .prose { max-width: 100%; }
        </style>
    </head>
    <body class="h-full bg-gray-100">
    <div class="flex h-full">
        <?php include '../includes/student_sidebar.php'; ?>
        <div class="flex-1 flex flex-col overflow-auto">
            <?php include '../includes/header.php'; ?>
            <div class="flex-1 p-4 md:p-8">

                <nav class="mb-6 flex items-center justify-between text-sm">
                    <a href="modules.php" class="inline-flex items-center gap-2 text-green-700 hover:text-green-900 font-semibold">
                        <i class="fas fa-arrow-left"></i><span>Back to All Modules</span>
                    </a>
                    <div class="flex items-center gap-2">
                        <?php if ($prev_module): ?>
                            <a href="?module_id=<?= $prev_module['module_id'] ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 font-medium" title="<?= htmlspecialchars($prev_module['title']) ?>"><i class="fas fa-chevron-left text-xs"></i></a>
                        <?php endif; ?>
                        <?php if ($next_module): ?>
                            <a href="?module_id=<?= $next_module['module_id'] ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 font-medium" title="<?= htmlspecialchars($next_module['title']) ?>"><i class="fas fa-chevron-right text-xs"></i></a>
                        <?php endif; ?>
                    </div>
                </nav>

                <?php if (isset($_GET['completed'])): ?>
                    <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 text-green-800 rounded-r-lg flex items-center gap-3"><i class="fas fa-check-circle text-green-500"></i> Marked as complete!</div>
                <?php elseif (isset($_GET['submitted'])): ?>
                    <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 text-green-800 rounded-r-lg flex items-center gap-3"><i class="fas fa-check-circle text-green-500"></i> Assessment submitted successfully.</div>
                <?php endif; ?>

                <div class="bg-white rounded-2xl shadow-sm p-6 md:p-8 mb-6">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="text-xs font-semibold uppercase tracking-wider <?= $current_module['type'] == 'module' ? 'text-blue-600' : 'text-purple-600' ?>"><?= ucfirst($current_module['type']) ?></span>
                        <?php if ($current_module['completed']): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-check text-xs"></i> Completed</span>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-800 leading-tight"><?= htmlspecialchars($current_module['title']) ?></h1>
                    <?php if (!empty($current_module['description'])): ?>
                        <p class="text-gray-600 text-sm mt-4 leading-relaxed"><?= nl2br(htmlspecialchars($current_module['description'])) ?></p>
                    <?php endif; ?>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-500 mt-6 pt-6 border-t border-gray-100">
                        <span><i class="fas fa-folder-open mr-1.5 text-gray-400"></i><?= htmlspecialchars($current_module['subtopic_title']) ?></span>
                        <span><i class="fas fa-calendar-alt mr-1.5 text-gray-400"></i>Due: <?= $current_module['due_date'] ? date('M d, Y', strtotime($current_module['due_date'])) : 'No deadline' ?></span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-6 md:p-8">
                    <?php if ($current_module['type'] === 'module' && !$is_pdf): ?>
                        <div class="max-h-[60vh] overflow-y-auto p-4 border border-gray-100 rounded-lg" id="moduleContentScroll"><?= $content_html ?></div>
                    <?php else: ?>
                        <?= $content_html ?>
                    <?php endif; ?>

                    <?php if (($current_module['type'] === 'module' || ($current_module['type'] === 'assessment' && $empty_assessment)) && !$current_module['completed']): ?>
                        <div class="mt-8 pt-6 border-t border-gray-100">
                            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4">
                                <label class="flex items-center gap-3 text-sm text-gray-700 cursor-pointer flex-1">
                                    <input type="checkbox" id="confirmRead" class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500" <?= ($current_module['type'] === 'assessment' && $empty_assessment) ? '' : 'disabled' ?>>
                                    <span id="scrollHint">
                                        <?php if ($is_pdf): ?>
                                            <i class="fas fa-info-circle mr-1 text-gray-400"></i> Please view all pages to enable completion.
                                        <?php elseif ($current_module['type'] === 'assessment' && $empty_assessment): ?>
                                            <i class="fas fa-info-circle mr-1 text-gray-400"></i> Confirm to mark as done.
                                        <?php else: ?>
                                            <i class="fas fa-info-circle mr-1 text-gray-400"></i> Please scroll to the end to enable completion.
                                        <?php endif; ?>
                                    </span>
                                </label>
                                <form method="POST" id="completeForm" class="w-full sm:w-auto">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_complete">
                                    <button type="submit" id="markCompleteBtn" class="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-6 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition" <?= ($current_module['type'] === 'assessment' && $empty_assessment) ? '' : 'disabled' ?>>Mark as <?= $current_module['type'] === 'module' ? 'Complete' : 'Done' ?></button>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($current_module['completed']): ?>
                        <div class="mt-8 pt-6 border-t border-gray-100 text-center">
                            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-green-50 text-green-700 font-semibold"><i class="fas fa-check-circle"></i> You have completed this <?= $current_module['type'] ?>.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php if ($prev_module): ?>
                        <a href="?module_id=<?= $prev_module['module_id'] ?>" class="p-4 bg-white rounded-xl shadow-sm border border-gray-200 hover:border-green-300 hover:shadow-md transition flex items-center gap-3">
                            <i class="fas fa-arrow-left text-green-600"></i>
                            <div><div class="text-xs font-semibold uppercase text-gray-400">Previous</div><div class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($prev_module['title']) ?></div></div>
                        </a>
                    <?php else: ?><div></div><?php endif; ?>
                    <?php if ($next_module): ?>
                        <a href="?module_id=<?= $next_module['module_id'] ?>" class="p-4 bg-white rounded-xl shadow-sm border border-gray-200 hover:border-green-300 hover:shadow-md transition flex items-center justify-end gap-3 text-right">
                            <div><div class="text-xs font-semibold uppercase text-gray-400">Next</div><div class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($next_module['title']) ?></div></div>
                            <i class="fas fa-arrow-right text-green-600"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($current_module['type'] === 'module' && $is_pdf): ?>
    <script>
        (async function() {
            const moduleId = <?= $module_id ?>;
            const studentId = '<?= $_SESSION['student_id'] ?>';
            const storageKey = `pdf_visited_${moduleId}_${studentId}`;
            const confirmationPageNum = 0; // virtual page - will be set after load

            let totalPages = 0;
            let currentPageNum = 1;
            let visitedPages = JSON.parse(localStorage.getItem(storageKey)) || [];

            const canvas = document.getElementById('pdfCanvas');
            const ctx = canvas.getContext('2d');
            const prevBtn = document.getElementById('prevPageBtn');
            const nextBtn = document.getElementById('nextPageBtn');
            const currentPageSpan = document.getElementById('currentPage');
            const totalPagesSpan = document.getElementById('totalPages');
            const markBtn = document.getElementById('markCompleteBtn');
            const confirmCheck = document.getElementById('confirmRead');
            const pdfHint = document.getElementById('pdfHint');
            const pageCounter = document.getElementById('pageCounter');

            if (markBtn) markBtn.disabled = true;
            if (confirmCheck) confirmCheck.disabled = true;

            let pdfDoc = null;

            try {
                pdfDoc = await pdfjsLib.getDocument('<?= $file_path ?>').promise;
                totalPages = pdfDoc.numPages;
                totalPagesSpan.textContent = totalPages;

                // Show "Page X / total" (no +1 visible to user)
                function updatePageCounter(displayPage) {
                    currentPageSpan.textContent = displayPage;
                    // Mark the confirmation page differently
                    if (displayPage === '✓') {
                        pageCounter.innerHTML = 'Last page <span id="currentPage">✓</span> / <span id="totalPages">' + totalPages + '</span>';
                    } else {
                        pageCounter.innerHTML = 'Page <span id="currentPage">' + displayPage + '</span> / <span id="totalPages">' + totalPages + '</span>';
                    }
                }

                // Render a specific PDF page into the canvas
                function renderPage(pageNum) {
                    pdfDoc.getPage(pageNum).then(function(page) {
                        const viewport = page.getViewport({scale: 1.5});
                        canvas.height = viewport.height;
                        canvas.width = viewport.width;
                        canvas.style.display = '';
                        page.render({canvasContext: ctx, viewport: viewport}).promise.then(function() {
                            updatePageCounter(pageNum);
                            if (!visitedPages.includes(pageNum)) {
                                visitedPages.push(pageNum);
                                localStorage.setItem(storageKey, JSON.stringify(visitedPages));
                            }
                            updateCompletion();
                        });
                    });
                }

                // Show the confirmation "page" — hides canvas, shows a big confirmation panel
                function renderConfirmationPage() {
                    updatePageCounter('✓');

                    // Hide the canvas
                    canvas.style.display = 'none';

                    // If we already added the confirmation panel, show it
                    let panel = document.getElementById('pdfConfirmationPanel');
                    if (!panel) {
                        panel = document.createElement('div');
                        panel.id = 'pdfConfirmationPanel';
                        panel.className = 'flex flex-col items-center justify-center py-16 px-6 bg-gradient-to-b from-green-50 to-white rounded-lg';
                        panel.innerHTML = `
                            <div class="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mb-6">
                                <i class="fas fa-book-reader text-green-700 text-3xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-2">You've reached the end!</h3>
                            <p class="text-gray-500 text-sm mb-8 text-center max-w-md">
                                You've completed reading all ${totalPages} pages of this module.
                                Please confirm below to mark it as complete.
                            </p>
                            <div class="flex items-center gap-3">
                                <button type="button" id="pdfConfirmationPrev" class="text-gray-600 hover:text-gray-800 font-medium text-sm">
                                    ← Back to page ${totalPages}
                                </button>
                            </div>
                        `;
                        canvas.parentElement.appendChild(panel);

                        // Back button from confirmation page
                        document.getElementById('pdfConfirmationPrev').addEventListener('click', () => {
                            currentPageNum = totalPages;
                            panel.remove();
                            renderPage(totalPages);
                        });
                    }

                    // Move the completion UI (checkbox + button) INTO the confirmation panel
                    const completeForm = document.getElementById('completeForm');
                    const confirmLabel = completeForm?.parentElement?.querySelector('label');
                    if (completeForm && confirmLabel && !document.getElementById('pdfConfirmationEmbedded')) {
                        const wrapper = document.createElement('div');
                        wrapper.id = 'pdfConfirmationEmbedded';
                        wrapper.className = 'mt-6 p-4 bg-white border border-green-200 rounded-lg shadow-sm w-full max-w-md';
                        wrapper.appendChild(confirmLabel);
                        wrapper.appendChild(completeForm);
                        panel.appendChild(wrapper);
                    }

                    // Enable the confirm checkbox now that they've reached the end
                    if (confirmCheck) confirmCheck.disabled = false;
                    if (pdfHint) pdfHint.style.display = 'none'; // hide the old hint
                }

                function updateCompletion() {
                    const allVisited = visitedPages.length === totalPages;
                    // Note: the checkbox gets enabled when reaching the confirmation page,
                    // not just when all pages have been visited (though both should be true by then)
                }

                // --- Navigation buttons ---
                prevBtn.addEventListener('click', function() {
                    const panel = document.getElementById('pdfConfirmationPanel');
                    if (panel) {
                        // We're on the confirmation page -> go back to last actual page
                        panel.remove();
                        currentPageNum = totalPages;
                        renderPage(totalPages);
                        if (confirmCheck) confirmCheck.checked = false;
                        return;
                    }
                    if (currentPageNum > 1) {
                        currentPageNum--;
                        renderPage(currentPageNum);
                    }
                });

                nextBtn.addEventListener('click', function() {
                    const panel = document.getElementById('pdfConfirmationPanel');
                    if (panel) return; // already on confirmation page

                    if (currentPageNum < totalPages) {
                        currentPageNum++;
                        renderPage(currentPageNum);
                    } else if (currentPageNum === totalPages) {
                        // On last PDF page -> clicking Next shows the confirmation page
                        renderConfirmationPage();
                    }
                });

                // Checkbox enables the Mark Complete button
                if (confirmCheck && markBtn) {
                    confirmCheck.addEventListener('change', function() {
                        markBtn.disabled = !this.checked;
                    });
                }

                // Start on page 1
                renderPage(1);

            } catch (err) {
                document.getElementById('pdfViewer').innerHTML = '<p class="text-red-500 p-4">Failed to load PDF.</p>';
                console.error(err);
            }
        })();
    </script>
    <?php elseif ($current_module['type'] === 'module'): ?>
    <script>
        (function() {
            const scrollContainer = document.getElementById('moduleContentScroll');
            const confirmCheck   = document.getElementById('confirmRead');
            const markBtn        = document.getElementById('markCompleteBtn');
            const scrollHint     = document.getElementById('scrollHint');
            const completeForm   = document.getElementById('completeForm');
            if (!scrollContainer || !confirmCheck || !markBtn) return;
            let hasScrolledToBottom = false;
            function checkBottom() {
                const tolerance = 10;
                const atBottom = scrollContainer.scrollHeight - scrollContainer.scrollTop - scrollContainer.clientHeight <= tolerance;
                const isNotScrollable = scrollContainer.scrollHeight <= scrollContainer.clientHeight + tolerance;
                if ((atBottom || isNotScrollable) && !hasScrolledToBottom) {
                    hasScrolledToBottom = true;
                    confirmCheck.disabled = false;
                    scrollHint.innerHTML = '<i class="fas fa-check-circle mr-1 text-green-600"></i> You\'ve reached the end! Confirm below to complete.';
                }
                markBtn.disabled = !(hasScrolledToBottom && confirmCheck.checked);
            }
            scrollContainer.addEventListener('scroll', checkBottom);
            confirmCheck.addEventListener('change', function() { markBtn.disabled = !(hasScrolledToBottom && this.checked); });
            checkBottom();
            setTimeout(checkBottom, 300);
            setTimeout(checkBottom, 800);
            completeForm?.addEventListener('submit', function(e) {
                if (markBtn.disabled) { e.preventDefault(); return false; }
                markBtn.disabled = true;
                markBtn.innerText = 'Saving…';
            });
        })();
    </script>
    <?php else: ?>
    <script>
        (function() {
            const confirmCheck = document.getElementById('confirmRead');
            const markBtn      = document.getElementById('markCompleteBtn');
            if (!confirmCheck || !markBtn) return;
            markBtn.disabled = true;
            confirmCheck.addEventListener('change', function() { markBtn.disabled = !this.checked; });
        })();
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}
?>

<!-- ========== LISTING MODE ========== -->
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Modules | ACES Student</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        .module-card { transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s; border-left-width: 4px; }
        .module-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
        .module-card.completed { border-left-color: #10b981; }
        .module-card.pending { border-left-color: #f59e0b; }
        .progress-ring { transition: stroke-dashoffset 0.5s ease-out; }
    </style>
</head>
<body class="h-full bg-gray-100">
<div class="flex h-full">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-auto">
        <?php include '../includes/header.php'; ?>
        <div class="flex-1 p-4 md:p-8">

            <div class="bg-white rounded-2xl shadow-sm p-6 md:p-8 mb-8">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Welcome back, <?= htmlspecialchars(explode(' ', $student_name)[0]) ?>! 👋</h1>
                        <p class="text-gray-500 mt-1">Here's an overview of your learning progress.</p>
                    </div>
                    <div class="flex items-center gap-5">
                        <div class="relative w-24 h-24 flex items-center justify-center">
                            <svg class="w-full h-full" viewBox="0 0 36 36">
                                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#e5e7eb" stroke-width="4"></circle>
                                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#0a6e2d" stroke-width="4" stroke-dasharray="<?= $progress_percent ?> 100" stroke-dashoffset="0" stroke-linecap="round" class="progress-ring"></circle>
                            </svg>
                            <div class="absolute text-xl font-bold text-[#0a6e2d]"><?= $progress_percent ?>%</div>
                        </div>
                        <div>
                            <div class="text-3xl font-bold text-gray-800"><?= $completed_modules ?> / <?= $total_modules ?></div>
                            <div class="text-sm text-gray-500">Modules Completed</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($all_modules)): ?>
                <div class="text-center py-20 bg-white rounded-2xl shadow-sm">
                    <i class="fas fa-book-open text-6xl text-gray-200 mb-4"></i>
                    <h3 class="text-lg font-semibold text-gray-700">No Modules Yet</h3>
                    <p class="text-gray-500 mt-1">You are not enrolled in any modules. Register for a session to get started.</p>
                </div>
            <?php else: ?>
                <?php foreach ($modules_by_subtopic as $subtopic_title => $modules): ?>
                    <div class="mb-8">
                        <h2 class="text-lg font-bold text-gray-700 mb-4 flex items-center gap-3">
                            <i class="fas fa-folder text-[#0a6e2d]"></i>
                            <?= htmlspecialchars($subtopic_title) ?>
                            <span class="text-sm font-normal text-gray-400">(<?= count($modules) ?> items)</span>
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                            <?php foreach ($modules as $mod): ?>
                                <div class="module-card bg-white rounded-xl shadow-sm overflow-hidden border-l-4 <?= $mod['completed'] ? 'completed' : 'pending' ?> cursor-pointer" onclick="window.location.href='?module_id=<?= $mod['module_id'] ?>'">
                                    <div class="p-5">
                                        <div class="flex items-center justify-between mb-3">
                                            <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-md <?= $mod['type'] == 'module' ? 'bg-blue-50 text-blue-700' : 'bg-purple-50 text-purple-700' ?>"><?= ucfirst($mod['type']) ?></span>
                                            <?php if ($mod['completed']): ?>
                                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-600"><i class="fas fa-check-circle"></i> Done</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-amber-500"><i class="far fa-circle"></i> Pending</span>
                                            <?php endif; ?>
                                        </div>
                                        <h3 class="text-base font-bold text-gray-800 mb-2 leading-snug line-clamp-2"><?= htmlspecialchars($mod['title']) ?></h3>
                                        <?php if (!empty($mod['description'])): ?>
                                            <p class="text-gray-500 text-xs mb-4 line-clamp-2"><?= htmlspecialchars(substr($mod['description'], 0, 100)) ?></p>
                                        <?php endif; ?>
                                        <div class="flex items-center justify-between text-xs text-gray-400 pt-3 border-t border-gray-100">
                                            <div class="flex items-center gap-1.5">
                                                <i class="fas <?= getIconClass($mod['type'], $mod['content']) ?>"></i>
                                                <span><?= getPreview($mod['content'], $mod['type']) ?></span>
                                            </div>
                                            <?php if ($mod['due_date']): ?>
                                                <div class="flex items-center gap-1.5"><i class="fas fa-calendar-alt"></i><span><?= date('M d', strtotime($mod['due_date'])) ?></span></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>