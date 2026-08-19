<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';


requireRole('student');

$user = getCurrentUser();
$pageTitle = $LANG['my_profile'] ?? 'My Profile';
$navItems = [
    ['label' => $LANG['nav_dashboard'] ?? 'Dashboard', 'href' => BASE_URL . 'student/dashboard.php', 'key' => 'dashboard', 'icon' => 'home', 'iconColor' => 'text-yellow-300'],
    ['label' => $LANG['nav_history'] ?? 'Submission History', 'href' => BASE_URL . 'student/feedback_history.php', 'key' => 'history', 'icon' => 'history', 'iconColor' => 'text-teal-300'],
    ['label' => $LANG['nav_profile'] ?? 'Profile', 'href' => BASE_URL . 'student/profile.php', 'key' => 'profile', 'icon' => 'user', 'iconColor' => 'text-rose-300'],
];
$initials = avatarInitials($user['name']);

// ─── Validation helpers (same rules as admin/users.php) ──────────────────────
function isValidEmail($email)
{
    return preg_match('/^[a-zA-Z0-9._]+@(ucsh\.edu\.mm|gmail\.com)$/', $email);
}
function isValidPassword($password)
{
    if (strlen($password) < 6) return false;
    if (preg_match('/[\s\x00-\x1F\x7F]/', $password)) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[^a-zA-Z0-9\s\x00-\x1F\x7F]/', $password)) return false;
    return true;
}
function isValidName($name)
{
    $name = trim($name);
    if (strlen($name) < 2 || strlen($name) > 100)
        return false;
    return preg_match('/^(?:(?:Dr|Prof|U|Daw)\.\s)?[A-Za-z]+(?: [A-Za-z]+)*$/i', $name);
}
function formatName($name)
{
    $name = trim($name);
    $name = ucwords(strtolower($name));
    $name = preg_replace('/\b(Dr|Prof|U|Daw)\b\.(\s)/', '$1.$2', $name);
    return $name;
}

$stmt = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT u.*, st.roll_no FROM users u LEFT JOIN students st ON st.user_id=u.id WHERE u.id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── Inline error state ───────────────────────────────────────────────────────
$formErrors = $_SESSION['profile_errors'] ?? [];
$formValues = $_SESSION['profile_values'] ?? [];
unset($_SESSION['profile_errors'], $_SESSION['profile_values']);

$nameErr = in_array('name_invalid', $formErrors);
$emailErr = in_array('email_invalid', $formErrors) || in_array('email_domain', $formErrors);
$emailExistsErr = in_array('email_exists', $formErrors);
$passwordErr = in_array('password', $formErrors);
$requiredErr = in_array('required', $formErrors);
$hasErrors = !empty($formErrors);

// Restore previously submitted values on validation failure
$prevName = $hasErrors ? ($formValues['name'] ?? $userData['name']) : $userData['name'];
$prevEmail = $hasErrors ? ($formValues['email'] ?? $userData['email']) : $userData['email'];

$borderRed = 'border-red-500 focus:border-red-500 focus:ring-2 focus:ring-red-500/20';

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
        $rawName = clean($_POST['name'] ?? '');
        $email = strtolower(clean($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $errors = [];

        // --- Name ---
        if (!$rawName) {
            $errors[] = 'required';
        } elseif (!isValidName($rawName)) {
            $errors[] = 'name_invalid';
        }

        // --- Email ---
        if (!$email) {
            $errors[] = 'required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email_invalid';
        } elseif (!preg_match('/@(ucsh\.edu\.mm|gmail\.com)$/', $email)) {
            $errors[] = 'email_domain';
        } elseif (!isValidEmail($email)) {
            $errors[] = 'email_invalid';
        }

        // --- Password (optional) ---
        if ($password !== '' && !isValidPassword($password)) {
            $errors[] = 'password';
        }

        if ($errors) {
            $_SESSION['profile_errors'] = $errors;
            $_SESSION['profile_values'] = ['name' => $rawName, 'email' => $email];
            header('Location: profile.php');
            exit;
        }

        // Uniqueness check for email
        if ($email !== $userData['email']) {
            $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->bind_param('si', $email, $user['id']);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $_SESSION['profile_errors'] = ['email_exists'];
                $_SESSION['profile_values'] = ['name' => $rawName, 'email' => $email];
                header('Location: profile.php');
                exit;
            }
            $chk->close();
        }

        $name = formatName($rawName);

        // --- Profile image upload ---
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

        // --- Save to DB ---
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
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
            setFlash('error', $LANG['flash_profile_failed'] ?? 'Failed to update profile.');
        }
        $stmt->close();
    }
    header('Location: profile.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/css/custom.css">
    <style>
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }
    </style>
