<?php

declare(strict_types=1);

namespace Dashboard\Package;

use Dashboard\Config;
use Dashboard\Support\HttpClient;

/**
 * OAuth2 client-credentials token for UPS Track API.
 */
final class UpsTokenManager
{
    private const CACHE_FILE = 'ups_token.json';
    private const EXPIRY_BUFFER = 120;

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public function getAccessToken(): ?string
    {
        $clientId     = Config::maybe('UPS_CLIENT_ID');
        $clientSecret = Config::maybe('UPS_CLIENT_SECRET');

        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        $cached = $this->readCache();
        if ($cached !== null && ($cached['expires_at'] ?? 0) > time() + self::EXPIRY_BUFFER) {
            return (string) $cached['access_token'];
        }

        $url  = $this->baseUrl() . '/security/v1/oauth/token';
        $auth = base64_encode($clientId . ':' . $clientSecret);

        try {
            $response = $this->http->request(
                'POST',
                $url,
                'grant_type=client_credentials',
                [
                    'Authorization'  => 'Basic ' . $auth,
                    'Content-Type'   => 'application/x-www-form-urlencoded',
                    'Accept'         => 'application/json',
                    'x-merchant-id'  => $clientId,
                ],
                15,
            );
        } catch (\Throwable $e) {
            error_log('[Dashboard][UPS] Token request failed: ' . $e->getMessage());
            return $cached['access_token'] ?? null;
        }

        if ($response['status'] !== 200) {
            error_log('[Dashboard][UPS] Token HTTP ' . $response['status'] . ': ' . $response['body']);
            return $cached['access_token'] ?? null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $this->writeCache([
            'access_token' => $data['access_token'],
            'expires_at'   => time() + $expiresIn,
        ]);

        return (string) $data['access_token'];
    }

    public function baseUrl(): string
    {
        if (Config::bool('UPS_USE_SANDBOX', false) || Config::bool('PACKAGES_SANDBOX', false)) {
            return 'https://wwwcie.ups.com';
        }

        return 'https://onlinetools.ups.com';
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
