<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\Api\V1\Scope;

/**
 * Pure OAuth 2.1 checks. No database and no clock, so the rules can be tested
 * without a server.
 */
final class OAuthRules
{
    public const CODE_TTL = 300;

    public const ACCESS_TTL = 3600;

    public const REFRESH_TTL = 2592000;

    public const REGISTER_LIMIT = 10;

    public const REGISTER_WINDOW = 3600;

    public const AUTHORIZE_LIMIT = 30;

    public const AUTHORIZE_WINDOW = 600;

    public const TOKEN_LIMIT = 60;

    public const TOKEN_WINDOW = 600;

    public static function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function pkceError(string $method, string $challenge, string $verifier): ?string
    {
        if ($method !== 'S256') {
            return 'PKCE method must be S256.';
        }
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            return 'PKCE verifier must be 43 to 128 unreserved characters.';
        }
        $given = rtrim(trim($challenge), '=');
        if ($given === '' || !hash_equals(self::s256($verifier), $given)) {
            return 'PKCE verifier does not match the challenge.';
        }
        return null;
    }

    public static function challengeError(string $method, string $challenge): ?string
    {
        if ($method !== 'S256') {
            return 'PKCE method must be S256.';
        }
        $given = rtrim(trim($challenge), '=');
        if (!preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $given)) {
            return 'PKCE challenge must be a base64url SHA-256 digest.';
        }
        return null;
    }

    /**
     * https anywhere, or http only on loopback. No userinfo and no fragment.
     */
    public static function redirectError(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Redirect URI must be an absolute URL.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Redirect URI must not include a username or password.';
        }
        if (isset($parts['fragment'])) {
            return 'Redirect URI must not include a fragment.';
        }
        if (strlen($uri) > 500) {
            return 'Redirect URI is too long.';
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($scheme === 'https') {
            return null;
        }
        if ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return null;
        }
        return 'Redirect URI must be https, or http on 127.0.0.1, localhost, or ::1.';
    }

    /**
     * @param list<string> $registered what the client is allowed to ask
     * @param list<string> $requested what this authorize call asked for
     * @param list<string> $selected what the signed-in user checked
     * @return list<string>
     */
    public static function grantScopes(array $registered, array $requested, array $selected, bool $admin): array
    {
        $known = self::known($admin);
        $requested = array_values(array_unique(array_map(
            static fn (string $scope): string => strtolower(trim($scope)),
            $requested
        )));
        if ($requested === []) {
            throw new OAuthException('invalid_scope', 'The client did not request a scope.');
        }
        foreach ($requested as $scope) {
            if (!in_array($scope, $known, true)) {
                throw new OAuthException('invalid_scope', "Unknown scope '{$scope}'.", toClient: true);
            }
        }
        if ($registered !== []) {
            foreach ($requested as $scope) {
                if (!in_array($scope, $registered, true)) {
                    throw new OAuthException('invalid_scope', "Scope '{$scope}' was not registered for this client.", toClient: true);
                }
            }
        }
        $selected = array_values(array_unique(array_map(
            static fn (string $scope): string => strtolower(trim($scope)),
            $selected
        )));
        if ($selected === []) {
            throw new OAuthException('invalid_scope', 'Choose at least one scope.', toClient: true);
        }
        foreach ($selected as $scope) {
            if (!in_array($scope, $requested, true)) {
                throw new OAuthException('invalid_scope', "Scope '{$scope}' was not requested.", toClient: true);
            }
        }
        return $selected;
    }

    /**
     * @return list<string>
     */
    public static function known(bool $admin): array
    {
        $out = [];
        foreach (Scope::AREAS as $area) {
            $out[] = $area . ':read';
            $out[] = $area . ':write';
        }
        if ($admin) {
            foreach (Scope::EXPLICIT as $scope) {
                $out[] = $scope;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $scopes
     */
    public static function scopeString(array $scopes): string
    {
        return implode(' ', $scopes);
    }

    /**
     * @return list<string>
     */
    public static function parseScope(string $scope): array
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $out[] = strtolower($part);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public static function protectedResource(string $resource, string $issuer, array $scopes): array
    {
        return [
            'resource' => $resource,
            'authorization_servers' => [$issuer],
            'scopes_supported' => $scopes,
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public static function authorizationServer(string $issuer, array $scopes): array
    {
        $base = rtrim($issuer, '/');
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => $scopes,
        ];
    }

    public static function metadataUrl(string $mcpPublicUrl): ?string
    {
        $mcpPublicUrl = trim($mcpPublicUrl);
        if ($mcpPublicUrl === '') {
            return null;
        }
        $parts = parse_url($mcpPublicUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return $origin . '/.well-known/oauth-protected-resource';
        }
        return $origin . '/.well-known/oauth-protected-resource/' . $path;
    }

    public static function wwwAuthenticate(string $mcpPublicUrl): string
    {
        $url = self::metadataUrl($mcpPublicUrl);
        if ($url === null) {
            return 'Bearer';
        }
        return 'Bearer resource_metadata="' . $url . '"';
    }

    public static function withQuery(string $uri, array $params): string
    {
        $parts = parse_url($uri);
        $query = [];
        if (is_array($parts) && isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }
        $base = $uri;
        $cut = strpos($base, '?');
        if ($cut !== false) {
            $base = substr($base, 0, $cut);
        }
        return $base . '?' . http_build_query($query);
    }
}
