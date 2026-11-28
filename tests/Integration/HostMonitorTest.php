<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Host\HostFiles;
use Dashboard\Host\HostHttp;
use Dashboard\Host\HostMonitor;
use Dashboard\Host\HostProbe;
use Dashboard\Host\HostReadings;
use Dashboard\Host\HostShell;

final class HostMonitorTest extends PgsqlTestCase
{
    private string $root = '';

    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['ada', 'ada@example.com', 'hash', 1]
        );
        $this->admin = (int) $this->db->lastInsertId();
        $this->root = sys_get_temp_dir() . '/lifedash-hostmon-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/proc', 0777, true);
        file_put_contents($this->root . '/proc/loadavg', "1.00 0.50 0.25 1/10 1\n");
        file_put_contents($this->root . '/proc/uptime', "100 0\n");
        file_put_contents($this->root . '/proc/stat', "cpu  1 0 1 8 0 0 0 0\n");
    }

    protected function tearDown(): void
    {
        foreach (['host-snapshot.json', 'host-snapshot.lock', 'host-counters.json'] as $name) {
            $path = (defined('STORAGE_DIR') ? STORAGE_DIR : '') . '/' . $name;
            if ($path !== '/' . $name && is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testHistoryPruneOptionsAndTunnelNotification(): void
    {
        $store = new \Dashboard\Host\HostStore($this->db);
        $store->insert('2026-01-01 00:00:00', [['metric' => 'cpu.load1', 'subject' => '', 'value' => 9.0]]);
        $store->insert(gmdate('Y-m-d H:i:s'), [['metric' => 'cpu.load1', 'subject' => '', 'value' => 1.5]]);
        $history = $store->history('cpu.load1', '', '24h');
        self::assertSame('cpu.load1', $history['metric']);
        self::assertCount(1, $history['points']);
        self::assertEqualsWithDelta(1.5, $history['points'][0]['value'], 0.001);
        $store->prune(90);
        $left = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM host_samples WHERE value = 9');
        self::assertSame(0, $left);

        $monitor = new HostMonitor($this->db, $this->probe());
        $monitor->saveOptions([
            'sample_interval_minutes' => 5,
            'retention_days' => 90,
            'tunnel_name' => 'ai-vault',
            'tunnel_hostnames' => "https://mcp.lifeboardx.com/mcp\nhttps://dash.lifeboardx.com",
            'tunnel_metrics_url' => 'http://127.0.0.1:20241/metrics',
            'known' => ['k10temp:Tctl'],
            'shown' => [],
        ], $this->admin);
        $saved = $monitor->options();
        self::assertSame('ai-vault', $saved['tunnel_name']);
        self::assertSame(['k10temp:Tctl'], $saved['hidden_sensors']);
        $audit = $this->db->fetchOne('SELECT resource, detail FROM audit_events WHERE user_id = ?', [$this->admin]);
        self::assertSame('/settings/host', $audit['resource']);

        $this->db->execute("UPDATE host_monitor_options SET tunnel_state = 'up' WHERE id = 1");
        $this->db->execute('DELETE FROM host_samples');
        $result = $monitor->sample();
        self::assertFalse($result['skipped']);
        self::assertSame('down', $result['tunnel']);
        $note = $this->db->fetchOne('SELECT title, href FROM notifications WHERE user_id = ?', [$this->admin]);
        self::assertSame('Tunnel down', $note['title']);
        self::assertSame('/settings/host', $note['href']);
        self::assertGreaterThan(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM host_samples'));
    }

    private function probe(): HostProbe
    {
        return new HostProbe(
            new HostFiles($this->root),
            new HostShell(function (array $argv): ?array {
                if (in_array('cloudflared', $argv, true)) {
                    return ['code' => 0, 'out' => "LoadState=loaded\nActiveState=inactive\nSubState=dead\n", 'err' => ''];
                }
                if (str_contains($argv[0], 'apt-get')) {
                    return null;
                }

                return ['code' => 0, 'out' => '', 'err' => ''];
            }),
            new HostHttp(fn (string $url, bool $follow): array => ['status' => null, 'latency_ms' => null, 'error' => 'down', 'body' => '']),
            function (): float {
                static $n = 0;
                $n += 0.2;

                return 1000 + $n;
            },
            fn (string $host): array => ['1.1.1.1'],
            0,
        );
    }
}
