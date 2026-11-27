<?php

declare(strict_types=1);

namespace Dashboard\WakeHub;

use Dashboard\Support\HttpClient;

/**
 * Server-side caller for Wake Hub's versioned REST API.
 * The bearer token is a header only. It is not written into errors.
 */
final class WakeHubClient
{
    public const TIMEOUT_READ = 8;

    public const TIMEOUT_POWER = 20;

    // Wake Hub waits until the host answers or the device timeout ends.
    public const TIMEOUT_WAKE = 35;

    private readonly HttpClient $http;

    /**
     * @param callable|null $transport function(string $method, string $url, ?string $body, array $headers, int $timeout): array{status: int, body: string}
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        ?HttpClient $http = null,
        private readonly mixed $transport = null,
    ) {
        $this->http = $http ?? new HttpClient();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function devices(): array
    {
        $payload = $this->call('GET', '/api/v1/devices', null, self::TIMEOUT_READ);
        if (!array_is_list($payload)) {
            throw new WakeHubException('Wake Hub returned an unexpected device list.', 'wakehub_invalid', 502);
        }
        $devices = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $devices[] = $row;
            }
        }

        return $devices;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $deviceId): array
    {
        $payload = $this->call('GET', '/api/v1/devices/' . $deviceId . '/status', null, self::TIMEOUT_READ);

        return $this->object($payload, 'status');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statusAll(): array
    {
        $payload = $this->call('GET', '/api/v1/status', null, self::TIMEOUT_READ);
        if (!array_is_list($payload)) {
            throw new WakeHubException('Wake Hub returned an unexpected status list.', 'wakehub_invalid', 502);
        }
        $rows = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function wake(int $deviceId): array
    {
        $payload = $this->call('POST', '/api/v1/devices/' . $deviceId . '/wake', [], self::TIMEOUT_WAKE);

        return $this->object($payload, 'wake');
    }

    /**
     * @return array<string, mixed>
     */
    public function power(int $deviceId, string $action, bool $confirm, string $method = 'auto'): array
    {
        $payload = $this->call('POST', '/api/v1/devices/' . $deviceId . '/power', [
            'action'  => $action,
            'method'  => $method,
            'confirm' => $confirm,
        ], self::TIMEOUT_POWER);

        return $this->object($payload, 'power');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function metrics(int $deviceId): array
    {
        $payload = $this->call('GET', '/api/v1/devices/' . $deviceId . '/metrics', null, self::TIMEOUT_READ);
        if (!array_is_list($payload)) {
            throw new WakeHubException('Wake Hub returned an unexpected metrics list.', 'wakehub_invalid', 502);
        }
        $rows = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|list<mixed>
     */
    private function call(string $method, string $path, ?array $body, int $timeout): array
    {
        $url = $this->baseUrl . $path;
        $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ];
        if ($encoded !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            if (is_callable($this->transport)) {
                $response = ($this->transport)($method, $url, $encoded, $headers, $timeout);
            } else {
                $response = $this->http->request($method, $url, $encoded, $headers, $timeout);
            }
        } catch (\RuntimeException) {
            throw new WakeHubException(
                'Wake Hub is unreachable at ' . $this->baseUrl . '.',
                'wakehub_unreachable',
                502
            );
        }
        if (!is_array($response)) {
            throw new WakeHubException(
                'Wake Hub is unreachable at ' . $this->baseUrl . '.',
                'wakehub_unreachable',
                502
            );
        }
        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');
        $decoded = $raw === '' ? [] : json_decode($this->redact($raw), true);
        if ($status === 0) {
            throw new WakeHubException(
                'Wake Hub is unreachable at ' . $this->baseUrl . '.',
                'wakehub_unreachable',
                502
            );
        }
        if ($status === 401) {
            throw new WakeHubException('Wake Hub rejected the token.', 'wakehub_unauthorized', 502);
        }
        if ($status >= 400) {
            $message = 'Wake Hub returned an error.';
            $code = 'wakehub_error';
            if (is_array($decoded)) {
                $error = $decoded['error'] ?? null;
                if (is_array($error)) {
                    $message = $this->redact((string) ($error['message'] ?? $message));
                    $code = (string) ($error['code'] ?? $code);
                } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                    $message = $this->redact($decoded['error']);
                }
            }
            if ($code === '') {
                $code = 'wakehub_error';
            }
            $http = $status === 404 ? 404 : ($status === 409 ? 409 : ($status >= 500 ? 502 : 422));
            throw new WakeHubException($message, $code, $http);
        }
        if (!is_array($decoded)) {
            throw new WakeHubException('Wake Hub returned a response that was not JSON.', 'wakehub_invalid', 502);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return array<string, mixed>
     */
    private function object(array $payload, string $what): array
    {
        if (array_is_list($payload)) {
            throw new WakeHubException('Wake Hub returned an unexpected ' . $what . ' response.', 'wakehub_invalid', 502);
        }

        return $payload;
    }

    private function redact(string $text): string
    {
        if ($this->token === '' || !str_contains($text, $this->token)) {
            return $text;
        }

        return str_replace($this->token, '[redacted]', $text);
    }
}
