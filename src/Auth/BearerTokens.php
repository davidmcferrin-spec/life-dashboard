<?php

declare(strict_types=1);

namespace Dashboard\Auth;

/**
 * Static agent tokens stay on api_tokens. OAuth access tokens are a separate
 * table. A leftover or revoked static token must not fall through to OAuth.
 */
final class BearerTokens
{
    /**
     * @param callable(string): ?array $static
     * @param callable(string): ?array $oauth
     * @return array<string, mixed>|null
     */
    public static function resolve(string $plain, callable $static, callable $oauth): ?array
    {
        if (str_starts_with($plain, 'ldoa_')) {
            $row = $oauth($plain);
            return is_array($row) ? $row : null;
        }
        $row = $static($plain);
        if (is_array($row) || str_starts_with($plain, 'ld_')) {
            return is_array($row) ? $row : null;
        }
        $row = $oauth($plain);
        return is_array($row) ? $row : null;
    }
}
