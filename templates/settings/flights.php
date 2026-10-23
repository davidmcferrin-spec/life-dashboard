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

            <form id="flight-add-form" class="surface p-4 space-y-3 mb-4">
                <h2 class="section-title">Add flight</h2>
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
                <button type="submit" class="btn-primary">Add watch</button>
            </form>

            <div id="flight-manage-list" class="space-y-2">
                <p class="empty-state mono">Loading…</p>
            </div>

<?php
$settingsScripts = ['/assets/js/flights-manage.js'];
require __DIR__ . '/_shell-bottom.php';
