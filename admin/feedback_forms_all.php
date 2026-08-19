<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['all_feedback_forms'] ?? 'All Feedback Forms';
$activeMenu = 'forms';

updateAllFeedbackStatuses($conn);

/* =========================================================
   BASIC DATA
========================================================= */

$academicYears = $conn->query("
    SELECT id, year_name
    FROM academic_years
    WHERE status='active'
    ORDER BY year_name DESC
")->fetch_all(MYSQLI_ASSOC);

$allAcademicYears = $conn->query("
    SELECT id, year_name
    FROM academic_years
    ORDER BY year_name DESC
")->fetch_all(MYSQLI_ASSOC);

$semesters = $conn->query("
    SELECT id, semester_name
    FROM semesters
    ORDER BY id ASC
")->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Section List
|--------------------------------------------------------------------------
| Used for Create and Edit searchable Section field.
| Includes:
| - Section
| - Course Code
| - Course Name
| - Academic Year
| - Semester
| - Teacher Name
|--------------------------------------------------------------------------
*/

$sectionList = $conn->query("
    SELECT 
        s.id,
        sm_sec.section_name AS section_name,
        s.course_id,
        s.teacher_id,
        s.academic_year_id,
        s.semester_id,

        c.course_name,
        c.course_code,

        COALESCE(ay.year_name, '') AS academic_year,

        sm.semester_name,

        u.name AS teacher_name

    FROM sections s

    LEFT JOIN section_master sm_sec 
        ON s.section_id = sm_sec.id

    JOIN courses c 
        ON s.course_id = c.id

    JOIN teachers t 
        ON s.teacher_id = t.id

    JOIN users u 
        ON t.user_id = u.id

    LEFT JOIN academic_years ay 
        ON s.academic_year_id = ay.id

    LEFT JOIN semesters sm 
        ON s.semester_id = sm.id

    ORDER BY 
        c.course_code ASC,
        sm_sec.section_name ASC,
        u.name ASC
")->fetch_all(MYSQLI_ASSOC);


/* =========================================================
   AJAX - QUESTION SETS
========================================================= */

if (isset($_GET['ajax_question_sets'])) {

    header('Content-Type: application/json');

    $ayId = (int) ($_GET['academic_year_id'] ?? 0);
    $mod = clean($_GET['module'] ?? 'teaching_quality');

    if ($ayId) {

        $stmt = $conn->prepare("
            SELECT 
                fqs.id,
                fqs.title,

                (
                    SELECT COUNT(*)
                    FROM feedback_questions
                    WHERE question_set_id = fqs.id
                ) AS question_count

            FROM feedback_question_sets fqs

            WHERE fqs.academic_year_id = ?
            AND fqs.module = ?
            AND fqs.status = 'active'

            ORDER BY fqs.title ASC
        ");

        $stmt->bind_param('is', $ayId, $mod);
        $stmt->execute();

        $sets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

        echo json_encode([
            'sets' => $sets
        ]);

    } else {

        echo json_encode([
            'sets' => []
        ]);
    }

    exit;
}


/* =========================================================
   POST ACTIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {

    $action = $_POST['action'] ?? '';


    /* =====================================================
       ADD FORM
    ===================================================== */

    if ($action === 'add') {

        $module = in_array(
            $_POST['module'] ?? '',
            ['teaching_quality', 'student_support_services', 'learning_environment']
        )
            ? $_POST['module']
            : 'teaching_quality';

        $ayId = (int) ($_POST['academic_year_id'] ?? 0);
        $semId = (int) ($_POST['semester_id'] ?? 0);

        $title = clean($_POST['title'] ?? '');

        $start = clean($_POST['start_date'] ?? '');
        $end = clean($_POST['end_date'] ?? '');

        $sec = (int) ($_POST['section_id'] ?? 0);

        /* ---------------------------------------------
           Auto-detect Question Set
        --------------------------------------------- */

        $qsStmt = $conn->prepare("
            SELECT fqs.id
            FROM feedback_question_sets fqs

            WHERE fqs.academic_year_id = ?
            AND fqs.module = ?
            AND fqs.status = 'active'

            LIMIT 1
        ");

        $qsStmt->bind_param(
            'is',
            $ayId,
            $module
        );

        $qsStmt->execute();

        $qsRow = $qsStmt
            ->get_result()
            ->fetch_assoc();

        $qsStmt->close();

        $questionSetId = $qsRow
            ? (int) $qsRow['id']
            : 0;


        /* ---------------------------------------------
           Validation
        --------------------------------------------- */

        if (
            !$ayId ||
            !$semId ||
            !$questionSetId ||
            !$title ||
            !$start ||
            !$end
        ) {

            setFlash('error', $LANG['flash_admin_all_fields_are_required_make_sure'] ?? 'All fields are required. Make sure a Question Set exists for the selected Year + Module.');

            header('Location: feedback_forms_all.php');
            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | Academic Only
        |--------------------------------------------------------------------------
        | Section is required only for Academic.
        |--------------------------------------------------------------------------
        */

        if ($module === 'teaching_quality' && !$sec) {

            setFlash('error', $LANG['flash_admin_section_is_required_for_teaching_quality'] ?? 'Section is required for Teaching Quality forms.');

            header('Location: feedback_forms_all.php');
            exit;
        }

        if ($module === 'teaching_quality') {
            $sectionConsistencyStmt = $conn->prepare("
                SELECT id
                FROM sections
                WHERE id = ?
                  AND academic_year_id = ?
                  AND semester_id = ?
                LIMIT 1
            ");
            $sectionConsistencyStmt->bind_param('iii', $sec, $ayId, $semId);
            $sectionConsistencyStmt->execute();
            $sectionIsConsistent = (bool) $sectionConsistencyStmt->get_result()->fetch_assoc();
            $sectionConsistencyStmt->close();

            if (!$sectionIsConsistent) {
                setFlash('error', $LANG['flash_admin_selected_section_does_not_belong_to'] ?? 'Selected section does not belong to the selected academic year and semester.');

                header('Location: feedback_forms_all.php');
                exit;
            }
        }


        /* ---------------------------------------------
           Date Validation
        --------------------------------------------- */

        $nowDateTime = date('Y-m-d H:i');

        $startDateTime = date(
            'Y-m-d H:i',
            strtotime($start)
        );

        $endDateTime = date(
            'Y-m-d H:i',
            strtotime($end)
        );


        if ($startDateTime < $nowDateTime) {

            setFlash('error', $LANG['flash_admin_start_date_cannot_be_before_now'] ?? 'Start date cannot be before now.');

            header('Location: feedback_forms_all.php');
            exit;
        }


        if ($endDateTime < $startDateTime) {

            setFlash('error', $LANG['flash_admin_end_date_must_be_after_start'] ?? 'End date must be after start date.');

            header('Location: feedback_forms_all.php');
            exit;
        }


        $formStatus = calculateFormStatus(
            $start,
            $end
        );


        /* ---------------------------------------------
           Academic Form
        --------------------------------------------- */

        if ($module === 'teaching_quality') {

            $dupStmt = $conn->prepare("
                SELECT ff.id
                FROM feedback_forms ff
                JOIN sections existing_section
                    ON existing_section.id = ff.section_id
                JOIN sections selected_section
                    ON selected_section.id = ?
                WHERE ff.module = 'teaching_quality'
                  AND ff.academic_year_id = ?
                  AND ff.semester_id = ?
                  AND existing_section.section_id <=> selected_section.section_id
                  AND existing_section.course_id = selected_section.course_id
                  AND existing_section.teacher_id = selected_section.teacher_id
                LIMIT 1
            ");

            $dupStmt->bind_param(
                'iii',
                $sec,
                $ayId,
                $semId
            );

            $dupStmt->execute();
            $duplicateAcademicForm = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();

            if ($duplicateAcademicForm) {
                setFlash(
                    'error',
                    $LANG['duplicate_feedback_form_academic']
                );

                header('Location: feedback_forms_all.php');
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO feedback_forms
                (
                    module,
                    academic_year_id,
                    semester_id,
                    question_set_id,
                    section_id,
                    title,
                    start_date,
                    end_date,
                    status
                )

                VALUES
                (
                    'teaching_quality',
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmt->bind_param(
                'iiiissss',
                $ayId,
                $semId,
                $questionSetId,
                $sec,
                $title,
                $start,
                $end,
                $formStatus
            );

        }


        /* ---------------------------------------------
           Student Affairs / Administration
        --------------------------------------------- */ else {

            /* Duplicate check: one SA/Admin form per AY+Semester */
            $dupStmt = $conn->prepare("
                SELECT id FROM feedback_forms
                WHERE module = ? AND academic_year_id = ? AND semester_id = ?
                LIMIT 1
            ");

            $dupStmt->bind_param(
                'sii',
                $module,
                $ayId,
                $semId
            );

            $dupStmt->execute();
            $dupRow = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();

            if ($dupRow) {

                setFlash(
                    'error',
                    $LANG['duplicate_feedback_form_module']
                );

                header('Location: feedback_forms_all.php');
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO feedback_forms
                (
                    module,
                    academic_year_id,
                    semester_id,
                    question_set_id,
                    title,
                    start_date,
                    end_date,
                    status
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmt->bind_param(
                'siiissss',
                $module,
                $ayId,
                $semId,
                $questionSetId,
                $title,
                $start,
                $end,
                $formStatus
            );
        }


        if ($stmt->execute()) {

            setFlash('success', $LANG['flash_admin_feedback_form_created'] ?? 'Feedback form created.');

        } else {

            setFlash('error', $LANG['flash_admin_failed'] ?? 'Failed.');
        }

        $stmt->close();
    }


    /* =====================================================
       EDIT FORM
    ===================================================== */

    if ($action === 'edit') {

        $id = (int) ($_POST['id'] ?? 0);

        $editModule = clean(
            $_POST['module'] ?? ''
        );

        $editAyId = (int) (
            $_POST['academic_year_id'] ?? 0
        );

        $editSemId = (int) (
            $_POST['semester_id'] ?? 0
        );

        $editQsId = (int) (
            $_POST['question_set_id'] ?? 0
        );

        $sec = (int) (
            $_POST['section_id'] ?? 0
        );

        $title = clean(
            $_POST['title'] ?? ''
        );

        $start = clean(
            $_POST['start_date'] ?? ''
        );

        $end = clean(
            $_POST['end_date'] ?? ''
        );

        if ($id && $title) {


            /*
            |--------------------------------------------------------------------------
            | Academic Section Validation
            |--------------------------------------------------------------------------
            */

            if (
                $editModule === 'teaching_quality' &&
                !$sec
            ) {

                setFlash('error', $LANG['flash_admin_section_is_required_for_teaching_quality'] ?? 'Section is required for Teaching Quality forms.');

                header('Location: feedback_forms_all.php');
                exit;
            }


            /* ---------------------------------------------
               Date Validation
            --------------------------------------------- */

            if ($start && $end) {

                $nowDateTime = date('Y-m-d H:i');

                $startDateTime = date(
                    'Y-m-d H:i',
                    strtotime($start)
                );

                $endDateTime = date(
                    'Y-m-d H:i',
                    strtotime($end)
                );


                /* Fetch existing start date */

                $origStmt = $conn->prepare("
                    SELECT start_date
                    FROM feedback_forms
                    WHERE id = ?
                ");

                $origStmt->bind_param(
                    'i',
                    $id
                );

                $origStmt->execute();

                $origRow = $origStmt
                    ->get_result()
                    ->fetch_assoc();

                $origStmt->close();


                $existingStart = date(
                    'Y-m-d H:i',
                    strtotime(
                        $origRow['start_date']
                    )
                );


                $startDateChanged = (
                    $startDateTime !==
                    $existingStart
                );


                if (
                    $startDateChanged &&
                    $startDateTime < $nowDateTime
                ) {

                    setFlash('error', $LANG['flash_admin_start_date_cannot_be_before_now'] ?? 'Start date cannot be before now.');

                    header('Location: feedback_forms_all.php');
                    exit;
                }


                if (
                    $endDateTime <=
                    $startDateTime
                ) {

                    setFlash('error', $LANG['flash_admin_end_date_must_be_after_start'] ?? 'End date must be after start date.');

                    header('Location: feedback_forms_all.php');
                    exit;
                }
            }


            $formStatus = calculateFormStatus(
                $start,
                $end
            );


            /* ---------------------------------------------
               Academic Edit
            --------------------------------------------- */

            if ($editModule === 'teaching_quality') {

                $stmt = $conn->prepare("
                    UPDATE feedback_forms

                    SET
                        section_id = ?,
                        title = ?,
                        start_date = ?,
                        end_date = ?,
                        status = ?,
                        academic_year_id = ?,
                        semester_id = ?,
                        question_set_id = ?

                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'isssiiiii',
                    $sec,
                    $title,
                    $start,
                    $end,
                    $formStatus,
                    $editAyId,
                    $editSemId,
                    $editQsId,
                    $id
                );

            }


            /* ---------------------------------------------
               SA / Administration Edit
            --------------------------------------------- */ else {

                /* Duplicate check: ensure no other form occupies
                   the same module + AY + Semester slot */
                $dupStmt = $conn->prepare("
                    SELECT id FROM feedback_forms
                    WHERE module = ? AND academic_year_id = ? AND semester_id = ?
                      AND id != ?
                    LIMIT 1
                ");

                $dupStmt->bind_param(
                    'siii',
                    $editModule,
                    $editAyId,
                    $editSemId,
                    $id
                );

                $dupStmt->execute();
                $dupRow = $dupStmt->get_result()->fetch_assoc();
                $dupStmt->close();

                if ($dupRow) {

                    setFlash(
                        'error',
                        $LANG['duplicate_feedback_form_module']
                    );

                    header('Location: feedback_forms_all.php');
                    exit;
                }

                $stmt = $conn->prepare("
                    UPDATE feedback_forms

                    SET
                        title = ?,
                        start_date = ?,
                        end_date = ?,
                        status = ?,
                        academic_year_id = ?,
                        semester_id = ?,
                        question_set_id = ?

                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'ssssiiii',
                    $title,
                    $start,
                    $end,
                    $formStatus,
                    $editAyId,
                    $editSemId,
                    $editQsId,
                    $id
                );
            }


            if ($stmt->execute()) {

                setFlash('success', $LANG['flash_admin_form_updated'] ?? 'Form updated.');

            } else {

                setFlash('error', $LANG['flash_admin_update_failed'] ?? 'Update failed.');
            }

            $stmt->close();
        }
    }


    /* =====================================================
       DELETE
    ===================================================== */

    $deleteAction = $_POST['action'] ?? $_GET['action'] ?? '';
    $deleteId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

    if ($deleteAction === 'delete' && $deleteId) {

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
            setFlash('error', $LANG['flash_admin_invalid_request'] ?? 'Invalid request.');
            header('Location: feedback_forms_all.php');
            exit;
        }

        $deleted = false;

        try {

            $conn->begin_transaction();

            /* -----------------------------------------
               Delete child records first, regardless of
               module, so all form-related feedback data
               is removed safely inside one transaction.
            ----------------------------------------- */

            $surveyStmt = $conn->prepare(
                "DELETE FROM feedback_survey_answers
                 WHERE submission_id IN (
                     SELECT id FROM feedback_submissions
                     WHERE form_id = ?
                 )"
            );
            $surveyStmt->bind_param('i', $deleteId);
            $surveyStmt->execute();
            $surveyStmt->close();

            $submissionsStmt = $conn->prepare(
                "DELETE FROM feedback_submissions
                 WHERE form_id = ?"
            );
            $submissionsStmt->bind_param('i', $deleteId);
            $submissionsStmt->execute();
            $submissionsStmt->close();

            $formStmt = $conn->prepare(
                "DELETE FROM feedback_forms
                 WHERE id = ?"
            );
            $formStmt->bind_param('i', $deleteId);
            $formStmt->execute();
            $deleted = ($formStmt->affected_rows > 0);
            $formStmt->close();

            if ($deleted) {
                $conn->commit();
            } else {
                $conn->rollback();
            }

        } catch (\Throwable $e) {
            $conn->rollback();
        }

        if ($deleted) {
            setFlash('success', $LANG['flash_admin_form_deleted'] ?? 'Form deleted.');
        } else {
            setFlash('error', $LANG['flash_admin_cannot_delete'] ?? 'Cannot delete.');
        }
    }


    header(
        'Location: feedback_forms_all.php'
    );

    exit;
}


/* =========================================================
   FILTERS
========================================================= */

$search = clean(
    $_GET['search'] ?? ''
);

$filterMod = clean(
    $_GET['module'] ?? ''
);

$filterAY = (int) (
    $_GET['ay_id'] ?? 0
);

$perPage = max(
    10,
    min(
        100,
        (int) (
            $_GET['per_page'] ?? 15
        )
    )
);

$page = max(
    1,
    (int) (
        $_GET['page'] ?? 1
    )
);


/* =========================================================
   WHERE
========================================================= */

$conds = [];

$params = '';

$types = '';

$searchTerm = '';


if ($filterMod) {

    $conds[] = "ff.module=?";

    $params .= 's';

    $types .= 's';
}


if ($filterAY) {

    $conds[] = "ff.academic_year_id=?";

    $params .= 'i';

    $types .= 'i';
}


if ($search) {

    $searchTerm = "%$search%";

    $conds[] = "
        (
            ff.title LIKE ?
            OR ff.module LIKE ?
        )
    ";

    $params .= 'ss';

    $types .= 'ss';
}


$where = $conds
    ? 'WHERE ' . implode(
        ' AND ',
        $conds
    )
    : '';


/* =========================================================
   COUNT
========================================================= */

$countSql = "
    SELECT COUNT(*) AS c
    FROM feedback_forms ff
    $where
";


if ($types) {

    $c = $conn->prepare(
        $countSql
    );

    $bindVals = [];


    if ($filterMod) {

        $bindVals[] =
            $filterMod;
    }


    if ($filterAY) {

        $bindVals[] =
            $filterAY;
    }


    if ($search) {

        $bindVals[] =
            $searchTerm;

        $bindVals[] =
            $searchTerm;
    }


    $c->bind_param(
        $types,
        ...$bindVals
    );

    $c->execute();


    $total = (int) (
        $c
            ->get_result()
            ->fetch_assoc()['c']
    );


    $c->close();

} else {

    $total = (int) (
        $conn
            ->query($countSql)
            ->fetch_assoc()['c']
    );
}


/* =========================================================
   PAGINATION
========================================================= */

$pg = paginate(
    $total,
    $perPage,
    $page
);

$off = $pg['offset'];


/* =========================================================
   MAIN QUERY
========================================================= */

$sql = "
    SELECT

        ff.*,

        ay.year_name,

        sm.semester_name,

        fqs.title AS question_set_title,

        /* Section Information */
        sm_sec.section_name AS section_name,

        /* Course Information */
        c.course_code,

        c.course_name,

        /* Teacher Information */
        u.name AS teacher_name,


        (
            SELECT COUNT(*)
            FROM feedback_submissions fs
            WHERE fs.form_id = ff.id
        ) AS submission_count,


        (
            SELECT COUNT(*)
            FROM feedback_questions fq
            WHERE fq.question_set_id =
                ff.question_set_id
        ) AS question_count


    FROM feedback_forms ff


    LEFT JOIN academic_years ay
        ON ff.academic_year_id = ay.id


    LEFT JOIN semesters sm
        ON ff.semester_id = sm.id


    LEFT JOIN feedback_question_sets fqs
        ON ff.question_set_id = fqs.id


    /* Section */
    LEFT JOIN sections sec
        ON ff.section_id = sec.id

    LEFT JOIN section_master sm_sec
        ON sec.section_id = sm_sec.id


    /* Course */
    LEFT JOIN courses c
        ON sec.course_id = c.id


    /* Teacher */
    LEFT JOIN teachers t
        ON sec.teacher_id = t.id


    /* User */
    LEFT JOIN users u
        ON t.user_id = u.id


    $where


    ORDER BY ff.id DESC


    LIMIT ? OFFSET ?
";


if ($types) {

    $stmt = $conn->prepare(
        $sql
    );

    $allBind = [];


    if ($filterMod) {

        $allBind[] =
            $filterMod;
    }


    if ($filterAY) {

        $allBind[] =
            $filterAY;
    }


    if ($search) {

        $allBind[] =
            $searchTerm;

        $allBind[] =
            $searchTerm;
    }


    $allBind[] =
        $perPage;

    $allBind[] =
        $off;


    $stmt->bind_param(
        $types . 'ii',
        ...$allBind
    );

} else {

    $stmt = $conn->prepare(
        $sql
    );

    $stmt->bind_param(
        'ii',
        $perPage,
        $off
    );
}


$stmt->execute();

$rows = $stmt
    ->get_result()
    ->fetch_all(
        MYSQLI_ASSOC
    );

$stmt->close();


include '../includes/admin_header.php';

include '../includes/admin_sidebar.php';

?>

<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">

    <div>

        <!-- <h2 class="text-xl font-bold text-slate-800">
            <?= $LANG["all_feedback_forms"] ?? "All Feedback Forms" ?>
        </h2> -->

        <p class="text-sm text-slate-500 mt-0.5">

            <?= $LANG["manage_feedback_forms_subtitle"] ??
                "Manage feedback forms for all modules   Teaching Quality, Student Support Services, and Learning Environment." ?>

        </p>

    </div>


    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">

        <?= iconSvg('plus', 'w-4 h-4') ?>

        <?= $LANG["create_form"] ?? "Create Form" ?>

    </button>

</div>


<?php renderFlash() ?>


<!-- =====================================================
     FILTERS
===================================================== -->

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-6">

    <form method="GET" class="flex items-end gap-3 flex-wrap">


        <div class="flex-1 min-w-[160px]">

            <label class="block text-xs font-semibold text-slate-500 mb-1">

                <?= $LANG["module"] ?? "Module" ?>

            </label>


            <select name="module"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 bg-white">

                <option value="">

                    <?= $LANG["all_modules"] ?? "All Modules" ?>

                </option>


                <option value="teaching_quality" <?= $filterMod === 'teaching_quality'
                    ? 'selected'
                    : '' ?>>

                    <?= $LANG["teaching_quality"] ?? "Teaching Quality" ?>

                </option>


                <option value="student_support_services" <?= $filterMod === 'student_support_services'
                    ? 'selected'
                    : '' ?>>

                    <?= $LANG["student_support_services"] ?? "Student Support Services" ?>

                </option>


                <option value="learning_environment" <?= $filterMod === 'learning_environment'
                    ? 'selected'
                    : '' ?>>

                    <?= $LANG["learning_environment"] ?? "Learning Environment" ?>

                </option>

            </select>

        </div>


        <div class="flex-1 min-w-[160px]">

            <label class="block text-xs font-semibold text-slate-500 mb-1">

                <?= $LANG["academic_year"] ?? "Academic Year" ?>

            </label>


            <select name="ay_id"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 bg-white">

                <option value="">

                    <?= $LANG["all_years"] ?? "All Years" ?>

                </option>


                <?php foreach ($allAcademicYears as $ay): ?>

                    <option value="<?= $ay['id'] ?>" <?= $filterAY == $ay['id']
                          ? 'selected'
                          : '' ?>>

                        <?= e($ay['year_name']) ?>

                    </option>

                <?php endforeach ?>

            </select>

        </div>


        <div class="flex-1 min-w-[200px]">

            <label class="block text-xs font-semibold text-slate-500 mb-1">

                <?= $LANG["search"] ?? "Search" ?>

            </label>


            <input type="text" name="search" value="<?= e($search) ?>"
                placeholder="<?= $LANG["search_forms_placeholder"] ?? "Search forms..." ?>"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500">

        </div>


        <div class="flex gap-2">

            <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700">

                <?= $LANG["search"] ?? "Search" ?>

            </button>


            <?php if ($filterMod || $search || $filterAY): ?>

                <a href="feedback_forms_all.php"
                    class="px-4 py-2 bg-red-600 text-white hover:bg-red-700 text-sm font-semibold rounded-xl">

                    <?= $LANG["reset"] ?? "Reset" ?>

                </a>

            <?php endif ?>

        </div>

    </form>

</div>


<!-- =====================================================
     TABLE
===================================================== -->

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">


    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-end">

        <span class="text-xs text-slate-400">

            <?= $LANG['total'] ?? 'Total' ?>

            <?= $total ?>

            <?= $total !== 1
                ? ($LANG['records'] ?? 'records')
                : ($LANG['record'] ?? 'record') ?>

        </span>

    </div>


    <div class="overflow-x-auto">

        <table class="w-full min-w-[2100px]">


            <thead class="bg-slate-200 border-b border-slate-200">

                <tr>


                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[60px]">
                        #
                    </th>


                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[180px]">
                        <?= $LANG["module"] ?? "Module" ?>
                    </th>


                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[280px]">
                        <?= $LANG["form_title"] ?? "Form Title" ?>
                    </th>


                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[180px]">
                        <?= $LANG["year_semester"] ?? "Year / Semester" ?>
                    </th>


                    <!-- Question Set -->

                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[280px]">

                        <?= $LANG["question_set"] ?? "Question Set" ?>

                    </th>


                    <!-- NEW SECTION COLUMN -->

                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[320px]">

                        <?= $LANG["section_name"] ?? "Section" ?>

                    </th>


                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[260px]">

                        <?= $LANG["col_duration"] ?? "Duration" ?>

                    </th>


                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold w-[160px]">

                        <?= $LANG["status"] ?? "Status" ?>

                    </th>


                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold w-[100px]">
                        <?= $LANG["col_questions"] ?? "Questions" ?>
                    </th>


                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold w-[100px]">
                        <?= $LANG["col_submissions"] ?? "Submissions" ?>
                    </th>


                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold w-[180px]">

                        <?= $LANG["col_actions"] ?? "Actions" ?>

                    </th>

                </tr>

            </thead>


            <tbody class="divide-y divide-slate-100">


                <?php if ($rows): ?>


                    <?php foreach ($rows as $i => $row): ?>


                        <tr class="hover:bg-slate-50 transition-colors">


                            <!-- # -->

                            <td class="px-5 py-3 text-sm text-slate-400">

                                <?= $pg['offset'] + $i + 1 ?>

                            </td>


                            <!-- MODULE -->

                            <td class="px-5 py-3 text-sm text-slate-800 font-medium">

                                <?= e($LANG[$row['module']] ?? match ($row['module']) {
                                    'teaching_quality' => 'Teaching Quality',
                                    'student_support_services' => 'Student Support Services',
                                    'learning_environment' => 'Learning Environment',
                                    default => $row['module'],
                                }) ?>

                            </td>


                            <!-- TITLE -->

                            <td class="px-5 py-3 text-sm font-medium text-slate-800">

                                <?= e($row['title']) ?>

                            </td>


                            <!-- YEAR / SEMESTER -->

                            <td class="px-5 py-3">

                                <p class="text-sm text-slate-700 font-semibold">

                                    <?= e($row['year_name'] ?? ' ') ?>

                                </p>


                                <p class="text-xs text-slate-400">

                                    <?= e(
                                        semesterToRoman(
                                            $row['semester_name'] ?? ''
                                        )
                                    ) ?>

                                </p>

                            </td>


                            <!-- QUESTION SET -->

                            <td class="px-5 py-3">

                                <?php if ($row['question_set_title']): ?>

                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">

                                        <?= e(
                                            $row['question_set_title']
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span class="text-xs text-red-400 italic">

                                        <?= $LANG["no_set"] ?? "No set" ?>

                                    </span>

                                <?php endif ?>

                            </td>


                            <!-- =================================================
                                 SECTION + COURSE + TEACHER
                            ================================================== -->

                            <td class="px-5 py-3">

                                <?php if (
                                    $row['module'] === 'teaching_quality' &&
                                    !empty($row['section_name'])
                                ): ?>


                                    <div class="space-y-1">


                                        <!-- SECTION -->

                                        <p class="text-sm font-semibold text-slate-700">

                                            Section <?= e(
                                                $row['section_name']
                                            ) ?>

                                        </p>


                                        <!-- COURSE -->

                                        <?php if (
                                            !empty($row['course_code']) ||
                                            !empty($row['course_name'])
                                        ): ?>

                                            <p class="text-xs text-slate-500">

                                                <?= e(
                                                    $row['course_code'] ?? ''
                                                ) ?>


                                                <?php if (
                                                    !empty($row['course_code']) &&
                                                    !empty($row['course_name'])
                                                ): ?>

                                                    -

                                                <?php endif ?>


                                                <?= e(
                                                    $row['course_name'] ?? ''
                                                ) ?>

                                            </p>

                                        <?php endif ?>


                                        <!-- TEACHER -->

                                        <?php if (
                                            !empty($row['teacher_name'])
                                        ): ?>

                                            <p class="text-xs text-indigo-600 font-medium">

                                                Teacher:
                                                <?= e(
                                                    $row['teacher_name']
                                                ) ?>

                                            </p>

                                        <?php endif ?>


                                    </div>


                                <?php else: ?>


                                    <span class="text-xs text-slate-400 italic">

                                        <?= $row['module'] === 'teaching_quality'
                                            ? 'No Section'
                                            : 'No Section' ?>

                                    </span>


                                <?php endif ?>

                            </td>


                            <!-- DURATION -->

                            <td class="px-5 py-3 text-xs text-slate-500 min-w-[200px]">

                                <p class="text-slate-700 font-medium">

                                    <?= formatDateTime(
                                        $row['start_date']
                                    ) ?>

                                </p>


                                <p class="text-slate-700 font-medium">

                                    <?= formatDateTime(
                                        $row['end_date']
                                    ) ?>

                                </p>

                            </td>


                            <!-- STATUS -->

                            <td class="px-5 py-3">

                                <?php

                                $nowTs = time();

                                $startTs = strtotime(
                                    $row['start_date']
                                );

                                $endTs = strtotime(
                                    $row['end_date']
                                );

                                if ($startTs !== false && $endTs !== false) {
                                    if ($nowTs < $startTs) {
                                        $dynStatus = 'upcoming';
                                    } elseif ($nowTs > $endTs) {
                                        $dynStatus = 'expired';
                                    } else {
                                        $dynStatus = 'active';
                                    }
                                } else {
                                    $dynStatus = getFeedbackStatus(
                                        $row['start_date'],
                                        $row['end_date']
                                    );
                                }

                                ?>


                                <?php if (
                                    $dynStatus === 'active'
                                ): ?>

                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">

                                        <?= $LANG["active"] ?? "Active" ?>

                                    </span>

                                <?php elseif (
                                    $dynStatus === 'upcoming'
                                ): ?>

                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">

                                        <?= $LANG["upcoming"] ?? "Upcoming" ?>

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">

                                        <?= $LANG["expired"] ?? "Expired" ?>

                                    </span>

                                <?php endif ?>

                            </td>


                            <!-- QUESTIONS -->

                            <td class="px-5 py-3 text-center">

                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-cyan-100 text-cyan-700">

                                    <?= $row['question_count'] ?>

                                </span>

                            </td>


                            <!-- SUBMISSIONS -->

                            <td class="px-5 py-3 text-center">

                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">

                                    <?= $row['submission_count'] ?>

                                </span>

                            </td>


                            <!-- ACTIONS -->

                            <td class="px-5 py-3 text-center">

                                <div class="flex items-center justify-center gap-1.5">


                                    <a href="results_all.php?form_id=<?= $row['id'] ?>"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-100 hover:bg-cyan-200 rounded-lg">

                                        <?= iconSvg(
                                            'chart',
                                            'w-3.5 h-3.5'
                                        ) ?>

                                        <?= $LANG["results_link"] ?? "Results" ?>

                                    </a>


                                    <button onclick="openEdit(<?= htmlspecialchars(
                                        json_encode($row),
                                        ENT_QUOTES
                                    ) ?>)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">

                                        <?= iconSvg(
                                            'edit',
                                            'w-3.5 h-3.5'
                                        ) ?>

                                        <?= $LANG["edit"] ?? "Edit" ?>

                                    </button>


                                    <!-- <button onclick="openDelete(
                                            <?= $row['id'] ?>,
                                            '<?= addslashes(
                                                e($row['title'])
                                            ) ?>'
                                        )"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">

                                        <?= iconSvg(
                                            'trash',
                                            'w-3.5 h-3.5'
                                        ) ?>

                                        <?= $LANG["delete"] ?? "Delete" ?>

                                    </button>
                                     -->
                                    <button onclick='openDelete(
        <?= (int) $row['id'] ?>,
        <?= json_encode(
            $row['title'],
            JSON_HEX_TAG |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_HEX_AMP
        ) ?>
    )' class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">

                                        <?= iconSvg(
                                            'trash',
                                            'w-3.5 h-3.5'
                                        ) ?>

                                        <?= $LANG["delete"] ?? "Delete" ?>

                                    </button>

                                </div>

                            </td>

                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td colspan="11" class="text-center py-16 text-slate-400">

                            <?= iconSvg(
                                'document',
                                'w-10 h-10 mx-auto mb-3 opacity-40'
                            ) ?>


                            <p class="text-sm">

                                <?= $LANG["no_feedback_forms_found"] ??
                                    "No feedback forms found." ?>

                            </p>

                        </td>

                    </tr>


                <?php endif ?>


            </tbody>

        </table>

    </div>


    <div class="px-5 py-4 border-t border-slate-100">

        <?php

        $pgParams = [];


        if ($filterMod) {

            $pgParams['module'] =
                $filterMod;
        }


        if ($filterAY) {

            $pgParams['ay_id'] =
                $filterAY;
        }


        if ($search) {

            $pgParams['search'] =
                $search;
        }


        $pgBase =
            'feedback_forms_all.php' .
            (
                $pgParams
                ? '?' . http_build_query($pgParams)
                : ''
            );


        echo paginationLinks(
            $pg,
            $pgBase,
            $perPage
        );

        ?>

    </div>

</div>


<!-- =====================================================
     ADD MODAL
===================================================== -->

<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>


    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">


        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">

            <h3 class="font-semibold text-slate-800">

                <?= $LANG["create_feedback_form"] ??
                    "Create Feedback Form" ?>

            </h3>

<!-- 
            <button type="button" onclick="closeCreateFormModal()" class="text-slate-400 hover:text-slate-600">

                <?= iconSvg(
                    'x',
                    'w-5 h-5'
                ) ?>

            </button> -->

        </div>


        <form method="POST" id="createForm">

            <?= csrfField() ?>


            <input type="hidden" name="action" value="add">


            <input type="hidden" name="question_set_id" id="add_question_set_id" value="0">


            <div class="px-6 py-5 space-y-2">


                <!-- MODULE -->

                <div>

                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">

                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">

                            1

                        </span>

                        Module

                        <span class="text-red-500">*</span>

                    </label>


                    <select name="module" id="add_module" required onchange="onModuleChange(this.value)"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">

                        <option value="">

                            <?= $LANG["select_module"] ??
                                "  Select Module  " ?>

                        </option>


                        <option value="teaching_quality">

                            <?= $LANG["teaching_quality"] ??
                                "Teaching Quality" ?>

                        </option>


                        <option value="student_support_services">

                            <?= $LANG["student_support_services"] ??
                                "Student Support Services" ?>

                        </option>


                        <option value="learning_environment">

                            <?= $LANG["learning_environment"] ??
                                "Learning Environment" ?>

                        </option>

                    </select>

                </div>


                <!-- ACADEMIC YEAR -->

                <div>

                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">

                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">

                            2

                        </span>

                        Academic Year

                        <span class="text-red-500">*</span>

                    </label>


                    <select name="academic_year_id" id="add_academic_year_id" required
                        onchange="loadQuestionSets(); filterAddSectionsBySelection()"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">


                        <option value="">

                            <?= $LANG["select_academic_year"] ??
                                "  Select Academic Year  " ?>

                        </option>


                        <?php foreach ($academicYears as $ay): ?>

                            <option value="<?= $ay['id'] ?>">

                                <?= e(
                                    $ay['year_name']
                                ) ?>

                            </option>

                        <?php endforeach ?>


                    </select>

                </div>


                <!-- SEMESTER -->

                <div>

                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">

                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">

                            3

                        </span>

                        Semester

                        <span class="text-red-500">*</span>

                    </label>


                    <select name="semester_id" id="add_semester_id" required onchange="filterAddSectionsBySelection()"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">


                        <option value="">

                            <?= $LANG["select_semester"] ??
                                "  Select Semester  " ?>

                        </option>


                        <?php foreach ($semesters as $sm): ?>

                            <option value="<?= $sm['id'] ?>">

                                <?= e(
                                    semesterToRoman(
                                        $sm['semester_name']
                                    )
                                ) ?>

                            </option>

                        <?php endforeach ?>


                    </select>

                </div>


                <!-- =====================================================
                     SEARCHABLE SECTION
                ====================================================== -->

                <div id="add_section_wrapper">

                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">

                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">

                            4

                        </span>

                        Section

                        <span class="text-red-500">*</span>

                    </label>


                    <input type="hidden" name="section_id" id="add_section_id" value="">


                    <div class="relative">

                        <input type="text" id="add_section_search" placeholder="Type to search Course, or Teacher..."
                            autocomplete="off"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none"
                            oninput="filterAddSections(this.value)" onfocus="showAddSectionDropdown()"
                            onblur="hideAddSectionDropdown()">


                        <div id="add_section_dropdown"
                            class="absolute z-20 w-full mt-1 bg-white border border-slate-200 rounded-xl shadow-lg max-h-60 overflow-y-auto hidden">


                            <?php foreach ($sectionList as $s): ?>

                                <div class="add-section-option px-4 py-2.5 cursor-pointer hover:bg-indigo-50 border-b border-slate-50 last:border-b-0 transition-colors"
                                    data-id="<?= $s['id'] ?>" data-academic-year-id="<?= (int) $s['academic_year_id'] ?>"
                                    data-semester-id="<?= (int) $s['semester_id'] ?>" data-search="<?= e(strtolower(
                                           $s['section_name'] . ' ' .
                                           $s['course_code'] . ' ' .
                                           $s['course_name'] . ' ' .
                                           $s['teacher_name'] . ' ' .
                                           $s['academic_year'] . ' ' .
                                           $s['semester_name']
                                       )) ?>" onmousedown="selectAddSection(this)">


                                    <div class="font-semibold text-sm text-slate-700">

                                        Section <?= e($s['section_name']) ?>

                                    </div>


                                    <div class="text-xs text-slate-500 mt-0.5">

                                        <?= e($s['course_code']) ?> - <?= e($s['course_name']) ?>

                                    </div>


                                    <div class="text-xs text-indigo-600 font-medium mt-0.5">

                                        Teacher: <?= e($s['teacher_name']) ?>

                                    </div>


                                    <div class="text-[11px] text-slate-400 mt-0.5">

                                        <?= e($s['academic_year']) ?> &middot; <?= e($s['semester_name']) ?>

                                    </div>


                                </div>

                            <?php endforeach ?>


                        </div>

                    </div>


                    <p class="text-xs text-slate-400 mt-1">

                        Type in the search box to filter and select a section.

                    </p>

                </div>


                <!-- QUESTION SET -->

                <div id="qs_info">

                    <label class="block text-sm font-medium text-slate-700 mb-1">

                        <?= $LANG["question_set_auto_detected"] ??
                            "Question Set (Auto-detected)" ?>

                    </label>


                    <div id="qs_loading" class="hidden text-xs text-slate-400 py-2">

                        <?= $LANG["loading_question_sets"] ??
                            "Loading question sets..." ?>

                    </div>


                    <div id="qs_empty" class="hidden">

                        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">

                            <?= iconSvg(
                                'question',
                                'w-4 h-4 inline mr-1'
                            ) ?>


                            <strong>

                                <?= $LANG["no_question_set_found"] ??
                                    "No Question Set found" ?>

                            </strong>

                            for the selected Year + Module.


                            <a href="question_sets.php" class="underline ml-1">

                                <?= $LANG["create_one_first"] ??
                                    "Create one first" ?>

                            </a>

                        </div>

                    </div>


                    <div id="qs_found" class="hidden">

                        <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3 text-xs text-indigo-700">

                            <?= iconSvg(
                                'check',
                                'w-4 h-4 inline mr-1'
                            ) ?>


                            <strong id="qs_found_title"></strong>

                            <span id="qs_found_count"></span>

                            questions

                        </div>

                    </div>

                </div>


                <!-- FORM TITLE -->

                <div>

                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">

                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">

                            5

                        </span>

                        Form Title

                        <span class="text-red-500">*</span>

                    </label>


                    <input type="text" name="title" required
                        placeholder="<?= $LANG["form_title_placeholder"] ?? "e.g. Midterm Evaluation" ?>"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">

                </div>


                <!-- DATE -->

                <div class="grid grid-cols-2 gap-4">


                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-1">

                            <?= $LANG["start_date_time"] ??
                                "Start Date & Time" ?>

                            <span class="text-red-500">*</span>

                        </label>


                        <input type="datetime-local" name="start_date" id="add_start_date" required
                            min="<?= date('Y-m-d\TH:i') ?>"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">

                    </div>


                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-1">

                            <?= $LANG["end_date_time"] ??
                                "End Date & Time" ?>

                            <span class="text-red-500">*</span>

                        </label>


                        <input type="datetime-local" name="end_date" id="add_end_date" required
                            min="<?= date('Y-m-d\TH:i') ?>"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">

                    </div>

                </div>


            </div>


            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">


                <button type="button" onclick="closeCreateFormModal()"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors">

                    <?= $LANG["cancel"] ?? "Cancel" ?>

                </button>


                <button type="submit" id="addSubmitBtn" disabled
                    class="flex-1 px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl disabled:opacity-50 disabled:cursor-not-allowed">

                    <?= $LANG["create"] ?? "Create" ?>

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     EDIT MODAL
===================================================== -->

<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>


    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">


        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">

            <h3 class="font-semibold text-slate-800">

                <?= $LANG["edit_feedback_form"] ??
                    "Edit Feedback Form" ?>

            </h3>


            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600">

                <?= iconSvg(
                    'x',
                    'w-5 h-5'
                ) ?>

            </button>

        </div>


        <form method="POST">


            <?= csrfField() ?>


            <input type="hidden" name="action" value="edit">


            <input type="hidden" name="id" id="edit_id">


            <input type="hidden" name="module" id="edit_module" value="">


            <input type="hidden" name="question_set_id" id="edit_question_set_id" value="0">


            <div class="px-6 py-5 space-y-4">


                <!-- YEAR / SEMESTER -->

                <div class="grid grid-cols-2 gap-4">


                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-1">

                            <?= $LANG["academic_year"] ??
                                "Academic Year" ?>

                        </label>


                        <input type="hidden" name="academic_year_id" id="hidden_edit_academic_year_id" value="">


                        <select id="edit_academic_year_id" disabled
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-slate-100 text-slate-500 cursor-not-allowed">


                            <option value="">

                                <?= $LANG["select_academic_year"] ??
                                    "  Select Academic Year  " ?>

                            </option>


                            <?php foreach ($academicYears as $ay): ?>

                                <option value="<?= $ay['id'] ?>">

                                    <?= e(
                                        $ay['year_name']
                                    ) ?>

                                </option>

                            <?php endforeach ?>


                        </select>

                    </div>


                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-1">

                            <?= $LANG["semester"] ??
                                "Semester" ?>

                        </label>


                        <select name="semester_id" id="edit_semester_id"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white">


                            <option value="">

                                <?= $LANG["select_semester"] ??
                                    "  Select Semester  " ?>

                            </option>


                            <?php foreach ($semesters as $sm): ?>

                                <option value="<?= $sm['id'] ?>">

                                    <?= e(
                                        semesterToRoman(
                                            $sm['semester_name']
                                        )
                                    ) ?>

                                </option>

                            <?php endforeach ?>


                        </select>

                    </div>

                </div>


                <!-- =====================================================
                     EDIT SEARCHABLE SECTION
                ====================================================== -->

                <div id="edit_section_wrapper">


                    <label class="block text-sm font-medium text-slate-700 mb-1">

                        <?= $LANG["section_name"] ??
                            "Section" ?>

                    </label>


                    <input type="hidden" name="section_id" id="edit_section_id" value="">


                    <div class="relative">

                        <input type="text" id="edit_section_search"
                            placeholder="Type to search Section, Course, or Teacher..." autocomplete="off"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white"
                            oninput="filterEditSections(this.value)" onfocus="showEditSectionDropdown()"
                            onblur="hideEditSectionDropdown()">


                        <div id="edit_section_dropdown"
                            class="absolute z-20 w-full mt-1 bg-white border border-slate-200 rounded-xl shadow-lg max-h-60 overflow-y-auto hidden">


                            <?php foreach ($sectionList as $s): ?>

                                <div class="edit-section-option px-4 py-2.5 cursor-pointer hover:bg-indigo-50 border-b border-slate-50 last:border-b-0 transition-colors"
                                    data-id="<?= $s['id'] ?>" data-search="<?= e(strtolower(
                                          $s['section_name'] . ' ' .
                                          $s['course_code'] . ' ' .
                                          $s['course_name'] . ' ' .
                                          $s['teacher_name'] . ' ' .
                                          $s['academic_year'] . ' ' .
                                          $s['semester_name']
                                      )) ?>" onmousedown="selectEditSection(this)">


                                    <div class="font-semibold text-sm text-slate-700">

                                        Section <?= e($s['section_name']) ?>

                                    </div>


                                    <div class="text-xs text-slate-500 mt-0.5">

                                        <?= e($s['course_code']) ?> - <?= e($s['course_name']) ?>

                                    </div>


                                    <div class="text-xs text-indigo-600 font-medium mt-0.5">

                                        Teacher: <?= e($s['teacher_name']) ?>

                                    </div>


                                    <div class="text-[11px] text-slate-400 mt-0.5">

                                        <?= e($s['academic_year']) ?> &middot; <?= e($s['semester_name']) ?>

                                    </div>


                                </div>

                            <?php endforeach ?>


                        </div>

                    </div>


                    <p class="text-xs text-slate-400 mt-1">

                        Type in the search box to filter and select a section.

                    </p>

                </div>


                <!-- QUESTION SET -->

                <div id="edit_qs_info">


                    <label class="block text-sm font-medium text-slate-700 mb-1">

                        <?= $LANG["question_set"] ??
                            "Question Set" ?>

                    </label>


                    <div id="edit_qs_loading" class="hidden text-xs text-slate-400 py-2">

                        <?= $LANG["loading_question_sets"] ??
                            "Loading question sets..." ?>

                    </div>


                    <div id="edit_qs_empty" class="hidden">

                        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">

                            <?= iconSvg(
                                'question',
                                'w-4 h-4 inline mr-1'
                            ) ?>


                            <strong>

                                <?= $LANG["no_question_set_found"] ??
                                    "No Question Set found" ?>

                            </strong>

                            for the selected Year + Module.

                        </div>

                    </div>


                    <div id="edit_qs_found" class="hidden">

                        <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3 text-xs text-indigo-700">

                            <?= iconSvg(
                                'check',
                                'w-4 h-4 inline mr-1'
                            ) ?>


                            <strong id="edit_qs_found_title"></strong>

                            <span id="edit_qs_found_count"></span>

                            questions

                        </div>

                    </div>

                </div>


                <!-- TITLE -->

                <div>

                    <label class="block text-sm font-medium text-slate-700 mb-1">

                        <?= $LANG["title"] ??
                            "Title" ?>

                    </label>


                    <input type="text" name="title" id="edit_title" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">

                </div>


                <!-- DATES -->

                <div class="grid grid-cols-2 gap-4">


                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-1">

                            Start Date & Time

                        </label>


                        <input type="datetime-local" name="start_date" id="edit_start"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">

                    </div>


                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-1">

                            End Date & Time

                        </label>


                        <input type="datetime-local" name="end_date" id="edit_end"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">

                    </div>

                </div>


            </div>


            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">


                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors">

                    <?= $LANG["cancel"] ?? "Cancel" ?>

                </button>


                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">

                    <?= $LANG["save"] ?? "Save" ?>

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     DELETE MODAL
===================================================== -->

<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>


    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">


        <div class="px-6 py-6 text-center">


            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">

                <?= iconSvg(
                    'trash',
                    'w-7 h-7 text-red-600'
                ) ?>

            </div>


            <h3 class="text-lg font-semibold text-slate-800">

                <?= $LANG["delete_form"] ??
                    "Delete Form" ?>

            </h3>


            <p class="text-sm text-slate-500 mt-2">



                <strong id="delete_name"
                    class="text-slate-700"></strong>  <?= $LANG['delete_section_name_confirm'] ?? 'Delete' ?>?

                <!-- All submissions will be lost. -->

            </p>

        </div>


        <form method="POST">


            <?= csrfField() ?>


            <input type="hidden" name="action" value="delete">


            <input type="hidden" name="id" id="delete_id">


            <div class="flex gap-3 px-6 pb-6">


                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors">

                    <?= $LANG["cancel"] ?? "Cancel" ?>

                </button>


                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">

                    <?= $LANG["delete"] ?? "Delete" ?>

                </button>

            </div>

        </form>

    </div>

</div>


<script>

    let questionSetRequestToken = 0;

    function resetCreateFormModal() {

        const form = document.getElementById('createForm');

        if (!form) {
            return;
        }

        // Invalidate any Question Set request started by the previous modal state.
        questionSetRequestToken++;
        form.reset();

        document.getElementById('add_question_set_id').value = '0';
        document.getElementById('add_section_id').value = '';
        document.getElementById('add_section_search').value = '';
        document.getElementById('add_section_dropdown').classList.add('hidden');
        document.querySelectorAll('.add-section-option').forEach(function (option) {
            option.style.display = '';
        });

        document.getElementById('add_section_wrapper').style.display = 'none';
        document.getElementById('qs_loading').classList.add('hidden');
        document.getElementById('qs_empty').classList.add('hidden');
        document.getElementById('qs_found').classList.add('hidden');
        document.getElementById('qs_found_title').textContent = '';
        document.getElementById('qs_found_count').textContent = '';
        document.getElementById('addSubmitBtn').disabled = true;
    }

    function closeCreateFormModal() {

        resetCreateFormModal();
        closeModal('addModal');
    }

    /* =====================================================
       LANGUAGE
    ===================================================== */

    var LANG = <?= json_encode([

        'val_question_set_required' =>
            $LANG['val_question_set_required'] ??
            'Please ensure a Question Set is available for the selected Year + Module.',

        'loading_question_sets' =>
            $LANG['loading_question_sets'] ??
            'Loading question sets...',

    ]) ?>;


    /* =====================================================
       MODULE CHANGE
    ===================================================== */

    function onModuleChange(val) {

        const sectionWrapper =
            document.getElementById(
                'add_section_wrapper'
            );


        const sectionInput =
            document.getElementById(
                'add_section_search'
            );


        const sectionHidden =
            document.getElementById(
                'add_section_id'
            );


        if (val === 'teaching_quality') {

            sectionWrapper.style.display =
                '';


        } else if (
            val === 'student_support_services' ||
            val === 'learning_environment'
        ) {

            sectionWrapper.style.display =
                'none';


            sectionHidden.value =
                '';


            sectionInput.value =
                '';


        } else {

            sectionWrapper.style.display =
                'none';


            sectionHidden.value =
                '';


            sectionInput.value =
                '';


        }


        loadQuestionSets();
    }


    /* =====================================================
       CREATE SECTION - CUSTOM DROPDOWN
    ===================================================== */

    function filterAddSections(query) {

        const search =
            query.trim().toLowerCase();

        const academicYear =
            document.getElementById('add_academic_year_id').value;

        const semester =
            document.getElementById('add_semester_id').value;


        const options =
            document.querySelectorAll(
                '.add-section-option'
            );


        let hasVisible = false;


        options.forEach(function (opt) {

            const text =
                opt.dataset.search || '';


            const belongsToAcademicYear =
                academicYear !== '' &&
                opt.dataset.academicYearId === academicYear;

            const belongsToSemester =
                semester === '' ||
                opt.dataset.semesterId === semester;

            const matchesSearch =
                !search || text.includes(search);

            const show =
                belongsToAcademicYear &&
                belongsToSemester &&
                matchesSearch;


            opt.style.display =
                show ? '' : 'none';


            if (show) {
                hasVisible = true;
            }
        });


        const dropdown =
            document.getElementById(
                'add_section_dropdown'
            );


        if (hasVisible) {

            dropdown.classList.remove(
                'hidden'
            );

        } else {

            dropdown.classList.add(
                'hidden'
            );
        }
    }


    function filterAddSectionsBySelection() {

        const academicYear =
            document.getElementById('add_academic_year_id').value;

        const semester =
            document.getElementById('add_semester_id').value;

        const selectedSection =
            document.getElementById('add_section_id');

        const search =
            document.getElementById('add_section_search');

        const sectionWrapper =
            document.getElementById('add_section_wrapper');

        const module =
            document.getElementById('add_module').value;

        // Semester/Year filtering must never remove the Section control itself.
        sectionWrapper.style.display = module === 'teaching_quality' ? '' : 'none';

        if (selectedSection.value) {
            const selectedOption = document.querySelector(
                '.add-section-option[data-id="' + selectedSection.value + '"]'
            );

            const selectionStillMatches =
                selectedOption &&
                selectedOption.dataset.academicYearId === academicYear &&
                (semester === '' || selectedOption.dataset.semesterId === semester);

            if (!selectionStillMatches) {
                selectedSection.value = '';
                search.value = '';
            }
        }

        // Do not retain a search phrase from a previous Year/Semester after its
        // selected Section has been cleared; it can incorrectly hide every option.
        if (!selectedSection.value) {
            search.value = '';
        }

        filterAddSections(search.value);
    }


    function showAddSectionDropdown() {

        const dropdown =
            document.getElementById(
                'add_section_dropdown'
            );


        const search =
            document.getElementById(
                'add_section_search'
            );


        if (search) {
            filterAddSections(search.value);
        }
    }


    function hideAddSectionDropdown() {

        const dropdown =
            document.getElementById(
                'add_section_dropdown'
            );


        dropdown.classList.add(
            'hidden'
        );
    }


    function selectAddSection(el) {

        const id =
            el.dataset.id;


        const lines =
            el.querySelectorAll(
                'div'
            );


        let label =
            '';


        lines.forEach(function (div) {

            const t =
                div.textContent.trim();


            if (t) {

                label +=
                    (label ? ' | ' : '') +
                    t;
            }
        });


        document.getElementById(
            'add_section_id'
        ).value =
            id;


        document.getElementById(
            'add_section_search'
        ).value =
            label;


        hideAddSectionDropdown();
    }


    /* =====================================================
       QUESTION SET
    ===================================================== */

    function loadQuestionSets() {

        const requestToken = ++questionSetRequestToken;

        const module =
            document.getElementById(
                'add_module'
            ).value;


        const academicYear =
            document.getElementById(
                'add_academic_year_id'
            ).value;


        const qsLoading =
            document.getElementById(
                'qs_loading'
            );


        const qsEmpty =
            document.getElementById(
                'qs_empty'
            );


        const qsFound =
            document.getElementById(
                'qs_found'
            );


        const submitBtn =
            document.getElementById(
                'addSubmitBtn'
            );


        const hiddenQsId =
            document.getElementById(
                'add_question_set_id'
            );


        if (
            !module ||
            !academicYear
        ) {

            qsLoading.classList.add(
                'hidden'
            );


            qsEmpty.classList.add(
                'hidden'
            );


            qsFound.classList.add(
                'hidden'
            );


            submitBtn.disabled =
                true;


            hiddenQsId.value =
                0;


            return;
        }


        qsLoading.classList.remove(
            'hidden'
        );


        qsEmpty.classList.add(
            'hidden'
        );


        qsFound.classList.add(
            'hidden'
        );


        submitBtn.disabled =
            true;


        hiddenQsId.value =
            0;


        fetch(
            'feedback_forms_all.php?ajax_question_sets=1&academic_year_id=' +
            academicYear +
            '&module=' +
            module
        )

            .then(
                r => r.json()
            )

            .then(
                data => {

                    if (
                        requestToken !== questionSetRequestToken ||
                        document.getElementById('add_module').value !== module ||
                        document.getElementById('add_academic_year_id').value !== academicYear
                    ) {
                        return;
                    }

                    qsLoading.classList.add(
                        'hidden'
                    );


                    if (
                        data.sets &&
                        data.sets.length > 0
                    ) {

                        const qs =
                            data.sets[0];


                        if (
                            qs.question_count > 0
                        ) {

                            hiddenQsId.value =
                                qs.id;


                            document
                                .getElementById(
                                    'qs_found_title'
                                )
                                .textContent =
                                qs.title;


                            document
                                .getElementById(
                                    'qs_found_count'
                                )
                                .textContent =
                                qs.question_count;


                            qsFound.classList.remove(
                                'hidden'
                            );


                            submitBtn.disabled =
                                false;

                        } else {

                            qsEmpty.classList.remove(
                                'hidden'
                            );


                            qsEmpty
                                .querySelector(
                                    'div'
                                )
                                .innerHTML =
                                '<strong>No questions found</strong> in the Question Set. <a href="question_sets.php" class="underline ml-1">Add questions first</a>.';
                        }

                    } else {

                        qsEmpty.classList.remove(
                            'hidden'
                        );
                    }

                }
            )

            .catch(
                () => {

                    if (requestToken !== questionSetRequestToken) {
                        return;
                    }

                    qsLoading.classList.add(
                        'hidden'
                    );


                    qsEmpty.classList.remove(
                        'hidden'
                    );
                }
            );
    }


    /* =====================================================
       DATE VALIDATION
    ===================================================== */

    (function () {


        function getNow() {

            const d =
                new Date();


            return (
                d.getFullYear() +
                '-' +
                String(
                    d.getMonth() + 1
                ).padStart(
                    2,
                    '0'
                ) +
                '-' +
                String(
                    d.getDate()
                ).padStart(
                    2,
                    '0'
                ) +
                'T' +
                String(
                    d.getHours()
                ).padStart(
                    2,
                    '0'
                ) +
                ':' +
                String(
                    d.getMinutes()
                ).padStart(
                    2,
                    '0'
                )
            );
        }


        const addStart =
            document.getElementById(
                'add_start_date'
            );


        const addEnd =
            document.getElementById(
                'add_end_date'
            );


        const editStart =
            document.getElementById(
                'edit_start'
            );


        const editEnd =
            document.getElementById(
                'edit_end'
            );


        const addModal =
            document.getElementById(
                'addModal'
            );


        if (addModal) {

            let addModalWasOpen = !addModal.classList.contains('hidden');

            const obs =
                new MutationObserver(
                    function () {

                        const addModalIsOpen = !addModal.classList.contains('hidden');

                        // Also reset for backdrop clicks and Escape, which use the
                        // shared closeModal helper rather than the modal buttons.
                        if (addModalWasOpen && !addModalIsOpen) {
                            resetCreateFormModal();
                        }

                        addModalWasOpen = addModalIsOpen;

                        if (addStart) {

                            addStart.min =
                                getNow();
                        }


                        if (addEnd) {

                            addEnd.min =
                                (
                                    addStart &&
                                    addStart.value
                                )
                                    ? addStart.value
                                    : getNow();
                        }

                    }
                );


            obs.observe(
                addModal,
                {
                    attributes: true,
                    attributeFilter: [
                        'class',
                        'style'
                    ]
                }
            );
        }


        if (
            addStart &&
            addEnd
        ) {

            addStart.addEventListener(
                'change',
                function () {

                    addEnd.min =
                        this.value ||
                        getNow();


                    if (
                        addEnd.value &&
                        addEnd.value <
                        this.value
                    ) {

                        addEnd.value =
                            this.value;
                    }

                }
            );
        }


        if (
            editStart &&
            editEnd
        ) {

            editStart.addEventListener(
                'change',
                function () {

                    editEnd.min =
                        this.value ||
                        getNow();


                    if (
                        editEnd.value &&
                        editEnd.value <
                        this.value
                    ) {

                        editEnd.value =
                            this.value;
                    }

                }
            );
        }


        /* CREATE FORM VALIDATION */

        const createForm =
            document.getElementById(
                'createForm'
            );


        if (createForm) {

            createForm.addEventListener(
                'submit',
                function (e) {


                    const qsId =
                        document
                            .getElementById(
                                'add_question_set_id'
                            )
                            .value;


                    const module =
                        document
                            .getElementById(
                                'add_module'
                            )
                            .value;


                    const sectionId =
                        document
                            .getElementById(
                                'add_section_id'
                            )
                            .value;


                    if (
                        !qsId ||
                        qsId === '0'
                    ) {

                        e.preventDefault();


                        alert(
                            LANG.val_question_set_required
                        );


                        return;
                    }


                    if (
                        module ===
                        'teaching_quality' &&
                        (
                            !sectionId ||
                            sectionId === '0'
                        )
                    ) {

                        e.preventDefault();


                        alert(
                            'Please select a Section for Academic form.'
                        );


                        return;
                    }

                }
            );
        }

    })();


    /* =====================================================
       EDIT SECTION - CUSTOM DROPDOWN
    ===================================================== */

    function filterEditSections(query) {

        const search =
            query.trim().toLowerCase();


        const options =
            document.querySelectorAll(
                '.edit-section-option'
            );


        let hasVisible = false;


        options.forEach(function (opt) {

            const text =
                opt.dataset.search || '';


            const show =
                !search ||
                text.includes(search);


            opt.style.display =
                show ? '' : 'none';


            if (show) {
                hasVisible = true;
            }
        });


        const dropdown =
            document.getElementById(
                'edit_section_dropdown'
            );


        if (hasVisible) {

            dropdown.classList.remove(
                'hidden'
            );

        } else {

            dropdown.classList.add(
                'hidden'
            );
        }
    }


    function showEditSectionDropdown() {

        const dropdown =
            document.getElementById(
                'edit_section_dropdown'
            );


        const search =
            document.getElementById(
                'edit_section_search'
            );


        const options =
            document.querySelectorAll(
                '.edit-section-option'
            );


        options.forEach(function (opt) {

            opt.style.display = '';
        });


        if (search) {

            dropdown.classList.remove(
                'hidden'
            );
        }
    }


    function hideEditSectionDropdown() {

        const dropdown =
            document.getElementById(
                'edit_section_dropdown'
            );


        dropdown.classList.add(
            'hidden'
        );
    }


    function selectEditSection(el) {

        const id =
            el.dataset.id;


        const lines =
            el.querySelectorAll(
                'div'
            );


        let label =
            '';


        lines.forEach(function (div) {

            const t =
                div.textContent.trim();


            if (t) {

                label +=
                    (label ? ' | ' : '') +
                    t;
            }
        });


        document.getElementById(
            'edit_section_id'
        ).value =
            id;


        document.getElementById(
            'edit_section_search'
        ).value =
            label;


        hideEditSectionDropdown();
    }


    /* =====================================================
       OPEN EDIT
    ===================================================== */

    function openEdit(row) {


        document.getElementById(
            'edit_id'
        ).value =
            row.id;


        document.getElementById(
            'edit_module'
        ).value =
            row.module || '';


        document.getElementById(
            'edit_question_set_id'
        ).value =
            row.question_set_id || 0;


        document.getElementById(
            'edit_academic_year_id'
        ).value =
            row.academic_year_id || '';


        document.getElementById(
            'hidden_edit_academic_year_id'
        ).value =
            row.academic_year_id || '';


        document.getElementById(
            'edit_semester_id'
        ).value =
            row.semester_id || '';


        document.getElementById(
            'edit_section_id'
        ).value =
            row.section_id || 0;


        /*
        |--------------------------------------------------------------------------
        | Put existing Section name into search input
        |--------------------------------------------------------------------------
        */

        const editSearch =
            document.getElementById(
                'edit_section_search'
            );


        if (
            row.section_name
        ) {

            const label =
                'Section ' +
                row.section_name +
                (row.course_code
                    ? ' - ' + row.course_code
                    : '') +
                (row.course_name
                    ? ' ' + row.course_name
                    : '') +
                (row.teacher_name
                    ? ' - Teacher: ' + row.teacher_name
                    : '');

            editSearch.value =
                label;

        } else {

            editSearch.value =
                '';
        }


        document.getElementById(
            'edit_title'
        ).value =
            row.title;


        document.getElementById(
            'edit_start'
        ).value =
            row.start_date
                .replace(
                    ' ',
                    'T'
                )
                .substring(
                    0,
                    16
                );


        document.getElementById(
            'edit_end'
        ).value =
            row.end_date
                .replace(
                    ' ',
                    'T'
                )
                .substring(
                    0,
                    16
                );


        const secWrapper =
            document.getElementById(
                'edit_section_wrapper'
            );


        /*
        |--------------------------------------------------------------------------
        | Section only for Academic
        |--------------------------------------------------------------------------
        */

        if (
            row.module ===
            'teaching_quality'
        ) {

            secWrapper.style.display =
                '';

        } else {

            secWrapper.style.display =
                'none';

        }


        /*
        |--------------------------------------------------------------------------
        | Question Set
        |--------------------------------------------------------------------------
        */

        var qsFound =
            document.getElementById(
                'edit_qs_found'
            );


        var qsEmpty =
            document.getElementById(
                'edit_qs_empty'
            );


        if (
            row.question_set_id &&
            row.question_set_id > 0
        ) {

            qsFound.classList.remove(
                'hidden'
            );


            qsEmpty.classList.add(
                'hidden'
            );


            document.getElementById(
                'edit_qs_found_title'
            ).textContent =
                row.question_set_title ||
                'Question Set';


            document.getElementById(
                'edit_qs_found_count'
            ).textContent =
                row.question_count ||
                '?';

        } else {

            qsFound.classList.add(
                'hidden'
            );


            qsEmpty.classList.remove(
                'hidden'
            );
        }


        openModal(
            'editModal'
        );
    }


    /* =====================================================
       DELETE
    ===================================================== */

    function openDelete(
        id,
        name
    ) {

        document.getElementById(
            'delete_id'
        ).value =
            id;


        document.getElementById(
            'delete_name'
        ).textContent =
            name;


        openModal(
            'deleteModal'
        );
    }

</script>


<?php

include '../includes/admin_footer.php';

?>
