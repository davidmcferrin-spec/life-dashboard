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
            if (WidgetTypeRegistry::isLegacy($type)) {
                self::assertTrue($def['singleton'], "{$type}: legacy types are singletons");
            }
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

    public function testTopRowDefaultsAreThreeColumns(): void
    {
        foreach (['legacy.weather', 'goal', 'mood', 'legacy.todos'] as $type) {
            $def = WidgetTypeRegistry::get($type);
            self::assertSame(3, $def['size']['w'], $type);
            self::assertSame(2, $def['min']['w'], $type);
            self::assertGreaterThanOrEqual(2, $def['min']['h'], $type);
        }
        self::assertSame(12, WidgetTypeRegistry::get('legacy.news')['size']['w']);
        self::assertSame(3, WidgetTypeRegistry::get('legacy.news')['min']['w']);
    }

    public function testCompactRefitNarrowsUnlockedDefaultsAndLeavesAResizeAlone(): void
    {
        $next = WidgetService::refitCompactRow([
            ['id' => 1, 'type' => 'legacy.weather', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 5, 'height_locked' => 0],
            ['id' => 2, 'type' => 'goal', 'x' => 4, 'y' => 0, 'w' => 6, 'h' => 3, 'height_locked' => 0],
            ['id' => 3, 'type' => 'mood', 'x' => 0, 'y' => 5, 'w' => 6, 'h' => 5, 'height_locked' => 0],
            ['id' => 4, 'type' => 'legacy.todos', 'x' => 8, 'y' => 10, 'w' => 4, 'h' => 6, 'height_locked' => 0],
            ['id' => 5, 'type' => 'goal', 'x' => 0, 'y' => 16, 'w' => 6, 'h' => 3, 'height_locked' => 1],
            ['id' => 6, 'type' => 'legacy.news', 'x' => 0, 'y' => 20, 'w' => 12, 'h' => 8, 'height_locked' => 0],
            ['id' => 7, 'type' => 'goal', 'x' => 0, 'y' => 30, 'w' => 5, 'h' => 3, 'height_locked' => 0],
        ]);
        $byId = [];
        foreach ($next as $row) {
            $byId[$row['id']] = $row;
        }

        self::assertSame([0, 0, 3, 10], [$byId[1]['x'], $byId[1]['y'], $byId[1]['w'], $byId[1]['h']]);
        self::assertSame([3, 0, 3, 6], [$byId[2]['x'], $byId[2]['y'], $byId[2]['w'], $byId[2]['h']]);
        self::assertSame([6, 0, 3, 10], [$byId[3]['x'], $byId[3]['y'], $byId[3]['w'], $byId[3]['h']]);
        self::assertSame([9, 0, 3, 12], [$byId[4]['x'], $byId[4]['y'], $byId[4]['w'], $byId[4]['h']]);
        self::assertSame(6, $byId[5]['w'], 'a resized goal keeps its width');
        self::assertSame(0, $byId[5]['x'], 'a resized goal keeps its column');
        self::assertSame(1, $byId[5]['height_locked']);
        self::assertSame(6, $byId[5]['h'], 'locked height doubles with the shorter row');
        self::assertGreaterThanOrEqual(10, $byId[5]['y']);
        self::assertSame(12, $byId[6]['w']);
        self::assertSame(0, $byId[6]['x']);
        self::assertSame(5, $byId[7]['w'], 'a width that is not an old default stays');
        self::assertSame(0, $byId[7]['x']);

        $under = WidgetService::refitCompactRow([
            ['id' => 1, 'type' => 'legacy.news', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 4, 'height_locked' => 1],
            ['id' => 2, 'type' => 'legacy.weather', 'x' => 0, 'y' => 4, 'w' => 4, 'h' => 2, 'height_locked' => 0],
            ['id' => 3, 'type' => 'goal', 'x' => 4, 'y' => 4, 'w' => 6, 'h' => 2, 'height_locked' => 0],
            ['id' => 4, 'type' => 'mood', 'x' => 0, 'y' => 8, 'w' => 4, 'h' => 2, 'height_locked' => 0],
            ['id' => 5, 'type' => 'legacy.todos', 'x' => 4, 'y' => 8, 'w' => 4, 'h' => 2, 'height_locked' => 0],
        ]);
        $byId = [];
        foreach ($under as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertSame([0, 0, 12, 8], [$byId[1]['x'], $byId[1]['y'], $byId[1]['w'], $byId[1]['h']]);
        self::assertSame(8, $byId[2]['y'], 'cards do not jump above a locked full-width widget');
        self::assertSame([0, 3, 6, 9], [$byId[2]['x'], $byId[3]['x'], $byId[4]['x'], $byId[5]['x']]);
        self::assertSame([$byId[2]['y'], $byId[2]['y'], $byId[2]['y']], [$byId[3]['y'], $byId[4]['y'], $byId[5]['y']]);
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

    public function testOverviewTypesAreOrdinaryWidgets(): void
    {
        foreach (['vitals' => 'System vitals', 'agenda' => "Today's agenda", 'quick_actions' => 'Quick actions'] as $type => $label) {
            $def = WidgetTypeRegistry::types()[$type];
            self::assertSame($label, $def['label']);
            self::assertTrue($def['singleton']);
            self::assertArrayNotHasKey('floating', $def);
            self::assertSame('life', $def['category']);
            self::assertSame([], $def['fields']);
            self::assertSame([], WidgetTypeRegistry::validateConfig($type, [], self::finder()));
        }
        self::assertCount(3, \Dashboard\Widgets\OverviewCards::actions());
        self::assertSame(
            ['Brief my day', 'Analyze systems', 'Optimize energy'],
            array_column(\Dashboard\Widgets\OverviewCards::actions(), 'title')
        );
        $span = 0;
        foreach (WidgetService::OVERVIEW_SPOTS as $spot) {
            self::assertSame(0, $spot['y']);
            $span = max($span, $spot['x'] + $spot['w']);
        }
        self::assertSame(12, $span, 'the seeded row starts at the left edge and fills the width');
    }

    public function testProjectColumnsKeepsWidthsAndCentersAShortRow(): void
    {
        $items = [
            ['id' => 1, 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 3],
            ['id' => 2, 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 6],
            ['id' => 3, 'x' => 6, 'y' => 0, 'w' => 3, 'h' => 5],
            ['id' => 4, 'x' => 9, 'y' => 0, 'w' => 3, 'h' => 5],
            ['id' => 5, 'x' => 8, 'y' => 5, 'w' => 4, 'h' => 3],
            ['id' => 6, 'x' => 0, 'y' => 6, 'w' => 8, 'h' => 8],
            ['id' => 7, 'x' => 0, 'y' => 20, 'w' => 12, 'h' => 4],
            ['id' => 8, 'x' => 0, 'y' => 30, 'w' => 6, 'h' => 4],
        ];
        $same = [];
        foreach (WidgetService::projectColumns($items, 12) as $row) {
            $same[$row['id']] = $row;
        }
        self::assertSame([0, 0, 3], [$same[1]['x'], $same[1]['y'], $same[1]['w']]);
        self::assertSame(6, $same[6]['y'], 'a card stays on its row');
        self::assertSame(8, $same[6]['w']);
        self::assertSame([0, 30, 12], [$same[8]['x'], $same[8]['y'], $same[8]['w']], 'a short row starts at the left edge and fills the width');

        $wide = [];
        foreach (WidgetService::projectColumns($items, 18) as $row) {
            $wide[$row['id']] = $row;
        }
        self::assertSame(3, $wide[1]['w'], 'a 3-column card stays 3 columns on a wider grid');
        self::assertSame(0, $wide[5]['y'], 'a card that fits moves up onto the first row');
        self::assertSame(4, $wide[5]['w']);
        self::assertSame(8, $wide[6]['w'], 'an 8-column card keeps its column count');
        self::assertSame(12, $wide[7]['w'], 'a full-width card stays 12 columns instead of stretching');
        self::assertSame(6, $wide[8]['w']);
        self::assertSame(1, $wide[1]['x']);
        self::assertSame(13, $wide[5]['x']);
        self::assertSame(1, 18 - ($wide[5]['x'] + $wide[5]['w']), 'the first row has equal empty columns on each side');

        $ultra = [];
        foreach (WidgetService::projectColumns($items, 24) as $row) {
            $ultra[$row['id']] = $row;
        }
        self::assertSame(0, $ultra[6]['y'], 'six cards share the first row when 24 columns fit');
        self::assertSame(8, $ultra[6]['w']);
        self::assertSame(12, $ultra[7]['w']);
        self::assertSame(3, $ultra[1]['w']);
        self::assertSame(0, $ultra[1]['x'], 'a full row starts at the left edge');
        self::assertSame(16, $ultra[6]['x']);
        self::assertSame(3, $ultra[7]['x']);
        self::assertSame(3, 24 - ($ultra[8]['x'] + $ultra[8]['w']), 'a short row is centered');

        $order = WidgetService::projectColumns($items, 18);
        $xs = [];
        foreach ($order as $row) {
            if ((int) $row['y'] === 0) {
                $xs[] = (int) $row['x'];
            }
        }
        $sorted = $xs;
        sort($sorted);
        self::assertSame($sorted, $xs, 'left-to-right order on a row is unchanged');
    }

    public function testGridMaxWidthCapsEachBreakpoint(): void
    {
        self::assertSame([12 => 1600, 18 => 2400, 24 => 3200], WidgetService::GRID_MAX_WIDTH);
        self::assertArrayNotHasKey(1, WidgetService::GRID_MAX_WIDTH);
    }

    public function testColumnsForWidthHoldsStillInsideTheHysteresisBand(): void
    {
        self::assertSame(12, WidgetService::columnsForWidth(1280));
        self::assertSame(12, WidgetService::columnsForWidth(1024));
        self::assertSame(18, WidgetService::columnsForWidth(1920));
        self::assertSame(18, WidgetService::columnsForWidth(2560));
        self::assertSame(24, WidgetService::columnsForWidth(3440));
        self::assertSame(1, WidgetService::columnsForWidth(700));

        self::assertSame(12, WidgetService::columnsForWidth(1599, 12));
        self::assertSame(18, WidgetService::columnsForWidth(1600, 12));
        self::assertSame(18, WidgetService::columnsForWidth(1500, 18), 'dragging back a little does not return to 12');
        self::assertSame(12, WidgetService::columnsForWidth(1479, 18));
        self::assertSame(24, WidgetService::columnsForWidth(3320, 24));
        self::assertSame(18, WidgetService::columnsForWidth(3319, 24));
        self::assertSame(1, WidgetService::columnsForWidth(750, 1));
        self::assertSame(12, WidgetService::columnsForWidth(820, 1));
    }

    public function testPlacementLockKeepsItsRectangle(): void
    {
        $stored = ['x' => 2, 'y' => 4, 'w' => 3, 'h' => 5];
        $incoming = ['x' => 0, 'y' => 9, 'w' => 6, 'h' => 2];
        self::assertSame($stored, WidgetService::heldRect(true, 1, $stored, $incoming));
        self::assertSame($stored, WidgetService::heldRect(true, null, $stored, $incoming));
        self::assertSame($incoming, WidgetService::heldRect(true, 0, $stored, $incoming));
        self::assertSame($incoming, WidgetService::heldRect(false, 1, $stored, $incoming));

        $packed = WidgetService::pack([
            ['id' => 1, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'placement_locked' => 1],
            ['id' => 2, 'w' => 4, 'h' => 2],
        ]);
        $byId = [];
        foreach ($packed as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertSame([0, 0, 4, 3], [$byId[1]['x'], $byId[1]['y'], $byId[1]['w'], $byId[1]['h']]);
        self::assertSame([4, 0], [$byId[2]['x'], $byId[2]['y']]);

        $up = WidgetService::packUp([
            ['id' => 1, 'x' => 0, 'y' => 5, 'w' => 4, 'h' => 2, 'placement_locked' => 1],
            ['id' => 2, 'x' => 6, 'y' => 8, 'w' => 4, 'h' => 2],
        ]);
        $byId = [];
        foreach ($up as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertSame(5, $byId[1]['y']);
        self::assertSame(0, $byId[2]['y']);
    }
}
