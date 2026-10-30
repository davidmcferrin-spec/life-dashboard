<?php
/**
 * Positions and stored portfolio history. No control on this page places an order.
 *
 * @var array<string, mixed> $user
 * @var string $assetVer
 */

$pageTitle = 'Investing';

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
            <h1 class="fin-title">Investing</h1>
            <p class="fin-lead">Track performance, positions and market movement. A holding entry stays on this server and does not place an order.</p>
        </div>
        <button type="button" id="invest-position-open" class="btn-primary fin-add">
            <span class="fin-upload-icon" aria-hidden="true"><?php $finIcon('plus'); ?></span>
            Add position
        </button>
    </header>

    <div id="invest-position-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="invest-position-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="invest-position-title" class="fin-modal-title">Add position</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="invest-position" class="space-y-3">
                <input type="hidden" name="id" id="invest-position-id" value="">
                <label class="block ld-field"><span>Asset</span><input name="name" class="input w-full" maxlength="80" autocomplete="off"></label>
                <label class="block ld-field"><span>Symbol</span><input name="symbol" class="input w-full fin-symbol-input" required maxlength="16" autocapitalize="characters" spellcheck="false"></label>
                <label class="block ld-field"><span>Shares</span><input name="quantity" type="number" step="any" min="0" class="input w-full" required></label>
                <label class="block ld-field"><span>Average cost</span><input name="avg_cost" type="number" step="0.01" min="0" class="input w-full" placeholder="Optional"></label>
                <label class="block ld-field"><span>Price</span><input name="mark" type="number" step="0.01" min="0" class="input w-full" placeholder="Optional"></label>
                <p id="invest-position-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save holding</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="invest-cash-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="invest-cash-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="invest-cash-title" class="fin-modal-title">Cash available</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="invest-cash" class="space-y-3">
                <label class="block ld-field"><span>Buying power</span><input name="cash" type="number" step="0.01" min="0" class="input w-full"></label>
                <p id="invest-cash-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" class="btn" id="invest-cash-clear">Clear</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="invest-wallet-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="invest-wallet-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="invest-wallet-title" class="fin-modal-title">Polymarket wallet</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="invest-wallet" class="space-y-3">
                <label class="block ld-field">
                    <span>0x address</span>
                    <input name="address" class="input w-full" placeholder="0x…" maxlength="42" autocomplete="off">
                </label>
                <p id="invest-wallet-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="invest-stats" class="fin-stats" aria-live="polite"></div>

    <div class="fin-grid">
        <section class="fin-card" aria-labelledby="invest-chart-title">
            <header class="fin-card-head">
                <div class="fin-card-title">
                    <span class="fin-icon" aria-hidden="true"><?php $finIcon('chart-column'); ?></span>
                    <div>
                        <span class="fin-eyebrow">Last 6 months</span>
                        <h2 id="invest-chart-title">Portfolio performance</h2>
                    </div>
                </div>
            </header>
            <div id="invest-chart" class="fin-chart"></div>
        </section>
        <section class="fin-card" aria-labelledby="invest-wallet-card-title">
            <header class="fin-card-head">
                <div class="fin-card-title">
                    <span class="fin-icon" aria-hidden="true"><?php $finIcon('wallet-cards'); ?></span>
                    <div>
                        <span class="fin-eyebrow">Connected</span>
                        <h2 id="invest-wallet-card-title">Accounts</h2>
                    </div>
                </div>
                <button type="button" id="invest-cash-open" class="btn">Cash available</button>
            </header>
            <div id="invest-accounts"></div>
            <p id="invest-wallet-status" class="fin-status" role="status"></p>
            <div class="fin-tools fin-tools-split">
                <button type="button" id="invest-wallet-open" class="btn">Polymarket wallet</button>
                <button type="button" id="invest-refresh" class="btn">Refresh public positions</button>
            </div>
        </section>
    </div>

    <section class="fin-card" aria-labelledby="invest-holdings-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <span class="fin-icon" aria-hidden="true"><?php $finIcon('list-filter'); ?></span>
                <div>
                    <span class="fin-eyebrow">Scorecard</span>
                    <h2 id="invest-holdings-title">Holdings</h2>
                </div>
            </div>
            <div class="fin-table-actions">
                <button type="button" id="invest-filter" class="btn fin-filter" aria-expanded="false" aria-controls="invest-filter-input">
                    <span class="fin-filter-icon" aria-hidden="true"><?php $finIcon('funnel'); ?></span>
                    Filter
                </button>
            </div>
        </header>
        <div class="fin-filter-row" id="invest-filter-row" hidden>
            <input id="invest-filter-input" class="input fin-filter-input" type="search" placeholder="Asset, symbol, or venue" aria-label="Filter holdings">
        </div>
        <div id="invest-card"></div>
    </section>

    <section class="fin-card" aria-labelledby="invest-import-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <div>
                    <span class="fin-eyebrow">Robinhood</span>
                    <h2 id="invest-import-title">Activity CSV</h2>
                </div>
            </div>
        </header>
        <p class="fin-note">Import a Robinhood activity export. Nothing on this page sends an order.</p>
        <div class="fin-tools">
            <label class="btn fin-upload">
                <span class="fin-upload-icon" aria-hidden="true"><?php $finIcon('upload'); ?></span>
                Import CSV
                <input id="invest-csv" class="fin-file" type="file" accept=".csv,text/csv">
            </label>
        </div>
        <p id="invest-import-status" class="fin-status" role="status"></p>
    </section>
</main>

<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/fin-table.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/settings-investing.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
