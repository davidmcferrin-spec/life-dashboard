<?php
/**
 * Tracked subreddits: name, listing sort, and keyword filters.
 *
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $subs
 * @var string|null $flash
 */

$pageTitle   = 'Reddit';
$active      = 'reddit';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Reddit'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Subreddits saved here are fetched about every half hour and shown on the
                    <a href="/reddit" class="text-accent hover:underline">reader</a>
                    and the Reddit reader widget. Search and an assistant can read the same posts.
                    The rotating Reddit card still uses the list typed into that widget.
                </p>
            </header>

            <form id="reddit-add" method="post" action="/settings/reddit/add" class="surface p-4 space-y-3 mb-4">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <h2 class="section-title">Add a subreddit</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mono text-xs text-faint">Name</span>
                        <input type="text" name="name" required maxlength="80" placeholder="programming or r/news" class="input w-full mt-1">
                    </label>
                    <label class="block">
                        <span class="mono text-xs text-faint">Listing</span>
                        <select name="sort" class="input w-full mt-1">
                            <option value="hot">Hot</option>
                            <option value="new">New</option>
                            <option value="top">Top this week</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mono text-xs text-faint">Only posts containing</span>
                        <input type="text" name="include" maxlength="200" placeholder="postgres, php" class="input w-full mt-1">
                    </label>
                    <label class="block">
                        <span class="mono text-xs text-faint">Hide posts containing</span>
                        <input type="text" name="exclude" maxlength="200" placeholder="hiring" class="input w-full mt-1">
                    </label>
                </div>
                <button type="submit" class="btn-primary">Add</button>
            </form>

            <div class="space-y-2 mb-4">
                <?php if ($subs === []): ?>
                <?php
                $emptyTitle = 'No subreddits';
                $emptyBody = 'Add one in the form above. Posts are fetched about every half hour.';
                $emptyAction = 'Add a subreddit';
                $emptyHref = '#reddit-add';
                require __DIR__ . '/../partials/empty-panel.php';
                ?>
                <?php endif; ?>
                <?php foreach ($subs as $sub): ?>
                <form method="post" action="/settings/reddit/update" class="surface p-4 space-y-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="name" value="<?= e($sub['name']) ?>">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="section-title">r/<?= e($sub['name']) ?></h2>
                        <span class="mono text-xs text-faint"><?= (int) $sub['posts'] ?> saved</span>
                    </div>
                    <?php if (!empty($sub['last_error'])): ?>
                    <p class="text-sm" style="color: var(--danger, #e07070)"><?= e($sub['last_error']) ?></p>
                    <?php endif; ?>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="mono text-xs text-faint">Listing</span>
                            <select name="sort" class="input w-full mt-1">
                                <?php foreach (['hot' => 'Hot', 'new' => 'New', 'top' => 'Top this week'] as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $sub['sort'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mono text-xs text-faint">Only posts containing</span>
                            <input type="text" name="include" maxlength="200" value="<?= e($sub['include']) ?>" class="input w-full mt-1">
                        </label>
                        <label class="block">
                            <span class="mono text-xs text-faint">Hide posts containing</span>
                            <input type="text" name="exclude" maxlength="200" value="<?= e($sub['exclude']) ?>" class="input w-full mt-1">
                        </label>
                    </div>
                    <button type="submit" class="btn">Save</button>
                </form>
                <form method="post" action="/settings/reddit/remove" class="mb-2">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="name" value="<?= e($sub['name']) ?>">
                    <button type="submit" class="btn text-xs">Remove r/<?= e($sub['name']) ?></button>
                </form>
                <?php endforeach; ?>
            </div>

            <?php if ($subs !== []): ?>
            <form method="post" action="/settings/reddit/refresh">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <button type="submit" class="btn-primary">Fetch now</button>
                <p class="text-muted text-xs mt-2">Asks Reddit once per subreddit, about a second apart. Keys, if you need them, go on Setup.</p>
            </form>
            <?php endif; ?>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
