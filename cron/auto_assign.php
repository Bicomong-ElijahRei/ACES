<?php
/**
 * ACES Auto‑Assign Unregistered Students
 * 
 * Run manually by visiting this page, or schedule via Windows Task Scheduler:
 *   curl http://localhost/cair-system/cron/auto_assign.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/send_email.php';

// --- Configuration ---
$window_hours = 12;   // auto‑assign students for sessions starting within this many hours

$total_assigned = 0;
$errors = [];

header('Content-Type: text/plain');
echo "ACES Auto‑Assign – " . date('Y-m-d H:i:s') . "\n\n";

// 1. Find sessions whose start time falls within the next $window_hours
$stmt_sessions = $pdo->prepare("
    SELECT s.session_id, s.title, s.date, s.start_time
    FROM sessions s
    WHERE s.is_deleted = 0
      AND CONCAT(s.date, ' ', s.start_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)
    ORDER BY s.date ASC, s.start_time ASC
");
$stmt_sessions->execute([$window_hours]);
$sessions = $stmt_sessions->fetchAll();

if (empty($sessions)) {
    echo "No upcoming sessions within $window_hours hours.\n";
    exit;
}

foreach ($sessions as $session) {
    $session_id = $session['session_id'];
    echo "Session: {$session['title']} ({$session['date']} {$session['start_time']})\n";

    // 2. Get all subtopics for this session with their capacities and how many are already registered
    $stmt_subtopics = $pdo->prepare("
        SELECT sub.subtopic_id, sub.title, sub.capacity,
               COALESCE(reg_counts.registered_count, 0) AS registered_count,
               (sub.capacity - COALESCE(reg_counts.registered_count, 0)) AS remaining
        FROM subtopics sub
        LEFT JOIN (
            SELECT subtopic_id, COUNT(*) AS registered_count
            FROM registrations
            WHERE status IN ('assigned','auto_assigned')
            GROUP BY subtopic_id
        ) reg_counts ON sub.subtopic_id = reg_counts.subtopic_id
        WHERE sub.session_id = ? AND sub.is_deleted = 0
        ORDER BY remaining DESC, sub.title ASC
    ");
    $stmt_subtopics->execute([$session_id]);
    $subtopics = $stmt_subtopics->fetchAll();

    if (empty($subtopics)) {
        echo "  No subtopics found.\n";
        continue;
    }

    // 3. Find students who have ZERO registrations for ANY subtopic of this session
    $stmt_unregistered = $pdo->prepare("
        SELECT s.student_id, s.program, s.section, u.email, u.full_name
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.is_deleted = 0
          AND s.student_id NOT IN (
              SELECT DISTINCT r.student_id
              FROM registrations r
              JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
              WHERE sub.session_id = ?
          )
        ORDER BY s.program, s.section, u.full_name
    ");
    $stmt_unregistered->execute([$session_id]);
    $unregistered_students = $stmt_unregistered->fetchAll();

    if (empty($unregistered_students)) {
        echo "  All students already registered for this session.\n";
        continue;
    }
    echo "  Found " . count($unregistered_students) . " unregistered student(s).\n";

    // 4. For each unregistered student, pick the subtopic with the most remaining capacity
    //    (respecting section‐specific capacity limits if defined)
    foreach ($unregistered_students as $student) {
        $student_id = $student['student_id'];
        $assigned_subtopic = null;

        // Re‑calculate remaining capacity for each subtopic (could have changed after previous assignments)
        $stmt_remaining = $pdo->prepare("
            SELECT sub.subtopic_id, sub.title, sub.capacity,
                   COALESCE(reg_counts.registered_count, 0) AS registered_count,
                   (sub.capacity - COALESCE(reg_counts.registered_count, 0)) AS remaining
            FROM subtopics sub
            LEFT JOIN (
                SELECT subtopic_id, COUNT(*) AS registered_count
                FROM registrations
                WHERE status IN ('assigned','auto_assigned')
                GROUP BY subtopic_id
            ) reg_counts ON sub.subtopic_id = reg_counts.subtopic_id
            WHERE sub.session_id = ? AND sub.is_deleted = 0
            ORDER BY remaining DESC
            LIMIT 1
        ");
        $stmt_remaining->execute([$session_id]);
        $best_subtopic = $stmt_remaining->fetch();

        if ($best_subtopic && $best_subtopic['remaining'] > 0) {
            // Insert auto‑assigned registration
            $stmt_insert = $pdo->prepare("
                INSERT INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
                VALUES (?, ?, ?, 'auto_assigned', NOW())
            ");
            $stmt_insert->execute([$student_id, $session_id, $best_subtopic['subtopic_id']]);
            $total_assigned++;
            echo "    ✓ {$student['full_name']} ({$student_id}) → {$best_subtopic['title']} (remaining: {$best_subtopic['remaining']})\n";

            // Send notification email
            $subject = "Auto‑assigned: {$best_subtopic['title']} – {$session['title']}";
            $body = "
                <p>Hi {$student['full_name']},</p>
                <p>You were automatically registered for <strong>{$best_subtopic['title']}</strong> (Session: {$session['title']}) because you hadn't selected a subtopic before the deadline.</p>
                <p>Date: {$session['date']}<br>Time: {$session['start_time']}</p>
                <p>Please log in to ACES for more details.</p>
            ";
            try {
                sendEmail($student['email'], $subject, $body);
            } catch (Exception $e) {
                $errors[] = "Email failed for {$student['email']}: " . $e->getMessage();
            }
        } else {
            echo "    ✗ {$student['full_name']} ({$student_id}) – all subtopics are full.\n";
            $errors[] = "Could not assign {$student['full_name']} ({$student_id}) to session {$session['title']} – all subtopics full.";
        }
    }
}

echo "\n---\nTotal auto‑assigned: $total_assigned\n";
if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $e) {
        echo "  $e\n";
    }
}