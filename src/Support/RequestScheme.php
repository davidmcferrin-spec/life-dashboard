<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * HTTPS at the client. Forwarded scheme headers are trusted only from
 * loopback, which is where cloudflared connects. A LAN client cannot
 * turn a plain HTTP cookie into a Secure one by sending the header.
 */
final class RequestScheme
{
    /**
     * @param array<string, mixed> $server
     */
    public static function https(array $server): bool
    {
        $flag = $server['HTTPS'] ?? '';
        if (is_string($flag) && $flag !== '' && strtolower($flag) !== 'off') {
            return true;
        }
        $remote = $server['REMOTE_ADDR'] ?? '';
        if ($remote !== '127.0.0.1' && $remote !== '::1') {
            return false;
        }
        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($proto)) {
            $first = strtolower(trim(explode(',', $proto)[0]));
            if ($first === 'https') {
                return true;
            }
        }
        $visitor = $server['HTTP_CF_VISITOR'] ?? '';
        if (is_string($visitor) && str_contains(strtolower($visitor), '"scheme":"https"')) {
            return true;
        }
        return false;
    }

    public static function current(): bool
    {
        return self::https($_SERVER);
    }
}
