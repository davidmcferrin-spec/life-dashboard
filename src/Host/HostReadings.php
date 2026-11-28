<?php

declare(strict_types=1);

namespace Dashboard\Host;

use Dashboard\Support\ValidationException;

/**
 * Parsers and the metric catalog. Nothing here reads the machine.
 */
final class HostReadings
{
    public const RANGES = [
        '6h' => 6 * 3600,
        '24h' => 24 * 3600,
        '7d' => 7 * 86400,
        '30d' => 30 * 86400,
        '90d' => 90 * 86400,
    ];

    public const BUCKETS = [
        '6h' => 300,
        '24h' => 900,
        '7d' => 3600,
        '30d' => 21600,
        '90d' => 86400,
    ];

    /** @var array<string, array{label: string, unit: string}> */
    public const METRICS = [
        'cpu.load1' => ['label' => 'CPU load (1 min)', 'unit' => ''],
        'cpu.load5' => ['label' => 'CPU load (5 min)', 'unit' => ''],
        'cpu.load15' => ['label' => 'CPU load (15 min)', 'unit' => ''],
        'cpu.util' => ['label' => 'CPU use', 'unit' => '%'],
        'cpu.freq_mhz' => ['label' => 'CPU frequency', 'unit' => 'MHz'],
        'gpu.util' => ['label' => 'GPU use', 'unit' => '%'],
        'gpu.vram_used_mib' => ['label' => 'GPU VRAM used', 'unit' => 'MiB'],
        'gpu.clock_mhz' => ['label' => 'GPU clock', 'unit' => 'MHz'],
        'gpu.power_w' => ['label' => 'GPU power', 'unit' => 'W'],
        'temp.c' => ['label' => 'Temperature', 'unit' => '°C'],
        'fan.rpm' => ['label' => 'Fan speed', 'unit' => 'rpm'],
        'disk.used_pct' => ['label' => 'Disk use', 'unit' => '%'],
        'disk.temp_c' => ['label' => 'Disk temperature', 'unit' => '°C'],
        'disk.smart' => ['label' => 'SMART passed', 'unit' => ''],
        'mem.used_pct' => ['label' => 'Memory use', 'unit' => '%'],
        'mem.used_mib' => ['label' => 'Memory used', 'unit' => 'MiB'],
        'swap.used_pct' => ['label' => 'Swap use', 'unit' => '%'],
        'power.w' => ['label' => 'Power', 'unit' => 'W'],
        'voltage.v' => ['label' => 'Voltage', 'unit' => 'V'],
        'net.rx_bps' => ['label' => 'Network in', 'unit' => 'B/s'],
        'net.tx_bps' => ['label' => 'Network out', 'unit' => 'B/s'],
        'proc.count' => ['label' => 'Processes', 'unit' => ''],
        'uptime.s' => ['label' => 'Uptime', 'unit' => 's'],
        'tunnel.up' => ['label' => 'Tunnel up', 'unit' => ''],
        'tunnel.degraded' => ['label' => 'Tunnel degraded', 'unit' => ''],
        'tunnel.latency_ms' => ['label' => 'Tunnel latency', 'unit' => 'ms'],
        'tunnel.connections' => ['label' => 'Tunnel connections', 'unit' => ''],
        'tunnel.http' => ['label' => 'Tunnel reachability', 'unit' => ''],
    ];

