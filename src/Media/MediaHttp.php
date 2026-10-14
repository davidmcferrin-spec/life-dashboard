<?php

declare(strict_types=1);

namespace Dashboard\Media;

/**
 * GET one https URL whose host is on the caller's list.
 */
final class MediaHttp
{
    public function __construct(private readonly int $timeoutSeconds = 8) {}

    /**
     * @param list<string> $hosts
     * @param array<string, string> $headers
     */
    public function get(string $url, array $hosts, array $headers = []): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || !in_array($host, $hosts, true)) {
            throw new \RuntimeException('Blocked URL.');
        }

        $lines = ['User-Agent: life-dashboard/1.0 (self-hosted)', 'Accept: application/json, application/xml, text/xml, */*'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $this->timeoutSeconds,
                'header'          => implode("\r\n", $lines),
                'follow_location' => 1,
                'max_redirects'   => 2,
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
        if (strlen($body) > 2_000_000) {
            throw new \RuntimeException('The response was too large.');
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m) === 1) {
            $status = (int) $m[0];
        }
        if ($status >= 400) {
            throw new \RuntimeException('HTTP ' . $status . '.');
        }
        return $body;
    }
}
