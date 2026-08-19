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

if (!$studentId) {
    setFlash('error', $LANG['flash_student_profile_missing'] ?? 'Student profile not found.');
    header('Location: ' . BASE_URL . 'student/dashboard.php');
    exit;
}

$now = date('Y-m-d H:i:s');
$nowTimestamp = strtotime($now);
$isFormCurrentlyActive = static function (array $form) use ($nowTimestamp): bool {
    $startTimestamp = !empty($form['start_date']) ? strtotime($form['start_date']) : null;
    $endTimestamp = !empty($form['end_date']) ? strtotime($form['end_date']) : null;
    return ($form['status'] ?? '') === 'Active'
        && ($startTimestamp === null || $startTimestamp <= $nowTimestamp)
        && ($endTimestamp === null || $endTimestamp >= $nowTimestamp);
};
$isFormExpired = static function (array $form) use ($nowTimestamp): bool {
    return !empty($form['end_date']) && strtotime($form['end_date']) < $nowTimestamp;
};

// ─── Load ALL available forms across all modules ─────────────────────
$allForms = [];

// Academic forms
if ($studentId) {
    $acadStmt = $conn->prepare(
        "SELECT ff.id, ff.title, ff.start_date, ff.end_date, ff.status, ff.module,
                ff.section_id,
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
         ORDER BY ff.end_date ASC, ff.id ASC"
    );
    $acadStmt->bind_param('iii', $studentId, $studentId, $studentId);
    $acadStmt->execute();
    $acadRows = $acadStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $acadStmt->close();
    foreach ($acadRows as $r) {
        $allForms[] = $r;
    }
}

