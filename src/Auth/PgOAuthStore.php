<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\DB\Database;

final class PgOAuthStore implements OAuthStore
{
    public function __construct(private readonly Database $db) {}

    public function insertClient(array $client): int
    {
        $id = $this->db->fetchScalar(
            'INSERT INTO oauth_clients (user_id, client_id, client_name, redirect_uris, registered_scopes, granted_scopes, created_at)
             VALUES (?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?)
             RETURNING id',
            [
                $client['user_id'],
                $client['client_id'],
                $client['client_name'],
                self::json($client['redirect_uris']),
                self::json($client['registered_scopes']),
                self::json($client['granted_scopes']),
                self::stamp((int) $client['created_at']),
            ]
        );
        return (int) $id;
    }

    public function findClient(string $publicId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM oauth_clients WHERE client_id = ?', [$publicId]);
        return $row === null ? null : $this->client($row);
    }

    public function bindClient(int $id, int $userId, array $granted, int $now): void
    {
        $this->db->execute(
            'UPDATE oauth_clients SET user_id = ?, granted_scopes = ?::jsonb, last_used_at = ? WHERE id = ?',
            [$userId, self::json($granted), self::stamp($now), $id]
        );
    }

    public function touchClient(int $id, int $now): void
    {
        $this->db->execute(
            'UPDATE oauth_clients SET last_used_at = ? WHERE id = ?',
            [self::stamp($now), $id]
        );
    }

    public function revokeClient(int $id, int $userId, int $now): bool
    {
        $written = $this->db->execute(
            'UPDATE oauth_clients SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [self::stamp($now), $id, $userId]
        );
        if ($written < 1) {
            return false;
        }
        $this->revokeTokensForClient($id, $now);
        $this->db->execute(
            'UPDATE oauth_authorization_codes SET used_at = ? WHERE client_id = ? AND used_at IS NULL',
            [self::stamp($now), $id]
        );
        return true;
    }

