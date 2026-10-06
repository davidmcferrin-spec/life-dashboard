<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

/**
 * Cloud storage backend (Dropbox, Google Drive, etc.).
 */
interface CloudStorageProvider
{
    public function listFolder(string $parentPath, ?string $cursor = null): FolderListing;

    public function createFolder(string $parentPath, string $name): CloudNode;

    public function upload(string $parentPath, string $localTmpPath, string $name, ?string $mime = null): CloudNode;

    public function downloadStream(string $providerPath): StreamMeta;

    public function delete(string $providerPath): void;

    /**
     * Ensure a root folder exists for this dashboard user; returns provider path.
     */
    public function ensureRootFolder(string $rootName): string;
}
