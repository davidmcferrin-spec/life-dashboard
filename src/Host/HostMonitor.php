<?php

declare(strict_types=1);

namespace Dashboard\Host;

use Dashboard\Audit\AuditLog;
use Dashboard\DB\Database;
use Dashboard\Notification\NotificationService;

/**
 * Live snapshot, the 5-minute sample, and tunnel alerts.
 * The web pool cannot read /proc, so a page load asks the CLI build for a fresh snapshot.
 */
final class HostMonitor
{
    private const CACHE_SECONDS = 15;

    private readonly HostStore $store;

    private readonly HostProbe $probe;

    public function __construct(private readonly Database $db, ?HostProbe $probe = null)
    {
        $this->store = new HostStore($db);
        $this->probe = $probe ?? new HostProbe(new HostFiles(), new HostShell(), new HostHttp());
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->store->options();
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveOptions(array $input, int $userId): void
    {
        $previous = $this->store->options();
        $next = HostReadings::options($input, $previous);
        $next['tunnel_state'] = $previous['tunnel_state'];
        $this->store->save($next);
        (new AuditLog($this->db))->record($userId, 'user', null, 'POST', '/settings/host', [
            'entity' => 'host',
            'sample_interval_minutes' => $next['sample_interval_minutes'],
            'retention_days' => $next['retention_days'],
            'hidden_sensors' => count($next['hidden_sensors']),
            'tunnel_name' => $next['tunnel_name'],
            'hostnames' => count($next['tunnel_hostnames']),
            'metrics_url' => $next['tunnel_metrics_url'] !== '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function live(bool $fresh = false): array
    {
        $lock = $this->lock();
        try {
            if (!$fresh) {
                $cached = $this->readCache();
                if ($cached !== null) {
                    return $cached;
                }
            }
            if (!$this->probe->canReadHost()) {
                $snap = $this->spawn() ?? HostReadings::blank('Host readings are not readable from this process.');
            } else {
                $snap = $this->capture();
            }
            $this->writeCache($snap);

            return $snap;
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function capture(): array
    {
        $options = $this->store->options();
        $snap = $this->probe->capture($options, $this->counters());
        $this->writeCounters($snap['_counters'] ?? null);
        unset($snap['_counters']);
        $snap['sources'] = HostReadings::SOURCES;

        return $snap;
    }

    /**
     * @return array{skipped: bool, points: int, tunnel: string}
     */
    public function sample(): array
    {
        $options = $this->store->options();
        $this->store->prune((int) $options['retention_days']);
        $latest = $this->store->latestAt();
        $interval = (int) $options['sample_interval_minutes'] * 60;
        if ($latest !== null && (time() - $latest) < ($interval - 45)) {
            return ['skipped' => true, 'points' => 0, 'tunnel' => (string) $options['tunnel_state']];
        }
        $snap = $this->capture();
        $sampledAt = gmdate('Y-m-d H:i:s');
        $points = HostReadings::points($snap);
        $written = $this->store->insert($sampledAt, $points);
        $alert = (string) ($snap['tunnel']['alert'] ?? 'unknown');
        $this->notify($options['tunnel_state'], $alert, $snap);
        if (in_array($alert, ['up', 'degraded', 'down', 'unknown'], true)) {
            $this->store->setState($alert);
        }
        $this->writeCache($snap);

        return ['skipped' => false, 'points' => $written, 'tunnel' => $alert];
    }

    /**
     * @return array{metric: string, subject: string, label: string, unit: string, range: string, points: list<array{at: string, value: float}>}
     */
    public function history(string $metric, string $subject, string $range): array
    {
        return $this->store->history($metric, $subject, $range);
    }

    /**
     * @param array<string, mixed> $snap
     * @return list<array{metric: string, label: string, unit: string, subjects: list<array{subject: string, label: string}>}>
     */
    public function catalog(array $snap = []): array
    {
        $live = [];
        foreach (HostReadings::points($snap) as $point) {
            $live[$point['metric']][$point['subject']] = $point['subject_label'];
        }

        return $this->store->catalog($live);
    }

    /**
     * @param array<string, mixed> $snap
     */
    private function notify(string $previous, string $next, array $snap): void
    {
        if ($previous === '' || $previous === $next || !in_array($next, ['up', 'degraded', 'down'], true)) {
            return;
        }
        if ($next === 'up' && !in_array($previous, ['down', 'degraded'], true)) {
            return;
        }
        $title = match ($next) {
            'down' => 'Tunnel down',
            'degraded' => 'Tunnel degraded',
            default => 'Tunnel restored',
        };
        $body = $this->tunnelBody($snap);
        $notifications = new NotificationService($this->db);
        $admins = $this->db->fetchAll('SELECT id FROM users WHERE is_admin = 1');
        foreach ($admins as $admin) {
            $notifications->create((int) $admin['id'], $title, $body, '/settings/host');
        }
    }

    /**
     * @param array<string, mixed> $snap
     */
    private function tunnelBody(array $snap): string
    {
        $tunnel = is_array($snap['tunnel'] ?? null) ? $snap['tunnel'] : [];
        $service = is_array($tunnel['service'] ?? null) ? $tunnel['service'] : [];
        $connector = is_array($tunnel['connector'] ?? null) ? $tunnel['connector'] : [];
        $parts = [];
        if (isset($service['state']) && is_string($service['state']) && $service['state'] !== '') {
            $parts[] = 'cloudflared ' . $service['state'];
        }
        if (isset($connector['connections']) && is_numeric($connector['connections'])) {
            $parts[] = (int) $connector['connections'] . ' connector connection(s)';
        }
        foreach ($tunnel['probes'] ?? [] as $probe) {
            if (!is_array($probe)) {
                continue;
            }
            $status = $probe['status'] ?? 'unreachable';
            $latency = isset($probe['latency_ms']) && is_numeric($probe['latency_ms']) ? ' ' . round((float) $probe['latency_ms']) . ' ms' : '';
            $parts[] = (string) ($probe['url'] ?? '') . ' ' . $status . $latency;
        }
        $text = implode('. ', $parts);

        return substr($text, 0, 500);
    }

    /**
     * @return array{at: float, ifaces: array<string, array{rx: int, tx: int}>}|null
     */
    private function counters(): ?array
    {
        $path = $this->path('host-counters.json');
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['at'], $data['ifaces']) || !is_array($data['ifaces'])) {
            return null;
        }

        return ['at' => (float) $data['at'], 'ifaces' => $data['ifaces']];
    }

    private function writeCounters(mixed $counters): void
    {
        if (!is_array($counters)) {
            return;
        }
        $path = $this->path('host-counters.json');
        @file_put_contents($path, json_encode($counters));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $path = $this->path('host-snapshot.json');
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['at'], $data['snap']) || !is_array($data['snap'])) {
            return null;
        }
        if ((time() - (int) $data['at']) > self::CACHE_SECONDS) {
            return null;
        }

        return $data['snap'];
    }

    /**
     * @param array<string, mixed> $snap
     */
    private function writeCache(array $snap): void
    {
        @file_put_contents($this->path('host-snapshot.json'), json_encode(['at' => time(), 'snap' => $snap]));
    }

    /**
     * @return resource|null
     */
    private function lock()
    {
        $handle = @fopen($this->path('host-snapshot.lock'), 'c');
        if (!is_resource($handle)) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function unlock(mixed $handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function spawn(): ?array
    {
        $php = null;
        foreach ([PHP_BINDIR . '/php', '/usr/bin/php'] as $candidate) {
            if (is_executable($candidate)) {
                $php = $candidate;
                break;
            }
        }
        if ($php === null) {
            return null;
        }
        $cmd = [$php, ROOT_DIR . '/cron/sample_host.php', '--json'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, ROOT_DIR);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) > 25) {
                proc_terminate($proc, 9);
                proc_close($proc);

                return null;
            }
            usleep(50000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $decoded = json_decode($out, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function path(string $name): string
    {
        $dir = defined('STORAGE_DIR') ? STORAGE_DIR : sys_get_temp_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . $name;
    }
}
