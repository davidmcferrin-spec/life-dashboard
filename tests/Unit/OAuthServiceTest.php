<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Auth\BearerTokens;
use Dashboard\Auth\MemoryOAuthStore;
use Dashboard\Auth\OAuthException;
use Dashboard\Auth\OAuthRules;
use Dashboard\Auth\OAuthService;
use Dashboard\Deploy\DeployService;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase
{
    private int $now = 1_700_000_000;

    public function testCodeExchangeRefreshRotationReuseAndRevocation(): void
    {
        $svc = $this->service();
        $client = $svc->register([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'scope' => 'pantry:read pantry:write',
        ], '203.0.113.5');
        $prepared = $svc->prepare([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => OAuthRules::s256($this->verifier()),
            'code_challenge_method' => 'S256',
            'scope' => 'pantry:read pantry:write',
            'state' => 'xyz',
            'resource' => 'https://mcp.example.com/mcp',
        ], '203.0.113.5', false);
        $redirect = $svc->grant(7, false, $prepared['request'], ['pantry:read']);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        self::assertSame('xyz', $query['state']);
        $code = (string) $query['code'];

        try {
            $svc->token([
                'grant_type' => 'authorization_code',
                'client_id' => $client['client_id'],
                'code' => $code,
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'code_verifier' => str_repeat('b', 43),
            ], '203.0.113.6');
            self::fail('wrong verifier must fail');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }

        $again = $svc->prepare([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => OAuthRules::s256($this->verifier()),
            'code_challenge_method' => 'S256',
            'scope' => 'pantry:read pantry:write',
            'resource' => 'https://mcp.example.com/mcp',
        ], '203.0.113.7', false);
        $redirect = $svc->grant(7, false, $again['request'], ['pantry:read']);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $tokens = $svc->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $query['code'],
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_verifier' => $this->verifier(),
        ], '203.0.113.8');
        self::assertStringStartsWith('ldoa_', $tokens['access_token']);
        self::assertStringStartsWith('ldor_', $tokens['refresh_token']);
        self::assertSame('pantry:read', $tokens['scope']);

        try {
            $svc->token([
                'grant_type' => 'authorization_code',
                'client_id' => $client['client_id'],
                'code' => $query['code'],
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'code_verifier' => $this->verifier(),
            ], '203.0.113.9');
            self::fail('a code is single use');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }

        $access = $svc->authenticate($tokens['access_token']);
        self::assertNotNull($access);
        self::assertSame(['pantry:read'], $access['scopes']);
        self::assertGreaterThan(0, $access['id']);
        self::assertFalse(Scope::allows($access['scopes'], 'pantry:write'));
        self::assertFalse(DeployService::mcpRequestAllowed(false, $access['id']));
        self::assertTrue(DeployService::mcpRequestAllowed(true, $access['id']));
        $store = $this->storeOf($svc);
        $stored = $store->findAccess(hash('sha256', $tokens['access_token']));
        self::assertNotNull($stored);
        self::assertSame(hash('sha256', $tokens['access_token']), $stored['token_hash']);
        self::assertNotSame($tokens['access_token'], $stored['token_hash']);

        $this->now += OAuthRules::ACCESS_TTL + 1;
        self::assertNull($svc->authenticate($tokens['access_token']));
        $rotated = $svc->token([
            'grant_type' => 'refresh_token',
            'client_id' => $client['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ], '203.0.113.10');
        self::assertNotSame($tokens['access_token'], $rotated['access_token']);
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        self::assertNotNull($svc->authenticate($rotated['access_token']));

        try {
            $svc->token([
                'grant_type' => 'refresh_token',
                'client_id' => $client['client_id'],
                'refresh_token' => $tokens['refresh_token'],
            ], '203.0.113.11');
            self::fail('reusing a rotated refresh token must fail');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }
        self::assertNull($svc->authenticate($rotated['access_token']), 'reuse revokes the grant');

        $fresh = $this->issued($svc, $client['client_id']);
        $row = $svc->authenticate($fresh['access_token']);
        self::assertNotNull($row);
        $clients = $svc->listClients(7);
        self::assertCount(1, $clients);
        self::assertSame('Claude', $clients[0]['client_name']);
        self::assertSame(['pantry:read'], $clients[0]['granted_scopes']);
        self::assertNotNull($clients[0]['last_used_at']);
        self::assertTrue($svc->revokeClient(7, (int) $clients[0]['id']));
        self::assertNull($svc->authenticate($fresh['access_token']));
        self::assertSame([], $svc->listClients(7));
        self::assertFalse($svc->revokeClient(7, (int) $clients[0]['id']));
    }

    public function testRefreshExpiryAndRegisterRateLimit(): void
    {
        $svc = $this->service();
        $client = $svc->register([
            'client_name' => 'Claude',
            'redirect_uris' => ['http://127.0.0.1:3000/callback'],
            'token_endpoint_auth_method' => 'none',
        ], '198.51.100.4');
        $tokens = $this->issued($svc, $client['client_id'], 'http://127.0.0.1:3000/callback');
        $this->now += OAuthRules::REFRESH_TTL + 5;
        try {
            $svc->token([
                'grant_type' => 'refresh_token',
                'client_id' => $client['client_id'],
                'refresh_token' => $tokens['refresh_token'],
            ], '198.51.100.5');
            self::fail('an expired refresh token must fail');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }

        $limited = $this->service();
        for ($i = 0; $i < OAuthRules::REGISTER_LIMIT; $i++) {
            $limited->register([
                'client_name' => 'Client ' . $i,
                'redirect_uris' => ['https://example.com/cb/' . $i],
            ], '198.51.100.9');
        }
        try {
            $limited->register([
                'client_name' => 'Too many',
                'redirect_uris' => ['https://example.com/cb/overflow'],
            ], '198.51.100.9');
            self::fail('register must be rate limited');
        } catch (OAuthException $e) {
            self::assertSame(429, $e->status);
            self::assertSame(OAuthRules::REGISTER_WINDOW, $e->retryAfter);
        }
    }

    public function testRejectsPublicHttpAndASpoofedResource(): void
    {
        $svc = $this->service();
        try {
            $svc->register([
                'client_name' => 'Bad',
                'redirect_uris' => ['http://203.0.113.8/callback'],
            ], '203.0.113.8');
            self::fail('public http redirects are rejected');
        } catch (OAuthException $e) {
            self::assertSame('invalid_redirect_uri', $e->error);
        }
        $client = $svc->register([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/cb'],
        ], '203.0.113.10');
        try {
            $svc->prepare([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://evil.example/cb',
                'code_challenge' => OAuthRules::s256($this->verifier()),
                'code_challenge_method' => 'S256',
                'scope' => 'pantry:read',
            ], '203.0.113.11', false);
            self::fail('an unregistered redirect must not be used');
        } catch (OAuthException $e) {
            self::assertFalse($e->toClient);
        }
        try {
            $svc->prepare([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => 'https://claude.ai/cb',
                'code_challenge' => OAuthRules::s256($this->verifier()),
                'code_challenge_method' => 'S256',
                'scope' => 'pantry:read',
                'resource' => 'https://evil.example/mcp',
            ], '203.0.113.12', false);
            self::fail('resource must match MCP_PUBLIC_URL');
        } catch (OAuthException $e) {
            self::assertSame('invalid_target', $e->error);
        }
    }

    public function testStaticBearerTokensDoNotFallThroughToOAuth(): void
    {
        $seen = ['static' => 0, 'oauth' => 0];
        $static = static function (string $token) use (&$seen): ?array {
            $seen['static']++;
            if ($token === 'ld_live') {
                return ['user_id' => 1, 'scopes' => ['pantry:read'], 'id' => 4, 'name' => 'bot'];
            }
            return null;
        };
        $oauth = static function (string $token) use (&$seen): ?array {
            $seen['oauth']++;
            if ($token === 'ldoa_live') {
                return ['user_id' => 1, 'scopes' => ['pantry:write'], 'id' => 9, 'name' => 'Claude'];
            }
            return null;
        };
        $live = BearerTokens::resolve('ld_live', $static, $oauth);
        self::assertSame('bot', $live['name'] ?? null);
        self::assertSame(0, $seen['oauth']);
        $revoked = BearerTokens::resolve('ld_revoked', $static, $oauth);
        self::assertNull($revoked);
        self::assertSame(0, $seen['oauth']);
        $connector = BearerTokens::resolve('ldoa_live', $static, $oauth);
        self::assertSame('Claude', $connector['name'] ?? null);
        self::assertSame(2, $seen['static']);
    }

    private function service(): OAuthService
    {
        return new OAuthService(new MemoryOAuthStore(), fn (): int => $this->now, 'https://mcp.example.com/mcp');
    }

    private function storeOf(OAuthService $svc): MemoryOAuthStore
    {
        $prop = new \ReflectionProperty($svc, 'store');
        $store = $prop->getValue($svc);
        self::assertInstanceOf(MemoryOAuthStore::class, $store);
        return $store;
    }

    private function verifier(): string
    {
        return str_repeat('a', 43);
    }

    /**
     * @return array<string, mixed>
     */
    private function issued(OAuthService $svc, string $clientId, string $redirect = 'https://claude.ai/api/mcp/auth_callback'): array
    {
        $prepared = $svc->prepare([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_challenge' => OAuthRules::s256($this->verifier()),
            'code_challenge_method' => 'S256',
            'scope' => 'pantry:read',
            'resource' => 'https://mcp.example.com/mcp',
        ], '203.0.113.' . ($this->now % 200), false);
        $redirectTo = $svc->grant(7, false, $prepared['request'], ['pantry:read']);
        parse_str((string) parse_url($redirectTo, PHP_URL_QUERY), $query);
        return $svc->token([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $query['code'],
            'redirect_uri' => $redirect,
            'code_verifier' => $this->verifier(),
        ], '203.0.113.' . (($this->now + 1) % 200));
    }
}
