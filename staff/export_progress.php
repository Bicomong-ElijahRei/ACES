<?php
/**
 * ============================================================
 * ACES System — Export Student Progress (JSON wrapper)
 * ============================================================
 * POST-only AJAX endpoint. Accepts raw JSON body:
 *   {
 *     "csrf_token": "...",
 *     "format": "csv" | "pdf",
 *     "columns": ["Student No.", ...],
 *     "data":    [ { "Student No.": "...", ... }, ... ]
 *   }
 *
 * Returns JSON: { success: bool, csv? | html?, error? }
 * The JS caller converts the response into a downloadable file
 * or a printable window.
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

header('Content-Type: application/json; charset=utf-8');

// ---- POST only ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- Read JSON body ----
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// ---- CSRF check (from JSON body, not $_POST) ----
$submitted_token = $input['csrf_token'] ?? '';
$expected_token  = $_SESSION['csrf_token'] ?? '';

if ($expected_token === '' || !hash_equals($expected_token, $submitted_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed.']);
    exit;
}

// ---- Validate input ----
$format  = $input['format']  ?? '';
$columns = $input['columns'] ?? [];
$data    = $input['data']    ?? [];

if (!is_array($columns) || empty($columns)) {
    echo json_encode(['success' => false, 'error' => 'No columns provided']);
    exit;
}
if (!is_array($data) || empty($data)) {
    echo json_encode(['success' => false, 'error' => 'No data provided']);
    exit;
}
if (!in_array($format, ['csv', 'pdf'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unsupported format']);
    exit;
}

// Sanitize column labels — keep as strings, cap count
$columns = array_slice(array_map(fn($c) => (string)$c, $columns), 0, 50);

// ============================================================
// CSV
// ============================================================
if ($format === 'csv') {
    $output = fopen('php://temp', 'w');

    // UTF-8 BOM for Excel
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, $columns);

    foreach ($data as $row) {
        if (!is_array($row)) continue;
        $csvRow = [];
        foreach ($columns as $col) {
            $val = $row[$col] ?? '';
            // Convert arrays / objects to a readable string, just in case
            if (is_array($val) || is_object($val)) {
                $val = json_encode($val);
            }
            $csvRow[] = (string)$val;
        }
        fputcsv($output, $csvRow);
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);

    echo json_encode([
        'success' => true,
        'csv'     => $csv,
        'filename' => 'student_progress_' . date('Y-m-d') . '.csv'
    ]);
    exit;
}

// ============================================================
// PDF (returns HTML that the JS opens in a new tab and prints)
// ============================================================
if ($format === 'pdf') {
    $rowCount = count($data);
    $generated = date('M d, Y g:i A');

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Progress Report</title>
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
            <h1>Student Progress Report</h1>
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
                <?php foreach ($columns as $col): ?>
                    <th><?= htmlspecialchars($col) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $row): ?>
                <?php if (!is_array($row)) continue; ?>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <?php
                        $val = $row[$col] ?? '';
                        if (is_array($val) || is_object($val)) $val = json_encode($val);
                        ?>
                        <td><?= htmlspecialchars((string)$val) ?></td>
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
        // Auto-trigger print after page loads
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
    <?php
    $html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'html'    => $html
    ]);
    exit;
}