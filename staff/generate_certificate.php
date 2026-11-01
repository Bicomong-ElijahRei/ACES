<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotStaff();
require_once __DIR__ . '/../lib/fpdf.php';

// Fetch Template Settings
$settings = $pdo->query("SELECT * FROM certificate_settings WHERE id = 1")->fetch();
$bg_path = (!empty($settings['background_image']) && file_exists(__DIR__ . '/../' . $settings['background_image'])) 
            ? __DIR__ . '/../' . $settings['background_image'] : null;

$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);

// Check if it's a Template Preview Request
if (isset($_GET['preview_template'])) {
    $dummy_student = [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'student_id' => '2023-1-99999',
        'program' => 'BS Information Systems',
        'custom_name' => null
    ];
    
    generateCertificatePage($pdf, $dummy_student, $bg_path, $settings);
    $pdf->Output('I', 'Template_Preview.pdf');
    exit;
}

// Check if it's a Bulk Request (POST from Preview Modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_ids'])) {
    $ids = explode(',', $_POST['student_ids']);
    $is_preview = isset($_POST['is_preview']) && $_POST['is_preview'] == '1';
    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    
    // Fetch selected students
    $stmt = $pdo->prepare("
        SELECT cr.request_id, cr.custom_name, s.student_id, s.first_name, s.last_name, s.program 
        FROM students s
        LEFT JOIN certificate_requests cr ON cr.student_id = s.student_id
        WHERE s.student_id IN ($inQuery)
    ");
    $stmt->execute($ids);
    $students = $stmt->fetchAll();

    if (empty($students)) die("No students found.");

    $generated_count = 0;
    $skipped_count = 0;

    foreach ($students as $student) {
        // Check compliance for EACH student before generating
        $stmt_stats = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM registrations r WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned')) AS total_subs,
                (SELECT COUNT(*) FROM attendance a WHERE a.student_id = ? AND a.attendance_status = 'present' AND a.is_deleted = 0) AS present_subs,
                (SELECT COUNT(*) FROM registrations r JOIN modules m ON r.subtopic_id = m.subtopic_id WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned') AND m.is_deleted = 0) AS total_mods,
                (SELECT COUNT(*) FROM student_module_progress smp WHERE smp.student_id = ? AND smp.completed = 1) AS completed_mods
        ");
        $stmt_stats->execute([$student['student_id'], $student['student_id'], $student['student_id'], $student['student_id']]);
        $stats = $stmt_stats->fetch();

        // If eligible, generate the page
        if ($stats['total_subs'] > 0 && $stats['present_subs'] >= $stats['total_subs'] && $stats['completed_mods'] >= $stats['total_mods']) {
            generateCertificatePage($pdf, $student, $bg_path, $settings);
            $generated_count++;

            // ONLY update database if this is NOT a preview
            if (!$is_preview && !empty($student['request_id'])) {
                $pdo->prepare("UPDATE certificate_requests SET status = 'generated', processed_at = NOW(), processed_by = ? WHERE request_id = ?")
                    ->execute([$_SESSION['user_id'], $student['request_id']]);
            }
        } else {
            $skipped_count++;
        }
    }

    if ($generated_count === 0) {
        die("None of the selected students are 100% compliant. PDF not generated.");
    }

    // If preview, view inline in the iframe. If actual export, force download.
    if ($is_preview) {
        $pdf->Output('I', 'Preview.pdf');
    } else {
        $pdf->Output('D', 'ACES_Bulk_Certificates_' . date('Ymd') . '.pdf');
    }
    exit;

} else {
    // Single Export (fallback)
    $student_id = isset($_GET['id']) ? $_GET['id'] : '';
    $req_id = isset($_GET['req_id']) ? (int)$_GET['req_id'] : 0;
    $is_preview = isset($_GET['preview']) && $_GET['preview'] == '1'; // NEW: Check for preview mode

    if (empty($student_id)) die("No student ID provided.");

    $stmt = $pdo->prepare("SELECT cr.custom_name, s.first_name, s.last_name, s.program, s.section, s.student_id 
                           FROM students s 
                           LEFT JOIN certificate_requests cr ON cr.student_id = s.student_id AND cr.request_id = ?
                           WHERE s.student_id = ?");
    $stmt->execute([$req_id, $student_id]);
    $student = $stmt->fetch();

    if (!$student) die("Student not found.");

    generateCertificatePage($pdf, $student, $bg_path, $settings);

    // ONLY update the database if this is NOT a preview
    if (!$is_preview && $req_id > 0) {
        $pdo->prepare("UPDATE certificate_requests SET status = 'generated', processed_at = NOW(), processed_by = ? WHERE request_id = ?")
            ->execute([$_SESSION['user_id'], $req_id]);
    }

    $pdf->Output('I', 'ACES_Certificate_' . $student_id . '.pdf');
    exit;
}

// HELPER FUNCTION TO DRAW CERTIFICATE
function generateCertificatePage($pdf, $student, $bg_path, $settings) {
    $pdf->AddPage();
    
    // 1. Draw Background or Default Border
    if ($bg_path) {
        $pdf->Image($bg_path, 0, 0, 297, 210);
    } else {
        $pdf->SetDrawColor(10, 110, 45);
        $pdf->SetLineWidth(3); // Slightly thinner outer border
        $pdf->Rect(10, 10, 277, 190);
        $pdf->SetLineWidth(1);
        $pdf->Rect(15, 15, 267, 180);
        
        // Centered Logo
        $logo_path = __DIR__ . '/../assets/images/kld_logo.png';
        if (file_exists($logo_path)) $pdf->Image($logo_path, 133, 30, 30); // Adjusted logo size and position
    }

    // 2. Student Name
    $full_name = !empty($student['custom_name']) ? $student['custom_name'] : strtoupper($student['first_name'] . ' ' . $student['last_name']);

    // 3. Text Rendering (Matching the HTML structure)
    
    // Title
    $pdf->SetFont('Times', 'B', 26);
    $pdf->SetTextColor(10, 110, 45);
    $pdf->SetY(70);
    $pdf->Cell(0, 12, strtoupper($settings['title_text']), 0, 1, 'C');

    // Subtitle
    $pdf->SetFont('Times', '', 12);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, strtoupper($settings['subtitle_text']), 0, 1, 'C');

    // Name
    $pdf->SetFont('Times', 'B', 28);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(90);
    $pdf->Cell(0, 15, strtoupper($full_name), 0, 1, 'C');
    
    // Draw Underline for Name
    $name_width = $pdf->GetStringWidth(strtoupper($full_name));
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(148.5 - ($name_width / 2), 108, 148.5 + ($name_width / 2), 108); 

    // "Has successfully..."
    $pdf->SetFont('Times', '', 12);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetY(115);
    $pdf->Cell(0, 8, 'has successfully completed all required activities and modules for the', 0, 1, 'C');

    // ACES Program text
    $pdf->SetFont('Times', 'B', 16);
    $pdf->SetTextColor(10, 110, 45);
    $pdf->Cell(0, 10, 'Alumni Career, Enhancement and Services (ACES) Program', 0, 1, 'C');

    // 4. Footer (Date & Signature)
    $pdf->SetY(165);
    
    // --- Date (Left Aligned) ---
    $pdf->SetFont('Times', '', 12);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(30); // Push to the left
    $pdf->Cell(80, 10, 'Date Issued: ' . date('F d, Y'), 0, 0, 'L'); 

    // --- Signature (Right Aligned) ---
    $pdf->SetX(185); // Push to the right
    $pdf->Cell(80, 10, '_________________________', 0, 1, 'C');
    
    $pdf->SetX(185);
    $pdf->SetFont('Times', 'B', 14);
    $pdf->Cell(80, 6, strtoupper($settings['sig_name']), 0, 1, 'C');
    
    $pdf->SetX(185);
    $pdf->SetFont('Times', '', 11);
    $pdf->Cell(80, 6, $settings['sig_title'], 0, 1, 'C');
}