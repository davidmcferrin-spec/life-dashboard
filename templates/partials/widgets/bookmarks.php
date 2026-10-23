<div class="flex items-center justify-between mb-2 dashboard-widget-head">
    <h2 class="section-title">Bookmarks</h2>
    <a href="/settings/bookmarks" class="nav-link mono text-xs">Manage</a>
</div>

<?php if (empty($folders) && empty($unfiledBookmarks)): ?>
    <?php
    $emptyTitle = 'No bookmarks';
    $emptyBody = 'Add a folder or a link to see them here.';
    $emptyAction = 'Add bookmarks';
    $emptyHref = '/settings/bookmarks';
    require __DIR__ . '/../empty-panel.php';
    ?>
<?php else: ?>

    <?php foreach ($folders as $folder): ?>
    <div class="mb-2">
        <button
            class="folder-toggle flex items-center gap-1.5 text-secondary hover:text-primary text-sm font-medium transition-colors w-full text-left"
            data-folder="folder-<?= (int) $folder['id'] ?>"
        >
            <span class="folder-arrow text-slate-600 mono text-xs transition-transform">▶</span>
            <span class="truncate"><?= e($folder['name']) ?></span>
            <span class="text-faint text-xs ml-auto flex-shrink-0"><?= count($folder['bookmarks']) ?></span>
        </button>
        <div id="folder-<?= (int) $folder['id'] ?>" class="folder-contents mt-1.5 ml-4 space-y-1 hidden">
            <?php foreach ($folder['bookmarks'] as $bm): ?>
            <?php
                $bmIcon = null;
                if ($showBookmarkIcons ?? true) {
                    if (!empty($bm['favicon_ext'])) {
                        $bmIcon = '/api/bookmarks/' . (int) $bm['id'] . '/favicon';
                    } elseif (!empty($bm['favicon_url'])) {
                        $bmIcon = (string) $bm['favicon_url'];
                    }
                }
            ?>
            <a
                href="<?= e($bm['url']) ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center gap-2 text-sm text-muted link truncate"
                title="<?= e($bm['title']) ?>"
            >
                <?php if ($bmIcon !== null): ?>
                    <img src="<?= e($bmIcon) ?>" alt="" class="w-3.5 h-3.5 flex-shrink-0" loading="lazy">
                <?php else: ?>
                    <span class="text-faint flex-shrink-0">›</span>
                <?php endif; ?>
                <span class="truncate"><?= e($bm['title']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($unfiledBookmarks)): ?>
    <div class="mt-2 space-y-1">
        <?php foreach ($unfiledBookmarks as $bm): ?>
        <?php
            $bmIcon = null;
            if ($showBookmarkIcons ?? true) {
                if (!empty($bm['favicon_ext'])) {
                    $bmIcon = '/api/bookmarks/' . (int) $bm['id'] . '/favicon';
                } elseif (!empty($bm['favicon_url'])) {
                    $bmIcon = (string) $bm['favicon_url'];
                }
            }
        ?>
        <a
            href="<?= e($bm['url']) ?>"
            target="_blank"
            rel="noopener noreferrer"
            class="flex items-center gap-2 text-sm text-muted link truncate"
        >
            <?php if ($bmIcon !== null): ?>
                <img src="<?= e($bmIcon) ?>" alt="" class="w-3.5 h-3.5 flex-shrink-0" loading="lazy">
            <?php else: ?>
                <span class="text-faint flex-shrink-0">›</span>
            <?php endif; ?>
            <span class="truncate"><?= e($bm['title']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>
