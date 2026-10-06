<?php

declare(strict_types=1);

namespace Dashboard\CloudPad;

use Dashboard\Clipboard\ShareToken;
use Dashboard\DB\Database;

final class CloudPadAccessService
{
    private CloudPadRepository $canvases;
    private CloudPadShareRepository $shares;

    public function __construct(Database $db)
    {
        $this->canvases = new CloudPadRepository($db);
        $this->shares   = new CloudPadShareRepository($db);
    }

    public function resolve(int $canvasId, ?int $userId = null): ?CloudPadAccess
    {
        if ($userId === null) {
            return null;
        }

        $canvas = $this->canvases->findById($canvasId);
        if ($canvas === null) {
            return null;
        }

        if ((int) $canvas['user_id'] === $userId) {
            return new CloudPadAccess($canvas, CloudPadAccess::PERM_WRITE, true, 'owner');
        }

        if ($this->shares->tablesReady()) {
            $share = $this->shares->findUserShare($canvasId, $userId);
            if ($share !== null) {
                return new CloudPadAccess(
                    $canvas,
                    (string) $share['permission'],
                    false,
                    'user',
                );
            }
        }

        return null;
    }

    public function resolveByLink(string $token, ?int $expectedCanvasId = null): ?CloudPadAccess
    {
        if (!ShareToken::isValid($token) || !$this->shares->tablesReady()) {
            return null;
        }

        $link = $this->shares->findLinkByToken($token);
        if ($link === null) {
            return null;
        }

        $canvasId = (int) $link['canvas_id'];
        if ($expectedCanvasId !== null && $canvasId !== $expectedCanvasId) {
            return null;
        }

        $canvas = $this->canvases->findById($canvasId);
        if ($canvas === null) {
            return null;
        }

        return new CloudPadAccess(
            $canvas,
            CloudPadAccess::PERM_READ,
            false,
            'link',
        );
    }

    public function requireOwner(int $canvasId, int $userId): ?CloudPadAccess
    {
        $access = $this->resolve($canvasId, $userId);

        return ($access !== null && $access->isOwner) ? $access : null;
    }

    public function shareRepository(): CloudPadShareRepository
    {
        return $this->shares;
    }

    public function canvasRepository(): CloudPadRepository
    {
        return $this->canvases;
    }
}
