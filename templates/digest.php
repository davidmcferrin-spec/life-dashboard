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
    <section class="surface p-4 space-y-3" id="digest-compare">
        <h2 class="text-primary text-base font-medium">Compare</h2>
        <div class="ld-digest-chips" id="digest-compare-modes">
            <button type="button" class="ld-digest-chip is-on" data-mode="date">Dates</button>
            <button type="button" class="ld-digest-chip" data-mode="version">Versions</button>
            <button type="button" class="ld-digest-chip" data-mode="snapshot">Snapshot</button>
        </div>
        <form id="digest-compare-form" class="flex flex-wrap gap-2 items-end">
            <label class="text-sm text-muted" data-for="date version">Product
                <select id="digest-compare-product" class="input"></select>
            </label>
            <label class="text-sm text-muted" data-for="date">From
                <input id="digest-from-date" class="input" type="date">
            </label>
            <label class="text-sm text-muted" data-for="date">To
                <input id="digest-to-date" class="input" type="date">
            </label>
            <label class="text-sm text-muted hidden" data-for="version">From
                <select id="digest-from-version" class="input"></select>
            </label>
            <label class="text-sm text-muted hidden" data-for="version">To
                <select id="digest-to-version" class="input"></select>
            </label>
            <label class="text-sm text-muted hidden" data-for="snapshot">Source
                <select id="digest-snap-source" class="input"></select>
            </label>
            <label class="text-sm text-muted hidden" data-for="snapshot">From
                <input id="digest-snap-from" class="input" type="date">
            </label>
            <label class="text-sm text-muted hidden" data-for="snapshot">To
                <input id="digest-snap-to" class="input" type="date">
            </label>
            <button type="submit" class="btn">Show</button>
        </form>
        <p id="digest-compare-count" class="text-sm text-muted"></p>
        <div id="digest-compare-out" class="space-y-3"></div>
        <form id="digest-retain-form" class="flex flex-wrap gap-2 items-end hidden" data-for="snapshot">
            <label class="text-sm text-muted">Keep snapshots
                <input id="digest-retain" class="input" type="number" min="1" max="3650" value="180">
            </label>
            <span class="text-sm text-muted">days</span>
            <button type="submit" class="btn">Save</button>
        </form>
    </section>
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
