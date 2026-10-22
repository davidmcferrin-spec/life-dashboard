<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Auth\OAuthException;
use Dashboard\Auth\OAuthRules;
use PHPUnit\Framework\TestCase;

final class OAuthRulesTest extends TestCase
{
    public function testPkceS256MatchesAndRejectsTheWrongVerifier(): void
    {
        $verifier = str_repeat('a', 43);
        $challenge = OAuthRules::s256($verifier);
        self::assertNull(OAuthRules::pkceError('S256', $challenge, $verifier));
        self::assertNull(OAuthRules::pkceError('S256', $challenge . '=', $verifier));
        self::assertNotNull(OAuthRules::pkceError('S256', $challenge, str_repeat('b', 43)));
        self::assertNotNull(OAuthRules::pkceError('plain', $challenge, $verifier));
        self::assertNotNull(OAuthRules::pkceError('S256', $challenge, 'short'));
        self::assertNotNull(OAuthRules::challengeError('S256', 'not-a-challenge'));
    }

    public function testRedirectAllowsHttpsAndLoopbackOnly(): void
    {
        self::assertNull(OAuthRules::redirectError('https://claude.ai/api/mcp/auth_callback'));
        self::assertNull(OAuthRules::redirectError('http://127.0.0.1:8765/callback'));
        self::assertNull(OAuthRules::redirectError('http://localhost/callback'));
        self::assertNull(OAuthRules::redirectError('http://[::1]/callback'));
        self::assertNotNull(OAuthRules::redirectError('http://example.com/callback'));
        self::assertNotNull(OAuthRules::redirectError('http://192.168.1.20/callback'));
        self::assertNotNull(OAuthRules::redirectError('https://user:pass@example.com/callback'));
        self::assertNotNull(OAuthRules::redirectError('https://example.com/callback#fragment'));
        self::assertNotNull(OAuthRules::redirectError('/relative'));
    }

    public function testGrantIsASubsetOfTheRequestAndDropsPrivilegedScopesForANonAdmin(): void
    {
        $granted = OAuthRules::grantScopes(
            ['pantry:read', 'pantry:write'],
            ['pantry:read', 'pantry:write'],
            ['pantry:read'],
            false
        );
        self::assertSame(['pantry:read'], $granted);
        self::assertFalse(Scope::allows($granted, 'pantry:write'));
        self::assertTrue(Scope::allows($granted, 'pantry:read'));

        try {
            OAuthRules::grantScopes(['pantry:read'], ['pantry:read'], ['pantry:write'], false);
            self::fail('a scope the client did not request must be rejected');
        } catch (OAuthException $e) {
            self::assertSame('invalid_scope', $e->error);
        }
    }

    public function testMetadataPointsAtTheDashboardIssuer(): void
    {
        $url = OAuthRules::metadataUrl('https://mcp.example.com/mcp');
        self::assertSame('https://mcp.example.com/.well-known/oauth-protected-resource/mcp', $url);
        self::assertSame('Bearer', OAuthRules::wwwAuthenticate(''));
        self::assertStringContainsString('resource_metadata="' . $url . '"', OAuthRules::wwwAuthenticate('https://mcp.example.com/mcp'));
        $doc = OAuthRules::authorizationServer('https://dash.example.com', OAuthRules::known(true));
        self::assertSame('https://dash.example.com/oauth/token', $doc['token_endpoint']);
        self::assertSame(['S256'], $doc['code_challenge_methods_supported']);
        self::assertContains('pantry:read', $doc['scopes_supported']);
        self::assertContains('system:deploy', $doc['scopes_supported']);
        self::assertNotContains('system:deploy', OAuthRules::known(false));
    }
}
