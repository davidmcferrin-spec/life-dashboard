'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/settings-host.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'templates/settings/host.php'), 'utf8');

function element(extra) {
    return Object.assign({
        textContent: '',
        innerHTML: '',
        hidden: false,
        className: '',
        value: '',
        addEventListener() {},
    }, extra);
}

function bootPage(withClient, units) {
    const boot = {
        snapshot: {
            sampled_at: '2026-11-28T18:22:42Z',
            cpu: {
                model: 'AMD Ryzen AI 9 HX 370',
                load: [0.42, 0.31, 0.2],
                package_percent: 18,
                cores: [{ id: 0, percent: 18, mhz: 4200 }],
            },
            gpus: [{ id: 'card0', model: 'Radeon 890M', util_percent: 12, driver: 'amdgpu' }],
            temperatures: [{ id: 'k10temp:Tctl', label: 'k10temp Tctl', celsius: 46, kind: 'cpu' }],
            memory: { used_percent: 40, used_bytes: 8000000000, total_bytes: 20000000000 },
            power: [{ id: 'rapl:package-0', label: 'RAPL package-0', watts: 22 }],
            voltage: [],
            mounts: [{ mount: '/', used_percent: 55, used_bytes: 100, total_bytes: 200 }],
            disks: [],
            network: [{ iface: 'enp1s0', rx_bps: 1000, tx_bps: 200 }],
            processes: { count: 120, top: [] },
            failed_services: { available: true, units: units || [] },
            host: { os: 'Ubuntu 26.04 LTS', kernel: '6.12.0', hostname: 'ai-vault', uptime_seconds: 3600, boot_time: '2026-11-28T17:00:00Z' },
            updates: { checked: true, count: 0, security_count: 0, packages: [] },
            reboot_required: { known: true, required: false, packages: [] },
            tunnel: { name: 'vault', alert: 'up', service: { active: true, sub: 'running', process: true }, connector: { available: true, connections: 4, state: 'connected' }, probes: [] },
        },
        catalog: {
            'cpu.load1': { label: 'CPU load (1 min)', unit: '' },
            'temp.c': { label: 'Temperature', unit: '°C' },
        },
        options: { hidden_sensors: [], sample_interval_minutes: 5 },
    };
    const cards = {};
    ['cpu', 'gpu', 'temp', 'mem', 'power', 'disk', 'net', 'proc', 'os', 'tunnel'].forEach((id) => {
        cards[id] = element();
    });
    const nodes = {
        'host-boot': element({ textContent: JSON.stringify(boot) }),
        'host-grid': element(),
        'host-alert': element(),
        'host-sampled': element(),
        'host-metric': element(),
        'host-subject': element(),
        'host-subject-label': element(),
        'host-chart': element(),
        'host-refresh': element(),
    };
    const document = {
        hidden: false,
        getElementById(id) { return nodes[id] || null; },
        querySelector(selector) {
            const match = /data-host-card="([^"]+)"/.exec(selector);
            return match ? cards[match[1]] : null;
        },
        querySelectorAll() { return []; },
    };
    const sandbox = {
        document,
        console,
        setInterval() {},
        window: withClient ? { LD: { esc: (v) => String(v), fmt: { number: (v) => String(v), duration: (v) => String(v) + 's' }, api: { get() { return Promise.resolve({ points: [] }); } } } } : {},
    };
    sandbox.globalThis = sandbox;
    sandbox.window.document = document;
    vm.runInNewContext(script, sandbox);
    return { cards, nodes };
}

test('the settings page loads the api client before the host painter', () => {
    const api = page.indexOf('lifedash-api.js');
    const painter = page.indexOf('settings-host.js');
    assert.ok(api > 0 && painter > api);
    assert.equal(page.includes('<script src="/assets/js/settings-host.js'), false);
    assert.match(page, /\$settingsScripts/);
});

test('cards and the metric list paint from the boot json without the api client', async () => {
    const { cards, nodes } = bootPage(false);
    assert.match(cards.cpu.innerHTML, /AMD Ryzen AI 9 HX 370/);
    assert.match(cards.gpu.innerHTML, /Radeon 890M/);
    assert.match(cards.temp.innerHTML, /k10temp Tctl/);
    assert.match(cards.power.innerHTML, /RAPL package-0/);
    assert.match(cards.tunnel.innerHTML, /up/);
    assert.match(nodes['host-metric'].innerHTML, /CPU load \(1 min\)/);
    assert.match(nodes['host-metric'].innerHTML, /Temperature/);
    assert.match(nodes['host-subject'].innerHTML, /host/);
});

test('the banner names each failed systemd unit', async () => {
    const { nodes } = bootPage(false, [
        { unit: 'systemd-networkd-wait-online.service', state: 'failed' },
        { unit: 'nginx.service', state: 'failed' },
    ]);
    const banner = nodes['host-alert'].innerHTML;
    assert.equal(nodes['host-alert'].hidden, false);
    assert.match(banner, /Failed service: systemd-networkd-wait-online\.service \(failed\)/);
    assert.match(banner, /Failed service: nginx\.service \(failed\)/);
    assert.equal(banner.includes('failed unit(s)'), false);
    assert.equal((banner.match(/<li>/g) || []).length, 2);
});

test('the same boot still paints when the api client is present', async () => {
    const { cards } = bootPage(true);
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.match(cards.cpu.innerHTML, /AMD Ryzen AI 9 HX 370/);
    assert.match(cards.mem.innerHTML, /40/);
});
