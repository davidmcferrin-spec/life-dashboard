<?php
/**
 * settings.php — Dashboard / general preferences
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$db               = Database::getInstance();
$userId           = (int) $user['id'];
$prefs            = new PreferenceService($db);
$searchEngine     = $prefs->getSearchEngine($userId);
$themePref        = $prefs->getTheme($userId);
$clockFormat      = $prefs->getClockFormat($userId);
$showBroadcastWeek = $prefs->getShowBroadcastWeek($userId);
$showBookmarkIcons = $prefs->getShowBookmarkIcons($userId);
$pageTitle        = 'Settings';
$flash            = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$active           = 'general';

require __DIR__ . '/settings/_shell-top.php';
?>

            <section class="surface p-5">
                <h1 class="text-primary text-xl font-medium mb-1">Dashboard</h1>
                <p class="text-muted text-sm mb-4">Appearance, search engine, and which dashboard sections to show.</p>

                <p class="text-faint mono text-xs mb-4">
                    Account <span class="text-muted"><?= e((string) $user['username']) ?></span>
                    <?php if (!empty($user['last_login_at'])): ?>
                    · Last login <?= e((string) $user['last_login_at']) ?>
                    <?php endif; ?>
                    <?php if (!empty($user['last_active_at'])): ?>
                    · Last active <?= e((string) $user['last_active_at']) ?>
                    <?php endif; ?>
                </p>

                <form method="post" action="/settings/preferences" class="space-y-6">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">

                    <div>
                        <h3 class="section-title mb-3">Appearance</h3>
                        <div class="flex flex-wrap gap-4 mb-1">
                            <?php foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $key => $label): ?>
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                                <input type="radio" name="theme" value="<?= e($key) ?>"
                                    <?= $themePref === $key ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-faint text-xs mono">System follows your device. Use the nav switcher for instant preview; save here to sync across devices. Starfield is toggled on the dashboard nav.</p>
                    </div>

                    <div>
                        <h3 class="section-title mb-3">Timezone bar</h3>
                        <div class="flex flex-wrap gap-4 mb-1">
                            <?php foreach (['12h' => '12-hour (AM/PM)', '24h' => '24-hour'] as $key => $label): ?>
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                                <input type="radio" name="clock_format" value="<?= e($key) ?>"
                                    <?= $clockFormat === $key ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-faint text-xs mono">Applies to the timezone bar on the dashboard. Hover a clock to see sync offset. Press <kbd class="mono">Alt+J</kbd> on the dashboard to jam time from the server.</p>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted mt-3">
                            <input type="checkbox" name="show_broadcast_week" value="1"
                                <?= $showBroadcastWeek ? 'checked' : '' ?>>
                            Show broadcast week (EPOCH) under clocks
                        </label>
                        <p class="text-faint text-xs mono mt-1">
                            US broadcast calendar: Monday–Sunday weeks; week 1 contains January 1. Uses Eastern Time for “today.”
                            Large focus clock and alarm:
                            <a href="/settings/clock" class="text-accent hover:underline">Focus Clock settings</a>.
                        </p>
                    </div>

                    <div>
                        <h3 class="section-title mb-3">Search engine</h3>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach (PreferenceService::searchEnginesForUi() as $key => $engine): ?>
                            <label class="flex items-center gap-2 cursor-pointer mono text-sm text-muted">
                                <input type="radio" name="search_engine" value="<?= e($key) ?>"
                                    <?= $searchEngine === $key ? 'checked' : '' ?>>
                                <?= e($engine['name']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-faint text-xs mono mt-1">Grok and Claude open a new chat/search with your query.</p>
                    </div>

                    <div>
                        <h3 class="section-title mb-3">Dashboard widgets</h3>
                        <p class="text-muted text-sm">
                            Widgets are arranged on the dashboard itself: choose <span class="text-secondary">Edit layout</span>
                            to drag, resize, add, configure or remove them. Habits and other trackers are managed under
                            <a href="/settings/trackers" class="link">Habits &amp; trackers</a>.
                        </p>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted mt-3">
                            <input type="checkbox" name="show_bookmark_icons" value="1"
                                <?= $showBookmarkIcons ? 'checked' : '' ?>>
                            Show bookmark icons on dashboard
                        </label>
                    </div>

                    <button type="submit" class="btn-primary">
                        Save general settings
                    </button>
                </form>

            </section>

<?php require __DIR__ . '/settings/_shell-bottom.php'; ?>
