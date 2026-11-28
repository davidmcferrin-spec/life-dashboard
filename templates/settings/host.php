<?php
/**
 * Settings → System. Live readings from this machine, plus the tunnel.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $host
 * @var array<string, mixed> $hostOptions
 */

use Dashboard\Host\HostReadings;

$pageTitle = 'System';
$active = 'host';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsWide = true;
$settingsMainClass = 'settings-main host-settings-main';
$settingsFormId = 'host-options-form';
$settingsSubmitLabel = 'Save';

$sensors = HostReadings::sensors($host);
$hidden = is_array($hostOptions['hidden_sensors'] ?? null) ? $hostOptions['hidden_sensors'] : [];
$hostnames = is_array($hostOptions['tunnel_hostnames'] ?? null) ? $hostOptions['tunnel_hostnames'] : [];
$boot = [
    'snapshot' => $host,
    'catalog' => HostReadings::METRICS,
    'options' => $hostOptions,
];

if (!function_exists('host_icon')) {
    function host_icon(string $name): void
    {
        $path = __DIR__ . '/../../public/assets/vendor/lucide/' . $name . '.svg';
        if (is_file($path)) {
            readfile($path);
        }
    }
}

require __DIR__ . '/_shell-top.php';
?>

            <?php $pageEyebrow = 'Administration'; $pageHeading = 'System'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead host-lead">Readings from this machine: the Minisforum running Ubuntu. Power and voltage come from RAPL and hwmon on the host. UPS sensors are left out. A sensor the hardware does not expose is shown as unavailable.</p>
            <div class="host-toolbar">
                <p class="host-sub" id="host-sampled">Sampled <?= e((string) ($host['sampled_at'] ?? '')) ?></p>
                <button type="button" class="btn" id="host-refresh">Refresh</button>
            </div>
            <div id="host-alert" class="host-alert" hidden></div>
            <div class="host-grid" id="host-grid">
                <?php
                $cards = [
                    ['cpu', 'CPU', 'gauge'],
                    ['gpu', 'GPU', 'monitor'],
                    ['temp', 'Temperatures', 'flame'],
                    ['mem', 'Memory', 'server'],
                    ['power', 'Power and voltage', 'gauge'],
                    ['disk', 'Storage', 'disc-3'],
                    ['net', 'Network', 'wifi'],
                    ['proc', 'Processes', 'list-checks'],
                    ['os', 'Operating system', 'shield-check'],
                ];
                foreach ($cards as [$id, $label, $icon]):
                ?>
                <section class="host-card" id="host-card-<?= e($id) ?>">
                    <h2><span class="ld-w-icon" aria-hidden="true"><?php host_icon($icon); ?></span><?= e($label) ?></h2>
                    <div class="host-body" data-host-card="<?= e($id) ?>"></div>
                </section>
                <?php endforeach; ?>
                <section class="host-card host-span" id="host-card-tunnel">
                    <h2><span class="ld-w-icon" aria-hidden="true"><?php host_icon('globe-2'); ?></span>Cloudflare tunnel</h2>
                    <div class="host-body" data-host-card="tunnel"></div>
                </section>
                <section class="host-card host-span" id="host-card-chart">
                    <h2><span class="ld-w-icon" aria-hidden="true"><?php host_icon('activity'); ?></span>History</h2>
                    <div class="host-controls">
                        <label>Metric
                            <select id="host-metric" class="input"></select>
                        </label>
                        <label id="host-subject-label">Sensor
                            <select id="host-subject" class="input"></select>
                        </label>
                        <div class="fin-ranges" role="tablist" aria-label="Time range">
                            <?php foreach (['6h' => '6h', '24h' => '24h', '7d' => '7d', '30d' => '30d', '90d' => '90d'] as $range => $label): ?>
                            <button type="button" class="fin-range" data-range="<?= e($range) ?>" aria-selected="<?= $range === '24h' ? 'true' : 'false' ?>"><?= e($label) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="host-chart" class="fin-chart"></div>
                    <p class="host-note">Samples are taken every <?= e((string) ($hostOptions['sample_interval_minutes'] ?? 5)) ?> minutes and kept for <?= e((string) ($hostOptions['retention_days'] ?? 90)) ?> days. Longer ranges are averaged into fewer points.</p>
                </section>
            </div>
            <p class="host-note">CPU load, per-core use, and frequency come from /proc and cpufreq. GPU use, VRAM, clock, and power come from the drm device when the kernel exposes them. Package power is the RAPL energy counter over a short window. Temperatures, fans, and voltages are hwmon, and battery sensors are included when present. Memory is /proc/meminfo. Disk use is the mounted filesystem. SMART is smartctl when it can read the disk. Network rates are byte counters. Pending packages are apt's simulated upgrade. The tunnel row is systemd, the cloudflared metrics port, and an HTTPS request to each saved hostname.</p>

            <form id="host-options-form" method="post" action="/settings/host" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <section class="settings-block">
                    <h2 class="section-title">Collector</h2>
                    <p class="text-muted text-sm">The timer wakes every 5 minutes. A sample is stored when this interval has passed. Rows older than the retention are deleted on that run.</p>
                    <div class="settings-field">
                        <label class="settings-label" for="host-interval">Sample interval (minutes)</label>
                        <input class="input" id="host-interval" name="sample_interval_minutes" type="number" min="5" max="120" step="1" value="<?= e((string) ($hostOptions['sample_interval_minutes'] ?? 5)) ?>">
                    </div>
                    <div class="settings-field">
                        <label class="settings-label" for="host-retention">Retention (days)</label>
                        <input class="input" id="host-retention" name="retention_days" type="number" min="7" max="365" step="1" value="<?= e((string) ($hostOptions['retention_days'] ?? 90)) ?>">
                    </div>
                    <fieldset class="homelab-scopes">
                        <legend class="settings-label">Sensors to show</legend>
                        <div class="host-sensors">
                            <?php foreach ($sensors as $sensor): ?>
                            <input type="hidden" name="known[]" value="<?= e($sensor['id']) ?>">
                            <label class="flex items-center gap-2 text-sm text-primary">
                                <input type="checkbox" name="shown[]" value="<?= e($sensor['id']) ?>"<?= in_array($sensor['id'], $hidden, true) ? '' : ' checked' ?>>
                                <span><?= e($sensor['group'] . ' · ' . $sensor['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                            <?php if ($sensors === []): ?>
                            <p class="host-missing">No sensors in this reading. They appear here after the host exposes them.</p>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                </section>
                <section class="settings-block">
                    <h2 class="section-title">Tunnel</h2>
                    <p class="text-muted text-sm">The name, public hostnames, and the loopback metrics URL are saved here. Nothing is read from an environment file.</p>
                    <div class="settings-field">
                        <label class="settings-label" for="host-tunnel-name">Tunnel name</label>
                        <input class="input" id="host-tunnel-name" name="tunnel_name" maxlength="80" value="<?= e((string) ($hostOptions['tunnel_name'] ?? '')) ?>">
                    </div>
                    <div class="settings-field">
                        <label class="settings-label" for="host-tunnel-hosts">Public hostnames</label>
                        <textarea class="input" id="host-tunnel-hosts" name="tunnel_hostnames" rows="3"><?= e(implode("\n", $hostnames)) ?></textarea>
                    </div>
                    <div class="settings-field">
                        <label class="settings-label" for="host-tunnel-metrics">Metrics URL</label>
                        <input class="input" id="host-tunnel-metrics" name="tunnel_metrics_url" value="<?= e((string) ($hostOptions['tunnel_metrics_url'] ?? '')) ?>" placeholder="http://127.0.0.1:20241/metrics">
                    </div>
                </section>
            </form>
            <script type="application/json" id="host-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/settings-host.js?v=<?= e($assetVer) ?>"></script>
<?php require __DIR__ . '/_shell-bottom.php'; ?>
