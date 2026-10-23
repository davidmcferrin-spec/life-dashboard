<?php
/**
 * News feed widget (legacy.news) — dashboard-pinned sources, driven by news-feed.js.
 *
 * @var bool $showNewsFeed
 */
if (empty($ldMediaWidgetsCss)) {
    $ldMediaWidgetsCss = true;
    ?>
<link rel="stylesheet" href="/assets/css/widgets-media.css?v=<?= e($assetVer ?? '') ?>">
    <?php
}
?>
<section class="ld-mk ld-mk-news" data-feed-scope="dashboard">
    <div class="ld-mk-head dashboard-widget-head">
        <h2 class="section-title ld-mk-heading">
            <span class="ld-mk-ico" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/newspaper.svg'); ?></span>
            News feed
        </h2>
        <div class="ld-mk-tools">
            <span id="feed-count" class="ld-mk-meta"></span>
            <a href="/settings/feeds" class="ld-mk-link">Manage</a>
            <a href="/news" class="ld-mk-link">View all
                <span class="ld-mk-ico" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/chevron-right.svg'); ?></span>
            </a>
        </div>
    </div>

    <?php if ($showNewsFeed): ?>
        <?php require dirname(__DIR__) . '/news-feed-filters.php'; ?>
        <div id="news-feed" class="ld-mk-news-grid">
            <div class="empty-state py-4 text-center">Loading feed...</div>
        </div>
        <div class="ld-mk-more">
            <button type="button" id="load-more-feed" class="hidden btn">Load more</button>
        </div>
    <?php else: ?>
        <?php
        $emptyTitle = 'No sources pinned';
        $emptyBody = 'Pin a feed to show articles on the dashboard.';
        $emptyAction = 'Pin feeds';
        $emptyHref = '/settings/feeds';
        require __DIR__ . '/../empty-panel.php';
        ?>
    <?php endif; ?>
</section>
