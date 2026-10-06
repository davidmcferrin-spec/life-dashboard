<?php

declare(strict_types=1);

namespace Dashboard\Files;

use Dashboard\Clipboard\ShareToken;
use Dashboard\Config;
use Dashboard\DB\Database;
use DateInterval;
use DateTimeImmutable;

final class FileShareService
{
    private FileAccessService $access;
    private FileShareRepository $shares;

    public function __construct(Database $db)
    {
        $this->access = new FileAccessService($db);
        $this->shares = $this->access->shareRepository();
    }

    /**
     * @return array{links: list<array<string, mixed>>, users: list<array<string, mixed>>}
     */
    public function listSharesForOwner(int $fileId, int $ownerId): array
    {
        $this->assertSharingReady();

        if ($this->access->requireOwner($fileId, $ownerId) === null) {
            throw new \InvalidArgumentException('File not found.');
        }

        $links = array_map(function (array $row): array {
            return [
                'id'           => (int) $row['id'],
                'label'        => $row['label'],
                'expires_at'   => $row['expires_at'],
                'created_at'   => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'revoked'      => (bool) $row['revoked'],
                'url'          => $this->shareUrl((string) $row['token']),
            ];
        }, $this->shares->listLinksForFile($fileId));

        $users = array_map(function (array $row): array {
            return [
                'id'         => (int) $row['id'],
                'user_id'    => (int) $row['user_id'],
                'username'   => (string) $row['username'],
                'email'      => (string) $row['email'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $this->shares->listUserSharesForFile($fileId));

        return ['links' => $links, 'users' => $users];
    }

    /**
     * @return array{token: string, url: string, id: int}
     */
    public function createLink(
        int $fileId,
        int $ownerId,
        ?string $label,
        ?string $expiresIn,
    ): array {
        $this->assertSharingReady();

        if ($this->access->requireOwner($fileId, $ownerId) === null) {
            throw new \InvalidArgumentException('File not found.');
        }

        $token     = ShareToken::generateUnique(fn (string $t): bool => $this->shares->tokenExists($t));
        $expiresAt = $this->expiresAtFromInterval($expiresIn);

        $id = $this->shares->insertLink($fileId, $token, $label ?: null, $expiresAt);

        return [
            'id'    => $id,
            'token' => $token,
            'url'   => $this->shareUrl($token),
        ];
    }

    public function revokeLink(int $fileId, int $ownerId, int $linkId): bool
    {
        if (!$this->shares->tablesReady()) {
            return false;
        }

        if ($this->access->requireOwner($fileId, $ownerId) === null) {
            return false;
        }

        return $this->shares->revokeLink($linkId, $fileId);
    }

    public function shareWithUser(int $fileId, int $ownerId, string $identifier): array
    {
        $this->assertSharingReady();

        if ($this->access->requireOwner($fileId, $ownerId) === null) {
            throw new \InvalidArgumentException('File not found.');
        }

        $target = $this->access->fileRepository()->findUserByIdentifier($identifier);
        if ($target === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $targetId = (int) $target['id'];
        if ($targetId === $ownerId) {
            throw new \InvalidArgumentException('Cannot share with yourself.');
        }

        $existing = $this->shares->findUserShare($fileId, $targetId);
        if ($existing !== null) {
            return [
                'id'       => (int) $existing['id'],
                'user_id'  => $targetId,
                'username' => (string) $target['username'],
                'email'    => (string) $target['email'],
                'updated'  => true,
            ];
        }

        $id = $this->shares->insertUserShare($fileId, $ownerId, $targetId);

        return [
            'id'       => $id,
            'user_id'  => $targetId,
            'username' => (string) $target['username'],
            'email'    => (string) $target['email'],
            'updated'  => false,
        ];
    }

    public function removeUserShare(int $fileId, int $ownerId, int $shareId): bool
    {
        if (!$this->shares->tablesReady()) {
            return false;
        }

        if ($this->access->requireOwner($fileId, $ownerId) === null) {
            return false;
        }

        return $this->shares->deleteUserShare($shareId, $fileId);
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
        $base = rtrim((string) Config::get('APP_URL', ''), '/');

        return ($base !== '' ? $base : '') . '/share/file/' . $token;
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
