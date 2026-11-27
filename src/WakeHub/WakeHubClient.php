<?php

declare(strict_types=1);

namespace Dashboard\WakeHub;

/**
 * Server-side caller for Wake Hub's versioned REST API.
 * The bearer token is a header only. It is not written into errors.
 */
final class WakeHubClient
{
    public const TIMEOUT_READ = 8;

    public const TIMEOUT_POWER = 45;

    public const TIMEOUT_CONNECT = 5;

    // A device may wait up to 300 seconds for the host. The client has to outlast that.
    public const TIMEOUT_WAKE = 330;

    /**
     * @param callable|null $transport function(string $method, string $url, ?string $body, array $headers, int $timeout): array{status: int, body: string, sent?: bool}
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly mixed $transport = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $path): array
    {
        $payload = $this->call('GET', $path, null, self::TIMEOUT_READ);
        if (!array_is_list($payload)) {
            throw new WakeHubException('Wake Hub returned an unexpected list for ' . $path . '.', 'wakehub_invalid', 502);
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
    public function statusOf(WakeHubTarget $target): array
    {
        $path = match ($target->kind) {
            'device' => '/api/v1/devices/' . $target->id . '/status',
            'amt' => '/api/v1/amt/' . $target->id . '/power',
            'pdu', 'outlet' => '/api/v1/pdus/' . $target->id . '/outlets',
            'plug' => '/api/v1/plugs/' . $target->id . '/status',
            'jetkvm' => '/api/v1/jetkvm/' . $target->id . '/status',
            'ups' => '/api/v1/ups/' . $target->id . '/status',
            'roku' => '/api/v1/rokus/' . $target->id . '/status',
            default => '',
        };
        if ($path === '') {
            throw new WakeHubException('That Wake Hub target has no status.', 'wakehub_invalid', 422);
        }
        $payload = $this->object($this->call('GET', $path, null, self::TIMEOUT_READ), 'status');
        if ($target->kind !== 'outlet' || $target->outlet === null) {
            return $payload;
        }
        foreach (is_array($payload['outlets'] ?? null) ? $payload['outlets'] : [] as $outlet) {
            if (is_array($outlet) && (int) ($outlet['number'] ?? 0) === $target->outlet) {
                $outlet['pdu_id'] = $target->id;

                return $outlet;
            }
        }

        throw new WakeHubException('PDU outlet ' . $target->ref . ' was not found.', 'not_found', 404, ['hub_status' => 404]);
    }

    /**
     * @return array<string, mixed>
     */
    public function wake(WakeHubTarget $target): array
    {
        if ($target->kind !== 'device') {
            throw new WakeHubException(
                'Wake-on-LAN applies to a device. Use power on for ' . $target->ref . '.',
                'wakehub_invalid',
                422
            );
        }

        return $this->object(
            $this->call('POST', '/api/v1/devices/' . $target->id . '/wake', [], self::TIMEOUT_WAKE),
            'wake'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function power(WakeHubTarget $target, string $action, bool $confirm, string $method = 'auto'): array
    {
        $wire = WakeHubActions::forKind($target->kind, $action);
        if ($target->kind === 'device') {
            return $this->object($this->call('POST', '/api/v1/devices/' . $target->id . '/power', [
                'action'  => $wire,
                'method'  => $method,
                'confirm' => $confirm,
            ], self::TIMEOUT_WAKE), 'power');
        }
        if ($target->kind === 'amt') {
            $path = '/api/v1/amt/' . $target->id . '/power';
            $body = ['action' => $wire, 'confirm' => $confirm];
        } elseif ($target->kind === 'outlet') {
            $path = '/api/v1/pdus/' . $target->id . '/outlets/' . $target->outlet;
            $body = ['action' => $wire, 'confirm' => $confirm];
        } elseif ($target->kind === 'plug') {
            $path = '/api/v1/plugs/' . $target->id . '/power';
            $body = ['action' => $wire, 'confirm' => $confirm];
        } elseif ($target->kind === 'jetkvm') {
            $path = '/api/v1/jetkvm/' . $target->id . '/power';
            $body = ['action' => $wire, 'confirm' => $confirm];
        } elseif ($target->kind === 'ups') {
            $path = '/api/v1/ups/' . $target->id . '/action';
            $body = ['action' => $wire, 'confirm' => $confirm];
        } elseif ($target->kind === 'roku') {
            $path = '/api/v1/rokus/' . $target->id . '/power';
            $body = ['action' => $wire, 'confirm' => $confirm];
        } else {
            throw new WakeHubException('That Wake Hub target cannot take a power action.', 'wakehub_invalid', 422);
        }

        return $this->object($this->call('POST', $path, $body, self::TIMEOUT_POWER), 'power');
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
        if ($timeout > 30) {
            set_time_limit($timeout + self::TIMEOUT_CONNECT + 5);
        }
        $url = $this->baseUrl . $path;
        $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ];
        if ($encoded !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->exchange($method, $url, $encoded, $headers, $timeout);
        $status = (int) ($response['status'] ?? 0);
        $raw = $this->redact((string) ($response['body'] ?? ''));
        if ($status === 0) {
            if (!empty($response['sent'])) {
                throw $this->sentTimeout();
            }
            throw $this->unreachable();
        }
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if ($status >= 400) {
            throw $this->hubError($status, $raw, is_array($decoded) ? $decoded : null);
        }
        if (!is_array($decoded)) {
            throw new WakeHubException('Wake Hub returned a response that was not JSON.', 'wakehub_invalid', 502, ['hub_status' => $status]);
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, sent?: bool}
     */
    private function exchange(string $method, string $url, ?string $body, array $headers, int $timeout): array
    {
        if (is_callable($this->transport)) {
            try {
                $response = ($this->transport)($method, $url, $body, $headers, $timeout);
            } catch (\RuntimeException) {
                throw $this->unreachable();
            }
            if (!is_array($response)) {
                throw $this->unreachable();
            }

            return $response;
        }

        return $this->stream($method, $url, $body, $headers, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function stream(string $method, string $url, ?string $body, array $headers, int $timeout): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $remote = ($scheme === 'https' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT_CONNECT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw $this->unreachable();
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($body !== null) {
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        $lines[] = 'Connection: close';
        $hostHeader = $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port !== $defaultPort) {
            $hostHeader .= ':' . $port;
        }
        $request = $method . ' ' . $path . " HTTP/1.1\r\nHost: " . $hostHeader . "\r\n" . implode("\r\n", $lines) . "\r\n\r\n" . ($body ?? '');
        $written = @fwrite($socket, $request);
        if ($written === false || $written < strlen($request)) {
            fclose($socket);
            throw $this->unreachable();
        }
        stream_set_timeout($socket, $timeout);
        $raw = stream_get_contents($socket);
        $meta = stream_get_meta_data($socket);
        fclose($socket);
        if (!empty($meta['timed_out'])) {
            throw $this->sentTimeout();
        }
        if (!is_string($raw) || $raw === '') {
            throw $this->unreachable();
        }
        [$status, $payload] = $this->splitHttp($raw);

        return ['status' => $status, 'body' => $payload];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function splitHttp(string $raw): array
    {
        $cut = strpos($raw, "\r\n\r\n");
        if ($cut === false) {
            return [0, ''];
        }
        $head = substr($raw, 0, $cut);
        $body = substr($raw, $cut + 4);
        $status = 0;
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $match) === 1) {
            $status = (int) $match[1];
        }
        if (stripos($head, 'transfer-encoding: chunked') !== false) {
            $body = $this->unchunk($body);
        }

        return [$status, $body];
    }

    private function unchunk(string $body): string
    {
        $out = '';
        $rest = $body;
        while ($rest !== '') {
            $cut = strpos($rest, "\r\n");
            if ($cut === false) {
                break;
            }
            $size = hexdec(trim(substr($rest, 0, $cut)));
            if ($size === 0) {
                break;
            }
            $out .= substr($rest, $cut + 2, $size);
            $rest = substr($rest, $cut + 2 + $size + 2);
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function hubError(int $status, string $raw, ?array $decoded): WakeHubException
    {
        $message = '';
        $code = 'wakehub_error';
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error)) {
                $message = trim((string) ($error['message'] ?? ''));
                $named = trim((string) ($error['code'] ?? ''));
                if ($named !== '') {
                    $code = $named;
                }
            } elseif (is_string($error) && trim($error) !== '') {
                $message = trim($error);
            } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                $message = trim($decoded['message']);
            }
        }
        if ($message === '') {
            $snippet = trim((string) (preg_replace('/\s+/', ' ', $raw) ?? ''));
            $message = $snippet !== '' ? mb_substr($snippet, 0, 300) : 'Wake Hub returned HTTP ' . $status . '.';
        }
        $message = $this->redact($message);
        $http = $status;
        if ($status === 401) {
            // A 401 on this dashboard means the caller's own key was rejected.
            $http = 502;
            $code = 'wakehub_unauthorized';
        }

        return new WakeHubException($message, $code, $http, ['hub_status' => $status]);
    }

    private function unreachable(): WakeHubException
    {
        return new WakeHubException(
            'Wake Hub is unreachable at ' . $this->baseUrl . '.',
            'wakehub_unreachable',
            502
        );
    }

    private function sentTimeout(): WakeHubException
    {
        return new WakeHubException(
            'The request was sent to ' . $this->baseUrl . '. Wake Hub did not answer before the wait ended, so a wake packet may already have been sent.',
            'wakehub_timeout',
            504,
            ['sent' => null, 'reachable' => null, 'elapsed_s' => null]
        );
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
        if ($this->token !== '' && str_contains($text, $this->token)) {
            $text = str_replace($this->token, '[redacted]', $text);
        }
        $text = (string) (preg_replace('/("(?:password|community|token|private_key)"\s*:\s*")[^"]*"/i', '$1[redacted]"', $text) ?? $text);

        return $text;
    }
}
