<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = $LANG['majors_title'] ?? 'Majors';
$activeMenu = 'majors';

$deptList = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $dept = (int)($_POST['department_id'] ?? 0);
        $name = clean($_POST['major_name'] ?? '');
        if ($dept && $name) {
            $stmt = $conn->prepare("INSERT INTO majors (department_id, major_name) VALUES (?,?)");
            $stmt->bind_param('is',$dept,$name);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_major_added'] ?? 'Major added.') : setFlash('error', $LANG['flash_admin_failed'] ?? 'Failed.');
            $stmt->close();
        } else { setFlash('error', $LANG['flash_admin_all_fields_required'] ?? 'All fields required.'); }
    }
    if ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $dept = (int)($_POST['department_id'] ?? 0);
        $name = clean($_POST['major_name'] ?? '');
        if ($id && $dept && $name) {
            $stmt = $conn->prepare("UPDATE majors SET department_id=?,major_name=? WHERE id=?");
            $stmt->bind_param('isi',$dept,$name,$id);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_major_updated'] ?? 'Major updated.') : setFlash('error', $LANG['flash_admin_update_failed'] ?? 'Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id=(int)($_POST['id']??0);
        if ($id) {
            $stmt=$conn->prepare("DELETE FROM majors WHERE id=?");
            $stmt->bind_param('i',$id);
            $stmt->execute() ? setFlash('success', $LANG['flash_admin_major_deleted'] ?? 'Major deleted.') : setFlash('error', $LANG['flash_admin_cannot_delete_has_courses_or_students'] ?? 'Cannot delete (has courses or students).');
            $stmt->close();
        }
    }
    header('Location: majors.php'); exit;
}

$search  = clean($_GET['search'] ?? '');
$deptF   = (int)($_GET['dept'] ?? 0);
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 10)));
$page    = max(1,(int)($_GET['page'] ?? 1));

$conds=[]; $params=[]; $types='';
if ($search) { $s2="%$search%"; $conds[]="m.major_name LIKE ?"; $params[]=$s2; $types.='s'; }
if ($deptF) { $conds[]="m.department_id=?"; $params[]=$deptF; $types.='i'; }
$where = $conds ? 'WHERE '.implode(' AND ',$conds) : '';

$c=$conn->prepare("SELECT COUNT(*) AS c FROM majors m $where");
if ($types) $c->bind_param($types,...$params);
$c->execute(); $total=(int)$c->get_result()->fetch_assoc()['c']; $c->close();

$pg=paginate($total,$perPage,$page); $off=$pg['offset'];
$p2=array_merge($params,[$perPage,$off]); $t2=$types.'ii';