    /** @var array<string, string> */
    public const SOURCES = [
        'cpu' => '/proc/loadavg, /proc/stat, /proc/cpuinfo, cpufreq scaling_cur_freq',
        'gpu' => '/sys/class/drm card device: gpu_busy_percent, mem_info_vram_*, hwmon freq1_input and power1_input, product_name, driver',
        'temperature' => '/sys/class/hwmon temp*_input. UPS hwmon is skipped.',
        'power' => 'RAPL/powercap energy_uj delta, hwmon power*_input, battery power_now. UPS sensors are skipped.',
        'voltage' => 'hwmon in*_input and battery voltage_now. UPS sensors are skipped.',
        'memory' => '/proc/meminfo MemTotal, MemAvailable, SwapTotal, SwapFree',
        'disk' => '/proc/mounts plus statvfs. SMART from smartctl -j when that command can read the disk.',
        'network' => '/proc/net/dev byte counters over the sample window',
        'processes' => '/proc pid stat. Failed units from systemctl --state=failed.',
        'os' => '/etc/os-release, /proc/sys/kernel/osrelease, /proc/uptime, /run/reboot-required',
        'updates' => 'apt-get -s upgrade. Security lines are the ones whose pocket contains security or esm.',
        'tunnel' => 'systemctl show cloudflared, a cloudflared process, the metrics URL gauge cloudflared_tunnel_ha_connections, and an HTTPS GET of each saved hostname',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'sample_interval_minutes' => 5,
            'retention_days' => 90,
            'hidden_sensors' => [],
            'tunnel_name' => '',
            'tunnel_hostnames' => [
                'https://mcp.lifeboardx.com/mcp',
                'https://dash.lifeboardx.com',
            ],
            'tunnel_metrics_url' => 'http://127.0.0.1:20241/metrics',
            'tunnel_state' => '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    public static function options(array $input, array $previous): array
    {
        $interval = filter_var($input['sample_interval_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 5 || $interval > 120) {
            throw new \InvalidArgumentException('Sample interval must be from 5 to 120 minutes.');
        }
        $retention = filter_var($input['retention_days'] ?? null, FILTER_VALIDATE_INT);
        if ($retention === false || $retention < 7 || $retention > 365) {
            throw new \InvalidArgumentException('Retention must be from 7 to 365 days.');
        }
        $name = trim((string) ($input['tunnel_name'] ?? ''));
        $name = str_replace(["\r", "\n", "\0"], '', $name);
        if (strlen($name) > 80) {
            throw new \InvalidArgumentException('Tunnel name must be 80 characters or fewer.');
        }
        $hostnames = self::hostnames($input['tunnel_hostnames'] ?? '');
        $metrics = self::metricsUrl((string) ($input['tunnel_metrics_url'] ?? ''));
        $known = self::sensorIds($input['known'] ?? []);
        $shown = self::sensorIds($input['shown'] ?? []);
        $previousHidden = self::sensorIds($previous['hidden_sensors'] ?? []);
        $hiddenNow = array_values(array_diff($known, $shown));
        $kept = array_values(array_filter(
            $previousHidden,
            static fn (string $id): bool => !in_array($id, $known, true)
        ));

        return [
            'sample_interval_minutes' => $interval,
            'retention_days' => $retention,
            'hidden_sensors' => array_values(array_unique(array_merge($kept, $hiddenNow))),
            'tunnel_name' => $name,
            'tunnel_hostnames' => $hostnames,
            'tunnel_metrics_url' => $metrics,
            'tunnel_state' => is_string($previous['tunnel_state'] ?? null) ? $previous['tunnel_state'] : '',
        ];
    }

    /**
     * @return list<string>
     */
    public static function hostnames(mixed $raw): array
    {
        if (is_array($raw)) {
            $lines = $raw;
        } else {
            $lines = preg_split('/\r\n|\r|\n/', trim((string) $raw)) ?: [];
        }
        $out = [];
        foreach ($lines as $line) {
            $url = trim((string) $line);
            if ($url === '') {
                continue;
            }
            $out[] = self::publicHttps($url);
        }
        if (count($out) > 4) {
            throw new \InvalidArgumentException('Save at most four tunnel hostnames.');
        }

        return array_values(array_unique($out));
    }

    public static function publicHttps(string $url): string
    {
        if (strlen($url) > 160 || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            throw new \InvalidArgumentException('A tunnel hostname must be an https URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('A tunnel hostname must be an https URL.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
            throw new \InvalidArgumentException('A tunnel hostname must be https, with no user, password, or query.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || !self::publicHost($host)) {
            throw new \InvalidArgumentException('Tunnel hostnames stay on the public names of this tunnel.');
        }
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return 'https://' . $host . $port . $path;
    }

    public static function metricsUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (strlen($raw) > 200 || preg_match('/[\s\x00-\x1f]/', $raw) === 1) {
            throw new \InvalidArgumentException('The metrics URL must stay on this machine.');
        }
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('The metrics URL must stay on this machine.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \InvalidArgumentException('The metrics URL must be http or https on 127.0.0.1, localhost, or ::1.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
            throw new \InvalidArgumentException('The metrics URL cannot include a user, password, or query.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The metrics URL port is not valid.');
        }
        $path = (string) ($parts['path'] ?? '/metrics');
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..')) {
            throw new \InvalidArgumentException('The metrics URL path is not valid.');
        }
        $shown = $host === '::1' ? '[::1]' : $host;

        return $scheme . '://' . $shown . ':' . $port . $path;
    }

    public static function publicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::publicIp($host);
        }
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || str_starts_with($host, '.') || str_contains($host, '..')) {
            return false;
        }

