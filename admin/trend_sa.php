<?php
// ============================================================
// Admin – Student Affairs Trend Analysis Page
// ============================================================
// Analyzes Student Affairs feedback trends across Academic Years.
// Filters: Semester (required).
// Shows prompt until a Semester is selected.
// Does NOT modify any existing functionality.
// ============================================================

require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/trend_helpers.php';

requireRole('admin');

$pageTitle  = $LANG['sa_trend_analysis'] ?? 'Student Support Services – Trend Analysis';
$activeMenu = 'trend_sa';

// --- Filters ------------------------------------------------
$semId = (int) ($_GET['semester_id'] ?? 0);

// Dropdown data
$semesters = getTrendSemesters($conn, 'student_support_services');

// --- Trend Data (only if semester selected) -----------------
$trendData     = [];
$questionTrend = [];
$surveyTrend   = [];
$summary       = null;
$hasData       = false;
$hasMultipleAY = false;
$hasComparableHistory = false;
$ayImprovements = [];

if ($semId) {
    $allTrendData = getModuleRatingTrend($conn, 'student_support_services', $semId);
    $hasData = count($allTrendData) > 0;
    $hasMultipleAY = count($allTrendData) > 1;
    $trendData = comparableOverallRatingTrend($conn, $allTrendData);
    $hasComparableHistory = count($trendData) > 1;
    $summary = $hasComparableHistory ? buildTrendSummary($trendData) : null;

    for ($i = 0; $i < count($trendData); $i++) {
        if ($i === 0) {
            $ayImprovements[] = null;
        } else {
            $ayImprovements[] = calcImprovement(
                (float) $trendData[$i]['overall_rating'],
                (float) $trendData[$i - 1]['overall_rating']
            );
        }
    }
}

// --- Page Rendering -----------------------------------------
include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<!-- --- Page Header -------------------------------------------- -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <!-- <h2 class="text-2xl font-bold text-slate-800">📊 <?= e($pageTitle) ?></h2> -->
            <p class="text-sm text-slate-500 mt-1">
                <?= $LANG['sa_trend_desc'] ?? 'Analyze Student Support Services feedback trends across Academic Years' ?>
            </p>
        </div>
    </div>
</div>

