<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['teachers_title'] ?? 'Teachers';
$activeMenu = 'teachers';

// Get users with role=teacher (not already in teachers table)
$availableUsers = $conn->query("SELECT u.id, u.name, u.email FROM users u WHERE u.role='teacher' AND u.id NOT IN (SELECT user_id FROM teachers) ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);
$deptList = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

// --- POST Handlers --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $dept = (int) ($_POST['department_id'] ?? 0);
        if ($uid && $dept) {
            $stmt = $conn->prepare("INSERT INTO teachers (user_id, department_id) VALUES (?,?)");
            $stmt->bind_param('ii', $uid, $dept);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_teacher_added'] ?? 'Teacher added.') : setFlash('error', $LANG['flash_admin_failed_to_add_teacher'] ?? 'Failed to add teacher.');
            $stmt->close();
        } else {
            setFlash('error', $LANG['flash_admin_all_fields_required'] ?? 'All fields required.');
        }
    }
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $dept = (int) ($_POST['department_id'] ?? 0);
        if ($id && $dept) {
            $stmt = $conn->prepare("UPDATE teachers SET department_id=? WHERE id=?");
            $stmt->bind_param('ii', $dept, $id);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_teacher_updated'] ?? 'Teacher updated.') : setFlash('error', $LANG['flash_admin_update_failed'] ?? 'Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM teachers WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_teacher_removed'] ?? 'Teacher removed.') : setFlash('error', $LANG['flash_admin_cannot_delete_has_sections'] ?? 'Cannot delete (has sections).');
            $stmt->close();
        }
    }
    header('Location: teachers.php');
    exit;
}

// --- Fetch ----------------------------------------------------
$search = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($search) {
    $s2 = "%$search%";
    $c = $conn->prepare("SELECT COUNT(*) AS c FROM teachers t JOIN users u ON t.user_id=u.id JOIN departments d ON t.department_id=d.id WHERE u.name LIKE ? OR d.department_name LIKE ?");
    $c->bind_param('ss', $s2, $s2);
    $c->execute();
    $total = (int) $c->get_result()->fetch_assoc()['c'];
    $c->close();
} else {
    $total = (int) $conn->query("SELECT COUNT(*) AS c FROM teachers")->fetch_assoc()['c'];
}

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

if ($search) {
    $s2 = "%$search%";
    $stmt = $conn->prepare("SELECT t.*, u.name, u.email, d.department_name FROM teachers t JOIN users u ON t.user_id=u.id JOIN departments d ON t.department_id=d.id WHERE u.name LIKE ? OR d.department_name LIKE ? ORDER BY t.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ssii', $s2, $s2, $perPage, $off);
} else {
    $stmt = $conn->prepare("SELECT t.*, u.name, u.email, d.department_name FROM teachers t JOIN users u ON t.user_id=u.id JOIN departments d ON t.department_id=d.id ORDER BY t.id DESC LIMIT ? OFFSET ?");
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
        <!-- <h2 class="text-xl font-bold text-slate-800"><?= $LANG['teachers_title'] ?? 'Teachers' ?></h2> -->
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG['teachers_subtitle'] ?? 'Manage teacher profiles and assignments' ?>
        </p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?> <?= $LANG['add_teacher'] ?? 'Add Teacher' ?>
    </button>
</div>

<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_teachers'] ?? 'Search teachers...' ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <button type="submit"
                class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG['search'] ?? 'Search' ?></button>
            <?php if ($search): ?><a href="teachers.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-white hover:bg-red-700 bg-red-500"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <!-- <span class="text-xs text-slate-400"><?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span> -->
        <span class="text-xs text-slate-400"><?= $LANG['total'] ?? 'Total' ?> <?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table  class="w-full">
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_name'] ?? 'Name' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_email'] ?? 'Email' ?>
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_department'] ?? 'Department' ?>
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
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="w-8 h-8 rounded-full bg-cyan-100 flex items-center justify-center text-xs font-bold text-cyan-700">
                                        <?= e(avatarInitials($row['name'])) ?>
                                    </div>
                                    <span class="text-sm font-medium text-slate-800"><?= e($row['name']) ?></span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-sm text-slate-500"><?= e($row['email']) ?></td>
                            <td class="px-5 py-3"><span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-800"><?= e($row['department_name']) ?></span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button onclick="openEdit(<?= $row['id'] ?>,<?= $row['department_id'] ?>)"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>         <?= $LANG['edit'] ?? 'Edit' ?>
                                    </button>
                                    <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['name'])) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>         <?= $LANG['delete'] ?? 'Delete' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" class="text-center py-16 text-slate-400">
                            <?= iconSvg('user', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm">
                                <?= $LANG['no_teachers_found'] ?? 'No teachers found. First create a user with role "teacher" in Users page.' ?>
                            </p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'teachers.php' . ($search ? '?search=' . urlencode($search) : ''), $perPage) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['add_teacher'] ?? 'Add Teacher' ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['select_teacher_user'] ?? 'Select Teacher User' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="user_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['choose_user_teacher'] ?? 'Choose user (role=teacher)' ?></option>
                        <?php foreach ($availableUsers as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['email']) ?></option>
                        <?php endforeach ?>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">
                        <?= $LANG['teacher_user_hint'] ?? 'Only users with role "teacher" and not yet assigned appear here.' ?>
                    </p>
                </div>
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['select_department'] ?? 'Department' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="department_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_department'] ?? 'Select Department' ?></option>
                        <?php foreach ($deptList as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['add_teacher_btn'] ?? 'Add Teacher' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['edit_teacher_modal'] ?? 'Edit Teacher' ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id"
                id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['select_department'] ?? 'Department' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="department_id" id="edit_dept" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <?php foreach ($deptList as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['save'] ?? 'Save Changes' ?></button>
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
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG['remove_teacher_modal'] ?? 'Remove Teacher' ?>
            </h3>
            <p class="text-sm text-slate-500 mt-2"><strong
                    id="delete_name" class="text-slate-700"></strong> <?= $LANG["from_system"] ?? "from the system?" ?>
            </p>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id"
                id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Remove' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEdit(id, deptId) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_dept').value = deptId;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
</script>
<?php include '../includes/admin_footer.php'; ?>
