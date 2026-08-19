<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireRole('student');
updateAllFeedbackStatuses($conn);

$module = $surveyModule ?? 'teaching_quality';
$isAjaxEmbedded = ($_GET['embed'] ?? '') === '1';
$isDashboardEmbedded = !empty($surveyDashboardEmbed);
$isEmbedded = $isAjaxEmbedded || $isDashboardEmbedded;
$validModules = ['teaching_quality', 'student_support_services', 'learning_environment'];
if (!in_array($module, $validModules, true)) {
    http_response_code(400);
    exit(e($LANG['invalid_feedback_module'] ?? 'Invalid feedback module.'));
}

$user = getCurrentUser();
$studentStmt = $conn->prepare("SELECT id FROM students WHERE user_id = ?");
$studentStmt->bind_param('i', $user['id']);
$studentStmt->execute();
$studentId = (int) ($studentStmt->get_result()->fetch_assoc()['id'] ?? 0);
$studentStmt->close();
if (!$studentId) {
    setFlash('error', $LANG['flash_student_profile_missing'] ?? 'Student profile not found.');
    header('Location: feedback_forms.php');
    exit;
}

$formId = (int) ($_GET['form_id'] ?? 0);
if (!$formId) {
    header('Location: feedback_forms.php');
    exit;
}

if ($module === 'teaching_quality') {
    $formStmt = $conn->prepare(
        "SELECT ff.*, c.course_code, c.course_name, u.name AS teacher_name,
                ay.year_name, sm.semester_name, secm.section_name
         FROM feedback_forms ff
         JOIN sections sec ON sec.id = ff.section_id
         JOIN section_assignments sa ON sa.section_id = sec.id AND sa.student_id = ?
         JOIN courses c ON c.id = sec.course_id
         JOIN teachers t ON t.id = sec.teacher_id
         JOIN users u ON u.id = t.user_id
         LEFT JOIN academic_years ay ON ay.id = ff.academic_year_id
         LEFT JOIN semesters sm ON sm.id = ff.semester_id
         LEFT JOIN section_master secm ON secm.id = sec.section_id
         WHERE ff.id = ? AND ff.module = 'teaching_quality'
         LIMIT 1"
    );
    $formStmt->bind_param('ii', $studentId, $formId);
} else {
    $formStmt = $conn->prepare(
        "SELECT ff.*, ay.year_name, sm.semester_name
         FROM feedback_forms ff
         JOIN section_assignments sa
         JOIN sections sec ON sec.id = sa.section_id
              AND sec.academic_year_id = ff.academic_year_id
              AND sec.semester_id = ff.semester_id
         LEFT JOIN academic_years ay ON ay.id = ff.academic_year_id
         LEFT JOIN semesters sm ON sm.id = ff.semester_id
         WHERE ff.id = ? AND ff.module = ? AND sa.student_id = ?
         LIMIT 1"
    );
    $formStmt->bind_param('isi', $formId, $module, $studentId);
}
$formStmt->execute();
$form = $formStmt->get_result()->fetch_assoc();
$formStmt->close();
if (!$form) {
    setFlash('error', $LANG['survey_not_available_for_class'] ?? 'This Survey is not available for your assigned class.');
    header('Location: feedback_forms.php');
    exit;
}

$submittedStmt = $conn->prepare(
    "SELECT id FROM feedback_submissions WHERE form_id = ? AND student_id = ?"
);
$submittedStmt->bind_param('ii', $formId, $studentId);
$submittedStmt->execute();
$alreadySubmitted = $submittedStmt->get_result()->num_rows > 0;
$submittedStmt->close();
$canSubmit = $form['status'] === 'Active' && !$alreadySubmitted;

$questions = [];
if (!empty($form['question_set_id'])) {
    $questions = getSurveyQuestionsForSet($conn, (int) $form['question_set_id']);
}

$submissionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $submissionError = $LANG['invalid_request_token'] ?? 'Invalid request token.';
    } elseif (!$canSubmit) {
        $submissionError = $LANG['flash_form_not_open'] ?? 'This Survey is not open for submission.';
    } elseif (!$questions) {
        $submissionError = $LANG['survey_has_no_questions'] ?? 'This Survey has no questions.';
    } else {
        $answers = [];
        foreach ($questions as $question) {
            $selected = filter_input(
                INPUT_POST,
                'survey_' . $question['id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 5]]
            );
            if ($selected === false || $selected === null) {
                $answers = [];
                break;
            }
            $answers[(int) $question['id']] = (int) $selected;
        }

        if (count($answers) !== count($questions)) {
            $submissionError = $LANG['answer_every_survey_question'] ?? 'Please answer every Survey question.';
        } else {
            $conn->begin_transaction();
            try {
                $insertSubmission = $conn->prepare(
                    "INSERT INTO feedback_submissions (form_id, student_id) VALUES (?, ?)"
                );
                $insertSubmission->bind_param('ii', $formId, $studentId);
                if (!$insertSubmission->execute()) {
                    throw new RuntimeException('Unable to create Survey submission.');
                }
                $submissionId = $conn->insert_id;
                $insertSubmission->close();
                if ($submissionId < 1) {
                    throw new RuntimeException('Survey submission ID was not created.');
                }

                $insertAnswer = $conn->prepare(
                    "INSERT INTO feedback_survey_answers
                     (submission_id, question_id, rating)
                     VALUES (?, ?, ?)"
                );
                foreach ($answers as $questionId => $rating) {
                    $insertAnswer->bind_param('iii', $submissionId, $questionId, $rating);
                    if (!$insertAnswer->execute() || $insertAnswer->affected_rows !== 1) {
                        throw new RuntimeException('Unable to save a Survey answer.');
                    }
                }
                $insertAnswer->close();
                $conn->commit();
                if ($isAjaxEmbedded) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $LANG['survey_submitted_successfully'] ?? 'Survey submitted successfully.',
                        'form_id' => $formId,
                    ]);
                    exit;
                }
                $_SESSION['feedback_completion'] = [
                    'form_id' => (int) $formId,
                    'module' => $module,
                ];
                header('Location: ' . BASE_URL . 'student/dashboard.php');
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                $submissionError = $error instanceof mysqli_sql_exception && $error->getCode() === 1062
                    ? ($LANG['survey_already_submitted'] ?? 'You have already submitted this Survey.')
                    : ($LANG['survey_submission_failed'] ?? 'Survey submission failed.');
            }
        }
    }

    if ($isAjaxEmbedded) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $submissionError ?: ($LANG['survey_submission_failed'] ?? 'Survey submission failed.'),
        ]);
        exit;
    }

    if ($submissionError !== '') {
        setFlash('error', $submissionError);
    }
}

