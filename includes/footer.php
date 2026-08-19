<?php if ($showFooterContent ?? true): ?>
    <footer class="bg-indigo-600 text-white text-center py-5">
        © <?= date("Y") ?> UCSH - <?= $LANG['footer_system'] ?? 'Student Feedback Information System' ?>. <?= $LANG['footer_rights'] ?? 'All Rights Reserved' ?>.
    </footer>
<?php endif; ?>

<?php if ($showLoginModal ?? true): ?>
    <div id="loginModal"
        class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-md transition-opacity duration-300">

        <div
            class="relative w-[90%] max-w-[900px] bg-white/90 backdrop-blur-xl border border-white/60 rounded-[20px] p-8 md:p-10 shadow-2xl text-center mx-4 transform scale-95 transition-transform duration-300">

            <button onclick="closeLoginModal()"
                class="absolute top-6 right-8 text-cyan-600 hover:text-cyan-900 text-2xl transition-colors duration-200 focus:outline-none">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <h2 class="text-cyan-900 text-2xl md:text-3xl font-bold mb-10 tracking-tight">
                <?= $LANG['choose_login_role'] ?? 'Choose Your Login Role' ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <div
                    class="bg-white border border-black/5 rounded-2xl p-8 shadow-sm flex flex-col items-center justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <div class="w-[80px] h-[80px] rounded-full bg-cyan-50 flex items-center justify-center mb-6">
                        <i class="fa-solid fa-key text-3xl text-cyan-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-6"><?= $LANG['admin_login'] ?? 'Admin Login' ?></h3>
                    <a href="<?= e(BASE_URL) ?>auth/login.php?role=admin"
                        class="w-full py-3 px-4 bg-indigo-600  hover:bg-indigo-700 text-white font-medium text-sm rounded-lg transition-all duration-200 text-center block">
                        <?= $LANG['login_as_admin'] ?? 'Login as Administrator' ?>
                    </a>
                </div>

                <div
                    class="bg-white border border-black/5 rounded-2xl p-8 shadow-sm flex flex-col items-center justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <div class="w-[80px] h-[80px] rounded-full bg-cyan-50 flex items-center justify-center mb-6">
                        <i class="fa-solid fa-graduation-cap text-3xl text-cyan-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-6"><?= $LANG['teachers_login'] ?? 'Teachers Login' ?></h3>
                    <a href="<?= e(BASE_URL) ?>auth/login.php?role=teacher"
                        class="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-lg transition-all duration-200 text-center block">
                        <?= $LANG['login_as_teacher'] ?? 'Login as Teacher' ?>
                    </a>
                </div>

                <div
                    class="bg-white border border-black/5 rounded-2xl p-8 shadow-sm flex flex-col items-center justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <div class="w-[80px] h-[80px] rounded-full bg-cyan-50 flex items-center justify-center mb-6">
                        <i class="fa-solid fa-users text-3xl text-cyan-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-6"><?= $LANG['students_login'] ?? 'Students Login' ?></h3>
                    <a href="<?= e(BASE_URL) ?>auth/login.php?role=student"
                        class="w-full py-3 px-4 bg-indigo-600  hover:bg-indigo-700 text-white font-medium text-sm rounded-lg transition-all duration-200 text-center block">
                        <?= $LANG['login_as_student'] ?? 'Login as Student' ?>
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script>
        const loginModal = document.getElementById('loginModal');

        function openLoginModal() {
            loginModal.classList.remove('hidden');
            loginModal.classList.add('flex');
            setTimeout(() => {
                loginModal.firstElementChild.classList.remove('scale-95');
            }, 10);
        }

        function closeLoginModal() {
            loginModal.firstElementChild.classList.add('scale-95');
            setTimeout(() => {
                loginModal.classList.add('hidden');
                loginModal.classList.remove('flex');
            }, 200);
        }
    </script>
<?php endif; ?>

</body>

</html>
