<?php
/**
 * settings/stocks.php — stock symbol management
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Stock symbols';
$active    = 'stocks';
$flash     = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

require __DIR__ . '/_shell-top.php';
?>

            <section class="surface p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
                    <?php $pageEyebrow = 'Settings'; $pageHeading = 'Stock symbols'; require __DIR__ . '/../partials/page-heading.php'; ?>
                    <div class="flex flex-wrap gap-3 mono text-xs text-faint">
                        <span id="stocks-manage-count">…</span>
                        <span id="stocks-pin-count">…</span>
                    </div>
                </div>
                <p class="text-muted text-sm mb-4">
                    Symbols polled by cron (<code class="mono text-xs">fetch_stocks.php</code>, ~13s between quotes).
                    Up to 10 symbols in your watch list; choose up to 10 to show on the dashboard widget (compact single-line rows).
                    New accounts start with SPY, QQQ, DIA, AAPL, MSFT, GOOGL, AMZN, NVDA — all eight appear on the dashboard until you change pins.
                    Requires <code class="mono text-xs">ALPHAVANTAGE_API_KEY</code>.
                </p>

                <p class="mb-4">
                    <button type="button" id="stocks-add-open" class="btn-primary">Add symbol</button>
                </p>

                <div id="stocks-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="stocks-add-title">
                    <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                    <div class="app-modal-panel surface">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h2 id="stocks-add-title" class="section-title text-sm">Add symbol</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                        </div>
                        <form id="stocks-add-form" class="space-y-3">
                            <label class="block ld-field">
                                <span>Symbol</span>
                                <input type="text" name="symbol" required placeholder="e.g. TSLA" maxlength="16"
                                    pattern="[A-Za-z0-9.\-]+"
                                    class="input mono w-full uppercase"
                                    autocapitalize="characters" spellcheck="false">
                            </label>
                            <p id="stocks-add-error" class="ld-field-error hidden" role="alert"></p>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="btn-primary">Add symbol</button>
                                <button type="button" class="btn" data-close>Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="stocks-manage-list" class="space-y-2">
                    <p class="empty-state mono">Loading…</p>
                </div>
            </section>

<?php
$settingsScripts = ['/assets/js/stocks-manage.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
