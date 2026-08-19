<?php
session_start();
require_once 'config/base_url.php';
require_once 'includes/functions.php';

// Already logged-in users go straight to their dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: ' . BASE_URL . 'admin/dashboard.php');
            exit;
        case 'teacher':
            header('Location: ' . BASE_URL . 'teacher/dashboard.php');
            exit;
        case 'student':
            header('Location: ' . BASE_URL . 'student/dashboard.php');
            exit;
    }
}

$currentLang = $_SESSION['lang'] ?? 'en';
?>
<!DOCTYPE html>
<html lang="<?= $currentLang === 'mm' ? 'my' : 'en' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH — Student Feedback Information System</title>
    <meta name="description"
        content="University of Computer Studies (Hinthada) — Student Feedback Information System. Enter as a Teacher or Student to get started.">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100vh;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
        }

        /* ─── Myanmar Language Mode ─────────────────────────── */
        body.lang-mm {
            font-family: 'Noto Sans Myanmar', 'Inter', system-ui, sans-serif;
        }

        body.lang-mm .hero-heading {
            line-height: 1.5;
            letter-spacing: 0.02em;
        }

        body.lang-mm .hero-desc {
            line-height: 1.8;
            word-spacing: 0.1em;
        }

        body.lang-mm .role-card h3 {
            line-height: 1.5;
        }

        body.lang-mm .stat-number {
            font-size: 1.75rem;
        }

        body.lang-mm .stat-label {
            font-size: 0.8125rem;
            line-height: 1.5;
        }

        /* ─── Animations ───────────────────────────────────── */
        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-12px);
            }
        }

        .animate-float {
            animation: float 4s ease-in-out infinite;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-up-1 {
            animation: fadeUp 0.7s ease-out 0.1s both;
        }

        .fade-up-2 {
            animation: fadeUp 0.7s ease-out 0.3s both;
        }

        .fade-up-3 {
            animation: fadeUp 0.7s ease-out 0.5s both;
        }

        .fade-up-4 {
            animation: fadeUp 0.7s ease-out 0.7s both;
        }

        @keyframes shimmer {
            0% {
                background-position: -200% center;
            }

            100% {
                background-position: 200% center;
            }
        }

        /*.shimmer-text {
            background: linear-gradient(90deg, rgba(125, 211, 252, 0.8) 0%, rgba(255, 255, 255, 1) 50%, rgba(125, 211, 252, 0.8) 100%);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: shimmer 4s linear infinite;
        }*/

        .shimmer-text {
            display: inline-block;

            background: linear-gradient(90deg,
                    #7dd3fc,
                    #ffffff,
                    #7dd3fc);

            background-size: 200% auto;

            -webkit-background-clip: text;
            background-clip: text;

            -webkit-text-fill-color: transparent;

            font-family: "Noto Sans Myanmar", sans-serif;
            font-weight: 700;

            line-height: 1.6;

            animation: shimmer 4s linear infinite;
        }

        /* ─── Typewriter ───────────────────────────────────── */
        @keyframes tw-blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0;
            }
        }

        .tw-text {
            font-family: 'Courier New', 'Noto Sans Myanmar', monospace;
            letter-spacing: 0.06em;
            min-height: 1.4em;
            line-height: 1.4;
            vertical-align: middle;
            display: inline-block;
        }

        .tw-cursor {
            display: inline-block;
            width: 3px;
            height: 1.1em;
            background: rgba(255, 255, 255, 0.85);
            margin-left: 2px;
            vertical-align: middle;
            animation: tw-blink 0.7s step-end infinite;
        }

        /* ─── Role Card ───────────────────────────────────── */
        .role-card {
            width: 100%;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .role-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%);
            border-radius: inherit;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .role-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 25px 60px rgba(15, 47, 143, 0.25);
            border-color: rgba(255, 255, 255, 0.35);
        }

        .role-card:hover::before {
            opacity: 1;
        }

        .role-icon {
            width: 72px;
            height: 72px;
            border-radius: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            transition: transform 0.35s ease;
        }

        .role-card:hover .role-icon {
            transform: scale(1.12) rotate(-3deg);
        }
    </style>
</head>

