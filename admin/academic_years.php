<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['academic_years_title'] ?? 'Academic Years';
$activeMenu = 'academic_years';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $yearName = clean($_POST['year_name'] ?? '');
        if ($yearName && preg_match('/^\d{4}-\d{4}$/', $yearName)) {
            [$y1, $y2] = explode('-', $yearName);
            if ((int) $y2 !== (int) $y1 + 1) {
                setFlash('error', $LANG['flash_admin_invalid_academic_year_the_second_year'] ?? 'Invalid Academic Year. The second year must be exactly one year after the first (e.g. 2025-2026).');
            } else {
                $chk = $conn->prepare("SELECT 1 FROM academic_years WHERE year_name=?");
                $chk->bind_param('s', $yearName);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    setFlash('error', str_replace(':year', e($yearName), $LANG['flash_admin_academic_year_exists'] ?? 'Academic Year :year already exists.'));
                } else {
                    $maxRes = $conn->query("SELECT MAX(year_name) as max_year FROM academic_years");
                    $maxRow = $maxRes->fetch_assoc();
                    $maxYear = $maxRow['max_year'] ?? null;
                    $expectedYear = null;
                    if ($maxYear) {
                        [$maxY1, $maxY2] = explode('-', $maxYear);
                        $expectedYear = ((int) $maxY1 + 1) . '-' . ((int) $maxY2 + 1);
                    }
                    if ($expectedYear && $yearName !== $expectedYear) {
                        setFlash('error', str_replace(':expected', $expectedYear, $LANG['flash_admin_academic_year_must_be_sequential'] ?? "Academic Year must be sequential. The next required year is :expected."));
                    } else {
                        $stmt = $conn->prepare("INSERT INTO academic_years (year_name, status) VALUES (?, 'active')");
                        $stmt->bind_param('s', $yearName);
                        if ($stmt->execute()) {
                            ensurePerformanceGradeSettings($conn, (int) $stmt->insert_id);
                            setFlash('success', $LANG['flash_admin_academic_year_added'] ?? 'Academic Year added.');
                        } else {
                            setFlash('error', $LANG['flash_admin_failed'] ?? 'Failed.');
                        }
                        $stmt->close();
                    }
                }
                $chk->close();
            }
        } else {
            setFlash('error', $LANG['flash_admin_please_enter_a_valid_year_format'] ?? 'Please enter a valid year format (e.g. 2025-2026).');
        }
    }
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $yearName = clean($_POST['year_name'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        if ($id && $yearName && preg_match('/^\d{4}-\d{4}$/', $yearName)) {
            [$y1, $y2] = explode('-', $yearName);
            if ((int) $y2 !== (int) $y1 + 1) {
                setFlash('error', $LANG['flash_admin_invalid_academic_year_the_second_year'] ?? 'Invalid Academic Year. The second year must be exactly one year after the first (e.g. 2025-2026).');
            } else {
                $chk = $conn->prepare("SELECT 1 FROM academic_years WHERE year_name=? AND id!=?");
                $chk->bind_param('si', $yearName, $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    setFlash('error', str_replace(':year', e($yearName), $LANG['flash_admin_academic_year_exists'] ?? 'Academic Year :year already exists.'));
                } else {
                    $stmt = $conn->prepare("UPDATE academic_years SET year_name=?, status=? WHERE id=?");
                    $stmt->bind_param('ssi', $yearName, $status, $id);
                    $stmt->execute() ? setFlash('success', $LANG['flash_admin_academic_year_updated'] ?? 'Academic Year updated.') : setFlash('error', $LANG['flash_admin_update_failed'] ?? 'Update failed.');
                    $stmt->close();
                }
                $chk->close();
            }
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $chk = $conn->prepare("SELECT 1 FROM sections WHERE academic_year_id=? LIMIT 1");
            $chk->bind_param('i', $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_admin_cannot_delete_this_academic_year_is'] ?? 'Cannot delete: this Academic Year is referenced by sections.');
            } else {
                $chk2 = $conn->prepare("SELECT 1 FROM feedback_forms WHERE academic_year_id=? LIMIT 1");
                $chk2->bind_param('i', $id);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    setFlash('error', $LANG['flash_admin_cannot_delete_this_academic_year_is_1'] ?? 'Cannot delete: this Academic Year is referenced by feedback forms.');
                } else {
                    $stmt = $conn->prepare("DELETE FROM academic_years WHERE id=?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute() ? setFlash('success', $LANG['flash_admin_academic_year_deleted'] ?? 'Academic Year deleted.') : setFlash('error', $LANG['flash_admin_cannot_delete'] ?? 'Cannot delete.');
                    $stmt->close();
                }
                $chk2->close();
            }
            $chk->close();
        }
    }
    header('Location: academic_years.php');
    exit;
}

$search = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 15)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$conds = [];
$params = [];
$types = '';
if ($search) {
    $s2 = "%$search%";
    $conds[] = "ay.year_name LIKE ?";
    $params[] = $s2;
    $types .= 's';
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM academic_years ay $where");
if ($types)
    $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int) $cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

$dataSql = "SELECT ay.*,
    (SELECT COUNT(*) FROM sections WHERE academic_year_id = ay.id) AS section_count,
    (SELECT COUNT(*) FROM feedback_forms WHERE academic_year_id = ay.id) AS form_count,
    (SELECT COUNT(*) FROM feedback_question_sets WHERE academic_year_id = ay.id) AS question_set_count
    FROM academic_years ay $where ORDER BY ay.year_name DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $off;
$types .= 'ii';

