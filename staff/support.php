<?php
require_once '../config/env.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
redirectIfNotStaff();

$success_msg = '';
$error_msg = '';

// ==========================================
// FAQ MANAGEMENT LOGIC
// ==========================================
$edit_faq = null;

// Handle Add/Edit FAQ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_faq') {
    csrf_verify();
    $faq_id = !empty($_POST['faq_id']) ? (int)$_POST['faq_id'] : null;
    $category = trim($_POST['category']);
    $question = trim($_POST['question']);
    $answer = trim($_POST['answer']);

    if ($category && $question && $answer) {
        if ($faq_id) {
            $stmt = $pdo->prepare("UPDATE faqs SET category = ?, question = ?, answer = ? WHERE faq_id = ?");
            $stmt->execute([$category, $question, $answer, $faq_id]);
            $success_msg = "FAQ updated successfully!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO faqs (category, question, answer, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$category, $question, $answer, $_SESSION['user_id']]);
            $success_msg = "FAQ added successfully!";
        }
    } else {
        $error_msg = "All FAQ fields are required.";
    }
}

// Handle Delete FAQ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_faq') {
    csrf_verify();
    $stmt = $pdo->prepare("UPDATE faqs SET is_deleted = 1 WHERE faq_id = ?");
    $stmt->execute([(int)$_POST['faq_id']]);
    $success_msg = "FAQ deleted successfully!";
}

// Handle Edit Request
if (isset($_GET['edit_faq'])) {
    $stmt = $pdo->prepare("SELECT * FROM faqs WHERE faq_id = ? AND is_deleted = 0");
    $stmt->execute([(int)$_GET['edit_faq']]);
    $edit_faq = $stmt->fetch();
}

// Fetch all active FAQs for the table
$all_faqs = $pdo->query("SELECT * FROM faqs WHERE is_deleted = 0 ORDER BY category ASC, created_at DESC")->fetchAll();
$faq_categories = $pdo->query("SELECT DISTINCT category FROM faqs WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN);
// ==========================================

// Handle Permanent Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tickets') {
    csrf_verify();

    if (!empty($_POST['ticket_ids']) && is_array($_POST['ticket_ids'])) {
        $ids = array_map('intval', $_POST['ticket_ids']);
        $inQuery = implode(',', array_fill(0, count($ids), '?'));

        try {
            $pdo->beginTransaction();
            $stmtDel = $pdo->prepare("DELETE FROM tickets WHERE ticket_id IN ($inQuery)");
            $stmtDel->execute($ids);
            $pdo->commit();
            $success_msg = count($ids) . " ticket(s) permanently deleted.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Failed to delete tickets: " . $e->getMessage();
        }
    } else {
        $error_msg = "No tickets were selected for deletion.";
    }
}

// Fetch All Tickets (with unread message count)
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS student_name,
           (SELECT COUNT(*) FROM ticket_messages tm WHERE tm.ticket_id = t.ticket_id AND tm.is_read_by_staff = 0) AS unread_count
    FROM tickets t 
    JOIN students s ON t.student_id = s.student_id 
    JOIN users u ON s.user_id = u.user_id 
    ORDER BY t.created_at DESC
");
$stmt->execute();
$all_tickets = $stmt->fetchAll();

