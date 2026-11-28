<?php

declare(strict_types=1);

namespace Dashboard\Host;

/**
 * One pass over sysfs, /proc, and the local commands that publish host readings.
 * A missing file stays null. Nothing here is filled in from a UPS or from a guess.
 */
final class HostProbe
{
    private const DWELL_US = 200000;

    /** @param null|\Closure(string): list<string> $resolve */
    public function __construct(
        private readonly HostFiles $files,
        private readonly HostShell $shell,
        private readonly HostHttp $http,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $resolve = null,
        private readonly int $dwellUs = self::DWELL_US,
    ) {}

    public function canReadHost(): bool
    {
        return $this->files->canReadHost();
    }

    /**
     * @param array<string, mixed> $options
     * @param array{at: float, ifaces: array<string, array{rx: int, tx: int}>}|null $previousNet
     * @return array<string, mixed>
     */
    public function capture(array $options, ?array $previousNet = null): array
    {
        $snap = HostReadings::blank();
        $t1 = $this->now();
        $stat1 = HostReadings::cpuTimes((string) $this->files->read('/proc/stat'));
        $energy1 = $this->energies();
        $net1 = $this->netCounters();
        $proc1 = $this->procTicks();
        $this->dwell();
        $t2 = $this->now();
        $stat2 = HostReadings::cpuTimes((string) $this->files->read('/proc/stat'));
        $energy2 = $this->energies();
        $net2 = $this->netCounters();
        $proc2 = $this->procTicks();
        $dt = $t2 - $t1;

        $snap['sampled_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $t2);
        $snap['host'] = $this->host($t2);
        $snap['cpu'] = $this->cpu($stat1, $stat2);
        $snap['gpus'] = $this->gpus();
        [$temps, $fans, $power, $voltage] = $this->hwmon();
        $snap['temperatures'] = $temps;
        $snap['fans'] = $fans;
        $rapl = $this->raplWatts($energy1, $energy2, $dt);
        $supply = $this->powerSupply();
        $snap['power'] = array_merge($rapl, $power, $supply['power']);
        $snap['voltage'] = array_merge($voltage, $supply['voltage']);
        $snap['memory'] = $this->memory();
        [$mounts, $disks] = $this->storage();
        $snap['mounts'] = $mounts;
        $snap['disks'] = $disks;
        $snap['network'] = $this->networkRates($net1, $net2, $dt, $previousNet, $t2);
        $snap['processes'] = $this->processes($proc1, $proc2, $dt);
        $snap['failed_services'] = $this->failed();
        $snap['reboot_required'] = $this->reboot();
        $snap['updates'] = $this->updates();
        $snap['tunnel'] = $this->tunnel($options, $snap['processes']['cloudflared']);
        $snap['_counters'] = ['at' => $t2, 'ifaces' => $net2];
        unset($snap['processes']['cloudflared']);

        return $snap;
    }

    private function now(): float
    {
        if ($this->clock !== null) {
            return (float) ($this->clock)();
        }

        return microtime(true);
    }

    private function dwell(): void
    {
        if ($this->dwellUs > 0 && $this->clock === null) {
            usleep($this->dwellUs);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function host(float $now): array
    {
        $os = $this->osRelease();
        $kernel = $this->line('/proc/sys/kernel/osrelease');
        $hostname = $this->line('/proc/sys/kernel/hostname');
        $uptime = null;
        $boot = null;
        $raw = $this->files->read('/proc/uptime');
        if ($raw !== null && preg_match('/^([0-9.]+)/', trim($raw), $m) === 1) {
            $uptime = (float) $m[1];
            $boot = gmdate('Y-m-d\TH:i:s\Z', (int) round($now - $uptime));
        }

        return [
            'hostname' => $hostname,
            'os' => $os,
            'kernel' => $kernel,
            'arch' => $this->line('/proc/sys/kernel/arch'),
            'uptime_seconds' => $uptime,
            'boot_time' => $boot,
        ];
    }

    private function osRelease(): ?string
    {
        $text = $this->files->read('/etc/os-release');
        if ($text === null) {
            return null;
        }
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, 'PRETTY_NAME=')) {
                return trim(substr($line, 12), "\"' \t");
            }
        }

        return null;
    }

