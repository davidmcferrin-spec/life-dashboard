<?php
/**
 * settings.php — Dashboard / general preferences
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\JarvisAccent;

$db               = Database::getInstance();
$userId           = (int) $user['id'];
$prefs            = new PreferenceService($db);
$searchEngine     = $prefs->getSearchEngine($userId);
$themePref        = $prefs->getTheme($userId);
$jarvisAccent     = $prefs->getJarvisAccent($userId);
$jarvisRgb        = JarvisAccent::rgb($jarvisAccent);
$clockFormat      = $prefs->getClockFormat($userId);
$showBroadcastWeek = $prefs->getShowBroadcastWeek($userId);
$showBookmarkIcons = $prefs->getShowBookmarkIcons($userId);
$showAiPresence = $prefs->getAiPresence($userId);
$gridMaxWidth = $prefs->getGridMaxWidth($userId);
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
                            <?php foreach (['jarvis' => 'JARVIS', 'light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $key => $label): ?>
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                                <input type="radio" name="theme" value="<?= e($key) ?>"
                                    <?= $themePref === $key ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-faint text-xs mono">JARVIS is the holographic default. Light, Dark, and System stay available here and on the nav switcher. System follows your device. Starfield is a Dark-mode extra on the dashboard nav.</p>

                        <div id="jarvis-accent" class="jarvis-accent mt-4">
                            <h3 class="section-title mb-2">JARVIS accent</h3>
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="mono text-xs text-muted">
                                    Color
                                    <input type="color" id="jarvis-accent-picker" value="<?= e($jarvisAccent) ?>" aria-label="JARVIS accent color">
                                </label>
                                <label class="mono text-xs text-muted">
                                    R
                                    <input class="input jarvis-rgb" id="jarvis-accent-r" type="number" min="0" max="255" inputmode="numeric" value="<?= e((string) $jarvisRgb['r']) ?>" aria-label="Red">
                                </label>
                                <label class="mono text-xs text-muted">
                                    G
                                    <input class="input jarvis-rgb" id="jarvis-accent-g" type="number" min="0" max="255" inputmode="numeric" value="<?= e((string) $jarvisRgb['g']) ?>" aria-label="Green">
                                </label>
                                <label class="mono text-xs text-muted">
                                    B
                                    <input class="input jarvis-rgb" id="jarvis-accent-b" type="number" min="0" max="255" inputmode="numeric" value="<?= e((string) $jarvisRgb['b']) ?>" aria-label="Blue">
                                </label>
                                <label class="mono text-xs text-muted">
                                    Hex
                                    <input class="input mono" id="jarvis-accent-hex" name="jarvis_accent" value="<?= e($jarvisAccent) ?>" maxlength="7" spellcheck="false" autocomplete="off" aria-label="Accent hex">
                                </label>
                                <button type="button" class="btn text-xs" id="jarvis-accent-reset">Reset to cyan</button>
                                <span class="jarvis-swatch" aria-hidden="true"></span>
                            </div>
                            <p class="text-faint text-xs mono mt-2">Edges, glows, and gauges follow this color while JARVIS is on. The preview updates as you drag. Saving stores it on this account.</p>
                        </div>
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
                        <p class="text-faint text-xs mono mt-1">This dashboard searches stored text on this server. The others open in a new tab.</p>
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
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted mt-3">
                            <input type="checkbox" name="ai_presence" value="1"
                                <?= $showAiPresence ? 'checked' : '' ?>>
                            Show the AI presence sprite
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted mt-3">
                            <input type="checkbox" name="grid_max_width" value="1"
                                <?= $gridMaxWidth ? 'checked' : '' ?>>
                            Cap the widget grid and center it
                        </label>
                        <p class="text-faint text-xs mono mt-1">Columns stay fluid up to 1600px (12 columns), 2400px (18), or 3200px (24). A wider window keeps that width, with equal space on both sides. Turn this off and the grid fills the window.</p>
                        <p class="text-faint text-xs mono mt-1">When an agent uses a token, a small core drifts across the dashboard and names the token and the tool. It fades after about 30 seconds of quiet. Reduced motion keeps a still badge. The bubble never includes notes or account numbers.</p>
                    </div>

                    <button type="submit" class="btn-primary">
                        Save general settings
                    </button>
                </form>

            </section>

<?php require __DIR__ . '/settings/_shell-bottom.php'; ?>
