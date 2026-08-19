<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';


requireRole('teacher');

$user      = getCurrentUser();
$stmt      = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i',$user['id']); $stmt->execute();
$teacher   = $stmt->get_result()->fetch_assoc(); $stmt->close();
$teacherId = $teacher['id'] ?? 0;

$pageTitle  = $LANG['nav_my_sections'] ?? 'My Courses';
$activeMenu = 'sections';

$search  = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 10)));
$page    = max(1,(int)($_GET['page'] ?? 1));

if ($teacherId) {
    if ($search) {
        $s2="%$search%";
        $c=$conn->prepare("SELECT COUNT(*) AS c FROM sections s JOIN courses c2 ON s.course_id=c2.id LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE s.teacher_id=? AND (c2.course_name LIKE ? OR sm_sec.section_name LIKE ? OR COALESCE(ay.year_name, '') LIKE ? OR sm.semester_name LIKE ?)");
        $c->bind_param('issss',$teacherId,$s2,$s2,$s2,$s2); $c->execute();
        $total=(int)$c->get_result()->fetch_assoc()['c']; $c->close();
    } else {
        $c=$conn->prepare("SELECT COUNT(*) AS c FROM sections WHERE teacher_id=?");
        $c->bind_param('i',$teacherId); $c->execute();
        $total=(int)$c->get_result()->fetch_assoc()['c']; $c->close();
    }
} else { $total = 0; }

$pg=paginate($total,$perPage,$page); $off=$pg['offset'];

if ($teacherId) {
    if ($search) {
        $s2="%$search%";
        $stmt=$conn->prepare("SELECT s.*, c2.course_name, c2.course_code, sm_sec.section_name AS section_name, COALESCE(ay.year_name, '') AS display_year, sm.semester_name AS display_semester, (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id=s.id) AS student_count, (SELECT COUNT(*) FROM feedback_forms ff WHERE ff.section_id=s.id) AS form_count FROM sections s JOIN courses c2 ON s.course_id=c2.id LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE s.teacher_id=? AND (c2.course_name LIKE ? OR sm_sec.section_name LIKE ? OR COALESCE(ay.year_name, '') LIKE ? OR sm.semester_name LIKE ?) ORDER BY s.id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('issssii',$teacherId,$s2,$s2,$s2,$s2,$perPage,$off);
    } else {
        $stmt=$conn->prepare("SELECT s.*, c2.course_name, c2.course_code, sm_sec.section_name AS section_name, COALESCE(ay.year_name, '') AS display_year, sm.semester_name AS display_semester, (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id=s.id) AS student_count, (SELECT COUNT(*) FROM feedback_forms ff WHERE ff.section_id=s.id) AS form_count FROM sections s JOIN courses c2 ON s.course_id=c2.id LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE s.teacher_id=? ORDER BY s.id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('iii',$teacherId,$perPage,$off);
    }
    $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
} else { $rows = []; }

?>
<!DOCTYPE html><html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($pageTitle) ?> — SFIS</title><script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif']}}}}</script><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css"></head>
<body class="h-full bg-gradient-to-br from-slate-50 via-blue-50 to-sky-50 font-inter antialiased <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
<?php require_once '../includes/teacher_sidebar.php'; ?>

<div class="mb-6"><p class="text-sm text-slate-500"><?= $LANG['my_sections_subtitle'] ?? 'Your assigned course sections' ?></p></div>

<div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 overflow-hidden">
    <div class="px-5 py-4 border-b border-blue-100/50 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs"><span class="absolute inset-y-0 left-0 flex items-center pl-3 text-blue-400"><?= iconSvg('search','w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="<?= $LANG['search'] ?? 'Search' ?>..." class="w-full pl-9 pr-4 py-2 text-sm border border-blue-200/50 rounded-xl focus:border-blue-400 focus:ring-2 focus:ring-blue-400/20 outline-none bg-white/80">
            </div>
            <button class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-colors"><?= $LANG['search'] ?? 'Search' ?></button>
            <?php if($search): ?><a href="my_sections.php" class="px-3 py-2 text-sm border border-blue-200/50 rounded-xl text-blue-600 hover:bg-blue-50/50 transition-colors"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <span class="text-xs text-blue-400"><?= $total ?> section<?= $total!==1?'s':'' ?></span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-blue-200 border-b border-blue-200/50"><tr>
                <th class="text-left px-5 py-3 text-blue-500 text-sm font-semibold uppercase tracking-wider">#</th>
                <th class="text-left px-5 py-3 text-blue-500 text-sm font-semibold uppercase tracking-wider"><?= $LANG['col_course'] ?? 'Course' ?></th>
                <th class="text-left px-5 py-3 text-blue-500 text-sm font-semibold uppercase tracking-wider"><?= $LANG['col_section'] ?? 'Section' ?></th>
                <th class="text-left px-5 py-3 text-blue-500 text-sm font-semibold uppercase tracking-wider"><?= $LANG['year_semester'] ?? 'Year / Semester' ?></th>
                <th class="text-left px-5 py-3 text-blue-500 text-sm font-semibold uppercase tracking-wider"><?= $LANG['students_label'] ?? 'Students' ?></th>
                <th class="text-left px-5 py-3 text-blue-500 text-sm font-semibold uppercase tracking-wider"><?= $LANG['forms'] ?? 'Forms' ?></th>
                <th class="text-right px-5 py-3 text-blue-500 text-sm font-semibold uppercase tracking-wider"><?= $LANG['col_actions'] ?? 'Actions' ?></th>
            </tr></thead>
            <tbody class="divide-y divide-blue-100/40">
            <?php if($rows): foreach($rows as $i => $row): ?>
                <tr class="hover:bg-blue-50/30 transition-colors">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset']+$i+1 ?></td>
                    <td class="px-5 py-3"><p class="text-sm font-medium text-slate-800"><?= e($row['course_name']) ?></p><p class="text-xs text-slate-400 font-mono"><?= e($row['course_code']) ?></p></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-800"><?= e($row['section_name']) ?></span></td>
                    <td class="px-5 py-3 text-sm text-slate-500"><?= e($row['display_year']) ?><br><span class="text-xs text-slate-400"><?= e(semesterToRoman($row['display_semester'])) ?></span></td>
                    <td class="px-10 py-3 "><span class="text-sm font-bold text-slate-700"><?= $row['student_count'] ?></span></td>
                    <td class="px-10 py-3 "><span class="text-sm font-bold text-emerald-600"><?= $row['form_count'] ?></span></td>
                    <td class="px-5 py-3 text-right">
                        <a href="<?= e(BASE_URL) ?>teacher/feedback_results.php?section_id=<?= $row['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors">
                            <?= iconSvg('chart','w-3.5 h-3.5') ?> <?= $LANG['results_link'] ?? 'Results' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center py-16 text-slate-400"><?= iconSvg('grid','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No sections assigned.</p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-blue-100/50"><?= paginationLinks($pg,'my_sections.php'.($search?'?search='.urlencode($search):''), $perPage) ?></div>
</div>

<?php require_once '../includes/teacher_footer.php'; ?>
