<?php
/**
 * Vault mesh. One geodesic cloud per folder. Live reads and writes come from presence.
 *
 * @var array<string, mixed> $user
 */

$prefs         = new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance());
$themePref     = $prefs->getTheme((int) $user['id']);
$starfieldPref = $prefs->getStarfield((int) $user['id']);
$pageTitle     = 'Brain';
$extraStyles   = ['/assets/css/lifedash.css'];
$icon = static function (string $name): void {
    $path = __DIR__ . '/../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page brain-page brain-screen min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<div class="brain-screen-page">
    <div
        id="brain-mesh"
        class="brain-canvas mode-3d camera-free"
        tabindex="0"
        aria-label="Vault map. Drag to pan, scroll or pinch to zoom. Arrow keys pan. Plus and minus zoom."
    >
        <div class="brain-horizon"></div>
        <div class="scan-beam"></div>
        <div class="brain-readout">
            <span><i aria-hidden="true"></i> NEURAL MESH LIVE</span>
            <strong id="brain-readout-counts">0 NODES · 0 LINKS · 0 CLOUDS</strong>
        </div>
        <div class="brain-toolbar left">
            <div class="brain-search">
                <?php $icon('search'); ?>
                <input id="brain-search" type="search" placeholder="Search the brain" aria-label="Search the brain" autocomplete="off">
            </div>
            <div class="brain-filters" role="toolbar" aria-label="Filter pages">
                <button type="button" class="chip active" data-filter="all" aria-pressed="true">All types</button>
                <button type="button" class="chip" data-filter="recent" aria-pressed="false">Recent</button>
                <button type="button" class="chip" data-filter="projects" aria-pressed="false">Projects</button>
            </div>
        </div>
        <div class="brain-toolbar right" role="toolbar" aria-label="Camera">
            <button type="button" id="brain-zoom-in" class="icon-btn" aria-label="Zoom in"><?php $icon('zoom-in'); ?></button>
            <button type="button" id="brain-zoom-out" class="icon-btn" aria-label="Zoom out"><?php $icon('zoom-out'); ?></button>
            <button type="button" id="brain-zoom-reset" class="icon-btn" aria-label="Reset camera"><?php $icon('refresh-cw'); ?></button>
            <button type="button" id="brain-mode" class="chip active" aria-pressed="true">3D</button>
            <button type="button" id="brain-pause" class="icon-btn" aria-label="Pause live updates" aria-pressed="false">
                <span class="icon-pause"><?php $icon('pause'); ?></span>
                <span class="icon-play" hidden><?php $icon('play'); ?></span>
            </button>
        </div>
        <div class="camera-dock">
            <div class="camera-mode">
                <?php $icon('globe-2'); ?>
                <span>
                    <strong id="brain-mode-name">FREE ROAM</strong>
                    <small id="brain-mode-hint">Drag to travel · scroll to zoom</small>
                </span>
            </div>
            <button type="button" id="brain-free" class="active" aria-pressed="true"><?php $icon('globe-2'); ?> Free</button>
            <div id="brain-cloud-tabs" role="toolbar" aria-label="Attach the camera to a cloud"></div>
        </div>
        <div class="neural-world" id="brain-world">
            <div class="neural-world-inner" id="brain-world-inner"></div>
        </div>
        <div class="brain-legend" aria-label="Page types">
            <span><i class="type-project"></i>Project</span>
            <span><i class="type-topic"></i>Topic</span>
            <span><i class="type-note"></i>Note</span>
            <span><i class="type-person"></i>Person</span>
            <span><i class="type-bot"></i>Bot</span>
        </div>
    </div>
    <aside class="node-panel" id="brain-node-panel">
        <header>
            <span class="node-type" id="brain-node-type">—</span>
            <span class="node-signal" id="brain-node-signal"><i aria-hidden="true"></i> <span>LIVE SIGNAL</span></span>
        </header>
        <div class="node-mark" id="brain-node-mark"><?php $icon('brain'); ?><i aria-hidden="true"></i></div>
        <h2 id="brain-node-title">Select a page</h2>
        <p id="brain-node-copy">This neural object is connected across the spatial knowledge mesh and updates as its source context changes.</p>
        <div class="io-readout">
            <span><?php $icon('arrow-down-right'); ?> Reads<strong id="brain-node-reads">—</strong></span>
            <span><?php $icon('arrow-up-right'); ?> Writes<strong id="brain-node-writes">—</strong></span>
        </div>
        <div class="activity-meter">
            <span>Neural activity <strong id="brain-node-activity">—</strong></span>
            <div class="progress"><i id="brain-node-meter"></i></div>
        </div>
        <div class="node-meta">
            <span>Last updated<strong id="brain-node-updated">—</strong></span>
            <span>Connections<strong id="brain-node-links">—</strong></span>
        </div>
        <section>
            <span class="eyebrow">FIRING CONNECTIONS</span>
            <div id="brain-node-firing"></div>
        </section>
        <footer>
            <a id="brain-node-open" href="/brain"><?php $icon('book'); ?> Open in Vault</a>
            <button type="button" id="brain-node-focus"><?php $icon('activity'); ?> Focus</button>
        </footer>
    </aside>
</div>
<template id="brain-icon-brain"><?php $icon('brain'); ?></template>
<template id="brain-icon-chevron"><?php $icon('chevron-right'); ?></template>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-camera.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-sphere.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-firing.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-mesh.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
