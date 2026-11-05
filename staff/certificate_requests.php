<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

$success = '';
$error = '';

// Handle Template Text Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_template') {
    csrf_verify();
    $stmt = $pdo->prepare("UPDATE certificate_settings SET title_text = ?, subtitle_text = ?, sig_name = ?, sig_title = ? WHERE id = 1");
    $stmt->execute([trim($_POST['title_text']), trim($_POST['subtitle_text']), trim($_POST['sig_name']), trim($_POST['sig_title'])]);
    $success = "Template text updated successfully!";
}

// Handle Background Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_bg') {
    csrf_verify();
    if (isset($_FILES['bg_image']) && $_FILES['bg_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['bg_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $upload_dir = __DIR__ . '/../assets/images/certificate_bg/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'cert_bg.' . $ext;
            if (move_uploaded_file($_FILES['bg_image']['tmp_name'], $upload_dir . $filename)) {
                $pdo->query("UPDATE certificate_settings SET background_image = 'assets/images/certificate_bg/$filename' WHERE id = 1");
                $success = "Certificate background updated successfully!";
            }
        } else { $error = "Only JPG and PNG files are allowed."; }
    }
}
// Handle Signature Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_sig') {
    csrf_verify();
    if (isset($_FILES['sig_image']) && $_FILES['sig_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['sig_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $upload_dir = __DIR__ . '/../assets/images/certificate_sig/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'cert_sig.' . $ext;
            if (move_uploaded_file($_FILES['sig_image']['tmp_name'], $upload_dir . $filename)) {
                $pdo->query("UPDATE certificate_settings SET signature_image = 'assets/images/certificate_sig/$filename' WHERE id = 1");
                $success = "Signature image updated successfully!";
            }
        } else { $error = "Only JPG and PNG files are allowed for signature."; }
    }
}
// Handle Signature Removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_sig') {
    csrf_verify();
    $pdo->query("UPDATE certificate_settings SET signature_image = NULL WHERE id = 1");
    $success = "Signature image removed successfully!";
}

// Fetch Settings
$settings = $pdo->query("SELECT * FROM certificate_settings WHERE id = 1")->fetch();

