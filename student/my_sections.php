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
$pageTitle = $LANG['my_sections_page_title'] ?? 'My Courses';
$activeMenu = 'sections';
$today = date('Y-m-d');

// Load sections and their forms
$sections = [];
if ($studentId) {
    $rs = $conn->prepare("SELECT s.*, c.course_name, c.course_code, sm_sec.section_name AS section_name, u.name AS teacher_name, COALESCE(ay.year_name, '') AS display_year, sm.semester_name AS display_semester FROM section_assignments sa JOIN sections s ON sa.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE sa.student_id=? ORDER BY s.id DESC");
    $rs->bind_param('i', $studentId);
    $rs->execute();
    $sections = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    $rs->close();
}

// Fixed navigation array: Includes structural modules so items do not disappear
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
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { inter: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
</head>

<body class="h-full bg-slate-50 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">

        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <!-- <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center font-bold text-white">S
                </div> -->
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
                    <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH Logo"
                        class="w-full h-full object-contain rounded-xl">
                </div>
                <div>
                    <p class="text-lg font-bold">
                        <?= $LANG['student_portal'] ?? 'SFIS Student' ?>
                    </p>
                    <p class="text-[10px] text-cyan-100">
                        <?= $LANG['student_portal_sub'] ?? 'Student Portal' ?>
                    </p>
                </div>
                <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 py-4 px-3 space-y-0.5 overflow-y-auto scrollbar-thin">
                <?php foreach ($navItems as $n):
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
                    <!-- <h2 class="text-xl font-bold text-slate-800">
                        <?= $LANG['my_sections_page_title'] ?? 'My Courses & Feedback Forms' ?>
                    </h2> -->
                    <p class="text-sm text-slate-500">
                        <?= $LANG['my_sections_subtitle'] ?? 'Your enrolled courses and available feedback forms' ?>
                    </p>
                </div>

                <?php renderFlash() ?>

                <?php if ($sections):
                    foreach ($sections as $sec):
                        // Get forms for this section
                        $fStmt = $conn->prepare("SELECT ff.id, ff.title, ff.status, ff.start_date, ff.end_date, (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.form_id=ff.id AND fs.student_id=?) AS submitted FROM feedback_forms ff WHERE ff.section_id=? ORDER BY ff.id DESC");
                        $fStmt->bind_param('ii', $studentId, $sec['id']);
                        $fStmt->execute();
                        $forms = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $fStmt->close();
                        ?>
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden mb-4">
                            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-800"><?= e($sec['course_name']) ?> <span
                                            class="font-mono text-xs text-slate-400">(<?= e($sec['course_code']) ?>)</span></p>
                                    <p class="text-xs text-slate-400 mt-0.5"><?= $LANG['section_label'] ?? 'Section' ?>
                                        <?= e($sec['section_name']) ?> ·
                                        <?= e($sec['display_year']) ?> · <?= e(semesterToRoman($sec['display_semester'])) ?> ·
                                        <?= $LANG['taught_by'] ?? 'Taught by' ?>
                                        <?= e($sec['teacher_name']) ?>
                                    </p>
                                </div>
                            </div>
                            <?php if ($forms): ?>
                                <div class="divide-y divide-slate-100">
                                    <?php foreach ($forms as $f):
                                        $fStatus = $f['status'];
                                        $isActive = $fStatus === 'Active';
                                        $submitted = (bool) $f['submitted'];
                                        $expired = $fStatus === 'Expired';
                                        $upcoming = $fStatus === 'Upcoming';
                                        ?>
                                        <div class="px-6 py-4 flex items-center justify-between">
                                            <div>
                                                <p class="text-sm font-medium text-slate-800"><?= e($f['title']) ?></p>
                                                <p class="text-xs text-slate-400"><?= formatDateTime($f['start_date']) ?> —
                                                    <?= formatDateTime($f['end_date']) ?>
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <?php if ($submitted): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded-xl">
                                                        <?= iconSvg('check', 'w-3.5 h-3.5') ?>
                                                        <?= $LANG['submitted_status'] ?? 'Submitted' ?>
                                                    </span>
                                                <?php elseif ($isActive): ?>
                                                    <span
                                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700"><?= $LANG['active'] ?? 'Active' ?>
                                                        · <?= getTimeRemaining($f['end_date']) ?></span>
                                                    <a href="<?= e(BASE_URL) ?>student/feedback_form.php?form_id=<?= $f['id'] ?>"
                                                        class="inline-flex items-center gap-1 px-4 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all hover:-translate-y-0.5">
                                                        <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?>                     <?= $LANG['fill_form'] ?? 'Fill Form' ?>
                                                    </a>
                                                <?php elseif ($expired): ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-slate-500 bg-slate-100 rounded-xl"><?= $LANG['expired_status'] ?? 'Expired' ?></span>
                                                <?php elseif ($upcoming): ?>
                                                    <span class="text-xs text-blue-500 font-medium"><?= $LANG['starts_label'] ?? 'Opens' ?>
                                                        <?= formatDateTimeShort($f['start_date']) ?></span>
                                                <?php else: ?>
                                                    <?= badgeStatus($fStatus) ?>
                                                <?php endif ?>
                                            </div>
                                        </div>
                                    <?php endforeach ?>
                                </div>
                            <?php else: ?>
                                <div class="px-6 py-6 text-sm text-slate-400">
                                    <?= $LANG['no_forms_for_section'] ?? 'No feedback forms for this section.' ?>
                                </div>
                            <?php endif ?>
                        </div>
                    <?php endforeach; else: ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 text-center py-16 text-slate-400">
                        <?= iconSvg('grid', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                        <p class="text-sm"><?= $LANG['not_enrolled_any'] ?? 'You are not enrolled in any sections yet.' ?>
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
