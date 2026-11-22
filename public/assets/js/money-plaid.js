'use strict';

(function () {
    const root = document.getElementById('money-plaid');
    if (!root || !window.LD) return;
    const { api, esc } = window.LD;

    function showError(id, err) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = err && err.message ? err.message : 'Could not save.';
        el.classList.remove('hidden');
    }

    function clearError(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = '';
        el.classList.add('hidden');
    }

    function hint(value) {
        if (!value) return '';
        if (value === 'set') return '••••••••';
        return '•••••••• ' + value;
    }

    function loadScript() {
        if (window.Plaid) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Plaid Link did not load.'));
            document.head.appendChild(script);
        });
    }

    async function openLink(itemId) {
        const path = itemId ? '/money/plaid/items/' + itemId + '/link-token' : '/money/plaid/link-token';
        const token = await api.post(path, {});
        await loadScript();
        const handler = window.Plaid.create({
            token: token.link_token,
            onSuccess: async (publicToken, metadata) => {
                if (itemId) {
                    await api.post('/money/plaid/items/' + itemId + '/relinked', {});
                } else {
                    const institution = metadata && metadata.institution ? metadata.institution : {};
                    await api.post('/money/plaid/exchange', {
                        public_token: publicToken,
                        institution_name: institution.name || '',
                        institution_id: institution.institution_id || institution.id || '',
                    });
                }
                await load();
            },
        });
        handler.open();
    }

    function paint(settings, items, accounts) {
        const form = document.getElementById('money-plaid-settings');
        if (form && settings) {
            form.elements.environment.value = settings.environment || 'sandbox';
            const client = form.querySelector('[name="client_id"]');
            const secret = form.querySelector('[name="secret"]');
            if (client) client.placeholder = hint(settings.client_id_hint) || 'Client id';
            if (secret) secret.placeholder = hint(settings.secret_hint) || 'Secret';
        }
        const link = document.getElementById('money-plaid-link');
        if (link) link.hidden = !(settings && settings.configured);
        const list = document.getElementById('money-plaid-items');
        if (!list) return;
        const choices = (accounts || []).map((account) =>
            '<option value="' + esc(account.id) + '">' + esc(account.name) + '</option>'
        ).join('');
        if (!items.length) {
            list.innerHTML = '<p class="fin-note">No bank is linked yet.</p>';
            return;
        }
        list.innerHTML = items.map((item) => {
            const accountsHtml = (item.accounts || []).map((account) => {
                const mapped = account.account_id ? 'Mapped' : 'Not mapped';
                return '<div class="fin-bill" data-plaid-account="' + esc(account.id) + '"><span><strong>' + esc(account.name) + '</strong>'
                    + '<small class="fin-bill-meta">' + esc(account.subtype || account.type || '') + (account.mask ? ' · ' + esc(account.mask) : '') + ' · ' + esc(mapped) + '</small></span>'
                    + '<span class="fin-row-actions"><select class="input" data-map-account="' + esc(account.id) + '"><option value="">Choose</option>' + choices + '<option value="create">Create account</option></select>'
                    + '<button type="button" class="btn" data-map="' + esc(account.id) + '">Map</button></span></div>';
            }).join('');
            const error = item.sync_error ? '<p class="fin-stale">' + esc(item.sync_error) + '</p>' : '';
            const relink = item.needs_login ? '<button type="button" class="btn" data-relink="' + esc(item.id) + '">Sign in again</button>' : '';
            return '<div class="fin-plaid-item"><p><strong>' + esc(item.institution_name) + '</strong> <small class="fin-bill-meta">'
                + esc(item.status) + (item.last_synced_at ? ' · ' + esc(item.last_synced_at) : '') + '</small></p>'
                + error + accountsHtml
                + '<p class="fin-tools">' + relink + '<button type="button" class="btn" data-disconnect="' + esc(item.id) + '">Disconnect</button></p></div>';
        }).join('');
    }

    async function load() {
        const [settingsRes, itemRes, accountRes] = await Promise.all([
            api.get('/money/plaid/settings'),
            api.get('/money/plaid/items'),
            api.get('/money/accounts'),
        ]);
        paint(settingsRes.plaid || {}, itemRes.items || [], accountRes.accounts || []);
    }

    document.getElementById('money-plaid-settings').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError('money-plaid-settings-error');
        const data = new FormData(event.target);
        const body = { environment: String(data.get('environment') || 'sandbox') };
        const clientId = String(data.get('client_id') || '').trim();
        const secret = String(data.get('secret') || '').trim();
        if (clientId !== '') body.client_id = clientId;
        if (secret !== '') body.secret = secret;
        try {
            await api.post('/money/plaid/settings', body);
            event.target.reset();
            await load();
        } catch (err) {
            showError('money-plaid-settings-error', err);
        }
    });

    document.getElementById('money-plaid-link').addEventListener('click', () => {
        openLink(null).catch((err) => showError('money-plaid-settings-error', err));
    });

    document.getElementById('money-plaid-items').addEventListener('click', async (event) => {
        const disconnect = event.target.closest('[data-disconnect]');
        const relink = event.target.closest('[data-relink]');
        const map = event.target.closest('[data-map]');
        try {
            if (disconnect) {
                await api.post('/money/plaid/items/' + disconnect.getAttribute('data-disconnect') + '/disconnect', {});
                await load();
            } else if (relink) {
                await openLink(relink.getAttribute('data-relink'));
            } else if (map) {
                const id = map.getAttribute('data-map');
                const select = root.querySelector('[data-map-account="' + id + '"]');
                const value = select ? select.value : '';
                const body = value === 'create' ? { create: true } : { account_id: Number(value) };
                if (value === '') return;
                await api.post('/money/plaid/accounts/' + id + '/map', body);
                await load();
                document.dispatchEvent(new CustomEvent('money-ledger-reload'));
            }
        } catch (err) {
            showError('money-plaid-settings-error', err);
        }
    });

    load().catch((err) => showError('money-plaid-settings-error', err));
})();