// Separate Active and Archived
$active_tickets = [];
$archived_tickets = [];
foreach ($all_tickets as $t) {
    if ($t['status'] === 'open' || $t['status'] === 'in_progress') {
        $active_tickets[] = $t;
    } else {
        $archived_tickets[] = $t;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Tickets | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden w-full min-w-0">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-6 overflow-y-auto overflow-x-hidden w-full max-w-full">
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Support & FAQs</h1>
            
            <!-- TABS -->
            <div class="flex border-b border-gray-200 mb-6">
                <button id="tab-tickets-btn" class="tab-btn font-bold text-sm pb-2 px-4 border-b-2 border-[#0a6e2d] text-[#0a6e2d]" onclick="switchTab('tickets')">
                    <i class="fas fa-ticket-alt mr-2"></i>Student Tickets
                </button>
                <button id="tab-faqs-btn" class="tab-btn font-bold text-sm pb-2 px-4 border-b-2 border-transparent text-gray-500 hover:text-gray-800" onclick="switchTab('faqs')">
                    <i class="fas fa-question-circle mr-2"></i>Manage FAQs
                </button>
            </div>

            <!-- Alerts -->
            <?php if ($success_msg): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($success_msg) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>
            
            <!-- TICKETS TAB CONTENT -->
            <div id="tab-tickets" class="tab-content block">
            
            <!-- ACTIVE TICKETS -->
            <div class="mb-10">
                <div class="flex items-center gap-2 mb-4">
                    <h2 class="text-xl font-bold text-gray-800"><i class="fas fa-inbox text-blue-500"></i> Active Tickets</h2>
                    <span class="bg-blue-100 text-blue-800 py-0.5 px-2.5 rounded-full text-xs font-bold"><?= count($active_tickets) ?></span>
                </div>

                <?php if (empty($active_tickets)): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-10 text-center text-gray-500">
                        <i class="fas fa-check-circle text-4xl text-green-400 mb-3"></i>
                        <p>No active tickets. Great job!</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                        <?php foreach ($active_tickets as $t): ?>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex flex-col h-full hover:shadow-md transition-shadow">
                                <!-- Top Row -->
                                <div class="flex justify-between items-start mb-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-1 rounded">#<?= $t['ticket_id'] ?></span>
                                        
                                        <!-- NEW: UNREAD MESSAGE BADGE -->
                                        <?php if ($t['unread_count'] > 0): ?>
                                            <span class="bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full animate-pulse" title="<?= $t['unread_count'] ?> unread message(s)">
                                                <?= $t['unread_count'] ?> New
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase <?= $t['status'] === 'open' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                                        <?= str_replace('_', ' ', $t['status']) ?>
                                    </span>
                                </div>
                                
                                <!-- Middle -->
                                <div class="flex-1">
                                    <h3 class="font-bold text-gray-800 text-lg mb-1 leading-tight line-clamp-2"><?= htmlspecialchars($t['subject']) ?></h3>
                                    <p class="text-sm text-gray-500 mb-2">By <span class="font-medium text-gray-700"><?= htmlspecialchars($t['student_name']) ?></span></p>
                                    <span class="inline-block text-[10px] font-semibold text-gray-500 uppercase tracking-wider border border-gray-200 rounded px-2 py-0.5"><?= $t['category'] ?></span>
                                </div>
                                
                                <!-- Bottom -->
                                <div class="mt-4 pt-3 border-t border-gray-100 flex justify-between items-center">
                                    <span class="text-xs text-gray-400"><i class="far fa-calendar-alt mr-1"></i> <?= date('M d, Y', strtotime($t['created_at'])) ?></span>
                                    <a href="ticket.php?id=<?= $t['ticket_id'] ?>" class="bg-[#0a6e2d] text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-[#054018] transition-colors shadow-sm">View Thread</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ARCHIVED TICKETS -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div id="archiveToggle" class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center cursor-pointer hover:bg-gray-100 transition-colors">
                    <h2 class="font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-archive text-gray-400"></i> Archived Tickets 
                        <span class="bg-gray-200 text-gray-600 py-0.5 px-2.5 rounded-full text-xs font-semibold ml-1"><?= count($archived_tickets) ?></span>
                    </h2>
                    <i class="fas fa-chevron-down text-gray-400 transition-transform duration-200"></i>
                </div>
                
                <div id="archiveContent" class="hidden">
                    <?php if (!empty($archived_tickets)): ?>
                    
                    <form id="bulkDeleteForm" method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_tickets">
                        
                        <!-- Action Bar -->
                        <div class="bg-gray-100 px-6 py-3 border-b border-gray-200 flex justify-between items-center">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" id="selectAllArchive" class="rounded border-gray-300 text-red-600 focus:ring-red-500 w-4 h-4">
                                <span class="text-xs text-gray-700 font-semibold">Select All</span>
                            </label>
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-xs font-bold transition-colors shadow-sm flex items-center gap-2">
                                <i class="fas fa-trash-alt"></i> Delete Selected
                            </button>
                        </div>

                        <!-- Archived Cards Grid -->
                        <div class="p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 bg-gray-50">
                            <?php foreach ($archived_tickets as $t): ?>
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 flex gap-4 opacity-80 hover:opacity-100 transition-opacity">
                                    <!-- Checkbox -->
                                    <div class="pt-1">
                                        <input type="checkbox" name="ticket_ids[]" value="<?= $t['ticket_id'] ?>" class="ticket-checkbox rounded border-gray-300 text-red-600 focus:ring-red-500 cursor-pointer w-5 h-5">
                                    </div>
                                    
                                    <!-- Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between items-start mb-2">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-mono text-gray-500">#<?= $t['ticket_id'] ?></span>
                                                <!-- NEW: UNREAD BADGE FOR ARCHIVES -->
                                                <?php if ($t['unread_count'] > 0): ?>
                                                    <span class="bg-red-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full">
                                                        <?= $t['unread_count'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $t['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= $t['status'] === 'resolved' ? 'Resolved' : 'Abolished' ?>
                                            </span>
                                        </div>
                                        
                                        <h3 class="font-bold text-gray-800 text-base mb-1 leading-tight truncate"><?= htmlspecialchars($t['subject']) ?></h3>
                                        <p class="text-sm text-gray-500 mb-2 truncate">By <?= htmlspecialchars($t['student_name']) ?></p>
                                        
                                        <div class="flex justify-between items-center mt-3 pt-3 border-t border-gray-100">
                                            <span class="text-xs text-gray-400"><?= date('M d, Y', strtotime($t['created_at'])) ?></span>
                                            <a href="ticket.php?id=<?= $t['ticket_id'] ?>" class="bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-gray-200 transition-colors">View Thread</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                    <?php else: ?>
                        <div class="p-6 text-center text-gray-500 bg-gray-50">No archived tickets.</div>
                    <?php endif; ?>
                </div>
            </div>
            
            </div> <!-- END TICKETS TAB CONTENT -->

            <!-- FAQS TAB CONTENT -->
            <div id="tab-faqs" class="tab-content hidden">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Add/Edit Form -->
                    <div class="lg:col-span-1 bg-white p-5 rounded-xl shadow-sm border border-gray-200 h-fit">
                        <h2 class="font-bold text-gray-800 mb-4 border-b pb-2 text-sm uppercase tracking-wide">
                            <?= $edit_faq ? '<i class="fas fa-edit text-indigo-500 mr-2"></i>Edit FAQ' : '<i class="fas fa-plus-circle text-[#0a6e2d] mr-2"></i>Add New FAQ' ?>
                        </h2>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_faq">
                            <?php if ($edit_faq): ?><input type="hidden" name="faq_id" value="<?= $edit_faq['faq_id'] ?>"><?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="block text-xs font-bold text-gray-600 mb-1">Category</label>
                                <select name="category" required class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#0a6e2d] bg-white">
                                    <option value="">-- Select Category --</option>
                                    <?php 
                                    // Merge hardcoded defaults with existing DB categories to avoid duplicates
                                    $default_cats = ['Registration', 'Attendance', 'Make-Ups', 'Modules', 'Certificates'];
                                    $all_cats = array_unique(array_merge($default_cats, $faq_categories));
                                    sort($all_cats);
                                    
                                    foreach ($all_cats as $cat): 
                                    ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= (isset($edit_faq['category']) && $edit_faq['category'] === $cat) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="block text-xs font-bold text-gray-600 mb-1">Question</label>
                                <input type="text" name="question" value="<?= htmlspecialchars($edit_faq['question'] ?? '') ?>" required class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#0a6e2d]">
                            </div>
                            <div class="mb-4">
                                <label class="block text-xs font-bold text-gray-600 mb-1">Answer</label>
                                <textarea name="answer" rows="4" required class="w-full border rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#0a6e2d]"><?= htmlspecialchars($edit_faq['answer'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" class="w-full bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-2 rounded-lg text-sm transition">
                                <?= $edit_faq ? 'Update FAQ' : 'Save FAQ' ?>
                            </button>
                            <?php if ($edit_faq): ?><a href="support.php" class="block text-center text-xs text-gray-500 mt-2 hover:underline">Cancel Edit</a><?php endif; ?>
                        </form>
                    </div>

                    <!-- FAQ List -->
                    <div class="lg:col-span-2 space-y-3">
                        <?php if (empty($all_faqs)): ?>
                            <div class="bg-white p-8 text-center rounded-xl text-gray-500 border border-gray-200">No FAQs added yet.</div>
                        <?php else: ?>
                            <?php foreach ($all_faqs as $f): ?>
                                <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200">
                                    <div class="flex justify-between items-start gap-4">
                                        <div>
                                            <span class="inline-block px-2 py-1 bg-gray-100 text-gray-600 text-[10px] font-bold uppercase rounded mb-1"><?= htmlspecialchars($f['category']) ?></span>
                                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($f['question']) ?></h3>
                                            <p class="text-xs text-gray-600 mt-1 line-clamp-2"><?= htmlspecialchars($f['answer']) ?></p>
                                        </div>
                                        <div class="flex gap-1 shrink-0">
                                            <a href="support.php?edit_faq=<?= $f['faq_id'] ?>" class="text-blue-500 hover:text-blue-700 bg-blue-50 p-2 rounded" title="Edit"><i class="fas fa-edit"></i></a>
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this FAQ?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_faq">
                                                <input type="hidden" name="faq_id" value="<?= $f['faq_id'] ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-700 bg-red-50 p-2 rounded" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div> <!-- END FAQS TAB CONTENT -->

            <script>
                // Toggle Archive Accordion
                document.getElementById('archiveToggle').addEventListener('click', function() {
                    document.getElementById('archiveContent').classList.toggle('hidden');
                    this.querySelector('.fa-chevron-down').classList.toggle('rotate-180');
                });

                // Select All Checkbox Logic
                const selectAllCheckbox = document.getElementById('selectAllArchive');
                if (selectAllCheckbox) {
                    selectAllCheckbox.addEventListener('change', function() {
                        const checkboxes = document.querySelectorAll('.ticket-checkbox');
                        checkboxes.forEach(cb => cb.checked = this.checked);
                    });

                    // Update Select All if individual checkboxes are clicked
                    document.querySelectorAll('.ticket-checkbox').forEach(cb => {
                        cb.addEventListener('change', function() {
                            const total = document.querySelectorAll('.ticket-checkbox').length;
                            const checked = document.querySelectorAll('.ticket-checkbox:checked').length;
                            selectAllCheckbox.checked = total === checked;
                        });
                    });
                }

                // Delete Confirmation
                if (bulkForm) {
                    bulkForm.addEventListener('submit', function(e) {
                        const checked = document.querySelectorAll('.ticket-checkbox:checked').length;
                        if (checked === 0) {
                            e.preventDefault();
                            alert('Please select at least one ticket to delete.');
                            return;
                        }
                        
                        if (!confirm('⚠️ WARNING: Are you sure you want to permanently delete the ' + checked + ' selected ticket(s)?\n\nThis action CANNOT be undone. All messages within these tickets will also be deleted forever.')) {
                            e.preventDefault();
                        }
                    });
                }

                // ==========================================
                // TAB SWITCHING LOGIC
                // ==========================================
                function switchTab(tabName) {
                    // Hide all tabs
                    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
                    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('block'));
                    
                    // Reset button styles
                    document.querySelectorAll('.tab-btn').forEach(el => {
                        el.classList.remove('border-[#0a6e2d]', 'text-[#0a6e2d]');
                        el.classList.add('border-transparent', 'text-gray-500');
                    });

                    // Show selected tab
                    document.getElementById('tab-' + tabName).classList.remove('hidden');
                    document.getElementById('tab-' + tabName).classList.add('block');

                    // Highlight selected button
                    const activeBtn = document.getElementById('tab-' + tabName + '-btn');
                    activeBtn.classList.remove('border-transparent', 'text-gray-500');
                    activeBtn.classList.add('border-[#0a6e2d]', 'text-[#0a6e2d]');
                }

                // Auto-open FAQ tab if editing
                document.addEventListener('DOMContentLoaded', () => {
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('edit_faq')) {
                        switchTab('faqs');
                    }
                });
            </script>
        </main>
    </div>
</body>
</html>