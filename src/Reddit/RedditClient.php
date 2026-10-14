<?php

declare(strict_types=1);

namespace Dashboard\Reddit;

use Dashboard\Config;

/**
 * Reads public subreddit JSON, or oauth.reddit.com when a script app is configured.
 * One request at a time. A 429 or a used-up budget stops the rest of the run.
 */
final class RedditClient
{
    public const DEFAULT_AGENT = 'web:life-dashboard:1.0 (self-hosted)';

    private const HOSTS = ['www.reddit.com', 'reddit.com', 'oauth.reddit.com'];

    private const GAP_USEC = 1_000_000;

    private bool $stopped = false;

    private float $nextAt = 0.0;

    private ?string $token = null;

    /**
     * @param null|\Closure(string, string, array<string, string>, ?string): array{status: int, headers: array<string, string>, body: string} $transport
     *        method, url, headers, body. Tests pass this. Production leaves it null.
     * @param null|\Closure(int): void $sleeper microseconds
     */
    public function __construct(
        private readonly ?\Closure $transport = null,
        private readonly ?\Closure $sleeper = null,
        private readonly string $userAgent = self::DEFAULT_AGENT,
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
    ) {}

    public static function fromConfig(): self
    {
        $agent = Config::maybe('REDDIT_USER_AGENT');
        return new self(
            null,
            null,
            $agent !== null && $agent !== '' ? $agent : self::DEFAULT_AGENT,
            Config::maybe('REDDIT_CLIENT_ID'),
            Config::maybe('REDDIT_CLIENT_SECRET'),
        );
    }

    public function usesOauth(): bool
    {
        return $this->clientId !== null && $this->clientId !== ''
            && $this->clientSecret !== null && $this->clientSecret !== '';
    }

    public function rateLimited(): bool
    {
        return $this->stopped;
    }

    /**
     * @return list<array{id: string, subreddit: string, title: string, author: string, score: int, num_comments: int, url: string, permalink: string, created_utc: string, flair: ?string, selftext: string}>
     */
    public function listing(string $subreddit, string $sort): array
    {
        $subreddit = strtolower($subreddit);
        $sort = in_array($sort, ['hot', 'new', 'top'], true) ? $sort : 'hot';
        $query = 'limit=25&raw_json=1' . ($sort === 'top' ? '&t=week' : '');
        $host = $this->usesOauth() ? 'https://oauth.reddit.com' : 'https://www.reddit.com';
        $body = $this->get($host . '/r/' . rawurlencode($subreddit) . '/' . $sort . '.json?' . $query);
        return RedditListing::posts($body);
    }

    /**
     * @return list<array{id: string, author: string, score: int, body: string, created_utc: string}>
     */
    public function comments(string $postId, int $limit = 20): array
    {
        if (preg_match('/^[a-z0-9]{3,12}$/', $postId) !== 1) {
            throw new \RuntimeException('Unknown post.');
        }
        $host = $this->usesOauth() ? 'https://oauth.reddit.com' : 'https://www.reddit.com';
        $body = $this->get($host . '/comments/' . $postId . '.json?limit=' . max(1, min(40, $limit)) . '&depth=1&raw_json=1');
        return RedditListing::comments($body, $limit);
    }

    private function get(string $url): string
    {
        if ($this->stopped) {
            throw new \RuntimeException('Reddit rate limit. Waiting for the next run.');
        }
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
        if ($this->usesOauth()) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken();
        }
        return $this->request('GET', $url, $headers, null);
    }

    private function accessToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $basic = base64_encode($this->clientId . ':' . $this->clientSecret);
        $body = $this->request(
            'POST',
            'https://www.reddit.com/api/v1/access_token',
            [
                'Accept'        => 'application/json',
                'User-Agent'    => $this->userAgent,
                'Authorization' => 'Basic ' . $basic,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'grant_type=client_credentials'
        );
        $parsed = json_decode($body, true);
        $token = is_array($parsed) ? (string) ($parsed['access_token'] ?? '') : '';
        if ($token === '') {
            throw new \RuntimeException('Reddit did not return an access token. Check the app id and secret on Setup.');
        }
        $this->token = $token;
        return $token;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers, ?string $body): string
    {
        $this->assertHost($url);
        $hops = 0;
        while (true) {
            $this->pace();
            $response = $this->send($method, $url, $headers, $body);
            $status = $response['status'];
            $this->noteBudget($response['headers']);
            if ($status >= 300 && $status < 400) {
                $hops++;
                if ($hops > 2) {
                    throw new \RuntimeException('Too many redirects.');
                }
                $url = $this->resolve($url, $response['headers']['location'] ?? '');
                $this->assertHost($url);
                $method = 'GET';
                $body = null;
                continue;
            }
            if ($status === 429) {
                $this->stopped = true;
                throw new \RuntimeException('Reddit rate limit. Waiting for the next run.');
            }
            if ($status === 403) {
                throw new \RuntimeException('Reddit refused the request. Add a Reddit app id and secret on Setup, and a User-Agent that names this server.');
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException($status === 0 ? 'The request failed.' : 'Reddit returned HTTP ' . $status . '.');
            }
            if (strlen($response['body']) > 2_000_000) {
                throw new \RuntimeException('The response was too large.');
            }
            return $response['body'];
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function noteBudget(array $headers): void
    {
        $remaining = $headers['x-ratelimit-remaining'] ?? null;
        if ($remaining !== null && $remaining !== '' && (float) $remaining <= 0) {
            $this->stopped = true;
        }
    }

    private function pace(): void
    {
        $now = microtime(true);
        if ($this->nextAt > $now) {
            $wait = (int) ceil(($this->nextAt - $now) * 1_000_000);
            if ($this->sleeper !== null) {
                ($this->sleeper)($wait);
            } else {
                usleep($wait);
            }
        }
        $this->nextAt = microtime(true) + (self::GAP_USEC / 1_000_000);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ctx = stream_context_create([
            'http' => [
                'method'          => $method,
                'timeout'         => 12,
                'header'          => implode("\r\n", $lines),
                'content'         => $body ?? '',
                'follow_location' => 0,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $payload = @file_get_contents($url, false, $ctx);
        if ($payload === false) {
            throw new \RuntimeException('The request failed.');
        }

        $status = 0;
        $parsed = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $parsed = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $parsed[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
        return ['status' => $status, 'headers' => $parsed, 'body' => $payload];
    }

    private function assertHost(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('Blocked URL.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || !in_array($host, self::HOSTS, true)) {
            throw new \RuntimeException('Blocked URL.');
        }
    }

    private function resolve(string $current, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new \RuntimeException('Redirect had no location.');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $base = parse_url($current);
        $host = (string) ($base['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('Redirect had no location.');
        }
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        if (str_starts_with($location, '/')) {
            return 'https://' . $host . $port . $location;
        }
        $path = (string) ($base['path'] ?? '/');
        $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';
        return 'https://' . $host . $port . $dir . $location;
    }
}
