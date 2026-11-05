<?php

declare(strict_types=1);

namespace Dashboard\Hermes;

/**
 * Loopback Hermes API server. The browser never sees the API key.
 *
 * @phpstan-type GatewayResult array{status: int, json: ?array<string, mixed>}
 */
final class Gateway
{
    public const BASE = 'http://127.0.0.1:8642';

    public const ENV_PATH = '/var/lib/lifedash/hermes/.env';

    /** @var null|callable(string, string, ?string, int): array{status: int, json: ?array<string, mixed>} */
    private $transport;

    public function __construct(private readonly string $key, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public static function fromEnv(string $path = self::ENV_PATH): self
    {
        return new self(self::keyFromEnv($path));
    }

    public static function keyFromEnv(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return '';
        }
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^API_SERVER_KEY=([A-Za-z0-9]{32,128})$/', trim((string) $line), $match) === 1) {
                return $match[1];
            }
        }

        return '';
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    public function healthy(): bool
    {
        $res = $this->call('GET', '/health', null, 3);

        return $res['status'] === 200 && (($res['json']['status'] ?? '') === 'ok');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, json: ?array<string, mixed>}
     */
    public function call(string $method, string $path, ?array $body, int $timeout): array
    {
        if (!str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\n")) {
            return ['status' => 0, 'json' => null];
        }
        if ($path !== '/health' && $this->key === '') {
            return ['status' => 0, 'json' => null];
        }
        $url = self::BASE . $path;
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        if ($this->transport !== null) {
            $res = ($this->transport)($method, $url, $payload, $timeout);
            $status = (int) ($res['status'] ?? 0);
            $json = $res['json'] ?? null;

            return ['status' => $status, 'json' => is_array($json) ? $json : null];
        }

        return $this->http($method, $url, $payload, $timeout);
    }

    /**
     * @return array{status: int, json: ?array<string, mixed>}
     */
    private function http(string $method, string $url, ?string $body, int $timeout): array
    {
        $header = "Accept: application/json\r\nContent-Type: application/json\r\n";
        if ($this->key !== '') {
            $header .= 'Authorization: Bearer ' . $this->key . "\r\n";
        }
        $options = [
            'http' => [
                'method'          => $method,
                'header'          => $header,
                'timeout'         => $timeout,
                'follow_location' => 0,
                'ignore_errors'   => true,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
        }
        $context = stream_context_create($options);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['status' => 0, 'json' => null];
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $match) === 1) {
            $status = (int) $match[0];
        }
        $decoded = json_decode($raw, true);

        return ['status' => $status, 'json' => is_array($decoded) ? $decoded : null];
    }
}
