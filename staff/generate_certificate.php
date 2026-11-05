<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/fpdf.php';

// NEW: Allow Students to Download via this endpoint, or Staff to generate
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
$is_student = (isset($_SESSION['role']) && $_SESSION['role'] === 'student');

// Fetch Template Settings
$settings = $pdo->query("SELECT * FROM certificate_settings WHERE id = 1")->fetch();
$bg_path = (!empty($settings['background_image']) && file_exists(__DIR__ . '/../' . $settings['background_image'])) 
            ? __DIR__ . '/../' . $settings['background_image'] : null;

$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);

// ==========================================
// 1. STUDENT DOWNLOAD ENDPOINT
// ==========================================
if ($is_student && isset($_GET['download_id'])) {
    $req_id = (int)$_GET['download_id'];
    $stmt = $pdo->prepare("
        SELECT cr.subtopic_id, s.first_name, s.last_name, s.program, cr.custom_name 
        FROM certificate_requests cr 
        JOIN students s ON cr.student_id = s.student_id 
        WHERE cr.request_id = ? AND cr.student_id = ? AND cr.status = 'generated'
    ");
    $stmt->execute([$req_id, $_SESSION['student_id']]);
    $student = $stmt->fetch();

    if (!$student) die("Certificate not found or not yet generated.");

    $cert_type = empty($student['subtopic_id']) ? 'overall' : 'seminar';
    $subtopic_data = null;

    if ($cert_type === 'seminar') {
        $stmt_sub = $pdo->prepare("SELECT st.title as sub_title, s.title as sess_title FROM subtopics st JOIN sessions s ON st.session_id = s.session_id WHERE st.subtopic_id = ?");
        $stmt_sub->execute([$student['subtopic_id']]);
        $subtopic_data = $stmt_sub->fetch();
    }

    generateCertificatePage($pdf, $student, $bg_path, $settings, $cert_type, $subtopic_data);
    $pdf->Output('D', 'ACES_Certificate_' . $student['last_name'] . '.pdf');
    exit;
}

// ==========================================
// 2. STAFF TEMPLATE PREVIEW
// ==========================================
if (!$is_student && isset($_GET['preview_template'])) {
    $dummy_student = ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'custom_name' => null];
    generateCertificatePage($pdf, $dummy_student, $bg_path, $settings, 'overall', null);
    $pdf->Output('I', 'Template_Preview.pdf');
    exit;
}

