<?php
require_once '../config/env.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php'; // Added for secure POST requests
redirectIfNotStaff();

$success_msg = '';
$error_msg = '';

// Handle Permanent Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tickets') {
    csrf_verify(); // Prevent CSRF attacks

    if (!empty($_POST['ticket_ids']) && is_array($_POST['ticket_ids'])) {
        $ids = array_map('intval', $_POST['ticket_ids']);
        $inQuery = implode(',', array_fill(0, count($ids), '?'));

        try {
            $pdo->beginTransaction();
            // Delete tickets. (Ticket messages will be deleted automatically due to ON DELETE CASCADE)
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

// Fetch All Tickets
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS student_name 
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
    <title>Student Tickets | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-6 overflow-y-auto">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Student Support Tickets</h1>

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
            
            <!-- ACTIVE TICKETS -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-8">
                <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                    <h2 class="font-bold text-gray-800"><i class="fas fa-inbox text-blue-500 mr-2"></i>Active Tickets</h2>
                </div>
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                            <th class="p-4 font-bold border-b">Ticket ID</th>
                            <th class="p-4 font-bold border-b">Student</th>
                            <th class="p-4 font-bold border-b">Subject</th>
                            <th class="p-4 font-bold border-b">Category</th>
                            <th class="p-4 font-bold border-b">Status</th>
                            <th class="p-4 font-bold border-b">Date</th>
                            <th class="p-4 font-bold border-b text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-700">
                        <?php if (empty($active_tickets)): ?>
                            <tr><td colspan="7" class="p-6 text-center text-gray-500">No active tickets. Great job!</td></tr>
                        <?php else: ?>
                            <?php foreach ($active_tickets as $t): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-mono text-xs">#<?= $t['ticket_id'] ?></td>
                                    <td class="p-4 font-bold"><?= htmlspecialchars($t['student_name']) ?></td>
                                    <td class="p-4"><?= htmlspecialchars($t['subject']) ?></td>
                                    <td class="p-4 text-xs font-semibold text-gray-500 uppercase"><?= $t['category'] ?></td>
                                    <td class="p-4">
                                        <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase <?= $t['status'] === 'open' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                                            <?= str_replace('_', ' ', $t['status']) ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-xs text-gray-500"><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                                    <td class="p-4 text-right">
                                        <a href="ticket.php?id=<?= $t['ticket_id'] ?>" class="bg-[#0a6e2d] text-white px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-[#054018]">View Thread</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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
                    <!-- Form for Multi-Select Deletion -->
                    <form id="bulkDeleteForm" method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_tickets">
                        
                        <!-- Action Bar -->
                        <div class="bg-gray-100 px-6 py-3 border-b border-gray-200 flex justify-between items-center">
                            <span class="text-xs text-gray-500 font-semibold"><i class="fas fa-info-circle mr-1"></i> Select tickets to permanently delete.</span>
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-xs font-bold transition-colors shadow-sm flex items-center gap-2">
                                <i class="fas fa-trash-alt"></i> Delete Selected
                            </button>
                        </div>

                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                                    <th class="p-4 border-b w-10 text-center">
                                        <input type="checkbox" id="selectAllArchive" class="rounded border-gray-300 text-red-600 focus:ring-red-500 cursor-pointer w-4 h-4">
                                    </th>
                                    <th class="p-4 font-bold border-b">Ticket ID</th>
                                    <th class="p-4 font-bold border-b">Student</th>
                                    <th class="p-4 font-bold border-b">Subject</th>
                                    <th class="p-4 font-bold border-b">Status</th>
                                    <th class="p-4 font-bold border-b">Date</th>
                                    <th class="p-4 font-bold border-b text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm text-gray-700">
                                <?php foreach ($archived_tickets as $t): ?>
                                    <tr class="hover:bg-gray-50 opacity-75">
                                        <td class="p-4 text-center">
                                            <input type="checkbox" name="ticket_ids[]" value="<?= $t['ticket_id'] ?>" class="ticket-checkbox rounded border-gray-300 text-red-600 focus:ring-red-500 cursor-pointer w-4 h-4">
                                        </td>
                                        <td class="p-4 font-mono text-xs">#<?= $t['ticket_id'] ?></td>
                                        <td class="p-4 font-bold"><?= htmlspecialchars($t['student_name']) ?></td>
                                        <td class="p-4"><?= htmlspecialchars($t['subject']) ?></td>
                                        <td class="p-4">
                                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase <?= $t['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= $t['status'] === 'resolved' ? 'Resolved' : 'Abolished' ?>
                                            </span>
                                        </td>
                                        <td class="p-4 text-xs text-gray-500"><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                                        <td class="p-4 text-right">
                                            <a href="ticket.php?id=<?= $t['ticket_id'] ?>" class="bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-gray-200">View Thread</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                    <?php else: ?>
                        <div class="p-6 text-center text-gray-500">No archived tickets.</div>
                    <?php endif; ?>
                </div>
            </div>

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
                const bulkForm = document.getElementById('bulkDeleteForm');
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
            </script>
        </main>
    </div>
</body>
</html>