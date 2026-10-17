<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Audit\AuditLog;
use Dashboard\Deploy\DeployService;

final class DeployFlagTest extends PgsqlTestCase
{
    public function testMcpUpdatesDefaultOffAndRoundTrip(): void
    {
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['admin', 'admin@x', 'h', 1]
        );
        $user = (int) $this->db->lastInsertId();
        $deploy = new DeployService($this->db);
        self::assertFalse($deploy->mcpUpdatesEnabled());
        self::assertFalse($deploy->status()['mcp_updates']);

        $same = $deploy->setMcpUpdates(false, $user);
        self::assertFalse($same['changed']);
        self::assertFalse($same['mcp_updates']);

        $on = $deploy->setMcpUpdates(true, $user);
        self::assertTrue($on['changed']);
        self::assertFalse($on['previous']);
        self::assertTrue($deploy->mcpUpdatesEnabled());

        $off = $deploy->setMcpUpdates(false, $user);
        self::assertTrue($off['changed']);
        self::assertTrue($off['previous']);
        self::assertFalse($deploy->mcpUpdatesEnabled());

        $audit = new AuditLog($this->db);
        $audit->record($user, 'user', null, 'POST', '/api/v1/system/deploy/mcp-updates', [
            'entity' => 'deploy',
            'mcp_updates' => true,
            'changed' => true,
            'previous' => false,
        ]);
        $rows = $audit->search($user, ['entity' => 'deploy', 'limit' => 5]);
        self::assertNotSame([], $rows);
        self::assertTrue($rows[0]['detail']['mcp_updates']);
        self::assertFalse($rows[0]['detail']['previous']);
    }
}
