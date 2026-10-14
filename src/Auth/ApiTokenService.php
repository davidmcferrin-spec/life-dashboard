<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\Api\V1\Scope;
use Dashboard\DB\Database;
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
    public function create(int $userId, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw ValidationException::withFields(['name' => 'Name must be 1–64 characters.']);
        }
        $scopes = Scope::normalize($scopes);
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
        $this->db->execute(
            "UPDATE api_tokens SET last_used_at = (now() AT TIME ZONE 'UTC') WHERE id = ?",
            [(int) $row['id']]
        );
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
            'revoked'      => $row['revoked_at'] !== null,
            'created_at'   => (string) $row['created_at'],
        ];
    }

    /**
     * A money-only token expires in 90 days unless the caller picked a date.
     *
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
     * @return list<string>
     */
    private static function parseScopes(string $stored): array
    {
        $parts = preg_split('/\s+/', trim($stored)) ?: [];
        return array_values(array_filter($parts, static fn (string $s): bool => $s !== ''));
    }
}
