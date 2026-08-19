<?php
/**
 * Student Page Top Header Bar
 * Include this AFTER the <div class="flex-1 flex flex-col"> wrapper
 * Requires: $pageTitle, session started
 */
$currentLang = $_SESSION['lang'] ?? 'en';
$user      = getCurrentUser();
$initials  = avatarInitials($user['name']);
$studentLanguageUrl = static function (string $language): string {
    $params = $_GET;
    $params['lang'] = $language;
    unset($params['ajax'], $params['embed']);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ($_SERVER['PHP_SELF'] ?? '');
    return $path . '?' . http_build_query($params);
};
?>
<header class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 sticky top-0 z-20 shadow-sm">
    <!-- Hamburger (mobile) -->
    <button onclick="openSidebar()" class="lg:hidden text-slate-500">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
            stroke="currentColor" class="w-6 h-6">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    </button>

    <!-- Page Title -->
    <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>

    <!-- Right: Language + Profile -->
    <div class="ml-auto flex items-center gap-3">
        <!-- Language Switcher -->
        <div class="flex items-center gap-0.5 bg-cyan-50 rounded-lg p-0.5 text-xs font-semibold border border-cyan-100 shadow-sm">
            <a href="<?= e($studentLanguageUrl('en')) ?>" data-language="en"
               class="student-language-link px-3 py-1 rounded-md transition-all <?= $currentLang === 'en' ? 'bg-white shadow text-cyan-700 font-bold' : 'text-cyan-400 hover:text-cyan-700' ?>">
                ENG
            </a>
            <a href="<?= e($studentLanguageUrl('mm')) ?>" data-language="mm"
               class="student-language-link px-3 py-1 rounded-md transition-all <?= $currentLang === 'mm' ? 'bg-white shadow text-cyan-700 font-bold' : 'text-cyan-400 hover:text-cyan-700' ?>">
                မြန်မာ
            </a>
        </div>
        <!-- Profile -->
        <a href="<?= e(BASE_URL) ?>student/profile.php"
           class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-cyan-50/60 transition-colors">
            <?php if (!empty($user['profile_image'])): ?>
                <img src="<?= e(BASE_URL) ?><?= e($user['profile_image']) ?>" alt="Profile"
                    class="w-7 h-7 rounded-full object-cover">
            <?php else: ?>
                <div class="w-7 h-7 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold text-white">
                    <?= e($initials) ?>
                </div>
            <?php endif; ?>
            <span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span>
        </a>
    </div>
</header>
