<?php
/**
 * ============================================================
 * ACES System — Edit Staff Handler
 * ============================================================
 * POST-only. Receives action + user_id from manage_staff.php.
 * Supports: update_role, deactivate, reactivate, delete.
 * CSRF-protected. Admin-only.
 * ============================================================
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

// ---- Admin only ----
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

// ---- POST only ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_staff.php');
    exit;
}

// ---- CSRF check ----
csrf_verify();

$action  = $_POST['action'] ?? '';
$user_id = (int) ($_POST['user_id'] ?? 0);

// ---- Prevent admin from modifying themselves ----
if ($user_id == $_SESSION['user_id']) {
    header('Location: manage_staff.php?error=' . urlencode('Cannot modify yourself'));
    exit;
}

// ---- Ensure target user exists and is staff ----
$check = $pdo->prepare("SELECT user_id, role FROM users WHERE user_id = ?");
$check->execute([$user_id]);
$target = $check->fetch();

if (!$target || $target['role'] !== 'staff') {
    header('Location: manage_staff.php?error=' . urlencode('Target staff not found'));
    exit;
}

switch ($action) {

    case 'update_role':
        $new_role = $_POST['staff_role'] ?? '';
        if (!in_array($new_role, ['admin', 'lead', 'viewer'], true)) {
            header('Location: manage_staff.php?error=' . urlencode('Invalid role'));
            exit;
        }

        $stmt = $pdo->prepare("UPDATE users SET staff_role = ? WHERE user_id = ?");
        $stmt->execute([$new_role, $user_id]);

        $log = $pdo->prepare("
            INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
            VALUES (?, ?, 'update_role', ?)
        ");
        $log->execute([$_SESSION['user_id'], $user_id, "Changed role to $new_role"]);

        header('Location: manage_staff.php?success=' . urlencode('Role updated'));
        exit;

    case 'deactivate':
        $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE user_id = ?");
        $stmt->execute([$user_id]);

        $log = $pdo->prepare("
            INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
            VALUES (?, ?, 'deactivate', 'Deactivated account')
        ");
        $log->execute([$_SESSION['user_id'], $user_id]);

        header('Location: manage_staff.php?success=' . urlencode('Account deactivated'));
        exit;

    case 'reactivate':
        $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);

        $log = $pdo->prepare("
            INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
            VALUES (?, ?, 'reactivate', 'Reactivated account')
        ");
        $log->execute([$_SESSION['user_id'], $user_id]);

        header('Location: manage_staff.php?success=' . urlencode('Account reactivated'));
        exit;

    case 'delete':
        // Log before deleting
        $log = $pdo->prepare("
            INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
            VALUES (?, ?, 'delete', 'Permanently deleted account')
        ");
        $log->execute([$_SESSION['user_id'], $user_id]);

        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);

        header('Location: manage_staff.php?success=' . urlencode('Account deleted'));
        exit;
}

header('Location: manage_staff.php?error=' . urlencode('Invalid action'));
exit;