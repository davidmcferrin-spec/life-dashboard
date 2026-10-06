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
    public const MAX_WIDGETS = 60;
    public const BOARD = 'home';
    public const CHANGERS = ['user', 'ai', 'system'];
    private const SEEDED_PREF = 'dashboard_grid_seeded';

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
     * @param list<array{id: mixed, x: mixed, y: mixed, w: mixed, h: mixed}> $items
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
            $clean[] = [$id, $x, $y, $w, $h];
        }

        return $this->db->transaction(function (Database $db) use ($userId, $clean): int {
            $n = 0;
            foreach ($clean as [$id, $x, $y, $w, $h]) {
                $n += $db->execute(
                    "UPDATE dashboard_widgets SET grid_x = ?, grid_y = ?, grid_w = ?, grid_h = ?, updated_at = (now() AT TIME ZONE 'UTC')
                     WHERE id = ? AND user_id = ? AND (grid_x, grid_y, grid_w, grid_h) IS DISTINCT FROM (?, ?, ?, ?)",
                    [$x, $y, $w, $h, $id, $userId, $x, $y, $w, $h]
                );
            }
            return $n;
        });
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
     * Legacy visible widgets in their saved order, packed 3 per row on a 12-col grid,
     * with the new habits + mood on top and clipboards/news full width below.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    public function seedPlan(int $userId, PreferenceService $prefs): array
    {
        $this->metrics->ensureMood($userId);

        $visible = $prefs->getWidgetVisibility($userId);
        $order   = $prefs->getWidgetOrder($userId);

        $plan = [['habits', 0, 0, 6, 5], ['mood', 6, 0, 6, 5]];
        $y = 5;
        $col = 0;
        foreach ($order as $id) {
            if (!($visible[$id] ?? true) || !WidgetTypeRegistry::exists('legacy.' . $id)) {
                continue;
            }
            $h = $id === 'bookmarks' || $id === 'todos' ? 6 : 5;
            $plan[] = ['legacy.' . $id, $col * 4, $y, 4, $h];
            if (++$col === 3) {
                $col = 0;
                $y += 6;
            }
        }
        if ($col !== 0) {
            $y += 6;
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
            'w'           => (int) $row['grid_w'],
            'h'           => (int) $row['grid_h'],
            'min_w'       => (int) ($def['min']['w'] ?? 1),
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
