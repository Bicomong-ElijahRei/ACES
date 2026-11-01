<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

// Start session to remember the proctor securely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error_msg = '';

// 1. Logout logic
if (isset($_GET['logout'])) {
    unset($_SESSION['proctor_pin']);
    header('Location: proctor_scan.php');
    exit;
}

// 2. Handle PIN Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    $_SESSION['proctor_pin'] = trim($_POST['pin']);
}

// 3. Get PIN from session
$pin = $_SESSION['proctor_pin'] ?? '';
$subtopic = null;
$students = [];

if ($pin) {
    $stmt = $pdo->prepare("SELECT subtopic_id, title, session_id, visible_courses FROM subtopics WHERE proctor_pin = ? AND is_deleted = 0");
    $stmt->execute([$pin]);
    $subtopic = $stmt->fetch();

    if (!$subtopic) {
        // Invalid PIN in session
        unset($_SESSION['proctor_pin']);
        $error_msg = "Invalid or expired PIN. Please try again.";
    } else {
        // Fetch students based on visibility rules
        $vis_courses = json_decode($subtopic['visible_courses'] ?? '[]', true) ?: [];
        
        $sql = "SELECT s.student_id, s.first_name, s.last_name, s.program, s.section,
                (SELECT attendance_status FROM attendance WHERE student_id = s.student_id AND subtopic_id = ? LIMIT 1) as att_status
                FROM students s 
                WHERE s.is_deleted = 0";
        $params = [$subtopic['subtopic_id']];

        if (!empty($vis_courses)) {
            $inQuery = implode(',', array_fill(0, count($vis_courses), '?'));
            $sql .= " AND s.program IN ($inQuery)";
            $params = array_merge($params, $vis_courses);
        }
        // (Section filtering is not applied to subtopics in this version)
        
        $sql .= " ORDER BY s.last_name, s.first_name";
        $stmt_students = $pdo->prepare($sql);
        $stmt_students->execute($params);
        $students = $stmt_students->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proctor Dashboard | ACES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        body { background-color: #f0f4f8; font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        .header-custom { background: #0a6e2d; color: white; padding: 20px 15px; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .nav-tabs .nav-link { font-weight: bold; color: #6b7280; border: none; border-bottom: 3px solid transparent; }
        .nav-tabs .nav-link.active { color: #0a6e2d; border-bottom: 3px solid #0a6e2d; background: transparent; }
        #reader { border-radius: 15px; overflow: hidden; border: 3px solid #0a6e2d; }
        .student-row { transition: all 0.3s ease; }
        .student-row.present { background-color: #dcfce7 !important; opacity: 0.8; }
        .search-box { border-radius: 20px; padding-left: 15px; }
    </style>
</head>
<body>

<div class="header-custom text-center position-relative">
    <h3 class="mb-0"><i class="fas fa-clipboard-check"></i> ACES Proctor</h3>
    <?php if ($subtopic): ?>
        <p class="mb-0 text-light mt-1" style="font-size: 14px;"><?= htmlspecialchars($subtopic['title']) ?></p>
        <!-- LOGOUT BUTTON -->
        <a href="?logout=1" class="position-absolute text-white" style="right: 15px; top: 50%; transform: translateY(-50%); text-decoration: none;" title="Exit Proctor Mode">
            <i class="fas fa-sign-out-alt fa-lg"></i>
        </a>
    <?php endif; ?>
</div>

<div class="container mt-4" style="max-width: 600px;">
    <?php if (!$subtopic): ?>
        <div class="card shadow-sm border-0 rounded-4 p-4 text-center mt-5">
            <form method="POST" action="">
                <i class="fas fa-lock text-muted mb-3" style="font-size: 40px;"></i>
                <h5 class="mb-3">Enter Proctor PIN</h5>
                
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger py-2 text-sm"><?= $error_msg ?></div>
                <?php endif; ?>

                <input type="text" name="pin" class="form-control form-control-lg text-center font-monospace mb-3" placeholder="e.g. 839201" maxlength="6" required autofocus>
                <button type="submit" class="btn btn-success w-100 btn-lg font-weight-bold">Unlock Dashboard</button>
            </form>
        </div>
    <?php else: ?>
        <!-- TABS -->
        <ul class="nav nav-tabs justify-content-center mb-4" id="proctorTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="scanner-tab" data-bs-toggle="tab" data-bs-target="#scanner-pane" type="button" role="tab"><i class="fas fa-qrcode"></i> Scanner</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual-pane" type="button" role="tab"><i class="fas fa-list-ul"></i> Manual List</button>
            </li>
        </ul>

        <div class="tab-content" id="proctorTabsContent">
            
            <!-- SCANNER PANE -->
            <div class="tab-pane fade show active" id="scanner-pane" role="tabpanel">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-3">
                    <div id="reader" class="w-100 bg-black"></div>
                    <div class="card-body text-center bg-light">
                        <p class="mb-0 text-muted small">Point student QR code at camera</p>
                    </div>
                </div>
                <div class="mt-4">
                    <h6 class="font-weight-bold border-bottom pb-2 text-muted">Recent Scans</h6>
                    <div id="scan_log" class="mt-2 text-center text-muted font-italic" style="max-height: 200px; overflow-y: auto;">
                        Waiting for scans...
                    </div>
                </div>
            </div>

            <!-- MANUAL LIST PANE -->
            <div class="tab-pane fade" id="manual-pane" role="tabpanel">
                <div class="input-group mb-3 shadow-sm">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="studentSearch" class="form-control border-0 search-box" placeholder="Search student name or ID...">
                </div>

                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="table-responsive" style="max-height: 500px;">
                        <table class="table table-hover mb-0" id="studentListTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th class="text-xs text-muted">Student</th>
                                    <th class="text-xs text-muted text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $s): ?>
                                    <tr class="student-row <?= ($s['att_status'] === 'present') ? 'present' : '' ?>" id="row-<?= $s['student_id'] ?>">
                                        <td>
                                            <div class="fw-bold" style="font-size: 14px;"><?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name']) ?></div>
                                            <div class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($s['student_id']) ?> &middot; <?= htmlspecialchars($s['program']) ?> - <?= htmlspecialchars($s['section']) ?></div>
                                        </td>
                                        <td class="text-center align-middle">
                                            <?php if ($s['att_status'] === 'present'): ?>
                                                <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-check"></i> Present</span>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold mark-present-btn" data-id="<?= $s['student_id'] ?>">Mark Present</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($subtopic): ?>
<script>
const SUBTOPIC_ID = <?= $subtopic['subtopic_id'] ?>;
const PIN = "<?= htmlspecialchars($pin) ?>";
let html5QrCode;

// Function to start the camera
function startCamera() {
    if (!html5QrCode) {
        html5QrCode = new Html5Qrcode("reader");
        html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: { width: 250, height: 250 } }, onScanSuccess, () => {});
    }
}

// 1. Start the camera immediately when the page loads
window.addEventListener('load', startCamera);

// 2. Also start the camera if they switch back to the Scanner tab from the Manual List
document.getElementById('scanner-tab').addEventListener('shown.bs.tab', startCamera);

function onScanSuccess(decodedText) {
    if (window.lastScanned === decodedText) return;
    window.lastScanned = decodedText;
    setTimeout(() => { window.lastScanned = null; }, 3000);

    // Process Scan (reuse the same logic, send to QR API)
    fetch('../api/process_proctor_scan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `pin=${PIN}&subtopic_id=${SUBTOPIC_ID}&student_id=${encodeURIComponent(decodedText)}`
    })
    .then(res => res.json())
    .then(data => {
        const logDiv = document.getElementById('scan_log');
        if (logDiv.innerHTML.includes('Waiting')) logDiv.innerHTML = '';

        if (data.success) {
            new Audio('https://actions.google.com/sounds/v1/alarms/beep_short.ogg').play().catch(()=>{});
            
            logDiv.insertAdjacentHTML('afterbegin', `
                <div class="alert alert-success py-2 mb-2 text-start shadow-sm animate__animated animate__fadeIn">
                    <i class="fas fa-check-circle me-2"></i> <strong>${data.student_name}</strong> marked present.
                </div>
            `);

            // Update the manual list UI instantly
            const row = document.getElementById(`row-${decodedText}`);
            if (row && !row.classList.contains('present')) {
                row.classList.add('present');
                row.querySelector('td:last-child').innerHTML = '<span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-check"></i> Present</span>';
            }

        } else {
            logDiv.insertAdjacentHTML('afterbegin', `
                <div class="alert alert-danger py-2 mb-2 text-start shadow-sm">
                    <i class="fas fa-times-circle me-2"></i> ${data.message}
                </div>
            `);
        }
    });
}

// Manual Mark Present
$(document).on('click', '.mark-present-btn', function() {
    const btn = $(this);
    const studentId = btn.data('id');
    
    btn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);

    fetch('../api/manual_proctor_verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `pin=${PIN}&subtopic_id=${SUBTOPIC_ID}&student_id=${studentId}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById(`row-${studentId}`);
            row.classList.add('present');
            btn.replaceWith('<span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-check"></i> Present</span>');
        } else {
            alert(data.message);
            btn.html('Mark Present').prop('disabled', false);
        }
    });
});

// Search functionality
document.getElementById('studentSearch').addEventListener('keyup', function() {
    const value = this.value.toLowerCase();
    document.querySelectorAll('#studentListTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
    });
});
</script>
<?php endif; ?>
</body>
</html>