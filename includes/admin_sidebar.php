<?php
$academicKeys = [
    'departments',
    'teachers',
    'students',
    'courses',
    'sections',
    'section_management',
    'assignments',
    'academic_years',
    'semesters'
];

$isAcademicActive = in_array($activeMenu, $academicKeys);

$feedbackKeys = [
    'question_sets',
    'forms',
    'results',
    'performance_grades'
];

$isFeedbackActive = in_array($activeMenu, $feedbackKeys);

$nav = [
    [
        'label' => $LANG['nav_dashboard'] ?? 'Dashboard',
        'href' => BASE_URL . 'admin/dashboard.php',
        'key' => 'dashboard',
        'icon' => 'home',
        'iconColor' => 'text-blue-700'
    ],

    [
        'label' => $LANG['nav_user_management'] ?? 'User Management',
        'type' => 'group',
        'key' => 'user_management',
        'isOpen' => in_array($activeMenu, ['users'])
    ],

    [
        'label' => $LANG['nav_users'] ?? 'Users',
        'href' => BASE_URL . 'admin/users.php',
        'key' => 'users',
        'icon' => 'users',
        'indent' => true,
        'group' => 'user_management',
        'iconColor' => 'text-rose-300'
    ],

    [
        'label' => $LANG['nav_academic_management'] ?? 'Academic Management',
        'type' => 'group',
        'key' => 'academic',
        'isOpen' => $isAcademicActive
    ],

    [
        'label' => $LANG['nav_academic_years'] ?? 'Academic Years',
        'href' => BASE_URL . 'admin/academic_years.php',
        'key' => 'academic_years',
        'icon' => 'academic',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-amber-500'
    ],

    [
        'label' => $LANG['nav_semesters'] ?? 'Semesters',
        'href' => BASE_URL . 'admin/semesters.php',
        'key' => 'semesters',
        'icon' => 'clipboard',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-orange-500'
    ],

    [
        'label' => $LANG['nav_section_management'] ?? 'Class Sections',
        'href' => BASE_URL . 'admin/section_management.php',
        'key' => 'section_management',
        'icon' => 'grid',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-violet-700'
    ],

    [
        'label' => $LANG['nav_departments'] ?? 'Departments',
        'href' => BASE_URL . 'admin/departments.php',
        'key' => 'departments',
        'icon' => 'building',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-teal-500'
    ],

    [
        'label' => $LANG['nav_students'] ?? 'Students',
        'href' => BASE_URL . 'admin/students.php',
        'key' => 'students',
        'icon' => 'users',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-cyan-500'
    ],

    [
        'label' => $LANG['nav_teachers'] ?? 'Teachers',
        'href' => BASE_URL . 'admin/teachers.php',
        'key' => 'teachers',
        'icon' => 'user',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-emerald-500'
    ],

    [
        'label' => $LANG['nav_courses'] ?? 'Courses',
        'href' => BASE_URL . 'admin/courses.php',
        'key' => 'courses',
        'icon' => 'book',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-lime-500'
    ],

    [
        'label' => $LANG['nav_sections'] ?? 'Teaching Assignments',
        'href' => BASE_URL . 'admin/sections.php',
        'key' => 'sections',
        'icon' => 'grid',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-violet-700'
    ],

    [
        'label' => $LANG['nav_assignments'] ?? 'Student Assignments',
        'href' => BASE_URL . 'admin/section_assignments.php',
        'key' => 'assignments',
        'icon' => 'link',
        'indent' => true,
        'group' => 'academic',
        'iconColor' => 'text-pink-500'
    ],

    [
        'label' => $LANG['nav_feedback_management'] ?? 'Feedback Management',
        'type' => 'group',
        'key' => 'feedback_management',
        'isOpen' => $isFeedbackActive
    ],

    [
        'label' => $LANG['nav_question_sets'] ?? 'Question Sets',
        'href' => BASE_URL . 'admin/question_sets.php',
        'key' => 'question_sets',
        'icon' => 'question',
        'indent' => true,
        'group' => 'feedback_management',
        'iconColor' => 'text-indigo-700'
    ],

    [
        'label' => $LANG['nav_performance_grades'] ?? 'Performance Grades',
        'href' => BASE_URL . 'admin/performance_grades.php',
        'key' => 'performance_grades',
        'icon' => 'chart',
        'indent' => true,
        'group' => 'feedback_management',
        'iconColor' => 'text-emerald-300'
    ],

    [
        'label' => $LANG['nav_forms'] ?? 'Forms',
        'href' => BASE_URL . 'admin/feedback_forms_all.php',
        'key' => 'forms',
        'icon' => 'document',
        'indent' => true,
        'group' => 'feedback_management',
        'iconColor' => 'text-sky-500'
    ],

    [
        'label' => $LANG['nav_results'] ?? 'Results',
        'href' => BASE_URL . 'admin/results_all.php',
        'key' => 'results',
        'icon' => 'chart',
        'indent' => true,
        'group' => 'feedback_management',
        'iconColor' => 'text-fuchsia-500'
    ],

    [
        'label' => $LANG['nav_trend_analysis'] ?? 'Trend Analysis',
        'type' => 'group',
        'key' => 'trend_analysis',
        'isOpen' => in_array(
            $activeMenu,
            [
                'trend_academic',
                'trend_sa',
                'trend_adm'
            ]
        )
    ],

    [
        'label' => $LANG['nav_academic_trend'] ?? 'Teaching Quality Trend',
        'href' => BASE_URL . 'admin/trend_academic.php',
        'key' => 'trend_academic',
        'icon' => 'history',
        'indent' => true,
        'group' => 'trend_analysis',
        'iconColor' => 'text-yellow-500'
    ],

    [
        'label' => $LANG['nav_sa_trend'] ?? 'Student Support Services Trend',
        'href' => BASE_URL . 'admin/trend_sa.php',
        'key' => 'trend_sa',
        'icon' => 'shield',
        'indent' => true,
        'group' => 'trend_analysis',
        'iconColor' => 'text-red-500'
    ],

    [
        'label' => $LANG['nav_adm_trend'] ?? 'Learning Environment Trend',
        'href' => BASE_URL . 'admin/trend_adm.php',
        'key' => 'trend_adm',
        'icon' => 'office',
        'indent' => true,
        'group' => 'trend_analysis',
        'iconColor' => 'text-green-500'
    ],
];
?>

