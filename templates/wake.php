<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Wake Hub';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page">
    <?php $pageEyebrow = 'LAN'; $pageHeading = 'Wake Hub'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Machines from Wake Hub. Wake sends a magic packet. Off, cycle, reboot, and shutdown ask before they run.</p>
    <section class="platform-card wake-hub-page">
        <?php require __DIR__ . '/partials/wake-hub-card.php'; ?>
    </section>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/wake-hub.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
