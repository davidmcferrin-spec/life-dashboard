'use strict';

(function () {
    const { api, esc } = window.LD;
    const modal = document.getElementById('agent-token-modal');
    const tokenEl = document.getElementById('agent-token-value');
    const headerEl = document.getElementById('agent-token-header');
    const statusEl = document.getElementById('agent-token-status');

    const tokenRoot = document.getElementById('agent-tokens');
    const admin = tokenRoot.dataset.admin === '1';
    const areas = JSON.parse(tokenRoot.dataset.areas || '[]');
    const editModal = document.getElementById('agent-edit-modal');
    const deleteModal = document.getElementById('agent-delete-modal');
    const oauthModal = document.getElementById('oauth-edit-modal');
    let tokenRows = [];
    let deleteId = 0;

    function paintTokens(tokens) {
        tokenRows = tokens || [];
        tokenRoot.innerHTML = tokenRows.map((t) => {
            const actions = [];
            if (!t.revoked && admin) actions.push(`<button type="button" class="btn text-xs" data-edit="${t.id}">Edit</button>`);
            if (!t.revoked) actions.push(`<button type="button" class="btn text-xs" data-revoke="${t.id}">Revoke</button>`);
            if (t.revoked && admin) actions.push(`<button type="button" class="btn text-xs" data-delete="${t.id}">Delete</button>`);
            const hop = t.last_transport === 'https' ? 'HTTPS' : (t.last_transport === 'http' ? 'HTTP' : 'not seen yet');
            return `<div class="ld-life-row">
                <span>${esc(t.name)} <span class="ld-meta">${esc(t.prefix)}… ${t.revoked ? 'revoked' : esc(t.scopes.join(' '))}${t.expires_at ? ' · expires ' + esc(t.expires_at.slice(0, 10)) : ''} · ${hop}</span></span>
                <span class="ld-metric-actions">${actions.join('')}</span>
            </div>`;
        }).join('') || window.DashboardEmpty.html({
            title: 'No tokens yet',
            body: 'Create a token to let an agent call the API.',
            actionLabel: 'Agents',
            actionHref: '/settings/agents',
        });
    }

    function paintAudit(events) {
        const el = document.getElementById('agent-audit');
        el.innerHTML = (events || []).map((e) => {
            const name = e.detail && e.detail.token_name ? ' · ' + esc(e.detail.token_name) : '';
            const gone = e.detail && e.detail.deleted ? ' · deleted' : '';
            return `<div class="ld-life-row"><span>${esc(e.actor)}${e.tool ? ' · ' + esc(e.tool) : ''} ${esc(e.action)}${name}${gone}</span><span class="ld-meta">${esc(e.resource || '')}</span></div>`;
        }).join('') || window.DashboardEmpty.html({
            title: 'No writes yet',
            body: 'Agent changes will be listed here.',
            actionLabel: 'Agents',
            actionHref: '/settings/agents',
        });
    }

    function findToken(id) {
        return tokenRows.find((row) => String(row.id) === String(id)) || null;
    }

    function checksFor(scopes) {
        const set = new Set(scopes || []);
        const rest = (scopes || []).filter((scope) => scope !== 'system:read' && scope !== 'system:deploy' && scope !== 'spotify:playback');
        const checks = {
            all: false,
            read: false,
            reddit: false,
            money: false,
            todos: false,
            spotify: false,
            system_read: set.has('system:read'),
            system_deploy: set.has('system:deploy'),
            spotify_playback: set.has('spotify:playback'),
        };
        if (rest.indexOf('*') !== -1) {
            checks.all = true;
            checks.spotify_playback = true;
            return checks;
        }
        const readOnly = areas.length > 0
            && rest.length === areas.length
            && areas.every((area) => rest.indexOf(area + ':read') !== -1);
        if (readOnly) {
            checks.read = true;
            return checks;
        }
        checks.reddit = set.has('reddit:read') || set.has('reddit:write');
        checks.money = set.has('money:read') || set.has('money:write');
        checks.todos = set.has('todos:read') || set.has('todos:write');
        checks.spotify = set.has('spotify:read') || set.has('spotify:write');
        return checks;
    }

    function openModal(el) {
        if (!el) return;
        el.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
    }

    function closeModal(el) {
        if (!el) return;
        el.classList.add('hidden');
        document.body.classList.remove('app-modal-open');
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
            const scopes = access === 'reddit'
                ? ['reddit:read', 'reddit:write']
                : (access === 'money'
                    ? ['money:read', 'money:write']
                    : (access === 'todos'
                        ? ['todos:read', 'todos:write']
                        : (access === 'spotify'
                            ? ['spotify:read', 'spotify:write']
                            : (access === 'read' ? ['read'] : ['*']))));
            if (data.get('spotify_playback')) scopes.push('spotify:playback');
            if (data.get('system_read')) scopes.push('system:read');
            if (data.get('system_deploy')) scopes.push('system:deploy');
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
        if (e.key !== 'Escape') return;
        if (!modal.classList.contains('hidden')) closeTokenModal();
        if (editModal && !editModal.classList.contains('hidden')) closeModal(editModal);
        if (oauthModal && !oauthModal.classList.contains('hidden')) closeModal(oauthModal);
        if (deleteModal && !deleteModal.classList.contains('hidden')) closeModal(deleteModal);
    });

    document.getElementById('agent-token-copy').addEventListener('click', (e) => {
        copyText(tokenEl.value, tokenEl, e.currentTarget, 'Token copied. Paste it only into the MCP client.');
    });

    document.getElementById('agent-token-copy-header').addEventListener('click', (e) => {
        copyText(headerEl.value, headerEl, e.currentTarget, 'Header copied. Paste it into the Authorization header value.');
    });

    function openEdit(id) {
        const row = findToken(id);
        if (!row || !editModal || row.revoked) return;
        const form = document.getElementById('agent-edit');
        form.dataset.id = String(row.id);
        form.elements.name.value = row.name;
        const checks = checksFor(row.scopes);
        form.querySelectorAll('[data-access]').forEach((box) => {
            box.checked = checks[box.value] === true;
        });
        form.elements.system_read.checked = checks.system_read;
        form.elements.system_deploy.checked = checks.system_deploy;
        if (form.elements.spotify_playback) form.elements.spotify_playback.checked = checks.spotify_playback === true;
        document.getElementById('agent-edit-current').textContent = 'Current scopes: ' + row.scopes.join(' ');
        document.getElementById('agent-edit-error').textContent = '';
        openModal(editModal);
        form.elements.name.focus();
    }

    function openDelete(id) {
        const row = findToken(id);
        if (!row || !deleteModal || !row.revoked) return;
        deleteId = row.id;
        document.getElementById('agent-delete-name').textContent = row.name;
        document.getElementById('agent-delete-error').textContent = '';
        openModal(deleteModal);
        document.getElementById('agent-delete-confirm').focus();
    }

    function bindExclusive(root) {
        if (!root) return;
        root.querySelectorAll('[data-access]').forEach((box) => {
            box.addEventListener('change', () => {
                if (!box.checked) return;
                root.querySelectorAll('[data-access]').forEach((other) => {
                    if (other !== box) other.checked = false;
                });
            });
        });
    }

    function scopesFromEdit(form) {
        const scopes = [];
        form.querySelectorAll('[data-access]:checked').forEach((box) => {
            if (box.value === 'all') scopes.push('*');
            else if (box.value === 'read') scopes.push('read');
            else if (box.value === 'reddit') scopes.push('reddit:read', 'reddit:write');
            else if (box.value === 'money') scopes.push('money:read', 'money:write');
            else if (box.value === 'todos') scopes.push('todos:read', 'todos:write');
            else if (box.value === 'spotify') scopes.push('spotify:read', 'spotify:write');
        });
        if (form.elements.spotify_playback && form.elements.spotify_playback.checked) scopes.push('spotify:playback');
        if (form.elements.system_read.checked) scopes.push('system:read');
        if (form.elements.system_deploy.checked) scopes.push('system:deploy');
        return scopes;
    }

    if (editModal) {
        bindExclusive(editModal);
        editModal.addEventListener('click', (e) => {
            if (e.target.closest('[data-close]')) closeModal(editModal);
        });
        document.getElementById('agent-edit').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const errEl = document.getElementById('agent-edit-error');
            const submit = form.querySelector('button[type="submit"]');
            errEl.textContent = '';
            submit.disabled = true;
            try {
                await api.post('/tokens/' + form.dataset.id + '/update', {
                    name: form.elements.name.value,
                    scopes: scopesFromEdit(form),
                });
                closeModal(editModal);
                await load();
            } catch (err) {
                errEl.textContent = err.message || 'Could not save the token.';
            } finally {
                submit.disabled = false;
            }
        });
    }

    if (deleteModal) {
        deleteModal.addEventListener('click', (e) => {
            if (e.target.closest('[data-close]')) closeModal(deleteModal);
        });
        document.getElementById('agent-delete-confirm').addEventListener('click', async () => {
            const errEl = document.getElementById('agent-delete-error');
            const button = document.getElementById('agent-delete-confirm');
            errEl.textContent = '';
            button.disabled = true;
            try {
                await api.post('/tokens/' + deleteId + '/delete', {});
                closeModal(deleteModal);
                deleteId = 0;
                await load();
            } catch (err) {
                errEl.textContent = err.message || 'Could not delete the token.';
            } finally {
                button.disabled = false;
            }
        });
    }

    document.getElementById('agent-tokens').addEventListener('click', async (e) => {
        const edit = e.target.closest('[data-edit]');
        if (edit) {
            openEdit(edit.dataset.edit);
            return;
        }
        const del = e.target.closest('[data-delete]');
        if (del) {
            openDelete(del.dataset.delete);
            return;
        }
        const btn = e.target.closest('[data-revoke]');
        if (!btn) return;
        btn.disabled = true;
        try {
            await api.post('/tokens/' + btn.dataset.revoke + '/revoke', {});
            await load();
        } catch (err) {
            btn.disabled = false;
            btn.insertAdjacentHTML('afterend', '<span class="ld-field-error">' + esc(err.message || 'Could not revoke the token.') + '</span>');
        }
    });

    function openOauth(button) {
        if (!oauthModal) return;
        const form = document.getElementById('oauth-edit');
        const scopes = JSON.parse(button.dataset.oauthScopes || '[]');
        form.dataset.id = button.dataset.oauthEdit;
        form.dataset.scopes = JSON.stringify(scopes);
        const checks = checksFor(scopes);
        const preset = checks.all || checks.read || checks.reddit || checks.money || checks.todos || checks.spotify
            || checks.system_read || checks.system_deploy || checks.spotify_playback;
        form.dataset.preset = preset ? '1' : '0';
        form.dataset.touched = '0';
        form.elements.name.value = button.dataset.oauthName || '';
        form.querySelectorAll('[data-access]').forEach((box) => {
            box.checked = checks[box.value] === true;
        });
        form.elements.system_read.checked = checks.system_read;
        form.elements.system_deploy.checked = checks.system_deploy;
        if (form.elements.spotify_playback) form.elements.spotify_playback.checked = checks.spotify_playback === true;
        const current = scopes.length ? scopes.join(' ') : 'none';
        document.getElementById('oauth-edit-current').textContent = button.dataset.oauthLimited === '1'
            ? 'Current scopes: ' + current
            : 'Not limited yet. Saving sets the scopes this client may use. Current grant: ' + current;
        document.getElementById('oauth-edit-error').textContent = '';
        document.getElementById('oauth-revoke').action = '/settings/oauth/' + button.dataset.oauthEdit + '/revoke';
        openModal(oauthModal);
        form.elements.name.focus();
    }

    const oauthList = document.getElementById('oauth-clients');
    if (oauthList) {
        oauthList.addEventListener('click', (e) => {
            const button = e.target.closest('[data-oauth-edit]');
            if (button) openOauth(button);
        });
    }

    if (oauthModal) {
        bindExclusive(oauthModal);
        oauthModal.querySelectorAll('[data-access], input[name="spotify_playback"], input[name="system_read"], input[name="system_deploy"]').forEach((box) => {
            box.addEventListener('change', () => {
                document.getElementById('oauth-edit').dataset.touched = '1';
            });
        });
        oauthModal.addEventListener('click', (e) => {
            if (e.target.closest('[data-close]')) closeModal(oauthModal);
        });
        document.getElementById('oauth-edit').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const errEl = document.getElementById('oauth-edit-error');
            const submit = form.querySelector('button[type="submit"]');
            errEl.textContent = '';
            submit.disabled = true;
            try {
                let scopes = scopesFromEdit(form);
                if (scopes.length === 0 && form.dataset.preset === '0' && form.dataset.touched !== '1') {
                    scopes = JSON.parse(form.dataset.scopes || '[]');
                }
                await api.post('/oauth/clients/' + form.dataset.id + '/update', {
                    name: form.elements.name.value,
                    scopes: scopes,
                });
                window.location.assign('/settings/agents');
            } catch (err) {
                errEl.textContent = err.message || 'Could not save the client.';
                submit.disabled = false;
            }
        });
    }

    document.getElementById('agent-copy-prompt').addEventListener('click', async () => {
        await navigator.clipboard.writeText(document.getElementById('agent-prompt').value);
    });

    load().catch((err) => {
        document.getElementById('agent-tokens').textContent = err.message;
    });
})();
