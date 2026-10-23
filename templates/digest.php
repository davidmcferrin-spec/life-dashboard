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
$digestIcon = static function (string $name): void {
    $path = __DIR__ . '/../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="dash-main mx-auto py-6 space-y-6" id="digest-app" data-tz="<?= e($digestTz) ?>">
<div class="ld-grid<?= $gridMaxWidth ? ' ld-cols-capped' : '' ?>" data-grid-cap="<?= $gridMaxWidth ? '1' : '0' ?>">
<div id="digest-band">
    <header class="ld-digest-top">
        <div class="ld-digest-heading">
            <?php $pageEyebrow = 'Curated by Grok'; $pageHeading = 'Grok Digest'; require __DIR__ . '/partials/page-heading.php'; ?>
            <p class="ld-digest-lead" id="digest-intro">One feed of Cursor, Grok Bot, Grok, and Grok Build release notes.</p>
        </div>
        <div class="ld-digest-tools">
            <button type="button" class="btn" id="digest-read">Mark all read</button>
            <button type="button" class="btn-primary" id="digest-check">Check now</button>
            <div class="ld-digest-menu">
                <button type="button" class="btn" id="digest-menu" aria-haspopup="menu" aria-expanded="false" aria-controls="digest-menu-panel">Menu</button>
                <div class="ld-digest-menu-panel" id="digest-menu-panel" role="menu" aria-labelledby="digest-menu" hidden>
                    <button type="button" class="ld-digest-menu-item is-text" role="menuitem" data-digest-panel="history" aria-current="true">History</button>
                    <button type="button" class="ld-digest-menu-item" role="menuitem" data-digest-panel="compare"><?php $digestIcon('git-compare'); ?>Compare</button>
                    <button type="button" class="ld-digest-menu-item" role="menuitem" data-digest-panel="sources"><?php $digestIcon('rss'); ?>Sources</button>
                </div>
            </div>
        </div>
    </header>
    <p id="digest-error" class="ld-error hidden"></p>
    <p id="digest-status" class="ld-digest-meta" role="status"></p>
    <div class="ld-digest-pane is-shown" data-digest-view="history">
        <div class="ld-digest-filters">
            <div class="ld-digest-filter-row">
                <div class="ld-digest-chips" id="digest-products" aria-label="Source"></div>
                <p class="ld-digest-checked" id="digest-checked">Not checked yet.</p>
            </div>
            <div class="ld-digest-chips" id="digest-group" aria-label="Grouping">
                <button type="button" class="ld-digest-chip is-on" data-group="date">By date</button>
                <button type="button" class="ld-digest-chip" data-group="source">By source</button>
            </div>
            <form id="digest-search" class="ld-digest-search">
                <input type="search" id="digest-q" class="input" placeholder="Search" autocomplete="off">
                <label class="ld-digest-unread">
                    <input type="checkbox" id="digest-unread">
                    Unread only
                </label>
            </form>
            <p id="digest-count" class="ld-digest-meta"></p>
        </div>
        <div id="digest-feed" class="ld-digest-feed"></div>
        <button type="button" class="btn hidden ld-digest-more" id="digest-more">Load more</button>
    </div>
    <section class="ld-digest-pane ld-digest-panel" id="digest-compare" data-digest-view="compare">
        <h2 class="ld-digest-panel-title">Compare</h2>
        <div class="ld-digest-chips" id="digest-compare-modes">
            <button type="button" class="ld-digest-chip is-on" data-mode="date">Dates</button>
            <button type="button" class="ld-digest-chip" data-mode="version">Versions</button>
            <button type="button" class="ld-digest-chip" data-mode="snapshot">Snapshot</button>
        </div>
        <form id="digest-compare-form" class="ld-digest-form">
            <label class="ld-digest-field" data-for="date version">Product
                <select id="digest-compare-product" class="input"></select>
            </label>
            <label class="ld-digest-field" data-for="date">From
                <input id="digest-from-date" class="input" type="date">
            </label>
            <label class="ld-digest-field" data-for="date">To
                <input id="digest-to-date" class="input" type="date">
            </label>
            <label class="ld-digest-field hidden" data-for="version">From
                <select id="digest-from-version" class="input"></select>
            </label>
            <label class="ld-digest-field hidden" data-for="version">To
                <select id="digest-to-version" class="input"></select>
            </label>
            <label class="ld-digest-field hidden" data-for="snapshot">Source
                <select id="digest-snap-source" class="input"></select>
            </label>
            <label class="ld-digest-field hidden" data-for="snapshot">From
                <input id="digest-snap-from" class="input" type="date">
            </label>
            <label class="ld-digest-field hidden" data-for="snapshot">To
                <input id="digest-snap-to" class="input" type="date">
            </label>
            <button type="submit" class="btn">Show</button>
        </form>
        <p id="digest-compare-count" class="ld-digest-meta"></p>
        <div id="digest-compare-out" class="ld-digest-feed"></div>
        <form id="digest-retain-form" class="ld-digest-form hidden" data-for="snapshot">
            <label class="ld-digest-field">Keep snapshots
                <input id="digest-retain" class="input" type="number" min="1" max="3650" value="180">
            </label>
            <span class="ld-digest-meta">days</span>
            <button type="submit" class="btn">Save</button>
        </form>
    </section>
    <section class="ld-digest-pane ld-digest-panel" id="digest-sources-fold" data-digest-view="sources">
        <h2 class="ld-digest-panel-title">Sources</h2>
        <div id="digest-sources" class="ld-digest-sources"></div>
        <form id="digest-add" class="ld-digest-add">
            <select id="digest-product" class="input" required></select>
            <input id="digest-label" class="input" maxlength="80" placeholder="Label" required>
            <input id="digest-url" class="input" type="url" placeholder="https://" required>
            <button type="submit" class="btn">Add source</button>
        </form>
    </section>
    <template id="digest-icon-chevron"><?php $digestIcon('chevron-down'); ?></template>
</div>
</div>
<script type="application/json" id="ld-band-items"><?= json_encode($bandItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<script type="application/json" id="ld-band-layouts"><?= json_encode($bandLayouts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-grid.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/digest.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
