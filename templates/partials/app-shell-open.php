<?php
/**
 * Authenticated shell: sidebar, header, user card.
 *
 * Opens .app-shell, .app-main, and .app-content. Close with app-shell-close.php.
 * Page-specific header controls are chosen from the request path so each
 * workspace keeps the control it already had (map, alarm, canvas tools).
 *
 * @var array<string, mixed> $user
 */

$shellPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($shellPath) || $shellPath === '') {
    $shellPath = '/';
}
$shellQuery = [];
$shellRawQuery = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY);
if (is_string($shellRawQuery) && $shellRawQuery !== '') {
    parse_str($shellRawQuery, $shellQuery);
}

$shellCurrent = static function (string $href) use ($shellPath, $shellQuery): bool {
    // Canvases are CloudPad even though the list lives on the clipboards tab.
    if ($href === '/settings/clipboards?tab=cloudpad' && str_starts_with($shellPath, '/cloudpad/')) {
        return true;
    }
    foreach (['/money', '/investing', '/stocks', '/feeds'] as $shellPrefix) {
        if ($href === $shellPrefix && ($shellPath === $shellPrefix || str_starts_with($shellPath, $shellPrefix . '/'))) {
            return true;
        }
    }
    $parts = parse_url($href);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : $href;
    if ($path !== $shellPath) {
        return false;
    }
    if ($path === '/pantry' && (!is_array($parts) || !isset($parts['query']))) {
        return (string) ($shellQuery['view'] ?? '') !== 'meals';
    }
    if ($path === '/settings/clipboards' && (!is_array($parts) || !isset($parts['query']))) {
        return (string) ($shellQuery['tab'] ?? '') !== 'cloudpad';
    }
    if (!is_array($parts) || !isset($parts['query'])) {
        return true;
    }
    $expected = [];
    parse_str((string) $parts['query'], $expected);
    foreach ($expected as $key => $value) {
        if ((string) ($shellQuery[$key] ?? '') !== (string) $value) {
            return false;
        }
    }
    return true;
};

// Flat list from the Figma Make sidebar. Vault stays Vault (the file spells it Valut).
$shellItems = [
    ['Dashboard', '/', 'layout-dashboard'],
    ['Music', '/spotify', 'music-2'],
    ['Money', '/money', 'wallet-cards'],
    ['Investing', '/investing', 'trending-up'],
    ['Stocks', '/stocks', 'chart-column'],
    ['Pantry', '/pantry', 'shopping-basket'],
    ['Meal Prep', '/pantry?view=meals', 'cooking-pot'],
    ['Vault', '/brain', 'book'],
    ['Brain', '/brain/graph', 'brain'],
    ['Clipboard', '/settings/clipboards', 'clipboard-list'],
    ['CloudPad', '/settings/clipboards?tab=cloudpad', 'cloud'],
    ['Clock', '/clock', 'clock-3'],
    ['Flights', '/flights', 'plane'],
    ['Feeds', '/feeds', 'inbox'],
    ['Grok Digest', '/digest', 'bot'],
];

$shellIcon = static function (string $name): void {
    $path = __DIR__ . '/../../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};

$shellClaimedByNav = ['/settings/clipboards'];
$shellSettingsActive = str_starts_with($shellPath, '/settings')
    && !in_array($shellPath, $shellClaimedByNav, true);

if ((!isset($starfieldPref) || !isset($themePref) || !isset($clockFormat)) && !empty($user['id'])) {
    try {
        $shellPrefs = new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance());
        $shellUserId = (int) $user['id'];
        if (!isset($starfieldPref)) {
            $starfieldPref = $shellPrefs->getStarfield($shellUserId);
        }
        if (!isset($themePref)) {
            $themePref = $shellPrefs->getTheme($shellUserId);
        }
        if (!isset($clockFormat)) {
            $clockFormat = $shellPrefs->getClockFormat($shellUserId);
        }
    } catch (\Throwable) {
        $starfieldPref = $starfieldPref ?? false;
        $themePref = $themePref ?? 'jarvis';
        $clockFormat = $clockFormat ?? '12h';
    }
}
$starfieldPref = $starfieldPref ?? false;
$themePref = $themePref ?? 'jarvis';
$shellClock24 = ($clockFormat ?? '12h') === '24h';

