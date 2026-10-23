<?php
/**
 * Public share view — read-only CloudPad canvas.
 *
 * @var string $shareToken
 */
$shareToken = $shareToken ?? '';
?>
<?php require __DIR__ . '/../partials/head.php'; ?>
<link rel="stylesheet" href="/assets/css/cloudpad.css?v=<?= e($assetVer) ?>">
<body class="page cloudpad-page min-h-screen">

<nav class="chrome flex items-center justify-between px-4 py-2">
    <span class="brand">Shared CloudPad</span>
    <div class="flex items-center gap-2">
        <span id="share-cloudpad-status" class="text-secondary mono text-xs"></span>
        <button type="button" id="share-cloudpad-fit" class="btn text-xs">Fit</button>
        <button type="button" id="share-cloudpad-png" class="btn text-xs">Download PNG</button>
    </div>
</nav>

<main class="cloudpad-share-main px-2 pb-4">
    <?php $pageEyebrow = 'Shared CloudPad'; $pageHeading = ''; $pageHeadingId = 'share-cloudpad-name'; require __DIR__ . '/../partials/page-heading.php'; ?>
    <div id="share-cloudpad-root" class="cloudpad-root cloudpad-root-share"></div>
</main>

<script>
    window.EXCALIDRAW_ASSET_PATH = '/assets/';
    window.SHARE_CLOUDPAD_TOKEN = <?= json_encode($shareToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="/assets/js/share-cloudpad.bundle.js?v=<?= e($assetVer) ?>" defer></script>
</body>
</html>
