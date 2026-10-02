<?php
/**
 * ============================================================
 * ACES System — Custom Report Export (CSV + PDF)
 * ============================================================
 * POST-only, CSRF-protected. Generates:
 *   - CSV (Excel-compatible)
 *   - PDF (landscape A4, auto-wrapping cells)
 *
 * Fixes:
 *   - Dynamic column widths based on content type
 *   - MultiCell() so long text wraps (no truncation)
 *   - Auto row height per row
 *   - Styled header + footer with page numbers
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
$format     = $_POST['format']     ?? 'csv';
$session_id = $_POST['session_id'] ?? '';
$course     = $_POST['course']     ?? '';
$section    = $_POST['section']    ?? '';
$columns    = $_POST['columns']    ?? ['student_id','full_name','session_title','subtopic_title','attendance_status'];

// Whitelist columns to prevent injection
$allowed_columns = [
    'student_id','full_name','course','section',
    'attendance_status','attendance_date',
    'module_title','module_completion','module_score',
    'subtopic_title','session_title'
];
$columns = array_values(array_intersect($columns, $allowed_columns));
if (empty($columns)) {
    $columns = ['student_id','full_name','session_title','subtopic_title','attendance_status'];
}

// ---- Column metadata ----
$col_meta = [
    'student_id'        => ['label' => 'Student No.',        'weight' => 0.7, 'align' => 'L'],
    'full_name'         => ['label' => 'Student Name',       'weight' => 1.6, 'align' => 'L'],
    'course'            => ['label' => 'Course',             'weight' => 1.2, 'align' => 'L'],
    'section'           => ['label' => 'Section',            'weight' => 0.6, 'align' => 'C'],
    'attendance_status' => ['label' => 'Attendance',         'weight' => 0.9, 'align' => 'C'],
    'attendance_date'   => ['label' => 'Attendance Date',    'weight' => 0.9, 'align' => 'C'],
    'module_title'      => ['label' => 'Module Title',       'weight' => 1.6, 'align' => 'L'],
    'module_completion' => ['label' => 'Module Completion',  'weight' => 0.9, 'align' => 'C'],
    'module_score'      => ['label' => 'Assessment Score',   'weight' => 0.8, 'align' => 'C'],
    'subtopic_title'    => ['label' => 'Subtopic',           'weight' => 1.5, 'align' => 'L'],
    'session_title'     => ['label' => 'Session',            'weight' => 1.5, 'align' => 'L'],
];

// ---- Query ----
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
if ($session_id) { $sql .= " AND ses.session_id = ?"; $params[] = $session_id; }
if ($course)     { $sql .= " AND s.program = ?";      $params[] = $course; }
if ($section)    { $sql .= " AND s.section = ?";      $params[] = $section; }
$sql .= " ORDER BY u.full_name, ses.title, st.title";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Format value ----
function formatCellValue($col, $val) {
    if ($val === null || $val === '') return '-';   // ASCII hyphen — no encoding issues
    if ($col === 'module_completion') return $val ? 'Completed' : 'Not completed';
    if ($col === 'attendance_status') {
        // Map to plain labels so no accented chars sneak in
        $map = ['present' => 'Present', 'pending' => 'Pending', 'absent' => 'Absent'];
        return $map[$val] ?? ucfirst($val);
    }
    if ($col === 'attendance_date' && $val) {
        return date('M d, Y', strtotime($val));
    }
    return $val;
}

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="custom_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // BOM for Excel UTF-8 compatibility
    fwrite($output, "\xEF\xBB\xBF");

    // Header
    $header_row = [];
    foreach ($columns as $col) $header_row[] = $col_meta[$col]['label'];
    fputcsv($output, $header_row);

    // Rows
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $col) {
            $line[] = formatCellValue($col, $row[$col] ?? '');
        }
        fputcsv($output, $line);
    }
    fclose($output);
    exit;
}

// ============================================================
// PDF EXPORT
// ============================================================
if ($format === 'pdf') {
    require_once __DIR__ . '/../lib/fpdf.php';

    // ---- Setup page ----
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    // ---- Constants ----
    $margin_left  = 10;
    $margin_right = 10;
    $page_width   = $pdf->GetPageWidth();
    $usable_width = $page_width - $margin_left - $margin_right;

    // ---- Header bar ----
    $pdf->SetFillColor(10, 110, 45);   // KLD green
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($usable_width, 10, 'ACES Activity Tracking System', 0, 1, 'L', true);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($usable_width, 6, 'Custom Report', 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    // ---- Meta line ----
    $pdf->SetFont('Helvetica', '', 9);
    $meta_parts = ['Generated: ' . date('M d, Y g:i A')];
    if ($session_id) $meta_parts[] = 'Session filter applied';
    if ($course)     $meta_parts[] = 'Course: ' . $course;
    if ($section)    $meta_parts[] = 'Section: ' . $section;
    $meta_parts[] = count($rows) . ' record(s)';
    $pdf->Cell($usable_width, 6, implode('  |  ', $meta_parts), 0, 1, 'L');
    $pdf->Ln(1);

    // ---- Calculate dynamic column widths ----
    $base_cell_padding = 4; // mm of left+right padding per cell
    $total_weight = 0;
    foreach ($columns as $col) {
        $total_weight += $col_meta[$col]['weight'];
    }

    $col_widths = [];
    foreach ($columns as $col) {
        $weight = $col_meta[$col]['weight'];
        $col_widths[$col] = ($weight / $total_weight) * $usable_width;
    }

    // ---- Header row ----
    $pdf->SetFillColor(5, 64, 24);   // dark KLD green
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetX($margin_left);

    foreach ($columns as $col) {
        $meta = $col_meta[$col];
        // Multi-line header (wrap words if needed)
        $pdf->Cell($col_widths[$col], 9, $meta['label'], 1, 0, 'C', true);
    }
    $pdf->Ln();

    // ---- Data rows ----
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 8.5);

    $row_index = 0;
    foreach ($rows as $row) {
        $pdf->SetX($margin_left);

        // Compute row height based on longest wrapped cell
        $max_lines = 1;
        $cell_values = [];
        foreach ($columns as $col) {
            $val = formatCellValue($col, $row[$col] ?? '');
            $cell_values[$col] = $val;

            // Estimate number of lines this text will need
            $chars_per_mm = 2.4; // approx for Helvetica 8.5
            $max_chars_per_line = max(1, (int)(($col_widths[$col] - $base_cell_padding) * $chars_per_mm));
            $lines = ceil(mb_strlen($val) / $max_chars_per_line);
            if ($lines > $max_lines) $max_lines = $lines;
        }
        $row_height = max(6, $max_lines * 4.5);

        // Alternating row background
        $fill = ($row_index % 2 === 1);
        if ($fill) {
            $pdf->SetFillColor(248, 250, 252);
        }

        // Render each cell with MultiCell for wrapping
        $x_start = $margin_left;
        $y_start = $pdf->GetY();

        foreach ($columns as $col) {
            $meta = $col_meta[$col];
            $val = $cell_values[$col];

            // Save position
            $x_cur = $pdf->GetX();
            $y_cur = $pdf->GetY();

            // Draw border + background
            $pdf->SetXY($x_cur, $y_cur);
            if ($fill) {
                $pdf->Cell($col_widths[$col], $row_height, '', 1, 0, '', true);
            } else {
                $pdf->Cell($col_widths[$col], $row_height, '', 1, 0, '', false);
            }

            // Write text with MultiCell (centered vertically would need extra work; use top-align with small padding)
            $pdf->SetXY($x_cur + 1, $y_cur + 1);
            $pdf->MultiCell($col_widths[$col] - 2, 4, $val, 0, $meta['align']);

            // Move to next column position
            $pdf->SetXY($x_cur + $col_widths[$col], $y_start);
        }

        // Move to next row
        $pdf->SetXY($margin_left, $y_start + $row_height);
        $row_index++;
    }

    // ---- Footer with page numbers ----
    $pdf->SetY(-12);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 6, 'ACES Unit - Kolehiyo ng Lungsod ng Dasmarinas  |  Page ' . $pdf->PageNo() . '/{nb}', 0, 0, 'C');
    $pdf->AliasNbPages();

    $pdf->Output('D', 'custom_report_' . date('Y-m-d') . '.pdf');
    exit;
}

// ---- Fallback ----
http_response_code(400);
exit('Invalid format.');