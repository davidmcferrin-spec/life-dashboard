<?php
/**
 * Top-level Feeds page. Articles come from pinned sources in feed_cache.
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Feed\FeedBoard;
use Dashboard\Settings\PreferenceService;

$db = Database::getInstance();
$userId = (int) $user['id'];
$prefService = new PreferenceService($db);
$themePref = $prefService->getTheme($userId);
$starfieldPref = $prefService->getStarfield($userId);
$pageTitle = 'Feeds';
$extraStyles = ['/assets/css/lifedash.css'];

$feedBoard = FeedBoard::fromDatabase($db)->forUser($userId);
$systemContext = $feedBoard['context'];
$feedArticles = $feedBoard['articles'];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="feeds-page">
    <header class="feeds-head">
        <div>
            <?php $pageEyebrow = 'Life Dashboard'; $pageHeading = 'Feeds'; require __DIR__ . '/partials/page-heading.php'; ?>
            <p class="feeds-lead">Articles from the sources you pinned.</p>
        </div>
        <a class="btn" href="/settings/feeds">Add a feed</a>
    </header>
    <div class="feeds-board">
        <section class="feeds-overview" aria-labelledby="feeds-overview-title">
            <header class="feeds-card-head">
                <span class="feeds-card-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../public/assets/vendor/lucide/inbox.svg'); ?></span>
                <h2 id="feeds-overview-title">Feeds overview</h2>
            </header>
            <?php if ($feedArticles === []): ?>
                <?php
                if ((int) $systemContext['connected'] === 0) {
                    $emptyTitle = 'No feeds connected';
                    $emptyBody = 'Pin a source to show articles here.';
                    $emptyAction = 'Add a feed';
                    $emptyHref = '/settings/feeds';
                } else {
                    $emptyTitle = 'No articles yet';
                    $emptyBody = 'Connected sources have not returned anything.';
                }
                require __DIR__ . '/partials/empty-panel.php';
                ?>
            <?php else: ?>
            <ul class="feeds-list">
                <?php foreach ($feedArticles as $article): ?>
                <li>
                    <?php if ($article['category'] !== ''): ?>
                    <p class="feeds-kicker"><?= e($article['category']) ?></p>
                    <?php endif; ?>
                    <a href="<?= e($article['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($article['title']) ?></a>
                    <?php
                    $meta = [];
                    if ($article['source'] !== '') {
                        $meta[] = $article['source'];
                    }
                    $when = FeedBoard::ago($article['at']);
                    if ($when !== '' && $when !== 'Not yet') {
                        $meta[] = $when;
                    }
                    if ($meta !== []) {
                        echo '<p class="feeds-meta">' . e(implode(' · ', $meta)) . '</p>';
                    }
                    ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <?php require __DIR__ . '/partials/system-context.php'; ?>
    </div>
</main>

<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
</body>
</html>
