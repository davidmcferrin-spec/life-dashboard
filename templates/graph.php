<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Links';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="graph-app">
    <?php $pageEyebrow = 'Memory'; $pageHeading = 'Links'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Pages, bills, envelopes, digests, health days, and briefs. Edges come from wikilinks, mentions, related names, and links you add. Stored in Postgres.</p>
    <p id="graph-error" class="ld-error hidden"></p>
    <form id="graph-search" class="platform-actions">
        <input class="input" name="q" placeholder="Search labels" maxlength="80">
        <button class="btn" type="submit">Search</button>
    </form>
    <div class="platform-actions" id="graph-results"></div>
    <section class="platform-card" id="graph-neighbors"></section>
    <form class="platform-card platform-form" id="graph-link">
        <p class="platform-kicker">Manual link</p>
        <input class="input" name="from_kind" placeholder="from kind" required>
        <input class="input" name="from_ref" type="number" min="1" placeholder="from id" required>
        <input class="input" name="to_kind" placeholder="to kind" required>
        <input class="input" name="to_ref" type="number" min="1" placeholder="to id" required>
        <button class="btn-primary" type="submit">Link</button>
    </form>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/graph.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
