<?php

declare(strict_types=1);

namespace Dashboard\Mcp;

use Dashboard\Config;
use Dashboard\DB\Database;

/**
 * Reads and writes the dashboard override for plain HTTP MCP access.
 * The MCP process reads the same server_flags row. This class is not an API route.
 */
final class PlainHttpSettings
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array{allowed: bool, source: string, config: bool}
     */
    public function state(): array
    {
        $config = Config::bool('MCP_ALLOW_PLAIN_HTTP', true);
        $effective = PlainHttp::effective($this->stored(), $config);
        return [
            'allowed' => $effective['allowed'],
            'source'  => $effective['source'],
            'config'  => $config,
        ];
    }

    public function save(bool $allowed, int $userId): void
    {
        $this->db->execute(
            "INSERT INTO server_flags (flag_key, enabled, updated_by_user_id)
             VALUES ('mcp_plain_http', ?, ?)
             ON CONFLICT (flag_key) DO UPDATE
             SET enabled = EXCLUDED.enabled,
                 updated_at = (now() AT TIME ZONE 'UTC'),
                 updated_by_user_id = EXCLUDED.updated_by_user_id",
            [$allowed ? 1 : 0, $userId]
        );
    }

    private function stored(): ?int
    {
        try {
            $value = $this->db->fetchScalar(
                "SELECT enabled FROM server_flags WHERE flag_key = 'mcp_plain_http'"
            );
        } catch (\Throwable) {
            return null;
        }
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        return (int) $value;
    }
}