$shellUserName = trim((string) ($user['username'] ?? ''));
if ($shellUserName === '') {
    $shellUserName = 'Account';
}
$shellCut = static function (string $value, int $length): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }
    return substr($value, 0, $length);
};
$shellInitials = static function (string $name) use ($shellCut): string {
    $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || $parts === []) {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($parts) || $parts === []) {
        return 'A';
    }
    if (count($parts) >= 2) {
        $letters = $shellCut($parts[0], 1) . $shellCut($parts[1], 1);
    } else {
        $letters = $shellCut($parts[0], 2);
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($letters) : strtoupper($letters);
};
$shellUserInitials = $shellInitials($shellUserName);
$shellUserRole = ((int) ($user['is_admin'] ?? 0) === 1) ? 'Admin' : 'Member';

$shellDigestUnread = 0;
$shellNotifyUnread = 0;
if (!empty($user['id'])) {
    $shellUserId = (int) $user['id'];
    try {
        $shellDigestUnread = (new \Dashboard\Digest\DigestService(\Dashboard\DB\Database::getInstance()))->unreadCount($shellUserId);
    } catch (\Throwable) {
        $shellDigestUnread = 0;
    }
    try {
        $shellNotifyUnread = (new \Dashboard\Notification\NotificationService(\Dashboard\DB\Database::getInstance()))->unreadCount($shellUserId);
    } catch (\Throwable) {
        $shellNotifyUnread = 0;
    }
}
$shellCountLabel = static function (int $count): string {
    return $count > 99 ? '99+' : (string) $count;
};

