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
 *   - Conditional module JOIN to prevent row duplication
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
$progress_status = $_POST['progress_status'] ?? 'all';
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

// ---- Detect whether module columns are requested ----
$needs_module_columns = !empty(array_intersect(
    $columns,
    ['module_title', 'module_completion', 'module_score']
));

// --- ADVANCED PROGRESS FILTERING LOGIC ---
$valid_student_ids = [];
if ($progress_status !== 'all') {
    $stmt_prog = $pdo->query("
        SELECT 
            s.student_id,
            COUNT(r.subtopic_id) AS total_reg,
            SUM(CASE WHEN a.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_reg
        FROM students s
        LEFT JOIN registrations r ON r.student_id = s.student_id
        LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id
        WHERE s.is_deleted = 0
        GROUP BY s.student_id
    ");
    
    while ($row = $stmt_prog->fetch(PDO::FETCH_ASSOC)) {
        $total = (int)$row['total_reg'];
        $present = (int)$row['present_reg'];
        $pct = $total > 0 ? round(($present / $total) * 100) : 0;
        
        $match = false;
        if ($progress_status === 'completed' && $pct === 100) $match = true;
        elseif ($progress_status === 'almost' && $pct >= 75 && $pct < 100) $match = true;
        elseif ($progress_status === 'in_progress' && $pct >= 50 && $pct < 75) $match = true;
        elseif ($progress_status === 'at_risk' && $pct > 0 && $pct < 50) $match = true;
        elseif ($progress_status === 'inactive' && $pct === 0) $match = true;
        
        if ($match) {
            $valid_student_ids[] = $row['student_id'];
        }
    }
    
    // If the filter is active but no students match, stop the export gracefully
    if (empty($valid_student_ids)) {
        die("<h2 style='font-family:sans-serif; text-align:center; margin-top:50px;'>No students found matching the selected Progress Status.</h2>");
    }
}
// ----------------------------------------

// ---- Build SELECT list ----
$select_parts = [
    'r.student_id',
    'u.full_name',
    's.program AS course',
    's.section',
    'a.attendance_status',
    'a.attendance_date',
];
if ($needs_module_columns) {
    $select_parts[] = 'm.title AS module_title';
    $select_parts[] = 'sp.completed AS module_completion';
    $select_parts[] = 'sp.score AS module_score';
}
$select_parts[] = 'st.title AS subtopic_title';
$select_parts[] = 'ses.title AS session_title';

$sql = "SELECT " . implode(",\n            ", $select_parts) . "
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        JOIN subtopics st ON r.subtopic_id = st.subtopic_id
        JOIN sessions ses ON st.session_id = ses.session_id
        LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id";

if ($needs_module_columns) {
    $sql .= "
        LEFT JOIN modules m ON m.subtopic_id = st.subtopic_id AND m.is_deleted = 0
        LEFT JOIN student_module_progress sp ON sp.module_id = m.module_id AND sp.student_id = r.student_id";
}

$sql .= " WHERE 1=1";
$params = [];
if ($session_id) { $sql .= " AND ses.session_id = ?"; $params[] = $session_id; }
if ($course)     { $sql .= " AND s.program = ?";      $params[] = $course; }
if ($section)    { $sql .= " AND s.section = ?";      $params[] = $section; }

// Apply the progress filter if any student IDs were found
if (!empty($valid_student_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($valid_student_ids), '?'));
    $sql .= " AND r.student_id IN ($in_placeholders)";
    $params = array_merge($params, $valid_student_ids);
}
$sql .= " ORDER BY u.full_name, ses.title, st.title";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Format value ----
function formatCellValue($col, $val) {
    if ($val === null || $val === '') return '-';
    if ($col === 'module_completion') return $val ? 'Completed' : 'Not completed';
    if ($col === 'attendance_status') {
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

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $margin_left  = 10;
    $margin_right = 10;
    $page_width   = $pdf->GetPageWidth();
    $usable_width = $page_width - $margin_left - $margin_right;

    $pdf->SetFillColor(10, 110, 45);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($usable_width, 10, 'ACES Activity Tracking System', 0, 1, 'L', true);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($usable_width, 6, 'Custom Report', 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', '', 9);
    $meta_parts = ['Generated: ' . date('M d, Y g:i A')];
    if ($session_id) $meta_parts[] = 'Session filter applied';
    if ($course)     $meta_parts[] = 'Course: ' . $course;
    if ($section)    $meta_parts[] = 'Section: ' . $section;
    $meta_parts[] = count($rows) . ' record(s)';
    $pdf->Cell($usable_width, 6, implode('  |  ', $meta_parts), 0, 1, 'L');
    $pdf->Ln(1);

    $base_cell_padding = 4;
    $total_weight = 0;
    foreach ($columns as $col) {
        $total_weight += $col_meta[$col]['weight'];
    }

    $col_widths = [];
    foreach ($columns as $col) {
        $weight = $col_meta[$col]['weight'];
        $col_widths[$col] = ($weight / $total_weight) * $usable_width;
    }

    $pdf->SetFillColor(5, 64, 24);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetX($margin_left);

    foreach ($columns as $col) {
        $meta = $col_meta[$col];
        $pdf->Cell($col_widths[$col], 9, $meta['label'], 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 8.5);

    $row_index = 0;
    foreach ($rows as $row) {
        $pdf->SetX($margin_left);

        $max_lines = 1;
        $cell_values = [];
        foreach ($columns as $col) {
            $val = formatCellValue($col, $row[$col] ?? '');
            $cell_values[$col] = $val;

            $chars_per_mm = 2.4;
            $max_chars_per_line = max(1, (int)(($col_widths[$col] - $base_cell_padding) * $chars_per_mm));
            $lines = ceil(mb_strlen($val) / $max_chars_per_line);
            if ($lines > $max_lines) $max_lines = $lines;
        }
        $row_height = max(6, $max_lines * 4.5);

        $fill = ($row_index % 2 === 1);
        if ($fill) {
            $pdf->SetFillColor(248, 250, 252);
        }

        $y_start = $pdf->GetY();

        foreach ($columns as $col) {
            $meta = $col_meta[$col];
            $val = $cell_values[$col];

            $x_cur = $pdf->GetX();
            $y_cur = $pdf->GetY();

            $pdf->SetXY($x_cur, $y_cur);
            if ($fill) {
                $pdf->Cell($col_widths[$col], $row_height, '', 1, 0, '', true);
            } else {
                $pdf->Cell($col_widths[$col], $row_height, '', 1, 0, '', false);
            }

            $pdf->SetXY($x_cur + 1, $y_cur + 1);
            $pdf->MultiCell($col_widths[$col] - 2, 4, $val, 0, $meta['align']);

            $pdf->SetXY($x_cur + $col_widths[$col], $y_start);
        }

        $pdf->SetXY($margin_left, $y_start + $row_height);
        $row_index++;
    }

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