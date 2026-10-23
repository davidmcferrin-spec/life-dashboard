<?php
/**
 * Public share view — download via token only (one file).
 *
 * @var string $shareToken 12-char share code
 */
$shareToken = $shareToken ?? '';
?>
<?php require __DIR__ . '/../partials/head.php'; ?>
<body class="page min-h-screen">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <span class="brand">Shared file</span>
    <span id="share-file-status" class="text-secondary mono text-xs"></span>
</nav>

<main class="max-w-screen-md mx-auto px-4 py-6">
    <section id="share-file-wrap" class="hidden surface p-6 text-center">
        <?php $pageEyebrow = 'Shared file'; $pageHeading = ''; $pageHeadingId = 'share-file-name'; require __DIR__ . '/../partials/page-heading.php'; ?>
        <p id="share-file-meta" class="text-faint mono text-xs mb-6"></p>
        <a id="share-file-download" href="#" class="btn-primary inline-block" download>Download</a>
    </section>
</main>

<script>
    window.SHARE_FILE_TOKEN = <?= json_encode($shareToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="/assets/js/share-file.js" defer></script>
</body>
</html>