$stmt = $conn->prepare($dataSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <!-- <div class="flex items-center gap-2 mb-1">
            <?= iconSvg('academic', 'w-5 h-5 text-indigo-600') ?>
            <h2 class="text-xl font-bold text-slate-800"><?= $LANG['academic_years_title'] ?? 'Academic Years' ?></h2>
        </div> -->
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG["academic_years_subtitle"] ?? "Manage academic year versions. Each year has its own question sets." ?>
        </p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?><?= $LANG["add_academic_year"] ?? "Add Academic Year" ?></button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG["search_academic_years_placeholder"] ?? "Search academic years..." ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
            </div>
            <button type="submit"
                class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG['filter'] ?? 'Search' ?></button>
            <?php if ($search): ?><a href="academic_years.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <!-- <span class="text-xs text-slate-400"><?= $total ?> <?= $LANG['records'] ?? 'records' ?></span> -->
        <span class="text-xs text-slate-400"><?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["year_name_label"] ?? "Year Name" ?>
                    </th>
                    <!-- <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["status"] ?? "Status" ?></th> -->
                    <!-- <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["sections"] ?? "Sections" ?></th> -->
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["forms"] ?? "Forms" ?>
                    </th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["question_sets"] ?? "Question Sets" ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["status"] ?? "Status" ?>
                    </th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["col_actions"] ?? "Actions" ?>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3">
                                <span class="text-sm font-bold text-slate-800"><?= e($row['year_name']) ?></span>
                            </td>
                            <!-- <td class="px-5 py-3">
                                <?php if ($row['status'] === 'active'): ?>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span><?= $LANG["active"] ?? "Active" ?></span>
                                <?php else: ?>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span><?= $LANG["inactive"] ?? "Inactive" ?></span>
                                <?php endif ?>
                            </td> -->
                            <!-- <td class="px-5 py-3 text-center">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700"><?= $row['section_count'] ?></span>
                            </td> -->
                            <td class="px-5 py-3 text-center">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700"><?= $row['form_count'] ?></span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700"><?= $row['question_set_count'] ?></span>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($row['status'] === 'active'): ?>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>
                                        <?= $LANG["active"] ?? "Active" ?>
                                    </span>
                                <?php else: ?>
                                    <span
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>
                                        <?= $LANG["inactive"] ?? "Inactive" ?>
                                    </span>
                                <?php endif ?>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>         <?= $LANG["edit"] ?? "Edit" ?></button>
                                    <!-- <button onclick="openDelete(<?= $row['id'] ?>, '<?= e($row['year_name']) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>        <?= $LANG["delete"] ?? "Delete" ?></button> -->
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-16 text-slate-400">
                            <?= iconSvg('academic', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG["no_academic_years_found"] ?? "No academic years found." ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'academic_years.php' . ($search ? '?search=' . urlencode($search) : ''), $perPage) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["add_academic_year"] ?? "Add Academic Year" ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST" onsubmit="return validateYear(this)"><?= csrfField() ?><input type="hidden" name="action"
                value="add">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["year_name_label"] ?? "Academic Year Name" ?>
                        <span class="text-red-500">*
                            (e.g. 2025-2026)</span></label>
                    <input type="text" name="year_name" required pattern="\d{4}-\d{4}" maxlength="9"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    <p class="text-xs text-slate-400 mt-1">Format: YYYY-YYYY (e.g. 2025-2026). Second year must be
                        exactly one year after the first.</p>
                    <p id="addYearError" class="text-xs text-red-500 mt-1 hidden"></p>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG["add"] ?? "Add" ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["edit_academic_year"] ?? "Edit Academic Year" ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST" onsubmit="return validateYear(this)"><?= csrfField() ?><input type="hidden" name="action"
                value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["year_name_label"] ?? "Academic Year Name" ?></label>
                    <input type="text" name="year_name" id="edit_year_name" required pattern="\d{4}-\d{4}" maxlength="9"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    <p id="editYearError" class="text-xs text-red-500 mt-1 hidden"></p>
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
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['save'] ?? 'Save' ?></button>
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
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?>
            </div>
            <h3 class="text-lg font-semibold text-slate-800">
                <?= $LANG["delete_academic_year"] ?? "Delete Academic Year" ?>
            </h3>
            <p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>?</p>
            <p class="text-xs text-red-500 mt-2">
                <?= $LANG["cannot_delete_referenced"] ?? "Cannot delete if referenced by sections or forms." ?>
            </p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden"
                name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Delete' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    var LANG = <?= json_encode([
        'val_year_format' => $LANG['val_year_format'] ?? 'Format must be YYYY-YYYY (e.g. 2025-2026).',
        'val_second_year_after_first' => $LANG['val_second_year_after_first'] ?? 'The second year must be exactly one year after the first (e.g. 2025-2026, not 2025-2027).',
    ]) ?>;
    function validateYear(form) {
        const input = form.querySelector('input[name="year_name"]');
        const errorEl = form.querySelector('[id$="YearError"]');
        const val = input.value.trim();
        errorEl.textContent = '';
        errorEl.classList.add('hidden');

        if (!/^\d{4}-\d{4}$/.test(val)) {
            errorEl.textContent = LANG.val_year_format;
            errorEl.classList.remove('hidden');
            input.focus();
            return false;
        }
        const parts = val.split('-');
        const y1 = parseInt(parts[0], 10);
        const y2 = parseInt(parts[1], 10);
        if (y2 !== y1 + 1) {
            errorEl.textContent = LANG.val_second_year_after_first;
            errorEl.classList.remove('hidden');
            input.focus();
            return false;
        }
        return true;
    }
    function openEdit(row) {
        document.getElementById('edit_id').value = row.id;
        document.getElementById('edit_year_name').value = row.year_name;
        document.getElementById('edit_status').value = row.status;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
</script>
<?php include '../includes/admin_footer.php'; ?>
