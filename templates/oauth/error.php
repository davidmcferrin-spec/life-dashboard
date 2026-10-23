<?php
/**
 * OAuth request that cannot be sent back to the client.
 *
 * @var string $message
 * @var int $status
 */
http_response_code($status);
$pageTitle = 'Connection failed';
$themePref = 'jarvis';
$themeLocalOnly = true;
require dirname(__DIR__) . '/partials/head.php';
?>
<body class="page min-h-screen flex items-center justify-center px-4">
    <div class="surface p-6 w-full max-w-md" id="oauth-error">
        <?php $pageEyebrow = 'Connection'; $pageHeading = 'Connection failed'; require __DIR__ . '/../partials/page-heading.php'; ?>
        <p class="text-muted text-sm mt-2" role="alert"><?= e($message) ?></p>
        <a class="btn mt-4 inline-block" href="/">Back to the dashboard</a>
    </div>
</body>
</html>
