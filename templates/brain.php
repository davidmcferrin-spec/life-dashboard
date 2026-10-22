<?php
/**
 * Knowledge vault — markdown editor, tree, backlinks, revisions.
 *
 * @var array<string, mixed> $user
 */

$themePref     = (new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance()))->getTheme((int) $user['id']);
$starfieldPref = (new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance()))->getStarfield((int) $user['id']);
$pageTitle     = 'Vault';
$extraStyles   = ['/assets/css/lifedash.css'];
$openPage      = isset($_GET['page']) ? (int) $_GET['page'] : 0;
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page brain-page min-h-screen">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <div class="flex items-center gap-3">
        <span class="brand">Vault</span>
        <a href="/" class="nav-link mono text-xs">← Dashboard</a>
    </div>
    <div class="nav-meta flex items-center gap-3 flex-wrap justify-end">
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <button type="button" id="brain-show-map" class="nav-link">Map</button>
        <a href="/brain/graph" class="nav-link">Brain</a>
        <a href="/settings" class="nav-link">Settings</a>
    </div>
</nav>

<div class="brain-shell" data-open-page="<?= (int) $openPage ?>">
    <aside class="brain-side">
        <div class="brain-side-bar">
            <input id="brain-search" type="search" class="input w-full" placeholder="Search" autocomplete="off">
            <button type="button" id="brain-folder" class="btn text-xs" title="New folder">Folder</button>
            <button type="button" id="brain-new" class="btn text-xs" title="New page">+</button>
        </div>
        <div id="brain-tree" class="brain-tree"></div>
    </aside>
    <section class="brain-stage">
        <div class="brain-tabs">
            <div>
                <button type="button" data-brain-tab="edit" class="is-active">Edit</button>
                <button type="button" data-brain-tab="preview">Preview</button>
            </div>
            <div class="flex items-center gap-2">
                <select id="brain-kind" class="input text-xs">
                    <option value="note">Note</option>
                    <option value="daily">Daily</option>
                    <option value="project">Project</option>
                    <option value="reference">Reference</option>
                    <option value="review">Review</option>
                </select>
                <button type="button" id="brain-save" class="btn-primary text-xs">Save</button>
            </div>
        </div>
        <input id="brain-title" class="brain-title" type="text" maxlength="200" placeholder="Untitled" autocomplete="off">
        <div class="brain-crumbs-row">
            <nav id="brain-crumbs" class="brain-crumbs" aria-label="Folder"></nav>
            <label class="brain-move-label">Move
                <select id="brain-move" class="input text-xs" aria-label="Move page to folder"></select>
            </label>
        </div>
        <div class="brain-split" id="brain-split">
            <textarea id="brain-body" class="brain-editor" placeholder="Markdown. Link pages with [[Title]]. Callouts: &#10;&gt; [!note]&#10;&gt; text"></textarea>
            <div id="brain-preview" class="brain-preview km" hidden></div>
        </div>
        <pre id="brain-redline-view" class="brain-redline" hidden aria-label="Red-line of this version against the current page"></pre>
        <p id="brain-status" class="ld-meta mt-2"></p>
    </section>
    <aside class="brain-side">
        <p class="brain-kicker">Links</p>
        <div id="brain-links"></div>
        <p class="brain-kicker mt-4">Backlinks</p>
        <div id="brain-backlinks"></div>
        <p class="brain-kicker mt-4">History</p>
        <label class="brain-redline-toggle" for="brain-redline">
            <input type="checkbox" id="brain-redline">
            Red-line
        </label>
        <p id="brain-redline-summary" class="ld-meta" aria-live="polite"></p>
        <div class="brain-rev-actions">
            <button type="button" id="brain-rev-current" hidden>Current</button>
            <button type="button" id="brain-rev-restore" hidden>Restore</button>
        </div>
        <ul id="brain-revs" class="brain-revs"></ul>
    </aside>
</div>

<div id="brain-map-layer" class="brain-map-layer" hidden>
    <div class="brain-graph-wrap">
        <canvas id="brain-canvas" tabindex="0" aria-label="Vault map. Drag to pan, scroll or pinch to zoom."></canvas>
        <div class="brain-zoom" role="toolbar" aria-label="Map zoom">
            <button type="button" id="brain-zoom-in" aria-label="Zoom in">+</button>
            <button type="button" id="brain-zoom-out" aria-label="Zoom out">−</button>
            <button type="button" id="brain-zoom-fit">Fit</button>
            <button type="button" id="brain-zoom-reset">Reset</button>
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
            <button type="button" id="brain-map-close">Close</button>
        </div>
        <div id="brain-ticker" class="brain-ticker"></div>
        <aside class="brain-panel">
            <p class="brain-kicker">Second brain</p>
            <h1 id="brain-panel-title" class="text-primary text-lg mt-2">Vault</h1>
            <p id="brain-panel-copy" class="text-secondary text-sm mt-2">Pages stay in clusters. Drag to pan. Scroll or pinch to zoom.</p>
            <a id="brain-panel-open" class="nav-link inline-block mt-3" href="/brain">Open vault</a>
            <p class="brain-kicker mt-4">Agents</p>
            <div id="brain-legend"></div>
            <p id="brain-live" class="brain-live">Connecting</p>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-markdown.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-camera.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-forces.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-graph.js?v=<?= e($assetVer) ?>"></script>
<script>
document.getElementById('brain-show-map').addEventListener('click', function () {
    var layer = document.getElementById('brain-map-layer');
    layer.hidden = false;
    layer.getBoundingClientRect();
    window.dispatchEvent(new Event('resize'));
});
document.getElementById('brain-map-close').addEventListener('click', function () {
    document.getElementById('brain-map-layer').hidden = true;
});
</script>
</body>
</html>
