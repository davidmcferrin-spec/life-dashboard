<?php
/**
 * Settings sidebar. User settings are grouped; administration is separate.
 *
 * @var string $active
 * @var array<string, mixed>|null $user
 */
$active = $active ?? 'general';
$showAdminNav = !empty($user) && (int) ($user['is_admin'] ?? 0) === 1;

$userGroups = [
    'Account' => [
        'general' => ['Dashboard', '/settings'],
        'tokens'  => ['Auto-login tokens', '/settings/tokens'],
    ],
    'Life' => [
        'life'     => ['Life', '/settings/life'],
        'trackers' => ['Habits & trackers', '/settings/trackers'],
        'clock'    => ['Focus Clock', '/settings/clock'],
        'todos'    => ['Todo lists', '/settings/todos'],
        'pantry'   => ['Shelf life', '/settings/pantry'],
    ],
    'Money' => [
        'money'     => ['Money', '/settings/money'],
        'investing' => ['Investing', '/settings/investing'],
    ],
    'Connections' => [
        'weather'    => ['Weather', '/settings/weather'],
        'stocks'     => ['Stocks', '/settings/stocks'],
        'feeds'      => ['News feeds', '/settings/feeds'],
        'reddit'     => ['Reddit', '/settings/reddit'],
        'spotify'    => ['Spotify', '/settings/spotify'],
        'bookmarks'  => ['Bookmarks', '/settings/bookmarks'],
        'clipboards' => ['Cloud clipboards', '/settings/clipboards'],
        'agents'     => ['Agents', '/settings/agents'],
        'pings'      => ['System status', '/settings/pings'],
    ],
    'Tracking' => [
        'flights'  => ['Flight watches', '/settings/flights'],
        'packages' => ['Package tracking', '/settings/packages'],
    ],
];

$pageLinks = [
    ['Pantry', '/pantry'],
    ['Grok Digest', '/digest'],
    ['Knowledge vault →', '/brain'],
    ['Brain view →', '/brain/graph'],
    ['News reader →', '/news'],
    ['Reddit reader →', '/reddit'],
    ['Playlists →', '/spotify'],
    ['Todo lists →', '/todos'],
];

$adminNav = [
    'setup'         => ['Setup', '/settings/setup'],
    'ollama'        => ['Local AI', '/settings/ollama'],
    'tunnel'        => ['Tunnel', '/settings/tunnel'],
    'system'        => ['System health', '/settings/system'],
    'cloud-storage' => ['Cloud file defaults', '/settings/cloud-storage'],
    'users'         => ['Users', '/settings/users'],
];

$settingsGroupId = static function (string $label): string {
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $label));
    return 'settings-group-' . trim($slug, '-');
};
?>
<aside class="settings-nav-aside lg:w-52 flex-shrink-0">
    <nav class="surface p-3 settings-nav text-sm" aria-label="Settings">
        <?php foreach ($userGroups as $groupLabel => $items): ?>
        <?php $groupId = $settingsGroupId($groupLabel); ?>
        <div class="settings-nav-group" role="group" aria-labelledby="<?= e($groupId) ?>">
            <p class="settings-nav-group-label" id="<?= e($groupId) ?>"><?= e($groupLabel) ?></p>
            <?php foreach ($items as $key => [$label, $href]): ?>
            <a href="<?= e($href) ?>" class="<?= $active === $key ? 'is-active' : '' ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <?php $pagesId = $settingsGroupId('Pages'); ?>
        <div class="settings-nav-group" role="group" aria-labelledby="<?= e($pagesId) ?>">
            <p class="settings-nav-group-label" id="<?= e($pagesId) ?>">Pages</p>
            <?php foreach ($pageLinks as [$label, $href]): ?>
            <a href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($showAdminNav): ?>
        <?php $adminId = $settingsGroupId('Administration'); ?>
        <div class="settings-nav-group" role="group" aria-labelledby="<?= e($adminId) ?>">
            <p class="settings-nav-group-label" id="<?= e($adminId) ?>">Administration</p>
            <?php foreach ($adminNav as $key => [$label, $href]): ?>
            <a href="<?= e($href) ?>" class="<?= $active === $key ? 'is-active' : '' ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </nav>
</aside>
