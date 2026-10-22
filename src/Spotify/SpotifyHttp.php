<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

/**
 * Spotify Web API. The transport is injectable so tests never call Spotify.
 *
 * @phpstan-type Response array{status: int, body: string, headers: array<string, string>}
 */
final class SpotifyHttp
{
    public const API = 'https://api.spotify.com/v1';
    public const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    /** @var callable(string, string, ?string, array<string, string>): array{status: int, body: string, headers: array<string, string>} */
    private $transport;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param callable(string, string, ?string, array<string, string>): array{status: int, body: string, headers: array<string, string>}|null $transport
     * @param callable(int): void|null $sleeper
     */
    public function __construct(?callable $transport = null, ?callable $sleeper = null)
    {
        $this->transport = $transport ?? $this->defaultTransport();
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    public function token(array $form, string $clientId, string $clientSecret): array
    {
        $basic = base64_encode($clientId . ':' . $clientSecret);
        $body = http_build_query($form);
        return $this->send('POST', self::TOKEN_URL, $body, [
            'Authorization' => 'Basic ' . $basic,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ]);
    }

    /**
     * @param array<string, mixed>|\stdClass|null $json
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function api(string $method, string $path, string $accessToken, array|\stdClass|null $json = null, array $query = []): array
    {
        $url = str_starts_with($path, 'https://') ? $path : self::API . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR);
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        return $this->send($method, $url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, ?string $body, array $headers): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $response = ($this->transport)($method, $url, $body, $headers);
            $status = (int) ($response['status'] ?? 0);
            $raw = (string) ($response['body'] ?? '');
            $responseHeaders = [];
            foreach ($response['headers'] ?? [] as $name => $value) {
                $responseHeaders[strtolower((string) $name)] = (string) $value;
            }
            if ($status >= 200 && $status < 300) {
                if (trim($raw) === '') {
                    return [];
                }
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : [];
            }
            $retryAfter = isset($responseHeaders['retry-after']) && is_numeric($responseHeaders['retry-after'])
                ? (int) $responseHeaders['retry-after']
                : null;
            $wait = Retry::backoffSeconds($status, $retryAfter, $attempt);
            if ($wait !== null) {
                ($this->sleeper)($wait);
                continue;
            }
            throw new SpotifyException(
                self::message($status, $url, $raw),
                $status === 401 || $status === 400 ? 409 : ($status === 404 ? 404 : 502),
                $status === 401 || ($status === 400 && str_contains($raw, 'invalid_grant')),
            );
        }
    }

    public static function message(int $status, string $path, string $body): string
    {
        $lower = strtolower($path);
        if ($status === 403 && (str_contains($lower, 'recommendation') || str_contains($lower, 'audio-features') || str_contains($lower, 'audio-analysis'))) {
            return 'Spotify does not offer recommendations or audio features to this app.';
        }
        if ($status === 403) {
            return 'Spotify refused that request (403).';
        }
        $err = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $code = $decoded['error'] ?? null;
            if (is_string($code)) {
                $err = $code;
            } elseif (is_array($code) && isset($code['message']) && is_string($code['message'])) {
                $err = $code['message'];
            }
            if ($err === '' && isset($decoded['error_description']) && is_string($decoded['error_description'])) {
                $err = $decoded['error'];
                if (!is_string($err) || $err === '') {
                    $err = 'rejected';
                }
            }
        }
        $err = self::scrub($err);
        if ($err === '') {
            return "Spotify request failed ({$status}).";
        }
        return "Spotify request failed ({$status}): {$err}";
    }

    public static function scrub(string $text): string
    {
        $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/(access_token|refresh_token|client_secret|code_verifier|authorization)\s*[:=]\s*\S+/i', '$1=[redacted]', $text) ?? $text;
        return trim($text);
    }

    /**
     * @return callable(string, string, ?string, array<string, string>): array{status: int, body: string, headers: array<string, string>}
     */
    private function defaultTransport(): callable
    {
        $http = new \Dashboard\Support\HttpClient();
        return static function (string $method, string $url, ?string $body, array $headers) use ($http): array {
            return $http->request($method, $url, $body, $headers, 20);
        };
    }
}
