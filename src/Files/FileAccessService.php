<?php

declare(strict_types=1);

namespace Dashboard\Files;

use Dashboard\Clipboard\ShareToken;
use Dashboard\DB\Database;

final class FileAccessService
{
    private FileRepository $files;
    private FileShareRepository $shares;

    public function __construct(Database $db)
    {
        $this->files  = new FileRepository($db);
        $this->shares = new FileShareRepository($db);
    }

    public function fileRepository(): FileRepository
    {
        return $this->files;
    }

    public function shareRepository(): FileShareRepository
    {
        return $this->shares;
    }

    public function resolve(int $fileId, ?int $userId = null, ?string $shareToken = null): ?FileAccess
    {
        if ($shareToken !== null && $shareToken !== '') {
            return $this->resolveByLink($shareToken, $fileId);
        }

        if ($userId === null) {
            return null;
        }

        $file = $this->files->findById($fileId);
        if ($file === null) {
            return null;
        }

        if ((int) $file['user_id'] === $userId) {
            return new FileAccess($file, true, FileAccess::ROLE_OWNER);
        }

        if ($this->shares->tablesReady()) {
            $share = $this->shares->findUserShare($fileId, $userId);
            if ($share !== null) {
                return new FileAccess($file, false, FileAccess::ROLE_USER);
            }
        }

        return null;
    }

    public function resolveByLink(string $token, ?int $expectedFileId = null): ?FileAccess
    {
        if (!ShareToken::isValid($token) || !$this->shares->tablesReady()) {
            return null;
        }

        $link = $this->shares->findLinkByToken($token);
        if ($link === null) {
            return null;
        }

        $fileId = (int) $link['file_id'];
        if ($expectedFileId !== null && $fileId !== $expectedFileId) {
            return null;
        }

        $file = $this->files->findById($fileId);
        if ($file === null) {
            return null;
        }

        return new FileAccess($file, false, FileAccess::ROLE_LINK);
    }

    public function requireOwner(int $fileId, int $userId): ?FileAccess
    {
        $access = $this->resolve($fileId, $userId);

        return ($access !== null && $access->isOwner) ? $access : null;
    }
}
