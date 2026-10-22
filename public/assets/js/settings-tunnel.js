'use strict';

(function () {
    const form = document.getElementById('tunnel-form');
    const errorEl = document.getElementById('tunnel-error');
    const statusEl = document.getElementById('tunnel-status');
    const tokenInput = document.getElementById('tunnel-token');
    let clearToken = false;

    function csrf() {
        return window.getCsrfToken ? window.getCsrfToken() : '';
    }

    function showError(message) {
        if (!errorEl) return;
        errorEl.textContent = message || 'Could not save.';
        errorEl.classList.remove('hidden');
    }

    function existingTunnel(tunnel) {
        return !!(tunnel && tunnel.status && tunnel.status.existing && tunnel.status.connected);
    }

    function statusText(status) {
        if (status && status.existing && status.connected) {
            return 'Connected. cloudflared is already running on this server.';
        }
        return status && status.connected ? 'Connected' : 'Not connected';
    }

    function paint(tunnel) {
        if (!tunnel) return;
        const existing = existingTunnel(tunnel);
        if (statusEl) statusEl.textContent = statusText(tunnel.status);
        const lead = document.getElementById('tunnel-lead');
        if (lead) {
            lead.textContent = existing
                ? 'cloudflared is already running on this server. This page shows that service. A token is not required, and this page will not install another tunnel.'
                : 'Create the tunnel in the Cloudflare Zero Trust dashboard, paste its token here, then add the two public hostnames below.';
        }
        const actions = document.getElementById('tunnel-actions');
        if (actions) actions.classList.toggle('hidden', existing);
        const hint = document.getElementById('tunnel-token-hint');
        if (hint) {
            if (existing) hint.textContent = 'Not required while cloudflared is already running.';
            else if (!tunnel.token_set) hint.textContent = 'Not set';
            else if (tunnel.token_hint && tunnel.token_hint !== 'set') hint.textContent = 'Set. Ending in ' + tunnel.token_hint;
            else hint.textContent = 'Set.';
        }
        const list = document.getElementById('tunnel-routes');
        if (list && Array.isArray(tunnel.routes)) {
            list.innerHTML = tunnel.routes.map(function (route) {
                const copy = String(route.copy || '');
                return '<li class="flex flex-wrap items-center gap-2 text-sm"><span class="mono">' +
                    copy.replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }) +
                    '</span> <button type="button" class="btn text-xs" data-copy="' +
                    copy.replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }) +
                    '">Copy</button></li>';
            }).join('');
        }
    }

    async function post(body) {
        const res = await fetch('/api/settings/tunnel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok) throw new Error(data.error || 'Could not save.');
        paint(data.tunnel);
        return data;
    }

    document.getElementById('tunnel-replace')?.addEventListener('click', function () {
        clearToken = false;
        tokenInput?.classList.remove('hidden');
        tokenInput?.focus();
    });
    document.getElementById('tunnel-clear')?.addEventListener('click', function () {
        clearToken = true;
        if (tokenInput) tokenInput.value = '';
        const hint = document.getElementById('tunnel-token-hint');
        if (hint) hint.textContent = 'Clears on save.';
    });

    document.getElementById('tunnel-app')?.addEventListener('click', async function (event) {
        const copy = event.target.closest('[data-copy]');
        if (copy) {
            const text = copy.getAttribute('data-copy') || '';
            try {
                await navigator.clipboard.writeText(text);
                copy.textContent = 'Copied';
            } catch (err) {
                showError('Could not copy.');
            }
            return;
        }
        const action = event.target.closest('[data-tunnel-action]');
        if (!action) return;
        errorEl?.classList.add('hidden');
        action.disabled = true;
        try {
            await post({ action: action.dataset.tunnelAction });
        } catch (err) {
            showError(err.message);
        } finally {
            action.disabled = false;
        }
    });

    form?.addEventListener('submit', async function (event) {
        event.preventDefault();
        errorEl?.classList.add('hidden');
        const btn = form.querySelector('[type="submit"]');
        btn.disabled = true;
        try {
            await post({
                action: 'save',
                values: {
                    dash_host: document.getElementById('tunnel-dash')?.value || '',
                    mcp_host: document.getElementById('tunnel-mcp')?.value || '',
                    token: tokenInput?.value || '',
                    clear_token: clearToken ? '1' : '',
                },
            });
            clearToken = false;
            if (tokenInput) {
                tokenInput.value = '';
                tokenInput.classList.add('hidden');
            }
        } catch (err) {
            showError(err.message);
        } finally {
            btn.disabled = false;
        }
    });

    window.setInterval(async function () {
        try {
            const res = await fetch('/api/settings/tunnel', { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.tunnel) return;
            if (statusEl) statusEl.textContent = statusText(data.tunnel.status);
            if (!existingTunnel(data.tunnel)) return;
            const lead = document.getElementById('tunnel-lead');
            if (lead) {
                lead.textContent = 'cloudflared is already running on this server. This page shows that service. A token is not required, and this page will not install another tunnel.';
            }
            const actions = document.getElementById('tunnel-actions');
            if (actions) actions.classList.add('hidden');
            const hint = document.getElementById('tunnel-token-hint');
            if (hint) hint.textContent = 'Not required while cloudflared is already running.';
        } catch (err) {
            /* The last status line stays. */
        }
    }, 3000);
})();
