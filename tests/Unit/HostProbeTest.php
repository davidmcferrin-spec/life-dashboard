<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Host\HostFiles;
use Dashboard\Host\HostHttp;
use Dashboard\Host\HostProbe;
use Dashboard\Host\HostReadings;
use Dashboard\Host\HostShell;
use Dashboard\Support\ForbiddenException;
use PHPUnit\Framework\TestCase;

final class HostProbeTest extends TestCase
{
    public function testParsersAndSettingsRejectGuesses(): void
    {
        $apt = HostReadings::apt("Inst openssl [3.0] (3.1 Ubuntu:26.04/noble-security [amd64])\nInst bash [5] (5.1 Ubuntu:26.04/noble-updates [amd64])\n");
        self::assertTrue($apt[0]['security']);
        self::assertFalse($apt[1]['security']);
        self::assertSame(4.0, HostReadings::prometheus("cloudflared_tunnel_ha_connections 4\n", 'cloudflared_tunnel_ha_connections'));
        self::assertNull(HostReadings::cpuPercent(['total' => 10, 'idle' => 10], ['total' => 10, 'idle' => 10]));
        self::assertTrue(HostReadings::isUps('ups'));
        self::assertTrue(HostReadings::isUps('hid', 'UPS'));
        self::assertFalse(HostReadings::isUps('amdgpu'));
        self::assertSame('/dev/nvme0n1', HostReadings::diskOf('/dev/nvme0n1p2'));

        $points = [
            ['at' => 0, 'value' => 1.0],
            ['at' => 100, 'value' => 3.0],
            ['at' => 400, 'value' => 10.0],
        ];
        $buckets = HostReadings::downsample($points, 300);
        self::assertSame(2.0, $buckets[0]['value']);
        self::assertSame(10.0, $buckets[1]['value']);

        try {
            HostReadings::publicHttps('https://127.0.0.1/secret');
            self::fail('A loopback probe must be rejected.');
        } catch (\InvalidArgumentException) {
        }
        try {
            HostReadings::metricsUrl('http://10.0.0.5:20241/metrics');
            self::fail('Metrics must stay on loopback.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('http://127.0.0.1:20241/metrics', HostReadings::metricsUrl('http://127.0.0.1:20241/metrics'));

        $down = HostReadings::tunnelAlert(
            ['active' => false, 'process' => false],
            ['available' => true, 'connections' => 0.0],
            [['reachable' => false]],
            true
        );
        self::assertSame('down', $down);
        $degraded = HostReadings::tunnelAlert(
            ['active' => true, 'process' => true],
            ['available' => true, 'connections' => 2.0],
            [['reachable' => true], ['reachable' => true]],
            true
        );
        self::assertSame('degraded', $degraded);
        $up = HostReadings::tunnelAlert(
            ['active' => true, 'process' => true],
            ['available' => false, 'connections' => null],
            [['reachable' => true]],
            true
        );
        self::assertSame('up', $up);

        $allow = Scope::auditEntityAllow(['system:read'], null);
        self::assertContains('host', $allow);
        $this->expectException(ForbiddenException::class);
        Scope::auditEntityAllow(['metrics:read'], 'host');
    }

    public function testSnapshotUsesOnlyExposedSensors(): void
    {
        $root = sys_get_temp_dir() . '/lifedash-host-' . bin2hex(random_bytes(4));
        $this->tree($root);
        $times = [1000.0, 1000.2];
        $probe = new HostProbe(
            new HostFiles($root, [
                '/proc/stat' => [
                    "cpu  100 0 100 800 0 0 0 0 0 0\ncpu0 100 0 100 800 0 0 0 0 0 0\n",
                    "cpu  150 0 150 900 0 0 0 0 0 0\ncpu0 150 0 150 900 0 0 0 0 0 0\n",
                ],
                '/proc/net/dev' => [
                    " lo: 10 0 0 0 0 0 0 0 10 0 0 0 0 0 0 0\n eth0: 1000 0 0 0 0 0 0 0 2000 0 0 0 0 0 0 0\n",
                    " lo: 10 0 0 0 0 0 0 0 10 0 0 0 0 0 0 0\n eth0: 3000 0 0 0 0 0 0 0 4000 0 0 0 0 0 0 0\n",
                ],
                '/sys/class/powercap/intel-rapl/intel-rapl:0/energy_uj' => ["1000000\n", "6000000\n"],
            ], ['/' => ['total' => 1000, 'free' => 400]]),
            new HostShell(function (array $argv): ?array {
                $bin = $argv[0];
                if (str_contains($bin, 'smartctl')) {
                    return ['code' => 0, 'out' => '{"smart_status":{"passed":true},"temperature":{"current":40}}', 'err' => ''];
                }
                if (str_contains($bin, 'apt-get')) {
                    return ['code' => 0, 'out' => "Inst openssl [3.0] (3.1 Ubuntu:26.04/noble-security [amd64])\nInst bash [5] (5.1 Ubuntu:26.04/noble-updates [amd64])\n", 'err' => ''];
                }
                if (in_array('cloudflared', $argv, true)) {
                    return ['code' => 0, 'out' => "LoadState=loaded\nActiveState=active\nSubState=running\n", 'err' => ''];
                }
                return ['code' => 0, 'out' => "nginx.service loaded failed failed Nginx\n", 'err' => ''];
            }),
            new HostHttp(function (string $url, bool $follow): array {
                if (str_contains($url, '20241')) {
                    return ['status' => 200, 'latency_ms' => 2.0, 'error' => null, 'body' => "cloudflared_tunnel_ha_connections 4\n"];
                }
                $status = str_contains($url, 'mcp.') ? 401 : 200;

                return ['status' => $status, 'latency_ms' => 30.0, 'error' => null, 'body' => ''];
            }),
            function () use (&$times): float {
                return count($times) > 1 ? (float) array_shift($times) : (float) $times[0];
            },
            fn (string $host): array => ['1.1.1.1'],
            0,
        );

        $snap = $probe->capture(HostReadings::defaults());
        self::assertSame('AMD Ryzen AI 9 HX 370', $snap['cpu']['model']);
        self::assertSame([0.4, 0.3, 0.2], $snap['cpu']['load']);
        self::assertEqualsWithDelta(50.0, $snap['cpu']['package_percent'], 0.01);
        self::assertEqualsWithDelta(4200.0, $snap['cpu']['cores'][0]['mhz'], 0.01);
        self::assertSame('Radeon 890M', $snap['gpus'][0]['model']);
        self::assertSame('amdgpu', $snap['gpus'][0]['driver']);
        self::assertEqualsWithDelta(33.0, $snap['gpus'][0]['util_percent'], 0.01);
        self::assertEqualsWithDelta(2000.0, $snap['gpus'][0]['clock_mhz'], 0.01);
        self::assertEqualsWithDelta(8.0, $snap['gpus'][0]['power_watts'], 0.01);
        $labels = array_column($snap['temperatures'], 'label');
        self::assertContains('k10temp Tctl', $labels);
        self::assertContains('nvme Composite', $labels);
        self::assertNotContains('ups temp1', $labels);
        $powerIds = array_column($snap['power'], 'id');
        self::assertContains('rapl:package-0', $powerIds);
        self::assertContains('supply:BAT0', $powerIds);
        self::assertNotContains('supply:ups1', $powerIds);
        foreach ($powerIds as $id) {
            self::assertStringNotContainsString('ups', $id);
        }
        self::assertEqualsWithDelta(25.0, $snap['power'][array_search('rapl:package-0', $powerIds, true)]['watts'], 0.01);
        $volts = array_column($snap['voltage'], 'id');
        self::assertContains('amdgpu:vddgfx', $volts);
        self::assertContains('supply:BAT0:voltage', $volts);
        self::assertNotContains('ups:in0', $volts);
        self::assertEqualsWithDelta(50.0, $snap['memory']['used_percent'], 0.01);
        self::assertEqualsWithDelta(60.0, $snap['mounts'][0]['used_percent'], 0.01);
        self::assertSame('passed', $snap['disks'][0]['smart']);
        self::assertEqualsWithDelta(40.0, $snap['disks'][0]['temp_c'], 0.01);
        self::assertEqualsWithDelta(10000.0, $snap['network'][0]['rx_bps'], 0.01);
        self::assertSame('eth0', $snap['network'][0]['iface']);
        self::assertSame(2, $snap['updates']['count']);
        self::assertSame(1, $snap['updates']['security_count']);
        self::assertTrue($snap['reboot_required']['required']);
        self::assertSame(['linux-image'], $snap['reboot_required']['packages']);
        self::assertSame('nginx.service', $snap['failed_services']['units'][0]['unit']);
        self::assertSame(2, $snap['processes']['count']);
        self::assertSame('up', $snap['tunnel']['alert']);
        self::assertSame(4.0, $snap['tunnel']['connector']['connections']);
        self::assertSame(401, $snap['tunnel']['probes'][0]['status']);
        self::assertTrue($snap['tunnel']['probes'][0]['reachable']);
        self::assertSame(3000, $snap['_counters']['ifaces']['eth0']['rx']);
        $series = HostReadings::points($snap);
        $metrics = array_column($series, 'metric');
        self::assertContains('power.w', $metrics);
        self::assertContains('tunnel.latency_ms', $metrics);
        self::assertContains('tunnel.up', $metrics);
        foreach ($series as $point) {
            self::assertTrue(is_finite($point['value']));
            self::assertStringNotContainsString('ups', $point['subject']);
        }

        $flatClock = new HostProbe(
            new HostFiles($root, [
                '/sys/class/powercap/intel-rapl/intel-rapl:0/energy_uj' => "1000000\n",
            ]),
            new HostShell(fn (array $argv): ?array => null),
            new HostHttp(fn (string $url, bool $follow): array => ['status' => null, 'latency_ms' => null, 'error' => 'down', 'body' => '']),
            fn (): float => 50.0,
            fn (string $host): array => ['1.1.1.1'],
            0,
        );
        $still = $flatClock->capture(HostReadings::defaults());
        self::assertNotContains('rapl:package-0', array_column($still['power'], 'id'));
        unset($flat);
    }

    private function tree(string $root): void
    {
        $files = [
            '/proc/loadavg' => "0.40 0.30 0.20 1/100 1\n",
            '/proc/cpuinfo' => "model name\t: AMD Ryzen AI 9 HX 370\n",
            '/proc/meminfo' => "MemTotal: 32000000 kB\nMemAvailable: 16000000 kB\nSwapTotal: 8000000 kB\nSwapFree: 8000000 kB\n",
            '/proc/uptime' => "3661.5 0\n",
            '/proc/mounts' => "/dev/nvme0n1p2 / ext4 rw 0 0\n",
            '/proc/sys/kernel/osrelease' => "6.12.0\n",
            '/proc/sys/kernel/hostname' => "ai-vault\n",
            '/proc/sys/kernel/arch' => "x86_64\n",
            '/etc/os-release' => "PRETTY_NAME=\"Ubuntu 26.04 LTS\"\n",
            '/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq' => "4200000\n",
            '/sys/class/hwmon/hwmon0/name' => "k10temp\n",
            '/sys/class/hwmon/hwmon0/temp1_input' => "45000\n",
            '/sys/class/hwmon/hwmon0/temp1_label' => "Tctl\n",
            '/sys/class/hwmon/hwmon1/name' => "ups\n",
            '/sys/class/hwmon/hwmon1/temp1_input' => "30000\n",
            '/sys/class/hwmon/hwmon1/temp1_label' => "temp1\n",
            '/sys/class/hwmon/hwmon1/power1_input' => "50000000\n",
            '/sys/class/hwmon/hwmon1/in0_input' => "12000\n",
            '/sys/class/hwmon/hwmon1/in0_label' => "in0\n",
            '/sys/class/hwmon/hwmon2/name' => "amdgpu\n",
            '/sys/class/hwmon/hwmon2/power1_input' => "8000000\n",
            '/sys/class/hwmon/hwmon2/power1_label' => "power1\n",
            '/sys/class/hwmon/hwmon2/in0_input' => "850\n",
            '/sys/class/hwmon/hwmon2/in0_label' => "vddgfx\n",
            '/sys/class/hwmon/hwmon2/temp1_input' => "42000\n",
            '/sys/class/hwmon/hwmon2/temp1_label' => "edge\n",
            '/sys/class/hwmon/hwmon2/fan1_input' => "1200\n",
            '/sys/class/hwmon/hwmon3/name' => "nvme\n",
            '/sys/class/hwmon/hwmon3/temp1_input' => "38000\n",
            '/sys/class/hwmon/hwmon3/temp1_label' => "Composite\n",
            '/sys/class/powercap/intel-rapl/intel-rapl:0/name' => "package-0\n",
            '/sys/class/power_supply/BAT0/type' => "Battery\n",
            '/sys/class/power_supply/BAT0/power_now' => "5000000\n",
            '/sys/class/power_supply/BAT0/voltage_now' => "12000000\n",
            '/sys/class/power_supply/ups1/type' => "UPS\n",
            '/sys/class/power_supply/ups1/power_now' => "40000000\n",
            '/sys/class/power_supply/ups1/voltage_now' => "24000000\n",
            '/sys/class/drm/card0/device/vendor' => "0x1002\n",
            '/sys/class/drm/card0/device/device' => "0x150e\n",
            '/sys/class/drm/card0/device/product_name' => "Radeon 890M\n",
            '/sys/class/drm/card0/device/driver' => "amdgpu\n",
            '/sys/class/drm/card0/device/gpu_busy_percent' => "33\n",
            '/sys/class/drm/card0/device/mem_info_vram_used' => "1073741824\n",
            '/sys/class/drm/card0/device/mem_info_vram_total' => "2147483648\n",
            '/sys/class/drm/card0/device/hwmon/hwmon9/freq1_input' => "2000000000\n",
            '/sys/class/drm/card0/device/hwmon/hwmon9/power1_input' => "8000000\n",
            '/sys/module/amdgpu/version' => "6.12.0\n",
            '/run/reboot-required' => "\n",
            '/run/reboot-required.pkgs' => "linux-image\n",
            '/proc/1/stat' => "1 (systemd) S 0 0 0 0 0 0 0 0 0 0 10 10 0 0 20 0 1 0 100 400 100\n",
            '/proc/12/stat' => "12 (cloudflared) S 0 0 0 0 0 0 0 0 0 0 1 1 0 0 20 0 1 0 1 1 1\n",
        ];
        foreach ($files as $path => $contents) {
            $full = $root . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }
    }
}
