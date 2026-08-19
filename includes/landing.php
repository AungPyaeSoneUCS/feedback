<?php
//session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($loginType)) {
    $loginType = 'student';
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === $loginType) {
        header("Location: dashboard.php");
        exit;
    }
    require_once __DIR__ . '/auth.php';
    redirectToDashboard();
}

require_once __DIR__ . '/functions.php';

$badgeText = $LANG['badge_text'] ?? "Your Opinion, Our Improvement";
$buttonText = "Get Started";
$showBadge = true;

$heroDescriptions = [
    'teacher' => $LANG['hero_teacher_desc'] ?? 'Teachers can review reports and feedback results.',
    'student' => $LANG['hero_student_desc'] ?? 'Students can submit feedback only for the semester assigned by the admin.',
];
$heroDescription = $heroDescriptions[$loginType] ?? '';

if ($loginType === 'student') {
    $buttonText = $LANG['login_student_myanmar'] ?? "ကျောင်းသားအကောင့်ဖြင့်လော့ဂ်အင်ဝင်ရန်";
} elseif ($loginType === 'teacher') {
    $badgeText = "";
    $showBadge = false;
    $buttonText = $LANG['login_teacher_myanmar'] ?? "ဆရာ/ဆရာမ အကောင့်ဖြင့် လော့ဂ်အင်ဝင်ရန်";
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/functions.php';

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT id, name, email, password, role, profile_image FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['role'] !== $loginType) {
                $error = $LANG['error_not_authorized'] ?? 'You are not authorized to log in from this page.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['profile_image'] = $user['profile_image'] ?? null;
                header("Location: dashboard.php");
                exit;
            }
        } else {
            $error = $LANG['error_invalid_credentials'] ?? 'Invalid email or password. Please try again.';
        }
    } else {
        $error = $LANG['error_fill_fields'] ?? 'Please fill in all fields.';
    }
}

$showLoginOnLoad = !empty($error) || !empty($_GET['show_login']);

// ─── Statistics ────────────────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
$stats = [
    'teachers' => 0,
    'students' => 0,
    'courses' => 0,
    'departments' => 0,
];
$r = $conn->query("SELECT COUNT(*) AS c FROM teachers");
if ($r)
    $stats['teachers'] = (int) $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) AS c FROM students");
if ($r)
    $stats['students'] = (int) $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) AS c FROM courses");
if ($r)
    $stats['courses'] = (int) $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) AS c FROM departments");
if ($r)
    $stats['departments'] = (int) $r->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Student Feedback Information System</title>
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

        body.lang-mm .hero-sub {
            line-height: 1.5;
            letter-spacing: 0.02em;
        }

        body.lang-mm .hero-desc {
            line-height: 1.8;
            word-spacing: 0.1em;
        }

        body.lang-mm .stat-number {
            font-size: 1.75rem;
        }

        body.lang-mm .stat-label {
            font-size: 0.8125rem;
            line-height: 1.5;
        }

        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }

        .fade-up {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.7s ease-out, transform 0.7s ease-out;
        }

        .fade-up.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 1.25rem;
            padding: 1.75rem 1.5rem;
            text-align: center;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(15, 47, 143, 0.12);
            border-color: rgba(15, 47, 143, 0.15);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 1rem;
            transition: transform 0.3s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1);
        }

        .stat-number {
            font-size: 2.25rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.02em;
        }

        .stat-label {
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }

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

        .animate-float-delayed {
            animation: float 5s ease-in-out 1s infinite;
        }

        /* ─── Typewriter ───────────────────────────────────── */
        @keyframes tw-blink {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0
            }
        }

        .tw-text {
            font-family: 'Courier New', 'Noto Sans Myanmar', monospace;
            letter-spacing: 0.06em;
            min-height: 1.4em;
        }

        .tw-cursor {
            display: inline-block;
            width: 3px;
            height: 1em;
            background: rgba(255, 255, 255, 0.85);
            margin-left: 2px;
            vertical-align: text-bottom;
            animation: tw-blink 0.7s step-end infinite;
        }
    </style>
</head>

