<?php
/**
 * Live status for each homelab app.
 *
 * @var array<string, mixed> $user
 * @var array<string, array<string, mixed>> $homelabServices
 */
$pageTitle = 'Homelab';
$homelabJson = json_encode($homelabServices, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main homelab-page py-6 platform-page">
    <?php $pageEyebrow = 'LAN'; $pageHeading = 'Homelab'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Sonarr, Radarr, Prowlarr, and qBittorrent on the Unraid server. Deletes ask before they run. File deletes say so in the question.</p>
    <div data-homelab="page">
        <div class="homelab-tabs" role="tablist">
            <?php $first = true; foreach ($homelabServices as $service => $meta): ?>
            <button type="button" class="homelab-tab<?= $first ? ' is-active' : '' ?>" role="tab" data-tab="<?= e((string) $service) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>">
                <?= e((string) $meta['label']) ?>
            </button>
            <?php $first = false; endforeach; ?>
        </div>
        <?php $first = true; foreach ($homelabServices as $service => $meta): ?>
        <section class="homelab-panel<?= $first ? '' : ' is-hidden' ?>" data-panel="<?= e((string) $service) ?>" role="tabpanel"<?= $first ? '' : ' hidden' ?>>
            <p class="homelab-status mono text-xs" data-homelab-status>Loading…</p>
            <div class="homelab-split" data-homelab-body></div>
            <?php if ($service === 'sonarr' || $service === 'radarr'): ?>
            <form class="homelab-add platform-card" data-add="<?= e((string) $service) ?>">
                <h3 class="section-title">Add <?= $service === 'sonarr' ? 'a series' : 'a movie' ?></h3>
                <div class="homelab-add-grid">
                    <label class="settings-field">Look up
                        <input class="input" name="term" autocomplete="off" placeholder="<?= $service === 'sonarr' ? 'Series title' : 'Movie title' ?>">
                    </label>
                    <button type="button" class="btn" data-lookup>Look up</button>
                    <label class="settings-field">Result
                        <select class="input" name="remote_id"></select>
                    </label>
                    <label class="settings-field">Title
                        <input class="input" name="title" autocomplete="off">
                    </label>
                    <label class="settings-field">Quality profile
                        <select class="input" name="quality_profile_id"></select>
                    </label>
                    <label class="settings-field">Root folder
                        <select class="input" name="root_folder"></select>
                    </label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="search" value="1"> Search after adding</label>
                </div>
                <button type="submit" class="btn-primary">Add</button>
                <p class="homelab-form-note text-xs text-muted" data-add-note></p>
            </form>
            <?php elseif ($service === 'prowlarr'): ?>
            <form class="homelab-add platform-card" data-prowlarr-search>
                <h3 class="section-title">Search indexers</h3>
                <div class="homelab-add-grid">
                    <label class="settings-field">Query
                        <input class="input" name="query" autocomplete="off">
                    </label>
                    <button type="submit" class="btn">Search</button>
                </div>
                <div data-prowlarr-hits></div>
            </form>
            <?php else: ?>
            <form class="homelab-add platform-card" data-qbit-add>
                <h3 class="section-title">Add a torrent</h3>
                <div class="homelab-add-grid">
                    <label class="settings-field">Magnet or URL
                        <input class="input" name="urls" autocomplete="off" spellcheck="false" placeholder="magnet:?xt=urn:btih:…">
                    </label>
                    <label class="settings-field">Category
                        <input class="input" name="category" autocomplete="off" placeholder="optional">
                    </label>
                </div>
                <button type="submit" class="btn-primary">Add</button>
                <p class="homelab-form-note text-xs text-muted" data-add-note></p>
            </form>
            <?php endif; ?>
        </section>
        <?php $first = false; endforeach; ?>
    </div>
    <script type="application/json" id="homelab-services"><?= $homelabJson ?></script>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/homelab.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
