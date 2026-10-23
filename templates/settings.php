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
$settingsFormId   = 'settings-general';
$settingsSubmitLabel = 'Save';

require __DIR__ . '/settings/_shell-top.php';
?>

            <?php $pageEyebrow = ''; $pageHeading = 'Dashboard'; require __DIR__ . '/partials/page-heading.php'; ?>
            <p class="text-muted text-sm mb-6">Appearance, search, and which dashboard sections to show.</p>

                <p class="text-faint mono text-xs mb-4">
                    Account <span class="text-muted"><?= e((string) $user['username']) ?></span>
                    <?php if (!empty($user['last_login_at'])): ?>
                    · Last login <?= e((string) $user['last_login_at']) ?>
                    <?php endif; ?>
                    <?php if (!empty($user['last_active_at'])): ?>
                    · Last active <?= e((string) $user['last_active_at']) ?>
                    <?php endif; ?>
                </p>

                <form id="settings-general" method="post" action="/settings/preferences" class="space-y-2">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">

                    <h2 class="settings-section">Appearance</h2>
                    <div class="settings-field">
                        <span class="settings-label" id="theme-label">Theme</span>
                        <div class="settings-seg" role="radiogroup" aria-labelledby="theme-label">
                            <?php foreach (['jarvis' => 'JARVIS', 'light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $key => $label): ?>
                            <label class="settings-seg-btn">
                                <input class="settings-seg-input" type="radio" name="theme" value="<?= e($key) ?>"
                                    <?= $themePref === $key ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="settings-help">JARVIS and Dark share the near-black canvas. Light keeps the light canvas. System follows the device and the same teal. Starfield is a Dark-mode extra on the dashboard.</p>

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
                                <button type="button" class="btn text-xs" id="jarvis-accent-reset">Reset</button>
                                <span class="jarvis-swatch" aria-hidden="true"></span>
                            </div>
                            <p class="text-faint text-xs mono mt-2">JARVIS links and gauges use this color. It starts as the shared teal. Light and Dark keep the teal that reads on their canvas.</p>
                        </div>
                    </div>

                    <h2 class="settings-section">Clock</h2>
                    <div class="settings-field">
                        <span class="settings-label" id="clock-format-label">Clock format</span>
                        <div class="settings-seg" role="radiogroup" aria-labelledby="clock-format-label">
                            <?php foreach (['12h' => '12-hour', '24h' => '24-hour'] as $key => $label): ?>
                            <label class="settings-seg-btn">
                                <input class="settings-seg-input" type="radio" name="clock_format" value="<?= e($key) ?>"
                                    <?= $clockFormat === $key ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="settings-help">Applies to the clock widget on the dashboard. Hover a clock to see sync offset. Press <kbd class="mono">Alt+J</kbd> on the dashboard to jam time from the server.</p>
                    </div>
                    <div class="settings-field">
                        <label class="settings-check">
                            <input type="checkbox" name="show_broadcast_week" value="1"
                                <?= $showBroadcastWeek ? 'checked' : '' ?>>
                            <span>
                                <span class="settings-label">Broadcast week</span>
                                <span class="settings-help">Monday–Sunday weeks. Week 1 contains January 1, using Eastern Time for today. The large clock and alarm are under <a href="/settings/clock" class="link">Focus Clock</a>.</span>
                            </span>
                        </label>
                    </div>

                    <h2 class="settings-section">Search</h2>
                    <div class="settings-field">
                        <span class="settings-label" id="search-engine-label">Search engine</span>
                        <div class="settings-seg" role="radiogroup" aria-labelledby="search-engine-label">
                            <?php foreach (PreferenceService::searchEnginesForUi() as $key => $engine): ?>
                            <label class="settings-seg-btn">
                                <input class="settings-seg-input" type="radio" name="search_engine" value="<?= e($key) ?>"
                                    <?= $searchEngine === $key ? 'checked' : '' ?>>
                                <?= e($engine['name']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="settings-help">This dashboard searches stored text on this server. The others open in a new tab.</p>
                    </div>

                    <h2 class="settings-section">Dashboard widgets</h2>
                    <p class="settings-help settings-help-block">
                        Arrange widgets on the dashboard with Edit layout.
                        Habits and other trackers are under
                        <a href="/settings/trackers" class="link">Habits &amp; trackers</a>.
                    </p>
                    <div class="settings-field">
                        <label class="settings-check">
                            <input type="checkbox" name="show_bookmark_icons" value="1"
                                <?= $showBookmarkIcons ? 'checked' : '' ?>>
                            <span>
                                <span class="settings-label">Bookmark icons</span>
                                <span class="settings-help">Show icons on the dashboard bookmark rows.</span>
                            </span>
                        </label>
                    </div>
                    <div class="settings-field">
                        <label class="settings-check">
                            <input type="checkbox" name="ai_presence" value="1"
                                <?= $showAiPresence ? 'checked' : '' ?>>
                            <span>
                                <span class="settings-label">AI presence</span>
                                <span class="settings-help">When an agent uses a token, a small core names the token and the tool. It fades after about 30 seconds of quiet. Reduced motion keeps a still badge. The bubble never includes notes or account numbers.</span>
                            </span>
                        </label>
                    </div>
                    <div class="settings-field">
                        <label class="settings-check">
                            <input type="checkbox" name="grid_max_width" value="1"
                                <?= $gridMaxWidth ? 'checked' : '' ?>>
                            <span>
                                <span class="settings-label">Cap the grid</span>
                                <span class="settings-help">Columns stay fluid up to 1600px (12 columns), 2400px (18), or 3200px (24). A wider window keeps that width, with equal space on both sides. Off, and the grid fills the window.</span>
                            </span>
                        </label>
                    </div>
                </form>

<?php require __DIR__ . '/settings/_shell-bottom.php'; ?>
