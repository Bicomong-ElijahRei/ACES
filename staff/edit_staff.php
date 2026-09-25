<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$action  = $_POST['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? 0);

// Prevent admin from modifying themselves
if ($user_id == $_SESSION['user_id']) {
    header('Location: manage_staff.php?error=Cannot+modify+yourself');
    exit;
}

switch ($action) {
    case 'update_role':
        $new_role = $_POST['staff_role'] ?? '';
        if (in_array($new_role, ['admin','lead','viewer'])) {
            $stmt = $pdo->prepare("UPDATE users SET staff_role = ? WHERE user_id = ?");
            $stmt->execute([$new_role, $user_id]);

            // Log
            $log = $pdo->prepare("INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
                                  VALUES (?, ?, 'update_role', ?)");
            $log->execute([$_SESSION['user_id'], $user_id, "Changed role to $new_role"]);

            header('Location: manage_staff.php?success=Role+updated');
            exit;
        }
        break;

    case 'deactivate':
        $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE user_id = ?");
        $stmt->execute([$user_id]);

        $log = $pdo->prepare("INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
                              VALUES (?, ?, 'deactivate', 'Deactivated account')");
        $log->execute([$_SESSION['user_id'], $user_id]);

        header('Location: manage_staff.php?success=Account+deactivated');
        exit;

    case 'reactivate':
        $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);

        $log = $pdo->prepare("INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
                              VALUES (?, ?, 'reactivate', 'Reactivated account')");
        $log->execute([$_SESSION['user_id'], $user_id]);

        header('Location: manage_staff.php?success=Account+reactivated');
        exit;

    case 'delete':
        // Log before deleting
        $log = $pdo->prepare("INSERT INTO staff_action_log (performed_by, affected_staff_id, action, details)
                              VALUES (?, ?, 'delete', 'Permanently deleted account')");
        $log->execute([$_SESSION['user_id'], $user_id]);

        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);

        header('Location: manage_staff.php?success=Account+deleted');
        exit;
}

header('Location: manage_staff.php?error=Invalid+action');
exit;