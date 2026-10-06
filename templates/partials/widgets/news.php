<?php
/**
 * News feed widget (legacy.news) — dashboard-pinned sources, driven by news-feed.js.
 *
 * @var bool $showNewsFeed
 */
?>
<section data-feed-scope="dashboard">
    <div class="flex items-center justify-between mb-2 dashboard-widget-head">
        <h2 class="section-title">News Feed</h2>
        <div class="flex items-center gap-3">
            <span id="feed-count" class="text-faint mono text-xs"></span>
            <a href="/settings/feeds" class="nav-link mono text-xs">Manage</a>
            <a href="/news" class="nav-link mono text-xs">View all →</a>
        </div>
    </div>

    <?php if ($showNewsFeed): ?>
        <?php require dirname(__DIR__) . '/news-feed-filters.php'; ?>
        <div id="news-feed" class="space-y-0.5">
            <div class="empty-state mono py-4 text-center">Loading feed...</div>
        </div>
        <div class="mt-4 text-center">
            <button type="button" id="load-more-feed" class="hidden btn">Load more</button>
        </div>
    <?php else: ?>
        <p class="empty-state mono text-xs py-3 text-center">
            No sources pinned. <a href="/settings/feeds" class="link">Pin feeds →</a>
        </p>
    <?php endif; ?>
</section>
