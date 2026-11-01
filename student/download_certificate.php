<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotStudent();
require_once __DIR__ . '/../lib/fpdf.php';

$student_id = $_SESSION['student_id'];

// 1. SECURITY CHECK: Has the Staff actually generated this certificate?
$stmt = $pdo->prepare("SELECT status FROM certificate_requests WHERE student_id = ? ORDER BY requested_at DESC LIMIT 1");
$stmt->execute([$student_id]);
$status = $stmt->fetchColumn();

if ($status !== 'generated') {
    die("Access Denied: Your certificate has not been generated yet. Please wait for Staff approval.");
}

// 2. Fetch Student Info
$stmt = $pdo->prepare("SELECT s.first_name, s.last_name, s.program, s.student_id FROM students s WHERE s.student_id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

// 3. Fetch Certificate Settings
$settings = $pdo->query("SELECT * FROM certificate_settings WHERE id = 1")->fetch();
$bg_path = (!empty($settings['background_image']) && file_exists(__DIR__ . '/../' . $settings['background_image'])) 
            ? __DIR__ . '/../' . $settings['background_image'] : null;

// 4. Generate the PDF
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetAutoPageBreak(false);

if ($bg_path) {
    $pdf->Image($bg_path, 0, 0, 297, 210);
} else {
    $pdf->SetDrawColor(10, 110, 45);
    $pdf->SetLineWidth(3);
    $pdf->Rect(10, 10, 277, 190);
    $pdf->SetLineWidth(1);
    $pdf->Rect(15, 15, 267, 180);
    $logo_path = __DIR__ . '/../assets/images/kld_logo.png';
    if (file_exists($logo_path)) $pdf->Image($logo_path, 133, 30, 30);
}

$full_name = strtoupper($student['first_name'] . ' ' . $student['last_name']);

$pdf->SetFont('Times', 'B', 26);
$pdf->SetTextColor(10, 110, 45);
$pdf->SetY(70);
$pdf->Cell(0, 12, strtoupper($settings['title_text']), 0, 1, 'C');

$pdf->SetFont('Times', '', 12);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 8, strtoupper($settings['subtitle_text']), 0, 1, 'C');

$pdf->SetFont('Times', 'B', 28);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetY(90);
$pdf->Cell(0, 15, strtoupper($full_name), 0, 1, 'C');

$name_width = $pdf->GetStringWidth(strtoupper($full_name));
$pdf->SetDrawColor(200, 200, 200);
$pdf->Line(148.5 - ($name_width / 2), 108, 148.5 + ($name_width / 2), 108); 

$pdf->SetFont('Times', '', 12);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetY(115);
$pdf->Cell(0, 8, 'has successfully completed all required activities and modules for the', 0, 1, 'C');

$pdf->SetFont('Times', 'B', 16);
$pdf->SetTextColor(10, 110, 45);
$pdf->Cell(0, 10, 'Alumni Career, Enhancement and Services (ACES) Program', 0, 1, 'C');

$pdf->SetY(165);
$pdf->SetFont('Times', '', 12);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(30);
$pdf->Cell(80, 10, 'Date Issued: ' . date('F d, Y'), 0, 0, 'L'); 

$pdf->SetX(185);
$pdf->Cell(80, 10, '_________________________', 0, 1, 'C');

$pdf->SetX(185);
$pdf->SetFont('Times', 'B', 14);
$pdf->Cell(80, 6, strtoupper($settings['sig_name']), 0, 1, 'C');

$pdf->SetX(185);
$pdf->SetFont('Times', '', 11);
$pdf->Cell(80, 6, $settings['sig_title'], 0, 1, 'C');

// 5. Force Download
$pdf->Output('D', 'ACES_Certificate_' . $student_id . '.pdf');
exit;