// SA forms
if ($studentId && !empty($studentYearIds) && !empty($studentSemIds)) {
    $yrPH = implode(',', array_fill(0, count($studentYearIds), '?'));
    $yrBT = str_repeat('i', count($studentYearIds));
    $smPH = implode(',', array_fill(0, count($studentSemIds), '?'));
    $smBT = str_repeat('i', count($studentSemIds));

    $saStmt = $conn->prepare(
        "SELECT f.id, f.title, f.start_date, f.end_date, f.status, f.module,
                NULL AS section_id,
                NULL AS course_name, NULL AS course_code, NULL AS section_name,
                COALESCE(ay.year_name, '') AS display_year,
                sm.semester_name AS display_semester,
                NULL AS teacher_name,
                (SELECT COUNT(*) FROM feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
         FROM feedback_forms f
         LEFT JOIN academic_years ay ON f.academic_year_id = ay.id
         LEFT JOIN semesters sm ON f.semester_id = sm.id
         WHERE f.module='student_support_services' AND f.academic_year_id IN ($yrPH) AND f.semester_id IN ($smPH)
         ORDER BY f.end_date ASC, f.id ASC"
    );
    $saStmt->bind_param('i' . $yrBT . $smBT, ...array_merge([$studentId], $studentYearIds, $studentSemIds));
    $saStmt->execute();
    $saRows = $saStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $saStmt->close();
    foreach ($saRows as $r) {
        $allForms[] = $r;
    }
}

// Admin forms
if ($studentId && !empty($studentYearIds) && !empty($studentSemIds)) {
    $yrPH = implode(',', array_fill(0, count($studentYearIds), '?'));
    $yrBT = str_repeat('i', count($studentYearIds));
    $smPH = implode(',', array_fill(0, count($studentSemIds), '?'));
    $smBT = str_repeat('i', count($studentSemIds));

    $admStmt = $conn->prepare(
        "SELECT f.id, f.title, f.start_date, f.end_date, f.status, f.module,
                NULL AS section_id,
                NULL AS course_name, NULL AS course_code, NULL AS section_name,
                COALESCE(ay.year_name, '') AS display_year,
                sm.semester_name AS display_semester,
                NULL AS teacher_name,
                (SELECT COUNT(*) FROM feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
         FROM feedback_forms f
         LEFT JOIN academic_years ay ON f.academic_year_id = ay.id
         LEFT JOIN semesters sm ON f.semester_id = sm.id
         WHERE f.module='learning_environment' AND f.academic_year_id IN ($yrPH) AND f.semester_id IN ($smPH)
         ORDER BY f.end_date ASC, f.id ASC"
    );
    $admStmt->bind_param('i' . $yrBT . $smBT, ...array_merge([$studentId], $studentYearIds, $studentSemIds));
    $admStmt->execute();
    $admRows = $admStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $admStmt->close();
    foreach ($admRows as $r) {
        $allForms[] = $r;
    }
}

// Sort all forms: submitted last, then by module priority (academic, student_affairs, administration), then by date
$moduleOrder = ['teaching_quality' => 0, 'student_support_services' => 1, 'learning_environment' => 2];
usort($allForms, function ($a, $b) use ($moduleOrder) {
    $aSubmitted = (int) $a['submitted'] > 0;
    $bSubmitted = (int) $b['submitted'] > 0;
    if ($aSubmitted !== $bSubmitted) {
        return $aSubmitted ? 1 : -1;
    }
    $aMod = $moduleOrder[$a['module']] ?? 99;
    $bMod = $moduleOrder[$b['module']] ?? 99;
    if ($aMod !== $bMod) {
        return $aMod - $bMod;
    }
    return strtotime($a['end_date']) - strtotime($b['end_date']);
});

// ─── Find the first uncompleted form (for enforcement) ────────────────
$firstUncompletedId = null;
foreach ($allForms as $f) {
    if ((int) $f['submitted'] === 0 && $isFormCurrentlyActive($f)) {
        $firstUncompletedId = $f['id'];
        break;
    }
}

// ─── Handle skip prevention ──────────────────────────────────────────
$requestedFormId = (int) ($_GET['form_id'] ?? 0);
if ($requestedFormId && $firstUncompletedId && $requestedFormId !== $firstUncompletedId) {
    // Check if the requested form is actually in the list and not submitted
    $requestedSubmitted = false;
    $requestedActive = false;
    foreach ($allForms as $f) {
        if ($f['id'] === $requestedFormId) {
            $requestedSubmitted = (int) $f['submitted'] > 0;
            $requestedActive = strtotime($f['end_date']) >= $nowTimestamp;
            break;
        }
    }
    if (!$requestedSubmitted && $requestedActive) {
        setFlash('error', $LANG['flash_please_complete_previous'] ?? 'Please complete the previous feedback form first.');
        header('Location: feedback_forms.php');
        exit;
    }
}

// Stats
$totalForms = count($allForms);
$completedCount = 0;
$pendingCount = 0;
$expiredCount = 0;
foreach ($allForms as $f) {
    if ((int) $f['submitted'] > 0) {
        $completedCount++;
    } elseif ($isFormCurrentlyActive($f)) {
        $pendingCount++;
    } elseif ($isFormExpired($f)) {
        $expiredCount++;
    }
}
$progressEligibleTotal = $completedCount + $pendingCount;
$progressPercent = $progressEligibleTotal > 0 ? round(($completedCount / $progressEligibleTotal) * 100) : 0;
$allCompleted = $totalForms > 0 && $pendingCount === 0;

// Current state for the inline, sequential Survey workflow.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'workflow') {
    $nextForm = null;
    if ($firstUncompletedId !== null) {
        foreach ($allForms as $candidate) {
            if ((int) $candidate['id'] === (int) $firstUncompletedId) {
                $nextForm = $candidate;
                break;
            }
        }
    }

    $formPages = [
        'teaching_quality' => 'feedback_form.php',
        'student_support_services' => 'sa_feedback_form.php',
        'learning_environment' => 'adm_feedback_form.php',
    ];
    $nextUrl = $nextForm
        ? ($formPages[$nextForm['module']] ?? 'feedback_form.php') . '?form_id=' . (int) $nextForm['id']
        : null;

    header('Content-Type: application/json');
    echo json_encode([
        'next_url' => $nextUrl,
        'next_form_id' => $nextForm ? (int) $nextForm['id'] : null,
        'all_completed' => $nextForm === null,
        'completed' => $completedCount,
        'pending' => $pendingCount,
        'total' => $progressEligibleTotal,
        'progress' => $progressPercent,
    ]);
    exit;
}

// ─── AJAX: Get form status ───────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status' && isset($_GET['fid'])) {
    header('Content-Type: application/json');
    $fid = (int) $_GET['fid'];
    $st = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=?");
    $st->bind_param('ii', $fid, $studentId);
    $st->execute();
    $submitted = $st->get_result()->num_rows > 0;
    $st->close();
    echo json_encode(['submitted' => $submitted]);
    exit;
}

