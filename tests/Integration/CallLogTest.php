<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class CallLogTest extends PgsqlTestCase
{
    private Services $svc;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-10-16 18:00:00', new DateTimeZone('UTC'))));
    }

    public function testUpsertDebtContactLookupAndStats(): void
    {
        $contact = $this->svc->calls->upsertContact($this->user, [
            'name' => 'Harbor Clinic',
            'phones' => ['(256) 555-0144'],
            'relationship' => 'client',
            'vip_tier' => 'gold',
            'handling_rule' => 'Put them through.',
        ]);
        $again = $this->svc->calls->upsertContact($this->user, [
            'name' => 'Harbor Clinic desk',
            'phones' => ['2565550144'],
            'notes' => 'Ask for the front desk.',
        ]);
        self::assertSame($contact['id'], $again['id']);
        self::assertSame('gold', $again['vip_tier']);
        self::assertSame(['(256) 555-0144'], $again['phones_display']);

        $agency = $this->svc->calls->upsertAgency($this->user, [
            'name' => 'Northwind Recovery',
            'phones' => ['2565550177'],
            'website' => 'https://northwind.example/collections',
            'verdict' => 'suspicious',
            'evidence_urls' => ['https://example.com/cfpb'],
        ]);
        $agencyAgain = $this->svc->calls->upsertAgency($this->user, ['name' => 'northwind recovery', 'notes' => 'No license on file.']);
        self::assertSame($agency['id'], $agencyAgain['id']);
        self::assertSame('suspicious', $agencyAgain['verdict']);

        $call = $this->svc->calls->log($this->user, [
            'direction' => 'inbound',
            'phone' => '256-555-0144',
            'name' => 'Harbor Clinic',
            'company' => 'Harbor Clinic',
            'category' => 'vip',
            'reason' => 'Schedule change',
            'summary' => 'Asked for a Thursday slot.',
            'urgency' => 'high',
            'return_needed' => true,
            'source' => 'bland',
            'source_call_id' => 'bland-100',
            'handled_by' => 'claire',
            'duration_sec' => 95,
        ], 'ai');
        self::assertSame('needs_return', $call['status']);
        self::assertTrue($call['open_return']);
        self::assertTrue($call['vip']);
        self::assertSame($contact['id'], $call['contact_id']);
        self::assertSame('(256) 555-0144', $call['phone_display']);
        self::assertSame('tel:+12565550144', $call['phone_tel']);
        self::assertSame('bland', $call['source']);

        $updated = $this->svc->calls->log($this->user, [
            'direction' => 'inbound',
            'source_call_id' => 'bland-100',
            'summary' => 'Confirmed Thursday at 2.',
            'notified_david' => true,
        ], 'ai');
        self::assertSame($call['id'], $updated['id']);
        self::assertSame('Confirmed Thursday at 2.', $updated['summary']);
        self::assertTrue($updated['notified_david']);
        self::assertNotNull($updated['notified_at']);
        self::assertCount(1, $this->svc->calls->listCalls($this->user));

        $debt = $this->svc->calls->log($this->user, [
            'direction' => 'inbound',
            'phone' => '2565550177',
            'callback_number' => '2565550188',
            'company' => 'Northwind Recovery',
            'category' => 'debt_collector',
            'urgency' => 'urgent',
            'return_needed' => true,
            'deadline' => '2026-10-20',
            'source_call_id' => 'bland-200',
            'handled_by' => 'cos',
            'debt' => [
                'collector_name' => 'Pat Lee',
                'agency' => 'Northwind Recovery',
                'reference_last4' => '998877665544',
                'amount_claimed' => 640.5,
                'original_creditor' => 'Harbor Electric',
                'validation_requested' => true,
                'referred_to_penny' => true,
            ],
        ], 'ai');
        self::assertSame('5544', $debt['debt']['reference_last4']);
        self::assertEquals(640.5, $debt['debt']['amount_claimed']);
        self::assertSame('suspicious', $debt['debt']['verdict']);
        self::assertSame('https://northwind.example/collections', $debt['debt']['website']);
        self::assertTrue($debt['debt']['referred_to_penny']);
        self::assertTrue($debt['open_return']);

        $list = $this->svc->calls->listCalls($this->user, ['category' => 'debt_collector', 'return_needed' => true]);
        self::assertCount(1, $list);
        self::assertSame($debt['id'], $list[0]['id']);

        $found = $this->svc->calls->search($this->user, 'Thursday');
        self::assertSame($call['id'], $found[0]['id']);
        self::assertArrayHasKey('transcript_text', $found[0]);

        $lookup = $this->svc->calls->lookup($this->user, '+1 256 555 0144');
        self::assertSame($contact['id'], $lookup['contact']['id']);
        self::assertSame('Harbor Clinic desk', $lookup['contact']['name']);
        self::assertSame($call['id'], $lookup['calls'][0]['id']);

        $stats = $this->svc->calls->stats($this->user);
        self::assertSame(2, $stats['total']);
        self::assertSame(2, $stats['open_returns']);
        self::assertSame(1, $stats['urgent']);
        self::assertSame(1, $stats['debt']);
        self::assertSame(1, $stats['vip']);
        self::assertSame(1, $stats['by_category']['debt_collector']);

        $returned = $this->svc->calls->setStatus($this->user, $call['id'], 'returned');
        self::assertSame('returned', $returned['status']);
        self::assertFalse($returned['return_needed']);
        self::assertSame(1, $this->svc->calls->stats($this->user)['open_returns']);

        $todoId = $this->todo('Call Harbor back');
        $linked = $this->svc->calls->linkTodo($this->user, $debt['id'], $todoId);
        self::assertSame($todoId, $linked['linked_todo_id']);
        $cleared = $this->svc->calls->linkTodo($this->user, $debt['id'], null);
        self::assertNull($cleared['linked_todo_id']);

        $widget = $this->svc->widgets->create($this->user, 'calls', 'Calls', ['limit' => 10]);
        $board = $this->svc->widgetData->forWidget($this->user, $widget['id']);
        self::assertSame([], $board['metric_ids']);
        self::assertSame(1, $board['counts']['open_returns']);
        self::assertArrayNotHasKey('transcript_text', $board['calls'][0]);
        self::assertSame($debt['id'], $board['calls'][0]['id']);
    }

    public function testRejectsSsnAndAnotherUsersCall(): void
    {
        try {
            $this->svc->calls->log($this->user, [
                'direction' => 'inbound',
                'debt' => ['reference_last4' => '123-45-6789'],
            ], 'manual');
            self::fail('stored an SSN');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reference_last4', $e->fields());
        }

        $call = $this->svc->calls->log($this->user, [
            'direction' => 'outbound',
            'name' => 'Studio desk',
            'phone' => '2565550100',
        ], 'manual');
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('ada', 'a@x', 'h')");
        $other = (int) $this->db->lastInsertId();
        try {
            $this->svc->calls->get($other, $call['id']);
            self::fail('other user read the call');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
        self::assertSame([], $this->svc->calls->listCalls($other));
    }

    public function testFullAccountNumberInNotesIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc->calls->log($this->user, [
            'direction' => 'inbound',
            'notes' => 'Account 4111111111111111 past due',
        ], 'manual');
    }

    private function todo(string $title): int
    {
        $listId = (int) $this->db->fetchScalar(
            'INSERT INTO user_todo_lists (user_id, name, slug) VALUES (?, ?, ?) RETURNING id',
            [$this->user, 'Work', 'work']
        );
        return (int) $this->db->fetchScalar(
            'INSERT INTO user_todo_items (list_id, title, created_by_user_id) VALUES (?, ?, ?) RETURNING id',
            [$listId, $title, $this->user]
        );
    }
}
