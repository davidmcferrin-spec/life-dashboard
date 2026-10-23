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

                <form id="stocks-add-form" class="flex flex-wrap gap-2 mb-4 pb-4 border-b border-chrome">
                    <input type="text" name="symbol" required placeholder="e.g. TSLA" maxlength="16"
                        pattern="[A-Za-z0-9.\-]+"
                        class="input mono flex-1 min-w-[8rem] uppercase"
                        autocapitalize="characters" spellcheck="false">
                    <button type="submit" class="btn-primary">Add symbol</button>
                </form>

                <div id="stocks-manage-list" class="space-y-2">
                    <p class="empty-state mono">Loading…</p>
                </div>
            </section>

<?php
$settingsScripts = ['/assets/js/stocks-manage.js'];
require __DIR__ . '/_shell-bottom.php';
