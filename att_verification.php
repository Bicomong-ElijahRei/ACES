<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Attendance Verification</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Hide scrollbar for Chrome, Safari and Opera */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        /* Hide scrollbar for IE, Edge and Firefox */
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">

    <?php
    $students = [
        ["id" => "2023-2-000690", "name" => "Michelle Guirao", "date" => "03-22-26", "course" => "BSIS", "section" => "410"],
        ["id" => "2023-2-000451", "name" => "Jessica Encarguez", "date" => "03-22-26", "course" => "BSIS", "section" => "410"],
        ["id" => "2023-2-000450", "name" => "Elijah Biconong", "date" => "03-22-26", "course" => "BSIS", "section" => "410"],
        ["id" => "2023-2-000449", "name" => "Andrea Santos", "date" => "03-19-26", "course" => "BSP", "section" => "410"],
        ["id" => "2023-2-000448", "name" => "John Rivera", "date" => "03-19-26", "course" => "BSP", "section" => "403"],
        ["id" => "2023-2-000447", "name" => "Jackie Chan", "date" => "03-19-26", "course" => "BSP", "section" => "403"],
    ];
    ?>

    <aside class="hidden md:flex w-64 bg-[#0a6e2d] text-white flex-col justify-between p-6 shrink-0 h-full">
        <div>
            <div class="flex items-center gap-3 mb-10">
                <img src="https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png" 
                     class="w-10 h-10 rounded-full border border-white/20 bg-white p-0.5 object-contain" alt="Logo">
                <span class="font-bold tracking-wider text-lg">KLD</span>
            </div>

            <h2 class="text-2xl font-bold mb-8 leading-tight">Welcome<br>Admin</h2>

            <nav class="space-y-4 text-sm opacity-90">
                <a href="#" class="block hover:text-green-200">Calendar</a>
                <a href="#" class="block hover:text-green-200">Dashboard</a>
                <a href="student.php" class="block hover:text-green-200">Student Info</a>
                <a href="#" class="block font-bold border-l-4 border-white pl-3">Attendance Verification</a>
                <a href="#" class="block hover:text-green-200">Subtopics</a>
                <a href="#" class="block hover:text-green-200">Module</a>
                <a href="#" class="block hover:text-green-200">Student Progress</a>
                <a href="#" class="block hover:text-green-200">Account Settings</a>
            </nav>
        </div>

        <div class="mt-auto">
            <a href="#" class="block font-bold mb-4 hover:text-red-300">Logout</a>
            <div class="flex gap-4 text-lg opacity-80">
                <i class="fa-brands fa-facebook cursor-pointer"></i>
                <i class="fa-brands fa-google cursor-pointer"></i>
            </div>
        </div>
    </aside>

    <main class="flex-1 p-4 md:p-10 relative overflow-y-auto no-scrollbar">
        
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl md:text-4xl font-bold text-[#0a6e2d]">Attendance Verification</h1>
            
            <a href="#" class="text-[#0a6e2d]">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M3 10.4V21h18V10.4l-9-6.3-9 6.3zM14.2 19h-4.4v-5.6h4.4V19z"/>
                </svg>
            </a>
        </div>

        <div class="bg-white rounded shadow-xl overflow-hidden mb-10">
            
            <div class="bg-[#054018] px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                <button class="text-white text-xs md:text-sm font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-box-archive"></i> Archive
                </button>
                
                <div class="flex items-center gap-2 md:gap-4 flex-1 justify-end">
                    <button class="text-white text-xs md:text-sm flex items-center gap-1">
                        <i class="fa-solid fa-filter"></i> <span class="hidden sm:inline">Filter</span>
                    </button>
                    <div class="relative w-full max-w-[150px] md:max-w-[250px]">
                        <input type="text" placeholder="Search" class="w-full py-1 px-3 md:px-4 rounded-full text-xs outline-none">
                        <i class="fa-solid fa-magnifying-glass absolute right-3 top-2 text-gray-400 text-[10px]"></i>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs md:text-sm min-w-[800px]">
                    <thead>
                        <tr class="text-gray-800 font-bold border-b border-gray-100">
                            <th class="py-4 px-4">Student No.</th>
                            <th class="py-4 px-4">Student Name</th>
                            <th class="py-4 px-4">Date</th>
                            <th class="py-4 px-4">Course</th>
                            <th class="py-4 px-4">Section</th>
                            <th class="py-4 px-4">Session Attended</th>
                            <th class="py-4 px-4 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600">
                        <?php foreach ($students as $student): ?>
                        <tr class="border-b border-gray-50 hover:bg-gray-50 transition">
                            <td class="py-4 px-4"><?= $student['id'] ?></td>
                            <td class="py-4 px-4 font-semibold"><?= $student['name'] ?></td>
                            <td class="py-4 px-4"><?= $student['date'] ?></td>
                            <td class="py-4 px-4"><?= $student['course'] ?></td>
                            <td class="py-4 px-4"><?= $student['section'] ?></td>
                            <td class="py-4 px-4 italic font-bold">"SESSIONTITLE"</td>
                            <td class="py-4 px-4 text-center">
                                <input type="checkbox" class="w-4 h-4 accent-green-800">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex justify-center items-center gap-3 py-6 text-gray-400">
                <i class="fa-solid fa-caret-left cursor-pointer"></i>
                <div class="w-2 h-2 bg-black rounded-full"></div>
                <div class="w-1.5 h-1.5 bg-gray-300 rounded-full"></div>
                <div class="w-1.5 h-1.5 bg-gray-300 rounded-full"></div>
                <i class="fa-solid fa-caret-right cursor-pointer"></i>
            </div>
        </div>
    </main>

    <div class="md:hidden bg-[#0a6e2d] text-white p-3 flex justify-around text-xl">
        <i class="fa-solid fa-house"></i>
        <i class="fa-solid fa-calendar"></i>
        <i class="fa-solid fa-user-graduate"></i>
        <i class="fa-solid fa-gear"></i>
    </div>

</body>
</html>