<body class="text-slate-800 antialiased min-h-screen flex flex-col <?= $currentLang === 'mm' ? 'lang-mm' : '' ?>">

    <!-- ─── Navigation ──────────────────────────────────────── -->
    <nav class="sticky top-0 z-50 backdrop-blur-md border-b border-blue-100/50 shadow-lg shadow-blue-500/5 transition-all duration-300"
        style="background: linear-gradient(135deg, rgba(219,234,254,0.92) 0%, rgba(191,219,254,0.88) 40%, rgba(224,242,254,0.90) 100%);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 lg:h-20">
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                        <img src="<?= e(BASE_URL . 'assets/uploads/profiles/image.png') ?>" alt="ucsh_logo"
                            class="object-contain rounded-lg">
                    </div>
                    <div>
                        <h1 class="text-lg font-bold tracking-tight text-blue-900 leading-none">UCSH</h1>
                        <p class="text-xs text-blue-500/80 leading-tight hidden sm:block font-medium">
                            <?= $LANG['university_name_title'] ?? 'University of Computer Studies (Hinthada)' ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-5">
                    <!-- <span class="text-xs text-blue-700/70 hidden md:block font-semibold capitalize tracking-wide">
                        <?= $LANG['welcome_portal'] ?? 'Welcome' ?>
                    </span> -->

                    <a href="<?= e(BASE_URL . 'index.php') ?>"
                        class="inline-flex items-center gap-1.5 text-blue-600/80 hover:text-blue-700 font-semibold text-sm transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        <?= $LANG['home'] ?? 'Home' ?>
                    </a>

                    <div
                        class="flex items-center gap-0.5 bg-white/70 backdrop-blur-sm rounded-xl p-0.5 text-xs font-semibold border border-blue-200/60 shadow-sm">
                        <a href="<?= e(BASE_URL . 'index.php?lang=en') ?>" id="lang-btn-en"
                            class="px-3 py-1.5 rounded-lg transition-all duration-200 <?= $currentLang === 'en' ? 'bg-blue-500 shadow-md shadow-blue-500/25 text-white font-bold' : 'text-blue-400 hover:text-blue-600 hover:bg-blue-50/50' ?>">
                            ENG
                        </a>
                        <a href="<?= e(BASE_URL . 'index.php?lang=mm') ?>" id="lang-btn-mm"
                            class="px-3 py-1.5 rounded-lg transition-all duration-200 <?= $currentLang === 'mm' ? 'bg-blue-500 shadow-md shadow-blue-500/25 text-white font-bold' : 'text-blue-400 hover:text-blue-600 hover:bg-blue-50/50' ?>">
                            မြန်မာ
                        </a>
                    </div>

                    <!-- <a href="<?= e(BASE_URL . 'index.php') ?>"
                        class="inline-flex items-center gap-1.5 text-blue-600/80 hover:text-blue-700 font-semibold text-sm transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Home
                    </a> -->
                </div>
            </div>
        </div>
    </nav>

    <!-- ─── Hero / Welcome Section ─────────────────────────────── -->
    <main class="relative flex-1 flex flex-col items-center justify-center overflow-hidden">

        <!-- Background (blurred hero image) -->
        <div class="absolute inset-0 -z-30 overflow-hidden">
            <img src="<?= e(BASE_URL . 'assets/uploads/ucsh_logo.jpg') ?>"
                class="w-full h-full object-cover scale-110 blur-xs brightness-75" alt="ucsh">
        </div>

        <!-- Modern Gradient Overlay -->
        <div
            class="absolute inset-0 -z-20 bg-gradient-to-br from-blue-950/50 via-blue-900/40 to-slate-900/50 backdrop-blur-xs">
        </div>

        <!-- Decorative Blurs -->
        <div class="absolute top-20 left-10 w-72 h-72 bg-blue-300/20 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute bottom-10 right-10 w-96 h-96 bg-cyan-300/20 rounded-full blur-3xl"></div>
        <div
            class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-400/10 rounded-full blur-3xl">
        </div>

        <div class="relative max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24 w-full">

            <!-- University Branding -->
            <div class="text-center mb-14 lg:mb-20">
                <!-- Logo with subtle ring -->
                <div class="relative w-28 h-28 mx-auto mb-4 fade-up-1">
                    <!-- <div class="absolute inset-0 rounded-2xl bg-white/10 backdrop-blur-sm border border-white/20 rotate-3"></div> -->
                    <div class="relative w-full h-full flex items-center justify-center overflow-hidden">
                        <img src="<?= e(BASE_URL . 'assets/uploads/profiles/image.png') ?>" alt="UCSH Logo"
                            class="w-20 h-20 object-contain">
                    </div>
                </div>

                <!-- Typewriter University Name -->
                <p class="text-base sm:text-lg font-semibold text-white/90 tracking-wide mb-4 fade-up-1">
                    <span id="tw-welcome" class="tw-text"></span>
                </p>

                <!-- Main Heading -->
                <h1
                    class="hero-heading text-3xl sm:text-4xl lg:text-5xl xl:text-6xl text-sky-300 font-bold tracking-tight leading-[1.25] fade-up-2">
                    <?= $LANG['welcome_to'] ?? 'Welcome to Student Feedback Information System' ?>
                </h1>
                <!-- <h1 class="hero-heading text-3xl sm:text-4xl lg:text-5xl xl:text-6xl font-extrabold tracking-tight leading-[1.25] fade-up-2 bg-gradient-to-r from-cyan-300 via-sky-400 to-indigo-400 bg-clip-text text-transparent drop-shadow-sm">
    <?= $LANG['welcome_to'] ?? 'Welcome to Student Feedback Information System' ?>
