<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

$action = $_GET['action'] ?? '';
$ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
$cols = isset($_GET['cols']) ? explode(',', $_GET['cols']) : [];

if (empty($ids) || empty($cols)) {
    die('Invalid request.');
}

// Build column mapping
$col_map = [
    'student_id' => 'Student No.',
    'last_name' => 'Last Name',
    'first_name' => 'First Name',
    'middle_name' => 'Middle Name',
    'course' => 'Course',
    'section' => 'Section',
    'email' => 'Email'
];

// Fetch students
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "
    SELECT s.student_id, s.program, s.section, s.middle_name, u.full_name, u.email
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id IN ($placeholders)
    ORDER BY u.full_name
";
$stmt = $pdo->prepare($sql);
$stmt->execute($ids);
$students = $stmt->fetchAll();

// Split full_name into first and last
$data = [];
foreach ($students as $student) {
    $name_parts = explode(' ', trim($student['full_name']));
    $last = array_pop($name_parts);
    $first = implode(' ', $name_parts);
    $row = [];
    foreach ($cols as $col) {
        switch ($col) {
            case 'student_id': $row[] = $student['student_id']; break;
            case 'last_name': $row[] = $last; break;
            case 'first_name': $row[] = $first; break;
            case 'middle_name': $row[] = $student['middle_name']; break;
            case 'course': $row[] = $student['program']; break;
            case 'section': $row[] = $student['section']; break;
            case 'email': $row[] = $student['email']; break;
        }
    }
    $data[] = $row;
}

if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_export.csv"');
    $output = fopen('php://output', 'w');
    // Header row
    $headers = [];
    foreach ($cols as $col) {
        $headers[] = $col_map[$col];
    }
    fputcsv($output, $headers);
    // Data rows
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
} elseif ($action === 'export_pdf') {
    // Simple HTML report that can be printed to PDF
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Student Export</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h2 { color: #0a3e6d; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <h2>Student List</h2>
        <p>Generated: <?= date('Y-m-d H:i:s') ?></p>
        <table>
            <thead>
                <tr>
                    <?php foreach ($cols as $col): ?>
                        <th><?= $col_map[$col] ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?= htmlspecialchars($cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="no-print" style="margin-top: 20px; text-align: center;">
            <button onclick="window.print();">Print / Save as PDF</button>
            <button onclick="window.close();">Close</button>
        </div>
        <script>
            // Auto-trigger print dialog (optional)
            // window.print();
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>