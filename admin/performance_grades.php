<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['performance_grades_title'] ?? 'Performance Grade Settings';
$activeMenu = 'performance_grades';
$gradeCodes = ['excellent', 'good', 'satisfactory', 'needs_improvement', 'unsatisfactory'];
$years = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$academicYearId = (int) ($_POST['academic_year_id'] ?? $_GET['academic_year_id'] ?? $_SESSION['performance_grades_academic_year_id'] ?? ($years[0]['id'] ?? 0));
$validYearIds = array_map(static fn($year) => (int) $year['id'], $years);
if (!in_array($academicYearId, $validYearIds, true))
    $academicYearId = (int) ($years[0]['id'] ?? 0);
if ($academicYearId)
    $_SESSION['performance_grades_academic_year_id'] = $academicYearId;
if ($academicYearId)
    ensurePerformanceGradeSettings($conn, $academicYearId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && $academicYearId) {
    $ranges = [];
    $error = '';
    foreach ($gradeCodes as $code) {
        $minRaw = $_POST['min_score'][$code] ?? '';
        $maxRaw = $_POST['max_score'][$code] ?? '';
        $recommendation = trim((string) ($_POST['recommendation'][$code] ?? ''));
        if (filter_var($minRaw, FILTER_VALIDATE_INT) === false || filter_var($maxRaw, FILTER_VALIDATE_INT) === false) {
            $error = $LANG['performance_grades_integer_error'] ?? 'All scores must be whole numbers.';
            break;
        }
        $min = (int) $minRaw;
        $max = (int) $maxRaw;
        if ($min < 0 || $max > 100 || $min > $max) {
            $error = $LANG['performance_grades_bounds_error'] ?? 'Scores must be between 0 and 100, and Min Score cannot exceed Max Score.';
            break;
        }
        if ($recommendation === '') {
            $error = $LANG['performance_grades_recommendation_required'] ?? 'A recommendation is required for every grade.';
            break;
        }
        $ranges[$code] = ['min' => $min, 'max' => $max, 'recommendation' => $recommendation];
    }
    if (!$error) {
        $ordered = array_values($ranges);
        usort($ordered, static fn($a, $b) => $a['min'] <=> $b['min']);
        if (abs($ordered[0]['min']) > 0.001 || abs($ordered[4]['max'] - 100) > 0.001) {
            $error = $LANG['performance_grades_coverage_error'] ?? 'The ranges must cover every score from 0 through 100.';
        } else {
            for ($i = 1; $i < 5; $i++)
                if ($ordered[$i]['min'] !== $ordered[$i - 1]['max'] + 1) {
                    $error = $LANG['performance_grades_contiguous_error'] ?? 'Ranges must not overlap or contain gaps.';
                    break;
                }
        }
    }
    if ($error)
        setFlash('error', $error);
    else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE performance_grade_settings SET min_score=?, max_score=?, recommendation=? WHERE academic_year_id=? AND grade_code=?");
            foreach ($ranges as $code => $range) {
                $stmt->bind_param('iisis', $range['min'], $range['max'], $range['recommendation'], $academicYearId, $code);
                if (!$stmt->execute())
                    throw new RuntimeException($stmt->error);
            }
            $stmt->close();
            $conn->commit();
            setFlash('success', $LANG['flash_performance_grade_updated'] ?? 'Performance grade settings updated successfully.');
        } catch (Throwable $e) {
            $conn->rollback();
            setFlash('error', $LANG['performance_grades_save_failed'] ?? 'Unable to save performance grade ranges.');
        }
    }
    header('Location: ' . BASE_URL . 'admin/performance_grades.php');
    exit;
}

