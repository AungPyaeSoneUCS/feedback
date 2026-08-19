<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireRole('student');
updateAllFeedbackStatuses($conn);

$user = getCurrentUser();
$stmtSt = $conn->prepare("SELECT id FROM students WHERE user_id = ?");
$stmtSt->bind_param('i', $user['id']);
$stmtSt->execute();
$student = $stmtSt->get_result()->fetch_assoc();
$stmtSt->close();
$studentId = (int) ($student['id'] ?? 0);

if (!$studentId) {
    header('Location: ' . BASE_URL . 'student/dashboard.php');
    exit;
}

$formId = (int) ($_GET['form_id'] ?? 0);
$module = trim($_GET['module'] ?? '');
$validModules = ['teaching_quality', 'student_support_services', 'learning_environment'];

if (!$formId || !in_array($module, $validModules, true)) {
    header('Location: ' . BASE_URL . 'student/dashboard.php');
    exit;
}

// Verify this submission actually exists
$vStmt = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id = ? AND student_id = ?");
$vStmt->bind_param('ii', $formId, $studentId);
$vStmt->execute();
if ($vStmt->get_result()->num_rows === 0) {
    header('Location: ' . BASE_URL . 'student/dashboard.php');
    exit;
}
$vStmt->close();

// ─── Load form details ────────────────────────────────────────────────
$formTitle = '';
$courseName = '';
$teacherName = '';

if ($module === 'teaching_quality') {
    $fi = $conn->prepare(
        "SELECT ff.title, c.course_name, u.name AS teacher_name
         FROM feedback_forms ff
         JOIN sections s ON ff.section_id = s.id
         JOIN courses c ON s.course_id = c.id
         JOIN teachers t ON s.teacher_id = t.id
         JOIN users u ON t.user_id = u.id
         WHERE ff.id = ? LIMIT 1"
    );
    $fi->bind_param('i', $formId);
    $fi->execute();
    $row = $fi->get_result()->fetch_assoc();
    $fi->close();
    $formTitle = $row['title'] ?? '';
    $courseName = $row['course_name'] ?? '';
    $teacherName = $row['teacher_name'] ?? '';
} else {
    $fi = $conn->prepare("SELECT title FROM feedback_forms WHERE id = ? LIMIT 1");
    $fi->bind_param('i', $formId);
    $fi->execute();
    $row = $fi->get_result()->fetch_assoc();
    $fi->close();
    $formTitle = $row['title'] ?? '';
}

// ─── Count remaining active unsubmitted forms ─────────────────────────
$studentYearIds = getStudentAcademicYearIds($conn, $studentId);
$studentSemIds = getStudentSemesterIds($conn, $studentId);

$remainingTQ = 0;
$tqR = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM feedback_forms ff
     JOIN sections s ON ff.section_id = s.id
     JOIN section_assignments sa ON sa.section_id = s.id
     LEFT JOIN feedback_submissions fs ON fs.form_id = ff.id AND fs.student_id = ?
     WHERE sa.student_id = ? AND ff.module = 'teaching_quality'
       AND ff.status = 'Active' AND fs.id IS NULL"
);
$tqR->bind_param('ii', $studentId, $studentId);
$tqR->execute();
$remainingTQ = (int) ($tqR->get_result()->fetch_assoc()['cnt'] ?? 0);
$tqR->close();

$remainingSSS = 0;
$remainingLE = 0;

if (!empty($studentYearIds) && !empty($studentSemIds)) {
    $yrPH = implode(',', array_fill(0, count($studentYearIds), '?'));
    $yrBT = str_repeat('i', count($studentYearIds));
    $smPH = implode(',', array_fill(0, count($studentSemIds), '?'));
    $smBT = str_repeat('i', count($studentSemIds));

    $sssR = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM feedback_forms f
         LEFT JOIN feedback_submissions fs ON fs.form_id = f.id AND fs.student_id = ?
         WHERE f.module = 'student_support_services' AND f.status = 'Active'
           AND f.academic_year_id IN ($yrPH) AND f.semester_id IN ($smPH) AND fs.id IS NULL"
    );
    $sssR->bind_param('i' . $yrBT . $smBT, ...array_merge([$studentId], $studentYearIds, $studentSemIds));
    $sssR->execute();
    $remainingSSS = (int) ($sssR->get_result()->fetch_assoc()['cnt'] ?? 0);
    $sssR->close();

    $leR = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM feedback_forms f
         LEFT JOIN feedback_submissions fs ON fs.form_id = f.id AND fs.student_id = ?
         WHERE f.module = 'learning_environment' AND f.status = 'Active'
           AND f.academic_year_id IN ($yrPH) AND f.semester_id IN ($smPH) AND fs.id IS NULL"
    );
    $leR->bind_param('i' . $yrBT . $smBT, ...array_merge([$studentId], $studentYearIds, $studentSemIds));
    $leR->execute();
    $remainingLE = (int) ($leR->get_result()->fetch_assoc()['cnt'] ?? 0);
    $leR->close();
}

