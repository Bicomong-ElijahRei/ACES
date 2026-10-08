<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload.php';
redirectIfNotStaff();

// Block Viewer POST actions
if (isViewer() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: modules.php?error=Access denied');
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

// ---------- BULK ACTIONS ----------
if ($action === 'bulk_archive' && !empty($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE modules SET is_deleted = 1 WHERE module_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: modules.php?msg=archived');
    exit;
} elseif ($action === 'bulk_restore' && !empty($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE modules SET is_deleted = 0 WHERE module_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: modules.php?msg=restored');
    exit;
} elseif ($action === 'bulk_delete' && !empty($_POST['ids'])) {
    $ids = explode(',', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM modules WHERE module_id IN ($placeholders)");
    $stmt->execute($ids);
    header('Location: modules.php?msg=deleted');
    exit;
}

// ---------- SINGLE ACTIONS ----------
if ($action === 'create') {
    $type = $_POST['type'] ?? 'module';
    $subtopic_id = (int)$_POST['subtopic_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $due_date = $_POST['due_date'] ?: null;
    $content = '';

    if ($type == 'module') {
        $content_type = $_POST['content_type'];
        if ($content_type == 'file' && isset($_FILES['module_file']) && $_FILES['module_file']['error'] == UPLOAD_ERR_OK) {
            $upload_result = aces_upload('module_file', 'modules');

            if ($upload_result['ok']) {
                $filename = basename($upload_result['path']);
                $content  = 'file:' . $filename;
            } else {
                $error = 'File upload failed: ' . $upload_result['error'];
            }
        } elseif ($content_type == 'link') {
            $content = 'link:' . trim($_POST['module_link']);
        } elseif ($content_type == 'text') {
            $content = 'text:' . trim($_POST['module_text']);
        }
    } elseif ($type == 'assessment') {
        $assessment_type = $_POST['assessment_type'];
        if ($assessment_type == 'link') {
            $content = 'link:' . trim($_POST['assessment_link']);
        } elseif ($assessment_type == 'quiz') {
            $quiz_json = $_POST['quiz_data'];
            $content = $quiz_json;
        }
    }

    if (!$subtopic_id || !$title) {
        $error = "Subtopic and title are required.";
    } elseif (empty($error)) {
        $stmt = $pdo->prepare("INSERT INTO modules (subtopic_id, title, description, due_date, created_by, type, content) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$subtopic_id, $title, $description, $due_date, $_SESSION['user_id'], $type, $content]);
        $success = "Item created successfully.";
    }

} elseif ($action === 'update') {
    $id = (int)$_POST['id'];
    $type = $_POST['type'] ?? 'module';
    $subtopic_id = (int)$_POST['subtopic_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $due_date = $_POST['due_date'] ?: null;

    if (isset($_POST['quiz_data']) && !empty($_POST['quiz_data'])) {
        $content = $_POST['quiz_data'];
    } else {
        $content = trim($_POST['content'] ?? '');
    }

    if (!$subtopic_id || !$title) {
        $error = "Subtopic and title are required.";
    } else {
        $stmt = $pdo->prepare("UPDATE modules SET subtopic_id = ?, title = ?, description = ?, due_date = ?, type = ?, content = ?, is_deleted = 0 WHERE module_id = ?");
        $stmt->execute([$subtopic_id, $title, $description, $due_date, $type, $content, $id]);
        $success = "Item updated successfully.";
    }
} elseif ($action === 'soft_delete') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE modules SET is_deleted = 1 WHERE module_id = ?");
    $stmt->execute([$id]);
    $success = "Item moved to archives.";

} elseif ($action === 'restore') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE modules SET is_deleted = 0 WHERE module_id = ?");
    $stmt->execute([$id]);
    $success = "Item restored.";

} elseif ($action === 'hard_delete') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("DELETE FROM modules WHERE module_id = ?");
    $stmt->execute([$id]);
    $success = "Item permanently deleted.";
}

// Fetch all active modules/assessments
$stmt = $pdo->prepare("
    SELECT m.*, sub.title as subtopic_title
    FROM modules m
    JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id
    WHERE m.is_deleted = 0
    ORDER BY m.due_date ASC, m.module_id DESC
");
$stmt->execute();
$active_items = $stmt->fetchAll();

// Fetch deleted items
$stmt_deleted = $pdo->prepare("
    SELECT m.*, sub.title as subtopic_title
    FROM modules m
    JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id
    WHERE m.is_deleted = 1
    ORDER BY m.updated_at DESC
");
$stmt_deleted->execute();
$deleted_items = $stmt_deleted->fetchAll();

// Fetch all subtopics for dropdowns
$subtopics = $pdo->query("SELECT subtopic_id, title FROM subtopics ORDER BY title")->fetchAll();

// Content helper — supports both legacy and new upload paths
function renderContent($content) {
    if (empty($content)) return '';
    if (strpos($content, 'file:') === 0) {
        $file = substr($content, 5);
        // Support both legacy flat files (uploads/) and new subfolder (uploads/modules/)
        $url = file_exists(__DIR__ . '/../uploads/' . $file)
            ? '../uploads/' . $file
            : '../uploads/modules/' . $file;
        return "<a href='{$url}' target='_blank' class='text-blue-600 underline'>Download File</a>";
    } elseif (strpos($content, 'link:') === 0) {
        $url = substr($content, 5);
        return "<a href='$url' target='_blank' class='text-blue-600 underline'>Open Link</a>";
    } elseif (strpos($content, 'text:') === 0) {
        return nl2br(htmlspecialchars(substr($content, 5)));
    } elseif (strpos($content, 'form:') === 0) {
        return "<div class='border p-2 bg-gray-100 rounded'>" . nl2br(htmlspecialchars(substr($content, 5))) . "</div>";
    } elseif (strpos($content, 'quiz:') === 0) {
        $json = substr($content, 5);
        $questions = json_decode($json, true);
        $count = is_array($questions) ? count($questions) : 0;
        return "<span class='inline-block bg-blue-100 text-blue-800 text-xs font-semibold px-2 py-0.5 rounded-full'>Quiz</span> {$count} question(s)";
    } else {
        return nl2br(htmlspecialchars($content));
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Modules | ACES Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
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
        .phase-badge { font-size: 0.7rem; padding: 2px 10px; border-radius: 20px; font-weight: 600; text-transform: uppercase; }
        .phase-Module { background: #dbeafe; color: #1e40af; }
        .phase-Assessment { background: #f3e8ff; color: #6b21a8; }
        @keyframes slideInToast {
            from { transform: translateX(120%); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">

        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">
                <i class="fas fa-book-open mr-2"></i>Manage Modules
            </h1>
            <a href="dashboard.php" class="text-[#0a6e2d]">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/></svg>
            </a>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] == 'archived'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">Item(s) archived.</div>
            <?php elseif ($_GET['msg'] == 'restored'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">Item(s) restored.</div>
            <?php elseif ($_GET['msg'] == 'deleted'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">Item(s) permanently deleted.</div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Create Button + Filters -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex flex-wrap items-center gap-2">
                <input type="checkbox" id="selectAllActive" class="w-4 h-4 text-green-600 border-gray-300 rounded">
                <button id="bulkArchiveBtn" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1.5 rounded hidden">
                    <i class="fas fa-box-archive mr-1"></i> Archive Selected
                </button>
                <select id="filterSubtopic" class="bg-white border border-gray-300 rounded px-2 py-1 text-xs">
                    <option value="">All Subtopics</option>
                    <?php foreach ($subtopics as $sub): ?>
                        <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterType" class="bg-white border border-gray-300 rounded px-2 py-1 text-xs">
                    <option value="">All Types</option>
                    <option value="module">Module</option>
                    <option value="assessment">Assessment</option>
                </select>
                <select id="sortBy" class="bg-white border border-gray-300 rounded px-2 py-1 text-xs">
                    <option value="due-asc">Sort: Due date (earliest)</option>
                    <option value="due-desc">Sort: Due date (latest)</option>
                    <option value="title-asc">Sort: Title (A → Z)</option>
                    <option value="title-desc">Sort: Title (Z → A)</option>
                    <option value="type-module">Sort: Modules first</option>
                    <option value="type-assessment">Sort: Assessments first</option>
                    <option value="subtopic">Sort: Subtopic</option>
                    <option value="created-desc">Sort: Recently created</option>
                    <option value="created-asc">Sort: Oldest first</option>
                </select>
                <div class="relative">
                    <input type="text" id="searchActiveInput" placeholder="Search..." class="border border-gray-300 rounded-full px-3 py-1 text-xs w-36">
                    <i class="fas fa-search absolute right-3 top-1.5 text-gray-400 text-[10px]"></i>
                </div>
            </div>
            <?php if (!isViewer()): ?>
            <button class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="fas fa-plus mr-2"></i> Create
            </button>
            <?php endif; ?>
        </div>

        <!-- Active Items -->
        <?php if (count($active_items) > 0): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 mb-8" id="activeItemsContainer">
                <?php foreach ($active_items as $item): ?>
                    <div class="session-card active-item-row"
                         data-id="<?= $item['module_id'] ?>"
                         data-subtopic="<?= $item['subtopic_id'] ?>"
                         data-type="<?= $item['type'] ?>"
                         data-title="<?= htmlspecialchars($item['title']) ?>"
                         data-due="<?= htmlspecialchars($item['due_date'] ?? '') ?>"
                         data-created="<?= htmlspecialchars($item['created_at'] ?? '') ?>"
                         data-subtopic-title="<?= htmlspecialchars($item['subtopic_title']) ?>">
                        <div class="card-header">
                            <div class="title-block">
                                <h3><?= htmlspecialchars($item['title']) ?></h3>
                                <div class="phase mt-1">
                                    <span class="phase-badge phase-<?= ucfirst($item['type']) ?>"><?= ucfirst($item['type']) ?></span>
                                    <span class="text-xs ml-2 opacity-75"><?= htmlspecialchars($item['subtopic_title']) ?></span>
                                </div>
                            </div>
                            <div class="action-btns">
                                <?php if ($item['type'] == 'module'): ?>
                                    <button class="view-module-status" data-module-id="<?= $item['module_id'] ?>" data-module-title="<?= htmlspecialchars($item['title']) ?>" title="View Status"><i class="fas fa-eye"></i></button>
                                <?php else: ?>
                                    <button class="view-results" data-module-id="<?= $item['module_id'] ?>" data-module-title="<?= htmlspecialchars($item['title']) ?>" title="View Results"><i class="fas fa-chart-bar"></i></button>
                                <?php endif; ?>
                                <?php if (!isViewer()): ?>
                                    <button class="edit-item" data-id="<?= $item['module_id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="soft_delete">
                                        <input type="hidden" name="id" value="<?= $item['module_id'] ?>">
                                        <button type="submit" onclick="return confirm('Archive this item?')" title="Archive"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body text-sm">
                            <?php if ($item['description']): ?>
                                <p class="text-gray-500 text-xs mb-3"><?= nl2br(htmlspecialchars($item['description'])) ?></p>
                            <?php endif; ?>
                            <?php if ($item['due_date']): ?>
                                <div class="text-xs text-gray-600 mb-2"><i class="far fa-calendar-alt mr-1"></i> Due: <?= date('M d, Y', strtotime($item['due_date'])) ?></div>
                            <?php endif; ?>
                            <?php if ($item['content']): ?>
                                <div class="text-xs text-gray-700"><?= renderContent($item['content']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bg-white rounded shadow-xl p-6 text-center text-gray-500 mb-8">No active modules or assessments.</div>
        <?php endif; ?>

        <!-- Archives Section -->
        <div class="mt-8">
            <div id="archiveHeader" class="flex justify-between items-center cursor-pointer bg-white rounded-t-lg px-6 py-3 shadow-md border border-gray-200">
                <div class="font-bold text-[#0a6e2d]"><i class="fas fa-archive mr-2"></i> Archives <span class="bg-gray-500 text-white rounded-full px-2 py-0.5 text-xs ml-2"><?= count($deleted_items) ?></span></div>
                <i class="fas fa-chevron-down transition-transform duration-200 text-gray-500"></i>
            </div>
            <div id="archiveContent" class="hidden mt-1">
                <div class="bg-white rounded-b-lg shadow-md overflow-hidden border border-t-0 border-gray-200">
                    <div class="bg-gray-50 p-3 border-b flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="selectAllArchive" class="w-4 h-4 text-green-600 border-gray-300 rounded">
                            <button id="bulkRestoreBtn" class="bg-green-500 hover:bg-green-600 text-white text-sm px-3 py-1 rounded hidden">
                                <i class="fas fa-undo-alt mr-1"></i> Restore Selected
                            </button>
                            <button id="bulkDeleteBtn" class="bg-red-500 hover:bg-red-600 text-white text-sm px-3 py-1 rounded hidden">
                                <i class="fas fa-trash-alt mr-1"></i> Delete Selected
                            </button>
                        </div>
                        <div class="flex gap-2">
                            <select id="filterArchSubtopic" class="border border-gray-300 rounded px-2 py-1 text-xs">
                                <option value="">All Subtopics</option>
                                <?php foreach ($subtopics as $sub): ?>
                                    <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="filterArchType" class="border border-gray-300 rounded px-2 py-1 text-xs">
                                <option value="">All Types</option>
                                <option value="module">Module</option>
                                <option value="assessment">Assessment</option>
                            </select>
                            <div class="relative">
                                <input type="text" id="searchArchInput" placeholder="Search..." class="border border-gray-300 rounded-full px-3 py-1 text-xs w-36">
                                <i class="fas fa-search absolute right-3 top-1.5 text-gray-400 text-[10px]"></i>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 space-y-4" id="archiveItemsContainer">
                        <?php if (count($deleted_items) > 0): ?>
                            <?php foreach ($deleted_items as $item): ?>
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white rounded-lg shadow-sm p-4 border border-gray-100 archive-item-row"
                                     data-id="<?= $item['module_id'] ?>"
                                     data-subtopic="<?= $item['subtopic_id'] ?>"
                                     data-type="<?= $item['type'] ?>"
                                     data-title="<?= htmlspecialchars($item['title']) ?>">
                                    <div class="flex items-start gap-3 flex-1">
                                        <input type="checkbox" class="row-checkbox-archive w-4 h-4 text-green-600 border-gray-300 rounded mt-1" value="<?= $item['module_id'] ?>">
                                        <div>
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= $item['type'] == 'module' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800' ?>"><?= ucfirst($item['type']) ?></span>
                                                <strong class="text-gray-800"><?= htmlspecialchars($item['title']) ?></strong>
                                            </div>
                                            <div class="text-sm text-gray-500"><i class="fas fa-folder-open mr-1"></i> <?= htmlspecialchars($item['subtopic_title']) ?></div>
                                            <?php if ($item['due_date']): ?>
                                                <div class="text-xs text-gray-400 mt-1">Due: <?= date('M d, Y', strtotime($item['due_date'])) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!isViewer()): ?>
                                    <div class="flex items-center gap-2 ml-10">
                                        <form method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="id" value="<?= $item['module_id'] ?>">
                                            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-1.5 rounded">Restore</button>
                                        </form>
                                        <form method="POST" class="inline" onsubmit="return confirm('Permanently delete? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="hard_delete">
                                            <input type="hidden" name="id" value="<?= $item['module_id'] ?>">
                                            <button type="submit" class="bg-red-500 hover:bg-red-600 text-white text-xs px-3 py-1.5 rounded">Delete</button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-gray-500 text-sm">No archived items.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- Results Modal -->
<div class="modal fade" id="resultsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-green-700 text-white">
                <h5 class="modal-title">Status / Results</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="resultsModalBody">Loading...</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-green-700 text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle mr-2"></i>Create</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="createTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="module-tab" data-bs-toggle="tab" data-bs-target="#module" type="button" role="tab">Module</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="assessment-tab" data-bs-toggle="tab" data-bs-target="#assessment" type="button" role="tab">Assessment</button>
                    </li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- Module Tab -->
                    <div class="tab-pane fade show active" id="module" role="tabpanel">
                        <form method="POST" id="createModuleForm" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="type" value="module">
                            <div class="mb-3">
                                <label class="form-label">Subtopic *</label>
                                <select name="subtopic_id" class="form-select" required>
                                    <option value="">Select Subtopic</option>
                                    <?php foreach ($subtopics as $sub): ?>
                                        <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Title *</label>
                                <input type="text" name="title" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" rows="2" class="form-control"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Content Type</label><br>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="content_type" value="file" id="module_file_radio" checked>
                                    <label class="form-check-label" for="module_file_radio">Upload File</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="content_type" value="link" id="module_link_radio">
                                    <label class="form-check-label" for="module_link_radio">Provide Link</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="content_type" value="text" id="module_text_radio">
                                    <label class="form-check-label" for="module_text_radio">Write Content</label>
                                </div>
                            </div>
                            <div id="module_file_div">
                                <div class="mb-3">
                                    <label class="form-label">File (PDF, DOC, etc. — max 10 MB)</label>
                                    <input type="file" name="module_file" class="form-control">
                                    <small class="text-gray-500 text-xs">Allowed: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT, JPG, PNG, GIF, MP4, ZIP</small>
                                </div>
                            </div>
                            <div id="module_link_div" style="display:none;">
                                <div class="mb-3"><label class="form-label">URL</label><input type="text" name="module_link" class="form-control" placeholder="https://..."></div>
                            </div>
                            <div id="module_text_div" style="display:none;">
                                <div class="mb-3"><label class="form-label">Write Module Content</label><textarea name="module_text" rows="6" class="form-control" placeholder="Write your module content here..."></textarea></div>
                            </div>
                            <button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded">Create Module</button>
                        </form>
                    </div>
                    <!-- Assessment Tab -->
                    <div class="tab-pane fade" id="assessment" role="tabpanel">
                        <form method="POST" id="createAssessmentForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="type" value="assessment">
                            <div class="mb-3">
                                <label class="form-label">Subtopic *</label>
                                <select name="subtopic_id" class="form-select" required>
                                    <option value="">Select Subtopic</option>
                                    <?php foreach ($subtopics as $sub): ?>
                                        <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Title *</label>
                                <input type="text" name="title" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" rows="2" class="form-control"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Assessment Type</label><br>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assessment_type" value="link" id="assess_link_radio" checked>
                                    <label class="form-check-label" for="assess_link_radio">Provide Link (e.g., Google Form)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="assessment_type" value="quiz" id="assess_quiz_radio">
                                    <label class="form-check-label" for="assess_quiz_radio">Create Quiz / Assignment</label>
                                </div>
                            </div>
                            <div id="assessment_link_div">
                                <div class="mb-3"><label class="form-label">Link</label><input type="text" name="assessment_link" class="form-control" placeholder="https://forms.google.com/..."></div>
                            </div>
                            <div id="assessment_quiz_div" style="display:none;">
                                <div class="mb-3">
                                    <label class="form-label">Questions</label>
                                    <div id="questions-container">
                                        <div class="question-template" style="display:none;">
                                            <div class="card mb-3">
                                                <div class="card-body">
                                                    <div class="mb-2"><label class="form-label">Question Text</label><input type="text" class="form-control question-text"></div>
                                                    <div class="mb-2"><label class="form-label">Type</label>
                                                        <select class="form-select question-type">
                                                            <option value="multiple_choice">Multiple Choice</option>
                                                            <option value="true_false">True/False</option>
                                                            <option value="short_answer">Short Answer</option>
                                                        </select>
                                                    </div>
                                                    <div class="question-options" style="display:none;"><label class="form-label">Options (comma separated)</label><input type="text" class="form-control question-options-list"></div>
                                                    <div class="mb-2"><label class="form-label">Correct Answer</label><input type="text" class="form-control question-correct-answer"></div>
                                                    <div class="mb-2"><label class="form-label">Points</label><input type="number" class="form-control question-points" value="1" step="0.5"></div>
                                                    <button type="button" class="bg-red-500 hover:bg-red-600 text-white text-xs px-2 py-1 rounded remove-question">Remove</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="bg-gray-500 hover:bg-gray-600 text-white text-sm px-3 py-1.5 rounded" id="add-question">+ Add Question</button>
                                </div>
                                <input type="hidden" name="quiz_data" id="quiz_data">
                            </div>
                            <button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded mt-3">Create Assessment</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-green-700 text-white">
                <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Edit Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editModalBody"><!-- AJAX populated --></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// CSRF token for JS-generated forms
const CSRF_TOKEN = '<?= csrf_token() ?>';
// ============================================================
// ERROR / SUCCESS HELPERS
// ============================================================
function extractError(html) {
    // Look for the red error banner in the response HTML
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const banner = doc.querySelector('.bg-red-100');
    if (banner) {
        // Strip the trailing × close button character
        return banner.textContent.replace(/×\s*$/, '').trim();
    }
    return null;
}

function extractSuccess(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const banner = doc.querySelector('.bg-green-100');
    if (banner) {
        return banner.textContent.replace(/×\s*$/, '').trim();
    }
    return null;
}

function showToast(message, type = 'info') {
    // Remove any existing toasts
    document.querySelectorAll('.aces-toast').forEach(t => t.remove());

    const colors = {
        error:   { bg: '#fee2e2', border: '#dc2626', text: '#991b1b', icon: 'fa-exclamation-circle' },
        success: { bg: '#dcfce7', border: '#16a34a', text: '#166534', icon: 'fa-check-circle' },
        info:    { bg: '#dbeafe', border: '#2563eb', text: '#1e40af', icon: 'fa-info-circle' },
    };
    const c = colors[type] || colors.info;

    const toast = document.createElement('div');
    toast.className = 'aces-toast';
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 99999;
        background: ${c.bg};
        color: ${c.text};
        border-left: 4px solid ${c.border};
        padding: 14px 20px;
        border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        max-width: 420px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        font-size: 14px;
        font-weight: 500;
        animation: slideInToast 0.3s ease;
    `;
    toast.innerHTML = `
        <i class="fas ${c.icon}" style="margin-top:2px; font-size:16px;"></i>
        <div style="flex:1;">${message}</div>
        <button onclick="this.parentElement.remove()" style="background:none; border:none; font-size:18px; cursor:pointer; color:${c.text}; opacity:0.6; padding:0; line-height:1;">&times;</button>
    `;
    document.body.appendChild(toast);

    // Auto-remove after 6 seconds (errors stay longer)
    setTimeout(() => toast.remove(), type === 'error' ? 8000 : 4000);
}
// ========= FIX: CLEAN UP ALL BACKDROPS AFTER ANY MODAL CLOSE =========
document.addEventListener('hidden.bs.modal', function () {
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
});

// ---------- Create Form Toggles ----------
document.querySelectorAll('input[name="content_type"]').forEach(r =>
    r.addEventListener('change', function() {
        document.getElementById('module_file_div').style.display = this.value === 'file' ? 'block' : 'none';
        document.getElementById('module_link_div').style.display = this.value === 'link' ? 'block' : 'none';
        document.getElementById('module_text_div').style.display = this.value === 'text' ? 'block' : 'none';
    })
);
document.getElementById('assess_link_radio').addEventListener('change', function() {
    if (this.checked) { document.getElementById('assessment_link_div').style.display = 'block'; document.getElementById('assessment_quiz_div').style.display = 'none'; }
});
document.getElementById('assess_quiz_radio').addEventListener('change', function() {
    if (this.checked) { document.getElementById('assessment_link_div').style.display = 'none'; document.getElementById('assessment_quiz_div').style.display = 'block'; }
});

// ---------- Quiz Builder ----------
document.getElementById('add-question').addEventListener('click', function() {
    const t = document.querySelector('.question-template').cloneNode(true);
    t.style.display = 'block'; t.classList.remove('question-template');
    t.querySelectorAll('input, select, textarea').forEach(el => { el.value = ''; el.removeAttribute('name'); el.removeAttribute('required'); });
    t.querySelector('.remove-question').addEventListener('click', () => t.remove());
    const sel = t.querySelector('.question-type');
    const opts = t.querySelector('.question-options');
    sel.addEventListener('change', () => opts.style.display = sel.value === 'multiple_choice' ? 'block' : 'none');
    document.getElementById('questions-container').appendChild(t);
});

// ---------- Create Submissions ----------
let isCreating = false;
document.getElementById('createModuleForm')?.addEventListener('submit', function(e) {
    e.preventDefault(); if (isCreating) return; isCreating = true;
    const btn = this.querySelector('button[type="submit"]'); btn.disabled = true;

    fetch('modules.php', { method: 'POST', body: new FormData(this) })
            .then(async (res) => {
                const html = await res.text();
                const err = extractError(html);
                if (err) {
                    showToast(err, 'error');
                    btn.disabled = false;
                    isCreating = false;
                    return;
                }
                const ok = extractSuccess(html);
                if (ok) showToast(ok, 'success');
                setTimeout(() => location.reload(), ok ? 1800 : 0);
            })
            .catch(err => {
                showToast('Network error: ' + err, 'error');
                btn.disabled = false;
                isCreating = false;
            });
    });
document.getElementById('createAssessmentForm')?.addEventListener('submit', function(e) {
    e.preventDefault(); if (isCreating) return; isCreating = true;
    const btn = this.querySelector('button[type="submit"]'); btn.disabled = true;

    // Build quiz_data if needed (existing logic preserved)
    if (document.querySelector('input[name="assessment_type"]:checked').value === 'quiz') {
        const qs = [];
        document.querySelectorAll('#questions-container .card:not(.question-template)').forEach(div => {
            qs.push({
                text: div.querySelector('.question-text').value,
                type: div.querySelector('.question-type').value,
                correct: div.querySelector('.question-correct-answer').value,
                points: div.querySelector('.question-points').value,
                options: div.querySelector('.question-type').value === 'multiple_choice'
                    ? div.querySelector('.question-options-list').value.split(',').map(o => o.trim())
                    : null
            });
        });
        if (!qs.length) { alert('Add at least one question.'); btn.disabled = false; isCreating = false; return; }
        document.getElementById('quiz_data').value = 'quiz:' + JSON.stringify(qs);
    }

    fetch('modules.php', { method: 'POST', body: new FormData(this) })
        .then(async (res) => {
            const html = await res.text();
            const err = extractError(html);
            if (err) { showToast(err, 'error'); btn.disabled = false; isCreating = false; return; }
            const ok = extractSuccess(html);
            if (ok) showToast(ok, 'success');
            setTimeout(() => location.reload(), ok ? 1800 : 0);
        })
        .catch(err => {
            showToast('Network error: ' + err, 'error');
            btn.disabled = false;
            isCreating = false;
        });
});
document.getElementById('createModal').addEventListener('show.bs.modal', () => {
    const c = document.getElementById('questions-container');
    if (c) {
        // Only remove cards that are NOT inside the hidden .question-template
        c.querySelectorAll('.card').forEach(card => {
            if (!card.closest('.question-template')) card.remove();
        });
    }
});

// ---------- Edit Item ----------
let isLoading = false;
document.querySelectorAll('.edit-item').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (isLoading) return; isLoading = true;
        const id = this.dataset.id;
        try {
            const r = await fetch(`get_module.php?id=${id}`); const data = await r.json();
            document.getElementById('editModalBody').innerHTML = buildEditModalHtml(data);
            const editForm = document.getElementById('editForm');
            if (!editForm) return;
            if (data.type === 'assessment' && data.content?.startsWith('quiz:')) setupQuizBuilder(editForm);
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (data.type === 'assessment' && data.content?.startsWith('quiz:')) {
                    const qs = [];
                    document.querySelectorAll('#edit_questions_container .card').forEach(div => {
                        qs.push({
                            text: div.querySelector('.question-text').value,
                            type: div.querySelector('.question-type').value,
                            correct: div.querySelector('.question-correct-answer').value,
                            points: div.querySelector('.question-points').value,
                            options: div.querySelector('.question-type').value === 'multiple_choice' ? div.querySelector('.question-options-list').value.split(',').map(o => o.trim()) : null
                        });
                    });
                    if (!qs.length) { alert('Add at least one question.'); return; }
                    document.getElementById('edit_quiz_data').value = 'quiz:' + JSON.stringify(qs);
                }
                fetch('modules.php', { method: 'POST', body: new FormData(this) })
                    .then(() => { bootstrap.Modal.getInstance(document.getElementById('editModal')).hide(); location.reload(); })
                    .catch(err => alert(err));
            });
            new bootstrap.Modal(document.getElementById('editModal')).show();
        } catch(err) { console.error(err); alert('Error loading item.'); } finally { isLoading = false; }
    });
});

function buildEditModalHtml(data) {
    let h = `<form method="POST" id="editForm">
        <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="${data.module_id}">
        <div class="mb-3"><label class="form-label">Subtopic *</label><select name="subtopic_id" class="form-select" required>`;
    <?php foreach ($subtopics as $sub): ?>
        h += `<option value="<?= $sub['subtopic_id'] ?>" ${data.subtopic_id == <?= $sub['subtopic_id'] ?> ? 'selected' : ''}><?= addslashes($sub['title']) ?></option>`;
    <?php endforeach; ?>
    h += `</select></div>
        <div class="mb-3"><label class="form-label">Type</label><select name="type" class="form-select"><option value="module" ${data.type=='module'?'selected':''}>Module</option><option value="assessment" ${data.type=='assessment'?'selected':''}>Assessment</option></select></div>
        <div class="mb-3"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" value="${escapeHtml(data.title)}" required></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea name="description" rows="2" class="form-control">${escapeHtml(data.description)}</textarea></div>
        <div class="mb-3"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="${data.due_date||''}"></div>`;
    if (data.type === 'assessment' && data.content?.startsWith('quiz:')) {
        const qs = JSON.parse(data.content.substring(5));
        h += `<div class="mb-3"><label class="form-label">Assessment Type</label><br>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="assessment_type" value="link" id="edit_assess_link_radio"><label for="edit_assess_link_radio">Provide Link</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="assessment_type" value="quiz" id="edit_assess_quiz_radio" checked><label for="edit_assess_quiz_radio">Quiz / Assignment</label></div></div>
            <div id="edit_quiz_div"><div class="mb-3"><label class="form-label">Questions</label><div id="edit_questions_container">`;
        qs.forEach(q => {
            h += `<div class="card mb-3"><div class="card-body">
            <div class="mb-2"><label class="form-label">Question Text</label><input type="text" class="form-control question-text" value="${escapeHtml(q.text)}"></div>
            <div class="mb-2"><label class="form-label">Type</label><select class="form-select question-type"><option value="multiple_choice" ${q.type=='multiple_choice'?'selected':''}>Multiple Choice</option><option value="true_false" ${q.type=='true_false'?'selected':''}>True/False</option><option value="short_answer" ${q.type=='short_answer'?'selected':''}>Short Answer</option></select></div>
            <div class="question-options" style="display:${q.type=='multiple_choice'?'block':'none'};"><label class="form-label">Options (comma separated)</label><input type="text" class="form-control question-options-list" value="${escapeHtml(q.options?.join(', ')||'')}"></div>
            <div class="mb-2"><label class="form-label">Correct Answer</label><input type="text" class="form-control question-correct-answer" value="${escapeHtml(q.correct)}"></div>
            <div class="mb-2"><label class="form-label">Points</label><input type="number" class="form-control question-points" value="${q.points}" step="0.5"></div>
            <button type="button" class="bg-red-500 hover:bg-red-600 text-white text-xs px-2 py-1 rounded remove-question">Remove</button></div></div>`;
        });
        h += `</div><button type="button" class="bg-gray-500 hover:bg-gray-600 text-white text-sm px-3 py-1.5 rounded" id="edit_add_question">+ Add Question</button></div><input type="hidden" name="quiz_data" id="edit_quiz_data"></div>`;
        h += `<div id="edit_link_div" style="display:none;"><div class="mb-3"><label class="form-label">Link</label><input type="text" name="assessment_link" class="form-control"></div></div>`;
    } else {
        h += `<div class="mb-3"><label class="form-label">Content</label><textarea name="content" rows="3" class="form-control">${escapeHtml(data.content)}</textarea></div>`;
    }
    h += `<button type="submit" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded">Update</button></form>`;
    return h;
}
function escapeHtml(s) { return s ? s.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]) : ''; }

function setupQuizBuilder(form) {
    const container = document.getElementById('edit_questions_container');
    const addBtn = document.getElementById('edit_add_question');
    const t = document.querySelector('.question-template');
    function add() {
        const n = t.cloneNode(true); n.style.display = 'block'; n.classList.remove('question-template');
        n.querySelectorAll('input,select,textarea').forEach(el => { el.value = ''; el.removeAttribute('name'); el.removeAttribute('required'); });
        n.querySelector('.remove-question').addEventListener('click', () => n.remove());
        const s = n.querySelector('.question-type'); const o = n.querySelector('.question-options');
        s.addEventListener('change', () => o.style.display = s.value === 'multiple_choice' ? 'block' : 'none');
        container.appendChild(n);
    }
    if (addBtn) addBtn.addEventListener('click', add);
    document.querySelectorAll('#edit_questions_container .remove-question').forEach(btn => btn.addEventListener('click', function() { this.closest('.card').remove(); }));
    document.getElementById('edit_assess_link_radio')?.addEventListener('change', function() {
        if (this.checked) { document.getElementById('edit_link_div').style.display = 'block'; document.getElementById('edit_quiz_div').style.display = 'none'; }
    });
    document.getElementById('edit_assess_quiz_radio')?.addEventListener('change', function() {
        if (this.checked) { document.getElementById('edit_link_div').style.display = 'none'; document.getElementById('edit_quiz_div').style.display = 'block'; }
    });
}

// ---------- View Status / Results ----------
document.querySelectorAll('.view-module-status').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        document.querySelector('#resultsModal .modal-title').innerText = `Module Status: ${this.dataset.moduleTitle}`;
        const body = document.getElementById('resultsModalBody'); body.innerHTML = 'Loading...';
        new bootstrap.Modal(document.getElementById('resultsModal')).show();
        fetch(`get_module_status.php?module_id=${this.dataset.moduleId}`).then(r => r.json()).then(d => {
            if (d.success && d.results.length) {
                let h = '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Name</th><th>Course</th><th>Section</th><th>Status</th><th>Date</th></tr></thead><tbody>';
                d.results.forEach(r => h += `<tr><td>${escapeHtml(r.full_name)}</td><td>${escapeHtml(r.program)}</td><td>${escapeHtml(r.section)}</td><td>${r.completed ? '✅' : '❌'}</td><td>${r.completion_date || '-'}</td></tr>`);
                h += '</tbody></table></div>'; body.innerHTML = h;
            } else body.innerHTML = '<div class="alert alert-info">No students registered.</div>';
        }).catch(() => body.innerHTML = '<div class="alert alert-danger">Error.</div>');
    });
});
document.querySelectorAll('.view-results').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        document.querySelector('#resultsModal .modal-title').innerText = `Assessment Results: ${this.dataset.moduleTitle}`;
        const body = document.getElementById('resultsModalBody'); body.innerHTML = 'Loading...';
        new bootstrap.Modal(document.getElementById('resultsModal')).show();
        fetch(`get_assessment_results.php?module_id=${this.dataset.moduleId}`).then(r => r.json()).then(d => {
            if (d.success && d.results.length) {
                let h = '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Name</th><th>Course</th><th>Section</th><th>Status</th><th>Score</th><th>Date</th></tr></thead><tbody>';
                d.results.forEach(r => h += `<tr><td>${escapeHtml(r.full_name)}</td><td>${escapeHtml(r.program)}</td><td>${escapeHtml(r.section)}</td><td>${r.completed ? '✅' : '❌'}</td><td>${r.score !== null ? r.score+'%' : 'N/A'}</td><td>${r.completion_date || '-'}</td></tr>`);
                h += '</tbody></table></div>'; body.innerHTML = h;
            } else body.innerHTML = '<div class="alert alert-info">No students registered.</div>';
        }).catch(() => body.innerHTML = '<div class="alert alert-danger">Error.</div>');
    });
});

// ============================================================
// SORTING
// ============================================================
function sortItems() {
    const sortBy = document.getElementById('sortBy')?.value || 'due-asc';
    const container = document.getElementById('activeItemsContainer');
    if (!container) return;

    const cards = Array.from(container.querySelectorAll('.active-item-row'));

    cards.sort((a, b) => {
        const getA = (k) => (a.dataset[k] || '').toString();
        const getB = (k) => (b.dataset[k] || '').toString();

        switch (sortBy) {
            case 'due-asc': {
                const da = getA('due') || '9999-12-31';
                const db = getB('due') || '9999-12-31';
                return da.localeCompare(db);
            }
            case 'due-desc': {
                const da = getA('due') || '0000-01-01';
                const db = getB('due') || '0000-01-01';
                return db.localeCompare(da);
            }
            case 'title-asc':
                return getA('title').toLowerCase().localeCompare(getB('title').toLowerCase());
            case 'title-desc':
                return getB('title').toLowerCase().localeCompare(getA('title').toLowerCase());
            case 'type-module':
                return getA('type') === 'module' ? -1 : (getB('type') === 'module' ? 1 : getA('title').localeCompare(getB('title')));
            case 'type-assessment':
                return getA('type') === 'assessment' ? -1 : (getB('type') === 'assessment' ? 1 : getA('title').localeCompare(getB('title')));
            case 'subtopic':
                return getA('subtopic-title').toLowerCase().localeCompare(getB('subtopic-title').toLowerCase());
            case 'created-desc':
                return (getB('created') || '').localeCompare(getA('created') || '');
            case 'created-asc':
                return (getA('created') || '').localeCompare(getB('created') || '');
            default:
                return 0;
        }
    });

    cards.forEach(card => container.appendChild(card));
}

document.getElementById('sortBy')?.addEventListener('change', sortItems);

// ---------- Filters & Bulk Actions ----------
function filterContainer(containerId, subtopicId, typeId, searchId) {
    const rows = document.getElementById(containerId)?.querySelectorAll('.active-item-row, .archive-item-row') || [];
    const subtopic = document.getElementById(subtopicId);
    const type = document.getElementById(typeId);
    const search = document.getElementById(searchId);
    function apply() {
        const s = subtopic?.value || '', t = type?.value || '', q = (search?.value || '').toLowerCase();
        rows.forEach(r => {
            r.style.display = (!s || r.dataset.subtopic === s) && (!t || r.dataset.type === t) && (!q || (r.dataset.title || '').toLowerCase().includes(q)) ? '' : 'none';
        });
    }
    subtopic?.addEventListener('change', apply); type?.addEventListener('change', apply); search?.addEventListener('keyup', apply);
}
filterContainer('activeItemsContainer', 'filterSubtopic', 'filterType', 'searchActiveInput');
filterContainer('archiveItemsContainer', 'filterArchSubtopic', 'filterArchType', 'searchArchInput');

function setupBulk(masterId, checkboxClass, ...btnIds) {
    const master = document.getElementById(masterId);
    const btns = btnIds.map(id => document.getElementById(id)).filter(Boolean);
    function update() {
        const any = document.querySelectorAll(`.${checkboxClass}:checked`).length > 0;
        btns.forEach(b => b.classList.toggle('hidden', !any));
    }
    master?.addEventListener('change', function() {
        document.querySelectorAll(`.${checkboxClass}`).forEach(cb => cb.checked = this.checked);
        update();
    });
    document.querySelectorAll(`.${checkboxClass}`).forEach(cb => cb.addEventListener('change', update));
    update();
}
setupBulk('selectAllActive', 'row-checkbox-active', 'bulkArchiveBtn');
setupBulk('selectAllArchive', 'row-checkbox-archive', 'bulkRestoreBtn', 'bulkDeleteBtn');

function bulkAction(action, ids) {
    const f = document.createElement('form'); f.method = 'POST';
    f.innerHTML = `<input type="hidden" name="csrf_token" value="${CSRF_TOKEN}"><input type="hidden" name="action" value="${action}"><input type="hidden" name="ids" value="${ids}">`;
    document.body.appendChild(f); f.submit();
}
document.getElementById('bulkArchiveBtn')?.addEventListener('click', () => {
    const ids = Array.from(document.querySelectorAll('.row-checkbox-active:checked')).map(c => c.value);
    if (!ids.length) return alert('Select at least one.');
    if (confirm(`Archive ${ids.length} item(s)?`)) bulkAction('bulk_archive', ids.join(','));
});
document.getElementById('bulkRestoreBtn')?.addEventListener('click', () => {
    const ids = Array.from(document.querySelectorAll('.row-checkbox-archive:checked')).map(c => c.value);
    if (!ids.length) return alert('Select at least one.');
    if (confirm(`Restore ${ids.length} item(s)?`)) bulkAction('bulk_restore', ids.join(','));
});
document.getElementById('bulkDeleteBtn')?.addEventListener('click', () => {
    const ids = Array.from(document.querySelectorAll('.row-checkbox-archive:checked')).map(c => c.value);
    if (!ids.length) return alert('Select at least one.');
    if (confirm(`PERMANENTLY DELETE ${ids.length} item(s)?`)) bulkAction('bulk_delete', ids.join(','));
});

// ---------- Archive toggle ----------
document.getElementById('archiveHeader')?.addEventListener('click', function() {
    document.getElementById('archiveContent').classList.toggle('hidden');
    this.querySelector('.fa-chevron-down').classList.toggle('rotate-180');
});

// ============================================================
// INIT — Restore sort from URL + apply on load
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    // Restore sort choice from URL
    const urlSort = new URL(window.location).searchParams.get('sort');
    const sortSelect = document.getElementById('sortBy');
    if (urlSort && sortSelect) sortSelect.value = urlSort;

    // Apply sort
    sortItems();

    // Persist sort in URL when changed
    sortSelect?.addEventListener('change', () => {
        const url = new URL(window.location);
        url.searchParams.set('sort', sortSelect.value);
        history.replaceState({}, '', url);
    });
});
</script>
</body>
</html>