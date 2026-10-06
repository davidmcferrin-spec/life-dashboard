<?php

declare(strict_types=1);

namespace Dashboard\Clipboard;

use Dashboard\DB\Database;

final class ClipboardAccessService
{
    private ClipboardRepository $clipboards;
    private ClipboardShareRepository $shares;

    public function __construct(Database $db)
    {
        $this->clipboards = new ClipboardRepository($db);
        $this->shares     = new ClipboardShareRepository($db);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBoard(int $id): ?array
    {
        return $this->clipboards->findById($id);
    }

    public function resolve(int $boardId, ?int $userId = null, ?string $shareToken = null): ?ClipboardAccess
    {
        if ($shareToken !== null && $shareToken !== '') {
            return $this->resolveByLink($shareToken, $boardId);
        }

        if ($userId === null) {
            return null;
        }

        $board = $this->clipboards->findById($boardId);
        if ($board === null) {
            return null;
        }

        if ((int) $board['user_id'] === $userId) {
            return new ClipboardAccess($board, ClipboardAccess::PERM_WRITE, true, 'owner');
        }

        if ($this->shares->tablesReady()) {
            $share = $this->shares->findUserShare($boardId, $userId);
            if ($share !== null) {
                return new ClipboardAccess(
                    $board,
                    (string) $share['permission'],
                    false,
                    'user',
                );
            }
        }

        return null;
    }

    public function resolveByLink(string $token, ?int $expectedBoardId = null): ?ClipboardAccess
    {
        if (!ShareToken::isValid($token) || !$this->shares->tablesReady()) {
            return null;
        }

        $link = $this->shares->findLinkByToken($token);
        if ($link === null) {
            return null;
        }

        $boardId = (int) $link['clipboard_id'];
        if ($expectedBoardId !== null && $boardId !== $expectedBoardId) {
            return null;
        }

        $board = $this->clipboards->findById($boardId);
        if ($board === null) {
            return null;
        }

        return new ClipboardAccess(
            $board,
            (string) $link['permission'],
            false,
            'link',
        );
    }

    public function requireOwner(int $boardId, int $userId): ?ClipboardAccess
    {
        $access = $this->resolve($boardId, $userId);

        return ($access !== null && $access->isOwner) ? $access : null;
    }

    public function shareRepository(): ClipboardShareRepository
    {
        return $this->shares;
    }

    public function clipboardRepository(): ClipboardRepository
    {
        return $this->clipboards;
    }

}
