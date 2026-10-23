<?php
/**
 * Accounts, Monarch-style CSV, and the weekly review page.
 *
 * @var array<string, mixed> $user
 */

$pageTitle   = 'Money';
$active      = 'money';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Money'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Balances and signed transactions. A negative amount is money out.
                    CSV columns: Date, Amount, Merchant, Category, Account.
                </p>
            </header>

            <p class="mb-4">
                <button type="button" id="money-account-open" class="btn-primary">Add account</button>
            </p>

            <div id="money-account-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-account-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="money-account-title" class="section-title text-sm">Add account</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
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
                        <p id="money-account-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary">Add account</button>
                            <button type="button" class="btn" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="money-edit-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-edit-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="money-edit-title" class="section-title text-sm">Edit balance</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="money-edit" class="space-y-3">
                        <input type="hidden" name="id" id="money-edit-id" value="">
                        <label class="block ld-field"><span>Balance</span><input id="money-edit-balance" name="balance" type="number" step="0.01" class="input w-full"></label>
                        <p id="money-edit-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary">Save</button>
                            <button type="button" class="btn" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <section class="surface p-4 mb-4">
                <h2 class="section-title">Balances</h2>
                <div id="money-accounts" class="mt-2"></div>
            </section>

            <section class="surface p-4 mb-4">
                <h2 class="section-title">Import CSV</h2>
                <input id="money-csv" type="file" accept=".csv,text/csv" class="text-sm mt-2">
                <p id="money-import-status" class="ld-meta mt-2"></p>
            </section>

            <section class="surface p-4 mb-4">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Recent</h2>
                    <button type="button" id="money-review" class="btn text-xs">Write weekly review</button>
                </div>
                <div id="money-tx" class="mt-2"></div>
            </section>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-money.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