// ==========================================
// 3. STAFF BULK GENERATION
// ==========================================
if (!$is_student && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_ids'])) {
    $ids = explode(',', $_POST['student_ids']);
    $is_preview = isset($_POST['is_preview']) && $_POST['is_preview'] == '1';
    
    // NEW: Get Certificate Type
    $cert_type = $_POST['cert_type'] ?? 'overall'; 
    $subtopic_id = ($cert_type === 'seminar') ? (int)$_POST['subtopic_id'] : null;
    
    $subtopic_data = null;
    if ($cert_type === 'seminar') {
        $stmt_sub = $pdo->prepare("SELECT st.title as sub_title, s.title as sess_title FROM subtopics st JOIN sessions s ON st.session_id = s.session_id WHERE st.subtopic_id = ?");
        $stmt_sub->execute([$subtopic_id]);
        $subtopic_data = $stmt_sub->fetch();
        if (!$subtopic_data) die("Invalid Seminar selected.");
    }

    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    
    $stmt = $pdo->prepare("
        SELECT cr.request_id, cr.custom_name, s.student_id, s.first_name, s.last_name, s.program 
        FROM students s
        LEFT JOIN certificate_requests cr ON cr.student_id = s.student_id AND cr.subtopic_id <=> ? 
        WHERE s.student_id IN ($inQuery)
    ");
    // Use <=> (null safe equal) to match the exact request type (overall vs seminar)
    $params = array_merge([$subtopic_id], $ids);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    if (empty($students)) die("No students found.");

    $generated_count = 0;
    $skipped_count = 0;

    foreach ($students as $student) {
        $is_eligible = true;
        
        // ONLY check 100% compliance if it's the Overall Certificate
        if ($cert_type === 'overall') {
            $stmt_stats = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM registrations r WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned')) AS total_subs,
                    (SELECT COUNT(*) FROM attendance a WHERE a.student_id = ? AND a.attendance_status = 'present' AND a.is_deleted = 0) AS present_subs,
                    (SELECT COUNT(*) FROM registrations r JOIN modules m ON r.subtopic_id = m.subtopic_id WHERE r.student_id = ? AND r.status IN ('assigned','auto_assigned') AND m.is_deleted = 0) AS total_mods,
                    (SELECT COUNT(*) FROM student_module_progress smp WHERE smp.student_id = ? AND smp.completed = 1) AS completed_mods
            ");
            $stmt_stats->execute([$student['student_id'], $student['student_id'], $student['student_id'], $student['student_id']]);
            $stats = $stmt_stats->fetch();
            
            if (!($stats['total_subs'] > 0 && $stats['present_subs'] >= $stats['total_subs'] && $stats['completed_mods'] >= $stats['total_mods'])) {
                $is_eligible = false;
            }
        }

        if ($is_eligible) {
            generateCertificatePage($pdf, $student, $bg_path, $settings, $cert_type, $subtopic_data);
            $generated_count++;

            // Record in DB (Insert if new, Update if exists)
            if (!$is_preview) {
                if (!empty($student['request_id'])) {
                    $pdo->prepare("UPDATE certificate_requests SET status = 'generated', processed_at = NOW(), processed_by = ? WHERE request_id = ?")
                        ->execute([$_SESSION['user_id'], $student['request_id']]);
                } else {
                    $pdo->prepare("INSERT INTO certificate_requests (student_id, subtopic_id, status, processed_at, processed_by) VALUES (?, ?, 'generated', NOW(), ?)")
                        ->execute([$student['student_id'], $subtopic_id, $_SESSION['user_id']]);
                }
            }
        } else {
            $skipped_count++;
        }
    }

    if ($generated_count === 0) {
        die("None of the selected students are eligible for this certificate. (Overall requires 100% compliance).");
    }

    if ($is_preview) {
        $pdf->Output('I', 'Preview.pdf');
    } else {
        $pdf->Output('D', 'ACES_Certificates_' . date('Ymd') . '.pdf');
    }
    exit;
}

// ==========================================
// 4. FALLBACK (If accessed directly without parameters)
// ==========================================
header('Location: certificates.php');
exit;


// ==========================================
// HELPER FUNCTION TO DRAW CERTIFICATE
// ==========================================
function generateCertificatePage($pdf, $student, $bg_path, $settings, $cert_type = 'overall', $subtopic_data = null) {
    $pdf->AddPage();
    
    // 1. Draw Background or Default Border
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

    // 2. Student Name
    $full_name = !empty($student['custom_name']) ? $student['custom_name'] : strtoupper($student['first_name'] . ' ' . $student['last_name']);

    // 3. Determine Dynamic Text based on Type
    if ($cert_type === 'seminar') {
        $title_text = 'CERTIFICATE OF PARTICIPATION';
        $subtitle_text = 'This is to certify that';
        $body_text = 'has successfully participated in';
        $program_text = strtoupper($subtopic_data['sess_title'] . ' - ' . $subtopic_data['sub_title']);
    } else {
        $title_text = strtoupper($settings['title_text']); // Default: CERTIFICATE OF COMPLETION
        $subtitle_text = strtoupper($settings['subtitle_text']);
        $body_text = 'has successfully completed all required activities and modules for the';
        $program_text = 'Alumni Career, Enhancement and Services (ACES) Program';
    }
    
    // Title
    $pdf->SetFont('Times', 'B', 26);
    $pdf->SetTextColor(10, 110, 45);
    $pdf->SetY(70);
    $pdf->Cell(0, 12, $title_text, 0, 1, 'C');

    // Subtitle
    $pdf->SetFont('Times', '', 12);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, $subtitle_text, 0, 1, 'C');

    // Name
    $pdf->SetFont('Times', 'B', 28);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(90);
    $pdf->Cell(0, 15, strtoupper($full_name), 0, 1, 'C');
    
    // Underline
    $name_width = $pdf->GetStringWidth(strtoupper($full_name));
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line(148.5 - ($name_width / 2), 108, 148.5 + ($name_width / 2), 108); 

    // Body
    $pdf->SetFont('Times', '', 12);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetY(115);
    $pdf->Cell(0, 8, $body_text, 0, 1, 'C');

    // Program/Seminar Name
    $pdf->SetFont('Times', 'B', 16);
    $pdf->SetTextColor(10, 110, 45);
    $pdf->Cell(0, 10, $program_text, 0, 1, 'C');

    // 4. Footer (Date & Signature)
    $pdf->SetY(165);
    
    $pdf->SetFont('Times', '', 12);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(30); 
    $pdf->Cell(80, 10, 'Date Issued: ' . date('F d, Y'), 0, 0, 'L'); 

    $pdf->SetX(185); 
    
    // Check if a signature image was uploaded
    $sig_image_path = (!empty($settings['signature_image']) && file_exists(__DIR__ . '/../' . $settings['signature_image'])) 
                      ? __DIR__ . '/../' . $settings['signature_image'] : null;

    if ($sig_image_path) {
        // Print the signature image (adjust width/height as needed)
        // X=207 centers it over the 80mm width, Y=150 moves it up slightly
        $pdf->Image($sig_image_path, 207, 150, 35); 
        // Draw a smaller line under the signature if desired, or skip the line
        $pdf->Cell(80, 10, '', 0, 1, 'C'); 
    } else {
        // Fallback to the text line
        $pdf->Cell(80, 10, '_________________________', 0, 1, 'C');
    }
    
    $pdf->SetX(185);
    $pdf->SetFont('Times', 'B', 14);
    $pdf->Cell(80, 6, strtoupper($settings['sig_name']), 0, 1, 'C');
    
    $pdf->SetX(185);
    $pdf->SetFont('Times', '', 11);
    $pdf->Cell(80, 6, $settings['sig_title'], 0, 1, 'C');
}
?>