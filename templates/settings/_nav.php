<?php
/**
 * Settings steps. Groups match the sections that already lived in the old list.
 *
 * @var bool $settingsRenderNav
 * @var array<string, array<string, array{0: string, 1: string}>> $settingsGroups
 * @var list<array{0: string, 1: string}> $settingsPageLinks
 * @var list<array{key: string, label: string, href: string, group: string, number: int}> $settingsTrail
 * @var string $active
 */

if (!function_exists('settings_nav_groups')) {
    /**
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    function settings_nav_groups(bool $admin): array
    {
        $tracking = [];
        if ($admin) {
            $tracking['flight-provider'] = ['Flight provider', '/settings/flight-provider'];
        }
        $tracking['packages'] = ['Package tracking', '/settings/packages'];

        $groups = [
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
            'Tracking' => $tracking,
        ];

        if ($admin) {
            $groups['Administration'] = [
                'setup'         => ['Setup', '/settings/setup'],
                'ollama'        => ['Local AI', '/settings/ollama'],
                'tunnel'        => ['Tunnel', '/settings/tunnel'],
                'system'        => ['System health', '/settings/system'],
                'cloud-storage' => ['Cloud file defaults', '/settings/cloud-storage'],
                'users'         => ['Users', '/settings/users'],
            ];
        }

        return $groups;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    function settings_nav_page_links(): array
    {
        return [
            ['Pantry', '/pantry'],
            ['Grok Digest', '/digest'],
            ['Knowledge vault', '/brain'],
            ['Brain view', '/brain/graph'],
            ['News reader', '/news'],
            ['Reddit reader', '/reddit'],
            ['Playlists', '/spotify'],
            ['Todo lists', '/todos'],
        ];
    }

    /**
     * @param array<string, array<string, array{0: string, 1: string}>> $groups
     * @return list<array{key: string, label: string, href: string, group: string, number: int}>
     */
    function settings_nav_trail(array $groups): array
    {
        $trail = [];
        $number = 0;
        foreach ($groups as $groupLabel => $items) {
            foreach ($items as $key => $item) {
                $number++;
                $trail[] = [
                    'key'    => $key,
                    'label'  => $item[0],
                    'href'   => $item[1],
                    'group'  => $groupLabel,
                    'number' => $number,
                ];
            }
        }

        return $trail;
    }
}

if (empty($settingsRenderNav)) {
    return;
}

$active = $active ?? 'general';
$settingsGroups = $settingsGroups ?? [];
$settingsPageLinks = $settingsPageLinks ?? [];
$settingsTrail = $settingsTrail ?? [];
$numbered = [];
foreach ($settingsTrail as $step) {
    $numbered[$step['key']] = $step;
}
$lastKey = $settingsTrail === [] ? '' : $settingsTrail[count($settingsTrail) - 1]['key'];
?>
<nav class="settings-rail" id="settings-rail" aria-label="Settings">
    <ol class="settings-steps">
        <?php foreach ($settingsGroups as $groupLabel => $items): ?>
        <?php if ($items === []) { continue; } ?>
        <li class="settings-rail-label"><?= e($groupLabel) ?></li>
        <?php foreach ($items as $key => [$label, $href]): ?>
        <?php
            $step = $numbered[$key] ?? null;
            $isActive = $active === $key;
            $num = $step !== null ? sprintf('%02d', $step['number']) : '';
        ?>
        <li class="settings-step-item<?= $key === $lastKey ? ' is-last' : '' ?>">
            <a class="settings-step-link<?= $isActive ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                <span class="settings-step-num"><?= e($num) ?></span>
                <span class="settings-step-name"><?= e($label) ?></span>
            </a>
        </li>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </ol>
    <div class="settings-rail-pages">
        <p class="settings-rail-label">Pages</p>
        <ul class="settings-page-links">
            <?php foreach ($settingsPageLinks as [$label, $href]): ?>
            <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
