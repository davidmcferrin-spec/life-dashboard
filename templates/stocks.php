<?php
/**
 * Holdings and the watch list. Add position records a holding. It does not place an order.
 *
 * @var array<string, mixed> $user
 * @var string $assetVer
 */

$pageTitle = 'Stocks';

$finIcon = static function (string $name): void {
    $path = __DIR__ . '/../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="fin-page">
    <template id="fin-icon-wallet"><?php $finIcon('wallet-cards'); ?></template>
    <template id="fin-icon-funnel"><?php $finIcon('funnel'); ?></template>
    <template id="fin-icon-ellipsis"><?php $finIcon('ellipsis'); ?></template>

    <header class="fin-head">
        <div>
            <p class="fin-eyebrow">Financial intelligence</p>
            <h1 class="fin-title">Stocks</h1>
            <p class="fin-lead">Track performance, positions and market movement. Add position records a holding on this server. It does not place an order.</p>
        </div>
        <div class="fin-table-actions">
            <button type="button" id="stocks-add-open" class="btn">Add symbol</button>
            <button type="button" id="stocks-position-open" class="btn-primary fin-add">
                <span class="fin-upload-icon" aria-hidden="true"><?php $finIcon('plus'); ?></span>
                Add position
            </button>
        </div>
    </header>

    <div id="stocks-position-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="stocks-position-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="stocks-position-title" class="fin-modal-title">Add position</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="stocks-position-form" class="space-y-3">
                <input type="hidden" name="id" id="stocks-position-id" value="">
                <label class="block ld-field"><span>Asset</span><input name="name" class="input w-full" maxlength="80" autocomplete="off"></label>
                <label class="block ld-field"><span>Symbol</span><input name="symbol" class="input w-full fin-symbol-input" required maxlength="16" autocapitalize="characters" spellcheck="false"></label>
                <label class="block ld-field"><span>Shares</span><input name="quantity" type="number" step="any" min="0" class="input w-full" required></label>
                <label class="block ld-field"><span>Average cost</span><input name="avg_cost" type="number" step="0.01" min="0" class="input w-full" placeholder="Optional"></label>
                <label class="block ld-field"><span>Price</span><input name="mark" type="number" step="0.01" min="0" class="input w-full" placeholder="Optional"></label>
                <p id="stocks-position-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save holding</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="stocks-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="stocks-add-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="stocks-add-title" class="fin-modal-title">Add symbol</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="stocks-add-form" class="space-y-3">
                <label class="block ld-field">
                    <span>Symbol</span>
                    <input type="text" name="symbol" required placeholder="e.g. TSLA" maxlength="16"
                        pattern="[A-Za-z0-9.\-]+"
                        class="input w-full fin-symbol-input"
                        autocapitalize="characters" spellcheck="false">
                </label>
                <p id="stocks-add-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Add symbol</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="stocks-stats" class="fin-stats" aria-live="polite"></div>

    <div class="fin-grid">
        <section class="fin-card" aria-labelledby="stocks-chart-title">
            <header class="fin-card-head">
                <div class="fin-card-title">
                    <span class="fin-icon" aria-hidden="true"><?php $finIcon('chart-column'); ?></span>
                    <div>
                        <span class="fin-eyebrow">Last 6 months</span>
                        <h2 id="stocks-chart-title">Portfolio performance</h2>
                    </div>
                </div>
            </header>
            <div id="stocks-chart" class="fin-chart"></div>
        </section>
        <section class="fin-card" aria-labelledby="stocks-accounts-title">
            <header class="fin-card-head">
                <div class="fin-card-title">
                    <span class="fin-icon" aria-hidden="true"><?php $finIcon('wallet-cards'); ?></span>
                    <div>
                        <span class="fin-eyebrow">Connected</span>
                        <h2 id="stocks-accounts-title">Accounts</h2>
                    </div>
                </div>
            </header>
            <div id="stocks-accounts"></div>
        </section>
    </div>

    <section class="fin-card" aria-labelledby="stocks-table-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <span class="fin-icon" aria-hidden="true"><?php $finIcon('list-filter'); ?></span>
                <div>
                    <span class="fin-eyebrow">Portfolio</span>
                    <h2 id="stocks-table-title">Holdings and watchlist</h2>
                </div>
            </div>
            <div class="fin-table-actions">
                <button type="button" id="stocks-filter" class="btn fin-filter" aria-expanded="false" aria-controls="stocks-filter-input">
                    <span class="fin-filter-icon" aria-hidden="true"><?php $finIcon('funnel'); ?></span>
                    Filter
                </button>
            </div>
        </header>
        <div class="fin-filter-row" id="stocks-filter-row" hidden>
            <input id="stocks-filter-input" class="input fin-filter-input" type="search" placeholder="Asset or symbol" aria-label="Filter holdings">
        </div>
        <div id="stocks-manage-list"></div>
    </section>
</main>

<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/fin-table.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/stocks-manage.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
