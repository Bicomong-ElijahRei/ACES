<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM modules WHERE module_id = ?");
$stmt->execute([$id]);
$module = $stmt->fetch(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($module);