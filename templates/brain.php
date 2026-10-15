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
        <p id="brain-status" class="ld-meta mt-2"></p>
    </section>
    <aside class="brain-side">
        <p class="brain-kicker">Links</p>
        <div id="brain-links"></div>
        <p class="brain-kicker mt-4">Backlinks</p>
        <div id="brain-backlinks"></div>
        <p class="brain-kicker mt-4">History</p>
        <ul id="brain-revs" class="brain-revs"></ul>
    </aside>
</div>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-markdown.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
