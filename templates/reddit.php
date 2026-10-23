<?php
/**
 * Reader for posts saved from tracked subreddits.
 *
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $subs
 * @var list<array<string, mixed>> $posts
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$prefService   = new PreferenceService(Database::getInstance());
$themePref     = $prefService->getTheme((int) $user['id']);
$starfieldPref = $prefService->getStarfield((int) $user['id']);
$pageTitle     = 'Reddit';

$grouped = [];
foreach ($posts as $post) {
    $grouped[$post['subreddit']][] = $post;
}
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <a href="/" class="brand hover:text-accent">← Dashboard</a>
    <div class="nav-meta flex items-center gap-3 flex-wrap justify-end min-w-0">
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <a href="/settings/reddit" class="nav-link mono text-xs">Subreddits</a>
        <a href="/logout" class="nav-link nav-link-danger">Sign Out</a>
    </div>
</nav>

<main class="max-w-3xl mx-auto px-4 py-8 space-y-4">
    <header>
        <?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Reddit'; require __DIR__ . '/partials/page-heading.php'; ?>
        <p class="text-muted text-sm mt-1">
            Posts saved from the subreddits you track.
            <a href="/settings/reddit" class="text-accent hover:underline">Add or remove one</a>.
        </p>
    </header>

    <?php if ($subs === []): ?>
    <section class="surface p-4">
        <p class="empty-state mono text-sm">No subreddits yet.</p>
    </section>
    <?php endif; ?>

    <?php foreach ($subs as $sub): ?>
    <section class="surface p-4">
        <div class="flex items-baseline justify-between gap-3 mb-2">
            <h2 class="section-title">r/<?= e($sub['name']) ?></h2>
            <span class="mono text-xs text-faint"><?= e($sub['sort']) ?><?= $sub['include'] !== '' ? ' · ' . e($sub['include']) : '' ?></span>
        </div>
        <?php if (!empty($sub['last_error'])): ?>
        <p class="text-sm mb-2" style="color: var(--danger, #e07070)"><?= e($sub['last_error']) ?></p>
        <?php endif; ?>
        <?php $rows = $grouped[$sub['name']] ?? []; ?>
        <?php if ($rows === []): ?>
        <p class="empty-state mono text-xs">Nothing saved for this subreddit yet.</p>
        <?php endif; ?>
        <div class="space-y-3">
            <?php foreach ($rows as $post): ?>
            <article>
                <a class="text-primary hover:text-accent" href="<?= e($post['permalink']) ?>" target="_blank" rel="noopener noreferrer"><?= e($post['title']) ?></a>
                <p class="mono text-xs text-faint mt-1">
                    <?= e($post['author'] !== '' ? 'u/' . $post['author'] : '') ?>
                    · <?= (int) $post['score'] ?> points
                    · <?= (int) $post['num_comments'] ?> comments
                    <?php if (!empty($post['flair'])): ?> · <?= e($post['flair']) ?><?php endif; ?>
                    · <?= e(substr((string) $post['created_utc'], 0, 16)) ?>
                </p>
                <?php if ($post['selftext'] !== ''): ?>
                <p class="text-secondary text-sm mt-1"><?= e($post['selftext']) ?></p>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</main>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
</body>
</html>