<!-- ─── Sidebar ─────────────────────────────────────────────── -->
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 text-white flex flex-col z-40
           transform -translate-x-full transition-transform duration-300 ease-in-out
           lg:relative lg:translate-x-0 lg:flex-shrink-0"
    style="background: linear-gradient(180deg, #6366F1, #818CF8);">

    <!-- Brand -->
    <div class="flex items-center gap-3 px-5 py-5 border-b border-white/15">

        <!-- UCSH Logo -->
        <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
            <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH Logo"
                class="w-full h-full object-contain rounded-xl">
        </div>

        <!-- Brand Text -->
        <div class="min-w-0">
            <p class="text-lg font-bold leading-tight">
                <?= $LANG['admin_portal'] ?? 'SFIS Admin' ?>
            </p>

            <p class="text-[12px] text-white/70 leading-tight">
                <?= $LANG['admin_portal_sub'] ?? 'Feedback Management' ?>
            </p>
        </div>

        <!-- Mobile Close Button -->
        <button onclick="closeSidebar()" class="ml-auto lg:hidden text-white/60 hover:text-white">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5 scrollbar-thin"
        style="font-family: 'Noto Sans Myanmar', sans-serif;">

        <?php $groupActive = false; ?>

        <?php foreach ($nav as $item): ?>

            <?php if (($item['type'] ?? '') === 'section'): ?>

                <?php if ($groupActive): ?>
                    </div>
                    <?php $groupActive = false; ?>
                <?php endif; ?>

                <p class="px-3 pt-4 pb-1
                           text-[12px]
                           font-semibold
                           leading-[1.7]
                           tracking-wide
                           text-white/60">
                    <?= e($item['label']) ?>
                </p>

            <?php elseif (($item['type'] ?? '') === 'group'): ?>

                <?php if ($groupActive): ?>
                    </div>
                    <?php $groupActive = false; ?>
                <?php endif; ?>

                <?php $groupKey = $item['key']; ?>

                <div class="px-3 pt-4 pb-0">

                    <button onclick="toggleGroup('<?= $groupKey ?>')" class="w-full flex items-center justify-between
                               pb-3
                               text-[16px]
                               font-semibold
                               leading-[1.7]
                               text-white/70
                               hover:text-white
                               transition-colors
                               border-b border-white/20">

                        <span>
                            <?= e($item['label']) ?>
                        </span>

                        <svg id="chevron-<?= $groupKey ?>" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 flex-shrink-0
                                   transition-transform duration-200
                                   <?= $item['isOpen'] ? 'rotate-90' : '' ?>">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>

                    </button>

                </div>

                <div id="group-<?= $groupKey ?>" class="<?= $item['isOpen'] ? '' : 'hidden' ?>">

                    <?php $groupActive = true; ?>

                <?php else:

                $isActive = ($activeMenu === $item['key']);

                $indent = ($item['indent'] ?? false)
                    ? 'pl-4'
                    : 'pl-3';

                $activeCs = $isActive
                    ? 'bg-white/40 text-white font-semibold'
                    : 'text-white/80 hover:bg-white/40 hover:text-white';

                ?>

                    <!-- Sidebar Menu Item -->
                    <a href="<?= $item['href'] ?>" class="
                        flex items-center gap-3
                        <?= $indent ?>
                        pr-3
                        py-2
                        rounded-xl
                        text-[16px]
                        font-medium
                        leading-[1.7]
                        transition-all duration-150
                        <?= $activeCs ?>
                    ">

                        <?= iconSvg(
                            $item['icon'],
                            'w-5 h-5 flex-shrink-0 ' .
                            ($item['iconColor'] ?? 'text-white/80')
                        ) ?>

                        <span class="min-w-0 flex-1">
                            <?= e($item['label']) ?>
                        </span>

                        <?php if ($isActive): ?>
                            <span class="ml-auto w-1.5 h-1.5 rounded-full bg-white flex-shrink-0"></span>
                        <?php endif; ?>

                    </a>

                <?php endif; ?>

            <?php endforeach; ?>

            <?php if ($groupActive): ?>
            </div>
        <?php endif; ?>

    </nav>

    <!-- User Footer -->
    <a href="<?= e(BASE_URL) ?>auth/logout.php" title="<?= $LANG['logout'] ?? 'Logout' ?>" class="
            block
            border-t border-white/15
            bg-red-500/80
            text-gray-50
            hover:text-gray-200
            transition-colors
            px-4
            py-3
            cursor-pointer
        ">

        <div class="flex items-center justify-center gap-3">

            <div class="min-w-0">
                <p class="
                        text-[16px]
                        font-semibold
                        leading-[1.7]
                    ">
                    <?= $LANG['logout'] ?? 'Logout' ?>
                </p>
            </div>

            <?= iconSvg('logout', 'w-6 h-6 flex-shrink-0') ?>

        </div>

    </a>

