<div class="flex items-center justify-between mb-2 dashboard-widget-head">
    <h2 class="section-title ld-legacy-title">
        <span class="ld-legacy-ico" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/bookmark.svg'); ?></span>
        Bookmarks
    </h2>
    <a href="/settings/bookmarks" class="nav-link mono text-xs">Manage</a>
</div>

<?php
$showIcons = $showBookmarkIcons ?? true;
$bmCard = static function (array $bm) use ($showIcons): void {
    $bmIcon = null;
    if ($showIcons) {
        if (!empty($bm['favicon_ext'])) {
            $bmIcon = '/api/bookmarks/' . (int) $bm['id'] . '/favicon';
        } elseif (!empty($bm['favicon_url'])) {
            $bmIcon = (string) $bm['favicon_url'];
        }
    }
    $host = parse_url((string) ($bm['url'] ?? ''), PHP_URL_HOST);
    ?>
    <a
        href="<?= e($bm['url']) ?>"
        target="_blank"
        rel="noopener noreferrer"
        class="ld-bookmark-card"
        title="<?= e($bm['title']) ?>"
    >
        <?php if ($bmIcon !== null): ?>
            <img src="<?= e($bmIcon) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="ld-legacy-ico" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/bookmark.svg'); ?></span>
        <?php endif; ?>
        <span>
            <strong><?= e($bm['title']) ?></strong>
            <?php if (is_string($host) && $host !== ''): ?><small><?= e($host) ?></small><?php endif; ?>
        </span>
    </a>
    <?php
};
?>

<?php if (empty($folders) && empty($unfiledBookmarks)): ?>
    <?php
    $emptyTitle = 'No bookmarks';
    $emptyBody = 'Add a folder or a link to see them here.';
    $emptyAction = 'Add bookmarks';
    $emptyHref = '/settings/bookmarks';
    require __DIR__ . '/../empty-panel.php';
    ?>
<?php else: ?>
<div class="ld-bookmark-board ld-card-wipe">
    <?php foreach ($folders as $folder): ?>
    <div>
        <button
            class="folder-toggle"
            type="button"
            data-folder="folder-<?= (int) $folder['id'] ?>"
        >
            <span class="folder-arrow" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/folder-open.svg'); ?></span>
            <span class="truncate"><?= e($folder['name']) ?></span>
            <span class="ld-bookmark-count"><?= count($folder['bookmarks']) ?></span>
        </button>
        <div id="folder-<?= (int) $folder['id'] ?>" class="folder-contents ld-bookmark-grid hidden">
            <?php foreach ($folder['bookmarks'] as $bm): ?>
                <?php $bmCard($bm); ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($unfiledBookmarks)): ?>
    <div class="ld-bookmark-grid">
        <?php foreach ($unfiledBookmarks as $bm): ?>
            <?php $bmCard($bm); ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
