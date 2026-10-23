<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Jarvis\Wizard;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class JarvisWizardFlowTest extends PgsqlTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
    }

    public function testIdentitySavesTheNameWithoutChangingTheAccount(): void
    {
        $wizard = $this->wizardAt('2026-09-25 15:00:00');
        $state = $wizard->saveIdentity($this->user, 'Dana', 'JARVIS', 'concise', 'david');

        self::assertSame('david', $this->db->fetchScalar('SELECT username FROM users WHERE id = ?', [$this->user]));
        self::assertSame('Dana', $state['identity']['display_name']);
        self::assertSame('JARVIS', $state['identity']['assistant_name']);
        self::assertSame('concise', $state['identity']['style']);
        self::assertTrue($state['identity']['saved']);
        self::assertSame(
            'Good morning, Dana. I will keep it short.',
            Wizard::greeting((int) $state['hour'], $state['identity']['display_name'], $state['identity']['style'])
        );
    }

    public function testMorningBriefUsesASavedDigestAndSkipsWhenOff(): void
    {
        $this->digest('Agents shipped', '2026-09-25 12:00:00');
        $wizard = $this->wizardAt('2026-09-25 12:00:00');
        $wizard->saveRoutines($this->user, [
            'morning_brief' => true,
            'meeting_prep' => false,
            'focus_mode' => false,
            'day_summary' => false,
        ], 'david');
        $wizard->savePrivacy($this->user, $this->privacy('allowed', 'off'), 'david');

        $ran = $wizard->runDue($this->user);
        self::assertSame(1, $ran['ran']);
        self::assertStringContainsString('Agents shipped', $this->summary('morning_brief'));
        self::assertSame(['ran' => 0, 'waiting' => 0], $wizard->runDue($this->user));

        $this->db->execute('DELETE FROM jarvis_routine_runs WHERE user_id = ?', [$this->user]);
        $wizard->saveRoutines($this->user, [
            'morning_brief' => false,
            'meeting_prep' => false,
            'focus_mode' => false,
            'day_summary' => false,
        ], 'david');
        self::assertSame(['ran' => 0, 'waiting' => 0], $wizard->runDue($this->user));
        self::assertNull($this->summary('morning_brief'));
    }

    public function testMeetingPrepWaitsUntilConfirmedAndRespectsCalendar(): void
    {
        $this->todo('Budget review', 'open', '2026-09-25', null);
        $wizard = $this->wizardAt('2026-09-25 15:00:00');
        $wizard->saveRoutines($this->user, [
            'morning_brief' => false,
            'meeting_prep' => true,
            'focus_mode' => false,
            'day_summary' => false,
        ], 'david');
        $wizard->savePrivacy($this->user, $this->privacy('ask', 'allowed'), 'david');

        $waiting = $wizard->runDue($this->user);
        self::assertSame(1, $waiting['waiting']);
        self::assertSame('Waiting for permission to run.', $this->summary('meeting_prep'));
        self::assertStringNotContainsString('Budget review', (string) $this->summary('meeting_prep'));

        $confirmed = $wizard->confirmRoutine($this->user, 'meeting_prep', 'david');
        $meeting = $this->routine($confirmed, 'meeting_prep');
        self::assertSame('ran', $meeting['run_status']);
        self::assertStringContainsString('Budget review', $meeting['run_summary']);

        $this->db->execute('DELETE FROM jarvis_routine_runs WHERE user_id = ?', [$this->user]);
        $wizard->savePrivacy($this->user, $this->privacy('allowed', 'off'), 'david');
        self::assertSame(['ran' => 0, 'waiting' => 0], $wizard->runDue($this->user));
        self::assertNull($this->summary('meeting_prep'));
    }

    public function testFocusAndDaySummaryReadOpenAndFinishedTasks(): void
    {
        $this->todo('Write the brief', 'open', null, null);
        $focus = $this->wizardAt('2026-09-25 15:00:00');
        $focus->saveRoutines($this->user, [
            'morning_brief' => false,
            'meeting_prep' => false,
            'focus_mode' => true,
            'day_summary' => false,
        ], 'david');
        $focus->savePrivacy($this->user, $this->privacy('allowed', 'off'), 'david');
        self::assertSame(1, $focus->runDue($this->user)['ran']);
        self::assertStringContainsString('Write the brief', (string) $this->summary('focus_mode'));

        $this->db->execute('DELETE FROM jarvis_routine_runs WHERE user_id = ?', [$this->user]);
        $this->todo('Close the books', 'completed', null, '2026-09-25 23:00:00');
        $evening = $this->wizardAt('2026-09-25 23:00:00');
        $evening->saveRoutines($this->user, [
            'morning_brief' => false,
            'meeting_prep' => false,
            'focus_mode' => false,
            'day_summary' => true,
        ], 'david');
        $evening->savePrivacy($this->user, $this->privacy('allowed', 'off'), 'david');
        self::assertSame(1, $evening->runDue($this->user)['ran']);
        self::assertStringContainsString('Close the books', (string) $this->summary('day_summary'));
    }

    public function testInitializeRequiresIdentityAndReportsWhatWasSaved(): void
    {
        $wizard = $this->wizardAt('2026-09-25 15:00:00');
        try {
            $wizard->initialize($this->user, 'david');
            self::fail('Initialize ran without a name.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('identity', $e->fields());
        }

        $wizard->saveIdentity($this->user, 'Dana', 'JARVIS', 'analytical', 'david');
        $wizard->saveVoice($this->user, 'nova', 'david');
        $wizard->saveRoutines($this->user, [
            'morning_brief' => true,
            'meeting_prep' => false,
            'focus_mode' => true,
            'day_summary' => false,
        ], 'david');
        $state = $wizard->initialize($this->user, 'david');

        self::assertTrue($state['initialize']['initialized']);
        self::assertFalse($state['initialize']['ready']);
        $checks = [];
        foreach ($state['initialize']['checks'] as $check) {
            $checks[$check['label']] = $check['value'];
        }
        self::assertSame('Verified', $checks['Voice profile']);
        self::assertSame('2 active', $checks['Routines']);
        self::assertSame('Not set', $checks['Privacy protocol']);
        self::assertSame('0 linked', $checks['Service connections']);

        $ready = $wizard->savePrivacy($this->user, $this->privacy('ask', 'allowed'), 'david');
        self::assertTrue($ready['initialize']['ready']);
        self::assertTrue($ready['initialize']['initialized']);
    }

    private function wizardAt(string $utc): Wizard
    {
        return new Wizard(
            $this->db,
            new Clock('America/Chicago', new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        );
    }

    /**
     * @return array{calendar_context: string, microphone: string, memory: string, proactive: string}
     */
    private function privacy(string $proactive, string $calendar): array
    {
        return [
            'calendar_context' => $calendar,
            'microphone' => 'while_active',
            'memory' => 'off',
            'proactive' => $proactive,
        ];
    }

    private function digest(string $title, string $seenAt): void
    {
        $this->db->execute(
            "INSERT INTO digest_sources (user_id, product, label, url) VALUES (?, 'cursor_ide', 'Notes', ?)",
            [$this->user, 'https://example.test/jarvis-source']
        );
        $sourceId = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO digest_entries (user_id, source_id, entry_key, title, url, summary, content_hash, first_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->user,
                $sourceId,
                'brief',
                $title,
                'https://example.test/jarvis-entry',
                '',
                str_repeat('b', 64),
                $seenAt,
            ]
        );
    }

    private function todo(string $title, string $status, ?string $due, ?string $completedAt): void
    {
        $listId = $this->db->fetchScalar(
            'SELECT id FROM user_todo_lists WHERE user_id = ? AND slug = ?',
            [$this->user, 'today']
        );
        if ($listId === null) {
            $this->db->execute(
                "INSERT INTO user_todo_lists (user_id, name, slug) VALUES (?, 'Today', 'today')",
                [$this->user]
            );
            $listId = (int) $this->db->lastInsertId();
        }
        $this->db->execute(
            'INSERT INTO user_todo_items (list_id, title, status, created_by_user_id, due_date, completed_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$listId, $title, $status, $this->user, $due, $completedAt]
        );
    }

    private function summary(string $key): ?string
    {
        $value = $this->db->fetchScalar(
            'SELECT summary FROM jarvis_routine_runs WHERE user_id = ? AND routine_key = ?',
            [$this->user, $key]
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function routine(array $state, string $key): array
    {
        foreach ($state['routines'] as $routine) {
            if ($routine['key'] === $key) {
                return $routine;
            }
        }
        self::fail('Missing routine ' . $key);
    }
}
