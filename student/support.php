<?php
require_once '../config/env.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
redirectIfNotStudent();

$student_id = $_SESSION['student_id'];

// Create Ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    csrf_verify();
    $subject = trim($_POST['subject']);
    $category = $_POST['category'];
    $message = trim($_POST['message']);
    
    if ($subject && $message) {
        try {
            $stmt = $pdo->prepare("INSERT INTO tickets (student_id, subject, category) VALUES (?, ?, ?)");
            $stmt->execute([$student_id, $subject, $category]);
            $ticket_id = $pdo->lastInsertId();
            
            $stmt_msg = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)");
            $stmt_msg->execute([$ticket_id, $_SESSION['user_id'], $message]);
            
            header("Location: ticket.php?id=$ticket_id");
            exit;
        } catch (PDOException $e) {
            $error = "Failed to create ticket. Please try again.";
        }
    } else {
        $error = "Subject and message cannot be empty.";
    }
}

// Fetch My Tickets
$stmt = $pdo->prepare("SELECT * FROM tickets WHERE student_id = ? ORDER BY created_at DESC");
$stmt->execute([$student_id]);
$tickets = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Support | ACES Student</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/student_header.php'; ?>
        <main class="flex-1 p-6 overflow-y-auto">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">My Support Tickets</h1>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Create Ticket Form -->
                <div class="md:col-span-1 bg-white p-6 rounded-xl shadow-sm h-fit">
                    <h2 class="font-bold text-gray-800 mb-4 border-b pb-2"><i class="fas fa-plus-circle text-[#0a6e2d] mr-2"></i>Open New Ticket</h2>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Subject</label>
                            <input type="text" name="subject" required class="w-full border rounded-lg p-2 text-sm" placeholder="e.g. Cannot access module">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">Category</label>
                            <select name="category" class="w-full border rounded-lg p-2 text-sm">
                                <option value="bug">Bug / System Error</option>
                                <option value="attendance">Attendance Issue</option>
                                <option value="module">Module Issue</option>
                                <option value="other">Other Reason</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Reason / Message</label>
                            <textarea name="message" rows="4" required class="w-full border rounded-lg p-2 text-sm" placeholder="Explain your issue..."></textarea>
                        </div>
                        <button type="submit" class="w-full bg-[#0a6e2d] text-white font-bold py-2 rounded-lg">Submit Ticket</button>
                    </form>
                </div>

                <!-- Ticket List -->
                <div class="md:col-span-2 space-y-3">
                    <?php if (empty($tickets)): ?>
                        <div class="bg-white p-10 text-center rounded-xl text-gray-500">You have no support tickets.</div>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): ?>
                            <a href="ticket.php?id=<?= $t['ticket_id'] ?>" class="block bg-white p-4 rounded-xl shadow-sm border border-gray-200 hover:border-[#0a6e2d] transition">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($t['subject']) ?></h3>
                                        <p class="text-xs text-gray-500 mt-1">Opened on <?= date('M d, Y', strtotime($t['created_at'])) ?> • Category: <?= ucfirst($t['category']) ?></p>
                                    </div>
                                    <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase 
                                        <?= $t['status'] === 'open' ? 'bg-amber-100 text-amber-800' : ($t['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?>">
                                        <?= str_replace('_', ' ', $t['status']) ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>