$settings = [];
if ($academicYearId) {
    $stmt = $conn->prepare("SELECT grade_code, min_score, max_score, recommendation FROM performance_grade_settings WHERE academic_year_id=?");
    $stmt->bind_param('i', $academicYearId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)
        $settings[$row['grade_code']] = $row;
    $stmt->close();
}
include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>
<div class="mx-auto space-y-6">
    <?php renderFlash(); ?>
    <div>
        <h2 class="text-2xl font-bold text-slate-800">
            <?= e($LANG['performance_grades_title'] ?? 'Performance Grade Settings') ?>
        </h2>
        <p class="text-sm text-slate-500 mt-1">
            <?= e($LANG['performance_grades_help'] ?? 'Configure score ranges independently for each Academic Year.') ?>
        </p>
    </div>
    <form method="get" action="<?= e(BASE_URL . 'admin/performance_grades.php') ?>"
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5"><label
            class="block text-sm font-semibold text-slate-700 mb-2"><?= e($LANG['academic_year'] ?? 'Academic Year') ?></label><select
            name="academic_year_id" onchange="this.form.submit()"
            class="w-full md:w-80 rounded-xl border border-slate-300 px-4 py-2.5 bg-white"><?php foreach ($years as $year): ?>
                <option value="<?= (int) $year['id'] ?>" <?= $academicYearId === (int) $year['id'] ? 'selected' : '' ?>>
                    <?= e($year['year_name']) ?>
                </option><?php endforeach; ?>
        </select></form>
    <?php if ($academicYearId): ?>
        <form method="post" action="<?= e(BASE_URL . 'admin/performance_grades.php') ?>"
            class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden"><?= csrfField() ?><input
                type="hidden" name="academic_year_id" value="<?= $academicYearId ?>">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 text-sm text-slate-600">
                        <tr>
                            <th class="text-left px-6 py-4"><?= e($LANG['grade_name'] ?? 'Grade') ?></th>
                            <th class="text-left px-6 py-4"><?= e($LANG['min_score'] ?? 'Min Score') ?></th>
                            <th class="w-8 px-0 py-4" aria-hidden="true"></th>
                            <th class="text-left px-6 py-4"><?= e($LANG['max_score'] ?? 'Max Score') ?></th>
                            <th class="text-left px-6 py-4"><?= e($LANG['recommendation_label'] ?? 'Recommendation') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($gradeCodes as $code):
                            $row = $settings[$code] ?? ['min_score' => '', 'max_score' => '', 'recommendation' => '']; ?>
                            <tr>
                                <td class="px-6 py-4 font-semibold text-slate-800">
                                    <?= e($LANG['performance_grade_' . $code] ?? ucwords(str_replace('_', ' ', $code))) ?>
                                </td>
                                <td class="px-6 py-4">
                                    <input required type="number" min="0" max="100" step="1" name="min_score[<?= e($code) ?>]"
                                        value="<?= (int) $row['min_score'] ?>"
                                        class="w-32 rounded-lg border border-slate-300 px-3 py-2">
                                </td>
                                <td class="w-8 px-0 py-4 text-lg font-semibold text-slate-400"
                                    aria-hidden="true">–</td>
                                <td class="px-6 py-4"><input required type="number" min="0" max="100" step="1"
                                        name="max_score[<?= e($code) ?>]" value="<?= (int) $row['max_score'] ?>"
                                        class="w-32 rounded-lg border border-slate-300 px-3 py-2"></td>
                                <td class="px-6 py-4"><textarea required rows="3" name="recommendation[<?= e($code) ?>]"
                                        class="min-w-80 w-full rounded-lg border border-slate-300 px-3 py-2"><?= e($row['recommendation']) ?></textarea>
                                </td>
                            </tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-5 bg-slate-50 flex justify-end"><button
                    class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold"><?= e($LANG['save_changes'] ?? 'Save Changes') ?></button>
            </div>
        </form><?php else: ?>
        <div class="bg-amber-50 text-amber-800 rounded-xl p-4">
            <?= e($LANG['performance_grades_no_years'] ?? 'Create an Academic Year first.') ?>
        </div><?php endif; ?>
</div>
<?php include '../includes/admin_footer.php'; ?>
