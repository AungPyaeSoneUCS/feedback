<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['nav_assignments'] ?? 'Student Assignments';
$activeMenu = 'assignments';

$sectionList = $conn->query("SELECT s.id, c.course_name, c.course_code, sm_sec.section_name, COALESCE(ay.year_name, '') AS academic_year, COALESCE(sm.semester_name, '') AS semester_name FROM sections s JOIN courses c ON s.course_id=c.id LEFT JOIN section_master sm_sec ON s.section_id=sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id ORDER BY c.course_name, sm_sec.section_name")->fetch_all(MYSQLI_ASSOC);
$studentList = $conn->query("SELECT st.id, u.name, st.roll_no FROM students st JOIN users u ON st.user_id=u.id ORDER BY st.roll_no")->fetch_all(MYSQLI_ASSOC);
$semesterList = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$academicYears = $conn->query("SELECT id, year_name FROM academic_years WHERE status='active' ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$allAcademicYears = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$sectionMasterList = $conn->query("SELECT section_name FROM section_master ORDER BY section_name ASC")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    // Roll Number Range အလိုက် ဘာသာရပ်အများကြီးကို တစ်ပြိုင်နက် အစုလိုက်သွင်းခြင်း (Method 2)
    if ($action === 'add_by_roll_range') {
        $roll_from = clean($_POST['roll_from'] ?? '');
        $roll_to = clean($_POST['roll_to'] ?? '');
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $classSection = clean($_POST['class_section'] ?? '');
        $sectionIds = $_POST['section_ids'] ?? []; // array from checkboxes

        if (!is_array($sectionIds))
            $sectionIds = [];
        $sectionIds = array_values(array_unique(array_map('intval', array_filter($sectionIds))));

        // Never trust the filtered checkbox list: every selected course section
        // must belong to the year, semester, and class section chosen by admin.
        $validAssignmentSelection = false;
        if ($academicYearId && $semesterId && $classSection && count($sectionIds) > 0) {
            $sectionPH = implode(',', array_fill(0, count($sectionIds), '?'));
            $validationStmt = $conn->prepare(
                "SELECT COUNT(*) AS cnt
                 FROM sections s
                 JOIN section_master sm ON sm.id = s.section_id
                 WHERE s.id IN ($sectionPH)
                   AND s.academic_year_id = ? AND s.semester_id = ?
                   AND sm.section_name = ?"
            );
            $validationTypes = str_repeat('i', count($sectionIds)) . 'iis';
            $validationStmt->bind_param($validationTypes, ...array_merge($sectionIds, [$academicYearId, $semesterId, $classSection]));
            $validationStmt->execute();
            $validAssignmentSelection = (int) $validationStmt->get_result()->fetch_assoc()['cnt'] === count($sectionIds);
            $validationStmt->close();
        }

        if ($academicYearId && $semesterId && $classSection && $roll_from && $roll_to && count($sectionIds) > 0 && $validAssignmentSelection) {
            // Parse prefix and numeric suffix from both roll numbers
            $fromParts = explode('-', $roll_from, 2);
            $toParts = explode('-', $roll_to, 2);
            $fromPrefix = $fromParts[0];
            $fromNum = isset($fromParts[1]) ? (int) $fromParts[1] : 0;
            $toPrefix = $toParts[0];
            $toNum = isset($toParts[1]) ? (int) $toParts[1] : 0;

            // Fetch ALL students, then filter by prefix + numeric range in PHP
            // (MySQL BETWEEN does string comparison which breaks "5CT1-2" vs "5CT1-10")
            $allStudents = $conn->query("SELECT id, roll_no FROM students")->fetch_all(MYSQLI_ASSOC);
            $matchedStudents = [];
            foreach ($allStudents as $stud) {
                $parts = explode('-', $stud['roll_no'], 2);
                $prefix = $parts[0];
                $num = isset($parts[1]) ? (int) $parts[1] : 0;
                if ($prefix === $fromPrefix && $prefix === $toPrefix && $num >= $fromNum && $num <= $toNum) {
                    $matchedStudents[] = $stud;
                }
            }

            if (count($matchedStudents) > 0) {
                $added = 0;
                $addedStudents = 0;
                $duplicates = 0;
                $failed = 0;
                $checkStmt = $conn->prepare("SELECT 1 FROM section_assignments WHERE student_id=? AND section_id=? LIMIT 1");
                $insertStmt = $conn->prepare("INSERT INTO section_assignments (student_id, section_id) VALUES (?,?)");
                foreach ($matchedStudents as $stud) {
                    $studentAdded = false;
                    foreach ($sectionIds as $secId) {
                        $studentId = (int) $stud['id'];
                        $checkStmt->bind_param('ii', $studentId, $secId);
                        if (!$checkStmt->execute()) {
                            $failed++;
                            continue;
                        }
                        $checkStmt->store_result();
                        $assignmentExists = $checkStmt->num_rows > 0;
                        $checkStmt->free_result();
                        if ($assignmentExists) {
                            $duplicates++;
                            continue;
                        }

                        $insertStmt->bind_param('ii', $studentId, $secId);
                        if ($insertStmt->execute() && $insertStmt->affected_rows === 1) {
                            $added++;
                            $studentAdded = true;
                        } else {
                            $failed++;
                        }
                    }
                    if ($studentAdded) {
                        $addedStudents++;
                    }
                }
                $checkStmt->close();
                $insertStmt->close();

                if ($failed > 0) {
                    setFlash('error', $LANG['flash_admin_one_or_more_student_assignments_could'] ?? 'One or more student assignments could not be saved.');
                } elseif ($added > 0) {
                    setFlash('success', $duplicates > 0
                        ? str_replace([':added', ':duplicates'], [$addedStudents, $duplicates], $LANG['flash_admin_assignments_added_with_duplicates'] ?? ":added student assignment(s) added successfully. :duplicates existing assignment(s) were skipped.")
                        : str_replace(':added', $addedStudents, $LANG['flash_admin_assignments_added'] ?? ":added student assignment(s) added successfully."));
                } else {
                    setFlash('error', $LANG['duplicate_range_error'] ?? 'This student range has already been assigned. Duplicate assignments are not allowed.');
                }
            } else {
                setFlash('error', $LANG['flash_admin_roll'] ?? 'ရိုက်ထည့်ထားသော Roll နံပါတ် အပိုင်းအခြားအတွင်း မည်သည့်ကျောင်းသားမှ မရှိပါ။');
            }
        } else {
            setFlash('error', !$validAssignmentSelection && count($sectionIds) > 0
                ? ($LANG['flash_admin_invalid_courses_selection'] ?? 'One or more selected courses do not belong to the chosen academic year, semester, and class section.')
                : ($LANG['assignment_required_fields'] ?? 'Academic Year, Semester, Class Section, roll number range, and at least one course are required.'));
        }
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if ($id && $sectionId) {
            $stmt = $conn->prepare("UPDATE section_assignments SET section_id=? WHERE id=?");
            $stmt->bind_param('ii', $sectionId, $id);
            if ($stmt->execute()) {
                setFlash('success', $LANG['flash_admin_assignment_updated_successfully'] ?? 'Assignment updated successfully.');
            } else {
                setFlash('error', $LANG['flash_admin_failed_to_update_assignment_may_already'] ?? 'Failed to update assignment (may already exist).');
            }
            $stmt->close();
        } else {
            setFlash('error', $LANG['flash_admin_invalid_assignment_or_section'] ?? 'Invalid assignment or section.');
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM section_assignments WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_assignment_removed'] ?? 'Assignment removed.') : setFlash('error', $LANG['flash_admin_failed'] ?? 'Failed.');
            $stmt->close();
        }
    }

    if ($action === 'delete_all_for_student') {
        $student = (int) ($_POST['student_id'] ?? 0);
        if ($student) {
            $stmt = $conn->prepare("DELETE FROM section_assignments WHERE student_id=?");
            $stmt->bind_param('i', $student);
            $stmt->execute();
            setFlash('success', $LANG['flash_admin_all_assignments_for_student_removed'] ?? 'All assignments for student removed.');
            $stmt->close();
        }
    }

    header('Location: section_assignments.php');
    exit;
}

