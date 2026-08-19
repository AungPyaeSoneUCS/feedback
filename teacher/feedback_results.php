<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Section annotation

requireRole('teacher');

updateAllFeedbackStatuses($conn);

/*
 * Request-scoped Survey analysis dataset for the restored report UI.
 * Fixed Likert ratings are categorized only for compatibility with the
 * existing report cards; the stored value remains the canonical 1-5 score.
 */
$conn->query("
    CREATE TEMPORARY TABLE survey_analysis_answers AS
    SELECT
        fsa.id,
        fsa.question_id,
        fsub.form_id,
        fsa.rating
    FROM feedback_survey_answers fsa
    JOIN feedback_submissions fsub
        ON fsub.id = fsa.submission_id
    JOIN feedback_questions fq
        ON fq.id = fsa.question_id
");

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
$teacherId = $teacher['id'] ?? 0;

// AJAX endpoint for student lists (teacher can only see their own sections)
if (isset($_GET['ajax_students']) && $_GET['ajax_students'] === '1') {
    header('Content-Type: application/json');
    $ajaxFormId = (int) ($_GET['form_id'] ?? 0);
    $ajaxType = $_GET['type'] ?? '';
    if (!$ajaxFormId || !in_array($ajaxType, ['all', 'completed', 'pending'])) {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }
    // Verify teacher owns this form's section
    $af = $conn->prepare("SELECT ff.* FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id WHERE ff.id=? AND s.teacher_id=?");
    $af->bind_param('ii', $ajaxFormId, $teacherId);
    $af->execute();
    $afRow = $af->get_result()->fetch_assoc();
    $af->close();
    if (!$afRow) {
        echo json_encode(['error' => 'Form not found']);
        exit;
    }

    $allList = [];
    $doneList = [];

    $q1 = $conn->prepare("SELECT st.id, u.name, st.roll_no FROM students st JOIN users u ON st.user_id=u.id JOIN section_assignments sa ON sa.student_id=st.id WHERE sa.section_id=?");
    $q1->bind_param('i', $afRow['section_id']);
    $q1->execute();
    $allList = $q1->get_result()->fetch_all(MYSQLI_ASSOC);
    $q1->close();

    $q2 = $conn->prepare("SELECT st.id, u.name, st.roll_no, fs.submitted_at FROM students st JOIN users u ON st.user_id=u.id JOIN section_assignments sa ON sa.student_id=st.id JOIN feedback_submissions fs ON fs.student_id=st.id WHERE sa.section_id=? AND fs.form_id=?");
    $q2->bind_param('ii', $afRow['section_id'], $ajaxFormId);
    $q2->execute();
    $doneList = $q2->get_result()->fetch_all(MYSQLI_ASSOC);
    $q2->close();

    // Sort by CS first, then CT, then others; within each group sort by numeric suffix
    usort($allList, function ($a, $b) {
        $ra = strtoupper($a['roll_no'] ?? '');
        $rb = strtoupper($b['roll_no'] ?? '');
        $ga = str_contains($ra, 'CS') ? 0 : (str_contains($ra, 'CT') ? 1 : 2);
        $gb = str_contains($rb, 'CS') ? 0 : (str_contains($rb, 'CT') ? 1 : 2);
        if ($ga !== $gb)
            return $ga - $gb;
        $na = (int) substr($a['roll_no'], strrpos($a['roll_no'], '-') + 1);
        $nb = (int) substr($b['roll_no'], strrpos($b['roll_no'], '-') + 1);
        return $na - $nb;
    });
    usort($doneList, function ($a, $b) {
        $ra = strtoupper($a['roll_no'] ?? '');
        $rb = strtoupper($b['roll_no'] ?? '');
        $ga = str_contains($ra, 'CS') ? 0 : (str_contains($ra, 'CT') ? 1 : 2);
        $gb = str_contains($rb, 'CS') ? 0 : (str_contains($rb, 'CT') ? 1 : 2);
        if ($ga !== $gb)
            return $ga - $gb;
        $na = (int) substr($a['roll_no'], strrpos($a['roll_no'], '-') + 1);
        $nb = (int) substr($b['roll_no'], strrpos($b['roll_no'], '-') + 1);
        return $na - $nb;
    });

    $doneIds = array_column($doneList, 'id');
    $pendList = [];
    foreach ($allList as $s) {
        if (!in_array($s['id'], $doneIds))
            $pendList[] = $s;
    }

    $result = match ($ajaxType) {
        'all' => $allList,
        'completed' => $doneList,
        'pending' => $pendList,
    };
    echo json_encode($result);
    exit;
}

$pageTitle = $LANG['teacher_results_title'] ?? 'Feedback Results';
$activeMenu = 'results';

// Parse query strings accurately
$sectionId = (int) ($_GET['section_id'] ?? 0);
$formId = (int) ($_GET['form_id'] ?? 0);
$semesterFilter = clean($_GET['semester'] ?? '');

// My sections list
$mySections = [];
if ($teacherId) {
    $rs = $conn->query("SELECT s.id, c.course_name, c.course_code, sm_sec.section_name AS section_name, COALESCE(ay.year_name, '') AS display_year, sm.semester_name AS display_semester, sm.semester_name AS semester_value FROM sections s JOIN courses c ON s.course_id=c.id LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE s.teacher_id=$teacherId ORDER BY s.id DESC");
    $mySections = $rs->fetch_all(MYSQLI_ASSOC);
}

// Distinct semesters for filter
$semesters = [];
if ($teacherId) {
    $rs = $conn->query("SELECT DISTINCT sm.semester_name AS semester_value FROM sections s LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE s.teacher_id=$teacherId AND sm.semester_name IS NOT NULL AND sm.semester_name != '' ORDER BY semester_value");
    $mySectionsQuery = $rs->fetch_all(MYSQLI_ASSOC);
    foreach ($mySectionsQuery as $r) {
        $semesters[] = $r['semester_value'];
    }
}

// Filter sections by semester configuration
if ($semesterFilter && !empty($mySections)) {
    $mySections = array_filter($mySections, fn($s) => $s['semester_value'] === $semesterFilter);
    // If the currently selected section doesn't belong to the filtered semester, clear it
    if ($sectionId) {
        $validIds = array_map(fn($s) => (int) $s['id'], $mySections);
        if (!in_array($sectionId, $validIds)) {
            $sectionId = 0;
            $formId = 0;
        }
    }
}

// Automatically deduce section_id if form_id was passed without a section context
if ($formId && !$sectionId) {
    $secStmt = $conn->prepare("SELECT ff.section_id FROM feedback_forms ff JOIN sections s ON ff.section_id = s.id WHERE ff.id = ? AND s.teacher_id = ?");
    $secStmt->bind_param('ii', $formId, $teacherId);
    $secStmt->execute();
    $resSec = $secStmt->get_result()->fetch_assoc();
    if ($resSec) {
        $sectionId = (int) $resSec['section_id'];
    }
    $secStmt->close();
}

// Forms list matching current targeted section context
$sectionForms = [];
if ($sectionId && $teacherId) {
    $chk = $conn->prepare("SELECT id FROM sections WHERE id=? AND teacher_id=?");
    $chk->bind_param('ii', $sectionId, $teacherId);
    $chk->execute();
    if ($chk->get_result()->num_rows) {
        $rf = $conn->prepare("SELECT ff.id, ff.title, ff.status, (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.form_id=ff.id) AS submissions FROM feedback_forms ff WHERE ff.section_id=? ORDER BY ff.id DESC");
        $rf->bind_param('i', $sectionId);
        $rf->execute();
        $sectionForms = $rf->get_result()->fetch_all(MYSQLI_ASSOC);
        $rf->close();
    }
    $chk->close();
}

// Fallback auto-selection layer
$autoFormId = 0;
if ($sectionId && !$formId && !empty($sectionForms)) {
    $formId = (int) $sectionForms[0]['id'];
    $autoFormId = $formId;
}

// Core Data fetching block for selected feedback form
$form = null;
$questions = [];
$ratingResults = [];
$comments = [];
$surveyResults = [];
$submissionCount = 0;

if ($formId && $teacherId) {
    $rf = $conn->prepare("SELECT ff.*, c.course_name, c.course_code, sm_sec.section_name AS section_name, COALESCE(ay.year_name, '') AS display_year, sm.semester_name AS display_semester, u.name AS teacher_name FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE ff.id=? AND s.teacher_id=?");
    $rf->bind_param('ii', $formId, $teacherId);
    $rf->execute();
    $form = $rf->get_result()->fetch_assoc();
    $rf->close();

    $module = $form['module'] ?? '';

    $formMeta = [];
    if ($form && $module === 'teaching_quality') {
        $formMeta = [
            'academic_year' => $form['display_year'] ?? '',
            'semester' => $form['display_semester'] ?? '',
            'course_code' => $form['course_code'] ?? '',
            'course_name' => $form['course_name'] ?? '',
            'section_name' => $form['section_name'] ?? '',
            'teacher_name' => $form['teacher_name'] ?? '',
        ];
    }

    if ($form) {
        if (!empty($form['question_set_id'])) {
            $questions = getSurveyQuestionsForSet($conn, (int) $form['question_set_id']);
            foreach ($questions as &$surveyQuestion) {
                $surveyQuestion['question_type'] = 'rating';
            }
            unset($surveyQuestion);
            if (($_SESSION['lang'] ?? 'en') === 'mm') {
                foreach ($questions as &$localizedQuestion) {
                    $localizedQuestion['question_text_en'] = $localizedQuestion['question_text_mm'];
                }
                unset($localizedQuestion);
            }
        }

        $totalStudentsStmt = $conn->prepare("SELECT COUNT(DISTINCT st.id) AS cnt FROM students st JOIN section_assignments sa ON sa.student_id = st.id WHERE sa.section_id = ?");
        $totalStudentsStmt->bind_param('i', $form['section_id']);
        $totalStudentsStmt->execute();
        $totalStudents = (int) $totalStudentsStmt->get_result()->fetch_assoc()['cnt'];
        $totalStudentsStmt->close();

        $completedStmt = $conn->prepare("SELECT COUNT(DISTINCT st.id) AS cnt FROM students st JOIN section_assignments sa ON sa.student_id = st.id JOIN feedback_submissions fs ON fs.student_id = st.id WHERE sa.section_id = ? AND fs.form_id = ?");
        $completedStmt->bind_param('ii', $form['section_id'], $formId);
        $completedStmt->execute();
        $completedCount = (int) $completedStmt->get_result()->fetch_assoc()['cnt'];
        $completedStmt->close();

        $pendingCount = max(0, $totalStudents - $completedCount);

        foreach ($questions as $quest) {
            if ($quest['question_type'] === 'rating') {
                $rs = $conn->prepare("SELECT rating, COUNT(*) AS cnt FROM survey_analysis_answers WHERE question_id=? AND form_id=? GROUP BY rating");
                $rs->bind_param('ii', $quest['id'], $formId);
                $rs->execute();
                $rawR = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
                $rs->close();

                $bd = array_fill_keys(array_column(normalizeSurveyOptions(null), 'label'), 0);
                $surveyResults[$quest['id']] = [];
                $tot = 0;
                foreach ($rawR as $rr) {
                    $rating = (int) $rr['rating'];
                    $option = normalizeSurveyOptions(null)[5 - $rating] ?? null;
                    if ($rating >= 1 && $rating <= 5)
                        $surveyResults[$quest['id']][$rating] = (int) $rr['cnt'];
                    if ($option && array_key_exists($option['label'], $bd)) {
                        $bd[$option['label']] += (int) $rr['cnt'];
                        $tot += $rr['cnt'];
                    }
                }
                $ratingResults[$quest['id']] = ['breakdown' => $bd, 'total' => $tot];
            } else {
                $cs = $conn->prepare("SELECT NULL AS comment_text WHERE ? = ? AND 1=0");
                $cs->bind_param('ii', $quest['id'], $formId);
                $cs->execute();
                $comments[$quest['id']] = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
                $cs->close();
            }
        }

        foreach ($questions as $quest) {
            if ($quest['question_type'] === 'survey') {
                $ss = $conn->prepare("
                    SELECT fsa.rating, COUNT(*) AS cnt
                    FROM feedback_survey_answers fsa
                    JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
                    WHERE fsub.form_id = ? AND fsa.question_id = ?
                    GROUP BY fsa.rating
                ");
                $ss->bind_param('ii', $formId, $quest['id']);
                $ss->execute();
                $rawS = $ss->get_result()->fetch_all(MYSQLI_ASSOC);
                $ss->close();
                $surveyResults[$quest['id']] = [];
                foreach ($rawS as $sr) {
                    $surveyResults[$quest['id']][(int) $sr['rating']] = (int) $sr['cnt'];
                }
            }
        }
    }
}

$surveyGroupRatings = [];
$surveyOverallAverage = 0.0;
if ($formId > 0 && !empty($form['question_set_id'])) {
    $questionSetId = (int) $form['question_set_id'];
    $rows = getSurveyGroupRatings($conn, $formId, $questionSetId);
    foreach ($rows as $row)
        $surveyGroupRatings[] = ['name_en' => $row['group_name_en'], 'name_mm' => $row['group_name_mm'], 'question_count' => (int) $row['question_count'], 'average' => $row['average'] === null ? null : (float) $row['average']];
    $overallStmt = $conn->prepare("SELECT ROUND(AVG(fsa.rating),2) average FROM feedback_survey_answers fsa JOIN feedback_submissions fs ON fs.id=fsa.submission_id WHERE fs.form_id=?");
    $overallStmt->bind_param('i', $formId);
    $overallStmt->execute();
    $overallRow = $overallStmt->get_result()->fetch_assoc();
    $overallStmt->close();
    $surveyOverallAverage = $overallRow['average'] === null ? 0.0 : (float) $overallRow['average'];
}

$ratingQuestions = [];
$commentQuestions = [];
$surveyQuestions = [];
foreach ($questions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } elseif ($q['question_type'] === 'survey') {
        $surveyQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

$likertTotals = array_fill_keys(array_column(normalizeSurveyOptions(null), 'label'), 0);
foreach ($ratingResults as $qId => $res) {
    foreach ($likertTotals as $label => $_)
        $likertTotals[$label] += $res['breakdown'][$label] ?? 0;
}
$totalRatingResponses = array_sum($likertTotals);
$numRatingQuestions = count($ratingQuestions);
$completedCount = $completedCount ?? 0;
$earnedScore = 0;
foreach (normalizeSurveyOptions(null) as $option)
    $earnedScore += $likertTotals[$option['label']] * $option['value'];
$maxScore = $completedCount * $numRatingQuestions * 5;
$overallPct = $maxScore > 0 ? round(($earnedScore / $maxScore) * 100, 1) : 0;
$gradeInfo = $completedCount > 0 ? performanceGradeFromPercentage($conn, $overallPct, (int) ($form['academic_year_id'] ?? 0)) : null;
$grade = $gradeInfo['label'] ?? '--';
$gradeColor = $gradeInfo['color'] ?? 'slate';
$gradeIcon = $gradeInfo['icon'] ?? '';
$gradeDisplay = $gradeIcon !== '' ? $gradeIcon . ' ' . $grade : '--';


?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Pyidaungsu', 'Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
    <style>
        @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');

        body {
            font-family: 'Pyidaungsu', 'Inter', sans-serif;
        }

        body.lang-mm th,
        body.lang-mm td {
            font-size: 0.8125rem;
            line-height: 1.6;
        }

        .rating-ring {
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }

        .rating-ring-circle {
            transition: stroke-dashoffset 1.5s ease-in-out;
        }

        .rating-ring-bg {
            opacity: 0.15;
        }

        .progress-bar-fill {
            transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .grade-badge {
            animation: gradePulse 2s ease-in-out infinite;
        }

        .print-only {
            display: none;
        }

        @keyframes gradePulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.3);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(99, 102, 241, 0);
            }
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 12mm;
            }

            body {
                background: white !important;
            }

            .print-only {
                display: block !important;
            }

            .no-print,
            nav,
            aside,
            header,
            .sidebar,
            [data-sidebar],
            .print-hide {
                display: none !important;
            }

            .bg-gradient-to-br {
                background: white !important;
            }

            * {
                box-shadow: none !important;
            }
        }
    </style>
