<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStudent();

// Fetch student info (Joined with users to get full_name for the sidebar)
$stmt = $pdo->prepare("
    SELECT s.student_id, s.first_name, s.last_name, s.program, s.section, u.full_name 
    FROM students s 
    JOIN users u ON s.user_id = u.user_id 
    WHERE s.student_id = ?
");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: ../login.php');
    exit;
}

// Generate the QR Code URL using a free API
$qr_data = urlencode($student['student_id']);
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data={$qr_data}&color=0a6e2d";
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My QR Code | ACES Student</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/student_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/student_header.php'; ?>
    
    <main class="flex-1 p-4 md:p-8 overflow-y-auto flex items-center justify-center">
        
        <div class="bg-white rounded-3xl shadow-xl border border-gray-200 w-full max-w-md overflow-hidden text-center transform transition-all hover:scale-[1.02] duration-300">
            
            <!-- ID Header -->
            <div class="bg-[#0a6e2d] p-6 text-white">
                <h2 class="text-xl font-bold tracking-wider mb-1">KOLEHIYO NG LUNGSOD NG DASMARIÑAS</h2>
                <p class="text-sm text-green-200 uppercase tracking-widest font-semibold">ACES Digital ID</p>
            </div>

            <!-- QR Code Area -->
            <div class="p-8 bg-gray-50 flex flex-col items-center justify-center">
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-200 mb-4">
                    <img id="qr_image" src="<?= $qr_url ?>" alt="My QR Code" class="w-64 h-64 object-contain">
                </div>
                
                <!-- DOWNLOAD BUTTON -->
                <button onclick="downloadQR()" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-bold py-2.5 px-6 rounded-lg transition-colors shadow-sm inline-flex items-center gap-2 mb-3">
                    <i class="fas fa-download"></i> Download QR Image
                </button>
                
                <p class="text-xs text-gray-400 mt-1"><i class="fas fa-camera mr-1"></i> Show this to the Proctor to scan your attendance.</p>
            </div>

            <!-- Student Details -->
            <div class="p-6 text-left border-t border-gray-100">
                <div class="mb-3">
                    <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Student Name</p>
                    <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Student ID</p>
                        <p class="font-mono text-sm font-semibold text-gray-700"><?= htmlspecialchars($student['student_id']) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Program</p>
                        <p class="text-sm font-semibold text-gray-700 truncate"><?= htmlspecialchars($student['program']) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-bold tracking-wider">Section</p>
                        <p class="text-sm font-semibold text-gray-700"><?= htmlspecialchars($student['section']) ?></p>
                    </div>
                </div>
            </div>

        </div>

    </main>
</div>
<script>
async function downloadQR() {
    const img = document.getElementById('qr_image');
    const imageUrl = img.src;
    const fileName = 'ACES_QR_<?= $student["student_id"] ?>.png';

    try {
        // Fetch the image as a blob to force download
        const response = await fetch(imageUrl);
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        // Fallback: If fetch is blocked by CORS, open the image in a new tab
        const a = document.createElement('a');
        a.href = imageUrl;
        a.target = '_blank';
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        a.remove();
    }
}
</script>
</body>
</html>