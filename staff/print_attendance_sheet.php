<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();
require_once '../lib/fpdf.php';

$subtopic_id = $_GET['subtopic_id'] ?? 0;

// Fetch subtopic info
$stmt = $pdo->prepare("
    SELECT st.title AS subtopic_title, s.title AS session_title, s.date, st.subtopic_start_time, st.subtopic_end_time, st.subtopic_location, st.subtopic_proctor
    FROM subtopics st
    JOIN sessions s ON st.session_id = s.session_id
    WHERE st.subtopic_id = ?");
$stmt->execute([$subtopic_id]);
$subtopic = $stmt->fetch();
if (!$subtopic) die("Subtopic not found.");

// Get registered students, sorted by full_name
$stmt = $pdo->prepare("
    SELECT s.student_id, u.full_name
    FROM registrations r
    JOIN students s ON r.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    WHERE r.subtopic_id = ? AND r.status IN ('assigned','auto_assigned')
    ORDER BY u.full_name");
$stmt->execute([$subtopic_id]);
$students = $stmt->fetchAll();

// Parse full name into last, first, middle
foreach ($students as &$stu) {
    $parts = explode(' ', $stu['full_name']);
    $last = array_pop($parts);
    $first = array_shift($parts);
    $middle = '';
    if (!empty($parts)) {
        $middle = implode(' ', $parts);
        $middle = implode('', array_map(function($n) { return strtoupper(substr($n,0,1)) . '.'; }, explode(' ', $middle)));
    }
    $stu['last'] = $last;
    $stu['first'] = $first;
    $stu['mi'] = $middle;
}
// Sort by last name
usort($students, function($a, $b) { return strcasecmp($a['last'], $b['last']) ?: strcasecmp($a['first'], $b['first']); });

// Build PDF
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();

// ============================================================
// HEADER WITH LOGOS
// ============================================================
$kldLogo  = $_SERVER['DOCUMENT_ROOT'] . '/cair-system/assets/images/kld_logo.png';
$acesLogo = $_SERVER['DOCUMENT_ROOT'] . '/cair-system/assets/images/aces_logo.png';

// KLD logo – left side
$pdf->Image($kldLogo, 15, 12, 28);

// ACES logo – right side
$pdf->Image($acesLogo, 167, 12, 28);

// Push content down below the logos
$pdf->Ln(14);

// ============================================================
// TITLE & EVENT DETAILS
// ============================================================
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Attendance Sheet', 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 12);
$pdf->Cell(0, 6, 'Session: ' . $subtopic['session_title'], 0, 1, 'C');
$pdf->Cell(0, 6, 'Subtopic: ' . $subtopic['subtopic_title'], 0, 1, 'C');
$pdf->Cell(0, 6, 'Date: ' . date('F d, Y', strtotime($subtopic['date'])) . 
    ($subtopic['subtopic_start_time'] ? '   Time: ' . date('g:i A', strtotime($subtopic['subtopic_start_time'])) . ' - ' . date('g:i A', strtotime($subtopic['subtopic_end_time'])) : ''), 0, 1, 'C');
if ($subtopic['subtopic_location']) $pdf->Cell(0, 6, 'Location: ' . $subtopic['subtopic_location'], 0, 1, 'C');
if ($subtopic['subtopic_proctor']) $pdf->Cell(0, 6, 'Proctor: ' . $subtopic['subtopic_proctor'], 0, 1, 'C');
$pdf->Ln(6);

// ============================================================
// TABLE
// ============================================================
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(60, 8, 'Student Name', 1, 0, 'C');
$pdf->Cell(40, 8, 'Student ID', 1, 0, 'C');
$pdf->Cell(90, 8, 'Signature', 1, 1, 'C');

$pdf->SetFont('Helvetica', '', 11);
foreach ($students as $stu) {
    $name = $stu['last'] . ', ' . $stu['first'] . ($stu['mi'] ? ' ' . $stu['mi'] : '');
    $pdf->Cell(60, 10, $name, 1);
    $pdf->Cell(40, 10, $stu['student_id'], 1);
    $pdf->Cell(90, 10, '', 1, 1);
}

$pdf->Output('D', 'attendance_' . $subtopic_id . '.pdf');
exit;