</head>

<body
    class="h-full bg-gradient-to-br from-slate-50 via-blue-50 to-sky-50 font-inter antialiased <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <?php require_once '../includes/teacher_sidebar.php'; ?>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <!-- <h2 class="text-xl font-bold text-slate-800"><?= $LANG['teacher_results_title'] ?? 'Feedback Results' ?>
            </h2> -->
            <p class="text-sm text-slate-500">
                <?= $LANG['teacher_results_subtitle'] ?? 'View student feedback results anonymously' ?>
            </p>
        </div>
    </div>

    <div class="gap-6 items-start">
        <div class="no-print bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 shadow-sm p-4">
            <p class="text-xs font-bold text-slate-400 uppercase mb-3 tracking-wider"><?= $LANG['filter'] ?? 'Filter' ?>
            </p>
            <div class="mb-3">
                <label
                    class="block text-[10px] font-semibold text-slate-500 mb-1"><?= $LANG['semester_filter'] ?? 'Semester' ?></label>
                <select id="semesterFilter" onchange="filterBySemester()"
                    class="w-full border border-blue-200/50 rounded-lg px-3 py-2 text-xs focus:border-blue-400 focus:ring-2 focus:ring-blue-400/20 outline-none bg-white/80">
                    <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                    <?php foreach ($semesters as $sem): ?>
                        <option value="<?= e($sem) ?>" <?= $semesterFilter === $sem ? 'selected' : '' ?>>
                            <?= e(semesterToRoman($sem)) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <p class="text-xs font-bold text-slate-400 uppercase mb-3 tracking-wider">
                <?= $LANG['select_section'] ?? 'Select Section' ?>
            </p>
            <div class="space-y-2">
                <?php if (!empty($mySections)):
                    foreach ($mySections as $sec):
                        $isSelected = $sectionId == $sec['id'];
                        ?>
                        <label onclick="toggleSection(<?= $sec['id'] ?>)"
                            class="flex items-start gap-3 w-full px-3 py-2.5 rounded-xl text-xs transition-all border cursor-pointer select-none <?= $isSelected ? 'bg-blue-600 border-blue-600 text-white font-bold shadow-sm' : 'border-blue-200/50 text-slate-600 hover:bg-blue-50/50 hover:border-blue-300' ?>">
                            <div class="flex-1 min-w-0">
                                <p class="truncate"><?= e($sec['course_name']) ?></p>
                                <p class="text-[10px] mt-0.5 <?= $isSelected ? 'text-blue-100' : 'text-slate-400' ?>">
                                    <?= e($sec['display_year']) ?> · <?= e(semesterToRoman($sec['display_semester'])) ?> · Section <?= e($sec['section_name']) ?>
                                </p>
                            </div>
                        </label>
                    <?php endforeach; else: ?>
                    <p class="text-xs text-slate-400 italic text-center py-4">
                        <?= $LANG['no_sections_assigned'] ?? 'No sections assigned.' ?>
                    </p>
                <?php endif ?>
            </div>
        </div>

        <div class="lg:col-span-3 space-y-6">
            <?php if ($sectionId && !empty($sectionForms)): ?>
                <?php if ($form): ?>
                    <!-- Progress Stats -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 mt-6 myanmar-font">
                        <!-- <div onclick="openStudentModal('all')"
                            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center cursor-pointer hover:shadow-md hover:border-blue-300 transition-all">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">
                                <?= $LANG['total_students_label'] ?? 'Total Students' ?>
                            </p>
                            <p class="text-3xl font-black text-slate-800"><?= $totalStudents ?></p>
                        </div> -->
                        <div
                            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center  transition-all">
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">
                                <?= $LANG['total_students_label'] ?? 'Total Students' ?>
                            </p>
                            <p class="text-3xl font-black text-slate-800"><?= $totalStudents ?></p>
                        </div>
                        <div
                            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center transition-all">
                            <p class="text-xs font-bold text-emerald-500 uppercase tracking-wider mb-1">
                                <?= $LANG['completed_label'] ?? 'Completed' ?>
                            </p>
                            <p class="text-3xl font-black text-emerald-600"><?= $completedCount ?></p>
                            <p class="text-[10px] text-slate-400 mt-1">
                                <?= $totalStudents > 0 ? round(($completedCount / $totalStudents) * 100) : 0 ?>%
                                <?= $LANG['response_rate'] ?? 'response rate' ?>
                            </p>
                        </div>
                        <div
                            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center  transition-all">
                            <p class="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1">
                                <?= $LANG['pending_label'] ?? 'Pending' ?>
                            </p>
                            <p class="text-3xl font-black text-amber-600"><?= $pendingCount ?></p>
                            <p class="text-[10px] text-slate-400 mt-1">
                                <?= $totalStudents > 0 ? round(($pendingCount / $totalStudents) * 100) : 0 ?>%
                                <?= $LANG['remaining'] ?? 'remaining' ?>
                            </p>
                        </div>
                    </div>

                    <?php if (!empty($ratingQuestions)): ?>
                        <div class="mb-6">
                            <div
                                class="bg-gradient-to-br from-blue-500 via-purple-700 to-indigo-700 rounded-2xl shadow-xl border border-slate-700 p-6 md:p-8 text-white relative overflow-hidden">
                                <div class="relative z-10">
                                    <div class="flex items-center gap-2 mb-5">
                                        <?= iconSvg('star', 'w-5 h-5 text-indigo-300') ?>
                                        <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-200">
                                            <?= $LANG['overall_rating_score'] ?? 'Overall Rating Score' ?>
                                        </h3>
                                        <span
                                            class="ml-auto text-[10px] text-slate-400 bg-slate-700/50 px-2 py-0.5 rounded-full"><?= $LANG['rating_questions_only'] ?? 'Rating Questions Only' ?></span>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                                        <div class="md:col-span-3 flex flex-col items-center justify-center">
                                            <!-- Section annotation -->
                                            <div class="relative" style="width:160px;height:160px;">
                                                <canvas id="overallRatingPieChart" data-type="overall" width="160"
                                                    height="160"></canvas>
                                                <div
                                                    class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                                                    <span class="text-xl font-black text-white"><?= $overallPct ?>%</span>
                                                    <span
                                                        class="text-[9px] text-slate-300 font-bold uppercase"><?= $LANG['score_matrix'] ?? 'Score Matrix' ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="md:col-span-4 space-y-4">
                                            <div class="flex items-center gap-3">
                                                <?php if ($gradeIcon !== ''): ?><span
                                                        class="text-2xl"><?= e($gradeIcon) ?></span><?php endif; ?>
                                                <div>
                                                    <span
                                                        class="grade-badge inline-block px-4 py-1.5 rounded-lg text-sm font-extrabold <?= match ($gradeColor) { 'emerald' => 'bg-emerald-500/20 text-emerald-300 border border-emerald-400/30', 'blue' => 'bg-blue-500/20 text-blue-300 border border-blue-400/30', 'cyan' => 'bg-cyan-500/20 text-cyan-300 border border-cyan-400/30', 'amber' => 'bg-amber-500/20 text-amber-300 border border-amber-400/30', 'red' => 'bg-red-500/20 text-red-300 border border-red-400/30', default => 'bg-slate-500/20 text-slate-300 border border-slate-400/30'} ?>"><?= e($grade) ?></span>
                                                    <p class="text-[10px] text-slate-400 mt-1">
                                                        <?= $LANG['performance_grade'] ?? 'Performance Grade' ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-2 gap-3 text-xs">
                                                <div class="bg-white/5 backdrop-blur rounded-lg p-3 border border-white/10">
                                                    <p class="text-slate-400 font-semibold mb-0.5">
                                                        <?= $LANG['total_responses'] ?? 'Total Responses' ?>
                                                    </p>
                                                    <p class="text-lg font-black text-white"><?= $completedCount ?></p>
                                                </div>
                                                <div class="bg-white/5 backdrop-blur rounded-lg p-3 border border-white/10">
                                                    <p class="text-slate-400 font-semibold mb-0.5">
                                                        <?= $LANG['rating_questions'] ?? 'Rating Questions' ?>
                                                    </p>
                                                    <p class="text-lg font-black text-white"><?= $numRatingQuestions ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="md:col-span-5 space-y-3 hidden">
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">
                                                <?= $LANG['rating_distribution'] ?? 'Rating Distribution' ?>
                                            </p>
                                            <div class="space-y-1">
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="font-semibold text-emerald-300 flex items-center gap-1.5"><span
                                                            class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                                                        <?= $LANG['likert_strongly_agree'] ?? 'Strongly Agree' ?></span><span
                                                        class="text-slate-300 font-bold">0 <span
                                                            class="text-slate-500 font-normal">(0%)</span></span>
                                                </div>
                                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                                    <div class="progress-bar-fill bg-gradient-to-r from-emerald-500 to-emerald-400 h-full rounded-full"
                                                        style="width:0%"></div>
                                                </div>
                                            </div>
                                            <div class="space-y-1">
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="font-semibold text-amber-300 flex items-center gap-1.5"><span
                                                            class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
                                                        <?= $LANG['likert_neutral'] ?? 'Neutral' ?></span><span
                                                        class="text-slate-300 font-bold">0 <span
                                                            class="text-slate-500 font-normal">(0%)</span></span>
                                                </div>
                                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                                    <div class="progress-bar-fill bg-gradient-to-r from-amber-500 to-amber-400 h-full rounded-full"
                                                        style="width:0%"></div>
                                                </div>
                                            </div>
                                            <div class="space-y-1">
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="font-semibold text-red-300 flex items-center gap-1.5"><span
                                                            class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
                                                        <?= $LANG['likert_strongly_disagree'] ?? 'Strongly Disagree' ?></span><span
                                                        class="text-slate-300 font-bold">0 <span
                                                            class="text-slate-500 font-normal">(0%)</span></span>
                                                </div>
                                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                                    <div class="progress-bar-fill bg-gradient-to-r from-red-500 to-red-400 h-full rounded-full"
                                                        style="width:0%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif ?>

                    <!-- <?php if (!empty($surveyGroupRatings)):
                        $groupLang = ($_SESSION['lang'] ?? 'en') === 'mm' ? 'mm' : 'en'; ?>
                        <section class="mb-6 bg-white border border-slate-200 rounded-2xl shadow-sm p-6 no-print">
                            <div class="flex items-center justify-between gap-3 mb-4">
                                <div>
                                    <h3 class="text-lg font-bold text-slate-900">
                                        <?= e($LANG['survey_group_ratings'] ?? 'Survey Group Ratings') ?>
                                    </h3>
                                    <p class="text-xs text-slate-500">
                                        <?= e($LANG['survey_group_ratings_help'] ?? 'Average of all question ratings in each Survey Group.') ?>
                                    </p>
                                </div><span
                                    class="text-xs font-semibold text-violet-700 bg-violet-50 px-3 py-1.5 rounded-full"><?= number_format($surveyOverallAverage, 2) ?>
                                    / 5 <?= e($LANG['overall_rating'] ?? 'Overall Rating') ?></span>
                            </div>
                            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                                <?php foreach ($surveyGroupRatings as $group):
                                    $percentage = $group['average'] === null ? null : round($group['average'] * 20, 1); ?>
                                    <article class="rounded-xl border border-slate-200 p-4">
                                        <h4 class="font-bold text-slate-800"><?= e($group['name_' . $groupLang]) ?></h4>
                                        <div class="flex items-end justify-between gap-3 mt-3">
                                            <div>
                                                <p class="text-xs text-slate-500"><?= e($LANG['questions'] ?? 'Questions') ?></p>
                                                <p class="font-semibold text-slate-700"><?= (int) $group['question_count'] ?></p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-2xl font-black text-violet-700">
                                                    <?= $group['average'] === null ? '—' : number_format($group['average'], 2) . ' / 5' ?>
                                                </p>
                                                <?php if ($percentage !== null): ?>
                                                    <p class="text-xs text-slate-500"><?= $percentage ?>%</p><?php endif; ?>
                                            </div>
                                        </div>
                                    </article><?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?> -->

                    <?php if (!empty($ratingQuestions)): ?>
                        <!-- Rating Distribution Bar Chart -->
                        <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-6 mb-6">
                            <h3 class="text-sm font-bold text-slate-800 mb-4">
                                <?= $LANG['rating_distribution'] ?? '5-Point Likert Rating Distribution' ?>
                            </h3>
                            <div class="relative" style="height:280px;">
                                <canvas id="ratingBarChart"></canvas>
                            </div>
                            <?php $likertPercentages = surveyCategoryPercentages($likertTotals); ?>
                            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mt-4">
                                <?php foreach (normalizeSurveyOptions(null) as $option): ?>
                                    <div class="text-center p-3 rounded-xl bg-violet-50 border border-violet-100">
                                        <p class="text-xl font-bold text-violet-700">
                                            <?= number_format($likertPercentages[$option['label']] ?? 0, 1) ?>%
                                        </p>
                                        <p class="text-xs font-semibold"><?= e($option['label']) ?></p>
                                        <p class="text-[10px] text-slate-500"><?= number_format($likertTotals[$option['label']] ?? 0) ?>
                                            <?= e($LANG['ratings'] ?? 'ratings') ?>
                                        </p>
                                    </div><?php endforeach; ?>
                            </div>
                            <?php
                            $barTotal = $totalRatingResponses;
                            ?>
                            <div class="hidden grid-cols-3 gap-3 mt-4">
                                <div class="text-center p-3 rounded-xl bg-emerald-50 border border-emerald-200">
                                    <p class="text-2xl font-bold text-emerald-600">0%</p>
                                    <p class="text-xs font-semibold text-emerald-700">
                                        <?= $LANG['likert_strongly_agree'] ?? 'Strongly Agree' ?>
                                    </p>
                                    <p class="text-[10px] text-slate-500">0
                                        <?= $LANG['ratings'] ?? 'ratings' ?>
                                    </p>
                                </div>
                                <div class="text-center p-3 rounded-xl bg-amber-50 border border-amber-200">
                                    <p class="text-2xl font-bold text-amber-600">0%</p>
                                    <p class="text-xs font-semibold text-amber-700"><?= $LANG['likert_neutral'] ?? 'Neutral' ?></p>
                                    <p class="text-[10px] text-slate-500">0
                                        <?= $LANG['ratings'] ?? 'ratings' ?>
                                    </p>
                                </div>
                                <div class="text-center p-3 rounded-xl bg-red-50 border border-red-200">
                                    <p class="text-2xl font-bold text-red-600">0%</p>
                                    <p class="text-xs font-semibold text-red-700">
                                        <?= $LANG['likert_strongly_disagree'] ?? 'Strongly Disagree' ?>
                                    </p>
                                    <p class="text-[10px] text-slate-500">0
                                        <?= $LANG['ratings'] ?? 'ratings' ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif ?>

                    <?php if (!empty($surveyGroupRatings)):
                        $groupLang = ($_SESSION['lang'] ?? 'en') === 'mm' ? 'mm' : 'en'; ?>
                        <section class="print-only mb-6" style="page-break-inside:avoid">
                            <h3 style="font-size:14pt;font-weight:700;margin-bottom:10px">
                                <?= e($LANG['survey_group_ratings'] ?? 'Survey Group Ratings') ?>
                            </h3>
                            <table style="width:100%;border-collapse:collapse">
                                <thead>
                                    <tr>
                                        <th style="text-align:left;border:1px solid #cbd5e1;padding:7px">
                                            <?= e($LANG['survey_group'] ?? 'Survey Group') ?>
                                        </th>
                                        <th style="border:1px solid #cbd5e1;padding:7px"><?= e($LANG['questions'] ?? 'Questions') ?>
                                        </th>
                                        <th style="border:1px solid #cbd5e1;padding:7px">
                                            <?= e($LANG['average_rating'] ?? 'Average Rating') ?>
                                        </th>
                                        <th style="border:1px solid #cbd5e1;padding:7px">
                                            <?= e($LANG['percentage'] ?? 'Percentage') ?>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($surveyGroupRatings as $group):
                                        $percentage = $group['average'] === null ? null : round($group['average'] * 20, 1); ?>
                                        <tr>
                                            <td style="border:1px solid #cbd5e1;padding:7px"><?= e($group['name_' . $groupLang]) ?></td>
                                            <td style="text-align:center;border:1px solid #cbd5e1;padding:7px">
                                                <?= (int) $group['question_count'] ?>
                                            </td>
                                            <td style="text-align:center;border:1px solid #cbd5e1;padding:7px;font-weight:700">
                                                <?= $group['average'] === null ? '—' : number_format($group['average'], 2) . ' / 5' ?>
                                            </td>
                                            <td style="text-align:center;border:1px solid #cbd5e1;padding:7px">
                                                <?= $percentage === null ? '—' : $percentage . '%' ?>
                                            </td>
                                        </tr><?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>
                    <?php endif; ?>

                    <div class="bg-white/90 backdrop-blur-sm shadow-md rounded-xl border border-blue-100/50 p-6 md:p-8">
                        <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                            <h2 class="text-lg md:text-xl font-bold text-slate-900 mb-1"><?= e($form['title']) ?></h2>
                            <p class="text-xs text-slate-400 font-mono">
                                <?= $LANG['statistical_report'] ?? 'Statistical Evaluation Report Matrix (Anonymous)' ?>
                            </p>
                            <p class="text-xs text-slate-400 mt-1"><?= $LANG['feedback_period'] ?? 'Feedback Period' ?>:
                                <?= formatDateTime($form['start_date']) ?> — <?= formatDateTime($form['end_date']) ?>
                            </p>
                            <p class="text-xs mt-1"><?= badgeStatus($form['status']) ?>
                                <?php if ($form['status'] === 'Active'): ?><span
                                        class="text-slate-500"><?= getTimeRemaining($form['end_date']) ?></span><?php elseif ($form['status'] === 'Upcoming'): ?><span
                                        class="text-slate-500"><?= getTimeUntilStart($form['start_date']) ?></span><?php endif ?>
                            </p>
                        </div>

                        <?php if ($module === 'teaching_quality' && !empty($formMeta)): ?>
                            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
                                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">
                                    <?= $LANG['form_information'] ?? 'Form Information' ?>
                                </h3>
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                    <div>
                                        <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                            <?= $LANG["academic_year"] ?? "Academic Year" ?>
                                        </p>
                                        <p class="text-sm font-bold text-slate-800"><?= e($formMeta['academic_year'] ?? '—') ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                            <?= $LANG["semester_filter"] ?? "Semester" ?>
                                        </p>
                                        <p class="text-sm font-bold text-slate-800">
                                            <?= e(semesterToRoman($formMeta['semester'] ?? '')) ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                            <?= $LANG["course_code"] ?? "Course Code" ?>
                                        </p>
                                        <p class="text-sm font-bold text-slate-800"><?= e($formMeta['course_code'] ?? '—') ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                            <?= $LANG["course_name"] ?? "Course Name" ?>
                                        </p>
                                        <p class="text-sm font-bold text-slate-800"><?= e($formMeta['course_name'] ?? '—') ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                            <?= $LANG["section_name"] ?? "Section" ?>
                                        </p>
                                        <p class="text-sm font-bold text-slate-800"><?= e($formMeta['section_name'] ?? '—') ?></p>
                                    </div>
                                    <!-- <div>
                                        <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                            <?= $LANG["teacher_name"] ?? "Teacher Name" ?>
                                        </p>
                                        <p class="text-sm font-bold text-slate-800"><?= e($formMeta['teacher_name'] ?? '—') ?></p>
                                    </div> -->
                                </div>
                            </div>
                        <?php endif ?>

                        <?php if (!empty($ratingQuestions)): ?>
                            <div class="mb-8">
                                <h3 class="text-xl font-bold text-slate-900 uppercase tracking-wider mb-3">
                                    <?= $LANG['question_stats_header'] ?? 'Question-wise Statistical Results' ?>
                                </h3>
                                <div class="overflow-x-auto border border-blue-200/50 rounded-lg">
                                    <table class="w-full text-left border-collapse min-w-[850px] text-xs">
                                        <thead>
                                            <tr
                                                class="text-white font-bold [&>th]:px-4 [&>th]:py-3.5 [&>th]:align-middle [&>th]:transition-colors [&>th]:duration-200 [&>th:first-child]:rounded-tl-lg [&>th:last-child]:rounded-tr-lg [&>th:nth-child(3)]:bg-emerald-600 [&>th:nth-child(3):hover]:bg-emerald-700 [&>th:nth-child(4)]:bg-blue-600 [&>th:nth-child(4):hover]:bg-blue-700 [&>th:nth-child(5)]:bg-violet-500 [&>th:nth-child(5):hover]:bg-violet-600 [&>th:nth-child(6)]:bg-orange-500 [&>th:nth-child(6):hover]:bg-orange-600 [&>th:nth-child(7)]:bg-red-600 [&>th:nth-child(7):hover]:bg-red-700">
                                                <th class="p-3 w-12 text-center bg-blue-300 text-lg"><?= $LANG['col_no'] ?? 'No.' ?>
                                                </th>
                                                <th class="p-3 bg-blue-500/80 text-lg">
                                                    <?= $LANG['eval_questions_header'] ?? 'Evaluation Questions' ?>
                                                </th>
                                                <?php foreach (normalizeSurveyOptions(null) as $option): ?>
                                                    <th class="w-28 text-center shadow-sm">
                                                        <div class="text-sm font-semibold leading-tight"><?= e($option['label']) ?>
                                                        </div>
                                                        <div class="mt-1 text-[10px] font-medium text-white/90 tracking-wide">
                                                            <?= e($LANG['count_pct'] ?? 'COUNT / %') ?>
                                                        </div>
                                                    </th><?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-blue-200/40 text-slate-800 font-medium">
                                            <?php foreach ($ratingQuestions as $q):
                                                $res = $ratingResults[$q['id']] ?? ['breakdown' => [], 'total' => 0];
                                                $totalVotes = array_sum($res['breakdown']);
                                                ?>
                                                <tr class="hover:bg-blue-50/30 transition-colors">
                                                    <td class="p-3 text-center font-bold border-r text-lg">
                                                        <?= e($q['question_code']) ?>
                                                    </td>
                                                    <td class="p-3 border-r leading-relaxed text-lg"><?= e($q['question_text_en']) ?>
                                                    </td>
                                                    <?php foreach (normalizeSurveyOptions(null) as $option):
                                                        $count = $res['breakdown'][$option['label']] ?? 0;
                                                        $pct = $totalVotes ? round($count * 100 / $totalVotes) : 0; ?>
                                                        <td class="p-3 text-center border-r bg-violet-50/30"><span
                                                                class="text-violet-700 font-bold block text-sm"><?= $count ?></span><span
                                                                class="text-[10px] text-slate-500">(<?= $pct ?>%)</span></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif ?>

                        <?php if (!empty($commentQuestions)): ?>
                            <div class="space-y-6 pt-6 border-t-2 border-blue-200/50">
                                <h3 class="text-xl font-bold text-slate-900 uppercase tracking-wider">
                                    <?= $LANG['comments_header'] ?? 'Comments' ?>
                                </h3>
                                <?php foreach ($commentQuestions as $q):
                                    $commentsForThisQuestion = $comments[$q['id']] ?? []; ?>
                                    <div class="space-y-2 text-xs">
                                        <label class="block font-bold text-slate-700 text-lg">
                                            <?= e($q['question_code']) ?>
                                            <?= e($q['question_text_en']) ?>
                                            <span
                                                class="text-slate-400 font-normal text-xs">(<?= $LANG['total_comments'] ?? 'Total comments' ?>
                                                - <?= count($commentsForThisQuestion) ?>
                                                <?= $LANG['comments_box'] ?? 'comments' ?>)</span>
                                        </label>
                                        <div
                                            class="w-full bg-blue-50/50 backdrop-blur-sm border border-blue-100/50 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                            <?php if (!empty($commentsForThisQuestion)):
                                                foreach ($commentsForThisQuestion as $index => $cm): ?>
                                                    <div
                                                        class="bg-white border border-blue-100/40 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                                        <span class="text-slate-400 font-bold text-lg">#<?= $index + 1 ?></span>
                                                        <div class="text-slate-800 font-medium text-lg">
                                                            <?= e($cm['comment_text']) ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; else: ?>
                                                <div class="text-slate-400 italic text-center py-4">—
                                                    <?= $LANG['no_comments'] ?? 'No comments for this question' ?> —
                                                </div>
                                            <?php endif ?>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>

                        <!-- Survey Results (MCQ) -->
                        <?php if (!empty($surveyQuestions)): ?>
                            <div class="space-y-6 pt-6 border-t-2 border-slate-300">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xl font-bold text-slate-900 uppercase tracking-wider">
                                        <?= $LANG['survey_results_mcq'] ?? 'Survey Results (MCQ)' ?>
                                    </h3>
                                    <span
                                        class="text-[10px] text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full font-semibold"><?= $LANG['not_included_overall_rating'] ?? 'Not included in Overall Rating' ?></span>
                                </div>
                                <?php foreach ($surveyQuestions as $q):
                                    $opts = json_decode($q['question_text_en'] ?? '[]', true) ?: [];
                                    $qStats = $surveyResults[$q['id']] ?? [];
                                    $mostSelected = getMostSelectedSurveyOptions($qStats);
                                    $totalVotes = $mostSelected['total'];
                                    $doughnutColors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#c026d3'];
                                    $chartLabels = $chartData = $chartColors = [];
                                    foreach ($opts as $idx => $opt) {
                                        $chartLabels[] = $opt;
                                        $chartData[] = $qStats[$idx] ?? 0;
                                        $chartColors[] = $doughnutColors[$idx % count($doughnutColors)];
                                    }
                                    ?>
                                    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm"
                                        data-qid="<?= $q['id'] ?>">
                                        <div class="px-6 pt-5 pb-3 border-b border-slate-100">
                                            <div class="flex items-start gap-3">
                                                <span
                                                    class="text-lg font-bold text-violet-600 bg-violet-50 px-2 py-1 rounded-lg mt-0.5 shrink-0"><?= e($q['question_code']) ?></span>
                                                <div class="flex-1 min-w-0">
                                                    <h4 class="text-lg font-bold text-slate-800 leading-snug">
                                                        <?= e($q['question_text_en']) ?>
                                                    </h4>
                                                    <div
                                                        class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-[11px] text-slate-400">
                                                        <span><?= $totalVotes > 0 ? $totalVotes . ' ' . ($LANG['responses'] ?? 'responses') : ($LANG['no_responses_yet'] ?? 'No responses yet') ?></span>

                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($totalVotes > 0): ?>
                                            <div class="p-6">
                                                <div class="flex flex-col md:flex-row gap-6 items-start">
                                                    <div class="w-full md:w-1/3 flex justify-center">
                                                        <div class="relative" style="width:220px;height:220px;">
                                                            <canvas id="surveyChart_<?= $q['id'] ?>" data-type="survey" width="220"
                                                                height="220"></canvas>
                                                        </div>
                                                    </div>
                                                    <div class="w-full md:w-2/3 space-y-4">
                                                        <!-- Section annotation -->
                                                        <!-- <?php if ($totalVotes > 0 && !empty($mostSelected['indices'])): ?>
                                                            <span
                                                                class="inline-flex items-center gap-1 text-violet-700 bg-violet-50 px-2 py-0.5 rounded-md font-semibold">
                                                                <?= iconSvg('star', 'w-3.5 h-3.5') ?> <?= e($LANG['most_selected_answer'] ?? 'Most Selected Answer') ?>:
                                                                <?php
                                                                $mostSelectedLabels = [];
                                                                foreach ($mostSelected['indices'] as $msIndex) {
                                                                    if (isset($opts[$msIndex])) {
                                                                        $mostSelectedLabels[] = $opts[$msIndex];
                                                                    }
                                                                }
                                                                echo e(implode(' / ', $mostSelectedLabels));
                                                                ?>
                                                            </span>
                                                        <?php endif; ?> -->
                                                        <?php foreach ($opts as $idx => $opt):
                                                            $votes = $chartData[$idx];
                                                            $pct = $totalVotes > 0 ? round(($votes / $totalVotes) * 100) : 0;
                                                            $isMostSelected = in_array($idx, $mostSelected['indices']);
                                                            ?>
                                                            <div class="flex items-center gap-3">
                                                                <span class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white shadow-sm"
                                                                    style="background-color: <?= $chartColors[$idx] ?>"></span>
                                                                <div class="flex-1 min-w-0">
                                                                    <div class="flex items-center justify-between gap-2">
                                                                        <span class="text-sm font-semibold text-slate-700"><?= e($opt) ?></span>
                                                                        <div class="flex items-center gap-2 shrink-0">
                                                                            <span class="text-[11px] text-slate-400 font-medium"><?= $votes ?>
                                                                                <?= $votes != 1 ? ($LANG['votes'] ?? 'votes') : ($LANG['vote'] ?? 'vote') ?></span>
                                                                            <?php if ($isMostSelected): ?>
                                                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                                                                    fill="currentColor" class="w-4 h-4 text-violet-600">
                                                                                    <path fill-rule="evenodd"
                                                                                        d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                                                                                        clip-rule="evenodd" />
                                                                                </svg>
                                                                            <?php endif ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="w-full bg-slate-100 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                                                        <div class="h-full rounded-full transition-all duration-700"
                                                                            style="width: <?= $pct ?>%; background-color: <?= $chartColors[$idx] ?>">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if (!empty($mostSelected['indices'])): ?>
                                                <div
                                                    class="mx-6 mb-6 p-4 bg-gradient-to-br from-violet-50 to-purple-50 border border-violet-200 rounded-xl">
                                                    <p class="text-xs font-bold text-violet-600 uppercase tracking-wider mb-2">
                                                        <?= $LANG['most_selected_answer'] ?? 'Most Selected Answer' ?>
                                                        <?= count($mostSelected['indices']) > 1 ? 's' : '' ?>
                                                    </p>
                                                    <?php foreach ($mostSelected['indices'] as $msIdx):
                                                        $msLabel = $opts[$msIdx] ?? '';
                                                        $msCount = $mostSelected['max_votes'];
                                                        $msPct = $totalVotes > 0 ? round(($msCount / $totalVotes) * 100, 1) : 0;
                                                        ?>
                                                        <div class="flex items-center gap-3 mb-2 last:mb-0">
                                                            <?= iconSvg('star', 'w-5 h-5 text-amber-500') ?>
                                                            <div>
                                                                <p class="text-sm font-bold text-slate-800"><?= e($msLabel) ?></p>
                                                                <p class="text-xs text-slate-500">
                                                                    <span class="inline-flex items-center gap-1"><?= iconSvg('users', 'w-4 h-4') ?>
                                                                        <?= $msCount ?>
                                                                        <?= $LANG['students_label'] ?? 'Students' ?></span>
                                                                    <span class="mx-1.5">·</span>
                                                                    <span class="inline-flex items-center gap-1"><?= iconSvg('chart', 'w-4 h-4') ?>
                                                                        <?= $msPct ?>%</span>
                                                                </p>
                                                            </div>
                                                        </div>
                                                    <?php endforeach ?>
                                                </div>
                                            <?php endif ?>
                                        <?php else: ?>
                                            <div class="p-6">
                                                <p class="text-sm text-slate-400 italic text-center py-4">
                                                    <?= $LANG['no_responses_yet'] ?? 'No responses yet.' ?>
                                                </p>
                                                <div class="space-y-2">
                                                    <?php foreach ($opts as $idx => $opt): ?>
                                                        <div class="flex items-center gap-3">
                                                            <span class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white shadow-sm"
                                                                style="background-color: <?= $chartColors[$idx] ?>"></span>
                                                            <div class="flex-1 min-w-0">
                                                                <div class="flex items-center justify-between gap-2">
                                                                    <span class="text-sm font-semibold text-slate-700"><?= e($opt) ?></span>
                                                                    <span
                                                                        class="text-[11px] text-slate-400 font-medium"><?= $LANG['zero_votes'] ?? '0 votes' ?></span>
                                                                </div>
                                                                <div class="w-full bg-slate-100 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                                                    <div class="h-full rounded-full transition-all duration-700"
                                                                        style="width: 0%; background-color: <?= $chartColors[$idx] ?>"></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach ?>
                                                </div>
                                            </div>
                                        <?php endif ?>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>

                        <div
                            class="mt-8 pt-4 border-t border-blue-100/50 text-center text-[11px] text-blue-500 font-semibold italic">
                            "<?= $LANG['anonymous_note'] ?? 'This report is an automatic statistical system that maintains student privacy.' ?>"
                        </div>
                    </div>
                <?php endif ?>
            <?php elseif ($semesterFilter && empty($mySections)): ?>
                <div
                    class="bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 text-center py-20 text-slate-400">
                    <?= iconSvg('search', 'w-7 h-7 mx-auto mb-2') ?>
                    <p class="text-sm font-semibold">
                        <?= $LANG['no_feedback_semester'] ?? 'No feedback available for the selected semester.' ?>
                    </p>
                    <p class="text-xs mt-1">
                        <?= $LANG['no_feedback_results_semester'] ?? 'No feedback results for the selected semester.' ?>
                    </p>
                </div>
            <?php elseif ($sectionId): ?>
                <div
                    class="bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 text-center py-20 text-slate-400">
                    <?= iconSvg('document', 'w-7 h-7 mx-auto mb-2') ?>
                    <p class="text-sm font-semibold">
                        <?= $LANG['no_feedback_forms_for_section'] ?? 'No feedback forms for this section.' ?>
                    </p>
                </div>
            <?php else: ?>
                <div
                    class="bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 text-center py-20 text-slate-400 mt-4">
                    <?= iconSvg('chart', 'w-7 h-7 mx-auto mb-2') ?>
                    <p class="text-sm font-semibold">
                        <?= $LANG['select_section_first'] ?? 'Select a section to view results.' ?>
                    </p>
                    <p class="text-xs mt-1">
                        <?= $LANG['select_section_first'] ?? 'Select a section from the above to view results.' ?>
                    </p>
                </div>
            <?php endif ?>
        </div>
    </div>
    <script>
        function filterBySemester() {
            var semester = document.getElementById('semesterFilter').value;
            var url = new URL(window.location);
            if (semester) {
                url.searchParams.set('semester', semester);
            } else {
                url.searchParams.delete('semester');
            }
            url.searchParams.delete('section_id');
            url.searchParams.delete('form_id');
            window.location.href = url.href;
        }
        function toggleSection(id) {
            var url = new URL(window.location);
            var current = url.searchParams.get('section_id');
            if (current == id) {
                url.searchParams.delete('section_id');
            } else {
                url.searchParams.set('section_id', id);
            }
            url.searchParams.delete('form_id');
            window.location.href = url.href;
        }
        <?php if ($autoFormId): ?>
                (function () {
                    var url = new URL(window.location);
                    url.searchParams.set('form_id', '<?= $autoFormId ?>');
                    window.location.href = url.href;
                })();
        <?php endif ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script
        src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {

            // Animate progress bars
            document.querySelectorAll(".progress-bar-fill[data-target-width]").forEach(function (bar) {
                setTimeout(function () {
                    bar.style.width = bar.dataset.targetWidth;
                }, 400);
            });

            const chartInstances = {};

            // Custom plugin: draw center text inside doughnut chart
            const centerTextPlugin = {
                id: 'centerText',
                afterDraw: function (chart) {
                    const centerConfig = chart.options.plugins.centerText;
                    if (!centerConfig || !centerConfig.display) return;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return;
                    const centerX = (chartArea.left + chartArea.right) / 2;
                    const centerY = (chartArea.top + chartArea.bottom) / 2;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    if (centerConfig.mainText) {
                        ctx.font = 'bold 22px Inter, sans-serif';
                        ctx.fillStyle = centerConfig.mainColor || '#1e293b';
                        ctx.fillText(centerConfig.mainText, centerX, centerY - 8);
                    }
                    if (centerConfig.subText) {
                        ctx.font = '600 9px Inter, sans-serif';
                        ctx.fillStyle = centerConfig.subColor || '#94a3b8';
                        ctx.fillText(centerConfig.subText, centerX, centerY + 12);
                    }
                    ctx.restore();
                }
            };
            Chart.register(centerTextPlugin);

            function createChart(canvasId, labels, data, colors, showLegend = false, centerText) {
                const canvas = document.getElementById(canvasId);
                if (!canvas) {
                    console.warn("Canvas not found:", canvasId);
                    return;
                }
                if (typeof Chart === "undefined") {
                    console.error("Chart.js not loaded.");
                    return;
                }

                if (chartInstances[canvasId]) {
                    chartInstances[canvasId].destroy();
                }

                const isSurvey = canvas.getAttribute('data-type') === 'survey';
                const isOverall = canvas.getAttribute('data-type') === 'overall';

                const chartPlugins = [];
                if (isSurvey && typeof ChartDataLabels !== 'undefined') {
                    chartPlugins.push(ChartDataLabels);
                }

                chartInstances[canvasId] = new Chart(canvas, {
                    type: "doughnut",
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: colors,
                            borderColor: isOverall ? "transparent" : "#ffffff",
                            borderWidth: isOverall ? 0 : 3,
                            hoverOffset: isOverall ? 0 : 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        cutout: isOverall ? "80%" : "55%",
                        layout: { padding: 0 },
                        plugins: {
                            legend: {
                                //display: showLegend && !isOverall,
                                display: false,
                                position: "bottom",
                                labels: {
                                    usePointStyle: true,
                                    color: "#475569",
                                    padding: 12,
                                    font: { size: 11 }
                                }
                            },
                            tooltip: {
                                enabled: !isOverall,
                                backgroundColor: "rgba(15,23,42,.95)",
                                padding: 10,
                                titleFont: { size: 12, weight: 'bold' },
                                bodyFont: { size: 12 },
                                callbacks: {
                                    title: function () {
                                        if (isSurvey) return "";
                                        return null;
                                    },
                                    label: function (ctx) {
                                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                        const pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;

                                        if (isSurvey) {
                                            return pct + "%";
                                        } else {
                                            return ctx.label + ": " + ctx.raw + " (" + pct + "%)";
                                        }
                                    }
                                }
                            },
                            datalabels: false,
                            centerText: centerText || { display: false }
                            //datalabels: false,

                        },
                        animation: {
                            animateRotate: true,
                            animateScale: true,
                            duration: 900
                        }
                    },
                    plugins: chartPlugins
                });
            }

            // ==========================================
            // Section annotation
            // ==========================================
            <?php if (!empty($ratingQuestions)): ?>
                const scorePct = <?= (float) $overallPct ?>;
                const remainder = 100 - scorePct;
                const ringColor = "<?= match ($gradeColor) { 'emerald' => '#10b981', 'blue' => '#3b82f6', 'cyan' => '#06b6d4', 'amber' => '#f59e0b', 'red' => '#ef4444', default => '#6366f1'} ?>";

                createChart(
                    "overallRatingPieChart",
                    ["Earned Score", "Remaining"],
                    [scorePct, remainder],
                    [ringColor, "rgba(255, 255, 255, 0.12)"],
                    false
                );
            <?php endif; ?>

            // ==========================================
            // Rating Distribution Bar Chart
            // ==========================================
            <?php if (!empty($ratingQuestions)): ?>
                var barCanvas = document.getElementById('ratingBarChart');
                if (barCanvas) {
                    new Chart(barCanvas, {
                        type: 'bar',
                        data: {
                            labels: <?= json_encode(array_column(normalizeSurveyOptions(null), 'label')) ?>,
                            datasets: [{
                                label: <?= json_encode($LANG['ratings'] ?? 'Ratings') ?>,
                                data: <?= json_encode(array_values($likertTotals)) ?>,
                                backgroundColor: ['#16a34a', '#2563eb', '#8b5cf6', '#f97316', '#dc2626'],
                                borderWidth: 0,
                                borderRadius: 8,
                                barPercentage: 0.55
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: function (ctx) {
                                            var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                            var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                                            return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: { font: { family: 'Inter', size: 12, weight: '600' } }
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: '#f1f5f9' },
                                    ticks: { font: { family: 'Inter', size: 11 }, stepSize: 1 }
                                }
                            }
                        }
                    });
                }
            <?php endif; ?>

            // ===========================
            // Survey Charts
            // ===========================
            <?php
            $surveyChartData = [];
            foreach ($surveyQuestions as $q) {
                $options = array_column(normalizeSurveyOptions(null), 'label');
                $stats = $surveyResults[$q['id']] ?? [];
                $colors = ["#7c3aed", "#2563eb", "#059669", "#d97706", "#dc2626", "#0891b2", "#8b5cf6", "#ec4899"];

                $labels = [];
                $values = [];
                $bg = [];
                foreach ($options as $i => $option) {
                    $labels[] = $option;
                    $values[] = (int) ($stats[5 - $i] ?? 0);
                    $bg[] = $colors[$i % count($colors)];
                }
                $surveyChartData[$q['id']] = [
                    "labels" => $labels,
                    "data" => $values,
                    "colors" => $bg
                ];
            }
            ?>

            const surveys = <?= json_encode($surveyChartData) ?>;
            Object.keys(surveys).forEach(function (id) {
                var total = surveys[id].data.reduce(function (a, b) { return a + b; }, 0);
                createChart(
                    "surveyChart_" + id,
                    surveys[id].labels,
                    surveys[id].data,
                    surveys[id].colors,
                    true,
                    {
                        display: true,
                        // mainText: total + ' Total',
                        // subText: '',
                        // mainColor: '#1e293b',
                        // subColor: '#94a3b8'
                        mainText: total.toString(),
                        subText: 'Total',
                        mainColor: '#1e293b',
                        subColor: '#94a3b8'
                    }
                );
            });

        });
    </script>
    <script>
        var currentFormId = <?= (int) $formId ?>;
        var LANG = <?= json_encode([
            'loading' => $LANG['loading'] ?? 'Loading...',
            'completed' => $LANG['completed'] ?? 'Completed',
            'pending' => $LANG['pending'] ?? 'Pending',
            'total_responses' => $LANG['total_responses'] ?? 'Total Responses',
            'no_data' => $LANG['no_data_yet'] ?? 'No data yet',
            'all_assigned_students' => $LANG['all_assigned_students'] ?? 'All Assigned Students',
            'no_students_found' => $LANG['no_students_found'] ?? 'No students found.',
            'roll_no' => $LANG['roll_no_header'] ?? 'Roll No',
            'student_name' => $LANG['student_name'] ?? 'Student Name',
            'submitted' => $LANG['submitted_label'] ?? 'Submitted',
            'failed_load' => $LANG['failed_load_students'] ?? 'Failed to load student list.',
        ]) ?>;

        function openStudentModal(type) {
            var modal = document.getElementById('studentModal');
            var title = document.getElementById('modalTitle');
            var body = document.getElementById('modalBody');
            if (type === 'all') title.textContent = LANG.all_assigned_students;
            if (type === 'completed') title.textContent = LANG.completed;
            if (type === 'pending') title.textContent = LANG.pending;
            body.innerHTML = '<p class="text-center text-slate-400 py-8 text-sm">Loading...</p>';
            modal.classList.remove('hidden');
            fetch('feedback_results.php?ajax_students=1&form_id=' + currentFormId + '&type=' + type)
                .then(function (r) { return r.json(); })
                .then(function (students) {
                    if (students.error) { body.innerHTML = '<p class="text-center text-red-400 py-8 text-sm">' + students.error + '</p>'; return; }
                    title.textContent = title.textContent + ' (' + students.length + ')';
                    if (students.length === 0) { body.innerHTML = '<p class="text-center text-slate-400 py-8 text-sm">' + LANG.no_students_found + '</p>'; return; }
                    var html = '<div class="overflow-x-auto"><table class="w-full text-xs"><thead><tr class="border-b border-slate-200 bg-slate-50">';
                    html += '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold w-10">#</th>';
                    html += '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold">' + LANG.roll_no + '</th>';
                    html += '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold">' + LANG.student_name + '</th>';
                    html += (type === 'completed') ? '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold">' + LANG.submitted + '</th>' : '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold"></th>';
                    html += '</tr></thead><tbody class="divide-y divide-slate-100">';
                    for (var i = 0; i < students.length; i++) {
                        var s = students[i];
                        html += '<tr class="hover:bg-slate-50">';
                        html += '<td class="py-2 px-3 text-slate-400 font-medium">' + (i + 1) + '</td>';
                        html += '<td class="py-2 px-3 font-mono font-semibold text-slate-700">' + escHtml(s.roll_no || '—') + '</td>';
                        html += '<td class="py-2 px-3 font-medium text-slate-800">' + escHtml(s.name) + '</td>';
                        if (type === 'completed') {
                            html += '<td class="py-2 px-3 text-slate-500">' + (s.submitted_at || '—') + '</td>';
                        }
                        html += '</tr>';
                    }
                    html += '</tbody></table></div>';
                    body.innerHTML = html;
                })
                .catch(function () { body.innerHTML = '<p class="text-center text-red-400 py-8 text-sm">' + LANG.failed_load + '</p>'; });
        }
        function closeStudentModal() { document.getElementById('studentModal').classList.add('hidden'); }
        function escHtml(t) { var d = document.createElement('div'); d.appendChild(document.createTextNode(t || '')); return d.innerHTML; }
    </script>

    <div id="studentModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4"
        onclick="if(event.target===this)closeStudentModal()">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
                <h3 id="modalTitle" class="font-semibold text-slate-800"><?= $LANG['student_list'] ?? 'Student List' ?>
                </h3>
                <button onclick="closeStudentModal()"
                    class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition-colors"><?= iconSvg('x', 'w-5 h-5') ?></button>
            </div>
            <div id="modalBody" class="px-6 py-4 overflow-y-auto flex-1"></div>
        </div>
    </div>

    <?php require_once '../includes/teacher_footer.php'; ?>
</body>

</html>