if (isset($_GET['ajax_filter']) && $_GET['ajax_filter'] == '1') {
    header('Content-Type: application/json');
    $af_sem = (int) ($_GET['semester_id'] ?? 0);
    $af_sec = clean($_GET['section_id'] ?? '');
    $af_year = (int) ($_GET['academic_year_id'] ?? 0);

    if (!$af_year || !$af_sem || !$af_sec) {
        echo json_encode(['html' => '', 'count' => 0]);
        exit;
    }

    $q = "SELECT s.id, c.course_name, c.course_code, sm_sec.section_name, COALESCE(ay.year_name, '') AS academic_year, COALESCE(sm.semester_name, '') AS semester_name 
          FROM sections s JOIN courses c ON s.course_id=c.id LEFT JOIN section_master sm_sec ON s.section_id=sm_sec.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id";
    $w = [];
    $bp = [];
    $bt = "";
    if ($af_sem) {
        $w[] = "s.semester_id = ?";
        $bp[] = $af_sem;
        $bt .= "i";
    }
    if ($af_sec) {
        $w[] = "sm_sec.section_name = ?";
        $bp[] = $af_sec;
        $bt .= "s";
    }
    if ($af_year) {
        $w[] = "s.academic_year_id = ?";
        $bp[] = $af_year;
        $bt .= "i";
    }
    if ($w)
        $q .= " WHERE " . implode(" AND ", $w);
    $q .= " ORDER BY c.course_name, sm_sec.section_name";

    $stmt = $conn->prepare($q);
    if ($bp)
        $stmt->bind_param($bt, ...$bp);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $html = '';
    foreach ($rows as $sec) {
        $html .= '<label data-semester="' . e($sec['semester_name']) . '" data-section="' . e($sec['section_name']) . '" class="add-section-item flex items-center gap-3 px-3 py-1.5 hover:bg-white cursor-pointer rounded-lg transition-colors"><input type="checkbox" name="section_ids[]" value="' . $sec['id'] . '" class="add-section-cb w-4 h-4 rounded border-slate-300 text-cyan-600 accent-cyan-600"><div class="text-xs"><span class="font-semibold text-slate-800">' . e($sec['course_name']) . '</span><span class="font-bold text-cyan-600 bg-cyan-50 border border-cyan-200 px-1 rounded ml-1">Sec ' . e($sec['section_name']) . '</span><span class="text-slate-400 ml-2">(' . e(semesterToRoman($sec['semester_name'])) . ')</span></div></label>';
    }

    echo json_encode(['html' => $html, 'count' => count($rows)]);
    exit;
}

