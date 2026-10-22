<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\Api\V1\Scope;
use Dashboard\DB\Database;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Scoped bearer tokens for /api/v1 and the MCP server.
 * The database stores SHA-256(token). The plaintext is returned once.
 */
final class ApiTokenService
{
    public const MAX_ACTIVE = 20;

    public function __construct(private readonly Database $db) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed> includes plaintext "token" once
     */
    public function create(int $userId, string $name, array $scopes, ?string $expiresAt = null, bool $admin = false): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw ValidationException::withFields(['name' => 'Name must be 1–64 characters.']);
        }
        $scopes = Scope::normalize($scopes);
        self::assertAssignable($scopes, $admin);
        if ($expiresAt !== null && $expiresAt !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $expiresAt)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $expiresAt);
            if ($dt === false) {
                throw ValidationException::withFields(['expires_at' => 'Use YYYY-MM-DD.']);
            }
            $expiresAt = $dt->format('Y-m-d') . ' 23:59:59';
        } elseif (self::isMoneyOnly($scopes)) {
            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+90 days')->format('Y-m-d') . ' 23:59:59';
        } else {
            $expiresAt = null;
        }

        $active = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
        if ($active >= self::MAX_ACTIVE) {
            throw new ValidationException('Too many tokens (max ' . self::MAX_ACTIVE . '). Revoke one first.');
        }

        $plain  = 'ld_' . bin2hex(random_bytes(24));
        $prefix = substr($plain, 0, 11);
        $this->db->execute(
            'INSERT INTO api_tokens (user_id, name, prefix, token_hash, scopes, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $name, $prefix, self::hash($plain), implode(' ', $scopes), $expiresAt]
        );
        $row = $this->get($userId, (int) $this->db->lastInsertId());
        $row['token'] = $plain;
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return array_map(
            [self::class, 'format'],
            $this->db->fetchAll('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$userId])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM api_tokens WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Token not found.');
        }
        return self::format($row);
    }

    public function revoke(int $userId, int $id): array
    {
        $this->get($userId, $id);
        $this->db->execute(
            "UPDATE api_tokens SET revoked_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ? AND revoked_at IS NULL",
            [$id, $userId]
        );
        return $this->get($userId, $id);
    }

    /**
     * Rename an active token or replace its scopes. The hash and prefix stay.
     *
     * @param list<string> $scopes
     * @return array{token: array<string, mixed>, previous_name: string, previous_scopes: list<string>}
     */
    public function update(int $userId, int $id, string $name, array $scopes): array
    {
        $this->requireAdmin($userId);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw ValidationException::withFields(['name' => 'Name must be 1–64 characters.']);
        }
        $scopes = Scope::normalize($scopes);
        self::assertAssignable($scopes, true);
        $current = $this->get($userId, $id);
        if ($current['revoked']) {
            throw new ValidationException('A revoked token cannot be edited.');
        }
        $written = $this->db->execute(
            'UPDATE api_tokens SET name = ?, scopes = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$name, implode(' ', $scopes), $id, $userId]
        );
        if ($written < 1) {
            throw new ValidationException('A revoked token cannot be edited.');
        }
        return [
            'token'            => $this->get($userId, $id),
            'previous_name'    => (string) $current['name'],
            'previous_scopes'  => $current['scopes'],
        ];
    }

    /**
     * Remove a revoked token row.
     * Audit events keep token_id and token_name inside detail JSON. Nothing references this row.
     *
     * @return array{deleted: true, token: array<string, mixed>}
     */
    public function delete(int $userId, int $id): array
    {
        $this->requireAdmin($userId);
        $current = $this->get($userId, $id);
        if (!$current['revoked']) {
            throw new ValidationException('Revoke the token before deleting it.');
        }
        $written = $this->db->execute(
            'DELETE FROM api_tokens WHERE id = ? AND user_id = ? AND revoked_at IS NOT NULL',
            [$id, $userId]
        );
        if ($written < 1) {
            throw new NotFoundException('Token not found.');
        }
        return [
            'deleted' => true,
            'token'   => $current,
        ];
    }

    private function requireAdmin(int $userId): void
    {
        $flag = $this->db->fetchScalar('SELECT is_admin FROM users WHERE id = ?', [$userId]);
        if ((int) $flag !== 1) {
            throw new ForbiddenException('Only an admin can edit or delete an agent token.');
        }
    }

    /**
     * @return array{user_id: int, scopes: list<string>, id: int, name: string}|null
     */
    public function authenticate(string $plain): ?array
    {
        if ($plain === '' || strlen($plain) > 200) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT * FROM api_tokens WHERE token_hash = ?', [self::hash($plain)]);
        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        $transport = \Dashboard\Mcp\PlainHttp::inbound($_SERVER);
        if ($transport === null) {
            $this->db->execute(
                "UPDATE api_tokens SET last_used_at = (now() AT TIME ZONE 'UTC') WHERE id = ?",
                [(int) $row['id']]
            );
        } else {
            $this->db->execute(
                "UPDATE api_tokens SET last_used_at = (now() AT TIME ZONE 'UTC'), last_transport = ? WHERE id = ?",
                [$transport, (int) $row['id']]
            );
        }
        return [
            'user_id' => (int) $row['user_id'],
            'scopes'  => self::parseScopes((string) $row['scopes']),
            'id'      => (int) $row['id'],
            'name'    => (string) $row['name'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'name'         => (string) $row['name'],
            'prefix'       => (string) $row['prefix'],
            'scopes'       => self::parseScopes((string) $row['scopes']),
            'expires_at'   => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            'last_transport' => ($row['last_transport'] ?? null) === 'http' || ($row['last_transport'] ?? null) === 'https'
                ? (string) $row['last_transport']
                : null,
            'revoked'      => $row['revoked_at'] !== null,
            'created_at'   => (string) $row['created_at'],
        ];
    }

    /**
     * A money-only token expires in 90 days unless the caller picked a date.
     *
     * @param list<string> $scopes
     */
    /**
     * system:read and system:deploy are never part of read, write, or "*".
     * Only an admin session may store them on a token.
     *
     * @param list<string> $scopes
     */
    public static function assertAssignable(array $scopes, bool $admin): void
    {
        if ($admin) {
            return;
        }
        foreach ($scopes as $scope) {
            if (in_array($scope, Scope::EXPLICIT, true)) {
                throw ValidationException::withFields([
                    'scopes' => 'Only an admin can grant system:read or system:deploy.',
                ]);
            }
        }
    }

    /**
     * @param list<string> $scopes
     */
    public static function isMoneyOnly(array $scopes): bool
    {
        if ($scopes === [] || in_array('*', $scopes, true)) {
            return false;
        }
        foreach ($scopes as $scope) {
            if ($scope !== 'money:read' && $scope !== 'money:write') {
                return false;
            }
        }
        return true;
    }

    /**
     * A todos-only token is todos:read and todos:write, with no expiry of its own.
     *
     * @param list<string> $scopes
     */
    public static function isTodosOnly(array $scopes): bool
    {
        if ($scopes === [] || in_array('*', $scopes, true)) {
            return false;
        }
        foreach ($scopes as $scope) {
            if ($scope !== 'todos:read' && $scope !== 'todos:write') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private static function parseScopes(string $stored): array
    {
        $parts = preg_split('/\s+/', trim($stored)) ?: [];
        return array_values(array_filter($parts, static fn (string $s): bool => $s !== ''));
    }
}
