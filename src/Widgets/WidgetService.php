<?php

declare(strict_types=1);

namespace Dashboard\Widgets;

use Dashboard\DB\Database;
use Dashboard\Settings\FeedPreferenceService;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\MetricService;

/**
 * WidgetService — the dashboard grid.
 *
 * - Title/config changes bump `version` and append a widget_revisions row, so
 *   any change (including AI edits in L2) can be rolled back.
 * - Position changes (drag/resize) do not create revisions.
 * - The first dashboard load after upgrading seeds the grid from the legacy
 *   personal-dashboard preferences (visible widgets, their order).
 */
final class WidgetService
{
    public const COLUMNS = 12;
    /** Display columns above the canonical 12. Positions for these live in user preferences. */
    public const WIDE_COLUMNS = [18, 24];
    public const COLUMN_MAX = 24;
    /** Viewport widths that switch column count. Hysteresis keeps a drag from flipping back. */
    public const BREAK_NARROW = 700;
    public const BREAK_WIDE = 1600;
    public const BREAK_ULTRA = 3440;
    public const BREAK_HYSTERESIS = 120;
    /**
     * Fluid columns stop growing at these widths (px). The block is then
     * centered. Keys are the column counts from columnsForWidth().
     *
     * @var array<int, int>
     */
    public const GRID_MAX_WIDTH = [
        12 => 1600,
        18 => 2400,
        24 => 3200,
    ];
    public const MAX_WIDGETS = 60;
    public const BOARD = 'home';
    public const CHANGERS = ['user', 'ai', 'system'];
    /** One-time narrow of unlocked Weather / Goals / Mood / Todos that still use a previous default width. */
    public const COMPACT_PREF = 'layout_compact_row_v1';
    public const COMPACT_WIDTH = 3;
    /** Grid row in px. Was 64; existing card heights are doubled once so a locked card keeps its pixel size. */
    public const CELL_HEIGHT = 32;
    public const PREV_CELL_HEIGHT = 64;
    private const SEEDED_PREF = 'dashboard_grid_seeded';
    /** Catalog and seed widths from before the 3-column top row. A hand resize sets height_locked and is left alone. */
    private const COMPACT_OLD_WIDTHS = [
        'legacy.weather' => [4, 6],
        'goal'           => [4, 6],
        'mood'           => [4, 6],
        'legacy.todos'   => [4, 6],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly MetricService $metrics,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId, string $board = self::BOARD): array
    {
        return array_map(
            [self::class, 'format'],
            $this->db->fetchAll(
                'SELECT * FROM dashboard_widgets WHERE user_id = ? AND board = ? ORDER BY grid_y, grid_x, id',
                [$userId, $board]
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $widgetId): array
    {
        $row = $this->db->fetchOne('SELECT * FROM dashboard_widgets WHERE id = ? AND user_id = ?', [$widgetId, $userId]);
        if ($row === null) {
            throw new NotFoundException('Widget not found.');
        }
        return self::format($row);
    }

    /**
     * @param array<string, mixed>          $config
     * @param array{x?: int, y?: int, w?: int, h?: int}|null $pos
     * @return array<string, mixed>
     */
    public function create(int $userId, string $type, ?string $title, array $config, ?array $pos = null, string $createdBy = 'user', string $board = self::BOARD): array
    {
        self::checkChanger($createdBy);
        $def    = WidgetTypeRegistry::get($type);
        if (!empty($def['floating'])) {
            throw ValidationException::withFields(['type' => 'The Spotify player floats above the page. Turn it on from the widget list or Settings, Spotify.']);
        }
        $config = $this->validate($userId, $type, $config);
        $title  = self::cleanTitle($title);

        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM dashboard_widgets WHERE user_id = ? AND board = ?', [$userId, $board]);
        if ($count >= self::MAX_WIDGETS) {
            throw new ValidationException('Too many widgets on this board (max ' . self::MAX_WIDGETS . ').');
        }
        if (!empty($def['singleton'])) {
            $exists = $this->db->fetchScalar(
                'SELECT 1 FROM dashboard_widgets WHERE user_id = ? AND board = ? AND type = ?',
                [$userId, $board, $type]
            );
            if ($exists !== null) {
                throw ValidationException::withFields(['type' => "{$def['label']} is already on the dashboard."]);
            }
        }

        $w = self::clampInt($pos['w'] ?? $def['size']['w'], max(1, $def['min']['w'] ?? 1), self::COLUMNS);
        $h = self::clampInt($pos['h'] ?? $def['size']['h'], max(1, $def['min']['h'] ?? 1), 24);
        if (isset($pos['x'], $pos['y'])) {
            $x = self::clampInt($pos['x'], 0, self::COLUMNS - $w);
            $y = self::clampInt($pos['y'], 0, 10000);
        } else {
            [$x, $y] = $this->nextSlot($userId, $board, $w);
        }

        return $this->db->transaction(function (Database $db) use ($userId, $board, $type, $title, $config, $x, $y, $w, $h, $createdBy): array {
            $id = (int) $db->fetchScalar(
                'INSERT INTO dashboard_widgets (user_id, board, type, title, config, version, grid_x, grid_y, grid_w, grid_h, created_by)
                 VALUES (?, ?, ?, ?, ?::jsonb, 1, ?, ?, ?, ?, ?) RETURNING id',
                [$userId, $board, $type, $title, self::json($config), $x, $y, $w, $h, $createdBy]
            );
            $db->execute(
                'INSERT INTO widget_revisions (widget_id, version, title, config, changed_by, note) VALUES (?, 1, ?, ?::jsonb, ?, ?)',
                [$id, $title, self::json($config), $createdBy, 'created']
            );
            return $this->get($userId, $id);
        });
    }

    /**
     * Change title and/or config. Creates a new revision when anything changed.
     *
     * @param array{title?: ?string, config?: array<string, mixed>} $changes
     * @return array<string, mixed>
     */
    public function update(int $userId, int $widgetId, array $changes, string $changedBy = 'user', ?string $note = null): array
    {
        self::checkChanger($changedBy);
        $widget = $this->get($userId, $widgetId);

        $title  = array_key_exists('title', $changes) ? self::cleanTitle($changes['title']) : $widget['title'];
        $config = $widget['config'];
        if (array_key_exists('config', $changes)) {
            if (!is_array($changes['config'])) {
                throw ValidationException::withFields(['config' => 'Config must be an object.']);
            }
            $config = $this->validate($userId, (string) $widget['type'], $changes['config']);
        }
        if ($title === $widget['title'] && $config == $widget['config']) {
            return $widget; // nothing to record
        }

        return $this->writeVersion($userId, $widget, $title, $config, $changedBy, $note);
    }

    /**
     * Restore an earlier revision (recorded as a new version, so rollbacks are undoable too).
     *
     * @return array<string, mixed>
     */
    public function rollback(int $userId, int $widgetId, int $version, string $changedBy = 'user'): array
    {
        self::checkChanger($changedBy);
        $widget = $this->get($userId, $widgetId);
        $rev = $this->db->fetchOne(
            'SELECT * FROM widget_revisions WHERE widget_id = ? AND version = ?',
            [$widgetId, $version]
        );
        if ($rev === null) {
            throw new NotFoundException("Version {$version} not found.");
        }
        $config = json_decode((string) $rev['config'], true) ?: [];
        // Metrics may have been removed since; re-validate against today's data.
        try {
            $config = $this->validate($userId, (string) $widget['type'], $config);
        } catch (ValidationException $e) {
            throw new ValidationException("Version {$version} can no longer be restored: " . $e->getMessage(), $e->fields());
        }
        return $this->writeVersion($userId, $widget, $rev['title'] !== null ? (string) $rev['title'] : null, $config, $changedBy, "rollback to v{$version}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function revisions(int $userId, int $widgetId, int $limit = 50): array
    {
        $this->get($userId, $widgetId);
        $limit = max(1, min(200, $limit));
        $rows = $this->db->fetchAll(
            "SELECT * FROM widget_revisions WHERE widget_id = ? ORDER BY version DESC LIMIT {$limit}",
            [$widgetId]
        );
        return array_map(static fn (array $r): array => [
            'version'    => (int) $r['version'],
            'title'      => $r['title'] !== null ? (string) $r['title'] : null,
            'config'     => json_decode((string) $r['config'], true) ?: [],
            'changed_by' => (string) $r['changed_by'],
            'note'       => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    public function delete(int $userId, int $widgetId): void
    {
        if ($this->db->execute('DELETE FROM dashboard_widgets WHERE id = ? AND user_id = ?', [$widgetId, $userId]) === 0) {
            throw new NotFoundException('Widget not found.');
        }
    }

    /**
     * Save grid positions for many widgets at once (one drag can move several).
     *
     * @param list<array{id: mixed, x: mixed, y: mixed, w: mixed, h: mixed, height_locked?: mixed}> $items
     */
    public function saveLayout(int $userId, array $items): int
    {
        if (count($items) > self::MAX_WIDGETS) {
            throw new ValidationException('Too many layout items.');
        }
        $clean = [];
        foreach ($items as $i => $it) {
            if (!is_array($it)) {
                throw ValidationException::withFields(["items.{$i}" => 'Invalid item.']);
            }
            $id = filter_var($it['id'] ?? null, FILTER_VALIDATE_INT);
            $w  = filter_var($it['w'] ?? null, FILTER_VALIDATE_INT);
            $h  = filter_var($it['h'] ?? null, FILTER_VALIDATE_INT);
            $x  = filter_var($it['x'] ?? null, FILTER_VALIDATE_INT);
            $y  = filter_var($it['y'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $w === false || $h === false || $x === false || $y === false
                || $w < 1 || $w > self::COLUMNS || $h < 1 || $h > 24 || $x < 0 || $x + $w > self::COLUMNS || $y < 0 || $y > 10000) {
                throw ValidationException::withFields(["items.{$i}" => 'Position out of range (12-column grid, height 1–24).']);
            }
            $locked = null;
            if (array_key_exists('height_locked', $it)) {
                $flag = filter_var($it['height_locked'], FILTER_VALIDATE_INT);
                if ($flag !== 0 && $flag !== 1) {
                    throw ValidationException::withFields(["items.{$i}.height_locked" => 'height_locked must be 0 or 1.']);
                }
                $locked = $flag;
            }
            $placed = null;
            if (array_key_exists('placement_locked', $it)) {
                $flag = filter_var($it['placement_locked'], FILTER_VALIDATE_INT);
                if ($flag !== 0 && $flag !== 1) {
                    throw ValidationException::withFields(["items.{$i}.placement_locked" => 'placement_locked must be 0 or 1.']);
                }
                $placed = $flag;
            }
            $clean[] = [$id, $x, $y, $w, $h, $locked, $placed];
        }

        return $this->db->transaction(function (Database $db) use ($userId, $clean): int {
            $n = 0;
            $geometry = false;
            foreach ($clean as [$id, $x, $y, $w, $h, $locked, $placed]) {
                $prev = $db->fetchOne(
                    'SELECT grid_x, grid_y, grid_w, grid_h, height_locked, placement_locked FROM dashboard_widgets WHERE id = ? AND user_id = ?',
                    [$id, $userId]
                );
                if ($prev !== null) {
                    $rect = self::heldRect(
                        (int) $prev['placement_locked'] === 1,
                        $placed,
                        [
                            'x' => (int) $prev['grid_x'],
                            'y' => (int) $prev['grid_y'],
                            'w' => (int) $prev['grid_w'],
                            'h' => (int) $prev['grid_h'],
                        ],
                        ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h]
                    );
                    $x = $rect['x'];
                    $y = $rect['y'];
                    $w = $rect['w'];
                    $h = $rect['h'];
                    if ((int) $prev['grid_x'] !== $x || (int) $prev['grid_y'] !== $y || (int) $prev['grid_w'] !== $w) {
                        $geometry = true;
                    }
                }
                if ($locked === null && $placed === null) {
                    $n += $db->execute(
                        "UPDATE dashboard_widgets SET grid_x = ?, grid_y = ?, grid_w = ?, grid_h = ?, updated_at = (now() AT TIME ZONE 'UTC')
                         WHERE id = ? AND user_id = ? AND (grid_x, grid_y, grid_w, grid_h) IS DISTINCT FROM (?, ?, ?, ?)",
                        [$x, $y, $w, $h, $id, $userId, $x, $y, $w, $h]
                    );
                    continue;
                }
                if ($placed === null) {
                    $n += $db->execute(
                        "UPDATE dashboard_widgets SET grid_x = ?, grid_y = ?, grid_w = ?, grid_h = ?, height_locked = ?, updated_at = (now() AT TIME ZONE 'UTC')
                         WHERE id = ? AND user_id = ? AND (grid_x, grid_y, grid_w, grid_h, height_locked) IS DISTINCT FROM (?, ?, ?, ?, ?)",
                        [$x, $y, $w, $h, $locked ?? 0, $id, $userId, $x, $y, $w, $h, $locked ?? 0]
                    );
                    continue;
                }
                $heightFlag = $locked !== null ? $locked : (int) ($prev['height_locked'] ?? 0);
                $n += $db->execute(
                    "UPDATE dashboard_widgets SET grid_x = ?, grid_y = ?, grid_w = ?, grid_h = ?, height_locked = ?, placement_locked = ?, updated_at = (now() AT TIME ZONE 'UTC')
                     WHERE id = ? AND user_id = ? AND (grid_x, grid_y, grid_w, grid_h, height_locked, placement_locked) IS DISTINCT FROM (?, ?, ?, ?, ?, ?)",
                    [$x, $y, $w, $h, $heightFlag, $placed, $id, $userId, $x, $y, $w, $h, $heightFlag, $placed]
                );
            }
            // A real 12-column move makes a saved wide arrangement stale. Height-only
            // writes (auto-fit) leave those arrangements in place.
            if ($geometry) {
                self::clearWideLayouts($db, $userId);
            }
            return $n;
        });
    }

    /**
     * Persist only row heights. Used when a wide screen auto-fits, so the
     * 12-column x/y/w and any saved wide arrangement stay as they are.
     *
     * @param list<array{id: mixed, h: mixed, height_locked?: mixed}> $items
     */
    public function saveHeights(int $userId, array $items): int
    {
        if (count($items) > self::MAX_WIDGETS) {
            throw new ValidationException('Too many layout items.');
        }
        $clean = [];
        foreach ($items as $i => $it) {
            if (!is_array($it)) {
                throw ValidationException::withFields(["items.{$i}" => 'Invalid item.']);
            }
            $id = filter_var($it['id'] ?? null, FILTER_VALIDATE_INT);
            $h  = filter_var($it['h'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $h === false || $h < 1 || $h > 24) {
                throw ValidationException::withFields(["items.{$i}" => 'Height out of range (1–24).']);
            }
            $locked = null;
            if (array_key_exists('height_locked', $it)) {
                $flag = filter_var($it['height_locked'], FILTER_VALIDATE_INT);
                if ($flag !== 0 && $flag !== 1) {
                    throw ValidationException::withFields(["items.{$i}.height_locked" => 'height_locked must be 0 or 1.']);
                }
                $locked = $flag;
            }
            $clean[] = [$id, $h, $locked];
        }

        return $this->db->transaction(function (Database $db) use ($userId, $clean): int {
            $n = 0;
            foreach ($clean as [$id, $h, $locked]) {
                $pin = $db->fetchScalar(
                    'SELECT placement_locked FROM dashboard_widgets WHERE id = ? AND user_id = ?',
                    [$id, $userId]
                );
                if ($pin === null || (int) $pin === 1) {
                    continue;
                }
                if ($locked === null) {
                    $n += $db->execute(
                        "UPDATE dashboard_widgets SET grid_h = ?, updated_at = (now() AT TIME ZONE 'UTC')
                         WHERE id = ? AND user_id = ? AND grid_h IS DISTINCT FROM ?",
                        [$h, $id, $userId, $h]
                    );
                    continue;
                }
                $n += $db->execute(
                    "UPDATE dashboard_widgets SET grid_h = ?, height_locked = ?, updated_at = (now() AT TIME ZONE 'UTC')
                     WHERE id = ? AND user_id = ? AND (grid_h, height_locked) IS DISTINCT FROM (?, ?)",
                    [$h, $locked, $id, $userId, $h, $locked]
                );
            }
            return $n;
        });
    }

    public static function layoutPrefKey(int $columns): string
    {
        return 'dashboard_layout_v2_' . $columns;
    }

    /**
     * Column count for a viewport width. `$previous` is the count already on
     * screen; within {@see BREAK_HYSTERESIS} px of a boundary it stays put so a
     * resize does not re-pack until the pointer has clearly crossed.
     */
    public static function columnsForWidth(int $width, ?int $previous = null): int
    {
        $narrow = self::BREAK_NARROW;
        $wide = self::BREAK_WIDE;
        $ultra = self::BREAK_ULTRA;
        $h = self::BREAK_HYSTERESIS;
        if ($previous === 1 && $width < $narrow + $h) {
            return 1;
        }
        if ($previous === 24 && $width >= $ultra - $h) {
            return 24;
        }
        if ($previous === 18 && $width < $ultra && $width >= $wide - $h) {
            return 18;
        }
        if ($previous === self::COLUMNS && $width > $narrow && $width < $wide) {
            return self::COLUMNS;
        }
        if ($width <= $narrow) {
            return 1;
        }
        if ($width < $wide) {
            return self::COLUMNS;
        }
        if ($width < $ultra) {
            return 18;
        }
        return 24;
    }

    /**
     * Saved drag/resize for one wide breakpoint. Missing or unreadable prefs
     * come back as an empty list so the client projects from the 12-column layout.
     *
     * @return array{18: list<array{id: int, x: int, y: int, w: int, h: int}>, 24: list<array{id: int, x: int, y: int, w: int, h: int}>}
     */
    public function breakpointLayouts(int $userId): array
    {
        $keys = [];
        foreach (self::WIDE_COLUMNS as $columns) {
            $keys[self::layoutPrefKey($columns)] = $columns;
        }
        $rows = $this->db->fetchAll(
            'SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ? AND pref_key IN ('
            . implode(', ', array_fill(0, count($keys), '?')) . ')',
            array_merge([$userId], array_keys($keys))
        );
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row['pref_key']] = (string) $row['pref_value'];
        }
        $out = [];
        foreach (self::WIDE_COLUMNS as $columns) {
            $out[(string) $columns] = self::decodeLayout($byKey[self::layoutPrefKey($columns)] ?? null, $columns);
        }
        return $out;
    }

    /**
     * Remember a wide-screen arrangement. Canonical grid_x/grid_w are not written
     * (the 12-column check constraint would reject them).
     *
     * @param list<array{id: mixed, x: mixed, y: mixed, w: mixed, h: mixed}> $items
     */
    public function saveBreakpointLayout(int $userId, int $columns, array $items): void
    {
        if (!in_array($columns, self::WIDE_COLUMNS, true)) {
            throw ValidationException::withFields(['columns' => 'columns must be 18 or 24.']);
        }
        if (count($items) > self::MAX_WIDGETS) {
            throw new ValidationException('Too many layout items.');
        }
        $clean = [];
        foreach ($items as $i => $it) {
            if (!is_array($it)) {
                throw ValidationException::withFields(["items.{$i}" => 'Invalid item.']);
            }
            $id = filter_var($it['id'] ?? null, FILTER_VALIDATE_INT);
            $w  = filter_var($it['w'] ?? null, FILTER_VALIDATE_INT);
            $h  = filter_var($it['h'] ?? null, FILTER_VALIDATE_INT);
            $x  = filter_var($it['x'] ?? null, FILTER_VALIDATE_INT);
            $y  = filter_var($it['y'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $w === false || $h === false || $x === false || $y === false
                || $w < 1 || $w > $columns || $h < 1 || $h > 24 || $x < 0 || $x + $w > $columns || $y < 0 || $y > 10000) {
                throw ValidationException::withFields(["items.{$i}" => "Position out of range ({$columns}-column grid, height 1–24)."]);
            }
            $clean[] = ['id' => $id, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        }
        (new PreferenceService($this->db))->set(
            $userId,
            self::layoutPrefKey($columns),
            json_encode($clean, JSON_THROW_ON_ERROR)
        );
    }

    private static function clearWideLayouts(Database $db, int $userId): void
    {
        $keys = array_map([self::class, 'layoutPrefKey'], self::WIDE_COLUMNS);
        $db->execute(
            'DELETE FROM user_preferences WHERE user_id = ? AND pref_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')',
            array_merge([$userId], $keys)
        );
    }

    /**
     * @return list<array{id: int, x: int, y: int, w: int, h: int}>
     */
    private static function decodeLayout(?string $raw, int $columns): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data) || !array_is_list($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $it) {
            if (!is_array($it)) {
                return [];
            }
            $id = filter_var($it['id'] ?? null, FILTER_VALIDATE_INT);
            $x  = filter_var($it['x'] ?? null, FILTER_VALIDATE_INT);
            $y  = filter_var($it['y'] ?? null, FILTER_VALIDATE_INT);
            $w  = filter_var($it['w'] ?? null, FILTER_VALIDATE_INT);
            $h  = filter_var($it['h'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $x === false || $y === false || $w === false || $h === false
                || $w < 1 || $w > $columns || $x < 0 || $x + $w > $columns || $h < 1 || $h > 24 || $y < 0) {
                return [];
            }
            $out[] = ['id' => $id, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        }
        return $out;
    }

    /**
     * Place items in order on a 12-column grid. A row is as tall as its tallest item.
     * Used when a phone reorder is saved back as a desktop layout.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function pack(array $items, int $columns = self::COLUMNS): array
    {
        $fixed = [];
        $flow = [];
        foreach ($items as $it) {
            if ((int) ($it['placement_locked'] ?? 0) === 1 && isset($it['x'], $it['y'])) {
                $row = $it;
                $row['x'] = max(0, (int) $it['x']);
                $row['y'] = max(0, (int) $it['y']);
                $row['w'] = max(1, min($columns, (int) ($it['w'] ?? 1)));
                $row['h'] = max(1, min(24, (int) ($it['h'] ?? 1)));
                $fixed[] = $row;
                continue;
            }
            $flow[] = $it;
        }
        if ($fixed === []) {
            return self::packFlow($items, $columns);
        }
        $placed = $fixed;
        foreach ($flow as $it) {
            $w = max(1, min($columns, (int) ($it['w'] ?? 1)));
            $h = max(1, min(24, (int) ($it['h'] ?? 1)));
            [$x, $y] = self::firstFree($placed, $w, $h, $columns);
            $row = $it;
            $row['x'] = $x;
            $row['y'] = $y;
            $row['w'] = $w;
            $row['h'] = $h;
            $placed[] = $row;
        }
        return $placed;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function packFlow(array $items, int $columns): array
    {
        $x = 0;
        $y = 0;
        $rowH = 0;
        $out = [];
        foreach ($items as $it) {
            $w = max(1, min($columns, (int) ($it['w'] ?? 1)));
            $h = max(1, min(24, (int) ($it['h'] ?? 1)));
            if ($x > 0 && $x + $w > $columns) {
                $y += $rowH;
                $x = 0;
                $rowH = 0;
            }
            $row = $it;
            $row['x'] = $x;
            $row['y'] = $y;
            $row['w'] = $w;
            $row['h'] = $h;
            $out[] = $row;
            $x += $w;
            $rowH = max($rowH, $h);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $placed
     * @return array{0: int, 1: int}
     */
    private static function firstFree(array $placed, int $w, int $h, int $columns): array
    {
        $limit = $h;
        foreach ($placed as $o) {
            $limit = max($limit, (int) $o['y'] + (int) $o['h'] + $h);
        }
        for ($y = 0; $y <= $limit; $y++) {
            for ($x = 0; $x + $w <= $columns; $x++) {
                if (!self::rectHits($x, $y, $w, $h, $placed)) {
                    return [$x, $y];
                }
            }
        }
        return [0, $limit];
    }

    /**
     * A locked widget keeps its stored rectangle until the caller clears the lock.
     *
     * @param array{x: int, y: int, w: int, h: int} $stored
     * @param array{x: int, y: int, w: int, h: int} $incoming
     * @return array{x: int, y: int, w: int, h: int}
     */
    public static function heldRect(bool $storedLock, ?int $requestedLock, array $stored, array $incoming): array
    {
        if ($storedLock && $requestedLock !== 0) {
            return $stored;
        }
        return $incoming;
    }

    /**
     * Pull each widget up into the first row where its column is free.
     * Horizontal position stays, so a card the user put on the right stays there.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function packUp(array $items): array
    {
        $nodes = array_values($items);
        usort($nodes, static function (array $a, array $b): int {
            return [$a['y'] ?? 0, $a['x'] ?? 0, $a['id'] ?? 0] <=> [$b['y'] ?? 0, $b['x'] ?? 0, $b['id'] ?? 0];
        });
        $count = count($nodes);
        for ($i = 0; $i < $count; $i++) {
            if ((int) ($nodes[$i]['placement_locked'] ?? 0) === 1) {
                continue;
            }
            $y = (int) $nodes[$i]['y'];
            $h = (int) $nodes[$i]['h'];
            $x = (int) $nodes[$i]['x'];
            $w = (int) $nodes[$i]['w'];
            while ($y > 0) {
                $trial = $y - 1;
                $hit = false;
                for ($j = 0; $j < $count; $j++) {
                    if ($j === $i) {
                        continue;
                    }
                    $o = $nodes[$j];
                    $ox = (int) $o['x'];
                    $oy = (int) $o['y'];
                    if ($x < $ox + (int) $o['w'] && $x + $w > $ox && $trial < $oy + (int) $o['h'] && $trial + $h > $oy) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    break;
                }
                $y = $trial;
            }
            $nodes[$i]['y'] = $y;
        }
        return $nodes;
    }

    /**
     * Map a 12-column layout onto another column count without reordering.
     *
     * Each card keeps its column count, so a 3-column card stays 3 columns
     * wide (the same pixel width) when the screen grows from 12 to 18 or 24.
     * Cards are placed in their current reading order, and a wider grid fits
     * more of them on each row. A card wider than the grid is clamped. A row
     * that does not fill the grid is centered when that does not cover another
     * card. The 12-column layout is not repacked.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function projectColumns(array $items, int $columns): array
    {
        $columns = max(1, $columns);
        $from = self::COLUMNS;
        $nodes = array_values($items);
        usort($nodes, static function (array $a, array $b): int {
            return [(int) ($a['y'] ?? 0), (int) ($a['x'] ?? 0), (int) ($a['id'] ?? 0)]
                <=> [(int) ($b['y'] ?? 0), (int) ($b['x'] ?? 0), (int) ($b['id'] ?? 0)];
        });
        if ($columns === $from) {
            return self::centerOccupied($nodes, $columns);
        }
        $cursorX = 0;
        $cursorY = 0;
        $rowH = 0;
        foreach ($nodes as $i => $it) {
            $w = max(1, min($columns, (int) ($it['w'] ?? 1)));
            $h = max(1, min(24, (int) ($it['h'] ?? 1)));
            if ($cursorX > 0 && $cursorX + $w > $columns) {
                $cursorY += $rowH;
                $cursorX = 0;
                $rowH = 0;
            }
            $it['x'] = $cursorX;
            $it['y'] = $cursorY;
            $it['w'] = $w;
            $it['h'] = $h;
            $nodes[$i] = $it;
            $cursorX += $w;
            $rowH = max($rowH, $h);
        }
        return self::centerOccupied($nodes, $columns);
    }

    /**
     * Shift each row so the columns it occupies sit in the middle of the grid.
     * Rows that already span the grid stay put. A shift that would overlap a
     * card on another row is skipped.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public static function centerOccupied(array $nodes, int $columns): array
    {
        $columns = max(1, $columns);
        $groups = [];
        foreach ($nodes as $i => $it) {
            $groups[(int) ($it['y'] ?? 0)][] = $i;
        }
        foreach ($groups as $idxs) {
            $min = min(array_map(static fn (int $i): int => (int) $nodes[$i]['x'], $idxs));
            $max = max(array_map(static fn (int $i): int => (int) $nodes[$i]['x'] + (int) $nodes[$i]['w'], $idxs));
            $span = $max - $min;
            if ($span <= 0 || $span >= $columns) {
                continue;
            }
            $offset = intdiv($columns - $span, 2) - $min;
            if ($offset === 0) {
                continue;
            }
            $moved = [];
            foreach ($idxs as $i) {
                $trial = $nodes[$i];
                $trial['x'] = (int) $trial['x'] + $offset;
                $moved[$i] = $trial;
            }
            $hit = false;
            foreach ($nodes as $j => $other) {
                if (isset($moved[$j])) {
                    continue;
                }
                foreach ($moved as $trial) {
                    if (self::rectsOverlap($trial, $other)) {
                        $hit = true;
                        break 2;
                    }
                }
            }
            if ($hit) {
                continue;
            }
            foreach ($moved as $i => $trial) {
                $nodes[$i] = $trial;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function rectsOverlap(array $a, array $b): bool
    {
        return (int) $a['x'] < (int) $b['x'] + (int) $b['w']
            && (int) $a['x'] + (int) $a['w'] > (int) $b['x']
            && (int) $a['y'] < (int) $b['y'] + (int) $b['h']
            && (int) $a['y'] + (int) $a['h'] > (int) $b['y'];
    }

    /**
     * Narrow unlocked Weather, Goals, Mood and Todos cards that still have a
     * previous default width (4 or 6) to 3 columns, then sit those cards in
     * reading order on that row. A resize lock keeps its width and column.
     * Every other card keeps its width and column; packUp only slides cards up.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function refitCompactRow(array $items): array
    {
        $nodes = array_values($items);
        $eligible = [];
        foreach ($nodes as $i => $it) {
            if (self::isCompactCandidate($it)) {
                $eligible[] = $i;
            }
        }
        if ($eligible === []) {
            return self::packUp($nodes);
        }

        $minY = min(array_map(static fn (int $i): int => (int) $nodes[$i]['y'], $eligible));
        usort($eligible, static function (int $a, int $b) use ($nodes): int {
            return [(int) $nodes[$a]['y'], (int) $nodes[$a]['x'], (int) ($nodes[$a]['id'] ?? 0)]
                <=> [(int) $nodes[$b]['y'], (int) $nodes[$b]['x'], (int) ($nodes[$b]['id'] ?? 0)];
        });

        $skip = array_flip($eligible);
        $obstacles = [];
        foreach ($nodes as $i => $it) {
            if (!isset($skip[$i])) {
                $obstacles[] = $it;
            }
        }

        foreach ($eligible as $i) {
            $it = $nodes[$i];
            $h = max(1, (int) $it['h']);
            [$x, $y] = self::firstCompactSlot($minY, self::COMPACT_WIDTH, $h, $obstacles);
            $it['w'] = self::COMPACT_WIDTH;
            $it['x'] = $x;
            $it['y'] = $y;
            $nodes[$i] = $it;
            $obstacles[] = $it;
        }

        // Placement used the old row size. Double every height so 32px rows match the previous 64px rows,
        // then push anything a taller card now covers and pack the gaps.
        $scale = intdiv(self::PREV_CELL_HEIGHT, self::CELL_HEIGHT);
        foreach ($nodes as $i => $it) {
            $nodes[$i]['h'] = max(1, min(24, (int) $it['h'] * $scale));
        }

        return self::packUp(self::pushApart($nodes));
    }

    /**
     * Once per user, after the grid exists. Cards the user has resized stay put.
     */
    public function ensureCompactRow(int $userId): void
    {
        $prefs = new PreferenceService($this->db);
        if ($prefs->get($userId, self::COMPACT_PREF) === '1') {
            return;
        }

        $this->db->transaction(function (Database $db) use ($userId, $prefs): void {
            $db->fetchScalar('SELECT pg_advisory_xact_lock(?, ?)', [0x4c45, $userId]);
            if ($prefs->get($userId, self::COMPACT_PREF) === '1') {
                return;
            }
            $items = $this->list($userId);
            if ($items !== []) {
                $payload = [];
                foreach (self::refitCompactRow($items) as $it) {
                    $payload[] = [
                        'id' => $it['id'],
                        'x' => $it['x'],
                        'y' => $it['y'],
                        'w' => $it['w'],
                        'h' => $it['h'],
                        'height_locked' => (int) ($it['height_locked'] ?? 0),
                    ];
                }
                $this->saveLayout($userId, $payload);
            }
            $prefs->set($userId, self::COMPACT_PREF, '1');
        });
    }

    /**
     * @param array<string, mixed> $it
     */
    private static function isCompactCandidate(array $it): bool
    {
        if ((int) ($it['height_locked'] ?? 0) === 1 || (int) ($it['placement_locked'] ?? 0) === 1) {
            return false;
        }
        $old = self::COMPACT_OLD_WIDTHS[(string) ($it['type'] ?? '')] ?? null;
        return $old !== null && in_array((int) ($it['w'] ?? 0), $old, true);
    }

    /**
     * First 3-column slot at or below $minY that does not touch $obstacles.
     *
     * @param list<array<string, mixed>> $obstacles
     * @return array{0: int, 1: int}
     */
    private static function firstCompactSlot(int $minY, int $w, int $h, array $obstacles): array
    {
        $limit = $minY + $h + 4;
        foreach ($obstacles as $o) {
            $limit = max($limit, (int) $o['y'] + (int) $o['h'] + $h);
        }
        for ($y = max(0, $minY); $y <= $limit; $y++) {
            for ($x = 0; $x + $w <= self::COLUMNS; $x += $w) {
                if (!self::rectHits($x, $y, $w, $h, $obstacles)) {
                    return [$x, $y];
                }
            }
        }
        return [0, $limit];
    }

    /**
     * @param list<array<string, mixed>> $rects
     */
    private static function rectHits(int $x, int $y, int $w, int $h, array $rects): bool
    {
        foreach ($rects as $o) {
            $ox = (int) $o['x'];
            $oy = (int) $o['y'];
            if ($x < $ox + (int) $o['w'] && $x + $w > $ox && $y < $oy + (int) $o['h'] && $y + $h > $oy) {
                return true;
            }
        }
        return false;
    }

    /**
     * Move the lower of two overlapping cards down until columns no longer intersect.
     * Width and column stay. Used after a compact card grows into the card below it.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function pushApart(array $items): array
    {
        $n = count($items);
        for ($guard = 0; $guard < $n * 24; $guard++) {
            $moved = false;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $items[$i];
                    $b = $items[$j];
                    if (!self::rectHits((int) $a['x'], (int) $a['y'], (int) $a['w'], (int) $a['h'], [$b])) {
                        continue;
                    }
                    $pushI = ((int) $a['y'] > (int) $b['y']
                        || ((int) $a['y'] === (int) $b['y'] && (int) ($a['id'] ?? 0) > (int) ($b['id'] ?? 0)))
                        ? $i : $j;
                    $other = $items[$pushI === $i ? $j : $i];
                    $newY = (int) $other['y'] + (int) $other['h'];
                    if ((int) $items[$pushI]['y'] !== $newY) {
                        $items[$pushI]['y'] = $newY;
                        $moved = true;
                    }
                }
            }
            if (!$moved) {
                break;
            }
        }
        return $items;
    }

    /**
     * First load after upgrade: build the grid from legacy preferences. Runs once per user.
     */
    public function ensureSeeded(int $userId): void
    {
        $prefs = new PreferenceService($this->db);
        if ($prefs->get($userId, self::SEEDED_PREF) === '1') {
            return;
        }

        $this->db->transaction(function (Database $db) use ($userId, $prefs): void {
            // Serialise concurrent first loads (two tabs).
            $db->fetchScalar('SELECT pg_advisory_xact_lock(?, ?)', [0x4c44, $userId]);
            if ($prefs->get($userId, self::SEEDED_PREF) === '1') {
                return;
            }
            $existing = (int) $db->fetchScalar('SELECT COUNT(*) FROM dashboard_widgets WHERE user_id = ?', [$userId]);
            if ($existing === 0) {
                foreach ($this->seedPlan($userId, $prefs) as [$type, $x, $y, $w, $h]) {
                    $this->create($userId, $type, null, [], ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h], 'system');
                }
            }
            $prefs->set($userId, self::SEEDED_PREF, '1');
        });
    }

    /**
     * Legacy visible widgets in their saved order, packed 4 per row on a 12-col grid,
     * with habits and a 3-column mood on top and clipboards/news full width below.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    public function seedPlan(int $userId, PreferenceService $prefs): array
    {
        $this->metrics->ensureMood($userId);

        $visible = $prefs->getWidgetVisibility($userId);
        $order   = $prefs->getWidgetOrder($userId);

        $plan = [['habits', 0, 0, 6, 5], ['mood', 6, 0, 3, 4]];
        $y = 5;
        $col = 0;
        $rowH = 0;
        foreach ($order as $id) {
            if (!($visible[$id] ?? true) || !WidgetTypeRegistry::exists('legacy.' . $id)) {
                continue;
            }
            $h = $id === 'bookmarks' || $id === 'todos' ? 6 : 5;
            $plan[] = ['legacy.' . $id, $col * 3, $y, 3, $h];
            $rowH = max($rowH, $h);
            if (++$col === 4) {
                $col = 0;
                $y += $rowH;
                $rowH = 0;
            }
        }
        if ($col !== 0) {
            $y += $rowH;
        }
        if ($visible['clipboards'] ?? true) {
            $plan[] = ['legacy.clipboards', 0, $y, 12, 6];
            $y += 6;
        }
        if (FeedPreferenceService::fromDatabase($this->db)->hasDashboardPins($userId)) {
            $plan[] = ['legacy.news', 0, $y, 12, 8];
        }
        return $plan;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        $config = is_string($row['config']) ? (json_decode($row['config'], true) ?: []) : (array) $row['config'];
        $type   = (string) $row['type'];
        $def    = WidgetTypeRegistry::exists($type) ? WidgetTypeRegistry::get($type) : null;
        return [
            'id'          => (int) $row['id'],
            'type'        => $type,
            'label'       => $def['label'] ?? $type,
            'title'       => $row['title'] !== null ? (string) $row['title'] : null,
            'config'      => $config,
            'metric_refs' => $def !== null ? WidgetTypeRegistry::metricRefs($type, $config) : [],
            'version'     => (int) $row['version'],
            'x'           => (int) $row['grid_x'],
            'y'           => (int) $row['grid_y'],
            'w'             => (int) $row['grid_w'],
            'h'             => (int) $row['grid_h'],
            'height_locked'    => (int) ($row['height_locked'] ?? 0),
            'placement_locked' => (int) ($row['placement_locked'] ?? 0),
            'min_w'         => (int) ($def['min']['w'] ?? 1),
            'min_h'       => (int) ($def['min']['h'] ?? 1),
            'created_by'  => (string) $row['created_by'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $widget
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function writeVersion(int $userId, array $widget, ?string $title, array $config, string $changedBy, ?string $note): array
    {
        $note = $note !== null ? mb_substr(trim($note), 0, 255) : null;
        return $this->db->transaction(function (Database $db) use ($userId, $widget, $title, $config, $changedBy, $note): array {
            // Row lock makes concurrent edits produce consecutive versions instead of a unique violation.
            $version = (int) $db->fetchScalar('SELECT version FROM dashboard_widgets WHERE id = ? AND user_id = ? FOR UPDATE', [$widget['id'], $userId]) + 1;
            $db->execute(
                "UPDATE dashboard_widgets SET title = ?, config = ?::jsonb, version = ?, updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE id = ? AND user_id = ?",
                [$title, self::json($config), $version, $widget['id'], $userId]
            );
            $db->execute(
                'INSERT INTO widget_revisions (widget_id, version, title, config, changed_by, note) VALUES (?, ?, ?, ?::jsonb, ?, ?)',
                [$widget['id'], $version, $title, self::json($config), $changedBy, $note]
            );
            return $this->get($userId, (int) $widget['id']);
        });
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function validate(int $userId, string $type, array $config): array
    {
        return WidgetTypeRegistry::validateConfig($type, $config, function (int $id) use ($userId): ?array {
            try {
                $m = $this->metrics->get($userId, $id);
                return $m['archived'] ? null : $m;
            } catch (NotFoundException) {
                return null;
            }
        });
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function nextSlot(int $userId, string $board, int $w): array
    {
        $bottom = (int) $this->db->fetchScalar(
            'SELECT COALESCE(MAX(grid_y + grid_h), 0) FROM dashboard_widgets WHERE user_id = ? AND board = ?',
            [$userId, $board]
        );
        return [0, $bottom];
    }

    private static function cleanTitle(mixed $title): ?string
    {
        if ($title === null) {
            return null;
        }
        $t = trim((string) $title);
        if (mb_strlen($t) > 128) {
            throw ValidationException::withFields(['title' => 'Title must be at most 128 characters.']);
        }
        return $t === '' ? null : $t;
    }

    private static function checkChanger(string $who): void
    {
        if (!in_array($who, self::CHANGERS, true)) {
            throw new \InvalidArgumentException('Invalid changer.');
        }
    }

    private static function clampInt(mixed $v, int $min, int $max): int
    {
        return max($min, min($max, (int) $v));
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data === [] ? new \stdClass() : $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
