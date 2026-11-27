(function (root) {
    'use strict';

    var DESTRUCTIVE = { off: 1, cycle: 1, reboot: 1, shutdown: 1 };
    var FULL = [
        { action: 'on', label: 'On' },
        { action: 'off', label: 'Off' },
        { action: 'cycle', label: 'Cycle' },
        { action: 'reboot', label: 'Reboot' },
        { action: 'shutdown', label: 'Shutdown' }
    ];
    var SWITCH = [
        { action: 'on', label: 'On' },
        { action: 'off', label: 'Off' },
        { action: 'cycle', label: 'Cycle' }
    ];
    var ROKU = [
        { action: 'on', label: 'On' },
        { action: 'off', label: 'Off' }
    ];

    function actionsFor(type) {
        if (type === 'roku') {
            return ROKU;
        }
        if (type === 'plug' || type === 'outlet') {
            return SWITCH;
        }
        if (type === 'pdu') {
            return [];
        }
        return FULL;
    }

    function canWake(type) {
        return !type || type === 'device';
    }

    function destructive(action) {
        return Object.prototype.hasOwnProperty.call(DESTRUCTIVE, String(action || '').toLowerCase());
    }

    function confirmCopy(name, action) {
        var machine = name && String(name).trim() ? String(name).trim() : 'this machine';
        if (action === 'off' || action === 'shutdown') {
            return 'Turn off ' + machine + '? This drops its power.';
        }
        if (action === 'cycle') {
            return 'Power-cycle ' + machine + '? It will turn off, then on.';
        }
        if (action === 'reboot') {
            return 'Reboot ' + machine + '? It will go down and come back.';
        }
        return 'Send ' + action + ' to ' + machine + '?';
    }

    function errorText(payload) {
        var code = payload && payload.code ? String(payload.code) : '';
        var message = payload && payload.error ? String(payload.error) : '';
        if (code === 'wakehub_unconfigured') {
            return message || 'Wake Hub is not configured. Save the base URL and token under Settings.';
        }
        if (code === 'wakehub_unreachable') {
            return message || 'Wake Hub is unreachable.';
        }
        if (code === 'wakehub_unauthorized') {
            return message || 'Wake Hub rejected the token.';
        }
        if (code === 'confirmation_required') {
            return message || 'That power action needs confirmation.';
        }
        return message || 'Wake Hub did not answer.';
    }

    function powerBody(deviceId, action, confirm, method) {
        var body = {
            action: action,
            confirm: destructive(action) ? confirm === true : false
        };
        if (method) {
            body.method = method;
        }
        return { device_id: deviceId, body: body };
    }

    root.WakeHubCard = {
        destructive: destructive,
        confirmCopy: confirmCopy,
        errorText: errorText,
        powerBody: powerBody,
        actionsFor: actionsFor,
        canWake: canWake
    };

    if (typeof document === 'undefined') {
        return;
    }

    function icon(name) {
        var tpl = document.getElementById('wake-ico-' + name);
        if (!tpl) {
            return '';
        }
        return tpl.innerHTML;
    }

    function csrf() {
        return root.getCsrfToken ? root.getCsrfToken() : '';
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function reachLabel(value) {
        if (value === true) {
            return { cls: 'is-up', text: 'Up' };
        }
        if (value === false) {
            return { cls: 'is-down', text: 'Down' };
        }
        return { cls: 'is-unknown', text: 'Unknown' };
    }

    async function post(url, body) {
        var res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrf()
            },
            body: JSON.stringify(body)
        });
        var payload = {};
        try {
            payload = await res.json();
        } catch (err) {
            payload = {};
        }
        if (!res.ok) {
            var error = new Error(errorText(payload));
            error.payload = payload;
            throw error;
        }
        return payload;
    }

    function paint(host, devices, note) {
        var status = host.querySelector('[data-wake-status]');
        var list = host.querySelector('[data-wake-list]');
        if (status && note != null) {
            status.textContent = note;
        }
        if (!list) {
            return;
        }
        if (!devices.length) {
            list.innerHTML = '';
            return;
        }
        list.innerHTML = devices.map(function (device) {
            var reach = reachLabel(device.reachable);
            var disabled = device.enabled === false ? ' disabled' : '';
            var target = encodeURIComponent(device.id);
            var buttons = '';
            if (canWake(device.type)) {
                buttons += '<button type="button" class="btn text-xs wake-hub-btn" data-wake="' + esc(device.id) + '"' + disabled + '>'
                    + icon('play') + '<span>Wake</span></button>';
            }
            actionsFor(device.type).forEach(function (item) {
                buttons += '<button type="button" class="btn text-xs wake-hub-btn" data-power="' + item.action + '" data-device="' + esc(device.id) + '" data-name="' + esc(device.name) + '"' + disabled + '>'
                    + icon(item.action) + '<span>' + item.label + '</span></button>';
            });
            return '<li class="wake-hub-row" data-target="' + esc(target) + '">'
                + '<span class="wake-dot ' + reach.cls + '" title="' + reach.text + '"></span>'
                + '<span class="wake-hub-name"><span class="wake-hub-title">' + esc(device.name) + '</span>'
                + '<span class="wake-hub-host mono text-xs">' + esc(device.type || 'device') + (device.host ? ' · ' + esc(device.host) : '') + '</span></span>'
                + '<span class="wake-hub-reach mono text-xs">' + reach.text + '</span>'
                + '<span class="wake-hub-actions">' + buttons + '</span>'
                + '</li>';
        }).join('');
    }

    async function load(host, note) {
        var status = host.querySelector('[data-wake-status]');
        try {
            var res = await fetch('/api/v1/wakehub/devices', { headers: { 'Accept': 'application/json' } });
            var payload = {};
            try {
                payload = await res.json();
            } catch (err) {
                payload = {};
            }
            if (!res.ok) {
                paint(host, [], errorText(payload));
                return;
            }
            var devices = payload.devices || [];
            var count = devices.length ? devices.length + (devices.length === 1 ? ' device' : ' devices') : 'No devices yet.';
            paint(host, devices, note || count);
        } catch (err) {
            if (status) {
                status.textContent = 'Wake Hub is unreachable.';
            }
        }
    }

    async function onClick(event) {
        var wake = event.target.closest('[data-wake]');
        var power = event.target.closest('[data-power]');
        var host = event.target.closest('[data-wake-hub]');
        if (!host || (!wake && !power)) {
            return;
        }
        var button = wake || power;
        if (button.disabled || button.dataset.busy === '1') {
            return;
        }
        var status = host.querySelector('[data-wake-status]');
        button.dataset.busy = '1';
        try {
            if (wake) {
                if (status) {
                    status.textContent = 'Sending wake…';
                }
                await post('/api/v1/wakehub/devices/' + encodeURIComponent(wake.dataset.wake) + '/wake', {});
                await load(host, 'Wake sent.');
                return;
            } else {
                var action = power.dataset.power;
                var confirm = false;
                if (destructive(action)) {
                    var ask = root.AppModal && root.AppModal.confirm;
                    if (!ask) {
                        if (status) {
                            status.textContent = 'Confirmation is not available.';
                        }
                        return;
                    }
                    var ok = await ask({
                        title: 'Confirm power',
                        message: confirmCopy(power.dataset.name, action),
                        confirmLabel: action.charAt(0).toUpperCase() + action.slice(1),
                        danger: true
                    });
                    if (!ok) {
                        if (status) {
                            status.textContent = 'Not sent.';
                        }
                        return;
                    }
                    confirm = true;
                }
                var packed = powerBody(Number(power.dataset.device), action, confirm);
                if (status) {
                    status.textContent = 'Sending ' + action + '…';
                }
                await post('/api/v1/wakehub/devices/' + encodeURIComponent(power.dataset.device) + '/power', packed.body);
                await load(host, action + ' sent.');
                return;
            }
        } catch (err) {
            if (status) {
                status.textContent = err && err.message ? err.message : 'Wake Hub did not answer.';
            }
        } finally {
            if (button.isConnected) {
                button.dataset.busy = '';
            }
        }
    }

    function boot() {
        var hosts = document.querySelectorAll('[data-wake-hub]');
        if (!hosts.length) {
            return;
        }
        hosts.forEach(function (host) { load(host); });
        document.addEventListener('click', onClick);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}(typeof globalThis !== 'undefined' ? globalThis : this));
