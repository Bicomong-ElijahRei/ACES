<?php
/**
 * Global student progress — used by the At Risk / Avg Progress drawers
 * on student_progress.php
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$filter = $_GET['filter'] ?? 'all';

try {
    // Base: all students with aggregate attendance progress
    $stmt = $pdo->query("
        SELECT 
            s.student_id, s.program, s.section,
            u.full_name,
            COUNT(r.subtopic_id) AS total_reg,
            SUM(CASE WHEN a.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_reg
        FROM students s
        JOIN users u ON u.user_id = s.user_id
        LEFT JOIN registrations r ON r.student_id = s.student_id
        LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id
        WHERE s.is_deleted = 0
        GROUP BY s.student_id
        ORDER BY u.full_name
    ");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Per-student session breakdown
    $sessStmt = $pdo->prepare("
        SELECT 
            sess.session_id, sess.title AS session_title, sess.phase,
            st.subtopic_id, st.title AS subtopic_title,
            a.attendance_status,
            (SELECT COUNT(*) FROM modules m 
                WHERE m.subtopic_id = st.subtopic_id AND m.is_deleted = 0) AS modules_total,
            (SELECT COUNT(*) FROM student_module_progress smp
                JOIN modules m ON smp.module_id = m.module_id
                WHERE smp.student_id = ? 
                  AND m.subtopic_id = st.subtopic_id 
                  AND smp.completed = 1) AS modules_completed
        FROM registrations r
        JOIN subtopics st ON st.subtopic_id = r.subtopic_id
        JOIN sessions sess ON sess.session_id = st.session_id
        LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id
        WHERE r.student_id = ? AND st.is_deleted = 0 AND sess.is_deleted = 0
        ORDER BY sess.date DESC
    ");

    foreach ($students as &$s) {
        $total   = (int) $s['total_reg'];
        $present = (int) $s['present_reg'];
        $s['progress_pct'] = $total > 0 ? (int) round(($present / $total) * 100) : 0;

        // Pass the student_id twice for the two '?' placeholders
        $sessStmt->execute([$s['student_id'], $s['student_id']]);
        $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

        $modTotal = 0;
        $modDone  = 0;
        foreach ($sessions as $row) {
            $modTotal += (int) $row['modules_total'];
            $modDone  += (int) $row['modules_completed'];
        }

        $s['attendance_summary'] = ['present' => $present, 'total' => $total];
        $s['modules_summary']    = ['completed' => $modDone, 'total' => $modTotal];
        $s['sessions']           = $sessions;
    }
    unset($s);

    // Apply filter
    if ($filter === 'at-risk') {
        $students = array_values(array_filter(
            $students,
            fn($x) => $x['progress_pct'] < 50 && $x['attendance_summary']['total'] > 0
        ));
        usort($students, fn($a, $b) => $a['progress_pct'] <=> $b['progress_pct']);
    } elseif ($filter === 'complete') {
        $students = array_values(array_filter($students, fn($x) => $x['progress_pct'] === 100));
    } elseif ($filter === 'in-progress') {
        $students = array_values(array_filter(
            $students,
            fn($x) => $x['progress_pct'] > 0 && $x['progress_pct'] < 100
        ));
    }

    echo json_encode([
        'success'  => true,
        'filter'   => $filter,
        'count'    => count($students),
        'students' => $students,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}