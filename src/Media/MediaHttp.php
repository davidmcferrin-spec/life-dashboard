<?php

declare(strict_types=1);

namespace Dashboard\Media;

/**
 * GET one https URL whose host is on the caller's list.
 * Each redirect is checked again before it is followed.
 */
final class MediaHttp
{
    public const MAX_REDIRECTS = 2;

    /**
     * @param null|\Closure(string): array{status: int, headers: array<string, string>, body: string} $transport
     *        Test double. Production leaves this null and uses PHP streams.
     */
    public function __construct(
        private readonly int $timeoutSeconds = 8,
        private readonly ?\Closure $transport = null,
    ) {}

    /**
     * @param list<string> $hosts
     * @param array<string, string> $headers
     */
    public function get(string $url, array $hosts, array $headers = []): string
    {
        $lines = ['User-Agent: life-dashboard/1.0 (self-hosted)', 'Accept: application/json, application/xml, text/xml, */*'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $current = $url;
        for ($hop = 0; ; $hop++) {
            self::assertAllowed($current, $hosts);
            if ($hop > self::MAX_REDIRECTS) {
                throw new \RuntimeException('Too many redirects.');
            }

            $response = $this->requestOnce($current, $lines);
            $status   = $response['status'];
            if ($status >= 300 && $status < 400) {
                $current = self::resolveRedirect($current, $response['headers']['location'] ?? '');
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException($status === 0 ? 'The request failed.' : 'HTTP ' . $status . '.');
            }
            if (strlen($response['body']) > 2_000_000) {
                throw new \RuntimeException('The response was too large.');
            }
            return $response['body'];
        }
    }

    /**
     * Reject anything that is not https on the allowlist. No userinfo, so a
     * URL cannot smuggle credentials or a different host.
     *
     * @param list<string> $hosts
     */
    public static function assertAllowed(string $url, array $hosts): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('Blocked URL.');
        }
        $host    = strtolower((string) ($parts['host'] ?? ''));
        $scheme  = strtolower((string) ($parts['scheme'] ?? ''));
        $allowed = array_map(static fn (string $item): string => strtolower($item), $hosts);
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || !in_array($host, $allowed, true)) {
            throw new \RuntimeException('Blocked URL.');
        }
    }

    /**
     * Turn a Location header into an absolute URL. The caller must run
     * assertAllowed on the result before fetching it.
     */
    public static function resolveRedirect(string $current, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new \RuntimeException('Redirect had no location.');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $base   = parse_url($current);
        $scheme = strtolower((string) ($base['scheme'] ?? 'https'));
        $host   = (string) ($base['host'] ?? '');
        $port   = isset($base['port']) ? ':' . $base['port'] : '';
        if ($host === '') {
            throw new \RuntimeException('Redirect had no location.');
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }
        $path = (string) ($base['path'] ?? '/');
        $dir  = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';
        return $scheme . '://' . $host . $port . $dir . $location;
    }

    /**
     * @param list<string> $headerLines
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function requestOnce(string $url, array $headerLines): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url);
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $this->timeoutSeconds,
                'header'          => implode("\r\n", $headerLines),
                'follow_location' => 0,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException('The request failed.');
        }

        $status  = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status  = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
