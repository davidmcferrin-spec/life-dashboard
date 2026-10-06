<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * RealtimeToken — short-lived, HMAC-signed ticket for the WebSocket push service.
 *
 * Format: base64url(json{"u":userId,"exp":unix}) . "." . base64url(HMAC-SHA256)
 * Key:    HMAC-SHA256(key=APP_SECRET, msg="lifedash-realtime-v1")
 *
 * services/lifedash/push.py verifies the same format with the same derived key
 * (tests on both sides pin the format). The browser fetches a ticket over the
 * authenticated session, so the WebSocket never needs the session cookie.
 */
final class RealtimeToken
{
    public const TTL_SECONDS = 120;
    private const CONTEXT = 'lifedash-realtime-v1';

    public static function issue(int $userId, string $appSecret, ?int $now = null, int $ttl = self::TTL_SECONDS): string
    {
        $payload = self::b64(json_encode(['u' => $userId, 'exp' => ($now ?? time()) + $ttl], JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, self::key($appSecret), true));
    }

    /** User id if valid and unexpired, else null. */
    public static function verify(string $token, string $appSecret, ?int $now = null): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $payload, self::key($appSecret), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $data = json_decode((string) self::unb64($payload), true);
        if (!is_array($data) || !is_int($data['u'] ?? null) || !is_int($data['exp'] ?? null)) {
            return null;
        }
        return $data['exp'] >= ($now ?? time()) ? $data['u'] : null;
    }

    private static function key(string $appSecret): string
    {
        if (strlen($appSecret) < 16) {
            // An empty/short secret would make tickets forgeable.
            throw new \RuntimeException('APP_SECRET must be set (>= 16 chars) to issue realtime tokens.');
        }
        return hash_hmac('sha256', self::CONTEXT, $appSecret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
