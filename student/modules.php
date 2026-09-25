<?php
// student/modules.php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStudent();

$student_id = $_SESSION['student_id'];
$user_id    = $_SESSION['user_id'];

// Student info
$stmt = $pdo->prepare("SELECT full_name, student_id, program, section FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.student_id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

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
            // Save progress
            $check = $pdo->prepare("SELECT progress_id FROM student_module_progress WHERE student_id = ? AND module_id = ?");
            $check->execute([$student_id, $module_id]);
            if ($check->fetch()) {
                $upd = $pdo->prepare("UPDATE student_module_progress SET completed = 1, completion_date = NOW() WHERE student_id = ? AND module_id = ?");
                $upd->execute([$student_id, $module_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO student_module_progress (student_id, module_id, completed, completion_date) VALUES (?, ?, 1, NOW())");
                $ins->execute([$student_id, $module_id]);
            }

            // ---- TASK 14: Auto-attendance for module‑based subtopics ----
            $subtopic_id = $mod['subtopic_id'];

            $stmtSub = $pdo->prepare("SELECT attendance_type, session_id FROM subtopics WHERE subtopic_id = ? AND is_deleted = 0");
            $stmtSub->execute([$subtopic_id]);
            $sub = $stmtSub->fetch();

            if ($sub && $sub['attendance_type'] === 'module') {
                $stmtTotalMods = $pdo->prepare("SELECT COUNT(*) FROM modules WHERE subtopic_id = ? AND is_deleted = 0");
                $stmtTotalMods->execute([$subtopic_id]);
                $totalModules = (int)$stmtTotalMods->fetchColumn();

                $stmtCompleted = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM modules m
                    JOIN student_module_progress sp ON sp.module_id = m.module_id
                    WHERE m.subtopic_id = ? AND sp.student_id = ? AND sp.completed = 1 AND m.is_deleted = 0
                ");
                $stmtCompleted->execute([$subtopic_id, $student_id]);
                $completedModules = (int)$stmtCompleted->fetchColumn();

                if ($completedModules >= $totalModules && $totalModules > 0) {
                    $stmtReg = $pdo->prepare("SELECT registration_id FROM registrations WHERE student_id = ? AND subtopic_id = ?");
                    $stmtReg->execute([$student_id, $subtopic_id]);
                    $registration = $stmtReg->fetch();

                    if ($registration) {
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
            // ---- End of TASK 14 ----

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
        $quiz_json   = substr($module['content'], 5);
        $questions   = json_decode($quiz_json, true);
        $score       = 0;
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
foreach ($all_modules as $mod) {
    if ($mod['completed']) $completed_modules++;
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
    $is_pdf = false;      // flag for PDF pagination
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
                    // Custom PDF.js viewer container
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
                    $content_html = '<a href="' . $file_path . '" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded">Download File</a>';
                }
            } else {
                $content_html = '<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-3 rounded">File not found.</div>';
            }
        } elseif (strpos($content_raw, 'link:') === 0) {
            $url = substr($content_raw, 5);
            $content_html = '<iframe src="' . htmlspecialchars($url) . '" width="100%" height="600px" frameborder="0"></iframe>';
        } elseif (strpos($content_raw, 'text:') === 0) {
            $text = substr($content_raw, 5);
            $content_html = '<div class="prose max-w-none">' . nl2br(htmlspecialchars($text)) . '</div>';
        } else {
            $content_html = '<div class="text-gray-500 italic">No content available.</div>';
        }
    } else { // assessment
        $content_raw = $current_module['content'];
        if (strpos($content_raw, 'link:') === 0) {
            $url = substr($content_raw, 5);
            $content_html = '<iframe src="' . htmlspecialchars($url) . '" width="100%" height="600px" frameborder="0"></iframe>';
        } elseif (strpos($content_raw, 'quiz:') === 0) {
            $quiz_json = substr($content_raw, 5);
            $questions = json_decode($quiz_json, true);
            if (is_array($questions)) {
                if ($current_module['completed']) {
                    $content_html = '<div class="bg-green-50 border border-green-200 text-green-800 p-3 rounded">You have already completed this assessment. Score: ' . ($current_module['score'] ?? 'N/A') . '%</div>';
                } else {
                    $content_html = '<form method="POST" id="quizForm">';
                    $content_html .= '<input type="hidden" name="action" value="submit_assessment">';
                    foreach ($questions as $idx => $q) {
                        $content_html .= '<div class="mb-4 border p-3 rounded">';
                        $content_html .= '<label class="font-semibold">' . htmlspecialchars($q['text']) . '</label> (' . ($q['points'] ?? 1) . ' pts)<br>';
                        if ($q['type'] == 'multiple_choice') {
                            foreach ($q['options'] as $opt) {
                                $content_html .= '<div class="form-check"><input class="form-check-input" type="radio" name="answers[' . $idx . ']" value="' . htmlspecialchars($opt) . '" id="q' . $idx . '_' . md5($opt) . '"><label class="form-check-label" for="q' . $idx . '_' . md5($opt) . '">' . htmlspecialchars($opt) . '</label></div>';
                            }
                        } elseif ($q['type'] == 'true_false') {
                            $content_html .= '<div class="form-check"><input class="form-check-input" type="radio" name="answers[' . $idx . ']" value="True" id="q' . $idx . '_true"><label for="q' . $idx . '_true">True</label></div>';
                            $content_html .= '<div class="form-check"><input class="form-check-input" type="radio" name="answers[' . $idx . ']" value="False" id="q' . $idx . '_false"><label for="q' . $idx . '_false">False</label></div>';
                        } elseif ($q['type'] == 'short_answer') {
                            $content_html .= '<textarea name="answers[' . $idx . ']" class="form-control" rows="3"></textarea>';
                        }
                        $content_html .= '</div>';
                    }
                    $content_html .= '<button type="submit" class="bg-green-700 text-white px-4 py-2 rounded">Submit Assessment</button>';
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
        <!-- PDF.js -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <script>
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        </script>
        <style>
            html, body { height: 100%; margin: 0; padding: 0; }
            .prose { max-width: 100%; }
        </style>
    </head>
    <body class="h-full bg-gray-100">
    <div class="flex h-full">
        <?php include '../includes/student_sidebar.php'; ?>
        <div class="flex-1 flex flex-col overflow-auto">
            <?php include '../includes/header.php'; ?>
            <div class="flex-1 p-6">

                <?php if (isset($_GET['completed'])): ?>
                    <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded">Marked as complete!</div>
                <?php elseif (isset($_GET['submitted'])): ?>
                    <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded">Assessment submitted successfully.</div>
                <?php endif; ?>

                <!-- Back & navigation -->
                <div class="mb-4 flex justify-between items-center">
                    <a href="modules.php" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Modules
                    </a>
                    <div class="flex gap-2">
                        <?php if ($prev_module): ?>
                            <a href="?module_id=<?= $prev_module['module_id'] ?>" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">← Previous</a>
                        <?php endif; ?>
                        <?php if ($next_module): ?>
                            <a href="?module_id=<?= $next_module['module_id'] ?>" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Module title card -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h1 class="text-3xl font-bold mb-2"><?= htmlspecialchars($current_module['title']) ?></h1>
                    <p class="text-gray-600 mb-4"><?= nl2br(htmlspecialchars($current_module['description'] ?? '')) ?></p>
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-calendar-alt"></i> Due: <?= $current_module['due_date'] ? date('M d, Y', strtotime($current_module['due_date'])) : 'No deadline' ?>
                        &nbsp;|&nbsp;
                        <i class="fas fa-folder-open"></i> Subtopic: <?= htmlspecialchars($current_module['subtopic_title']) ?>
                    </div>
                </div>

                <!-- Content area -->
                <div class="bg-white rounded-lg shadow p-6">
                    <?php if ($current_module['type'] === 'module' && !$is_pdf): ?>
                        <div class="max-h-[60vh] overflow-y-auto p-2 border border-gray-100 rounded" id="moduleContentScroll">
                            <?= $content_html ?>
                        </div>
                    <?php else: ?>
                        <?= $content_html ?>
                    <?php endif; ?>

                    <!-- Completion box for modules and empty assessments -->
                    <?php if ($current_module['type'] === 'module' || ($current_module['type'] === 'assessment' && $empty_assessment)): ?>
                        <?php if ($current_module['completed']): ?>
                            <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
                                <i class="fas fa-check-circle mr-2"></i> You have already completed this <?= $current_module['type'] === 'module' ? 'module' : 'assessment' ?>.
                            </div>
                        <?php else: ?>
                            <div class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                <?php if ($current_module['type'] === 'module' && !$is_pdf): ?>
                                    <p class="text-sm text-gray-500 mb-2" id="scrollHint">
                                        <i class="fas fa-info-circle mr-1"></i> Please scroll to the end of the content to enable completion.
                                    </p>
                                <?php elseif ($is_pdf): ?>
                                    <p class="text-sm text-gray-500 mb-2" id="pdfHint">
                                        <i class="fas fa-info-circle mr-1"></i> Please view all pages of the PDF to enable completion.
                                    </p>
                                <?php else: ?>
                                    <p class="text-sm text-gray-500 mb-2">
                                        <i class="fas fa-info-circle mr-1"></i> This assessment has no questions. Please confirm to mark as complete.
                                    </p>
                                <?php endif; ?>
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                        <input type="checkbox" id="confirmRead" class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500" <?= ($current_module['type'] === 'assessment' && $empty_assessment) ? '' : 'disabled' ?>>
                                        <span>I have <?= $current_module['type'] === 'module' ? ($is_pdf ? 'viewed all pages of this module' : 'read this module') : 'acknowledged this assessment' ?></span>
                                    </label>
                                    <form method="POST" id="completeForm" class="inline">
                                        <input type="hidden" name="action" value="mark_complete">
                                        <button type="submit" id="markCompleteBtn" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded disabled:opacity-50 disabled:cursor-not-allowed" <?= ($current_module['type'] === 'assessment' && $empty_assessment) ? '' : 'disabled' ?>>
                                            Mark as <?= $current_module['type'] === 'module' ? 'Complete' : 'Done' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Bottom navigation -->
                <div class="mt-6 flex justify-between">
                    <?php if ($prev_module): ?>
                        <a href="?module_id=<?= $prev_module['module_id'] ?>" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">← Previous: <?= htmlspecialchars($prev_module['title']) ?></a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <?php if ($next_module): ?>
                        <a href="?module_id=<?= $next_module['module_id'] ?>" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Next: <?= htmlspecialchars($next_module['title']) ?> →</a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($current_module['type'] === 'module'): ?>
    <?php if ($is_pdf): ?>
    <!-- PDF pagination logic -->
    <script>
        (async function() {
            const moduleId = <?= $module_id ?>;
            const studentId = '<?= $_SESSION['student_id'] ?>';
            const storageKey = `pdf_visited_${moduleId}_${studentId}`;

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

            if (markBtn) markBtn.disabled = true;
            if (confirmCheck) confirmCheck.disabled = true;

            try {
                const pdf = await pdfjsLib.getDocument('<?= $file_path ?>').promise;
                totalPages = pdf.numPages;
                totalPagesSpan.textContent = totalPages;

                function renderPage(pageNum) {
                    pdf.getPage(pageNum).then(function(page) {
                        const viewport = page.getViewport({scale: 1.5});
                        canvas.height = viewport.height;
                        canvas.width = viewport.width;
                        page.render({canvasContext: ctx, viewport: viewport}).promise.then(function() {
                            currentPageSpan.textContent = pageNum;
                            if (!visitedPages.includes(pageNum)) {
                                visitedPages.push(pageNum);
                                localStorage.setItem(storageKey, JSON.stringify(visitedPages));
                            }
                            updateCompletion();
                        });
                    });
                }

                function updateCompletion() {
                    const allVisited = visitedPages.length === totalPages;
                    if (confirmCheck) {
                        confirmCheck.checked = allVisited;
                        confirmCheck.disabled = !allVisited;
                    }
                    if (markBtn) {
                        markBtn.disabled = !allVisited;
                    }
                    if (pdfHint && allVisited) {
                        pdfHint.innerHTML = '<i class="fas fa-check-circle mr-1 text-green-600"></i> You\'ve viewed all pages! Confirm below to complete.';
                    }
                }

                prevBtn.addEventListener('click', function() {
                    if (currentPageNum > 1) {
                        currentPageNum--;
                        renderPage(currentPageNum);
                    }
                });
                nextBtn.addEventListener('click', function() {
                    if (currentPageNum < totalPages) {
                        currentPageNum++;
                        renderPage(currentPageNum);
                    }
                });

                renderPage(1);
            } catch (err) {
                document.getElementById('pdfViewer').innerHTML = '<p class="text-red-500 p-4">Failed to load PDF.</p>';
                console.error(err);
            }
        })();
    </script>
    <?php else: ?>
    <!-- Scroll-based completion for non-PDF modules -->
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
            confirmCheck.addEventListener('change', function() {
                markBtn.disabled = !(hasScrolledToBottom && this.checked);
            });

            checkBottom();
            setTimeout(checkBottom, 300);
            setTimeout(checkBottom, 800);

            completeForm?.addEventListener('submit', function(e) {
                if (markBtn.disabled) {
                    e.preventDefault();
                    return false;
                }
                markBtn.disabled = true;
                markBtn.innerText = 'Saving…';
            });
        })();
    </script>
    <?php endif; ?>
    <?php else: ?>
    <!-- For empty assessments, button is enabled immediately but requires checkbox -->
    <script>
        (function() {
            const confirmCheck = document.getElementById('confirmRead');
            const markBtn      = document.getElementById('markCompleteBtn');
            if (!confirmCheck || !markBtn) return;

            markBtn.disabled = true;

            confirmCheck.addEventListener('change', function() {
                markBtn.disabled = !this.checked;
            });
        })();
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}
?>

<!-- ========== LISTING MODE (unchanged) ========== -->
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
        html, body { height: 100%; margin: 0; padding: 0; }
        .progress-bar-custom { height: 12px; border-radius: 10px; background-color: #e5e7eb; overflow: hidden; }
        .progress-fill { background-color: #0a6e2d; height: 100%; width: 0%; transition: width 0.3s; }
        .module-card { transition: transform 0.2s, box-shadow 0.2s; }
        .module-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); cursor: pointer; }
    </style>
</head>
<body class="h-full bg-gray-100">
<div class="flex h-full">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-auto">
        <?php include '../includes/header.php'; ?>
        <div class="flex-1 p-6">
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Enrolled Modules</h2>
                        <p class="text-gray-500 text-sm">All modules and assessments from your registered sessions</p>
                    </div>
                    <div class="text-right">
                        <span class="text-3xl font-bold text-green-700"><?= $total_modules ?></span>
                        <span class="text-gray-500"> total items</span>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h3 class="text-lg font-semibold">Your Progress</h3>
                        <p class="text-gray-500">You have completed <?= $completed_modules ?> out of <?= $total_modules ?> modules/assessments</p>
                    </div>
                    <div class="w-full md:w-1/2">
                        <div class="progress-bar-custom">
                            <div class="progress-fill" style="width: <?= $progress_percent ?>%;"></div>
                        </div>
                        <div class="text-right text-sm text-gray-600 mt-1"><?= $progress_percent ?>% complete</div>
                    </div>
                </div>
            </div>
            <h3 class="text-xl font-bold mb-4">All Modules & Assessments</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($all_modules as $mod): ?>
                    <div class="module-card bg-white rounded-lg shadow overflow-hidden border border-gray-200" onclick="window.location.href='?module_id=<?= $mod['module_id'] ?>'">
                        <div class="p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold px-2 py-1 rounded-full <?= $mod['type'] == 'module' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800' ?>">
                                    <?= ucfirst($mod['type']) ?>
                                </span>
                                <?php if ($mod['completed']): ?>
                                    <span class="text-green-600"><i class="fas fa-check-circle"></i> Completed</span>
                                <?php else: ?>
                                    <span class="text-gray-400"><i class="far fa-circle"></i> Pending</span>
                                <?php endif; ?>
                            </div>
                            <h4 class="text-lg font-bold mb-2 line-clamp-2"><?= htmlspecialchars($mod['title']) ?></h4>
                            <p class="text-gray-600 text-sm mb-3 line-clamp-3"><?= nl2br(htmlspecialchars(substr($mod['description'] ?? '', 0, 100))) ?></p>
                            <div class="flex items-center text-gray-500 text-sm">
                                <i class="fas <?= getIconClass($mod['type'], $mod['content']) ?> mr-2"></i>
                                <span><?= getPreview($mod['content'], $mod['type']) ?></span>
                            </div>
                            <?php if ($mod['due_date']): ?>
                                <div class="mt-2 text-xs text-gray-400">
                                    <i class="fas fa-calendar-alt"></i> Due: <?= date('M d, Y', strtotime($mod['due_date'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($all_modules)): ?>
                    <div class="col-span-full text-center py-12 bg-white rounded-lg shadow">
                        <i class="fas fa-book-open text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">You are not enrolled in any modules yet. Register for sessions first.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>