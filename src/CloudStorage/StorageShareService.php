<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\Clipboard\ShareToken;
use Dashboard\DB\Database;
use DateInterval;
use DateTimeImmutable;

final class StorageShareService
{
    private NodeRepository $nodes;
    private StorageShareRepository $shares;
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db     = $db;
        $this->nodes  = new NodeRepository($db);
        $this->shares = new StorageShareRepository($db);
    }

    /**
     * @return array{links: list<array<string, mixed>>, users: list<array<string, mixed>>}
     */
    public function listForNode(int $nodeId, int $ownerId): array
    {
        $node = $this->requireOwnerNode($nodeId, $ownerId);

        $links = array_map(function (array $row): array {
            return [
                'id'           => (int) $row['id'],
                'label'        => $row['label'],
                'expires_at'   => $row['expires_at'],
                'created_at'   => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'url'          => $this->shares->shareUrl((string) $row['token']),
            ];
        }, $this->shares->listLinksForNode((int) $node['id']));

        $users = array_map(function (array $row): array {
            return [
                'id'         => (int) $row['id'],
                'user_id'    => (int) $row['user_id'],
                'username'   => (string) $row['username'],
                'email'      => (string) $row['email'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $this->shares->listUserSharesForNode((int) $node['id']));

        return ['links' => $links, 'users' => $users];
    }

    /**
     * @return array{token: string, url: string, id: int}
     */
    public function createLink(int $nodeId, int $ownerId, ?string $label, ?string $expiresIn): array
    {
        $this->requireOwnerNode($nodeId, $ownerId);

        $token = ShareToken::generateUnique(fn (string $t): bool => $this->shares->tokenExists($t));
        $expiresAt = $this->expiresAtFromInterval($expiresIn);
        $id = $this->shares->insertLink($nodeId, $token, $label, $expiresAt);

        return [
            'id'    => $id,
            'token' => $token,
            'url'   => $this->shares->shareUrl($token),
        ];
    }

    public function revokeLink(int $nodeId, int $ownerId, int $linkId): void
    {
        $this->requireOwnerNode($nodeId, $ownerId);
        if (!$this->shares->revokeLink($linkId, $nodeId)) {
            throw new \InvalidArgumentException('Link not found.');
        }
    }

    public function removeUserShare(int $nodeId, int $ownerId, int $shareId): void
    {
        $this->requireOwnerNode($nodeId, $ownerId);
        if (!$this->shares->deleteUserShare($shareId, $nodeId)) {
            throw new \InvalidArgumentException('Share not found.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSharedWithUser(int $userId): array
    {
        return $this->shares->listSharedWithUser($userId);
    }

    public function shareWithUser(int $nodeId, int $ownerId, string $identifier): array
    {
        $node = $this->requireOwnerNode($nodeId, $ownerId);
        $repo = new \Dashboard\Files\FileRepository($this->db);
        $target = $repo->findUserByIdentifier($identifier);
        if ($target === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $targetId = (int) $target['id'];
        if ($targetId === $ownerId) {
            throw new \InvalidArgumentException('Cannot share with yourself.');
        }

        $existing = $this->shares->findUserShare($nodeId, $targetId);
        if ($existing !== null) {
            return [
                'id'       => (int) $existing['id'],
                'user_id'  => $targetId,
                'username' => (string) $target['username'],
                'updated'  => true,
            ];
        }

        $id = $this->shares->insertUserShare($nodeId, $ownerId, $targetId);

        return [
            'id'       => $id,
            'user_id'  => $targetId,
            'username' => (string) $target['username'],
            'updated'  => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requireOwnerNode(int $nodeId, int $ownerId): array
    {
        $node = $this->nodes->findByIdForUser($nodeId, $ownerId);
        if ($node === null || ($node['node_type'] ?? '') !== CloudNode::TYPE_FILE) {
            throw new \InvalidArgumentException('File not found.');
        }

        return $node;
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
}
