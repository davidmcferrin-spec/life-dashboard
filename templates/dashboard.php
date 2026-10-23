<?php
/**
 * dashboard.php — main dashboard view
 *
 * @var array<string, mixed> $user  Current authenticated user
 * @var \Dashboard\DB\Database $db
 */

use Dashboard\DB\Database;
use Dashboard\Settings\FeedPreferenceService;
use Dashboard\Settings\PreferenceService;
use Dashboard\Tracking\Services;
use Dashboard\Widgets\WidgetTypeRegistry;

$db = Database::getInstance();

// -------------------------------------------------------
// User preferences
// -------------------------------------------------------
$prefService = new PreferenceService($db);
$feedPrefs   = FeedPreferenceService::fromDatabase($db);
$userId      = (int) $user['id'];

$searchEngine     = $prefService->getSearchEngine($userId);
$themePref        = $prefService->getTheme($userId);
$clockFormat         = $prefService->getClockFormat($userId);
$showBroadcastWeek   = $prefService->getShowBroadcastWeek($userId);
$showBookmarkIcons   = $prefService->getShowBookmarkIcons($userId);
$gridMaxWidth        = $prefService->getGridMaxWidth($userId);
$showAiPresence      = $prefService->getAiPresence($userId);
$pageTitle        = 'Dashboard';
$extraStyles      = ['/assets/vendor/gridstack-12.6.0/gridstack.min.css', '/assets/css/lifedash.css'];
$showNewsFeed     = $feedPrefs->hasDashboardPins($userId);

$searchEngines  = PreferenceService::searchEnginesForUi();
$starfieldPref  = $prefService->getStarfield($userId);

// -------------------------------------------------------
// Widget grid (first visit converts the legacy layout prefs)
// -------------------------------------------------------
$lifedash = Services::fromDatabase($db);
$lifedash->widgets->ensureSeeded($userId);
$lifedash->widgets->ensureCompactRow($userId);
$lifedash->widgets->ensureOverviewWidgets($userId);
$widgets     = $lifedash->widgets->list($userId);
$wideLayouts = $lifedash->widgets->breakpointLayouts($userId);
$onBoard  = array_fill_keys(array_column($widgets, 'type'), true);
$hasAny   = static fn (string ...$types): bool => array_intersect_key($onBoard, array_flip($types)) !== [];

// -------------------------------------------------------
// Bookmarks
// -------------------------------------------------------
$folders = $db->fetchAll(
    'SELECT * FROM bookmark_folders WHERE user_id = ? ORDER BY sort_order ASC, name ASC',
    [(int) $user['id']]
);

foreach ($folders as &$folder) {
    $folder['bookmarks'] = $db->fetchAll(
        'SELECT * FROM bookmarks WHERE user_id = ? AND folder_id = ? ORDER BY sort_order ASC, title ASC',
        [(int) $user['id'], (int) $folder['id']]
    );
}
unset($folder);

// Unfiled bookmarks
$unfiledBookmarks = $db->fetchAll(
    'SELECT * FROM bookmarks WHERE user_id = ? AND folder_id IS NULL ORDER BY sort_order ASC, title ASC',
    [(int) $user['id']]
);

// -------------------------------------------------------
// Timezones
// -------------------------------------------------------
$timezones = [
    ['label' => 'ET', 'tz' => 'America/New_York'],
    ['label' => 'CT', 'tz' => 'America/Chicago'],
    ['label' => 'MT', 'tz' => 'America/Denver'],
    ['label' => 'PT', 'tz' => 'America/Los_Angeles'],
];

