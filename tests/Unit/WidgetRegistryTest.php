<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Support\ValidationException;
use Dashboard\Widgets\GoalLog;
use Dashboard\Widgets\WidgetDataService;
use Dashboard\Widgets\WidgetService;
use Dashboard\Widgets\WidgetTypeRegistry;
use PHPUnit\Framework\TestCase;

final class WidgetRegistryTest extends TestCase
{
    /** Fake metric lookup: 1 and 4 = number, 2 = habit, 3 = scale; anything else missing. */
    private static function finder(): callable
    {
        $metrics = [1 => 'number', 2 => 'habit', 3 => 'scale', 4 => 'number'];
        return static fn (int $id): ?array => isset($metrics[$id]) ? ['id' => $id, 'name' => "M{$id}", 'kind' => $metrics[$id], 'archived' => false] : null;
    }

    public function testEveryTypeIsWellFormed(): void
    {
        foreach (WidgetTypeRegistry::types() as $type => $def) {
            foreach (['label', 'category', 'singleton', 'description', 'size', 'min', 'fields'] as $k) {
                self::assertArrayHasKey($k, $def, "{$type}.{$k}");
            }
            self::assertLessThanOrEqual(12, $def['size']['w'], $type);
            self::assertGreaterThanOrEqual($def['min']['w'], $def['size']['w'], $type);
            self::assertGreaterThanOrEqual($def['min']['h'], $def['size']['h'], $type);
            self::assertSame(WidgetTypeRegistry::isLegacy($type), $def['singleton'], "{$type}: legacy types are singletons");
            // Every type must accept its own defaults when required fields are satisfied.
            $cfg = [];
            foreach ($def['fields'] as $name => $f) {
                if (($f['type'] === 'metric' && !empty($f['required'])) || ($f['type'] === 'metrics' && ($f['min_items'] ?? 0) > 0)) {
                    $id = in_array('habit', $f['kinds'] ?? [], true) ? 2 : 1;
                    $cfg[$name] = $f['type'] === 'metric' ? $id : [$id];
                }
            }
            WidgetTypeRegistry::validateConfig($type, $cfg, self::finder());
        }
    }

    public function testDefaultsAndCoercion(): void
    {
        self::assertSame(
            ['metric_ids' => [1, 3], 'style' => 'bar', 'period' => '30d', 'bucket' => 'week', 'agg' => 'avg'],
            WidgetTypeRegistry::validateConfig('chart', ['metric_ids' => ['1', 3, 1], 'style' => 'bar', 'bucket' => 'week'], self::finder())
        );
        self::assertSame(['metric_ids' => [], 'days' => '7'], WidgetTypeRegistry::validateConfig('habits', [], self::finder()));
        self::assertSame(['days' => '30'], WidgetTypeRegistry::validateConfig('mood', ['metric_id' => ''], self::finder()), 'empty = default Mood');
        self::assertSame(
            ['metric_ids' => [1], 'view' => 'week', 'logs' => ['1' => ['increments' => '250,500']]],
            WidgetTypeRegistry::validateConfig('goal', ['metric_id' => '1', 'view' => 'week'], self::finder())
        );
        self::assertSame(
            ['metric_ids' => [1], 'view' => 'day', 'logs' => ['1' => ['increments' => '100,250', 'dedupe_prefix' => 'walk']]],
            WidgetTypeRegistry::validateConfig('goal', ['metric_id' => 1, 'increments' => '100, 250', 'dedupe_prefix' => 'walk'], self::finder())
        );
        self::assertSame(
            [
                'metric_ids' => [1, 4],
                'view' => 'day',
                'logs' => [
                    '1' => ['mode' => 'amount', 'increments' => '250,500'],
                    '4' => ['increments' => '1', 'mode' => 'count'],
                ],
            ],
            WidgetTypeRegistry::validateConfig('goal', [
                'metric_ids' => [1, 4, 1],
                'logs' => [
                    '1' => ['mode' => 'amount'],
                    '4' => ['mode' => 'count', 'increments' => '1'],
                ],
            ], self::finder())
        );
    }