$search = clean($_GET['search'] ?? '');
$filter_semester = clean($_GET['filter_semester'] ?? '');
$filter_section = clean($_GET['filter_section'] ?? '');
$filter_year = (int) ($_GET['filter_year'] ?? 0);

$hasFilters = $search || $filter_semester || $filter_section || $filter_year;

$selectFrom = "FROM section_assignments sa 
              JOIN students st ON sa.student_id=st.id 
              JOIN users u ON st.user_id=u.id 
              JOIN sections s ON sa.section_id=s.id 
              JOIN courses c ON s.course_id=c.id
              LEFT JOIN section_master sm_sec ON s.section_id=sm_sec.id
              LEFT JOIN academic_years ay ON s.academic_year_id=ay.id
              LEFT JOIN semesters sm ON s.semester_id=sm.id";

$selectColumns = "SELECT sa.id AS assignment_id, sa.student_id, u.name, st.roll_no,
                  c.course_name, c.course_code, sm_sec.section_name, COALESCE(ay.year_name, '') AS academic_year, COALESCE(sm.semester_name, '') AS semester_name,
                  s.id AS section_id, sa.created_at";

$whereClauses = [];
$bindParams = [];
$bindTypes = "";

// if ($search) {
//     $whereClauses[] = "(u.name LIKE ? OR st.roll_no LIKE ? OR c.course_name LIKE ? OR c.course_code LIKE ?)";
//     $s = "%$search%";
//     $bindParams[] = $s;
//     $bindParams[] = $s;
//     $bindParams[] = $s;
//     $bindParams[] = $s;
//     //$bindParams[] = strtoupper(trim($search));
//     $bindTypes .= "ssss";
// }





// Search filter
if ($search) {
    $search = trim($search);

    /*
     * SECTION SEARCH
     * 
     * If search term matches a section name in section_master,
     * search the section column only.
     */

    $secMatch = $conn->prepare("SELECT section_name FROM section_master WHERE section_name = ?");
    $secMatch->bind_param('s', $search);
    $secMatch->execute();
    $isSectionSearch = $secMatch->get_result()->num_rows > 0;
    $secMatch->close();

    if ($isSectionSearch) {

        // Exact case-sensitive section search
        $whereClauses[] = "BINARY sm_sec.section_name = ?";

        $bindParams[] = $search;
        $bindTypes .= "s";

    } else {

        // Normal search:
        // Student name
        // Roll number
        // Course name
        // Course code
        $whereClauses[] = "(
            u.name LIKE ?
            OR st.roll_no LIKE ?
            OR c.course_name LIKE ?
            OR c.course_code LIKE ?
        )";

        $s = "%$search%";

        $bindParams[] = $s;
        $bindParams[] = $s;
        $bindParams[] = $s;
        $bindParams[] = $s;

        $bindTypes .= "ssss";
    }
}
if ($filter_semester) {
    $whereClauses[] = "s.semester_id = ?";
    $bindParams[] = (int) $filter_semester;
    $bindTypes .= "i";
}
if ($filter_section) {
    $whereClauses[] = "sm_sec.section_name = ?";
    $bindParams[] = $filter_section;
    $bindTypes .= "s";
}
if ($filter_year) {
    $whereClauses[] = "s.academic_year_id = ?";
    $bindParams[] = $filter_year;
    $bindTypes .= "i";
}

