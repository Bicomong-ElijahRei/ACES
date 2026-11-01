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
    $title = trim($_POST['title_text']);
    $subtitle = trim($_POST['subtitle_text']);
    $sig_name = trim($_POST['sig_name']);
    $sig_title = trim($_POST['sig_title']);
    
    $stmt = $pdo->prepare("UPDATE certificate_settings SET title_text = ?, subtitle_text = ?, sig_name = ?, sig_title = ? WHERE id = 1");
    $stmt->execute([$title, $subtitle, $sig_name, $sig_title]);
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
                $path = 'assets/images/certificate_bg/' . $filename;
                $pdo->query("UPDATE certificate_settings SET background_image = '$path' WHERE id = 1");
                $success = "Certificate background updated successfully!";
            }
        } else { $error = "Only JPG and PNG files are allowed."; }
    }
}

// Handle Request Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_request') {
    csrf_verify();
    $req_id = (int)$_POST['request_id'];
    $stmt = $pdo->prepare("DELETE FROM certificate_requests WHERE request_id = ?");
    $stmt->execute([$req_id]);
    header("Location: certificate_requests.php?success=deleted");
    exit;
}

// Handle Custom Name Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_name') {
    csrf_verify();
    $req_id = (int)$_POST['request_id'];
    $custom_name = trim($_POST['custom_name']);
    $stmt = $pdo->prepare("UPDATE certificate_requests SET custom_name = ? WHERE request_id = ?");
    $stmt->execute([$custom_name ?: null, $req_id]);
    $success = "Student name for certificate updated!";
}

// Fetch Settings
$settings = $pdo->query("SELECT * FROM certificate_settings WHERE id = 1")->fetch();
$bg_image = $settings['background_image'] ?? null;
$bg_url = $bg_image ? "../$bg_image" : "";

