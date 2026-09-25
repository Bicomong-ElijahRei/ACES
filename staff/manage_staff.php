<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

// Only admins can access this page
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$success = $_GET['success'] ?? '';
$error   = $_GET['error'] ?? '';

// Fetch all staff members
$stmt = $pdo->query("
    SELECT u.user_id, u.email, u.full_name, u.staff_role, u.is_active, u.created_at,
           creator.full_name AS created_by_name
    FROM users u
    LEFT JOIN users creator ON u.created_by = creator.user_id
    WHERE u.role = 'staff'
    ORDER BY u.created_at DESC
");
$staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff | ACES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-10 overflow-y-auto no-scrollbar">
        <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d] mb-6">Manage Staff</h1>

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="mb-4 text-right">
            <a href="../public/register_staff.php" class="bg-green-700 hover:bg-green-800 text-white font-bold py-2 px-4 rounded inline-flex items-center">
                <i class="fas fa-user-plus mr-2"></i> Add New Staff
            </a>
        </div>

        <div class="bg-white rounded shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-[#054018] text-white">
                        <tr>
                            <th class="py-4 px-4">Name</th>
                            <th class="py-4 px-4">Email</th>
                            <th class="py-4 px-4">Role</th>
                            <th class="py-4 px-4">Status</th>
                            <th class="py-4 px-4">Created By</th>
                            <th class="py-4 px-4">Created At</th>
                            <th class="py-4 px-4 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($staff_members)): ?>
                            <tr>
                                <td colspan="7" class="py-4 text-center text-gray-500">No staff members found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staff_members as $staff): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-4 px-4 font-semibold"><?= htmlspecialchars($staff['full_name']) ?></td>
                                    <td class="py-4 px-4"><?= htmlspecialchars($staff['email']) ?></td>
                                    <td class="py-4 px-4">
                                        <?php if ($staff['user_id'] == $_SESSION['user_id']): ?>
                                            <span class="text-xs font-medium bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($staff['staff_role']) ?></span>
                                        <?php else: ?>
                                            <form method="POST" action="edit_staff.php" class="flex items-center gap-2">
                                                <input type="hidden" name="action" value="update_role">
                                                <input type="hidden" name="user_id" value="<?= $staff['user_id'] ?>">
                                                <select name="staff_role" class="text-xs border rounded px-2 py-1" onchange="this.form.submit()">
                                                    <option value="admin" <?= $staff['staff_role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                    <option value="lead" <?= $staff['staff_role'] === 'lead' ? 'selected' : '' ?>>Lead</option>
                                                    <option value="viewer" <?= $staff['staff_role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                                </select>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $staff['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                            <?= $staff['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-4 text-sm text-gray-600"><?= htmlspecialchars($staff['created_by_name'] ?? 'N/A') ?></td>
                                    <td class="py-4 px-4 text-sm text-gray-600"><?= date('M d, Y', strtotime($staff['created_at'])) ?></td>
                                    <td class="py-4 px-4 text-center">
                                        <?php if ($staff['user_id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" action="edit_staff.php" class="inline">
                                                <input type="hidden" name="action" value="<?= $staff['is_active'] ? 'deactivate' : 'reactivate' ?>">
                                                <input type="hidden" name="user_id" value="<?= $staff['user_id'] ?>">
                                                <button type="submit" class="text-xs <?= $staff['is_active'] ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' ?> font-medium">
                                                    <?= $staff['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                                </button>
                                            </form>
                                            <form method="POST" action="edit_staff.php" class="inline ml-2" onsubmit="return confirm('Permanently delete this staff account?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?= $staff['user_id'] ?>">
                                                <button type="submit" class="text-xs text-red-600 hover:text-red-800 font-medium">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">You</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>