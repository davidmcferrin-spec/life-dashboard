'use strict';

(function () {
    const { api, esc } = window.LD;

    function paintTokens(tokens) {
        const el = document.getElementById('agent-tokens');
        el.innerHTML = (tokens || []).map((t) =>
            `<div class="ld-life-row">
                <span>${esc(t.name)} <span class="ld-meta">${esc(t.prefix)}… ${t.revoked ? 'revoked' : esc(t.scopes.join(' '))}</span></span>
                ${t.revoked ? '' : `<button type="button" class="btn text-xs" data-revoke="${t.id}">Revoke</button>`}
            </div>`
        ).join('') || '<p class="empty-state mono text-xs">No tokens yet.</p>';
    }

    function paintAudit(events) {
        const el = document.getElementById('agent-audit');
        el.innerHTML = (events || []).map((e) =>
            `<div class="ld-life-row"><span>${esc(e.actor)}${e.tool ? ' · ' + esc(e.tool) : ''} ${esc(e.action)}</span><span class="ld-meta">${esc(e.resource || '')}</span></div>`
        ).join('') || '<p class="empty-state mono text-xs">No writes yet.</p>';
    }

    async function load() {
        const [tokens, audit, prompt] = await Promise.all([
            api.get('/tokens'),
            api.get('/audit'),
            api.get('/agent-prompt'),
        ]);
        paintTokens(tokens.tokens);
        paintAudit(audit.events);
        document.getElementById('agent-prompt').value = prompt.prompt;
    }

    document.getElementById('agent-create').addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = new FormData(e.target);
        const access = String(data.get('access'));
        const scopes = access === 'read' ? ['read'] : ['read', 'write'];
        const res = await api.post('/tokens', { name: String(data.get('name')), scopes });
        const once = document.getElementById('agent-token-once');
        once.hidden = false;
        once.textContent = res.token.token + '  — copy it now. It will not be shown again.';
        e.target.reset();
        await load();
    });

    document.getElementById('agent-tokens').addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-revoke]');
        if (!btn) return;
        await api.post('/tokens/' + btn.dataset.revoke + '/revoke', {});
        await load();
    });

    document.getElementById('agent-copy-prompt').addEventListener('click', async () => {
        await navigator.clipboard.writeText(document.getElementById('agent-prompt').value);
    });

    load().catch((err) => {
        document.getElementById('agent-tokens').textContent = err.message;
    });
})();
