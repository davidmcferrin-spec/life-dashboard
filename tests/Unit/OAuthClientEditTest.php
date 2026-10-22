<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Auth\MemoryOAuthStore;
use Dashboard\Auth\OAuthClientEndpoint;
use Dashboard\Auth\OAuthRules;
use Dashboard\Auth\OAuthService;
use Dashboard\Settings\BrowserOnly;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\UnauthorizedException;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class OAuthClientEditTest extends TestCase
{
    public function testEditEndpointRejectsABearerAMissingCsrfAndANonAdmin(): void
    {
        $endpoint = new OAuthClientEndpoint($this->service());
        $admin = ['id' => 7, 'is_admin' => 1];

        try {
            $endpoint->authorize(['HTTP_AUTHORIZATION' => 'Bearer abc.def'], $admin, 'tok', 'tok');
            self::fail('A bearer must be rejected.');
        } catch (ForbiddenException $e) {
            self::assertSame(BrowserOnly::DENIED, $e->getMessage());
        }

        try {
            $endpoint->authorize([], null, 'tok', 'tok');
            self::fail('A signed-out request must be rejected.');
        } catch (UnauthorizedException $e) {
            self::assertSame('Not authenticated.', $e->getMessage());
        }

        try {
            $endpoint->authorize([], $admin, '', 'expected');
            self::fail('A missing CSRF token must be rejected.');
        } catch (ForbiddenException $e) {
            self::assertSame('Invalid CSRF token. Refresh the page and try again.', $e->getMessage());
        }

        try {
            $endpoint->authorize([], $admin, 'nope', 'expected');
            self::fail('A wrong CSRF token must be rejected.');
        } catch (ForbiddenException $e) {
            self::assertSame('Invalid CSRF token. Refresh the page and try again.', $e->getMessage());
        }

        try {
            $endpoint->authorize([], ['id' => 7, 'is_admin' => 0], 'expected', 'expected');
            self::fail('A non-admin must be rejected.');
        } catch (ForbiddenException $e) {
            self::assertSame('Only an admin can edit an OAuth client.', $e->getMessage());
        }

        $endpoint->authorize([], $admin, 'expected', 'expected');
        self::assertTrue(Scope::sessionOnly('/api/v1/oauth/clients/3/update'));
        self::assertTrue(ApiRunner::auditsCredentialChange('/api/v1/oauth/clients/3/update'));
        self::assertTrue(ApiRunner::auditsCredentialChange('/api/v1/tokens/3/update'));
    }

    public function testEditEndpointSavesTheNameAndScopes(): void
    {
        $store = new MemoryOAuthStore();
        $svc = new OAuthService($store, fn (): int => 1_700_000_000, 'https://mcp.example.com/mcp');
        $endpoint = new OAuthClientEndpoint($svc);
        $client = $svc->register([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'scope' => 'spotify:read spotify:write spotify:playback',
        ], '203.0.113.80');
        $prepared = $svc->prepare([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => OAuthRules::s256(str_repeat('a', 43)),
            'code_challenge_method' => 'S256',
            'scope' => 'spotify:read spotify:write spotify:playback',
            'resource' => 'https://mcp.example.com/mcp',
        ], '203.0.113.81', true);
        $svc->grant(7, true, $prepared['request'], ['spotify:read', 'spotify:write', 'spotify:playback']);
        $row = $store->findClient($client['client_id']);
        self::assertNotNull($row);

        try {
            $endpoint->update(7, true, (int) $row['id'], [
                'name' => 'Player',
                'scopes' => ['spotify:read', 'spotify:write', 'spotify:playback'],
                'redirect_uris' => ['https://evil.example/cb'],
            ]);
            self::fail('The edit accepts only name and scopes.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body', $e->fields());
        }

        $saved = $endpoint->update(7, true, (int) $row['id'], [
            'name' => 'Player',
            'scopes' => ['spotify:read', 'spotify:write', 'spotify:playback', 'system:read'],
        ]);
        self::assertSame('Player', $saved['token']['name']);
        self::assertSame(
            ['spotify:read', 'spotify:write', 'spotify:playback', 'system:read'],
            $saved['token']['scopes']
        );
        self::assertSame('Claude', $saved['previous_name']);
        $again = $store->findClientById((int) $row['id']);
        self::assertNotNull($again);
        self::assertSame('Player', $again['client_name']);
        self::assertSame(
            ['spotify:read', 'spotify:write', 'spotify:playback', 'system:read'],
            $again['allowed_scopes']
        );

        try {
            $endpoint->update(7, false, (int) $row['id'], [
                'name' => 'Player',
                'scopes' => ['spotify:read'],
            ]);
            self::fail('The service rejects a non-admin even after the request is parsed.');
        } catch (ForbiddenException $e) {
            self::assertSame('Only an admin can edit an OAuth client.', $e->getMessage());
        }
    }

    private function service(): OAuthService
    {
        return new OAuthService(new MemoryOAuthStore(), fn (): int => 1_700_000_000, 'https://mcp.example.com/mcp');
    }
}