</head>

<body class="h-full bg-slate-50 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">
        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <!-- <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                    <?= iconSvg('academic', 'w-5 h-5 text-white') ?>
                </div> -->
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
                    <img src="<?= e(BASE_URL) ?>assets/uploads/profiles/image.png" alt="UCSH Logo"
                        class="w-full h-full object-contain rounded-xl">
                </div>
                <div>
                    <p class="text-lg font-bold"><?= $LANG['student_portal'] ?? 'SFIS Student' ?></p>
                    <p class="text-[10px] text-cyan-100">
                        <?= $LANG['student_portal_sub'] ?? 'Student Portal' ?>
                    </p>
                </div><button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100"><svg
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg></button>
            </div>
            <nav class="flex-1 py-4 px-3 space-y-0.5"><?php foreach ($navItems as $n):
                $a = $n['key'] === 'profile'; ?><a href="<?= $n['href'] ?>"
                        class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm <?= $a ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white' ?>"><?= iconSvg($n['icon'], 'w-5 h-5 ' . ($n['iconColor'] ?? 'text-white/80')) ?>
                        <?= e($n['label']) ?></a><?php endforeach ?></nav>
            <a href="<?= e(BASE_URL) ?>auth/logout.php" title="<?= $LANG['logout'] ?? 'Logout' ?>"
                class="block border-t border-white/15 bg-red-500/80 text-gray-50 hover:text-gray-200 transition-colors px-4 py-4 cursor-pointer">
                <div class="flex items-center justify-center gap-3">

                    <div class="min-w-0 ">
                        <p class="text-xl h-8"><?= $LANG['logout'] ?? 'Logout' ?></p>
                    </div>
                    <?= iconSvg('logout', 'w-6 h-6') ?>
                </div>
            </a>
        </aside>
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <?php include '../includes/student_header.php'; ?>
            <main class="flex-1 overflow-y-auto p-4 lg:p-6">
                <div class="mb-6">
                    <!-- <h2 class="text-xl font-bold text-slate-800"><?= $LANG['my_profile'] ?? 'My Profile' ?></h2> -->
                </div>
                <?php renderFlash() ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 flex flex-col items-center text-center">
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
                        <p class="text-sm text-slate-500 mt-1"><?= $LANG['student'] ?? 'Student' ?></p>
                        <div class="w-full border-t border-slate-100 mt-5 pt-5 space-y-2 text-left">
                            <div class="flex justify-between text-sm"><span
                                    class="text-slate-500"><?= $LANG['roll_no_label'] ?? 'Roll No' ?></span><span
                                    class="font-mono font-medium text-slate-700"><?= e($userData['roll_no'] ?? '—') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="lg:col-span-2 space-y-5">
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100">
                                <h3 class="font-semibold text-slate-800">
                                    <?= $LANG['update_profile'] ?? 'Update Profile' ?>
                                </h3>
                            </div>
                            <form id="profileForm" method="POST" enctype="multipart/form-data"
                                class="px-6 py-5 space-y-4" novalidate>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_info">

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

                                <!-- Full Name -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        <?= $LANG['full_name_label'] ?? 'Full Name' ?> <span
                                            class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="name" id="profileName" required value="<?= e($prevName) ?>"
                                        class="w-full border <?= $nameErr || $requiredErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                                    <p id="nameErrMsg"
                                        class="text-red-500 text-xs mt-1.5<?= ($nameErr || ($requiredErr && !$prevName)) ? '' : ' hidden' ?>">
                                        <?php if ($nameErr): ?>
                                            <?= $LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.' ?>
                                        <?php elseif ($requiredErr && !$prevName): ?>
                                            <?= $LANG['val_name_required'] ?? 'Full Name is required.' ?>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <!-- Email Address -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        <?= $LANG['email_address_label'] ?? 'Email Address' ?> <span
                                            class="text-red-500">*</span>
                                    </label>
                                    <input type="email" name="email" id="profileEmail" required
                                        value="<?= e($prevEmail) ?>"
                                        class="w-full border <?= $emailErr || $emailExistsErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                                    <p id="emailErrMsg"
                                        class="text-red-500 text-xs mt-1.5<?= ($emailErr || $emailExistsErr) ? '' : ' hidden' ?>">
                                        <?php if ($emailExistsErr): ?>
                                            <?= $LANG['val_email_taken'] ?? 'This email address is already registered.' ?>
                                        <?php elseif (in_array('email_domain', $formErrors)): ?>
                                            <?= $LANG['val_email_domain'] ?? 'Only @ucsh.edu.mm and @gmail.com email addresses are allowed.' ?>
                                        <?php elseif ($emailErr): ?>
                                            <?= $LANG['val_email_invalid'] ?? 'Please enter a valid email address.' ?>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <!-- Password -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        <?= $LANG['password_field'] ?? 'New Password' ?>
                                        <span
                                            class="text-xs text-slate-400">(<?= $LANG['new_password_hint'] ?? 'leave blank to keep current' ?>)</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" name="password" id="profilePassword" minlength="6"
                                             placeholder="••••••••"
                                            class="w-full border <?= $passwordErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 pr-10 text-sm outline-none">
                                        <button type="button" onclick="togglePassword('profilePassword', this)"
                                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600">
                                            <?= iconSvg('eye', 'w-4 h-4') ?>
                                        </button>
                                    </div>
                                    <p id="pwErrMsg"
                                        class="text-red-500 text-xs mt-1.5<?= $passwordErr ? '' : ' hidden' ?>">
                                        <?= $passwordErr ? ($LANG['val_password_strong'] ?? 'Password must be at least 6 characters, and include at least one uppercase letter, one lowercase letter, one number, and one special character (no spaces).') : '' ?>
                                    </p>
                                    <p id="pwHintMsg"
                                        class="text-xs text-slate-400 mt-1<?= $passwordErr ? ' hidden' : '' ?>"><?= e($LANG['password_hint_strong'] ?? 'Min 6 chars: 1 uppercase, 1 lowercase, 1 number, 1 special char. No spaces.') ?></p>
                                </div>

                                <div class="flex justify-end gap-3">
                                    <a href="profile.php" class="px-6 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></a>
                                    <button type="submit"
                                        class="px-6 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['update_btn'] ?? 'Update Profile' ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script>
        // ── Auto-hide flash message ───────────────────────────────────────────
        setTimeout(function() {
            var flash = document.getElementById('flash-alert');
            if (flash) {
                flash.style.transition = 'opacity 0.5s ease';
                flash.style.opacity = '0';
                setTimeout(function() { flash.remove(); }, 500);
            }
        }, 3000);

        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }

        // ── Show/hide password toggle ─────────────────────────────────────────
        function togglePassword(id, btn) {
            var inp = document.getElementById(id);
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            btn.innerHTML = show
                ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
        }

        // ── Profile image preview ────────────────────────────────────────────
        document.getElementById('profileImageInput').addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function (ev) {
                    const preview = document.getElementById('imagePreview');
                    const initials = document.getElementById('initialsPreview');
                    preview.src = ev.target.result;
                    preview.classList.remove('hidden');
                    initials.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }
        });

        // ── Frontend validation (same rules as admin) ────────────────────────
        var msgs = {
            name_required: <?= json_encode($LANG['val_name_required'] ?? 'Full Name is required.') ?>,
            name_invalid: <?= json_encode($LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.') ?>,
            email_required: <?= json_encode($LANG['val_email_required'] ?? 'Email Address is required.') ?>,
            email_invalid: <?= json_encode($LANG['val_email_invalid'] ?? 'Please enter a valid email address.') ?>,
            email_domain: <?= json_encode($LANG['val_email_domain'] ?? 'Only @ucsh.edu.mm and @gmail.com email addresses are allowed.') ?>,
            password: <?= json_encode($LANG['val_password_strong'] ?? 'Password must be at least 6 characters, and include at least one uppercase letter, one lowercase letter, one number, and one special character (no spaces).') ?>
        };

        function showError(inputEl, msgEl, msg) {
            inputEl.classList.add('border-red-500', 'focus:border-red-500');
            inputEl.classList.remove('border-slate-200', 'focus:border-cyan-500', 'border-blue-200/50', 'focus:border-blue-400');
            if (msgEl) { msgEl.textContent = msg; msgEl.classList.remove('hidden'); }
        }
        function clearError(inputEl, msgEl) {
            inputEl.classList.remove('border-red-500', 'focus:border-red-500');
            inputEl.classList.add('border-slate-200', 'focus:border-cyan-500');
            if (msgEl) { msgEl.textContent = ''; msgEl.classList.add('hidden'); }
        }

        document.getElementById('profileForm').addEventListener('submit', function (e) {
            var valid = true;

            var nameEl = document.getElementById('profileName');
            var emailEl = document.getElementById('profileEmail');
            var pwEl = document.getElementById('profilePassword');
            var nameErrEl = document.getElementById('nameErrMsg');
            var emailErrEl = document.getElementById('emailErrMsg');
            var pwErrEl = document.getElementById('pwErrMsg');
            var pwHintEl = document.getElementById('pwHintMsg');

            // --- Name ---
            var nameVal = nameEl.value.trim();
            if (!nameVal) {
                showError(nameEl, nameErrEl, msgs.name_required);
                valid = false;
            } else if (nameVal.length < 2 || nameVal.length > 100 ||
                !/^(?:(?:Dr|Prof|U|Daw)\.\s)?[A-Za-z]+(?: [A-Za-z]+)*$/i.test(nameVal)) {
                showError(nameEl, nameErrEl, msgs.name_invalid);
                valid = false;
            } else {
                clearError(nameEl, nameErrEl);
            }

            // --- Email ---
            var emailVal = emailEl.value.trim().toLowerCase();
            emailEl.value = emailVal;
            if (!emailVal) {
                showError(emailEl, emailErrEl, msgs.email_required);
                valid = false;
            } else if (!/^[a-zA-Z0-9._]+@(ucsh\.edu\.mm|gmail\.com)$/.test(emailVal)) {
                var emsg = emailVal.indexOf('@') >= 0 && !(/@(ucsh\.edu\.mm|gmail\.com)$/.test(emailVal))
                    ? msgs.email_domain : msgs.email_invalid;
                showError(emailEl, emailErrEl, emsg);
                valid = false;
            } else {
                clearError(emailEl, emailErrEl);
            }

            // --- Password (optional) ---
            var pwVal = pwEl.value;
            if (pwVal !== '') {
                if (
                    pwVal.length < 6 || 
                    /[\s\x00-\x1F\x7F]/.test(pwVal) || 
                    !/[A-Z]/.test(pwVal) || 
                    !/[a-z]/.test(pwVal) || 
                    !/[0-9]/.test(pwVal) || 
                    !/[^a-zA-Z0-9\s\x00-\x1F\x7F]/.test(pwVal)
                ) {
                    showError(pwEl, pwErrEl, msgs.password);
                    if (pwHintEl) pwHintEl.classList.add('hidden');
                    valid = false;
                } else {
                    clearError(pwEl, pwErrEl);
                    if (pwHintEl) pwHintEl.classList.remove('hidden');
                }
            } else {
                clearError(pwEl, pwErrEl);
                if (pwHintEl) pwHintEl.classList.remove('hidden');
            }

            if (!valid) e.preventDefault();
        });
    </script>
</body>

</html>
