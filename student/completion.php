<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireRole('student');

$user     = getCurrentUser();
$initials = avatarInitials($user['name']);
$pageTitle = $LANG['completion_congratulations'] ?? 'Congratulations!';
$currentLang = $_SESSION['lang'] ?? 'en';
$completionLanguageUrl = static function (string $language): string {
    return ($_SERVER['PHP_SELF'] ?? BASE_URL . 'student/completion.php') . '?lang=' . $language;
};
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — SFIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
    <style>
        body { font-family: 'Inter', sans-serif; }

        @keyframes sparkleFloat {
            0%, 100% { transform: scale(1) rotate(-2deg); }
            50%       { transform: scale(1.08) rotate(2deg); }
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(32px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes checkDraw {
            from { stroke-dashoffset: 100; }
            to   { stroke-dashoffset: 0; }
        }
        @keyframes confettiFall {
            to { transform: translateY(115vh) rotate(720deg); opacity: 0; }
        }
        @keyframes shimmer {
            from { background-position: -200% center; }
            to   { background-position: 200% center; }
        }

        .trophy-float { animation: sparkleFloat 3s ease-in-out infinite; }
        .slide-up-1 { animation: slideUp 0.6s ease both; }
        .slide-up-2 { animation: slideUp 0.6s ease both; animation-delay: 0.12s; }
        .slide-up-3 { animation: slideUp 0.6s ease both; animation-delay: 0.25s; }
        .slide-up-4 { animation: slideUp 0.6s ease both; animation-delay: 0.38s; }
        .slide-up-5 { animation: slideUp 0.6s ease both; animation-delay: 0.51s; }

        .check-path {
            stroke-dasharray: 100;
            animation: checkDraw 0.5s ease forwards;
        }
        .check-1 { animation-delay: 0.55s; }
        .check-2 { animation-delay: 0.75s; }
        .check-3 { animation-delay: 0.95s; }

        .confetti {
            position: fixed;
            pointer-events: none;
            border-radius: 3px;
            animation: confettiFall linear forwards;
        }

        .shimmer-btn {
            background-size: 200% auto;
            background-image: linear-gradient(135deg, #0891b2, #4f46e5, #7c3aed, #0891b2);
            animation: shimmer 3s linear infinite;
        }
        .shimmer-btn:hover {
            background-image: linear-gradient(135deg, #0e7490, #4338ca, #6d28d9, #0e7490);
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-cyan-50/20 to-indigo-50/20 flex flex-col <?= $currentLang === 'mm' ? 'lang-mm' : '' ?>">

    <!-- Minimal branded header -->
    <header class="bg-white/90 backdrop-blur-sm border-b border-slate-100 px-6 py-3.5 flex items-center justify-between sticky top-0 z-20 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0">
                <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH" class="w-full h-full object-contain">
            </div>
            <div>
                <p class="text-sm font-bold text-slate-800"><?= e($LANG['student_portal'] ?? 'SFIS Student Portal') ?></p>
                <p class="text-[10px] text-slate-400 hidden sm:block"><?= e($LANG['system_name'] ?? 'Student Feedback Information System') ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-0.5 rounded-lg border border-cyan-100 bg-cyan-50 p-0.5 text-xs font-semibold">
                <a href="<?= e($completionLanguageUrl('en')) ?>" class="rounded-md px-3 py-1 <?= $currentLang === 'en' ? 'bg-white text-cyan-700 shadow' : 'text-cyan-400' ?>">ENG</a>
                <a href="<?= e($completionLanguageUrl('mm')) ?>" class="rounded-md px-3 py-1 <?= $currentLang === 'mm' ? 'bg-white text-cyan-700 shadow' : 'text-cyan-400' ?>">မြန်မာ</a>
            </div>
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-cyan-500 flex items-center justify-center text-xs font-bold text-white flex-shrink-0">
                <?= e($initials) ?>
            </div>
            <span class="text-sm font-medium text-slate-700 hidden sm:block"><?= e($user['name']) ?></span>
        </div>
    </header>

    <!-- Main content -->
    <main class="flex-1 flex items-center justify-center p-6">
        <div class="w-full max-w-lg">

            <!-- Trophy -->
            <div class="text-center mb-6 slide-up-1">
                <div class="trophy-float inline-block">
                    <div class="w-28 h-28 rounded-full bg-gradient-to-br from-amber-400 via-orange-400 to-rose-500 flex items-center justify-center shadow-2xl shadow-orange-300/50 mx-auto">
                        <span class="text-5xl leading-none">🏆</span>
                    </div>
                </div>
            </div>

            <!-- Main card -->
            <div class="bg-white rounded-3xl shadow-2xl shadow-slate-200/60 border border-slate-100 overflow-hidden slide-up-2">
                <!-- Rainbow gradient top bar -->
                <div class="h-2 bg-gradient-to-r from-cyan-500 via-violet-500 to-rose-500"></div>

                <div class="p-8 text-center">
                    <!-- Title -->
                    <h1 class="text-3xl font-extrabold text-slate-900 mb-2 slide-up-3">&#127881; <?= e($LANG['completion_congratulations'] ?? 'Congratulations!') ?></h1>
                    <p class="text-slate-500 text-sm mb-6 max-w-sm mx-auto leading-relaxed slide-up-3">
                        <?= e($LANG['completion_thank_participation'] ?? 'Thank you for participating in the Student Feedback Information System.') ?>
                    </p>

                    <!-- Intro statement -->
                    <div class="bg-gradient-to-r from-cyan-50 to-indigo-50 rounded-2xl px-5 py-3.5 mb-6 border border-indigo-100 slide-up-4">
                        <p class="text-sm font-semibold text-indigo-800">
                            <?= e($LANG['completion_all_required'] ?? 'You have successfully completed all required feedback forms:') ?>
                        </p>
                    </div>

                    <!-- Completed modules checklist -->
                    <div class="space-y-3 mb-8 slide-up-4">

                        <div class="flex items-center gap-3.5 bg-green-50 border border-green-100 rounded-2xl px-5 py-3.5 text-left">
                            <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 shadow-sm shadow-green-200">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                    <path class="check-path check-1" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-green-800"><?= e($LANG['academic'] ?? 'Teaching Quality') ?></p>
                                <p class="text-xs text-green-600"><?= e($LANG['all_forms_submitted'] ?? 'All forms submitted') ?></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3.5 bg-green-50 border border-green-100 rounded-2xl px-5 py-3.5 text-left">
                            <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 shadow-sm shadow-green-200">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                    <path class="check-path check-2" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-green-800"><?= e($LANG['student_affairs'] ?? 'Student Support Services') ?></p>
                                <p class="text-xs text-green-600"><?= e($LANG['form_submitted'] ?? 'Form submitted') ?></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3.5 bg-green-50 border border-green-100 rounded-2xl px-5 py-3.5 text-left">
                            <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 shadow-sm shadow-green-200">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                    <path class="check-path check-3" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-green-800"><?= e($LANG['administration'] ?? 'Learning Environment') ?></p>
                                <p class="text-xs text-green-600"><?= e($LANG['form_submitted'] ?? 'Form submitted') ?></p>
                            </div>
                        </div>

                    </div>

                    <!-- Appreciation quote -->
                    <div class="bg-slate-50 rounded-2xl p-4 mb-8 border border-slate-100 slide-up-5">
                        <p class="text-xs text-slate-500 leading-relaxed italic">
                            <?= e($LANG['completion_appreciation'] ?? 'Your valuable feedback will help improve teaching quality, student support services, and the learning environment. We sincerely appreciate your participation.') ?>
                        </p>
                    </div>

                    <!-- Return to Dashboard (the ONLY button in the entire flow) -->
                    <a href="<?= e(BASE_URL) ?>student/dashboard.php"
                       id="return-dashboard-btn"
                       class="shimmer-btn inline-flex items-center gap-2.5 px-8 py-3.5 text-white font-semibold rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200 text-sm slide-up-5">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <?= e($LANG['return_to_dashboard'] ?? 'Return to Dashboard') ?>
                    </a>

                </div>
            </div>

        </div>
    </main>

    <script>
        // Grand confetti burst
        (function () {
            var colors = ['#06b6d4', '#10b981', '#8b5cf6', '#f59e0b', '#ec4899', '#3b82f6', '#f97316', '#84cc16'];
            for (var i = 0; i < 70; i++) {
                var el = document.createElement('div');
                el.className = 'confetti';
                var size = 7 + Math.random() * 11;
                el.style.cssText = [
                    'left:' + (Math.random() * 100) + 'vw',
                    'top:-30px',
                    'width:' + size + 'px',
                    'height:' + size + 'px',
                    'background:' + colors[Math.floor(Math.random() * colors.length)],
                    'border-radius:' + (Math.random() > 0.4 ? '50%' : '2px'),
                    'animation-duration:' + (1.8 + Math.random() * 3.5) + 's',
                    'animation-delay:' + (Math.random() * 1.8) + 's'
                ].join(';');
                document.body.appendChild(el);
                setTimeout(function (e) { return function () { e.remove(); }; }(el), 7000);
            }
        }());
    </script>
</body>
</html>
