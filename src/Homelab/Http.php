<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

/**
 * HTTP for one homelab app.
 * The base URL is split once. Request paths are encoded per segment so a colon
 * in an id is not handed to parse_url(), which rejects that shape.
 */
final class Http
{
    private const CONNECT_SECONDS = 5;

    private const MAX_BODY = 2000000;

    private string $scheme;

    private string $host;

    private int $port;

    private string $prefix;

    private string $cookie = '';

    /**
     * @param callable|null $transport function(string $method, string $url, ?string $body, array $headers, int $timeout): array{status:int, body:string, headers?:array<string, string>}
     * @param array<string, string> $headers
     * @param list<string> $redact
     */
    public function __construct(
        string $baseUrl,
        private readonly mixed $transport = null,
        private array $headers = [],
        private readonly array $redact = [],
    ) {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new HomelabException('The base URL is not usable.', 'homelab_invalid', 422);
        }
        $this->scheme = strtolower((string) $parts['scheme']);
        $this->host = (string) $parts['host'];
        $this->port = (int) ($parts['port'] ?? ($this->scheme === 'https' ? 443 : 80));
        $this->prefix = rtrim((string) ($parts['path'] ?? ''), '/');
    }

    public function withCookie(string $cookie): void
    {
        $this->cookie = $cookie;
    }

    public function withHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function origin(): string
    {
        $host = $this->host;
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $origin = $this->scheme . '://' . $host;
        $default = $this->scheme === 'https' ? 443 : 80;
        if ($this->port !== $default) {
            $origin .= ':' . $this->port;
        }

        return $origin;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>|list<mixed>
     */
    public function json(string $method, string $path, ?array $body = null, array $query = [], int $timeout = 8): array
    {
        $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $response = $this->call($method, $path, $encoded, $query, $timeout, 'application/json');
        if ($response['body'] === '') {
            return [];
        }
        $requestPath = $this->prefix . self::encodePath($path);
        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HomelabException(
                'The service returned a response that was not JSON. HTTP ' . $response['status'] . ' ' . $requestPath,
                'homelab_invalid',
                502,
                ['upstream_status' => $response['status'], 'path' => $requestPath]
            );
        }
        if (!is_array($decoded)) {
            throw new HomelabException(
                'The service returned a response that was not JSON. HTTP ' . $response['status'] . ' ' . $requestPath,
                'homelab_invalid',
                502,
                ['upstream_status' => $response['status'], 'path' => $requestPath]
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, scalar|null> $fields
     * @param array<string, scalar|null> $query
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function form(string $method, string $path, array $fields, array $query = [], int $timeout = 12): array
    {
        return $this->call($method, $path, http_build_query($fields), $query, $timeout, 'application/x-www-form-urlencoded');
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function call(string $method, string $path, ?string $body, array $query, int $timeout, string $contentType): array
    {
        if ($timeout > 20) {
            set_time_limit($timeout + self::CONNECT_SECONDS + 5);
        }
        $requestPath = $this->prefix . self::encodePath($path);
        $urlPath = $requestPath;
        if ($query !== []) {
            $urlPath .= '?' . http_build_query($query);
        }
        $headers = $this->headers;
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';
        if ($this->cookie !== '') {
            $headers['Cookie'] = $this->cookie;
        }
        if ($body !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $response = $this->exchange($method, $this->origin() . $urlPath, $urlPath, $body, $headers, $timeout);
        $status = (int) ($response['status'] ?? 0);
        $raw = $this->redactText((string) ($response['body'] ?? ''));
        $responseHeaders = [];
        foreach (is_array($response['headers'] ?? null) ? $response['headers'] : [] as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $responseHeaders[strtolower($name)] = $this->redactText((string) $value);
            }
        }
        if ($status === 0) {
            throw $this->unreachable();
        }
        if ($status === 401 || $status === 403) {
            throw new HomelabException(
                $this->httpError($status, $requestPath, $raw),
                'homelab_unauthorized',
                502,
                ['upstream_status' => $status, 'path' => $requestPath]
            );
        }
        if ($status >= 400) {
            throw new HomelabException(
                $this->httpError($status, $requestPath, $raw),
                'homelab_error',
                $status >= 500 ? 502 : 422,
                ['upstream_status' => $status, 'path' => $requestPath]
            );
        }

        return ['status' => $status, 'body' => $raw, 'headers' => $responseHeaders];
    }

    public static function encodePath(string $path): string
    {
        $lead = str_starts_with($path, '/');
        $parts = explode('/', $path);
        $encoded = [];
        foreach ($parts as $part) {
            $encoded[] = rawurlencode(rawurldecode($part));
        }
        $out = implode('/', $encoded);
        if ($lead && !str_starts_with($out, '/')) {
            $out = '/' . $out;
        }

        return $out;
    }

    public static function cookieValue(array $headers, string $name): string
    {
        $raw = (string) ($headers['set-cookie'] ?? '');
        if ($raw === '') {
            return '';
        }
        if (preg_match('/(?:^|[\s,])' . preg_quote($name, '/') . '=([^;\s]+)/i', $raw, $match) !== 1) {
            return '';
        }

        return rawurldecode($match[1]);
    }

    /**
     * qBittorrent names the session cookie SID, or QBT_SID_<port> when the port is part of the name.
     * The client has to send the name the server set, not a name it assumed.
     */
    public static function sessionCookie(array $headers): string
    {
        $raw = (string) ($headers['set-cookie'] ?? '');
        if ($raw === '') {
            return '';
        }
        if (preg_match('/(?:^|[\r\n,])\s*((?:QBT_)?SID(?:_\d+)?)=([^;\s]+)/i', $raw, $match) !== 1) {
            return '';
        }

        return $match[1] . '=' . $match[2];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    private function exchange(string $method, string $url, string $urlPath, ?string $body, array $headers, int $timeout): array
    {
        if (is_callable($this->transport)) {
            try {
                $response = ($this->transport)($method, $url, $body, $headers, $timeout);
            } catch (\RuntimeException) {
                // A stand-in transport reports a dead socket the same way the stream client does.
                throw $this->unreachable();
            }
            if (!is_array($response)) {
                throw $this->unreachable();
            }

            return $response;
        }

        return $this->stream($method, $urlPath, $body, $headers, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function stream(string $method, string $urlPath, ?string $body, array $headers, int $timeout): array
    {
        $remoteHost = $this->host;
        if (str_contains($remoteHost, ':') && !str_starts_with($remoteHost, '[')) {
            $remoteHost = '[' . $remoteHost . ']';
        }
        $remote = ($this->scheme === 'https' ? 'ssl://' : 'tcp://') . $remoteHost . ':' . $this->port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, self::CONNECT_SECONDS, STREAM_CLIENT_CONNECT, $context);
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
        $request = $method . ' ' . ($urlPath === '' ? '/' : $urlPath) . " HTTP/1.1\r\nHost: " . $this->hostHeader() . "\r\n" . implode("\r\n", $lines) . "\r\n\r\n" . ($body ?? '');
        $written = @fwrite($socket, $request);
        if ($written === false || $written < strlen($request)) {
            fclose($socket);
            throw $this->unreachable();
        }
        stream_set_timeout($socket, $timeout);
        $raw = $this->readSocket($socket);
        $meta = stream_get_meta_data($socket);
        fclose($socket);
        if (!empty($meta['timed_out']) && $raw === '') {
            throw new HomelabException(
                'The service at ' . $this->origin() . ' did not answer before the wait ended.',
                'homelab_timeout',
                504
            );
        }
        if ($raw === '') {
            throw $this->unreachable();
        }

        return $this->splitHttp($raw);
    }

    /**
     * @param resource $socket
     */
    private function readSocket($socket): string
    {
        $raw = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
            if (strlen($raw) > self::MAX_BODY) {
                throw new HomelabException('The service response was too large.', 'homelab_invalid', 502);
            }
        }

        return $raw;
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function splitHttp(string $raw): array
    {
        $cut = strpos($raw, "\r\n\r\n");
        if ($cut === false) {
            return ['status' => 0, 'body' => '', 'headers' => []];
        }
        $head = substr($raw, 0, $cut);
        $body = substr($raw, $cut + 4);
        $status = 0;
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $match) === 1) {
            $status = (int) $match[1];
        }
        $headers = [];
        foreach (preg_split("/\r\n/", $head) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $value = trim($value);
            if ($key === 'set-cookie' && isset($headers[$key])) {
                $headers[$key] .= "\n" . $value;
                continue;
            }
            $headers[$key] = $value;
        }
        if (isset($headers['transfer-encoding']) && stripos($headers['transfer-encoding'], 'chunked') !== false) {
            $body = $this->unchunk($body);
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
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
            $out .= substr($rest, $cut + 2, (int) $size);
            $rest = substr($rest, $cut + 2 + (int) $size + 2);
        }

        return $out;
    }

    private function hostHeader(): string
    {
        $host = $this->host;
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $default = $this->scheme === 'https' ? 443 : 80;
        if ($this->port !== $default) {
            $host .= ':' . $this->port;
        }

        return $host;
    }

    private function unreachable(): HomelabException
    {
        return new HomelabException(
            'The service is unreachable at ' . $this->origin() . '.',
            'homelab_unreachable',
            502
        );
    }

    private function httpError(int $status, string $path, string $raw): string
    {
        $detail = $this->snippet($raw, $status);
        $message = 'HTTP ' . $status . ' ' . $path;
        if ($detail !== '' && !str_starts_with($detail, 'The service returned HTTP')) {
            $message .= ': ' . $detail;
        }

        return $this->redactText($message);
    }

    private function snippet(string $raw, int $status): string
    {
        $message = '';
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (isset($decoded['message']) && is_string($decoded['message'])) {
                $message = trim($decoded['message']);
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $message = trim($decoded['error']);
            } elseif (isset($decoded['title']) && is_string($decoded['title'])) {
                $message = trim($decoded['title']);
            }
        }
        if ($message === '') {
            $flat = trim((string) (preg_replace('/\s+/', ' ', $raw) ?? ''));
            $message = $flat !== '' ? $flat : 'The service returned HTTP ' . $status . '.';
        }

        return mb_substr($this->redactText($message), 0, 300);
    }

    private function redactText(string $text): string
    {
        foreach ($this->redact as $secret) {
            if ($secret !== '' && strlen($secret) >= 4 && str_contains($text, $secret)) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return $text;
    }
}
