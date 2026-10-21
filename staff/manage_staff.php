<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
redirectIfNotStaff();

if (!isAdmin()) {
    header('Location: dashboard.php?error=Access denied');
    exit;
}

// ---- CSRF check on any POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$staff_name = '';
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$staff = $stmt->fetch();
$staff_name = $staff['full_name'];

$action = $_POST['action'] ?? '';
$success = '';
$error = '';

// Handle Create Staff
if ($action === 'create') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $role = $_POST['staff_role'];
    
    // Default random password (user will need to reset it)
    $temp_password = bin2hex(random_bytes(8));
    $hash = password_hash($temp_password, PASSWORD_BCRYPT);

    if (!$full_name || !$email || !$role) {
        $error = "All fields are required.";
    } elseif (!str_ends_with(strtolower($email), '@kld.edu.ph')) {
        $error = "Staff email must be an official KLD email (must end in @kld.edu.ph).";
    } else {
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = "Email already exists.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, role, staff_role, is_active) VALUES (?, ?, ?, 'staff', ?, 1)");
            $stmt->execute([$email, $hash, $full_name, $role]);
            $success = "Staff account created. Temp password: " . $temp_password;
        }
    }
} 

// Handle Toggle Status (Active/Inactive)
elseif ($action === 'toggle_status') {
    $id = (int)$_POST['id'];
    // Prevent deactivating yourself
    if ($id === $_SESSION['user_id']) {
        $error = "You cannot deactivate your own account.";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE user_id = ?");
        $stmt->execute([$id]);
        $success = "Status updated successfully.";
    }
}

// Handle Delete Staff
elseif ($action === 'delete') {
    $id = (int)$_POST['id'];
    if ($id === $_SESSION['user_id']) {
        $error = "You cannot delete your own account.";
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$id]);
        $success = "Staff account deleted.";
    }
}

