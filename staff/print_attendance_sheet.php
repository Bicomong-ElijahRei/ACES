<?php
/**
 * ============================================================
 * ACES System — Print Attendance Sheet (PDF)
 * ============================================================
 * POST-only, CSRF-protected. Generates a printable A4 attendance
 * sheet with student names (Last, First M.I.), IDs, and blank
 * signature boxes.
 *
 * Accepts:
 *   subtopic_id (required)
 *   csrf_token  (required)
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../lib/fpdf.php';
redirectIfNotStaff();

// ---- POST only ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

csrf_verify();

$subtopic_id = isset($_POST['subtopic_id']) ? (int)$_POST['subtopic_id'] : 0;
if (!$subtopic_id) {
    exit('Subtopic ID required.');
}

// ---- Fetch subtopic info ----
$stmt = $pdo->prepare("
    SELECT st.title AS subtopic_title,
           s.title AS session_title,
           s.date,
           st.subtopic_start_time,
           st.subtopic_end_time,
           st.subtopic_location,
           st.subtopic_proctor
    FROM subtopics st
    JOIN sessions s ON st.session_id = s.session_id
    WHERE st.subtopic_id = ? AND st.is_deleted = 0
    LIMIT 1
");
$stmt->execute([$subtopic_id]);
$subtopic = $stmt->fetch();

if (!$subtopic) {
    exit('Subtopic not found.');
}

// ---- Fetch registered students ----
$stmt = $pdo->prepare("
    SELECT s.student_id, u.full_name
    FROM registrations r
    JOIN students s ON r.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    WHERE r.subtopic_id = ?
      AND r.status IN ('assigned', 'auto_assigned')
      AND s.is_deleted = 0
    ORDER BY u.full_name
");
$stmt->execute([$subtopic_id]);
$students = $stmt->fetchAll();

// ---- Parse names ----
foreach ($students as &$stu) {
    $parts = explode(' ', trim($stu['full_name']));
    $last  = array_pop($parts) ?: '';
    $first = array_shift($parts) ?: '';
    $middle = '';
    if (!empty($parts)) {
        $middle = implode('', array_map(
            fn($n) => strtoupper(substr($n, 0, 1)) . '.',
            $parts
        ));
    }
    $stu['last']  = $last;
    $stu['first'] = $first;
    $stu['mi']    = $middle;
}
unset($stu);

// Sort by last name
usort($students, fn($a, $b) => strcasecmp($a['last'], $b['last']) ?: strcasecmp($a['first'], $b['first']));

// ============================================================
// BUILD PDF
// ============================================================
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

// ---- Logos ----
$kld_logo  = __DIR__ . '/../assets/images/kld_logo.png';
$aces_logo = __DIR__ . '/../assets/images/aces_logo.png';

if (file_exists($kld_logo)) {
    $pdf->Image($kld_logo, 15, 12, 28);
}
if (file_exists($aces_logo)) {
    $pdf->Image($aces_logo, 167, 12, 28);
}

$pdf->Ln(16);

// ---- Title ----
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->SetTextColor(10, 110, 45);      // KLD green
$pdf->Cell(0, 10, 'ACES Attendance Sheet', 0, 1, 'C');

$pdf->SetTextColor(80, 80, 80);
$pdf->SetFont('Helvetica', '', 11);
$pdf->Cell(0, 6, 'Kolehiyo ng Lungsod ng Dasmarinas', 0, 1, 'C');

$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(4);

// ---- Details block ----
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell(0, 7, $subtopic['session_title'], 0, 1, 'C');

$pdf->SetFont('Helvetica', '', 11);
$pdf->Cell(0, 6, $subtopic['subtopic_title'], 0, 1, 'C');

// Date + time
$date_str = date('F d, Y', strtotime($subtopic['date']));
if ($subtopic['subtopic_start_time'] && $subtopic['subtopic_end_time']) {
    $date_str .= '  |  ' . date('g:i A', strtotime($subtopic['subtopic_start_time']))
               . ' – ' . date('g:i A', strtotime($subtopic['subtopic_end_time']));
}
$pdf->Cell(0, 6, $date_str, 0, 1, 'C');

if ($subtopic['subtopic_location']) {
    $pdf->Cell(0, 6, 'Location: ' . $subtopic['subtopic_location'], 0, 1, 'C');
}
if ($subtopic['subtopic_proctor']) {
    $pdf->Cell(0, 6, 'Proctor: ' . $subtopic['subtopic_proctor'], 0, 1, 'C');
}

$pdf->Ln(6);

// ---- Table header ----
$page_width = $pdf->GetPageWidth();
$margin_left = 15;
$usable_width = $page_width - 30;  // 15mm each side

$col_widths = [
    'name'      => 70,
    'student_id'=> 40,
    'signature' => $usable_width - 70 - 40,   // remaining space
];

$pdf->SetFillColor(5, 64, 24);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 10);

$pdf->SetX($margin_left);
$pdf->Cell($col_widths['name'],       9, 'Student Name', 1, 0, 'C', true);
$pdf->Cell($col_widths['student_id'], 9, 'Student No.',  1, 0, 'C', true);
$pdf->Cell($col_widths['signature'],  9, 'Signature',    1, 1, 'C', true);

// ---- Table rows ----
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Helvetica', '', 10);

if (empty($students)) {
    $pdf->SetX($margin_left);
    $pdf->Cell($usable_width, 12, 'No students registered for this subtopic.', 1, 1, 'C');
} else {
    $row_num = 0;
    foreach ($students as $stu) {
        $name = $stu['last'] . ', ' . $stu['first'];
        if ($stu['mi']) $name .= ' ' . $stu['mi'];

        // Estimate if name needs wrapping (approx 45 chars fit in 70mm at 10pt)
        $needs_wrap = mb_strlen($name) > 40;
        $row_height = $needs_wrap ? 12 : 10;

        $pdf->SetX($margin_left);

        if ($needs_wrap) {
            // MultiCell for the name only; other cells stay same height
            $x_start = $pdf->GetX();
            $y_start = $pdf->GetY();

            $pdf->MultiCell($col_widths['name'], 6, $name, 1, 'L');
            $y_after = $pdf->GetY();
            $new_row_height = $y_after - $y_start;

            // Fill remaining cells at the new height
            $pdf->SetXY($x_start + $col_widths['name'], $y_start);
            $pdf->Cell($col_widths['student_id'], $new_row_height, $stu['student_id'], 1, 0, 'C');
            $pdf->Cell($col_widths['signature'],  $new_row_height, '', 1, 1, 'C');

            // Move below the row
            $pdf->SetY($y_start + $new_row_height);
        } else {
            $pdf->Cell($col_widths['name'],       $row_height, $name, 1, 0, 'L');
            $pdf->Cell($col_widths['student_id'], $row_height, $stu['student_id'], 1, 0, 'C');
            $pdf->Cell($col_widths['signature'],  $row_height, '', 1, 1, 'C');
        }
        $row_num++;
    }
}

// ---- Footer ----
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 9);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell(0, 5, 'Total students: ' . count($students) . '  |  Printed: ' . date('M d, Y g:i A'), 0, 1, 'C');

// ---- Output ----
$pdf->Output('D', 'attendance_' . $subtopic_id . '_' . date('Y-m-d') . '.pdf');
exit;