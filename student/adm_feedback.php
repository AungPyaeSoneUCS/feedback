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

$pageTitle = $LANG['adm_feedback_page_title'] ?? 'Learning Environment Feedback';
$activeMenu = 'adm';
$today = date('Y-m-d');

$forms = [];
if ($studentId && !empty($studentYearIds) && !empty($studentSemIds)) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'feedback_forms'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $yrPH = implode(',', array_fill(0, count($studentYearIds), '?'));
        $yrBT = str_repeat('i', count($studentYearIds));
        $smPH = implode(',', array_fill(0, count($studentSemIds), '?'));
        $smBT = str_repeat('i', count($studentSemIds));
        $rs = $conn->prepare("
            SELECT f.*, (SELECT COUNT(*) FROM feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
            FROM feedback_forms f
            WHERE f.module='learning_environment' AND f.academic_year_id IN ($yrPH) AND f.semester_id IN ($smPH)
            ORDER BY f.end_date ASC, f.id DESC
        ");
        $rs->bind_param('i' . $yrBT . $smBT, ...array_merge([$studentId], $studentYearIds, $studentSemIds));
        $rs->execute();
        $forms = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
        $rs->close();
    }
}

$navItems = [
    ['label' => $LANG['nav_dashboard'] ?? 'Dashboard', 'href' => BASE_URL . 'student/dashboard.php', 'key' => 'dashboard', 'icon' => 'home', 'iconColor' => 'text-yellow-300'],
    ['label' => $LANG['nav_history'] ?? 'Submission History', 'href' => BASE_URL . 'student/feedback_history.php', 'key' => 'history', 'icon' => 'history', 'iconColor' => 'text-teal-300'],
    ['label' => $LANG['nav_profile'] ?? 'Profile', 'href' => BASE_URL . 'student/profile.php', 'key' => 'profile', 'icon' => 'user', 'iconColor' => 'text-rose-300'],
];
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
</head>

<body class="h-full bg-slate-50 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">
        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <!-- <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                    <?= iconSvg('academic', 'w-5 h-5 text-white') ?>
                </div> -->
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
                    <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH Logo"
                        class="w-full h-full object-contain rounded-xl">
                </div>
                <div>
                    <p class="text-lg font-bold"><?= $LANG['student_portal'] ?? 'SFIS Student' ?></p>
                    <p class="text-[10px] text-cyan-100"><?= $LANG['student_portal_sub'] ?? 'Student Portal' ?></p>
                </div>
                <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100 hover:text-white"><svg
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg></button>
            </div>
            <nav class="flex-1 py-4 px-3 space-y-0.5">
                <?php foreach ($navItems as $n):
                    $a = $activeMenu === $n['key']; ?>
                    <a href="<?= $n['href'] ?>"
                        class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm <?= $a ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white' ?>">
                        <?= iconSvg($n['icon'], 'w-5 h-5 ' . ($n['iconColor'] ?? 'text-white/80')) ?>     <?= e($n['label']) ?>
                        <?php if ($a): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
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
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <?php include '../includes/student_header.php'; ?>
            <main class="flex-1 overflow-y-auto p-4 lg:p-6">

                <div class="mb-6">
                    <div class="flex items-center gap-2 mb-1">
                        <!-- <?= iconSvg('office', 'w-5 h-5 text-orange-600') ?>
                        <h2 class="text-xl font-bold text-slate-800">
                            <?= $LANG['adm_feedback_page_title'] ?? 'Learning Environment Feedback' ?>
                        </h2> -->
                    </div>
                    <p class="text-sm text-slate-500">
                        <?= $LANG['adm_feedback_subtitle'] ?? 'Rate and review the learning environment' ?>
                    </p>
                </div>
                <?php renderFlash() ?>

                <?php if ($forms): ?>
                    <div class="space-y-4">
                        <?php foreach ($forms as $f):
                            $fStatus = $f['status'];
                            $isInRange = $fStatus === 'Active';
                            $submitted = (bool) $f['submitted'];
                            $canSubmit = $isInRange && !$submitted;
                            $expired = $fStatus === 'Expired';
                            $upcoming = $fStatus === 'Upcoming';
                            ?>
                            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                                <div class="px-6 py-5 flex items-center justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <?= iconSvg('office', 'w-4 h-4 text-orange-500') ?>
                                            <p class="text-sm font-semibold text-slate-800"><?= e($f['title']) ?></p>
                                        </div>
                                        <p class="text-xs text-slate-400 ml-6"><?= formatDateTime($f['start_date']) ?> —
                                            <?= formatDateTime($f['end_date']) ?>
                                        </p>
                                        <p class="text-xs mt-1 ml-6"><?= badgeStatus($fStatus) ?>
                                            <?php if ($fStatus === 'Active'): ?><span
                                                    class="text-slate-500"><?= getTimeRemaining($f['end_date']) ?></span><?php elseif ($fStatus === 'Upcoming'): ?><span
                                                    class="text-slate-500"><?= getTimeUntilStart($f['start_date']) ?></span><?php endif ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-3 flex-shrink-0">
                                        <?php if ($submitted): ?>
                                            <span
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded-xl"><?= iconSvg('check', 'w-3.5 h-3.5') ?>
                                                <?= $LANG['submitted_status'] ?? 'Submitted' ?></span>
                                        <?php elseif ($canSubmit): ?>
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700"><?= $LANG['active'] ?? 'Active' ?>
                                                · <?= getTimeRemaining($f['end_date']) ?></span>
                                            <a href="adm_feedback_form.php?form_id=<?= $f['id'] ?>"
                                                class="inline-flex items-center gap-1 px-4 py-2 text-xs font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-xl transition-all hover:-translate-y-0.5">
                                                <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?>             <?= $LANG['fill_form'] ?? 'Fill Form' ?>
                                            </a>
                                        <?php elseif ($expired): ?>
                                            <span
                                                class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-slate-500 bg-slate-100 rounded-xl"><?= $LANG['expired_status'] ?? 'Expired' ?></span>
                                        <?php elseif ($upcoming): ?>
                                            <span class="text-xs text-blue-500 font-medium"><?= $LANG['starts_label'] ?? 'Opens' ?>
                                                <?= formatDateTimeShort($f['start_date']) ?></span>
                                        <?php endif ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 text-center py-16 text-slate-400">
                        <?= iconSvg('office', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                        <p class="text-sm font-medium text-slate-600">
                            <?= $LANG['no_adm_forms_available'] ?? 'No Learning Environment forms available right now.' ?>
                        </p>
                    </div>
                <?php endif ?>

            </main>
        </div>
    </div>
    <script>
        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }
    </script>
</body>

</html>
