<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['departments_title'] ?? 'Departments';
$activeMenu = 'departments';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = strtoupper(trim(clean($_POST['department_name'] ?? '')));
        if ($name) {
            $chk = $conn->prepare("SELECT 1 FROM departments WHERE LOWER(TRIM(department_name))=LOWER(?)");
            $chk->bind_param('s', $name);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_admin_this_department_already_exists'] ?? 'This department already exists.');
            } else {
                $stmt = $conn->prepare("INSERT INTO departments (department_name) VALUES (?)");
                $stmt->bind_param('s', $name);
                $stmt->execute() ? setFlash('success', $LANG['flash_admin_department_added'] ?? 'Department added.') : setFlash('error', $LANG['flash_admin_failed_to_add_department'] ?? 'Failed to add department.');
                $stmt->close();
            }
            $chk->close();
        } else {
            setFlash('error', $LANG['flash_admin_department_name_is_required'] ?? 'Department name is required.');
        }
    }
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = strtoupper(trim(clean($_POST['department_name'] ?? '')));
        if ($id && $name) {
            $chk = $conn->prepare("SELECT 1 FROM departments WHERE LOWER(TRIM(department_name))=LOWER(?) AND id!=?");
            $chk->bind_param('si', $name, $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                setFlash('error', $LANG['flash_admin_this_department_already_exists'] ?? 'This department already exists.');
            } else {
                $stmt = $conn->prepare("UPDATE departments SET department_name=? WHERE id=?");
                $stmt->bind_param('si', $name, $id);
                $stmt->execute() ? setFlash('success', $LANG['flash_admin_department_updated'] ?? 'Department updated.') : setFlash('error', $LANG['flash_admin_update_failed'] ?? 'Update failed.');
                $stmt->close();
            }
            $chk->close();
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM departments WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_department_deleted'] ?? 'Department deleted.') : setFlash('error', $LANG['flash_admin_cannot_delete'] ?? 'Cannot delete.');
            $stmt->close();
        }
    }
    header('Location: departments.php');
    exit;
}

$search = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($search) {
    $s2 = "%$search%";
    $c = $conn->prepare("SELECT COUNT(*) AS c FROM departments WHERE department_name LIKE ?");
    $c->bind_param('s', $s2);
    $c->execute();
    $total = (int) $c->get_result()->fetch_assoc()['c'];
    $c->close();
} else {
    $total = (int) $conn->query("SELECT COUNT(*) AS c FROM departments")->fetch_assoc()['c'];
}
$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

if ($search) {
    $s2 = "%$search%";
    $stmt = $conn->prepare("SELECT d.* FROM departments d WHERE d.department_name LIKE ? ORDER BY d.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('sii', $s2, $perPage, $off);
} else {
    $stmt = $conn->prepare("SELECT d.* FROM departments d ORDER BY d.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $perPage, $off);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <!-- <h2 class="text-xl font-bold text-slate-800"><?= $LANG['departments_title'] ?? 'Departments' ?></h2> -->
        <p class="text-sm text-slate-500 mt-0.5"><?= $LANG['departments_subtitle'] ?? 'Manage academic departments' ?>
        </p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?> <?= $LANG['add_department'] ?? 'Add Department' ?>
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs"><span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_departments'] ?? 'Search departments...' ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <button type="submit"
                class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG['search'] ?? 'Search' ?></button>
            <?php if ($search): ?><a href="departments.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl hover:bg-red-700 bg-red-500 text-white"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <!-- <span class="text-xs text-slate-400"><?= $total ?> <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span> -->
        <span class="text-xs text-slate-400"><?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_dept_name'] ?? 'Department Name' ?>
                    </th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_actions'] ?? 'Actions' ?>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3 text-sm font-semibold text-slate-800"><?= e($row['department_name']) ?></td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        onclick="openEdit(<?= $row['id'] ?>,'<?= addslashes(e($row['department_name'])) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>         <?= $LANG['edit'] ?? 'Edit' ?>
                                    </button>
                                    <button
                                        onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['department_name'])) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>         <?= $LANG['delete'] ?? 'Delete' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="4" class="text-center py-16 text-slate-400">
                            <?= iconSvg('home', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_departments_found'] ?? 'No departments found.' ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'departments.php' . ($search ? '?search=' . urlencode($search) : ''), $perPage) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['add_dept_modal'] ?? 'Add Department' ?></h3><button
                onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['dept_name_label'] ?? 'Department Name' ?>
                        <span class="text-red-500">*</span></label><input type="text" name="department_name" required
                        placeholder="<?= $LANG['dept_name_placeholder'] ?? 'e.g. Faculty of Computer Science' ?>"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button type="button"
                    onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button><button
                    type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['add'] ?? 'Add' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['edit_dept_modal'] ?? 'Edit Department' ?></h3><button
                onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden"
                name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['dept_name_label'] ?? 'Department Name' ?></label><input
                        type="text" name="department_name" id="edit_name" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button type="button"
                    onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button><button
                    type="submit"
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
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG['delete_dept_modal'] ?? 'Delete Department' ?>
            </h3>
            <p class="text-sm text-slate-500 mt-2"><strong id="delete_name"
                    class="text-slate-700"></strong><?= $LANG['delete'] ?? 'Delete' ?> ?</p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden"
                name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6"><button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button><button
                    type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Delete' ?></button>
            </div>
        </form>
    </div>
</div>
<script>
    const existingDepts = <?= json_encode(array_map(function ($r) {
        return strtolower(trim($r['department_name']));
    }, $rows)) ?>;
    function checkDeptDuplicate(input, excludeName) {
        const val = input.value.trim().toLowerCase();
        if (!val) { input.setCustomValidity(''); return; }
        const isDup = existingDepts.some(d => d === val && d !== (excludeName || '').toLowerCase());
        input.setCustomValidity(isDup ? 'This department already exists.' : '');
    }
    document.querySelector('#addModal input[name="department_name"]')?.addEventListener('input', function () { checkDeptDuplicate(this, ''); });
    function openEdit(id, name) {
        document.getElementById('edit_id').value = id; document.getElementById('edit_name').value = name; openModal('editModal');
        const editInput = document.querySelector('#editModal input[name="department_name"]');
        editInput.oninput = function () { checkDeptDuplicate(this, name); };
    }
    function openDelete(id, name) { document.getElementById('delete_id').value = id; document.getElementById('delete_name').textContent = name; openModal('deleteModal'); }
</script>
<?php include '../includes/admin_footer.php'; ?>