<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Support\HttpClient;

/**
 * Dropbox cloud storage via HTTP API.
 */
final class DropboxProvider implements CloudStorageProvider
{
    /** @var list<string> Scopes required for browse, upload, folder create, delete, and space usage. */
    public const OAUTH_SCOPES = [
        'files.content.read',
        'files.content.write',
        'account_info.read',
    ];

    private HttpClient $http;
    /** @var array<string, mixed> */
    private array $creds;

    public function __construct(
        array $credentials,
        private readonly Database $db,
        private readonly int $connectionId,
    ) {
        $this->creds = $credentials;
        $this->http  = new HttpClient();
    }

    public function ensureRootFolder(string $rootName): string
    {
        $path = '/' . trim($rootName, '/');
        $this->apiJson('POST', 'https://api.dropboxapi.com/2/files/create_folder_v2', [
            'path'       => $path,
            'autorename' => false,
        ], ignore409: true);

        return $path;
    }

    /**
     * @return array{used_bytes: int, limit_bytes: int}
     */
    public function getAccountSpaceUsage(): array
    {
        $data = $this->apiJson('POST', 'https://api.dropboxapi.com/2/users/get_space_usage', null);

        $used = $this->parseSpaceBytes($data['used'] ?? 0);
        $limit = $this->parseAllocationBytes($data['allocation'] ?? []);

        return [
            'used_bytes'  => $used,
            'limit_bytes' => $limit,
        ];
    }

    public function listFolder(string $parentPath, ?string $cursor = null): FolderListing
    {
        if ($cursor !== null && $cursor !== '') {
            $data = $this->apiJson('POST', 'https://api.dropboxapi.com/2/files/list_folder/continue', [
                'cursor' => $cursor,
            ]);
        } else {
            $data = $this->apiJson('POST', 'https://api.dropboxapi.com/2/files/list_folder', [
                'path'                                => $parentPath,
                'recursive'                           => false,
                'include_media_info'                  => false,
                'include_deleted'                     => false,
                'include_has_explicit_shared_members' => false,
            ]);
        }

        $entries = $data['entries'] ?? [];
        $items   = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $tag = (string) ($entry['.tag'] ?? $entry['tag'] ?? '');
            if ($tag === 'folder') {
                $items[] = new CloudNode(
                    (string) ($entry['name'] ?? ''),
                    CloudNode::TYPE_FOLDER,
                    (string) ($entry['path_display'] ?? ''),
                    (string) ($entry['id'] ?? ''),
                    mimeType: null,
                    modifiedAt: isset($entry['server_modified']) ? (string) $entry['server_modified'] : null,
                );
            } elseif ($tag === 'file') {
                $name = (string) ($entry['name'] ?? '');
                $items[] = new CloudNode(
                    $name,
                    CloudNode::TYPE_FILE,
                    (string) ($entry['path_display'] ?? ''),
                    (string) ($entry['id'] ?? ''),
                    mimeType: null,
                    sizeBytes: isset($entry['size']) ? (int) $entry['size'] : null,
                    ext: pathinfo($name, PATHINFO_EXTENSION) ?: null,
                    modifiedAt: isset($entry['server_modified']) ? (string) $entry['server_modified'] : null,
                );
            }
        }

        usort($items, static function (CloudNode $a, CloudNode $b): int {
            if ($a->isFolder() !== $b->isFolder()) {
                return $a->isFolder() ? -1 : 1;
            }

            return strcasecmp($a->name, $b->name);
        });

