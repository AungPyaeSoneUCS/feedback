<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="<?= e($htmlClass ?? '') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? ($LANG['sfms_full'] ?? 'Student Feedback Information System')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ucshTeal: {
                            50: '#e8f4f5',
                            600: '#2c6e75',
                            700: '#1f5258',
                            900: '#1a3d44',
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="<?= e($bodyClass ?? 'bg-gray-100 relative') ?> <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">

<?php if (!empty($showNav)): ?>
    <nav class="sticky top-0 z-40 bg-indigo-600 text-white shadow-lg">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">

            <div>
                <h1 class="text-3xl font-bold">UCSH</h1>
                <p class="text-sm"><?= $LANG['university_name_title'] ?? 'University of Computer Studies (Hinthada)' ?></p>
            </div>

            <ul class="hidden md:flex gap-10 items-center font-medium">
                <li><a href="<?= e(BASE_URL) ?>index.php" class="hover:text-cyan-300 transition"><?= $LANG['home'] ?? 'Home' ?></a></li>
                <li><a href="#" class="hover:text-cyan-300 transition"><?= $LANG['about'] ?? 'About' ?></a></li>
                <?php if (empty($isLoginPage)): ?>
                <li>
                    <a href="?lang=<?= ($_SESSION['lang'] ?? 'en') === 'en' ? 'mm' : 'en' ?>" class="border border-white px-3 py-1.5 rounded-lg hover:bg-cyan-500 hover:text-cyan-950 transition text-xs font-bold">
                        <?= ($_SESSION['lang'] ?? 'en') === 'en' ? 'မြန်မာ' : 'ENG' ?>
                    </a>
                </li>
                <li>
                    <button onclick="openLoginModal()"
                        class="border border-white px-5 py-2 rounded-lg hover:bg-cyan-500 hover:text-cyan-950 transition focus:outline-none">
                        <?= $LANG['login'] ?? 'Login' ?>
                    </button>
                </li>
                <?php endif; ?>
            </ul>

        </div>
    </nav>
<?php endif; ?>
