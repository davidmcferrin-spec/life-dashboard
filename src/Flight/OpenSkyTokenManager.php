<?php

declare(strict_types=1);

namespace Dashboard\Flight;

use Dashboard\Config;
use Dashboard\Support\HttpClient;

/**
 * OAuth2 client-credentials token for OpenSky (30-minute lifetime).
 */
final class OpenSkyTokenManager
{
    private const TOKEN_URL = 'https://auth.opensky-network.org/auth/realms/opensky-network/protocol/openid-connect/token';
    private const CACHE_FILE = 'opensky_token.json';
    private const EXPIRY_BUFFER = 120;

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public function getAccessToken(): ?string
    {
        $clientId     = Config::maybe('OPENSKY_CLIENT_ID');
        $clientSecret = Config::maybe('OPENSKY_CLIENT_SECRET');

        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        $cached = $this->readCache();
        if ($cached !== null && ($cached['expires_at'] ?? 0) > time() + self::EXPIRY_BUFFER) {
            return (string) $cached['access_token'];
        }

        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        try {
            $response = $this->http->request(
                'POST',
                self::TOKEN_URL,
                $body,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                10,
            );
        } catch (\Throwable $e) {
            error_log('[Dashboard][OpenSky] Token request failed: ' . $e->getMessage());
            return $cached['access_token'] ?? null;
        }

        if ($response['status'] !== 200) {
            error_log('[Dashboard][OpenSky] Token HTTP ' . $response['status']);
            return $cached['access_token'] ?? null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $expiresIn = (int) ($data['expires_in'] ?? 1800);
        $this->writeCache([
            'access_token' => $data['access_token'],
            'expires_at'   => time() + $expiresIn,
        ]);

        return (string) $data['access_token'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCache(array $data): void
    {
        $dir = dirname($this->cachePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        file_put_contents($this->cachePath(), json_encode($data), LOCK_EX);
    }

    private function cachePath(): string
    {
        return STORAGE_DIR . '/cache/' . self::CACHE_FILE;
    }
}
