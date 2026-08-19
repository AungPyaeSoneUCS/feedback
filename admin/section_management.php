<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['nav_section_management'] ?? 'Class Sections';
$activeMenu = 'section_management';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $sectionName = strtoupper(trim(clean($_POST['section_name'] ?? '')));

        if (!$sectionName) {
            setFlash('error', $LANG['flash_sm_empty'] ?? 'Section name cannot be empty.');
        } elseif (!preg_match('/^[A-Z]+$/', $sectionName)) {
            setFlash('error', $LANG['flash_sm_invalid'] ?? 'Invalid section name. Use letters (e.g. A, B, C, D, etc.).');
        } else {
            $chk = $conn->prepare("SELECT id FROM section_master WHERE section_name = ?");
            $chk->bind_param('s', $sectionName);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_sm_duplicate'] ?? 'This section name already exists.');
            } else {
                $stmt = $conn->prepare("INSERT INTO section_master (section_name) VALUES (?)");
                $stmt->bind_param('s', $sectionName);
                $stmt->execute() ? setFlash('success', $LANG['flash_sm_added'] ?? 'Section name added.') : setFlash('error', $LANG['flash_sm_add_failed'] ?? 'Failed to add section name.');
                $stmt->close();
            }
            $chk->close();
        }
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $sectionName = strtoupper(trim(clean($_POST['section_name'] ?? '')));

        if (!$id || !$sectionName) {
            setFlash('error', $LANG['flash_sm_empty'] ?? 'Section name cannot be empty.');
        } elseif (!preg_match('/^[A-Z0-9]([A-Z0-9\-]*[A-Z0-9])?$/i', $sectionName)) {
            setFlash('error', $LANG['flash_sm_invalid'] ?? 'Invalid section name. Use letters, numbers, or hyphens (e.g. A, B, C, D, 5CS-A).');
        } else {
            $chk = $conn->prepare("SELECT id FROM section_master WHERE section_name = ? AND id != ?");
            $chk->bind_param('si', $sectionName, $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_sm_duplicate'] ?? 'This section name already exists.');
            } else {
                $stmt = $conn->prepare("UPDATE section_master SET section_name = ? WHERE id = ?");
                $stmt->bind_param('si', $sectionName, $id);
                $stmt->execute() ? setFlash('success', $LANG['flash_sm_updated'] ?? 'Section name updated.') : setFlash('error', $LANG['flash_sm_update_failed'] ?? 'Update failed.');
                $stmt->close();
            }
            $chk->close();
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $chk = $conn->prepare("SELECT id FROM sections WHERE section_id = ? LIMIT 1");
            $chk->bind_param('i', $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_sm_in_use'] ?? 'Cannot delete: this section name is assigned to one or more course sections.');
            } else {
                $stmt = $conn->prepare("DELETE FROM section_master WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute() ? setFlash('success', $LANG['flash_sm_deleted'] ?? 'Section name deleted.') : setFlash('error', $LANG['flash_sm_delete_failed'] ?? 'Cannot delete.');
                $stmt->close();
            }
            $chk->close();
        }
    }

    header('Location: section_management.php');
    exit;
}

$search = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$conditions = [];
$params = [];
$types = '';
if ($search) {
    $conditions[] = "sm.section_name LIKE ?";
    $s2 = "%$search%";
    $params[] = $s2;
    $types .= 's';
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countSql = "SELECT COUNT(*) AS c FROM section_master sm $where";
$c = $conn->prepare($countSql);
if ($params)
    $c->bind_param($types, ...$params);
$c->execute();
$total = (int) $c->get_result()->fetch_assoc()['c'];
$c->close();

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

$dataSql = "SELECT sm.*,
    (SELECT COUNT(*) FROM sections WHERE section_id = sm.id) AS assignment_count
    FROM section_master sm $where ORDER BY sm.section_name ASC LIMIT ? OFFSET ?";
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
    return 'section_management.php' . ($params ? '?' . http_build_query($params) : '');
};

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG['section_management_subtitle'] ?? 'Manage reusable section names used across the system.' ?>
        </p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?>
        <?= $LANG['add_section_name'] ?? 'Add Section Name' ?>
    </button>
</div>

