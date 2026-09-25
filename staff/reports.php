<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
redirectIfNotStaff();

// Fetch sessions and subtopics for dropdowns
$stmt_sessions = $pdo->query("SELECT session_id, title FROM sessions WHERE is_deleted = 0 ORDER BY date DESC");
$sessions = $stmt_sessions->fetchAll();

$subtopics_by_session = [];
foreach ($sessions as $s) {
    $stmt = $pdo->prepare("SELECT subtopic_id, title FROM subtopics WHERE session_id = ? AND is_deleted = 0 ORDER BY title");
    $stmt->execute([$s['session_id']]);
    $subtopics_by_session[$s['session_id']] = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | ACES Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
<?php include '../includes/staff_sidebar.php'; ?>
<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto no-scrollbar">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]"><i class="fas fa-file-export mr-2"></i>Reports & Exports</h1>
            <a href="dashboard.php" class="text-[#0a6e2d]"><i class="fas fa-home fa-lg"></i></a>
        </div>

        <!-- Attendance Sheet Card -->
        <div class="bg-white rounded shadow-xl p-6 mb-6">
            <h2 class="text-xl font-bold text-[#0a6e2d] mb-4"><i class="fas fa-clipboard-list mr-2"></i>Print Attendance Sheet</h2>
            <p class="text-sm text-gray-600 mb-3">Generate an A4 PDF with alphabetical student names and signature boxes for a physical attendance event.</p>
            <form action="print_attendance_sheet.php" method="get" target="_blank" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Session</label>
                    <select name="session_id" id="attendanceSession" class="form-select" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= $s['session_id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Subtopic</label>
                    <select name="subtopic_id" id="attendanceSubtopic" class="form-select" required>
                        <option value="">-- Select session first --</option>
                    </select>
                </div>
                <button type="submit" class="bg-green-700 hover:bg-green-800 text-white px-4 py-2 rounded">
                    <i class="fas fa-print mr-1"></i> Generate PDF
                </button>
            </form>
        </div>

        <!-- Customizable Detailed Report Card -->
        <div class="bg-white rounded shadow-xl p-6">
            <h2 class="text-xl font-bold text-[#0a6e2d] mb-4"><i class="fas fa-table mr-2"></i>Customizable Detailed Report</h2>
            <p class="text-sm text-gray-600 mb-3">Choose what data to include, filter by session/course/section, and export as CSV or printable PDF.</p>
            <form action="export_custom_report.php" method="get" target="_blank" class="flex flex-col gap-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Session</label>
                        <select name="session_id" class="form-select">
                            <option value="">All Sessions</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?= $s['session_id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Course</label>
                        <select name="course" class="form-select">
                            <option value="">All Courses</option>
                            <?php
                            $courses = $pdo->query("SELECT DISTINCT program FROM students")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($courses as $c):
                            ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Section</label>
                        <select name="section" class="form-select">
                            <option value="">All Sections</option>
                            <?php
                            $sections = $pdo->query("SELECT DISTINCT section FROM students")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($sections as $sec):
                            ?>
                                <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Columns to Include</label>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <?php
                        $columns = [
                            'student_id' => 'Student No.',
                            'full_name' => 'Student Name',
                            'course' => 'Course',
                            'section' => 'Section',
                            'attendance_status' => 'Attendance Status',
                            'attendance_date' => 'Attendance Date',
                            'module_title' => 'Module Title',
                            'module_completion' => 'Module Completion',
                            'module_score' => 'Assessment Score',
                            'subtopic_title' => 'Subtopic',
                            'session_title' => 'Session'
                        ];
                        foreach ($columns as $key => $label):
                        ?>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="columns[]" value="<?= $key ?>" <?= in_array($key, ['student_id','full_name','session_title','subtopic_title','attendance_status']) ? 'checked' : '' ?>>
                            <?= $label ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button type="submit" name="format" value="csv" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        <i class="fas fa-file-csv mr-1"></i> Export CSV
                    </button>
                    <button type="submit" name="format" value="pdf" class="bg-green-700 hover:bg-green-800 text-white px-4 py-2 rounded">
                        <i class="fas fa-file-pdf mr-1"></i> Export PDF
                    </button>
                </div>
            </form>
        </div>

    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Dynamic subtopic dropdown for attendance sheet
    const subtopicsBySession = <?= json_encode($subtopics_by_session) ?>;
    document.getElementById('attendanceSession')?.addEventListener('change', function() {
        const sid = this.value;
        const subSelect = document.getElementById('attendanceSubtopic');
        subSelect.innerHTML = '<option value="">-- Select --</option>';
        if (sid && subtopicsBySession[sid]) {
            subtopicsBySession[sid].forEach(sub => {
                const opt = document.createElement('option');
                opt.value = sub.subtopic_id;
                opt.textContent = sub.title;
                subSelect.appendChild(opt);
            });
        }
    });
</script>
</body>
</html>