<?php
/**
 * Missing URL. Same centered shell as login, with the shared empty panel.
 */
$pageTitle = $pageTitle ?? 'Page not found';
$themePref = $themePref ?? 'jarvis';
$themeLocalOnly = $themeLocalOnly ?? true;
require dirname(__DIR__) . '/partials/head.php';
?>
<body class="page min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <?php
        $emptyTitle = 'Page not found';
        $emptyBody = 'That address is not part of this dashboard.';
        $emptyAction = 'Back to Dashboard';
        $emptyHref = '/';
        require dirname(__DIR__) . '/partials/empty-panel.php';
        ?>
    </div>
</body>
</html>
