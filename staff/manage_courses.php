<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();
if (isViewer() && $_SERVER['REQUEST_METHOD'] === 'POST') { exit('Access denied'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

$action = $_POST['action'] ?? '';
$error = ''; $success = '';

if ($action === 'add_course') {
    $name = trim($_POST['course_name']);
    if ($name) {
        // Insert the course
        $stmt = $pdo->prepare("INSERT IGNORE INTO courses (name) VALUES (?)");
        $stmt->execute([$name]);
        
        // Get the course ID (works whether it was just created or already existed)
        $stmt_get = $pdo->prepare("SELECT id FROM courses WHERE name = ?");
        $stmt_get->execute([$name]);
        $course_id = $stmt_get->fetchColumn();

        // Auto-generate sections 401 to 415 for this course
        if ($course_id) {
            $stmt_sec = $pdo->prepare("INSERT IGNORE INTO sections (course_id, name) VALUES (?, ?)");
            for ($i = 401; $i <= 415; $i++) {
                $stmt_sec->execute([$course_id, (string)$i]);
            }
        }
        
    $success = "Course added successfully with default sections (401-415).";
    }
} elseif ($action === 'delete_course') {
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->execute([(int)$_POST['course_id']]);
    $success = "Course deleted.";
} elseif ($action === 'add_section') {
    $course_id = (int)$_POST['course_id'];
    $name = trim($_POST['section_name']);
    if ($course_id && $name) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO sections (course_id, name) VALUES (?, ?)");
        $stmt->execute([$course_id, $name]);
        $success = "Section added.";
    }
} elseif ($action === 'delete_section') {
    $stmt = $pdo->prepare("DELETE FROM sections WHERE id = ?");
    $stmt->execute([(int)$_POST['section_id']]);
    $success = "Section deleted.";
}

$courses = $pdo->query("SELECT * FROM courses ORDER BY name")->fetchAll();
$sections = $pdo->query("
    SELECT s.*, c.name AS course_name 
    FROM sections s JOIN courses c ON s.course_id = c.id 
    ORDER BY c.name, s.name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Manage Courses | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-8 overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-[#0a6e2d]">Manage Courses & Sections</h1>
                    <p class="text-sm text-gray-500">Dynamically add programs and sections for student registration.</p>
                </div>
                <a href="students.php" class="bg-white hover:bg-gray-50 border border-gray-300 text-gray-800 text-sm font-semibold px-4 py-2 rounded-lg inline-flex items-center gap-2 transition shadow-sm">
                    <i class="fas fa-arrow-left text-[#0a6e2d]"></i> Back to Student Info
                </a>
            </div>

            <?php if ($success): ?><div class="bg-green-50 text-green-700 p-3 rounded mb-4"><?= $success ?></div><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Courses Column -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Courses</h2>
                    <form method="POST" class="flex gap-2 mb-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_course">
                        <input type="text" name="course_name" placeholder="e.g. BS Information Systems" required class="flex-1 border border-gray-300 rounded-lg p-2 text-sm">
                        <button type="submit" class="bg-[#0a6e2d] text-white px-4 py-2 rounded-lg text-sm font-medium">Add</button>
                    </form>
                    <ul class="space-y-2">
                        <?php foreach ($courses as $c): ?>
                        <li class="flex justify-between items-center bg-gray-50 p-3 rounded-lg border border-gray-100">
                            <span class="font-medium text-gray-700 text-sm"><?= htmlspecialchars($c['name']) ?></span>
                            <form method="POST" onsubmit="return confirm('Delete this course and all its sections?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_course">
                                <input type="hidden" name="course_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Sections Column -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Sections</h2>
                    <form method="POST" class="grid grid-cols-2 gap-2 mb-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_section">
                        <select name="course_id" required class="border border-gray-300 rounded-lg p-2 text-sm">
                            <option value="">Select Course...</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="section_name" placeholder="e.g. 4-A" required class="border border-gray-300 rounded-lg p-2 text-sm">
                        <button type="submit" class="col-span-2 bg-[#0a6e2d] text-white px-4 py-2 rounded-lg text-sm font-medium">Add Section</button>
                    </form>
                    <ul class="space-y-2 max-h-96 overflow-y-auto">
                        <?php foreach ($sections as $s): ?>
                        <li class="flex justify-between items-center bg-gray-50 p-3 rounded-lg border border-gray-100">
                            <div>
                                <span class="font-medium text-gray-700 text-sm"><?= htmlspecialchars($s['name']) ?></span>
                                <span class="text-xs text-gray-500 block"><?= htmlspecialchars($s['course_name']) ?></span>
                            </div>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_section">
                                <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </main>
    </div>
</body>
</html>