    public function testGoalRatioCapsAtDoubleTheGoal(): void
    {
        self::assertSame(0.0, WidgetDataService::goalRatio(null, 3000));
        self::assertSame(0.0, WidgetDataService::goalRatio(-40.0, 3000));
        self::assertSame(0.5, WidgetDataService::goalRatio(1500.0, 3000));
        self::assertSame(1.0, WidgetDataService::goalRatio(3000.0, 3000));
        self::assertSame(1.5, WidgetDataService::goalRatio(4500.0, 3000));
        self::assertSame(2.0, WidgetDataService::goalRatio(9000.0, 3000));
    }

    public function testGoalLogKeyMatchesHypeManAndPackClosesGaps(): void
    {
        self::assertSame([250.0, 500.0], GoalLog::parseIncrements(null));
        self::assertSame([250.0, 500.0], GoalLog::parseIncrements('250, 500'));
        self::assertSame('water', GoalLog::prefix([], ['key' => 'water_ml', 'id' => 17]));
        self::assertSame('movement', GoalLog::prefix([], ['key' => 'movement_sessions', 'id' => 18]));
        self::assertSame('steps', GoalLog::prefix([], ['key' => 'steps', 'id' => 3]));
        self::assertSame('walk', GoalLog::prefix(['dedupe_prefix' => 'walk'], ['key' => 'water_ml', 'id' => 17]));
        self::assertSame('water:2026-10-15', GoalLog::dedupeKey('water', '2026-10-15'));
        self::assertSame('movement:2026-10-15', GoalLog::dedupeKey('movement', '2026-10-15'));
        self::assertSame('count', GoalLog::mode(['key' => 'movement_sessions', 'unit' => '', 'config' => ['goal' => 3]]));
        self::assertSame('amount', GoalLog::mode(['key' => 'water_ml', 'unit' => 'ml', 'config' => ['goal' => 3000]]));
        self::assertSame(6, GoalLog::pipCount(3));
        self::assertSame([17], GoalLog::metricIds(['metric_id' => 17]));
        self::assertSame([17, 18], GoalLog::metricIds(['metric_ids' => [17, 18]]));

        $flow = WidgetService::pack([
            ['id' => 1, 'w' => 8, 'h' => 3],
            ['id' => 2, 'w' => 8, 'h' => 4],
            ['id' => 3, 'w' => 4, 'h' => 2, 'height_locked' => 1],
        ]);
        self::assertSame([0, 0, 8, 3], [$flow[0]['x'], $flow[0]['y'], $flow[0]['w'], $flow[0]['h']]);
        self::assertSame([0, 3, 8, 4], [$flow[1]['x'], $flow[1]['y'], $flow[1]['w'], $flow[1]['h']]);
        self::assertSame([8, 3, 4, 2], [$flow[2]['x'], $flow[2]['y'], $flow[2]['w'], $flow[2]['h']]);
        self::assertSame(1, $flow[2]['height_locked']);

        $up = WidgetService::packUp([
            ['id' => 1, 'x' => 0, 'y' => 5, 'w' => 6, 'h' => 2],
            ['id' => 2, 'x' => 6, 'y' => 10, 'w' => 6, 'h' => 2],
            ['id' => 3, 'x' => 0, 'y' => 20, 'w' => 4, 'h' => 3],
        ]);
        $byId = [];
        foreach ($up as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertSame(0, $byId[1]['y']);
        self::assertSame(0, $byId[2]['y'], 'a widget on the right slides up beside the one on the left');
        self::assertSame(6, $byId[2]['x']);
        self::assertSame(2, $byId[3]['y'], 'the card below stops under the one in its column');
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function badConfigs(): iterable
    {
        yield 'unknown key'          => ['stat', ['metric_id' => 1, 'x' => 1], 'config'];
        yield 'missing metric'       => ['stat', [], 'config.metric_id'];
        yield 'foreign metric'       => ['stat', ['metric_id' => 99], 'config.metric_id'];
        yield 'wrong kind'           => ['streak', ['metric_id' => 1], 'config.metric_id'];
        yield 'mood needs scale'     => ['mood', ['metric_id' => 2], 'config.metric_id'];
        yield 'enum'                 => ['stat', ['metric_id' => 1, 'period' => 'decade'], 'config.period'];
        yield 'int range'            => ['table', ['metric_id' => 1, 'limit' => 500], 'config.limit'];
        yield 'too many metrics'     => ['chart', ['metric_ids' => [1, 2, 3, 1, 2]], 'config.metric_ids'];
        yield 'metrics not a list'   => ['chart', ['metric_ids' => ['a' => 1]], 'config.metric_ids'];
        yield 'gauge max <= min'     => ['gauge', ['metric_id' => 1, 'min' => 10, 'max' => 10], 'config.max'];
        yield 'goal needs number'    => ['goal', ['metric_id' => 2], 'config.metric_ids'];
        yield 'goal view'            => ['goal', ['metric_id' => 1, 'view' => 'month'], 'config.view'];
        yield 'goal increments'      => ['goal', ['metric_id' => 1, 'increments' => '0,250'], 'config.logs'];
        yield 'goal prefix'          => ['goal', ['metric_id' => 1, 'dedupe_prefix' => 'water:'], 'config.logs'];
        yield 'goal too many'        => ['goal', ['metric_ids' => [1, 1, 1, 1, 1]], 'config.metric_ids'];
        yield 'legacy takes no cfg'  => ['legacy.weather', ['city' => 'x'], 'config'];
        yield 'unknown type'         => ['nope', [], 'type'];
    }

    /**
     * @dataProvider badConfigs
     * @param array<string, mixed> $config
     */
    public function testRejects(string $type, array $config, string $field): void
    {
        try {
            WidgetTypeRegistry::validateConfig($type, $config, self::finder());
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
    }

    public function testMetricRefs(): void
    {
        self::assertSame([1, 3], WidgetTypeRegistry::metricRefs('chart', ['metric_ids' => [1, 3, 1]]));
        self::assertSame([2], WidgetTypeRegistry::metricRefs('streak', ['metric_id' => 2]));
        self::assertSame([], WidgetTypeRegistry::metricRefs('legacy.todos', []));
    }

    // ---- WidgetDataService pure helpers -----------------------------

    public function testPeriodRanges(): void
    {
        self::assertSame(['2026-09-25', '2026-09-25'], WidgetDataService::periodRange('today', '2026-09-25'));
        self::assertSame(['2026-09-19', '2026-09-25'], WidgetDataService::periodRange('7d', '2026-09-25'));
        self::assertSame([null, '2026-09-25'], WidgetDataService::periodRange('all', '2026-09-25'));

        self::assertSame(['2026-09-12', '2026-09-18'], WidgetDataService::previousRange('7d', '2026-09-25'));
        self::assertSame(['2026-09-24', '2026-09-24'], WidgetDataService::previousRange('today', '2026-09-25'));
        self::assertNull(WidgetDataService::previousRange('all', '2026-09-25'));

        $this->expectException(\InvalidArgumentException::class);
        WidgetDataService::periodRange('forever', '2026-09-25');
    }

    public function testBucketKeysMatchDateTrunc(): void
    {
        self::assertSame(['2026-09-23', '2026-09-24', '2026-09-25'], WidgetDataService::bucketKeys('day', '2026-09-23', '2026-09-25'));
        self::assertSame(['2026-09-14', '2026-09-21'], WidgetDataService::bucketKeys('week', '2026-09-20', '2026-09-25'), 'Sunday → prior Monday');
        self::assertSame(['2026-07-01', '2026-08-01', '2026-09-01'], WidgetDataService::bucketKeys('month', '2026-07-31', '2026-09-25'));
        self::assertCount(365, WidgetDataService::bucketKeys('day', '2025-09-26', '2026-09-25'));
    }
}
