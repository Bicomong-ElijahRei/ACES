<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

// Block Viewers from reading edit data (defense in depth)
if (isViewer()) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Module ID required']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM modules WHERE module_id = ? AND is_deleted = 0");
$stmt->execute([$id]);
$module = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');

if (!$module) {
    http_response_code(404);
    echo json_encode(['error' => 'Module not found']);
    exit;
}

echo json_encode($module);