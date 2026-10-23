<?php

declare(strict_types=1);

namespace Dashboard\Digest;

use Dashboard\Support\ValidationException;

/** https pages on a public host. The worker fetches whatever is saved here. */
final class DigestUrl
{
    public static function assert(string $url): string
    {
        $url = trim($url);
        if (strlen($url) > 500) {
            throw ValidationException::withFields(['url' => 'URL must be 500 characters or less.']);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withFields(['url' => 'Only https URLs can be saved.']);
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw ValidationException::withFields(['url' => 'That host is not saved.']);
        }
        if (self::blockedHost($host)) {
            throw ValidationException::withFields(['url' => 'That host is not saved.']);
        }
        return $url;
    }

    private static function blockedHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::blockedIp($host);
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $row) {
                if (!empty($row['ipv6'])) {
                    $ips[] = (string) $row['ipv6'];
                }
            }
        }
        if ($ips === []) {
            throw ValidationException::withFields(['url' => 'The host name did not resolve.']);
        }
        foreach ($ips as $ip) {
            if (self::blockedIp($ip)) {
                return true;
            }
        }
        return false;
    }

    private static function blockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