$whereStr = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Step 1: Fetch ALL assignment rows matching filters — one row per (student, course)
$assignmentQuery = "$selectColumns $selectFrom $whereStr";
$aStmt = $conn->prepare($assignmentQuery);
if ($bindParams) {
    $aStmt->bind_param($bindTypes, ...$bindParams);
}
$aStmt->execute();
$allAssignmentRows = $aStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$aStmt->close();

// Step 2: Group by student_id (int), deduplicate courses by assignment_id
$grouped = [];
foreach ($allAssignmentRows as $r) {
    $sid = (int) $r['student_id'];
    if (!isset($grouped[$sid])) {
        $grouped[$sid] = [
            'student_id' => $sid,
            'name' => $r['name'],
            'roll_no' => $r['roll_no'],
            'courses' => [],
            'latest_enrolled' => $r['created_at'],
        ];
    }
    $aid = (int) $r['assignment_id'];
    if (!isset($grouped[$sid]['_seen'][$aid])) {
        $grouped[$sid]['_seen'][$aid] = true;
        $grouped[$sid]['courses'][] = [
            'assignment_id' => $aid,
            'course_name' => $r['course_name'],
            'course_code' => $r['course_code'],
            'section' => $r['section_name'],
            'academic_year' => $r['academic_year'],
            'semester' => $r['semester_name'],
            'section_id' => (int) $r['section_id'],
            'created_at' => $r['created_at'],
        ];
    }
    if ($r['created_at'] > $grouped[$sid]['latest_enrolled']) {
        $grouped[$sid]['latest_enrolled'] = $r['created_at'];
    }
}

// Remove internal dedup keys
foreach ($grouped as &$st) {
    unset($st['_seen']);
}
unset($st);

// Step 3: Sort all students by roll_no — prefix first, then numeric suffix
$allStudents = array_values($grouped);
usort($allStudents, function ($a, $b) {
    $posA = strrpos($a['roll_no'], '-');
    $posB = strrpos($b['roll_no'], '-');
    $prefixA = $posA !== false ? substr($a['roll_no'], 0, $posA) : $a['roll_no'];
    $prefixB = $posB !== false ? substr($b['roll_no'], 0, $posB) : $b['roll_no'];
    $cmp = strcmp($prefixA, $prefixB);
    if ($cmp !== 0)
        return $cmp;
    $numA = $posA !== false ? (int) substr($a['roll_no'], $posA + 1) : 0;
    $numB = $posB !== false ? (int) substr($b['roll_no'], $posB + 1) : 0;
    return $numA <=> $numB;
});

