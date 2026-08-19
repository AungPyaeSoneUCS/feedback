<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$setId = filter_var($_REQUEST['set_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$setId) {
    header('Location: question_sets.php');
    exit;
}
$stmt = $conn->prepare("SELECT fqs.*, COALESCE(ay.year_name,'') year_name FROM feedback_question_sets fqs LEFT JOIN academic_years ay ON ay.id=fqs.academic_year_id WHERE fqs.id=?");
$stmt->bind_param('i', $setId);
$stmt->execute();
$questionSet = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$questionSet) {
    setFlash('error', $LANG['flash_admin_question_set_not_found'] ?? 'Question Set not found.');
    header('Location: question_sets.php');
    exit;
}

// ─── Sync Guard: determine whether this question set can be modified ───
$syncCheck = canSyncQuestionSet($conn, $setId);
$readOnly = !$syncCheck['allowed'];


function surveyAdminText(string $key, string $fallback): string
{
    global $LANG;
    return $LANG[$key] ?? $fallback;
}
function nextQuestionCode(mysqli $conn, int $groupId, int $setId): string
{
    $group = $conn->prepare("SELECT group_code FROM survey_groups WHERE id=? AND question_set_id=? FOR UPDATE");
    $group->bind_param('ii', $groupId, $setId);
    $group->execute();
    $row = $group->get_result()->fetch_assoc();
    $group->close();
    if (!$row)
        throw new RuntimeException(surveyAdminText('invalid_survey_group', 'Invalid Survey Group.'));
    $prefix = strtoupper(trim($row['group_code']));
    $codes = $conn->prepare("SELECT question_code FROM feedback_questions WHERE survey_group_id=?");
    $codes->bind_param('i', $groupId);
    $codes->execute();
    $result = $codes->get_result();
    $highestNumber = 0;
    while ($codeRow = $result->fetch_assoc()) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '([1-9][0-9]*)$/i', $codeRow['question_code'], $match))
            $highestNumber = max($highestNumber, (int) $match[1]);
    }
    $codes->close();
    return $prefix . ($highestNumber + 1);
}