        return true;
    }

    public static function publicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    public static function isUps(string $name, string $type = ''): bool
    {
        if (strtolower(trim($type)) === 'ups') {
            return true;
        }
        $hay = strtolower($name . ' ' . $type);
        foreach (['ups', 'apc', 'nut', 'cyberpower', 'eaton', 'powerpanel', 'usbhid'] as $needle) {
            if (preg_match('/(^|[^a-z])' . $needle . '([^a-z]|$)/', $hay) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{total: float, idle: float}>
     */
    public static function cpuTimes(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'cpu')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $key = $parts[0] === 'cpu' ? 'all' : (preg_match('/^cpu(\d+)$/', $parts[0], $m) === 1 ? $m[1] : null);
            if ($key === null) {
                continue;
            }
            $nums = [];
            foreach (array_slice($parts, 1) as $part) {
                if (is_numeric($part)) {
                    $nums[] = (float) $part;
                }
            }
            if ($nums === []) {
                continue;
            }
            $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0);
            $out[$key] = ['total' => array_sum($nums), 'idle' => $idle];
        }

        return $out;
    }

    /**
     * @param array{total: float, idle: float} $before
     * @param array{total: float, idle: float} $after
     */
    public static function cpuPercent(array $before, array $after): ?float
    {
        $total = $after['total'] - $before['total'];
        $idle = $after['idle'] - $before['idle'];
        if ($total <= 0 || $idle < 0 || $idle > $total) {
            return null;
        }

        return (1 - ($idle / $total)) * 100;
    }

    /**
     * @return array{total: ?int, available: ?int, swap_total: ?int, swap_free: ?int}
     */
    public static function meminfo(string $text): array
    {
        $want = ['MemTotal' => null, 'MemAvailable' => null, 'SwapTotal' => null, 'SwapFree' => null];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+)\s+kB/', $line, $m) !== 1) {
                continue;
            }
            $want[$m[1]] = (int) $m[2] * 1024;
        }

        return [
            'total' => $want['MemTotal'],
            'available' => $want['MemAvailable'],
            'swap_total' => $want['SwapTotal'],
            'swap_free' => $want['SwapFree'],
        ];
    }

    /**
     * @return list<array{device: string, mount: string, fstype: string}>
     */
    public static function mounts(string $text): array
    {
        $keep = ['ext4', 'ext3', 'xfs', 'btrfs', 'zfs', 'vfat', 'exfat', 'ntfs', 'ntfs3', 'f2fs', 'bcachefs'];
        $out = [];
        $seen = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(' ', str_replace('\040', ' ', $line));
            if (count($parts) < 3) {
                continue;
            }
            $fstype = $parts[2];
            $mount = $parts[1];
            if (!in_array($fstype, $keep, true) || isset($seen[$mount])) {
                continue;
            }
            if (str_starts_with($mount, '/snap') || str_starts_with($mount, '/proc') || str_starts_with($mount, '/sys')) {
                continue;
            }
            $seen[$mount] = true;
            $out[] = ['device' => $parts[0], 'mount' => $mount, 'fstype' => $fstype];
        }

        return $out;
    }

    public static function diskOf(string $source): ?string
    {
        if (!str_starts_with($source, '/dev/')) {
            return null;
        }
        $base = basename($source);
        if (preg_match('/^(nvme\d+n\d+)/', $base, $m) === 1 || preg_match('/^(mmcblk\d+)/', $base, $m) === 1) {
            return '/dev/' . $m[1];
        }
        if (preg_match('/^([a-z]+)\d+$/', $base, $m) === 1) {
            return '/dev/' . $m[1];
        }
        if (preg_match('/^(nvme\d+n\d+|sd[a-z]+|vd[a-z]+|mmcblk\d+)$/', $base) === 1) {
            return '/dev/' . $base;
        }

        return null;
    }

    /**
     * @return list<array{name: string, from: ?string, to: string, security: bool}>
     */
    public static function apt(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'Inst ')) {
                continue;
            }
            $name = '';
            $from = null;
            $detail = '';
            if (preg_match('/^Inst (\S+) \[([^\]]*)\] \(([^)]*)\)/', $line, $m) === 1) {
                $name = $m[1];
                $from = $m[2];
                $detail = $m[3];
            } elseif (preg_match('/^Inst (\S+) \(([^)]*)\)/', $line, $m) === 1) {
                $name = $m[1];
                $detail = $m[2];
            } else {
                continue;
            }
            $to = preg_split('/\s+/', $detail)[0] ?? '';
            $out[] = [
                'name' => $name,
                'from' => $from,
                'to' => $to,
                'security' => preg_match('/security|esm/i', $detail) === 1,
            ];
        }

        return $out;
    }

    /**
     * @return array{passed: ?bool, temp_c: ?float}
     */
    public static function smart(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['passed' => null, 'temp_c' => null];
        }
        $passed = null;
        if (isset($data['smart_status']['passed']) && is_bool($data['smart_status']['passed'])) {
            $passed = $data['smart_status']['passed'];
        }
        $temp = null;
        if (isset($data['temperature']['current']) && is_numeric($data['temperature']['current'])) {
            $temp = (float) $data['temperature']['current'];
        }

        return ['passed' => $passed, 'temp_c' => $temp];
    }

    public static function prometheus(string $body, string $name): ?float
    {
        $sum = null;
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_starts_with($line, $name)) {
                continue;
            }
            $rest = substr($line, strlen($name));
            if ($rest !== '' && $rest[0] !== ' ' && $rest[0] !== '{') {
                continue;
            }
            if (preg_match('/\s([-+0-9.eE]+)$/', $line, $m) !== 1 || !is_numeric($m[1])) {
                continue;
            }
            $sum = ($sum ?? 0) + (float) $m[1];
        }

        return $sum;
    }

    /**
     * @param array<string, string> $props
     */
    public static function serviceActive(array $props): ?bool
    {
        $load = $props['LoadState'] ?? '';
        $active = $props['ActiveState'] ?? '';
        if ($load === '' && $active === '') {
            return null;
        }
        if ($load === 'not-found') {
            return null;
        }
        if ($active === 'active') {
            return true;
        }
        if ($active !== '') {
            return false;
        }

        return null;
    }

    /**
     * @param array{active: ?bool, process: ?bool} $service
     * @param array{available: bool, connections: ?float} $connector
     * @param list<array{reachable: bool}> $probes
     */
    public static function tunnelAlert(array $service, array $connector, array $probes, bool $hostsConfigured): string
    {
        $active = $service['active'];
        $process = $service['process'];
        $serviceKnown = $active !== null || $process !== null;
        $serviceUp = $active === true || ($active === null && $process === true);
        $serviceDown = $active === false || ($active === null && $process === false);
        $ok = 0;
        $fail = 0;
        foreach ($probes as $probe) {
            if (!empty($probe['reachable'])) {
                $ok++;
            } else {
                $fail++;
            }
        }
        $allFail = $hostsConfigured && $ok === 0 && $fail > 0;
        $someFail = $fail > 0 && $ok > 0;
        $connections = $connector['connections'];
        $connKnown = $connector['available'] === true && $connections !== null;
        $connNone = $connKnown && $connections === 0.0;
        $connPartial = $connKnown && $connections > 0 && $connections < 4;
        // A missing metrics port is unavailable, not a failed tunnel. The count
        // only changes the alert once cloudflared actually published it.

        if (!$serviceKnown && !$hostsConfigured && !$connKnown) {
            return 'unknown';
        }
        if ($allFail || ($serviceDown && $ok === 0)) {
            return 'down';
        }
        if ($serviceDown || $someFail || $connPartial || $connNone || ($hostsConfigured && $fail > 0) || ($serviceKnown && !$serviceUp)) {
            return 'degraded';
        }
        if ($serviceUp && $fail === 0) {
            return 'up';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $snap
     * @return list<array{metric: string, subject: string, subject_label: string, value: float}>
     */
    public static function points(array $snap): array
    {
        $out = [];
        $add = static function (string $metric, string $subject, mixed $value, string $label = '') use (&$out): void {
            if (!is_int($value) && !is_float($value)) {
                return;
            }
            if (!is_finite((float) $value)) {
                return;
            }
            $out[] = [
                'metric' => $metric,
                'subject' => $subject,
                'subject_label' => $label !== '' ? $label : ($subject !== '' ? $subject : 'host'),
                'value' => (float) $value,
            ];
        };
        $load = $snap['cpu']['load'] ?? null;
        if (is_array($load) && count($load) === 3) {
            $add('cpu.load1', '', $load[0], '1 min');
            $add('cpu.load5', '', $load[1], '5 min');
            $add('cpu.load15', '', $load[2], '15 min');
        }
        $add('cpu.util', 'all', $snap['cpu']['package_percent'] ?? null, 'all');
        foreach ($snap['cpu']['cores'] ?? [] as $core) {
            if (!is_array($core)) {
                continue;
            }
            $id = (string) ($core['id'] ?? '');
            $add('cpu.util', $id, $core['percent'] ?? null, 'core ' . $id);
            $add('cpu.freq_mhz', $id, $core['mhz'] ?? null, 'core ' . $id);
        }
        foreach ($snap['gpus'] ?? [] as $gpu) {
            if (!is_array($gpu)) {
                continue;
            }
            $id = (string) ($gpu['id'] ?? '');
            $label = (string) ($gpu['model'] ?? $id);
            $add('gpu.util', $id, $gpu['util_percent'] ?? null, $label);
            if (isset($gpu['vram_used_bytes']) && is_int($gpu['vram_used_bytes'])) {
                $add('gpu.vram_used_mib', $id, $gpu['vram_used_bytes'] / 1048576, $label);
            }
            $add('gpu.clock_mhz', $id, $gpu['clock_mhz'] ?? null, $label);
            $add('gpu.power_w', $id, $gpu['power_watts'] ?? null, $label);
        }
        foreach (['temperatures' => 'temp.c', 'fans' => 'fan.rpm'] as $key => $metric) {
            foreach ($snap[$key] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $field = $metric === 'temp.c' ? 'celsius' : 'rpm';
                $add($metric, (string) ($row['id'] ?? ''), $row[$field] ?? null, (string) ($row['label'] ?? ''));
            }
        }
        foreach ($snap['mounts'] ?? [] as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $add('disk.used_pct', (string) ($mount['mount'] ?? ''), $mount['used_percent'] ?? null, (string) ($mount['mount'] ?? ''));
        }
        foreach ($snap['disks'] ?? [] as $disk) {
            if (!is_array($disk)) {
                continue;
            }
            $id = (string) ($disk['device'] ?? '');
            $add('disk.temp_c', $id, $disk['temp_c'] ?? null, $id);
            if (($disk['smart'] ?? null) === 'passed') {
                $add('disk.smart', $id, 1, $id);
            } elseif (($disk['smart'] ?? null) === 'failed') {
                $add('disk.smart', $id, 0, $id);
            }
        }
        $memory = is_array($snap['memory'] ?? null) ? $snap['memory'] : [];
        $add('mem.used_pct', '', $memory['used_percent'] ?? null, 'used');
        if (isset($memory['used_bytes']) && is_int($memory['used_bytes'])) {
            $add('mem.used_mib', '', $memory['used_bytes'] / 1048576, 'used');
        }
        $add('swap.used_pct', '', $memory['swap_used_percent'] ?? null, 'used');
        foreach (['power' => 'power.w', 'voltage' => 'voltage.v'] as $key => $metric) {
            foreach ($snap[$key] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $field = $metric === 'power.w' ? 'watts' : 'volts';
                $add($metric, (string) ($row['id'] ?? ''), $row[$field] ?? null, (string) ($row['label'] ?? ''));
            }
        }
        foreach ($snap['network'] ?? [] as $iface) {
            if (!is_array($iface)) {
                continue;
            }
            $name = (string) ($iface['iface'] ?? '');
            $add('net.rx_bps', $name, $iface['rx_bps'] ?? null, $name . ' in');
            $add('net.tx_bps', $name, $iface['tx_bps'] ?? null, $name . ' out');
        }
        $add('proc.count', '', $snap['processes']['count'] ?? null, 'processes');
        $add('uptime.s', '', $snap['host']['uptime_seconds'] ?? null, 'uptime');
        $tunnel = is_array($snap['tunnel'] ?? null) ? $snap['tunnel'] : [];
        $alert = (string) ($tunnel['alert'] ?? 'unknown');
        if ($alert === 'up') {
            $add('tunnel.up', '', 1, 'up');
            $add('tunnel.degraded', '', 0, 'degraded');
        } elseif ($alert === 'down' || $alert === 'degraded') {
            $add('tunnel.up', '', 0, 'up');
            $add('tunnel.degraded', '', $alert === 'degraded' ? 1 : 0, 'degraded');
        }
        $connections = $tunnel['connector']['connections'] ?? null;
        $add('tunnel.connections', '', $connections, 'connections');
        foreach ($tunnel['probes'] ?? [] as $probe) {
            if (!is_array($probe)) {
                continue;
            }
            $url = (string) ($probe['url'] ?? '');
            $add('tunnel.latency_ms', $url, $probe['latency_ms'] ?? null, $url);
            if (array_key_exists('reachable', $probe)) {
                $add('tunnel.http', $url, !empty($probe['reachable']) ? 1 : 0, $url);
            }
        }

        return $out;
    }

    /**
     * @param list<array{at: int, value: float}> $points
     * @return list<array{at: string, value: float}>
     */
    public static function downsample(array $points, int $bucketSeconds): array
    {
        if ($bucketSeconds < 1 || $points === []) {
            return [];
        }
        $buckets = [];
        foreach ($points as $point) {
            $key = intdiv($point['at'], $bucketSeconds) * $bucketSeconds;
            $buckets[$key]['sum'] = ($buckets[$key]['sum'] ?? 0) + $point['value'];
            $buckets[$key]['n'] = ($buckets[$key]['n'] ?? 0) + 1;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $at => $bucket) {
            $out[] = [
                'at' => gmdate('Y-m-d\TH:i:s\Z', (int) $at),
                'value' => $bucket['sum'] / $bucket['n'],
            ];
        }

        return $out;
    }

    public static function metric(string $raw): string
    {
        $metric = trim($raw);
        if (!isset(self::METRICS[$metric])) {
            throw ValidationException::withFields(['metric' => 'Unknown metric.']);
        }

        return $metric;
    }

    public static function range(string $raw): string
    {
        $range = trim($raw);
        if ($range === '') {
            return '24h';
        }
        if (!isset(self::RANGES[$range])) {
            throw ValidationException::withFields(['range' => 'Range must be 6h, 24h, 7d, 30d, or 90d.']);
        }

        return $range;
    }

    public static function subject(string $raw): string
    {
        if (strlen($raw) > 160 || preg_match('/[\x00-\x20\'"\\\\]/', $raw) === 1) {
            throw ValidationException::withFields(['subject' => 'That subject is not a sensor, disk, or hostname.']);
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $snap
     * @return list<array{id: string, label: string, group: string}>
     */
    public static function sensors(array $snap): array
    {
        $out = [];
        foreach (['temperatures' => 'Temperature', 'fans' => 'Fan', 'power' => 'Power', 'voltage' => 'Voltage'] as $key => $group) {
            foreach ($snap[$key] ?? [] as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }
                $out[] = [
                    'id' => (string) $row['id'],
                    'label' => (string) ($row['label'] ?? $row['id']),
                    'group' => $group,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function blank(string $error = ''): array
    {
        return [
            'sampled_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'error' => $error,
            'host' => [
                'hostname' => null, 'os' => null, 'kernel' => null, 'arch' => null,
                'uptime_seconds' => null, 'boot_time' => null,
            ],
            'cpu' => ['model' => null, 'load' => null, 'package_percent' => null, 'cores' => []],
            'gpus' => [],
            'temperatures' => [],
            'fans' => [],
            'mounts' => [],
            'disks' => [],
            'memory' => [
                'total_bytes' => null, 'available_bytes' => null, 'used_bytes' => null, 'used_percent' => null,
                'swap_total_bytes' => null, 'swap_free_bytes' => null, 'swap_used_bytes' => null, 'swap_used_percent' => null,
            ],
            'power' => [],
            'voltage' => [],
            'network' => [],
            'processes' => ['count' => null, 'top' => []],
            'failed_services' => ['available' => false, 'units' => []],
            'reboot_required' => ['known' => false, 'required' => false, 'packages' => []],
            'updates' => ['checked' => false, 'count' => null, 'security_count' => null, 'packages' => [], 'source' => 'apt-get -s upgrade'],
            'tunnel' => [
                'name' => '',
                'alert' => 'unknown',
                'service' => ['unit' => 'cloudflared', 'active' => null, 'state' => null, 'sub' => null, 'process' => null],
                'connector' => ['available' => false, 'connections' => null, 'state' => null, 'source' => null],
                'probes' => [],
            ],
            'sources' => self::SOURCES,
        ];
    }

    /**
     * @return list<string>
     */
    private static function sensorIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if ($id === '' || strlen($id) > 80 || preg_match('/^[A-Za-z0-9_.:@+-]+$/', $id) !== 1) {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }
}
