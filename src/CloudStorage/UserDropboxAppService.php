<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Support\HttpClient;

/**
 * Per-user Dropbox OAuth app credentials (encrypted in user_preferences).
 */
final class UserDropboxAppService
{
    private const PREF_KEY = 'dropbox_app_credentials';

    public function __construct(private readonly Database $db) {}

    public function isConfigured(int $userId): bool
    {
        $creds = $this->getCredentials($userId);

        return $creds !== null
            && ($creds['app_key'] ?? '') !== ''
            && ($creds['app_secret'] ?? '') !== '';
    }

    /**
     * @return array{app_key: string, app_secret: string}|null
     */
    public function getCredentials(int $userId): ?array
    {
        $raw = $this->readEncrypted($userId);
        if ($raw === null) {
            return null;
        }

        $key    = trim((string) ($raw['app_key'] ?? ''));
        $secret = (string) ($raw['app_secret'] ?? '');

        if ($key === '' || $secret === '') {
            return null;
        }

        return ['app_key' => $key, 'app_secret' => $secret];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicStatus(int $userId): array
    {
        $creds = $this->readEncrypted($userId);

        return [
            'configured'     => $this->isConfigured($userId),
            'app_key'        => trim((string) ($creds['app_key'] ?? '')),
            'app_secret_set' => ($creds['app_secret'] ?? '') !== '',
            'redirect_uri'   => DropboxProvider::redirectUri(),
            'required_scopes' => DropboxProvider::OAUTH_SCOPES,
        ];
    }

    public function save(int $userId, string $appKey, string $appSecret): void
    {
        $appKey = trim($appKey);
        $current = $this->readEncrypted($userId) ?? [];

        if ($appSecret === '') {
            $appSecret = (string) ($current['app_secret'] ?? '');
        }

        if ($appKey === '') {
            throw new \InvalidArgumentException('Dropbox app key is required.');
        }

        if ($appSecret === '') {
            throw new \InvalidArgumentException('Dropbox app secret is required.');
        }

        $this->writeEncrypted($userId, [
            'app_key'    => $appKey,
            'app_secret' => $appSecret,
        ]);
    }

    public function clear(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::PREF_KEY],
        );
    }

    /**
     * @param array<string, mixed> $override
     * @return array{ok: bool, message: string}
     */
    public function test(int $userId, array $override = []): array
    {
        $clientId = trim((string) ($override['app_key'] ?? ''));
        $clientSecret = trim((string) ($override['app_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            $saved = $this->getCredentials($userId);
            if ($clientId === '' && $saved !== null) {
                $clientId = $saved['app_key'];
            }
            if ($clientSecret === '' && $saved !== null) {
                $clientSecret = $saved['app_secret'];
            }
        }

        if ($clientId === '') {
            return ['ok' => false, 'message' => 'Dropbox app key is required.'];
        }

        if ($clientSecret === '') {
            return ['ok' => false, 'message' => 'Dropbox app secret is required to test credentials.'];
        }

        if (rtrim((string) Config::get('APP_URL', ''), '/') === '') {
            return ['ok' => false, 'message' => 'APP_URL is not set — required for OAuth redirect URI.'];
        }

        $body = http_build_query([
            'code'          => 'dashboard-credential-test',
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => DropboxProvider::redirectUri(),
            'code_verifier' => 'dashboard-credential-test-verifier',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $http     = new HttpClient();
        $response = $http->request(
            'POST',
            'https://api.dropboxapi.com/oauth2/token',
            $body,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            20,
        );

        return $this->interpretOAuthTokenResponse($response['body']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readEncrypted(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::PREF_KEY],
        );

        if ($row === null || ($row['pref_value'] ?? '') === '') {
            return null;
        }

        try {
            return CredentialCrypto::decryptJson((string) $row['pref_value']);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeEncrypted(int $userId, array $payload): void
    {
        $encrypted = CredentialCrypto::encryptJson($payload);
        $existing  = $this->db->fetchOne(
            'SELECT id FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::PREF_KEY],
        );

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)',
                [$userId, self::PREF_KEY, $encrypted],
            );

            return;
        }

        $this->db->execute(
            'UPDATE user_preferences SET pref_value = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND pref_key = ?',
            [$encrypted, $userId, self::PREF_KEY],
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function interpretOAuthTokenResponse(string $body): array
    {
        $data = json_decode($body, true);
        $error = is_array($data) ? strtolower((string) ($data['error'] ?? '')) : '';

        if (in_array($error, ['invalid_grant', 'invalid_request'], true)) {
            return [
                'ok'      => true,
                'message' => 'App key and secret look good. Save credentials, then click Authorize with Dropbox.',
            ];
        }

        if (in_array($error, ['invalid_client', 'unauthorized_client'], true)) {
            return [
                'ok'      => false,
                'message' => 'Dropbox rejected the app key or secret — double-check both values in the Dropbox developer console.',
            ];
        }

        if ($error !== '') {
            return [
                'ok'      => false,
                'message' => "Dropbox test returned: {$error}.",
            ];
        }

        return [
            'ok'      => false,
            'message' => 'Dropbox test returned an unexpected response.',
        ];
    }
}
