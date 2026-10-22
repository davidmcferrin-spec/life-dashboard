<?php

declare(strict_types=1);

namespace Dashboard\Auth;

interface OAuthStore
{
    /**
     * @param array<string, mixed> $client
     */
    public function insertClient(array $client): int;

    /**
     * @return array<string, mixed>|null
     */
    public function findClient(string $publicId): ?array;

    /**
     * @param list<string> $granted
     */
    public function bindClient(int $id, int $userId, array $granted, int $now): void;

    public function touchClient(int $id, int $now, ?string $transport = null): void;

    public function revokeClient(int $id, int $userId, int $now): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function listClients(int $userId): array;

    /**
     * @param array<string, mixed> $code
     */
    public function insertCode(array $code): void;

    /**
     * Marks a still-valid code used and returns it. A missing, used, or expired code returns null.
     *
     * @return array<string, mixed>|null
     */
    public function takeCode(string $codeHash, int $now): ?array;

    /**
     * @param array<string, mixed> $token
     */
    public function insertToken(array $token): int;

    /**
     * @return array<string, mixed>|null
     */
    public function findAccess(string $tokenHash): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findRefresh(string $refreshHash): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findPreviousRefresh(string $refreshHash): ?array;

    /**
     * @param list<string> $scopes
     */
    public function rotateToken(int $id, string $accessHash, string $refreshHash, string $previousHash, array $scopes, int $accessExp, int $refreshExp, int $now): void;

    public function revokeToken(int $id, int $now): void;

    public function revokeTokensForClient(int $clientId, int $now): void;

    public function touchToken(int $id, int $now): void;

    public function allowRate(string $bucket, int $limit, int $windowSeconds, int $now): bool;
}
