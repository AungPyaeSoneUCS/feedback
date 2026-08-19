<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['semesters_title'] ?? 'Semesters';
$activeMenu = 'semesters';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $semesterName = trim(clean($_POST['semester_name'] ?? ''));
        if (!$semesterName) {
            setFlash('error', $LANG['flash_admin_please_enter_a_semester_name'] ?? 'Please enter a semester name.');
        } elseif (!preg_match('/^Semester\s+(I{1,3}|IV|V|VI{0,3}|IX|X{1,3}(?:I{1,3}|IV|V|VI{0,3}|IX)?)$/i', $semesterName)) {
            setFlash('error', $LANG['flash_admin_invalid_format_use_roman_numeral_format'] ?? 'Invalid format. Use Roman numeral format, e.g. Semester I, Semester II, Semester III.');
        } else {
            // Normalize: "Semester" + space + uppercase Roman numeral
            preg_match('/^Semester\s+(.+)$/i', $semesterName, $m);
            $semesterName = 'Semester ' . strtoupper(trim($m[1]));
            $chk = $conn->prepare("SELECT 1 FROM semesters WHERE LOWER(TRIM(semester_name))=LOWER(?)");
            $chk->bind_param('s', $semesterName);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_admin_this_semester_already_exists'] ?? 'This semester already exists.');
            } else {
                $stmt = $conn->prepare("INSERT INTO semesters (semester_name) VALUES (?)");
                $stmt->bind_param('s', $semesterName);
                $stmt->execute() ? setFlash('success', $LANG['flash_admin_semester_added'] ?? 'Semester added.') : setFlash('error', $LANG['flash_admin_failed'] ?? 'Failed.');
                $stmt->close();
            }
            $chk->close();
        }
    }
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $semesterName = trim(clean($_POST['semester_name'] ?? ''));
        if ($id && $semesterName) {
            if (!preg_match('/^Semester\s+(I{1,3}|IV|V|VI{0,3}|IX|X{1,3}(?:I{1,3}|IV|V|VI{0,3}|IX)?)$/i', $semesterName)) {
                setFlash('error', $LANG['flash_admin_invalid_format_use_roman_numeral_format'] ?? 'Invalid format. Use Roman numeral format, e.g. Semester I, Semester II, Semester III.');
            } else {
                preg_match('/^Semester\s+(.+)$/i', $semesterName, $m);
                $semesterName = 'Semester ' . strtoupper(trim($m[1]));
                $chk = $conn->prepare("SELECT 1 FROM semesters WHERE LOWER(TRIM(semester_name))=LOWER(?) AND id!=?");
                $chk->bind_param('si', $semesterName, $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    setFlash('error', $LANG['flash_admin_this_semester_name_already_exists'] ?? 'This semester name already exists.');
                } else {
                    $stmt = $conn->prepare("UPDATE semesters SET semester_name=? WHERE id=?");
                    $stmt->bind_param('si', $semesterName, $id);
                    $stmt->execute() ? setFlash('success', $LANG['flash_admin_semester_updated'] ?? 'Semester updated.') : setFlash('error', $LANG['flash_admin_update_failed'] ?? 'Update failed.');
                    $stmt->close();
                }
                $chk->close();
            }
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $inUse = false;
            $tables = ['sections', 'feedback_forms'];
            foreach ($tables as $tbl) {
                $chk = $conn->prepare("SELECT 1 FROM $tbl WHERE semester_id=? LIMIT 1");
                $chk->bind_param('i', $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $inUse = true;
                }
                $chk->close();
                if ($inUse)
                    break;
            }
            if ($inUse) {
                setFlash('error', $LANG['flash_admin_cannot_delete_this_semester_is_referenced'] ?? 'Cannot delete: this semester is referenced by sections or forms.');
            } else {
                $stmt = $conn->prepare("DELETE FROM semesters WHERE id=?");
                $stmt->bind_param('i', $id);
                $stmt->execute() ? setFlash('success', $LANG['flash_admin_semester_deleted'] ?? 'Semester deleted.') : setFlash('error', $LANG['flash_admin_cannot_delete'] ?? 'Cannot delete.');
                $stmt->close();
            }
        }
    }
    header('Location: semesters.php');
    exit;
}

