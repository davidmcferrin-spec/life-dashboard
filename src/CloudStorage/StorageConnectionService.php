<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\DB\Database;
use Dashboard\Settings\CloudStorageConfigService;

final class StorageConnectionService
{
    public const ROOT_FOLDER = 'PersonalDashboard';

    private ConnectionRepository $connections;

    public function __construct(private readonly Database $db)
    {
        $this->connections = new ConnectionRepository($db);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeConnection(int $userId): ?array
    {
        return $this->connections->findActiveByUserId($userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $dropboxApp = new UserDropboxAppService($this->db);
        $conn = $this->connections->findByUserId($userId);
        if ($conn === null) {
            return array_merge($this->baseStatus($userId), [
                'connected'           => false,
                'provider'            => null,
                'root_path'           => null,
                'migration_dismissed' => false,
                'dropbox_app'         => $dropboxApp->publicStatus($userId),
            ]);
        }

        if ((bool) ($conn['is_active'] ?? 0) && self::needsRootRepair($conn)) {
            try {
                $conn = $this->repairRootPathIfNeeded($userId, $conn);
            } catch (\Throwable) {
                // Browse/upload will surface provider errors; status still loads.
            }
        }

        $payload = [
            'connected'           => (bool) ($conn['is_active'] ?? 0),
            'provider'            => (string) ($conn['provider'] ?? ''),
            'root_path'           => self::effectiveRootPath($conn),
            'migration_dismissed' => (bool) ($conn['migration_dismissed'] ?? 0),
            'disconnected_at'     => $conn['disconnected_at'] ?? null,
            'dropbox_app'         => $dropboxApp->publicStatus($userId),
        ];

        if ((bool) ($conn['is_active'] ?? 0)) {
            $usage = (new StorageUsageService($this->db))->forConnection($conn);
            if ($usage !== null) {
                $payload['storage_usage'] = $usage;
            }
        }

        return array_merge($this->baseStatus($userId), $payload);
    }

    /**
     * True when OAuth left a placeholder root before folder setup completed.
     *
     * @param array<string, mixed> $conn
     */
    public static function needsRootRepair(array $conn): bool
    {
        $root = trim((string) ($conn['root_provider_path'] ?? ''));

        return $root === '' || $root === '/';
    }

    /**
     * Resolve the scoped root folder path for path guards and UI.
     *
     * @param array<string, mixed> $conn
     */
    public static function effectiveRootPath(array $conn): string
    {
        $stored = trim((string) ($conn['root_provider_path'] ?? ''));
        if ($stored === '' || $stored === '/') {
            return '/' . ltrim(self::rootFolderName(), '/');
        }

        $normalized = CloudPathGuard::normalize($stored);
        if ($normalized === '/') {
            return '/' . ltrim(self::rootFolderName(), '/');
        }

        return CloudPathGuard::normalizeRoot($normalized);
    }

    /**
     * Create the cloud root folder and persist its provider path when missing.
     *
     * @param array<string, mixed> $conn
     * @return array<string, mixed>
     */
    public function repairRootPathIfNeeded(int $userId, array $conn): array
    {
        if (!self::needsRootRepair($conn)) {
            return $conn;
        }

        $provider = ProviderFactory::fromConnection($conn, $this->db);
        $rootPath  = $provider->ensureRootFolder(self::rootFolderName());

        $this->connections->update((int) $conn['id'], $userId, [
            'root_provider_path' => $rootPath,
            'root_provider_id'   => $rootPath,
        ]);

        $conn['root_provider_path'] = $rootPath;
        $conn['root_provider_id']   = $rootPath;

        return $conn;
    }

    /**
     * @return array<string, array{configured: bool, oauth: bool}>
     */
    public function providerOptions(int $userId): array
    {
        $dropbox = (new UserDropboxAppService($this->db))->isConfigured($userId);

        return [
            ProviderFactory::PROVIDER_DROPBOX => [
                'configured' => $dropbox,
                'oauth'      => true,
            ],
            ProviderFactory::PROVIDER_S3 => [
                'configured' => true,
                'oauth'      => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseStatus(int $userId): array
    {
        return [
            'local_files_pending' => $this->countLocalFiles($userId),
            'providers'           => $this->providerOptions($userId),
        ];
    }

    public function startOAuth(int $userId, string $provider): string
    {
        if (!ProviderFactory::isValid($provider)) {
            throw new \InvalidArgumentException('Invalid storage provider.');
        }

        $state = bin2hex(random_bytes(16));
        OAuthSession::put($provider, [
            'state'   => $state,
            'user_id' => $userId,
        ]);

        if ($provider === ProviderFactory::PROVIDER_DROPBOX) {
            $app = (new UserDropboxAppService($this->db))->getCredentials($userId);
            if ($app === null) {
                throw new \InvalidArgumentException('Dropbox app credentials are not saved.');
            }

            $verifier  = bin2hex(random_bytes(32));
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            OAuthSession::put($provider, [
                'state'         => $state,
                'user_id'       => $userId,
                'code_verifier' => $verifier,
            ]);

            return DropboxProvider::authorizeUrl($state, $challenge, $app['app_key']);
        }

        throw new \InvalidArgumentException('Provider does not use OAuth.');
    }

    public function handleOAuthCallback(int $userId, string $provider, string $code, string $state): void
    {
        $session = OAuthSession::pull($provider);
        if ($session === null || ($session['state'] ?? '') !== $state || (int) ($session['user_id'] ?? 0) !== $userId) {
            throw new \InvalidArgumentException('Invalid OAuth state.');
        }

        $creds = match ($provider) {
            ProviderFactory::PROVIDER_DROPBOX => $this->dropboxCredentials(
                $userId,
                $code,
                (string) ($session['code_verifier'] ?? ''),
            ),
            default                           => throw new \InvalidArgumentException('Invalid provider.'),
        };

        $existing = $this->connections->findByUserId($userId);
        $base     = [
            'provider'              => $provider,
            'credentials_encrypted' => CredentialCrypto::encryptJson($creds),
            'root_provider_path'    => '/',
            'root_provider_id'      => '/',
            'is_active'             => 0,
            'disconnected_at'       => null,
        ];

        if ($existing === null) {
            $connectionId = $this->connections->insert(array_merge($base, ['user_id' => $userId]));
        } else {
            $connectionId = (int) $existing['id'];
            $this->connections->update($connectionId, $userId, $base);
        }

        try {
            $conn         = $this->connections->findByIdForUser($connectionId, $userId);
            $providerImpl = ProviderFactory::fromConnection($conn ?? [], $this->db);
            $rootPath     = $providerImpl->ensureRootFolder(self::rootFolderName());

            $this->connections->update($connectionId, $userId, [
                'root_provider_path' => $rootPath,
                'root_provider_id'   => $rootPath,
                'is_active'          => 1,
            ]);
        } catch (\Throwable $e) {
            $this->connections->update($connectionId, $userId, ['is_active' => 0]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function connectS3(int $userId, array $input): void
    {
        $creds = S3Provider::normalizeCredentials($input, $userId);
        S3Provider::validateCredentials($creds);

        $provider = new S3Provider($creds);
        $rootPath = $provider->ensureRootFolder(self::rootFolderName());

        $payload = [
            'user_id'               => $userId,
            'provider'              => ProviderFactory::PROVIDER_S3,
            'credentials_encrypted' => CredentialCrypto::encryptJson($creds),
            'root_provider_path'    => $rootPath,
            'root_provider_id'      => $rootPath,
            'is_active'             => 1,
            'disconnected_at'       => null,
        ];

        $existing = $this->connections->findByUserId($userId);
        if ($existing === null) {
            $this->connections->insert($payload);
        } else {
            $this->connections->update((int) $existing['id'], $userId, $payload);
        }
    }

    /**
     * @return array{cloud_files: int, active_share_links: int}
     */
    public function disconnectStats(int $userId): array
    {
        $conn = $this->connections->findActiveByUserId($userId);
        if ($conn === null) {
            return ['cloud_files' => 0, 'active_share_links' => 0];
        }

        $nodes = (new NodeRepository($this->db))->listForConnection((int) $conn['id'], $userId);
        $files = array_filter($nodes, static fn (array $n): bool => ($n['node_type'] ?? '') === CloudNode::TYPE_FILE);

        return [
            'cloud_files'        => count($files),
            'active_share_links' => (new StorageShareRepository($this->db))->countActiveLinksForUser($userId),
        ];
    }

    public function disconnect(int $userId): void
    {
        $conn = $this->connections->findActiveByUserId($userId);
        if ($conn === null) {
            return;
        }

        $this->connections->update((int) $conn['id'], $userId, [
            'is_active'             => 0,
            'disconnected_at'       => date('Y-m-d H:i:s'),
            'credentials_encrypted' => CredentialCrypto::encryptJson([]),
        ]);
    }

    public function dismissMigration(int $userId): void
    {
        $conn = $this->connections->findByUserId($userId);
        if ($conn === null) {
            return;
        }

        $this->connections->update((int) $conn['id'], $userId, [
            'migration_dismissed' => 1,
        ]);
    }

    public static function rootFolderName(): string
    {
        return CloudStorageConfigService::instance()->get(
            'CLOUD_ROOT_FOLDER_NAME',
            self::ROOT_FOLDER,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dropboxCredentials(int $userId, string $code, string $verifier): array
    {
        $app = (new UserDropboxAppService($this->db))->getCredentials($userId);
        if ($app === null) {
            throw new \InvalidArgumentException('Dropbox app credentials are not saved.');
        }

        $data = DropboxProvider::exchangeCode(
            $code,
            $verifier,
            $app['app_key'],
            $app['app_secret'],
        );

        return [
            'access_token'  => (string) ($data['access_token'] ?? ''),
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_at'    => time() + (int) ($data['expires_in'] ?? 3600),
        ];
    }

    private function countLocalFiles(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_files WHERE user_id = ?',
            [$userId]
        );
    }
}
