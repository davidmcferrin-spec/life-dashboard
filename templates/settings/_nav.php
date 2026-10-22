<?php
/**
 * Settings sidebar nav — user settings + admin section
 *
 * @var string $active
 * @var array<string, mixed>|null $user
 */
$active = $active ?? 'general';
$showAdminNav = !empty($user) && (int) ($user['is_admin'] ?? 0) === 1;

$userNav = [
    'general'    => ['Dashboard', '/settings'],
    'trackers'   => ['Habits & trackers', '/settings/trackers'],
    'life'       => ['Life', '/settings/life'],
    'pantry'     => ['Pantry', '/pantry'],
    'money'      => ['Money', '/settings/money'],
    'investing'  => ['Investing', '/settings/investing'],
    'agents'     => ['Agents', '/settings/agents'],
    'clock'      => ['Focus Clock', '/settings/clock'],
    'stocks'     => ['Stocks', '/settings/stocks'],
    'bookmarks'  => ['Bookmarks', '/settings/bookmarks'],
    'feeds'      => ['News feeds', '/settings/feeds'],
    'reddit'     => ['Reddit', '/settings/reddit'],
    'spotify'    => ['Spotify', '/settings/spotify'],
    'tokens'     => ['Auto-login tokens', '/settings/tokens'],
    'flights'    => ['Flight watches', '/settings/flights'],
    'packages'   => ['Package tracking', '/settings/packages'],
    'clipboards' => ['Cloud clipboards', '/settings/clipboards'],
    'weather'    => ['Weather', '/settings/weather'],
    'pings'      => ['System status', '/settings/pings'],
    'todos'      => ['Todo lists', '/settings/todos'],
];
?>
<aside class="lg:w-52 flex-shrink-0">
    <nav class="surface p-3 settings-nav space-y-1 text-sm">
        <?php foreach ($userNav as $key => [$label, $href]): ?>
        <a href="<?= e($href) ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <a href="/brain" class="text-accent hover:underline px-2 py-1 block">Knowledge vault →</a>
        <a href="/brain/graph" class="text-accent hover:underline px-2 py-1 block">Brain view →</a>
        <a href="/news" class="text-accent hover:underline px-2 py-1 block">News reader →</a>
        <a href="/reddit" class="text-accent hover:underline px-2 py-1 block">Reddit reader →</a>
        <a href="/spotify" class="text-accent hover:underline px-2 py-1 block">Playlists →</a>
        <a href="/todos" class="text-accent hover:underline px-2 py-1 block">Todo lists →</a>

        <?php if ($showAdminNav): ?>
        <hr class="my-2 border-chrome">
        <p class="settings-nav-group-label px-2 pt-1 pb-1">Administration</p>
        <a href="/settings/setup" class="<?= $active === 'setup' ? 'is-active' : '' ?>">Setup</a>
        <a href="/settings/ollama" class="<?= $active === 'ollama' ? 'is-active' : '' ?>">Local AI</a>
        <a href="/settings/tunnel" class="<?= $active === 'tunnel' ? 'is-active' : '' ?>">Tunnel</a>
        <a href="/settings/system" class="<?= $active === 'system' ? 'is-active' : '' ?>">System health</a>
        <a href="/settings/cloud-storage" class="<?= $active === 'cloud-storage' ? 'is-active' : '' ?>">Cloud file defaults</a>
        <a href="/settings/users" class="<?= $active === 'users' ? 'is-active' : '' ?>">Users</a>
        <?php endif; ?>
    </nav>
</aside>
