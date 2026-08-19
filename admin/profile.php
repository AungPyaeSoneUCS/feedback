<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['my_profile'] ?? 'My Profile';
$activeMenu = 'profile';
$user = getCurrentUser();

$success = '';
$error = '';

// Re-fetch fresh user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    // Handle Remove Photo
    if (isset($_POST['remove_photo'])) {
        $profileImage = $userData['profile_image'] ?? null;
        if ($profileImage && file_exists(__DIR__ . '/../' . $profileImage)) {
            unlink(__DIR__ . '/../' . $profileImage);
        }
        $stmt = $conn->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
        $stmt->bind_param('i', $user['id']);
        if ($stmt->execute()) {
            $_SESSION['profile_image'] = null;
            setFlash('success', $LANG['flash_photo_removed'] ?? 'Profile photo removed successfully.');
        } else {
            setFlash('error', $LANG['flash_error'] ?? 'An error occurred.');
        }
        $stmt->close();
        header('Location: profile.php');
        exit;
    }

    if ($action === 'update_info') {
        $name = clean($_POST['name'] ?? '');
        $email = clean($_POST['email'] ?? '');
        $curPw = $_POST['current_password'] ?? '';
        $newPw = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if (!$name || !$email) {
            setFlash('error', $LANG['flash_fields_required'] ?? 'Name and Email are required.');
            header('Location: profile.php');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', $LANG['flash_invalid_email'] ?? 'Please enter a valid email address.');
            header('Location: profile.php');
            exit;
        }

        // Handle password change — only if user entered a new password
        $hash = null;
        if ($newPw !== '' || $confirmPw !== '') {
            // Current password is required to change password
            if ($curPw === '') {
                setFlash('error', $LANG['flash_current_password_required'] ?? 'Current Password is required to change your password.');
                header('Location: profile.php');
                exit;
            }

            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($curPw, $row['password'])) {
                setFlash('error', $LANG['flash_wrong_password'] ?? 'Current password is incorrect.');
                header('Location: profile.php');
                exit;
            }

            if ($newPw !== $confirmPw) {
                setFlash('error', $LANG['flash_passwords_no_match'] ?? 'New passwords do not match.');
                header('Location: profile.php');
                exit;
            }
            if (strlen($newPw) < 6 || preg_match('/[\s\x00-\x1F\x7F]/', $newPw) || !preg_match('/[A-Z]/', $newPw) || !preg_match('/[a-z]/', $newPw) || !preg_match('/[0-9]/', $newPw) || !preg_match('/[^a-zA-Z0-9\s\x00-\x1F\x7F]/', $newPw)) {
                setFlash('error', $LANG['val_password_strong'] ?? 'Password must be at least 6 characters, and include at least one uppercase letter, one lowercase letter, one number, and one special character (no spaces).');
                header('Location: profile.php');
                exit;
            }
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
        }

        // Handle profile image upload
        $profileImage = $userData['profile_image'] ?? null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];

            // Secure validation using finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

            if (in_array($ext, $allowed) && in_array($mime, $allowedMimes) && $file['size'] <= 5 * 1024 * 1024) {
                $uploadDir = __DIR__ . '/../assets/uploads/profiles';
                if (!is_dir($uploadDir))
                    mkdir($uploadDir, 0755, true);
                $filename = 'profile_' . $user['id'] . '_' . time() . '.' . $ext;
                $filepath = $uploadDir . '/' . $filename;
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    if ($profileImage && file_exists(__DIR__ . '/../' . $profileImage)) {
                        unlink(__DIR__ . '/../' . $profileImage);
                    }
                    $profileImage = 'assets/uploads/profiles/' . $filename;
                }
            } else {
                setFlash('error', $LANG['flash_image_invalid'] ?? 'Image must be JPG, PNG, or WebP and under 5MB.');
                header('Location: profile.php');
                exit;
            }
        }

        // Save to DB
        if ($hash) {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, profile_image=?, password=? WHERE id=?");
            $stmt->bind_param('ssssi', $name, $email, $profileImage, $hash, $user['id']);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, profile_image=? WHERE id=?");
            $stmt->bind_param('sssi', $name, $email, $profileImage, $user['id']);
        }

        if ($stmt->execute()) {
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;
            $_SESSION['profile_image'] = $profileImage;
            setFlash('success', $LANG['flash_profile_updated'] ?? 'Profile updated successfully.');
        } else {
            setFlash('error', $LANG['flash_email_in_use'] ?? 'Email already in use.');
        }
        $stmt->close();

        header('Location: profile.php');
        exit;
    }
}

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="mb-6">
    <!-- <h2 class="text-xl font-bold text-slate-800"><?= $LANG['my_profile'] ?? 'My Profile' ?></h2> -->
    <p class="text-sm text-slate-500 mt-0.5">
        <?= $LANG['profile_subtitle'] ?? 'Manage your account information and password' ?>
    </p>
</div>

