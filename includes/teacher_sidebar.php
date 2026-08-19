<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
$user = getCurrentUser();
$navItems = $navItems ?? [
    ['label' => $LANG['nav_dashboard'] ?? 'Dashboard', 'href' => BASE_URL . 'teacher/dashboard.php', 'key' => 'dashboard', 'icon' => 'home'],
    ['label' => $LANG['nav_my_sections'] ?? 'My Sections', 'href' => BASE_URL . 'teacher/my_sections.php', 'key' => 'sections', 'icon' => 'grid'],
    ['label' => $LANG['nav_feedback_results'] ?? 'Feedback Results', 'href' => BASE_URL . 'teacher/feedback_results.php', 'key' => 'results', 'icon' => 'chart'],
    ['label' => $LANG['nav_trend_analysis'] ?? 'Trend Analysis', 'href' => BASE_URL . 'teacher/trend_analysis.php', 'key' => 'trend', 'icon' => 'history'],
    ['label' => $LANG['nav_profile'] ?? 'Profile', 'href' => BASE_URL . 'teacher/profile.php', 'key' => 'profile', 'icon' => 'user'],
];
$initials = avatarInitials($user['name']);

// Icon color map — edit these to change sidebar icon colors
$iconColors = [
    'dashboard' => 'text-blue-700',
    'sections' => 'text-emerald-500',
    'results' => 'text-amber-500',
    'trend' => 'text-cyan-700',
    'profile' => 'text-pink-500',
];
?>
<div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
<div class="flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside id="sidebar"
        class="fixed inset-y-0 left-0 w-64 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0"
        style="background: linear-gradient(180deg, rgba(59,130,246,0.95), rgba(96,165,250,0.75));">
        <div class="flex items-center gap-3 px-5 py-5 border-b border-white/15">
            <!-- <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shadow-lg">
                <?= iconSvg('user', 'w-5 h-5 text-white') ?>
            </div> -->
            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
                <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH Logo"
                    class="w-full h-full object-contain rounded-xl">
            </div>
            <div>
                <p class="text-lg font-bold tracking-wide"><?= $LANG['teacher_portal'] ?? 'SFIS Teacher' ?></p>
                <p class="text-sm text-white/70"><?= $LANG['teacher_portal_sub'] ?? 'Faculty Portal' ?></p>
            </div>
            <button onclick="closeSidebar()" class="ml-auto lg:hidden text-white/60 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5">
            <?php foreach ($navItems as $item):
                $active = $activeMenu === $item['key'];
                $cls = $active ? 'bg-white/20 text-white font-semibold' : 'text-white/80 hover:bg-white/10 hover:text-white';
                ?>
                <a href="<?= $item['href'] ?>"
                    class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm transition-all <?= $cls ?>">
                    <?= iconSvg($item['icon'], 'w-5 h-5 flex-shrink-0 ' . ($iconColors[$item['key']] ?? 'text-white/80')) ?>
                    <?= e($item['label']) ?>
                    <?php if ($active): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
                </a>
            <?php endforeach ?>
        </nav>
        <a href="<?= e(BASE_URL) ?>auth/logout.php" title="<?= $LANG['logout'] ?? 'Logout' ?>"
            class="block border-t border-white/15 bg-red-500/80 text-gray-50 hover:text-gray-200 transition-colors px-4 py-4 cursor-pointer">
            <div class="flex items-center justify-center gap-3">

                <div class="min-w-0 ">
                    <p class="text-xl h-8"><?= $LANG['logout'] ?? 'Logout' ?></p>
                </div>
                <?= iconSvg('logout', 'w-6 h-6') ?>
            </div>
        </a>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header
            class="no-print bg-white/80 backdrop-blur-sm border-b border-blue-100/50 px-4 lg:px-6 py-3.5 flex items-center gap-4 flex-shrink-0 sticky top-0 z-20 shadow-sm">
            <button onclick="openSidebar()" class="lg:hidden text-blue-400 hover:text-blue-700">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
            <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
            <div class="ml-auto flex items-center gap-3">
                <!-- Language Switcher -->
                <?php
                $currentLang = $_SESSION['lang'] ?? 'en';
                $languageUrl = static function (string $language): string {
                    $params = $_GET;
                    $params['lang'] = $language;
                    return '?' . http_build_query($params);
                };
                ?>
                <div
                    class="flex items-center gap-0.5 bg-blue-50 rounded-lg p-0.5 text-xs font-semibold border border-blue-100 shadow-sm">
                    <a href="<?= e($languageUrl('en')) ?>"
                        class="px-3 py-1 rounded-md transition-all <?= $currentLang === 'en' ? 'bg-white shadow text-blue-700 font-bold' : 'text-blue-300 hover:text-blue-600' ?>">
                        ENG
                    </a>
                    <a href="<?= e($languageUrl('mm')) ?>"
                        class="px-3 py-1 rounded-md transition-all <?= $currentLang === 'mm' ? 'bg-white shadow text-blue-700 font-bold' : 'text-blue-300 hover:text-blue-600' ?>">
                        မြန်မာ
                    </a>
                </div>
                <!-- Profile -->
                <a href="<?= e(BASE_URL) ?>teacher/profile.php"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-blue-50/60">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="<?= e(BASE_URL) ?><?= e($user['profile_image']) ?>" alt="Profile"
                            class="w-7 h-7 rounded-full object-cover">
                    <?php else: ?>
                        <div
                            class="w-7 h-7 rounded-full bg-blue-700 flex items-center justify-center text-xs font-bold text-white">
                            <?= e($initials) ?>
                        </div>
                    <?php endif; ?>
                    <span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span>
                </a>
            </div>
        </header>
        <main class="flex-1 overflow-y-auto p-4 lg:p-6">
