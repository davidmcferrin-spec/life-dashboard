<?php

declare(strict_types=1);

namespace Dashboard\Todo;

final class TodoAccess
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_SHARED = 'shared';
    public const PERM_READ  = 'read';
    public const PERM_WRITE = 'write';

    /**
     * @param array<string, mixed> $list
     */
    public function __construct(
        public readonly array $list,
        public readonly string $permission,
        public readonly bool $isOwner,
        public readonly string $role,
    ) {}

    public function canWrite(): bool
    {
        return $this->isOwner || $this->permission === self::PERM_WRITE;
    }

    public function canManageList(): bool
    {
        return $this->isOwner;
    }

    public function listId(): int
    {
        return (int) $this->list['id'];
    }

    public function ownerId(): int
    {
        return (int) $this->list['user_id'];
    }
}
