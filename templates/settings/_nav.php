<?php
/**
 * Settings steps. Groups match the sections that already lived in the old list.
 *
 * @var bool $settingsRenderNav
 * @var array<string, array<string, array{0: string, 1: string}>> $settingsGroups
 * @var list<array{0: string, 1: string}> $settingsPageLinks
 * @var list<array{key: string, label: string, href: string, group: string, number: int}> $settingsTrail
 * @var string $active
 * @var string $settingsGroupTitle
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
                'grok'       => ['Grok', '/settings/grok'],
                'pings'      => ['System status', '/settings/pings'],
            ],
            'Tracking' => $tracking,
        ];

        // The key is for the whole server, so only an admin gets this step.
        if (!$admin) {
            unset($groups['Connections']['grok']);
        }

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
            ['Configure JARVIS', '/settings/jarvis'],
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

    function settings_shell_icon(string $name): void
    {
        if ($name !== 'chevron-left' && $name !== 'circle-dot') {
            return;
        }
        $path = __DIR__ . '/../../public/assets/vendor/lucide/' . $name . '.svg';
        if (is_file($path)) {
            readfile($path);
        }
    }
}

if (empty($settingsRenderNav)) {
    return;
}

$active = $active ?? 'general';
$settingsGroups = $settingsGroups ?? [];
$settingsPageLinks = $settingsPageLinks ?? [];
$settingsTrail = $settingsTrail ?? [];
$settingsGroupTitle = $settingsGroupTitle ?? 'Settings';
$numbered = [];
$settingsCurrentNumber = 0;
foreach ($settingsTrail as $step) {
    $numbered[$step['key']] = $step;
    if ($step['key'] === $active) {
        $settingsCurrentNumber = (int) $step['number'];
    }
}
$lastKey = $settingsTrail === [] ? '' : $settingsTrail[count($settingsTrail) - 1]['key'];
?>
<nav class="settings-rail" id="settings-rail" aria-label="Settings">
    <div class="settings-rail-head">
        <p class="settings-kicker">Settings</p>
        <p class="settings-rail-title"><?= e($settingsGroupTitle) ?></p>
    </div>
    <ol class="settings-steps">
        <?php foreach ($settingsGroups as $groupLabel => $items): ?>
        <?php if ($items === []) { continue; } ?>
        <li class="settings-rail-label"><?= e($groupLabel) ?></li>
        <?php foreach ($items as $key => [$label, $href]): ?>
        <?php
            $step = $numbered[$key] ?? null;
            $isActive = $active === $key;
            $isVisited = $step !== null && $settingsCurrentNumber > 0 && (int) $step['number'] < $settingsCurrentNumber;
            $num = $step !== null ? sprintf('%02d', $step['number']) : '';
            $linkClass = 'settings-step-link';
            if ($isActive) {
                $linkClass .= ' is-active';
            } elseif ($isVisited) {
                $linkClass .= ' is-visited';
            }
        ?>
        <li class="settings-step-item<?= $key === $lastKey ? ' is-last' : '' ?>">
            <a class="<?= e($linkClass) ?>" href="<?= e($href) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                <span class="settings-step-num">
                    <span class="settings-step-index<?= $isVisited ? ' is-clipped' : '' ?>"><?= e($num) ?></span>
                    <?php if ($isVisited): ?>
                    <span class="settings-step-icon" aria-hidden="true"><?php settings_shell_icon('circle-dot'); ?></span>
                    <?php endif; ?>
                </span>
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
