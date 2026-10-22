<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Config;
use Dashboard\Support\LocalPath;
use Dashboard\Support\PublicUrl;
use Dashboard\Support\RequestOrigin;
use Dashboard\Support\RequestScheme;
use PHPUnit\Framework\TestCase;

final class PublicOriginTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::overlay('PUBLIC_BASE_URL', null);
        Config::overlay('APP_URL', null);
        Config::overlay('MCP_PUBLIC_URL', null);
    }

    public function testForwardedHttpsIsTrustedOnlyFromLoopback(): void
    {
        self::assertTrue(RequestScheme::https(['HTTPS' => 'on', 'REMOTE_ADDR' => '192.168.1.20']));
        self::assertFalse(RequestScheme::https([
            'REMOTE_ADDR' => '192.168.1.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
        self::assertTrue(RequestScheme::https([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
        self::assertTrue(RequestScheme::https([
            'REMOTE_ADDR' => '::1',
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
        ]));
        self::assertFalse(RequestScheme::https([
            'REMOTE_ADDR' => '203.0.113.4',
            'HTTP_CF_VISITOR' => '{"scheme":"https"}',
        ]));
    }

    public function testOriginAllowsTheRequestHostAndConfiguredUrls(): void
    {
        self::assertTrue(RequestOrigin::permitted('https://dash.example.com', ['https://dash.example.com'], '192.168.1.5', true));
        self::assertTrue(RequestOrigin::permitted('http://192.168.1.5', [], '192.168.1.5', false));
        self::assertFalse(RequestOrigin::permitted('https://evil.example', ['https://dash.example.com'], '192.168.1.5', false));
        self::assertTrue(RequestOrigin::permitted('', ['https://dash.example.com'], '192.168.1.5', true));
    }

    public function testPublicBaseUrlWinsForGeneratedLinks(): void
    {
        Config::overlay('APP_URL', 'https://dash.lan');
        Config::overlay('PUBLIC_BASE_URL', 'https://dash.example.com');
        Config::overlay('MCP_PUBLIC_URL', 'https://mcp.example.com/mcp');
        self::assertSame('https://dash.example.com', PublicUrl::base());
        self::assertSame('https://mcp.example.com/mcp', PublicUrl::mcp());
        self::assertContains('https://mcp.example.com', PublicUrl::allowedOrigins());
        self::assertContains('https://dash.lan', PublicUrl::allowedOrigins());
    }

    public function testLoginNextStaysOnThisHost(): void
    {
        self::assertSame('/oauth/authorize?client_id=abc', LocalPath::next('/oauth/authorize?client_id=abc'));
        self::assertNull(LocalPath::next('https://evil.example/'));
        self::assertNull(LocalPath::next('//evil.example'));
        self::assertNull(LocalPath::next('/\\evil'));
    }
}