    /**
     * @param array<string, array{total: float, idle: float}> $before
     * @param array<string, array{total: float, idle: float}> $after
     * @return array<string, mixed>
     */
    private function cpu(array $before, array $after): array
    {
        $model = null;
        $info = $this->files->read('/proc/cpuinfo');
        if ($info !== null && preg_match('/^model name\s*:\s*(.+)$/m', $info, $m) === 1) {
            $model = trim($m[1]);
        }
        $load = null;
        $loadText = $this->files->read('/proc/loadavg');
        if ($loadText !== null && preg_match('/^([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)/', trim($loadText), $m) === 1) {
            $load = [(float) $m[1], (float) $m[2], (float) $m[3]];
        }
        $cores = [];
        $ids = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($ids);
        foreach ($ids as $id) {
            if ($id === 'all' || !ctype_digit((string) $id)) {
                continue;
            }
            $percent = isset($before[$id], $after[$id]) ? HostReadings::cpuPercent($before[$id], $after[$id]) : null;
            $mhz = $this->num($this->files->read('/sys/devices/system/cpu/cpu' . $id . '/cpufreq/scaling_cur_freq'), 0.001);
            $cores[] = ['id' => (int) $id, 'percent' => $percent, 'mhz' => $mhz];
        }

        return [
            'model' => $model,
            'load' => $load,
            'package_percent' => isset($before['all'], $after['all']) ? HostReadings::cpuPercent($before['all'], $after['all']) : null,
            'cores' => $cores,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gpus(): array
    {
        $out = [];
        foreach ($this->files->list('/sys/class/drm') as $name) {
            if (preg_match('/^card\d+$/', $name) !== 1) {
                continue;
            }
            $dev = '/sys/class/drm/' . $name . '/device';
            if (!$this->files->isDir($dev)) {
                continue;
            }
            $driver = $this->files->link($dev . '/driver');
            $util = $this->num($this->files->read($dev . '/gpu_busy_percent'));
            $used = $this->int($this->files->read($dev . '/mem_info_vram_used'));
            $total = $this->int($this->files->read($dev . '/mem_info_vram_total'));
            $clock = null;
            $power = null;
            foreach ($this->files->list($dev . '/hwmon') as $hw) {
                $base = $dev . '/hwmon/' . $hw;
                $clock ??= $this->num($this->files->read($base . '/freq1_input'), 1 / 1000000);
                $power ??= $this->num($this->files->read($base . '/power1_input'), 1 / 1000000);
            }
            $product = $this->line($dev . '/product_name');
            $known = in_array($driver, ['amdgpu', 'nvidia', 'nouveau', 'i915', 'xe', 'radeon'], true);
            if (!$known && $util === null && $used === null && $clock === null && $power === null && $product === null) {
                continue;
            }
            $version = $driver !== null ? $this->line('/sys/module/' . $driver . '/version') : null;
            $out[] = [
                'id' => $name,
                'model' => $product,
                'pci' => $this->pci($dev),
                'driver' => $driver,
                'driver_version' => $version,
                'util_percent' => $util,
                'vram_used_bytes' => $used,
                'vram_total_bytes' => $total,
                'clock_mhz' => $clock,
                'power_watts' => $power,
            ];
        }

        return $out;
    }

    private function pci(string $dev): ?string
    {
        $uevent = $this->files->read($dev . '/uevent');
        if ($uevent !== null && preg_match('/^PCI_ID=([0-9A-Fa-f]{4}:[0-9A-Fa-f]{4})/m', $uevent, $m) === 1) {
            return strtolower($m[1]);
        }
        $vendor = $this->line($dev . '/vendor');
        $device = $this->line($dev . '/device');
        if ($vendor === null || $device === null) {
            return null;
        }

        return strtolower(ltrim($vendor, '0x')) . ':' . strtolower(ltrim($device, '0x'));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}
     */
    private function hwmon(): array
    {
        $temps = [];
        $fans = [];
        $power = [];
        $voltage = [];
        foreach ($this->files->list('/sys/class/hwmon') as $name) {
            $base = '/sys/class/hwmon/' . $name;
            $chip = $this->line($base . '/name') ?? $name;
            if (HostReadings::isUps($chip)) {
                continue;
            }
            foreach ($this->files->list($base) as $file) {
                if (preg_match('/^temp(\d+)_input$/', $file, $m) === 1) {
                    $c = $this->num($this->files->read($base . '/' . $file), 0.001);
                    if ($c === null) {
                        continue;
                    }
                    $label = $this->line($base . '/temp' . $m[1] . '_label') ?? ('temp' . $m[1]);
                    $temps[] = [
                        'id' => $chip . ':' . $label,
                        'label' => $chip . ' ' . $label,
                        'celsius' => $c,
                        'kind' => $this->tempKind($chip, $label),
                        'source' => 'hwmon',
                    ];
                } elseif (preg_match('/^fan(\d+)_input$/', $file, $m) === 1) {
                    $rpm = $this->num($this->files->read($base . '/' . $file));
                    if ($rpm === null) {
                        continue;
                    }
                    $label = $this->line($base . '/fan' . $m[1] . '_label') ?? ('fan' . $m[1]);
                    $fans[] = [
                        'id' => $chip . ':' . $label,
                        'label' => $chip . ' ' . $label,
                        'rpm' => $rpm,
                        'source' => 'hwmon',
                    ];
                } elseif (preg_match('/^power(\d+)_input$/', $file, $m) === 1) {
                    $watts = $this->num($this->files->read($base . '/' . $file), 1 / 1000000);
                    if ($watts === null) {
                        continue;
                    }
                    $label = $this->line($base . '/power' . $m[1] . '_label') ?? ('power' . $m[1]);
                    $power[] = [
                        'id' => $chip . ':' . $label,
                        'label' => $chip . ' ' . $label,
                        'watts' => $watts,
                        'source' => 'hwmon power_input',
                    ];
                } elseif (preg_match('/^in(\d+)_input$/', $file, $m) === 1) {
                    $volts = $this->num($this->files->read($base . '/' . $file), 0.001);
                    if ($volts === null) {
                        continue;
                    }
                    $label = $this->line($base . '/in' . $m[1] . '_label') ?? ('in' . $m[1]);
                    $voltage[] = [
                        'id' => $chip . ':' . $label,
                        'label' => $chip . ' ' . $label,
                        'volts' => $volts,
                        'source' => 'hwmon in_input',
                    ];
                }
            }
        }

        return [$temps, $fans, $power, $voltage];
    }

    private function tempKind(string $chip, string $label): string
    {
        $hay = strtolower($chip . ' ' . $label);
        if (str_contains($hay, 'nvme')) {
            return 'nvme';
        }
        if (in_array($chip, ['amdgpu', 'nouveau', 'nvidia', 'i915', 'xe'], true)) {
            return 'gpu';
        }
        if (in_array($chip, ['k10temp', 'coretemp', 'zenpower'], true) || preg_match('/tctl|tdie|package/i', $label) === 1) {
            return 'cpu';
        }

        return 'other';
    }

    /**
     * @return array<string, int>
     */
    private function energies(): array
    {
        $out = [];
        $this->walkRapl('/sys/class/powercap', 0, $out);

        return $out;
    }

    /**
     * @param array<string, int> $out
     */
    private function walkRapl(string $dir, int $depth, array &$out): void
    {
        if ($depth > 3) {
            return;
        }
        foreach ($this->files->list($dir) as $name) {
            $path = $dir . '/' . $name;
            if (!$this->files->isDir($path)) {
                continue;
            }
            $label = $this->line($path . '/name');
            $energy = $this->int($this->files->read($path . '/energy_uj'));
            if ($label !== null && $energy !== null && !isset($out[$label])) {
                $out[$label] = $energy;
            }
            $this->walkRapl($path, $depth + 1, $out);
        }
    }

    /**
     * Watts from the energy counter. A single sample, a backwards counter, or a
     * zero-length window is unavailable rather than a made-up wattage.
     *
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return list<array<string, mixed>>
     */
    private function raplWatts(array $before, array $after, float $dt): array
    {
        $out = [];
        foreach ($after as $label => $energy) {
            if (!isset($before[$label]) || $dt < 0.05) {
                continue;
            }
            $delta = $energy - $before[$label];
            if ($delta < 0) {
                continue;
            }
            $out[] = [
                'id' => 'rapl:' . $label,
                'label' => 'RAPL ' . $label,
                'watts' => $delta / $dt / 1000000,
                'source' => 'powercap energy_uj',
            ];
        }

        return $out;
    }

    /**
     * @return array{power: list<array<string, mixed>>, voltage: list<array<string, mixed>>}
     */
    private function powerSupply(): array
    {
        $power = [];
        $voltage = [];
        foreach ($this->files->list('/sys/class/power_supply') as $name) {
            $base = '/sys/class/power_supply/' . $name;
            $type = $this->line($base . '/type') ?? '';
            if (HostReadings::isUps($name, $type) || !in_array($type, ['Battery', 'Mains'], true)) {
                continue;
            }
            $watts = $this->num($this->files->read($base . '/power_now'), 1 / 1000000);
            if ($watts !== null) {
                $power[] = [
                    'id' => 'supply:' . $name,
                    'label' => $type . ' ' . $name,
                    'watts' => $watts,
                    'source' => 'power_supply power_now',
                ];
            }
            $volts = $this->num($this->files->read($base . '/voltage_now'), 1 / 1000000);
            if ($volts !== null) {
                $voltage[] = [
                    'id' => 'supply:' . $name . ':voltage',
                    'label' => $type . ' ' . $name . ' voltage',
                    'volts' => $volts,
                    'source' => 'power_supply voltage_now',
                ];
            }
        }

        return ['power' => $power, 'voltage' => $voltage];
    }

    /**
     * @return array<string, mixed>
     */
    private function memory(): array
    {
        $info = $this->files->read('/proc/meminfo');
        $parsed = $info === null ? ['total' => null, 'available' => null, 'swap_total' => null, 'swap_free' => null] : HostReadings::meminfo($info);
        $used = null;
        $usedPct = null;
        if (is_int($parsed['total']) && is_int($parsed['available']) && $parsed['total'] > 0 && $parsed['available'] <= $parsed['total']) {
            $used = $parsed['total'] - $parsed['available'];
            $usedPct = $used / $parsed['total'] * 100;
        }
        $swapUsed = null;
        $swapPct = null;
        if (is_int($parsed['swap_total']) && is_int($parsed['swap_free']) && $parsed['swap_total'] > 0 && $parsed['swap_free'] <= $parsed['swap_total']) {
            $swapUsed = $parsed['swap_total'] - $parsed['swap_free'];
            $swapPct = $swapUsed / $parsed['swap_total'] * 100;
        }

        return [
            'total_bytes' => $parsed['total'],
            'available_bytes' => $parsed['available'],
            'used_bytes' => $used,
            'used_percent' => $usedPct,
            'swap_total_bytes' => $parsed['swap_total'],
            'swap_free_bytes' => $parsed['swap_free'],
            'swap_used_bytes' => $swapUsed,
            'swap_used_percent' => $swapPct,
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function storage(): array
    {
        $text = $this->files->read('/proc/mounts');
        $mounts = [];
        $devices = [];
        foreach ($text === null ? [] : HostReadings::mounts($text) as $row) {
            $space = $this->files->disk($row['mount']);
            $used = null;
            $pct = null;
            $total = null;
            if ($space !== null && $space['total'] > 0 && $space['free'] <= $space['total']) {
                $total = $space['total'];
                $used = $space['total'] - $space['free'];
                $pct = $used / $space['total'] * 100;
            }
            $mounts[] = [
                'mount' => $row['mount'],
                'source' => $row['device'],
                'fstype' => $row['fstype'],
                'used_bytes' => $used,
                'total_bytes' => $total,
                'used_percent' => $pct,
            ];
            $disk = HostReadings::diskOf($row['device']);
            if ($disk !== null) {
                $devices[$disk] = true;
            }
        }
        $disks = [];
        foreach (array_keys($devices) as $device) {
            if (!is_string($device) || preg_match('#^/dev/[a-z0-9]+$#', $device) !== 1) {
                continue;
            }
            $ran = $this->shell->run(['/usr/sbin/smartctl', '-H', '-j', $device], 8);
            $smart = ['passed' => null, 'temp_c' => null];
            if ($ran !== null && $ran['out'] !== '') {
                $smart = HostReadings::smart($ran['out']);
            }
            $disks[] = [
                'device' => $device,
                'smart' => $smart['passed'] === null ? null : ($smart['passed'] ? 'passed' : 'failed'),
                'temp_c' => $smart['temp_c'],
                'source' => $ran === null ? null : 'smartctl',
            ];
        }

        return [$mounts, $disks];
    }

    /**
     * @return array<string, array{rx: int, tx: int}>
     */
    private function netCounters(): array
    {
        $text = $this->files->read('/proc/net/dev');
        $out = [];
        if ($text === null) {
            return $out;
        }
        foreach (explode("\n", $text) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $rest] = explode(':', $line, 2);
            $name = trim($name);
            if ($name === '' || $name === 'lo') {
                continue;
            }
            $nums = preg_split('/\s+/', trim($rest)) ?: [];
            if (!isset($nums[0], $nums[8]) || !ctype_digit($nums[0]) || !ctype_digit($nums[8])) {
                continue;
            }
            $out[$name] = ['rx' => (int) $nums[0], 'tx' => (int) $nums[8]];
        }

        return $out;
    }

    /**
     * @param array<string, array{rx: int, tx: int}> $before
     * @param array<string, array{rx: int, tx: int}> $after
     * @param array{at: float, ifaces: array<string, array{rx: int, tx: int}>}|null $previous
     * @return list<array<string, mixed>>
     */
    private function networkRates(array $before, array $after, float $dt, ?array $previous, float $now): array
    {
        $usePrevious = is_array($previous)
            && isset($previous['at'], $previous['ifaces'])
            && is_array($previous['ifaces'])
            && ($now - (float) $previous['at']) >= 30
            && ($now - (float) $previous['at']) <= 1200;
        $from = $usePrevious ? $previous['ifaces'] : $before;
        $span = $usePrevious ? ($now - (float) $previous['at']) : $dt;
        $source = $usePrevious ? 'proc_net_dev interval' : 'proc_net_dev';
        $out = [];
        foreach ($after as $name => $counters) {
            $rx = null;
            $tx = null;
            if (isset($from[$name]) && $span >= 0.05) {
                $rx = $this->rate($from[$name]['rx'], $counters['rx'], $span);
                $tx = $this->rate($from[$name]['tx'], $counters['tx'], $span);
            }
            $out[] = ['iface' => $name, 'rx_bps' => $rx, 'tx_bps' => $tx, 'source' => $source];
        }

        return $out;
    }

    private function rate(int $before, int $after, float $span): ?float
    {
        $delta = $after - $before;
        if ($delta < 0) {
            return null;
        }

        return $delta / $span;
    }

    /**
     * @return array<int, array{ticks: int, comm: string, rss: int}>
     */
    private function procTicks(): array
    {
        $out = [];
        foreach ($this->files->list('/proc') as $name) {
            if (preg_match('/^\d+$/', $name) !== 1) {
                continue;
            }
            $stat = $this->files->read('/proc/' . $name . '/stat');
            if ($stat === null) {
                continue;
            }
            $parsed = $this->stat($stat);
            if ($parsed === null) {
                continue;
            }
            $out[(int) $name] = $parsed;
        }

        return $out;
    }

    /**
     * @return array{ticks: int, comm: string, rss: int}|null
     */
    private function stat(string $text): ?array
    {
        $open = strpos($text, '(');
        $close = strrpos($text, ')');
        if ($open === false || $close === false || $close < $open) {
            return null;
        }
        $comm = substr($text, $open + 1, $close - $open - 1);
        $rest = preg_split('/\s+/', trim(substr($text, $close + 1))) ?: [];
        if (!isset($rest[11], $rest[12], $rest[21]) || !is_numeric($rest[11]) || !is_numeric($rest[12]) || !is_numeric($rest[21])) {
            return null;
        }

        return [
            'ticks' => (int) $rest[11] + (int) $rest[12],
            'comm' => $comm,
            'rss' => (int) $rest[21],
        ];
    }

    /**
     * @param array<int, array{ticks: int, comm: string, rss: int}> $before
     * @param array<int, array{ticks: int, comm: string, rss: int}> $after
     * @return array{count: ?int, top: list<array<string, mixed>>, cloudflared: ?bool}
     */
    private function processes(array $before, array $after, float $dt): array
    {
        $listed = $this->files->isDir('/proc');
        if (!$listed && $after === []) {
            return ['count' => null, 'top' => [], 'cloudflared' => null];
        }
        $cloudflared = false;
        $rows = [];
        $page = $this->pageSize();
        foreach ($after as $pid => $row) {
            if ($row['comm'] === 'cloudflared') {
                $cloudflared = true;
            }
            $cpu = null;
            if (isset($before[$pid]) && $dt >= 0.05) {
                $ticks = $row['ticks'] - $before[$pid]['ticks'];
                if ($ticks >= 0) {
                    $cpu = ($ticks / 100) / $dt * 100;
                }
            }
            $rows[] = [
                'pid' => $pid,
                'name' => $row['comm'],
                'cpu_percent' => $cpu,
                'rss_bytes' => $page === null ? null : $row['rss'] * $page,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $cpu = ($b['cpu_percent'] ?? -1) <=> ($a['cpu_percent'] ?? -1);
            if ($cpu !== 0) {
                return $cpu;
            }

            return ($b['rss_bytes'] ?? 0) <=> ($a['rss_bytes'] ?? 0);
        });

        return [
            'count' => count($after),
            'top' => array_slice($rows, 0, 5),
            'cloudflared' => $cloudflared,
        ];
    }

    private function pageSize(): ?int
    {
        if (function_exists('posix_sysconf') && defined('POSIX_SC_PAGESIZE')) {
            $size = posix_sysconf(POSIX_SC_PAGESIZE);
            if (is_int($size) && $size > 0) {
                return $size;
            }
        }

        return null;
    }

    /**
     * @return array{available: bool, units: list<array{unit: string, state: string}>}
     */
    private function failed(): array
    {
        $ran = $this->shell->run(['/usr/bin/systemctl', 'list-units', '--state=failed', '--no-legend', '--plain', '--no-pager'], 5);
        if ($ran === null || $ran['code'] !== 0) {
            return ['available' => false, 'units' => []];
        }
        $units = [];
        foreach (explode("\n", trim($ran['out'])) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '0 loaded')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (!isset($parts[0]) || !str_contains($parts[0], '.')) {
                continue;
            }
            $units[] = ['unit' => $parts[0], 'state' => $parts[3] ?? ($parts[2] ?? 'failed')];
        }

        return ['available' => true, 'units' => $units];
    }

    /**
     * @return array{known: bool, required: bool, packages: list<string>}
     */
    private function reboot(): array
    {
        $run = $this->files->isDir('/run');
        if (!$run && !$this->files->exists('/run/reboot-required')) {
            return ['known' => false, 'required' => false, 'packages' => []];
        }
        $required = $this->files->exists('/run/reboot-required');
        $packages = [];
        $text = $this->files->read('/run/reboot-required.pkgs');
        if ($text !== null) {
            foreach (explode("\n", $text) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $packages[] = $line;
                }
            }
        }

        return ['known' => $run || $required, 'required' => $required, 'packages' => $packages];
    }

    /**
     * @return array<string, mixed>
     */
    private function updates(): array
    {
        $ran = $this->shell->run(['/usr/bin/apt-get', '-s', '-o', 'Debug::NoLocking=true', 'upgrade'], 20);
        if ($ran === null || $ran['code'] !== 0) {
            return ['checked' => false, 'count' => null, 'security_count' => null, 'packages' => [], 'source' => 'apt-get -s upgrade'];
        }
        $packages = HostReadings::apt($ran['out']);
        $security = 0;
        foreach ($packages as $package) {
            if ($package['security']) {
                $security++;
            }
        }

        return [
            'checked' => true,
            'count' => count($packages),
            'security_count' => $security,
            'packages' => $packages,
            'source' => 'apt-get -s upgrade',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function tunnel(array $options, ?bool $process): array
    {
        $show = $this->shell->run([
            '/usr/bin/systemctl', 'show', 'cloudflared',
            '--property=LoadState', '--property=ActiveState', '--property=SubState', '--no-pager',
        ], 5);
        $props = [];
        if ($show !== null && $show['code'] === 0) {
            foreach (explode("\n", $show['out']) as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $props[trim($key)] = trim($value);
                }
            }
        }
        $active = $show === null ? null : HostReadings::serviceActive($props);
        $service = [
            'unit' => 'cloudflared',
            'active' => $active,
            'state' => $props['ActiveState'] ?? null,
            'sub' => $props['SubState'] ?? null,
            'process' => $process,
        ];
        $metricsUrl = trim((string) ($options['tunnel_metrics_url'] ?? ''));
        $connector = ['available' => false, 'connections' => null, 'state' => null, 'source' => $metricsUrl !== '' ? $metricsUrl : null];
        if ($metricsUrl !== '') {
            $metrics = $this->http->get($metricsUrl, 2.0, false);
            if ($metrics['status'] === 200 && $metrics['body'] !== '') {
                $connections = HostReadings::prometheus($metrics['body'], 'cloudflared_tunnel_ha_connections');
                $connector['available'] = $connections !== null;
                $connector['connections'] = $connections;
                $connector['state'] = $connections === null ? null : ($connections >= 4 ? 'connected' : ($connections > 0 ? 'partial' : 'disconnected'));
            }
        }
        $hostnames = is_array($options['tunnel_hostnames'] ?? null) ? $options['tunnel_hostnames'] : [];
        $probes = [];
        foreach ($hostnames as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $probes[] = $this->probeUrl($url);
        }
        $alert = HostReadings::tunnelAlert(
            ['active' => $active, 'process' => $process],
            ['available' => $connector['available'], 'connections' => $connector['connections']],
            $probes,
            $probes !== []
        );

        return [
            'name' => (string) ($options['tunnel_name'] ?? ''),
            'alert' => $alert,
            'service' => $service,
            'connector' => $connector,
            'probes' => $probes,
        ];
    }

    /**
     * @return array{url: string, status: ?int, latency_ms: ?float, reachable: bool, error: ?string}
     */
    private function probeUrl(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $addresses = $this->addresses($host);
        if ($addresses !== null) {
            foreach ($addresses as $address) {
                if (!HostReadings::publicIp($address)) {
                    return ['url' => $url, 'status' => null, 'latency_ms' => null, 'reachable' => false, 'error' => 'Refusing a non-public address.'];
                }
            }
        }
        $res = $this->http->get($url, 4.0, true);
        $status = $res['status'];
        $reachable = is_int($status) && $status >= 100 && $status < 500;

        return [
            'url' => $url,
            'status' => $status,
            'latency_ms' => $reachable || $status !== null ? $res['latency_ms'] : null,
            'reachable' => $reachable,
            'error' => $res['error'],
        ];
    }

    /**
     * @return list<string>|null
     */
    private function addresses(string $host): ?array
    {
        if ($host === '') {
            return [];
        }
        if ($this->resolve !== null) {
            return ($this->resolve)($host);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $list = @gethostbynamel($host);

        return is_array($list) ? $list : null;
    }

    private function line(string $path): ?string
    {
        $text = $this->files->read($path);
        if ($text === null) {
            return null;
        }
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    private function num(?string $raw, float $scale = 1.0): ?float
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return ((float) $raw) * $scale;
    }

    private function int(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }
}