// Fetch ALL Students (and their LATEST request status + COMPLIANCE STATS)
$stmt = $pdo->query("
    SELECT s.student_id, s.first_name, s.last_name, s.program, s.section, 
           MAX(cr.request_id) as request_id, 
           MAX(cr.status) as status, 
           MAX(cr.custom_name) as custom_name, 
           MAX(cr.requested_at) as requested_at,
           (SELECT COUNT(DISTINCT r.subtopic_id) FROM registrations r WHERE r.student_id = s.student_id AND r.status IN ('assigned','auto_assigned')) AS total_subs,
           (SELECT COUNT(DISTINCT a.subtopic_id) FROM attendance a WHERE a.student_id = s.student_id AND a.attendance_status = 'present' AND a.is_deleted = 0) AS present_subs,
           (SELECT COUNT(*) FROM registrations r JOIN modules m ON r.subtopic_id = m.subtopic_id WHERE r.student_id = s.student_id AND r.status IN ('assigned','auto_assigned') AND m.is_deleted = 0) AS total_mods,
           (SELECT COUNT(*) FROM student_module_progress smp WHERE smp.student_id = s.student_id AND smp.completed = 1) AS completed_mods
    FROM students s
    LEFT JOIN certificate_requests cr ON s.student_id = cr.student_id
    WHERE s.is_deleted = 0
    GROUP BY s.student_id
    ORDER BY s.last_name, s.first_name
");
$all_students = $stmt->fetchAll(); // Used for the Bulk Modal (shows everyone)
$requests = []; // Used for the Main Table (shows only requesters)

// Calculate progress percentage for each student
$unique_courses = [];
$unique_sections = [];
foreach ($all_students as &$r) {
    $total_items = $r['total_subs'] + $r['total_mods'];
    $done_items = min($r['total_subs'], $r['present_subs']) + min($r['total_mods'], $r['completed_mods']);
    $r['progress_pct'] = ($total_items > 0) ? round(($done_items / $total_items) * 100) : 0;

    if (!empty($r['program'])) $unique_courses[$r['program']] = $r['program'];
    if (!empty($r['section'])) $unique_sections[$r['section']] = $r['section'];
    
    // ONLY add to the main page table if they actually requested a certificate
    if (!empty($r['status'])) {
        $requests[] = $r;
    }
}
unset($r); // break reference
sort($unique_courses);
sort($unique_sections);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Manager | ACES Staff</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* A4 Landscape Ratio Preview */
        .cert-preview {
            width: 100%; max-width: 800px; aspect-ratio: 297 / 210;
            background-color: white; background-size: cover; background-position: center;
            position: relative; margin: 0 auto 30px auto; padding: 40px;
            display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
            border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .cert-preview.has-bg { border: none; }
        .blur-bg { backdrop-filter: blur(8px); background-color: rgba(0,0,0,0.6); }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">
    <?php include '../includes/staff_sidebar.php'; ?>
    <div class="flex-1 flex flex-col overflow-hidden w-full">
        <?php include '../includes/header.php'; ?>
        <main class="flex-1 p-4 md:p-8 overflow-y-auto">
            
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-[#0a6e2d]">Certificate Manager</h1>
                    <p class="text-sm text-gray-600 mt-1">Customize template, preview, and export.</p>
                </div>
                <div class="flex gap-2">
                    <!-- TEMPLATE PREVIEW BUTTON -->
                    <a href="generate_certificate.php?preview_template=1" target="_blank" class="inline-flex items-center px-4 py-2 rounded-lg font-bold text-sm shadow-sm transition transform hover:scale-105" style="background-color: #e0e7ff; color: #3730a3; border: 1px solid #a5b4fc;">
                        <i class="fas fa-eye mr-2"></i> Preview Template
                    </a>
                    
                    <!-- BULK EXPORT BUTTON (Forced Color) -->
                    <button onclick="openBulkModal()" class="inline-flex items-center text-white px-4 py-2 rounded-lg font-bold text-sm shadow-md transition transform hover:scale-105" style="background-color: #4f46e5; border: 1px solid #4338ca;">
                        <i class="fas fa-layer-group mr-2"></i> Bulk Export
                    </button>
                </div>
            </div>

            <?php if ($success): ?><div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4"><i class="fas fa-check-circle mr-2"></i> <?= $success ?></div><?php endif; ?>
            <?php if ($error): ?><div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4"><i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?></div><?php endif; ?>

            <!-- TEMPLATE EDITOR -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Text Editor -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="font-bold text-gray-800 mb-4"><i class="fas fa-edit text-indigo-500 mr-2"></i> Edit Template Text</h2>
                    <form method="POST" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_template">
                        <div>
                            <label class="text-xs font-bold text-gray-600">Main Title</label>
                            <input type="text" name="title_text" value="<?= htmlspecialchars($settings['title_text']) ?>" class="w-full border rounded-lg p-2 text-sm" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-600">Subtitle</label>
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

                <!-- Background Uploader -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 flex flex-col justify-center">
                    <h2 class="font-bold text-gray-800 mb-4"><i class="fas fa-image text-indigo-500 mr-2"></i> Background Image</h2>
                    <p class="text-xs text-gray-500 mb-4">Upload an A4 Landscape design. The text above will automatically overlay on top.</p>
                    <form method="POST" enctype="multipart/form-data" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_bg">
                        <input type="file" name="bg_image" accept=".jpg,.jpeg,.png" required class="w-full border border-gray-300 rounded-lg p-2 text-sm">
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded-lg text-sm transition">Upload Background</button>
                    </form>
                </div>
            </div>

            <!-- REQUESTS TABLE -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                
                <!-- NEW: TABLE FILTER TABS -->
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex gap-4">
                    <button onclick="filterTable('all')" class="font-bold text-sm pb-1 border-b-2 border-indigo-600 text-indigo-600" id="btn-all">All Requests</button>
                    <button onclick="filterTable('pending')" class="font-bold text-sm pb-1 border-b-2 border-transparent text-gray-500 hover:text-gray-800" id="btn-pending">Pending</button>
                    <button onclick="filterTable('generated')" class="font-bold text-sm pb-1 border-b-2 border-transparent text-gray-500 hover:text-gray-800" id="btn-generated">Generated</button>
                </div>

                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 uppercase text-xs font-semibold">
                        <tr>
                            <th class="py-4 px-6">Student</th>
                            <th class="py-4 px-6">Certificate Name (Editable)</th>
                            <th class="py-4 px-6 text-center">Status / Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($requests as $req): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-3">
                                        <!-- PREVIEW (EYE) BUTTON -->
                                        <?php if (!empty($req['request_id'])): ?>
                                            <a href="generate_certificate.php?id=<?= $req['student_id'] ?>&req_id=<?= $req['request_id'] ?>&preview=1" target="_blank" class="text-gray-400 hover:text-blue-600 transition" title="Preview Certificate">
                                                <i class="fas fa-eye text-lg"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <div>
                                            <div class="font-bold text-gray-800"><?= htmlspecialchars($req['last_name'] . ', ' . $req['first_name']) ?></div>
                                            <div class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($req['student_id']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-6">
                                    <form method="POST" class="flex gap-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_name">
                                        <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                                        <input type="text" name="custom_name" value="<?= htmlspecialchars($req['custom_name'] ?? '') ?>" placeholder="Default: <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?>" class="border rounded-lg p-1.5 text-xs w-full">
                                        <button type="submit" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-bold transition"><i class="fas fa-save"></i></button>
                                    </form>
                                </td>
                                <td class="py-4 px-6 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <?php if (empty($req['status'])): ?>
                                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase bg-gray-100 text-gray-600">Not Requested</span>
                                        <?php elseif ($req['status'] === 'pending'): ?>
                                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase bg-amber-100 text-amber-800">Pending</span>
                                            
                                            <!-- GENERATE BUTTON (Forced Green) -->
                                            <a href="generate_certificate.php?id=<?= $req['student_id'] ?>&req_id=<?= $req['request_id'] ?>" target="_blank" class="inline-flex items-center px-3 py-1.5 rounded-md text-[10px] font-bold shadow-sm transition hover:scale-105" style="background-color: #10b981; color: white; border: 1px solid #059669;">
                                                <i class="fas fa-file-pdf mr-1"></i> Generate
                                            </a>
                                            
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase bg-green-100 text-green-800">Generated</span>
                                            
                                            <!-- NEW: DELETE BUTTON -->
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this record? The student will be able to request it again.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_request">
                                                <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-1.5 rounded-md transition" title="Delete Record">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                            
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- BULK SELECTION MODAL -->
    <div id="bulkModal" class="hidden fixed inset-0 z-50 blur-bg flex items-center justify-center p-4" style="backdrop-filter: blur(8px); background-color: rgba(0,0,0,0.6);">
        <!-- Enforce a fixed height of 90vh so it never overflows the screen -->
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl flex flex-col overflow-hidden" style="height: 90vh;">
            
            <!-- Header (Fixed) -->
            <div class="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center shrink-0">
                <h3 class="font-bold text-gray-800 text-lg"><i class="fas fa-layer-group text-indigo-500 mr-2"></i>Select Students to Export</h3>
                <button onclick="document.getElementById('bulkModal').classList.add('hidden')" class="text-gray-500 hover:text-red-500"><i class="fas fa-times text-xl"></i></button>
            </div>
            
            <!-- Filters & Search (Fixed) -->
            <div class="p-4 border-b border-gray-200 shrink-0 bg-gray-50">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <!-- Search -->
                    <div class="md:col-span-1">
                        <label class="text-[10px] font-bold text-gray-500 uppercase">Search</label>
                        <input type="text" id="studentSearch" placeholder="Name or ID..." class="w-full border border-gray-300 rounded-lg p-2 text-sm" onkeyup="filterStudents()">
                    </div>
                    <!-- Course -->
                    <div class="md:col-span-1">
                        <label class="text-[10px] font-bold text-gray-500 uppercase">Course</label>
                        <select id="filterCourse" class="w-full border border-gray-300 rounded-lg p-2 text-sm" onchange="filterStudents()">
                            <option value="">All Courses</option>
                            <?php foreach ($unique_courses as $crs): ?>
                                <option value="<?= htmlspecialchars($crs) ?>"><?= htmlspecialchars($crs) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Section -->
                    <div class="md:col-span-1">
                        <label class="text-[10px] font-bold text-gray-500 uppercase">Section</label>
                        <select id="filterSection" class="w-full border border-gray-300 rounded-lg p-2 text-sm" onchange="filterStudents()">
                            <option value="">All Sections</option>
                            <?php foreach ($unique_sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Status -->
                    <div class="md:col-span-1">
                        <label class="text-[10px] font-bold text-gray-500 uppercase">Status</label>
                        <select id="filterStatus" class="w-full border border-gray-300 rounded-lg p-2 text-sm" onchange="filterStudents()">
                            <option value="">All Statuses</option>
                            <option value="Not Requested">Not Requested</option>
                            <option value="Pending">Pending</option>
                            <option value="Generated">Generated</option>
                        </select>
                    </div>
                    <!-- NEW: Progress Filter -->
                    <div class="md:col-span-1">
                        <label class="text-[10px] font-bold text-gray-500 uppercase">Progress</label>
                        <select id="filterProgress" class="w-full border border-gray-300 rounded-lg p-2 text-sm" onchange="filterStudents()">
                            <option value="">All Progress</option>
                            <option value="100">100% Compliant</option>
                            <option value="50-99">50% - 99%</option>
                            <option value="0-49">Below 50%</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Select All Bar -->
            <div class="px-4 py-2 border-b border-gray-200 bg-indigo-50 flex justify-between items-center shrink-0">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="selectAllVisible" class="rounded text-indigo-600 focus:ring-indigo-500 w-5 h-5" onchange="toggleSelectAll(this)">
                    <span class="text-sm font-bold text-indigo-900">Select All Visible</span>
                </label>
                <span class="text-xs text-indigo-700 italic">Filters apply to selection</span>
            </div>

            <!-- Scrollable List -->
            <div class="p-4" id="studentList" style="flex: 1; overflow-y: auto;">
                <?php foreach ($all_students as $req): 
                    $status_text = empty($req['status']) ? 'Not Requested' : ucfirst($req['status']);
                ?>
                    <label class="flex items-center gap-3 p-3 hover:bg-gray-50 rounded-lg cursor-pointer border-b border-gray-100 student-row" 
                           data-name="<?= strtolower($req['first_name'] . ' ' . $req['last_name'] . ' ' . $req['student_id']) ?>"
                           data-course="<?= htmlspecialchars($req['program']) ?>"
                           data-section="<?= htmlspecialchars($req['section']) ?>"
                           data-status="<?= $status_text ?>"
                           data-progress="<?= $req['progress_pct'] ?>">
                        
                        <input type="checkbox" class="student-checkbox rounded text-indigo-600 focus:ring-indigo-500 w-5 h-5" 
                               value="<?= $req['student_id'] ?>" 
                               data-name="<?= htmlspecialchars(!empty($req['custom_name']) ? $req['custom_name'] : $req['first_name'] . ' ' . $req['last_name']) ?>"
                               onchange="updateCount()">
                        
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <div class="font-bold text-sm text-gray-800"><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></div>
                                <span class="text-[10px] font-extrabold px-1.5 py-0.5 rounded <?= $req['progress_pct'] === 100 ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $req['progress_pct'] ?>%
                                </span>
                            </div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($req['student_id']) ?> • <?= htmlspecialchars($req['program']) ?> - <?= htmlspecialchars($req['section']) ?></div>
                        </div>
                        <div class="text-[10px] font-bold px-2 py-1 rounded <?= $status_text === 'Not Requested' ? 'bg-gray-100 text-gray-500' : ($status_text === 'Pending' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700') ?>">
                            <?= $status_text ?>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="p-4 border-t border-gray-200 bg-gray-50 flex justify-between items-center shrink-0">
                <span class="text-sm font-bold text-gray-600">Selected: <span id="selectedCount" class="text-indigo-600">0</span></span>
                <button onclick="previewSelected()" class="text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md transition transform hover:scale-105" style="background-color: #4f46e5; border: 1px solid #4338ca;">
                    Preview & Export (<span id="btnSelectedCount">0</span>) <i class="fas fa-arrow-right ml-1"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- PREVIEW MODAL (Iframe PDF Viewer) -->
    <div id="previewModal" class="hidden fixed inset-0 flex flex-col p-0 m-0" style="z-index: 2147483647 !important; background-color: rgba(0,0,0,0.9);">
        
        <!-- Header Controls (Forced to top, with high z-index) -->
        <div class="flex flex-col md:flex-row justify-between items-center p-3 bg-white shrink-0 gap-3 border-b border-gray-200 shadow-sm relative z-50">
            
            <!-- Left Side: Close X + Title -->
            <div class="flex items-center gap-3 w-full md:w-auto overflow-hidden">
                <!-- BIG RED X BUTTON -->
                <button onclick="document.getElementById('previewModal').classList.add('hidden')" class="text-red-500 hover:text-red-700 transition-colors bg-red-50 hover:bg-red-100 p-2 rounded-lg shrink-0" title="Close Preview">
                    <i class="fas fa-times text-xl"></i>
                </button>
                
                <h3 class="text-gray-800 font-bold text-lg truncate">
                    <i class="fas fa-file-pdf text-red-500 mr-2"></i>
                    Live PDF Preview (<span id="previewCount">0</span> Students)
                </h3>
            </div>

            <!-- Right Side: Cancel & Confirm -->
            <div class="flex gap-2 w-full md:w-auto justify-end shrink-0">
                <!-- FORCED VISIBILITY CANCEL BUTTON -->
                <button onclick="document.getElementById('previewModal').classList.add('hidden')" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-bold transition shadow-sm" style="display: block;">
                    Cancel
                </button>
                
                <button onclick="confirmExport()" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-bold text-sm shadow-md transition transform hover:scale-105">
                    <i class="fas fa-download mr-2"></i> Confirm & Download PDF
                </button>
            </div>
        </div>

        <!-- The PDF Iframe (Absolute positioning forces it to fill the exact remaining space) -->
        <div class="flex-1 w-full bg-gray-300 relative">
            <iframe name="pdfFrame" id="pdfFrame" class="absolute inset-0 w-full h-full border-0"></iframe>
        </div>
    </div>

    <!-- Hidden Form to Submit to the Iframe -->
    <form id="pdfForm" method="POST" action="generate_certificate.php" target="pdfFrame" class="hidden">
        <input type="hidden" name="student_ids" id="exportIds">
        <input type="hidden" name="is_preview" id="isPreviewInput" value="1">
    </form>

    <script>
        // 1. Selection Logic
        const checkboxes = document.querySelectorAll('.student-checkbox');
        const countSpan = document.getElementById('selectedCount');

        function updateCount() {
            const checked = document.querySelectorAll('.student-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = checked;
            document.getElementById('btnSelectedCount').textContent = checked;
        }
        checkboxes.forEach(cb => cb.addEventListener('change', updateCount));

        // 2. Advanced Multi-Filter Logic (Now includes Progress)
        function filterStudents() {
            const query = document.getElementById('studentSearch').value.toLowerCase();
            const filterCourse = document.getElementById('filterCourse').value;
            const filterSection = document.getElementById('filterSection').value;
            const filterStatus = document.getElementById('filterStatus').value;
            const filterProgress = document.getElementById('filterProgress').value;

            let visibleCount = 0;

            document.querySelectorAll('.student-row').forEach(row => {
                const name = row.getAttribute('data-name');
                const course = row.getAttribute('data-course');
                const section = row.getAttribute('data-section');
                const status = row.getAttribute('data-status');
                const progress = parseInt(row.getAttribute('data-progress') || '0');

                // Check if it matches ALL active filters
                const matchSearch = name.includes(query);
                const matchCourse = !filterCourse || course === filterCourse;
                const matchSection = !filterSection || section === filterSection;
                const matchStatus = !filterStatus || status === filterStatus;
                
                // Progress Filter Logic
                let matchProgress = true;
                if (filterProgress === '100') matchProgress = (progress === 100);
                else if (filterProgress === '50-99') matchProgress = (progress >= 50 && progress < 100);
                else if (filterProgress === '0-49') matchProgress = (progress < 50);

                if (matchSearch && matchCourse && matchSection && matchStatus && matchProgress) {
                    row.style.display = 'flex';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Uncheck the "Select All" box if the filter changes
            document.getElementById('selectAllVisible').checked = false;
        }

        // 3. Select All Visible
        function toggleSelectAll(source) {
            const isChecked = source.checked;
            
            // Only select checkboxes in rows that are currently visible (not display:none)
            document.querySelectorAll('.student-row').forEach(row => {
                if (row.style.display !== 'none') {
                    row.querySelector('.student-checkbox').checked = isChecked;
                }
            });
            
            updateCount();
        }

        // 3. Modal Switchers
        function openBulkModal() {
            document.getElementById('bulkModal').classList.remove('hidden');
        }

        // 4. Generate PDF Preview (Loads into iframe)
        function previewSelected() {
            const checked = document.querySelectorAll('.student-checkbox:checked');
            if (checked.length === 0) {
                alert("Please select at least one student.");
                return;
            }

            const ids = [];
            checked.forEach(cb => ids.push(cb.value));

            // Setup form for PREVIEW (No DB update)
            document.getElementById('exportIds').value = ids.join(',');
            document.getElementById('isPreviewInput').value = '1';
            document.getElementById('previewCount').textContent = ids.length;

            // Submit to iframe to generate preview
            const form = document.getElementById('pdfForm');
            form.target = 'pdfFrame'; // Load inside iframe
            form.submit();

            // Switch Modals
            document.getElementById('bulkModal').classList.add('hidden');
            document.getElementById('previewModal').classList.remove('hidden');
        }

        // 5. Confirm & Download
        function confirmExport() {
            // Setup form for ACTUAL EXPORT (Updates DB and downloads)
            document.getElementById('isPreviewInput').value = '0';
            
            // Change target to _blank so it downloads instead of loading in iframe
            const form = document.getElementById('pdfForm');
            form.target = '_blank';
            form.submit();

            // Close the modal
            document.getElementById('previewModal').classList.add('hidden');

            // Refresh the page after a short delay to update the table status
            setTimeout(() => {
                location.reload();
            }, 1500);
        }
    </script>
        <script>
        function filterTable(status) {
            const rows = document.querySelectorAll('tbody tr');
            
            // Update Tab Styles
            document.getElementById('btn-all').className = "font-bold text-sm pb-1 border-b-2 border-transparent text-gray-500 hover:text-gray-800";
            document.getElementById('btn-pending').className = "font-bold text-sm pb-1 border-b-2 border-transparent text-gray-500 hover:text-gray-800";
            document.getElementById('btn-generated').className = "font-bold text-sm pb-1 border-b-2 border-transparent text-gray-500 hover:text-gray-800";
            
            document.getElementById('btn-' + status).className = "font-bold text-sm pb-1 border-b-2 border-indigo-600 text-indigo-600";

            // Filter Rows
            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                if (status === 'all' || rowStatus === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>