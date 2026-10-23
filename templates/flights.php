<?php
/**
 * Flight watches. Provider choice lives in Settings.
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Flights';
$extraStyles = ['/assets/css/lifedash.css'];
$flightsAdmin = (int) ($user['is_admin'] ?? 0) === 1;
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="settings-main">
    <header class="settings-page-head">
        <?php $pageEyebrow = 'Schedule'; $pageHeading = 'Flights'; require __DIR__ . '/partials/page-heading.php'; ?>
        <p class="text-muted text-sm mt-1">
            Watches update on a schedule.
            <?php if ($flightsAdmin): ?>
            Delay and gate data depends on the provider in
            <a href="/settings/flight-provider" class="link">Settings</a>.
            <?php else: ?>
            Delay and gate data depends on the provider an administrator chose in Settings.
            <?php endif; ?>
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
                <div class="settings-field">
                    <label class="settings-label" for="flight-ident">Flight ident</label>
                    <input id="flight-ident" type="text" name="ident" required placeholder="UA500" maxlength="16" class="input w-full" autocomplete="off">
                </div>
                <div class="settings-field">
                    <label class="settings-label" for="flight-departure">Departure</label>
                    <input id="flight-departure" type="datetime-local" name="departure_at" required class="input w-full">
                    <p class="settings-help">Local time at this dashboard.</p>
                </div>
                <div class="settings-field">
                    <label class="settings-label" for="flight-arrival">Arrival</label>
                    <input id="flight-arrival" type="datetime-local" name="arrival_at" class="input w-full">
                    <p class="settings-help">Optional.</p>
                </div>
                <div class="settings-field">
                    <label class="settings-label" for="flight-origin">Origin</label>
                    <input id="flight-origin" type="text" name="origin_icao" placeholder="KORD" maxlength="8" class="input w-full" autocomplete="off">
                    <p class="settings-help">Optional ICAO airport code.</p>
                </div>
                <p id="flight-add-error" class="ld-field-error hidden" role="alert"></p>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary">Add watch</button>
                    <button type="button" class="btn" data-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="flight-manage-list" class="watch-list">
        <p class="empty-state mono">Loading…</p>
    </div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/flights-manage.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
