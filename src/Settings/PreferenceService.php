<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\Clock\AlarmSoundService;
use Dashboard\DB\Database;

final class PreferenceService
{
    public const KEY_SEARCH_ENGINE = 'search_engine';
    public const KEY_WIDGET_VISIBILITY = 'widget_visibility';
    public const KEY_WIDGET_ORDER = 'widget_order';
    public const KEY_THEME = 'theme';
    public const KEY_STARFIELD = 'starfield_background';
    public const KEY_WIDGET_HEIGHT = 'widget_height';
    public const KEY_WIDGET_COLUMNS = 'widget_columns';
    public const KEY_CLOCK_FORMAT = 'clock_format';
    public const KEY_SHOW_BROADCAST_WEEK = 'show_broadcast_week';
    public const KEY_FOCUS_CLOCK = FocusClockPreference::KEY;

    public const WIDGET_HEIGHT_DEFAULT = 320;
    public const WIDGET_HEIGHT_MIN = 200;
    public const WIDGET_HEIGHT_MAX = 600;

    /** @var list<string> */
    public const WIDGET_COLUMNS = ['auto', '1', '2', '3', '4', '5', '6'];

    public const WIDGET_COLUMNS_DEFAULT = 'auto';

    public const WIDGET_COLUMNS_MAX = 6;

    public const KEY_DEFAULTS_SEEDED_FLIGHTS = 'defaults_seeded_flights';
    public const KEY_DEFAULTS_SEEDED_STOCKS = 'defaults_seeded_stocks';
    public const KEY_DEFAULTS_SEEDED_PINGS = 'defaults_seeded_pings';
    public const KEY_DEFAULTS_SEEDED_CLIPBOARDS = 'defaults_seeded_clipboards';
    public const KEY_DEFAULTS_SEEDED_PACKAGES = 'defaults_seeded_packages';
    public const KEY_DEFAULTS_SEEDED_TODOS   = 'defaults_seeded_todos';
    public const KEY_TODO_WIDGET_LIST_ID      = 'todo_widget_list_id';
    public const KEY_SHOW_BOOKMARK_ICONS      = 'show_bookmark_icons';

    /** @var array<string, string> widget id => user_preferences.pref_key */
    public const WIDGET_DEFAULTS_SEEDED_KEYS = [
        'flights'    => self::KEY_DEFAULTS_SEEDED_FLIGHTS,
        'stocks'     => self::KEY_DEFAULTS_SEEDED_STOCKS,
        'pings'      => self::KEY_DEFAULTS_SEEDED_PINGS,
        'clipboards' => self::KEY_DEFAULTS_SEEDED_CLIPBOARDS,
        'packages'   => self::KEY_DEFAULTS_SEEDED_PACKAGES,
        'todos'      => self::KEY_DEFAULTS_SEEDED_TODOS,
    ];

    /** @var list<string> */
    public const SEARCH_ENGINES = ['google', 'ddg', 'bing', 'grok', 'claude'];

