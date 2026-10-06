<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * Minimal HTTP client using PHP streams (no curl dependency).
 */
final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        int $timeoutSeconds = 15,
    ): array {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headerLines),
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $context = stream_context_create($options);
        $result  = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \RuntimeException("HTTP request failed: {$method} {$url}");
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
            $status = (int) $m[0];
        }

        $parsedHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        return [
            'status'  => $status,
            'body'    => $result,
            'headers' => $parsedHeaders,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    public function requestJson(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        int $timeoutSeconds = 15,
    ): ?array {
        $response = $this->request($method, $url, $body, $headers, $timeoutSeconds);

        if ($response['body'] === '') {
            return null;
        }

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : null;
    }
}
