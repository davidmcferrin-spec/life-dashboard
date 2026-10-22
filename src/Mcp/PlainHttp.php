<?php

declare(strict_types=1);

namespace Dashboard\Mcp;

/**
 * Whether direct http://<host>:8765 is still accepted, and which hop a call used.
 * A server_flags row named mcp_plain_http overrides MCP_ALLOW_PLAIN_HTTP.
 * A missing row leaves the config value in place. The listen address does not move.
 */
final class PlainHttp
{
    public const FLAG = 'mcp_plain_http';

    public const CHANGE_DENIED = 'Plain HTTP access can only be changed from a signed-in browser.';

    /**
     * @return array{allowed: bool, source: 'config'|'database'}
     */
    public static function effective(?int $stored, bool $configAllows): array
    {
        if ($stored === null) {
            return ['allowed' => $configAllows, 'source' => 'config'];
        }
        return ['allowed' => $stored === 1, 'source' => 'database'];
    }

    public static function sourceText(bool $allowed, string $source = '', bool $configAllows = false): string
    {
        // $source and $configAllows stay so callers can pass the effective row. The page shows On or Off.
        return $allowed ? 'On.' : 'Off.';
    }

    /**
     * A bearer on this request is an agent, even if a browser session is also present.
     *
     * @param array<string, mixed> $server
     */
    public static function changeBlocked(array $server, bool $agentSession): bool
    {
        if ($agentSession) {
            return true;
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = $server[$key] ?? '';
            if (is_string($header) && preg_match('/^\s*Bearer\s+\S/i', $header) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Transport forwarded by lifedash-mcp. Ignored unless the peer is loopback,
     * so a LAN client cannot stamp HTTPS on a direct call to Apache.
     *
     * @param array<string, mixed> $server
     */
    public static function inbound(array $server): ?string
    {
        $remote = $server['REMOTE_ADDR'] ?? '';
        if ($remote !== '127.0.0.1' && $remote !== '::1') {
            return null;
        }
        $raw = $server['HTTP_X_LIFEDASH_TRANSPORT'] ?? '';
        if (!is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));
        return $value === 'http' || $value === 'https' ? $value : null;
    }
}