$initialInlineUrl = '';
if ($requestedFormId) {
    $formPages = [
        'teaching_quality' => 'feedback_form.php',
        'student_support_services' => 'sa_feedback_form.php',
        'learning_environment' => 'adm_feedback_form.php',
    ];
    foreach ($allForms as $candidate) {
        if ((int) $candidate['id'] === $requestedFormId) {
            $initialInlineUrl = ($formPages[$candidate['module']] ?? 'feedback_form.php')
                . '?form_id=' . $requestedFormId;
            break;
        }
    }
}

$pageTitle = $LANG['nav_feedback_forms'] ?? 'Feedback Forms';
$activeMenu = 'feedback_forms';
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
    <style>
        .form-card {
            transition: all 0.3s ease;
        }

        .form-card.locked {
            opacity: 0.6;
            pointer-events: none;
        }

        .form-card.locked .fill-btn {
            display: none;
        }

        .progress-ring {
            transition: stroke-dashoffset 0.6s ease;
        }

        #feedback-workflow[aria-busy="true"] {
            min-height: 180px;
        }
    </style>
</head>

<body
    class="h-full bg-gradient-to-br from-slate-50 to-cyan-50/30 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">

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

        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <?php include '../includes/student_header.php'; ?>
            <main class="flex-1 overflow-y-auto p-4 lg:p-6">

                <?php renderFlash() ?>

                <!-- Progress Header -->
                <div class="mb-6">
                    <div class="flex items-center gap-2 mb-1">
                        <h2 class="text-xl font-bold text-slate-800">
                            <?= $LANG['nav_feedback_forms'] ?? 'Feedback Forms' ?>
                        </h2>
                    </div>
                    <p class="text-sm text-slate-500">
                        <?= $LANG['feedback_forms_desc'] ?? 'Complete all feedback forms in order. Forms are unlocked sequentially.' ?>
                    </p>
                </div>

                <?php if ($allCompleted): ?>
                    <!-- Thank You Page -->
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-8 md:p-12 text-center">
                        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-green-100 flex items-center justify-center">
                            <?= iconSvg('check', 'w-10 h-10 text-green-600') ?>
                        </div>
                        <h3 class="text-2xl font-bold text-slate-800 mb-3"><?= $LANG['all_done'] ?? 'All Done!' ?></h3>
                        <p class="text-lg text-slate-600 mb-8">
                            <?= $LANG['all_forms_completed'] ?? 'You have completed all feedback forms. Thank you!' ?>
                        </p>
                        <a href="<?= e(BASE_URL) ?>student/dashboard.php"
                            class="inline-flex items-center gap-2 px-6 py-3 bg-cyan-600 text-white font-semibold rounded-xl hover:bg-cyan-700 transition-all hover:-translate-y-0.5 shadow-md">
                            <?= iconSvg('home', 'w-5 h-5') ?>
                            <?= $LANG['return_to_dashboard'] ?? 'Return to Dashboard' ?>
                        </a>
                    </div>
                <?php endif; ?>
                <?php if (!empty($allForms)): ?>
                    <!-- Overall Progress Card -->
                    <div id="overall-progress-section" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-6">
                        <div class="flex flex-col md:flex-row items-center gap-6">
                            <!-- Progress Ring -->
                            <div class="relative flex-shrink-0">
                                <svg class="w-24 h-24 transform -rotate-90" viewBox="0 0 100 100">
                                    <circle cx="50" cy="50" r="42" fill="none" stroke="#e2e8f0" stroke-width="8" />
                                    <circle cx="50" cy="50" r="42" fill="none" stroke="#06b6d4" stroke-width="8"
                                        stroke-linecap="round" stroke-dasharray="<?= 2 * M_PI * 42 ?>"
                                        stroke-dashoffset="<?= 2 * M_PI * 42 * (1 - $progressPercent / 100) ?>"
                                        class="progress-ring" />
                                </svg>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span id="workflow-progress-percent" class="text-lg font-bold text-cyan-700"><?= $progressPercent ?>%</span>
                                </div>
                            </div>
                            <!-- Stats -->
                            <div class="flex-1 text-center md:text-left">
                                <h3 class="text-lg font-bold text-slate-800 mb-1">
                                    <?= $LANG['overall_progress'] ?? 'Overall Progress' ?>
                                </h3>
                                <p id="workflow-progress-text" class="text-sm text-slate-500 mb-3">
                                    <?= $completedCount ?>     <?= $LANG['of'] ?? 'of' ?>     <?= $progressEligibleTotal ?>
                                    <?= $LANG['forms_completed'] ?? 'forms completed' ?>
                                </p>
                                <!-- Progress Bar -->
                                <div class="w-full bg-slate-100 rounded-full h-3 mb-2">
                                    <div id="workflow-progress-bar" class="bg-gradient-to-r from-cyan-500 to-cyan-600 h-3 rounded-full transition-all duration-500 ease-out"
                                        style="width: <?= $progressPercent ?>%"></div>
                                </div>
                                <div class="flex items-center gap-4 text-xs text-slate-500">
                                    <span class="flex items-center gap-1">
                                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                        <span id="workflow-completed-count"><?= $completedCount ?></span> <?= $LANG['completed'] ?? 'Completed' ?>
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                        <span id="workflow-pending-count"><?= $pendingCount ?></span> <?= $LANG['pending'] ?? 'Pending' ?>
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <span class="w-2 h-2 rounded-full bg-slate-300"></span>
                                        <?= $totalForms ?>     <?= $LANG['total'] ?? 'Total' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Continue Feedback -->
                    <?php if ($firstUncompletedId): ?>
                        <div class="bg-gradient-to-r from-cyan-600 to-cyan-700 rounded-2xl p-5 mb-6 text-white shadow-md">
                            <div class="flex flex-col sm:flex-row items-center gap-4">
                                <div class="flex items-center gap-3 flex-1">
                                    <div
                                        class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                                        <?= iconSvg('clipboard', 'w-6 h-6 text-white') ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold opacity-90">
                                            <?= $LANG['continue_feedback'] ?? 'Continue Feedback' ?>
                                        </p>
                                        <p class="text-xs opacity-75">
                                            <?= $pendingCount ?>         <?= $LANG['forms_remaining'] ?? 'forms remaining' ?>
                                        </p>
                                    </div>
                                </div>
                                <a href="#form-list" id="continue-feedback-link"
                                    class="px-5 py-2.5 bg-white text-cyan-700 font-semibold text-sm rounded-xl hover:bg-cyan-50 transition-all shadow-sm">
                                    <?= $LANG['view_forms'] ?? 'View Forms' ?> ↓
                                </a>
                            </div>
                        </div>
                    <?php endif ?>

                    <section id="feedback-workflow" class="hidden mb-6" aria-live="polite"></section>

                    <template id="all-feedback-completed-template">
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-8 md:p-12 text-center">
                            <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-green-100 flex items-center justify-center">
                                <?= iconSvg('check', 'w-10 h-10 text-green-600') ?>
                            </div>
                            <h3 class="text-2xl font-bold text-slate-800 mb-3"><?= $LANG['all_done'] ?? 'All Done!' ?></h3>
                            <p class="text-lg text-slate-600 mb-8">
                                <?= $LANG['all_forms_completed'] ?? 'You have completed all feedback forms. Thank you!' ?>
                            </p>
                            <a href="<?= e(BASE_URL) ?>student/dashboard.php"
                                class="inline-flex items-center gap-2 px-6 py-3 bg-cyan-600 text-white font-semibold rounded-xl hover:bg-cyan-700 transition-all hover:-translate-y-0.5 shadow-md">
                                <?= iconSvg('home', 'w-5 h-5') ?>
                                <?= $LANG['return_to_dashboard'] ?? 'Return to Dashboard' ?>
                            </a>
                        </div>
                    </template>

                    <!-- Forms List -->
                    <div id="form-list" class="space-y-4">
                        <?php
                        $currentModule = '';
                        $formIndex = 0;
                        foreach ($allForms as $f):
                            $formIndex++;
                            $isSubmitted = (int) $f['submitted'] > 0;
                            $isExpired = !$isSubmitted && $isFormExpired($f);
                            $isAvailable = !$isSubmitted && $isFormCurrentlyActive($f);
                            $isFirstUncompleted = $f['id'] === $firstUncompletedId;
                            $isLocked = !$isSubmitted && !$isExpired
                                && (!$isAvailable || (!$isFirstUncompleted && $firstUncompletedId !== null));

                            // Module header
                            $moduleKey = $f['module'];
                            $moduleNames = [
                                'teaching_quality' => $LANG['academic_feedback_section'] ?? 'Teaching Quality Feedback',
                                'student_support_services' => $LANG['student_affairs_section'] ?? 'Student Support Services',
                                'learning_environment' => $LANG['administration_section'] ?? 'Learning Environment'
                            ];
                            $moduleColors = [
                                'teaching_quality' => ['bg' => 'bg-cyan-50', 'text' => 'text-cyan-800', 'border' => 'border-cyan-200', 'dot' => 'bg-cyan-500'],
                                'student_support_services' => ['bg' => 'bg-purple-50', 'text' => 'text-purple-800', 'border' => 'border-purple-200', 'dot' => 'bg-purple-500'],
                                'learning_environment' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-800', 'border' => 'border-orange-200', 'dot' => 'bg-orange-500']
                            ];
                            $moduleIcons = [
    'teaching_quality' => 'academic',
                                'student_support_services' => 'shield',
                                'learning_environment' => 'office'
                            ];

                            if ($moduleKey !== $currentModule):
                                $currentModule = $moduleKey;
                                $mc = $moduleColors[$moduleKey] ?? ['bg' => 'bg-slate-50', 'text' => 'text-slate-800', 'border' => 'border-slate-200', 'dot' => 'bg-slate-500'];
                                ?>
                                <div class="flex items-center gap-2 mt-2 mb-1">
                                    <span class="w-2 h-2 rounded-full <?= $mc['dot'] ?>"></span>
                                    <h3 class="text-sm font-bold <?= $mc['text'] ?>"><?= $moduleNames[$moduleKey] ?? $moduleKey ?>
                                    </h3>
                                    <div class="flex-1 h-px <?= $mc['border'] ?> border-t border-dashed"></div>
                                </div>
                            <?php endif ?>

                            <!-- Form Card -->
                            <div data-form-id="<?= (int) $f['id'] ?>"
                                class="form-card bg-white rounded-2xl shadow-sm border <?= $isLocked ? 'border-slate-200 locked' : ($isSubmitted ? 'border-green-200' : 'border-slate-100 hover:border-cyan-200 hover:shadow-md') ?> overflow-hidden transition-all duration-300 <?= $isFirstUncompleted ? 'ring-2 ring-cyan-400/40' : '' ?>">
                                <div class="px-5 py-4 flex items-center gap-4">
                                    <!-- Step Number -->
                                    <div
                                        class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold <?= $isSubmitted ? 'bg-green-100 text-green-700' : ($isLocked ? 'bg-slate-100 text-slate-400' : 'bg-cyan-100 text-cyan-700') ?>">
                                        <?php if ($isSubmitted): ?>
                                            <?= iconSvg('check', 'w-5 h-5') ?>
                                        <?php else: ?>
                                            <?= $formIndex ?>
                                        <?php endif ?>
                                    </div>

                                    <!-- Form Info -->
                                    <div class="flex-1 min-w-0">
                                        <p
                                            class="text-sm font-semibold <?= $isLocked ? 'text-slate-400' : 'text-slate-800' ?> truncate">
                                            <?= e($f['title']) ?>
                                        </p>
                                        <div
                                            class="flex items-center gap-2 text-xs <?= $isLocked ? 'text-slate-300' : 'text-slate-400' ?> mt-0.5">
                                            <?= iconSvg($moduleIcons[$moduleKey] ?? 'clipboard', 'w-3 h-3') ?>
                                            <?php if ($f['module'] === 'teaching_quality' && $f['course_name']): ?>
                                                <span><?= e($f['course_name']) ?> (<?= e($f['course_code']) ?>)</span>
                                                <span>·</span>
                                                <span><?= e($LANG['section_short'] ?? 'Sec') ?> <?= e($f['section_name']) ?></span>
                                            <?php else: ?>
                                                <span><?= $moduleNames[$moduleKey] ?? $moduleKey ?></span>
                                            <?php endif ?>
                                            <span>·</span>
                                            <span><?= e($LANG['due'] ?? 'Due') ?> <?= formatDateTime($f['end_date']) ?></span>
                                        </div>
                                    </div>

                                    <!-- Status / Action -->
                                    <div class="flex items-center gap-3 flex-shrink-0">
                                        <?php if ($isSubmitted): ?>
                                            <span
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded-xl">
                                                <?= iconSvg('check', 'w-3.5 h-3.5') ?>
                                                <?= $LANG['submitted_status'] ?? 'Submitted' ?>
                                            </span>
                                        <?php elseif ($isLocked): ?>
                                            <span
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-slate-400 bg-slate-50 border border-slate-200 rounded-xl">
                                                <?= iconSvg('eye', 'w-3.5 h-3.5') ?>
                                                <?= $LANG['locked'] ?? 'Locked' ?>
                                            </span>
                                        <?php elseif ($isAvailable): ?>
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">
                                                <?= $LANG['active'] ?? 'Active' ?>
                                                · <?= getTimeRemaining($f['end_date']) ?>
                                            </span>
                                            <?php if ($f['module'] === 'teaching_quality'): ?>
                                                <a href="<?= e(BASE_URL) ?>student/feedback_form.php?form_id=<?= $f['id'] ?>"
                                                    class="fill-btn js-inline-survey inline-flex items-center gap-1 px-4 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all hover:-translate-y-0.5 shadow-sm">
                                                    <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?>
                                                    <?= $LANG['fill'] ?? 'Fill' ?>
                                                </a>
                                            <?php elseif ($f['module'] === 'student_support_services'): ?>
                                                <a href="<?= e(BASE_URL) ?>student/sa_feedback_form.php?form_id=<?= $f['id'] ?>"
                                                    class="fill-btn js-inline-survey inline-flex items-center gap-1 px-4 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all hover:-translate-y-0.5 shadow-sm">
                                                    <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?>
                                                    <?= $LANG['fill'] ?? 'Fill' ?>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= e(BASE_URL) ?>student/adm_feedback_form.php?form_id=<?= $f['id'] ?>"
                                                    class="fill-btn js-inline-survey inline-flex items-center gap-1 px-4 py-2 text-xs font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-xl transition-all hover:-translate-y-0.5 shadow-sm">
                                                    <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?>
                                                    <?= $LANG['fill'] ?? 'Fill' ?>
                                                </a>
                                            <?php endif ?>
                                        <?php elseif ($isExpired): ?>
                                            <span
                                                class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-slate-500 bg-slate-100 rounded-xl">
                                                <?= $LANG['expired_status'] ?? 'Expired' ?>
                                            </span>
                                        <?php endif ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>

            </main>
        </div>
    </div>
    <script>
        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }

        // Animate progress ring on load
        document.addEventListener('DOMContentLoaded', function () {
            const ring = document.querySelector('.progress-ring');
            if (ring) {
                const target = ring.getAttribute('stroke-dashoffset');
                ring.style.strokeDashoffset = ring.getAttribute('stroke-dasharray');
                setTimeout(() => { ring.style.strokeDashoffset = target; }, 100);
            }

            const workflow = document.getElementById('feedback-workflow');
            const formList = document.getElementById('form-list');
            const overallProgress = document.getElementById('overall-progress-section');
            const completionTemplate = document.getElementById('all-feedback-completed-template');
            const continueLink = document.getElementById('continue-feedback-link');
            const continuePanel = continueLink ? continueLink.closest('.bg-gradient-to-r') : null;
            const initialInlineUrl = <?= json_encode($initialInlineUrl) ?>;
            const labels = {
                loading: <?= json_encode($LANG['loading'] ?? 'Loading...') ?>,
                loadError: <?= json_encode($LANG['failed_to_load'] ?? 'Unable to load the feedback form.') ?>,
                submitError: <?= json_encode($LANG['survey_submission_failed'] ?? 'Survey submission failed.') ?>,
                submitting: <?= json_encode($LANG['submitting'] ?? 'Submitting...') ?>,
                completed: <?= json_encode($LANG['completed'] ?? 'Completed') ?>,
                of: <?= json_encode($LANG['of'] ?? 'of') ?>,
                formsCompleted: <?= json_encode($LANG['forms_completed'] ?? 'forms completed') ?>
            };

            function inlineUrl(sourceUrl) {
                const url = new URL(sourceUrl, window.location.href);
                url.searchParams.set('embed', '1');
                return url;
            }

            function updateAddress(formId) {
                const url = new URL(window.location.href);
                if (formId) url.searchParams.set('form_id', formId);
                else url.searchParams.delete('form_id');
                url.searchParams.delete('ajax');
                window.history.replaceState({}, '', url);

                document.querySelectorAll('.student-language-link').forEach(function (link) {
                    const languageUrl = new URL(url);
                    languageUrl.searchParams.set('lang', link.dataset.language || 'en');
                    link.href = languageUrl.toString();
                });
            }

            function showWorkflowError(message) {
                workflow.classList.remove('hidden');
                workflow.setAttribute('aria-busy', 'false');
                workflow.innerHTML = '<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"></div>';
                workflow.firstElementChild.textContent = message;
                if (formList) formList.classList.remove('hidden');
                if (continuePanel) continuePanel.classList.remove('hidden');
                if (overallProgress) overallProgress.classList.remove('hidden');
            }

            function activeFormId() {
                const content = workflow.querySelector('.survey-inline-content');
                return content ? content.dataset.formId : '';
            }

            function draftKey(formId) {
                return 'sfms-survey-draft-' + formId;
            }

            function saveCurrentDraft() {
                const form = workflow.querySelector('#survey-form');
                const formId = activeFormId();
                if (!form || !formId) return;

                const answers = {};
                form.querySelectorAll('input[type="radio"]:checked').forEach(function (input) {
                    answers[input.name] = input.value;
                });
                sessionStorage.setItem(draftKey(formId), JSON.stringify(answers));
            }

            function restoreCurrentDraft(form) {
                const formId = activeFormId();
                if (!formId) return;
                try {
                    const answers = JSON.parse(sessionStorage.getItem(draftKey(formId)) || '{}');
                    Object.keys(answers).forEach(function (name) {
                        form.querySelectorAll('input[type="radio"]').forEach(function (input) {
                            if (input.name === name && input.value === String(answers[name])) input.checked = true;
                        });
                    });
                } catch (error) {
                    sessionStorage.removeItem(draftKey(formId));
                }
            }

            function bindInlineSubmission() {
                const form = workflow.querySelector('#survey-form');
                if (!form) return;

                restoreCurrentDraft(form);
                form.addEventListener('change', saveCurrentDraft);

                form.addEventListener('change', function (event) {
                    const fieldset = event.target.closest('[data-survey-question]');
                    if (!fieldset || !fieldset.querySelector('input[type="radio"]:checked')) return;
                    fieldset.classList.remove('border-red-400', 'ring-2', 'ring-red-100', 'bg-red-50');
                    fieldset.querySelector('.survey-question-error')?.classList.add('hidden');
                });

                function validateSurveyQuestions() {
                    const unanswered = [];
                    form.querySelectorAll('[data-survey-question]').forEach(function (fieldset) {
                        const answered = fieldset.querySelector('input[type="radio"]:checked');
                        const error = fieldset.querySelector('.survey-question-error');
                        fieldset.classList.remove('border-red-400', 'ring-2', 'ring-red-100', 'bg-red-50');
                        if (answered) {
                            if (error) error.classList.add('hidden');
                            return;
                        }

                        unanswered.push(fieldset);
                        fieldset.classList.add('border-red-400', 'ring-2', 'ring-red-100', 'bg-red-50');
                        if (error) error.classList.remove('hidden');
                    });

                    if (unanswered.length === 0) return true;

                    const first = unanswered[0];
                    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    window.setTimeout(function () {
                        first.querySelector('input[type="radio"]')?.focus({ preventScroll: true });
                    }, 350);
                    return false;
                }

                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    if (!validateSurveyQuestions()) return;

                    const button = form.querySelector('button[type="submit"]');
                    const originalText = button ? button.textContent : '';
                    if (button) {
                        button.disabled = true;
                        button.textContent = labels.submitting;
                        button.classList.add('opacity-60', 'cursor-not-allowed');
                    }

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const result = await response.json();
                        if (!response.ok || !result.success) {
                            throw new Error(result.message || labels.submitError);
                        }

                        sessionStorage.removeItem(draftKey(result.form_id));
                        markFormCompleted(result.form_id);
                        const stateResponse = await fetch('feedback_forms.php?ajax=workflow', {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        if (!stateResponse.ok) throw new Error(labels.loadError);
                        const state = await stateResponse.json();
                        updateProgress(state);

                        if (state.next_url) {
                            await loadInlineForm(state.next_url, true);
                        } else {
                            showAllCompleted();
                        }
                    } catch (error) {
                        const existing = form.querySelector('.inline-submit-error');
                        if (existing) existing.remove();
                        const errorBox = document.createElement('div');
                        errorBox.className = 'inline-submit-error rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700';
                        errorBox.textContent = error.message || labels.submitError;
                        form.prepend(errorBox);
                        if (button) {
                            button.disabled = false;
                            button.textContent = originalText;
                            button.classList.remove('opacity-60', 'cursor-not-allowed');
                        }
                    }
                });
            }

            async function loadInlineForm(sourceUrl, updateHistory) {
                if (formList) formList.classList.add('hidden');
                if (continuePanel) continuePanel.classList.add('hidden');
                if (overallProgress) overallProgress.classList.add('hidden');
                workflow.classList.remove('hidden');
                workflow.setAttribute('aria-busy', 'true');
                workflow.innerHTML = '<div class="bg-white rounded-2xl border border-slate-100 p-10 text-center text-slate-500 shadow-sm"></div>';
                workflow.firstElementChild.textContent = labels.loading;
                workflow.scrollIntoView({ behavior: 'smooth', block: 'start' });

                try {
                    const response = await fetch(inlineUrl(sourceUrl), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) throw new Error(labels.loadError);
                    workflow.innerHTML = await response.text();
                    workflow.setAttribute('aria-busy', 'false');
                    bindInlineSubmission();

                    const content = workflow.querySelector('.survey-inline-content');
                    if (updateHistory && content) updateAddress(content.dataset.formId);
                } catch (error) {
                    showWorkflowError(error.message || labels.loadError);
                }
            }

            function markFormCompleted(formId) {
                const card = document.querySelector('.form-card[data-form-id="' + formId + '"]');
                if (!card) return;
                card.classList.remove('locked', 'ring-2', 'ring-cyan-400/40');
                card.classList.add('border-green-200');
                const action = card.querySelector('.flex.items-center.gap-3.flex-shrink-0');
                if (action) {
                    action.innerHTML = '<span class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded-xl"></span>';
                    action.firstElementChild.textContent = labels.completed;
                }
            }

            function updateProgress(state) {
                const percent = Number(state.progress || 0);
                const percentLabel = document.getElementById('workflow-progress-percent');
                const progressBar = document.getElementById('workflow-progress-bar');
                const progressText = document.getElementById('workflow-progress-text');
                const completed = document.getElementById('workflow-completed-count');
                const pending = document.getElementById('workflow-pending-count');
                if (percentLabel) percentLabel.textContent = percent + '%';
                if (progressBar) progressBar.style.width = percent + '%';
                if (progressText) progressText.textContent = state.completed + ' ' + labels.of + ' ' + state.total + ' ' + labels.formsCompleted;
                if (completed) completed.textContent = state.completed;
                if (pending) pending.textContent = state.pending;
                if (ring) {
                    const circumference = 2 * Math.PI * 42;
                    ring.style.strokeDashoffset = circumference * (1 - percent / 100);
                }
            }

            function showAllCompleted() {
                workflow.classList.remove('hidden');
                workflow.setAttribute('aria-busy', 'false');
                workflow.replaceChildren(completionTemplate.content.cloneNode(true));
                if (formList) formList.classList.add('hidden');
                if (continuePanel) continuePanel.classList.add('hidden');
                if (overallProgress) overallProgress.classList.add('hidden');
                updateAddress(null);
                workflow.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            document.querySelectorAll('.js-inline-survey').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    loadInlineForm(link.href, true);
                });
            });

            document.querySelectorAll('.student-language-link').forEach(function (link) {
                link.addEventListener('click', saveCurrentDraft);
            });

            if (initialInlineUrl) {
                loadInlineForm(initialInlineUrl, false);
            }
        });
    </script>
</body>

</html>
