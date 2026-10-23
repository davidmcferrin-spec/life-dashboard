<?php
$pageTitle = 'Forbidden';
$themePref = 'jarvis';
$themeLocalOnly = true;
require dirname(__DIR__) . '/partials/head.php';
?>
<body class="page min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm text-center">
        <?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Forbidden'; $pageHeadingCenter = true; require dirname(__DIR__) . '/partials/page-heading.php'; ?>
        <p class="text-muted text-sm mt-4">Admin access required.</p>
        <p class="mt-4"><a class="btn-primary inline-block" href="/settings">Back to settings</a></p>
    </div>
</body>
</html>
