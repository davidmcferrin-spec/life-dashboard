<?php

declare(strict_types=1);

namespace Dashboard\Auth;

/**
 * In-memory stand-in for the PostgreSQL OAuth tables. Unit tests use this
 * so expiry and rotation do not need a database.
 */
final class MemoryOAuthStore implements OAuthStore
{
    private int $next = 1;

    /** @var array<int, array<string, mixed>> */
    private array $clients = [];

    /** @var array<int, array<string, mixed>> */
    private array $codes = [];

    /** @var array<int, array<string, mixed>> */
    private array $tokens = [];

    /** @var array<string, list<int>> */
    private array $hits = [];

    public function insertClient(array $client): int
    {
        $id = $this->next++;
        $client['id'] = $id;
        $this->clients[$id] = $client;
        return $id;
    }

    public function findClient(string $publicId): ?array
    {
        foreach ($this->clients as $client) {
            if ((string) $client['client_id'] === $publicId) {
                return $client;
            }
        }
        return null;
    }

    public function bindClient(int $id, int $userId, array $granted, int $now): void
    {
        $this->clients[$id]['user_id'] = $userId;
        $this->clients[$id]['granted_scopes'] = $granted;
        $this->clients[$id]['last_used_at'] = $now;
    }

    public function touchClient(int $id, int $now, ?string $transport = null): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $this->clients[$id]['last_used_at'] = $now;
        if ($transport === 'http' || $transport === 'https') {
            $this->clients[$id]['last_transport'] = $transport;
        }
    }

    public function findClientById(int $id): ?array
    {
        return $this->clients[$id] ?? null;
    }

    public function saveClient(int $id, int $userId, string $name, array $allowed): bool
    {
        $client = $this->clients[$id] ?? null;
        if ($client === null || (int) ($client['user_id'] ?? 0) !== $userId || $client['revoked_at'] !== null) {
            return false;
        }
        $this->clients[$id]['client_name'] = $name;
        $this->clients[$id]['allowed_scopes'] = $allowed;
        return true;
    }

    public function liveTokenScopes(int $clientId): array
    {
        $out = [];
        foreach ($this->tokens as $token) {
            if ((int) $token['client_row_id'] === $clientId && $token['revoked_at'] === null) {
                $out[] = [
                    'id' => (int) $token['id'],
                    'scopes' => $token['scopes'],
                ];
            }
        }
        return $out;
    }

    public function setTokenScopes(int $id, array $scopes): void
    {
        if (isset($this->tokens[$id])) {
            $this->tokens[$id]['scopes'] = $scopes;
        }
    }

    public function revokeClient(int $id, int $userId, int $now): bool
    {
        $client = $this->clients[$id] ?? null;
        if ($client === null || (int) ($client['user_id'] ?? 0) !== $userId || $client['revoked_at'] !== null) {
            return false;
        }
        $this->clients[$id]['revoked_at'] = $now;
        $this->revokeTokensForClient($id, $now);
        return true;
    }

    public function listClients(int $userId): array
    {
        $out = [];
        foreach ($this->clients as $client) {
            if ((int) ($client['user_id'] ?? 0) === $userId && $client['revoked_at'] === null) {
                $out[] = $client;
            }
        }
        return $out;
    }

    public function insertCode(array $code): void
    {
        $code['id'] = $this->next++;
        $code['used_at'] = null;
        $this->codes[$code['id']] = $code;
    }

    public function takeCode(string $codeHash, int $now): ?array
    {
        foreach ($this->codes as $id => $code) {
            if (!hash_equals((string) $code['code_hash'], $codeHash)) {
                continue;
            }
            if ($code['used_at'] !== null || (int) $code['expires_at'] <= $now) {
                return null;
            }
            $this->codes[$id]['used_at'] = $now;
            return $this->codes[$id];
        }
        return null;
    }

    public function insertToken(array $token): int
    {
        $id = $this->next++;
        $token['id'] = $id;
        $token['revoked_at'] = null;
        $token['previous_refresh_hash'] = null;
        $this->tokens[$id] = $token;
        return $id;
    }

    public function findAccess(string $tokenHash): ?array
    {
        foreach ($this->tokens as $token) {
            if (hash_equals((string) $token['token_hash'], $tokenHash)) {
                return $this->withClient($token);
            }
        }
        return null;
    }

    public function findRefresh(string $refreshHash): ?array
    {
        foreach ($this->tokens as $token) {
            if ($token['refresh_hash'] !== null && hash_equals((string) $token['refresh_hash'], $refreshHash)) {
                return $this->withClient($token);
            }
        }
        return null;
    }

    public function findPreviousRefresh(string $refreshHash): ?array
    {
        foreach ($this->tokens as $token) {
            $prev = $token['previous_refresh_hash'] ?? null;
            if (is_string($prev) && $prev !== '' && hash_equals($prev, $refreshHash)) {
                return $this->withClient($token);
            }
        }
        return null;
    }

    public function rotateToken(int $id, string $accessHash, string $refreshHash, string $previousHash, array $scopes, int $accessExp, int $refreshExp, int $now): void
    {
        $this->tokens[$id]['token_hash'] = $accessHash;
        $this->tokens[$id]['previous_refresh_hash'] = $previousHash;
        $this->tokens[$id]['refresh_hash'] = $refreshHash;
        $this->tokens[$id]['scopes'] = $scopes;
        $this->tokens[$id]['access_expires_at'] = $accessExp;
        $this->tokens[$id]['refresh_expires_at'] = $refreshExp;
        $this->tokens[$id]['last_used_at'] = $now;
    }

    public function revokeToken(int $id, int $now): void
    {
        if (isset($this->tokens[$id])) {
            $this->tokens[$id]['revoked_at'] = $now;
        }
    }

    public function revokeTokensForClient(int $clientId, int $now): void
    {
        foreach ($this->tokens as $id => $token) {
            if ((int) $token['client_row_id'] === $clientId && $token['revoked_at'] === null) {
                $this->tokens[$id]['revoked_at'] = $now;
            }
        }
    }

    public function touchToken(int $id, int $now): void
    {
        if (isset($this->tokens[$id])) {
            $this->tokens[$id]['last_used_at'] = $now;
        }
    }

    public function allowRate(string $bucket, int $limit, int $windowSeconds, int $now): bool
    {
        $fresh = [];
        foreach ($this->hits[$bucket] ?? [] as $hit) {
            if ($hit > $now - $windowSeconds) {
                $fresh[] = $hit;
            }
        }
        if (count($fresh) >= $limit) {
            $this->hits[$bucket] = $fresh;
            return false;
        }
        $fresh[] = $now;
        $this->hits[$bucket] = $fresh;
        return true;
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private function withClient(array $token): array
    {
        $client = $this->clients[(int) $token['client_row_id']] ?? null;
        $token['client_public_id'] = $client['client_id'] ?? '';
        $token['client_name'] = $client['client_name'] ?? '';
        $token['client_revoked_at'] = $client['revoked_at'] ?? null;
        $allowed = $client['allowed_scopes'] ?? null;
        $token['allowed_scopes'] = is_array($allowed) ? $allowed : null;
        return $token;
    }
}
