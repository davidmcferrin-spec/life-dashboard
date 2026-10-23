<?php
/**
 * settings/stocks.php — stock symbol management
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Stocks';
$active = 'stocks';
$settingsWide = true;
$settingsFooterNote = '';

require __DIR__ . '/_shell-top.php';
?>

            <div class="fin-page">
                <header class="fin-head">
                    <div>
                        <p class="fin-eyebrow">Financial intelligence</p>
                        <h1 class="fin-title">Stocks</h1>
                        <p class="fin-lead">
                            Symbols polled by cron (<code>fetch_stocks.php</code>, about 13 seconds between quotes).
                            Up to 10 symbols in the watch list, and up to 10 pinned to the dashboard widget.
                            New accounts start with SPY, QQQ, DIA, AAPL, MSFT, GOOGL, AMZN, and NVDA, all pinned until you change them.
                            Requires <code>ALPHAVANTAGE_API_KEY</code>.
                        </p>
                    </div>
                    <button type="button" id="stocks-add-open" class="btn-primary">Add symbol</button>
                </header>

                <div id="stocks-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="stocks-add-title">
                    <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                    <div class="app-modal-panel surface">
                        <div class="fin-modal-head">
                            <h2 id="stocks-add-title" class="fin-modal-title">Add symbol</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/x.svg'); ?></button>
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

                <div class="fin-stats" aria-live="polite">
                    <article class="fin-stat">
                        <span class="fin-stat-label">Watch list</span>
                        <strong class="fin-stat-value" id="stocks-manage-count">…</strong>
                    </article>
                    <article class="fin-stat">
                        <span class="fin-stat-label">On dashboard</span>
                        <strong class="fin-stat-value" id="stocks-pin-count">…</strong>
                    </article>
                    <article class="fin-stat">
                        <span class="fin-stat-label">Up</span>
                        <strong class="fin-stat-value" id="stocks-up-count">…</strong>
                    </article>
                    <article class="fin-stat">
                        <span class="fin-stat-label">Down</span>
                        <strong class="fin-stat-value" id="stocks-down-count">…</strong>
                    </article>
                </div>

                <div class="fin-grid">
                    <section class="fin-card" aria-labelledby="stocks-chart-title">
                        <header class="fin-card-head">
                            <div class="fin-card-title">
                                <span class="fin-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/chart-column.svg'); ?></span>
                                <div>
                                    <span class="fin-eyebrow">Latest quotes</span>
                                    <h2 id="stocks-chart-title">Day move</h2>
                                </div>
                            </div>
                        </header>
                        <div id="stocks-chart" class="fin-chart"></div>
                    </section>
                    <section class="fin-card" aria-labelledby="stocks-pins-title">
                        <header class="fin-card-head">
                            <div class="fin-card-title">
                                <span class="fin-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/wallet-cards.svg'); ?></span>
                                <div>
                                    <span class="fin-eyebrow">Dashboard</span>
                                    <h2 id="stocks-pins-title">Pinned</h2>
                                </div>
                            </div>
                        </header>
                        <div id="stocks-pins"></div>
                    </section>
                </div>

                <section class="fin-card" aria-labelledby="stocks-table-title">
                    <header class="fin-card-head">
                        <div class="fin-card-title">
                            <span class="fin-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/list-filter.svg'); ?></span>
                            <div>
                                <span class="fin-eyebrow">Symbols</span>
                                <h2 id="stocks-table-title">Watch list</h2>
                            </div>
                        </div>
                    </header>
                    <div id="stocks-manage-list"></div>
                </section>
            </div>

<?php
$settingsScripts = ['/assets/js/stocks-manage.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
