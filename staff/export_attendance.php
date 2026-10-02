<?php
/**
 * ============================================================
 * ACES System — Export Attendance Records (CSV / PDF Print View)
 * ============================================================
 * POST-only, CSRF-protected. Accepts:
 *   format     (csv | pdf)
 *   ids        (comma-separated attendance IDs)
 *   cols       (JSON array of column keys)
 *   csrf_token (required)
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

// ---- POST only ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

csrf_verify();

// ---- Read input ----
$format   = $_POST['format'] ?? '';
$ids_raw  = $_POST['ids']    ?? '';
$cols_raw = $_POST['cols']   ?? '';

$ids = array_values(array_filter(array_map('trim', explode(',', $ids_raw)), 'strlen'));
$columns = json_decode($cols_raw, true);
if (!is_array($columns)) $columns = [];

if (empty($ids) || empty($columns)) {
    http_response_code(400);
    exit('Missing or invalid ids/columns.');
}

// ---- Column labels ----
$col_labels = [
    'student_no'        => 'Student No.',
    'student_name'      => 'Student Name',
    'date'              => 'Date',
    'course'            => 'Course',
    'section'           => 'Section',
    'subtopic_session'  => 'Subtopic / Session',
    'status'            => 'Status',
];

// Only keep known columns
$columns = array_values(array_intersect($columns, array_keys($col_labels)));
if (empty($columns)) {
    http_response_code(400);
    exit('No valid columns selected.');
}

// ---- Query ----
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare("
    SELECT a.attendance_id,
           s.student_id AS student_no,
           u.full_name  AS student_name,
           a.attendance_date AS date,
           s.program    AS course,
           s.section,
           CONCAT(ses.title, ' – ', sub.title) AS subtopic_session,
           a.attendance_status AS status
    FROM attendance a
    JOIN students s  ON a.student_id = s.student_id
    JOIN users u     ON s.user_id = u.user_id
    JOIN subtopics sub ON a.subtopic_id = sub.subtopic_id
    JOIN sessions ses  ON a.session_id = ses.session_id
    WHERE a.attendance_id IN ($placeholders)
      AND a.is_deleted = 0
    ORDER BY a.attendance_date DESC
");
$stmt->execute($ids);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($data)) {
    http_response_code(404);
    exit('No matching attendance records.');
}

// ---- Format value ----
function formatExportValue($col, $val) {
    if ($val === null || $val === '') return '-';
    if ($col === 'date' && $val) return date('M d, Y', strtotime($val));
    if ($col === 'status') {
        return ['present' => 'Present', 'pending' => 'Pending', 'absent' => 'Absent'][$val] ?? ucfirst($val);
    }
    return $val;
}

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_export_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel

    // Header
    $header_row = array_map(fn($col) => $col_labels[$col], $columns);
    fputcsv($output, $header_row);

    // Rows
    foreach ($data as $row) {
        $line = array_map(fn($col) => formatExportValue($col, $row[$col] ?? ''), $columns);
        fputcsv($output, $line);
    }
    fclose($output);
    exit;
}

// ============================================================
// PDF / PRINT VIEW
// ============================================================
if ($format === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Attendance Export</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: 'Segoe UI', Arial, sans-serif; margin: 24px; color: #1f2937; }
            .header { border-bottom: 3px solid #0a6e2d; padding-bottom: 14px; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; }
            .header h1 { color: #0a6e2d; font-size: 22px; margin: 0; }
            .header .meta { font-size: 11px; color: #6b7280; text-align: right; }
            table { border-collapse: collapse; width: 100%; font-size: 11px; }
            th { background: #054018; color: #fff; padding: 8px 10px; text-align: left; font-weight: 600; border: 1px solid #054018; }
            td { padding: 6px 10px; border: 1px solid #e5e7eb; vertical-align: top; }
            tr:nth-child(even) td { background: #f9fafb; }
            .footer { margin-top: 18px; font-size: 10px; color: #9ca3af; text-align: center; }
            .no-print { margin-top: 24px; text-align: center; }
            .no-print button {
                background: #0a6e2d; color: #fff; border: none;
                padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600;
                margin: 0 6px; cursor: pointer; transition: 0.15s;
            }
            .no-print button:hover { background: #054018; }
            .no-print button.secondary { background: #6b7280; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
                th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>

        <div class="header">
            <div>
                <h1>ACES Attendance Records</h1>
                <div style="font-size:12px; color:#6b7280;">Kolehiyo ng Lungsod ng Dasmariñas</div>
            </div>
            <div class="meta">
                Generated: <?= date('M d, Y g:i A') ?><br>
                <?= count($data) ?> record(s)
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th><?= htmlspecialchars($col_labels[$col]) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td><?= htmlspecialchars(formatExportValue($col, $row[$col] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">ACES Unit · Kolehiyo ng Lungsod ng Dasmariñas</div>

        <div class="no-print">
            <button onclick="window.print();">🖨️ Print / Save as PDF</button>
            <button class="secondary" onclick="window.close();">Close</button>
        </div>

        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(400);
exit('Unsupported format.');