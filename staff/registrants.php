<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'];

// Fetch all active sessions for dropdown
$sessions = $pdo->query("SELECT session_id, title FROM sessions WHERE is_deleted = 0 ORDER BY date DESC")->fetchAll();

// Fetch all subtopics for chained dropdown (we'll filter client‑side later)
$subtopics_all = $pdo->query("
    SELECT st.subtopic_id, st.title, st.session_id
    FROM subtopics st
    JOIN sessions s ON st.session_id = s.session_id
    WHERE st.is_deleted = 0 AND s.is_deleted = 0
    ORDER BY s.date DESC, st.title
")->fetchAll();

// Fetch filters from GET (if any)
$filter_session   = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$filter_subtopic  = isset($_GET['subtopic_id']) ? (int)$_GET['subtopic_id'] : 0;
$filter_course    = $_GET['course'] ?? '';
$filter_section   = $_GET['section'] ?? '';

// Build query to fetch registrants with optional filters
$sql = "
    SELECT r.registration_id, r.registration_date, r.status,
           st.student_id, u.full_name, st.program, st.section,
           sub.title AS subtopic_title, s.title AS session_title,
           sub.subtopic_id, s.session_id
    FROM registrations r
    JOIN students st ON r.student_id = st.student_id
    JOIN users u ON st.user_id = u.user_id
    JOIN subtopics sub ON r.subtopic_id = sub.subtopic_id
    JOIN sessions s ON r.session_id = s.session_id
    WHERE sub.is_deleted = 0 AND s.is_deleted = 0 AND st.is_deleted = 0
";
$params = [];
if ($filter_session) {
    $sql .= " AND s.session_id = ?";
    $params[] = $filter_session;
}
if ($filter_subtopic) {
    $sql .= " AND sub.subtopic_id = ?";
    $params[] = $filter_subtopic;
}
if ($filter_course) {
    $sql .= " AND st.program = ?";
    $params[] = $filter_course;
}
if ($filter_section) {
    $sql .= " AND st.section = ?";
    $params[] = $filter_section;
}
$sql .= " ORDER BY s.date DESC, sub.title, st.section, u.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registrants = $stmt->fetchAll();

// Downloads for dropdowns
$courses  = $pdo->query("SELECT DISTINCT program FROM students WHERE is_deleted = 0 ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM students WHERE is_deleted = 0 ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrants | ACES Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-10 overflow-y-auto no-scrollbar">

        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">Registrants</h1>
            <a href="dashboard.php" class="text-[#0a6e2d]">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/></svg>
            </a>
        </div>

        <!-- Filter Card -->
        <div class="bg-white rounded shadow-xl p-4 mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Session</label>
                    <select name="session_id" class="form-select form-select-sm" id="filterSession">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessions as $sess): ?>
                            <option value="<?= $sess['session_id'] ?>" <?= $filter_session == $sess['session_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sess['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subtopic</label>
                    <select name="subtopic_id" class="form-select form-select-sm" id="filterSubtopic">
                        <option value="">All Subtopics</option>
                        <?php foreach ($subtopics_all as $sub): ?>
                            <option value="<?= $sub['subtopic_id'] ?>"
                                    data-session="<?= $sub['session_id'] ?>"
                                    <?= $filter_subtopic == $sub['subtopic_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sub['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Course</label>
                    <select name="course" class="form-select form-select-sm">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $crs): ?>
                            <option value="<?= htmlspecialchars($crs) ?>" <?= $filter_course == $crs ? 'selected' : '' ?>>
                                <?= htmlspecialchars($crs) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                    <select name="section" class="form-select form-select-sm">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?= htmlspecialchars($sec) ?>" <?= $filter_section == $sec ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sec) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white text-sm px-4 py-2 rounded">
                        <i class="fas fa-filter mr-1"></i> Apply
                    </button>
                    <a href="registrants.php" class="bg-gray-500 hover:bg-gray-600 text-white text-sm px-4 py-2 rounded">
                        Reset
                    </a>
                </div>
                <?php if (!isViewer()): ?>
                    <div id="exportBtnContainer" class="ml-auto">
                        <button type="button" id="exportCSVBtn" class="bg-green-700 hover:bg-green-800 text-white text-sm px-4 py-2 rounded">
                            <i class="fas fa-download mr-1"></i> Export CSV
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- DataTable Card -->
        <div class="bg-white rounded shadow-xl overflow-hidden">
            <div class="bg-[#054018] px-4 py-3 font-bold text-white text-sm md:text-base">Registrant List</div>
            <div class="overflow-x-auto">
                <table id="registrantsTable" class="min-w-full bg-white text-left text-xs md:text-sm">
                    <thead>
                        <tr class="text-gray-800 font-bold border-b border-gray-100">
                            <th class="py-4 px-4">Student No.</th>
                            <th class="py-4 px-4">Student Name</th>
                            <th class="py-4 px-4">Course</th>
                            <th class="py-4 px-4">Section</th>
                            <th class="py-4 px-4">Session</th>
                            <th class="py-4 px-4">Subtopic</th>
                            <th class="py-4 px-4">Registration Date</th>
                            <th class="py-4 px-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600">
                        <?php foreach ($registrants as $reg): ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50 transition">
                                <td class="py-4 px-4 font-mono"><?= htmlspecialchars($reg['student_id']) ?></td>
                                <td class="py-4 px-4 font-semibold"><?= htmlspecialchars($reg['full_name']) ?></td>
                                <td class="py-4 px-4"><?= htmlspecialchars($reg['program']) ?></td>
                                <td class="py-4 px-4"><?= htmlspecialchars($reg['section']) ?></td>
                                <td class="py-4 px-4"><?= htmlspecialchars($reg['session_title']) ?></td>
                                <td class="py-4 px-4"><?= htmlspecialchars($reg['subtopic_title']) ?></td>
                                <td class="py-4 px-4"><?= date('M d, Y', strtotime($reg['registration_date'])) ?></td>
                                <td class="py-4 px-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold
                                        <?= $reg['status'] == 'assigned' ? 'bg-blue-100 text-blue-800' : 
                                            ($reg['status'] == 'auto_assigned' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $reg['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($registrants)): ?>
                            <tr><td colspan="8" class="py-4 text-center text-gray-500">No registrants found for the selected filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';

$(document).ready(function() {
    $('#registrantsTable').DataTable({
        order: [[6, 'desc']],
        paging: true,
        searching: true,
        pageLength: 25,
        language: { search: "" }
    });

    // Chained dropdown: filter subtopics when session changes
    const sessionSelect = document.getElementById('filterSession');
    const subtopicSelect = document.getElementById('filterSubtopic');
    const allOptions = Array.from(subtopicSelect.options);

    sessionSelect.addEventListener('change', function() {
        const selectedSession = this.value;
        // Show all options first, then hide those not belonging to the selected session
        while (subtopicSelect.options.length > 1) {
            subtopicSelect.remove(1);
        }
        allOptions.forEach(opt => {
            if (opt.value === '') return; // skip the placeholder
            const sessionId = opt.getAttribute('data-session');
            if (selectedSession === '' || sessionId === selectedSession) {
                subtopicSelect.appendChild(opt.cloneNode(true));
            }
        });
        // Reset subtopic to "All"
        subtopicSelect.value = '';
    });

    // Export CSV
    <?php if (!isViewer()): ?>
    $('#exportCSVBtn').on('click', function() {
        const params = new URLSearchParams(window.location.search);
        // Append filters if not already present
        if (!params.has('export')) params.set('export', 'csv');
        const url = 'export_registrants_csv.php?' + params.toString();
        window.location.href = url;
    });
    <?php endif; ?>
});
</script>
</body>
</html>