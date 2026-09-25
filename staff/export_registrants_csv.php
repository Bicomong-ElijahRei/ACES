<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();
if (isViewer()) { header('Location: dashboard.php'); exit; }

$filter_session   = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$filter_subtopic  = isset($_GET['subtopic_id']) ? (int)$_GET['subtopic_id'] : 0;
$filter_course    = $_GET['course'] ?? '';
$filter_section   = $_GET['section'] ?? '';

$sql = "
    SELECT st.student_id, u.full_name, st.program, st.section,
           sub.title AS subtopic_title, s.title AS session_title,
           r.registration_date, r.status
    FROM registrations r
    JOIN students st ON r.student_id = st.student_id
    JOIN users u ON st.user_id = u.user_id
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
    JOIN sessions s ON r.session_id = s.session_id
    WHERE sub.is_deleted = 0 AND s.is_deleted = 0 AND st.is_deleted = 0
";
$params = [];
if ($filter_session) { $sql .= " AND s.session_id = ?"; $params[] = $filter_session; }
if ($filter_subtopic) { $sql .= " AND sub.subtopic_id = ?"; $params[] = $filter_subtopic; }
if ($filter_course) { $sql .= " AND st.program = ?"; $params[] = $filter_course; }
if ($filter_section) { $sql .= " AND st.section = ?"; $params[] = $filter_section; }
$sql .= " ORDER BY s.date DESC, sub.title, st.section, u.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="registrants.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Student No.', 'Full Name', 'Course', 'Section', 'Session', 'Subtopic', 'Registration Date', 'Status']);
foreach ($data as $row) {
    fputcsv($output, [
        $row['student_id'],
        $row['full_name'],
        $row['program'],
        $row['section'],
        $row['session_title'],
        $row['subtopic_title'],
        date('M d, Y', strtotime($row['registration_date'])),
        ucfirst(str_replace('_',' ',$row['status']))
    ]);
}
fclose($output);
exit;