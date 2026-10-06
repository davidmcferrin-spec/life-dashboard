<?php

declare(strict_types=1);

namespace Dashboard\Files;

final class FileAccess
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_USER  = 'user';
    public const ROLE_LINK  = 'link';

    /**
     * @param array<string, mixed> $file
     */
    public function __construct(
        public readonly array $file,
        public readonly bool $isOwner,
        public readonly string $role,
    ) {}

    public function canDownload(): bool
    {
        return true;
    }
}
