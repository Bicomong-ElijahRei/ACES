<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$filter = $_GET['filter'] ?? 'all';

try {
    // Base: all students with aggregate attendance AND module progress
    $stmt = $pdo->query("
        SELECT 
            s.student_id, s.program, s.section,
            u.full_name,
            (SELECT COUNT(DISTINCT r.subtopic_id) FROM registrations r WHERE r.student_id = s.student_id) AS total_reg,
            (SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a WHERE a.student_id = s.student_id AND a.attendance_status = 'present' AND a.is_deleted = 0) AS present_reg,
            (SELECT COUNT(DISTINCT m.module_id) FROM modules m JOIN registrations r ON m.subtopic_id = r.subtopic_id WHERE r.student_id = s.student_id AND m.is_deleted = 0) AS total_mods,
            (SELECT COUNT(DISTINCT smp.module_id) FROM student_module_progress smp JOIN modules m ON smp.module_id = m.module_id JOIN registrations r ON m.subtopic_id = r.subtopic_id WHERE r.student_id = s.student_id AND smp.student_id = s.student_id AND smp.completed = 1 AND m.is_deleted = 0) AS done_mods
        FROM students s
        JOIN users u ON u.user_id = s.user_id
        WHERE s.is_deleted = 0
        ORDER BY u.full_name
    ");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Per-student session breakdown (Fixed placeholder issue)
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
        // Combined Math (Attendance + Modules)
        $total_req = (int)$s['total_reg'] + (int)$s['total_mods'];
        $done_req  = (int)$s['present_reg'] + (int)$s['done_mods'];
        $s['progress_pct'] = $total_req > 0 ? min(100, (int) round(($done_req / $total_req) * 100)) : 0;

        // Pass the student_id twice for the two '?' placeholders
        $sessStmt->execute([$s['student_id'], $s['student_id']]);
        $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

        $s['attendance_summary'] = ['present' => (int)$s['present_reg'], 'total' => (int)$s['total_reg']];
        $s['modules_summary']    = ['completed' => (int)$s['done_mods'], 'total' => (int)$s['total_mods']];
        $s['sessions']           = $sessions;
    }
    unset($s);

    // Apply filter
    if ($filter === 'at-risk') {
        $students = array_values(array_filter(
            $students,
            fn($x) => $x['progress_pct'] < 50 && (($x['total_reg'] + $x['total_mods']) > 0)
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