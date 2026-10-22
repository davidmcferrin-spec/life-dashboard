<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\TokenCipher;
use PHPUnit\Framework\TestCase;

final class SpotifyTokenCipherTest extends TestCase
{
    private const SECRET = 'spotify-test-key';
    private const PLAIN = 'refresh-token-example';
    private const GOLDEN = 'AQEBAQEBAQEBAQEBCyhX8rybhPwLN74AdYWqtnK9ClT0kQmK7cpgu1lGd8AzDe4xlA==';

    public function testGoldenVectorMatchesPython(): void
    {
        $key = hash('sha256', self::SECRET, true);
        $iv = str_repeat("\x01", 12);
        self::assertSame(self::GOLDEN, TokenCipher::seal(self::PLAIN, $key, $iv));
        self::assertSame(self::PLAIN, TokenCipher::open(self::GOLDEN, $key));
    }

    public function testRoundTripUsesAFreshIv(): void
    {
        $key = hash('sha256', self::SECRET, true);
        $first = TokenCipher::seal(self::PLAIN, $key);
        $second = TokenCipher::seal(self::PLAIN, $key);
        self::assertNotSame($first, $second);
        self::assertSame(self::PLAIN, TokenCipher::open($first, $key));
        self::assertSame(self::PLAIN, TokenCipher::open($second, $key));
    }

    public function testTamperedBlobIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        TokenCipher::open(self::GOLDEN, hash('sha256', 'other-key', true));
    }
}
