/**
 * lifedash-trackers.js — /settings/trackers: create, edit, reorder and archive metrics.
 */
'use strict';

(function () {
    const { api, esc } = window.LD;

    const form = document.getElementById('trk-add-form');
    const listEl = document.getElementById('trk-list');
    const showArchived = document.getElementById('trk-show-archived');
    const addError = document.getElementById('trk-add-error');
    const addModal = document.getElementById('trk-add-modal');
    const editModal = document.getElementById('trk-edit-modal');
    const editForm = document.getElementById('trk-edit-form');
    const listError = document.getElementById('trk-list-error');
    if (!form || !listEl || !addModal || !editModal || !editForm) return;

    const GROUPS = [
        ['habit', 'Habits'],
        ['scale', 'Mood & scales'],
        ['number', 'Numbers'],
        ['duration', 'Durations'],
    ];

    let metrics = [];

    // ---------------------------------------------------------------
    // Add form: show only the fields relevant to the chosen kind
    // ---------------------------------------------------------------
    function syncKindFields() {
        const kind = form.elements.kind.value;
        form.querySelectorAll('[data-kinds]').forEach((el) => {
            const show = el.dataset.kinds.split(' ').includes(kind);
            // class hidden keeps these out of the modal focus trap; inline
            // display wins over the label's block utility.
            el.classList.toggle('hidden', !show);
            el.style.display = show ? '' : 'none';
        });
    }

    function openModal(modal) {
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
    }

    function closeModal(modal) {
        modal.classList.add('hidden');
        if (!document.querySelector('.app-modal:not(.hidden)')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    function bindModal(modal) {
        modal.addEventListener('click', (e) => {
            if (e.target.closest('[data-close]')) closeModal(modal);
        });
    }
    bindModal(addModal);
    bindModal(editModal);

    document.getElementById('trk-add-open')?.addEventListener('click', () => {
        addError.classList.add('hidden');
        openModal(addModal);
    });
    form.elements.kind.addEventListener('change', syncKindFields);
    syncKindFields();

    function num(v) {
        return v === '' || v === null || v === undefined ? undefined : Number(v);
    }

    /** Build a config object for a kind from form-ish values (omits blanks). */
    function configFrom(kind, v) {
        const c = {};
        const put = (k, val) => { if (val !== undefined && val !== '' && !Number.isNaN(val)) c[k] = val; };
        if (kind === 'habit' || kind === 'scale') put('emoji', (v.emoji || '').trim() || undefined);
        if (kind === 'habit') put('target_per_week', num(v.target_per_week));
        if (kind === 'number') {
            put('decimals', num(v.decimals));
            put('goal', num(v.goal));
            put('goal_direction', v.goal_direction || undefined);
        }
        if (kind === 'duration') {
            const mins = num(v.goal_minutes);
            put('goal_seconds', mins !== undefined ? Math.round(mins * 60) : undefined);
        }
        if (kind === 'scale') {
            put('min', num(v.min));
            put('max', num(v.max));
            if (v.labels !== undefined) {
                const parts = String(v.labels).split(',').map((s) => s.trim());
                const min = num(v.min) ?? 1;
                const labels = {};
                parts.forEach((p, i) => { if (p) labels[String(min + i)] = p; });
                if (Object.keys(labels).length) c.labels = labels;
            }
        }
        return c;
    }

    function showError(el, err) {
        const fields = err.fields || {};
        el.textContent = Object.values(fields)[0] || err.message;
        el.classList.remove('hidden');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        addError.classList.add('hidden');
        const v = Object.fromEntries(new FormData(form).entries());
        const kind = v.kind;
        const body = { name: (v.name || '').trim(), kind, config: configFrom(kind, v) };
        if (kind === 'number' && v.unit && v.unit.trim()) body.unit = v.unit.trim();
        if (!body.name) {
            showError(addError, { message: 'Name is required.' });
            return;
        }
        const btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        try {
            await api.post('/metrics', body);
            form.reset();
            form.elements.kind.value = kind; // keep the kind for adding several in a row
            syncKindFields();
            await load();
            closeModal(addModal);
        } catch (err) {
            showError(addError, err);
        } finally {
            btn.disabled = false;
        }
    });

    // ---------------------------------------------------------------
    // List
    // ---------------------------------------------------------------
    async function load() {
        try {
            const res = await api.get('/metrics' + (showArchived.checked ? '?archived=1' : ''));
            metrics = res.metrics;
            render();
        } catch (err) {
            listEl.innerHTML = `<p class="ld-error">${esc(err.message)}</p>`;
        }
    }

    function describe(m) {
        const c = m.config || {};
        const bits = [m.kind];
        if (m.kind === 'habit' && c.target_per_week) bits.push(`${c.target_per_week}×/week`);
        if (m.kind === 'habit' && c.goal_metric_id) bits.push(`auto when metric ${c.goal_metric_id} hits ${c.goal_threshold ?? 'its goal'}`);
        if (m.kind === 'scale') bits.push(`${c.min ?? 1}–${c.max ?? 5}`);
        if (m.kind === 'number' && m.unit) bits.push(m.unit);
        if (m.kind === 'number' && c.goal !== undefined) bits.push(`goal ${c.goal}${c.goal_direction === 'down' ? ' ↓' : c.goal_direction === 'up' ? ' ↑' : ''}`);
        if (m.kind === 'duration' && c.goal_seconds) bits.push(`goal ${Math.round(c.goal_seconds / 60)} min`);
        bits.push(`key ${m.key}`);
        if (m.created_by !== 'user') bits.push(`by ${m.created_by}`);
        return bits.join(' · ');
    }

    function editHtml(m) {
        const c = m.config || {};
        const field = (label, html) => `<label class="block ld-field"><span>${esc(label)}</span>${html}</label>`;
        let extra = '';
        if (m.kind === 'habit' || m.kind === 'scale') {
            extra += field('Emoji', `<input class="input w-full" name="emoji" maxlength="8" value="${esc(c.emoji || '')}">`);
        }
        if (m.kind === 'habit') {
            extra += field('Target days / week', `<input class="input w-full" type="number" name="target_per_week" min="1" max="7" value="${esc(c.target_per_week ?? '')}">`);
        }
        if (m.kind === 'scale') {
            const labels = [];
            for (let i = c.min ?? 1; i <= (c.max ?? 5); i++) labels.push((c.labels || {})[String(i)] || '');
            extra += field(`Labels ${c.min ?? 1}→${c.max ?? 5} (comma-separated)`, `<input class="input w-full" name="labels" value="${esc(labels.join(', '))}">`);
        }
        if (m.kind === 'number') {
            extra += field('Unit', `<input class="input w-full" name="unit" maxlength="16" value="${esc(m.unit || '')}">`)
                + field('Decimals', `<input class="input w-full" type="number" name="decimals" min="0" max="4" value="${esc(c.decimals ?? '')}">`)
                + field('Goal', `<input class="input w-full" type="number" step="any" name="goal" value="${esc(c.goal ?? '')}">`)
                + field('Better when', `<select class="input w-full" name="goal_direction">
                    <option value="">—</option>
                    <option value="up" ${c.goal_direction === 'up' ? 'selected' : ''}>Higher</option>
                    <option value="down" ${c.goal_direction === 'down' ? 'selected' : ''}>Lower</option></select>`);
        }
        if (m.kind === 'duration') {
            extra += field('Goal (minutes)', `<input class="input w-full" type="number" name="goal_minutes" min="1" max="10080" value="${c.goal_seconds ? Math.round(c.goal_seconds / 60) : ''}">`);
        }
        return field('Name', `<input class="input w-full" name="name" maxlength="80" required value="${esc(m.name)}">`) + extra;
    }

    function rowHtml(m, idx, group) {
        const c = m.config || {};
        return `
            <div class="ld-metric-row${m.archived ? ' is-archived' : ''}" data-id="${m.id}">
                <div class="ld-metric-emoji">${esc(c.emoji || (m.kind === 'habit' ? '✓' : m.kind === 'scale' ? '◐' : m.kind === 'duration' ? '⏱' : '#'))}</div>
                <div class="min-w-0">
                    <div class="text-primary text-sm">${esc(m.name)}</div>
                    <div class="text-faint mono text-xs">${esc(describe(m))}</div>
                </div>
                <div class="ld-metric-actions">
                    ${m.archived ? '' : `
                    <button type="button" class="btn text-xs" data-move="-1" ${idx === 0 ? 'disabled' : ''} aria-label="Move up">↑</button>
                    <button type="button" class="btn text-xs" data-move="1" ${idx === group.length - 1 ? 'disabled' : ''} aria-label="Move down">↓</button>
                    <button type="button" class="btn text-xs" data-edit-open>Edit</button>`}
                    <button type="button" class="btn text-xs" data-archive="${m.archived ? '0' : '1'}">${m.archived ? 'Restore' : 'Archive'}</button>
                </div>
            </div>`;
    }

    function render() {
        if (!metrics.length) {
            listEl.innerHTML = window.DashboardEmpty.html({
                title: 'No habits yet',
                body: 'Add one with Add tracker.',
                actionLabel: 'Habits',
                actionHref: '/settings/trackers',
            });
            return;
        }
        listEl.innerHTML = GROUPS.map(([kind, label]) => {
            const group = metrics.filter((m) => m.kind === kind);
            if (!group.length) return '';
            return `<h3 class="ld-type-group" style="margin:0.75rem 0 0.35rem">${esc(label)}</h3>`
                + group.map((m, i) => rowHtml(m, i, group)).join('');
        }).join('');
    }

    function showListError(err) {
        if (!listError) return;
        listError.textContent = err.message || 'Something went wrong.';
        listError.classList.remove('hidden');
    }

    function clearListError() {
        if (!listError) return;
        listError.textContent = '';
        listError.classList.add('hidden');
    }

    function openEdit(m) {
        editForm.dataset.edit = String(m.id);
        document.getElementById('trk-edit-title').textContent = 'Edit ' + m.name;
        document.getElementById('trk-edit-fields').innerHTML = editHtml(m);
        const errEl = editForm.querySelector('[data-error]');
        errEl.textContent = '';
        errEl.classList.add('hidden');
        openModal(editModal);
    }

    listEl.addEventListener('click', async (e) => {
        const row = e.target.closest('.ld-metric-row');
        if (!row) return;
        const id = Number(row.dataset.id);
        const m = metrics.find((x) => x.id === id);
        const btn = e.target.closest('button');
        if (!m || !btn) return;

        try {
            if (btn.hasAttribute('data-edit-open')) {
                openEdit(m);
            } else if (btn.dataset.archive !== undefined) {
                clearListError();
                btn.disabled = true;
                await api.post(`/metrics/${id}/archive`, { archived: btn.dataset.archive === '1' });
                await load();
            } else if (btn.dataset.move !== undefined) {
                clearListError();
                // Reorder within the kind group, then persist the full active order.
                const active = metrics.filter((x) => !x.archived);
                const group = active.filter((x) => x.kind === m.kind);
                const gi = group.indexOf(m);
                const swap = group[gi + Number(btn.dataset.move)];
                if (!swap) return;
                const order = active.map((x) => x.id);
                const a = order.indexOf(m.id);
                const b = order.indexOf(swap.id);
                [order[a], order[b]] = [order[b], order[a]];
                btn.disabled = true;
                await api.post('/metrics/reorder', { ids: order });
                await load();
            }
        } catch (err) {
            showListError(err);
        }
    });

    editForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = editForm;
        const id = Number(f.dataset.edit);
        const m = metrics.find((x) => x.id === id);
        if (!m) return;
        const v = Object.fromEntries(new FormData(f).entries());
        const c = m.config || {};
        const merged = m.kind === 'scale' ? Object.assign({ min: c.min, max: c.max }, v) : v;
        const body = { name: (v.name || '').trim(), config: configFrom(m.kind, merged) };
        if (m.kind === 'habit') {
            if (c.goal_metric_id) body.config.goal_metric_id = Number(c.goal_metric_id);
            if (c.goal_threshold !== undefined && c.goal_threshold !== null && c.goal_threshold !== '') {
                body.config.goal_threshold = Number(c.goal_threshold);
            }
        }
        if (m.kind === 'number') body.unit = (v.unit || '').trim() || null;
        const errEl = f.querySelector('[data-error]');
        errEl.classList.add('hidden');
        try {
            await api.post(`/metrics/${id}/update`, body);
            closeModal(editModal);
            await load();
        } catch (err) {
            showError(errEl, err);
        }
    });

    showArchived.addEventListener('change', load);
    load();
})();