$stmt=$conn->prepare("SELECT m.*, d.department_name, (SELECT COUNT(*) FROM courses c WHERE c.major_id=m.id) AS course_count FROM majors m JOIN departments d ON m.department_id=d.id $where ORDER BY d.department_name, m.major_name LIMIT ? OFFSET ?");
$stmt->bind_param($t2,...$p2);
$stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$qs=http_build_query(array_filter(['search'=>$search,'dept'=>$deptF?:null]));

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div><h2 class="text-xl font-bold text-slate-800"><?= $LANG['majors_title'] ?? 'Majors' ?></h2><p class="text-sm text-slate-500 mt-0.5"><?= $LANG['majors_subtitle'] ?? 'Manage academic majors per department' ?></p></div>
    <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus','w-4 h-4') ?> <?= $LANG['add_major'] ?? 'Add Major' ?>
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2 flex-1">
            <div class="relative"><span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search','w-4 h-4') ?></span><input type="text" name="search" value="<?= e($search) ?>" placeholder="<?= $LANG['search_majors'] ?? 'Search majors...' ?>" class="pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl w-48 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none"></div>
            <select name="dept" class="border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-600 focus:border-cyan-500 outline-none bg-white">
                <option value=""><?= $LANG['all_departments'] ?? 'All Departments' ?></option>
                <?php foreach($deptList as $d): ?><option value="<?= $d['id'] ?>" <?= $deptF==$d['id']?'selected':'' ?>><?= e($d['department_name']) ?></option><?php endforeach ?>
            </select>
            <button type="submit" class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG['filter'] ?? 'Filter' ?></button>
            <?php if ($search||$deptF): ?><a href="majors.php" class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> <?= $total!==1 ? ($LANG['records'] ?? 'record(s)') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200"><tr>
                <th class="text-left px-5 py-3 text-slate-500">#</th>
                <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_major_name'] ?? 'Major Name' ?></th>
                <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_department'] ?? 'Department' ?></th>
                <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_courses'] ?? 'Courses' ?></th>
                <th class="text-right px-5 py-3 text-slate-500"><?= $LANG['col_actions'] ?? 'Actions' ?></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
            <?php if ($rows): foreach ($rows as $i => $row): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset']+$i+1 ?></td>
                    <td class="px-5 py-3 text-sm font-medium text-slate-800"><?= e($row['major_name']) ?></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-800"><?= e($row['department_name']) ?></span></td>
                    <td class="px-5 py-3"><span class="text-sm font-semibold text-slate-700"><?= $row['course_count'] ?></span></td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick="openEdit(<?= $row['id'] ?>,<?= $row['department_id'] ?>,'<?= addslashes(e($row['major_name'])) ?>')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg"><?= iconSvg('edit','w-3.5 h-3.5') ?> <?= $LANG['edit'] ?? 'Edit' ?></button>
                            <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['major_name'])) ?>')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg"><?= iconSvg('trash','w-3.5 h-3.5') ?> <?= $LANG['delete'] ?? 'Delete' ?></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center py-16 text-slate-400"><?= iconSvg('academic','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm"><?= $LANG['no_majors_found'] ?? 'No majors found.' ?></p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg,'majors.php'.($qs?"?$qs":''), $perPage) ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800"><?= $LANG['add_major_modal'] ?? 'Add Major' ?></h3><button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button></div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['col_department'] ?? 'Department' ?> <span class="text-red-500">*</span></label><select name="department_id" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white"><option value=""><?= $LANG['select_department'] ?? 'Select Department' ?></option><?php foreach($deptList as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach ?></select></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['major_name_label'] ?? 'Major Name' ?> <span class="text-red-500">*</span></label><input type="text" name="major_name" required placeholder="<?= $LANG['major_name_placeholder'] ?? 'e.g. Computer Science' ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none"></div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button type="button" onclick="closeModal('addModal')" class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button><button type="submit" class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['add_major'] ?? 'Add Major' ?></button></div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800"><?= $LANG['edit_major_modal'] ?? 'Edit Major' ?></h3><button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button></div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['col_department'] ?? 'Department' ?></label><select name="department_id" id="edit_dept" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white"><?php foreach($deptList as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach ?></select></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['major_name_label'] ?? 'Major Name' ?></label><input type="text" name="major_name" id="edit_name" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none"></div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button type="button" onclick="closeModal('editModal')" class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button><button type="submit" class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['save'] ?? 'Save' ?></button></div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center"><div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4"><?= iconSvg('trash','w-7 h-7 text-red-600') ?></div><h3 class="text-lg font-semibold text-slate-800"><?= $LANG['delete_major_modal'] ?? 'Delete Major' ?></h3><p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>?</p></div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6"><button type="button" onclick="closeModal('deleteModal')" class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button><button type="submit" class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Delete' ?></button></div>
        </form>
    </div>
</div>

<script>
function openEdit(id,deptId,name){document.getElementById('edit_id').value=id;document.getElementById('edit_dept').value=deptId;document.getElementById('edit_name').value=name;openModal('editModal');}
function openDelete(id,name){document.getElementById('delete_id').value=id;document.getElementById('delete_name').textContent=name;openModal('deleteModal');}
</script>
<?php include '../includes/admin_footer.php'; ?>
