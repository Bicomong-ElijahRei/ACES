<?php
if (!isset($student)) {
    require_once '../config/database.php';
    $stmt = $pdo->prepare("SELECT u.full_name, s.section FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.student_id = ?");
    $stmt->execute([$_SESSION['student_id']]);
    $student = $stmt->fetch();
    if (!$student) {
        $student = ['full_name' => 'Student', 'section' => ''];
    }
}
$full_name = $student['full_name'];
$name_parts = explode(' ', $full_name);
$first_name = $name_parts[0];
$current = basename($_SERVER['PHP_SELF']);
?>
<aside id="studentSidebar" class="hidden md:flex w-64 bg-[#0a6e2d] text-white flex-col justify-between p-4 shrink-0 h-full transition-all duration-300 overflow-hidden">
    <div>
        <!-- Top brand & collapse toggle -->
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3" id="sidebarBrand">
                <img src="../assets/images/kld_logo.png" class="w-10 h-10 rounded-full border border-white/20 bg-white p-0.5 object-contain" alt="KLD">
            </div>
            <button id="studentSidebarToggleBtn" class="text-white/70 hover:text-white text-xl focus:outline-none">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- Welcome -->
        <div class="mb-6 sidebar-text">
            <h2 class="text-xl font-bold leading-tight">Welcome<br><?= htmlspecialchars($first_name) ?></h2>
        </div>

        <!-- Navigation -->
        <nav class="space-y-4 text-sm opacity-90">
            <a href="dashboard.php" class="block hover:text-green-200 <?= $current == 'dashboard.php' ? 'font-bold border-l-4 border-white pl-3' : '' ?>">
                <i class="fas fa-tachometer-alt mr-2 w-4 text-center"></i><span class="sidebar-text">Dashboard</span>
            </a>
            <a href="sessions.php" class="block hover:text-green-200 <?= $current == 'sessions.php' ? 'font-bold border-l-4 border-white pl-3' : '' ?>">
                <i class="fas fa-book mr-2 w-4 text-center"></i><span class="sidebar-text">Subtopics</span>
            </a>
            <a href="modules.php" class="block hover:text-green-200 <?= $current == 'modules.php' ? 'font-bold border-l-4 border-white pl-3' : '' ?>">
                <i class="fas fa-book-open mr-2 w-4 text-center"></i><span class="sidebar-text">Modules</span>
            </a>
            <a href="account.php" class="block hover:text-green-200 <?= $current == 'account.php' ? 'font-bold border-l-4 border-white pl-3' : '' ?>">
                <i class="fas fa-cog mr-2 w-4 text-center"></i><span class="sidebar-text">Account Settings</span>
            </a>
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
    const sidebar = document.getElementById('studentSidebar');
    const toggleBtn = document.getElementById('studentSidebarToggleBtn');
    const textElements = sidebar.querySelectorAll('.sidebar-text');

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-16');
            textElements.forEach(el => el.classList.toggle('hidden'));
        });
    }
});
</script>