// Step 4: Paginate at student level (after grouping — each student appears once)
$totalStudents = count($allStudents);
$total = $totalStudents;
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 15)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pg = paginate($totalStudents, $perPage, $page);
$rows = array_slice($allStudents, $pg['offset'], $perPage);

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <!-- <h2 class="text-xl font-bold text-slate-800"><?= $LANG['assignments_title'] ?? 'Section Assignments' ?></h2> -->
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG['assignments_subtitle'] ?? 'Enroll students into multiple course sections by Roll Number Range' ?>
        </p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('link', 'w-4 h-4') ?> <?= $LANG['assign_students'] ?? 'Assign Students (Roll Range အလိုက်အပ်ရန်)' ?>
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_student_course'] ?? 'Search student, course name...' ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>

            <select name="filter_year" onchange="this.form.submit()"
                class="border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                <option value=""><?= $LANG['all_academic_years'] ?? 'All Academic Years' ?></option>
                <?php foreach ($allAcademicYears as $ay): ?>
                    <option value="<?= $ay['id'] ?>" <?= $filter_year == $ay['id'] ? ' selected' : '' ?>>
                        <?= e($ay['year_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="filter_semester" onchange="this.form.submit()"
                class="border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                <?php foreach ($semesterList as $sm): ?>
                    <option value="<?= $sm['id'] ?>" <?= $filter_semester == $sm['id'] ? ' selected' : '' ?>>
                        <?= e(semesterToRoman($sm['semester_name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="filter_section" onchange="this.form.submit()"
                class="border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                <option value=""><?= $LANG['all_sections'] ?? 'All Sections' ?></option>
                <?php foreach ($sectionMasterList as $smSec): ?>
                    <option value="<?= e($smSec['section_name']) ?>" <?= $filter_section === $smSec['section_name'] ? 'selected' : '' ?>>
                        <?= $LANG["section_label"] ?? "Section" ?>     <?= e($smSec['section_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>

            <button type="submit"
                class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 font-semibold shadow-sm transition-colors whitespace-nowrap"><?= $LANG['search'] ?? 'Search' ?></button>
            <?php if ($search || $filter_semester || $filter_section || $filter_year): ?>
                <a href="section_assignments.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-white hover:bg-red-700 bg-red-500 transition-colors whitespace-nowrap"><?= $LANG['clear'] ?? 'Clear' ?></a>
            <?php endif ?>
        </form>
        <span class="text-xs text-slate-400 shrink-0"><?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_student'] ?? 'Student' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_course'] ?? 'Course' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['section_name'] ?? 'Section' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['year_semester'] ?? 'Year / Semester' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_enrolled'] ?? 'Enrolled' ?>
                    </th>
                    <th class="text-right px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_actions'] ?? 'Actions' ?>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows): ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <?php $courses = $row['courses'] ?? [];
                        $cnt = count($courses);
                        $first = $courses[0] ?? null; ?>
                        <tr class="summary-row hover:bg-slate-50/80 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3 min-w-[140px]">
                                <p class="text-sm font-semibold text-slate-800"><?= e($row['name']) ?></p>
                                <p class="text-xs font-mono text-slate-400 mt-0.5"><?= e($row['roll_no']) ?></p>
                            </td>
                            <td class="px-5 py-3">
                                <button type="button" onclick="toggleCourses(this)"
                                    class="flex items-center gap-1.5 text-sm font-medium text-slate-700 hover:text-cyan-600 cursor-pointer transition-colors group">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 font-bold rounded-md bg-cyan-50 text-cyan-700 border border-cyan-200/50 text-xs"><?= $cnt ?>
                                        course<?= $cnt !== 1 ? 's' : '' ?></span>
                                    <svg class="w-4 h-4 transition-transform duration-200 text-slate-400 group-hover:text-cyan-600"
                                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($first): ?>
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 font-bold rounded-md bg-cyan-50 text-cyan-700 border border-cyan-200/50 text-xs"><?= $LANG["section_label"] ?? "Section" ?>
                                        <?= e($first['section']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-500">
                                <?php if ($first): ?>
                                    <?= e($first['academic_year']) ?> · <?= e(semesterToRoman($first['semester'])) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-400"><?= formatDate($row['latest_enrolled']) ?></td>
                            <td class="px-5 py-3 text-right">
                                <button type="button"
                                    onclick="openBulkDelete(<?= (int) $row['student_id'] ?>, '<?= addslashes(e($row['name'])) ?>')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg"
                                    title="<?= $LANG['remove_all_assignments_modal'] ?? 'Remove All' ?>">
                                    <?= iconSvg('trash', 'w-3.5 h-3.5') ?>         <?= $LANG['remove_all'] ?? 'Remove All' ?>
                                </button>
                            </td>
                        </tr>
                        <?php foreach ($courses as $course): ?>
                            <tr class="course-detail-row hidden bg-slate-50/30">
                                <td colspan="2" class="px-5 py-0"></td>
                                <td class="px-5 py-2">
                                    <p class="text-sm font-medium text-slate-800"><?= e($course['course_name']) ?></p>
                                </td>
                                <td colspan="3" class="px-5 py-2"></td>
                                <td class="px-5 py-2 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button"
                                            onclick="openEdit(<?= (int) $course['assignment_id'] ?>, <?= (int) $course['section_id'] ?>, '<?= addslashes(e($row['name'])) ?>')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                            <?= iconSvg('edit', 'w-3.5 h-3.5') ?>             <?= $LANG["edit"] ?? "Edit" ?></button>
                                        </button>
                                        <button type="button"
                                            onclick="openDelete(<?= (int) $course['assignment_id'] ?>, '<?= addslashes(e($row['name'] . ' - ' . $course['course_name'])) ?>')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                            <?= iconSvg('trash', 'w-3.5 h-3.5') ?>             <?= $LANG["delete"] ?? "Delete" ?></button>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-16 text-slate-400">
                            <?= iconSvg('link', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm">
                                <?php if ($hasFilters): ?>
                                    <?= $LANG['no_assignments_for_filter'] ?? 'No assigned courses found for the selected semester and section.' ?>
                                <?php else: ?>
                                    <?= $LANG['no_assignments_found'] ?? 'No assignments found.' ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'section_assignments.php' . '?' . http_build_query(array_filter(['search' => $search, 'filter_semester' => $filter_semester, 'filter_section' => $filter_section, 'filter_year' => $filter_year ?: null])), $perPage) ?>
    </div>
</div>

<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl modal-box overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50">
            <div>
                <h3 class="font-bold text-slate-800 text-lg">
                    <?= $LANG['assign_by_roll_range'] ?? 'Assign Students by Roll Number Range' ?>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    <?= $LANG['assign_roll_range_hint'] ?? 'Roll နံပါတ် အပိုင်းအခြားအလိုက် ဘာသာရပ်များ အစုလိုက်သွင်းရန်' ?>
                </p>
            </div>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>

        <form method="POST" id="rangeTab">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_by_roll_range">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-sm font-semibold text-slate-700 mb-1"><?= $LANG['roll_from'] ?? 'Roll Number (From)' ?>
                            <span class="text-red-500">*</span></label>
                        <input type="text" name="roll_from" required placeholder="e.g. 4CS-1"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                    <div>
                        <label
                            class="block text-sm font-semibold text-slate-700 mb-1"><?= $LANG['roll_to'] ?? 'Roll Number (To)' ?>
                            <span class="text-red-500">*</span></label>
                        <input type="text" name="roll_to" required placeholder="e.g. 4CS-50"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                </div>

                <div class="">
                    <label
                        class="block text-sm font-semibold text-slate-700 mb-1"><?= $LANG['select_courses_sections'] ?? 'Select Courses / Sections (၎င်း Range အတွက် တစ်ခါတည်းအပ်မည့် ဘာသာရပ်များ)' ?></label>
                    <!-- <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-2">
                        <select id="addFilterYear" name="academic_year_id" required onchange="filterAddSections()"
                            class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                            <option value=""><?= $LANG['select_academic_year'] ?? 'Select Academic Year' ?> *</option>
                            <?php foreach ($allAcademicYears as $ay): ?>
                                <option value="<?= $ay['id'] ?>"><?= e($ay['year_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="addFilterSemester" name="semester_id" required onchange="filterAddSections()"
                            class="flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                            <option value=""><?= $LANG['select_semester'] ?? 'Select Semester' ?> *</option>
                            <?php foreach ($semesterList as $sm): ?>
                                <option value="<?= $sm['id'] ?>"><?= e(semesterToRoman($sm['semester_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="addFilterSection" name="class_section" required onchange="filterAddSections()"
                            class="flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                            <option value=""><?= $LANG['select_class_section'] ?? 'Select Class Section' ?> *</option>
                            <?php foreach ($sectionMasterList as $smSec): ?>
                                <option value="<?= e($smSec['section_name']) ?>"><?= $LANG["section_label"] ?? "Section" ?>
                                    <?= e($smSec['section_name']) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div> -->
                    <div class="flex flex-wrap sm:flex-nowrap gap-2 mb-2">
                        <!-- Academic Year (Given slightly more width or allowed to grow) -->
                        <select id="addFilterYear" name="academic_year_id" required onchange="filterAddSections()"
                            class="w-full sm:w-auto sm:flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/25 outline-none bg-white">
                            <option value="">
                                <?= $LANG['select_academic_year'] ?? 'Select Academic Year' ?> *
                            </option>
                            <?php foreach ($allAcademicYears as $ay): ?>
                                <option value="<?= $ay['id'] ?>">
                                    <?= e($ay['year_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Semester -->
                        <select id="addFilterSemester" name="semester_id" required onchange="filterAddSections()"
                            class="w-full sm:w-auto sm:flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/25 outline-none bg-white">
                            <option value="">
                                <?= $LANG['select_semester'] ?? 'Select Semester' ?> *
                            </option>
                            <?php foreach ($semesterList as $sm): ?>
                                <option value="<?= $sm['id'] ?>">
                                    <?= e(semesterToRoman($sm['semester_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Class Section -->
                        <select id="addFilterSection" name="class_section" required onchange="filterAddSections()"
                            class="w-full sm:w-auto sm:flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/25 outline-none bg-white">
                            <option value="">
                                <?= $LANG['select_class_section'] ?? 'Select Class Section' ?> *
                            </option>
                            <?php foreach ($sectionMasterList as $smSec): ?>
                                <option value="<?= e($smSec['section_name']) ?>">
                                    <?= $LANG["section_label"] ?? "Section" ?>
                                    <?= e($smSec['section_name']) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <p id="addRequiredMessage" class="mb-3 text-xs font-medium text-amber-600">
                        <?= $LANG['select_assignment_filters'] ?? 'Select Academic Year, Semester, and Class Section to load Teaching Assignments.' ?>
                    </p>
                    <div class="flex items-center justify-between mb-2">
                        <label class="flex items-center gap-2 text-xs text-slate-500 cursor-pointer select-none">
                            <input type="checkbox" id="addSelectAllVisible" disabled
                                class="w-3.5 h-3.5 rounded border-slate-300 text-cyan-600 accent-cyan-600">
                            <?= $LANG['select_all_visible'] ?? 'Select All Visible' ?>
                        </label>
                        <span id="addVisibleCount" class="text-xs text-slate-400"></span>
                    </div>
                    <div id="addSectionList" aria-disabled="true"
                        class="border border-slate-200 rounded-xl overflow-y-auto max-h-56 divide-y divide-slate-100 p-2 bg-slate-50/50 opacity-60 pointer-events-none">
                        <div class="text-center text-xs text-slate-400 py-4">
                            <?= $LANG['select_assignment_filters'] ?? 'Select Academic Year, Semester, and Class Section to load Teaching Assignments.' ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit" id="assignRangeButton" disabled
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-indigo-600"><?= $LANG['assign_range_process'] ?? 'Assign Range Process' ?></button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
                <h3 class="font-semibold text-slate-800"><?= $LANG['edit_assignment_modal'] ?? 'Edit Assignment' ?></h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    <?= $LANG['edit_assignment_hint'] ?? 'Change the section for' ?> <strong id="edit_student_name"
                        class="text-slate-600"></strong>
                </p>
            </div>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['new_section'] ?? 'New Section' ?>
                        <span class="text-red-500">*</span></label>
                    <input type="text" id="editSectionSearch"
                        placeholder="<?= $LANG['filter_sections'] ?? 'Filter sections...' ?>" autocomplete="off"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2 text-sm mb-2 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    <div id="editSectionList"
                        class="border border-slate-200 rounded-xl overflow-y-auto max-h-56 divide-y divide-slate-100">
                        <?php foreach ($sectionList as $sec): ?>
                            <label
                                class="edit-section-item flex items-center gap-3 px-4 py-2.5 hover:bg-cyan-50 cursor-pointer transition-colors"
                                data-label="[<?= e($sec['course_code']) ?>] <?= e($sec['course_name']) ?> - Sec <?= e($sec['section_name']) ?> (<?= e($sec['academic_year']) ?>)">
                                <input type="radio" name="section_id" value="<?= $sec['id'] ?>"
                                    class="w-4 h-4 border-slate-300 text-cyan-600 accent-cyan-600">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-slate-800"><?= e($sec['course_name']) ?>
                                        <span
                                            class="font-mono font-normal text-slate-400">(<?= e($sec['course_code']) ?>)</span>
                                    </p>
                                    <p class="text-xs text-slate-400">Sec <?= e($sec['section_name']) ?> ·
                                        <?= e($sec['academic_year']) ?> · <?= e(semesterToRoman($sec['semester_name'])) ?>
                                    </p>
                                </div>
                            </label>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm"><?= $LANG['save_changes'] ?? 'Save Changes' ?></button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div
                class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4 font-bold text-red-600">
                ⚠️</div>
            <!-- <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?></div> -->
            <h3 class="text-lg font-semibold text-slate-800">
                <?= $LANG['remove_assignment_modal'] ?? 'Remove Assignment' ?>
            </h3>
            <p class="text-sm text-slate-500 mt-2"><?= $LANG['remove_assignment_modal'] ?? 'Remove' ?> <strong
                    id="delete_name" class="text-slate-700"></strong>?</p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden"
                name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Remove' ?></button>
            </div>
        </form>
    </div>
</div>

<div id="bulkDeleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div
                class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4 font-bold text-red-600">
                ⚠️</div>
            <h3 class="text-lg font-semibold text-slate-800">
                <?= $LANG['remove_all_assignments_modal'] ?? 'Remove All Assignments' ?>
            </h3>
            <p class="text-sm text-slate-500 mt-2">
                <?= $LANG['remove_all_confirm'] ?? 'Confirm' ?>
                <strong id="bulk_delete_name" class="text-slate-700"></strong>?
            </p>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_all_for_student">
            <input type="hidden" name="student_id" id="bulk_student_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('bulkDeleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['remove_all_confirm'] ?? 'Remove All' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    var LANG = <?= json_encode([
        'loading' => $LANG['loading'] ?? 'Loading...',
        'selectAssignmentFilters' => $LANG['select_assignment_filters'] ?? 'Select Academic Year, Semester, and Class Section to load Teaching Assignments.',
        'noMatchingSections' => $LANG['no_matching_teaching_assignments'] ?? 'No matching Teaching Assignments.',
        'loadSectionsFailed' => $LANG['load_teaching_assignments_failed'] ?? 'Failed to load Teaching Assignments.',
    ]) ?>;
    function openEdit(id, currentSectionId, studentName) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_student_name').textContent = studentName;
        document.querySelectorAll('input[name="section_id"]').forEach(radio => {
            radio.checked = (parseInt(radio.value) === parseInt(currentSectionId));
        });
        document.getElementById('editSectionSearch').value = '';
        document.querySelectorAll('.edit-section-item').forEach(item => item.style.display = '');
        openModal('editModal');
    }

    function openDelete(id, description) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = description;
        openModal('deleteModal');
    }

    function openBulkDelete(studentId, name) {
        document.getElementById('bulk_student_id').value = studentId;
        document.getElementById('bulk_delete_name').textContent = name;
        openModal('bulkDeleteModal');
    }

    function toggleCourses(btn) {
        const summaryRow = btn.closest('tr');
        let next = summaryRow.nextElementSibling;
        const isExpanding = next && next.classList.contains('course-detail-row') && next.classList.contains('hidden');
        while (next && next.classList.contains('course-detail-row')) {
            if (isExpanding) {
                next.classList.remove('hidden');
            } else {
                next.classList.add('hidden');
            }
            next = next.nextElementSibling;
        }
        const svg = btn.querySelector('svg');
        if (svg) svg.style.transform = isExpanding ? 'rotate(180deg)' : '';
    }

    // Edit Modal filtering logic
    document.getElementById('editSectionSearch').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.edit-section-item').forEach(item => {
            item.style.display = item.dataset.label.toLowerCase().includes(q) ? '' : 'none';
        });
    });

    // Add Modal: Semester/Section filter for section checkboxes (AJAX)
    function filterAddSections() {
        const sem = document.getElementById('addFilterSemester').value;
        const sec = document.getElementById('addFilterSection').value;
        const year = document.getElementById('addFilterYear').value;
        const list = document.getElementById('addSectionList');
        const requiredMessage = document.getElementById('addRequiredMessage');
        const submitButton = document.getElementById('assignRangeButton');
        const selectAll = document.getElementById('addSelectAllVisible');
        const visibleCount = document.getElementById('addVisibleCount');
        const filtersComplete = Boolean(year && sem && sec);

        submitButton.disabled = !filtersComplete;
        selectAll.disabled = !filtersComplete;
        selectAll.checked = false;
        requiredMessage.classList.toggle('hidden', filtersComplete);
        list.classList.toggle('opacity-60', !filtersComplete);
        list.classList.toggle('pointer-events-none', !filtersComplete);
        list.setAttribute('aria-disabled', filtersComplete ? 'false' : 'true');

        if (!filtersComplete) {
            visibleCount.textContent = '';
            list.innerHTML = '<div class="text-center text-xs text-slate-400 py-4"></div>';
            list.firstElementChild.textContent = LANG.selectAssignmentFilters;
            return;
        }

        const params = new URLSearchParams({ ajax_filter: 1 });
        params.set('semester_id', sem);
        params.set('section_id', sec);
        params.set('academic_year_id', year);
        list.innerHTML = '<div class="text-center text-xs text-slate-400 py-4">' + LANG.loading + '</div>';

        fetch('section_assignments.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                list.innerHTML = data.html || '<div class="text-center text-xs text-slate-400 py-4"></div>';
                if (!data.html) list.firstElementChild.textContent = LANG.noMatchingSections;
                document.getElementById('addVisibleCount').textContent = data.count + ' item' + (data.count !== 1 ? 's' : '');
                list.querySelectorAll('.add-section-cb').forEach(cb => cb.addEventListener('change', updateSelectAllVisibleState));
                updateSelectAllVisibleState();
            })
            .catch(() => {
                list.innerHTML = '<div class="text-center text-xs text-red-400 py-4"></div>';
                list.firstElementChild.textContent = LANG.loadSectionsFailed;
                visibleCount.textContent = '';
            });
    }

    function updateSelectAllVisibleState() {
        const visibleCbs = [...document.querySelectorAll('#addSectionList .add-section-cb')];
        const allChecked = visibleCbs.length > 0 && visibleCbs.every(cb => cb.checked);
        document.getElementById('addSelectAllVisible').checked = allChecked;
    }

    document.getElementById('addSelectAllVisible').addEventListener('change', function () {
        const checked = this.checked;
        document.querySelectorAll('#addSectionList .add-section-item').forEach(item => {
            const cb = item.querySelector('.add-section-cb');
            if (cb) cb.checked = checked;
        });
    });

    document.querySelectorAll('.add-section-cb').forEach(cb => {
        cb.addEventListener('change', updateSelectAllVisibleState);
    });

    document.getElementById('rangeTab').addEventListener('submit', function (event) {
        const filtersComplete = document.getElementById('addFilterYear').value
            && document.getElementById('addFilterSemester').value
            && document.getElementById('addFilterSection').value;
        if (!filtersComplete) {
            event.preventDefault();
            document.getElementById('addRequiredMessage').classList.remove('hidden');
        }
    });

</script>
<?php include '../includes/admin_footer.php'; ?>