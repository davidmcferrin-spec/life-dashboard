<?php
/**
 * Accounts, transactions, and the weekly review.
 *
 * @var array<string, mixed> $user
 * @var string $assetVer
 */

$pageTitle = 'Money';

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
            <h1 class="fin-title">Money</h1>
            <p class="fin-lead">A clear view of your accounts and spending. A negative amount is money out.</p>
        </div>
        <button type="button" id="money-account-open" class="btn-primary fin-add">
            <span class="fin-upload-icon" aria-hidden="true"><?php $finIcon('plus'); ?></span>
            Add account
        </button>
    </header>

    <div id="money-account-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-account-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="money-account-title" class="fin-modal-title">Add account</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="money-account" class="space-y-3">
                <label class="block ld-field"><span>Name</span><input name="name" class="input w-full" required maxlength="80"></label>
                <label class="block ld-field"><span>Kind</span>
                    <select name="kind" class="input w-full">
                        <option value="checking">Checking</option>
                        <option value="savings">Savings</option>
                        <option value="credit">Credit</option>
                        <option value="cash">Cash</option>
                        <option value="investment">Investment</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label class="block ld-field"><span>Balance</span><input name="balance" type="number" step="0.01" class="input w-full" value="0"></label>
                <label class="block ld-field"><span>Last 4</span><input name="last4" class="input w-full" inputmode="numeric" maxlength="8" autocomplete="off" placeholder="Optional"></label>
                <p id="money-account-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Add account</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="money-edit-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-edit-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="money-edit-title" class="fin-modal-title">Edit balance</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="money-edit" class="space-y-3">
                <input type="hidden" name="id" id="money-edit-id" value="">
                <label class="block ld-field"><span>Balance</span><input id="money-edit-balance" name="balance" type="number" step="0.01" class="input w-full"></label>
                <p id="money-edit-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="money-stats" class="fin-stats" aria-live="polite"></div>

    <div class="fin-grid">
        <section class="fin-card" aria-labelledby="money-chart-title">
            <header class="fin-card-head">
                <div class="fin-card-title">
                    <span class="fin-icon" aria-hidden="true"><?php $finIcon('chart-column'); ?></span>
                    <div>
                        <span class="fin-eyebrow">Last 6 months</span>
                        <h2 id="money-chart-title">Cash flow</h2>
                    </div>
                </div>
            </header>
            <div id="money-chart" class="fin-chart"></div>
        </section>
        <section class="fin-card" aria-labelledby="money-accounts-title">
            <header class="fin-card-head">
                <div class="fin-card-title">
                    <span class="fin-icon" aria-hidden="true"><?php $finIcon('wallet-cards'); ?></span>
                    <div>
                        <span class="fin-eyebrow">Balances</span>
                        <h2 id="money-accounts-title">Accounts</h2>
                    </div>
                </div>
            </header>
            <div id="money-accounts"></div>
        </section>
    </div>

    <section class="fin-card" aria-labelledby="money-tx-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <span class="fin-icon" aria-hidden="true"><?php $finIcon('list-filter'); ?></span>
                <div>
                    <span class="fin-eyebrow">Latest</span>
                    <h2 id="money-tx-title">Recent transactions</h2>
                </div>
            </div>
            <div class="fin-table-actions">
                <button type="button" id="money-filter" class="btn fin-filter" aria-expanded="false" aria-controls="money-filter-input">
                    <span class="fin-filter-icon" aria-hidden="true"><?php $finIcon('funnel'); ?></span>
                    Filter
                </button>
                <button type="button" id="money-review" class="btn">Write weekly review</button>
            </div>
        </header>
        <div class="fin-filter-row" id="money-filter-row" hidden>
            <input id="money-filter-input" class="input fin-filter-input" type="search" placeholder="Date, merchant, or category" aria-label="Filter transactions">
        </div>
        <div id="money-tx"></div>
    </section>

    <section class="fin-card" aria-labelledby="money-import-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <div>
                    <span class="fin-eyebrow">CSV</span>
                    <h2 id="money-import-title">Import</h2>
                </div>
            </div>
        </header>
        <p class="fin-note">Columns: Date, Amount, Merchant, Category, Account.</p>
        <div class="fin-tools">
            <label class="btn fin-upload">
                <span class="fin-upload-icon" aria-hidden="true"><?php $finIcon('upload'); ?></span>
                Import CSV
                <input id="money-csv" class="fin-file" type="file" accept=".csv,text/csv">
            </label>
        </div>
        <p id="money-import-status" class="fin-status" role="status"></p>
    </section>
</main>

<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/fin-table.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/settings-money.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