$shellCrumbGroup = '';
$shellCrumbPage = '';
foreach ($shellItems as [$shellItemLabel, $shellHref]) {
    if ($shellCurrent($shellHref)) {
        $shellCrumbGroup = 'Life Dashboard';
        $shellCrumbPage = (string) $shellItemLabel;
    }
}
if ($shellCrumbPage === '' && $shellSettingsActive) {
    $shellCrumbGroup = 'Settings';
    $shellStepLabel = '';
    if (isset($settingsCurrent) && is_array($settingsCurrent)) {
        $shellStepLabel = trim((string) ($settingsCurrent['label'] ?? ''));
    }
    if ($shellStepLabel === '') {
        $shellStepLabel = trim((string) ($pageTitle ?? ''));
    }
    $shellCrumbPage = $shellStepLabel !== '' ? $shellStepLabel : 'Settings';
}
if ($shellCrumbPage === '') {
    $shellCrumbGroup = 'Life Dashboard';
    $shellCrumbPage = trim((string) ($pageTitle ?? 'Dashboard'));
    if ($shellCrumbPage === '') {
        $shellCrumbPage = 'Dashboard';
    }
}
?>
<script>
(function () {
    try {
        if (localStorage.getItem('dashboard_sidebar_collapsed') === '1') {
            document.documentElement.classList.add('app-sidebar-rail');
        }
    } catch (e) { /* private mode */ }
    if (/Mac|iPhone|iPad/.test(navigator.userAgent || '')) {
        document.documentElement.classList.add('app-shortcut-mac');
    }
})();
</script>
<div class="app-shell">
<div class="app-ambient-grid" aria-hidden="true"></div>
<aside id="app-sidebar" class="app-sidebar" aria-label="Primary">
    <div class="app-sidebar-brand">
        <a class="app-wordmark" href="/">
            <span class="app-mark" aria-hidden="true"><?php $shellIcon('sparkles'); ?></span>
            <span class="app-wordmark-text">LIFE<span class="app-wordmark-dot">.</span></span>
        </a>
        <button type="button" id="app-sidebar-rail" class="app-sidebar-rail-btn" aria-pressed="false" aria-label="Collapse sidebar">
            <span class="app-sidebar-rail-close" aria-hidden="true"><?php $shellIcon('panel-left-close'); ?></span>
            <span class="app-sidebar-rail-open" aria-hidden="true"><?php $shellIcon('panel-left-open'); ?></span>
        </button>
    </div>
    <nav class="app-sidebar-scroll">
        <?php foreach ($shellItems as [$shellItemLabel, $shellHref, $shellItemIcon]): ?>
        <?php
            $shellItemActive = $shellCurrent($shellHref);
            $shellItemUnread = $shellItemLabel === 'Grok Digest' ? $shellDigestUnread : 0;
            $shellItemTitle = $shellItemUnread > 0
                ? $shellItemLabel . ', ' . $shellCountLabel($shellItemUnread) . ' unread'
                : $shellItemLabel;
        ?>
        <a class="app-nav-link<?= $shellItemActive ? ' is-active' : '' ?>" href="<?= e($shellHref) ?>" title="<?= e($shellItemTitle) ?>"<?= $shellItemActive ? ' aria-current="page"' : '' ?>>
            <span class="app-nav-icon" aria-hidden="true"><?php $shellIcon($shellItemIcon); ?></span>
            <span class="app-nav-text"><?= e($shellItemLabel) ?></span>
            <?php if ($shellItemUnread > 0): ?>
            <span class="app-nav-badge"><?= e($shellCountLabel($shellItemUnread)) ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <a class="app-settings-pin<?= $shellSettingsActive ? ' is-active' : '' ?>" href="/settings" title="Settings"<?= $shellSettingsActive ? ' aria-current="page"' : '' ?>>
        <span class="app-settings-cog" aria-hidden="true"><?php
            // Inline the vendored file so its stroke stays currentColor.
            readfile(__DIR__ . '/../../public/assets/vendor/lucide/settings.svg');
        ?></span>
        <span class="app-settings-label">Settings</span>
    </a>
    <div class="app-user">
        <span class="app-user-avatar" aria-hidden="true"><?= e($shellUserInitials) ?></span>
        <span class="app-user-id">
            <p class="app-user-name"><?= e($shellUserName) ?></p>
            <p class="app-user-role"><?= e($shellUserRole) ?></p>
        </span>
        <div class="app-user-menu">
            <button type="button" id="app-user-menu-btn" class="app-user-menu-btn" aria-expanded="false" aria-haspopup="menu" aria-controls="app-user-menu-list" aria-label="Account menu">
                <span aria-hidden="true"><?php $shellIcon('ellipsis'); ?></span>
            </button>
            <div id="app-user-menu-list" class="app-user-menu-list" role="menu" hidden>
                <a href="/settings" class="app-user-menu-link" role="menuitem">Settings</a>
                <a href="/logout" class="app-user-menu-link" role="menuitem">Sign out</a>
            </div>
        </div>
    </div>
