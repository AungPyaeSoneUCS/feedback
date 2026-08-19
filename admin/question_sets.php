<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['question_sets_title'] ?? 'Question Sets';
$activeMenu = 'question_sets';

$academicYears = $conn->query("SELECT id, year_name FROM academic_years WHERE status='active' ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$allAcademicYears = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);

function moduleQuestionsPage($module)
{
    return 'manage_questions.php';
}

function cloneSurveyArchitecture(mysqli $conn, int $sourceSetId, int $targetSetId): int
{
    $groups = $conn->prepare("SELECT sg.* FROM survey_groups sg WHERE sg.question_set_id=? AND EXISTS (SELECT 1 FROM feedback_questions fq WHERE fq.question_set_id=sg.question_set_id AND fq.survey_group_id=sg.id) ORDER BY sg.id");
    $groups->bind_param('i', $sourceSetId); $groups->execute();
    $sourceGroups = $groups->get_result()->fetch_all(MYSQLI_ASSOC); $groups->close();
    $groupMap = []; $copied = 0;
    $insertGroup = $conn->prepare("INSERT INTO survey_groups(question_set_id,group_code,group_name_en,group_name_mm,instruction_en,instruction_mm) VALUES(?,?,?,?,?,?)");
    $insertQuestion = $conn->prepare("INSERT INTO feedback_questions(question_set_id,survey_group_id,question_code,question_text_en,question_text_mm) VALUES(?,?,?,?,?)");
    foreach ($sourceGroups as $group) {
        $insertGroup->bind_param('isssss', $targetSetId, $group['group_code'], $group['group_name_en'], $group['group_name_mm'], $group['instruction_en'], $group['instruction_mm']);
        if (!$insertGroup->execute()) throw new RuntimeException($insertGroup->error);
        $groupMap[(int)$group['id']] = (int)$conn->insert_id;
    }
    $rows = getSurveyQuestionsForSet($conn, $sourceSetId);
    foreach ($rows as $q) {
        $newGroupId=$groupMap[(int)$q['survey_group_id']];
        $insertQuestion->bind_param('iisss',$targetSetId,$newGroupId,$q['question_code'],$q['question_text_en'],$q['question_text_mm']);
        if (!$insertQuestion->execute()) throw new RuntimeException($insertQuestion->error);
        $copied++;
    }
    $insertGroup->close(); $insertQuestion->close(); return $copied;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $ayId = (int) ($_POST['academic_year_id'] ?? 0);
        $module = in_array($_POST['module'] ?? '', ['teaching_quality', 'student_support_services', 'learning_environment']) ? $_POST['module'] : 'teaching_quality';
        $title = clean($_POST['title'] ?? '');

        if (!$ayId || !$title) {
            setFlash('error', $LANG['flash_admin_all_fields_are_required'] ?? 'All fields are required.');
            header('Location: question_sets.php');
            exit;
        }

        $chk = $conn->prepare("SELECT 1 FROM feedback_question_sets WHERE academic_year_id=? AND module=?");
        $chk->bind_param('is', $ayId, $module);
        $chk->execute();
        $dup = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($dup) {
            setFlash('error', $LANG['flash_admin_question_set_already_exists_for_this'] ?? 'Question Set already exists for this Academic Year and Module.');
            header('Location: question_sets.php');
            exit;
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO feedback_question_sets (academic_year_id, module, title, status) VALUES (?,?,?,'active')");
            $stmt->bind_param('iss', $ayId, $module, $title);
            if (!$stmt->execute()) {
                throw new Exception("Insert failed: " . $stmt->error . " (errno: " . $stmt->errno . ")");
            }
            $newSetId = $conn->insert_id;
            $stmt->close();

            if (!$newSetId) {
                throw new Exception("No insert_id returned. Connection error: " . $conn->error);
            }

            $prevSet = $conn->prepare(
                "SELECT fqs.id FROM feedback_question_sets fqs
                 JOIN academic_years ay ON fqs.academic_year_id = ay.id
                 JOIN academic_years cur ON cur.id = ?
                 WHERE fqs.module = ? AND ay.year_name < cur.year_name
                 ORDER BY ay.year_name DESC LIMIT 1"
            );
            $prevSet->bind_param('is', $ayId, $module);
            $prevSet->execute();
            $prevRow = $prevSet->get_result()->fetch_assoc();
            $prevSet->close();

            $copied = 0;

            if ($prevRow) {
                $prevSetId = (int) $prevRow['id'];

                if ($prevSetId === $newSetId) {
                    throw new Exception("Source and target Question Set are the same.");
                }

                $chkDup = $conn->prepare("SELECT COUNT(*) AS cnt FROM feedback_questions WHERE question_set_id = ?");
                $chkDup->bind_param('i', $newSetId);
                $chkDup->execute();
                $existingCount = (int) $chkDup->get_result()->fetch_assoc()['cnt'];
                $chkDup->close();

                if ($existingCount > 0) {
                    throw new Exception("Questions already exist for this new Question Set (possible double submission).");
                }

                $copied = cloneSurveyArchitecture($conn, $prevSetId, $newSetId);
            }

            $conn->commit();
            if ($copied > 0) {
                setFlash('success', str_replace(':copied', $copied, $LANG['flash_admin_question_set_copied'] ?? "Question Set created with :copied questions copied from previous year."));
            } else {
                setFlash('success', $LANG['flash_admin_question_set_created_no_previous_year'] ?? 'Question Set created. No previous year data found — add questions manually.');
            }
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('error', str_replace(':msg', $e->getMessage(), $LANG['flash_admin_question_set_failed'] ?? 'Failed to create Question Set: :msg'));
        }

        header('Location: question_sets.php');
        exit;
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = clean($_POST['title'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (!$id || !$title) {
            setFlash('error', $LANG['flash_admin_all_fields_required'] ?? 'All fields required.');
            header('Location: question_sets.php');
            exit;
        }

        $stmt = $conn->prepare("UPDATE feedback_question_sets SET title=?, status=? WHERE id=?");
        $stmt->bind_param('ssi', $title, $status, $id);
        $stmt->execute() ? setFlash('success', $LANG['flash_admin_question_set_updated'] ?? 'Question Set updated.') : setFlash('error', $LANG['flash_admin_update_failed'] ?? 'Update failed.');
        $stmt->close();
        header('Location: question_sets.php');
        exit;
    }

    if ($action === 'clone') {
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $targetAyId = (int) ($_POST['target_academic_year_id'] ?? 0);
        $targetModule = in_array($_POST['target_module'] ?? '', ['teaching_quality', 'student_support_services', 'learning_environment']) ? $_POST['target_module'] : 'teaching_quality';
        $targetTitle = clean($_POST['target_title'] ?? '');

        if (!$sourceId || !$targetAyId || !$targetTitle) {
            setFlash('error', $LANG['flash_admin_all_fields_are_required'] ?? 'All fields are required.');
            header('Location: question_sets.php');
            exit;
        }

        $srcRow = $conn->query("SELECT id, title, module FROM feedback_question_sets WHERE id=" . (int) $sourceId)->fetch_assoc();
        if (!$srcRow) {
            setFlash('error', $LANG['flash_admin_source_question_set_not_found'] ?? 'Source Question Set not found.');
            header('Location: question_sets.php');
            exit;
        }

        $chk = $conn->prepare("SELECT 1 FROM feedback_question_sets WHERE academic_year_id=? AND module=?");
        $chk->bind_param('is', $targetAyId, $targetModule);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($exists) {
            setFlash('error', $LANG['flash_admin_question_set_already_exists_for_this'] ?? 'Question Set already exists for this Academic Year and Module.');
            header('Location: question_sets.php');
            exit;
        }

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("INSERT INTO feedback_question_sets (academic_year_id, module, title, status) VALUES (?,?,?,'active')");
            $ins->bind_param('iss', $targetAyId, $targetModule, $targetTitle);
            if (!$ins->execute()) {
                throw new Exception("Insert failed: " . $ins->error . " (errno: " . $ins->errno . ")");
            }
            $newSetId = $conn->insert_id;
            $ins->close();

            if (!$newSetId) {
                throw new Exception("No insert_id returned. Connection error: " . $conn->error);
            }

            $copied = cloneSurveyArchitecture($conn, $sourceId, $newSetId);

            $conn->commit();
            setFlash('success', str_replace(':copied', $copied, $LANG['flash_admin_question_set_cloned'] ?? 'Question Set cloned successfully with :copied questions.'));
            header('Location: question_sets.php');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('error', str_replace(':msg', $e->getMessage(), $LANG['flash_admin_clone_failed'] ?? 'Clone failed: :msg'));
            header('Location: question_sets.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $chk = $conn->prepare("SELECT 1 FROM feedback_forms WHERE question_set_id=? LIMIT 1");
            $chk->bind_param('i', $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_admin_cannot_delete_this_question_set_is'] ?? 'Cannot delete: this Question Set is used by feedback forms.');
            } else {
                $conn->begin_transaction();
                try {
                    $delQ = $conn->prepare("DELETE FROM feedback_questions WHERE question_set_id=?");
                    $delQ->bind_param('i', $id);
                    $delQ->execute();
                    $delQ->close();

                    $delS = $conn->prepare("DELETE FROM feedback_question_sets WHERE id=?");
                    $delS->bind_param('i', $id);
                    $delS->execute();
                    $delS->close();

                    $conn->commit();
                    setFlash('success', $LANG['flash_admin_question_set_deleted'] ?? 'Question Set deleted.');
                } catch (Exception $e) {
                    $conn->rollback();
                    setFlash('error', $LANG['flash_admin_cannot_delete'] ?? 'Cannot delete.');
                }
            }
            $chk->close();
        }
        header('Location: question_sets.php');
        exit;
    }

    header('Location: question_sets.php');
    exit;
}

$filterAy = (int) ($_GET['academic_year_id'] ?? 0);
$filterMod = clean($_GET['module'] ?? '');

$conds = [];
$params = '';
$types = '';
if ($filterAy) {
    $conds[] = "fqs.academic_year_id=?";
    $params .= 'i';
    $types .= 'i';
}
if ($filterMod) {
    $conds[] = "fqs.module=?";
    $params .= 's';
    $types .= 's';
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sql = "SELECT fqs.*, ay.year_name,
    (SELECT COUNT(*) FROM feedback_questions WHERE question_set_id = fqs.id) AS question_count,
    (SELECT COUNT(*) FROM feedback_forms WHERE question_set_id = fqs.id) AS form_count
    FROM feedback_question_sets fqs
    JOIN academic_years ay ON fqs.academic_year_id = ay.id
    $where
    ORDER BY ay.year_name DESC, fqs.module ASC";

if ($types) {
    $bindValues = [];
    if ($filterAy)
        $bindValues[] = $filterAy;
    if ($filterMod)
        $bindValues[] = $filterMod;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$bindValues);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$total = count($rows);
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pg = paginate($total, $perPage, $page);
$pagedRows = array_slice($rows, $pg['offset'], $perPage);

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <!-- <div class="flex items-center gap-2 mb-1">
            <?= iconSvg('question', 'w-5 h-5 text-indigo-600') ?>
            <h2 class="text-xl font-bold text-slate-800"><?= $LANG['question_sets_title'] ?? 'Question Sets' ?></h2>
        </div> -->
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG["question_sets_subtitle"] ?? "One question set per Academic Year and Module — shared across all semesters." ?>
        </p>
    </div>
    <div class="flex gap-2">
        <!-- <button onclick="openModal('cloneModal')" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
            <?= iconSvg('copy', 'w-4 h-4') ?> Clone Previous Year
        </button> -->
        <button onclick="openModal('addModal')"
            class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
            <?= iconSvg('plus', 'w-4 h-4') ?><?= $LANG["create_question_set"] ?? "Create Question Set" ?></button>
    </div>
</div>
<?php renderFlash() ?>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[160px]">
            <label
                class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?></label>
            <select name="academic_year_id" onchange="this.form.submit()"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                <option value=""><?= $LANG["all_years"] ?? "All Years" ?></option>
                <?php foreach ($allAcademicYears as $ay): ?>
                    <option value="<?= $ay['id'] ?>" <?= $filterAy == $ay['id'] ? 'selected' : '' ?>><?= e($ay['year_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG["module"] ?? "Module" ?></label>
            <select name="module" onchange="this.form.submit()"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                <option value=""><?= $LANG["all_modules"] ?? "All Modules" ?></option>
                <option value="teaching_quality" <?= $filterMod === 'teaching_quality' ? 'selected' : '' ?>>
                    <?= $LANG["teaching_quality"] ?? "Teaching Quality" ?></option>
                <option value="student_support_services" <?= $filterMod === 'student_support_services' ? 'selected' : '' ?>>
                    <?= $LANG["student_support_services"] ?? "Student Support Services" ?></option>
                <option value="learning_environment" <?= $filterMod === 'learning_environment' ? 'selected' : '' ?>>
                    <?= $LANG["learning_environment"] ?? "Learning Environment" ?></option>
            </select>
        </div>
        <div class="flex gap-2">
            <?php if ($filterAy || $filterMod): ?>
                <a href="question_sets.php"
                    class="px-4 py-2 bg-red-600 text-white hover:bg-red-700 text-sm font-semibold rounded-xl"><?= $LANG["reset"] ?? "Reset" ?></a>
            <?php endif ?>
        </div>
    </form>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-end">
        <span class="text-xs text-slate-400"><?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["title"] ?? "Title" ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["academic_year"] ?? "Academic Year" ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["module"] ?? "Module" ?></th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["questions"] ?? "Questions" ?></th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["forms"] ?? "Forms" ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["status"] ?? "Status" ?></th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["col_actions"] ?? "Actions" ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($pagedRows):
                    foreach ($pagedRows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-800"><?= e($row['title']) ?></td>
                            <td class="px-5 py-3"><span
                                    class="text-sm font-bold text-indigo-700"><?= e($row['year_name']) ?></span></td>
                            <td class="px-5 py-3"><?= moduleBadge($row['module']) ?></td>
                            <?php $qPage = moduleQuestionsPage($row['module']); ?>
                            <td class="px-5 py-3 text-center">
                                <a href="<?= $qPage ?>?set_id=<?= $row['id'] ?>"
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-cyan-100 text-cyan-700 hover:bg-cyan-200">
                                    <?= $row['question_count'] ?>        <?= $LANG["questions"] ?? "Questions" ?></a>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700"><?= $row['form_count'] ?></span>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($row['status'] === 'active'): ?>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><?= $LANG["active"] ?? "Active" ?></span>
                                <?php else: ?>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600"><?= $LANG["inactive"] ?? "Inactive" ?></span>
                                <?php endif ?>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <a href="<?= moduleQuestionsPage($row['module']) ?>?set_id=<?= $row['id'] ?>"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-purple-700 bg-purple-50 hover:bg-purple-100 rounded-lg">
                                        <?= iconSvg('question', 'w-3.5 h-3.5') ?>        <?= $LANG["view"] ?? "View" ?></a>
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>        <?= $LANG["edit"] ?? "Edit" ?></button>
                                    <button onclick="openDelete(<?= $row['id'] ?>, '<?= e(addslashes($row['title'])) ?>')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>        <?= $LANG["delete"] ?? "Delete" ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="8" class="text-center py-16 text-slate-400">
                            <?= iconSvg('question', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm">
                                <?= $LANG["no_question_sets_found"] ?? "No question sets found. Create one." ?>
                            </p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?php $qs = http_build_query(array_filter(['academic_year_id' => $filterAy, 'module' => $filterMod])); ?>
        <?= paginationLinks($pg, 'question_sets.php' . ($qs ? "?$qs" : ''), $perPage) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["create_question_set"] ?? "Create Question Set" ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["title"] ?? "Title" ?> <span
                            class="text-red-500">*</span></label>
                    <input type="text" name="title" required placeholder="e.g. Academic Questions - 2027-2028"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?>
                            <span class="text-red-500">*</span></label>
                        <select name="academic_year_id" required
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none bg-white">
                            <option value=""><?= $LANG["select"] ?? "Select" ?></option>
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?= $ay['id'] ?>"><?= e($ay['year_name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["module"] ?? "Module" ?>
                            <span class="text-red-500">*</span></label>
                        <select name="module" required
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none bg-white">
                            <option value="teaching_quality"><?= $LANG["teaching_quality"] ?? "Teaching Quality" ?></option>
                            <option value="student_support_services"><?= $LANG["student_support_services"] ?? "Student Support Services" ?>
                            </option>
                            <option value="learning_environment"><?= $LANG["learning_environment"] ?? "Learning Environment" ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG["create"] ?? "Create" ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Clone Modal -->
<div id="cloneModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">
                <?= $LANG["clone_question_set_from_previous"] ?? "Clone Question Set from Previous Year" ?></h3>
            <button onclick="closeModal('cloneModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="clone">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["source_question_set"] ?? "Source Question Set" ?>
                        <span class="text-red-500">*</span></label>
                    <select name="source_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none bg-white">
                        <option value="">
                            <?= $LANG["select_source_question_set"] ?? "Select source question set to clone from" ?>
                        </option>
                        <?php foreach ($rows as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= e($r['year_name']) ?> — <?= moduleBadge($r['module']) ?> —
                                <?= e($r['title']) ?> (<?= $r['question_count'] ?> Q)</option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="border-t border-slate-200 pt-4">
                    <p class="text-sm font-semibold text-slate-700 mb-3">
                        <?= $LANG["target_new_academic_year"] ?? "Target (New Academic Year)" ?></p>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["title"] ?? "Title" ?>
                            <span class="text-red-500">*</span></label>
                        <input type="text" name="target_title" required
                            placeholder="e.g. Academic Questions - 2028-2029"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-4">
                        <div>
                            <label
                                class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?>
                                <span class="text-red-500">*</span></label>
                            <select name="target_academic_year_id" required
                                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none bg-white">
                                <option value=""><?= $LANG["select"] ?? "Select" ?></option>
                                <?php foreach ($academicYears as $ay): ?>
                                    <option value="<?= $ay['id'] ?>"><?= e($ay['year_name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <div>
                            <label
                                class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["module"] ?? "Module" ?>
                                <span class="text-red-500">*</span></label>
                            <select name="target_module" required
                                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none bg-white">
                                <option value="teaching_quality"><?= $LANG["teaching_quality"] ?? "Teaching Quality" ?></option>
                                <option value="student_support_services"><?= $LANG["student_support_services"] ?? "Student Support Services" ?>
                                </option>
                                <option value="learning_environment"><?= $LANG["learning_environment"] ?? "Learning Environment" ?>
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="bg-purple-50 border border-purple-200 rounded-xl p-3 text-xs text-purple-700">
                    <strong>Note:</strong> All questions from the source set will be copied. The new set is independent
                    — edits to it will NOT affect the source.
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('cloneModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG["clone_question_set"] ?? "Clone Question Set" ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["edit_question_set"] ?? "Edit Question Set" ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden"
                name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["title"] ?? "Title" ?></label>
                    <input type="text" name="title" id="edit_title" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                </div>
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["module"] ?? "Module" ?></label>
                    <input type="text" id="edit_module_display" readonly disabled
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm bg-slate-50 text-slate-500 cursor-not-allowed">
                    <p class="text-xs text-slate-400 mt-1">
                        <?= $LANG["module_cannot_be_changed"] ?? "Module cannot be changed after creation." ?></p>
                </div>
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["status"] ?? "Status" ?></label>
                    <select name="status" id="edit_status"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none bg-white">
                        <option value="active"><?= $LANG["active"] ?? "Active" ?></option>
                        <option value="inactive"><?= $LANG["inactive"] ?? "Inactive" ?></option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG["save"] ?? "Save" ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?></div>
            <h3 class="text-lg font-semibold text-slate-800">
                <?= $LANG["delete_question_set"] ?? "Delete Question Set" ?></h3>
            <p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>?</p>
            <p class="text-xs text-red-500 mt-2">
                <?= $LANG["cannot_delete_question_set"] ?? "Cannot delete if used by feedback forms. All questions in this set will also be deleted." ?>
            </p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden"
                name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG["delete"] ?? "Delete" ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    var moduleLabels = {
        'teaching_quality': <?= json_encode($LANG['teaching_quality'] ?? 'Teaching Quality') ?>,
        'student_support_services': <?= json_encode($LANG['student_support_services'] ?? 'Student Support Services') ?>,
        'learning_environment': <?= json_encode($LANG['learning_environment'] ?? 'Learning Environment') ?>
    };
    function openEdit(row) {
        document.getElementById('edit_id').value = row.id;
        document.getElementById('edit_title').value = row.title;
        document.getElementById('edit_status').value = row.status;
        document.getElementById('edit_module_display').value = moduleLabels[row.module] || row.module;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }

    // Double-submit prevention: disable submit buttons after first click
    document.querySelectorAll('#addModal form, #cloneModal form, #editModal form, #deleteModal form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = this.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.dataset.originalText = btn.textContent;
                btn.textContent = 'Processing...';
            }
        });
    });
</script>
<?php include '../includes/admin_footer.php'; ?>