<body
    class="text-slate-800 antialiased min-h-screen flex flex-col <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">

    <!-- ─── Navigation ──────────────────────────────────────── -->
    <nav class="sticky top-0 z-50 backdrop-blur-md border-b border-blue-100/50 shadow-lg shadow-blue-500/5 transition-all duration-300"
        style="background: linear-gradient(135deg, rgba(219,234,254,0.92) 0%, rgba(191,219,254,0.88) 40%, rgba(224,242,254,0.90) 100%);">
        <div class="max-w-7xl mx-auto px-2.5 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 lg:h-20">
                <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                    <div
                        class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0 ">
                        <img src="../assets/uploads/profiles/image.png" alt="uscsh_logo"
                            class="object-contain rounded-lg">
                    </div>
                    <div>
                        <h1 class="text-lg font-bold tracking-tight text-blue-900 leading-none">UCSH</h1>
                        <p class="text-xs text-blue-500/80 leading-tight hidden sm:block font-medium">
                            <?= $LANG['university_name_title'] ?? 'University of Computer Studies (Hinthada)' ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 sm:gap-3 lg:gap-5 flex-shrink-0">
                        <a href="<?= e(BASE_URL) ?>index.php"
                            class="inline-flex items-center gap-1 sm:gap-1.5 text-blue-600/80 hover:text-blue-700 font-semibold text-[10px] sm:text-sm whitespace-nowrap transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                            </svg>
                            <span class="hidden sm:inline"><?= $LANG['home'] ?? 'Home' ?></span>
                        </a>
                    <!-- <span
                        class="text-xs text-blue-700/70 hidden md:block font-semibold capitalize tracking-wide"><?= e($loginType) ?>
                        <?= $LANG['portal'] ?? 'Portal' ?></span> -->

                    <?php $currentLang = $_SESSION['lang'] ?? 'en'; ?>
                    <div
                        class="flex items-center gap-0.5 bg-white/70 backdrop-blur-sm rounded-xl p-0.5 text-xs font-semibold border border-blue-200/60 shadow-sm">
                        <a href="?lang=en" id="lang-btn-en"
                            class="px-2 sm:px-3 py-1.5 rounded-lg transition-all duration-200 <?= $currentLang === 'en' ? 'bg-blue-500 shadow-md shadow-blue-500/25 text-white font-bold' : 'text-blue-400 hover:text-blue-600 hover:bg-blue-50/50' ?>">
                            <span class="sm:hidden">EN</span><span class="hidden sm:inline">ENG</span>
                        </a>
                        <a href="?lang=mm" id="lang-btn-mm"
                            class="px-2 sm:px-3 py-1.5 rounded-lg transition-all duration-200 <?= $currentLang === 'mm' ? 'bg-blue-500 shadow-md shadow-blue-500/25 text-white font-bold' : 'text-blue-400 hover:text-blue-600 hover:bg-blue-50/50' ?>">
                            <span class="sm:hidden">MM</span><span class="hidden sm:inline">မြန်မာ</span>
                        </a>
                    </div>



                    <button type="button" id="navLoginBtn"
                        class="inline-flex items-center justify-center gap-1 sm:gap-2 bg-white/80 text-blue-600 px-2 py-2 sm:px-5 sm:py-2 rounded-xl font-semibold text-[10px] sm:text-sm whitespace-nowrap shadow-lg shadow-blue-500/30 transition-all duration-200 hover:shadow-xl hover:shadow-blue-500/40 hover:-translate-y-0.5 hover:from-blue-600 hover:to-blue-700 cursor-pointer ring-1 ring-blue-400/30">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span class="hidden sm:inline"><?= $LANG['login'] ?? 'Login' ?></span>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- ─── Hero Section ────────────────────────────────────────── -->
    <header class="relative min-h-screen flex flex-col overflow-hidden">

        <!-- Background (blurred hero image) -->
        <div class="absolute inset-0 -z-30 overflow-hidden">
            <img src="../assets/uploads/ucsh_logo.jpg"
                class="w-full h-full object-cover scale-110 blur-xs brightness-75" alt="ucsh">
        </div>

        <!-- Modern Gradient Overlay -->
        <div
            class="absolute inset-0 -z-20 bg-gradient-to-br from-blue-950/50 via-blue-900/40 to-slate-900/50 backdrop-blur-xs">
        </div>

        <!-- Decorative Blur -->
        <div class="absolute top-20 left-10 w-72 h-72 bg-blue-300/20 rounded-full blur-3xl animate-pulse"></div>

        <div class="absolute bottom-10 right-10 w-96 h-96 bg-cyan-300/20 rounded-full blur-3xl"></div>



        <!-- <div
            class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiNmZmYiIGZpbGwtb3BhY2l0eT0iMC4wNSI+PHBhdGggZD0iTTM2IDM0djItSDI0di0yaDEyek0zNiAyNHYySDI0di0yaDEyeiIvPjwvZz48L2c+PC9zdmc+')] opacity-40">
        </div> -->
        <!-- <div
            class="w-4 h-4 rounded-2xl bg-gradient-to-br from-white/20 to-white/5 flex items-center justify-center mb-5 shadow-lg animate-float">

            <img src="../assets/uploads/profiles/ucshlogo.jpg" alt="uscsh_logo" class="object-contain">
        </div> -->

        <!-- <div class="absolute top-20 left-10 w-72 h-72 bg-white/5 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute bottom-10 right-10 w-96 h-96 bg-blue-400/10 rounded-full blur-3xl"></div> -->

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 lg:py-20 flex items-center w-full">
            <div class="grid lg:grid-cols-12 gap-8 sm:gap-10 lg:gap-20 items-center w-full">

                <!-- Left: Hero Text -->
                <div class="lg:col-span-7 space-y-5 sm:space-y-6 lg:space-y-8 text-center lg:text-left">
                    <?php if ($showBadge && !empty($badgeText)): ?>
                        <div
                            class="inline-flex items-center gap-2 bg-white/15 backdrop-blur-sm px-4 py-2 rounded-full border border-white/15 text-sm font-medium">
                            <span class="text-sky-300">🎓</span>
                            <span class="text-white/80"><?= htmlspecialchars($badgeText) ?></span>
                        </div>
                    <?php endif; ?>

                    <h2 class="hero-heading text-3xl sm:text-4xl lg:text-5xl xl:text-6xl text-sky-300 font-bold tracking-tight leading-[1.25]">
                        <?= $LANG['welcome_to'] ?? 'Welcome to' ?>
                    </h2>

                    <!-- <p class="hero-sub text-xl sm:text-2xl lg:text-3xl font-semibold text-sky-300 tracking-tight leading-snug bg-gradient-to-r from-cyan-400 via-blue-400 to-indigo-400">
                        <?= $LANG['student_feedback_system'] ?? 'Student Feedback Information System' ?>
                    </p> -->

                    <!-- Gradient accent line -->
                    <!-- <div class="flex">
                        <div class="h-1 w-16 rounded-full bg-gradient-to-r from-cyan-400 via-blue-400 to-indigo-400 opacity-70"></div>
                    </div> -->

                    <p class="hero-desc text-sm sm:text-lg text-blue-50/70 font-light max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        <?= htmlspecialchars($heroDescription) ?>
                    </p>

                    <div class="flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:gap-4 pt-2">
                        <button type="button" id="loginTriggerBtn"
                            class="inline-flex items-center justify-center gap-2 w-full sm:w-auto bg-white/90 text-blue-800 font-semibold px-6 py-3 rounded-xl shadow-xl shadow-black/10 hover:shadow-2xl hover:-translate-y-0.5 transition-all cursor-pointer">
                            <span><?= htmlspecialchars($buttonText) ?></span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </button>
                        <!-- <a href="#stats"
                            class="inline-flex items-center gap-2 border border-white/30 text-white/90 hover:bg-white/10 px-6 py-3 rounded-xl font-medium transition-all">
                            
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                            </svg>
                        </a> -->

                        <a href="#stats" onclick="smoothScrollToStats(); return false;"
                            class="inline-flex items-center justify-center gap-2 w-full sm:w-auto border-2 border-white/70 text-white/90 bg-white/10 hover:bg-white/30 px-6 py-3 rounded-xl font-medium transition-all">
                            <?= $LANG['view_statistics'] ?? 'View Statistics' ?>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- Right: University Box / Login Form -->
                <div class="lg:col-span-5 relative w-full min-h-[430px] sm:min-h-[420px] flex items-center justify-center">

                    <div id="universityBox"
                        class="absolute inset-0 bg-white/8 backdrop-blur-sm rounded-2xl border border-white/15 flex flex-col items-center justify-center p-5 sm:p-8 text-center transition-all duration-500 <?= $showLoginOnLoad ? 'opacity-0 scale-95 pointer-events-none' : 'opacity-100 scale-100' ?> z-10">
                        <!-- Logo with ring -->
                        <div class="relative w-24 h-24 mb-6 animate-float">
                            <!-- <div class="absolute inset-0 rounded-2xl bg-white/10 border border-white/20 rotate-3"></div> -->
                            <div class="relative w-full h-full flex items-center justify-center overflow-hidden">
                                <img src="../assets/uploads/profiles/image.png" alt="UCSH Logo" class="w-16 h-16 object-contain drop-shadow-lg">
                            </div>
                        </div>
                        <?php
                        $currentLang = $_SESSION['lang'] ?? 'en';
                        $twEn = 'UNIVERSITY OF COMPUTER STUDIES (HINTHADA)';
                        $twMm = 'ကွန်ပျူတာတက္ကသိုလ် (ဟင်္သာတ)';
                        ?>
                        <p class="text-lg sm:text-xl font-bold text-white mt-1">
                            <span id="tw-landing" class="tw-text"></span>
                        </p>
                        <div class="mt-5 flex gap-2">
                            <div class="w-1.5 h-1.5 rounded-full bg-white/30 animate-bounce" style="animation-delay:0s"></div>
                            <div class="w-1.5 h-1.5 rounded-full bg-white/30 animate-bounce" style="animation-delay:0.15s"></div>
                            <div class="w-1.5 h-1.5 rounded-full bg-white/30 animate-bounce" style="animation-delay:0.3s"></div>
                        </div>
                    </div>

                    <div id="loginBox"
                        class="absolute w-full max-w-sm px-1 sm:px-0 mx-auto transition-all duration-500 <?= $showLoginOnLoad ? 'opacity-100 scale-100 z-10' : 'opacity-0 scale-95 pointer-events-none z-0' ?>">
                        <div
                            class="bg-white rounded-2xl shadow-2xl border border-slate-200/80 overflow-hidden text-slate-800">

                            <div
                                class="bg-gradient-to-r from-blue-600/70 to-violet-700/50 px-4 sm:px-6 py-4 sm:py-5 text-center relative">
                                <button type="button" id="closeLoginBtn"
                                    class="absolute top-3 right-3 text-white/70 hover:text-white text-xs bg-black/10 hover:bg-black/20 px-2 py-1 rounded-md transition-colors cursor-pointer">✕</button>
                                <!-- <div
                                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white/20 backdrop-blur mb-3">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />
                                    </svg>
                                </div> -->
                                <div
                                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl  mb-3">
                                    <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH Logo"
                                        class="w-full h-full object-contain rounded-xl">
                                </div>
                                <h1 class="text-xl font-bold text-white tracking-wide"><?= $LANG['sfms'] ?? 'SFIS' ?>
                                </h1>
                                <p class="text-blue-100 text-xs mt-0.5">
                                    <?= $LANG['sfms_full'] ?? 'Student Feedback Information System' ?>
                                </p>
                            </div>

                            <div class="px-4 sm:px-6 py-4 sm:py-5">
                                <h2 class="text-lg font-bold text-slate-900 mb-0.5">
                                    <?= $LANG['welcome_back'] ?? 'Welcome back' ?>
                                </h2>
                                <p class="text-xs text-slate-500 mb-4">
                                    <?= $LANG['sign_in_to_continue'] ?? 'Sign in to your account to continue' ?>
                                </p>

                                <?php if ($error): ?>
                                    <div
                                        class="flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 rounded-lg px-3 py-2 mb-3 text-xs">
                                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                        </svg>
                                        <?= htmlspecialchars($error) ?>
                                    </div>
                                <?php endif ?>

                                <form method="POST" novalidate>
                                    <div class="space-y-4">
                                        <div>
                                            <label for="email" class="block text-xs font-medium text-slate-700 mb-1"><?= $LANG['email_address'] ?? 'Email
                                                Address' ?></label>
                                            <div class="relative">
                                                <span
                                                    class="absolute inset-y-0 left-0 flex items-center pl-2.5 text-slate-400">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="1.5"
                                                            d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                                    </svg>
                                                </span>
                                                <input id="email" name="email" type="email" required
                                                    autocomplete="email"
                                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                                    placeholder="user@ucsh.edu.mm"
                                                    class="w-full pl-9 pr-3 py-2 border border-slate-200 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none transition-all">
                                            </div>
                                        </div>

                                        <div>
                                            <label for="password"
                                                class="block text-xs font-medium text-slate-700 mb-1"><?= $LANG['password'] ?? 'Password' ?></label>
                                            <div class="relative">
                                                <span
                                                    class="absolute inset-y-0 left-0 flex items-center pl-2.5 text-slate-400">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="1.5"
                                                            d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                    </svg>
                                                </span>
                                                <input id="password" name="password" type="password" required
                                                    autocomplete="current-password" placeholder="••••••••"
                                                    class="w-full pl-9 pr-8 py-2 border border-slate-200 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none transition-all">
                                                <button type="button" onclick="togglePwd()"
                                                    class="absolute inset-y-0 right-0 flex items-center pr-2 text-slate-400 hover:text-slate-600">
                                                    <svg id="eye-open" class="w-4 h-4" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="1.5"
                                                            d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                    <svg id="eye-closed" class="w-4 h-4 hidden" fill="none"
                                                        stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="1.5"
                                                            d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- <button type="submit"
                                            class="w-full bg-gradient-to-r from-blue-600/50 to-blue-700/50 hover:from-blue-700 hover:to-blue-800 text-white font-semibold py-2.5 px-4 rounded-lg shadow-md transition-all hover:shadow-lg hover:-translate-y-0.5 text-sm cursor-pointer">
                                            <?= $LANG['sign_in'] ?? 'Sign In' ?>
                                        </button> -->
                                        <button type="submit"
                                            class="w-full bg-blue-600/90  hover:bg-blue-800 text-white font-semibold py-2.5 px-4 rounded-lg shadow-md transition-all hover:shadow-lg hover:-translate-y-0.5 text-sm cursor-pointer">
                                            <?= $LANG['sign_in'] ?? 'Sign In' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </div>


    </header>

    <!-- ─── Statistics Section ──────────────────────────────────── -->
    <section id="stats" class="relative py-12 sm:py-16 lg:py-20 bg-slate-50">
        <div class="absolute inset-0 bg-gradient-to-b from-white via-transparent to-white pointer-events-none">
        </div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-14 lg:mb-18 fade-up">
                <span
                    class="inline-flex items-center gap-2 bg-blue-50 text-blue-700 text-xs font-semibold px-4 py-1.5 rounded-full border border-blue-200/60 mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                    <?= $LANG['live_statistics'] ?? 'Live Statistics' ?>
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-slate-900 tracking-tight">
                    <?= $LANG['system_overview'] ?? 'System Overview' ?>
                </h2>
                <p class="mt-3 text-slate-500 max-w-2xl mx-auto text-sm sm:text-base">
                    <?= $LANG['system_overview_desc'] ?? 'Real-time overview of the Student Feedback Information System at UCSH (Hinthada)' ?>
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 lg:gap-6">

                <!-- Teachers -->
                <div class="stat-card fade-up">
                    <div class="stat-icon bg-gradient-to-br from-sky-50 to-sky-100 text-sky-600 shadow-sm">
                        👨‍🏫
                    </div>
                    <div class="stat-number text-sky-700" data-target="<?= $stats['teachers'] ?>">0</div>
                    <div class="stat-label text-slate-500"><?= $LANG['total_teachers'] ?? 'Total Active Teachers' ?>
                    </div>
                </div>

                <!-- Students -->
                <div class="stat-card fade-up" style="transition-delay:0.1s">
                    <div class="stat-icon bg-gradient-to-br from-blue-50 to-blue-100 text-blue-600 shadow-sm">
                        🎓
                    </div>
                    <div class="stat-number text-blue-700" data-target="<?= $stats['students'] ?>">0</div>
                    <div class="stat-label text-slate-500"><?= $LANG['total_students'] ?? 'Total Active Students' ?>
                    </div>
                </div>

                <!-- Courses -->
                <div class="stat-card fade-up" style="transition-delay:0.2s">
                    <div class="stat-icon bg-gradient-to-br from-violet-50 to-violet-100 text-violet-600 shadow-sm">
                        📚
                    </div>
                    <div class="stat-number text-violet-700" data-target="<?= $stats['courses'] ?>">0</div>
                    <div class="stat-label text-slate-500"><?= $LANG['total_courses'] ?? 'Total Active Courses' ?></div>
                </div>

                <!-- Departments -->
                <div class="stat-card fade-up" style="transition-delay:0.3s">
                    <div class="stat-icon bg-gradient-to-br from-amber-50 to-amber-100 text-amber-600 shadow-sm">
                        🏢
                    </div>
                    <div class="stat-number text-amber-700" data-target="<?= $stats['departments'] ?>">0</div>
                    <div class="stat-label text-slate-500">
                        <?= $LANG['total_departments'] ?? 'Total Active Departments' ?>
                    </div>
                </div>

            </div>
        </div>
    </section>



    <!-- ─── Footer ──────────────────────────────────────────────── -->
    <footer class="backdrop-blur-md border-t border-blue-100/50 mt-auto relative overflow-hidden"
        style="background: linear-gradient(135deg, rgba(219,234,254,0.95) 0%, rgba(191,219,254,0.90) 40%, rgba(224,242,254,0.93) 100%);">
        <!-- Decorative top gradient line -->
        <div
            class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-blue-400/40 to-transparent">
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                        <img src="../assets/uploads/profiles/image.png" alt="uscsh_logo"
                            class="object-contain rounded-lg">
                    </div>
                    <div>
                        <p class="text-sm font-bold text-blue-900">
                            <?= $LANG['footer_system'] ?? 'Student Feedback Information System' ?>
                        </p>
                        <p class="text-xs text-blue-500/70 font-medium">
                            <?= $LANG['footer_university'] ?? 'University of Computer Studies (Hinthada)' ?>
                        </p>
                    </div>
                </div>
                <p class="text-xs text-blue-600/60 font-medium">
                    &copy; <?= date("Y") ?> UCSH. <?= $LANG['footer_rights'] ?? 'All Rights Reserved' ?>.
                </p>
            </div>
        </div>
    </footer>

    <script>
        function togglePwd() {
            const inp = document.getElementById('password');
            const open = document.getElementById('eye-open');
            const cls = document.getElementById('eye-closed');
            if (inp.type === 'password') {
                inp.type = 'text';
                open.classList.add('hidden');
                cls.classList.remove('hidden');
            } else {
                inp.type = 'password';
                open.classList.remove('hidden');
                cls.classList.add('hidden');
            }
        }

        // ─── Login Toggle ──────────────────────────────────────────────
        const navLoginBtn = document.getElementById('navLoginBtn');
        const triggerBtn = document.getElementById('loginTriggerBtn');
        const uniBox = document.getElementById('universityBox');
        const loginBox = document.getElementById('loginBox');
        const closeBtn = document.getElementById('closeLoginBtn');

        function openLogin() {
            uniBox.classList.remove('opacity-100', 'scale-100');
            uniBox.classList.add('opacity-0', 'scale-95', 'pointer-events-none');
            loginBox.classList.remove('opacity-0', 'scale-95', 'pointer-events-none');
            loginBox.classList.add('opacity-100', 'scale-100');
            setTimeout(() => document.getElementById('email')?.focus(), 500);
        }

        function closeLogin() {
            loginBox.classList.remove('opacity-100', 'scale-100');
            loginBox.classList.add('opacity-0', 'scale-95', 'pointer-events-none');
            uniBox.classList.remove('opacity-0', 'scale-95', 'pointer-events-none');
            uniBox.classList.add('opacity-100', 'scale-100');
        }

        navLoginBtn.addEventListener('click', openLogin);
        triggerBtn.addEventListener('click', openLogin);
        closeBtn.addEventListener('click', closeLogin);

        // ─── Scroll Reveal (Fade-Up) ──────────────────────────────────
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, { threshold: 0.2, rootMargin: '0px 0px -40px 0px' });

        document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));

        // ─── Counter Animation ─────────────────────────────────────────
        function animateCounter(el) {
            const target = parseInt(el.getAttribute('data-target'));
            if (isNaN(target) || target === 0) { el.textContent = '0'; return; }
            const duration = 2000;
            const start = performance.now();

            function update(now) {
                const elapsed = now - start;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const current = Math.floor(eased * target);
                el.textContent = current;
                if (progress < 1) requestAnimationFrame(update);
                else el.textContent = target;
            }
            requestAnimationFrame(update);
        }

        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    counterObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        document.querySelectorAll('.stat-number[data-target]').forEach(el => counterObserver.observe(el));

        // ─── Auto-open login on error ─────────────────────────────────
        <?php if ($showLoginOnLoad): ?>
            document.addEventListener('DOMContentLoaded', function () { openLogin(); });
        <?php endif ?>

            // ─── Typewriter Animation ────────────────────────────────────
            (function () {
                var TEXTS = {
                    en: <?= json_encode($twEn, JSON_UNESCAPED_UNICODE) ?>,
                    mm: <?= json_encode($twMm, JSON_UNESCAPED_UNICODE) ?>
                };
                var lang = '<?= $currentLang ?>';
                var TYPE_SPEED = lang === 'mm' ? 500 : 200;  //90,65
                var DELETE_SPEED = 150;  //30
                var PAUSE_AFTER = 2200;
                var PAUSE_DELETE = 600;

                var el = document.getElementById('tw-landing');
                var graphemes = [];
                var idx = 0;
                var deleting = false;
                var timer = null;

                function segment(str) {
                    if (typeof Intl !== 'undefined' && Intl.Segmenter) {
                        return Array.from(new Intl.Segmenter('my', { granularity: 'grapheme' }).segment(str), function (s) { return s.segment; });
                    }
                    return Array.from(str);
                }

                var CURSOR = '<span class="tw-cursor"></span>';

                function renderText(count) {
                    var text = graphemes.slice(0, count).join('');
                    el.innerHTML = text + CURSOR;
                }

                function step() {
                    var full = TEXTS[lang] || TEXTS.en;
                    if (graphemes.length === 0 || graphemes._text !== full) {
                        graphemes = segment(full);
                        graphemes._text = full;
                    }
                    var total = graphemes.length;

                    if (!deleting) {
                        idx++;
                        renderText(idx);
                        if (idx >= total) {
                            timer = setTimeout(function () {
                                deleting = true;
                                step();
                            }, PAUSE_AFTER);
                            return;
                        }
                        timer = setTimeout(step, TYPE_SPEED);
                    } else {
                        idx--;
                        renderText(idx);
                        if (idx <= 0) {
                            deleting = false;
                            timer = setTimeout(step, 400);
                            return;
                        }
                        timer = setTimeout(step, DELETE_SPEED);
                    }
                }

                step();
            })();



        function smoothScrollToStats() {
            const target = document.getElementById('stats');
            const targetY = target.getBoundingClientRect().top + window.pageYOffset;
            const startY = window.pageYOffset;
            const distance = targetY - startY;
            const duration = 900; // Increase for slower scrolling (e.g. 2500)

            let startTime = null;

            function animation(currentTime) {
                if (!startTime) startTime = currentTime;
                const timeElapsed = currentTime - startTime;
                const progress = Math.min(timeElapsed / duration, 1);

                // Ease-in-out
                const ease = progress < 0.5
                    ? 2 * progress * progress
                    : 1 - Math.pow(-2 * progress + 2, 2) / 2;

                window.scrollTo(0, startY + distance * ease);

                if (timeElapsed < duration) {
                    requestAnimationFrame(animation);
                }
            }

            requestAnimationFrame(animation);
        }

    </script>

</body>

</html>
