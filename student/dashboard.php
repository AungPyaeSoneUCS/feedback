<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';


requireRole('student');
updateAllFeedbackStatuses($conn);

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$studentId = $student['id'] ?? 0;
$studentYearIds = getStudentAcademicYearIds($conn, $studentId);
$studentSemIds = getStudentSemesterIds($conn, $studentId);

$pageTitle = $LANG['student_dashboard_title'] ?? 'Student Dashboard';
$activeMenu = 'dashboard';

// ─── Load ALL forms and compute stats ──────────────────────────────────
$allForms = [];
$totalCompletedCount = 0;
$totalAvailableCount = 0;

if ($studentId) {
    // Teaching Quality forms
    $acadFormsStmt = $conn->prepare(
        "SELECT ff.id, ff.title, ff.start_date, ff.end_date, ff.status, ff.module,
                c.course_name, c.course_code, sm_sec.section_name AS section_name,
                COALESCE(ay.year_name, '') AS display_year,
                sm.semester_name AS display_semester,
                u.name AS teacher_name,
                (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.form_id=ff.id AND fs.student_id=?) AS submitted
         FROM feedback_forms ff
         JOIN sections s ON ff.section_id = s.id
         JOIN courses c ON s.course_id = c.id
         JOIN teachers t ON s.teacher_id = t.id
         JOIN users u ON t.user_id = u.id
         JOIN section_assignments sa ON sa.section_id = s.id
         LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id
         LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
         LEFT JOIN semesters sm ON s.semester_id = sm.id
         WHERE sa.student_id = ? AND ff.module = 'teaching_quality'
           AND (ff.status = 'Active' OR EXISTS (SELECT 1 FROM feedback_submissions done WHERE done.form_id = ff.id AND done.student_id = ?))
         ORDER BY ff.end_date ASC"
    );
    $acadFormsStmt->bind_param('iii', $studentId, $studentId, $studentId);
    $acadFormsStmt->execute();
    $acadRows = $acadFormsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $acadFormsStmt->close();
    foreach ($acadRows as $r) {
        if ($r['status'] === 'Active' || (int) $r['submitted'] > 0) {
            $allForms[] = $r;
            $totalAvailableCount++;
            if ((int) $r['submitted'] > 0)
                $totalCompletedCount++;
        }
    }

    // Student Support Services + Learning Environment forms
    if (!empty($studentYearIds) && !empty($studentSemIds)) {
        foreach (['student_support_services', 'learning_environment'] as $module) {
            $yrPH = implode(',', array_fill(0, count($studentYearIds), '?'));
            $yrBT = str_repeat('i', count($studentYearIds));
            $smPH = implode(',', array_fill(0, count($studentSemIds), '?'));
            $smBT = str_repeat('i', count($studentSemIds));

            $modStmt = $conn->prepare(
                "SELECT f.id, f.title, f.start_date, f.end_date, f.status, f.module,
                        COALESCE(ay.year_name, '') AS display_year,
                        sm.semester_name AS display_semester,
                        NULL AS course_name, NULL AS course_code,
                        NULL AS section_name, NULL AS teacher_name,
                        (SELECT COUNT(*) FROM feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
                 FROM feedback_forms f
                 LEFT JOIN academic_years ay ON f.academic_year_id = ay.id
                 LEFT JOIN semesters sm ON f.semester_id = sm.id
                 WHERE f.module=? AND f.academic_year_id IN ($yrPH) AND f.semester_id IN ($smPH)
                 ORDER BY f.end_date ASC"
            );
            $modStmt->bind_param('is' . $yrBT . $smBT, ...array_merge([$studentId, $module], $studentYearIds, $studentSemIds));
            $modStmt->execute();
            $modRows = $modStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $modStmt->close();
            foreach ($modRows as $r) {
                if ($r['status'] === 'Active' || (int) $r['submitted'] > 0) {
                    $allForms[] = $r;
                    $totalAvailableCount++;
                    if ((int) $r['submitted'] > 0)
                        $totalCompletedCount++;
                }
            }
        }
    }
}

$totalPendingCount = $totalAvailableCount - $totalCompletedCount;
$allCompleted = $totalAvailableCount > 0 && $totalPendingCount === 0;

// ─── Group forms by module ──────────────────────────────────────────────
$acadPendingForms = [];
$acadCompletedCount = 0;
$saPendingForms = [];
$saCompletedCount = 0;
$admPendingForms = [];
$admCompletedCount = 0;

foreach ($allForms as $f) {
    $isSubmitted = (int) $f['submitted'] > 0;
    if ($f['module'] === 'teaching_quality') {
        if ($isSubmitted)
            $acadCompletedCount++;
        else
            $acadPendingForms[] = $f;
    } elseif ($f['module'] === 'student_support_services') {
        if ($isSubmitted)
            $saCompletedCount++;
        else
            $saPendingForms[] = $f;
    } elseif ($f['module'] === 'learning_environment') {
        if ($isSubmitted)
            $admCompletedCount++;
        else
            $admPendingForms[] = $f;
    }
}

// ─── Module totals ──────────────────────────────────────────────────────
$acadTotal = $acadCompletedCount + count($acadPendingForms);
$saTotal = $saCompletedCount + count($saPendingForms);
$admTotal = $admCompletedCount + count($admPendingForms);

// ─── Next form to complete (sequential: TQ → SSS → LE) ─────────────────
$nextForm = null;
$nextFormUrl = null;

if (!empty($acadPendingForms)) {
    $nextForm = $acadPendingForms[0];
    $nextFormUrl = BASE_URL . 'student/feedback_form.php?form_id=' . (int) $nextForm['id'];
} elseif (!empty($saPendingForms)) {
    $nextForm = $saPendingForms[0];
    $nextFormUrl = BASE_URL . 'student/sa_feedback_form.php?form_id=' . (int) $nextForm['id'];
} elseif (!empty($admPendingForms)) {
    $nextForm = $admPendingForms[0];
    $nextFormUrl = BASE_URL . 'student/adm_feedback_form.php?form_id=' . (int) $nextForm['id'];
}

// One-time completion state created by the successful survey submission.
$completionMessage = '';
$completionBadge = '';
$showCompletionMessage = false;
$completionDelay = 4000;
$completion = $_SESSION['feedback_completion'] ?? null;
unset($_SESSION['feedback_completion']);

if (is_array($completion)) {
    $completedFormId = (int) ($completion['form_id'] ?? 0);
    $completedModule = (string) ($completion['module'] ?? '');
    if ($completedFormId > 0 && in_array($completedModule, ['teaching_quality', 'student_support_services', 'learning_environment'], true)) {
        $completedLabel = '';
        if ($completedModule === 'teaching_quality') {
            $completedStmt = $conn->prepare(
                "SELECT COALESCE(c.course_name, ff.title) AS form_label
                 FROM feedback_forms ff
                 LEFT JOIN sections s ON s.id = ff.section_id
                 LEFT JOIN courses c ON c.id = s.course_id
                 WHERE ff.id = ? LIMIT 1"
            );
        } else {
            $completedStmt = $conn->prepare("SELECT title AS form_label FROM feedback_forms WHERE id = ? LIMIT 1");
        }
        $completedStmt->bind_param('i', $completedFormId);
        $completedStmt->execute();
        $completedLabel = (string) ($completedStmt->get_result()->fetch_assoc()['form_label'] ?? '');
        $completedStmt->close();

        if ($completedModule === 'teaching_quality') {
            if (empty($acadPendingForms)) {
                $completionMessage = $LANG['thank_tq_all_completed'] ?? 'You have successfully completed all Teaching Quality feedback forms. Please continue with the Student Support Services feedback form.';
                $completionBadge = $LANG['badge_all_tq_completed'] ?? 'All Teaching Quality Forms Completed';
            } else {
                $completionMessage = sprintf($LANG['thank_tq_course_completed'] ?? 'The Teaching Quality feedback for %s has been successfully completed. Please continue with the next feedback form.', $completedLabel);
                $completionBadge = $LANG['badge_tq_submitted'] ?? 'Teaching Quality — Feedback Submitted';
            }
        } elseif ($completedModule === 'student_support_services') {
            $completionMessage = $LANG['thank_sss_completed'] ?? 'You have successfully completed the Student Support Services feedback form. Please continue with the final Learning Environment feedback form.';
            $completionBadge = $LANG['badge_sss_completed'] ?? 'Student Support Services Completed';
        } else {
            $completionMessage = $LANG['thank_le_completed'] ?? 'You have successfully completed the Learning Environment feedback form.';
            $completionBadge = $LANG['badge_le_completed'] ?? 'Learning Environment Completed';
        }
        // The temporary notification is only useful when another required
        // form remains. Final completion goes straight to the completed state.
        $showCompletionMessage = $totalPendingCount > 0;
    }
}

$inlineFormHtml = '';
if ($nextForm) {
    $surveyModule = $nextForm['module'];
    $surveyDashboardEmbed = true;
    $_GET['form_id'] = (int) $nextForm['id'];
    ob_start();
    require __DIR__ . '/survey_form_common.php';
    $inlineFormHtml = ob_get_clean();
    $pageTitle = $LANG['student_dashboard_title'] ?? 'Student Dashboard';
}
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
    <style>
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(14px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulseGlow {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(6, 182, 212, 0.3);
            }

            50% {
                box-shadow: 0 0 0 10px rgba(6, 182, 212, 0);
            }
        }

        @keyframes activeCardShimmer {
            from {
                background-position: -200% center;
            }

            to {
                background-position: 200% center;
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.45s ease both;
        }

        .delay-1 {
            animation-delay: 0.08s;
        }

        .delay-2 {
            animation-delay: 0.16s;
        }

        .delay-3 {
            animation-delay: 0.26s;
        }

        .pulse-glow {
            animation: pulseGlow 2.5s ease-in-out infinite;
        }

        /* Arrow slides right on hover */
        .active-form-link .card-arrow {
            transition: transform 0.25s ease;
        }

        .active-form-link:hover .card-arrow {
            transform: translateX(4px);
        }

        /* Smooth card hover lift */
        .active-form-link {
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }

        .active-form-link:hover {
            transform: translateY(-3px);
        }

        @keyframes completionCountdown {
            from { width: 0%; }
            to { width: 100%; }
        }

        .dashboard-completion-countdown {
            width: 0;
            transform-origin: left center;
            animation-name: completionCountdown;
            animation-duration: <?= (int) $completionDelay ?>ms;
            animation-timing-function: linear;
            animation-fill-mode: forwards;
        }
    </style>
</head>

<body
    class="h-full bg-gradient-to-br from-slate-50 to-cyan-50/30 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <?php if ($showCompletionMessage): ?>
        <div id="dashboard-completion-message"
            class="fixed inset-x-3 top-3 z-[70] mx-auto max-w-2xl overflow-hidden rounded-2xl border border-emerald-200 bg-white/95 shadow-2xl shadow-emerald-200/50 backdrop-blur transition-all duration-300 ease-out sm:inset-x-6 sm:top-5"
            role="status" aria-live="polite">
            <div class="h-1.5 bg-gradient-to-r from-emerald-400 via-cyan-500 to-indigo-500"></div>
            <div class="flex items-start gap-4 p-5 sm:p-6">
                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-emerald-100 text-2xl shadow-sm"
                    aria-hidden="true">&#127881;</div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-extrabold text-slate-900 sm:text-xl">
                        <?= e($LANG['thank_you_heading'] ?? 'Thank You!') ?></h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-slate-600 sm:text-base"><?= e($completionMessage) ?></p>
                    <p class="mt-2 text-xs font-medium text-emerald-700">
                        <?= e($LANG['next_form_loading'] ?? 'The next required feedback form will appear automatically...') ?>
                    </p>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-emerald-50">
                        <div
                            class="dashboard-completion-countdown h-full rounded-full bg-gradient-to-r from-cyan-500 to-emerald-400">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">

        <!-- ── Sidebar ── -->
        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
                    <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH Logo"
                        class="w-full h-full object-contain rounded-xl">
                </div>
                <div>
                    <p class="text-lg font-bold"><?= $LANG['student_portal'] ?? 'SFIS Student' ?></p>
                    <p class="text-[10px] text-cyan-100"><?= $LANG['student_portal_sub'] ?? 'Student Portal' ?></p>
                </div>
                <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-200">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 py-4 px-3 space-y-0.5 overflow-y-auto scrollbar-thin">
                <?php
                $navItems = [
                    ['label' => $LANG['nav_dashboard'] ?? 'Dashboard', 'href' => BASE_URL . 'student/dashboard.php', 'key' => 'dashboard', 'icon' => 'home', 'iconColor' => 'text-yellow-300'],
                    ['label' => $LANG['nav_history'] ?? 'Submission History', 'href' => BASE_URL . 'student/feedback_history.php', 'key' => 'history', 'icon' => 'history', 'iconColor' => 'text-teal-300'],
                    ['label' => $LANG['nav_profile'] ?? 'Profile', 'href' => BASE_URL . 'student/profile.php', 'key' => 'profile', 'icon' => 'user', 'iconColor' => 'text-rose-300'],
                ];
                foreach ($navItems as $n):
                    $a = $activeMenu === $n['key']; ?>
                    <a href="<?= $n['href'] ?>"
                        class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm transition-all duration-150 <?= $a ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white' ?>">
                        <?= iconSvg($n['icon'], 'w-5 h-5 flex-shrink-0 ' . ($n['iconColor'] ?? 'text-white/80')) ?>
                        <?= e($n['label']) ?>
                        <?php if ($a): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
                    </a>
                <?php endforeach ?>
            </nav>

            <a href="<?= e(BASE_URL) ?>auth/logout.php" title="<?= $LANG['logout'] ?? 'Logout' ?>"
                class="block border-t border-white/15 bg-red-500/80 text-gray-50 hover:text-gray-200 transition-colors px-4 py-4 cursor-pointer">
                <div class="flex items-center justify-center gap-3">
                    <div class="min-w-0">
                        <p class="text-xl h-8"><?= $LANG['logout'] ?? 'Logout' ?></p>
                    </div>
                    <?= iconSvg('logout', 'w-6 h-6') ?>
                </div>
            </a>
        </aside>

        <!-- ── Main Column ── -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <?php include '../includes/student_header.php'; ?>

            <main class="flex-1 overflow-y-auto p-4 lg:p-6">
                <?php renderFlash() ?>

                <!-- ── Welcome Banner ── -->
                <div
                    class="relative bg-gradient-to-r from-cyan-600 via-cyan-600 to-cyan-700 rounded-2xl p-6 mb-6 text-white shadow-lg overflow-hidden fade-in-up">
                    <div class="absolute -right-6 -top-6 w-32 h-32 rounded-full bg-white/10 pointer-events-none"></div>
                    <div class="absolute right-8 -bottom-10 w-40 h-40 rounded-full bg-white/5 pointer-events-none">
                    </div>
                    <div class="relative z-10">
                        <h2 class="text-2xl font-bold">
                            <?= $LANG['student_welcome'] ?? 'Welcome' ?>, <?= e($user['name']) ?> 👋
                        </h2>
                        <p class="text-cyan-100 mt-1.5 text-sm max-w-lg">
                            <?= e($LANG['dashboard_feedback_instruction'] ?? 'Please complete all required feedback forms for this semester.') ?>
                        </p>
                    </div>
                </div>

                <?php if ($totalAvailableCount > 0): ?>

                    <!-- ── Required Forms Summary ── -->
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6 fade-in-up delay-1">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3.5">
                            <?= e($LANG['required_feedback_forms'] ?? 'Required Feedback Forms') ?>
                        </h3>
                        <div class="space-y-2.5">

                            <?php if ($acadTotal > 0): ?>
                                <?php $done = ($acadCompletedCount >= $acadTotal); ?>
                                <div
                                    class="flex flex-wrap items-center gap-3 px-4 py-3 sm:flex-nowrap rounded-xl <?= $done ? 'bg-green-50 border border-green-100' : 'bg-cyan-50 border border-cyan-100' ?>">
                                    <div
                                        class="w-8 h-8 rounded-lg <?= $done ? 'bg-green-500' : 'bg-cyan-500' ?> flex items-center justify-center text-white flex-shrink-0">
                                        <?= iconSvg('academic', 'w-4 h-4') ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold <?= $done ? 'text-green-800' : 'text-slate-700' ?>">
                                            <?= e($LANG['academic'] ?? 'Teaching Quality') ?></p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            <?= e(sprintf($LANG['feedback_forms_required_count'] ?? '%d Feedback Forms Required', $acadTotal)) ?>
                                        </p>
                                    </div>
                                    <?php if ($done): ?>
                                        <span class="flex items-center gap-1 text-xs font-semibold text-green-600">
                                            <?= iconSvg('check', 'w-3.5 h-3.5') ?>             <?= e($LANG['completed'] ?? 'Completed') ?>
                                            (<?= $acadCompletedCount ?>/<?= $acadTotal ?>)
                                        </span>
                                    <?php else: ?>
                                        <div class="flex flex-col items-end gap-1 text-xs font-semibold flex-shrink-0">
                                            <span
                                                class="flex items-center gap-1 text-green-600"><?= iconSvg('check', 'w-3.5 h-3.5') ?>
                                                <?= $acadCompletedCount ?>             <?= e($LANG['completed'] ?? 'Completed') ?></span>
                                            <span class="text-cyan-600">&rarr; <?= $acadTotal - $acadCompletedCount ?>
                                                <?= e(ucfirst($LANG['remaining'] ?? 'Remaining')) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($saTotal > 0): ?>
                                <?php $done = ($saCompletedCount >= $saTotal); ?>
                                <div
                                    class="flex flex-wrap items-center gap-3 px-4 py-3 sm:flex-nowrap rounded-xl <?= $done ? 'bg-green-50 border border-green-100' : 'bg-purple-50 border border-purple-100' ?>">
                                    <div
                                        class="w-8 h-8 rounded-lg <?= $done ? 'bg-green-500' : 'bg-purple-500' ?> flex items-center justify-center text-white flex-shrink-0">
                                        <?= iconSvg('shield', 'w-4 h-4') ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold <?= $done ? 'text-green-800' : 'text-slate-700' ?>">
                                            <?= e($LANG['student_affairs'] ?? 'Student Support Services') ?></p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            <?= e(sprintf($LANG['feedback_forms_required_count'] ?? '%d Feedback Forms Required', $saTotal)) ?>
                                        </p>
                                    </div>
                                    <?php if ($done): ?>
                                        <span class="flex items-center gap-1 text-xs font-semibold text-green-600">
                                            <?= iconSvg('check', 'w-3.5 h-3.5') ?>             <?= e($LANG['completed'] ?? 'Completed') ?>
                                            (<?= $saCompletedCount ?>/<?= $saTotal ?>)
                                        </span>
                                    <?php else: ?>
                                        <div class="flex flex-col items-end gap-1 text-xs font-semibold flex-shrink-0">
                                            <span
                                                class="flex items-center gap-1 text-green-600"><?= iconSvg('check', 'w-3.5 h-3.5') ?>
                                                <?= $saCompletedCount ?>             <?= e($LANG['completed'] ?? 'Completed') ?></span>
                                            <span class="text-purple-600">&rarr; <?= $saTotal - $saCompletedCount ?>
                                                <?= e(ucfirst($LANG['remaining'] ?? 'Remaining')) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($admTotal > 0): ?>
                                <?php $done = ($admCompletedCount >= $admTotal); ?>
                                <div
                                    class="flex flex-wrap items-center gap-3 px-4 py-3 sm:flex-nowrap rounded-xl <?= $done ? 'bg-green-50 border border-green-100' : 'bg-orange-50 border border-orange-100' ?>">
                                    <div
                                        class="w-8 h-8 rounded-lg <?= $done ? 'bg-green-500' : 'bg-orange-500' ?> flex items-center justify-center text-white flex-shrink-0">
                                        <?= iconSvg('office', 'w-4 h-4') ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold <?= $done ? 'text-green-800' : 'text-slate-700' ?>">
                                            <?= e($LANG['administration'] ?? 'Learning Environment') ?></p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            <?= e(sprintf($LANG['feedback_forms_required_count'] ?? '%d Feedback Forms Required', $admTotal)) ?>
                                        </p>
                                    </div>
                                    <?php if ($done): ?>
                                        <span class="flex items-center gap-1 text-xs font-semibold text-green-600">
                                            <?= iconSvg('check', 'w-3.5 h-3.5') ?>             <?= e($LANG['completed'] ?? 'Completed') ?>
                                            (<?= $admCompletedCount ?>/<?= $admTotal ?>)
                                        </span>
                                    <?php else: ?>
                                        <div class="flex flex-col items-end gap-1 text-xs font-semibold flex-shrink-0">
                                            <span
                                                class="flex items-center gap-1 text-green-600"><?= iconSvg('check', 'w-3.5 h-3.5') ?>
                                                <?= $admCompletedCount ?>             <?= e($LANG['completed'] ?? 'Completed') ?></span>
                                            <span class="text-orange-600">&rarr; <?= $admTotal - $admCompletedCount ?>
                                                <?= e(ucfirst($LANG['remaining'] ?? 'Remaining')) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                <?php endif; /* $totalAvailableCount > 0 */ ?>

                <div id="dashboard-feedback-flow" class="<?= $showCompletionMessage ? 'hidden' : '' ?>">

                    <?php if ($nextForm): ?>
                        <div class="mb-6 fade-in-up delay-2">
                            <?= $inlineFormHtml ?>
                        </div>

                    <?php elseif (false && $nextFormUrl): ?>
                        <!-- ── Current Feedback Form (fully-clickable card) ── -->
                        <div class="mb-6 fade-in-up delay-2">
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">
                                <?= e($LANG['current_feedback_form'] ?? 'Current Feedback Form') ?>
                            </p>
                            <?php
                            $nm = $nextForm['module'];
                            // Per-module colour tokens
                            $tokens = [
                                'teaching_quality' => [
                                    'border' => 'border-cyan-300',
                                    'hborder' => 'hover:border-cyan-500',
                                    'accent' => 'from-cyan-500 to-indigo-500',
                                    'badge' => 'bg-cyan-100 text-cyan-700',
                                    'arrow' => 'bg-cyan-50 group-hover:bg-cyan-100 text-cyan-600',
                                    'label' => $LANG['academic'] ?? 'Teaching Quality',
                                ],
                                'student_support_services' => [
                                    'border' => 'border-purple-300',
                                    'hborder' => 'hover:border-purple-500',
                                    'accent' => 'from-purple-500 to-indigo-500',
                                    'badge' => 'bg-purple-100 text-purple-700',
                                    'arrow' => 'bg-purple-50 group-hover:bg-purple-100 text-purple-600',
                                    'label' => $LANG['student_affairs'] ?? 'Student Support Services',
                                ],
                                'learning_environment' => [
                                    'border' => 'border-orange-300',
                                    'hborder' => 'hover:border-orange-500',
                                    'accent' => 'from-orange-500 to-amber-400',
                                    'badge' => 'bg-orange-100 text-orange-700',
                                    'arrow' => 'bg-orange-50 group-hover:bg-orange-100 text-orange-600',
                                    'label' => $LANG['administration'] ?? 'Learning Environment',
                                ],
                            ];
                            $t = $tokens[$nm] ?? $tokens['teaching_quality'];
                            ?>
                            <a href="<?= e($nextFormUrl) ?>"
                                class="active-form-link group block bg-white rounded-2xl border-2 <?= $t['border'] . ' ' . $t['hborder'] ?> shadow-md hover:shadow-xl overflow-hidden pulse-glow">
                                <!-- Gradient accent bar -->
                                <div class="h-1.5 bg-gradient-to-r <?= $t['accent'] ?>"></div>

                                <div class="p-6">
                                    <div class="flex items-start gap-4">
                                        <!-- Info -->
                                        <div class="flex-1 min-w-0">
                                            <!-- Module badge + live dot -->
                                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                                <span
                                                    class="inline-flex items-center px-3 py-0.5 rounded-full text-xs font-semibold <?= $t['badge'] ?>">
                                                    <?= e($t['label']) ?>
                                                </span>
                                                <span class="flex items-center gap-1 text-xs text-amber-600 font-medium">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse inline-block"></span>
                                                    <?= e($LANG['active'] ?? 'Active') ?>
                                                </span>
                                            </div>

                                            <!-- Form title -->
                                            <h4 class="text-lg font-bold text-slate-900 mb-2.5 leading-snug">
                                                <?= e($nextForm['title']) ?>
                                            </h4>

                                            <!-- Contextual details -->
                                            <div class="space-y-1.5">
                                                <?php if ($nm === 'teaching_quality'): ?>
                                                    <?php if (!empty($nextForm['course_name'])): ?>
                                                        <p class="text-sm text-slate-600 flex items-center gap-1.5">
                                                            <?= iconSvg('book', 'w-3.5 h-3.5 text-slate-400 flex-shrink-0') ?>
                                                            <span><?= e($nextForm['course_name']) ?><?= !empty($nextForm['course_code']) ? ' (' . e($nextForm['course_code']) . ')' : '' ?></span>
                                                        </p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($nextForm['teacher_name'])): ?>
                                                        <p class="text-sm text-slate-500 flex items-center gap-1.5">
                                                            <?= iconSvg('user', 'w-3.5 h-3.5 text-slate-400 flex-shrink-0') ?>
                                                            <span><?= e($nextForm['teacher_name']) ?></span>
                                                        </p>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if (!empty($nextForm['display_year']) || !empty($nextForm['display_semester'])): ?>
                                                        <p class="text-sm text-slate-500 flex items-center gap-1.5">
                                                            <?= iconSvg('academic', 'w-3.5 h-3.5 text-slate-400 flex-shrink-0') ?>
                                                            <span>
                                                                <?= e($nextForm['display_year'] ?? '') ?>
                                                                <?php if (!empty($nextForm['display_semester'])): ?>
                                                                    &nbsp;·&nbsp;<?= e(semesterToRoman($nextForm['display_semester'])) ?>
                                                                <?php endif; ?>
                                                            </span>
                                                        </p>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if (!empty($nextForm['end_date'])): ?>
                                                    <p class="text-xs text-slate-400 flex items-center gap-1.5">
                                                        <?= iconSvg('history', 'w-3.5 h-3.5 flex-shrink-0') ?>
                                                        <span><?= e($LANG['due'] ?? 'Due') ?>:
                                                            <?= formatDateTime($nextForm['end_date']) ?></span>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Arrow indicator -->
                                        <div
                                            class="flex-shrink-0 w-11 h-11 rounded-xl <?= $t['arrow'] ?> flex items-center justify-center mt-1 transition-colors">
                                            <svg class="w-5 h-5 card-arrow" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9 5l7 7-7 7" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>

                    <?php elseif ($allCompleted): ?>
                        <!-- ── All Done State ── -->
                        <div
                            class="bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 rounded-2xl p-8 mb-6 text-center fade-in-up delay-2">
                            <div
                                class="w-16 h-16 mx-auto mb-4 rounded-full bg-gradient-to-br from-green-400 to-emerald-500 flex items-center justify-center shadow-lg shadow-green-200">
                                <svg class="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                        d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-bold text-green-800 mb-1.5">
                                &#127881;
                                <?= e($LANG['all_required_feedback_completed'] ?? 'All required feedback forms have been completed.') ?>
                            </h3>
                            <p class="text-sm text-green-600 mb-7">
                                <?= e($LANG['thank_you_participation'] ?? 'Thank you for your valuable participation.') ?>
                            </p>

                            <div class="space-y-2.5 text-left max-w-sm mx-auto">
                                <?php if ($acadTotal > 0): ?>
                                    <div
                                        class="flex items-center gap-3 bg-white/70 rounded-xl px-5 py-3 border border-green-100">
                                        <div
                                            class="w-6 h-6 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                    d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                        <span
                                            class="text-sm font-semibold text-green-800"><?= e($LANG['teaching_quality_completed'] ?? 'Teaching Quality Completed') ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($saTotal > 0): ?>
                                    <div
                                        class="flex items-center gap-3 bg-white/70 rounded-xl px-5 py-3 border border-green-100">
                                        <div
                                            class="w-6 h-6 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                    d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                        <span
                                            class="text-sm font-semibold text-green-800"><?= e($LANG['student_support_services_completed'] ?? 'Student Support Services Completed') ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($admTotal > 0): ?>
                                    <div
                                        class="flex items-center gap-3 bg-white/70 rounded-xl px-5 py-3 border border-green-100">
                                        <div
                                            class="w-6 h-6 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                    d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                        <span
                                            class="text-sm font-semibold text-green-800"><?= e($LANG['learning_environment_completed'] ?? 'Learning Environment Completed') ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($totalAvailableCount === 0): ?>
                        <!-- ── No Forms Available ── -->
                        <div
                            class="bg-white rounded-2xl shadow-sm border border-slate-100 p-10 mb-6 text-center fade-in-up delay-2">
                            <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-slate-100 flex items-center justify-center">
                                <?= iconSvg('clipboard', 'w-7 h-7 text-slate-400') ?>
                            </div>
                            <p class="text-sm font-medium text-slate-500">
                                <?= e($LANG['no_feedback_forms_available'] ?? 'No feedback forms are currently available for you.') ?>
                            </p>
                            <p class="text-xs text-slate-400 mt-1">
                                <?= e($LANG['check_back_for_feedback_forms'] ?? 'Check back when forms are opened by your instructors.') ?>
                            </p>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- ── Quick Links ── -->
                <!-- <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 fade-in-up delay-3">
                    <a href="<?= e(BASE_URL) ?>student/feedback_history.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-slate-300 transition-all hover:-translate-y-0.5">
                        <div class="w-10 h-10 rounded-xl bg-teal-100 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('history', 'w-5 h-5 text-teal-600') ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate"><?= $LANG['nav_history'] ?? 'History' ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['all_submissions'] ?? 'All submissions' ?></p>
                        </div>
                    </a>
                    <a href="<?= e(BASE_URL) ?>student/profile.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-rose-200/60 transition-all hover:-translate-y-0.5">
                        <div class="w-10 h-10 rounded-xl bg-rose-100 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('user', 'w-5 h-5 text-rose-600') ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate"><?= $LANG['nav_profile'] ?? 'Profile' ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['profile_subtitle'] ?? 'Account settings' ?></p>
                        </div>
                    </a>
                </div> -->

            </main>
        </div>
    </div>
    <script>
        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }
        <?php if ($showCompletionMessage): ?>
            window.setTimeout(function () {
                const message = document.getElementById('dashboard-completion-message');
                const flow = document.getElementById('dashboard-feedback-flow');
                if (message) {
                    message.classList.add('opacity-0', '-translate-y-2');
                }
                window.setTimeout(function () {
                    if (message) message.remove();
                    if (flow) {
                        flow.classList.remove('hidden');
                        flow.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }, 300);
            }, <?= $completionDelay ?>);
        <?php endif; ?>
    </script>
</body>

</html>
