<?php
/**
 * Knowledge vault — tree, note, and context.
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

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<div class="brain-shell" data-open-page="<?= (int) $openPage ?>">
    <button type="button" id="brain-tree-scrim" class="brain-tree-scrim" hidden aria-label="Close notes"></button>
    <aside id="brain-tree-pane" class="brain-side brain-tree-pane">
        <header class="brain-tree-head">
            <div>
                <p class="brain-kicker">Knowledge</p>
                <h2 class="brain-tree-title">Vault</h2>
            </div>
            <div class="brain-tree-actions">
                <button type="button" id="brain-folder" class="btn" title="New folder">Folder</button>
                <button type="button" id="brain-new" class="btn" title="New note">New</button>
            </div>
        </header>
        <div class="brain-search-field">
            <span class="brain-search-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/search.svg'); ?></span>
            <input id="brain-search" type="search" class="input" placeholder="Search notes" autocomplete="off" aria-label="Search notes">
        </div>
        <div id="brain-tree" class="brain-tree"></div>
        <?php // Inline the vendored files so their stroke stays currentColor. ?>
        <template id="brain-icon-chevron"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/chevron-right.svg'); ?></template>
        <template id="brain-icon-folder"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/folder.svg'); ?></template>
        <template id="brain-icon-file"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/file-text.svg'); ?></template>
        <template id="brain-icon-link"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/link.svg'); ?></template>
        <template id="brain-icon-tag"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/tag.svg'); ?></template>
        <template id="brain-icon-list-tree"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/list-tree.svg'); ?></template>
        <template id="brain-icon-x"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/x.svg'); ?></template>
    </aside>
    <section class="brain-stage is-preview">
        <header class="brain-note-head">
            <div class="brain-note-title">
                <button type="button" id="brain-tree-toggle" class="brain-tree-toggle" aria-expanded="false" aria-controls="brain-tree-pane">
                    <span class="brain-tree-toggle-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/panel-left.svg'); ?></span>
                    <span>Notes</span>
                </button>
                <nav id="brain-crumbs" class="brain-crumbs" aria-label="Folder"></nav>
                <input id="brain-title" class="brain-title" type="text" maxlength="200" placeholder="Untitled" autocomplete="off">
                <p id="brain-status" class="brain-note-meta"></p>
            </div>
            <div class="brain-note-actions">
                <div class="brain-tabs" role="group" aria-label="Note view">
                    <button type="button" data-brain-tab="preview" class="is-active" aria-pressed="true">Preview</button>
                    <button type="button" data-brain-tab="edit" aria-pressed="false">Edit</button>
                </div>
                <select id="brain-kind" class="input" aria-label="Page kind">
                    <option value="note">Note</option>
                    <option value="daily">Daily</option>
                    <option value="project">Project</option>
                    <option value="reference">Reference</option>
                    <option value="review">Review</option>
                </select>
                <label class="brain-move-label">Move
                    <select id="brain-move" class="input" aria-label="Move page to folder"></select>
                </label>
                <button type="button" id="brain-save" class="btn-primary">Save</button>
                <button type="button" id="brain-archive" class="btn">Archive</button>
            </div>
        </header>
        <div class="brain-split" id="brain-split" style="grid-template-columns: 1fr">
            <textarea id="brain-body" class="brain-editor" hidden placeholder="Markdown. Link pages with [[Title]]. Callouts: &#10;&gt; [!note]&#10;&gt; text"></textarea>
            <div id="brain-preview" class="brain-preview km"></div>
        </div>
        <pre id="brain-redline-view" class="brain-redline" hidden aria-label="Red-line of this version against the current page"></pre>
    </section>
    <aside class="brain-side brain-context" aria-label="Note context">
        <section>
            <p class="brain-kicker"><span class="brain-ctx-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/link.svg'); ?></span> Backlinks <span id="brain-back-count"></span></p>
            <div id="brain-backlinks"></div>
        </section>
        <section>
            <p class="brain-kicker"><span class="brain-ctx-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/tag.svg'); ?></span> Tags</p>
            <div id="brain-tags" class="brain-chips"></div>
        </section>
        <section>
            <p class="brain-kicker"><span class="brain-ctx-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/list-tree.svg'); ?></span> Outline</p>
            <div id="brain-outline"></div>
        </section>
        <section>
            <p class="brain-kicker">Links</p>
            <div id="brain-links"></div>
        </section>
        <section>
            <p class="brain-kicker">History</p>
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
        </section>
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
            <h2 id="brain-panel-title" class="text-primary text-lg mt-2">Vault</h2>
            <p id="brain-panel-copy" class="text-secondary text-sm mt-2">Pages stay in clusters. Drag to pan. Scroll or pinch to zoom.</p>
            <a id="brain-panel-open" class="nav-link inline-block mt-3" href="/brain">Open vault</a>
            <p class="brain-kicker mt-4">Agents</p>
            <div id="brain-legend"></div>
            <p id="brain-live" class="brain-live">Connecting</p>
        </aside>
    </div>
</div>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

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