function renumberQuestionCodes(mysqli $conn, int $groupId, int $setId): void
{
    $group = $conn->prepare("SELECT group_code FROM survey_groups WHERE id=? AND question_set_id=? FOR UPDATE");
    $group->bind_param('ii', $groupId, $setId);
    $group->execute();
    $row = $group->get_result()->fetch_assoc();
    $group->close();
    if (!$row)
        throw new RuntimeException(surveyAdminText('invalid_survey_group', 'Invalid Survey Group.'));

    $prefix = strtoupper(trim($row['group_code']));
    $questions = $conn->prepare("SELECT id FROM feedback_questions WHERE survey_group_id=? AND question_set_id=? ORDER BY LENGTH(question_code), question_code, id FOR UPDATE");
    $questions->bind_param('ii', $groupId, $setId);
    $questions->execute();
    $questionRows = $questions->get_result()->fetch_all(MYSQLI_ASSOC);
    $questions->close();

    $update = $conn->prepare("UPDATE feedback_questions SET question_code=? WHERE id=? AND survey_group_id=? AND question_set_id=?");
    foreach ($questionRows as $index => $questionRow) {
        $questionCode = $prefix . ($index + 1);
        $questionId = (int) $questionRow['id'];
        $update->bind_param('siii', $questionCode, $questionId, $groupId, $setId);
        $update->execute();
    }
    $update->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', surveyAdminText('invalid_request_token', 'Invalid request token.'));
    } else {
        $action = $_POST['action'] ?? '';

        // ─── Block modifications when form is Active/Expired or has submissions ───
        $syncCheck = canSyncQuestionSet($conn, $setId);
        if (!$syncCheck['allowed']) {
            $msg = ($syncCheck['reason'] === 'has_submissions')
                ? ($LANG['sync_blocked_has_submissions'] ?? 'Cannot modify questions: a form using this Question Set already has student submissions.')
                : ($LANG['sync_blocked_form_active'] ?? 'Cannot modify questions: a form using this Question Set is currently Active or Expired.');
            setFlash('error', $msg);
            header("Location: manage_questions.php?set_id=$setId");
            exit;
        }

        $questionTransaction = false;
        $groupTransaction = false;
        try {
            if (in_array($action, ['add_group', 'edit_group'], true)) {
                $id = (int) ($_POST['id'] ?? 0);
                $code = strtoupper(clean($_POST['group_code'] ?? ''));
                $en = clean($_POST['group_name_en'] ?? '');
                $mm = clean($_POST['group_name_mm'] ?? '');
                $ien = clean($_POST['instruction_en'] ?? '');
                $imm = clean($_POST['instruction_mm'] ?? '');
                if ($code === '' || $en === '' || $mm === '')
                    throw new RuntimeException(surveyAdminText('survey_group_required', 'Code and both group names are required.'));
                if (!preg_match('/[A-Za-z]/', $en))
                    throw new RuntimeException(surveyAdminText('group_name_english_letter_required', 'The English Group Name must contain at least one English letter (A–Z).'));
                if (!preg_match('/[\x{1000}-\x{109F}\x{A9E0}-\x{A9FF}\x{AA60}-\x{AA7F}]/u', $mm))
                    throw new RuntimeException(surveyAdminText('group_name_myanmar_character_required', 'The Myanmar Group Name must contain at least one Myanmar Unicode character.'));
                if ($action === 'add_group') {
                    $s = $conn->prepare("INSERT INTO survey_groups(question_set_id,group_code,group_name_en,group_name_mm,instruction_en,instruction_mm) VALUES(?,?,?,?,?,?)");
                    $s->bind_param('isssss', $setId, $code, $en, $mm, $ien, $imm);
                } else {
                    $conn->begin_transaction();
                    $groupTransaction = true;

                    $currentGroup = $conn->prepare("SELECT group_code FROM survey_groups WHERE id=? AND question_set_id=? FOR UPDATE");
                    $currentGroup->bind_param('ii', $id, $setId);
                    $currentGroup->execute();
                    $currentGroupRow = $currentGroup->get_result()->fetch_assoc();
                    $currentGroup->close();
                    if (!$currentGroupRow)
                        throw new RuntimeException(surveyAdminText('invalid_survey_group', 'Invalid Survey Group.'));

                    $oldCode = strtoupper(trim($currentGroupRow['group_code']));
                    $s = $conn->prepare("UPDATE survey_groups SET group_code=?,group_name_en=?,group_name_mm=?,instruction_en=?,instruction_mm=? WHERE id=? AND question_set_id=?");
                    $s->bind_param('sssssii', $code, $en, $mm, $ien, $imm, $id, $setId);
                }
                $s->execute();
                $s->close();

                if ($action === 'edit_group') {
                    if ($oldCode !== $code) {
                        $questionRows = $conn->prepare("SELECT id, question_code FROM feedback_questions WHERE survey_group_id=? AND question_set_id=? FOR UPDATE");
                        $questionRows->bind_param('ii', $id, $setId);
                        $questionRows->execute();
                        $groupQuestions = $questionRows->get_result()->fetch_all(MYSQLI_ASSOC);
                        $questionRows->close();

                        $updateCode = $conn->prepare("UPDATE feedback_questions SET question_code=? WHERE id=? AND survey_group_id=? AND question_set_id=?");
                        foreach ($groupQuestions as $groupQuestion) {
                            $currentQuestionCode = strtoupper(trim($groupQuestion['question_code']));
                            if (!str_starts_with($currentQuestionCode, $oldCode))
                                throw new RuntimeException(surveyAdminText('invalid_question_code', 'A question has an invalid Question Code.'));
                            $numericSuffix = substr($currentQuestionCode, strlen($oldCode));
                            if ($numericSuffix === '' || !ctype_digit($numericSuffix))
                                throw new RuntimeException(surveyAdminText('invalid_question_code', 'A question has an invalid Question Code.'));
                            $newQuestionCode = $code . $numericSuffix;
                            $questionId = (int) $groupQuestion['id'];
                            $updateCode->bind_param('siii', $newQuestionCode, $questionId, $id, $setId);
                            $updateCode->execute();
                        }
                        $updateCode->close();
                    }
                    $conn->commit();
                    $groupTransaction = false;
                }
                setFlash('success', surveyAdminText('survey_group_saved', 'Survey Group saved.'));
            } elseif ($action === 'delete_group') {
                $id = (int) ($_POST['id'] ?? 0);
                $s = $conn->prepare("DELETE FROM survey_groups WHERE id=? AND question_set_id=?");
                $s->bind_param('ii', $id, $setId);
                $s->execute();
                $s->close();
                setFlash('success', surveyAdminText('survey_group_deleted', 'Survey Group deleted.'));
            } elseif (in_array($action, ['add_question', 'edit_question'], true)) {
                $id = (int) ($_POST['id'] ?? 0);
                $groupId = (int) ($_POST['survey_group_id'] ?? 0);
                $en = clean($_POST['question_text_en'] ?? '');
                $mm = clean($_POST['question_text_mm'] ?? '');
                $own = $conn->prepare("SELECT id FROM survey_groups WHERE id=? AND question_set_id=?");
                $own->bind_param('ii', $groupId, $setId);
                $own->execute();
                $valid = $own->get_result()->num_rows === 1;
                $own->close();
                if (!$valid || $en === '' || $mm === '')
                    throw new RuntimeException(surveyAdminText('survey_question_required', 'Group and both question texts are required.'));
                if (!preg_match('/[A-Za-z]/', $en))
                    throw new RuntimeException(surveyAdminText('question_english_letter_required', 'The English question must contain at least one English letter (A–Z).'));
                if (!preg_match('/[\x{1000}-\x{109F}\x{A9E0}-\x{A9FF}\x{AA60}-\x{AA7F}]/u', $mm))
                    throw new RuntimeException(surveyAdminText('question_myanmar_character_required', 'The Myanmar question must contain at least one Myanmar Unicode character.'));
                $conn->begin_transaction();
                $questionTransaction = true;
                if ($action === 'add_question') {
                    $code = nextQuestionCode($conn, $groupId, $setId);
                    $s = $conn->prepare("INSERT INTO feedback_questions(question_set_id,survey_group_id,question_code,question_text_en,question_text_mm) VALUES(?,?,?,?,?)");
                    $s->bind_param('iisss', $setId, $groupId, $code, $en, $mm);
                } else {
                    $current = $conn->prepare("SELECT survey_group_id,question_code FROM feedback_questions WHERE id=? AND question_set_id=? FOR UPDATE");
                    $current->bind_param('ii', $id, $setId);
                    $current->execute();
                    $currentRow = $current->get_result()->fetch_assoc();
                    $current->close();
                    if (!$currentRow)
                        throw new RuntimeException(surveyAdminText('survey_question_not_found', 'Survey Question not found.'));
                    $code = (int) $currentRow['survey_group_id'] === $groupId ? $currentRow['question_code'] : nextQuestionCode($conn, $groupId, $setId);
                    $s = $conn->prepare("UPDATE feedback_questions SET survey_group_id=?,question_code=?,question_text_en=?,question_text_mm=? WHERE id=? AND question_set_id=?");
                    $s->bind_param('isssii', $groupId, $code, $en, $mm, $id, $setId);
                }
                $s->execute();
                $s->close();
                $conn->commit();
                $questionTransaction = false;
                setFlash('success', surveyAdminText('survey_question_saved', 'Survey Question saved.'));
            } elseif ($action === 'delete_question') {
                $id = (int) ($_POST['id'] ?? 0);

                $conn->begin_transaction();
                $questionTransaction = true;

                $current = $conn->prepare("SELECT survey_group_id FROM feedback_questions WHERE id=? AND question_set_id=? FOR UPDATE");
                $current->bind_param('ii', $id, $setId);
                $current->execute();
                $currentRow = $current->get_result()->fetch_assoc();
                $current->close();
                if (!$currentRow)
                    throw new RuntimeException(surveyAdminText('survey_question_not_found', 'Survey Question not found.'));

                $groupId = (int) $currentRow['survey_group_id'];
                $s = $conn->prepare("DELETE FROM feedback_questions WHERE id=? AND question_set_id=?");
                $s->bind_param('ii', $id, $setId);
                $s->execute();
                $s->close();

                renumberQuestionCodes($conn, $groupId, $setId);
                $conn->commit();
                $questionTransaction = false;
                setFlash('success', surveyAdminText('survey_question_deleted', 'Survey Question deleted.'));
            }
        } catch (Throwable $e) {
            if ($questionTransaction)
                $conn->rollback();
            if ($groupTransaction)
                $conn->rollback();
            $dbMessage = in_array($action, ['add_question', 'edit_question'], true) ? surveyAdminText('duplicate_question_code', 'Unable to generate a unique Question Code.') : surveyAdminText('duplicate_group_code', 'Group Code is already in use.');
            setFlash('error', $e instanceof mysqli_sql_exception ? $dbMessage : $e->getMessage());
        }
    }
    header("Location: manage_questions.php?set_id=$setId");
    exit;
}

