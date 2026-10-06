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
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <a href="/" class="brand hover:text-accent">← Dashboard</a>
    <div class="nav-meta flex items-center gap-3">
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <a href="/settings/feeds" class="nav-link mono text-xs">Sources</a>
        <a href="/logout" class="nav-link nav-link-danger">Sign Out</a>
    </div>
</nav>

<main class="max-w-4xl mx-auto px-4 py-8 space-y-4">
    <header>
        <h1 class="text-primary text-xl font-medium">News reader</h1>
        <p class="text-muted text-sm mt-1">
            All built-in and custom RSS sources. Filter by category or provider, or pin sources to the
            <a href="/settings/feeds" class="text-accent hover:underline">dashboard news widget</a>.
        </p>
    </header>

    <section class="surface p-4" data-feed-scope="reader">
        <div class="flex items-center justify-between mb-3">
            <h2 class="section-title">Articles</h2>
            <span id="feed-count" class="text-faint mono text-xs"></span>
        </div>

        <?php require __DIR__ . '/partials/news-feed-filters.php'; ?>

        <div id="news-feed" class="space-y-0.5">
            <div class="empty-state mono py-4 text-center">Loading feed...</div>
        </div>

        <div class="mt-4 text-center">
            <button type="button" id="load-more-feed" class="hidden btn">
                Load more
            </button>
        </div>
    </section>
</main>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/news-feed.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
