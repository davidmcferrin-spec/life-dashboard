<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Calls\CallGuard;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CallGuardTest extends TestCase
{
    public function testReferenceKeepsLast4AndRejectsSsnShapes(): void
    {
        self::assertSame('9012', CallGuard::referenceLast4('123456789012'));
        self::assertSame('9012', CallGuard::referenceLast4('****9012'));
        self::assertSame('9012', CallGuard::referenceLast4('acct 12-3456-789012'));

        $ssn = ['123-45-6789', '123 45 6789', '123456789'];
        foreach ($ssn as $value) {
            try {
                CallGuard::referenceLast4($value);
                self::fail('stored an SSN-like reference: ' . $value);
            } catch (ValidationException $e) {
                self::assertArrayHasKey('reference_last4', $e->fields());
            }
        }
    }

    public function testFreeTextRejectsSsnAndFullAccountNumbers(): void
    {
        CallGuard::assertTextSafe('Call back at (256) 555-0199 about the $1,200 bill.', 'notes');
        try {
            CallGuard::assertTextSafe('SSN on file 123-45-6789', 'notes');
            self::fail('stored an SSN in notes');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('notes', $e->fields());
        }
        try {
            CallGuard::assertTextSafe('card 4111111111111111', 'notes');
            self::fail('stored a full account number');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('notes', $e->fields());
        }
    }

    public function testPhoneFormatAndTelLink(): void
    {
        self::assertSame('2565550199', CallGuard::phone('(256) 555-0199', 'phone'));
        self::assertSame('(256) 555-0199', CallGuard::formatPhone('+12565550199'));
        self::assertSame('tel:+12565550199', CallGuard::telHref('2565550199'));
        self::assertSame('2565550199', CallGuard::matchDigits('+1 (256) 555-0199'));
    }

    public function testReturnNeededBecomesNeedsReturn(): void
    {
        $clock = new Clock('America/Chicago', new DateTimeImmutable('2026-10-16 18:00:00', new DateTimeZone('UTC')));
        $row = CallGuard::callColumns([
            'call_at' => '2026-10-16 18:00:00',
            'direction' => '',
            'category' => 'personal',
            'urgency' => 'normal',
            'return_needed' => 0,
            'status' => 'new',
            'has_recording' => 0,
            'source' => 'manual',
            'notified_david' => 0,
            'notified_at' => null,
        ], [
            'direction' => 'inbound',
            'return_needed' => true,
            'category' => 'work',
        ], $clock, true, 'ai');
        self::assertSame('needs_return', $row['status']);
        self::assertSame(1, $row['return_needed']);
        self::assertSame('ai', $row['source']);
        self::assertSame('work', $row['category']);
    }

    public function testClosedStatusClearsTheReturnFlag(): void
    {
        $clock = new Clock('UTC', new DateTimeImmutable('2026-10-16 18:00:00', new DateTimeZone('UTC')));
        $row = CallGuard::callColumns([
            'direction' => 'inbound',
            'category' => 'work',
            'urgency' => 'high',
            'return_needed' => 1,
            'status' => 'needs_return',
            'has_recording' => 0,
            'notified_david' => 0,
            'notified_at' => null,
            'source' => 'manual',
            'call_at' => '2026-10-16 18:00:00',
        ], ['status' => 'returned'], $clock, false, 'manual');
        self::assertSame('returned', $row['status']);
        self::assertSame(0, $row['return_needed']);
    }

    public function testScopeAndAuditPaths(): void
    {
        self::assertSame('calls:write', Scope::forPath('/api/v1/calls', true));
        self::assertSame('calls:read', Scope::forPath('/api/v1/calls/query', false));
        self::assertSame('calls:read', Scope::forPath('/api/v1/calls/contacts', false));
        self::assertContains('calls:read', Scope::normalize(['read']));
        self::assertTrue(Scope::allows(['calls:write'], 'calls:read'));
        self::assertFalse(Scope::allows(['shopping:write'], 'calls:write'));
        self::assertSame(
            ['call', 'contact', 'vetted_agency'],
            Scope::auditEntityAllow(['calls:read'], null)
        );
        self::assertSame('call', ApiRunner::entityFromPath('/api/v1/calls/4/status'));
        self::assertSame('contact', ApiRunner::entityFromPath('/api/v1/calls/contacts'));
        self::assertSame('vetted_agency', ApiRunner::entityFromPath('/api/v1/calls/agencies'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/calls/4/todo'));
        self::assertSame(9, ApiRunner::entityIdFromPayload(['call' => ['id' => 9]]));
    }
}
