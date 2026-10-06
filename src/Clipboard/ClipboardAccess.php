<?php

declare(strict_types=1);

namespace Dashboard\Clipboard;

/**
 * Resolved access to a single clipboard (owner, in-app share, or public link).
 */
final class ClipboardAccess
{
    public const PERM_READ  = 'read';
    public const PERM_WRITE = 'write';

    /**
     * @param array<string, mixed> $board Full user_clipboards row
     */
    public function __construct(
        public readonly array $board,
        public readonly string $permission,
        public readonly bool $isOwner,
        public readonly ?string $via = null,
    ) {}

    public function boardId(): int
    {
        return (int) $this->board['id'];
    }

    public function ownerId(): int
    {
        return (int) $this->board['user_id'];
    }

    public function canRead(): bool
    {
        return true;
    }

    public function canWrite(): bool
    {
        return $this->permission === self::PERM_WRITE;
    }
}
