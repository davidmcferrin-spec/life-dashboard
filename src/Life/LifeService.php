<?php

declare(strict_types=1);

namespace Dashboard\Life;

use Dashboard\Audit\AuditLog;
use Dashboard\Auth\ApiTokenService;
use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\DatapointService;
use Dashboard\Tracking\MetricService;

/**
 * Seeded life metrics plus ingest from Health Connect and a webhook.
 */
final class LifeService
{
    /** @var list<array{key: string, name: string, kind: string, unit: ?string, config: array<string, mixed>}> */
    public const SEEDS = [
        ['key' => 'sleep_hours', 'name' => 'Sleep', 'kind' => 'number', 'unit' => 'h', 'config' => ['decimals' => 1]],
        ['key' => 'steps', 'name' => 'Steps', 'kind' => 'number', 'unit' => 'steps', 'config' => ['decimals' => 0, 'goal' => 8000, 'goal_direction' => 'up']],
        ['key' => 'exercise_min', 'name' => 'Exercise', 'kind' => 'duration', 'unit' => null, 'config' => []],
        ['key' => 'weight', 'name' => 'Weight', 'kind' => 'number', 'unit' => 'lb', 'config' => ['decimals' => 1]],
        ['key' => 'travel_day', 'name' => 'Travel day', 'kind' => 'habit', 'unit' => null, 'config' => ['emoji' => '✈']],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly MetricService $metrics,
        private readonly DatapointService $points,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function ensureDefaults(int $userId): array
    {
        $rows = [];
        foreach (self::SEEDS as $seed) {
            $existing = $this->metrics->findByKey($userId, $seed['key']);
            if ($existing !== null) {
                $rows[] = $existing;
                continue;
            }
            $rows[] = $this->metrics->create($userId, [
                'key'    => $seed['key'],
                'name'   => $seed['name'],
                'kind'   => $seed['kind'],
                'unit'   => $seed['unit'],
                'config' => $seed['config'],
            ], 'system');
        }
        return $rows;
    }

    /**
     * Today's value for each life metric. latest is null when nothing is logged.
     *
     * @return list<array{metric: array<string, mixed>, latest: ?array<string, mixed>}>
     */
    public function today(int $userId): array
    {
        $today = $this->clock->today();
        $rows = [];
        foreach ($this->ensureDefaults($userId) as $metric) {
            if ($metric['archived']) {
                continue;
            }
            $points = $this->points->list($userId, (int) $metric['id'], $today, $today, 1);
            $rows[] = ['metric' => $metric, 'latest' => $points[0] ?? null];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{written: list<array<string, mixed>>}
     */
    public function ingest(int $userId, array $body, string $source, string $actor = 'integration', ?string $tool = null): array
    {
        $this->ensureDefaults($userId);
        $entries = [];
        if (isset($body['records']) && is_array($body['records'])) {
            $entries = array_merge($entries, HealthConnect::entries($body['records']));
        }
        if (isset($body['entries']) && is_array($body['entries'])) {
            foreach ($body['entries'] as $entry) {
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        }
        if ($entries === []) {
            throw ValidationException::withFields(['entries' => 'Provide entries or Health Connect records.']);
        }

        $written = [];
        foreach ($entries as $entry) {
            $metric = $this->resolveMetric($userId, $entry);
            $input = [
                'value'      => $entry['value'] ?? null,
                'source'     => $source,
                'dedupe_key' => $entry['dedupe_key'] ?? null,
                'note'       => $entry['note'] ?? null,
            ];
            if (!empty($entry['ts'])) {
                $input['ts'] = (string) $entry['ts'];
            } elseif (!empty($entry['date'])) {
                $input['date'] = (string) $entry['date'];
            }
            $point = $this->points->record($userId, (int) $metric['id'], $input);
            $written[] = $point;
        }
        $this->audit->record($userId, $actor, $tool, 'life.ingest', 'life', ['count' => count($written)]);
        return ['written' => $written];
    }

    /**
     * @return array<string, mixed> includes plaintext "token" once
     */
    public function createHook(int $userId, string $name = 'Home Assistant'): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw ValidationException::withFields(['name' => 'Name must be 1–64 characters.']);
        }
        $plain = 'ha_' . bin2hex(random_bytes(24));
        $this->db->execute(
            'INSERT INTO life_hooks (user_id, name, prefix, token_hash) VALUES (?, ?, ?, ?)',
            [$userId, $name, substr($plain, 0, 11), ApiTokenService::hash($plain)]
        );
        $row = $this->hookRow($userId, (int) $this->db->lastInsertId());
        $row['token'] = $plain;
        $row['path'] = '/api/v1/life/hook/' . $plain;
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function hooks(int $userId): array
    {
        return array_map(
            [$this, 'formatHook'],
            $this->db->fetchAll('SELECT * FROM life_hooks WHERE user_id = ? ORDER BY created_at DESC', [$userId])
        );
    }

    public function revokeHook(int $userId, int $id): void
    {
        $n = $this->db->execute(
            "UPDATE life_hooks SET revoked_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        if ($n === 0) {
            throw new NotFoundException('Hook not found.');
        }
    }

    public function userForHook(string $plain): int
    {
        $row = $this->db->fetchOne(
            'SELECT user_id, revoked_at FROM life_hooks WHERE token_hash = ?',
            [ApiTokenService::hash($plain)]
        );
        if ($row === null || $row['revoked_at'] !== null) {
            throw new NotFoundException('Unknown hook.');
        }
        return (int) $row['user_id'];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function resolveMetric(int $userId, array $entry): array
    {
        if (!empty($entry['key'])) {
            $metric = $this->metrics->findByKey($userId, (string) $entry['key']);
            if ($metric === null || $metric['archived']) {
                throw ValidationException::withFields(['key' => 'Unknown metric key ' . $entry['key'] . '.']);
            }
            return $metric;
        }
        if (!empty($entry['metric_id'])) {
            return $this->metrics->get($userId, (int) $entry['metric_id']);
        }
        throw ValidationException::withFields(['key' => 'Each entry needs a metric key.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function hookRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM life_hooks WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Hook not found.');
        }
        return $this->formatHook($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatHook(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'prefix'     => (string) $row['prefix'],
            'revoked'    => $row['revoked_at'] !== null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
