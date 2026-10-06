<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Support\RealtimeToken;
use PHPUnit\Framework\TestCase;

final class RealtimeTokenTest extends TestCase
{
    private const SECRET = 'unit-test-secret-0123456789';

    /** Same vector is pinned in services/tests/test_push.py — change both or neither. */
    public const GOLDEN = 'eyJ1Ijo3LCJleHAiOjE4MDAwMDAxMjB9.mIyxf9E4UQfAi6CPRpSzsSJwwjsOudad3v9UwPyWJrA';

    public function testGoldenVectorIsStable(): void
    {
        self::assertSame(self::GOLDEN, RealtimeToken::issue(7, self::SECRET, 1_800_000_000));
    }

    public function testRoundTripAndExpiry(): void
    {
        $t = RealtimeToken::issue(42, self::SECRET, 1000, 120);
        self::assertSame(42, RealtimeToken::verify($t, self::SECRET, 1000));
        self::assertSame(42, RealtimeToken::verify($t, self::SECRET, 1120), 'valid through exp');
        self::assertNull(RealtimeToken::verify($t, self::SECRET, 1121));
    }

    public function testRejectsTamperingAndWrongSecret(): void
    {
        $t = RealtimeToken::issue(42, self::SECRET, 1000);
        [$payload, $sig] = explode('.', $t);
        $forged = rtrim(strtr(base64_encode('{"u":1,"exp":9999999999}'), '+/', '-_'), '=');

        self::assertNull(RealtimeToken::verify($forged . '.' . $sig, self::SECRET, 1000));
        self::assertNull(RealtimeToken::verify($t, 'another-secret-0123456789', 1000));
        self::assertNull(RealtimeToken::verify($payload, self::SECRET, 1000));
        self::assertNull(RealtimeToken::verify('', self::SECRET, 1000));
        self::assertNull(RealtimeToken::verify('a.b.c', self::SECRET, 1000));
    }

    public function testRefusesShortSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        RealtimeToken::issue(1, '');
    }
}
