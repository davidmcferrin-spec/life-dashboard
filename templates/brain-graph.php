<?php
/**
 * Vault map. Folder clusters sit in clouds on the existing canvas.
 * Free roam pans; attach orbits one cloud. Read and write pulses come from presence.
 *
 * @var array<string, mixed> $user
 */

$prefs         = new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance());
$themePref     = $prefs->getTheme((int) $user['id']);
$starfieldPref = $prefs->getStarfield((int) $user['id']);
$pageTitle     = 'Brain';
$extraStyles   = ['/assets/css/lifedash.css'];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page brain-page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Brain'; require __DIR__ . '/partials/page-heading.php'; ?>
<div class="brain-graph-wrap">
    <canvas id="brain-canvas" tabindex="0" aria-label="Vault map. Drag to pan, scroll or pinch to zoom. Arrow keys pan. Plus and minus zoom."></canvas>
    <div class="brain-graph-hud">
        <div class="brain-zoom" role="toolbar" aria-label="Map controls">
            <button type="button" id="brain-zoom-in" class="brain-graph-icon" aria-label="Zoom in" title="Zoom in"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/zoom-in.svg'); ?></button>
            <button type="button" id="brain-zoom-out" class="brain-graph-icon" aria-label="Zoom out" title="Zoom out"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/zoom-out.svg'); ?></button>
            <button type="button" id="brain-zoom-fit" class="brain-graph-icon" aria-label="Fit graph" title="Fit"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/crosshair.svg'); ?></button>
            <button type="button" id="brain-zoom-reset" class="brain-graph-icon" aria-label="Reset view" title="Reset"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/rotate-ccw.svg'); ?></button>
            <button type="button" id="brain-camera-free" class="brain-graph-free is-active" aria-pressed="true" aria-label="Free roam" title="Free roam"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/orbit.svg'); ?><span>Free</span></button>
            <label class="brain-zoom-label" for="brain-spacing">Spacing
                <input id="brain-spacing" type="range" min="0.7" max="1.8" step="0.05" value="1" aria-valuemin="0.7" aria-valuemax="1.8">
            </label>
            <label class="brain-zoom-label" for="brain-labels">Labels
                <select id="brain-labels">
                    <option value="smart">Smart</option>
                    <option value="all">All</option>
                    <option value="none">None</option>
                </select>
            </label>
        </div>
        <div class="brain-graph-camera">
            <p class="brain-graph-mode" id="brain-graph-mode">
                <strong id="brain-graph-mode-name">Free roam</strong>
                <span id="brain-graph-mode-hint">Drag to travel. Scroll to zoom.</span>
            </p>
            <div id="brain-camera-clusters" class="brain-graph-clusters" role="toolbar" aria-label="Attach to a cloud"></div>
            <p id="brain-graph-readout" class="brain-graph-readout"></p>
        </div>
    </div>
    <div id="brain-ticker" class="brain-ticker" aria-live="polite"></div>
    <aside class="brain-panel brain-graph-panel">
        <p class="brain-graph-kicker">Vault map</p>
        <p id="brain-graph-signal" class="brain-graph-signal"><i aria-hidden="true"></i><span>Live</span></p>
        <h2 id="brain-panel-title">Vault</h2>
        <p id="brain-panel-copy">Pages stay in folder clusters. A teal pulse is a read. An amber pulse is a write.</p>
        <a id="brain-panel-open" class="brain-graph-open" href="/brain">Open in Vault</a>
        <p class="brain-graph-kicker">Agents</p>
        <div id="brain-legend"></div>
        <p id="brain-live" class="brain-live">Connecting</p>
    </aside>
</div>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-camera.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-forces.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-graph.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
