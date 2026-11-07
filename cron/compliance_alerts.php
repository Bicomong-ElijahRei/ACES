<?php
/**
 * ACES Automated Compliance Alert Script
 *
 * Identifies students whose overall compliance (attendance + module
 * completion) is below 50% and emails them an alert. A per-student
 * cooldown (default 7 days) prevents spamming.
 *
 * Run manually by visiting this page, or set up a cron job to ping
 * this URL on a schedule (recommended: once per day).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/send_email.php';

// ---- Configuration ----
$cooldown_days = (int) aces_env('COMPLIANCE_ALERT_COOLDOWN_DAYS', 7);
if ($cooldown_days < 1) {
    $cooldown_days = 7;
}

$app_url = rtrim(aces_env('APP_URL', 'http://localhost/cair-system'), '/');

$total_sent = 0;
$total_skipped_cooldown = 0;
$total_not_at_risk = 0;
$errors = [];

header('Content-Type: text/plain');
echo "ACES Compliance Alerts – " . date('Y-m-d H:i:s') . "\n";
echo "Cooldown: {$cooldown_days} day(s)\n\n";

// ---- Ensure tracking table exists (idempotent) ----
$pdo->exec("
    CREATE TABLE IF NOT EXISTS compliance_alerts (
        alert_id     INT(11) NOT NULL AUTO_INCREMENT,
        student_id   VARCHAR(50) NOT NULL,
        progress_pct INT(11) NOT NULL,
        sent_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (alert_id),
        KEY idx_student (student_id),
        KEY idx_sent (sent_at),
        CONSTRAINT fk_compliance_alerts_student
            FOREIGN KEY (student_id) REFERENCES students (student_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// ---- Fetch every active student with their overall compliance ----
$sql = "
    SELECT
        s.student_id, s.program, s.section,
        u.email, u.full_name,
        (SELECT COUNT(*) FROM registrations r
            JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
            JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0
            WHERE r.student_id = s.student_id AND r.status IN ('assigned','auto_assigned')) AS sub_total,
        (SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a
            JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
            JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0
            JOIN registrations r ON r.subtopic_id = a.subtopic_id AND r.student_id = a.student_id AND r.status IN ('assigned','auto_assigned')
            WHERE a.student_id = s.student_id AND a.attendance_status = 'present' AND a.is_deleted = 0) AS sub_done,
        (SELECT COUNT(*) FROM registrations r
            JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
            JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0
            JOIN modules m ON m.subtopic_id = sub.subtopic_id AND m.is_deleted = 0
            WHERE r.student_id = s.student_id AND r.status IN ('assigned','auto_assigned')) AS mod_total,
        (SELECT COUNT(DISTINCT smp.module_id) FROM student_module_progress smp
            JOIN modules m ON smp.module_id = m.module_id AND m.is_deleted = 0
            JOIN subtopics sub ON m.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
            JOIN sessions sess ON sub.session_id = sess.session_id AND sess.is_deleted = 0
            JOIN registrations r ON r.subtopic_id = sub.subtopic_id AND r.status IN ('assigned','auto_assigned')
            WHERE smp.student_id = s.student_id AND smp.completed = 1 AND r.student_id = s.student_id) AS mod_done
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.is_deleted = 0 AND u.is_active = 1
";
$students = $pdo->query($sql)->fetchAll();

$stmt_cooldown = $pdo->prepare("
    SELECT MAX(sent_at) FROM compliance_alerts WHERE student_id = ?
");
$stmt_log = $pdo->prepare("
    INSERT INTO compliance_alerts (student_id, progress_pct) VALUES (?, ?)
");
foreach ($students as $st) {
    $student_id = $st['student_id'];
    $email      = $st['email'];
    $full_name  = $st['full_name'];

    $sub_total = (int) $st['sub_total'];
    $sub_done  = (int) $st['sub_done'];
    $mod_total = (int) $st['mod_total'];
    $mod_done  = (int) $st['mod_done'];

    $total = $sub_total + $mod_total;
    $done  = $sub_done + $mod_done;

    if ($total <= 0) {
        continue;
    }

    $progress_pct = (int) round(($done / $total) * 100);

    if ($progress_pct >= 50) {
        $total_not_at_risk++;
        continue;
    }

    $stmt_cooldown->execute([$student_id]);
    $last_sent = $stmt_cooldown->fetchColumn();
    if ($last_sent && strtotime($last_sent) > strtotime("-{$cooldown_days} days")) {
        $total_skipped_cooldown++;
        echo "SKIP (cooldown) {$email} — last alert {$last_sent}\n";
        continue;
    }

    if (empty($email)) {
        $errors[] = "No email for {$student_id}";
        echo "ERROR {$student_id}: no email address\n";
        continue;
    }

    $subject = "ACES Compliance Alert: Your progress is below 50%";
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color:#dc2626;'>ACES Compliance Alert</h2>
            <p>Dear " . htmlspecialchars($full_name) . ",</p>
            <p>Our records show that your overall ACES compliance is currently
               <strong style='color:#dc2626;'>{$progress_pct}%</strong>, which is below the required 50% threshold.</p>
            <p>Here is a breakdown of your progress:</p>
            <table style='width:100%; border-collapse:collapse; margin:15px 0;'>
                <tr>
                    <td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Program</td>
                    <td style='padding:8px;'>" . htmlspecialchars($st['program']) . "</td>
                </tr>
                <tr>
                    <td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Section</td>
                    <td style='padding:8px;'>" . htmlspecialchars($st['section']) . "</td>
                </tr>
                <tr>
                    <td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Attendance</td>
                    <td style='padding:8px;'>{$sub_done} / {$sub_total}</td>
                </tr>
                <tr>
                    <td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Modules Completed</td>
                    <td style='padding:8px;'>{$mod_done} / {$mod_total}</td>
                </tr>
                <tr>
                    <td style='padding:8px; background:#f0f0f0; font-weight:bold;'>Overall Compliance</td>
                    <td style='padding:8px;'><strong style='color:#dc2626;'>{$progress_pct}%</strong></td>
                </tr>
            </table>
            <p>Please attend your assigned sessions and complete your learning modules to improve your compliance.</p>
            <p>You can view your detailed progress here:
               <a href='{$app_url}/student/progress.php' style='color:#0a6e2d; font-weight:bold;'>View My Progress</a></p>
            <p>If you believe this is an error, please contact the ACES Unit.</p>
            <p style='font-size:0.8em; color:#666;'>This is an automated compliance alert from ACES Unit - KLD.</p>
        </div>
    ";

    $result = sendEmail($email, $subject, $body);
    if ($result['success']) {
        $stmt_log->execute([$student_id, $progress_pct]);
        $total_sent++;
        echo "SENT to {$email} ({$progress_pct}%)\n";
    } else {
        $errors[] = $email . ': ' . $result['message'];
        echo "ERROR to {$email}: {$result['message']}\n";
    }
}

echo "\n---\n";
echo "Total sent: {$total_sent}\n";
echo "Skipped (cooldown): {$total_skipped_cooldown}\n";
echo "Not at risk: {$total_not_at_risk}\n";
if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $e) {
        echo "  {$e}\n";
    }
}

