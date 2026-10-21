<!-- Student Header -->
<div class="bg-[#054018] text-white h-14 flex items-center justify-between px-5 shadow-md sticky top-0 z-50">
    
    <!-- Left: Hamburger (Mobile) + Logo + Name -->
    <div class="flex items-center gap-3">
        <!-- This is the button the sidebar is looking for -->
        <button type="button" id="studentMobileMenuBtn" class="md:hidden text-white/90 hover:text-white border-0 bg-transparent p-1 focus:outline-none" aria-label="Open navigation menu">
            <i class="fas fa-bars fa-lg"></i>
        </button>
        
        <!-- Added inline style to force the height just in case Tailwind fails -->
        <img src="../assets/images/kld_logo.png" alt="KLD" class="bg-white rounded-full p-0.5" style="height: 32px; width: 32px; object-fit: contain;" onerror="this.style.display='none'">
        
        <span class="text-xl font-bold tracking-wide hidden sm:inline">ACES Student</span>
    </div>

    <!-- Right: Home Icon -->
    <a href="dashboard.php" class="text-white/80 hover:text-white transition-colors duration-200">
        <i class="fas fa-home fa-lg"></i>
    </a>
</div>