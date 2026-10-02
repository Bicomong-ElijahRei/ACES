<?php
/**
 * ============================================================
 * ACES System — Get Session Progress (JSON)
 * ============================================================
 * Returns all students registered for any subtopic of a session,
 * along with per-subtopic attendance + module progress.
 *
 * GET params:
 *   session_id  (int, required)
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!isStaff()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$session_id = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Session ID required.']);
    exit;
}

// ---------- Fetch session ----------
$stmt = $pdo->prepare("
    SELECT session_id, title, phase, date
    FROM sessions
    WHERE session_id = ? AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([$session_id]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'error' => 'Session not found.']);
    exit;
}

// ---------- Fetch subtopics ----------
$stmt = $pdo->prepare("
    SELECT subtopic_id, title
    FROM subtopics
    WHERE session_id = ? AND is_deleted = 0
    ORDER BY title
");
$stmt->execute([$session_id]);
$subtopics = $stmt->fetchAll();

$subtopic_ids = array_column($subtopics, 'subtopic_id');
if (empty($subtopic_ids)) {
    echo json_encode([
        'success'   => true,
        'session'   => [
            'session_id' => $session['session_id'],
            'title'      => $session['title'],
            'phase'      => $session['phase'],
            'date'       => date('M d, Y', strtotime($session['date'])),
        ],
        'subtopics' => [],
        'students'  => [],
    ]);
    exit;
}

// ---------- Fetch total modules per subtopic ----------
$ph = implode(',', array_fill(0, count($subtopic_ids), '?'));
$stmt = $pdo->prepare("
    SELECT subtopic_id, COUNT(*) AS total
    FROM modules
    WHERE subtopic_id IN ($ph) AND is_deleted = 0
    GROUP BY subtopic_id
");
$stmt->execute($subtopic_ids);
$total_modules_per_subtopic = [];
while ($row = $stmt->fetch()) {
    $total_modules_per_subtopic[$row['subtopic_id']] = (int)$row['total'];
}

// ---------- Fetch students registered ----------
$stmt = $pdo->prepare("
    SELECT DISTINCT s.student_id, s.program, s.section, u.full_name
    FROM registrations r
    JOIN students s ON s.student_id = r.student_id
    JOIN users u ON u.user_id = s.user_id
    WHERE r.subtopic_id IN ($ph) AND s.is_deleted = 0
    ORDER BY u.full_name
");
$stmt->execute($subtopic_ids);
$students = $stmt->fetchAll();

if (empty($students)) {
    echo json_encode([
        'success'   => true,
        'session'   => [
            'session_id' => $session['session_id'],
            'title'      => $session['title'],
            'phase'      => $session['phase'],
            'date'       => date('M d, Y', strtotime($session['date'])),
        ],
        'subtopics' => $subtopics,
        'students'  => [],
    ]);
    exit;
}

$student_ids = array_column($students, 'student_id');

// ---------- Fetch registrations ----------
$ph_s = implode(',', array_fill(0, count($student_ids), '?'));
$stmt = $pdo->prepare("
    SELECT student_id, subtopic_id
    FROM registrations
    WHERE student_id IN ($ph_s) AND subtopic_id IN ($ph)
");
$stmt->execute(array_merge($student_ids, $subtopic_ids));
$reg_map = [];
while ($row = $stmt->fetch()) {
    $reg_map[$row['student_id']][$row['subtopic_id']] = true;
}

// ---------- Fetch attendance ----------
$stmt = $pdo->prepare("
    SELECT student_id, subtopic_id, attendance_status
    FROM attendance
    WHERE student_id IN ($ph_s) AND subtopic_id IN ($ph)
");
$stmt->execute(array_merge($student_ids, $subtopic_ids));
$att_map = [];
while ($row = $stmt->fetch()) {
    $att_map[$row['student_id']][$row['subtopic_id']] = $row['attendance_status'];
}

// ---------- Fetch module completions ----------
$stmt = $pdo->prepare("
    SELECT sp.student_id, m.subtopic_id, COUNT(*) AS completed
    FROM student_module_progress sp
    JOIN modules m ON sp.module_id = m.module_id
    WHERE sp.student_id IN ($ph_s)
      AND m.subtopic_id IN ($ph)
      AND sp.completed = 1
    GROUP BY sp.student_id, m.subtopic_id
");
$stmt->execute(array_merge($student_ids, $subtopic_ids));
$mod_map = [];
while ($row = $stmt->fetch()) {
    $mod_map[$row['student_id']][$row['subtopic_id']] = (int)$row['completed'];
}

// ---------- Assemble students ----------
$output = [];
foreach ($students as $s) {
    $sid = $s['student_id'];

    $attendance_present = 0;
    $attendance_total   = 0;
    $modules_completed  = 0;
    $modules_total_all  = 0;
    $subtopics_arr      = [];

    foreach ($subtopics as $sb) {
        $sub_id  = $sb['subtopic_id'];
        $registered = isset($reg_map[$sid][$sub_id]);
        $att_status = $att_map[$sid][$sub_id] ?? 'not_recorded';
        $mods_total = $total_modules_per_subtopic[$sub_id] ?? 0;
        $mods_done  = $mod_map[$sid][$sub_id] ?? 0;

        if ($registered) {
            $attendance_total++;
            if ($att_status === 'present') $attendance_present++;
            $modules_total_all += $mods_total;
            $modules_completed += $mods_done;
        }

        $subtopics_arr[] = [
            'subtopic_id'       => $sub_id,
            'title'             => $sb['title'],
            'registered'        => $registered,
            'attendance_status' => $att_status,
            'modules_completed' => $mods_done,
            'modules_total'     => $mods_total,
        ];
    }

    $progress_pct = $attendance_total > 0
        ? round(($attendance_present / $attendance_total) * 100)
        : 0;

    $output[] = [
        'student_id'         => $sid,
        'full_name'          => $s['full_name'],
        'program'            => $s['program'],
        'section'            => $s['section'],
        'progress_pct'       => $progress_pct,
        'attendance_summary' => [
            'present' => $attendance_present,
            'total'   => $attendance_total,
        ],
        'modules_summary'    => [
            'completed' => $modules_completed,
            'total'     => $modules_total_all,
        ],
        'subtopics'          => $subtopics_arr,
    ];
}

// Sort by progress ascending (at-risk first)
usort($output, fn($a, $b) => $a['progress_pct'] <=> $b['progress_pct']);

echo json_encode([
    'success'   => true,
    'session'   => [
        'session_id' => $session['session_id'],
        'title'      => $session['title'],
        'phase'      => $session['phase'],
        'date'       => date('M d, Y', strtotime($session['date'])),
    ],
    'subtopics' => array_map(fn($s) => ['subtopic_id' => $s['subtopic_id'], 'title' => $s['title']], $subtopics),
    'students'  => $output,
]);