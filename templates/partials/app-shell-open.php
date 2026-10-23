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
    $parts = parse_url($href);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : $href;
    if ($path !== $shellPath) {
        return false;
    }
    if ($path === '/pantry' && (!is_array($parts) || !isset($parts['query']))) {
        return (string) ($shellQuery['view'] ?? '') !== 'meals';
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

$shellGroups = [
    'Overview' => [
        ['Dashboard', '/'],
    ],
    'Intelligence' => [
        ['Vault', '/brain'],
        ['Brain', '/brain/graph'],
        ['Grok Digest', '/digest'],
        ['News', '/news'],
        ['Reddit', '/reddit'],
    ],
    'Automations' => [
        ['Agents', '/settings/agents'],
        ['Playlists', '/spotify'],
    ],
    'Schedule' => [
        ['Todos', '/todos'],
        ['Clock', '/clock'],
        ['Pantry', '/pantry'],
        ['Meals', '/pantry?view=meals'],
        ['Flights', '/flights'],
        ['Packages', '/settings/packages'],
    ],
];

$shellClaimedByGroup = ['/settings/agents', '/settings/packages'];
$shellSettingsActive = str_starts_with($shellPath, '/settings')
    && !in_array($shellPath, $shellClaimedByGroup, true);

if (!isset($starfieldPref) && !empty($user['id'])) {
    try {
        $starfieldPref = (new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance()))
            ->getStarfield((int) $user['id']);
    } catch (\Throwable) {
        $starfieldPref = false;
    }
}
$starfieldPref = $starfieldPref ?? false;

$shellUserName = trim((string) ($user['username'] ?? ''));
if ($shellUserName === '') {
    $shellUserName = 'Account';
}
?>
<div class="app-shell">
<aside id="app-sidebar" class="app-sidebar" aria-label="Primary">
    <a class="app-wordmark<?= $shellPath === '/' ? ' is-active' : '' ?>" href="/">
        <span class="app-mark" aria-hidden="true"></span>
        Dashboard
    </a>
    <nav class="app-sidebar-scroll">
        <?php foreach ($shellGroups as $shellLabel => $shellItems): ?>
        <div class="app-nav-group">
            <p class="app-nav-label"><?= e($shellLabel) ?></p>
            <?php foreach ($shellItems as [$shellItemLabel, $shellHref]): ?>
            <a class="app-nav-link<?= $shellCurrent($shellHref) ? ' is-active' : '' ?>" href="<?= e($shellHref) ?>"><?= e($shellItemLabel) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </nav>
    <a class="app-settings-pin<?= $shellSettingsActive ? ' is-active' : '' ?>" href="/settings"<?= $shellSettingsActive ? ' aria-current="page"' : '' ?>>
        <svg class="app-settings-cog" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                <circle cx="12" cy="12" r="2.6"/>
                <path d="M9.56 2.2A10.1 10.1 0 0 1 14.44 2.2L13.62 5.5A6.7 6.7 0 0 1 16.82 7.35L19.27 4.98A10.1 10.1 0 0 1 21.71 9.22L18.44 10.15A6.7 6.7 0 0 1 18.44 13.85L21.71 14.78A10.1 10.1 0 0 1 19.27 19.02L16.82 16.65A6.7 6.7 0 0 1 13.62 18.5L14.44 21.8A10.1 10.1 0 0 1 9.56 21.8L10.38 18.5A6.7 6.7 0 0 1 7.18 16.65L4.73 19.02A10.1 10.1 0 0 1 2.29 14.78L5.56 13.85A6.7 6.7 0 0 1 5.56 10.15L2.29 9.22A10.1 10.1 0 0 1 4.73 4.98L7.18 7.35A6.7 6.7 0 0 1 10.38 5.5L9.56 2.2Z"/>
            </g>
        </svg>
        <span class="app-settings-label">Settings</span>
    </a>
    <div class="app-user">
        <p class="app-user-name"><?= e($shellUserName) ?></p>
        <?php require __DIR__ . '/appearance-nav.php'; ?>
        <a href="/logout" class="nav-link nav-link-danger app-signout">Sign Out</a>
    </div>
</aside>
<button type="button" id="app-nav-backdrop" class="app-nav-backdrop" hidden aria-label="Close menu"></button>
<div class="app-main">
<header class="app-header">
    <button type="button" id="app-nav-toggle" class="app-nav-toggle" aria-expanded="false" aria-controls="app-sidebar">Menu</button>
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
        <span id="live-date"></span>
    </div>
</header>
<div class="app-content">
