<?php
/**
 * settings/weather.php — weather widget information
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Weather';
$active    = 'weather';

require __DIR__ . '/_shell-top.php';
?>

                <?php $pageEyebrow = ''; $pageHeading = 'Weather'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm">
                    The dashboard weather widget uses this browser&apos;s location for current conditions.
                    If location access is denied, it falls back to Huntsville, AL.
                </p>

                <h2 class="settings-section">Key</h2>
                <p class="settings-help settings-help-block">
                    The OpenWeatherMap key is saved in
                    <a href="/settings/setup" class="link">Setup</a>.
                    Responses are cached for about 10 minutes.
                </p>

                <h2 class="settings-section">Dashboard</h2>
                <p class="settings-help settings-help-block">
                    Show or hide the weather widget from
                    <a href="/settings" class="link">Dashboard</a>.
                </p>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
