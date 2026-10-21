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
<style>
    @media (max-width: 767px) {
        #studentSidebar.mobile-open {
            display: flex !important;
            position: fixed !important;
            top: 0;
            bottom: 0;
            left: 0;
            height: 100vh;
            z-index: 50;
            box-shadow: 2px 0 15px rgba(0,0,0,0.4);
            overflow-y: auto;
        }
        #studentSidebarBackdrop.active { display: block; }
    }
    #studentSidebarBackdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 40;
    }
    
</style>

<aside id="studentSidebar" class="hidden md:flex w-64 bg-[#0a6e2d] text-white flex-col p-4 shrink-0 h-screen sticky top-0 transition-all duration-300 overflow-y-auto">
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

        <!-- Navigation (Fixed: added text-white, reduced spacing) -->
        <nav class="space-y-1 text-sm opacity-90">
            <a href="dashboard.php" class="block text-white hover:text-green-200 hover:bg-white/10 rounded-lg px-3 py-2 transition-colors <?= $current == 'dashboard.php' ? 'font-bold bg-white/20' : '' ?>">
                <i class="fas fa-tachometer-alt mr-2 w-5 text-center"></i><span class="sidebar-text">Dashboard</span>
            </a>
            <a href="sessions.php" class="block text-white hover:text-green-200 hover:bg-white/10 rounded-lg px-3 py-2 transition-colors <?= $current == 'sessions.php' ? 'font-bold bg-white/20' : '' ?>">
                <i class="fas fa-book mr-2 w-5 text-center"></i><span class="sidebar-text">Subtopics</span>
            </a>
            <a href="calendar.php" class="block text-white hover:text-green-200 hover:bg-white/10 rounded-lg px-3 py-2 transition-colors <?= $current == 'calendar.php' ? 'font-bold bg-white/20' : '' ?>">
                <i class="fas fa-calendar-alt mr-2 w-5 text-center"></i><span class="sidebar-text">Calendar</span>
            </a>
            <a href="progress.php" class="block text-white hover:text-green-200 hover:bg-white/10 rounded-lg px-3 py-2 transition-colors <?= $current == 'progress.php' ? 'font-bold bg-white/20' : '' ?>">
                <i class="fas fa-chart-pie mr-2 w-5 text-center"></i><span class="sidebar-text">My Progress</span>
            </a>
            <a href="modules.php" class="block text-white hover:text-green-200 hover:bg-white/10 rounded-lg px-3 py-2 transition-colors <?= $current == 'modules.php' ? 'font-bold bg-white/20' : '' ?>">
                <i class="fas fa-book-open mr-2 w-5 text-center"></i><span class="sidebar-text">Modules</span>
            </a>
            <a href="account.php" class="block text-white hover:text-green-200 hover:bg-white/10 rounded-lg px-3 py-2 transition-colors <?= $current == 'account.php' ? 'font-bold bg-white/20' : '' ?>">
                <i class="fas fa-cog mr-2 w-5 text-center"></i><span class="sidebar-text">Account Settings</span>
            </a>
        </nav>
    </div>

    <!-- Bottom -->
    <div class="mt-auto pt-4 border-t border-white/20">
        <a href="../logout.php" class="block text-white font-bold hover:text-red-300 hover:bg-white/10 rounded-lg px-3 py-2 transition-colors sidebar-text mb-2">
            <i class="fas fa-sign-out-alt mr-2 w-5 text-center"></i><span class="sidebar-text">Logout</span>
        </a>
        <div class="flex gap-4 text-lg opacity-80 sidebar-text px-3">
            <i class="fa-brands fa-facebook cursor-pointer hover:text-white"></i>
            <i class="fa-brands fa-google cursor-pointer hover:text-white"></i>
        </div>
    </div>
</aside>

<div id="studentSidebarBackdrop"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('studentSidebar');
    if (!sidebar) return;

    const toggleBtn = document.getElementById('studentSidebarToggleBtn');
    const mobileBtn = document.getElementById('studentMobileMenuBtn');
    const backdrop = document.getElementById('studentSidebarBackdrop');
    const textElements = sidebar.querySelectorAll('.sidebar-text');

    // Desktop collapse toggle
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-16');
            textElements.forEach(el => el.classList.toggle('hidden'));
        });
    }

    // Mobile open / close
    function openMobile() {
        sidebar.classList.add('mobile-open');
        if (backdrop) backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeMobile() {
        sidebar.classList.remove('mobile-open');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Attach mobile button listener
    if (mobileBtn) {
        mobileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openMobile();
        });
    }
    
    if (backdrop) backdrop.addEventListener('click', closeMobile);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) closeMobile();
    });

    // Close when any nav link is tapped
    sidebar.querySelectorAll('nav a').forEach(function(a) {
        a.addEventListener('click', function() {
            if (window.innerWidth < 768) closeMobile();
        });
    });
});
</script>