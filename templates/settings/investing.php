<?php
/**
 * Read-only positions. No control on this page places an order.
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Investing';
$active = 'investing';
$settingsWide = true;
$settingsFooterNote = '';

require __DIR__ . '/_shell-top.php';
?>

            <div class="fin-page">
                <template id="fin-icon-wallet"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/wallet-cards.svg'); ?></template>

                <header class="fin-head">
                    <div>
                        <p class="fin-eyebrow">Financial intelligence</p>
                        <h1 class="fin-title">Investing</h1>
                        <p class="fin-lead">Positions come from a Robinhood activity CSV and the public Polymarket data API. This dashboard does not place trades.</p>
                    </div>
                </header>

                <div id="invest-wallet-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="invest-wallet-title">
                    <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                    <div class="app-modal-panel surface">
                        <div class="fin-modal-head">
                            <h2 id="invest-wallet-title" class="fin-modal-title">Polymarket wallet</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/x.svg'); ?></button>
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
                                <span class="fin-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/chart-column.svg'); ?></span>
                                <div>
                                    <span class="fin-eyebrow">Recent activity</span>
                                    <h2 id="invest-chart-title">Activity</h2>
                                </div>
                            </div>
                        </header>
                        <div id="invest-chart" class="fin-chart"></div>
                    </section>
                    <section class="fin-card" aria-labelledby="invest-wallet-card-title">
                        <header class="fin-card-head">
                            <div class="fin-card-title">
                                <span class="fin-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/wallet-cards.svg'); ?></span>
                                <div>
                                    <span class="fin-eyebrow">Connected</span>
                                    <h2 id="invest-wallet-card-title">Wallets</h2>
                                </div>
                            </div>
                            <button type="button" id="invest-wallet-open" class="btn">Polymarket wallet</button>
                        </header>
                        <p id="invest-wallet-status" class="fin-status" role="status"></p>
                        <div id="invest-wallets"></div>
                        <div class="fin-tools fin-tools-split">
                            <button type="button" id="invest-refresh" class="btn">Refresh public positions</button>
                        </div>
                    </section>
                </div>

                <section class="fin-card" aria-labelledby="invest-holdings-title">
                    <header class="fin-card-head">
                        <div class="fin-card-title">
                            <span class="fin-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/list-filter.svg'); ?></span>
                            <div>
                                <span class="fin-eyebrow">Scorecard</span>
                                <h2 id="invest-holdings-title">Holdings</h2>
                            </div>
                        </div>
                    </header>
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
                            <span class="fin-upload-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/upload.svg'); ?></span>
                            Import CSV
                            <input id="invest-csv" class="fin-file" type="file" accept=".csv,text/csv">
                        </label>
                    </div>
                    <p id="invest-import-status" class="fin-status" role="status"></p>
                </section>
            </div>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-investing.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
