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
            <p class="fin-lead">Accounts, this cycle’s bills, and envelopes. A negative amount is money out. The debt snowball stays its own list.</p>
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

    <section class="fin-card" aria-labelledby="money-pressure-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <span class="fin-icon" aria-hidden="true"><?php $finIcon('calendar-days'); ?></span>
                <div>
                    <span class="fin-eyebrow">Before the next check</span>
                    <h2 id="money-pressure-title">Payday pressure</h2>
                </div>
            </div>
            <button type="button" class="btn" data-open-payday>Payday settings</button>
        </header>
        <div id="money-pressure" aria-live="polite"></div>
    </section>

    <section class="fin-card" aria-labelledby="money-bills-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <span class="fin-icon" aria-hidden="true"><?php $finIcon('landmark'); ?></span>
                <div>
                    <span class="fin-eyebrow">This cycle</span>
                    <h2 id="money-bills-title">Bills and autopays</h2>
                </div>
            </div>
            <button type="button" class="btn-primary" data-open-bill>Add bill</button>
        </header>
        <p class="fin-note">Grouped by the account that pays them. A bill linked to a debt is this cycle’s payment. The balance stays on the snowball.</p>
        <div id="money-bills" aria-live="polite"></div>
    </section>

    <section class="fin-card" aria-labelledby="money-envelopes-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <span class="fin-icon" aria-hidden="true"><?php $finIcon('piggy-bank'); ?></span>
                <div>
                    <span class="fin-eyebrow">Named jobs</span>
                    <h2 id="money-envelopes-title">Envelopes</h2>
                </div>
            </div>
            <button type="button" class="btn-primary" data-open-envelope>Add envelope</button>
        </header>
        <p class="fin-note">Link a real account, such as an X Money bucket, when the money is there. Weekly food stays a weekly cap.</p>
        <div id="money-proposal"></div>
        <div id="money-envelopes" aria-live="polite"></div>
    </section>

    <section class="fin-card" aria-labelledby="money-snowball-title">
        <header class="fin-card-head">
            <div class="fin-card-title">
                <span class="fin-icon" aria-hidden="true"><?php $finIcon('landmark'); ?></span>
                <div>
                    <span class="fin-eyebrow">Payoff plan</span>
                    <h2 id="money-snowball-title">Debt snowball</h2>
                </div>
            </div>
        </header>
        <p class="fin-note">Balances, APR, and payoff order. This list is not bills and not envelopes.</p>
        <div id="money-snowball" aria-live="polite"></div>
    </section>

    <div id="money-bill-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-bill-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="money-bill-title" class="fin-modal-title">Add bill</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="money-bill-form" class="space-y-3 fin-modal-scroll">
                <input type="hidden" name="id" value="">
                <label class="block ld-field"><span>Name</span><input name="name" class="input w-full" required maxlength="80"></label>
                <label class="block ld-field"><span>Amount</span><input name="amount" type="number" step="0.01" min="0" class="input w-full" value="0"></label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="estimated"> Estimated</label>
                <label class="block ld-field"><span>Cadence</span>
                    <select name="cadence" class="input w-full">
                        <option value="monthly">Monthly</option>
                        <option value="biweekly">Biweekly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </label>
                <label class="block ld-field" data-bill-field="due_day"><span>Due day</span><input name="due_day" type="number" min="1" max="31" class="input w-full"></label>
                <label class="block ld-field" data-bill-field="due_month"><span>Due month</span><input name="due_month" type="number" min="1" max="12" class="input w-full"></label>
                <label class="block ld-field" data-bill-field="anchor_on"><span>Known due date</span><input name="anchor_on" type="date" class="input w-full"></label>
                <label class="block ld-field"><span>Pay from account</span><select name="pay_from_account_id" class="input w-full"></select></label>
                <label class="block ld-field"><span>Pay-from label</span><input name="pay_from_label" class="input w-full" maxlength="80" placeholder="Used when no account is linked"></label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="autopay"> Autopay</label>
                <label class="block ld-field"><span>Linked debt</span><select name="debt_id" class="input w-full"></select></label>
                <label class="block ld-field"><span>Statement minimum</span><input name="statement_min" type="number" step="0.01" min="0" class="input w-full" placeholder="Optional"></label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="default_notice"> Default notice</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="cancelled_charging"> Cancelled subscription charged again</label>
                <label class="block ld-field"><span>Notes</span><textarea name="notes" class="input w-full" rows="2" maxlength="2000"></textarea></label>
                <div id="money-bill-history" class="fin-note"></div>
                <p id="money-bill-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save bill</button>
                    <button type="button" class="btn" id="money-bill-archive" hidden>Archive</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
            <p class="fin-note">Saving records the bill. Mark paid on the list records the cycle and does not move money.</p>
        </div>
    </div>

    <div id="money-envelope-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-envelope-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="money-envelope-title" class="fin-modal-title">Add envelope</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="money-envelope-form" class="space-y-3">
                <input type="hidden" name="id" value="">
                <label class="block ld-field"><span>Name</span><input name="name" class="input w-full" required maxlength="80"></label>
                <label class="block ld-field"><span>Job</span>
                    <select name="job_type" class="input w-full">
                        <option value="sinking">Sinking fund</option>
                        <option value="buffer">Buffer</option>
                        <option value="gifts">Christmas gifts</option>
                        <option value="trip">Trip</option>
                        <option value="escrow">Escrow</option>
                        <option value="deep_clean">Deep clean</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label class="block ld-field"><span>Target</span><input name="target_amount" type="number" step="0.01" min="0" class="input w-full" value="0"></label>
                <label class="block ld-field"><span>Manual funded</span><input name="funded_amount" type="number" step="0.01" min="0" class="input w-full" value="0"></label>
                <label class="block ld-field"><span>Target date</span><input name="target_on" type="date" class="input w-full"></label>
                <label class="block ld-field"><span>Linked account</span><select name="account_id" class="input w-full"></select></label>
                <label class="block ld-field"><span>Notes</span><textarea name="notes" class="input w-full" rows="2" maxlength="2000"></textarea></label>
                <p class="fin-note">A linked account balance is what this page shows. Saving does not transfer money.</p>
                <p id="money-envelope-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save envelope</button>
                    <button type="button" class="btn" id="money-envelope-archive" hidden>Archive</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="money-payday-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-payday-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="money-payday-title" class="fin-modal-title">Payday</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="money-payday-form" class="space-y-3">
                <label class="block ld-field"><span>Next payday</span><input name="next_on" type="date" class="input w-full"></label>
                <label class="block ld-field"><span>Cadence</span>
                    <select name="cadence" class="input w-full">
                        <option value="weekly">Weekly</option>
                        <option value="biweekly" selected>Biweekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </label>
                <label class="block ld-field"><span>A known payday</span><input name="anchor_on" type="date" class="input w-full"></label>
                <label class="block ld-field"><span>Paycheck</span><input name="paycheck_amount" type="number" step="0.01" min="0" class="input w-full"></label>
                <label class="block ld-field"><span>Living float</span><input name="living_float" type="number" step="0.01" min="0" class="input w-full"></label>
                <p class="fin-note">Living float is a reserve on this check. Weekly food stays on its own cap and is not part of this number.</p>
                <p id="money-payday-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Save payday</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="money-propose-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="money-propose-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel surface">
            <div class="fin-modal-head">
                <h2 id="money-propose-title" class="fin-modal-title">Propose assignment</h2>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $finIcon('x'); ?></button>
            </div>
            <form id="money-propose-form" class="space-y-3">
                <input type="hidden" name="envelope_id" value="">
                <p id="money-propose-name" class="fin-note"></p>
                <label class="block ld-field"><span>Amount</span><input name="amount" type="number" step="0.01" min="0.01" class="input w-full" required></label>
                <p class="fin-note">This returns a proposal. It does not move money.</p>
                <p id="money-propose-error" class="ld-field-error hidden" role="alert"></p>
                <div class="fin-modal-actions">
                    <button type="submit" class="btn-primary">Show proposal</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

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
<script src="/assets/js/money-bills.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
