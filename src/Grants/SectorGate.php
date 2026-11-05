<?php

declare(strict_types=1);

namespace Dashboard\Grants;

use Dashboard\Audit\AuditLog;
use Dashboard\DB\Database;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Grants are explicit. Creating a token does not grant a sector.
 * Money movement and health writes are never granted by any other save.
 */
final class SectorGate
{
    public function __construct(
        private readonly Database $db,
        private readonly ?AuditLog $audit = null,
    ) {}

    public function mode(int $userId, string $kind, int $principalId, string $sector): string
    {
        $row = $this->db->fetchOne(
            'SELECT mode FROM sector_grants
             WHERE user_id = ? AND principal_kind = ? AND principal_id = ? AND sector = ?',
            [$userId, $kind, $principalId, $sector]
        );
        $mode = $row === null ? 'deny' : (string) $row['mode'];

        return in_array($mode, ['once', 'always'], true) ? $mode : 'deny';
    }

    public function consume(int $userId, string $kind, int $principalId, string $sector, string $mode): void
    {
        if ($mode === 'once') {
            $this->db->execute(
                "UPDATE sector_grants
                 SET mode = 'deny', last_used_at = (now() AT TIME ZONE 'UTC'), updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE user_id = ? AND principal_kind = ? AND principal_id = ? AND sector = ?",
                [$userId, $kind, $principalId, $sector]
            );
            return;
        }
        if ($mode === 'always') {
            $this->db->execute(
                "UPDATE sector_grants
                 SET last_used_at = (now() AT TIME ZONE 'UTC'), updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE user_id = ? AND principal_kind = ? AND principal_id = ? AND sector = ?",
                [$userId, $kind, $principalId, $sector]
            );
        }
    }

    /**
     * Owner sessions pass. A key is allowed once or always, and that act is audited.
     * Allow once returns to deny after this call.
     */
    public function take(int $userId, ?string $kind, ?int $principalId, string $sector, string $tool): bool
    {
        if ($kind === null || $principalId === null) {
            return true;
        }
        $mode = $this->mode($userId, $kind, $principalId, $sector);
        if ($mode === 'deny') {
            return false;
        }
        $this->consume($userId, $kind, $principalId, $sector, $mode);
        ($this->audit ?? new AuditLog($this->db))->record($userId, 'ai', $tool, 'read', 'sector:' . $sector, [
            'sector'     => $sector,
            'grant_mode' => $mode,
            'verb'       => 'read',
        ]);

        return true;
    }

    /**
     * @return array{principals: list<array<string, mixed>>, sectors: list<string>}
     */
    public function board(int $userId): array
    {
        $tokens = $this->db->fetchAll(
            'SELECT id, name FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL ORDER BY name, id',
            [$userId]
        );
        $clients = $this->db->fetchAll(
            'SELECT id, client_name FROM oauth_clients WHERE user_id = ? AND revoked_at IS NULL ORDER BY client_name, id',
            [$userId]
        );
        $rows = $this->db->fetchAll(
            'SELECT principal_kind, principal_id, sector, mode, last_used_at
             FROM sector_grants WHERE user_id = ?',
            [$userId]
        );
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['principal_kind'] . ':' . $row['principal_id'] . ':' . $row['sector']] = $row;
        }
        $principals = [];
        foreach ($tokens as $token) {
            $principals[] = $this->principal('token', (int) $token['id'], (string) $token['name'], $byKey);
        }
        foreach ($clients as $client) {
            $principals[] = $this->principal('oauth', (int) $client['id'], (string) $client['client_name'], $byKey);
        }

        return ['principals' => $principals, 'sectors' => SectorMap::SECTORS];
    }

    /**
     * @return array<string, string>
     */
    public function mine(int $userId, string $kind, int $principalId): array
    {
        $out = [];
        foreach (SectorMap::SECTORS as $sector) {
            $out[$sector] = $this->mode($userId, $kind, $principalId, $sector);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentAudits(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, actor, tool, action, resource, detail, created_at
             FROM audit_events
             WHERE user_id = ? AND detail->>'sector' IS NOT NULL
             ORDER BY id DESC
             LIMIT 30",
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $detail = is_string($row['detail']) ? json_decode($row['detail'], true) : $row['detail'];
            $detail = is_array($detail) ? $detail : [];
            $out[] = [
                'id'         => (int) $row['id'],
                'actor'      => (string) $row['actor'],
                'tool'       => $row['tool'] !== null ? (string) $row['tool'] : null,
                'action'     => (string) $row['action'],
                'resource'   => $row['resource'] !== null ? (string) $row['resource'] : null,
                'sector'     => isset($detail['sector']) ? (string) $detail['sector'] : null,
                'grant_mode' => isset($detail['grant_mode']) ? (string) $detail['grant_mode'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    public function save(int $userId, string $kind, int $principalId, string $sector, string $mode): array
    {
        if (!in_array($kind, ['token', 'oauth'], true)) {
            throw ValidationException::withFields(['principal_kind' => 'Principal must be a token or an OAuth client.']);
        }
        if (!in_array($sector, SectorMap::SECTORS, true)) {
            throw ValidationException::withFields(['sector' => 'Unknown sector.']);
        }
        if (!in_array($mode, SectorMap::MODES, true)) {
            throw ValidationException::withFields(['mode' => 'Mode must be deny, once, or always.']);
        }
        $this->assertOwner($userId, $kind, $principalId);
        $this->db->execute(
            'INSERT INTO sector_grants (user_id, principal_kind, principal_id, sector, mode)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (user_id, principal_kind, principal_id, sector)
             DO UPDATE SET mode = EXCLUDED.mode, updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $kind, $principalId, $sector, $mode]
        );

        return [
            'principal_kind' => $kind,
            'principal_id'   => $principalId,
            'sector'         => $sector,
            'mode'           => $mode,
        ];
    }

    private function assertOwner(int $userId, string $kind, int $principalId): void
    {
        if ($kind === 'token') {
            $row = $this->db->fetchOne(
                'SELECT id FROM api_tokens WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
                [$principalId, $userId]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT id FROM oauth_clients WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
                [$principalId, $userId]
            );
        }
        if ($row === null) {
            throw new NotFoundException('That key was not found.');
        }
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @return array<string, mixed>
     */
    private function principal(string $kind, int $id, string $name, array $byKey): array
    {
        $grants = [];
        foreach (SectorMap::SECTORS as $sector) {
            $row = $byKey[$kind . ':' . $id . ':' . $sector] ?? null;
            $grants[$sector] = [
                'mode'         => $row === null ? 'deny' : (string) $row['mode'],
                'last_used_at' => $row['last_used_at'] ?? null,
            ];
        }

        return [
            'principal_kind' => $kind,
            'principal_id'   => $id,
            'name'           => $name,
            'grants'         => $grants,
        ];
    }
}
