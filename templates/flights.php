<?php
/**
 * Flight watches. Provider choice lives in Settings.
 *
 * @var array<string, mixed> $user
 * @var string $assetVer
 */

$pageTitle = 'Flights';
$extraStyles = ['/assets/css/lifedash.css'];
$flightsAdmin = (int) ($user['is_admin'] ?? 0) === 1;

$flightIcon = static function (string $name): void {
    $path = __DIR__ . '/../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="flight-page">
    <header class="flight-head">
        <div class="flight-head-copy">
            <p class="flight-eyebrow">Travel command</p>
            <h1 class="flight-title">Flights</h1>
            <p class="flight-lede">
                Upcoming journeys, status updates and travel details.
                Watches update on a schedule.
                <?php if ($flightsAdmin): ?>
                Delay and gate data depends on the provider in
                <a class="flight-link" href="/settings/flight-provider">Settings</a>.
                <?php else: ?>
                Delay and gate data depends on the provider an administrator chose in Settings.
                <?php endif; ?>
            </p>
        </div>
        <button type="button" id="flight-add-open" class="btn-primary flight-add">Add flight</button>
    </header>

    <div id="flight-manage-list" class="flight-board">
        <p class="flight-loading">Loading…</p>
    </div>

    <div id="flight-add-modal" class="app-modal flight-modal hidden" role="dialog" aria-modal="true" aria-labelledby="flight-add-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel flight-modal-panel">
            <header class="flight-modal-head">
                <div class="flight-modal-heading">
                    <h2 id="flight-add-title" class="flight-modal-title">Add flight</h2>
                    <p class="flight-modal-desc">Ident and departure are required. Arrival and origin are optional.</p>
                </div>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $flightIcon('x'); ?></button>
            </header>
            <form id="flight-add-form" class="flight-form">
                <div class="flight-field">
                    <label class="flight-label" for="flight-ident">Flight ident</label>
                    <input id="flight-ident" type="text" name="ident" required placeholder="UA500" maxlength="16" class="input" autocomplete="off">
                </div>
                <div class="flight-field">
                    <label class="flight-label" for="flight-departure">Departure</label>
                    <input id="flight-departure" type="datetime-local" name="departure_at" required class="input">
                    <p class="flight-help">Local time at this dashboard.</p>
                </div>
                <div class="flight-field">
                    <label class="flight-label" for="flight-arrival">Arrival</label>
                    <input id="flight-arrival" type="datetime-local" name="arrival_at" class="input">
                    <p class="flight-help">Optional.</p>
                </div>
                <div class="flight-field">
                    <label class="flight-label" for="flight-origin">Origin</label>
                    <input id="flight-origin" type="text" name="origin_icao" placeholder="KORD" maxlength="8" class="input" autocomplete="off">
                    <p class="flight-help">Optional ICAO airport code.</p>
                </div>
                <p id="flight-add-error" class="flight-error hidden" role="alert"></p>
                <div class="flight-form-actions">
                    <button type="submit" class="btn-primary">Add watch</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="flight-detail-modal" class="app-modal flight-modal hidden" role="dialog" aria-modal="true" aria-labelledby="flight-detail-title">
        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
        <div class="app-modal-panel flight-modal-panel">
            <header class="flight-modal-head">
                <div class="flight-modal-heading">
                    <h2 id="flight-detail-title" class="flight-modal-title" tabindex="-1"></h2>
                    <p id="flight-detail-desc" class="flight-modal-desc"></p>
                </div>
                <button type="button" class="app-modal-close" data-close aria-label="Close"><?php $flightIcon('x'); ?></button>
            </header>
            <div id="flight-detail-body" class="flight-detail-body"></div>
            <footer class="flight-modal-foot">
                <a id="flight-detail-link" class="btn" target="_blank" rel="noopener noreferrer" hidden>View on FlightAware</a>
                <button type="button" id="flight-detail-delete" class="btn-danger">Delete</button>
            </footer>
        </div>
    </div>

    <template id="flight-icon-plane"><?php $flightIcon('plane'); ?></template>
    <template id="flight-icon-takeoff"><?php $flightIcon('plane-takeoff'); ?></template>
    <template id="flight-icon-landing"><?php $flightIcon('plane-landing'); ?></template>
    <template id="flight-icon-pin"><?php $flightIcon('map-pin'); ?></template>
    <template id="flight-icon-clock"><?php $flightIcon('clock-3'); ?></template>
    <template id="flight-icon-chevron"><?php $flightIcon('chevron-right'); ?></template>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/flights-manage.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
