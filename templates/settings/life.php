<?php
/**
 * Today's life metrics and the Home Assistant webhook.
 *
 * @var array<string, mixed> $user
 */

$pageTitle   = 'Life';
$active      = 'life';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Life'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Sleep, steps, exercise, weight, and whether today is a travel day.
                    Health Connect and Home Assistant post the same shape to the ingest API.
                </p>
            </header>

            <section class="surface p-4 mb-4">
                <h2 class="section-title">Today</h2>
                <form id="life-form" class="mt-3 space-y-3"></form>
            </section>

            <section class="surface p-4">
                <h2 class="section-title">Home Assistant webhook</h2>
                <p class="text-muted text-sm mt-1">POST JSON to the URL. The secret is shown once. Sending it again updates the same day.</p>
                <form id="life-hook-form" class="flex gap-2 mt-3">
                    <input name="name" class="input flex-1" placeholder="Home Assistant" maxlength="64">
                    <button type="submit" class="btn-primary">Create</button>
                </form>
                <p id="life-hook-once" class="mono text-xs mt-2 hidden"></p>
                <div id="life-hooks" class="mt-3"></div>
            </section>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-life.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