// ─── Determine state ──────────────────────────────────────────────────
$isLastInModule = false;
if ($module === 'teaching_quality')
    $isLastInModule = ($remainingTQ === 0);
if ($module === 'student_support_services')
    $isLastInModule = ($remainingSSS === 0);
if ($module === 'learning_environment')
    $isLastInModule = ($remainingLE === 0);

// ─── Redirect target ──────────────────────────────────────────────────
$redirectTarget = BASE_URL . 'student/dashboard.php';
$redirectDelay = 4000; // ms

// ─── Build contextual message ─────────────────────────────────────────
$formLabel = $courseName ?: $formTitle;

if ($module === 'teaching_quality') {
    if ($isLastInModule) {
        $thankMsg = $LANG['thank_tq_all_completed'] ?? 'You have successfully completed all Teaching Quality feedback forms. Please continue with the Student Support Services feedback form.';
    } else {
        $thankMsg = sprintf($LANG['thank_tq_course_completed'] ?? 'The Teaching Quality feedback for %s has been successfully completed. Please continue with the next feedback form.', $formLabel);
    }
} elseif ($module === 'student_support_services') {
    $thankMsg = $LANG['thank_sss_completed'] ?? 'You have successfully completed the Student Support Services feedback form. Please continue with the final Learning Environment feedback form.';
} else {
    $thankMsg = $LANG['thank_le_completed'] ?? 'You have successfully completed the Learning Environment feedback form.';
}

$badgeText = $isLastInModule
    ? match ($module) {
        'teaching_quality' => $LANG['badge_all_tq_completed'] ?? 'All Teaching Quality Forms Completed',
        'student_support_services' => $LANG['badge_sss_completed'] ?? 'Student Support Services Completed',
        'learning_environment' => $LANG['badge_le_completed'] ?? 'Learning Environment Completed',
        default => $LANG['badge_module_completed'] ?? 'Module Completed',
    }
    : match ($module) {
        'teaching_quality' => $LANG['badge_tq_submitted'] ?? 'Teaching Quality — Feedback Submitted',
        'student_support_services' => $LANG['badge_sss_submitted'] ?? 'Student Support Services — Submitted',
        'learning_environment' => $LANG['badge_le_submitted'] ?? 'Learning Environment — Submitted',
        default => $LANG['badge_feedback_submitted'] ?? 'Feedback Submitted',
    };

$redirectLabel = $LANG['returning_to_dashboard'] ?? 'Automatically returning to your dashboard...';

