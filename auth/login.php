<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
session_start();

if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    match ($role) {
        'admin' => header('Location: ' . BASE_URL . 'admin/dashboard.php'),
        'teacher' => header('Location: ' . BASE_URL . 'teacher/dashboard.php'),
        'student' => header('Location: ' . BASE_URL . 'student/dashboard.php'),
        default => null,
    };
    exit;
}

$error = '';
$expectedRole = clean($_GET['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT id, name, email, username, password, role, profile_image FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if ($expectedRole !== '' && $user['role'] !== $expectedRole) {
                $error = $LANG['error_not_authorized'] ?? 'You are not authorized to log in from this page.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['profile_image'] = $user['profile_image'] ?? null;

                match ($user['role']) {
                    'admin' => header('Location: ' . BASE_URL . 'admin/dashboard.php'),
                    'teacher' => header('Location: ' . BASE_URL . 'teacher/dashboard.php'),
                    'student' => header('Location: ' . BASE_URL . 'student/dashboard.php'),
                    default => header('Location: ' . BASE_URL . 'auth/login.php'),
                };
                exit;
            }
        } else {
            $error = $LANG['error_invalid_credentials'] ?? 'Invalid email or password. Please try again.';
        }
    } else {
        $error = $LANG['error_fill_fields'] ?? 'Please fill in all fields.';
    }
}

$pageTitle = 'Login — Student Feedback Information System';
$showNav = true;
$isLoginPage = true;
$htmlClass = 'h-full';
$bodyClass = 'bg-gray-100 relative flex flex-col min-h-screen';
include '../includes/header.php';
?>

<section class="flex-1 flex items-center justify-center px-4 relative">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-96 h-96 rounded-full bg-cyan-500/10 blur-3xl"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 rounded-full bg-indigo-600/10 blur-3xl"></div>
    </div>

    <div class="relative w-full max-w-sm mx-auto">
        <div class="bg-white rounded-3xl shadow-xl border border-slate-200/60 overflow-hidden">

            <div class="bg-gradient-to-r from-cyan-600 to-cyan-700 px-6 py-5 text-center">
                <div
                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white/20 backdrop-blur mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" class="w-6 h-6 text-white">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" />
                    </svg> 

                   


                </div>
                <h1 class="text-xl font-bold text-white tracking-wide"><?= $LANG['sfms'] ?? 'SFIS' ?></h1>
                <p class="text-cyan-100 text-xs mt-0.5">
                    <?= $LANG['sfms_full'] ?? 'Student Feedback Information System' ?>
                </p>
            </div>

            <div class="px-6 py-5">
                <h2 class="text-lg font-bold text-slate-900 mb-0.5"><?= $LANG['welcome_back'] ?? 'Welcome back' ?></h2>
                <p class="text-xs text-slate-500 mb-4">
                    <?= $LANG['sign_in_to_continue'] ?? 'Sign in to your account to continue' ?>
                </p>

                <?php if ($error): ?>
                    <div
                        class="flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 rounded-lg px-3 py-2 mb-3 text-xs">❌
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" class="w-4 h-4 flex-shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif ?>

                <form method="POST" novalidate>
                    <div class="space-y-4">
                        <div>
                            <label for="email"
                                class="block text-xs font-medium text-slate-700 mb-1"><?= $LANG['email_address'] ?? 'Email Address' ?></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-2.5 text-slate-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                    </svg>
                                </span>
                                <input id="email" name="email" type="email" required autocomplete="email"
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                    placeholder="admin@ucsh.edu.mm"
                                    class="w-full pl-9 pr-3 py-2 border border-slate-200 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none transition-all">
                            </div>
                        </div>

                        <div>
                            <label for="password"
                                class="block text-xs font-medium text-slate-700 mb-1"><?= $LANG['password'] ?? 'Password' ?></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-2.5 text-slate-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                </span>
                                <input id="password" name="password" type="password" required
                                    autocomplete="current-password" placeholder="••••••••"
                                    class="w-full pl-9 pr-8 py-2 border border-slate-200 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none transition-all">
                                <button type="button" onclick="togglePwd()"
                                    class="absolute inset-y-0 right-0 flex items-center pr-2 text-slate-400 hover:text-slate-600">
                                    <svg id="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <svg id="eye-closed" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                        class="w-4 h-4 hidden">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit"
                            class="w-full bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition-all hover:shadow-lg hover:-translate-y-0.5 text-sm">
                            <?= $LANG['sign_in'] ?? 'Sign In' ?>
                        </button>
                    </div>
                </form>

                <!-- <div class="mt-4 text-center text-[10px] text-slate-400">
                        Default Admin: <span
                            class="font-mono text-slate-600 bg-slate-50 px-1 py-0.5 rounded">admin@sfis.edu</span> / <span
                            class="font-mono text-slate-600 bg-slate-50 px-1 py-0.5 rounded">Admin@123</span>
                    </div> -->
            </div>
        </div>
    </div>
</section>

<script>
    function togglePwd() {
        const inp = document.getElementById('password');
        const open = document.getElementById('eye-open');
        const cls = document.getElementById('eye-closed');
        if (inp.type === 'password') {
            inp.type = 'text';
            open.classList.add('hidden');
            cls.classList.remove('hidden');
        } else {
            inp.type = 'password';
            open.classList.remove('hidden');
            cls.classList.add('hidden');
        }
    }
</script>

<?php
$showFooterContent = true;
$showLoginModal = false;
include '../includes/footer.php';
?>
