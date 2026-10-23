<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\Support\ValidationException;

/**
 * Dashboard on/off switches stored in user_preferences.
 * Security settings are named in skipped() and are not writable here.
 */
final class FeatureFlags
{
    /**
     * @var array<string, array{label: string, detail: string, icon: string, get: string, set: string}>
     */
    public const FLAGS = [
        'show_broadcast_week' => [
            'label'  => 'Broadcast week',
            'detail' => 'Monday–Sunday week number on the clock widget.',
            'icon'   => 'calendar-days',
            'get'    => 'getShowBroadcastWeek',
            'set'    => 'setShowBroadcastWeek',
        ],
        'show_bookmark_icons' => [
            'label'  => 'Bookmark icons',
            'detail' => 'Show icons on the dashboard bookmark rows.',
            'icon'   => 'bookmark',
            'get'    => 'getShowBookmarkIcons',
            'set'    => 'setShowBookmarkIcons',
        ],
        'ai_presence' => [
            'label'  => 'AI presence',
            'detail' => 'A small core names the token and the tool while an agent is working.',
            'icon'   => 'sparkles',
            'get'    => 'getAiPresence',
            'set'    => 'setAiPresence',
        ],
        'grid_max_width' => [
            'label'  => 'Cap the grid',
            'detail' => 'Stop the board growing past the width for 12, 18, or 24 columns.',
            'icon'   => 'layout-dashboard',
            'get'    => 'getGridMaxWidth',
            'set'    => 'setGridMaxWidth',
        ],
        'starfield_background' => [
            'label'  => 'Starfield',
            'detail' => 'Starfield behind the dashboard.',
            'icon'   => 'sun',
            'get'    => 'getStarfield',
            'set'    => 'setStarfield',
        ],
    ];

    /**
     * @return list<array{key: string, label: string, detail: string, icon: string, enabled: bool}>
     */
    public static function list(PreferenceService $prefs, int $userId): array
    {
        $out = [];
        foreach (self::FLAGS as $key => $flag) {
            $getter = $flag['get'];
            $out[] = [
                'key'     => $key,
                'label'   => $flag['label'],
                'detail'  => $flag['detail'],
                'icon'    => $flag['icon'],
                'enabled' => (bool) $prefs->{$getter}($userId),
            ];
        }

        return $out;
    }

    public static function set(PreferenceService $prefs, int $userId, string $key, bool $enabled): void
    {
        if (isset(self::skipped()[$key])) {
            throw ValidationException::withFields(['key' => self::skipped()[$key]]);
        }
        $flag = self::FLAGS[$key] ?? null;
        if ($flag === null) {
            throw ValidationException::withFields(['key' => 'Unknown feature.']);
        }
        $setter = $flag['set'];
        $prefs->{$setter}($userId, $enabled);
    }

    /**
     * @return array<string, string>
     */
    public static function skipped(): array
    {
        return [
            'allow_plain_http' => 'MCP plain HTTP is a server security setting and stays off the dashboard.',
            'mcp_updates'      => 'Allow MCP updates is a deploy setting and stays off the dashboard.',
            'admin'            => 'Admin access is not a dashboard feature.',
            'system:deploy'    => 'system:deploy is a token scope, not a dashboard feature.',
            'system:read'      => 'system:read is a token scope, not a dashboard feature.',
            'spotify:playback' => 'spotify:playback is a token scope, not a dashboard feature.',
        ];
    }
}