<!-- --- Filters ------------------------------------------------ -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5 mb-6">
    <div class="flex flex-wrap items-end gap-4">
        <!-- Semester Filter -->
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">
                <?= $LANG['semester'] ?? 'Semester' ?>
            </label>
            <select id="trendSemFilter"
                onchange="window.location.href='trend_sa.php' + (this.value ? '?semester_id=' + this.value : '')"
                class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                <option value=""><?= $LANG['select_semester'] ?? '-- Select Semester --' ?></option>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $semId === (int) $s['id'] ? 'selected' : '' ?>>
                        <?= e($s['semester_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($semId): ?>
            <a href="trend_sa.php"
                class="px-4 py-2.5 text-sm font-medium text-white bg-red-500 hover:bg-red-700 rounded-xl transition-all">
                <?= $LANG['clear'] ?? 'Clear' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$semId): ?>
    <!-- --- Select Semester Prompt ---------------------------- -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-12 text-center">
        <div class="text-5xl mb-4">📭</div>
        <h3 class="text-lg font-semibold text-slate-700 mb-2">
            <?= $LANG['select_semester_prompt'] ?? 'Please select a Semester to view the Trend Analysis.' ?>
        </h3>
        <!-- <p class="text-sm text-slate-500">
            <?= $LANG['select_semester_prompt_desc'] ?? 'Choose a semester from the dropdown above to view Student Support Services feedback trend analysis across Academic Years.' ?>
        </p> -->
    </div>

<?php elseif (!$hasData): ?>
    <!-- No Data -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-12 text-center">
        <div class="text-5xl mb-4">📭</div>
        <h3 class="text-lg font-semibold text-slate-700 mb-2">
            <?= $LANG['no_trend_data_sa'] ?? 'No Student Support Services feedback data found for trend analysis.'  ?>
        </h3>
        <!-- <p class="text-sm text-slate-500">
            <?= $LANG['no_trend_data_sa'] ?? 'No Student Support Services feedback data found for trend analysis.' ?>
        </p> -->
    </div>

<?php elseif (!$hasMultipleAY): ?>
    <!-- Single AY -->
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-6">
        <div class="flex items-start gap-3">
            <span class="text-2xl">⚠️</span>
            <div>
                <h3 class="text-base font-semibold text-amber-800">
                    <?= $LANG['no_historical_data'] ?? 'No historical data available for comparison.' ?>
                </h3>
                <p class="text-sm text-amber-700 mt-1">
                    <?= $LANG['no_historical_data_desc'] ?? 'Trend analysis requires feedback data from at least two Academic Years. Currently only data from' ?>
                    <strong><?= e($trendData[0]['year_name']) ?></strong>
                    <?= $LANG['is_available'] ?? 'is available.' ?>
                </p>
            </div>
        </div>
    </div>
    <!-- <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6">
        <h3 class="text-base font-semibold text-slate-800 mb-4">
            <?= e($trendData[0]['year_name']) ?> – <?= $LANG['feedback_summary'] ?? 'Feedback Summary' ?>
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-purple-50 rounded-xl p-4 text-center">
                <p class="text-2xl font-bold text-purple-700"><?= $trendData[0]['overall_rating'] ?></p>
                <p class="text-xs text-purple-600 mt-1"><?= $LANG['overall_rating'] ?? 'Overall Rating' ?> (%)</p>
            </div>
            <div class="bg-emerald-50 rounded-xl p-4 text-center">
                <p class="text-2xl font-bold text-emerald-700"><?= (int) $trendData[0]['strongly_agree_count'] ?></p>
                <p class="text-xs text-emerald-600 mt-1"><?= $LANG['likert_strongly_agree'] ?? 'Strongly Agree' ?></p>
            </div>
            <div class="bg-slate-50 rounded-xl p-4 text-center">
                <p class="text-2xl font-bold text-slate-700"><?= (int) $trendData[0]['total_ratings'] ?></p>
                <p class="text-xs text-slate-600 mt-1"><?= $LANG['total_responses'] ?? 'Total Responses' ?></p>
            </div>
        </div>
    </div> -->

<?php elseif (!$hasComparableHistory): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-6">
        <h3 class="text-base font-semibold text-amber-800"><?= e($LANG['no_comparable_historical_data'] ?? 'No comparable historical data available.') ?></h3>
        <p class="text-sm text-amber-700 mt-1"><?= e($LANG['no_comparable_module_history_desc'] ?? 'Academic Years can be compared only when the Performance Grade matches and both Overall Ratings are valid.') ?></p>
    </div>
<?php else: ?>
    <!-- --- Full Trend Analysis -------------------------------- -->

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center text-xl">⭐</div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['overall_rating'] ?? 'Overall Rating' ?>
                </p>
            </div>
            <p class="text-3xl font-bold text-slate-800"><?= $summary['latest_overall'] ?><span
                    class="text-base font-normal text-slate-400">%</span></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-xl">🏆</div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['highest_rating'] ?? 'Highest Rating' ?>
                </p>
            </div>
            <p class="text-xl font-bold text-emerald-700"><?= e($summary['best_year']) ?></p>
            <p class="text-sm text-slate-500"><?= $summary['best_overall'] ?>%</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center text-xl">📉</div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['lowest_rating'] ?? 'Lowest Rating' ?>
                </p>
            </div>
            <p class="text-xl font-bold text-red-700"><?= e($summary['worst_year']) ?></p>
            <p class="text-sm text-slate-500"><?= $summary['worst_overall'] ?>%</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5 <?= $summary['trend_info']['bg'] ?>">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-white/60 flex items-center justify-center text-xl">
                    <?= $summary['trend_info']['icon'] ?></div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['trend_status'] ?? 'Trend Status' ?></p>
            </div>
            <p class="text-xl font-bold <?= $summary['trend_info']['color'] ?>">
                <?= e($summary['trend_info']['status']) ?></p>
            <p class="text-sm <?= $summary['trend_info']['color'] ?>">
                <?= $summary['overall_change_pct'] >= 0 ? '+' : '' ?><?= $summary['overall_change_pct'] ?>%</p>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-4">
                <?= $LANG['overall_rating_trend'] ?? 'Overall Rating Trend' ?></h3>
            <div class="relative" style="height: 300px;">
                <canvas id="trendOverallLineChart"></canvas>
            </div>
        </div> -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-4">
                <?= $LANG['overall_rating_comparison'] ?? 'Overall Rating Comparison' ?></h3>
            <div class="relative" style="height: 300px;">
                <canvas id="trendOverallBarChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Per-AY Details Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6 mb-6">
        <h3 class="text-base font-semibold text-slate-800 mb-4">
            <?= $LANG['yearly_breakdown'] ?? 'Year-by-Year Breakdown' ?></h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="text-left py-3 px-4 text-slate-500"><?= $LANG['academic_year'] ?? 'Academic Year' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['overall_rating'] ?? 'Overall Rating' ?></th>
                        <?php foreach(normalizeSurveyOptions(null) as $option):?><th class="text-center py-3 px-4 text-slate-500"><?=e($option['label'])?></th><?php endforeach;?>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['total'] ?? 'Total' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['change'] ?? 'Change' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['status'] ?? 'Status' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trendData as $i => $row): ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50/50 transition-colors">
                            <td class="py-3 px-4 font-medium text-slate-800"><?= e($row['year_name']) ?></td>
                            <td class="py-3 px-4 text-center">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold text-sm"><?= $row['overall_rating'] ?>%</span>
                            </td>
                            <td class="py-3 px-4 text-center font-medium"><?=(int)$row['strongly_agree_count']?></td><td class="py-3 px-4 text-center font-medium"><?=(int)$row['agree_count']?></td><td class="py-3 px-4 text-center font-medium"><?=(int)$row['neutral_count']?></td><td class="py-3 px-4 text-center font-medium"><?=(int)$row['disagree_count']?></td><td class="py-3 px-4 text-center font-medium"><?=(int)$row['strongly_disagree_count']?></td>
                            <td class="py-3 px-4 text-center text-slate-600"><?= (int) $row['total_ratings'] ?></td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($ayImprovements[$i] === null): ?>
                                    <span class="text-slate-400">—</span>
                                <?php else:
                                    $imp = $ayImprovements[$i];
                                    $impColor = $imp > 2 ? 'text-emerald-600' : ($imp < -2 ? 'text-red-600' : 'text-amber-600');
                                ?>
                                    <span class="font-semibold <?= $impColor ?>"><?= $imp >= 0 ? '+' : '' ?><?= $imp ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($ayImprovements[$i] === null): ?>
                                    <span class="text-slate-400">—</span>
                                <?php else:
                                    $info = trendStatusInfo($ayImprovements[$i]);
                                ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold <?= $info['badge'] ?>">
                                        <?= $info['icon'] ?> <?= e($info['status']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Chart.js Initialization -->
    <script>
        const trendLabels = <?= json_encode(array_column($trendData, 'year_name')) ?>;
        const trendOverallRatings = <?= json_encode(array_map('floatval', array_column($trendData, 'overall_rating'))) ?>;

        // Line Chart
        new Chart(document.getElementById('trendOverallLineChart'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: '<?= $LANG['overall_rating'] ?? 'Overall Rating' ?>',
                    data: trendOverallRatings,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139,92,246,0.08)',
                    fill: true, tension: 0.35, pointRadius: 6, pointHoverRadius: 9,
                    pointBackgroundColor: '#8b5cf6', pointBorderColor: '#fff', pointBorderWidth: 2, borderWidth: 3,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    y: { min: 0, max: 100, ticks: { stepSize: 10 }, title: { display: true, text: '<?= $LANG['overall_rating'] ?? 'Overall Rating' ?>', font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.04)' } },
                    x: { title: { display: true, text: '<?= $LANG['academic_year'] ?? 'Academic Year' ?>', font: { size: 11 } }, grid: { display: false } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: 'rgba(15,23,42,0.9)', padding: 12, cornerRadius: 8, callbacks: { label: ctx => '<?= $LANG['overall_rating'] ?? 'Overall Rating' ?>: ' + ctx.parsed.y.toFixed(1) + '%' } }
                }
            }
        });

        // Bar Chart
        const barColors = trendLabels.map((_, i) => {
            const shades = ['#c4b5fd', '#a78bfa', '#8b5cf6', '#7c3aed', '#6d28d9', '#5b21b6'];
            return shades[i % shades.length];
        });
        new Chart(document.getElementById('trendOverallBarChart'), {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [{ label: '<?= $LANG['overall_rating'] ?? 'Overall Rating' ?>', data: trendOverallRatings, backgroundColor: barColors, borderRadius: 8, borderSkipped: false, maxBarThickness: 60 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    y: { min: 0, max: 100, ticks: { stepSize: 10 }, title: { display: true, text: '<?= $LANG['overall_rating'] ?? 'Overall Rating' ?>', font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.04)' } },
                    x: { grid: { display: false } }
                },
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(15,23,42,0.9)', padding: 12, cornerRadius: 8, callbacks: { label: ctx => '<?= $LANG['overall_rating'] ?? 'Overall Rating' ?>: ' + ctx.parsed.y.toFixed(1) + '%' } } }
            }
        });
    </script>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
