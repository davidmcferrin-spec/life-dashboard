<?php
/**
 * Changelog feed for Cursor and Grok products.
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Grok Digest';
$extraStyles = ['/assets/css/lifedash.css'];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<nav class="chrome flex items-center justify-between px-4 py-3">
    <a href="/" class="brand hover:text-accent">← Dashboard</a>
    <div class="nav-meta flex items-center gap-3 flex-wrap justify-end">
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <a href="/logout" class="nav-link nav-link-danger">Sign Out</a>
    </div>
</nav>

<main class="mx-auto px-4 py-6 space-y-4" id="digest-app">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-primary text-xl font-medium">Grok Digest</h1>
            <p class="text-muted text-sm mt-1" id="digest-count">Changelog notes in one list.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn" id="digest-read">Mark all read</button>
            <button type="button" class="btn-primary" id="digest-check">Check now</button>
        </div>
    </header>
    <p id="digest-status" class="text-sm text-muted" role="status"></p>
    <div class="ld-digest-chips" id="digest-products"></div>
    <form id="digest-search" class="flex flex-wrap gap-2">
        <input type="search" id="digest-q" class="input" placeholder="Search" autocomplete="off">
        <label class="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" id="digest-unread">
            Unread only
        </label>
    </form>
    <p id="digest-error" class="ld-error hidden"></p>
    <div id="digest-feed" class="space-y-3"></div>
    <section class="surface p-4 space-y-3">
        <h2 class="text-primary text-base font-medium">Sources</h2>
        <div id="digest-sources" class="space-y-4"></div>
        <form id="digest-add" class="grid gap-2 md:grid-cols-4">
            <select id="digest-product" class="input" required></select>
            <input id="digest-label" class="input" maxlength="80" placeholder="Label" required>
            <input id="digest-url" class="input" type="url" placeholder="https://" required>
            <button type="submit" class="btn">Add source</button>
        </form>
    </section>
</main>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/digest.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