<?php renderFlash() ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Profile Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 flex flex-col items-center text-center">
        <div class="mb-4 relative">
            <?php if (!empty($userData['profile_image'])): ?>
                <img src="<?= e(BASE_URL) ?><?= e($userData['profile_image']) ?>" alt="Profile"
                    class="w-20 h-20 rounded-full object-cover shadow-lg border-2 border-white">
            <?php else: ?>
                <div
                    class="w-20 h-20 rounded-full bg-gradient-to-br from-cyan-500 to-cyan-700 flex items-center justify-center text-2xl font-bold text-white shadow-lg">
                    <?= e(avatarInitials($userData['name'])) ?>
                </div>
            <?php endif; ?>
        </div>
        <h3 class="text-lg font-semibold text-slate-800"><?= e($userData['name']) ?></h3>
        <p class="text-sm text-slate-500 mt-1"><?= e($userData['email']) ?></p>
        <div class="mt-3"><?= badgeRole($userData['role']) ?></div>
        <div class="w-full border-t border-slate-100 mt-5 pt-5">
            <div class="flex justify-between text-sm"><span
                    class="text-slate-500"><?= $LANG['username_label'] ?? 'Username' ?></span><span
                    class="font-medium text-slate-700 font-mono"><?= e($userData['username']) ?></span></div>
            <div class="flex justify-between text-sm mt-2"><span
                    class="text-slate-500"><?= $LANG['member_since'] ?? 'Member Since' ?></span><span
                    class="font-medium text-slate-700"><?= formatDate($userData['created_at']) ?></span></div>
        </div>
    </div>

    <!-- Forms -->
    <div class="lg:col-span-2 space-y-5">
        <!-- Update Info -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="font-semibold text-slate-800"><?= $LANG['update_information'] ?? 'Update Information' ?></h3>
            </div>
            <form id="profileForm" method="POST" enctype="multipart/form-data" class="px-6 py-5 space-y-4" novalidate>
                <?= csrfField() ?><input type="hidden" name="action" value="update_info">

                <!-- Profile Image -->
                <div class="flex items-center gap-5">
                    <div class="shrink-0">
                        <img id="imagePreview"
                            src="<?= !empty($userData['profile_image']) ? e(BASE_URL . $userData['profile_image']) : '' ?>"
                            alt="Preview"
                            class="w-16 h-16 rounded-full object-cover border-2 border-slate-200 <?= empty($userData['profile_image']) ? 'hidden' : '' ?>">
                        <div id="initialsPreview"
                            class="w-16 h-16 rounded-full bg-gradient-to-br from-cyan-500 to-cyan-700 flex items-center justify-center text-lg font-bold text-white <?= !empty($userData['profile_image']) ? 'hidden' : '' ?>">
                            <?= e(avatarInitials($userData['name'])) ?>
                        </div>
                    </div>
                    <div class="flex-1">
                        <label
                            class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['profile_image'] ?? 'Profile Image' ?></label>
                        <input type="file" name="profile_image" id="profileImageInput" accept="image/*"
                            class="w-full text-sm text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-cyan-50 file:text-cyan-700 hover:file:bg-cyan-100 file:cursor-pointer">
                        <div class="mt-2 flex items-center gap-4">
                            <p class="text-xs text-slate-400">
                                <?= $LANG['profile_image_hint'] ?? 'JPG, PNG, or WebP. Max 5MB.' ?>
                            </p>
                            <?php if (!empty($userData['profile_image'])): ?>
                                <button type="submit" name="remove_photo" value="1" formnovalidate
                                    class="text-xs font-semibold text-white px-3 py-1 bg-red-600 rounded-md hover:bg-red-700"
                                    onclick="return confirm('<?= $LANG['confirm_remove_photo'] ?? 'Are you sure you want to remove your profile photo?' ?>');">
                                    <?= $LANG['remove_photo'] ?? 'Remove Photo' ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['full_name_label'] ?? 'Full Name' ?></label>
                    <input type="text" name="name" required value="<?= e($userData['name']) ?>"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['email_address_label'] ?? 'Email Address' ?></label>
                    <input type="email" name="email" required value="<?= e($userData['email']) ?>"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>

                <!-- Password Fields -->
                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['current_password'] ?? 'Current Password' ?></label>
                    <div class="relative">
                        <input type="password" name="current_password" id="currentPassword" placeholder="••••••••"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                        <button type="button" onclick="togglePassword('currentPassword', this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">
                        <?= $LANG['verify_to_save'] ?? 'Required only if changing your password.' ?>
                    </p>
                </div>
                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['new_password_label'] ?? 'New Password' ?>
                        <span
                            class="text-xs text-slate-400">(<?= $LANG['new_password_hint'] ?? 'leave blank to keep current' ?>)</span></label>
                    <div class="relative">
                        <input type="password" name="new_password" id="newPassword" minlength="6" placeholder="••••••••"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                        <button type="button" onclick="togglePassword('newPassword', this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        <?= e($LANG['password_hint_strong'] ?? 'Min 6 chars: 1 uppercase, 1 lowercase, 1 number, 1 special char. No spaces.') ?>
                    </p>
                </div>
                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['confirm_password_label'] ?? 'Confirm New Password' ?></label>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="confirmPassword" minlength="6"
                            placeholder="••••••••"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 pr-10 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                        <button type="button" onclick="togglePassword('confirmPassword', this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <a href="profile.php"
                        class="px-6 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></a>
                    <button type="submit"
                        class="px-6 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm"><?= $LANG['save_changes_btn'] ?? 'Save Changes' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Profile image preview
    document.getElementById('profileImageInput').addEventListener('change', function (e) {
        var file = e.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function (ev) {
                var preview = document.getElementById('imagePreview');
                var initials = document.getElementById('initialsPreview');
                preview.src = ev.target.result;
                preview.classList.remove('hidden');
                initials.classList.add('hidden');
            };
            reader.readAsDataURL(file);
        }
    });

    // Password show/hide toggle
    function togglePassword(id, btn) {
        var inp = document.getElementById(id);
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.innerHTML = show
            ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
    }
</script>

<?php include '../includes/admin_footer.php'; ?>
