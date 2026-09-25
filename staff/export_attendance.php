<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

// ========== Read from GET ==========
$format  = $_GET['format'] ?? '';
$ids_raw = $_GET['ids'] ?? '';
$cols_raw = $_GET['cols'] ?? '';

$ids     = explode(',', $ids_raw);
$columns = json_decode($cols_raw, true);
if (!is_array($columns)) $columns = [];

if (empty($ids) || empty($columns)) {
    die('Missing ids or columns');
}

// ========== Fetch the data ==========
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("
    SELECT a.attendance_id,
           s.student_id AS student_no,
           u.full_name AS student_name,
           a.attendance_date AS date,
           s.program AS course,
           s.section,
           CONCAT(ses.title, ' – ', sub.title) AS subtopic_session,
           a.attendance_status AS status
    FROM attendance a
    JOIN students s  ON a.student_id = s.student_id
    JOIN users u     ON s.user_id = u.user_id
    JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id
    JOIN sessions ses  ON a.session_id = ses.session_id
    WHERE a.attendance_id IN ($placeholders)
    ORDER BY a.attendance_date DESC
");
$stmt->execute($ids);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($data)) {
    die('No matching attendance records');
}

// ========== CSV export ==========
if ($format === 'csv') {
    // Force download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_export.csv"');

    $output = fopen('php://output', 'w');
    // Header row
    fputcsv($output, $columns);
    // Data rows
    foreach ($data as $row) {
        $csvRow = [];
        foreach ($columns as $col) {
            $csvRow[] = $row[$col] ?? '';
        }
        fputcsv($output, $csvRow);
    }
    fclose($output);
    exit;
}

// ========== PDF / Print view (optional) ==========
if ($format === 'pdf') {
    // Return a printable HTML page
    header('Content-Type: text/html; charset=utf-8');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Attendance Export</title>';
    $html .= '<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #0a3e6d; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #f2f2f2; }
        @media print { body { margin: 0; } .no-print { display: none; } }
    </style></head><body>';
    $html .= '<h2>Attendance Records</h2>';
    $html .= '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '<table><thead><tr>';
    foreach ($columns as $col) {
        $html .= '<th>' . htmlspecialchars($col) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($columns as $col) {
            $html .= '<td>' . htmlspecialchars($row[$col] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<div class="no-print" style="margin-top: 20px; text-align: center;">
                <button onclick="window.print();">Print / Save as PDF</button>
                <button onclick="window.close();">Close</button>
              </div>';
    $html .= '</body></html>';
    echo $html;
    exit;
}

die('Unsupported format');