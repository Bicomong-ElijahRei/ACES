<?php
require_once '../config/env.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
redirectIfNotStaff();

$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];

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
        
        $pdo->prepare("UPDATE tickets SET status = 'in_progress' WHERE ticket_id = ? AND status = 'open'")->execute([$ticket_id]);
        
        header("Location: ticket.php?id=$ticket_id");
        exit;
    }
}

// Handle Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_status') {
    csrf_verify();
    $new_status = $_POST['status'];
    
    // Whitelist validation to prevent database errors
    $allowed_statuses = ['open', 'in_progress', 'resolved', 'closed'];
    if (in_array($new_status, $allowed_statuses)) {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ? WHERE ticket_id = ?");
        $stmt->execute([$new_status, $ticket_id]);
    }
    
    header("Location: ticket.php?id=$ticket_id");
    exit;
}

// Fetch Ticket Details
$stmt = $pdo->prepare("SELECT t.*, u.full_name AS student_name, s.section, s.program FROM tickets t JOIN students s ON t.student_id = s.student_id JOIN users u ON s.user_id = u.user_id WHERE t.ticket_id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    die("Ticket not found.");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?= $ticket_id ?> | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">
    <?php include '../includes/staff_sidebar.php'; ?>
    
    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-6 overflow-y-auto">
            
            <!-- Header -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider <?= $ticket['status'] === 'open' ? 'bg-amber-100 text-amber-800' : ($ticket['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?>">
                            <?= str_replace('_', ' ', strtoupper($ticket['status'])) ?>
                        </span>
                        <span class="text-sm text-gray-500">Category: <?= ucfirst($ticket['category']) ?></span>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($ticket['subject']) ?></h1>
                    <p class="text-sm text-gray-500 mt-1">Reported by: <strong><?= htmlspecialchars($ticket['student_name']) ?></strong> (<?= htmlspecialchars($ticket['program']) ?> - <?= htmlspecialchars($ticket['section']) ?>) on <?= date('M d, Y g:i A', strtotime($ticket['created_at'])) ?></p>
                </div>
                <div class="flex flex-col items-start md:items-end gap-2 w-full md:w-auto">
                    <a href="support.php" class="text-sm text-[#0a6e2d] font-bold hover:underline mb-2"><i class="fas fa-arrow-left mr-1"></i> Back to List</a>
                    
                    <form method="POST" class="w-full md:w-auto">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_status">
                        <select name="status" onchange="this.form.submit()" class="w-full md:w-auto border border-gray-300 rounded-lg p-2 text-sm font-semibold bg-white shadow-sm">
                            <option value="open" <?= $ticket['status']=='open'?'selected':'' ?>>Mark as Open</option>
                            <option value="in_progress" <?= $ticket['status']=='in_progress'?'selected':'' ?>>Mark as In Progress</option>
                            <option value="resolved" <?= $ticket['status']=='resolved'?'selected':'' ?>>Mark as Resolved</option>
                            <option value="closed" <?= $ticket['status']=='closed'?'selected':'' ?>>Abolish (Close)</option>
                        </select>
                    </form>
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
                        <div class="max-w-[90%] md:max-w-[75%] break-words <?= $bubble_class ?> p-4 rounded-xl shadow-sm">
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
            <!-- CHANGED TO GRID: Guarantees the button stays small on PC -->
            <form method="POST" enctype="multipart/form-data" class="bg-white rounded-xl shadow-sm p-4 w-full grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3 md:items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reply">
                
                <div class="w-full min-w-0">
                    <textarea name="message" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" placeholder="Type your reply here..." required></textarea>
                    <div class="mt-2 text-xs text-gray-500">
                        <input type="file" name="chat_image" accept="image/*" class="text-xs">
                    </div>
                </div>
                
                <!-- Button is now its own grid column, it will NEVER stretch past its content -->
                <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-8 py-3 rounded-lg font-bold text-sm h-[46px] w-full md:w-auto">
                    <i class="fas fa-paper-plane mr-1"></i> Send
                </button>
            </form>
            <?php else: ?>
                <div class="bg-gray-100 text-center p-6 rounded-lg flex flex-col items-center justify-center gap-3">
                    <div class="text-gray-500 font-semibold">
                        <i class="fas fa-lock mr-2"></i> This ticket is marked as <strong class="<?= $ticket['status'] === 'resolved' ? 'text-green-600' : 'text-red-600' ?>"><?= strtoupper($ticket['status']) ?></strong>. Chat is disabled.
                    </div>
                    
                    <!-- NEW: RETRIEVE BUTTON -->
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_status">
                        <input type="hidden" name="status" value="in_progress">
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-5 py-2 rounded-lg text-sm font-bold shadow-sm transition">
                            <i class="fas fa-sync-alt mr-1"></i> Retrieve / Re-open Ticket
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
        </main>
    </div>
</body>
</html>