$s = $conn->prepare("SELECT * FROM survey_groups WHERE question_set_id=? ORDER BY id");
$s->bind_param('i', $setId);
$s->execute();
$groups = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();
$s = $conn->prepare("SELECT fq.* FROM feedback_questions fq WHERE fq.question_set_id=? ORDER BY fq.survey_group_id,LENGTH(fq.question_code),fq.question_code,fq.id");
$s->bind_param('i', $setId);
$s->execute();
$questions = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();
$byGroup = [];
foreach ($questions as $q)
    $byGroup[$q['survey_group_id']][] = $q;
$pageTitle = surveyAdminText('survey_architecture', 'Survey Groups & Questions');
include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 md:p-8">
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-wrap justify-between gap-4 mb-6">
            <div><a href="question_sets.php" class="font-bold text-indigo-600">←
                    <?= e(surveyAdminText('question_sets', 'Question Sets')) ?></a>
                <h1 class="text-2xl font-bold mt-2"><?= e($questionSet['title']) ?></h1>
                <p class="text-slate-500"><?= e($questionSet['year_name']) ?> ·
                    <?= moduleBadge($questionSet['module']) ?>
                </p>
            </div>
            <?php if (!$readOnly): ?><button onclick="modal('groupModal')"
                    class="inline-flex items-center self-end px-4 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold"><?= iconSvg('plus', 'w-4 h-4') ?>
                    <?= e(surveyAdminText('add_survey_group', 'Add Survey Group')) ?></button><?php endif; ?>
        </div>
        <?php renderFlash(); ?>
        <?php if ($readOnly): ?>
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 flex items-start gap-3">
                <svg class="w-6 h-6 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
                <div>
                    <p class="font-semibold text-amber-800">
                        <?= e($LANG['sync_locked_title'] ?? 'Read-Only — Questions Locked') ?></p>
                    <p class="text-sm text-amber-700 mt-1">
                        <?= e($syncCheck['reason'] === 'has_submissions' ? ($LANG['sync_locked_desc_submissions'] ?? 'A feedback form using this Question Set already has student submissions. Questions cannot be modified.') : ($LANG['sync_locked_desc_active'] ?? 'A feedback form using this Question Set is currently Active or Expired. Questions cannot be modified.')) ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
        <div class="space-y-6">
            <?php foreach ($groups as $g): ?>
                <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                    <header class="p-5 bg-slate-50 flex flex-wrap justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2"><span
                                    class="font-mono text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded"><?= e($g['group_code']) ?></span>
                                <h2 class="font-bold text-lg"><?= e($g['group_name_en']) ?> / <?= e($g['group_name_mm']) ?>
                                </h2>
                            </div>
                            <p class="text-sm text-slate-500 mt-2">
                                <?= e($g['instruction_en']) ?><br><?= e($g['instruction_mm']) ?>
                            </p>

                        </div>
                        <?php if (!$readOnly): ?>
                            <div class="flex items-center justify-end gap-2"><button type="button"
                                    data-row='<?= e(json_encode($g, JSON_UNESCAPED_UNICODE)) ?>' onclick="editGroup(this)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg transition-colors"><?= iconSvg('edit', 'w-3.5 h-3.5') ?>
                                    <?= e($LANG['edit'] ?? 'Edit') ?></button><button type="button"
                                    onclick="deleteItem('delete_group',<?= (int) $g['id'] ?>)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors"><?= iconSvg('trash', 'w-3.5 h-3.5') ?>
                                    <?= e($LANG['delete'] ?? 'Delete') ?></button></div><?php endif; ?>
                    </header>
                    <div class="divide-y"><?php foreach ($byGroup[$g['id']] ?? [] as $q): ?>
                            <div class="p-5 flex justify-between gap-4">
                                <!-- <div><span class="font-mono font-bold text-violet-600"><?= e($q['question_code']) ?></span>
                                    <span class="mt-1"><?= e($q['question_text_en']) ?></span>
                                    <p class="text-slate-500"><?= e($q['question_text_mm']) ?></p>
                                </div> -->
                                <div class="flex items-start gap-2">
                                    <!-- Question Code -->
                                    <span class="font-mono font-bold text-violet-600 shrink-0">
                                        <?= e($q['question_code']) ?>
                                    </span>

                                    <!-- Question Texts -->
                                    <div class="flex flex-col gap-1">
                                        <span class="text-slate-900">
                                            <?= e($q['question_text_en']) ?>
                                        </span>
                                        <p class="text-slate-500">
                                            <?= e($q['question_text_mm']) ?>
                                        </p>
                                    </div>
                                </div>

                                <?php if (!$readOnly): ?>
                                    <div class="shrink-0 flex items-center justify-end gap-2"><button type="button"
                                            data-row='<?= e(json_encode($q, JSON_UNESCAPED_UNICODE)) ?>'
                                            onclick="editQuestion(this)"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg transition-colors"><?= iconSvg('edit', 'w-3.5 h-3.5') ?>
                                            <?= e($LANG['edit'] ?? 'Edit') ?></button><button type="button"
                                            onclick="deleteItem('delete_question',<?= (int) $q['id'] ?>)"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors"><?= iconSvg('trash', 'w-3.5 h-3.5') ?>
                                            <?= e($LANG['delete'] ?? 'Delete') ?></button></div><?php endif; ?>
                            </div><?php endforeach; ?>
                        <?php if (empty($byGroup[$g['id']])): ?>
                            <p class="p-5 text-slate-400">
                                <?= e(surveyAdminText('no_questions_in_group', 'No questions in this group.')) ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!$readOnly): ?>
                            <div class="flex justify-end"><button onclick="addQuestion(<?= (int) $g['id'] ?>)"
                                    class="inline-flex items-center m-5 px-4 py-2 rounded-lg bg-violet-600 text-white "><?= iconSvg('plus', 'w-4 h-4') ?><?= e(surveyAdminText('add_survey_question', 'Add Survey Question')) ?></button>
                            </div><?php endif; ?>
                </section><?php endforeach; ?>


            <?php if (!$groups): ?>
                <div class="bg-white border rounded-2xl p-10 text-center text-slate-500">
                    <?= e(surveyAdminText('no_survey_groups', 'Create a Survey Group before adding questions.')) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php function field(string $label, string $name, string $id, bool $area = false): void
{
    if ($name === 'question_code')
        return;
    $labels = [
        'group_name_en' => 'Group Name (English)',
        'group_name_mm' => 'Group Name (Myanmar)',
        'instruction_en' => 'Instruction (English)',
        'instruction_mm' => 'Instruction (Myanmar)',
        'question_text_en' => 'Question (English)',
        'question_text_mm' => 'Question (Myanmar)',
    ];
    $label = $labels[$name] ?? $label;
    $wrapperClass = $name === 'group_code' ? 'md:col-span-2' : '';
    $rows = str_starts_with($name, 'question_text_') ? 4 : 3; ?>
    <div class="<?= $wrapperClass ?>"><label for="<?= $id ?>"
            class="block text-sm font-medium mb-1"><?= e($label) ?></label><?php if ($area): ?><textarea required
                name="<?= $name ?>" id="<?= $id ?>" rows="<?= $rows ?>"
                class="w-full border rounded-xl px-3 py-2 resize-y"></textarea><?php else: ?><input required name="<?= $name ?>"
                id="<?= $id ?>" class="w-full border rounded-xl px-3 py-2"><?php endif; ?></div><?php } ?>
