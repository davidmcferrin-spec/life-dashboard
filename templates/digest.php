<?php
/**
 * Changelog feed for Cursor and Grok products.
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;
use Dashboard\Tracking\Services;

$pageTitle = 'Grok Digest';
$extraStyles = ['/assets/css/lifedash.css'];
$digestTz = \Dashboard\Config::get('APP_TIMEZONE', 'America/Chicago');
$digestDb = Database::getInstance();
$gridMaxWidth = (new PreferenceService($digestDb))->getGridMaxWidth((int) $user['id']);
$digestWidgets = Services::fromDatabase($digestDb)->widgets;
$bandItems = array_map(static fn (array $w): array => [
    'id' => (int) $w['id'],
    'x' => (int) $w['x'],
    'y' => (int) $w['y'],
    'w' => (int) $w['w'],
    'h' => (int) $w['h'],
], $digestWidgets->list((int) $user['id']));
$bandLayouts = $digestWidgets->breakpointLayouts((int) $user['id']);
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <a href="/" class="brand hover:text-accent">← Dashboard</a>
    <div class="nav-meta flex items-center gap-3 flex-wrap justify-end">
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <a href="/logout" class="nav-link nav-link-danger">Sign Out</a>
    </div>
</nav>

<main class="dash-main mx-auto py-6 space-y-6" id="digest-app" data-tz="<?= e($digestTz) ?>">
<div class="ld-grid<?= $gridMaxWidth ? ' ld-cols-capped' : '' ?>" data-grid-cap="<?= $gridMaxWidth ? '1' : '0' ?>">
<div id="digest-band">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Grok Digest'; require __DIR__ . '/partials/page-heading.php'; ?>
            <p class="text-muted text-sm mt-1" id="digest-intro">One feed of Cursor, Grok Bot, Grok, and Grok Build release notes.</p>
            <p class="text-muted text-sm" id="digest-checked">Not checked yet.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn" id="digest-read">Mark all read</button>
            <button type="button" class="btn-primary" id="digest-check">Check now</button>
        </div>
    </header>
    <div class="ld-digest-filters">
        <div class="ld-digest-chips" id="digest-products" aria-label="Source"></div>
        <div class="ld-digest-chips" id="digest-group" aria-label="Grouping">
            <button type="button" class="ld-digest-chip is-on" data-group="date">By date</button>
            <button type="button" class="ld-digest-chip" data-group="source">By source</button>
        </div>
        <form id="digest-search" class="flex flex-wrap gap-2 items-center">
            <input type="search" id="digest-q" class="input" placeholder="Search" autocomplete="off">
            <label class="flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" id="digest-unread">
                Unread only
            </label>
        </form>
        <p id="digest-status" class="text-sm text-muted" role="status"></p>
        <p id="digest-count" class="text-sm text-muted"></p>
    </div>
    <p id="digest-error" class="ld-error hidden"></p>
    <div id="digest-feed" class="ld-digest-feed"></div>
    <button type="button" class="btn hidden" id="digest-more">Load more</button>
    <details class="surface ld-digest-fold" id="digest-compare">
        <summary>Compare and snapshots</summary>
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
        <div id="digest-compare-out" class="ld-digest-feed"></div>
        <form id="digest-retain-form" class="flex flex-wrap gap-2 items-end hidden" data-for="snapshot">
            <label class="text-sm text-muted">Keep snapshots
                <input id="digest-retain" class="input" type="number" min="1" max="3650" value="180">
            </label>
            <span class="text-sm text-muted">days</span>
            <button type="submit" class="btn">Save</button>
        </form>
    </details>
    <details class="surface ld-digest-fold" id="digest-sources-fold">
        <summary>Sources</summary>
        <div id="digest-sources" class="space-y-4"></div>
        <form id="digest-add" class="ld-digest-add">
            <select id="digest-product" class="input" required></select>
            <input id="digest-label" class="input" maxlength="80" placeholder="Label" required>
            <input id="digest-url" class="input" type="url" placeholder="https://" required>
            <button type="submit" class="btn">Add source</button>
        </form>
    </details>
</div>
</div>
<script type="application/json" id="ld-band-items"><?= json_encode($bandItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<script type="application/json" id="ld-band-layouts"><?= json_encode($bandLayouts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
</main>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-grid.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/digest.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
