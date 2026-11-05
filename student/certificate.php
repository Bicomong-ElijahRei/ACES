<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotStudent();

$student_id = $_SESSION['student_id'];

// Fetch ONLY generated certificates for this student
$stmt = $pdo->prepare("
    SELECT cr.request_id, cr.subtopic_id, cr.processed_at, 
           st.title as subtopic_title, s.title as session_title 
    FROM certificate_requests cr
    LEFT JOIN subtopics st ON cr.subtopic_id = st.subtopic_id
    LEFT JOIN sessions s ON st.session_id = s.session_id
    WHERE cr.student_id = ? AND cr.status = 'generated'
    ORDER BY cr.processed_at DESC
");
$stmt->execute([$student_id]);
$certificates = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Certificates | ACES Student</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex overflow-hidden">
    <?php include '../includes/student_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden">
        <?php include '../includes/header.php'; ?>
        
        <main class="flex-1 p-8 overflow-y-auto">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-[#0a6e2d]">My Certificates</h1>
                <p class="text-gray-600">Download your officially issued ACES certificates here.</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 uppercase text-xs font-semibold">
                        <tr>
                            <th class="py-4 px-6">Certificate Title</th>
                            <th class="py-4 px-6">Date Issued</th>
                            <th class="py-4 px-6 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (count($certificates) > 0): ?>
                            <?php foreach ($certificates as $cert): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-4 px-6">
                                        <div class="font-bold text-gray-800">
                                            <?php if ($cert['subtopic_id']): ?>
                                                Certificate of Participation
                                            <?php else: ?>
                                                Certificate of Completion (Overall)
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($cert['subtopic_id']): ?>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?= htmlspecialchars($cert['session_title'] . ' - ' . $cert['subtopic_title']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-6 text-gray-600">
                                        <?= date('M d, Y', strtotime($cert['processed_at'])) ?>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <!-- Directs to generate_certificate.php with a download flag -->
                                        <a href="../staff/generate_certificate.php?download_id=<?= $cert['request_id'] ?>" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-bold text-xs transition shadow-sm">
                                            <i class="fas fa-download mr-2"></i> Download PDF
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="py-12 text-center text-gray-500">
                                    <i class="fas fa-folder-open text-4xl mb-3 text-gray-300"></i>
                                    <p>No certificates have been issued to you yet.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>