$initials = avatarInitials($user['name']);
$pageTitle = $LANG['thank_you_title'] ?? 'Thank You';
$currentLang = $_SESSION['lang'] ?? 'en';
$languageUrl = static function (string $language): string {
    $params = $_GET;
    $params['lang'] = $language;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: BASE_URL . 'student/thank_you.php';
    return $path . '?' . http_build_query($params);
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
        body {
            font-family: 'Inter', sans-serif;
        }

        @keyframes bounceIn {
            0% {
                transform: scale(0.3);
                opacity: 0;
            }

            50% {
                transform: scale(1.08);
            }

            80% {
                transform: scale(0.96);
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(24px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes countdown {
            from {
                width: 100%;
            }

            to {
                width: 0%;
            }
        }

        @keyframes confettiFall {
            to {
                transform: translateY(110vh) rotate(540deg);
                opacity: 0;
            }
        }

        @keyframes ringPing {
            0% {
                transform: scale(1);
                opacity: 0.6;
            }

            100% {
                transform: scale(1.55);
                opacity: 0;
            }
        }

        .bounce-in {
            animation: bounceIn 0.65s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }

        .fade-up-1 {
            animation: fadeUp 0.5s ease both;
            animation-delay: 0.3s;
        }

        .fade-up-2 {
            animation: fadeUp 0.5s ease both;
            animation-delay: 0.5s;
        }

        .fade-up-3 {
            animation: fadeUp 0.5s ease both;
            animation-delay: 0.7s;
        }

        .ring-ping {
            animation: ringPing 1.2s ease-out 0.5s both;
        }

        .countdown-bar {
            animation: countdown
                <?= $redirectDelay ?>
                ms linear forwards;
            animation-delay: 0.6s;
        }

        .confetti {
            position: fixed;
            pointer-events: none;
            border-radius: 3px;
            animation: confettiFall linear forwards;
        }
    </style>
</head>

<body
    class="min-h-screen bg-gradient-to-br from-slate-50 via-emerald-50/20 to-cyan-50/30 flex flex-col <?= $currentLang === 'mm' ? 'lang-mm' : '' ?>">

    <!-- Minimal branded header -->
    <header
        class="bg-white/90 backdrop-blur-sm border-b border-slate-100 px-6 py-3.5 flex items-center justify-between sticky top-0 z-20 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0">
                <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH"
                    class="w-full h-full object-contain">
            </div>
            <div>
                <p class="text-sm font-bold text-slate-800"><?= e($LANG['student_portal'] ?? 'SFIS Student Portal') ?>
                </p>
                <p class="text-[10px] text-slate-400 hidden sm:block">
                    <?= e($LANG['system_name'] ?? 'Student Feedback Information System') ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <div
                class="flex items-center gap-0.5 bg-cyan-50 rounded-lg p-0.5 text-xs font-semibold border border-cyan-100">
                <a href="<?= e($languageUrl('en')) ?>"
                    class="px-3 py-1 rounded-md transition-all <?= $currentLang === 'en' ? 'bg-white shadow text-cyan-700 font-bold' : 'text-cyan-400 hover:text-cyan-700' ?>">ENG</a>
                <a href="<?= e($languageUrl('mm')) ?>"
                    class="px-3 py-1 rounded-md transition-all <?= $currentLang === 'mm' ? 'bg-white shadow text-cyan-700 font-bold' : 'text-cyan-400 hover:text-cyan-700' ?>">မြန်မာ</a>
            </div>
            <div
                class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-cyan-500 flex items-center justify-center text-xs font-bold text-white flex-shrink-0">
                <?= e($initials) ?>
            </div>
            <span class="text-sm font-medium text-slate-700 hidden sm:block"><?= e($user['name']) ?></span>
        </div>
    </header>

    <!-- Main content -->
    <main class="flex-1 flex items-center justify-center p-6">
        <div class="w-full max-w-md">

            <!-- Animated success icon -->
            <div class="flex justify-center mb-8 bounce-in">
                <div class="relative">
                    <!-- Ping ring -->
                    <div class="absolute inset-0 rounded-full border-4 border-emerald-300 ring-ping"></div>
                    <!-- Icon -->
                    <div
                        class="relative w-24 h-24 rounded-full bg-gradient-to-br from-emerald-400 to-green-600 flex items-center justify-center shadow-2xl shadow-green-200/60">
                        <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Message card -->
            <div
                class="bg-white rounded-3xl shadow-2xl shadow-slate-200/50 border border-slate-100 overflow-hidden fade-up-1">
                <!-- Gradient top bar -->
                <div class="h-1.5 bg-gradient-to-r from-emerald-400 via-cyan-500 to-indigo-500"></div>

                <div class="p-8 text-center">
                    <h1 class="text-2xl font-extrabold text-slate-900 mb-4 leading-snug">
                        &#127881; <?= e($LANG['thank_you_heading'] ?? 'Thank You!') ?>
                    </h1>
                    <p class="text-slate-600 leading-relaxed mb-6 text-sm sm:text-base">
                        <?= e($thankMsg) ?>
                    </p>

                    <!-- Status badge -->
                    <div
                        class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-green-50 border border-green-200 text-green-700 text-xs font-semibold mb-7">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M5 13l4 4L19 7" />
                        </svg>
                        <?= e($badgeText) ?>
                    </div>

                    <!-- Auto-redirect countdown -->
                    <div class="fade-up-3">
                        <p class="text-xs text-slate-400 mb-2.5 flex items-center justify-center gap-1.5">
                            <svg class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <?= e($redirectLabel) ?>
                        </p>
                        <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                            <div
                                class="h-full bg-gradient-to-r from-cyan-500 to-emerald-400 rounded-full countdown-bar w-full">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        // Auto-redirect after delay
        setTimeout(function () {
            window.location.href = <?= json_encode($redirectTarget) ?>;
        }, <?= $redirectDelay ?>);

        // Confetti burst on load
        (function () {
            var colors = ['#06b6d4', '#10b981', '#8b5cf6', '#f59e0b', '#ec4899', '#3b82f6'];
            for (var i = 0; i < 40; i++) {
                var el = document.createElement('div');
                el.className = 'confetti';
                var size = 6 + Math.random() * 8;
                el.style.cssText = [
                    'left:' + (10 + Math.random() * 80) + 'vw',
                    'top:-20px',
                    'width:' + size + 'px',
                    'height:' + size + 'px',
                    'background:' + colors[Math.floor(Math.random() * colors.length)],
                    'border-radius:' + (Math.random() > 0.5 ? '50%' : '3px'),
                    'animation-duration:' + (1.2 + Math.random() * 2.5) + 's',
                    'animation-delay:' + (Math.random() * 0.8) + 's'
                ].join(';');
                document.body.appendChild(el);
                setTimeout(function (e) { return function () { e.remove(); }; }(el), 4500);
            }
        }());
    </script>
</body>

</html>