<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 min-w-[200px] max-w-xs">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                    <?= iconSvg('search', 'w-4 h-4') ?>
                </span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_section_names'] ?? 'Search section names...' ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
            </div>
            <button type="submit" class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700">
                <?= $LANG['search'] ?? 'Search' ?>
            </button>
            <?php if ($hasFilter): ?>
                <a href="section_management.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-white hover:bg-red-700 bg-red-500">
                    <?= $LANG['clear'] ?? 'Clear' ?>
                </a>
            <?php endif ?>
        </form>
        <span class="text-xs text-slate-400">
            <?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?>
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['section_name'] ?? 'Section Name' ?>
                    </th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['assignments_label'] ?? 'Assignments' ?>
                    </th>
                    <th class="text-right px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_actions'] ?? 'Actions' ?>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows): ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-md">
                                    <?= $LANG['section_label'] ?? 'Section' ?>         <?= e($row['section_name']) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $row['assignment_count'] > 0 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= $row['assignment_count'] ?>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>
                                        <?= $LANG['edit'] ?? 'Edit' ?>
                                    </button>
                                    <button onclick="openDelete(<?= $row['id'] ?>, '<?= e($row['section_name']) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>
                                        <?= $LANG['delete'] ?? 'Delete' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center py-16 text-slate-400">
                            <?= iconSvg('grid', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_section_names_found'] ?? 'No section names found.' ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, $buildQuery(), $perPage) ?>
    </div>
</div>


<!-- =========================================================
     ADD MODAL
========================================================= -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">
                <?= $LANG['add_section_name'] ?? 'Add Section Name' ?>
            </h3>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600">
                <?= iconSvg('x', 'w-5 h-5') ?>
            </button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        <?= $LANG['section_name'] ?? 'Section Name' ?>
                        <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="section_name" id="add_section_name" required maxlength="20"
                        pattern="[A-Za-z0-9\-]+" title="Letters, numbers, or hyphens only (e.g. A, B, C, D, 5CS-A)"
                        placeholder="<?= $LANG['section_name_placeholder'] ?? 'e.g. A, B, C, D, 5CS-A' ?>"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none uppercase">
                    <p class="mt-1 text-xs text-slate-400">
                        <?= $LANG['section_name_hint'] ?? 'Letters, numbers, or hyphens. Will be stored in uppercase.' ?>
                    </p>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors">
                    <?= $LANG['cancel'] ?? 'Cancel' ?>
                </button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">
                    <?= $LANG['add'] ?? 'Add' ?>
                </button>
            </div>
        </form>
    </div>
</div>


<!-- =========================================================
     EDIT MODAL
========================================================= -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">
                <?= $LANG['edit_section_name'] ?? 'Edit Section Name' ?>
            </h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600">
                <?= iconSvg('x', 'w-5 h-5') ?>
            </button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        <?= $LANG['section_name'] ?? 'Section Name' ?>
                        <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="section_name" id="edit_section_name" required maxlength="20"
                        pattern="[A-Za-z0-9\-]+" title="Letters, numbers, or hyphens only (e.g. A, B, C, D, 5CS-A)"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none uppercase">
                    <p class="mt-1 text-xs text-slate-400">
                        <?= $LANG['section_name_hint'] ?? 'Letters, numbers, or hyphens. Will be stored in uppercase.' ?>
                    </p>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors">
                    <?= $LANG['cancel'] ?? 'Cancel' ?>
                </button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">
                    <?= $LANG['save'] ?? 'Save' ?>
                </button>
            </div>
        </form>
    </div>
</div>


<!-- =========================================================
     DELETE MODAL
========================================================= -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?>
            </div>
            <h3 class="text-lg font-semibold text-slate-800">
                <?= $LANG['delete_section_name'] ?? 'Delete Section Name' ?>
            </h3>
            <p class="text-sm text-slate-500 mt-2">

                <strong id="delete_name" class="text-slate-700"></strong>
                <?= $LANG['delete_section_name_confirm'] ?? 'Delete' ?>?
            </p>
            <p class="text-xs text-slate-400 mt-2">
                <?= $LANG['delete_section_name_hint'] ?? 'Only possible if no course sections use this name.' ?>
            </p>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors">
                    <?= $LANG['cancel'] ?? 'Cancel' ?>
                </button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">
                    <?= $LANG['delete'] ?? 'Delete' ?>
                </button>
            </div>
        </form>
    </div>
</div>


<script>
    function openEdit(row) {
        document.getElementById('edit_id').value = row.id;
        document.getElementById('edit_section_name').value = row.section_name;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
</script>

<?php include '../includes/admin_footer.php'; ?>