</h1> -->



                <!-- Gradient accent line -->
                <!-- <div class="flex justify-center mt-6 fade-up-2">
                    <div class="h-1 w-20 rounded-full bg-gradient-to-r from-cyan-400 via-blue-400 to-indigo-400 opacity-70"></div>
                </div> -->

                <!-- Description -->
                <p
                    class="hero-desc text-base sm:text-lg text-blue-50/70 font-light max-w-xl mx-auto leading-relaxed mt-6 fade-up-3">
                    <?= $LANG['welcome_desc'] ?? 'Select your role below to access the feedback portal.' ?>
                </p>
            </div>

            <!-- Role Selection Cards -->
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8 w-full max-w-7xl mx-auto fade-up-4">

                <!-- Enter as Teacher -->
                <a href="<?= e(BASE_URL . 'portal.php?role=teacher') ?>" id="enter-teacher-btn"
                    class="role-card group cursor-pointer block no-underline">
                    <div
                        class="role-icon bg-gradient-to-br from-emerald-400/30 to-teal-500/30 border border-emerald-300/30">
                        <svg class="w-8 h-8 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-2">
                        <?= $LANG['enter_as_teacher'] ?? 'Enter as Teacher' ?>
                    </h3>
                    <p class="text-sm text-blue-100/60 mb-5">
                        <?= $LANG['teacher_card_desc'] ?? 'Review reports and feedback results' ?>
                    </p>
                    <span
                        class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-all duration-200 group-hover:bg-white/25 border border-white/20">
                        <?= $LANG['enter_as_teacher'] ?? 'Enter as Teacher' ?>
                        <svg class="w-4 h-4 transition-transform duration-200 group-hover:translate-x-1" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </span>
                </a>

                <!-- Enter as Student -->
                <a href="<?= e(BASE_URL . 'portal.php?role=student') ?>" id="enter-student-btn"
                    class="role-card group cursor-pointer block no-underline">
                    <div
                        class="role-icon bg-gradient-to-br from-blue-400/30 to-indigo-500/30 border border-blue-300/30">
                        <svg class="w-8 h-8 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-2">
                        <?= $LANG['enter_as_student'] ?? 'Enter as Student' ?>
                    </h3>
                    <p class="text-sm text-blue-100/60 mb-5">
                        <?= $LANG['student_card_desc'] ?? 'Submit feedback for your assigned semester' ?>
                    </p>
                    <span
                        class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-all duration-200 group-hover:bg-white/25 border border-white/20">
                        <?= $LANG['enter_as_student'] ?? 'Enter as Student' ?>
                        <svg class="w-4 h-4 transition-transform duration-200 group-hover:translate-x-1" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </span>
                </a>

                <!-- Enter as Admin -->
                <a href="<?= e(BASE_URL . 'admin/index.php') ?>" id="enter-admin-btn"
                    class="role-card group cursor-pointer block no-underline">
                    <div
                        class="role-icon bg-gradient-to-br from-violet-400/30 to-purple-500/30 border border-violet-300/30">
                        <svg class="w-8 h-8 text-violet-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M9 12.75 11.25 15 15 9.75m6-3.75c0 7.142-3.75 12-9 13.5C6.75 18 3 13.142 3 6c3.75 0 6-1.5 9-3 3 1.5 5.25 3 9 3Z" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-2">
                        <?= $LANG['enter_as_admin'] ?? 'Enter as Admin' ?>
                    </h3>
                    <p class="text-sm text-blue-100/60 mb-5">
                        <?= $LANG['admin_card_desc'] ?? 'Manage users, feedback, and system settings' ?>
                    </p>
                    <span
                        class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-all duration-200 group-hover:bg-white/25 border border-white/20">
                        <?= $LANG['enter_as_admin'] ?? 'Enter as Admin' ?>
                        <svg class="w-4 h-4 transition-transform duration-200 group-hover:translate-x-1" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </span>
                </a>

            </div>
        </div>
    </main>

    <!-- ─── Typewriter Script ──────────────────────────────── -->
    <script>
        (function () {
            const enText = 'UNIVERSITY OF COMPUTER STUDIES (HINTHADA)';
            const mmText = 'ကွန်ပျူတာတက္ကသိုလ် (ဟင်္သာတ)';
            const lang = '<?= $currentLang ?>';
            const texts = lang === 'mm' ? [mmText, enText] : [enText, mmText];

            const el = document.getElementById('tw-welcome');
            if (!el) return;

            let textIdx = 0, charIdx = 0, isDeleting = false;
            const cursor = document.createElement('span');
            cursor.className = 'tw-cursor';
            el.after(cursor);

            function tick() {
                const current = texts[textIdx];
                if (!isDeleting) {
                    el.textContent = current.substring(0, ++charIdx);
                    if (charIdx === current.length) {
                        setTimeout(() => { isDeleting = true; tick(); }, 2200);
                        return;
                    }
                } else {
                    el.textContent = current.substring(0, --charIdx);
                    if (charIdx === 0) {
                        isDeleting = false;
                        textIdx = (textIdx + 1) % texts.length;
                        setTimeout(tick, 400);
                        return;
                    }
                }
                setTimeout(tick, isDeleting ? 150 : 200);
            }

            tick();
        })();
    </script>
</body>

</html>