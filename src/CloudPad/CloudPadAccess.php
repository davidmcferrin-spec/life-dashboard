<?php

declare(strict_types=1);

namespace Dashboard\CloudPad;

final class CloudPadAccess
{
    public const PERM_READ  = 'read';
    public const PERM_WRITE = 'write';

    /**
     * @param array<string, mixed> $canvas
     */
    public function __construct(
        public readonly array $canvas,
        public readonly string $permission,
        public readonly bool $isOwner,
        public readonly ?string $via = null,
    ) {}

    public function canvasId(): int
    {
        return (int) $this->canvas['id'];
    }

    public function ownerId(): int
    {
        return (int) $this->canvas['user_id'];
    }

    public function canWrite(): bool
    {
        return $this->permission === self::PERM_WRITE;
    }
}
