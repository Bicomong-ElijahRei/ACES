<?php
/**
 * ============================================================
 * ACES System — Export Students (CSV / PDF Print View)
 * ============================================================
 * POST-only, CSRF-protected. Accepts:
 *   action     (export_csv | export_pdf)
 *   ids        (comma-separated student IDs)
 *   cols       (comma-separated column keys)
 *   csrf_token (required)
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

csrf_verify();

// ---- Read inputs ----
$action = $_POST['action'] ?? '';
$ids    = array_values(array_filter(array_map('trim', explode(',', $_POST['ids']  ?? '')), 'strlen'));
$cols   = array_values(array_filter(array_map('trim', explode(',', $_POST['cols'] ?? '')), 'strlen'));

if (empty($ids) || empty($cols)) {
    http_response_code(400);
    exit('Invalid request.');
}

// ---- Column mapping (whitelist) ----
$col_map = [
    'student_id'  => 'Student No.',
    'last_name'   => 'Last Name',
    'first_name'  => 'First Name',
    'middle_name' => 'Middle Name',
    'course'      => 'Course',
    'section'     => 'Section',
    'email'       => 'Email',
];

// Keep only valid columns, preserving order
$cols = array_values(array_intersect($cols, array_keys($col_map)));
if (empty($cols)) {
    http_response_code(400);
    exit('No valid columns selected.');
}

// ---- Fetch students ----
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("
    SELECT s.student_id, s.program, s.section, s.middle_name,
           u.full_name, u.email
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.student_id IN ($placeholders)
      AND s.is_deleted = 0
    ORDER BY u.full_name
");
$stmt->execute($ids);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($students)) {
    http_response_code(404);
    exit('No matching students.');
}

// ---- Parse name into parts ----
function parse_name_parts($full_name, $middle_name = '') {
    $parts = preg_split('/\s+/', trim($full_name));

    // Remove known middle-name tokens
    if (!empty($middle_name)) {
        $mid_parts = preg_split('/\s+/', trim($middle_name));
        foreach ($mid_parts as $mp) {
            $k = array_search($mp, $parts, true);
            if ($k !== false) unset($parts[$k]);
        }
        $parts = array_values($parts);
    }

    $last = '';
    $first = '';
    if (count($parts) >= 2) {
        $last  = array_pop($parts);
        $first = implode(' ', $parts);
    } elseif (count($parts) === 1) {
        $last = $parts[0];
    }

    return ['first' => $first, 'last' => $last, 'middle' => $middle_name];
}

// ---- Build rows ----
$data = [];
foreach ($students as $student) {
    $parsed = parse_name_parts($student['full_name'], $student['middle_name']);

    $row = [];
    foreach ($cols as $col) {
        switch ($col) {
            case 'student_id':  $row[] = $student['student_id'];   break;
            case 'last_name':   $row[] = $parsed['last'];          break;
            case 'first_name':  $row[] = $parsed['first'];         break;
            case 'middle_name': $row[] = $student['middle_name'];  break;
            case 'course':      $row[] = $student['program'];      break;
            case 'section':     $row[] = $student['section'];      break;
            case 'email':       $row[] = $student['email'];        break;
        }
    }
    $data[] = $row;
}

$headers = array_map(fn($c) => $col_map[$c], $cols);

// ============================================================
// CSV
// ============================================================
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_export_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel

    fputcsv($output, $headers);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// ============================================================
// PDF (HTML print view)
// ============================================================
if ($action === 'export_pdf') {
    $rowCount  = count($data);
    $generated = date('M d, Y g:i A');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Student List</title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                margin: 24px;
                color: #1f2937;
            }
            .header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-bottom: 3px solid #0a6e2d;
                padding-bottom: 14px;
                margin-bottom: 20px;
            }
            .header h1 {
                color: #0a6e2d;
                font-size: 22px;
                margin: 0;
            }
            .header .meta {
                font-size: 11px;
                color: #6b7280;
                text-align: right;
            }
            .header .meta strong { color: #0a6e2d; }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }
            th {
                background: #054018;
                color: #fff;
                padding: 8px 10px;
                text-align: left;
                border: 1px solid #054018;
                font-weight: 600;
                font-size: 10.5px;
            }
            td {
                padding: 6px 10px;
                border: 1px solid #e5e7eb;
                vertical-align: top;
            }
            tbody tr:nth-child(even) td { background: #f9fafb; }
            tbody tr:hover td { background: #f0fdf4; }

            .footer {
                margin-top: 20px;
                padding-top: 12px;
                border-top: 1px solid #e5e7eb;
                font-size: 10px;
                color: #9ca3af;
                text-align: center;
            }

            .no-print {
                position: fixed;
                bottom: 20px;
                right: 20px;
                display: flex;
                gap: 8px;
                z-index: 100;
            }
            .no-print button {
                background: #0a6e2d;
                color: #fff;
                border: none;
                padding: 12px 20px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                transition: all 0.15s;
            }
            .no-print button:hover { background: #054018; transform: translateY(-1px); }
            .no-print button.secondary { background: #6b7280; }

            @media print {
                body { margin: 0; padding: 15px; }
                .no-print { display: none; }
                th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>

        <div class="header">
            <div>
                <h1>Student List</h1>
                <div style="font-size:12px; color:#6b7280;">Kolehiyo ng Lungsod ng Dasmariñas · ACES Unit</div>
            </div>
            <div class="meta">
                Generated: <strong><?= htmlspecialchars($generated) ?></strong><br>
                Records: <strong><?= (int)$rowCount ?></strong>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $h): ?>
                        <th><?= htmlspecialchars($h) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?= htmlspecialchars((string)$cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            ACES Unit · Kolehiyo ng Lungsod ng Dasmariñas · <?= date('Y') ?>
        </div>

        <div class="no-print">
            <button class="secondary" onclick="window.close();">Close</button>
            <button onclick="window.print();">🖨️ Print / Save PDF</button>
        </div>

        <script>
            window.addEventListener('load', () => setTimeout(() => window.print(), 400));
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Fallback
http_response_code(400);
exit('Unsupported action.');