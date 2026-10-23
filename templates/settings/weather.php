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

            <section class="surface p-5 space-y-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Weather'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm">
                    The dashboard weather widget uses your browser&apos;s geolocation to fetch current conditions
                    from OpenWeatherMap. If location access is denied or unavailable, it falls back to Huntsville, AL.
                </p>

                <div>
                    <h3 class="section-title mb-2">Server configuration</h3>
                    <p class="text-muted text-sm">
                        Weather data requires an OpenWeatherMap API key set in
                        <code class="mono text-xs">OWM_API_KEY</code> on the server.
                        Responses are cached for about 10 minutes to stay within the free tier limit.
                    </p>
                </div>

                <div>
                    <h3 class="section-title mb-2">Dashboard visibility</h3>
                    <p class="text-muted text-sm">
                        Show or hide the weather widget on the
                        <a href="/settings" class="text-accent hover:underline">Dashboard settings</a>
                        page under <strong>Dashboard widgets</strong>.
                    </p>
                </div>
            </section>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
