<?php
require_once '../config/env.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

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
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                                <th class="p-4 font-bold border-b">Ticket ID</th>
                                <th class="p-4 font-bold border-b">Student</th>
                                <th class="p-4 font-bold border-b">Subject</th>
                                <th class="p-4 font-bold border-b">Status</th>
                                <th class="p-4 font-bold border-b">Date</th>
                                <th class="p-4 font-bold border-b text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm text-gray-700">
                            <?php if (empty($archived_tickets)): ?>
                                <tr><td colspan="6" class="p-6 text-center text-gray-500">No archived tickets.</td></tr>
                            <?php else: ?>
                                <?php foreach ($archived_tickets as $t): ?>
                                    <tr class="hover:bg-gray-50 opacity-75">
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
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
                document.getElementById('archiveToggle').addEventListener('click', function() {
                    document.getElementById('archiveContent').classList.toggle('hidden');
                    this.querySelector('.fa-chevron-down').classList.toggle('rotate-180');
                });
            </script>
        </main>
    </div>
</body>
</html>