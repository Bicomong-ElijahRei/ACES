<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();
csrf_verify();

$subtopic_id = (int)$_POST['subtopic_id'];
$pin = trim($_POST['pin']);

$stmt = $pdo->prepare("UPDATE subtopics SET proctor_pin = ? WHERE subtopic_id = ?");
$stmt->execute([$pin, $subtopic_id]);

echo json_encode(['success' => true]);