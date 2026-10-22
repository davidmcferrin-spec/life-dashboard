<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Auth\ApiTokenService;
use Dashboard\Mcp\PlainHttp;
use PHPUnit\Framework\TestCase;

final class PlainHttpTest extends TestCase
{
    public function testMissingRowUsesTheConfigDefault(): void
    {
        $on = PlainHttp::effective(null, true);
        self::assertTrue($on['allowed']);
        self::assertSame('config', $on['source']);
        self::assertSame('On. Set by the config file (MCP_ALLOW_PLAIN_HTTP).', PlainHttp::sourceText(true, 'config', true));

        $off = PlainHttp::effective(null, false);
        self::assertFalse($off['allowed']);
        self::assertSame('config', $off['source']);
        self::assertSame('Off. Set by the config file (MCP_ALLOW_PLAIN_HTTP).', PlainHttp::sourceText(false, 'config', false));
    }

    public function testStoredRowOverridesTheConfigDefault(): void
    {
        $forcedOff = PlainHttp::effective(0, true);
        self::assertFalse($forcedOff['allowed']);
        self::assertSame('database', $forcedOff['source']);
        self::assertSame(
            'Off. Set on this page. The config file (MCP_ALLOW_PLAIN_HTTP) is on.',
            PlainHttp::sourceText(false, 'database', true)
        );

        $forcedOn = PlainHttp::effective(1, false);
        self::assertTrue($forcedOn['allowed']);
        self::assertSame('database', $forcedOn['source']);
        self::assertSame(
            'On. Set on this page. The config file (MCP_ALLOW_PLAIN_HTTP) is off.',
            PlainHttp::sourceText(true, 'database', false)
        );
    }

    public function testBearerCannotChangeTheSwitch(): void
    {
        self::assertFalse(PlainHttp::changeBlocked([], false));
        self::assertTrue(PlainHttp::changeBlocked(['HTTP_AUTHORIZATION' => 'Bearer ld_abc'], false));
        self::assertTrue(PlainHttp::changeBlocked(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer ldoa_abc'], false));
        self::assertTrue(PlainHttp::changeBlocked([], true));
        self::assertFalse(PlainHttp::changeBlocked(['HTTP_AUTHORIZATION' => 'Basic nope'], false));
    }

    public function testTransportIsTrustedOnlyFromLoopback(): void
    {
        self::assertSame('https', PlainHttp::inbound([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_LIFEDASH_TRANSPORT' => 'HTTPS',
        ]));
        self::assertSame('http', PlainHttp::inbound([
            'REMOTE_ADDR' => '::1',
            'HTTP_X_LIFEDASH_TRANSPORT' => 'http',
        ]));
        self::assertNull(PlainHttp::inbound([
            'REMOTE_ADDR' => '10.1.1.8',
            'HTTP_X_LIFEDASH_TRANSPORT' => 'https',
        ]));
        self::assertNull(PlainHttp::inbound([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_LIFEDASH_TRANSPORT' => 'tcp',
        ]));
        self::assertNull(PlainHttp::inbound(['REMOTE_ADDR' => '127.0.0.1']));
    }

    public function testTokenFormatKeepsTheLastTransport(): void
    {
        $row = ApiTokenService::format([
            'id' => 3,
            'name' => 'Grok',
            'prefix' => 'ld_abcd',
            'scopes' => '*',
            'expires_at' => null,
            'last_used_at' => '2026-10-22 15:00:00',
            'last_transport' => 'http',
            'revoked_at' => null,
            'created_at' => '2026-10-01 00:00:00',
        ]);
        self::assertSame('http', $row['last_transport']);

        $empty = ApiTokenService::format([
            'id' => 4,
            'name' => 'Claude',
            'prefix' => 'ld_efgh',
            'scopes' => 'read',
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => '2026-10-01 00:00:00',
        ]);
        self::assertNull($empty['last_transport']);
    }
}
