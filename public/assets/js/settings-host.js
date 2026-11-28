/**
 * Settings → System. Paints the live reading and the history chart.
 */
'use strict';

(function () {
    const bootNode = document.getElementById('host-boot');
    const grid = document.getElementById('host-grid');
    if (!bootNode || !grid) return;

    // Cards and the metric list come from the boot JSON. History requests
    // use LD when lifedash-api.js has loaded. A missing client must not
    // leave the cards blank.
    function esc(value) {
        if (window.LD && window.LD.esc) return window.LD.esc(value);
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function num(value, digits) {
        if (window.LD && window.LD.fmt) return window.LD.fmt.number(value, digits);
        if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
        const d = Number.isInteger(digits) ? digits : 1;
        return Number(value).toLocaleString(undefined, { maximumFractionDigits: d, minimumFractionDigits: 0 });
    }

    function duration(seconds) {
        if (window.LD && window.LD.fmt) return window.LD.fmt.duration(seconds);
        if (seconds === null || seconds === undefined) return '—';
        const s = Math.max(0, Math.round(Number(seconds)));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        if (h === 0 && m === 0) return s + 's';
        return h > 0 ? h + 'h ' + String(m).padStart(2, '0') + 'm' : m + 'm';
    }
    let boot = {};
    try {
        boot = JSON.parse(bootNode.textContent || '{}');
    } catch (err) {
        boot = {};
    }
    let snap = boot.snapshot || {};
    const catalog = boot.catalog || {};
    let options = boot.options || {};
    let metric = 'cpu.load1';
    let subject = '';
    let range = '24h';
    let historyRequest = 0;

    function hidden(id) {
        const list = options.hidden_sensors || [];
        return list.indexOf(id) !== -1;
    }

    function missing(text) {
        return '<p class="host-missing">' + esc(text) + '</p>';
    }

    function row(label, value) {
        return '<div class="platform-row"><span>' + esc(label) + '</span><span class="mono">' + value + '</span></div>';
    }

    function shown(value, digits, suffix) {
        if (value === null || value === undefined || Number.isNaN(Number(value))) return 'unavailable';
        const text = num(value, digits);
        return esc(suffix ? text + suffix : text);
    }

    function bytes(value) {
        if (value === null || value === undefined) return 'unavailable';
        const n = Number(value);
        if (!Number.isFinite(n)) return 'unavailable';
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let size = n;
        let i = 0;
        while (size >= 1024 && i < units.length - 1) {
            size /= 1024;
            i++;
        }
        return esc(num(size, size >= 10 || i === 0 ? 0 : 1) + ' ' + units[i]);
    }

    function rate(value) {
        if (value === null || value === undefined) return 'unavailable';
        const n = Number(value);
        if (!Number.isFinite(n)) return 'unavailable';
        if (n >= 1048576) return esc(num(n / 1048576, 1) + ' MiB/s');
        if (n >= 1024) return esc(num(n / 1024, 1) + ' KiB/s');
        return esc(num(n, 0) + ' B/s');
    }

    function table(headers, rows) {
        if (!rows.length) return '';
        return '<div class="host-scroll"><table class="host-table"><thead><tr>'
            + headers.map((header) => '<th>' + esc(header) + '</th>').join('')
            + '</tr></thead><tbody>'
            + rows.join('')
            + '</tbody></table></div>';
    }

    function paintAlert() {
        const banner = document.getElementById('host-alert');
        if (!banner) return;
        const tunnel = snap.tunnel || {};
        const alert = tunnel.alert || 'unknown';
        const reboot = snap.reboot_required || {};
        const failed = snap.failed_services || {};
        const units = failed.available ? (failed.units || []).filter((unit) => unit && unit.unit) : [];
        const messages = [];
        if (alert === 'down') messages.push('Tunnel down.');
        else if (alert === 'degraded') messages.push('Tunnel degraded.');
        if (reboot.known && reboot.required) messages.push('Reboot required.');
        banner.hidden = messages.length === 0 && units.length === 0;
        banner.className = 'host-alert' + (alert === 'down' || reboot.required || units.length ? ' is-down' : (alert === 'degraded' ? ' is-degraded' : ''));
        let html = messages.length ? '<p>' + esc(messages.join(' ')) + '</p>' : '';
        if (units.length) {
            html += '<ul class="host-alert-units">' + units.map((unit) => {
                const state = unit.state ? ' (' + unit.state + ')' : '';
                return '<li>Failed service: ' + esc(unit.unit) + esc(state) + '</li>';
            }).join('') + '</ul>';
        }
        banner.innerHTML = html;
    }

    function paintCpu() {
        const cpu = snap.cpu || {};
        const cores = (cpu.cores || []).map((core) => '<tr><td>' + esc(String(core.id)) + '</td><td>' + shown(core.percent, 0, '%') + '</td><td>' + shown(core.mhz, 0, ' MHz') + '</td></tr>');
        return '<p class="host-sub">' + esc(cpu.model || 'Model unavailable') + '</p>'
            + '<p class="host-value">' + (cpu.load ? shown(cpu.load[0], 2) : 'unavailable') + '</p>'
            + '<p class="host-sub">Load ' + (cpu.load ? [shown(cpu.load[0], 2), shown(cpu.load[1], 2), shown(cpu.load[2], 2)].join(' ') : 'unavailable')
            + ' · all cores ' + shown(cpu.package_percent, 0, '%') + '</p>'
            + table(['Core', 'Use', 'Frequency'], cores);
    }

    function paintGpu() {
        const gpus = snap.gpus || [];
        if (!gpus.length) return missing('No GPU device in /sys/class/drm.');
        return gpus.map((gpu) => {
            const vram = gpu.vram_used_bytes == null || gpu.vram_total_bytes == null
                ? 'unavailable'
                : bytes(gpu.vram_used_bytes) + ' / ' + bytes(gpu.vram_total_bytes);
            return '<p class="host-sub">' + esc(gpu.model || 'Model unavailable') + (gpu.pci ? ' · PCI ' + esc(gpu.pci) : '') + '</p>'
                + '<p class="host-value">' + shown(gpu.util_percent, 0, '%') + '</p>'
                + row('Driver', esc(gpu.driver || 'unavailable') + (gpu.driver_version ? ' ' + esc(gpu.driver_version) : ''))
                + row('VRAM', vram)
                + row('Clock', shown(gpu.clock_mhz, 0, ' MHz'))
                + row('Power', shown(gpu.power_watts, 1, ' W'));
        }).join('');
    }

    function sensorRows(rows, field, digits, suffix) {
        return (rows || []).filter((row) => !hidden(row.id)).map((row) => '<tr><td>' + esc(row.label || row.id) + '</td><td>' + shown(row[field], digits, suffix) + '</td></tr>');
    }

    function paintTemp() {
        const temps = snap.temperatures || [];
        const kinds = { cpu: 'CPU package', gpu: 'GPU', nvme: 'NVMe' };
        const notes = Object.keys(kinds).filter((kind) => !temps.some((row) => row.kind === kind)).map((kind) => kinds[kind] + ' unavailable');
        const rows = sensorRows(temps, 'celsius', 1, ' °C');
        const fans = sensorRows(snap.fans || [], 'rpm', 0, ' rpm');
        return (notes.length ? '<p class="host-sub">' + esc(notes.join(' · ')) + '</p>' : '')
            + (rows.length ? table(['Sensor', 'Temperature'], rows) : missing('No other temperature sensors.'))
            + (fans.length ? table(['Fan', 'Speed'], fans) : '');
    }

    function paintMem() {
        const mem = snap.memory || {};
        return '<p class="host-value">' + shown(mem.used_percent, 0, '%') + '</p>'
            + row('Used', bytes(mem.used_bytes))
            + row('Total', bytes(mem.total_bytes))
            + row('Swap', mem.swap_total_bytes ? shown(mem.swap_used_percent, 0, '%') + ' of ' + bytes(mem.swap_total_bytes) : 'unavailable');
    }

    function paintPower() {
        const power = sensorRows(snap.power || [], 'watts', 1, ' W');
        const voltage = sensorRows(snap.voltage || [], 'volts', 2, ' V');
        return (power.length ? table(['Power', 'Watts'], power) : missing('Package power unavailable.'))
            + (voltage.length ? table(['Voltage', 'Volts'], voltage) : missing('Voltage sensors unavailable.'));
    }

    function paintDisk() {
        const mounts = (snap.mounts || []).map((mount) => '<tr><td>' + esc(mount.mount) + '</td><td>' + shown(mount.used_percent, 0, '%') + '</td><td class="host-hide-sm">' + bytes(mount.used_bytes) + ' / ' + bytes(mount.total_bytes) + '</td></tr>');
        const disks = (snap.disks || []).map((disk) => '<tr><td>' + esc(disk.device) + '</td><td>' + esc(disk.smart || 'unavailable') + '</td><td>' + shown(disk.temp_c, 0, ' °C') + '</td></tr>');
        return (mounts.length ? table(['Mount', 'Used', 'Space'], mounts) : missing('No data mounts.'))
            + (disks.length ? table(['Disk', 'SMART', 'Temp'], disks) : missing('SMART unavailable.'));
    }

    function paintNet() {
        const rows = (snap.network || []).map((iface) => '<tr><td>' + esc(iface.iface) + '</td><td>' + rate(iface.rx_bps) + '</td><td>' + rate(iface.tx_bps) + '</td></tr>');
        return rows.length ? table(['Interface', 'In', 'Out'], rows) : missing('No interfaces in /proc/net/dev.');
    }

    function paintProc() {
        const proc = snap.processes || {};
        const failed = snap.failed_services || {};
        const top = (proc.top || []).map((row) => '<tr><td>' + esc(String(row.pid)) + '</td><td>' + esc(row.name || '') + '</td><td>' + shown(row.cpu_percent, 0, '%') + '</td><td class="host-hide-sm">' + bytes(row.rss_bytes) + '</td></tr>');
        const units = failed.available
            ? ((failed.units || []).length ? (failed.units || []).map((unit) => '<li>' + esc(unit.unit) + '</li>').join('') : '<li>No failed units.</li>')
            : '<li>unavailable</li>';
        return '<p class="host-value">' + (proc.count == null ? 'unavailable' : esc(String(proc.count))) + '</p>'
            + '<p class="host-sub">Processes</p>'
            + table(['PID', 'Command', 'CPU', 'RSS'], top)
            + '<p class="host-sub">Failed units</p><ul class="homelab-list">' + units + '</ul>';
    }

    function paintOs() {
        const host = snap.host || {};
        const updates = snap.updates || {};
        const reboot = snap.reboot_required || {};
        const when = host.boot_time ? esc(host.boot_time) : 'unavailable';
        let packages = missing('Pending updates unavailable.');
        if (updates.checked) {
            const rows = (updates.packages || []).map((pkg) => '<tr><td>' + esc(pkg.name) + '</td><td>' + esc(pkg.to || '') + '</td><td>' + (pkg.security ? '<span class="host-flag is-security">security</span>' : '') + '</td></tr>');
            packages = '<p class="host-sub">' + esc(String(updates.count)) + ' pending, ' + esc(String(updates.security_count)) + ' security</p>'
                + (rows.length ? table(['Package', 'Version', ''], rows) : '<p class="host-sub">No pending packages.</p>');
        }
        const rebootText = !reboot.known ? 'unavailable' : (reboot.required ? 'yes' + ((reboot.packages || []).length ? ' (' + (reboot.packages || []).join(', ') + ')' : '') : 'no');
        return '<p class="host-sub">' + esc(host.os || 'OS unavailable') + '</p>'
            + row('Kernel', esc(host.kernel || 'unavailable'))
            + row('Host', esc(host.hostname || 'unavailable'))
            + row('Uptime', host.uptime_seconds == null ? 'unavailable' : esc(duration(host.uptime_seconds)))
            + row('Boot', when)
            + row('Reboot required', esc(rebootText))
            + packages;
    }

    function paintTunnel() {
        const tunnel = snap.tunnel || {};
        const service = tunnel.service || {};
        const connector = tunnel.connector || {};
        const state = service.active === true ? 'running' : (service.active === false ? 'stopped' : 'unavailable');
        const process = service.process === true ? 'running' : (service.process === false ? 'not seen' : 'unavailable');
        const connections = connector.available ? shown(connector.connections, 0) + (connector.state ? ' · ' + esc(connector.state) : '') : 'unavailable';
        const probes = (tunnel.probes || []).map((probe) => '<tr><td>' + esc(probe.url) + '</td><td>' + (probe.status == null ? 'unavailable' : esc(String(probe.status))) + '</td><td>' + shown(probe.latency_ms, 0, ' ms') + '</td><td>' + esc(probe.reachable ? 'up' : 'down') + '</td></tr>');
        return '<p class="host-value">' + esc(tunnel.alert || 'unknown') + '</p>'
            + (tunnel.name ? '<p class="host-sub">' + esc(tunnel.name) + '</p>' : '')
            + row('cloudflared', esc(state + (service.sub ? ' (' + service.sub + ')' : '')))
            + row('Process', esc(process))
            + row('Connections', connections)
            + (probes.length ? table(['Hostname', 'Status', 'Latency', 'Reachable'], probes) : missing('No public hostnames saved.'));
    }

    const painters = {
        cpu: paintCpu,
        gpu: paintGpu,
        temp: paintTemp,
        mem: paintMem,
        power: paintPower,
        disk: paintDisk,
        net: paintNet,
        proc: paintProc,
        os: paintOs,
        tunnel: paintTunnel,
    };

    function paint() {
        Object.keys(painters).forEach((id) => {
            const node = document.querySelector('[data-host-card="' + id + '"]');
            if (node) node.innerHTML = painters[id]();
        });
        const sampled = document.getElementById('host-sampled');
        if (sampled) sampled.textContent = snap.sampled_at ? 'Sampled ' + snap.sampled_at : 'Sample time unavailable';
        if (snap.error) {
            const banner = document.getElementById('host-alert');
            if (banner) {
                banner.hidden = false;
                banner.className = 'host-alert is-degraded';
                banner.innerHTML = '<p>' + esc(snap.error) + '</p>';
            }
        } else {
            paintAlert();
        }
    }

    function subjectsFor(name) {
        const seen = {};
        const points = [];
        const cpu = snap.cpu || {};
        if (name === 'cpu.load1' || name === 'cpu.load5' || name === 'cpu.load15' || name === 'mem.used_pct' || name === 'mem.used_mib' || name === 'swap.used_pct' || name === 'proc.count' || name === 'uptime.s' || name === 'tunnel.up' || name === 'tunnel.degraded' || name === 'tunnel.connections') {
            points.push(['', 'host']);
        }
        if (name === 'cpu.util') points.push(['all', 'all cores']);
        (cpu.cores || []).forEach((core) => {
            if (name === 'cpu.util' || name === 'cpu.freq_mhz') points.push([String(core.id), 'core ' + core.id]);
        });
        (snap.gpus || []).forEach((gpu) => {
            if (name.indexOf('gpu.') === 0) points.push([gpu.id, gpu.model || gpu.id]);
        });
        (snap.temperatures || []).forEach((row) => {
            if (name === 'temp.c' && !hidden(row.id)) points.push([row.id, row.label]);
        });
        (snap.fans || []).forEach((row) => {
            if (name === 'fan.rpm' && !hidden(row.id)) points.push([row.id, row.label]);
        });
        (snap.power || []).forEach((row) => {
            if (name === 'power.w' && !hidden(row.id)) points.push([row.id, row.label]);
        });
        (snap.voltage || []).forEach((row) => {
            if (name === 'voltage.v' && !hidden(row.id)) points.push([row.id, row.label]);
        });
        (snap.mounts || []).forEach((row) => {
            if (name === 'disk.used_pct') points.push([row.mount, row.mount]);
        });
        (snap.disks || []).forEach((row) => {
            if (name === 'disk.temp_c' || name === 'disk.smart') points.push([row.device, row.device]);
        });
        (snap.network || []).forEach((row) => {
            if (name === 'net.rx_bps' || name === 'net.tx_bps') points.push([row.iface, row.iface]);
        });
        ((snap.tunnel || {}).probes || []).forEach((row) => {
            if (name === 'tunnel.latency_ms' || name === 'tunnel.http') points.push([row.url, row.url]);
        });
        const unique = [];
        points.forEach((point) => {
            const key = point[0];
            if (seen[key]) return;
            seen[key] = true;
            unique.push(point);
        });
        return unique;
    }

    function fillMetrics() {
        const select = document.getElementById('host-metric');
        if (!select) return;
        select.innerHTML = Object.keys(catalog).map((key) => '<option value="' + esc(key) + '">' + esc(catalog[key].label) + '</option>').join('');
        select.value = metric;
        fillSubjects();
    }

    function fillSubjects() {
        const select = document.getElementById('host-subject');
        const label = document.getElementById('host-subject-label');
        if (!select) return;
        const subjects = subjectsFor(metric);
        select.innerHTML = subjects.map((item) => '<option value="' + esc(item[0]) + '">' + esc(item[1]) + '</option>').join('');
        if (!subjects.some((item) => item[0] === subject)) subject = subjects.length ? subjects[0][0] : '';
        select.value = subject;
        if (label) label.hidden = subjects.length < 2;
    }

    function lineChart(points, label, unit) {
        const w = 700;
        const h = 180;
        const pad = 12;
        const values = points.map((point) => point.value);
        const min = Math.min.apply(null, values);
        const max = Math.max.apply(null, values);
        const span = (max - min) || 1;
        let coords = values.map((value, index) => {
            const x = points.length === 1 ? w / 2 : (index / (points.length - 1)) * w;
            const y = pad + (1 - ((value - min) / span)) * (h - pad * 2);
            return [x, y];
        });
        if (coords.length === 1) coords = [[0, coords[0][1]], [w, coords[0][1]]];
        const line = coords.map((coord, index) => (index ? 'L' : 'M') + coord[0].toFixed(1) + ' ' + coord[1].toFixed(1)).join(' ');
        const area = line + ' L' + w + ' ' + h + ' L0 ' + h + ' Z';
        let labels = points.map((point) => point.at.slice(5, 16).replace('T', ' '));
        if (labels.length > 6) labels = [labels[0], labels[Math.floor((labels.length - 1) / 2)], labels[labels.length - 1]];
        const axis = unit ? shown(max, 1, ' ' + unit) + ' / ' + shown(min, 1, ' ' + unit) : shown(max, 1) + ' / ' + shown(min, 1);
        return '<div class="fin-chart-frame"><div class="fin-chart-y"><span>' + axis.split(' / ')[0] + '</span><span>' + axis.split(' / ')[1] + '</span></div>'
            + '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="' + esc(label) + '">'
            + '<path class="fin-chart-area" d="' + area + '"></path><path class="fin-chart-line" d="' + line + '"></path></svg></div>'
            + '<div class="fin-axis">' + labels.map((text) => '<span>' + esc(text) + '</span>').join('') + '</div>';
    }

    async function loadHistory() {
        const host = document.getElementById('host-chart');
        const api = window.LD && window.LD.api;
        if (!host || !api) return;
        const ticket = ++historyRequest;
        host.innerHTML = '<p class="host-note">Loading…</p>';
        try {
            const data = await api.get('/system/host/history?metric=' + encodeURIComponent(metric) + '&subject=' + encodeURIComponent(subject) + '&range=' + encodeURIComponent(range));
            if (ticket !== historyRequest) return;
            const points = data.points || [];
            if (!points.length) {
                host.innerHTML = missing('No samples in this range yet. The collector stores a point every ' + (options.sample_interval_minutes || 5) + ' minutes.');
                return;
            }
            host.innerHTML = lineChart(points, (catalog[metric] && catalog[metric].label) || metric, data.unit || '');
        } catch (err) {
            if (ticket !== historyRequest) return;
            host.innerHTML = missing(err && err.message ? err.message : 'The history request failed.');
        }
    }

    async function refresh(fresh) {
        const api = window.LD && window.LD.api;
        if (!api) return;
        try {
            snap = await api.get('/system/host' + (fresh ? '?fresh=1' : ''));
            paint();
        } catch (err) {
            const banner = document.getElementById('host-alert');
            if (banner) {
                banner.hidden = false;
                banner.className = 'host-alert is-down';
                banner.innerHTML = '<p>' + esc(err && err.message ? err.message : 'Refresh failed.') + '</p>';
            }
        }
    }

    const metricSelect = document.getElementById('host-metric');
    const subjectSelect = document.getElementById('host-subject');
    if (metricSelect) {
        metricSelect.addEventListener('change', () => {
            metric = metricSelect.value;
            fillSubjects();
            loadHistory();
        });
    }
    if (subjectSelect) {
        subjectSelect.addEventListener('change', () => {
            subject = subjectSelect.value;
            loadHistory();
        });
    }
    document.querySelectorAll('#host-card-chart .fin-range').forEach((button) => {
        button.addEventListener('click', () => {
            range = button.getAttribute('data-range') || '24h';
            document.querySelectorAll('#host-card-chart .fin-range').forEach((other) => {
                other.setAttribute('aria-selected', other === button ? 'true' : 'false');
            });
            loadHistory();
        });
    });
    const refreshButton = document.getElementById('host-refresh');
    if (refreshButton) refreshButton.addEventListener('click', () => refresh(true));

    paint();
    fillMetrics();
    loadHistory();
    setInterval(() => {
        if (!document.hidden) refresh(false);
    }, 20000);
})();
