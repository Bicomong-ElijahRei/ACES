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

// Content helper
function renderContent($content) {
    if (empty($content)) return '';
    if (strpos($content, 'file:') === 0) {
        $file = substr($content, 5);
        $url = file_exists(__DIR__ . '/../uploads/' . $file)
            ? '../uploads/' . $file
            : '../uploads/modules/' . $file;
        return "<a href='{$url}' target='_blank' class='text-blue-600 hover:underline inline-flex items-center gap-1'><i class='fas fa-download'></i> Download File</a>";
    } elseif (strpos($content, 'link:') === 0) {
        $url = substr($content, 5);
        return "<a href='$url' target='_blank' class='text-blue-600 hover:underline inline-flex items-center gap-1'><i class='fas fa-external-link-alt'></i> Open Link</a>";
    } elseif (strpos($content, 'text:') === 0) {
        return nl2br(htmlspecialchars(substr($content, 5)));
    } elseif (strpos($content, 'form:') === 0) {
        return "<div class='border p-2 bg-gray-100 rounded'>" . nl2br(htmlspecialchars(substr($content, 5))) . "</div>";
    } elseif (strpos($content, 'quiz:') === 0) {
        $json = substr($content, 5);
        $questions = json_decode($json, true);
        $count = is_array($questions) ? count($questions) : 0;
        return "<span class='inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800'><i class='fas fa-question-circle mr-1'></i> Quiz ({$count} questions)</span>";
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
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Modal Fixes */
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
        
        /* Toast Animation */
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
    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-[#0a6e2d]">
                    <i class="fas fa-book-open mr-2"></i>Manage Modules
                </h1>
                <p class="text-sm text-gray-600 mt-1">Create, organize, and track learning materials and assessments.</p>
            </div>
            <div class="flex items-center gap-3">
                <?php if (!isViewer()): ?>
                <button class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium py-2 px-4 rounded-lg inline-flex items-center shadow-sm transition-colors" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="fas fa-plus mr-2"></i> Create New
                </button>
                <?php endif; ?>
                <a href="dashboard.php" class="text-[#0a6e2d] bg-white p-2 rounded-full shadow-sm hover:shadow-md transition-shadow">
                    <i class="fa-solid fa-house text-xl"></i>
                </a>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_GET['msg'])): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> 
                <?php 
                    if ($_GET['msg'] == 'archived') echo 'Item(s) archived.';
                    elseif ($_GET['msg'] == 'restored') echo 'Item(s) restored.';
                    elseif ($_GET['msg'] == 'deleted') echo 'Item(s) permanently deleted.';
                ?>
                <button type="button" class="ml-auto text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
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

        <!-- Toolbar -->
        <div class="bg-white p-4 rounded-t-xl border-b border-gray-200 flex flex-col md:flex-row md:items-center justify-between gap-4 shadow-sm mb-6">
            <div class="flex flex-wrap items-center gap-2">
                <input type="checkbox" id="selectAllActive" class="w-4 h-4 text-[#0a6e2d] border-gray-300 rounded focus:ring-[#0a6e2d]">
                <button id="bulkArchiveBtn" class="bg-red-50 text-red-600 hover:bg-red-100 border border-red-200 text-sm font-medium px-3 py-2 rounded-lg transition-colors shadow-sm hidden items-center gap-2">
                    <i class="fas fa-box-archive"></i> Archive Selected
                </button>
                <div class="h-6 w-px bg-gray-300 mx-1 hidden sm:block"></div>
                <select id="filterSubtopic" class="bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                    <option value="">All Subtopics</option>
                    <?php foreach ($subtopics as $sub): ?>
                        <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterType" class="bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                    <option value="">All Types</option>
                    <option value="module">Module</option>
                    <option value="assessment">Assessment</option>
                </select>
                <select id="sortBy" class="bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
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
            </div>
            <div class="relative w-full md:w-64">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchActiveInput" placeholder="Search items..." class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
            </div>
        </div>

        <!-- Active Items Grid -->
        <?php if (count($active_items) > 0): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10" id="activeItemsContainer">
                <?php foreach ($active_items as $item): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col hover:shadow-md transition-shadow active-item-row"
                         data-id="<?= $item['module_id'] ?>"
                         data-subtopic="<?= $item['subtopic_id'] ?>"
                         data-type="<?= $item['type'] ?>"
                         data-title="<?= htmlspecialchars($item['title']) ?>"
                         data-due="<?= htmlspecialchars($item['due_date'] ?? '') ?>"
                         data-created="<?= htmlspecialchars($item['created_at'] ?? '') ?>"
                         data-subtopic-title="<?= htmlspecialchars($item['subtopic_title']) ?>">
                        
                        <!-- Card Header -->
                        <div class="p-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-start">
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <?php if ($item['type'] == 'module'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-800 uppercase tracking-wide"><i class="fas fa-book mr-1"></i> Module</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-purple-100 text-purple-800 uppercase tracking-wide"><i class="fas fa-clipboard-check mr-1"></i> Assessment</span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900 leading-tight mt-1"><?= htmlspecialchars($item['title']) ?></h3>
                                <div class="text-xs text-gray-500 mt-1"><i class="fas fa-folder mr-1"></i> <?= htmlspecialchars($item['subtopic_title']) ?></div>
                            </div>
                            
                            <div class="flex flex-col gap-1 items-end">
                                <input type="checkbox" class="row-checkbox-active w-4 h-4 text-[#0a6e2d] border-gray-300 rounded focus:ring-[#0a6e2d] mt-1">
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="p-4 flex-1">
                            <?php if ($item['description']): ?>
                                <p class="text-sm text-gray-600 mb-3 line-clamp-2"><?= nl2br(htmlspecialchars($item['description'])) ?></p>
                            <?php endif; ?>
                            
                            <?php if ($item['due_date']): ?>
                                <div class="text-xs font-medium text-gray-600 bg-gray-100 inline-block px-2 py-1 rounded mb-3"><i class="far fa-calendar-alt mr-1"></i> Due: <?= date('M d, Y', strtotime($item['due_date'])) ?></div>
                            <?php endif; ?>
                            
                            <?php if ($item['content']): ?>
                                <div class="mt-3 pt-3 border-t border-gray-100 text-sm">
                                    <?= renderContent($item['content']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Footer (Actions) -->
                        <div class="p-3 bg-gray-50 border-t border-gray-100 flex justify-between items-center">
                            <div>
                                <?php if ($item['type'] == 'module'): ?>
                                    <button class="view-module-status text-gray-500 hover:text-[#0a6e2d] text-sm font-medium transition-colors" data-module-id="<?= $item['module_id'] ?>" data-module-title="<?= htmlspecialchars($item['title']) ?>"><i class="fas fa-eye mr-1"></i> View Status</button>
                                <?php else: ?>
                                    <button class="view-results text-gray-500 hover:text-[#0a6e2d] text-sm font-medium transition-colors" data-module-id="<?= $item['module_id'] ?>" data-module-title="<?= htmlspecialchars($item['title']) ?>"><i class="fas fa-chart-bar mr-1"></i> View Results</button>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!isViewer()): ?>
                            <div class="flex gap-1">
                                <button class="edit-item text-gray-400 hover:text-blue-600 p-2 rounded-lg hover:bg-blue-50 transition-colors" data-id="<?= $item['module_id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                <form method="POST" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="soft_delete">
                                    <input type="hidden" name="id" value="<?= $item['module_id'] ?>">
                                    <button type="submit" onclick="return confirm('Archive this item?')" class="text-gray-400 hover:text-red-600 p-2 rounded-lg hover:bg-red-50 transition-colors" title="Archive"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-16 bg-white rounded-xl border border-gray-200 border-dashed mb-10">
                <i class="fas fa-book-open text-5xl text-gray-300 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900">No active modules or assessments</h3>
                <p class="text-sm text-gray-500 mt-1">Click "Create New" to add your first item.</p>
            </div>
        <?php endif; ?>

        <!-- Archives Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div id="archiveHeader" class="px-6 py-4 flex justify-between items-center cursor-pointer hover:bg-gray-50 transition-colors">
                <div class="flex items-center gap-2 font-bold text-gray-800">
                    <i class="fas fa-archive text-gray-400"></i> Archived Items 
                    <span class="bg-gray-100 text-gray-600 py-0.5 px-2.5 rounded-full text-xs font-semibold ml-1"><?= count($deleted_items) ?></span>
                </div>
                <i class="fas fa-chevron-down text-gray-400 transition-transform duration-200"></i>
            </div>
            
            <div id="archiveContent" class="hidden border-t border-gray-200">
                <div class="p-4 bg-gray-50 flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="selectAllArchive" class="w-4 h-4 text-[#0a6e2d] border-gray-300 rounded focus:ring-[#0a6e2d]">
                        <button id="bulkRestoreBtn" class="bg-white border border-gray-300 hover:bg-green-50 text-green-700 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors flex items-center gap-2 shadow-sm hidden">
                            <i class="fas fa-undo-alt"></i> Restore
                        </button>
                        <button id="bulkDeleteBtn" class="bg-white border border-gray-300 hover:bg-red-50 text-red-600 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors flex items-center gap-2 shadow-sm hidden">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <select id="filterArchSubtopic" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="">All Subtopics</option>
                            <?php foreach ($subtopics as $sub): ?>
                                <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filterArchType" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                            <option value="">All Types</option>
                            <option value="module">Module</option>
                            <option value="assessment">Assessment</option>
                        </select>
                    </div>
                </div>

                <div class="divide-y divide-gray-100" id="archiveItemsContainer">
                    <?php if (count($deleted_items) > 0): ?>
                        <?php foreach ($deleted_items as $item): ?>
                            <div class="p-4 hover:bg-gray-50 transition-colors flex flex-col md:flex-row md:items-center justify-between gap-4 archive-item-row"
                                 data-id="<?= $item['module_id'] ?>"
                                 data-subtopic="<?= $item['subtopic_id'] ?>"
                                 data-type="<?= $item['type'] ?>"
                                 data-title="<?= htmlspecialchars($item['title']) ?>">
                                
                                <div class="flex items-start gap-3">
                                    <input type="checkbox" class="row-checkbox-archive w-4 h-4 text-[#0a6e2d] border-gray-300 rounded mt-1" value="<?= $item['module_id'] ?>">
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <?php if ($item['type'] == 'module'): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-gray-100 text-gray-600 uppercase"><i class="fas fa-book mr-1"></i> Module</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-gray-100 text-gray-600 uppercase"><i class="fas fa-clipboard-check mr-1"></i> Assessment</span>
                                            <?php endif; ?>
                                            <strong class="text-gray-800"><?= htmlspecialchars($item['title']) ?></strong>
                                        </div>
                                        <div class="text-xs text-gray-500"><i class="fas fa-folder mr-1"></i> <?= htmlspecialchars($item['subtopic_title']) ?></div>
                                    </div>
                                </div>
                                
                                <?php if (!isViewer()): ?>
                                <div class="flex gap-2 ml-7 md:ml-0">
                                    <form method="POST" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="id" value="<?= $item['module_id'] ?>">
                                        <button type="submit" class="bg-green-50 text-green-700 hover:bg-green-100 px-3 py-1.5 rounded-md text-xs font-medium transition-colors border border-green-200">Restore</button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Permanently delete? This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="hard_delete">
                                        <input type="hidden" name="id" value="<?= $item['module_id'] ?>">
                                        <button type="submit" class="bg-red-50 text-red-700 hover:bg-red-100 px-3 py-1.5 rounded-md text-xs font-medium transition-colors border border-red-200">Delete</button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-8 text-center text-gray-500">No archived items.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- ==================== RESULTS MODAL ==================== -->
<div class="modal fade" id="resultsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-[#0a6e2d] text-white p-4 border-0">
                <h5 class="modal-title text-lg font-bold">Status / Results</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-6 bg-white" id="resultsModalBody">Loading...</div>
            <div class="modal-footer bg-gray-50 border-t border-gray-200 p-4">
                <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== CREATE MODAL ==================== -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-[#0a6e2d] text-white p-4 border-0">
                <h5 class="text-lg font-bold"><i class="fas fa-plus-circle mr-2"></i>Create New Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-6 bg-gray-50">
                <!-- Tabs -->
                <ul class="nav nav-pills mb-6 bg-white p-2 rounded-xl shadow-sm border border-gray-200 inline-flex" id="createTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-lg font-medium px-4 py-2 text-sm" id="module-tab" data-bs-toggle="tab" data-bs-target="#module" type="button" role="tab"><i class="fas fa-book mr-2"></i>Module</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-lg font-medium px-4 py-2 text-sm" id="assessment-tab" data-bs-toggle="tab" data-bs-target="#assessment" type="button" role="tab"><i class="fas fa-clipboard-check mr-2"></i>Assessment</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Module Tab -->
                    <div class="tab-pane fade show active" id="module" role="tabpanel">
                        <form method="POST" id="createModuleForm" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="type" value="module">
                            
                            <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200 mb-6">
                                <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-info-circle text-gray-400 mr-2"></i>Item Details</h6>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic <span class="text-red-500">*</span></label>
                                        <select name="subtopic_id" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                                            <option value="">Select Subtopic</option>
                                            <?php foreach ($subtopics as $sub): ?>
                                                <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                                        <input type="text" name="title" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <textarea name="description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]"></textarea>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                                    <input type="date" name="due_date" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                </div>
                            </div>

                            <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
                                <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-file-alt text-gray-400 mr-2"></i>Content</h6>
                                <div class="flex flex-wrap gap-4 mb-4">
                                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 border border-gray-200 px-4 py-2 rounded-lg hover:border-[#0a6e2d] transition-colors">
                                        <input class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="radio" name="content_type" value="file" id="module_file_radio" checked>
                                        <span class="text-sm font-medium text-gray-700">Upload File</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 border border-gray-200 px-4 py-2 rounded-lg hover:border-[#0a6e2d] transition-colors">
                                        <input class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="radio" name="content_type" value="link" id="module_link_radio">
                                        <span class="text-sm font-medium text-gray-700">Provide Link</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 border border-gray-200 px-4 py-2 rounded-lg hover:border-[#0a6e2d] transition-colors">
                                        <input class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="radio" name="content_type" value="text" id="module_text_radio">
                                        <span class="text-sm font-medium text-gray-700">Write Content</span>
                                    </label>
                                </div>

                                <div id="module_file_div">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">File (PDF, DOC, etc. — max 10 MB)</label>
                                    <input type="file" name="module_file" class="w-full border border-gray-300 rounded-lg p-2 text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-[#0a6e2d]/10 file:text-[#0a6e2d] hover:file:bg-[#0a6e2d]/20">
                                </div>
                                <div id="module_link_div" style="display:none;">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
                                    <input type="text" name="module_link" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="https://...">
                                </div>
                                <div id="module_text_div" style="display:none;">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Write Module Content</label>
                                    <textarea name="module_text" rows="6" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="Write your module content here..."></textarea>
                                </div>
                            </div>

                            <div class="mt-6 flex justify-end gap-3">
                                <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium text-sm px-6 py-2.5 rounded-lg transition-colors" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium text-sm px-8 py-2.5 rounded-lg transition-colors shadow-sm">Create Module</button>
                            </div>
                        </form>
                    </div>

                    <!-- Assessment Tab -->
                    <div class="tab-pane fade" id="assessment" role="tabpanel">
                        <form method="POST" id="createAssessmentForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="type" value="assessment">
                            
                            <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200 mb-6">
                                <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-info-circle text-gray-400 mr-2"></i>Assessment Details</h6>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic <span class="text-red-500">*</span></label>
                                        <select name="subtopic_id" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                                            <option value="">Select Subtopic</option>
                                            <?php foreach ($subtopics as $sub): ?>
                                                <option value="<?= $sub['subtopic_id'] ?>"><?= htmlspecialchars($sub['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                                        <input type="text" name="title" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <textarea name="description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]"></textarea>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                                    <input type="date" name="due_date" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                </div>
                            </div>

                            <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200">
                                <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-tasks text-gray-400 mr-2"></i>Assessment Type</h6>
                                <div class="flex flex-wrap gap-4 mb-4">
                                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 border border-gray-200 px-4 py-2 rounded-lg hover:border-[#0a6e2d] transition-colors">
                                        <input class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="radio" name="assessment_type" value="link" id="assess_link_radio" checked>
                                        <span class="text-sm font-medium text-gray-700">Provide Link (e.g., Google Form)</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 border border-gray-200 px-4 py-2 rounded-lg hover:border-[#0a6e2d] transition-colors">
                                        <input class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="radio" name="assessment_type" value="quiz" id="assess_quiz_radio">
                                        <span class="text-sm font-medium text-gray-700">Create Quiz / Assignment</span>
                                    </label>
                                </div>

                                <div id="assessment_link_div">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Link</label>
                                    <input type="text" name="assessment_link" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="https://forms.google.com/...">
                                </div>
                                
                                <div id="assessment_quiz_div" style="display:none;">
                                    <div class="flex justify-between items-center mb-3">
                                        <label class="block text-sm font-bold text-gray-700">Questions</label>
                                        <div class="flex items-center gap-2">
                                            <label class="text-sm text-gray-600">Passing Score (%):</label>
                                            <input type="number" id="passing_score" value="50" min="1" max="100" class="w-16 border border-gray-300 rounded p-1 text-sm text-center focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                            <button type="button" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium px-3 py-1.5 rounded-lg transition-colors border border-gray-300 ml-2" id="add-question"><i class="fas fa-plus mr-1"></i> Add Question</button>
                                        </div>
                                    </div>
                                    <div id="questions-container" class="space-y-4">
                                        <div class="question-template" style="display:none;">
                                            <div class="card mb-4 border border-gray-200 rounded-xl bg-white overflow-hidden">
                                                <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 flex justify-between items-center">
                                                    <span class="text-xs font-bold text-gray-500 uppercase">Question</span>
                                                    <button type="button" class="text-red-500 hover:text-red-700 remove-question text-sm font-medium"><i class="fas fa-trash-alt"></i> Remove</button>
                                                </div>
                                                <div class="p-4 space-y-3">
                                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                                        <div class="md:col-span-3">
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">Question Text</label>
                                                            <input type="text" class="w-full border border-gray-300 rounded-lg p-2 question-text focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                                        </div>
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">Points</label>
                                                            <input type="number" class="w-full border border-gray-300 rounded-lg p-2 question-points focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="1" step="0.5">
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                                        <select class="w-full border border-gray-300 rounded-lg p-2 question-type focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                                            <option value="multiple_choice">Multiple Choice</option>
                                                            <option value="true_false">True/False</option>
                                                            <option value="short_answer">Short Answer</option>
                                                        </select>
                                                    </div>
                                                    <div class="question-options" style="display:none;">
                                                        <label class="block text-sm font-medium text-gray-700 mb-1">Options (comma separated)</label>
                                                        <input type="text" class="w-full border border-gray-300 rounded-lg p-2 question-options-list focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="e.g., Option A, Option B, Option C">
                                                    </div>
                                                    <div>
                                                        <label class="block text-sm font-medium text-gray-700 mb-1">Correct Answer</label>
                                                        <input type="text" class="w-full border border-gray-300 rounded-lg p-2 question-correct-answer focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="quiz_data" id="quiz_data">
                            </div>

                            <div class="mt-6 flex justify-end gap-3">
                                <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium text-sm px-6 py-2.5 rounded-lg transition-colors" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium text-sm px-8 py-2.5 rounded-lg transition-colors shadow-sm">Create Assessment</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== EDIT MODAL ==================== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-[#0a6e2d] text-white p-4 border-0">
                <h5 class="text-lg font-bold"><i class="fas fa-edit mr-2"></i>Edit Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-6 bg-gray-50" id="editModalBody">
                <!-- AJAX populated -->
            </div>
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
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const banner = doc.querySelector('.bg-red-100');
    if (banner) return banner.textContent.replace(/×\s*$/, '').trim();
    return null;
}

function extractSuccess(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const banner = doc.querySelector('.bg-green-100');
    if (banner) return banner.textContent.replace(/×\s*$/, '').trim();
    return null;
}

function showToast(message, type = 'info') {
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
        position: fixed; top: 20px; right: 20px; z-index: 99999;
        background: ${c.bg}; color: ${c.text};
        border-left: 4px solid ${c.border};
        padding: 14px 20px; border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.15); max-width: 420px;
        display: flex; align-items: flex-start; gap: 12px;
        font-size: 14px; font-weight: 500; animation: slideInToast 0.3s ease;
    `;
    toast.innerHTML = `
        <i class="fas ${c.icon}" style="margin-top:2px; font-size:16px;"></i>
        <div style="flex:1;">${message}</div>
        <button onclick="this.parentElement.remove()" style="background:none; border:none; font-size:18px; cursor:pointer; color:${c.text}; opacity:0.6; padding:0; line-height:1;">&times;</button>
    `;
    document.body.appendChild(toast);
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
    t.querySelector('.question-points').value = '1';
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
                if (err) { showToast(err, 'error'); btn.disabled = false; isCreating = false; return; }
                const ok = extractSuccess(html);
                if (ok) showToast(ok, 'success');
                setTimeout(() => location.reload(), ok ? 1800 : 0);
            })
            .catch(err => { showToast('Network error: ' + err, 'error'); btn.disabled = false; isCreating = false; });
    });
document.getElementById('createAssessmentForm')?.addEventListener('submit', function(e) {
    e.preventDefault(); if (isCreating) return; isCreating = true;
    const btn = this.querySelector('button[type="submit"]'); btn.disabled = true;

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
        
        // Grab the passing score
        const passingScore = document.getElementById('passing_score')?.value || 50;
        
        // Save it as an object instead of just an array
        const quizPayload = {
            passing_score: parseInt(passingScore),
            questions: qs
        };
        
        document.getElementById('quiz_data').value = 'quiz:' + JSON.stringify(quizPayload);
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
        .catch(err => { showToast('Network error: ' + err, 'error'); btn.disabled = false; isCreating = false; });
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
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200 mb-6">
            <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-info-circle text-gray-400 mr-2"></i>Item Details</h6>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic <span class="text-red-500">*</span></label>
                    <select name="subtopic_id" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>`;
    <?php foreach ($subtopics as $sub): ?>
        h += `<option value="<?= $sub['subtopic_id'] ?>" ${data.subtopic_id == <?= $sub['subtopic_id'] ?> ? 'selected' : ''}><?= addslashes($sub['title']) ?></option>`;
    <?php endforeach; ?>
    h += `</select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]"><option value="module" ${data.type=='module'?'selected':''}>Module</option><option value="assessment" ${data.type=='assessment'?'selected':''}>Assessment</option></select>
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                <input type="text" name="title" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(data.title)}" required>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">${escapeHtml(data.description)}</textarea>
            </div>
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                <input type="date" name="due_date" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${data.due_date||''}">
            </div>
        </div>`;
        
    if (data.type === 'assessment' && data.content?.startsWith('quiz:')) {
        const qs = JSON.parse(data.content.substring(5));
        h += `<div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200 mb-6">
                <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-tasks text-gray-400 mr-2"></i>Quiz Questions</h6>
                <div class="flex flex-wrap gap-4 mb-4">
                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 border border-gray-200 px-4 py-2 rounded-lg hover:border-[#0a6e2d]"><input class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="radio" name="assessment_type" value="link" id="edit_assess_link_radio"><span class="text-sm font-medium text-gray-700">Provide Link</span></label>
                    <label class="flex items-center gap-2 cursor-pointer bg-gray-50 border border-gray-200 px-4 py-2 rounded-lg hover:border-[#0a6e2d]"><input class="rounded border-gray-300 text-[#0a6e2d] focus:ring-[#0a6e2d]" type="radio" name="assessment_type" value="quiz" id="edit_assess_quiz_radio" checked><span class="text-sm font-medium text-gray-700">Quiz / Assignment</span></label>
                </div>
                <div id="edit_quiz_div">
                    <div class="flex justify-between items-center mb-3">
                        <label class="block text-sm font-bold text-gray-700">Questions</label>
                        <button type="button" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium px-3 py-1.5 rounded-lg border border-gray-300" id="edit_add_question"><i class="fas fa-plus mr-1"></i> Add Question</button>
                    </div>
                    <div id="edit_questions_container" class="space-y-4">`;
        qs.forEach(q => {
            h += `<div class="card mb-4 border border-gray-200 rounded-xl bg-white overflow-hidden">
                <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 flex justify-between items-center">
                    <span class="text-xs font-bold text-gray-500 uppercase">Question</span>
                    <button type="button" class="text-red-500 hover:text-red-700 remove-question text-sm font-medium"><i class="fas fa-trash-alt"></i> Remove</button>
                </div>
                <div class="p-4 space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div class="md:col-span-3">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Question Text</label>
                            <input type="text" class="w-full border border-gray-300 rounded-lg p-2 question-text focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(q.text)}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Points</label>
                            <input type="number" class="w-full border border-gray-300 rounded-lg p-2 question-points focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${q.points}" step="0.5">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <select class="w-full border border-gray-300 rounded-lg p-2 question-type focus:ring-[#0a6e2d] focus:border-[#0a6e2d]"><option value="multiple_choice" ${q.type=='multiple_choice'?'selected':''}>Multiple Choice</option><option value="true_false" ${q.type=='true_false'?'selected':''}>True/False</option><option value="short_answer" ${q.type=='short_answer'?'selected':''}>Short Answer</option></select>
                    </div>
                    <div class="question-options" style="display:${q.type=='multiple_choice'?'block':'none'};">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Options (comma separated)</label>
                        <input type="text" class="w-full border border-gray-300 rounded-lg p-2 question-options-list focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(q.options?.join(', ')||'')}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Correct Answer</label>
                        <input type="text" class="w-full border border-gray-300 rounded-lg p-2 question-correct-answer focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" value="${escapeHtml(q.correct)}">
                    </div>
                </div>
            </div>`;
        });
        h += `</div></div><input type="hidden" name="quiz_data" id="edit_quiz_data"></div>`;
        h += `<div id="edit_link_div" style="display:none;"><label class="block text-sm font-medium text-gray-700 mb-1">Link</label><input type="text" name="assessment_link" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]"></div></div>`;
    } else {
        h += `<div class="bg-white rounded-xl p-5 shadow-sm border border-gray-200 mb-6">
                <h6 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wider"><i class="fas fa-file-alt text-gray-400 mr-2"></i>Content</h6>
                <textarea name="content" rows="3" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]">${escapeHtml(data.content)}</textarea>
              </div>`;
    }
    h += `<div class="mt-6 flex justify-end gap-3">
            <button type="button" class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium text-sm px-6 py-2.5 rounded-lg transition-colors" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium text-sm px-8 py-2.5 rounded-lg transition-colors shadow-sm">Update Item</button>
          </div></form>`;
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
        n.querySelector('.question-points').value = '1';
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
                let h = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th><th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Course</th><th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Section</th><th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th><th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Date</th></tr></thead><tbody class="divide-y divide-gray-100 bg-white">';
                d.results.forEach(r => {
                    const statusBadge = r.completed ? '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i> Done</span>' : '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i> Missing</span>';
                    h += `<tr><td class="px-4 py-3 text-sm font-medium text-gray-900">${escapeHtml(r.full_name)}</td><td class="px-4 py-3 text-sm text-gray-500">${escapeHtml(r.program)}</td><td class="px-4 py-3 text-sm text-gray-500">${escapeHtml(r.section)}</td><td class="px-4 py-3 text-center">${statusBadge}</td><td class="px-4 py-3 text-center text-xs text-gray-500">${r.completion_date || '-'}</td></tr>`;
                });
                h += '</tbody></table></div>'; body.innerHTML = h;
            } else body.innerHTML = '<div class="bg-blue-50 text-blue-700 p-4 rounded-lg text-sm">No students registered for this module yet.</div>';
        }).catch(() => body.innerHTML = '<div class="bg-red-50 text-red-700 p-4 rounded-lg text-sm">Error loading data.</div>');
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
                let h = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Name</th><th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Course</th><th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Section</th><th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Status</th><th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Score</th><th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Date</th></tr></thead><tbody class="divide-y divide-gray-100 bg-white">';
                d.results.forEach(r => {
                    const statusBadge = r.completed ? '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i> Done</span>' : '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i> Missing</span>';
                    h += `<tr><td class="px-4 py-3 text-sm font-medium text-gray-900">${escapeHtml(r.full_name)}</td><td class="px-4 py-3 text-sm text-gray-500">${escapeHtml(r.program)}</td><td class="px-4 py-3 text-sm text-gray-500">${escapeHtml(r.section)}</td><td class="px-4 py-3 text-center">${statusBadge}</td><td class="px-4 py-3 text-center text-sm font-bold text-blue-600">${r.score !== null ? r.score+'%' : 'N/A'}</td><td class="px-4 py-3 text-center text-xs text-gray-500">${r.completion_date || '-'}</td></tr>`;
                });
                h += '</tbody></table></div>'; body.innerHTML = h;
            } else body.innerHTML = '<div class="bg-blue-50 text-blue-700 p-4 rounded-lg text-sm">No students have taken this assessment yet.</div>';
        }).catch(() => body.innerHTML = '<div class="bg-red-50 text-red-700 p-4 rounded-lg text-sm">Error loading data.</div>');
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
        btns.forEach(b => {
            b.classList.toggle('hidden', !any);
            if (!any) b.classList.add('hidden'); else b.classList.remove('hidden');
            // Handle flex display toggle
            if (any) { b.classList.remove('hidden'); b.classList.add('flex'); } 
            else { b.classList.add('hidden'); b.classList.remove('flex'); }
        });
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
    const urlSort = new URL(window.location).searchParams.get('sort');
    const sortSelect = document.getElementById('sortBy');
    if (urlSort && sortSelect) sortSelect.value = urlSort;

    sortItems();

    sortSelect?.addEventListener('change', () => {
        const url = new URL(window.location);
        url.searchParams.set('sort', sortSelect.value);
        history.replaceState({}, '', url);
    });
});
</script>
</body>
</html>