</aside>

<script>
    function toggleGroup(key) {
        var el = document.getElementById('group-' + key);
        var chevron = document.getElementById('chevron-' + key);

        if (el) {
            el.classList.toggle('hidden');

            if (chevron) {
                chevron.classList.toggle('rotate-90');
            }
        }
    }
</script>

<!-- ─── Main Column ──────────────────────────────────────────── -->
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">

    <!-- Top Navbar -->
    <header class="
            bg-white
            border-b border-slate-200
            px-4 lg:px-6
            py-3.5
            flex items-center gap-4
            flex-shrink-0
            sticky top-0
            z-20
            shadow-sm
        ">

        <!-- Hamburger -->
        <button onclick="openSidebar()" class="lg:hidden text-slate-500 hover:text-slate-800">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>

        <!-- Page Title -->
        <div>
            <h1 class="text-base font-semibold text-slate-800">
                <?= e($pageTitle) ?>
            </h1>
        </div>

        <div class="ml-auto flex items-center gap-3">

            <!-- Language Switcher -->
            <?php
            $currentLang = $_SESSION['lang'] ?? 'en';
            $adminLanguageUrl = static function (string $language): string {
                $params = $_GET;
                $params['lang'] = $language;
                return '?' . http_build_query($params);
            };
            ?>

            <div class="
                    flex items-center gap-0.5
                    bg-slate-100
                    rounded-lg
                    p-0.5
                    text-xs
                    font-semibold
                    border border-slate-200
                    shadow-sm
                ">

                <a href="<?= e($adminLanguageUrl('en')) ?>" class="
                        px-3 py-1
                        rounded-md
                        transition-all
                        <?= $currentLang === 'en'
                            ? 'bg-white shadow text-indigo-700 font-bold'
                            : 'text-slate-400 hover:text-slate-600'
                            ?>
                    ">
                    ENG
                </a>

                <a href="<?= e($adminLanguageUrl('mm')) ?>" class="
                        px-3 py-1
                        rounded-md
                        transition-all
                        <?= $currentLang === 'mm'
                            ? 'bg-white shadow text-indigo-700 font-bold'
                            : 'text-slate-400 hover:text-slate-600'
                            ?>
                    ">
                    မြန်မာ
                </a>

            </div>

            <!-- Profile -->
            <a href="<?= e(BASE_URL) ?>admin/profile.php" class="
                    flex items-center gap-2
                    px-3 py-1.5
                    rounded-xl
                    hover:bg-slate-50
                    transition-colors
                ">

                <?php if (!empty($user['profile_image'])): ?>

                    <img src="<?= e(BASE_URL) ?><?= e($user['profile_image']) ?>" alt="Profile"
                        class="w-7 h-7 rounded-full object-cover">

                <?php else: ?>

                    <div class="
                            w-7 h-7
                            rounded-full
                            bg-indigo-600
                            flex items-center justify-center
                            text-xs
                            font-bold
                            text-white
                        ">
                        <?= e($initials) ?>
                    </div>

                <?php endif; ?>

                <span class="hidden md:block text-sm font-medium text-slate-700">
                    <?= e($user['name']) ?>
                </span>

            </a>

        </div>

    </header>

    <!-- Page Content -->
    <main class="flex-1 overflow-y-auto p-4 lg:p-6">
