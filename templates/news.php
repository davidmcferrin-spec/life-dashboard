<?php
/**
 * news.php — full news reader with category/source filters
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$prefService   = new PreferenceService(Database::getInstance());
$themePref     = $prefService->getTheme((int) $user['id']);
$starfieldPref = $prefService->getStarfield((int) $user['id']);
$pageTitle     = 'News Reader';
$extraStyles   = ['/assets/css/remaining-pages.css'];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page ld-shell-page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="max-w-4xl mx-auto px-4 py-8 space-y-4">
    <header>
        <?php $pageEyebrow = 'Intelligence'; $pageHeading = 'News reader'; require __DIR__ . '/partials/page-heading.php'; ?>
        <p class="ld-page-lead">
            All built-in and custom RSS sources. Filter by category or provider, or pin sources to the
            <a href="/settings/feeds" class="link">dashboard news widget</a>.
        </p>
    </header>

    <section class="surface p-4" data-feed-scope="reader">
        <div class="flex items-center justify-between mb-3">
            <h2 class="section-title">Articles</h2>
            <span id="feed-count" class="text-faint mono text-xs"></span>
        </div>

        <?php require __DIR__ . '/partials/news-feed-filters.php'; ?>

        <div id="news-feed" class="space-y-0.5">
            <?php
            $emptyTitle = 'Loading articles';
            $emptyBody = 'They show up here after the feed responds.';
            require __DIR__ . '/partials/empty-panel.php';
            ?>
        </div>

        <div class="mt-4 text-center">
            <button type="button" id="load-more-feed" class="hidden btn">
                Load more
            </button>
        </div>
    </section>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/news-feed.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
