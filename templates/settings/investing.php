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
                <h1 class="text-primary text-xl font-medium">Investing</h1>
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

            <form id="invest-wallet" class="surface p-4 space-y-3 mb-4">
                <h2 class="section-title">Polymarket wallet</h2>
                <label class="block ld-field">
                    <span>0x address</span>
                    <input name="address" class="input w-full" placeholder="0x…" maxlength="42" autocomplete="off">
                </label>
                <div class="flex gap-2">
                    <button type="submit" class="btn-primary">Save</button>
                    <button type="button" id="invest-refresh" class="btn">Refresh public positions</button>
                </div>
                <p id="invest-wallet-status" class="ld-meta"></p>
            </form>

            <section class="surface p-4">
                <h2 class="section-title">Scorecard</h2>
                <div id="invest-card" class="mt-2"></div>
            </section>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-investing.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
