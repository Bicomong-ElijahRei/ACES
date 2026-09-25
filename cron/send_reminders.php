<?php
/**
 * ACES Automated Reminder Script
 * 
 * Run manually by visiting this page, or set up a cron job
 * to ping this URL every hour.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/send_email.php';

// Fetch subtopics starting within the next 24 hours
$stmt = $pdo->prepare("
    SELECT sub.subtopic_id, sub.title, sub.description, sub.subtopic_date, 
           sub.subtopic_start_time, sub.subtopic_end_time,
           sub.subtopic_location, sub.subtopic_proctor,
           s.title AS session_title, s.date AS session_date, s.start_time, s.end_time
    FROM subtopics sub
    JOIN sessions s ON sub.session_id = s.session_id
    WHERE sub.is_deleted = 0 AND s.is_deleted = 0
      AND (
        (sub.subtopic_date IS NOT NULL 
         AND CONCAT(sub.subtopic_date, ' ', COALESCE(sub.subtopic_start_time, s.start_time))
             BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR))
        OR
        (sub.subtopic_date IS NULL 
         AND CONCAT(s.date, ' ', s.start_time)
             BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR))
      )
    ORDER BY sub.subtopic_date ASC, sub.subtopic_start_time ASC
");
$stmt->execute();
$subtopics = $stmt->fetchAll();

$total_sent = 0;
$errors = [];

header('Content-Type: text/plain');

if (empty($subtopics)) {
    echo "No upcoming subtopics within the next 24 hours.\n";
    exit;
}

foreach ($subtopics as $sub) {
    $subtopic_id = $sub['subtopic_id'];

    // Fetch registered students who haven't received an auto_reminder for this subtopic
    $stmt = $pdo->prepare("
        SELECT u.email, u.full_name, s.student_id
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN reminders_sent rs ON rs.subtopic_id = r.subtopic_id
                                    AND rs.student_id = r.student_id
                                    AND rs.type = 'auto_reminder'
        WHERE r.subtopic_id = ? AND rs.id IS NULL
    ");
    $stmt->execute([$subtopic_id]);
    $students = $stmt->fetchAll();

    if (empty($students)) {
        echo "Subtopic {$sub['title']}: No students to remind (already notified or no registrants).\n";
        continue;
    }

    $date = $sub['subtopic_date'] ?: $sub['session_date'];
    $start = $sub['subtopic_start_time'] ?: $sub['start_time'];
    $end   = $sub['subtopic_end_time']   ?: $sub['end_time'];

    $subject = "Reminder: {$sub['title']} – {$sub['session_title']}";
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px;'>
            <h2 style='color:#0a6e2d;'>ACES Session Reminder</h2>
            <p>Dear student,</p>
            <p>This is a friendly reminder for the upcoming subtopic you registered for:</p>
            <table style='width:100%; border-collapse:collapse; margin:15px 0'>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Subtopic</td><td colspan='2' style='padding:8px;'>{$sub['title']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Session</td><td colspan='2' style='padding:8px;'>{$sub['session_title']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Date</td><td colspan='2' style='padding:8px;'>" . date('M d, Y', strtotime($date)) . "</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Time</td><td colspan='2' style='padding:8px;'>{$start} – {$end}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Location</td><td colspan='2' style='padding:8px;'>{$sub['subtopic_location']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Proctor</td><td colspan='2' style='padding:8px;'>{$sub['subtopic_proctor']}</td></tr>
                <tr><td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Details</td><td colspan='2' style='padding:8px;'>{$sub['description']}</td></tr>
            </table>
            <p>Please be on time and bring any required materials.</p>
            <p>Log in to ACES for more details.</p>
            <p style='font-size:0.8em; color:#666;'>This is an automated reminder from ACES Unit, KLD.</p>
        </div>
    ";

    foreach ($students as $student) {
        $result = sendEmail($student['email'], $subject, $body);
        if ($result['success']) {
            $stmt_log = $pdo->prepare("INSERT INTO reminders_sent (subtopic_id, student_id, type) VALUES (?, ?, 'auto_reminder')");
            $stmt_log->execute([$subtopic_id, $student['student_id']]);
            $total_sent++;
            echo "Sent to {$student['email']} for subtopic {$sub['title']}\n";
        } else {
            $errors[] = $student['email'] . ': ' . $result['message'];
            echo "ERROR to {$student['email']}: {$result['message']}\n";
        }
    }
}

echo "\n---\nTotal sent: $total_sent\n";
if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $e) {
        echo "  $e\n";
    }
}