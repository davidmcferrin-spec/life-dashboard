<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class TrackingTest extends PgsqlTestCase
{
    private Services $svc;
    private int $user;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->user  = $this->makeUser('david');
        $this->other = $this->makeUser('ann');
        // Fixed "now": 2026-09-25 15:00 UTC = 10:00 CDT.
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-09-25 15:00:00', new DateTimeZone('UTC'))));
    }

    private function makeUser(string $name): int
    {
        $this->db->execute('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)', [$name, "{$name}@x", 'h']);
        return (int) $this->db->lastInsertId();
    }

    public function testCreateMetricGeneratesUniqueKeysAndValidatesConfig(): void
    {
        $a = $this->svc->metrics->create($this->user, ['name' => 'Drink water', 'kind' => 'habit', 'config' => ['emoji' => '💧', 'target_per_week' => 7]]);
        $b = $this->svc->metrics->create($this->user, ['name' => 'Drink water', 'kind' => 'habit']);
        self::assertSame('drink_water', $a['key']);
        self::assertSame('drink_water_2', $b['key']);
        self::assertSame(['emoji' => '💧', 'target_per_week' => 7], $a['config']);

        try {
            $this->svc->metrics->create($this->user, ['name' => 'X', 'kind' => 'habit', 'config' => ['bogus' => 1]]);
            self::fail('unknown config key accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('config', $e->fields());
        }
    }

    public function testMetricsAreIsolatedPerUser(): void
    {
        $m = $this->svc->metrics->create($this->user, ['name' => 'Weight', 'kind' => 'number']);
        $this->expectException(NotFoundException::class);
        $this->svc->metrics->get($this->other, $m['id']);
    }

    public function testRecordComputesLocalDateAndHonoursDedupe(): void
    {
        $m = $this->svc->metrics->create($this->user, ['name' => 'Steps', 'kind' => 'number']);
        // 03:30 UTC on the 25th is still the 24th in Chicago.
        $p = $this->svc->points->record($this->user, $m['id'], ['value' => 1000, 'ts' => '2026-09-25T03:30:00Z']);
        self::assertSame('2026-09-24', $p['date']);
        self::assertSame('2026-09-25 03:30:00', $p['ts']);

        $x = $this->svc->points->record($this->user, $m['id'], ['value' => 5000, 'dedupe_key' => 'fitbit:abc']);
        $y = $this->svc->points->record($this->user, $m['id'], ['value' => 6000, 'dedupe_key' => 'fitbit:abc']);
        self::assertSame($x['id'], $y['id'], 'same dedupe_key upserts');
        self::assertSame(6000.0, $y['value']);
        self::assertCount(2, $this->svc->points->list($this->user, $m['id']));
    }

    public function testValueValidationPerKind(): void
    {
        $mood = $this->svc->metrics->ensureMood($this->user);
        $this->svc->points->record($this->user, $mood['id'], ['value' => 4]);
        foreach ([0, 6, 3.5, 'abc'] as $bad) {
            try {
                $this->svc->points->record($this->user, $mood['id'], ['value' => $bad]);
                self::fail("accepted mood value " . var_export($bad, true));
            } catch (ValidationException) {
                // expected
            }
        }
        try {
            $this->svc->points->record($this->user, $mood['id'], ['value' => 3, 'ts' => '2026-09-26T12:00:00Z']);
            self::fail('future timestamp accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ts', $e->fields());
        }
    }

    public function testHabitToggleStreakAndWeekCount(): void
    {
        $h = $this->svc->metrics->create($this->user, ['name' => 'Read', 'kind' => 'habit']);
        foreach (['2026-09-22', '2026-09-23', '2026-09-24'] as $d) {
            $this->svc->habits->toggle($this->user, $h['id'], $d);
        }
        $s = $this->svc->habits->summary($this->user, null, 7)['habits'][0];
        self::assertFalse($s['done_today']);
        self::assertSame(3, $s['streak'], 'streak alive through today until midnight');
        self::assertSame(3, $s['week_count'], 'Mon 22 – Thu 24');

        $r = $this->svc->habits->toggle($this->user, $h['id']);
        self::assertTrue($r['done']);
        self::assertSame('2026-09-25', $r['date']);
        self::assertSame(4, $this->svc->habits->summary($this->user)['habits'][0]['streak']);

        $r = $this->svc->habits->toggle($this->user, $h['id']);
        self::assertFalse($r['done'], 'second toggle undoes');
        self::assertSame(3.0, $this->svc->points->aggregate($this->user, $h['id'], 'count', null, null));

        $this->expectException(ValidationException::class);
        $this->svc->habits->toggle($this->user, $h['id'], '2026-09-26');
    }

    public function testSeriesBucketsByLocalDay(): void
    {
        $m = $this->svc->metrics->create($this->user, ['name' => 'Sleep', 'kind' => 'number']);
        $this->svc->points->record($this->user, $m['id'], ['value' => 6, 'date' => '2026-09-20']);
        $this->svc->points->record($this->user, $m['id'], ['value' => 8, 'date' => '2026-09-20']);
        $this->svc->points->record($this->user, $m['id'], ['value' => 7, 'date' => '2026-09-21']);

        $days = $this->svc->points->series($this->user, $m['id'], 'day', 'avg', '2026-09-19', '2026-09-25');
        self::assertSame([
            ['bucket' => '2026-09-20', 'value' => 7.0, 'count' => 2],
            ['bucket' => '2026-09-21', 'value' => 7.0, 'count' => 1],
        ], $days);

        $weeks = $this->svc->points->series($this->user, $m['id'], 'week', 'sum', '2026-09-01', '2026-09-25');
        self::assertSame('2026-09-14', $weeks[0]['bucket'], 'Sunday 20th belongs to the week of Monday 14th');
        self::assertSame(14.0, $weeks[0]['value']);
        self::assertSame('2026-09-21', $weeks[1]['bucket']);
        self::assertSame(3.0, $this->svc->points->aggregate($this->user, $m['id'], 'count', null, null));
        self::assertSame(7.0, $this->svc->points->aggregate($this->user, $m['id'], 'last', null, null));
    }

    public function testNotifyTriggerFiresOnDatapointInsert(): void
    {
        $m = $this->svc->metrics->create($this->user, ['name' => 'Water', 'kind' => 'number']);
        $pdo = $this->db->pdo();
        $pdo->exec('LISTEN lifedash_events');
        $this->svc->points->record($this->user, $m['id'], ['value' => 1]);

        $seen = [];
        while (($n = $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, 500)) !== false) {
            $seen[] = json_decode($n['payload'], true);
        }
        $pdo->exec('UNLISTEN lifedash_events');

        $dp = array_values(array_filter($seen, static fn (array $e): bool => $e['table'] === 'datapoints'));
        self::assertCount(1, $dp);
        self::assertEquals(['table' => 'datapoints', 'op' => 'insert', 'user_id' => $this->user, 'metric_id' => $m['id']], array_diff_key($dp[0], ['id' => 1]));
        self::assertIsInt($dp[0]['id']);
    }
}