// Fetch Students for OVERALL certificate (Only 100% compliant)
$stmt = $pdo->query("
    SELECT s.student_id, s.first_name, s.last_name, s.program, s.section,
           (SELECT COUNT(DISTINCT r.subtopic_id) FROM registrations r WHERE r.student_id = s.student_id AND r.status IN ('assigned','auto_assigned')) AS total_subs,
           (SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a WHERE a.student_id = s.student_id AND a.attendance_status = 'present' AND a.is_deleted = 0) AS present_subs,
           (SELECT COUNT(*) FROM registrations r JOIN modules m ON r.subtopic_id = m.subtopic_id WHERE r.student_id = s.student_id AND r.status IN ('assigned','auto_assigned') AND m.is_deleted = 0) AS total_mods,
           (SELECT COUNT(*) FROM student_module_progress smp WHERE smp.student_id = s.student_id AND smp.completed = 1) AS completed_mods
    FROM students s
    WHERE s.is_deleted = 0
    ORDER BY s.last_name, s.first_name
");
$all_students = $stmt->fetchAll();
$compliant_students = [];

foreach ($all_students as $r) {
    $total_items = $r['total_subs'] + $r['total_mods'];
    $done_items = min($r['total_subs'], $r['present_subs']) + min($r['total_mods'], $r['completed_mods']);
    $r['progress_pct'] = ($total_items > 0) ? round(($done_items / $total_items) * 100) : 0;
    if ($r['progress_pct'] === 100) {
        $compliant_students[] = $r;
    }
}

// Fetch Sessions/Subtopics for PER-SEMINAR certificates
$stmt_sub = $pdo->query("
    SELECT st.subtopic_id, st.title AS subtopic_title, s.title AS session_title, s.date,
           (SELECT COUNT(*) FROM registrations r WHERE r.subtopic_id = st.subtopic_id) AS total_registrants,
           (SELECT COUNT(*) FROM attendance a WHERE a.subtopic_id = st.subtopic_id AND a.attendance_status = 'present' AND a.is_deleted = 0) AS present_count
    FROM subtopics st
    JOIN sessions s ON st.session_id = s.session_id
    WHERE st.is_deleted = 0 AND s.is_deleted = 0
    ORDER BY s.date DESC
");
$subtopics = $stmt_sub->fetchAll();

// Fetch ALL registrants per subtopic (Present, Absent, Pending)
$attendees_data = [];
foreach ($subtopics as $sub) {
    $stmt_att = $pdo->prepare("
        SELECT s.student_id, s.first_name, s.last_name, s.program, s.section, 
               a.attendance_status, cr.request_id 
        FROM registrations r
        JOIN students s ON r.student_id = s.student_id
        LEFT JOIN attendance a ON a.student_id = r.student_id AND a.subtopic_id = r.subtopic_id
        LEFT JOIN certificate_requests cr ON cr.student_id = r.student_id AND cr.subtopic_id = r.subtopic_id
        WHERE r.subtopic_id = ? AND s.is_deleted = 0
        ORDER BY s.last_name, s.first_name
    ");
    $stmt_att->execute([$sub['subtopic_id']]);
    $attendees_data[$sub['subtopic_id']] = $stmt_att->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Manager | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-8 overflow-y-auto">
            
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-[#0a6e2d]">Certificate Manager</h1>
                    <p class="text-sm text-gray-600 mt-1">Customize template and issue certificates to students.</p>
                </div>
                <a href="generate_certificate.php?preview_template=1" target="_blank" class="inline-flex items-center px-4 py-2 rounded-lg font-bold text-sm shadow-sm transition transform hover:scale-105" style="background-color: #e0e7ff; color: #3730a3; border: 1px solid #a5b4fc;">
                    <i class="fas fa-eye mr-2"></i> Preview Template
                </a>
            </div>

            <?php if ($success): ?><div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4"><i class="fas fa-check-circle mr-2"></i> <?= $success ?></div><?php endif; ?>
            <?php if ($error): ?><div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4"><i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?></div><?php endif; ?>

            <!-- 1. TEMPLATE EDITOR -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="font-bold text-gray-800 mb-4"><i class="fas fa-edit text-indigo-500 mr-2"></i> Edit Template Text</h2>
                    <form method="POST" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_template">
                        <div>
                            <label class="text-xs font-bold text-gray-600">Main Title (Overall)</label>
                            <input type="text" name="title_text" value="<?= htmlspecialchars($settings['title_text']) ?>" class="w-full border rounded-lg p-2 text-sm" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-600">Subtitle (Overall)</label>
                            <input type="text" name="subtitle_text" value="<?= htmlspecialchars($settings['subtitle_text']) ?>" class="w-full border rounded-lg p-2 text-sm" required>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-xs font-bold text-gray-600">Signatory Name</label>
                                <input type="text" name="sig_name" value="<?= htmlspecialchars($settings['sig_name']) ?>" class="w-full border rounded-lg p-2 text-sm" required>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-gray-600">Signatory Title</label>
                                <input type="text" name="sig_title" value="<?= htmlspecialchars($settings['sig_title']) ?>" class="w-full border rounded-lg p-2 text-sm" required>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-gray-800 hover:bg-black text-white font-bold py-2 rounded-lg text-sm transition">Save Text Settings</button>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 flex flex-col justify-center">
                    <h2 class="font-bold text-gray-800 mb-4"><i class="fas fa-image text-indigo-500 mr-2"></i> Background Image</h2>
                    <p class="text-xs text-gray-500 mb-4">Upload an A4 Landscape design. The text will overlay on top.</p>
                    <form method="POST" enctype="multipart/form-data" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_bg">
                        <input type="file" name="bg_image" accept=".jpg,.jpeg,.png" required class="w-full border border-gray-300 rounded-lg p-2 text-sm">
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded-lg text-sm transition">Upload Background</button>
                    </form>
                </div>

                <!-- SIGNATURE UPLOADER (WITH PREVIEW & REMOVE) -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 flex flex-col justify-center">
                    <h2 class="font-bold text-gray-800 mb-4"><i class="fas fa-signature text-indigo-500 mr-2"></i> Signature Image</h2>
                    
                    <?php if (!empty($settings['signature_image']) && file_exists(__DIR__ . '/../' . $settings['signature_image'])): ?>
                        <!-- Show existing signature -->
                        <div class="mb-4 text-center">
                            <p class="text-xs font-bold text-green-600 mb-2"><i class="fas fa-check-circle"></i> Current Signature:</p>
                            <img src="../<?= htmlspecialchars($settings['signature_image']) ?>?t=<?= time() ?>" alt="Signature" class="max-h-24 mx-auto object-contain border border-gray-200 p-2 rounded bg-gray-50">
                            
                            <form method="POST" class="mt-3">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_sig">
                                <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-bold transition bg-red-50 hover:bg-red-100 px-3 py-1 rounded" onclick="return confirm('Remove this signature?');">
                                    <i class="fas fa-trash-alt mr-1"></i> Remove Signature
                                </button>
                            </form>
                        </div>
                        <hr class="mb-3 border-gray-200">
                        <p class="text-xs text-gray-500 mb-2">Upload a new one to replace it:</p>
                    <?php else: ?>
                        <p class="text-xs text-gray-500 mb-4">Upload a transparent PNG or JPG of the ACES Lead's signature.</p>
                    <?php endif; ?>

                    <!-- Upload Form -->
                    <form method="POST" enctype="multipart/form-data" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_sig">
                        <input type="file" name="sig_image" accept=".jpg,.jpeg,.png" required class="w-full border border-gray-300 rounded-lg p-2 text-sm">
                        <button type="submit" class="w-full bg-gray-800 hover:bg-black text-white font-bold py-2 rounded-lg text-sm transition">
                            <?= !empty($settings['signature_image']) ? 'Upload New Signature' : 'Upload Signature' ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- 2. OVERALL COMPLETION CERTIFICATE -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="font-bold text-gray-800 text-lg"><i class="fas fa-award text-amber-500 mr-2"></i> Overall ACES Completion Certificate</h2>
                        <p class="text-sm text-gray-500 mt-1">Issue the final certificate to students who are 100% compliant across all phases.</p>
                    </div>
                    <button onclick="openOverallModal()" class="inline-flex items-center text-white px-4 py-2 rounded-lg font-bold text-sm shadow-md transition transform hover:scale-105" style="background-color: #4f46e5; border: 1px solid #4338ca;">
                        <i class="fas fa-layer-group mr-2"></i> Generate Overall
                    </button>
                </div>
            </div>

            <!-- 3. PER-SEMINAR CERTIFICATES -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-10">
                <div class="p-6 border-b border-gray-200 bg-gray-50">
                    <h2 class="font-bold text-gray-800 text-lg"><i class="fas fa-certificate text-blue-500 mr-2"></i> Per-Seminar Certificates</h2>
                    <p class="text-sm text-gray-500 mt-1">Select a specific session and subtopic to issue Certificates of Participation to attendees.</p>
                </div>
                
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-white border-b border-gray-200 text-gray-500 uppercase text-xs font-semibold">
                        <tr>
                            <th class="py-4 px-6">Session Title</th>
                            <th class="py-4 px-6">Subtopic</th>
                            <th class="py-4 px-6">Date</th>
                            <th class="py-4 px-6 text-center">Status</th>
                            <th class="py-4 px-6 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($subtopics as $sub): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-4 px-6 font-medium text-gray-800"><?= htmlspecialchars($sub['session_title']) ?></td>
                                <td class="py-4 px-6 text-gray-600"><?= htmlspecialchars($sub['subtopic_title']) ?></td>
                                <td class="py-4 px-6 text-xs text-gray-500"><?= date('M d, Y', strtotime($sub['date'])) ?></td>
                                <td class="py-4 px-6 text-center">
                                    <div class="text-sm font-bold text-gray-800"><?= $sub['total_registrants'] ?> Registered</div>
                                    <div class="text-xs font-bold text-green-600"><?= $sub['present_count'] ?> Present</div>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <button type="button" 
                                            class="manage-cert-btn bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-3 py-1.5 rounded-lg text-xs font-bold transition" 
                                            data-id="<?= $sub['subtopic_id'] ?>" 
                                            data-session="<?= htmlspecialchars($sub['session_title']) ?>" 
                                            data-subtopic="<?= htmlspecialchars($sub['subtopic_title']) ?>">
                                        Manage Certificates
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- ================= MODALS ================= -->

    <!-- OVERALL MODAL -->
    <div id="overallModal" class="hidden fixed inset-0 z-50 blur-bg flex items-center justify-center p-4" style="backdrop-filter: blur(8px); background-color: rgba(0,0,0,0.6);">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl flex flex-col overflow-hidden" style="height: 90vh;">
            <div class="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center shrink-0">
                <h3 class="font-bold text-gray-800 text-lg"><i class="fas fa-award text-amber-500 mr-2"></i>Select Students for Overall Completion</h3>
                <button onclick="document.getElementById('overallModal').classList.add('hidden')" class="text-gray-500 hover:text-red-500"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <div class="p-4 border-b border-gray-200 shrink-0 bg-gray-50 flex flex-col md:flex-row justify-between items-center gap-3">
                <div class="flex items-center gap-4 w-full md:w-auto">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="selectAllOverall" class="rounded text-indigo-600 focus:ring-indigo-500 w-5 h-5" onchange="document.querySelectorAll('.overall-checkbox').forEach(cb => { if(cb.closest('.overall-row').style.display !== 'none') cb.checked = this.checked; }); updateOverallCount();">
                        <span class="text-sm font-bold text-indigo-900">Select All Visible</span>
                    </label>
                </div>
                
                <div class="flex items-center gap-2 w-full md:w-auto">
                    <input type="text" id="overallSearch" placeholder="Search name or ID..." class="border border-gray-300 rounded-lg p-1.5 text-sm w-full md:w-64" onkeyup="filterOverallStudents()">
                    <span class="text-sm font-bold text-gray-600 whitespace-nowrap">Selected: <span id="overallCount" class="text-indigo-600">0</span></span>
                </div>
            </div>

            <div class="p-4" style="flex: 1; overflow-y: auto;">
                <?php if(empty($compliant_students)): ?>
                    <p class="text-center text-gray-500 py-10">No students are currently 100% compliant.</p>
                <?php else: ?>
                    <?php foreach ($compliant_students as $req): ?>
                        <label class="overall-row flex items-center gap-3 p-3 hover:bg-gray-50 rounded-lg cursor-pointer border-b border-gray-100" 
                               data-name="<?= strtolower($req['first_name'] . ' ' . $req['last_name'] . ' ' . $req['student_id']) ?>">
                            <input type="checkbox" class="overall-checkbox rounded text-indigo-600 w-5 h-5" value="<?= $req['student_id'] ?>" onchange="updateOverallCount()">
                            <div class="flex-1">
                                <div class="font-bold text-sm text-gray-800"><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></div>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($req['student_id']) ?> • <?= htmlspecialchars($req['program']) ?> - <?= htmlspecialchars($req['section']) ?></div>
                            </div>
                            <span class="text-[10px] font-extrabold px-1.5 py-0.5 rounded bg-green-100 text-green-700">100%</span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="p-4 border-t border-gray-200 bg-gray-50 flex justify-end shrink-0">
                <button onclick="previewOverall()" class="text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md transition transform hover:scale-105" style="background-color: #4f46e5;">
                    Preview & Export <i class="fas fa-arrow-right ml-1"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- SEMINAR MODAL -->
    <div id="seminarModal" class="hidden fixed inset-0 z-50 blur-bg flex items-center justify-center p-4" style="backdrop-filter: blur(8px); background-color: rgba(0,0,0,0.6);">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl flex flex-col overflow-hidden" style="height: 90vh;">
            <div class="p-4 border-b border-gray-200 bg-blue-50 flex justify-between items-center shrink-0">
                <div>
                    <h3 class="font-bold text-gray-800 text-lg"><i class="fas fa-certificate text-blue-500 mr-2"></i>Issue Seminar Certificates</h3>
                    <p class="text-xs text-blue-700 mt-1" id="seminarHeaderTitle">Loading...</p>
                </div>
                <button onclick="document.getElementById('seminarModal').classList.add('hidden')" class="text-gray-500 hover:text-red-500"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <div class="p-4 border-b border-gray-200 shrink-0 bg-gray-50 flex flex-col md:flex-row justify-between items-center gap-3">
                <div class="flex items-center gap-4 w-full md:w-auto">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="selectAllSeminar" class="rounded text-blue-600 focus:ring-blue-500 w-5 h-5" onchange="document.querySelectorAll('.seminar-checkbox').forEach(cb => { if(cb.closest('.student-row-seminar').style.display !== 'none') cb.checked = this.checked; }); updateSeminarCount();">
                        <span class="text-sm font-bold text-blue-900">Select All Visible</span>
                    </label>
                    
                    <button onclick="selectAllPresent()" type="button" class="bg-green-100 hover:bg-green-200 text-green-800 text-xs font-bold px-3 py-1.5 rounded-lg border border-green-300 transition">
                        <i class="fas fa-check-circle mr-1"></i> Quick Select Present
                    </button>
                </div>
                
                <div class="flex items-center gap-2 w-full md:w-auto">
                    <select id="seminarStatusFilter" onchange="filterSeminarStudents()" class="border border-gray-300 rounded-lg p-1.5 text-sm w-full md:w-auto">
                        <option value="">All Registrants</option>
                        <option value="present">Present Only</option>
                        <option value="absent">Absent Only</option>
                        <option value="pending">Pending Only</option>
                    </select>
                    <span class="text-sm font-bold text-gray-600 whitespace-nowrap">Selected: <span id="seminarCount" class="text-blue-600">0</span></span>
                </div>
            </div>

            <div class="p-4" style="flex: 1; overflow-y: auto;" id="seminarStudentList">
                <!-- Populated by JS -->
            </div>

            <div class="p-4 border-t border-gray-200 bg-gray-50 flex justify-end shrink-0">
                <button onclick="previewSeminar()" class="text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md transition transform hover:scale-105" style="background-color: #2563eb;">
                    Preview & Export <i class="fas fa-arrow-right ml-1"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- PREVIEW MODAL -->
    <div id="previewModal" class="hidden fixed inset-0 flex flex-col p-0 m-0" style="z-index: 2147483647 !important; background-color: rgba(0,0,0,0.9);">
        <div class="flex justify-between items-center p-3 bg-white shrink-0 border-b border-gray-200 relative z-50">
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('previewModal').classList.add('hidden')" class="text-red-500 hover:text-red-700 bg-red-50 p-2 rounded-lg"><i class="fas fa-times text-xl"></i></button>
                <h3 class="text-gray-800 font-bold text-lg"><i class="fas fa-file-pdf text-red-500 mr-2"></i> Live PDF Preview</h3>
            </div>
            <div class="flex gap-2">
                <button onclick="document.getElementById('previewModal').classList.add('hidden')" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-bold">Cancel</button>
                <button onclick="confirmExport()" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md"><i class="fas fa-download mr-2"></i> Confirm & Download</button>
            </div>
        </div>
        <div class="flex-1 w-full bg-gray-300 relative">
            <iframe name="pdfFrame" id="pdfFrame" class="absolute inset-0 w-full h-full border-0"></iframe>
        </div>
    </div>

    <!-- HIDDEN FORM FOR EXPORTING -->
    <form id="pdfForm" method="POST" action="generate_certificate.php" target="pdfFrame" class="hidden">
        <input type="hidden" name="student_ids" id="exportIds">
        <input type="hidden" name="is_preview" id="isPreviewInput" value="1">
        <input type="hidden" name="cert_type" id="exportCertType" value="overall">
        <input type="hidden" name="subtopic_id" id="exportSubtopicId" value="">
    </form>

    <script>
        // Data from PHP
        const allAttendees = <?= json_encode($attendees_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        
        // ==========================================
        // OVERALL MODAL LOGIC
        // ==========================================
        function openOverallModal() {
            document.getElementById('overallModal').classList.remove('hidden');
        }

        function updateOverallCount() {
            document.getElementById('overallCount').textContent = document.querySelectorAll('.overall-checkbox:checked').length;
        }

        function filterOverallStudents() {
            const query = document.getElementById('overallSearch').value.toLowerCase();
            document.querySelectorAll('.overall-row').forEach(row => {
                const name = row.getAttribute('data-name');
                if (name.includes(query)) {
                    row.style.display = 'flex';
                } else {
                    row.style.display = 'none';
                }
            });
            document.getElementById('selectAllOverall').checked = false;
        }

        function previewOverall() {
            const checked = Array.from(document.querySelectorAll('.overall-checkbox:checked')).map(cb => cb.value);
            if (checked.length === 0) return alert('Select at least one student.');
            
            document.getElementById('exportIds').value = checked.join(',');
            document.getElementById('exportCertType').value = 'overall';
            document.getElementById('exportSubtopicId').value = '';
            
            showPreview();
        }
        

        // ==========================================
        // ATTACH CLICK LISTENER TO BUTTONS
        // ==========================================
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.manage-cert-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const session = this.getAttribute('data-session');
                    const subtopic = this.getAttribute('data-subtopic');
                    
                    // Call the function
                    openSeminarModal(id, session, subtopic);
                });
            });
        });

        // ==========================================
        // SEMINAR MODAL LOGIC
        // ==========================================
        function openSeminarModal(subtopicId, sessionTitle, subtopicTitle) {
            document.getElementById('seminarHeaderTitle').textContent = sessionTitle + ' - ' + subtopicTitle;
            document.getElementById('exportSubtopicId').value = subtopicId;
            
            const listDiv = document.getElementById('seminarStudentList');
            const students = allAttendees[subtopicId] || [];
            
            if (students.length === 0) {
                listDiv.innerHTML = '<p class="text-center text-gray-500 py-10">No registrants found for this subtopic.</p>';
            } else {
                listDiv.innerHTML = students.map(st => {
                    let statusColor = 'bg-gray-100 text-gray-500';
                    let statusText = 'Not Recorded';
                    
                    if (st.attendance_status === 'present') { 
                        statusColor = 'bg-green-100 text-green-700'; 
                        statusText = 'Present'; 
                    } else if (st.attendance_status === 'absent') { 
                        statusColor = 'bg-red-100 text-red-700'; 
                        statusText = 'Absent'; 
                    } else if (st.attendance_status === 'pending') { 
                        statusColor = 'bg-amber-100 text-amber-700'; 
                        statusText = 'Pending'; 
                    }

                    return `
                    <label class="student-row-seminar flex items-center gap-3 p-3 hover:bg-gray-50 rounded-lg cursor-pointer border-b border-gray-100" data-status="${st.attendance_status || 'none'}">
                        <input type="checkbox" class="seminar-checkbox rounded text-blue-600 w-5 h-5" value="${st.student_id}" onchange="updateSeminarCount()" ${st.attendance_status === 'present' ? 'checked' : ''}>
                        <div class="flex-1">
                            <div class="font-bold text-sm text-gray-800">${st.last_name}, ${st.first_name}</div>
                            <div class="text-xs text-gray-500">${st.student_id} • ${st.program} - ${st.section}</div>
                        </div>
                        <div class="flex gap-2">
                            ${st.request_id ? '<span class="text-[10px] font-bold px-2 py-1 rounded bg-indigo-100 text-indigo-700">Generated</span>' : ''}
                            <span class="text-[10px] font-bold px-2 py-1 rounded ${statusColor}">${statusText}</span>
                        </div>
                    </label>
                    `;
                }).join('');
            }
            
            document.getElementById('seminarStatusFilter').value = '';
            document.getElementById('selectAllSeminar').checked = false;
            filterSeminarStudents(); 
            updateSeminarCount();
            document.getElementById('seminarModal').classList.remove('hidden');
        }

        function updateSeminarCount() {
            document.getElementById('seminarCount').textContent = document.querySelectorAll('.seminar-checkbox:checked').length;
        }

        function filterSeminarStudents() {
            const filter = document.getElementById('seminarStatusFilter').value;
            document.querySelectorAll('.student-row-seminar').forEach(row => {
                const status = row.getAttribute('data-status');
                if (filter === '' || status === filter) {
                    row.style.display = 'flex';
                } else {
                    row.style.display = 'none';
                }
            });
            document.getElementById('selectAllSeminar').checked = false; 
        }

        function selectAllPresent() {
            document.querySelectorAll('.student-row-seminar').forEach(row => {
                if (row.style.display !== 'none' && row.getAttribute('data-status') === 'present') {
                    row.querySelector('.seminar-checkbox').checked = true;
                }
            });
            updateSeminarCount();
        }

        function previewSeminar() {
            const checked = Array.from(document.querySelectorAll('.seminar-checkbox:checked')).map(cb => cb.value);
            if (checked.length === 0) return alert('Select at least one student.');
            
            document.getElementById('exportIds').value = checked.join(',');
            document.getElementById('exportCertType').value = 'seminar';
            
            showPreview();
        }

        // ==========================================
        // PDF PREVIEW & EXPORT LOGIC
        // ==========================================
        function showPreview() {
            document.getElementById('isPreviewInput').value = '1';
            const form = document.getElementById('pdfForm');
            form.target = 'pdfFrame';
            form.submit();
            
            document.getElementById('overallModal').classList.add('hidden');
            document.getElementById('seminarModal').classList.add('hidden');
            document.getElementById('previewModal').classList.remove('hidden');
        }

        function confirmExport() {
            document.getElementById('isPreviewInput').value = '0';
            const form = document.getElementById('pdfForm');
            form.target = '_blank';
            form.submit();
            document.getElementById('previewModal').classList.add('hidden');
            setTimeout(() => { location.reload(); }, 1500);
        }
    </script>
</body>
</html>