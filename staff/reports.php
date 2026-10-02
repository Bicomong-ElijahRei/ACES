<?php
/**
 * ============================================================
 * ACES System — Reports & Exports (Redesigned)
 * ============================================================
 * Visual makeover + UX improvements:
 *   - Actions grouped under a unified "Export" card
 *   - Interactive preview of selected columns
 *   - Better feedback on what will be exported
 *   - Research-backed patterns from enterprise design systems
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
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

// Fetch courses and sections
$courses = $pdo->query("SELECT DISTINCT program FROM students WHERE is_deleted = 0 ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM students WHERE is_deleted = 0 ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);

$columns = [
    'student_id'         => 'Student No.',
    'full_name'          => 'Student Name',
    'course'             => 'Course',
    'section'            => 'Section',
    'attendance_status'  => 'Attendance Status',
    'attendance_date'    => 'Attendance Date',
    'module_title'       => 'Module Title',
    'module_completion'  => 'Module Completion',
    'module_score'       => 'Assessment Score',
    'subtopic_title'     => 'Subtopic',
    'session_title'      => 'Session',
];

$default_columns = ['student_id', 'full_name', 'session_title', 'subtopic_title', 'attendance_status'];
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
        :root {
            --primary: #0a6e2d;
            --primary-dark: #054018;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-400: #9ca3af;
            --neutral-600: #6b7280;
            --neutral-800: #1f2937;
        }
        html, body { height: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        .report-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            transition: all 0.2s;
        }
        .report-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.06); }

        .report-card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--neutral-100);
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .report-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .report-card-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--neutral-800);
            margin: 0;
        }
        .report-card-subtitle {
            font-size: 0.78rem;
            color: var(--neutral-600);
            margin: 2px 0 0 0;
        }

        .form-select-clean {
            width: 100%;
            border: 1px solid var(--neutral-200);
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 0.875rem;
            background: #fff;
            color: var(--neutral-800);
            transition: border-color 0.15s;
        }
        .form-select-clean:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(10,110,45,0.1); }

        .column-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            padding: 6px 12px;
            border-radius: 9999px;
            border: 1px solid var(--neutral-200);
            background: #fff;
            cursor: pointer;
            transition: all 0.15s;
            user-select: none;
        }
        .column-chip:hover { border-color: var(--primary); }
        .column-chip.is-checked {
            background: #ecfdf5;
            border-color: var(--primary);
            color: var(--primary-dark);
            font-weight: 600;
        }
        .column-chip input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: var(--primary);
            cursor: pointer;
            margin: 0;
        }

        .btn-primary-clean {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-primary-clean:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(10,110,45,0.2); }

        .btn-outline-clean {
            background: #fff;
            color: var(--neutral-800);
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            border: 1px solid var(--neutral-200);
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-outline-clean:hover { border-color: var(--primary); color: var(--primary); }

        .export-summary {
            background: var(--neutral-50);
            border: 1px dashed var(--neutral-200);
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 0.8rem;
            color: var(--neutral-600);
        }
        .export-summary strong { color: var(--neutral-800); }

        @media (max-width: 768px) {
            .report-card-header { padding: 16px; }
            .report-card-icon { width: 40px; height: 40px; font-size: 16px; }
        }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">
<?php include '../includes/staff_sidebar.php'; ?>
<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-[#0a6e2d]">
                    <i class="fas fa-file-export mr-2"></i>Reports & Exports
                </h1>
                <p class="text-sm text-gray-500 mt-1">Generate attendance sheets and customizable data exports</p>
            </div>
            <a href="dashboard.php" class="text-[#0a6e2d] hover:text-green-800" title="Home">
                <i class="fas fa-house text-2xl"></i>
            </a>
        </div>

        <!-- ============================================================
             CARD 1: Print Attendance Sheet
             ============================================================ -->
        <div class="report-card mb-6">
            <div class="report-card-header">
                <div class="report-card-icon bg-blue-100 text-blue-600">
                    <i class="fas fa-print"></i>
                </div>
                <div>
                    <h2 class="report-card-title">Print Attendance Sheet</h2>
                    <p class="report-card-subtitle">A4 PDF with alphabetical student names and signature boxes</p>
                </div>
            </div>
            <div class="p-6">
                <form action="print_attendance_sheet.php" method="post" target="_blank" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                 <?= csrf_field() ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Session</label>
                        <select name="session_id" id="attendanceSession" class="form-select-clean" required>
                            <option value="">— Select a session —</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?= $s['session_id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Subtopic</label>
                        <select name="subtopic_id" id="attendanceSubtopic" class="form-select-clean" required>
                            <option value="">— Select a session first —</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-primary-clean w-full justify-center">
                            <i class="fas fa-print"></i> Generate PDF
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================================
             CARD 2: Customizable Detailed Report
             ============================================================ -->
        <div class="report-card">
            <div class="report-card-header">
                <div class="report-card-icon bg-purple-100 text-purple-600">
                    <i class="fas fa-table"></i>
                </div>
                <div>
                    <h2 class="report-card-title">Customizable Detailed Report</h2>
                    <p class="report-card-subtitle">Choose filters and columns, then export as CSV or printable PDF</p>
                </div>
            </div>
            <div class="p-6">
                <form action="export_custom_report.php" method="post" target="_blank" id="reportForm">
                  <?= csrf_field() ?>

                    <!-- Filters Row -->
                    <div class="mb-6">
                        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wide mb-3 flex items-center gap-2">
                            <i class="fas fa-filter text-gray-400"></i> Filters
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1.5">Session</label>
                                <select name="session_id" class="form-select-clean">
                                    <option value="">All Sessions</option>
                                    <?php foreach ($sessions as $s): ?>
                                        <option value="<?= $s['session_id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1.5">Course</label>
                                <select name="course" class="form-select-clean">
                                    <option value="">All Courses</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1.5">Section</label>
                                <select name="section" class="form-select-clean">
                                    <option value="">All Sections</option>
                                    <?php foreach ($sections as $sec): ?>
                                        <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Columns Row -->
                    <div class="mb-6">
                        <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wide mb-3 flex items-center gap-2">
                            <i class="fas fa-columns text-gray-400"></i> Columns to Include
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($columns as $key => $label): ?>
                                <label class="column-chip <?= in_array($key, $default_columns) ? 'is-checked' : '' ?>">
                                    <input type="checkbox" name="columns[]" value="<?= $key ?>" <?= in_array($key, $default_columns) ? 'checked' : '' ?>>
                                    <?= $label ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Export Summary + Actions -->
                    <div class="export-summary mb-5">
                        <i class="fas fa-info-circle mr-1.5 text-gray-400"></i>
                        Exports all rows matching the filters above. The file will contain
                        <strong id="columnCount"><?= count($default_columns) ?></strong>
                        columns.
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" name="format" value="csv" class="btn-outline-clean">
                            <i class="fas fa-file-csv text-blue-600"></i> Export as CSV
                        </button>
                        <button type="submit" name="format" value="pdf" class="btn-primary-clean">
                            <i class="fas fa-file-pdf"></i> Export as PDF
                        </button>
                    </div>
                </form>
            </div>
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
        subSelect.innerHTML = '<option value="">— Select a subtopic —</option>';
        if (sid && subtopicsBySession[sid]) {
            subtopicsBySession[sid].forEach(sub => {
                const opt = document.createElement('option');
                opt.value = sub.subtopic_id;
                opt.textContent = sub.title;
                subSelect.appendChild(opt);
            });
        }
    });

    // Update column chip styling when checkboxes toggle
    document.querySelectorAll('.column-chip input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', function() {
            const chip = this.closest('.column-chip');
            chip.classList.toggle('is-checked', this.checked);
            updateColumnCount();
        });
    });

    function updateColumnCount() {
        const count = document.querySelectorAll('.column-chip input:checked').length;
        document.getElementById('columnCount').textContent = count;
    }
</script>
</body>
</html>