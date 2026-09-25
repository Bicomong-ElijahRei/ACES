<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();
if (isViewer()) die('Access denied');

$removed = 0;

// Get all active subtopics
$subtopics = $pdo->query("SELECT subtopic_id, capacity FROM subtopics WHERE is_deleted = 0")->fetchAll();

foreach ($subtopics as $sub) {
    $subtopic_id = $sub['subtopic_id'];
    $capacity    = (int)$sub['capacity'];

    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE subtopic_id = ? AND status IN ('assigned','auto_assigned','pending')");
    $stmt_count->execute([$subtopic_id]);
    $current = (int)$stmt_count->fetchColumn();

    $excess = $current - $capacity;
    if ($excess <= 0) continue;

    // Delete the newest EXCESS registrations – ANY status
    $stmt_excess = $pdo->prepare("
        SELECT registration_id FROM registrations
        WHERE subtopic_id = ?
        ORDER BY registration_date DESC, registration_id DESC
        LIMIT " . (int)$excess
    );
    $stmt_excess->execute([$subtopic_id]);
    $ids = $stmt_excess->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_del = $pdo->prepare("DELETE FROM registrations WHERE registration_id IN ($placeholders)");
        $stmt_del->execute($ids);
        $removed += count($ids);
    }
}

header("Location: dashboard.php?msg=purged&count=$removed");
exit;