        return new FolderListing(
            $items,
            (bool) ($data['has_more'] ?? false),
            FolderListing::trailForPath($parentPath),
            isset($data['cursor']) ? (string) $data['cursor'] : null,
        );
    }

    public function createFolder(string $parentPath, string $name): CloudNode
    {
        $path = rtrim($parentPath, '/') . '/' . trim($name, '/');
        $data = $this->apiJson('POST', 'https://api.dropboxapi.com/2/files/create_folder_v2', [
            'path'       => $path,
            'autorename' => false,
        ], ignore409: true);

        $meta = $data['metadata'] ?? $data;

        return new CloudNode(
            (string) ($meta['name'] ?? $name),
            CloudNode::TYPE_FOLDER,
            (string) ($meta['path_display'] ?? $path),
            (string) ($meta['id'] ?? ''),
        );
    }

    public function upload(string $parentPath, string $localTmpPath, string $name, ?string $mime = null): CloudNode
    {
        $path = rtrim($parentPath, '/') . '/' . $name;
        $body = file_get_contents($localTmpPath);
        if ($body === false) {
            throw new \RuntimeException('Could not read upload file.');
        }

        $arg = json_encode([
            'path'       => $path,
            'mode'       => 'add',
            'autorename' => true,
            'mute'       => false,
        ], JSON_THROW_ON_ERROR);

        $response = $this->http->request(
            'POST',
            'https://content.dropboxapi.com/2/files/upload',
            $body,
            [
                'Authorization'   => 'Bearer ' . $this->accessToken(),
                'Content-Type'    => 'application/octet-stream',
                'Dropbox-API-Arg' => $arg,
            ],
            120,
        );

        if ($response['status'] >= 400) {
            throw new \RuntimeException('Dropbox upload failed: ' . substr($response['body'], 0, 200));
        }

        $meta = json_decode($response['body'], true);
        if (!is_array($meta)) {
            throw new \RuntimeException('Invalid Dropbox upload response.');
        }

        return new CloudNode(
            (string) ($meta['name'] ?? $name),
            CloudNode::TYPE_FILE,
            (string) ($meta['path_display'] ?? $path),
            (string) ($meta['id'] ?? ''),
            mimeType: $mime,
            sizeBytes: isset($meta['size']) ? (int) $meta['size'] : (filesize($localTmpPath) ?: null),
            ext: pathinfo($name, PATHINFO_EXTENSION) ?: null,
            modifiedAt: isset($meta['server_modified']) ? (string) $meta['server_modified'] : null,
        );
    }

    public function downloadStream(string $providerPath): StreamMeta
    {
        $arg = json_encode(['path' => $providerPath], JSON_THROW_ON_ERROR);
        $response = $this->http->request(
            'POST',
            'https://content.dropboxapi.com/2/files/download',
            null,
            [
                'Authorization'   => 'Bearer ' . $this->accessToken(),
                'Dropbox-API-Arg' => $arg,
            ],
            120,
        );

        if ($response['status'] >= 400) {
            throw new \RuntimeException('Dropbox download failed.');
        }

        $name = basename($providerPath);
        $tmp  = tempnam(sys_get_temp_dir(), 'dbx_');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temp file.');
        }
        file_put_contents($tmp, $response['body']);

        return new StreamMeta($tmp, 'application/octet-stream', $name, false, strlen($response['body']));
    }

    public function delete(string $providerPath): void
    {
        $this->apiJson('POST', 'https://api.dropboxapi.com/2/files/delete_v2', [
            'path' => $providerPath,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function exchangeCode(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $clientSecret,
    ): array {
        $redirect = self::redirectUri();

        if ($clientId === '') {
            throw new \InvalidArgumentException('Dropbox is not configured.');
        }

        $body = http_build_query([
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirect,
            'code_verifier' => $codeVerifier,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $http = new HttpClient();
        $response = $http->request(
            'POST',
            'https://api.dropboxapi.com/oauth2/token',
            $body,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            30,
        );

        if ($response['status'] >= 400) {
            throw new \RuntimeException('Dropbox token exchange failed.');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Invalid Dropbox token response.');
        }

        return $data;
    }

    public static function authorizeUrl(string $state, string $codeChallenge, string $clientId): string
    {
        if ($clientId === '') {
            throw new \InvalidArgumentException('Dropbox is not configured.');
        }

        $params = http_build_query([
            'client_id'             => $clientId,
            'response_type'         => 'code',
            'redirect_uri'          => self::redirectUri(),
            'state'                 => $state,
            'scope'                 => implode(' ', self::OAUTH_SCOPES),
            'token_access_type'     => 'offline',
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return 'https://www.dropbox.com/oauth2/authorize?' . $params;
    }

    public static function redirectUri(): string
    {
        return rtrim((string) Config::get('APP_URL', ''), '/') . '/api/storage/oauth/callback/dropbox';
    }

    private function accessToken(): string
    {
        $expires = (int) ($this->creds['expires_at'] ?? 0);
        if ($expires > time() + 60) {
            return (string) ($this->creds['access_token'] ?? '');
        }

        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string
    {
        $refresh = (string) ($this->creds['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new \RuntimeException('Dropbox session expired; reconnect storage.');
        }

        $conn = (new ConnectionRepository($this->db))->findById($this->connectionId);
        $userId = (int) ($conn['user_id'] ?? 0);
        $app    = (new UserDropboxAppService($this->db))->getCredentials($userId);
        if ($app === null) {
            throw new \RuntimeException('Dropbox app credentials missing; reconfigure on Files tab.');
        }

        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => $app['app_key'],
            'client_secret' => $app['app_secret'],
        ]);

        $response = $this->http->request(
            'POST',
            'https://api.dropboxapi.com/oauth2/token',
            $body,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            30,
        );

        if ($response['status'] >= 400) {
            throw new \RuntimeException('Dropbox token refresh failed.');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Invalid Dropbox refresh response.');
        }

        $this->creds['access_token'] = $data['access_token'];
        $this->creds['expires_at']   = time() + (int) ($data['expires_in'] ?? 3600);

        $conn = (new ConnectionRepository($this->db))->findById($this->connectionId);
        if ($conn !== null) {
            (new ConnectionRepository($this->db))->update($this->connectionId, (int) $conn['user_id'], [
                'credentials_encrypted' => CredentialCrypto::encryptJson($this->creds),
            ]);
        }

        return (string) $this->creds['access_token'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function apiJson(
        string $method,
        string $url,
        ?array $payload,
        bool $ignore409 = false,
    ): array {
        $body = $payload === null ? '{}' : json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $this->http->request(
            $method,
            $url,
            $body,
            [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type'  => 'application/json',
            ],
            60,
        );

        if ($ignore409 && $response['status'] === 409) {
            return json_decode($response['body'], true) ?: [];
        }

        if ($response['status'] >= 400) {
            throw new \RuntimeException(self::formatApiError($response['body']));
        }

        if ($response['body'] === '') {
            return [];
        }

        $data = json_decode($response['body'], true);

        return is_array($data) ? $data : [];
    }

    private static function formatApiError(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return 'Dropbox API error: ' . substr($body, 0, 240);
        }

        $summary = strtolower((string) ($data['error_summary'] ?? $data['error'] ?? ''));
        if ($summary === '') {
            return 'Dropbox API error: ' . substr($body, 0, 240);
        }

        if (str_contains($summary, 'files.content.write')) {
            return 'Dropbox app is missing the files.content.write permission. In the Dropbox developer console, open your app → Permissions, enable files.content.read, files.content.write, and account_info.read, then disconnect and authorize again.';
        }

        if (str_contains($summary, 'files.content.read')) {
            return 'Dropbox app is missing the files.content.read permission. Enable files.content.read, files.content.write, and account_info.read on your app, then disconnect and authorize again.';
        }

        if (str_contains($summary, 'account_info.read')) {
            return 'Dropbox app is missing the account_info.read permission (required for storage usage). Enable it on your app, then disconnect and authorize again.';
        }

        return 'Dropbox API error: ' . substr((string) ($data['error_summary'] ?? $data['error'] ?? $body), 0, 240);
    }

    /**
     * @param mixed $value
     */
    private function parseSpaceBytes(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        if (!is_array($value)) {
            return 0;
        }

        foreach (['other', 'individual', 'team'] as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                return (int) $value[$key];
            }
        }

        return 0;
    }

    /**
     * @param mixed $allocation
     */
    private function parseAllocationBytes(mixed $allocation): int
    {
        if (!is_array($allocation)) {
            return 0;
        }

        if (isset($allocation['allocated']) && is_numeric($allocation['allocated'])) {
            return (int) $allocation['allocated'];
        }

        $team = $allocation['user_within_team'] ?? null;
        if (is_array($team) && isset($team['allocated']) && is_numeric($team['allocated'])) {
            return (int) $team['allocated'];
        }

        return 0;
    }
}