    public function listClients(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM oauth_clients WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC',
            [$userId]
        );
        return array_map($this->client(...), $rows);
    }

    public function insertCode(array $code): void
    {
        $this->db->execute(
            'INSERT INTO oauth_authorization_codes
                (user_id, client_id, code_hash, redirect_uri, code_challenge, code_challenge_method, scopes, resource, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?)',
            [
                $code['user_id'],
                $code['client_row_id'],
                $code['code_hash'],
                $code['redirect_uri'],
                $code['code_challenge'],
                $code['code_challenge_method'],
                self::json($code['scopes']),
                $code['resource'],
                self::stamp((int) $code['expires_at']),
                self::stamp((int) $code['created_at']),
            ]
        );
    }

    public function takeCode(string $codeHash, int $now): ?array
    {
        $row = $this->db->fetchOne(
            'UPDATE oauth_authorization_codes
             SET used_at = ?
             WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?
             RETURNING id, user_id, client_id, redirect_uri, code_challenge, code_challenge_method, scopes, resource, expires_at',
            [self::stamp($now), $codeHash, self::stamp($now)]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'client_row_id' => (int) $row['client_id'],
            'redirect_uri' => (string) $row['redirect_uri'],
            'code_challenge' => (string) $row['code_challenge'],
            'code_challenge_method' => (string) $row['code_challenge_method'],
            'scopes' => self::list($row['scopes']),
            'resource' => $row['resource'] !== null ? (string) $row['resource'] : '',
            'expires_at' => self::unix($row['expires_at']),
        ];
    }

    public function insertToken(array $token): int
    {
        $id = $this->db->fetchScalar(
            'INSERT INTO oauth_tokens
                (user_id, client_id, token_hash, refresh_hash, scopes, access_expires_at, refresh_expires_at, last_used_at, created_at)
             VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)
             RETURNING id',
            [
                $token['user_id'],
                $token['client_row_id'],
                $token['token_hash'],
                $token['refresh_hash'],
                self::json($token['scopes']),
                self::stamp((int) $token['access_expires_at']),
                self::stamp((int) $token['refresh_expires_at']),
                self::stamp((int) $token['created_at']),
                self::stamp((int) $token['created_at']),
            ]
        );
        return (int) $id;
    }

    public function findAccess(string $tokenHash): ?array
    {
        $sql = 'SELECT t.id, t.user_id, t.client_id, t.token_hash, t.refresh_hash, t.previous_refresh_hash,
                    t.scopes, t.access_expires_at, t.refresh_expires_at, t.revoked_at,
                    c.client_id AS public_client_id, c.client_name, c.revoked_at AS client_revoked_at
             FROM oauth_tokens t
             JOIN oauth_clients c ON c.id = t.client_id
             WHERE t.token_hash = ?';
        $row = $this->db->fetchOne($sql, [$tokenHash]);
        return $row === null ? null : $this->mapToken($row);
    }

    public function findRefresh(string $refreshHash): ?array
    {
        $sql = 'SELECT t.id, t.user_id, t.client_id, t.token_hash, t.refresh_hash, t.previous_refresh_hash,
                    t.scopes, t.access_expires_at, t.refresh_expires_at, t.revoked_at,
                    c.client_id AS public_client_id, c.client_name, c.revoked_at AS client_revoked_at
             FROM oauth_tokens t
             JOIN oauth_clients c ON c.id = t.client_id
             WHERE t.refresh_hash = ?';
        $row = $this->db->fetchOne($sql, [$refreshHash]);
        return $row === null ? null : $this->mapToken($row);
    }

    public function findPreviousRefresh(string $refreshHash): ?array
    {
        $sql = 'SELECT t.id, t.user_id, t.client_id, t.token_hash, t.refresh_hash, t.previous_refresh_hash,
                    t.scopes, t.access_expires_at, t.refresh_expires_at, t.revoked_at,
                    c.client_id AS public_client_id, c.client_name, c.revoked_at AS client_revoked_at
             FROM oauth_tokens t
             JOIN oauth_clients c ON c.id = t.client_id
             WHERE t.previous_refresh_hash = ?';
        $row = $this->db->fetchOne($sql, [$refreshHash]);
        return $row === null ? null : $this->mapToken($row);
    }

    public function rotateToken(int $id, string $accessHash, string $refreshHash, string $previousHash, array $scopes, int $accessExp, int $refreshExp, int $now): void
    {
        $this->db->execute(
            'UPDATE oauth_tokens
             SET token_hash = ?, refresh_hash = ?, previous_refresh_hash = ?, scopes = ?::jsonb,
                 access_expires_at = ?, refresh_expires_at = ?, last_used_at = ?
             WHERE id = ? AND revoked_at IS NULL',
            [$accessHash, $refreshHash, $previousHash, self::json($scopes), self::stamp($accessExp), self::stamp($refreshExp), self::stamp($now), $id]
        );
    }

    public function revokeToken(int $id, int $now): void
    {
        $this->db->execute(
            'UPDATE oauth_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [self::stamp($now), $id]
        );
    }

    public function revokeTokensForClient(int $clientId, int $now): void
    {
        $this->db->execute(
            'UPDATE oauth_tokens SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
            [self::stamp($now), $clientId]
        );
    }

    public function touchToken(int $id, int $now): void
    {
        $this->db->execute(
            'UPDATE oauth_tokens SET last_used_at = ? WHERE id = ?',
            [self::stamp($now), $id]
        );
    }

    public function allowRate(string $bucket, int $limit, int $windowSeconds, int $now): bool
    {
        $cutoff = self::stamp($now - $windowSeconds);
        $this->db->execute('DELETE FROM oauth_rate_hits WHERE bucket = ? AND hit_at <= ?', [$bucket, $cutoff]);
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM oauth_rate_hits WHERE bucket = ? AND hit_at > ?',
            [$bucket, $cutoff]
        );
        if ($count >= $limit) {
            return false;
        }
        $this->db->execute(
            'INSERT INTO oauth_rate_hits (bucket, hit_at) VALUES (?, ?)',
            [$bucket, self::stamp($now)]
        );
        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapToken(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'client_row_id' => (int) $row['client_id'],
            'token_hash' => (string) $row['token_hash'],
            'refresh_hash' => $row['refresh_hash'] !== null ? (string) $row['refresh_hash'] : null,
            'previous_refresh_hash' => $row['previous_refresh_hash'] !== null ? (string) $row['previous_refresh_hash'] : null,
            'scopes' => self::list($row['scopes']),
            'access_expires_at' => self::unix($row['access_expires_at']),
            'refresh_expires_at' => self::unix($row['refresh_expires_at']),
            'revoked_at' => $row['revoked_at'] !== null ? self::unix($row['revoked_at']) : null,
            'client_public_id' => (string) $row['public_client_id'],
            'client_name' => (string) $row['client_name'],
            'client_revoked_at' => $row['client_revoked_at'] !== null ? self::unix($row['client_revoked_at']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function client(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'client_id' => (string) $row['client_id'],
            'client_name' => (string) $row['client_name'],
            'redirect_uris' => self::list($row['redirect_uris']),
            'registered_scopes' => self::list($row['registered_scopes']),
            'granted_scopes' => self::list($row['granted_scopes']),
            'last_used_at' => $row['last_used_at'] !== null ? self::unix($row['last_used_at']) : null,
            'revoked_at' => $row['revoked_at'] !== null ? self::unix($row['revoked_at']) : null,
            'created_at' => self::unix($row['created_at']),
        ];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string) $value, true);
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    private static function stamp(int $unix): string
    {
        return gmdate('Y-m-d H:i:s', $unix);
    }

    private static function unix(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $parsed = strtotime((string) $value . ' UTC');
        return $parsed === false ? 0 : $parsed;
    }
}