</aside>
<button type="button" id="app-nav-backdrop" class="app-nav-backdrop" hidden aria-label="Close menu"></button>
<div class="app-main">
<header class="app-header">
    <button type="button" id="app-nav-toggle" class="app-nav-toggle" aria-expanded="false" aria-controls="app-sidebar" aria-label="Open menu">
        <span class="app-nav-toggle-menu" aria-hidden="true"><?php $shellIcon('menu'); ?></span>
        <span class="app-nav-toggle-x" aria-hidden="true"><?php $shellIcon('x'); ?></span>
    </button>
    <nav class="app-header-crumb" aria-label="Breadcrumb">
        <?php if (strcasecmp($shellCrumbGroup, $shellCrumbPage) !== 0): ?>
        <span class="app-header-crumb-group"><?= e($shellCrumbGroup) ?></span>
        <span class="app-header-crumb-sep" aria-hidden="true"><?php $shellIcon('chevron-right'); ?></span>
        <?php endif; ?>
        <span class="app-header-crumb-page"><?= e($shellCrumbPage) ?></span>
    </nav>
    <div class="nav-meta app-header-tools">
        <?php if ($shellPath === '/brain'): ?>
        <button type="button" id="brain-show-map" class="nav-link">Map</button>
        <?php elseif ($shellPath === '/clock'): ?>
        <a href="/settings/clock"
           id="focus-clock-alarm-indicator"
           class="focus-clock-alarm-indicator<?= empty($alarmOn) ? ' hidden' : '' ?>"
           title="<?= !empty($alarmOn) ? 'Alarm on — ' . e((string) ($fcAlarm['time'] ?? '07:00')) : 'Alarm off' ?>"
           aria-label="<?= !empty($alarmOn) ? 'Alarm enabled' : 'Alarm off' ?>">
            <svg class="focus-clock-alarm-icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 2c1.1 0 2 .9 2 2v.29c2.58.46 4.48 2.59 4.48 5.21v3.15l1.85 1.85c.63.63.18 1.7-.71 1.7H6.38c-.89 0-1.34-1.07-.71-1.7l1.85-1.85V9.5c0-2.62 1.9-4.75 4.48-5.21V4c0-1.1.9-2 2-2zm-1 18h2a2 2 0 0 1-4 0h2z"/>
            </svg>
        </a>
        <a href="/settings/clock" class="nav-link">Settings</a>
        <?php elseif ($shellPath === '/news'): ?>
        <a href="/settings/feeds" class="nav-link">Sources</a>
        <?php elseif ($shellPath === '/todos'): ?>
        <a href="/settings/todos" class="nav-link">Manage lists</a>
        <?php elseif ($shellPath === '/reddit'): ?>
        <a href="/settings/reddit" class="nav-link">Subreddits</a>
        <?php elseif (str_starts_with($shellPath, '/cloudpad/canvas')): ?>
        <?php require __DIR__ . '/shell-tools-cloudpad.php'; ?>
        <?php endif; ?>
        <button type="button" class="nav-link" data-player-toggle aria-pressed="false">Player</button>
        <form class="app-header-search" role="search" action="/api/v1/search" method="get">
            <span class="app-header-search-icon" aria-hidden="true"><?php $shellIcon('search'); ?></span>
            <label class="app-header-search-label" for="app-search">Search</label>
            <input id="app-search" type="search" name="q" placeholder="Search" autocomplete="off" spellcheck="false" aria-keyshortcuts="Control+K Meta+K" aria-controls="app-search-results" aria-expanded="false" enterkeyhint="search">
            <button type="button" class="app-header-search-clear" hidden aria-label="Clear search"><?php $shellIcon('x'); ?></button>
            <kbd class="app-header-kbd" aria-hidden="true">
                <span class="app-header-kbd-cmd"><?php $shellIcon('command'); ?></span>
                <span class="app-header-kbd-ctrl">Ctrl</span>
                <span>K</span>
            </kbd>
            <div id="app-search-results" class="app-header-results" hidden></div>
        </form>
        <?php require __DIR__ . '/appearance-nav.php'; ?>
        <div class="app-notify">
            <button type="button" id="app-notify-btn" class="app-notify-btn" aria-expanded="false" aria-controls="app-notify-panel" aria-label="<?= $shellNotifyUnread > 0 ? e('Notifications, ' . $shellCountLabel($shellNotifyUnread) . ' unread') : 'Notifications' ?>">
                <span aria-hidden="true"><?php $shellIcon('bell'); ?></span>
                <?php if ($shellNotifyUnread > 0): ?>
                <span class="app-notify-badge"><?= e($shellCountLabel($shellNotifyUnread)) ?></span>
                <?php endif; ?>
            </button>
            <div id="app-notify-panel" class="app-notify-panel" role="dialog" aria-label="Notifications" hidden>
                <div class="app-notify-head">
                    <span>Notifications</span>
                    <button type="button" id="app-notify-read" class="app-notify-read" hidden>Mark all read</button>
                </div>
                <div id="app-notify-list" class="app-notify-list"></div>
            </div>
        </div>
        <div class="app-header-clock"<?= $shellClock24 ? ' data-hour12="0"' : '' ?>>
            <span id="live-clock" class="app-header-clock-time"></span>
            <span id="live-date" class="app-header-clock-date"></span>
        </div>
    </div>
</header>
<div class="app-content">