?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen" data-ai-presence="<?= $showAiPresence ? '1' : '0' ?>">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="dash-main mx-auto py-6 space-y-6">

    <?php $pageEyebrow = 'Personal intelligence system'; $pageHeading = 'Dashboard'; require __DIR__ . '/partials/page-heading.php'; ?>

    <!-- -------------------------------------------------------
         TIMEZONE BAR
         ------------------------------------------------------- -->
    <div class="timezone-block flex flex-col items-center gap-2">
        <section id="timezone-bar" class="flex justify-center gap-8" data-clock-format="<?= e($clockFormat) ?>">
            <?php foreach ($timezones as $tz): ?>
            <div class="clock-cell text-center">
                <div class="clock-label mb-0.5"><?= e($tz['label']) ?></div>
                <div class="clock-hover">
                    <div class="clock-time clock" data-tz="<?= e($tz['tz']) ?>" tabindex="0">--:--</div>
                    <div class="clock-popover" role="tooltip" aria-hidden="true">
                        <div class="clock-popover-offset"></div>
                        <div class="clock-popover-jam"></div>
                        <button type="button" class="clock-jam-btn">Jam (Alt+J)</button>
                    </div>
                </div>
                <div class="clock-ampm ampm" data-tz="<?= e($tz['tz']) ?>"></div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php if ($showBroadcastWeek): ?>
        <div id="broadcast-week-line" class="broadcast-week-line text-center" aria-live="polite">
            <p class="broadcast-week-primary text-muted text-sm"></p>
            <p class="broadcast-week-detail text-faint text-xs mono mt-0.5"></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- -------------------------------------------------------
         Search stays fixed and centered. Vitals, agenda, and quick actions
         are ordinary widgets in the grid below.
         ------------------------------------------------------- -->
    <section class="ld-board" aria-label="Dashboard widgets">
        <div class="ld-search-row">
            <form id="search-form" method="GET" target="_blank" class="ov-composer ld-search-box" action="https://www.google.com/search">
                <label class="ov-sr" for="search-input">How can I assist you?</label>
                <svg class="ov-command" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8l4 4-4 4M13 16h5"/>
                </svg>
                <input type="text" id="search-input" name="q" placeholder="How can I assist you?" autofocus class="search-input" autocomplete="off">
                <button type="submit" class="ov-send btn-primary" aria-label="Send">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </button>
            </form>
            <div class="seg-group ov-engines">
                <?php foreach ($searchEngines as $key => $engine): ?>
                <button
                    type="button"
                    class="search-engine-btn seg-btn <?= $key === $searchEngine ? 'seg-btn-active' : '' ?>"
                    data-engine="<?= e($key) ?>"
                    data-url="<?= e($engine['url']) ?>"
                    data-param="<?= e($engine['param']) ?>"
                >
                    <?= e($engine['label']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div id="local-search" class="local-search hidden ov-results" aria-live="polite"></div>
        </div>
        <div class="ld-toolbar">
            <span id="ld-status" class="text-faint text-xs mono" aria-live="polite"></span>
            <div class="ld-toolbar-actions">
                <button type="button" id="ld-add" class="btn text-xs ld-edit-only">+ Add widget</button>
                <button type="button" id="ld-edit" class="btn text-xs" aria-pressed="false">Edit layout</button>
            </div>
        </div>

        <?php if ($widgets === []): ?>
        <p id="ld-empty" class="empty-state mono text-xs py-6 text-center">
            No widgets yet. Choose <span class="text-secondary">Edit layout → + Add widget</span>.
        </p>
        <?php endif; ?>

        <div id="ld-grid" class="grid-stack dashboard-widgets ld-grid<?= $gridMaxWidth ? ' ld-cols-capped' : '' ?>" data-grid-cap="<?= $gridMaxWidth ? '1' : '0' ?>">
            <?php foreach ($widgets as $w):
                $legacyKey = WidgetTypeRegistry::isLegacy($w['type']) ? substr($w['type'], 7) : null;
                $partial   = $legacyKey !== null ? __DIR__ . '/partials/widgets/' . $legacyKey . '.php' : null;
                if ($partial !== null && !is_file($partial)) {
                    continue; // unknown legacy type (should not happen; registry and partials ship together)
                }
            ?>
            <div class="grid-stack-item<?= !empty($w['height_locked']) || !empty($w['placement_locked']) ? ' ld-h-lock' : '' ?><?= !empty($w['placement_locked']) ? ' ld-place-lock' : '' ?>"
                 gs-id="<?= (int) $w['id'] ?>"
                 gs-x="<?= (int) $w['x'] ?>" gs-y="<?= (int) $w['y'] ?>"
                 gs-w="<?= (int) $w['w'] ?>" gs-h="<?= (int) $w['h'] ?>"
                 gs-min-w="<?= (int) $w['min_w'] ?>" gs-min-h="<?= (int) $w['min_h'] ?>"
                 <?php if (!empty($w['placement_locked'])): ?>gs-locked="true" gs-no-move="true" gs-no-resize="true"<?php endif; ?>
                 data-widget-id="<?= (int) $w['id'] ?>"
                 data-widget-type="<?= e($w['type']) ?>">
                <div class="grid-stack-item-content surface ld-w <?= $legacyKey !== null ? 'ld-w-legacy' : 'ld-w-native' ?>">
                    <div class="ld-fit">
                    <div class="ld-edit-bar ld-edit-only">
                        <span class="ld-drag" title="Drag to move" aria-hidden="true">⋮⋮</span>
                        <button type="button" class="ld-icon-btn<?= !empty($w['placement_locked']) ? ' is-on' : '' ?>" data-action="lock" title="<?= !empty($w['placement_locked']) ? 'Unlock size and position' : 'Lock size and position' ?>" aria-label="<?= !empty($w['placement_locked']) ? 'Unlock size and position' : 'Lock size and position' ?>" aria-pressed="<?= !empty($w['placement_locked']) ? 'true' : 'false' ?>"><?= !empty($w['placement_locked']) ? 'Locked' : 'Lock' ?></button>
                        <button type="button" class="ld-icon-btn" data-action="fit" title="Size to content" aria-label="Size to content">Fit</button>
                        <?php if ($legacyKey === null): ?>
                        <button type="button" class="ld-icon-btn" data-action="configure" title="Configure" aria-label="Configure widget">⚙</button>
                        <?php endif; ?>
                        <button type="button" class="ld-icon-btn ld-danger" data-action="remove" title="Remove" aria-label="Remove widget">✕</button>
                    </div>
                    <?php if ($partial !== null): ?>
                    <div class="ld-w-scroll"><?php require $partial; ?></div>
                    <?php else: ?>
                    <div class="ld-w-head dashboard-widget-head">
                        <h2 class="section-title ld-w-title"><?= e($w['title'] ?? $w['label']) ?></h2>
                        <div class="ld-w-tools"></div>
                    </div>
                    <div class="ld-w-body"><div class="empty-state mono text-xs">Loading…</div></div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <script type="application/json" id="ld-initial"><?= json_encode($widgets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
        <script type="application/json" id="ld-layouts"><?= json_encode($wideLayouts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
    </section>

</main>

<?php if ($hasAny('legacy.todos')): ?>
<div id="todo-widget-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="todo-widget-add-title">
    <div class="app-modal-backdrop" id="todo-widget-add-backdrop" aria-hidden="true"></div>
    <div class="app-modal-panel surface">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h3 id="todo-widget-add-title" class="section-title text-sm">Add task</h3>
            <button type="button" id="todo-widget-add-close" class="app-modal-close" aria-label="Close">×</button>
        </div>
        <form id="todo-widget-add-form" class="space-y-3">
            <label class="block">
                <span class="mono text-xs text-faint">Task</span>
                <input type="text" id="todo-widget-input" class="input w-full mt-1 text-sm" placeholder="What needs doing?" maxlength="255" autocomplete="off" required>
            </label>
            <label class="block">
                <span class="mono text-xs text-faint">Due date (optional)</span>
                <input type="date" id="todo-widget-due" class="input w-full mt-1 text-sm">
            </label>
            <p class="text-faint mono text-xs">Notes and links: <a href="/todos" class="link">full todo page →</a></p>
            <p id="todo-widget-add-error" class="ld-field-error hidden" role="alert"></p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary text-sm">Add</button>
                <button type="button" id="todo-widget-add-cancel" class="btn text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="todo-widget-edit-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="todo-widget-edit-title">
    <div class="app-modal-backdrop" id="todo-widget-edit-backdrop" aria-hidden="true"></div>
    <div class="app-modal-panel surface max-w-lg w-full">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h3 id="todo-widget-edit-title" class="section-title text-sm">Edit task</h3>
            <button type="button" id="todo-widget-edit-close" class="app-modal-close" aria-label="Close">×</button>
        </div>
        <form id="todo-widget-edit-form" class="space-y-3">
            <input type="hidden" id="todo-widget-edit-id" value="">
            <label class="block">
                <span class="mono text-xs text-faint">Task</span>
                <input type="text" id="todo-widget-edit-input" class="input w-full mt-1 text-sm" maxlength="255" autocomplete="off" required>
            </label>
            <label class="block todo-widget-edit-owner-only">
                <span class="mono text-xs text-faint">Due date</span>
                <div class="flex flex-wrap items-center gap-2 mt-1">
                    <input type="date" id="todo-widget-edit-due" class="input text-sm">
                    <button type="button" id="todo-widget-edit-due-clear" class="link text-xs">Clear</button>
                </div>
            </label>
            <label class="block">
                <span class="mono text-xs text-faint">Notes</span>
                <textarea id="todo-widget-edit-notes" class="input w-full mt-1 mono text-sm" rows="3" maxlength="4000" placeholder="Optional notes…"></textarea>
            </label>
            <div>
                <span class="mono text-xs text-faint">Links</span>
                <div id="todo-widget-edit-links" class="mt-1"></div>
                <button type="button" id="todo-widget-edit-link-add" class="link text-xs mt-1">+ Add link</button>
            </div>
            <label class="block todo-widget-edit-owner-only">
                <span class="mono text-xs text-faint">Assign to</span>
                <select id="todo-widget-edit-assignee" class="input mono text-xs mt-1 w-full">
                    <option value="">Unassigned</option>
                </select>
            </label>
            <p id="todo-widget-edit-error" class="ld-field-error hidden" role="alert"></p>
            <div class="flex flex-wrap gap-2 pt-1">
                <button type="submit" class="btn-primary text-sm">Save</button>
                <button type="button" id="todo-widget-edit-cancel" class="btn text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/lifedash-modals.php'; ?>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/dashboard.js?v=<?= e($assetVer) ?>"></script>
<script>
// dashboard.js writes "Search..." into the placeholder when the engine changes.
(function () {
    var input = document.getElementById('search-input');
    if (!input) return;
    var hint = 'How can I assist you?';
    var show = function () { input.placeholder = hint; };
    show();
    document.querySelectorAll('.search-engine-btn').forEach(function (btn) {
        btn.addEventListener('click', show);
    });
})();
</script>
<?php if ($hasAny('legacy.flights', 'legacy.packages', 'legacy.pings', 'legacy.stocks')): ?>
<script src="/assets/js/widgets.js?v=<?= e($assetVer) ?>"></script>
<?php endif; ?>
<?php if ($hasAny('legacy.todos')): ?>
<script src="/assets/js/todo-due.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/todo-widget.js?v=<?= e($assetVer) ?>"></script>
<?php endif; ?>
<?php if ($showNewsFeed && $hasAny('legacy.news')): ?>
<script src="/assets/js/news-feed.js?v=<?= e($assetVer) ?>"></script>
<?php endif; ?>
<?php if ($hasAny('legacy.clipboards')): ?>
<script src="/assets/js/clipboard-markdown.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/clipboard-widget.js?v=<?= e($assetVer) ?>"></script>
<?php endif; ?>
<script src="/assets/vendor/gridstack-12.6.0/gridstack-all.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-renderers.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-grid.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer) ?>"></script>
<?php if ($showAiPresence): ?>
<div id="ai-presence" class="ai-presence" hidden>
    <div class="ai-bubble" role="status" aria-live="polite">
        <p id="ai-agent" class="ai-agent"></p>
        <p id="ai-label" class="ai-label"></p>
    </div>
    <svg class="ai-core" viewBox="0 0 80 80" aria-hidden="true">
        <circle class="ai-ring" cx="40" cy="40" r="30" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="3 4"/>
        <circle class="ai-ring ai-ring-b" cx="40" cy="40" r="22" fill="none" stroke="currentColor" stroke-width="1.15"/>
        <polygon points="40,18 58,28 58,52 40,62 22,52 22,28" fill="none" stroke="currentColor" stroke-width="1.4"/>
        <rect x="33" y="35" width="14" height="10" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <line class="ai-pupil" x1="27" y1="40" x2="53" y2="40" stroke="currentColor" stroke-width="2"/>
        <circle cx="40" cy="40" r="2.2" fill="currentColor"/>
    </svg>
</div>
<script src="/assets/js/ai-presence.js?v=<?= e($assetVer) ?>"></script>
<?php endif; ?>
</body>
</html>
