<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class HabitsHealthTest extends PgsqlTestCase
{
    private Services $svc;

    private int $user;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['david', 'david@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ann', 'ann@x', 'h']
        );
        $this->other = (int) $this->db->lastInsertId();
        $this->svc = new Services(
            $this->db,
            new Clock('America/Chicago', new DateTimeImmutable('2026-11-22 18:00:00', new DateTimeZone('UTC')))
        );
    }

    public function testIncrementAddsToACountAndDoesNotTreatPartialAsDone(): void
    {
        $glasses = $this->svc->habits->create($this->user, [
            'name' => 'Water glasses',
            'key' => 'water_glasses',
            'daily_target' => 8,
        ]);
        $first = $this->svc->habits->incrementDatapoint($this->user, (int) $glasses['id'], null, 1, null, 'ai');
        $second = $this->svc->habits->incrementDatapoint($this->user, null, 'water_glasses', 1, null, 'ai');
        self::assertSame(2.0, $second['progress']);
        self::assertSame(8.0, $second['target']);
        self::assertSame(8.0, $second['threshold']);
        self::assertFalse($second['done']);
        self::assertFalse($second['done_today']);
        self::assertSame(6.0, $second['remaining']);
        self::assertSame(2.0, $first['progress'] + 1);

        $rows = $this->svc->points->list($this->user, (int) $glasses['id'], '2026-11-22', '2026-11-22', 10);
        self::assertCount(1, $rows);
        self::assertSame(2.0, $rows[0]['value']);

        $done = $this->svc->habits->set($this->user, (int) $glasses['id'], true, null, 'ai');
        self::assertTrue($done['done']);
        self::assertSame(8.0, $done['progress']);
        $again = $this->svc->habits->set($this->user, (int) $glasses['id'], true, null, 'ai');
        self::assertSame(8.0, $again['progress']);
        $off = $this->svc->habits->set($this->user, (int) $glasses['id'], false, null, 'ai');
        self::assertFalse($off['done']);
        $offAgain = $this->svc->habits->set($this->user, (int) $glasses['id'], false, null, 'ai');
        self::assertFalse($offAgain['done']);

        $flipped = $this->svc->habits->toggle($this->user, (int) $glasses['id'], null, 'widget');
        self::assertTrue($flipped['done']);
        self::assertSame(8.0, $flipped['progress']);
        $back = $this->svc->habits->toggle($this->user, (int) $glasses['id'], null, 'widget');
        self::assertFalse($back['done']);

        try {
            $this->svc->habits->incrementDatapoint($this->other, (int) $glasses['id'], null, 1, null, 'ai');
            self::fail('another user incremented the habit');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
    }

    public function testTodayStatusIncludesHabitsAndGoals(): void
    {
        $water = $this->svc->metrics->create($this->user, [
            'name' => 'Water',
            'key' => 'water_ml',
            'kind' => 'number',
            'unit' => 'ml',
            'config' => ['goal' => 8],
        ]);
        $this->svc->habits->create($this->user, ['name' => 'Read', 'key' => 'read']);
        $this->svc->habits->incrementDatapoint($this->user, (int) $water['id'], null, 3, null, 'ai');
        $this->svc->habits->incrementDatapoint($this->user, (int) $water['id'], null, 1, null, 'ai');

        $status = $this->svc->habits->todayStatus($this->user);
        self::assertSame('2026-11-22', $status['today']);
        self::assertStringContainsString('daily_target', $status['done_means']);
        $byKey = [];
        foreach ($status['items'] as $item) {
            $byKey[$item['key']] = $item;
        }
        self::assertSame('habit', $byKey['read']['kind']);
        self::assertFalse($byKey['read']['done']);
        self::assertSame(1.0, $byKey['read']['target']);
        self::assertSame('goal', $byKey['water_ml']['kind']);
        self::assertSame(4.0, $byKey['water_ml']['progress']);
        self::assertFalse($byKey['water_ml']['done']);
        self::assertSame(4.0, $byKey['water_ml']['remaining']);

        $met = $this->svc->habits->incrementDatapoint($this->user, (int) $water['id'], null, 4, null, 'ai');
        self::assertTrue($met['done']);
        self::assertSame(0.0, $met['remaining']);
        self::assertSame('water:2026-11-22', $met['dedupe_key']);
    }

    public function testMealLogChecksTheMatchingHabit(): void
    {
        $this->svc->habits->create($this->user, ['name' => 'Breakfast', 'key' => 'breakfast']);
        $this->svc->habits->create($this->user, ['name' => 'Eat at home', 'key' => 'eat_at_home']);
        $this->svc->habits->create($this->user, ['name' => 'Lunch', 'key' => 'lunch']);
        $this->svc->habits->create($this->user, ['name' => 'Meal prep', 'key' => 'meal_prep']);

        $out = $this->svc->healthLog->logMeal($this->user, [
            'meal_type' => 'lunch',
            'description' => 'Soup at the cafe',
            'eaten_out' => true,
            'place' => 'Cafe',
            'cost' => 12.5,
        ], 'ai');
        $marked = array_column($out['habits'], 'key');
        sort($marked);
        self::assertSame(['lunch'], $marked);
        self::assertSame('lunch', $out['meal']['meal_type']);
        self::assertTrue($out['meal']['eaten_out']);
        self::assertSame(12.5, $out['meal']['cost']);

        $home = $this->svc->healthLog->logMeal($this->user, [
            'meal_type' => 'breakfast',
            'description' => 'Eggs',
            'items' => ['eggs', ['name' => 'toast', 'quantity' => '2']],
            'eaten_out' => false,
        ], 'ai');
        $homeKeys = array_column($home['habits'], 'key');
        sort($homeKeys);
        self::assertSame(['breakfast', 'eat_at_home'], $homeKeys);
        self::assertNotContains('meal_prep', $homeKeys);

        $listed = $this->svc->healthLog->listMeals($this->user, '2026-11-22', '2026-11-22');
        self::assertCount(2, $listed['meals']);
        self::assertSame([], $this->svc->healthLog->listMeals($this->other, '2026-11-22', '2026-11-22')['meals']);

        $summary = $this->svc->habits->summary($this->user);
        $byKey = [];
        foreach ($summary['habits'] as $habit) {
            $byKey[$habit['key']] = $habit;
        }
        self::assertTrue($byKey['breakfast']['done']);
        self::assertTrue($byKey['breakfast']['done_today']);
        self::assertTrue($byKey['eat_at_home']['done']);
        self::assertFalse($byKey['meal_prep']['done']);
    }

    public function testBloodPressureMedicationsAndAppointmentsStayPerUser(): void
    {
        $this->svc->healthLog->logBloodPressure($this->user, [
            'systolic' => 120,
            'diastolic' => 80,
            'pulse' => 60,
            'arm' => 'left',
            'position' => 'sitting',
        ]);
        $this->svc->healthLog->logBloodPressure($this->user, [
            'systolic' => 130,
            'diastolic' => 85,
            'pulse' => 70,
        ]);
        $listed = $this->svc->healthLog->listBloodPressure($this->user, '2026-11-22', '2026-11-22');
        self::assertCount(2, $listed['readings']);
        self::assertSame(125.0, $listed['average']['systolic']);
        self::assertSame(82.5, $listed['average']['diastolic']);
        self::assertSame(65.0, $listed['average']['pulse']);
        self::assertSame(0, $this->svc->healthLog->listBloodPressure($this->other, '2026-11-22', '2026-11-22')['average']['count']);

        $med = $this->svc->healthLog->addMedication($this->user, [
            'name' => 'Lisinopril',
            'dose' => '10 mg',
            'schedule' => 'morning',
            'started_on' => '2026-01-01',
        ]);
        $id = (int) $med['medication']['id'];
        self::assertTrue($med['medication']['active']);
        $dose = $this->svc->healthLog->logDose($this->user, $id, ['notes' => 'with breakfast']);
        self::assertSame($id, $dose['dose']['medication_id']);
        $updated = $this->svc->healthLog->updateMedication($this->user, $id, ['active' => false]);
        self::assertFalse($updated['medication']['active']);
        self::assertSame('Lisinopril', $updated['medication']['name']);
        self::assertSame([], $this->svc->healthLog->listMedications($this->user, true)['medications']);
        self::assertCount(1, $this->svc->healthLog->listMedications($this->user, null)['medications']);
        try {
            $this->svc->healthLog->logDose($this->other, $id, []);
            self::fail('logged a dose on someone else\'s medication');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }

        $visit = $this->svc->healthLog->addAppointment($this->user, [
            'provider' => 'Dr Lee',
            'specialty' => 'Cardiology',
            'starts_at' => '2026-11-23 15:00:00',
            'location' => 'Clinic',
            'reason' => 'Follow up',
        ]);
        $apptId = (int) $visit['appointment']['id'];
        $upcoming = $this->svc->healthLog->listAppointments($this->user, true, null, null);
        self::assertCount(1, $upcoming['appointments']);
        self::assertSame('Dr Lee', $upcoming['appointments'][0]['provider']);
        $done = $this->svc->healthLog->updateAppointment($this->user, $apptId, [
            'status' => 'completed',
            'follow_up' => 'Recheck in six months',
        ]);
        self::assertSame('completed', $done['appointment']['status']);
        self::assertSame($visit['appointment']['starts_at'], $done['appointment']['starts_at']);
        self::assertSame([], $this->svc->healthLog->listAppointments($this->user, true, null, null)['appointments']);
        self::assertSame([], $this->svc->healthLog->listAppointments($this->other, true, null, null)['appointments']);
    }
}
