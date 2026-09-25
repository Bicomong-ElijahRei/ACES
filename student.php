<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Student Info Search</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-[#dcf3e6] font-sans h-screen flex flex-col md:flex-row overflow-hidden">

    <?php
    // 1. DATA SOURCE
    $all_students = [];
    for ($i = 1; $i <= 30; $i++) {
        $all_students[] = [
            "id" => "2023-2-" . str_pad($i, 6, "0", STR_PAD_LEFT),
            "first" => ($i % 2 == 0) ? "Michelle" : "Jessica", // Mixing names for search testing
            "last" => ($i % 2 == 0) ? "Guirao" : "Encarguez",
            "course" => "BSIS",
            "section" => "410",
            "email" => "student$i@kld.edu.ph"
        ];
    }

    // 2. SEARCH LOGIC
    $search_query = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
    
    // Filter the array based on search input
    if ($search_query !== '') {
        $filtered_students = array_filter($all_students, function($student) use ($search_query) {
            return str_contains(strtolower($student['id']), $search_query) || 
                   str_contains(strtolower($student['first']), $search_query) || 
                   str_contains(strtolower($student['last']), $search_query) ||
                   str_contains(strtolower($student['email']), $search_query);
        });
    } else {
        $filtered_students = $all_students;
    }

    // 3. PAGINATION LOGIC (Now using the filtered list)
    $limit = 10;
    $total_students = count($filtered_students);
    $total_pages = ceil($total_students / $limit);
    $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($current_page < 1) $current_page = 1;
    if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

    $offset = ($current_page - 1) * $limit;
    // Current slice of the SEARCHED list
    $current_list = array_slice($filtered_students, $offset, $limit);
    ?>

    <aside class="hidden md:flex w-64 bg-[#0a6e2d] text-white flex-col p-6 shrink-0 h-full">
        <div class="flex items-center gap-3 mb-10">
            <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-[#0a6e2d] font-bold italic">Logo</div>
            <span class="font-bold text-lg">KLD</span>
        </div>
        <h2 class="text-2xl font-bold mb-8">Welcome<br>Admin</h2>
        <nav class="space-y-4 text-sm opacity-90">
            <a href="#" class="block">Calendar</a>
            <a href="#" class="block">Dashboard</a>
            <a href="?page=1" class="block font-bold border-l-4 border-white pl-3">Student Info</a>
            <a href="att_verification.php" class="block">Attendance Verification</a>
            <a href="#" class="block">Subtopics</a>
            <a href="#" class="block">Module</a>
        </nav>
    </aside>

    <main class="flex-1 p-4 md:p-10 overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-[#0a6e2d]">Student Information</h1>
            <i class="fa-solid fa-house text-2xl text-[#0a6e2d]"></i>
        </div>

        <div class="bg-white rounded shadow-2xl overflow-hidden">
            <div class="bg-[#054018] px-4 py-3 flex items-center justify-between">
                <button class="text-white text-sm flex items-center gap-2">
                    <i class="fa-solid fa-box-archive"></i> Archive
                </button>
                <div class="flex items-center gap-4">
                    <button class="text-white text-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                    
                    <form method="GET" action="" class="relative">
                        <input type="hidden" name="page" value="1"> 
                        <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" 
                               placeholder="Search name or ID..." 
                               class="py-1 px-4 pr-10 rounded-full text-xs outline-none w-48 md:w-64 border border-transparent focus:border-green-400">
                        <button type="submit" class="absolute right-3 top-2 text-gray-400 hover:text-green-800">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </button>
                    </form>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm min-w-[900px]">
                    <thead>
                        <tr class="text-gray-800 font-bold border-b">
                            <th class="py-4 px-6">Student No.</th>
                            <th class="py-4 px-2">First Name</th>
                            <th class="py-4 px-2">Last Name</th>
                            <th class="py-4 px-2">Course</th>
                            <th class="py-4 px-2">Section</th>
                            <th class="py-4 px-2">Email</th>
                            <th class="py-4 px-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600">
                        <?php if (empty($current_list)): ?>
                            <tr>
                                <td colspan="7" class="py-10 text-center text-gray-400 italic">No students found matching "<?= htmlspecialchars($search_query) ?>"</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($current_list as $student): ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50 transition">
                                <td class="py-4 px-6"><?= $student['id'] ?></td>
                                <td class="py-4 px-2"><?= $student['first'] ?></td>
                                <td class="py-4 px-2"><?= $student['last'] ?></td>
                                <td class="py-4 px-2"><?= $student['course'] ?></td>
                                <td class="py-4 px-2"><?= $student['section'] ?></td>
                                <td class="py-4 px-2 font-bold"><?= $student['email'] ?></td>
                                <td class="py-4 px-2 text-right pr-6">
                                    <i class="fa-solid fa-pen cursor-pointer hover:text-blue-500 mr-3"></i>
                                    <i class="fa-solid fa-trash-can cursor-pointer hover:text-red-500"></i>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="flex justify-center items-center gap-4 py-6">
                <?php 
                    $prev_url = "?page=" . ($current_page - 1) . "&search=" . urlencode($search_query);
                    $next_url = "?page=" . ($current_page + 1) . "&search=" . urlencode($search_query);
                ?>
                
                <a href="<?= $current_page > 1 ? $prev_url : '#' ?>" class="<?= $current_page <= 1 ? 'opacity-20 pointer-events-none' : '' ?>">
                    <i class="fa-solid fa-caret-left text-xl"></i>
                </a>

                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="?page=<?= $p ?>&search=<?= urlencode($search_query) ?>" 
                       class="rounded-full <?= $p == $current_page ? 'w-2.5 h-2.5 bg-black' : 'w-2 h-2 bg-gray-300' ?>"></a>
                <?php endfor; ?>

                <a href="<?= $current_page < $total_pages ? $next_url : '#' ?>" class="<?= $current_page >= $total_pages ? 'opacity-20 pointer-events-none' : '' ?>">
                    <i class="fa-solid fa-caret-right text-xl"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>