    /**
     * @return array<string, array{label: string, name: string, url: string, param: string}>
     */
    public static function searchEngineDefinitions(): array
    {
        return [
            'google' => [
                'label' => 'G',
                'name'  => 'Google',
                'url'   => 'https://www.google.com/search',
                'param' => 'q',
            ],
            'ddg' => [
                'label' => 'DDG',
                'name'  => 'DuckDuckGo',
                'url'   => 'https://duckduckgo.com/',
                'param' => 'q',
            ],
            'bing' => [
                'label' => 'Bing',
                'name'  => 'Bing',
                'url'   => 'https://www.bing.com/search',
                'param' => 'q',
            ],
            'grok' => [
                'label' => 'Grok',
                'name'  => 'Grok',
                'url'   => 'https://grok.com/',
                'param' => 'q',
            ],
            'claude' => [
                'label' => 'Claude',
                'name'  => 'Claude',
                'url'   => 'https://claude.ai/new',
                'param' => 'q',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, name: string, url: string, param: string}>
     */
    public static function searchEnginesForUi(): array
    {
        $defs = self::searchEngineDefinitions();
        $out  = [];
        foreach (self::SEARCH_ENGINES as $key) {
            $out[$key] = $defs[$key];
        }

        return $out;
    }

    /** @var list<string> */
    public const THEMES = ['light', 'dark', 'system'];

    /** @var list<string> */
    public const CLOCK_FORMATS = ['12h', '24h'];

    /** @var list<string> Reorderable dashboard card grid (excludes clipboards, news). */
    public const GRID_WIDGET_IDS = ['bookmarks', 'flights', 'packages', 'pings', 'stocks', 'todos', 'weather'];

    /** @var array<string, string> */
    public const WIDGET_LABELS = [
        'bookmarks'   => 'Bookmarks',
        'flights'     => 'Flights (ADS-B)',
        'packages'    => 'Package tracking',
        'pings'       => 'System status',
        'stocks'      => 'Stocks',
        'todos'       => 'Todo lists',
        'weather'     => 'Weather',
        'clipboards'  => 'Cloud clipboards',
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * @return array<string, string>
     */
    public function all(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ?',
            [$userId]
        );

        $prefs = [];
        foreach ($rows as $row) {
            $prefs[(string) $row['pref_key']] = (string) $row['pref_value'];
        }

        return $prefs;
    }

    public function get(int $userId, string $key, ?string $default = null): ?string
    {
        $value = $this->db->fetchScalar(
            'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, $key]
        );

        return $value !== null ? (string) $value : $default;
    }

    public function set(int $userId, string $key, string $value): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, $key]
        );

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE user_preferences SET pref_value = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = ? AND pref_key = ?',
                [$value, $userId, $key]
            );
            return;
        }

        $this->db->execute(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)',
            [$userId, $key, $value]
        );
    }

    public function getSearchEngine(int $userId): string
    {
        $engine = $this->get($userId, self::KEY_SEARCH_ENGINE, 'google') ?? 'google';

        return in_array($engine, self::SEARCH_ENGINES, true) ? $engine : 'google';
    }

    public function setSearchEngine(int $userId, string $engine): void
    {
        if (!in_array($engine, self::SEARCH_ENGINES, true)) {
            throw new \InvalidArgumentException('Invalid search engine.');
        }

        $this->set($userId, self::KEY_SEARCH_ENGINE, $engine);
    }

    public function getTheme(int $userId): string
    {
        $theme = $this->get($userId, self::KEY_THEME, 'system') ?? 'system';

        return in_array($theme, self::THEMES, true) ? $theme : 'system';
    }

    public function setTheme(int $userId, string $theme): void
    {
        if (!in_array($theme, self::THEMES, true)) {
            throw new \InvalidArgumentException('Invalid theme.');
        }

        $this->set($userId, self::KEY_THEME, $theme);
    }

    public function getClockFormat(int $userId): string
    {
        $format = $this->get($userId, self::KEY_CLOCK_FORMAT, '12h') ?? '12h';

        return in_array($format, self::CLOCK_FORMATS, true) ? $format : '12h';
    }

    public function setClockFormat(int $userId, string $format): void
    {
        if (!in_array($format, self::CLOCK_FORMATS, true)) {
            throw new \InvalidArgumentException('Invalid clock format.');
        }

        $this->set($userId, self::KEY_CLOCK_FORMAT, $format);
    }

    public function getShowBroadcastWeek(int $userId): bool
    {
        $raw = $this->get($userId, self::KEY_SHOW_BROADCAST_WEEK, '0') ?? '0';

        return $raw === '1' || $raw === 'true';
    }

    public function setShowBroadcastWeek(int $userId, bool $enabled): void
    {
        $this->set($userId, self::KEY_SHOW_BROADCAST_WEEK, $enabled ? '1' : '0');
    }

    public function getShowBookmarkIcons(int $userId): bool
    {
        $raw = $this->get($userId, self::KEY_SHOW_BOOKMARK_ICONS, '1') ?? '1';

        return $raw === '1' || $raw === 'true';
    }

    public function setShowBookmarkIcons(int $userId, bool $enabled): void
    {
        $this->set($userId, self::KEY_SHOW_BOOKMARK_ICONS, $enabled ? '1' : '0');
    }

    /**
     * @return array<string, mixed>
     */
    public function getFocusClock(int $userId): array
    {
        $raw = $this->get($userId, self::KEY_FOCUS_CLOCK);

        $merged = FocusClockPreference::merge(
            FocusClockPreference::decode($raw),
            $this->getClockFormat($userId),
        );

        return $this->normalizeFocusClockAlarmSound($userId, $merged);
    }

    /**
     * @param array<string, mixed> $prefs
     */
    public function setFocusClock(int $userId, array $prefs): void
    {
        $merged = FocusClockPreference::merge($prefs, $this->getClockFormat($userId));
        $merged = $this->normalizeFocusClockAlarmSound($userId, $merged);
        $this->set($userId, self::KEY_FOCUS_CLOCK, FocusClockPreference::encode($merged));
    }

    /**
     * @param array<string, mixed> $prefs
     * @return array<string, mixed>
     */
    private function normalizeFocusClockAlarmSound(int $userId, array $prefs): array
    {
        if (!isset($prefs['alarm']) || !is_array($prefs['alarm'])) {
            return $prefs;
        }

        $sound = (string) ($prefs['alarm']['sound'] ?? 'builtin');
        $prefs['alarm']['sound'] = (new AlarmSoundService($this->db))->normalizeSoundKey($sound, $userId);

        return $prefs;
    }

    /**
     * @param array<string, mixed> $post
     */
    public function setFocusClockFromPost(int $userId, array $post): void
    {
        $this->setFocusClock(
            $userId,
            FocusClockPreference::fromPost($post, $this->getClockFormat($userId)),
        );
    }

    public function getStarfield(int $userId): bool
    {
        $raw = $this->get($userId, self::KEY_STARFIELD, '0') ?? '0';

        return $raw === '1' || $raw === 'true';
    }

    public function setStarfield(int $userId, bool $enabled): void
    {
        $this->set($userId, self::KEY_STARFIELD, $enabled ? '1' : '0');
    }

    public function getWidgetHeight(int $userId): int
    {
        $raw = $this->get($userId, self::KEY_WIDGET_HEIGHT);

        if ($raw === null || $raw === '') {
            return self::WIDGET_HEIGHT_DEFAULT;
        }

        return max(
            self::WIDGET_HEIGHT_MIN,
            min(self::WIDGET_HEIGHT_MAX, (int) $raw),
        );
    }

    public function setWidgetHeight(int $userId, int $height): void
    {
        $height = max(
            self::WIDGET_HEIGHT_MIN,
            min(self::WIDGET_HEIGHT_MAX, $height),
        );

        $this->set($userId, self::KEY_WIDGET_HEIGHT, (string) $height);
    }

    public function resetWidgetHeight(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::KEY_WIDGET_HEIGHT],
        );
    }

    public function getWidgetColumns(int $userId): string
    {
        $columns = $this->get($userId, self::KEY_WIDGET_COLUMNS, self::WIDGET_COLUMNS_DEFAULT)
            ?? self::WIDGET_COLUMNS_DEFAULT;

        return in_array($columns, self::WIDGET_COLUMNS, true) ? $columns : self::WIDGET_COLUMNS_DEFAULT;
    }

    public function setWidgetColumns(int $userId, string $columns): void
    {
        if (!in_array($columns, self::WIDGET_COLUMNS, true)) {
            throw new \InvalidArgumentException('Invalid widget column count.');
        }

        $this->set($userId, self::KEY_WIDGET_COLUMNS, $columns);
    }

    public function countVisibleGridWidgets(int $userId): int
    {
        $visibility = $this->getWidgetVisibility($userId);
        $count      = 0;

        foreach (self::GRID_WIDGET_IDS as $id) {
            if ($visibility[$id] ?? true) {
                $count++;
            }
        }

        return $count;
    }

    public function resolveWidgetColumnCount(int $userId): int
    {
        $visible = $this->countVisibleGridWidgets($userId);

        if ($visible === 0) {
            return 1;
        }

        $pref = $this->getWidgetColumns($userId);

        if ($pref === 'auto') {
            return max(1, min(self::WIDGET_COLUMNS_MAX, $visible));
        }

        return max(1, min(self::WIDGET_COLUMNS_MAX, (int) $pref));
    }

    /**
     * @return array<string, bool>
     */
    public function getWidgetVisibility(int $userId): array
    {
        $defaults = $this->defaultWidgetVisibility();
        $raw      = $this->get($userId, self::KEY_WIDGET_VISIBILITY);

        if ($raw === null || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        foreach ($defaults as $key => $defaultOn) {
            if (array_key_exists($key, $decoded)) {
                $defaults[$key] = (bool) $decoded[$key];
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, bool> $visibility
     */
    public function setWidgetVisibility(int $userId, array $visibility): void
    {
        $merged = $this->defaultWidgetVisibility();

        foreach ($merged as $key => $_) {
            if (array_key_exists($key, $visibility)) {
                $merged[$key] = (bool) $visibility[$key];
            }
        }

        $json = json_encode($merged, JSON_THROW_ON_ERROR);
        $this->set($userId, self::KEY_WIDGET_VISIBILITY, $json);
    }

    public function isWidgetVisible(int $userId, string $widget): bool
    {
        $visibility = $this->getWidgetVisibility($userId);

        return $visibility[$widget] ?? true;
    }

    /**
     * @return list<string>
     */
    public function defaultWidgetOrder(): array
    {
        return self::GRID_WIDGET_IDS;
    }

    /**
     * @return list<string>
     */
    public function getWidgetOrder(int $userId): array
    {
        $order   = [];
        $raw     = $this->get($userId, self::KEY_WIDGET_ORDER);
        $allowed = array_flip(self::GRID_WIDGET_IDS);

        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    if (!is_string($id) || !isset($allowed[$id])) {
                        continue;
                    }
                    if (!in_array($id, $order, true)) {
                        $order[] = $id;
                    }
                }
            }
        }

        foreach (self::GRID_WIDGET_IDS as $id) {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    /**
     * @param list<string> $order
     */
    public function setWidgetOrder(int $userId, array $order): void
    {
        $normalized = [];
        $allowed    = array_flip(self::GRID_WIDGET_IDS);

        foreach ($order as $id) {
            if (!is_string($id) || !isset($allowed[$id])) {
                throw new \InvalidArgumentException('Invalid widget id in order: ' . (string) $id);
            }
            if (in_array($id, $normalized, true)) {
                throw new \InvalidArgumentException('Duplicate widget id in order: ' . $id);
            }
            $normalized[] = $id;
        }

        if (count($normalized) !== count(self::GRID_WIDGET_IDS)) {
            throw new \InvalidArgumentException(
                'Widget order must include exactly ' . count(self::GRID_WIDGET_IDS) . ' grid widgets.',
            );
        }

        $json = json_encode($normalized, JSON_THROW_ON_ERROR);
        $this->set($userId, self::KEY_WIDGET_ORDER, $json);
    }

    /**
     * @return array<string, bool>
     */
    public function defaultWidgetVisibility(): array
    {
        $out = [];
        foreach (array_keys(self::WIDGET_LABELS) as $key) {
            $out[$key] = true;
        }

        return $out;
    }

    public function hasWidgetDefaultsSeeded(int $userId, string $widget): bool
    {
        $key = self::WIDGET_DEFAULTS_SEEDED_KEYS[$widget] ?? null;
        if ($key === null) {
            throw new \InvalidArgumentException("Unknown widget for default seed: {$widget}");
        }

        return $this->get($userId, $key) === '1';
    }

    public function markWidgetDefaultsSeeded(int $userId, string $widget): void
    {
        $key = self::WIDGET_DEFAULTS_SEEDED_KEYS[$widget] ?? null;
        if ($key === null) {
            throw new \InvalidArgumentException("Unknown widget for default seed: {$widget}");
        }

        $this->set($userId, $key, '1');
    }

    /**
     * Mark all widget default seeds as done without inserting rows (existing accounts).
     */
    public function markAllWidgetDefaultsSeeded(int $userId): void
    {
        foreach (array_keys(self::WIDGET_DEFAULTS_SEEDED_KEYS) as $widget) {
            $this->markWidgetDefaultsSeeded($userId, $widget);
        }
    }
}