$search = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$conditions = [];
$params = [];
$types = '';
if ($search) {
    $conditions[] = "sm.semester_name LIKE ?";
    $s2 = "%$search%";
    $params[] = $s2;
    $types .= 's';
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countSql = "SELECT COUNT(*) AS c FROM semesters sm $where";
$c = $conn->prepare($countSql);
if ($params)
    $c->bind_param($types, ...$params);
$c->execute();
$total = (int) $c->get_result()->fetch_assoc()['c'];
$c->close();

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

$dataSql = "SELECT sm.*,
    (SELECT COUNT(*) FROM sections WHERE semester_id = sm.id) AS section_count,
    (SELECT COUNT(*) FROM feedback_forms WHERE semester_id = sm.id) AS form_count
    FROM semesters sm $where ORDER BY sm.id ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $off;
$types .= 'ii';
$stmt = $conn->prepare($dataSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$hasFilter = $search;
$buildQuery = function ($overrides = []) use ($search) {
    $params = array_merge(['search' => $search], $overrides);
    $params = array_filter($params);
    return 'semesters.php' . ($params ? '?' . http_build_query($params) : '');
};

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <!-- <div class="flex items-center gap-2 mb-1">
            <?= iconSvg('clipboard', 'w-5 h-5 text-indigo-600') ?>
            <h2 class="text-xl font-bold text-slate-800"><?= $LANG["semesters_title"] ?? "Semesters" ?></h2>
        </div> -->
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG["semesters_subtitle"] ?? "Manage semester master list used across the system." ?>
        </p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?><?= $LANG["add_semester"] ?? "Add Semester" ?></button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 min-w-[200px] max-w-xs">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_semesters_placeholder'] ?? 'Search semesters...' ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
            </div>
            <button type="submit"
                class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG['filter'] ?? 'Search' ?></button>
            <?php if ($hasFilter): ?><a href="semesters.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-white hover:bg-red-700 bg-red-500"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <!-- <span class="text-xs text-slate-400"><?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span> -->
        <span class="text-xs text-slate-400"><?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['semester_name_col'] ?? 'Semester Name' ?>
                    </th>
                    <!-- <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["sections"] ?? "Sections" ?></th> -->
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["forms"] ?? "Forms" ?>
                    </th>
                    <th class="text-right px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG["col_actions"] ?? "Actions" ?>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-800"><?= e($row['semester_name']) ?></td>
                            <!-- <td class="px-5 py-3 text-center">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700"><?= $row['section_count'] ?></span>
                            </td> -->
                            <td class="px-5 py-3 text-center">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700"><?= $row['form_count'] ?></span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <div class="flex items-center justify-end gap-2">
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>         <?= $LANG["edit"] ?? "Edit" ?></button>
                                    <button onclick="openDelete(<?= $row['id'] ?>, '<?= e($row['semester_name']) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>         <?= $LANG["delete"] ?? "Delete" ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" class="text-center py-16 text-slate-400">
                            <?= iconSvg('clipboard', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG["no_semesters_found"] ?? "No semesters found." ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg, $buildQuery(), $perPage) ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["add_semester"] ?? "Add Semester" ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["semester_name_label"] ?? "Semester Name" ?>
                        <span class="text-red-500">*</span></label>
                    <input type="text" name="semester_name" id="add_semester_name" required
                        placeholder="e.g. Semester I"
                        pattern="^Semester\s+(I{1,3}|IV|V|VI{0,3}|IX|X{1,3}(?:I{1,3}|IV|V|VI{0,3}|IX)?)$"
                        title="Use Roman numeral format: Semester I, Semester II, Semester III, etc."
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    <p class="mt-1 text-xs text-slate-400">Use Roman numeral format (e.g. Semester I, Semester II, ...)
                    </p>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG["add_semester"] ?? "Add Semester" ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["edit_semester"] ?? "Edit Semester" ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden"
                name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["semester_name_label"] ?? "Semester Name" ?></label>
                    <input type="text" name="semester_name" id="edit_semester_name" required
                        pattern="^Semester\s+(I{1,3}|IV|V|VI{0,3}|IX|X{1,3}(?:I{1,3}|IV|V|VI{0,3}|IX)?)$"
                        title="Use Roman numeral format: Semester I, Semester II, Semester III, etc."
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    <p class="mt-1 text-xs text-slate-400">Use Roman numeral format (e.g. Semester I, Semester II, ...)
                    </p>
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
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG["delete_semester"] ?? "Delete Semester" ?></h3>
            <p class="text-sm text-slate-500 mt-2"><strong id="delete_name" class="text-slate-700"></strong>
                <?= $LANG['delete_section_name_confirm'] ?? 'Delete' ?>?
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
    const semesterRegex = /^Semester\s+(I{1,3}|IV|V|VI{0,3}|IX|X{1,3}(?:I{1,3}|IV|V|VI{0,3}|IX)?)$/i;
    function validateSemesterInput(input) {
        const val = input.value.trim();
        if (!val) return;
        if (!semesterRegex.test(val)) {
            input.setCustomValidity('Use Roman numeral format: Semester I, Semester II, Semester III, etc.');
        } else {
            input.setCustomValidity('');
        }
    }
    document.getElementById('add_semester_name')?.addEventListener('input', function () { validateSemesterInput(this); });
    document.getElementById('edit_semester_name')?.addEventListener('input', function () { validateSemesterInput(this); });
    function openEdit(row) {
        document.getElementById('edit_id').value = row.id;
        document.getElementById('edit_semester_name').value = row.semester_name;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
</script>
<?php include '../includes/admin_footer.php'; ?>