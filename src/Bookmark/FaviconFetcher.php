<?php

declare(strict_types=1);

namespace Dashboard\Bookmark;

use Dashboard\Support\HttpClient;

final class FaviconFetcher
{
    private const MAX_HTML_BYTES = 262_144;
    private const MAX_REDIRECTS  = 3;

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * @return array{bytes: string, content_type: string|null}
     */
    public function fetchForPageUrl(string $pageUrl): array
    {
        $pageUrl = $this->normalizeHttpUrl($pageUrl);
        $parts   = parse_url($pageUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid bookmark URL.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        $candidates = [
            $origin . '/favicon.ico',
            $origin . '/favicon.png',
        ];

        foreach ($this->discoverFromHtml($pageUrl) as $href) {
            $candidates[] = $href;
        }

        $seen = [];
        foreach ($candidates as $candidate) {
            $resolved = $this->resolveUrl($pageUrl, $candidate);
            if ($resolved === null || isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;

            try {
                return $this->fetchBinary($resolved);
            } catch (\Throwable) {
                continue;
            }
        }

        throw new \RuntimeException('Could not find a favicon for this site.');
    }

    /**
     * Likely favicon URLs for a page (no binary fetch). HTML discovery runs when reachable.
     *
     * @return list<string>
     */
    public function listCandidatesForPageUrl(string $pageUrl): array
    {
        $pageUrl = $this->normalizeHttpUrl($pageUrl);
        $parts   = parse_url($pageUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid bookmark URL.');
        }

        $candidates = self::defaultOriginIconPaths($pageUrl);

        foreach ($this->discoverFromHtml($pageUrl) as $href) {
            $candidates[] = $href;
        }

        $seen = [];
        $out  = [];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveUrl($pageUrl, $candidate);
            if ($resolved === null || isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;
            $out[]           = $resolved;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function defaultOriginIconPaths(string $pageUrl): array
    {
        $pageUrl = trim($pageUrl);
        if (!preg_match('#^https?://#i', $pageUrl)) {
            $pageUrl = 'https://' . $pageUrl;
        }

        $parts = parse_url($pageUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return [];
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return [
            $origin . '/favicon.ico',
            $origin . '/favicon.png',
            $origin . '/apple-touch-icon.png',
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverFromHtml(string $pageUrl): array
    {
        try {
            $response = $this->http->request('GET', $pageUrl, null, [
                'User-Agent' => 'PersonalDashboard/1.0 (Favicon Fetcher)',
                'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ], 8);
        } catch (\Throwable) {
            return [];
        }

        if ($response['status'] < 200 || $response['status'] >= 400) {
            return [];
        }

        $html = substr($response['body'], 0, self::MAX_HTML_BYTES);
        $out  = [];

        if (preg_match_all(
            '/<link\b[^>]*>/i',
            $html,
            $tags,
        )) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('/\brel=["\']([^"\']+)["\']/i', $tag, $relMatch)) {
                    continue;
                }
                $rels = preg_split('/\s+/', strtolower($relMatch[1])) ?: [];
                if (!array_intersect($rels, ['icon', 'shortcut', 'shortcut icon', 'apple-touch-icon'])) {
                    continue;
                }
                if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                    continue;
                }
                $resolved = $this->resolveUrl($pageUrl, html_entity_decode($hrefMatch[1], ENT_QUOTES | ENT_HTML5));
                if ($resolved !== null) {
                    $out[] = $resolved;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{bytes: string, content_type: string|null}
     */
    private function fetchBinary(string $url, int $redirects = 0): array
    {
        $this->assertSafeUrl($url);

        $response = $this->http->request('GET', $url, null, [
            'User-Agent' => 'PersonalDashboard/1.0 (Favicon Fetcher)',
            'Accept'     => 'image/*,*/*;q=0.8',
        ], 8);

        if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
            if ($redirects >= self::MAX_REDIRECTS) {
                throw new \RuntimeException('Too many redirects.');
            }
            $location = $response['headers']['location'] ?? '';
            if ($location === '') {
                throw new \RuntimeException('Redirect without location.');
            }
            $next = $this->resolveUrl($url, $location);
            if ($next === null) {
                throw new \RuntimeException('Invalid redirect URL.');
            }

            return $this->fetchBinary($next, $redirects + 1);
        }

        if ($response['status'] < 200 || $response['status'] >= 400) {
            throw new \RuntimeException('HTTP ' . $response['status']);
        }

        $bytes = $response['body'];
        if ($bytes === '') {
            throw new \RuntimeException('Empty response.');
        }

        $contentType = $response['headers']['content-type'] ?? null;

        return ['bytes' => $bytes, 'content_type' => $contentType];
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP(S) URLs are allowed.');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            throw new \InvalidArgumentException('Local hosts cannot be fetched from the server.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isPrivateIp($host)) {
                throw new \InvalidArgumentException('Private network addresses cannot be fetched from the server.');
            }

            return;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '' && $this->isPrivateIp($ip)) {
                    throw new \InvalidArgumentException('Host resolves to a private network address.');
                }
            }
        }
    }

    private function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function resolveUrl(string $base, string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '' || str_starts_with($relative, 'data:')) {
            return null;
        }

        if (preg_match('#^https?://#i', $relative)) {
            return $this->normalizeHttpUrl($relative);
        }

        $parts = parse_url($base);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($relative, '//')) {
            return $parts['scheme'] . ':' . $relative;
        }

        if (str_starts_with($relative, '/')) {
            return $origin . $relative;
        }

        $path = $parts['path'] ?? '/';
        $dir  = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

        return $origin . ($dir !== '' ? $dir : '') . '/' . $relative;
    }
}
