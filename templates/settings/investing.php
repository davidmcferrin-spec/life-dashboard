<?php
/**
 * Read-only positions. No control on this page places an order.
 *
 * @var array<string, mixed> $user
 */

$pageTitle   = 'Investing';
$active      = 'investing';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Investing'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Positions come from a Robinhood activity CSV and the public Polymarket data API.
                    This dashboard does not place trades.
                </p>
            </header>

            <section class="surface p-4 mb-4">
                <h2 class="section-title">Robinhood activity CSV</h2>
                <input id="invest-csv" type="file" accept=".csv,text/csv" class="text-sm mt-2">
                <p id="invest-import-status" class="ld-meta mt-2"></p>
            </section>

            <p class="mb-4">
                <button type="button" id="invest-wallet-open" class="btn-primary">Polymarket wallet</button>
            </p>

            <div id="invest-wallet-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="invest-wallet-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="invest-wallet-title" class="section-title text-sm">Polymarket wallet</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="invest-wallet" class="space-y-3">
                        <label class="block ld-field">
                            <span>0x address</span>
                            <input name="address" class="input w-full" placeholder="0x…" maxlength="42" autocomplete="off">
                        </label>
                        <p id="invest-wallet-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary">Save</button>
                            <button type="button" class="btn" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <section class="surface p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="section-title">Scorecard</h2>
                    <button type="button" id="invest-refresh" class="btn text-xs">Refresh public positions</button>
                </div>
                <p id="invest-wallet-status" class="ld-meta mt-2"></p>
                <div id="invest-card" class="mt-2"></div>
            </section>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-investing.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
