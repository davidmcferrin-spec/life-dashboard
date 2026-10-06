<?php

declare(strict_types=1);

namespace Dashboard\Files;

use Dashboard\DB\Database;

final class FileService
{
    private FileRepository $repo;
    private FileAccessService $access;
    private FileShareRepository $shares;

    public function __construct(Database $db)
    {
        $this->access  = new FileAccessService($db);
        $this->repo    = $this->access->fileRepository();
        $this->shares  = $this->access->shareRepository();
    }

    /**
     * @return array{files: list<array<string, mixed>>, quota: array<string, int>}
     */
    public function listForOwner(int $userId): array
    {
        $files = array_map(
            fn (array $row): array => $this->summarize($row, true),
            $this->repo->listForUser($userId)
        );

        return [
            'files'  => $files,
            'quota'  => $this->quotaStats($userId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSharedWithUser(int $userId): array
    {
        if (!$this->shares->tablesReady()) {
            return [];
        }

        return array_map(function (array $row): array {
            $summary = $this->summarize($row, false);
            $summary['is_owner']        = false;
            $summary['owner_username']  = (string) ($row['owner_username'] ?? '');
            $summary['share_id']        = (int) ($row['share_id'] ?? 0);

            return $summary;
        }, $this->shares->listFilesSharedWithUser($userId));
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array<string, mixed>
     */
    public function upload(int $userId, array $file): array
    {
        if ($this->repo->countForUser($userId) >= FileStorage::maxPerUser()) {
            throw new \InvalidArgumentException('File limit reached (' . FileStorage::maxPerUser() . ' max).');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($this->repo->totalBytesForUser($userId) + $size > FileStorage::quotaBytes()) {
            throw new \InvalidArgumentException('Storage quota exceeded.');
        }

        $storageId     = FileStorage::generateStorageId();
        $originalName  = FileStorage::sanitizeOriginalName((string) ($file['name'] ?? 'upload'));
        $validated     = FileStorage::storeUpload($userId, $storageId, $file);

        $id = $this->repo->insert([
            'user_id'       => $userId,
            'original_name' => $originalName,
            'storage_id'    => $storageId,
            'mime_type'     => $validated['mime'],
            'ext'           => $validated['ext'],
            'size_bytes'    => (int) ($file['size'] ?? 0),
        ]);

        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new \RuntimeException('Could not record uploaded file.');
        }

        return $this->summarize($row, true);
    }

    /**
     * @return array{path: string, mime: string, inline: bool, filename: string}|null
     */
    public function streamMeta(int $fileId, ?int $userId = null, ?string $shareToken = null): ?array
    {
        $access = $this->access->resolve($fileId, $userId, $shareToken);
        if ($access === null) {
            return null;
        }

        $meta = FileStorage::streamMetaForRow($access->file);
        if ($meta === null) {
            return null;
        }

        $meta['filename'] = (string) ($access->file['original_name'] ?? 'download');

        return $meta;
    }

    public function delete(int $fileId, int $userId): bool
    {
        $access = $this->access->requireOwner($fileId, $userId);
        if ($access === null) {
            return false;
        }

        $row = $access->file;
        FileStorage::deleteFile(
            (int) $row['user_id'],
            (string) $row['storage_id'],
            (string) $row['ext']
        );

        if ($this->shares->tablesReady()) {
            $this->shares->revokeAllForFile($fileId);
            $this->shares->deleteAllUserSharesForFile($fileId);
        }

        return $this->repo->delete($fileId, $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadataForShareToken(string $token): ?array
    {
        $access = $this->access->resolveByLink($token);
        if ($access === null) {
            return null;
        }

        return $this->summarize($access->file, false);
    }

    public function accessService(): FileAccessService
    {
        return $this->access;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summarize(array $row, bool $isOwner): array
    {
        return [
            'id'            => (int) $row['id'],
            'original_name' => (string) $row['original_name'],
            'mime_type'     => (string) $row['mime_type'],
            'ext'           => (string) $row['ext'],
            'size_bytes'    => (int) $row['size_bytes'],
            'created_at'    => (string) $row['created_at'],
            'updated_at'    => (string) ($row['updated_at'] ?? $row['created_at']),
            'is_owner'      => $isOwner,
        ];
    }

    /**
     * @return array{count: int, max_count: int, bytes: int, max_bytes: int}
     */
    private function quotaStats(int $userId): array
    {
        return [
            'count'     => $this->repo->countForUser($userId),
            'max_count' => FileStorage::maxPerUser(),
            'bytes'     => $this->repo->totalBytesForUser($userId),
            'max_bytes' => FileStorage::quotaBytes(),
        ];
    }
}
