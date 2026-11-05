<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * One GET with a timeout. Redirects are not followed.
 * The secret, when passed, is stripped from any error text.
 */
final class JsonHttp
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, json: array<string, mixed>|null, error: string}
     */
    public static function get(string $url, array $headers, int $timeout, string $secret = ''): array
    {
        if (!self::allowed($url)) {
            return ['status' => 0, 'json' => null, 'error' => 'URL must be http or https.'];
        }
        $header = "Accept: application/json\r\n";
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                return ['status' => 0, 'json' => null, 'error' => 'A header was rejected.'];
            }
            $header .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => $header,
                'timeout'         => max(1, $timeout),
                'follow_location' => 0,
                'ignore_errors'   => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context, 0, 500000);
        if ($raw === false) {
            return ['status' => 0, 'json' => null, 'error' => 'The service did not answer.'];
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', (string) $http_response_header[0], $match) === 1) {
            $status = (int) $match[0];
        }
        $decoded = json_decode($raw, true);
        $json = is_array($decoded) ? $decoded : null;
        if ($status < 200 || $status >= 300) {
            $detail = is_array($json) && isset($json['detail']) && is_string($json['detail']) ? $json['detail'] : '';

            return [
                'status' => $status,
                'json'   => null,
                'error'  => self::redact(trim('HTTP ' . $status . ' ' . $detail), $secret),
            ];
        }

        return ['status' => $status, 'json' => $json, 'error' => ''];
    }

    public static function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '' && !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function redact(string $text, string $secret): string
    {
        if ($secret !== '') {
            $text = str_replace($secret, '[redacted]', $text);
        }
        $text = (string) preg_replace('/(api[_-]?key|token|authorization)\s*[:=]\s*\S+/i', '$1 [redacted]', $text);

        return mb_substr(trim($text), 0, 180);
    }
}
