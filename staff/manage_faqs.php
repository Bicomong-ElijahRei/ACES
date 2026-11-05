<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

$success = '';
$error = '';
$edit_faq = null;

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_faq') {
    csrf_verify();
    $faq_id = !empty($_POST['faq_id']) ? (int)$_POST['faq_id'] : null;
    $category = trim($_POST['category']);
    $question = trim($_POST['question']);
    $answer = trim($_POST['answer']);

    if ($category && $question && $answer) {
        if ($faq_id) {
            // Update
            $stmt = $pdo->prepare("UPDATE faqs SET category = ?, question = ?, answer = ? WHERE faq_id = ?");
            $stmt->execute([$category, $question, $answer, $faq_id]);
            $success = "FAQ updated successfully!";
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO faqs (category, question, answer, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$category, $question, $answer, $_SESSION['user_id']]);
            $success = "FAQ added successfully!";
        }
    } else {
        $error = "All fields are required.";
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_faq') {
    csrf_verify();
    $faq_id = (int)$_POST['faq_id'];
    $stmt = $pdo->prepare("UPDATE faqs SET is_deleted = 1 WHERE faq_id = ?");
    $stmt->execute([$faq_id]);
    $success = "FAQ deleted successfully!";
}

// Handle Edit Request (GET)
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM faqs WHERE faq_id = ? AND is_deleted = 0");
    $stmt->execute([(int)$_GET['edit']]);
    $edit_faq = $stmt->fetch();
}

// Fetch all active FAQs
$faqs = $pdo->query("SELECT * FROM faqs WHERE is_deleted = 0 ORDER BY category ASC, created_at DESC")->fetchAll();
$categories = $pdo->query("SELECT DISTINCT category FROM faqs WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Manage FAQs | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-8 overflow-y-auto">
            
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-[#0a6e2d]">Manage FAQs</h1>
                    <p class="text-sm text-gray-600 mt-1">Add, edit, or remove Frequently Asked Questions for students.</p>
                </div>
                <a href="support.php" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold shadow-sm hover:bg-gray-50 transition">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Tickets
                </a>
            </div>

            <?php if ($success): ?><div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4"><i class="fas fa-check-circle mr-2"></i> <?= $success ?></div><?php endif; ?>
            <?php if ($error): ?><div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4"><i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?></div><?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Form -->
                <div class="lg:col-span-1 bg-white p-6 rounded-xl shadow-sm h-fit">
                    <h2 class="font-bold text-gray-800 mb-4 border-b pb-2">
                        <?= $edit_faq ? '<i class="fas fa-edit text-indigo-500 mr-2"></i>Edit FAQ' : '<i class="fas fa-plus-circle text-[#0a6e2d] mr-2"></i>Add New FAQ' ?>
                    </h2>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_faq">
                        <?php if ($edit_faq): ?>
                            <input type="hidden" name="faq_id" value="<?= $edit_faq['faq_id'] ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Category</label>
                            <input type="text" name="category" list="categories" value="<?= htmlspecialchars($edit_faq['category'] ?? '') ?>" required class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#0a6e2d]">
                            <datalist id="categories">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>">
                                <?php endforeach; ?>
                                <option value="Registration">
                                <option value="Attendance">
                                <option value="Make-Ups">
                                <option value="Modules">
                                <option value="Certificates">
                            </datalist>
                        </div>
                        
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Question</label>
                            <input type="text" name="question" value="<?= htmlspecialchars($edit_faq['question'] ?? '') ?>" required class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#0a6e2d]">
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Answer</label>
                            <textarea name="answer" rows="4" required class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#0a6e2d]"><?= htmlspecialchars($edit_faq['answer'] ?? '') ?></textarea>
                        </div>
                        
                        <button type="submit" class="w-full bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-2 rounded-lg transition-colors">
                            <?= $edit_faq ? 'Update FAQ' : 'Save FAQ' ?>
                        </button>
                        
                        <?php if ($edit_faq): ?>
                            <a href="manage_faqs.php" class="block text-center text-sm text-gray-500 mt-2 hover:underline">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- List -->
                <div class="lg:col-span-2 space-y-4">
                    <?php if (empty($faqs)): ?>
                        <div class="bg-white p-8 text-center rounded-xl text-gray-500">No FAQs added yet.</div>
                    <?php else: ?>
                        <?php foreach ($faqs as $f): ?>
                            <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200">
                                <div class="flex justify-between items-start gap-4">
                                    <div>
                                        <span class="inline-block px-2 py-1 bg-gray-100 text-gray-600 text-xs font-bold rounded mb-2"><?= htmlspecialchars($f['category']) ?></span>
                                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($f['question']) ?></h3>
                                        <p class="text-sm text-gray-600 mt-2"><?= nl2br(htmlspecialchars($f['answer'])) ?></p>
                                    </div>
                                    <div class="flex gap-2 shrink-0">
                                        <a href="manage_faqs.php?edit=<?= $f['faq_id'] ?>" class="text-blue-500 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg transition" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this FAQ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_faq">
                                            <input type="hidden" name="faq_id" value="<?= $f['faq_id'] ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-2 rounded-lg transition" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>