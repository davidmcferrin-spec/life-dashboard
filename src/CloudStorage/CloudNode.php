<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

/**
 * Normalized cloud storage node from any provider.
 */
final class CloudNode
{
    public const TYPE_FILE   = 'file';
    public const TYPE_FOLDER = 'folder';

    public function __construct(
        public readonly string $name,
        public readonly string $nodeType,
        public readonly string $providerPath,
        public readonly string $providerId,
        public readonly ?int $id = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $ext = null,
        public readonly ?string $modifiedAt = null,
    ) {}

    public function isFile(): bool
    {
        return $this->nodeType === self::TYPE_FILE;
    }

    public function isFolder(): bool
    {
        return $this->nodeType === self::TYPE_FOLDER;
    }
}
