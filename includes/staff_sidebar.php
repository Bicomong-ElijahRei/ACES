<?php
if (!isset($staff_name)) {
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $sidebar_user = $stmt->fetch();
    $staff_name = $sidebar_user['full_name'];
}
$current = basename($_SERVER['PHP_SELF']);
?>
<aside id="staffSidebar" class="hidden md:flex w-64 bg-[#0a6e2d] text-white flex-col justify-between p-4 shrink-0 h-full transition-all duration-300 overflow-hidden">
    <!-- Top brand & collapse toggle -->
    <div>
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3" id="sidebarBrand">
                <img src="../assets/images/kld_logo.png" class="w-10 h-10 rounded-full border border-white/20 bg-white p-0.5 object-contain" alt="KLD">
                <span class="font-bold tracking-wider text-lg sidebar-text">KLD</span>
            </div>
            <button id="sidebarToggleBtn" class="text-white/70 hover:text-white text-xl focus:outline-none">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- Welcome -->
        <div class="mb-6 sidebar-text">
            <h2 class="text-xl font-bold leading-tight">Welcome<br><?= htmlspecialchars($staff_name) ?></h2>
        </div>

        <!-- Navigation Groups -->
        <nav class="space-y-4 text-sm opacity-90">

            <!-- Main Group -->
            <div class="sidebar-group">
                <button class="group-header flex items-center justify-between w-full text-left hover:text-green-200 py-1">
                    <span><i class="fas fa-th-large mr-2 w-4 text-center"></i><span class="sidebar-text">Main</span></span>
                    <i class="fas fa-chevron-down text-xs transition-transform duration-200 sidebar-text"></i>
                </button>
                <div class="group-links ml-6 mt-2 space-y-2 hidden">
                    <a href="calendar.php" class="block hover:text-green-200 sidebar-link <?= $current == 'calendar.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="far fa-calendar-alt mr-2 w-4 text-center"></i><span class="sidebar-text">Calendar</span>
                    </a>
                    <a href="dashboard.php" class="block hover:text-green-200 sidebar-link <?= $current == 'dashboard.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-tachometer-alt mr-2 w-4 text-center"></i><span class="sidebar-text">Dashboard</span>
                    </a>
                </div>
            </div>

            <!-- Management Group -->
            <?php if (!isViewer()): ?>
            <div class="sidebar-group">
                <button class="group-header flex items-center justify-between w-full text-left hover:text-green-200 py-1">
                    <span><i class="fas fa-cogs mr-2 w-4 text-center"></i><span class="sidebar-text">Management</span></span>
                    <i class="fas fa-chevron-down text-xs transition-transform duration-200 sidebar-text"></i>
                </button>
                <div class="group-links ml-6 mt-2 space-y-2 hidden">
                    <a href="students.php" class="block hover:text-green-200 sidebar-link <?= $current == 'students.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-user-graduate mr-2 w-4 text-center"></i><span class="sidebar-text">Student Info</span>
                    </a>
                    <a href="attendance.php" class="block hover:text-green-200 sidebar-link <?= $current == 'attendance.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-clipboard-check mr-2 w-4 text-center"></i><span class="sidebar-text">Attendance</span>
                    </a>
                    <a href="subtopics.php" class="block hover:text-green-200 sidebar-link <?= $current == 'subtopics.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-list-alt mr-2 w-4 text-center"></i><span class="sidebar-text">Sessions</span>
                    </a>
                    <a href="modules.php" class="block hover:text-green-200 sidebar-link <?= $current == 'modules.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-book-open mr-2 w-4 text-center"></i><span class="sidebar-text">Modules</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reports Group -->
            <div class="sidebar-group">
                <button class="group-header flex items-center justify-between w-full text-left hover:text-green-200 py-1">
                    <span><i class="fas fa-chart-bar mr-2 w-4 text-center"></i><span class="sidebar-text">Reports</span></span>
                    <i class="fas fa-chevron-down text-xs transition-transform duration-200 sidebar-text"></i>
                </button>
                <div class="group-links ml-6 mt-2 space-y-2 hidden">
                    <a href="student_progress.php" class="block hover:text-green-200 sidebar-link <?= $current == 'student_progress.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-chart-line mr-2 w-4 text-center"></i><span class="sidebar-text">Student Progress</span>
                    </a>
                    <?php if (isAdmin()): ?>
                    <a href="registrants.php" class="block hover:text-green-200 sidebar-link <?= $current == 'registrants.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-list-ul mr-2 w-4 text-center"></i><span class="sidebar-text">Registrants</span>
                    </a>
                    <?php endif; ?>
                    <a href="reports.php" class="block hover:text-green-200 sidebar-link <?= $current == 'reports.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-file-export mr-2 w-4 text-center"></i><span class="sidebar-text">Reports & Exports</span>
                    </a>
                </div>
            </div>

            <!-- Account Group -->
            <div class="sidebar-group">
                <button class="group-header flex items-center justify-between w-full text-left hover:text-green-200 py-1">
                    <span><i class="fas fa-user-circle mr-2 w-4 text-center"></i><span class="sidebar-text">Account</span></span>
                    <i class="fas fa-chevron-down text-xs transition-transform duration-200 sidebar-text"></i>
                </button>
                <div class="group-links ml-6 mt-2 space-y-2 hidden">
                    <?php if (isAdmin()): ?>
                    <a href="manage_staff.php" class="block hover:text-green-200 sidebar-link <?= $current == 'manage_staff.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-users-cog mr-2 w-4 text-center"></i><span class="sidebar-text">Manage Staff</span>
                    </a>
                    <?php endif; ?>
                    <a href="account.php" class="block hover:text-green-200 sidebar-link <?= $current == 'account.php' ? 'font-bold border-l-4 border-white pl-2' : '' ?>">
                        <i class="fas fa-cog mr-2 w-4 text-center"></i><span class="sidebar-text">Account Settings</span>
                    </a>
                </div>
            </div>
        </nav>
    </div>

    <!-- Bottom -->
    <div class="mt-auto pt-4 border-t border-white/20">
        <a href="../logout.php" class="block font-bold hover:text-red-300 sidebar-text mb-2">
            <i class="fas fa-sign-out-alt mr-2 w-4 text-center"></i><span class="sidebar-text">Logout</span>
        </a>
        <div class="flex gap-4 text-lg opacity-80 sidebar-text">
            <i class="fa-brands fa-facebook cursor-pointer"></i>
            <i class="fa-brands fa-google cursor-pointer"></i>
        </div>
    </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('staffSidebar');
    if (!sidebar) return;

    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const textElements = sidebar.querySelectorAll('.sidebar-text');
    const groups = sidebar.querySelectorAll('.sidebar-group');

    // Whole sidebar collapse
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-16');
            textElements.forEach(el => el.classList.toggle('hidden'));
        });
    }

    let openGroup = null;
    let closeTimeout = null;

    function closeGroup(group) {
        const links = group.querySelector('.group-links');
        const chevron = group.querySelector('.fa-chevron-down');
        if (links && !links.classList.contains('hidden')) {
            links.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    }

    function openGroupOnly(group) {
        // Close any other open group
        if (openGroup && openGroup !== group) {
            closeGroup(openGroup);
        }
        const links = group.querySelector('.group-links');
        const chevron = group.querySelector('.fa-chevron-down');
        if (links && links.classList.contains('hidden')) {
            links.classList.remove('hidden');
            if (chevron) chevron.classList.add('rotate-180');
        }
        openGroup = group;
        // Cancel any pending close timer
        if (closeTimeout) {
            clearTimeout(closeTimeout);
            closeTimeout = null;
        }
    }

    function scheduleClose(group) {
        // Only schedule if this group is the currently open one
        if (openGroup === group) {
            closeTimeout = setTimeout(() => {
                closeGroup(group);
                openGroup = null;
            }, 3000); // 3 seconds
        }
    }

    groups.forEach(group => {
        const header = group.querySelector('.group-header');
        if (!header) return;

        // Hover over the group header → open immediately
        header.addEventListener('mouseenter', function() {
            openGroupOnly(group);
        });

        // When leaving the entire group, start the close timer
        group.addEventListener('mouseleave', function() {
            scheduleClose(group);
        });

        // Cancel close timer if mouse re-enters the group (even without touching header)
        group.addEventListener('mouseenter', function() {
            if (closeTimeout) {
                clearTimeout(closeTimeout);
                closeTimeout = null;
            }
        });
    });

    // All groups remain closed on page load (already hidden via "hidden" class)
});
</script>