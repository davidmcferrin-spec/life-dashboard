<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class GoalProgressTest extends PgsqlTestCase
{
    private Services $svc;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['david', 'david@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services(
            $this->db,
            new Clock('America/Chicago', new DateTimeImmutable('2026-10-15 15:00:00', new DateTimeZone('UTC')))
        );
    }

    public function testDayAndWeekUseTheLastValueAndCapAtDouble(): void
    {
        $water = $this->svc->metrics->create($this->user, [
            'name' => 'Water',
            'key'  => 'water_ml',
            'kind' => 'number',
            'unit' => 'ml',
            'config' => ['goal' => 3000, 'decimals' => 0],
        ]);
        $habit = $this->svc->habits->create($this->user, [
            'name' => 'Water',
            'key'  => 'water',
            'goal_metric_id' => $water['id'],
        ]);
        self::assertSame($water['id'], $habit['goal_metric_id']);
        self::assertNull($habit['goal_threshold']);

        $this->log($water['id'], '2026-10-13', 1000, 'water:2026-10-13');
        $this->log($water['id'], '2026-10-13', 9000, 'water:2026-10-13');
        $this->log($water['id'], '2026-10-14', 1500, 'water:2026-10-14');
        $this->log($water['id'], '2026-10-15', 1000, 'water:2026-10-15');
        self::assertFalse($this->doneOn('2026-10-15'));
        $this->log($water['id'], '2026-10-15', 4500, 'water:2026-10-15');
        self::assertTrue($this->doneOn('2026-10-15'));
        self::assertTrue($this->doneOn('2026-10-13'));
        self::assertFalse($this->doneOn('2026-10-14'));

        $check = $this->svc->points->list($this->user, $habit['id'], '2026-10-15', '2026-10-15', 5);
        self::assertCount(1, $check);
        self::assertSame('ai', $check[0]['source']);

        $this->svc->habits->toggle($this->user, $habit['id'], '2026-10-15');
        self::assertFalse($this->doneOn('2026-10-15'));
        $this->log($water['id'], '2026-10-15', 2000, 'water:2026-10-15');
        self::assertFalse($this->doneOn('2026-10-15'), 'under the goal stays unchecked');
        $this->log($water['id'], '2026-10-15', 3000, 'water:2026-10-15');
        self::assertTrue($this->doneOn('2026-10-15'), 'the next log at the goal checks it again');
        $this->log($water['id'], '2026-10-15', 500, 'water:2026-10-15');
        self::assertTrue($this->doneOn('2026-10-15'), 'dropping under the goal leaves the check');

        $day = $this->svc->widgets->create($this->user, 'goal', 'Water today', [
            'metric_id' => $water['id'],
            'view' => 'day',
        ]);
        $dayData = $this->svc->widgetData->forWidget($this->user, $day['id']);
        self::assertSame([$water['id']], $dayData['metric_ids']);
        self::assertSame('day', $dayData['view']);
        self::assertSame(500.0, $dayData['value']);
        self::assertSame(3000.0, $dayData['goal']);
        self::assertEqualsWithDelta(500 / 3000, $dayData['ratio'], 0.000001);
        self::assertSame(17, $dayData['percent']);

        $week = $this->svc->widgets->create($this->user, 'goal', 'Water week', [
            'metric_id' => $water['id'],
            'view' => 'week',
        ]);
        $weekData = $this->svc->widgetData->forWidget($this->user, $week['id']);
        self::assertSame('week', $weekData['view']);
        self::assertCount(7, $weekData['days']);
        $byDate = [];
        foreach ($weekData['days'] as $cell) {
            $byDate[$cell['date']] = $cell;
        }
        self::assertSame(0, $byDate['2026-10-12']['percent']);
        self::assertNull($byDate['2026-10-12']['value']);
        self::assertSame(200, $byDate['2026-10-13']['percent']);
        self::assertSame(2.0, $byDate['2026-10-13']['ratio']);
        self::assertSame(9000.0, $byDate['2026-10-13']['value']);
        self::assertSame(50, $byDate['2026-10-14']['percent']);
        self::assertSame(17, $byDate['2026-10-15']['percent']);
        self::assertTrue($byDate['2026-10-15']['today']);
        self::assertTrue($byDate['2026-10-16']['future']);
        self::assertSame(0, $byDate['2026-10-18']['percent']);

        self::assertSame([250.0, 500.0], $dayData['increments']);
        self::assertSame('water:2026-10-15', $dayData['dedupe_key']);
        self::assertSame('water:2026-10-15', $weekData['dedupe_key']);

        $cleared = $this->svc->habits->update($this->user, $habit['id'], ['goal_metric_id' => 0]);
        self::assertNull($cleared['goal_metric_id']);
        $this->svc->habits->toggle($this->user, $habit['id'], '2026-10-15');
        $this->log($water['id'], '2026-10-15', 9000, 'water:2026-10-15');
        self::assertFalse($this->doneOn('2026-10-15'));
    }

    public function testQuickAddUpdatesTheSharedDailyTotal(): void
    {
        $water = $this->svc->metrics->create($this->user, [
            'name' => 'Water',
            'key'  => 'water_ml',
            'kind' => 'number',
            'unit' => 'ml',
            'config' => ['goal' => 3000, 'decimals' => 0],
        ]);
        $habit = $this->svc->habits->create($this->user, [
            'name' => 'Water',
            'key'  => 'water',
            'goal_metric_id' => $water['id'],
        ]);
        $widget = $this->svc->widgets->create($this->user, 'goal', 'Water today', [
            'metric_id' => $water['id'],
        ]);

        // A total already stored under a different key is adopted once, then
        // every later add updates water:YYYY-MM-DD so the two writers agree.
        $this->log($water['id'], '2026-10-15', 2800, 'import:2026-10-15');
        $first = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 250, 'widget');
        self::assertSame('water:2026-10-15', $first['dedupe_key']);
        self::assertSame(3050.0, $first['value']);
        self::assertTrue($this->doneOn('2026-10-15', $habit['id']));
        $rows = $this->svc->points->list($this->user, $water['id'], '2026-10-15', '2026-10-15', 10);
        self::assertCount(2, $rows);
        $canonical = array_values(array_filter($rows, static fn (array $r): bool => $r['dedupe_key'] === 'water:2026-10-15'));
        self::assertCount(1, $canonical);
        self::assertSame('widget', $canonical[0]['source']);

        $second = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 250, 'widget');
        self::assertSame(3300.0, $second['value']);
        $canonical = array_values(array_filter(
            $this->svc->points->list($this->user, $water['id'], '2026-10-15', '2026-10-15', 10),
            static fn (array $r): bool => $r['dedupe_key'] === 'water:2026-10-15'
        ));
        self::assertCount(1, $canonical);
        self::assertSame(3300.0, $canonical[0]['value']);

        $undo = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], -800, 'widget');
        self::assertSame(2500.0, $undo['value']);
        self::assertTrue($this->doneOn('2026-10-15', $habit['id']), 'dropping under the goal leaves the check');
        $floor = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], -99999, 'widget');
        self::assertSame(0.0, $floor['value']);

        $steps = $this->svc->metrics->create($this->user, [
            'name' => 'Steps', 'key' => 'steps', 'kind' => 'number', 'config' => ['goal' => 8000],
        ]);
        $walk = $this->svc->widgets->create($this->user, 'goal', 'Steps', [
            'metric_id' => $steps['id'],
            'increments' => '100, 200',
            'dedupe_prefix' => 'walk',
        ]);
        $added = $this->svc->widgetData->adjustGoal($this->user, $walk['id'], 100, 'widget');
        self::assertSame('walk:2026-10-15', $added['dedupe_key']);
        self::assertSame([100.0, 200.0], $added['data']['increments']);

        $stocks = $this->svc->widgets->create($this->user, 'legacy.stocks', null, []);
        try {
            $this->svc->widgetData->adjustGoal($this->user, $stocks['id'], 1, 'widget');
            self::fail('logged against a stocks widget');
        } catch (ValidationException $e) {
            self::assertStringContainsString('goal widget', $e->getMessage());
        }
        try {
            $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 0, 'widget');
            self::fail('accepted a zero delta');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('delta', $e->fields());
        }
    }

    public function testSeveralGoalsShareOneCardAndCountsUsePips(): void
    {
        $water = $this->svc->metrics->create($this->user, [
            'name' => 'Water',
            'key'  => 'water_ml',
            'kind' => 'number',
            'unit' => 'ml',
            'config' => ['goal' => 3000, 'decimals' => 0],
        ]);
        $move = $this->svc->metrics->create($this->user, [
            'name' => 'Movement',
            'key'  => 'movement_sessions',
            'kind' => 'number',
            'config' => ['goal' => 3, 'decimals' => 0],
        ]);
        $steps = $this->svc->metrics->create($this->user, [
            'name' => 'Steps',
            'key'  => 'steps',
            'kind' => 'number',
            'unit' => 'steps',
            'config' => ['goal' => 8000],
        ]);
        $habit = $this->svc->habits->create($this->user, [
            'name' => 'Water',
            'key'  => 'water',
            'goal_metric_id' => $water['id'],
        ]);
        $widget = $this->svc->widgets->create($this->user, 'goal', 'Goals', [
            'metric_ids' => [$water['id'], $move['id'], $steps['id']],
            'view' => 'day',
            'logs' => [
                (string) $water['id'] => ['increments' => '250,500'],
                (string) $move['id'] => ['mode' => 'count', 'increments' => '1'],
                (string) $steps['id'] => ['increments' => '1000'],
            ],
        ]);
        self::assertSame([$water['id'], $move['id'], $steps['id']], $widget['config']['metric_ids']);

        $added = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 250, 'widget', $water['id']);
        self::assertSame('water:2026-10-15', $added['dedupe_key']);
        self::assertSame(250.0, $added['value']);
        $again = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 2750, 'widget', $water['id']);
        self::assertSame(3000.0, $again['value']);
        self::assertTrue($this->doneOn('2026-10-15', $habit['id']));
        $waterRows = array_values(array_filter(
            $this->svc->points->list($this->user, $water['id'], '2026-10-15', '2026-10-15', 10),
            static fn (array $r): bool => $r['dedupe_key'] === 'water:2026-10-15'
        ));
        self::assertCount(1, $waterRows);

        $pip = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 1, 'widget', $move['id']);
        self::assertSame('movement:2026-10-15', $pip['dedupe_key']);
        self::assertSame(1.0, $pip['value']);
        $pip = $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 1, 'widget', $move['id']);
        self::assertSame(2.0, $pip['value']);
        $moveRows = array_values(array_filter(
            $this->svc->points->list($this->user, $move['id'], '2026-10-15', '2026-10-15', 10),
            static fn (array $r): bool => $r['dedupe_key'] === 'movement:2026-10-15'
        ));
        self::assertCount(1, $moveRows);

        $data = $pip['data'];
        self::assertCount(3, $data['goals']);
        self::assertSame('amount', $data['goals'][0]['mode']);
        self::assertSame('count', $data['goals'][1]['mode']);
        self::assertSame(6, $data['goals'][1]['pips']);
        self::assertSame(2.0, $data['goals'][1]['value']);
        self::assertSame(67, $data['goals'][1]['percent']);
        self::assertSame('amount', $data['goals'][2]['mode']);
        self::assertSame([1000.0], $data['goals'][2]['increments']);

        try {
            $this->svc->widgetData->adjustGoal($this->user, $widget['id'], 1, 'widget');
            self::fail('logged without a metric');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('metric_id', $e->fields());
        }

        $week = $this->svc->widgets->create($this->user, 'goal', 'Goals this week', [
            'metric_ids' => [$water['id'], $move['id']],
            'view' => 'week',
        ]);
        $weekData = $this->svc->widgetData->forWidget($this->user, $week['id']);
        self::assertCount(2, $weekData['goals']);
        self::assertCount(7, $weekData['goals'][0]['days']);
        self::assertCount(7, $weekData['goals'][1]['days']);
        self::assertSame(100, $weekData['goals'][0]['days'][3]['percent']);
        self::assertSame('movement:2026-10-15', $weekData['goals'][1]['dedupe_key']);
    }

    public function testThresholdOverridesTheMetricGoalAndRejectsAHabitLink(): void
    {
        $steps = $this->svc->metrics->create($this->user, [
            'name' => 'Steps', 'kind' => 'number', 'config' => ['goal' => 8000],
        ]);
        $walk = $this->svc->habits->create($this->user, ['name' => 'Walk']);
        $linked = $this->svc->habits->update($this->user, $walk['id'], [
            'goal_metric_id' => $steps['id'],
            'goal_threshold' => 10000,
        ]);
        self::assertSame(10000.0, $linked['goal_threshold']);

        $this->log($steps['id'], '2026-10-15', 9000, 'steps:2026-10-15');
        self::assertFalse($this->doneOn('2026-10-15', $walk['id']));
        $this->log($steps['id'], '2026-10-15', 10000, 'steps:2026-10-15');
        self::assertTrue($this->doneOn('2026-10-15', $walk['id']));

        $other = $this->svc->habits->create($this->user, ['name' => 'Stretch', 'key' => 'stretch']);
        try {
            $this->svc->habits->update($this->user, $other['id'], ['goal_metric_id' => $walk['id']]);
            self::fail('linked a habit');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('goal_metric_id', $e->fields());
        }

        $plain = $this->svc->metrics->create($this->user, ['name' => 'Notes', 'kind' => 'number']);
        $widget = $this->svc->widgets->create($this->user, 'goal', 'Notes', ['metric_id' => $plain['id']]);
        $data = $this->svc->widgetData->forWidget($this->user, $widget['id']);
        self::assertSame('Set a goal on this metric to show progress.', $data['error']);
    }

    public function testMigrationLinksWaterByKeyAndById(): void
    {
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['keys', 'keys@x', 'h']
        );
        $byKey = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ids', 'ids@x', 'h']
        );
        $byId = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['other', 'other@x', 'h']
        );
        $other = (int) $this->db->lastInsertId();

        $this->insertMetric($byKey, null, 'water', 'Water', 'habit', null, '{}');
        $this->insertMetric($byKey, null, 'water_ml', 'Water', 'number', '', '{"goal": 2500}');
        $this->insertMetric($byId, 13, 'sip', 'Water', 'habit', null, '{}');
        $this->insertMetric($byId, 17, 'drink', 'Drink', 'number', null, '{}');
        $this->insertMetric($other, null, 'water', 'Water', 'habit', null, '{}');

        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/pgsql/0019_goal_progress.sql');
        self::assertIsString($sql);
        $this->db->pdo()->exec($sql);

        $keyHabit = $this->svc->metrics->findByKey($byKey, 'water');
        $keyMetric = $this->svc->metrics->findByKey($byKey, 'water_ml');
        self::assertNotNull($keyHabit);
        self::assertNotNull($keyMetric);
        self::assertEquals(2500, (float) $keyMetric['config']['goal']);
        self::assertSame('ml', $keyMetric['unit']);
        self::assertSame((int) $keyMetric['id'], (int) $keyHabit['config']['goal_metric_id']);
        self::assertEquals(2500, (float) $keyHabit['config']['goal_threshold']);

        $idHabit = $this->svc->metrics->get($byId, 13);
        $idMetric = $this->svc->metrics->get($byId, 17);
        self::assertEquals(3000, (float) $idMetric['config']['goal']);
        self::assertSame(17, (int) $idHabit['config']['goal_metric_id']);
        self::assertEquals(3000, (float) $idHabit['config']['goal_threshold']);

        $untouched = $this->svc->metrics->findByKey($other, 'water');
        self::assertNotNull($untouched);
        self::assertArrayNotHasKey('goal_metric_id', $untouched['config']);
    }

    private function log(int $metricId, string $date, float $value, string $dedupe): void
    {
        $this->svc->points->record($this->user, $metricId, [
            'value' => $value,
            'date' => $date,
            'source' => 'ai',
            'dedupe_key' => $dedupe,
        ]);
    }

    private function doneOn(string $date, ?int $habitId = null): bool
    {
        if ($habitId === null) {
            $habit = $this->svc->metrics->findByKey($this->user, 'water');
            self::assertNotNull($habit);
            $habitId = (int) $habit['id'];
        }
        $dates = $this->svc->points->datesWithValue($this->user, $habitId, $date, $date);
        return in_array($date, $dates, true);
    }

    private function insertMetric(int $userId, ?int $id, string $key, string $name, string $kind, ?string $unit, string $config): void
    {
        if ($id === null) {
            $this->db->execute(
                'INSERT INTO metrics (user_id, key, name, kind, unit, config) VALUES (?, ?, ?, ?, ?, ?::jsonb)',
                [$userId, $key, $name, $kind, $unit, $config]
            );
            return;
        }
        $this->db->execute(
            'INSERT INTO metrics (id, user_id, key, name, kind, unit, config) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb)',
            [$id, $userId, $key, $name, $kind, $unit, $config]
        );
    }
}
