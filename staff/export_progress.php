<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$format = $input['format'] ?? '';
$columns = $input['columns'] ?? [];
$data = $input['data'] ?? [];

if (empty($columns) || empty($data)) {
    echo json_encode(['success' => false, 'error' => 'No columns or data']);
    exit;
}

if ($format === 'csv') {
    // Generate CSV
    $output = fopen('php://temp', 'w');
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
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    echo json_encode(['success' => true, 'csv' => $csv]);
    exit;
} elseif ($format === 'pdf') {
    // Generate HTML that can be printed to PDF
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Student Progress Export</title>';
    $html .= '<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #0a3e6d; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #f2f2f2; }
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
    </style></head><body>';
    $html .= '<h2>Student Progress Report</h2>';
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
    $html .= '<script>// auto-trigger print? optional</script>';
    $html .= '</body></html>';
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
} else {
    echo json_encode(['success' => false, 'error' => 'Unsupported format']);
}