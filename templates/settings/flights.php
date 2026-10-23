<?php
/**
 * settings/flights.php — flight watch manager
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Flight watches';
$active    = 'flights';

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Flight watches'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    ADS-B via OpenSky. Polling starts 24 hours before departure (cron every 30 min).
                    Airline delay/gate data requires FlightAware (<code class="mono text-xs">FLIGHT_PROVIDER=flightaware</code>).
                </p>
            </header>

            <p class="mb-4">
                <button type="button" id="flight-add-open" class="btn-primary">Add flight</button>
            </p>

            <div id="flight-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="flight-add-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface ld-modal-wide">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="flight-add-title" class="section-title text-sm">Add flight</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="flight-add-form" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mono text-xs text-faint">Flight ident</span>
                                <input type="text" name="ident" required placeholder="UA500" maxlength="16" class="input mono w-full mt-1">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">Departure (local)</span>
                                <input type="datetime-local" name="departure_at" required class="input mono w-full mt-1">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">Arrival (optional)</span>
                                <input type="datetime-local" name="arrival_at" class="input mono w-full mt-1">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">Origin ICAO (optional)</span>
                                <input type="text" name="origin_icao" placeholder="KORD" maxlength="8" class="input mono w-full mt-1">
                            </label>
                        </div>
                        <p id="flight-add-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary">Add watch</button>
                            <button type="button" class="btn" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="flight-manage-list" class="space-y-2">
                <p class="empty-state mono">Loading…</p>
            </div>

<?php
$settingsScripts = ['/assets/js/flights-manage.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
