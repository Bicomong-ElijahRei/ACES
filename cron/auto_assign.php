<?php
/**
 * ACES Auto-Assign Unregistered Students
 * Batched, section-aware, transaction-safe.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/send_email.php';

$window_hours = 12;

$total_assigned = 0;
$errors = [];

header('Content-Type: text/plain');
echo "ACES Auto-Assign – " . date('Y-m-d H:i:s') . "\n\n";

// 1. Find sessions in the window
$stmt_sessions = $pdo->prepare("
    SELECT s.session_id, s.title, s.date, s.start_time, s.allow_multiple
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

    // 2. Get subtopics with section capacities pre-loaded
    $stmt_subtopics = $pdo->prepare("
        SELECT sub.subtopic_id, sub.title, sub.capacity,
               sub.visible_courses, sub.visible_for, sub.required_courses, sub.required_for
        FROM subtopics sub
        WHERE sub.session_id = ? AND sub.is_deleted = 0
        ORDER BY sub.capacity DESC
    ");
    $stmt_subtopics->execute([$session_id]);
    $subtopics = $stmt_subtopics->fetchAll();

    if (empty($subtopics)) {
        echo "  No subtopics found.\n";
        continue;
    }

    // 3. Pre-load per-section capacities
    $stmt_sec_caps = $pdo->prepare("
        SELECT subtopic_id, section, capacity 
        FROM subtopic_section_capacity 
        WHERE subtopic_id IN (" . implode(',', array_fill(0, count($subtopics), '?')) . ")
    ");
    $stmt_sec_caps->execute(array_column($subtopics, 'subtopic_id'));
    $section_caps = [];
    while ($row = $stmt_sec_caps->fetch()) {
        $section_caps[$row['subtopic_id']][$row['section']] = (int)$row['capacity'];
    }

    // 4. Pre-load current counts (global + per section)
    $stmt_counts = $pdo->prepare("
        SELECT r.subtopic_id, s.program, s.section, COUNT(*) AS cnt
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        WHERE r.subtopic_id IN (" . implode(',', array_fill(0, count($subtopics), '?')) . ")
          AND r.status IN ('assigned','auto_assigned')
        GROUP BY r.subtopic_id, s.program, s.section
    ");
    $stmt_counts->execute(array_column($subtopics, 'subtopic_id'));
    $global_counts = [];
    $section_counts = [];
    while ($row = $stmt_counts->fetch()) {
        $global_counts[$row['subtopic_id']] = ($global_counts[$row['subtopic_id']] ?? 0) + (int)$row['cnt'];
        $key = $row['program'] . '-' . $row['section'];
        $section_counts[$row['subtopic_id']][$key] = (int)$row['cnt'];
    }

    // 5. Find unregistered students (batch)
    $stmt_unregistered = $pdo->prepare("
        SELECT s.student_id, s.program, s.section, u.email, u.full_name
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.is_deleted = 0
          AND s.student_id NOT IN (
              SELECT DISTINCT r.student_id
              FROM registrations r
              JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id AND sub.is_deleted = 0
              WHERE sub.session_id = ? AND r.status IN ('assigned','auto_assigned')
          )
        ORDER BY s.program, s.section, u.full_name
    ");
    $stmt_unregistered->execute([$session_id]);
    $unregistered = $stmt_unregistered->fetchAll();

    if (empty($unregistered)) {
        echo "  All students already registered for this session.\n";
        continue;
    }
    echo "  Found " . count($unregistered) . " unregistered student(s).\n";

    // 6. Batch assign — track assignments in memory, do bulk INSERTs at end
    $to_register = [];
    $unassignable = [];

    foreach ($unregistered as $student) {
        $student_section_key = $student['program'] . '-' . $student['section'];
        $best_sub = null;

        foreach ($subtopics as $sub) {
            $sub_id = $sub['subtopic_id'];

            // Determine the effective capacity for this student's section
            $has_sec_cap = isset($section_caps[$sub_id][$student_section_key]);
            $cap = $has_sec_cap ? $section_caps[$sub_id][$student_section_key] : (int)$sub['capacity'];
            if ($cap <= 0) continue;

            // Check section count
            $used = $has_sec_cap 
                ? ($section_counts[$sub_id][$student_section_key] ?? 0) 
                : ($global_counts[$sub_id] ?? 0);
            
            if ($used >= $cap) continue;

            // Check visibility (AND logic)
            $vis_courses  = json_decode($sub['visible_courses'] ?? '[]', true) ?: [];
            $vis_sections = json_decode($sub['visible_for'] ?? '[]', true) ?: [];
            $req_courses  = json_decode($sub['required_courses'] ?? '[]', true) ?: [];
            $req_sections = json_decode($sub['required_for'] ?? '[]', true) ?: [];
            
            $has_restrictions = !empty($vis_courses) || !empty($vis_sections) || !empty($req_courses) || !empty($req_sections);
            $is_visible = !$has_restrictions;
            
            if ($has_restrictions) {
                // AND logic
                $match_vis = true;
                if (!empty($vis_courses) && !in_array($student['program'], $vis_courses)) $match_vis = false;
                if (!empty($vis_sections) && !in_array($student['section'], $vis_sections)) $match_vis = false;
                
                $match_req = true;
                if (!empty($req_courses) && !in_array($student['program'], $req_courses)) $match_req = false;
                if (!empty($req_sections) && !in_array($student['section'], $req_sections)) $match_req = false;
                
                $is_visible = $match_vis || $match_req;
            }

            if (!$is_visible) continue;

            if (!$best_sub || $used < $best_sub['used']) {
                $best_sub = ['sub_id' => $sub_id, 'title' => $sub['title'], 'used' => $used, 'cap' => $cap];
            }
        }

        if ($best_sub) {
            $to_register[] = [
                'student_id' => $student['student_id'],
                'subtopic_id' => $best_sub['sub_id'],
                'email' => $student['email'],
                'full_name' => $student['full_name'],
                'subtopic_title' => $best_sub['title'],
            ];
            // Update counters so next student sees the new remaining
            $global_counts[$best_sub['sub_id']] = ($global_counts[$best_sub['sub_id']] ?? 0) + 1;
            $section_counts[$best_sub['sub_id']][$student_section_key] = 
                ($section_counts[$best_sub['sub_id']][$student_section_key] ?? 0) + 1;
        } else {
            $unassignable[] = $student;
        }
    }

    // 7. Bulk insert registrations + attendance
    if (!empty($to_register)) {
        try {
            $pdo->beginTransaction();

            // Batch registrations
            $reg_values = [];
            $reg_params = [];
            foreach ($to_register as $r) {
                $reg_values[] = "(?, ?, ?, 'auto_assigned', NOW())";
                array_push($reg_params, $r['student_id'], $session_id, $r['subtopic_id']);
            }
            $pdo->prepare("
                INSERT IGNORE INTO registrations (student_id, session_id, subtopic_id, status, registration_date)
                VALUES " . implode(',', $reg_values)
            )->execute($reg_params);

            // Batch attendance (pending) — INSERT IGNORE now works because of the UNIQUE constraint
            $att_values = [];
            $att_params = [];
            foreach ($to_register as $r) {
                $att_values[] = "(?, ?, ?, CURDATE(), 'pending', 0, 0)";
                array_push($att_params, $r['student_id'], $session_id, $r['subtopic_id']);
            }
            $pdo->prepare("
                INSERT IGNORE INTO attendance (student_id, session_id, subtopic_id, attendance_date, attendance_status, validated, is_deleted)
                VALUES " . implode(',', $att_values)
            )->execute($att_params);

            $pdo->commit();
            $total_assigned = count($to_register);

            // Send emails AFTER commit (so if SMTP fails, DB is still saved)
            foreach ($to_register as $r) {
                if (empty($r['email'])) continue;
                $subject = "Auto-assigned: {$r['subtopic_title']} – {$session['title']}";
                $body = "<p>Hi {$r['full_name']},</p><p>You were automatically registered for <strong>{$r['subtopic_title']}</strong>.</p><p>Session: {$session['title']}<br>Date: {$session['date']}<br>Time: {$session['start_time']}</p><p>Please log in to ACES for details.</p>";
                try {
                    sendEmail($r['email'], $subject, $body);
                } catch (Exception $e) {
                    $errors[] = "Email failed for {$r['email']}: " . $e->getMessage();
                }
            }

            echo "  ✓ Assigned {$total_assigned} student(s).\n";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "  ✗ Batch insert failed: " . $e->getMessage() . "\n";
        }
    }

    if (!empty($unassignable)) {
        echo "  ✗ " . count($unassignable) . " student(s) could not be assigned (all subtopics full or restricted).\n";
    }
}

echo "\n---\nTotal auto-assigned: $total_assigned\n";
if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $e) echo "  $e\n";
}