// Fetch all staff
$stmt = $pdo->query("SELECT u.*, 
    (SELECT full_name FROM users WHERE user_id = u.created_by) as creator_name 
    FROM users u 
    WHERE role = 'staff' 
    ORDER BY u.created_at DESC");
$staff_members = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff | ACES Admin</title>
    <link rel="stylesheet" href="../assets/css/tailwind.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Modal Fixes */
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row">

<?php include '../includes/staff_sidebar.php'; ?>

<div class="flex-1 flex flex-col overflow-hidden">
    <?php include '../includes/header.php'; ?>
    <main class="flex-1 p-4 md:p-8 overflow-y-auto no-scrollbar">

        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-[#0a6e2d]">Manage Staff</h1>
                <p class="text-sm text-gray-600 mt-1">Manage administrative accounts, roles, and access.</p>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" class="bg-[#0a6e2d] hover:bg-[#054018] text-white font-medium py-2 px-4 rounded-lg inline-flex items-center shadow-sm transition-colors" data-bs-toggle="modal" data-bs-target="#createStaffModal">
                    <i class="fas fa-user-plus mr-2"></i> Add New Staff
                </button>
                <a href="dashboard.php" class="text-[#0a6e2d] bg-white p-2 rounded-full shadow-sm hover:shadow-md transition-shadow">
                    <i class="fa-solid fa-house text-xl"></i>
                </a>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if ($success): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                <button type="button" class="ml-auto text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php elseif ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 rounded-r-lg shadow-sm mb-6 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="ml-auto text-red-700 hover:text-red-900" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Staff Table Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            
            <div class="p-5 border-b border-gray-200 bg-gray-50/50 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <h2 class="text-lg font-bold text-gray-800">Staff Directory</h2>
                <span class="bg-[#0a6e2d]/10 text-[#0a6e2d] py-1 px-3 rounded-full text-xs font-bold self-start md:self-auto">
                    <?= count($staff_members) ?> accounts
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-white border-b border-gray-200">
                        <tr class="text-gray-500 font-semibold uppercase tracking-wider text-xs">
                            <th class="py-4 px-6">Staff Member</th>
                            <th class="py-4 px-4">Role</th>
                            <th class="py-4 px-4 text-center">Status</th>
                            <th class="py-4 px-4">Created By</th>
                            <th class="py-4 px-4">Created At</th>
                            <th class="py-4 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700 bg-white">
                        <?php foreach ($staff_members as $staff_member): 
                            $is_self = ($staff_member['user_id'] === $_SESSION['user_id']);
                            
                            // Role badge styling
                            $roleClass = 'bg-gray-100 text-gray-800 border-gray-200';
                            if ($staff_member['staff_role'] === 'admin') $roleClass = 'bg-purple-100 text-purple-800 border-purple-200';
                            elseif ($staff_member['staff_role'] === 'lead') $roleClass = 'bg-blue-100 text-blue-800 border-blue-200';
                            
                            // Status badge styling
                            $statusClass = $staff_member['is_active'] ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200';
                        ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150 <?= $is_self ? 'bg-green-50/30' : '' ?>">
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 flex-shrink-0 rounded-full <?= $is_self ? 'bg-[#0a6e2d] text-white' : 'bg-gray-100 text-gray-600' ?> flex items-center justify-center font-bold text-sm shadow-sm">
                                            <?= strtoupper(substr($staff_member['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-gray-900">
                                                <?= htmlspecialchars($staff_member['full_name']) ?>
                                                <?php if ($is_self): ?>
                                                    <span class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#0a6e2d] text-white uppercase tracking-wider">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($staff_member['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $roleClass ?> uppercase tracking-wide">
                                        <?= htmlspecialchars($staff_member['staff_role'] ?: 'viewer') ?>
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $statusClass ?>">
                                        <span class="w-1.5 h-1.5 rounded-full mr-1.5 <?= $staff_member['is_active'] ? 'bg-green-600' : 'bg-red-600' ?>"></span>
                                        <?= $staff_member['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-xs text-gray-600">
                                    <?= htmlspecialchars($staff_member['creator_name'] ?: 'System / N/A') ?>
                                </td>
                                <td class="py-4 px-4 text-xs text-gray-500">
                                    <?= date('M d, Y', strtotime($staff_member['created_at'])) ?>
                                </td>
                                <td class="py-4 px-6 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="edit_staff.php?id=<?= $staff_member['user_id'] ?>" class="text-blue-600 hover:text-blue-900 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg transition-colors" title="Edit Account">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <?php if (!$is_self): ?>
                                            <form method="POST" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= $staff_member['user_id'] ?>">
                                                <button type="submit" class="<?= $staff_member['is_active'] ? 'text-amber-600 hover:text-amber-900 bg-amber-50 hover:bg-amber-100' : 'text-green-600 hover:text-green-900 bg-green-50 hover:bg-green-100' ?> p-2 rounded-lg transition-colors" title="<?= $staff_member['is_active'] ? 'Deactivate Account' : 'Activate Account' ?>">
                                                    <i class="fas <?= $staff_member['is_active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                                </button>
                                            </form>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('PERMANENTLY DELETE this staff account? This cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $staff_member['user_id'] ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900 bg-red-50 hover:bg-red-100 p-2 rounded-lg transition-colors" title="Delete Account">
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
        </div>

    </main>
</div>

<!-- ==================== CREATE STAFF MODAL ==================== -->
<div class="modal fade" id="createStaffModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-xl rounded-xl overflow-hidden">
            <div class="modal-header bg-[#0a6e2d] text-white p-4 border-0">
                <h5 class="text-lg font-bold"><i class="fas fa-user-plus mr-2"></i>Register New Staff</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-body p-6 bg-gray-50 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                        <input type="text" name="full_name" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                        <input type="email" name="email" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
                        <select name="staff_role" class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-[#0a6e2d] focus:border-[#0a6e2d]" required>
                            <option value="viewer">Viewer — Read-only access</option>
                            <option value="lead">Lead — Manage sessions and attendance</option>
                            <option value="admin">Admin — Full system access</option>
                        </select>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800">
                        <i class="fas fa-info-circle mr-1"></i> A temporary password will be generated automatically. The staff member will be prompted to change it upon first login.
                    </div>
                </div>
                <div class="modal-footer bg-white border-t border-gray-200 p-4">
                    <button type="button" class="bg-gray-100 border border-gray-300 text-gray-700 hover:bg-gray-200 px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="bg-[#0a6e2d] hover:bg-[#054018] text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>