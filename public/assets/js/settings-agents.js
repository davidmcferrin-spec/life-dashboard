'use strict';

(function () {
    const { api, esc } = window.LD;
    const modal = document.getElementById('agent-token-modal');
    const tokenEl = document.getElementById('agent-token-value');
    const headerEl = document.getElementById('agent-token-header');
    const statusEl = document.getElementById('agent-token-status');

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

    function openTokenModal(token) {
        tokenEl.value = token;
        headerEl.value = 'Bearer ' + token;
        statusEl.textContent = '';
        document.getElementById('agent-token-url').value = modal.dataset.mcpUrl || '';
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
        tokenEl.focus();
    }

    function closeTokenModal() {
        modal.classList.add('hidden');
        document.body.classList.remove('app-modal-open');
        tokenEl.value = '';
        headerEl.value = '';
        statusEl.textContent = '';
    }

    async function copyText(text, field, button, doneLabel) {
        const label = button.dataset.label || button.textContent;
        button.dataset.label = label;
        try {
            await navigator.clipboard.writeText(text);
            statusEl.textContent = doneLabel;
            button.textContent = 'Copied';
            window.setTimeout(() => {
                button.textContent = label;
            }, 1600);
        } catch (err) {
            field.focus();
            field.select();
            statusEl.textContent = 'Clipboard blocked. The field is selected — press Ctrl+C.';
        }
    }

    document.getElementById('agent-create').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const errEl = document.getElementById('agent-create-error');
        const submit = form.querySelector('button[type="submit"]');
        errEl.textContent = '';
        submit.disabled = true;
        try {
            const data = new FormData(form);
            const access = String(data.get('access'));
            const scopes = access === 'read' ? ['read'] : ['*'];
            const res = await api.post('/tokens', { name: String(data.get('name')), scopes });
            const token = res.token && res.token.token;
            if (!token) {
                throw new Error('The server did not return a token.');
            }
            form.reset();
            openTokenModal(token);
            await load();
        } catch (err) {
            errEl.textContent = err.message || 'Could not create the token.';
        } finally {
            submit.disabled = false;
        }
    });

    modal.addEventListener('click', (e) => {
        if (e.target.closest('[data-close]')) closeTokenModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeTokenModal();
    });

    document.getElementById('agent-token-copy').addEventListener('click', (e) => {
        copyText(tokenEl.value, tokenEl, e.currentTarget, 'Token copied. Paste it only into the MCP client.');
    });

    document.getElementById('agent-token-copy-header').addEventListener('click', (e) => {
        copyText(headerEl.value, headerEl, e.currentTarget, 'Header copied. Paste it into the Authorization header value.');
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