<div id="groupModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <form method="post" class="bg-white rounded-2xl p-6 w-full max-w-2xl space-y-5"><?= csrfField() ?><input
            type="hidden" name="set_id" value="<?= $setId ?>"><input type="hidden" name="action" id="g_action"
            value="add_group"><input type="hidden" name="id" id="g_id">
        <h2 class="text-xl font-bold"><?= e(surveyAdminText('survey_group', 'Survey Group')) ?></h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php field(surveyAdminText('group_code', 'Group Code'), 'group_code', 'g_code');
            field('English name', 'group_name_en', 'g_en');
            field('မြန်မာအမည်', 'group_name_mm', 'g_mm');
            field('English instruction', 'instruction_en', 'g_ien', true);
            field('မြန်မာညွှန်ကြားချက်', 'instruction_mm', 'g_imm', true); ?>
        </div>
        <div class="flex justify-end gap-2"><button type="button" onclick="hide('groupModal')"
                class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= e($LANG['cancel'] ?? 'Cancel') ?></button><button
                class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= e($LANG['save'] ?? 'Save') ?></button>
        </div>
    </form>
</div>
<div id="questionModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <form method="post" class="bg-white rounded-2xl p-6 w-full max-w-2xl space-y-5"><?= csrfField() ?><input
            type="hidden" name="set_id" value="<?= $setId ?>"><input type="hidden" name="action" id="q_action"
            value="add_question"><input type="hidden" name="id" id="q_id">
        <h2 class="text-xl font-bold"><?= e(surveyAdminText('survey_question', 'Survey Question')) ?></h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2"><label
                    class="block text-sm font-medium mb-1"><?= e(surveyAdminText('survey_group', 'Survey Group')) ?></label><select
                    required name="survey_group_id" id="q_group"
                    class="w-full border rounded-xl px-3 py-2"><?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= e($g['group_code'] . ' — ' . $g['group_name_en']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php field('English question', 'question_text_en', 'q_en', true);
            field('မြန်မာမေးခွန်း', 'question_text_mm', 'q_mm', true); ?>
        </div>
        <!-- <p class="text-xs text-slate-500">
            <?= e(surveyAdminText('fixed_likert_notice', 'Options are fixed: Strongly Agree, Agree, Neutral, Disagree, Strongly Disagree.')) ?>
        </p> -->
        <div class="flex justify-end gap-2"><button type="button" onclick="hide('questionModal')"
                class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= e($LANG['cancel'] ?? 'Cancel') ?></button><button
                class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= e($LANG['save'] ?? 'Save') ?></button>
        </div>
    </form>
</div>
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?>
            </div>
            <h3 id="delete_title" class="text-lg font-semibold text-slate-800">
                <?= e(surveyAdminText('delete_survey_question', 'Delete Survey Question')) ?>
            </h3>
            <p id="delete_message" class="text-sm text-slate-500 mt-2">
                <?= e(surveyAdminText('confirm_delete_question', 'Are you sure you want to delete this question?')) ?>
            </p>
        </div>
        <form method="post" id="deleteForm"><?= csrfField() ?><input type="hidden" name="set_id"
                value="<?= $setId ?>"><input type="hidden" name="action" id="d_action"><input type="hidden" name="id"
                id="d_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="hide('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= e($LANG['cancel'] ?? 'Cancel') ?></button>
                <button type="submit"
                    class="flex-1 inline-flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl transition-colors"><?= e($LANG['delete'] ?? 'Delete') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
    function modal(id) { let m = document.getElementById(id); m.classList.remove('hidden'); m.classList.add('flex') } function hide(id) { let m = document.getElementById(id); m.classList.add('hidden'); m.classList.remove('flex') }
    function editGroup(b) { let r = JSON.parse(b.dataset.row); g_action.value = 'edit_group'; g_id.value = r.id; g_code.value = r.group_code; g_en.value = r.group_name_en; g_mm.value = r.group_name_mm; g_ien.value = r.instruction_en || ''; g_imm.value = r.instruction_mm || ''; modal('groupModal') }
    function addQuestion(group) { q_action.value = 'add_question'; q_id.value = ''; q_group.value = group; q_en.value = ''; q_mm.value = ''; modal('questionModal') }
    function editQuestion(b) { let r = JSON.parse(b.dataset.row); q_action.value = 'edit_question'; q_id.value = r.id; q_group.value = r.survey_group_id; q_en.value = r.question_text_en; q_mm.value = r.question_text_mm; modal('questionModal') }
    //function deleteItem(action, id) { d_action.value = action; d_id.value = id; let group = action === 'delete_group'; delete_title.textContent = group ? '<?= e(surveyAdminText('delete_survey_group', 'Delete Survey Group')) ?>' : '<?= e(surveyAdminText('delete_survey_question', 'Delete Survey Question')) ?>'; delete_message.textContent = group ? '<?= e(surveyAdminText('confirm_delete_group', 'Are you sure you want to delete this Survey Group and its questions?')) ?>' : '<?= e(surveyAdminText('confirm_delete_question', 'Are you sure you want to delete this question?')) ?>'; modal('deleteModal') }
    function deleteItem(action, id) {
        d_action.value = action;
        d_id.value = id;

        let group = action === 'delete_group';

        delete_title.textContent = group
            ? '<?= e($LANG['delete_survey_group'] ?? 'Delete Survey Group') ?>'
            : '<?= e($LANG['delete_survey_question'] ?? 'Delete Survey Question') ?>';

        delete_message.textContent = group
            ? '<?= e($LANG['confirm_delete_group'] ?? 'Are you sure you want to delete this Survey Group and its questions?') ?>'
            : '<?= e($LANG['confirm_delete_question'] ?? 'Are you sure you want to delete this question?') ?>';

        modal('deleteModal');
    }
    document.getElementById('deleteModal').addEventListener('click', function (event) { if (event.target === this) hide('deleteModal') });
</script>
<?php include '../includes/admin_footer.php'; ?>