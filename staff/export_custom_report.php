<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$format = $_GET['format'] ?? 'csv';
$session_id = $_GET['session_id'] ?? '';
$course = $_GET['course'] ?? '';
$section = $_GET['section'] ?? '';
$columns = $_GET['columns'] ?? ['student_id', 'full_name', 'session_title', 'subtopic_title', 'attendance_status'];

// Build query
$sql = "SELECT 
            r.student_id,
            u.full_name,
            s.program AS course,
            s.section,
            a.attendance_status,
            a.attendance_date,
            m.title AS module_title,
            sp.completed AS module_completion,
            sp.score AS module_score,
            st.title AS subtopic_title,
            ses.title AS session_title
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        JOIN subtopics st ON r.subtopic_id = st.subtopic_id
        JOIN sessions ses ON st.session_id = ses.session_id
        LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id
        LEFT JOIN modules m ON m.subtopic_id = st.subtopic_id AND m.is_deleted = 0
        LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = r.student_id
        WHERE 1=1";
$params = [];
if ($session_id) {
    $sql .= " AND ses.session_id = ?";
    $params[] = $session_id;
}
if ($course) {
    $sql .= " AND s.program = ?";
    $params[] = $course;
}
if ($section) {
    $sql .= " AND s.section = ?";
    $params[] = $section;
}
$sql .= " ORDER BY u.full_name, ses.title, st.title";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Filter columns
$col_labels = [
    'student_id' => 'Student No.',
    'full_name' => 'Student Name',
    'course' => 'Course',
    'section' => 'Section',
    'attendance_status' => 'Attendance Status',
    'attendance_date' => 'Attendance Date',
    'module_title' => 'Module Title',
    'module_completion' => 'Module Completion',
    'module_score' => 'Assessment Score',
    'subtopic_title' => 'Subtopic',
    'session_title' => 'Session'
];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="custom_report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_map(function($col) use ($col_labels) { return $col_labels[$col]; }, $columns));
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($output, $line);
    }
    fclose($output);
    exit;
} elseif ($format === 'pdf') {
    require_once '../lib/fpdf.php';
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 10);
    $w = array_fill(0, count($columns), 25);
    $totalWidth = array_sum($w);
    $pageWidth = $pdf->GetPageWidth() - 20;
    if ($totalWidth > $pageWidth) {
        $scale = $pageWidth / $totalWidth;
        foreach ($w as &$val) $val = $val * $scale;
    }
    foreach ($columns as $i => $col) {
        $pdf->Cell($w[$i], 7, $col_labels[$col], 1, 0, 'C');
    }
    $pdf->Ln();
    $pdf->SetFont('Helvetica', '', 9);
    foreach ($rows as $row) {
        foreach ($columns as $i => $col) {
            $val = $row[$col] ?? '';
            if ($col === 'module_completion') $val = $val ? 'Yes' : 'No';
            $pdf->Cell($w[$i], 6, substr($val, 0, 30), 1);
        }
        $pdf->Ln();
    }
    $pdf->Output('D', 'detailed_report.pdf');
    exit;
}