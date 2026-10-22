<?php

declare(strict_types=1);

namespace Dashboard\CloudPad;

use Dashboard\Clipboard\ShareToken;
use Dashboard\DB\Database;
use DateInterval;
use DateTimeImmutable;

final class CloudPadShareService
{
    private CloudPadAccessService $access;
    private CloudPadShareRepository $shares;

    public function __construct(Database $db)
    {
        $this->access = new CloudPadAccessService($db);
        $this->shares = $this->access->shareRepository();
    }

    /**
     * @return array{links: list<array<string, mixed>>, users: list<array<string, mixed>>}
     */
    public function listSharesForOwner(int $canvasId, int $ownerId): array
    {
        $this->assertSharingReady();

        if ($this->access->requireOwner($canvasId, $ownerId) === null) {
            throw new \InvalidArgumentException('Canvas not found.');
        }

        $links = array_map(function (array $row): array {
            return [
                'id'           => (int) $row['id'],
                'permission'   => (string) $row['permission'],
                'label'        => $row['label'],
                'expires_at'   => $row['expires_at'],
                'created_at'   => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'revoked'      => (bool) $row['revoked'],
                'url'          => $this->shareUrl((string) $row['token']),
            ];
        }, $this->shares->listLinksForCanvas($canvasId));

        $users = array_map(function (array $row): array {
            return [
                'id'         => (int) $row['id'],
                'user_id'    => (int) $row['user_id'],
                'username'   => (string) $row['username'],
                'email'      => (string) $row['email'],
                'permission' => (string) $row['permission'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $this->shares->listUserSharesForCanvas($canvasId));

        return ['links' => $links, 'users' => $users];
    }

    /**
     * @return array{token: string, url: string, id: int}
     */
    public function createLink(
        int $canvasId,
        int $ownerId,
        ?string $label,
        ?string $expiresIn,
    ): array {
        $this->assertSharingReady();

        if ($this->access->requireOwner($canvasId, $ownerId) === null) {
            throw new \InvalidArgumentException('Canvas not found.');
        }

        $token     = ShareToken::generateUnique(fn (string $t): bool => $this->shares->tokenExists($t));
        $expiresAt = $this->expiresAtFromInterval($expiresIn);

        $id = $this->shares->insertLink($canvasId, $token, CloudPadAccess::PERM_READ, $label ?: null, $expiresAt);

        return [
            'id'    => $id,
            'token' => $token,
            'url'   => $this->shareUrl($token),
        ];
    }

    public function revokeLink(int $canvasId, int $ownerId, int $linkId): bool
    {
        if (!$this->shares->tablesReady()) {
            return false;
        }

        if ($this->access->requireOwner($canvasId, $ownerId) === null) {
            return false;
        }

        return $this->shares->revokeLink($linkId, $canvasId);
    }

    public function shareWithUser(
        int $canvasId,
        int $ownerId,
        string $identifier,
        string $permission,
    ): array {
        $this->assertSharingReady();

        if ($this->access->requireOwner($canvasId, $ownerId) === null) {
            throw new \InvalidArgumentException('Canvas not found.');
        }

        $target = $this->access->canvasRepository()->findUserByIdentifier($identifier);
        if ($target === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $targetId = (int) $target['id'];
        if ($targetId === $ownerId) {
            throw new \InvalidArgumentException('Cannot share with yourself.');
        }

        $permission = $this->normalizePermission($permission);

        $existing = $this->shares->findUserShare($canvasId, $targetId);
        if ($existing !== null) {
            $this->shares->updateUserSharePermission((int) $existing['id'], $canvasId, $permission);

            return [
                'id'         => (int) $existing['id'],
                'user_id'    => $targetId,
                'username'   => (string) $target['username'],
                'email'      => (string) $target['email'],
                'permission' => $permission,
                'updated'    => true,
            ];
        }

        $id = $this->shares->insertUserShare($canvasId, $ownerId, $targetId, $permission);

        return [
            'id'         => $id,
            'user_id'    => $targetId,
            'username'   => (string) $target['username'],
            'email'      => (string) $target['email'],
            'permission' => $permission,
            'updated'    => false,
        ];
    }

    public function removeUserShare(int $canvasId, int $ownerId, int $shareId): bool
    {
        if (!$this->shares->tablesReady()) {
            return false;
        }

        if ($this->access->requireOwner($canvasId, $ownerId) === null) {
            return false;
        }

        return $this->shares->deleteUserShare($shareId, $canvasId);
    }

    public function touchLinkByToken(string $token): void
    {
        $link = $this->shares->findLinkByToken($token);
        if ($link !== null) {
            $this->shares->touchLinkUsed((int) $link['id']);
        }
    }

    private function shareUrl(string $token): string
    {
        $base = \Dashboard\Support\PublicUrl::base();

        return ($base !== '' ? $base : '') . '/share/cloudpad/' . $token;
    }

    private function normalizePermission(string $permission): string
    {
        $permission = strtolower(trim($permission));

        if (!in_array($permission, [CloudPadAccess::PERM_READ, CloudPadAccess::PERM_WRITE], true)) {
            throw new \InvalidArgumentException('Permission must be read or write.');
        }

        return $permission;
    }

    private function expiresAtFromInterval(?string $expiresIn): ?string
    {
        if ($expiresIn === null || trim($expiresIn) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable())
                ->add(new DateInterval(trim($expiresIn)))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid expiry interval (e.g. P7D, P30D).');
        }
    }

    private function assertSharingReady(): void
    {
        if (!$this->shares->tablesReady()) {
            throw new \InvalidArgumentException('Sharing is not available yet. Run database migrations on the server.');
        }
    }
}
