<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\DB\Database;
use Dashboard\Files\FileRepository;
use Dashboard\Files\FileService;
use Dashboard\Files\FileStorage;
use Dashboard\Settings\CloudStorageConfigService;

final class StorageService
{
    private ConnectionRepository $connections;
    private NodeRepository $nodes;
    private StorageShareRepository $shares;

    public function __construct(private readonly Database $db)
    {
        $this->connections = new ConnectionRepository($db);
        $this->nodes       = new NodeRepository($db);
        $this->shares      = new StorageShareRepository($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function browse(int $userId, ?string $path, ?string $cursor): array
    {
        $conn     = $this->requireActiveConnection($userId);
        $provider = ProviderFactory::fromConnection($conn, $this->db);
        $browsePath = $this->resolveBrowsePath($conn, $path);
        $listing  = $provider->listFolder($browsePath, $cursor);
        $root     = StorageConnectionService::effectiveRootPath($conn);

        $items = array_map(function (CloudNode $node) use ($conn, $userId): array {
            $dbNode = $this->nodes->findByProviderPath((int) $conn['id'], $node->providerPath);
            if ($dbNode === null && $node->isFile()) {
                $dbNode = $this->registerNode($userId, $conn, $node);
            }

            return $this->summarizeNode($node, $dbNode);
        }, $listing->items);

        return [
            'path'       => $browsePath,
            'items'      => $items,
            'breadcrumb' => CloudPathGuard::breadcrumbTrail($root, $browsePath),
            'cursor'     => $listing->cursor,
            'has_more'   => $listing->hasMore,
            'provider'   => (string) $conn['provider'],
        ];
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array<string, mixed>
     */
    public function upload(int $userId, string $parentPath, array $file): array
    {
        $conn     = $this->requireActiveConnection($userId);
        $provider = ProviderFactory::fromConnection($conn, $this->db);
        $target   = $this->resolveBrowsePath($conn, $parentPath);

        $name = FileStorage::sanitizeOriginalName((string) ($file['name'] ?? 'upload'));
        $tmp  = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp) ?: 'application/octet-stream';

        $node = $provider->upload($target, $tmp, $name, $mime);
        $row  = $this->registerNode($userId, $conn, $node);

        return $this->summarizeNode($node, $row);
    }

    public function createFolder(int $userId, string $parentPath, string $name): array
    {
        $conn     = $this->requireActiveConnection($userId);
        $provider = ProviderFactory::fromConnection($conn, $this->db);
        $target   = $this->resolveBrowsePath($conn, $parentPath);
        $name     = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Folder name is required.');
        }

        $node = $provider->createFolder($target, $name);
        $row  = $this->registerNode($userId, $conn, $node);

        return $this->summarizeNode($node, $row);
    }

    /**
     * @return array{meta: StreamMeta, inline: bool}
     */
    public function downloadByNodeId(int $nodeId, int $userId, ?int $sharedWithUserId = null, ?string $shareToken = null): array
    {
        $row = $this->nodes->findById($nodeId);
        if ($row === null) {
            throw new \InvalidArgumentException('File not found.');
        }

        if ($shareToken !== null) {
            $link = $this->shares->findLinkByToken($shareToken);
            if ($link === null || (int) ($link['storage_node_id'] ?? 0) !== $nodeId) {
                throw new \InvalidArgumentException('Invalid share link.');
            }
            if (empty($link['connection_active'])) {
                throw new \InvalidArgumentException('Cloud storage disconnected; owner must reconnect.');
            }
        } elseif ($sharedWithUserId !== null) {
            if ((int) $row['user_id'] !== $userId) {
                $share = $this->shares->findUserShare($nodeId, $userId);
                if ($share === null) {
                    throw new \InvalidArgumentException('File not found.');
                }
            }
            $conn = $this->connections->findById((int) $row['connection_id']);
            if ($conn === null || !(bool) ($conn['is_active'] ?? 0)) {
                throw new \InvalidArgumentException('Cloud storage disconnected; owner must reconnect.');
            }
        } elseif ((int) $row['user_id'] !== $userId) {
            $share = $this->shares->findUserShare($nodeId, $userId);
            if ($share === null) {
                throw new \InvalidArgumentException('File not found.');
            }
        } else {
            $conn = $this->requireActiveConnection($userId);
            if ((int) $conn['id'] !== (int) $row['connection_id']) {
                throw new \InvalidArgumentException('File not found.');
            }
        }

        $conn = $this->connections->findById((int) $row['connection_id']);
        if ($conn === null || !(bool) ($conn['is_active'] ?? 0)) {
            throw new \InvalidArgumentException('Cloud storage disconnected; owner must reconnect.');
        }

        $provider = ProviderFactory::fromConnection($conn, $this->db);
        $path     = (string) ($row['provider_path'] ?? '');
        CloudPathGuard::assertUnderRoot(
            StorageConnectionService::effectiveRootPath($conn),
            $path,
        );
        $meta     = $provider->downloadStream($path);
        $inline   = in_array(strtolower((string) ($row['ext'] ?? '')), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

        return ['meta' => $meta, 'inline' => $inline];
    }

    public function deleteNode(int $nodeId, int $userId): void
    {
        $row = $this->nodes->findByIdForUser($nodeId, $userId);
        if ($row === null) {
            throw new \InvalidArgumentException('Not found.');
        }

        $conn = $this->requireActiveConnection($userId);
        if ((int) $conn['id'] !== (int) $row['connection_id']) {
            throw new \InvalidArgumentException('Not found.');
        }

        $provider = ProviderFactory::fromConnection($conn, $this->db);
        $path = (string) ($row['provider_path'] ?? '');
        CloudPathGuard::assertUnderRoot(
            StorageConnectionService::effectiveRootPath($conn),
            $path,
        );
        $provider->delete($path);
        $this->nodes->delete($nodeId, $userId);
    }

    /**
     * @return array{moved: int, failed: int, errors: list<string>}
     */
    public function migrateLocalFiles(int $userId, bool $useSubfolder = true): array
    {
        $conn     = $this->requireActiveConnection($userId);
        $provider = ProviderFactory::fromConnection($conn, $this->db);
        $target   = StorageConnectionService::effectiveRootPath($conn);

        if ($useSubfolder) {
            $importPath = rtrim($target, '/') . '/Imported from local';
            try {
                $provider->createFolder($target, 'Imported from local');
            } catch (\Throwable) {
                // folder may exist
            }
            $target = $importPath;
        }

        $fileRepo = new FileRepository($this->db);
        $fileService = new FileService($this->db);
        $localFiles = $fileRepo->listForUser($userId);

        $moved  = 0;
        $failed = 0;
        /** @var list<string> $errors */
        $errors = [];

        foreach ($localFiles as $local) {
            $path = FileStorage::pathForRow($local);
            if ($path === null) {
                $failed++;
                $errors[] = (string) ($local['original_name'] ?? 'file') . ': missing on disk';
                continue;
            }

            try {
                $node = $provider->upload(
                    $target,
                    $path,
                    (string) $local['original_name'],
                    (string) ($local['mime_type'] ?? 'application/octet-stream'),
                );
                $newRow = $this->registerNode($userId, $conn, $node);
                $this->repointLocalShares((int) $local['id'], (int) $newRow['id']);
                FileStorage::deleteFile(
                    (int) $local['user_id'],
                    (string) $local['storage_id'],
                    (string) $local['ext']
                );
                $fileRepo->delete((int) $local['id'], $userId);
                $moved++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = (string) ($local['original_name'] ?? 'file') . ': ' . $e->getMessage();
            }
        }

        return ['moved' => $moved, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireActiveConnection(int $userId): array
    {
        $conn = $this->connections->findActiveByUserId($userId);
        if ($conn === null) {
            throw new \InvalidArgumentException('Cloud storage is not connected.');
        }

        if (StorageConnectionService::needsRootRepair($conn)) {
            $conn = (new StorageConnectionService($this->db))->repairRootPathIfNeeded($userId, $conn);
        }

        return $conn;
    }

    /**
     * @param array<string, mixed> $conn
     */
    private function resolveBrowsePath(array $conn, ?string $path): string
    {
        $root = StorageConnectionService::effectiveRootPath($conn);

        return CloudPathGuard::resolveUnderRoot($root, $path);
    }

    /**
     * @param array<string, mixed> $conn
     * @return array<string, mixed>
     */
    private function registerNode(int $userId, array $conn, CloudNode $node): array
    {
        $existing = $this->nodes->findByProviderPath((int) $conn['id'], $node->providerPath);
        if ($existing !== null) {
            $this->nodes->update((int) $existing['id'], $userId, [
                'name'             => $node->name,
                'provider_node_id' => $node->providerId,
                'mime_type'        => $node->mimeType,
                'size_bytes'       => $node->sizeBytes,
                'ext'              => $node->ext,
            ]);

            return $this->nodes->findById((int) $existing['id']) ?? $existing;
        }

        $id = $this->nodes->insert([
            'user_id'          => $userId,
            'connection_id'    => (int) $conn['id'],
            'node_type'        => $node->nodeType,
            'name'             => $node->name,
            'provider_node_id' => $node->providerId,
            'provider_path'    => $node->providerPath,
            'mime_type'        => $node->mimeType,
            'size_bytes'       => $node->sizeBytes,
            'ext'              => $node->ext,
        ]);

        return $this->nodes->findById($id) ?? [];
    }

    /**
     * @param array<string, mixed>|null $dbRow
     * @return array<string, mixed>
     */
    private function summarizeNode(CloudNode $node, ?array $dbRow): array
    {
        return [
            'id'            => $dbRow ? (int) $dbRow['id'] : null,
            'name'          => $node->name,
            'node_type'     => $node->nodeType,
            'provider_path' => $node->providerPath,
            'mime_type'     => $node->mimeType,
            'size_bytes'    => $node->sizeBytes,
            'ext'           => $node->ext,
            'modified_at'   => $node->modifiedAt,
        ];
    }

    private function repointLocalShares(int $localFileId, int $storageNodeId): void
    {
        $links = $this->db->fetchAll(
            'SELECT id, token, label, expires_at, created_at, last_used_at
             FROM file_share_links
             WHERE file_id = ? AND revoked = 0',
            [$localFileId]
        );

        foreach ($links as $link) {
            $this->db->execute(
                'INSERT INTO storage_node_share_links (storage_node_id, token, label, expires_at, created_at, last_used_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $storageNodeId,
                    $link['token'],
                    $link['label'],
                    $link['expires_at'],
                    $link['created_at'],
                    $link['last_used_at'],
                ]
            );
            $this->db->execute('UPDATE file_share_links SET revoked = 1 WHERE id = ?', [(int) $link['id']]);
        }

        $userShares = $this->db->fetchAll(
            'SELECT owner_user_id, shared_with_user_id FROM file_user_shares WHERE file_id = ?',
            [$localFileId]
        );

        foreach ($userShares as $share) {
            $existing = $this->shares->findUserShare(
                $storageNodeId,
                (int) $share['shared_with_user_id']
            );
            if ($existing === null) {
                $this->shares->insertUserShare(
                    $storageNodeId,
                    (int) $share['owner_user_id'],
                    (int) $share['shared_with_user_id']
                );
            }
            $this->db->execute(
                'DELETE FROM file_user_shares WHERE file_id = ? AND shared_with_user_id = ?',
                [$localFileId, (int) $share['shared_with_user_id']]
            );
        }
    }

    public function maxCloudBytes(): int
    {
        return max(1, CloudStorageConfigService::instance()->int('CLOUD_FILES_QUOTA_BYTES', 5_368_709_120));
    }
}
