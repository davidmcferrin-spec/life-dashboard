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
            const source = field.source === 'saved' ? 'Saved. Leave blank to keep it.' : (field.source === 'env' ? 'Using the environment value until you save a new one.' : '');
            const clear = field.secret && field.source === 'saved'
                ? `<label class="flex items-center gap-2 text-xs text-faint mt-1"><input type="checkbox" data-key="${esc(field.key)}" data-clear="1"> Remove saved value</label>`
                : '';
            return `<div class="setup-field"${when}>
                <label class="ld-field" for="setup-${esc(field.key)}">${esc(field.label)}</label>
                <input id="setup-${esc(field.key)}" class="input text-sm w-full" data-key="${esc(field.key)}"
                    type="${field.secret ? 'password' : 'text'}" autocomplete="off" placeholder="${esc(field.placeholder || '')}">
                ${source ? `<p class="text-faint text-xs mono mt-1">${esc(source)}</p>` : ''}
                ${clear}
            </div>`;
        }).join('');
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
