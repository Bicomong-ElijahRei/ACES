<?php
require_once '../config/env.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
redirectIfNotStudent();

$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$student_id = $_SESSION['student_id'];

// Handle Reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    csrf_verify();
    $message = trim($_POST['message']);
    
    if ($message) {
        $image_path = null;
        if (isset($_FILES['chat_image']) && $_FILES['chat_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['chat_image']['name'], PATHINFO_EXTENSION));
            $file_size = $_FILES['chat_image']['size'];
            
            // Check extension AND limit size to 2MB (2097152 bytes)
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && $file_size <= 2097152) {
                $filename = uniqid('tkt_') . '.' . $ext;
                $upload_dir = __DIR__ . '/../assets/images/tickets/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                if (move_uploaded_file($_FILES['chat_image']['tmp_name'], $upload_dir . $filename)) {
                    $image_path = 'assets/images/tickets/' . $filename;
                }
            }
        }

        $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, image_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$ticket_id, $user_id, $message, $image_path]);
        
        header("Location: ticket.php?id=$ticket_id");
        exit;
    }
}

// Fetch Ticket Details
$stmt = $pdo->prepare("SELECT t.*, u.full_name AS student_name, s.section, s.program FROM tickets t JOIN students s ON t.student_id = s.student_id JOIN users u ON s.user_id = u.user_id WHERE t.ticket_id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

if (!$ticket || $ticket['student_id'] !== $student_id) {
    die("Ticket not found or access denied.");
}

// Fetch Messages
$stmt_msgs = $pdo->prepare("SELECT tm.*, u.full_name, u.role FROM ticket_messages tm JOIN users u ON tm.sender_id = u.user_id WHERE tm.ticket_id = ? ORDER BY tm.created_at ASC");
$stmt_msgs->execute([$ticket_id]);
$messages = $stmt_msgs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Ticket #<?= $ticket_id ?> | ACES Student</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
    <?php include '../includes/student_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/student_header.php'; ?>
        <main class="flex-1 p-6 overflow-y-auto">
            
            <!-- Header -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6 flex justify-between items-center">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider <?= $ticket['status'] === 'open' ? 'bg-amber-100 text-amber-800' : ($ticket['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?>">
                            <?= str_replace('_', ' ', strtoupper($ticket['status'])) ?>
                        </span>
                        <span class="text-sm text-gray-500">Category: <?= ucfirst($ticket['category']) ?></span>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($ticket['subject']) ?></h1>
                    <p class="text-sm text-gray-500 mt-1">Opened on <?= date('M d, Y g:i A', strtotime($ticket['created_at'])) ?></p>
                </div>
                <div class="flex flex-col gap-2">
                    <a href="support.php" class="text-sm text-[#0a6e2d] font-bold hover:underline"><i class="fas fa-arrow-left mr-1"></i> Back to List</a>
                </div>
            </div>

            <!-- Chat Thread -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6 min-h-[400px] max-h-[600px] overflow-y-auto flex flex-col gap-4">
                <?php if (empty($messages)): ?>
                    <p class="text-center text-gray-400 py-10">No messages yet.</p>
                <?php else: ?>
                    <?php foreach ($messages as $msg): 
                        $is_me = ($msg['sender_id'] == $user_id);
                        $bubble_class = $is_me ? 'bg-[#0a6e2d] text-white ml-auto' : 'bg-gray-100 text-gray-800 mr-auto';
                    ?>
                        <div class="max-w-[70%] <?= $bubble_class ?> p-4 rounded-xl shadow-sm">
                            <div class="flex justify-between items-baseline gap-4 mb-1">
                                <span class="text-xs font-bold opacity-75"><?= htmlspecialchars($msg['full_name']) ?> (<?= ucfirst($msg['role']) ?>)</span>
                                <span class="text-[10px] opacity-75"><?= date('M d, g:i A', strtotime($msg['created_at'])) ?></span>
                            </div>
                            <p class="text-sm whitespace-pre-wrap"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                            <?php if ($msg['image_path']): ?>
                                <img src="../<?= htmlspecialchars($msg['image_path']) ?>" class="mt-3 rounded-lg max-h-48 object-cover border border-white/20 cursor-pointer" onclick="window.open(this.src)">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Reply Box -->
            <?php if ($ticket['status'] === 'open' || $ticket['status'] === 'in_progress'): ?>
            <form method="POST" enctype="multipart/form-data" class="bg-white rounded-xl shadow-sm p-4 flex gap-3 items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reply">
                
                <div class="flex-1">
                    <textarea name="message" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="Type your reply here..." required></textarea>
                    <div class="mt-2 text-xs text-gray-500">
                        <input type="file" name="chat_image" accept="image/*" class="text-xs">
                    </div>
                </div>
                
                <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-3 rounded-lg font-bold text-sm h-[46px] mb-[22px]">
                    <i class="fas fa-paper-plane mr-1"></i> Send
                </button>
            </form>
            <?php else: ?>
                <div class="bg-gray-100 text-center p-4 rounded-lg text-gray-500 font-semibold">
                    <i class="fas fa-lock mr-2"></i> This ticket is currently <strong><?= str_replace('_', ' ', strtoupper($ticket['status'])) ?></strong> and cannot receive new messages.
                </div>
            <?php endif; ?>
            
        </main>
    </div>
</body>
</html>