'use strict';

(function () {
    const state = window.SETUP || { groups: [] };
    const modal = document.getElementById('setup-modal');
    const form = document.getElementById('setup-form');
    const fieldsEl = document.getElementById('setup-fields');
    const errorEl = document.getElementById('setup-error');
    const titleEl = document.getElementById('setup-modal-title');
    let current = null;

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function groupById(id) {
        return state.groups.find((g) => g.id === id) || null;
    }

    function open(id) {
        current = groupById(id);
        if (!current || !modal) return;
        titleEl.textContent = current.label;
        errorEl.classList.add('hidden');
        renderFields();
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
        fieldsEl.querySelector('input, select')?.focus();
    }

    function close() {
        modal?.classList.add('hidden');
        document.body.classList.remove('app-modal-open');
        current = null;
    }

    function valuesFromForm() {
        const values = {};
        const clear = [];
        fieldsEl.querySelectorAll('[data-key]').forEach((el) => {
            if (el.type === 'checkbox' && el.dataset.clear === '1') {
                const hidden = el.closest('.setup-field')?.classList.contains('hidden');
                if (el.checked && !hidden) clear.push(el.dataset.key);
                return;
            }
            if (el.dataset.cleared === '1') {
                clear.push(el.dataset.key);
            }
            if (el.closest('.setup-field')?.classList.contains('hidden') && el.type !== 'checkbox') {
                return;
            }
            const field = el.closest('.setup-field');
            if (field?.classList.contains('hidden') && el.dataset.ui !== '1') return;
            if (el.type === 'checkbox') values[el.dataset.key] = el.checked ? '1' : '0';
            else values[el.dataset.key] = el.value;
        });
        values.clear = clear;
        return values;
    }

    function currentControl(key) {
        const el = fieldsEl.querySelector(`[data-key="${CSS.escape(key)}"]`);
        if (!el || el.dataset.clear === '1') return null;
        if (el.type === 'checkbox') return el.checked ? '1' : '0';
        return el.value;
    }

    function applyWhen() {
        fieldsEl.querySelectorAll('.setup-field').forEach((field) => {
            const when = field.dataset.when ? JSON.parse(field.dataset.when) : null;
            if (!when) {
                field.classList.remove('hidden');
                return;
            }
            const show = Object.entries(when).every(([key, expected]) => currentControl(key) === String(expected));
            field.classList.toggle('hidden', !show);
        });
    }

    function renderFields() {
        fieldsEl.innerHTML = current.fields.map((field) => {
            const when = field.when ? ` data-when="${esc(JSON.stringify(field.when))}"` : '';
            if (field.type === 'bool') {
                return `<label class="setup-field flex items-center gap-2 text-sm text-secondary"${when}>
                    <input type="checkbox" data-key="${esc(field.key)}" data-ui="${field.ui ? '1' : '0'}" ${field.value === '1' ? 'checked' : ''}>
                    ${esc(field.label)}
                </label>`;
            }
            if (field.type === 'enum') {
                const options = Object.entries(field.options || {}).map(([k, label]) =>
                    `<option value="${esc(k)}" ${field.value === k ? 'selected' : ''}>${esc(label)}</option>`
                ).join('');
                return `<div class="setup-field"${when}>
                    <label class="ld-field" for="setup-${esc(field.key)}">${esc(field.label)}</label>
                    <select id="setup-${esc(field.key)}" class="input text-sm w-full" data-key="${esc(field.key)}">${options}</select>
                </div>`;
            }
            if (field.secret) {
                const hint = field.hint === 'set' ? 'Set.' : (field.hint ? 'Set. Ending in ' + field.hint + '.' : (field.source === 'env' ? 'Using the saved fallback until you replace it.' : 'Not set.'));
                const clear = field.source === 'saved'
                    ? `<button type="button" class="btn text-xs" data-clear-btn="${esc(field.key)}">Clear</button>`
                    : '';
                return `<div class="setup-field"${when}>
                    <label class="ld-field" for="setup-${esc(field.key)}">${esc(field.label)}</label>
                    <p class="text-faint text-xs mono mt-1" data-hint-for="${esc(field.key)}">${esc(hint)}</p>
                    <input id="setup-${esc(field.key)}" class="input text-sm w-full hidden" data-key="${esc(field.key)}" data-secret="1"
                        type="password" autocomplete="off">
                    <div class="flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn text-xs" data-replace="${esc(field.key)}">Replace</button>
                        ${clear}
                    </div>
                </div>`;
            }
            const suggest = field.suggest
                ? `<p class="text-faint text-xs mono mt-1">Suggested <span class="mono">${esc(field.suggest)}</span>
                    <button type="button" class="btn text-xs" data-copy="${esc(field.suggest)}">Copy</button></p>`
                : '';
            const preset = field.value || field.suggest || '';
            return `<div class="setup-field"${when}>
                <label class="ld-field" for="setup-${esc(field.key)}">${esc(field.label)}</label>
                <input id="setup-${esc(field.key)}" class="input text-sm w-full" data-key="${esc(field.key)}"
                    type="text" autocomplete="off" placeholder="${esc(field.placeholder || '')}" value="${esc(preset)}">
                ${suggest}
            </div>`;
        }).join('');
        const actions = (current.actions || []).map((action) =>
            `<button type="button" class="btn text-sm" data-setup-action="${esc(action.id)}">${esc(action.label)}</button>`
        ).join('');
        if (actions) {
            fieldsEl.insertAdjacentHTML('beforeend', `<div class="flex flex-wrap gap-2 pt-1"><p class="text-faint text-xs w-full">MCP bind takes effect after the MCP service restarts.</p>${actions}</div>`);
        }
        applyWhen();
    }

    document.getElementById('setup-list')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-setup-open]');
        if (btn) open(btn.dataset.setupOpen);
    });
    modal?.addEventListener('click', (e) => {
        if (e.target.closest('[data-setup-close]')) close();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) close();
    });
    fieldsEl?.addEventListener('change', applyWhen);
    fieldsEl?.addEventListener('click', async (event) => {
        const replace = event.target.closest('[data-replace]');
        if (replace) {
            const input = fieldsEl.querySelector(`input[data-key="${CSS.escape(replace.dataset.replace)}"]`);
            input?.classList.remove('hidden');
            input?.focus();
            return;
        }
        const clear = event.target.closest('[data-clear-btn]');
        if (clear) {
            const input = fieldsEl.querySelector(`input[data-key="${CSS.escape(clear.dataset.clearBtn)}"]`);
            if (input) {
                input.value = '';
                input.dataset.cleared = '1';
            }
            const hint = fieldsEl.querySelector(`[data-hint-for="${CSS.escape(clear.dataset.clearBtn)}"]`);
            if (hint) hint.textContent = 'Clears on save.';
            return;
        }
        const copy = event.target.closest('[data-copy]');
        if (copy) {
            try {
                await navigator.clipboard.writeText(copy.dataset.copy || '');
                copy.textContent = 'Copied';
            } catch (err) {
                errorEl.textContent = 'Could not copy.';
                errorEl.classList.remove('hidden');
            }
            return;
        }
        const action = event.target.closest('[data-setup-action]');
        if (!action) return;
        action.disabled = true;
        errorEl.classList.add('hidden');
        try {
            const res = await fetch('/api/setup/service', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.getCsrfToken ? window.getCsrfToken() : '' },
                body: JSON.stringify({ action: action.dataset.setupAction }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Could not restart.');
            action.textContent = 'Restart queued';
        } catch (err) {
            errorEl.textContent = err.message || 'Could not restart.';
            errorEl.classList.remove('hidden');
            action.disabled = false;
        }
    });

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!current) return;
        errorEl.classList.add('hidden');
        const btn = form.querySelector('[type="submit"]');
        btn.disabled = true;
        try {
            const res = await fetch('/api/setup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.getCsrfToken ? window.getCsrfToken() : '' },
                body: JSON.stringify({ group: current.id, values: valuesFromForm() }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Could not save.');
            state.groups = data.setup.groups;
            data.setup.groups.forEach((g) => {
                const el = document.querySelector(`[data-status-for="${CSS.escape(g.id)}"]`);
                if (el) el.textContent = g.status;
            });
            close();
        } catch (err) {
            errorEl.textContent = err.message || 'Could not save.';
            errorEl.classList.remove('hidden');
        } finally {
            btn.disabled = false;
        }
    });
})();
