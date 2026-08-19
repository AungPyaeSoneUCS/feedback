<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';


requireRole('teacher');

updateAllFeedbackStatuses($conn);

$pageTitle = $LANG['teacher_dashboard_title'] ?? 'Teacher Dashboard';
$activeMenu = 'dashboard';
$user = getCurrentUser();

// Get teacher record
$stmt = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
$teacherId = $teacher['id'] ?? 0;

$sectionCount = $teacherId ? (int) $conn->query("SELECT COUNT(*) AS c FROM sections WHERE teacher_id=$teacherId")->fetch_assoc()['c'] : 0;
$formCount = $teacherId ? (int) $conn->query("SELECT COUNT(*) AS c FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id WHERE s.teacher_id=$teacherId AND ff.start_date<=NOW() AND ff.end_date>=NOW()")->fetch_assoc()['c'] : 0;
$submissionCount = $teacherId ? (int) $conn->query("SELECT COUNT(*) AS c FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id JOIN sections s ON ff.section_id=s.id WHERE s.teacher_id=$teacherId")->fetch_assoc()['c'] : 0;

// My sections
$sections = [];
if ($teacherId) {
    $rs = $conn->query("SELECT s.*, c.course_name, c.course_code, sm_sec.section_name AS section_name, COALESCE(ay.year_name, '') AS display_year, sm.semester_name AS display_semester, (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id=s.id) AS student_count, (SELECT COUNT(*) FROM feedback_forms ff WHERE ff.section_id=s.id AND ff.start_date<=NOW() AND ff.end_date>=NOW()) AS active_forms FROM sections s JOIN courses c ON s.course_id=c.id LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE s.teacher_id=$teacherId ORDER BY s.id DESC LIMIT 5");
    $sections = $rs->fetch_all(MYSQLI_ASSOC);
}

// Teacher header/sidebar will use the same includes but with teacher_ variables
// We reuse admin includes — just change the sidebar nav
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — SFIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
</head>
<body class="h-full bg-gradient-to-br from-slate-50 via-blue-50 to-sky-50 font-inter antialiased <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
<?php require_once '../includes/teacher_sidebar.php'; ?>

                <!-- Content -->
                <div class="mb-5 sm:mb-6">
                    <h2 class="text-xl sm:text-2xl font-bold text-slate-800 break-words"><?= $LANG['teacher_welcome'] ?? 'Welcome to' ?>, <?= e($user['name']) ?> 👋
                    </h2>
                    <p class="text-sm text-slate-500 mt-1"><?= $LANG['teacher_overview'] ?? "Here's your teaching overview." ?></p>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-5 mb-6 sm:mb-8">
                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-4 sm:p-5 flex items-center gap-3 sm:gap-4 min-w-0">
                        <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-blue-700 flex items-center justify-center shadow flex-shrink-0">
                            <?= iconSvg('grid', 'w-6 h-6 text-white') ?></div>
                        <div class="min-w-0">
                            <p class="text-2xl font-bold text-blue-800"><?= $sectionCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['my_sections_stat'] ?? 'My Sections' ?></p>
                        </div>
                    </div>
                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-emerald-100/50 p-4 sm:p-5 flex items-center gap-3 sm:gap-4 min-w-0">
                        <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-indigo-600 flex items-center justify-center shadow flex-shrink-0">
                            <?= iconSvg('document', 'w-6 h-6 text-white') ?></div>
                        <div class="min-w-0">
                            <p class="text-2xl font-bold text-emerald-700"><?= $formCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['active_forms_stat'] ?? 'Active Forms' ?></p>
                        </div>
                    </div>
                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-4 sm:p-5 flex items-center gap-3 sm:gap-4 min-w-0">
                        <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-blue-700 flex items-center justify-center shadow flex-shrink-0">
                            <?= iconSvg('check', 'w-6 h-6 text-white') ?></div>
                        <div class="min-w-0">
                            <p class="text-2xl font-bold text-blue-800"><?= $submissionCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['total_submissions_stat'] ?? 'Total Submissions' ?></p>
                        </div>
                    </div>
                </div>

                <!-- My Sections Preview -->
                <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 overflow-hidden">
                    <div class="flex items-center justify-between gap-3 px-4 sm:px-6 py-4 border-b border-blue-100/50">
                        <h3 class="text-base font-semibold text-slate-800"><?= $LANG['my_sections_title'] ?? 'My Sections' ?></h3>
                        <a href="<?= e(BASE_URL) ?>teacher/my_sections.php"
                            class="text-xs text-blue-600 hover:underline font-medium"><?= $LANG['view_all'] ?? 'View All' ?> →</a>
                    </div>
                    <?php if ($sections): ?>
                        <div class="divide-y divide-blue-100/50">
                            <?php foreach ($sections as $s): ?>
                                <div class="px-4 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 break-words"><?= e($s['course_name']) ?></p>
                                        <p class="text-xs text-slate-400 break-words"><?= e(semesterToRoman($s['display_semester'])) ?> · Sec <?= e($s['section_name']) ?>
                                            · <?= e($s['display_year']) ?></p>
                                    </div>
                                    <div class="grid grid-cols-3 items-center gap-2 sm:gap-4 w-full sm:w-auto text-center sm:text-right border-t border-blue-100/60 sm:border-0 pt-3 sm:pt-0">
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold text-slate-700"><?= $s['student_count'] ?></p>
                                            <p class="text-xs text-slate-400"><?= $LANG['students_label'] ?? 'Students' ?></p>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold text-emerald-600"><?= $s['active_forms'] ?></p>
                                            <p class="text-xs text-slate-400"><?= $LANG['forms'] ?? 'Forms' ?></p>
                                        </div>
                                        <a href="<?= e(BASE_URL) ?>teacher/feedback_results.php?section_id=<?= $s['id'] ?>"
                                            class="inline-flex items-center justify-center text-xs text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-lg px-3 py-2 font-medium transition-colors"><?= $LANG['results_link'] ?? 'Results' ?></a>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-slate-400">
                            <?= iconSvg('grid', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_sections_assigned'] ?? 'No sections assigned yet.' ?></p>
                        </div>
                    <?php endif ?>
                </div>

<?php require_once '../includes/teacher_footer.php'; ?>
