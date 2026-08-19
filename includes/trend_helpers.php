<?php
// ============================================================
// Section annotation
// ============================================================
// These functions are used by admin and teacher trend pages.
// Section annotation
// ============================================================

/** Canonical numeric Likert rating expression (1-5). */
function trendRatingCase(): string
{
    return "fsa.rating";
}

// ============================================================
// Section annotation
// ============================================================

/**
 * Get overall rating trend per Academic Year for a specific teacher.
 * Optionally filtered by course.
 * Returns one independent count for every fixed Likert rating.
 */
function getAcademicRatingTrend(mysqli $conn, int $teacherId, ?int $courseId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.id AS ay_id, ay.year_name, sec.teacher_id, sec.course_id,
                   ROUND(AVG($rc) * 20, 1) AS overall_rating,
                   COUNT(fsa.id) AS total_ratings,
                   SUM(CASE WHEN ($rc) = 5 THEN 1 ELSE 0 END) AS strongly_agree_count,
                   SUM(CASE WHEN ($rc) = 4 THEN 1 ELSE 0 END) AS agree_count,
                   SUM(CASE WHEN ($rc) = 3 THEN 1 ELSE 0 END) AS neutral_count,
                   SUM(CASE WHEN ($rc) = 2 THEN 1 ELSE 0 END) AS disagree_count,
                   SUM(CASE WHEN ($rc) = 1 THEN 1 ELSE 0 END) AS strongly_disagree_count
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN sections sec ON ff.section_id = sec.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = 'teaching_quality'
              AND sec.teacher_id = ?";

    $types = 'i';
    $params = [$teacherId];

    if ($courseId) {
        $sql .= " AND sec.course_id = ?";
        $types .= 'i';
        $params[] = $courseId;
    }

    $sql .= " GROUP BY ay.id, ay.year_name, sec.teacher_id, sec.course_id ORDER BY ay.year_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get per-question rating trends for the academic module.
 * Groups by question_code to match questions across academic years.
 * Returns: [['year_name','question_code','question_text_en','avg_rating'], ...]
 */
function getAcademicQuestionTrend(mysqli $conn, int $teacherId, ?int $courseId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.year_name, fq.question_code, fq.question_text_en,
                   ROUND(AVG($rc), 2) AS avg_rating
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN sections sec ON ff.section_id = sec.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = 'teaching_quality'
              AND sec.teacher_id = ?";

    $types = 'i';
    $params = [$teacherId];

    if ($courseId) {
        $sql .= " AND sec.course_id = ?";
        $types .= 'i';
        $params[] = $courseId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_code, fq.question_text_en
              ORDER BY LENGTH(fq.question_code), fq.question_code, ay.year_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get survey option trends for the academic module.
 * Returns raw counts per academic year, question code, and rating.
 * Percentages are calculated in PHP.
 * Returns: [['year_name','question_code','question_text_en','rating','cnt'], ...]
 */
function getAcademicSurveyTrend(mysqli $conn, int $teacherId, ?int $courseId = null): array
{
    $sql = "SELECT ay.year_name, fq.question_code, fq.question_text_en,
                   fsa.rating, COUNT(*) AS cnt
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN sections sec ON ff.section_id = sec.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = 'teaching_quality'
              AND sec.teacher_id = ?";

    $types = 'i';
    $params = [$teacherId];

    if ($courseId) {
        $sql .= " AND sec.course_id = ?";
        $types .= 'i';
        $params[] = $courseId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_code, fq.question_text_en, fsa.rating
              ORDER BY LENGTH(fq.question_code), fq.question_code, ay.year_name, fsa.rating";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// ============================================================
// Section annotation
// ============================================================

/**
 * Get overall rating trend per Academic Year for SA or Administration module.
 * Optionally filtered by semester.
 */
function getModuleRatingTrend(mysqli $conn, string $module, ?int $semId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.id AS ay_id, ay.year_name,
                   ROUND(AVG($rc) * 20, 1) AS overall_rating,
                   COUNT(fsa.id) AS total_ratings,
                   SUM(CASE WHEN ($rc) = 5 THEN 1 ELSE 0 END) AS strongly_agree_count,
                   SUM(CASE WHEN ($rc) = 4 THEN 1 ELSE 0 END) AS agree_count,
                   SUM(CASE WHEN ($rc) = 3 THEN 1 ELSE 0 END) AS neutral_count,
                   SUM(CASE WHEN ($rc) = 2 THEN 1 ELSE 0 END) AS disagree_count,
                   SUM(CASE WHEN ($rc) = 1 THEN 1 ELSE 0 END) AS strongly_disagree_count
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = ?";

    $types = 's';
    $params = [$module];

    if ($semId) {
        $sql .= " AND ff.semester_id = ?";
        $types .= 'i';
        $params[] = $semId;
    }

    $sql .= " GROUP BY ay.id, ay.year_name ORDER BY ay.year_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get per-question rating trends for SA or Administration module.
 */
function getModuleQuestionTrend(mysqli $conn, string $module, ?int $semId = null): array
{
    $rc = trendRatingCase();
    $sql = "SELECT ay.year_name, fq.question_code, fq.question_text_en,
                   ROUND(AVG($rc), 2) AS avg_rating
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = ?";

    $types = 's';
    $params = [$module];

    if ($semId) {
        $sql .= " AND ff.semester_id = ?";
        $types .= 'i';
        $params[] = $semId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_code, fq.question_text_en
              ORDER BY LENGTH(fq.question_code), fq.question_code, ay.year_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get survey option trends for SA or Administration module.
 */
function getModuleSurveyTrend(mysqli $conn, string $module, ?int $semId = null): array
{
    $sql = "SELECT ay.year_name, fq.question_code, fq.question_text_en,
                   fsa.rating, COUNT(*) AS cnt
            FROM feedback_survey_answers fsa
            JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
            JOIN feedback_forms ff ON fsub.form_id = ff.id
            JOIN feedback_questions fq ON fsa.question_id = fq.id
            JOIN academic_years ay ON ff.academic_year_id = ay.id
            WHERE ff.module = ?";

    $types = 's';
    $params = [$module];

    if ($semId) {
        $sql .= " AND ff.semester_id = ?";
        $types .= 'i';
        $params[] = $semId;
    }

    $sql .= " GROUP BY ay.year_name, fq.question_code, fq.question_text_en, fsa.rating
              ORDER BY LENGTH(fq.question_code), fq.question_code, ay.year_name, fsa.rating";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// ============================================================
// Section annotation
// ============================================================

/**
 * Get list of teachers who have academic feedback data.
 */
function getTrendTeachers(mysqli $conn): array
{
    $sql = "SELECT DISTINCT t.id, u.name
            FROM teachers t
            JOIN users u ON t.user_id = u.id
            JOIN sections sec ON sec.teacher_id = t.id
            JOIN feedback_forms ff ON ff.section_id = sec.id
            WHERE ff.module = 'teaching_quality'
              AND ff.academic_year_id IS NOT NULL
            ORDER BY u.name ASC";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get list of courses for a specific teacher that have feedback data.
 * If no teacher specified, returns all courses with academic feedback.
 */
function getTrendCourses(mysqli $conn, ?int $teacherId = null): array
{
    $sql = "SELECT DISTINCT c.id, c.course_code, c.course_name
            FROM courses c
            JOIN sections sec ON sec.course_id = c.id
            JOIN feedback_forms ff ON ff.section_id = sec.id
            WHERE ff.module = 'teaching_quality'
              AND ff.academic_year_id IS NOT NULL";

    if ($teacherId) {
        $sql .= " AND sec.teacher_id = " . (int) $teacherId;
    }

    $sql .= " ORDER BY c.course_name ASC";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get list of semesters that have feedback data for a given module.
 */
function getTrendSemesters(mysqli $conn, string $module): array
{
    $stmt = $conn->prepare(
        "SELECT DISTINCT sm.id, sm.semester_name
         FROM semesters sm
         JOIN feedback_forms ff ON ff.semester_id = sm.id
         WHERE ff.module = ?
           AND ff.academic_year_id IS NOT NULL
         ORDER BY sm.id ASC"
    );
    $stmt->bind_param('s', $module);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// ============================================================
// CALCULATION HELPERS
// ============================================================

/**
 * Calculate improvement percentage between two values.
  * Section annotation
 */
function calcImprovement(float $current, float $previous): float
{
    if ($previous <= 0) return 0;
    return round((($current - $previous) / $previous) * 100, 2);
}

/**
 * Determine trend status based on improvement percentage.
 * Returns: ['status' => string, 'color' => string, 'icon' => string, 'bg' => string]
 */
function trendStatusInfo(float $improvementPct): array
{
    if ($improvementPct > 2) {
        return [
            'status' => 'Improving',
            'color'  => 'text-emerald-700',
            'bg'     => 'bg-emerald-50 border-emerald-200',
            'icon'   => iconSvg('chart','w-5 h-5'),
            'badge'  => 'bg-emerald-100 text-emerald-800',
        ];
    } elseif ($improvementPct < -2) {
        return [
            'status' => 'Declining',
            'color'  => 'text-red-700',
            'bg'     => 'bg-red-50 border-red-200',
            'icon'   => iconSvg('chart','w-5 h-5'),
            'badge'  => 'bg-red-100 text-red-800',
        ];
    } else {
        return [
            'status' => 'Stable',
            'color'  => 'text-amber-700',
            'bg'     => 'bg-amber-50 border-amber-200',
            'icon'   => iconSvg('chart','w-5 h-5'),
            'badge'  => 'bg-amber-100 text-amber-800',
        ];
    }
}

/**
 * Build a summary from trend data array.
 * Input: array of ['year_name' => ..., 'overall_rating' => ...]
 * Returns: ['count', 'latest_overall', 'best_year', 'best_overall', 'worst_year', 'worst_overall',
 *           'overall_change_pct', 'trend_info']
 */
/**
 * Return a stable signature for an Academic Year's complete Performance Grade
 * range configuration. Grade labels are deliberately excluded: trend
 * compatibility depends only on the configured Min Score + Max Score pairs.
 */
function performanceGradeRangeSignature(mysqli $conn, int $academicYearId): ?string
{
    if ($academicYearId < 1)
        return null;

    $stmt = $conn->prepare(
        "SELECT min_score, max_score
         FROM performance_grade_settings
         WHERE academic_year_id = ?
         ORDER BY min_score ASC, max_score ASC"
    );
    $stmt->bind_param('i', $academicYearId);
    $stmt->execute();
    $ranges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$ranges)
        return null;

    return implode('|', array_map(static function (array $range): string {
        return number_format((float) $range['min_score'], 2, '.', '')
            . ':'
            . number_format((float) $range['max_score'], 2, '.', '');
    }, $ranges));
}

function comparableOverallRatingTrend(mysqli $conn, array $trendData): array
{
    foreach ($trendData as &$row) {
        $overallRating = isset($row['overall_rating']) ? (float) $row['overall_rating'] : 0.0;
        $row['performance_grade_range_signature'] = null;
        if ($overallRating > 0 && !empty($row['ay_id']))
            $row['performance_grade_range_signature'] = performanceGradeRangeSignature($conn, (int) $row['ay_id']);
    }
    unset($row);

    if (count($trendData) < 2)
        return $trendData;

    $compatibleGroups = [];
    foreach ($trendData as $row) {
        $rangeSignature = $row['performance_grade_range_signature'] ?? null;
        if ($rangeSignature === null || !isset($row['overall_rating']) || (float) $row['overall_rating'] <= 0)
            continue;

        // Teaching Quality rows are scoped to an exact teacher/course pair.
        // The other modules have no teacher/course association, so both parts
        // intentionally resolve to the same neutral key for every year.
        $teacherKey = array_key_exists('teacher_id', $row) ? (string) ($row['teacher_id'] ?? '') : '';
        $courseKey = array_key_exists('course_id', $row) ? (string) ($row['course_id'] ?? '') : '';
        $groupKey = $teacherKey . ':' . $courseKey . ':' . $rangeSignature;
        $compatibleGroups[$groupKey][] = $row;
    }

    $comparable = [];
    foreach ($compatibleGroups as $group) {
        // Prefer the largest compatible multi-year series. On equal sizes the
        // later group wins because trend data is ordered by Academic Year.
        if (count($group) >= 2 && count($group) >= count($comparable))
            $comparable = $group;
    }

    return array_values($comparable);
}

function buildTrendSummary(array $trendData): array
{
    $count = count($trendData);
    if ($count === 0) {
        return [
            'count' => 0, 'latest_overall' => 0,
            'best_year' => '—', 'best_overall' => 0,
            'worst_year' => '—', 'worst_overall' => 0,
            'overall_change_pct' => 0,
            'trend_info' => trendStatusInfo(0),
        ];
    }

    $latest = end($trendData);
    $first  = reset($trendData);
    $best   = $trendData[0];
    $worst  = $trendData[0];

    foreach ($trendData as $d) {
        $overallRating = (float) $d['overall_rating'];
        if ($overallRating > (float) $best['overall_rating'])  $best = $d;
        if ($overallRating < (float) $worst['overall_rating']) $worst = $d;
    }

    $overallChange = ($count > 1)
        ? calcImprovement((float) $latest['overall_rating'], (float) $first['overall_rating'])
        : 0;

    return [
        'count'              => $count,
        'latest_overall'         => round((float) $latest['overall_rating'], 1),
        'best_year'          => $best['year_name'],
        'best_overall'           => round((float) $best['overall_rating'], 1),
        'worst_year'         => $worst['year_name'],
        'worst_overall'          => round((float) $worst['overall_rating'], 1),
        'overall_change_pct' => $overallChange,
        'trend_info'         => trendStatusInfo($overallChange),
    ];
}

/**
 * Process raw survey trend data into structured format.
 * Groups by question code, then by academic year, then by option.
 * Calculates percentages automatically.
 *
 * Returns: [question_code => [
 *   'text' => latest question text,
 *   'options' => [option labels from latest AY],
 *   'years' => [year_name, ...],
 *   'data' => [option_index => [year_name => percentage, ...], ...]
 * ]]
 */
function processSurveyTrend(array $rawData): array
{
    if (empty($rawData)) return [];

    // Step 1: Collect raw counts grouped by question code, year, and rating.
    $grouped = [];
    $qTexts  = [];
    $allYears = [];     // all unique year names

    foreach ($rawData as $row) {
        $qno  = $row['question_code'];
        $year = $row['year_name'];
        $rating = (int) $row['rating'];
        if($rating<1||$rating>5) continue;
        $oidx=5-$rating;
        $cnt  = (int) $row['cnt'];

        $grouped[$qno][$year][$oidx] = ($grouped[$qno][$year][$oidx] ?? 0) + $cnt;
        $qTexts[$qno] = $row['question_text_en'];   // overwritten with latest
        $allYears[$year] = true;
    }

    $yearList = array_keys($allYears);
    sort($yearList);

    // Step 2: Build structured output with percentages
    $result = [];
    foreach ($grouped as $qno => $yearData) {
        $options = array_column(normalizeSurveyOptions(null),'label');
        $optionCount = 5;

        $data = []; // option_index mapped to [year => pct]
        foreach ($yearData as $year => $optCounts) {
            $total = array_sum($optCounts);
            for ($i = 0; $i < $optionCount; $i++) {
                $c = $optCounts[$i] ?? 0;
                $pct = $total > 0 ? round(($c / $total) * 100, 1) : 0;
                $data[$i][$year] = $pct;
            }
        }

        $result[$qno] = [
            'text'    => $qTexts[$qno],
            'options' => $options,
            'years'   => $yearList,
            'data'    => $data,
        ];
    }

    return $result;
}

/**
 * Process raw question-wise rating data into structured format.
 * Groups by question code and collects per-academic-year averages.
 *
 * Returns: [question_code => [
 *   'text' => latest question text,
 *   'years' => [year_name, ...],
 *   'ratings' => [year_name => avg_rating, ...]
 * ]]
 */
function processQuestionTrend(array $rawData): array
{
    if (empty($rawData)) return [];

    $grouped  = [];
    $qTexts   = [];
    $allYears = [];

    foreach ($rawData as $row) {
        $qno  = $row['question_code'];
        $year = $row['year_name'];

        $grouped[$qno][$year] = (float) $row['avg_rating'];
        $qTexts[$qno] = $row['question_text_en'];
        $allYears[$year] = true;
    }

    $yearList = array_keys($allYears);
    sort($yearList);

    $result = [];
    foreach ($grouped as $qno => $yearData) {
        $result[$qno] = [
            'text'    => $qTexts[$qno],
            'years'   => $yearList,
            'ratings' => $yearData,
        ];
    }

    return $result;
}

/**
 * Color palette for chart datasets (multiple lines/bars).
 */
function trendChartColors(): array
{
    return [
        '#6366f1', '#3b82f6', '#8b5cf6', '#06b6d4', '#10b981',
        '#f59e0b', '#ef4444', '#ec4899', '#14b8a6', '#f97316',
        '#84cc16', '#a855f7', '#0ea5e9', '#d946ef', '#64748b',
    ];
}

