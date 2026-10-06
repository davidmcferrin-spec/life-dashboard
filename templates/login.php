<?php
/** @var string|null $error */
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
$pageTitle = 'Login';
$themePref = 'system';
$themeLocalOnly = true;
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen flex items-center justify-center px-4">

    <div class="w-full max-w-sm">
        <div class="mb-8 text-center">
            <div class="brand mb-2">Personal Dashboard</div>
            <div class="text-primary text-2xl font-light tracking-tight">Sign In</div>
        </div>

        <div class="flex justify-center mb-6">
            <?php require __DIR__ . '/partials/theme-switcher.php'; ?>
        </div>

        <?php if ($error): ?>
        <div class="mb-4 px-4 py-3 rounded flash-error text-sm mono">
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/login" class="surface p-8">
            <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">

            <div class="mb-5">
                <label class="block text-muted text-xs mono uppercase tracking-wider mb-2" for="identifier">
                    Username or Email
                </label>
                <input type="text" id="identifier" name="identifier" autocomplete="username" autofocus required
                    class="input mono w-full" value="<?= e($_POST['identifier'] ?? '') ?>">
            </div>

            <div class="mb-6">
                <label class="block text-muted text-xs mono uppercase tracking-wider mb-2" for="password">
                    Password
                </label>
                <input type="password" id="password" name="password" autocomplete="current-password" required
                    class="input mono w-full">
            </div>

            <button type="submit" class="btn-primary w-full tracking-wide">
                Sign in →
            </button>
        </form>

        <div class="mt-4 text-center text-faint text-xs mono">
            <?= e(date('D, M j Y')) ?>
        </div>
    </div>

</body>
</html>
