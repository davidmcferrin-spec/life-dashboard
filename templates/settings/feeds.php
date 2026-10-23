<?php
/**
 * settings/feeds.php — RSS news source preferences
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\FeedPreferenceService;

$userId   = (int) $user['id'];
$feeds    = FeedPreferenceService::fromDatabase(Database::getInstance());
$feedSources = $feeds->listSystemWithState($userId);
$feedCategoryLabels = [
    'tech'       => 'Tech',
    'developer'  => 'Developer',
    'mobile'     => 'Mobile',
    'hardware'   => 'PC Hardware',
    'enterprise' => 'Enterprise IT',
    'microsoft'  => 'Microsoft',
    'broadcast'  => 'Broadcast',
    'news'       => 'News',
    'ev'         => 'Tesla/EVs',
    'ai'         => 'AI/Development',
];
$feedCategoryOrder = ['tech', 'developer', 'mobile', 'hardware', 'enterprise', 'microsoft', 'broadcast', 'news', 'ev', 'ai'];
$feedsByCategory   = [];
foreach ($feedSources as $source) {
    $feedsByCategory[$source['category']][] = $source;
}
$customFeedSources = $feeds->feedSourceService()->listCustomForUser($userId);
$maxCustomFeeds    = $feeds->feedSourceService()->maxCustomFeeds();

$pageTitle = 'News feeds';
$active    = 'feeds';
$flash     = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

require __DIR__ . '/_shell-top.php';
?>

            <section class="surface p-5">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'News sources'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mb-4">
                    Add custom RSS feeds, then choose which built-in and personal sources appear on the
                    <strong>dashboard news widget</strong>. All sources are always available on the
                    <a href="/news" class="text-accent hover:underline">news reader</a>.
                    If nothing is pinned, the dashboard widget is hidden.
                </p>

                <form method="post" action="/settings/feeds/add" class="mb-6 pb-6 border-b border-chrome">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                    <h3 class="section-title mb-3">Add your feed</h3>
                    <div class="grid sm:grid-cols-2 gap-3 max-w-2xl mb-3">
                        <div>
                            <label class="block text-muted text-xs mono mb-1" for="feed-name">Name</label>
                            <input type="text" id="feed-name" name="name" required maxlength="128"
                                placeholder="My podcast" class="input mono w-full">
                        </div>
                        <div>
                            <label class="block text-muted text-xs mono mb-1" for="feed-url">RSS URL</label>
                            <input type="url" id="feed-url" name="url" required
                                placeholder="https://example.com/feed.xml" class="input mono w-full">
                        </div>
                    </div>
                    <p class="text-faint text-xs mono mb-3">
                        Up to <?= (int) $maxCustomFeeds ?> custom feeds per account
                        (<?= count($customFeedSources) ?>/<?= (int) $maxCustomFeeds ?> used).
                        New feeds are fetched immediately; cron refreshes all sources periodically.
                    </p>
                    <button type="submit" class="btn-primary" <?= count($customFeedSources) >= $maxCustomFeeds ? 'disabled' : '' ?>>
                        Add feed
                    </button>
                </form>

                <form method="post" action="/settings/feeds" id="feed-prefs-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">

                    <h3 class="section-title mb-3">Built-in sources — show on dashboard</h3>
                    <?php foreach ($feedCategoryOrder as $catKey): ?>
                        <?php if (empty($feedsByCategory[$catKey])) {
                            continue;
                        } ?>
                    <h4 class="text-muted text-xs mono uppercase tracking-wide mb-2 mt-4 first:mt-0">
                        <?= e($feedCategoryLabels[$catKey] ?? $catKey) ?>
                    </h4>
                    <div class="grid sm:grid-cols-2 gap-2 mb-2">
                        <?php foreach ($feedsByCategory[$catKey] as $source): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                            <input type="checkbox" name="feed[<?= (int) $source['id'] ?>]" value="1"
                                <?= $source['enabled'] ? 'checked' : '' ?>
                                >
                            <span><?= e($source['name']) ?></span>
                            <span class="text-faint text-xs mono"><?= e($feedCategoryLabels[$source['category']] ?? $source['category']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="mb-6"></div>

                    <h3 class="section-title mb-3">Your feeds — show on dashboard</h3>
                    <?php if ($customFeedSources === []): ?>
                    <p class="text-muted text-sm mb-4">No custom feeds yet. Add an RSS URL above.</p>
                    <?php else: ?>
                    <div class="space-y-3 mb-4">
                        <?php foreach ($customFeedSources as $source): ?>
                        <div class="flex flex-wrap items-center gap-3 border border-chrome rounded-lg p-3">
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-muted flex-1 min-w-[12rem]">
                                <input type="checkbox" name="feed[<?= (int) $source['id'] ?>]" value="1"
                                    <?= $source['enabled'] ? 'checked' : '' ?>
                                    >
                                <span class="truncate"><?= e($source['name']) ?></span>
                            </label>
                            <span class="text-faint mono text-xs truncate max-w-full sm:max-w-md" title="<?= e($source['url']) ?>">
                                <?= e($source['url']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-primary">
                        Save dashboard pins
                    </button>
                </form>

                <?php if ($customFeedSources !== []): ?>
                <div class="mt-4 pt-4 border-t border-chrome space-y-2">
                    <?php foreach ($customFeedSources as $source): ?>
                    <form method="post" action="/settings/feeds/<?= (int) $source['id'] ?>/delete"
                        class="flex flex-wrap items-center justify-between gap-2"
                        data-confirm-title="Delete feed"
                        data-confirm-message="<?= e('Delete ' . $source['name'] . ' and its cached articles?') ?>"
                        data-confirm-label="Delete"
                        data-confirm-danger="1">
                        <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                        <span class="text-muted text-sm truncate"><?= e($source['name']) ?></span>
                        <button type="submit" class="text-danger text-xs mono hover:underline">Delete feed</button>
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

<?php
$settingsScripts = ['/assets/js/settings-confirms.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';

