<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

use Dashboard\DB\Database;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * MetricService — what a user tracks. Kinds:
 *
 *   habit     one 0/1 entry per local day            config: emoji, color, target_per_week, description
 *   scale     bounded integer rating (e.g. mood 1–5) config: min, max, labels{value:label}, emoji
 *   number    free numeric value                     config: decimals, goal, goal_direction
 *   duration  seconds                                config: goal_seconds
 *
 * Config is validated strictly (unknown keys rejected) because L2 lets AI
 * assistants create metrics through MCP.
 */
final class MetricService
{
    public const KINDS = ['habit', 'scale', 'number', 'duration'];
    public const CREATORS = ['user', 'ai', 'system', 'integration'];
    public const MAX_ACTIVE_PER_USER = 200;
    public const MOOD_KEY = 'mood';

    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId, ?string $kind = null, bool $includeArchived = false): array
    {
        $sql    = 'SELECT * FROM metrics WHERE user_id = ?';
        $params = [$userId];
        if ($kind !== null) {
            $sql     .= ' AND kind = ?';
            $params[] = $kind;
        }
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';

        return array_map([self::class, 'format'], $this->db->fetchAll($sql, $params));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $metricId): array
    {
        $row = $this->db->fetchOne('SELECT * FROM metrics WHERE id = ? AND user_id = ?', [$metricId, $userId]);
        if ($row === null) {
            throw new NotFoundException('Metric not found.');
        }
        return self::format($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(int $userId, string $key): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM metrics WHERE user_id = ? AND key = ?', [$userId, $key]);
        return $row === null ? null : self::format($row);
    }

    /**
     * @param array<string, mixed> $input name, kind, key?, unit?, config?
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input, string $createdBy = 'user'): array
    {
        if (!in_array($createdBy, self::CREATORS, true)) {
            throw new \InvalidArgumentException('Invalid creator.');
        }

        $name = self::cleanName($input['name'] ?? null);
        $kind = (string) ($input['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw ValidationException::withFields(['kind' => 'Kind must be one of: ' . implode(', ', self::KINDS) . '.']);
        }
        $key    = isset($input['key']) && $input['key'] !== '' ? self::cleanKey((string) $input['key']) : $this->uniqueKey($userId, $name);
        $unit   = self::cleanUnit($input['unit'] ?? null);
        $config = self::validateConfig($kind, is_array($input['config'] ?? null) ? $input['config'] : []);

        $active = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM metrics WHERE user_id = ? AND archived_at IS NULL', [$userId]);
        if ($active >= self::MAX_ACTIVE_PER_USER) {
            throw new ValidationException('Too many active metrics (max ' . self::MAX_ACTIVE_PER_USER . '). Archive some first.');
        }
        if ($this->findByKey($userId, $key) !== null) {
            throw ValidationException::withFields(['key' => "A metric with key '{$key}' already exists."]);
        }

        $sort = (int) $this->db->fetchScalar('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM metrics WHERE user_id = ?', [$userId]);

        $this->db->execute(
            'INSERT INTO metrics (user_id, key, name, kind, unit, config, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?)',
            [$userId, $key, $name, $kind, $unit, self::json($config), $sort, $createdBy]
        );

        return $this->get($userId, (int) $this->db->lastInsertId());
    }

    /**
     * Update name/unit/config/sort_order. Kind and key are immutable (datapoints depend on them).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $userId, int $metricId, array $input): array
    {
        $metric = $this->get($userId, $metricId);
        $sets   = [];
        $params = [];

        if (array_key_exists('kind', $input) && $input['kind'] !== $metric['kind']) {
            throw ValidationException::withFields(['kind' => 'Kind cannot be changed; create a new metric instead.']);
        }
        if (array_key_exists('key', $input) && $input['key'] !== $metric['key']) {
            throw ValidationException::withFields(['key' => 'Key cannot be changed.']);
        }
        if (array_key_exists('name', $input)) {
            $sets[]   = 'name = ?';
            $params[] = self::cleanName($input['name']);
        }
        if (array_key_exists('unit', $input)) {
            $sets[]   = 'unit = ?';
            $params[] = self::cleanUnit($input['unit']);
        }
        if (array_key_exists('config', $input)) {
            if (!is_array($input['config'])) {
                throw ValidationException::withFields(['config' => 'Config must be an object.']);
            }
            $sets[]   = 'config = ?::jsonb';
            $params[] = self::json(self::validateConfig((string) $metric['kind'], $input['config']));
        }
        if (array_key_exists('sort_order', $input)) {
            $sets[]   = 'sort_order = ?';
            $params[] = max(0, min(100000, (int) $input['sort_order']));
        }
        if ($sets === []) {
            return $metric;
        }

        $sets[]   = "updated_at = (now() AT TIME ZONE 'UTC')";
        $params[] = $metricId;
        $params[] = $userId;
        $this->db->execute('UPDATE metrics SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?', $params);

        return $this->get($userId, $metricId);
    }

    /** Archive (hide) or restore. Data is kept. */
    public function setArchived(int $userId, int $metricId, bool $archived): array
    {
        $this->get($userId, $metricId);
        $this->db->execute(
            $archived
                ? "UPDATE metrics SET archived_at = (now() AT TIME ZONE 'UTC'), updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?"
                : "UPDATE metrics SET archived_at = NULL, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$metricId, $userId]
        );
        return $this->get($userId, $metricId);
    }

    /**
     * Persist a new display order for the given metric ids (others keep theirs).
     *
     * @param list<int> $ids
     */
    public function reorder(int $userId, array $ids): void
    {
        $this->db->transaction(function (Database $db) use ($userId, $ids): void {
            foreach (array_values($ids) as $i => $id) {
                $db->execute('UPDATE metrics SET sort_order = ? WHERE id = ? AND user_id = ?', [$i, (int) $id, $userId]);
            }
        });
    }

    /**
     * Mood (scale 1–5) exists for every user so the mood widget works out of the box.
     *
     * @return array<string, mixed>
     */
    public function ensureMood(int $userId): array
    {
        $existing = $this->findByKey($userId, self::MOOD_KEY);
        if ($existing !== null) {
            return $existing;
        }
        try {
            return $this->create($userId, [
                'key'    => self::MOOD_KEY,
                'name'   => 'Mood',
                'kind'   => 'scale',
                'config' => [
                    'min'    => 1,
                    'max'    => 5,
                    'labels' => ['1' => 'Awful', '2' => 'Low', '3' => 'Okay', '4' => 'Good', '5' => 'Great'],
                ],
            ], 'system');
        } catch (ValidationException) {
            // Created concurrently by another request.
            return $this->findByKey($userId, self::MOOD_KEY) ?? throw new \RuntimeException('Could not create mood metric.');
        }
    }

    // ------------------------------------------------------------------
    // Validation helpers (public static for unit tests and the widget registry)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function validateConfig(string $kind, array $config): array
    {
        $allowed = match ($kind) {
            'habit'    => ['emoji', 'color', 'target_per_week', 'description'],
            'scale'    => ['min', 'max', 'labels', 'emoji', 'color', 'description'],
            'number'   => ['decimals', 'goal', 'goal_direction', 'color', 'description'],
            'duration' => ['goal_seconds', 'color', 'description'],
            default    => throw ValidationException::withFields(['kind' => 'Unknown kind.']),
        };
        $unknown = array_diff(array_keys($config), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withFields(['config' => 'Unknown config keys for ' . $kind . ': ' . implode(', ', $unknown) . '.']);
        }

        $out = [];
        if (isset($config['emoji'])) {
            $emoji = trim((string) $config['emoji']);
            if (mb_strlen($emoji) > 8) {
                throw ValidationException::withFields(['config.emoji' => 'Emoji must be at most 8 characters.']);
            }
            if ($emoji !== '') {
                $out['emoji'] = $emoji;
            }
        }
        if (isset($config['color'])) {
            $color = strtolower(trim((string) $config['color']));
            if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
                throw ValidationException::withFields(['config.color' => 'Color must be #rrggbb.']);
            }
            $out['color'] = $color;
        }
        if (isset($config['description'])) {
            $desc = trim((string) $config['description']);
            if (mb_strlen($desc) > 255) {
                throw ValidationException::withFields(['config.description' => 'Description must be at most 255 characters.']);
            }
            if ($desc !== '') {
                $out['description'] = $desc;
            }
        }

        switch ($kind) {
            case 'habit':
                if (isset($config['target_per_week'])) {
                    $t = self::intIn($config['target_per_week'], 1, 7, 'config.target_per_week');
                    $out['target_per_week'] = $t;
                }
                break;

            case 'scale':
                $min = self::intIn($config['min'] ?? 1, -100, 100, 'config.min');
                $max = self::intIn($config['max'] ?? 5, -100, 100, 'config.max');
                if ($max <= $min || $max - $min > 10) {
                    throw ValidationException::withFields(['config.max' => 'Scale needs max > min and at most 11 steps.']);
                }
                $out['min'] = $min;
                $out['max'] = $max;
                if (isset($config['labels'])) {
                    if (!is_array($config['labels'])) {
                        throw ValidationException::withFields(['config.labels' => 'Labels must be an object of value → label.']);
                    }
                    $labels = [];
                    foreach ($config['labels'] as $v => $label) {
                        $iv = filter_var($v, FILTER_VALIDATE_INT);
                        if ($iv === false || $iv < $min || $iv > $max) {
                            throw ValidationException::withFields(['config.labels' => "Label key {$v} is outside {$min}–{$max}."]);
                        }
                        $label = trim((string) $label);
                        if ($label === '' || mb_strlen($label) > 32) {
                            throw ValidationException::withFields(['config.labels' => 'Labels must be 1–32 characters.']);
                        }
                        $labels[(string) $iv] = $label;
                    }
                    ksort($labels, SORT_NUMERIC);
                    $out['labels'] = $labels;
                }
                break;

            case 'number':
                if (isset($config['decimals'])) {
                    $out['decimals'] = self::intIn($config['decimals'], 0, 4, 'config.decimals');
                }
                if (isset($config['goal'])) {
                    if (!is_numeric($config['goal'])) {
                        throw ValidationException::withFields(['config.goal' => 'Goal must be a number.']);
                    }
                    $out['goal'] = (float) $config['goal'];
                }
                if (isset($config['goal_direction'])) {
                    if (!in_array($config['goal_direction'], ['up', 'down'], true)) {
                        throw ValidationException::withFields(['config.goal_direction' => 'goal_direction must be up or down.']);
                    }
                    $out['goal_direction'] = $config['goal_direction'];
                }
                break;

            case 'duration':
                if (isset($config['goal_seconds'])) {
                    $out['goal_seconds'] = self::intIn($config['goal_seconds'], 1, 86400 * 7, 'config.goal_seconds');
                }
                break;
        }

        return $out;
    }

    public static function cleanKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $key)) {
            throw ValidationException::withFields(['key' => 'Key must be 1–64 chars: a-z, 0-9, _ . - (starting with a letter or digit).']);
        }
        return $key;
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
        return $slug === '' ? 'metric' : substr($slug, 0, 56);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        $config = is_string($row['config'] ?? null) ? json_decode($row['config'], true) : ($row['config'] ?? []);
        return [
            'id'          => (int) $row['id'],
            'key'         => (string) $row['key'],
            'name'        => (string) $row['name'],
            'kind'        => (string) $row['kind'],
            'unit'        => $row['unit'] !== null ? (string) $row['unit'] : null,
            'config'      => is_array($config) ? $config : [],
            'sort_order'  => (int) $row['sort_order'],
            'created_by'  => (string) $row['created_by'],
            'archived'    => $row['archived_at'] !== null,
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    // ------------------------------------------------------------------

    private function uniqueKey(int $userId, string $name): string
    {
        $base = self::slugify($name);
        $key  = $base;
        for ($i = 2; $this->findByKey($userId, $key) !== null; $i++) {
            $key = $base . '_' . $i;
        }
        return $key;
    }

    private static function cleanName(mixed $name): string
    {
        $name = trim((string) ($name ?? ''));
        if ($name === '' || mb_strlen($name) > 128) {
            throw ValidationException::withFields(['name' => 'Name is required (max 128 characters).']);
        }
        return $name;
    }

    private static function cleanUnit(mixed $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $unit = trim((string) $unit);
        if (mb_strlen($unit) > 32) {
            throw ValidationException::withFields(['unit' => 'Unit must be at most 32 characters.']);
        }
        return $unit === '' ? null : $unit;
    }

    private static function intIn(mixed $v, int $min, int $max, string $field): int
    {
        $i = filter_var($v, FILTER_VALIDATE_INT);
        if ($i === false || $i < $min || $i > $max) {
            throw ValidationException::withFields([$field => "Must be an integer {$min}–{$max}."]);
        }
        return $i;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        if (isset($data['labels']) && is_array($data['labels'])) {
            $data['labels'] = (object) $data['labels']; // keep {"0": …} an object, never a list
        }
        return json_encode($data === [] ? new \stdClass() : $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