$pageTitle = $form['title'] ?? 'Survey';
$moduleLabel = match ($module) {
    'teaching_quality' => $LANG['academic'] ?? 'Teaching Quality',
    'student_support_services' => $LANG['student_affairs'] ?? 'Student Support Services',
    'learning_environment' => $LANG['administration'] ?? 'Learning Environment',
};
?>
<?php if (!$isEmbedded): ?>
    <!DOCTYPE html>
    <html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($pageTitle) ?> — SFIS</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
    </head>

    <body class="bg-slate-100 min-h-screen">
        <header class="bg-cyan-700 text-white">
            <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
                <div>
                    <p class="font-bold"><?= e($LANG['sfms_survey'] ?? 'SFIS Survey') ?></p>
                    <p class="text-xs text-cyan-100"><?= e($user['name']) ?></p>
                </div>
                <a href="feedback_forms.php" class="text-sm hover:underline"><?= e($LANG['back_to_surveys'] ?? 'Back to Surveys') ?></a>
            </div>
        </header>
        <main class="max-w-5xl mx-auto px-4 py-8">
            <?php renderFlash(); ?>
        <?php else: ?>
            <div class="survey-inline-content" data-form-id="<?= $formId ?>" data-module="<?= e($module) ?>">
                <?php if (!$isDashboardEmbedded): ?>
                    <div class="sticky top-0 z-10 flex justify-end py-2 mb-2">
                        <a href="<?= e(BASE_URL) ?>student/feedback_forms.php"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-cyan-700 bg-white border border-cyan-200 rounded-xl shadow-sm hover:bg-cyan-50 hover:border-cyan-300 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                            </svg>
                            <?= e($LANG['back_to_feedback_forms'] ?? 'Back to Feedback Forms') ?>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <section class="w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p data-language-key="module-label" class="text-xs font-bold uppercase tracking-wider text-violet-600"><?= e($moduleLabel) ?></p>
                        <h1 class="text-2xl font-bold text-slate-900 mt-1"><?= e($form['title']) ?></h1>
                        <div class="grid sm:grid-cols-2 gap-x-8 gap-y-2 mt-4 text-sm">
                            <div>
                                <span
                                    data-language-key="academic-year-label" class="font-semibold text-slate-700"><?= e($LANG['academic_year'] ?? 'Academic Year') ?>:</span>
                                <span class="text-slate-500"><?= e($form['year_name'] ?? '-') ?></span>
                            </div>
                            <div>
                                <span
                                    data-language-key="semester-label" class="font-semibold text-slate-700"><?= e($LANG['semester'] ?? 'Semester') ?>:</span>
                                <span class="text-slate-500"><?= e($form['semester_name'] ?? '-') ?></span>
                            </div>

                            <?php if (($form['module'] ?? $module) === 'teaching_quality'): ?>
                                <div>
                                    <span
                                        data-language-key="section-label" class="font-semibold text-slate-700"><?= e($LANG['section'] ?? 'Section') ?>:</span>
                                    <span class="text-slate-500"><?= e($form['section_name'] ?? '-') ?></span>
                                </div>
                                <div>
                                    <span data-language-key="course-label" class="font-semibold text-slate-700"><?= e($LANG['course'] ?? 'Course') ?>:</span>
                                    <span class="text-slate-500">
                                        <?= e(trim(($form['course_code'] ?? '') . ' ' . ($form['course_name'] ?? '')) ?: '-') ?>
                                    </span>
                                </div>
                                <div class="sm:col-span-2">
                                    <span
                                        data-language-key="teacher-label" class="font-semibold text-slate-700"><?= e($LANG['teacher'] ?? 'Teacher') ?>:</span>
                                    <span class="text-slate-500"><?= e($form['teacher_name'] ?? '-') ?></span>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                    <?= badgeStatus($form['status']) ?>
                </div>
            </section>
            <!-- <section class="w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex-1">
            <p class="text-xs font-bold uppercase tracking-wider text-violet-600">Survey</p>
            <h1 class="text-xl font-bold text-slate-900 mt-0.5"><?= e($form['title']) ?></h1>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2 mt-3 text-sm">
                <div>
                    <span class="font-semibold text-slate-700"><?= e($LANG['academic_year'] ?? 'Academic Year') ?>:</span>
                    <span class="text-slate-500"><?= e($form['year_name'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold text-slate-700"><?= e($LANG['semester'] ?? 'Semester') ?>:</span>
                    <span class="text-slate-500"><?= e($form['semester_name'] ?? '-') ?></span>
                </div>

                <?php if (($form['module'] ?? $module) === 'teaching_quality'): ?>
                    <div>
                        <span class="font-semibold text-slate-700"><?= e($LANG['section'] ?? 'Section') ?>:</span>
                        <span class="text-slate-500"><?= e($form['section_name'] ?? '-') ?></span>
                    </div>
                    <div class="md:col-span-2">
                        <span class="font-semibold text-slate-700"><?= e($LANG['course'] ?? 'Course') ?>:</span>
                        <span class="text-slate-500">
                            <?= e(trim(($form['course_code'] ?? '') . ' ' . ($form['course_name'] ?? '')) ?: '-') ?>
                        </span>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-700"><?= e($LANG['teacher'] ?? 'Teacher') ?>:</span>
                        <span class="text-slate-500"><?= e($form['teacher_name'] ?? '-') ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <?= badgeStatus($form['status']) ?>
        </div>
    </div>
</section> -->
            <?php if ($alreadySubmitted): ?>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                    <?= e($LANG['survey_already_submitted'] ?? 'You have already submitted this Survey.') ?>
                </div>
            <?php elseif (!$canSubmit): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
                    <?= e($LANG['survey_not_open'] ?? 'This Survey is not currently open.') ?>
                </div>
            <?php elseif (!$questions): ?>
                <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">
                    <?= e($LANG['survey_has_no_questions'] ?? 'This Survey has no questions.') ?>
                </div>
            <?php else: ?>
                <form method="post"
                    action="<?= $isDashboardEmbedded ? BASE_URL . 'student/dashboard.php' : e(basename($_SERVER['PHP_SELF'])) ?>?form_id=<?= $formId ?><?= $isAjaxEmbedded ? '&amp;embed=1' : '' ?>"
                    class="space-y-5" id="survey-form" novalidate>
                    <?= csrfField() ?>
                    <!-- <div id="survey-validation-summary" class="hidden rounded-2xl border border-red-300 bg-red-50 p-5 text-red-800" role="alert" aria-live="assertive">
                        <p class="font-bold"><?= e($LANG['survey_unanswered_heading'] ?? 'Please answer the following questions:') ?></p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm" id="survey-unanswered-list"></ul>
                    </div> -->
                    <?php
                    $currentGroup = null;
                    $language = ($_SESSION['lang'] ?? 'en') === 'mm' ? 'mm' : 'en';
                    $likertOptions = [
                        5 => $LANG['likert_strongly_agree'] ?? 'Strongly Agree',
                        4 => $LANG['likert_agree'] ?? 'Agree',
                        3 => $LANG['likert_neutral'] ?? 'Neutral',
                        2 => $LANG['likert_disagree'] ?? 'Disagree',
                        1 => $LANG['likert_strongly_disagree'] ?? 'Strongly Disagree',
                    ];
                    foreach ($questions as $index => $question):
                        if ($currentGroup !== (int) $question['group_id']):
                            $currentGroup = (int) $question['group_id'];
                            $groupName = $question['group_name_' . $language];
                            $instruction = $question['instruction_' . $language];
                            ?>
                            <section class="rounded-2xl border border-violet-200 bg-violet-50 p-5">
                                <h2 data-language-key="group-name-<?= $currentGroup ?>" class="text-xl font-bold text-violet-900"><?= e($groupName) ?></h2>
                                <p data-language-key="group-instruction-<?= $currentGroup ?>"
                                    class="mt-2 text-violet-700 <?= $instruction === '' ? 'hidden' : '' ?>"><?= e($instruction) ?></p>
                            </section>
                        <?php endif;
                        $questionText = $question['question_text_' . $language]; ?>
                        <fieldset id="survey-question-<?= (int) $question['id'] ?>" data-survey-question
                            data-question-label="<?= e(sprintf($LANG['survey_question_label'] ?? 'Question %d: %s', $index + 1, $questionText)) ?>"
                            class="scroll-mt-24 bg-white rounded-2xl border border-slate-200 shadow-sm p-6 transition-all duration-200">
                            <legend class="sr-only"><?= e($questionText) ?></legend>
                            <p class="font-semibold text-slate-900">
                                <span class="text-violet-600"><?= e($question['question_code']) ?></span>
                                <span data-language-key="question-<?= (int) $question['id'] ?>"><?= e($questionText) ?></span>
                            </p>
                            <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3 mt-4">
                                <?php foreach ($likertOptions as $rating => $label): ?>
                                    <label
                                        class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 cursor-pointer hover:border-violet-400 hover:bg-violet-50">
                                        <input type="radio" name="survey_<?= (int) $question['id'] ?>" value="<?= $rating ?>"
                                            required class="w-4 h-4 text-violet-600">
                                        <span data-language-key="option-<?= (int) $question['id'] ?>-<?= $rating ?>" class="text-sm"><?= e($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p data-language-key="question-error-<?= (int) $question['id'] ?>" class="survey-question-error hidden mt-3 text-sm font-semibold text-red-600">
                                <?= e($LANG['survey_question_required'] ?? 'This question has not been answered.') ?>
                            </p>
                        </fieldset>
                    <?php endforeach; ?>
                    <!-- <button type="submit"
                class="w-full md:w-auto px-8 py-3 rounded-xl bg-violet-600 text-white font-semibold hover:bg-violet-700">
                Submit Survey
            </button> -->
                    <div class="flex justify-end">
                        <button type="submit" data-language-key="submit-survey"
                            class="w-full md:w-auto px-8 py-3 rounded-xl bg-violet-600 text-white font-semibold hover:bg-violet-700">
                            <?= $LANG['submit_survey'] ?? 'Submit Survey' ?>
                        </button>
                    </div>
                </form>
                <script>
                    (function () {
                        const form = document.getElementById('survey-form');
                        if (!form) return;

                        const summary = document.getElementById('survey-validation-summary');
                        const unansweredList = document.getElementById('survey-unanswered-list');

                        document.querySelectorAll('.student-language-link').forEach(function (link) {
                            link.addEventListener('click', async function (event) {
                                event.preventDefault();
                                const selectedLanguage = link.dataset.language;
                                const languageLinks = document.querySelectorAll('.student-language-link');
                                languageLinks.forEach(function (item) {
                                    item.classList.add('pointer-events-none', 'opacity-60');
                                    item.setAttribute('aria-busy', 'true');
                                });

                                try {
                                    const response = await fetch(link.href, {
                                        method: 'GET',
                                        credentials: 'same-origin',
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                        redirect: 'follow'
                                    });
                                    if (!response.ok) throw new Error('Language update failed.');

                                    const translatedDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
                                    if (!translatedDocument.getElementById('survey-form')) {
                                        throw new Error('Translated survey was not returned.');
                                    }

                                    // Synchronize translated page text without replacing form controls.
                                    // Keeping the existing INPUT nodes mounted preserves every radio selection.
                                    const skippedTags = new Set(['INPUT', 'TEXTAREA', 'SELECT', 'SCRIPT', 'STYLE', 'SVG', 'CANVAS']);
                                    function syncTranslatedTree(current, translated) {
                                        if (!current || !translated || current.tagName !== translated.tagName || skippedTags.has(current.tagName)) return;
                                        ['title', 'aria-label', 'placeholder'].forEach(function (attribute) {
                                            if (translated.hasAttribute(attribute)) current.setAttribute(attribute, translated.getAttribute(attribute));
                                        });
                                        const currentText = Array.from(current.childNodes).filter(function (node) { return node.nodeType === Node.TEXT_NODE; });
                                        const translatedTextNodes = Array.from(translated.childNodes).filter(function (node) { return node.nodeType === Node.TEXT_NODE; });
                                        if (currentText.length === translatedTextNodes.length) {
                                            currentText.forEach(function (node, index) { node.nodeValue = translatedTextNodes[index].nodeValue; });
                                        }
                                        const currentChildren = Array.from(current.children);
                                        const translatedChildren = Array.from(translated.children);
                                        if (currentChildren.length === translatedChildren.length) {
                                            currentChildren.forEach(function (child, index) { syncTranslatedTree(child, translatedChildren[index]); });
                                        }
                                    }
                                    syncTranslatedTree(document.body, translatedDocument.body);

                                    const translatedText = new Map();
                                    translatedDocument.querySelectorAll('[data-language-key]').forEach(function (element) {
                                        translatedText.set(element.dataset.languageKey, element);
                                    });
                                    document.querySelectorAll('[data-language-key]').forEach(function (element) {
                                        const translated = translatedText.get(element.dataset.languageKey);
                                        if (translated) {
                                            element.textContent = translated.textContent;
                                            if (element.dataset.languageKey.startsWith('group-instruction-')) {
                                                element.classList.toggle('hidden', translated.classList.contains('hidden'));
                                            }
                                        }
                                    });

                                    form.querySelectorAll('[data-survey-question]').forEach(function (fieldset) {
                                        const translatedFieldset = translatedDocument.getElementById(fieldset.id);
                                        if (translatedFieldset) {
                                            fieldset.dataset.questionLabel = translatedFieldset.dataset.questionLabel || '';
                                        }
                                    });

                                    document.documentElement.lang = selectedLanguage === 'mm' ? 'my' : 'en';
                                    document.body.classList.toggle('lang-mm', selectedLanguage === 'mm');
                                    document.title = translatedDocument.title;
                                    languageLinks.forEach(function (item) {
                                        const active = item.dataset.language === selectedLanguage;
                                        item.classList.toggle('bg-white', active);
                                        item.classList.toggle('shadow', active);
                                        item.classList.toggle('text-cyan-700', active);
                                        item.classList.toggle('font-bold', active);
                                        item.classList.toggle('text-cyan-400', !active);
                                    });
                                } catch (error) {
                                    console.error(error);
                                } finally {
                                    languageLinks.forEach(function (item) {
                                        item.classList.remove('pointer-events-none', 'opacity-60');
                                        item.removeAttribute('aria-busy');
                                    });
                                }
                            });
                        });

                        function clearQuestionError(fieldset) {
                            fieldset.classList.remove('border-red-400', 'ring-2', 'ring-red-100', 'bg-red-50');
                            fieldset.querySelector('.survey-question-error')?.classList.add('hidden');
                        }

                        function markQuestionUnanswered(fieldset) {
                            fieldset.classList.add('border-red-400', 'ring-2', 'ring-red-100', 'bg-red-50');
                            fieldset.querySelector('.survey-question-error')?.classList.remove('hidden');
                        }

                        function showQuestion(fieldset) {
                            if (!fieldset) return;
                            fieldset.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            window.setTimeout(function () {
                                fieldset.querySelector('input[type="radio"]')?.focus({ preventScroll: true });
                            }, 350);
                        }

                        function renderSummary(unanswered) {
                            if (!summary || !unansweredList) return;
                            unansweredList.replaceChildren();
                            unanswered.forEach(function (fieldset) {
                                const item = document.createElement('li');
                                item.textContent = fieldset.dataset.questionLabel || '';
                                unansweredList.appendChild(item);
                            });
                            summary.classList.toggle('hidden', unanswered.length === 0);
                        }

                        form.querySelectorAll('[data-survey-question] input[type="radio"]').forEach(function (radio) {
                            radio.addEventListener('change', function () {
                                const fieldset = radio.closest('[data-survey-question]');
                                if (fieldset) clearQuestionError(fieldset);
                                if (summary && !summary.classList.contains('hidden')) {
                                    const remaining = Array.from(form.querySelectorAll('[data-survey-question]')).filter(function (question) {
                                        return !question.querySelector('input[type="radio"]:checked');
                                    });
                                    renderSummary(remaining);
                                    if (remaining.length > 0) {
                                        window.setTimeout(function () { showQuestion(remaining[0]); }, 100);
                                    }
                                }
                            });
                        });

                        form.addEventListener('submit', function (event) {
                            const unanswered = [];
                            form.querySelectorAll('[data-survey-question]').forEach(function (fieldset) {
                                const answered = fieldset.querySelector('input[type="radio"]:checked');
                                clearQuestionError(fieldset);
                                if (!answered) {
                                    unanswered.push(fieldset);
                                    markQuestionUnanswered(fieldset);
                                }
                            });

                            if (unanswered.length === 0) {
                                summary?.classList.add('hidden');
                                return;
                            }

                            event.preventDefault();
                            renderSummary(unanswered);
                            showQuestion(unanswered[0]);
                        });
                    }());
                </script>
            <?php endif; ?>
            <?php if (!$isEmbedded): ?>
        </main>
    </body>

    </html>
<?php else: ?>
    </div>
<?php endif; ?>
