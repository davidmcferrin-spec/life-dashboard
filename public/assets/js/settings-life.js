'use strict';

(function () {
    const { api, esc } = window.LD;
    const form = document.getElementById('life-form');

    function field(row) {
        const m = row.metric;
        const value = row.latest ? row.latest.value : '';
        if (m.kind === 'habit') {
            const on = row.latest && row.latest.value >= 1;
            return `<label class="ld-life-row"><span>${esc(m.name)}</span><input type="checkbox" data-habit="${m.id}" ${on ? 'checked' : ''}></label>`;
        }
        if (m.kind === 'duration') {
            const minutes = row.latest ? Math.round(row.latest.value / 60) : '';
            return `<label class="ld-life-row"><span>${esc(m.name)} (minutes)</span><input class="input" type="number" min="0" step="1" data-metric="${m.id}" data-unit="minutes" value="${esc(minutes)}"></label>`;
        }
        const shown = value === '' ? '' : value;
        return `<label class="ld-life-row"><span>${esc(m.name)}${m.unit ? ' (' + esc(m.unit) + ')' : ''}</span><input class="input" type="number" step="any" data-metric="${m.id}" value="${esc(shown)}"></label>`;
    }

    async function load() {
        const [today, hooks] = await Promise.all([api.get('/life/today'), api.get('/life/hooks')]);
        form.innerHTML = (today.rows || []).map(field).join('') + '<button type="submit" class="btn-primary">Save today</button>';
        document.getElementById('life-hooks').innerHTML = (hooks.hooks || []).map((h) =>
            `<div class="ld-life-row"><span>${esc(h.name)} <span class="ld-meta">${esc(h.prefix)}…${h.revoked ? ' revoked' : ''}</span></span>${h.revoked ? '' : `<button type="button" class="btn text-xs" data-revoke="${h.id}">Revoke</button>`}</div>`
        ).join('') || '<p class="ld-meta">No webhooks.</p>';
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const today = (await api.get('/life/today')).today;
        const jobs = [];
        form.querySelectorAll('[data-metric]').forEach((input) => {
            if (input.value === '') return;
            let value = Number(input.value);
            if (input.dataset.unit === 'minutes') value = Math.round(value * 60);
            jobs.push(api.post('/metrics/' + input.dataset.metric + '/datapoints', {
                value, date: today, dedupe_key: 'day:' + today, source: 'api',
            }));
        });
        form.querySelectorAll('[data-habit]').forEach((input) => {
            const want = input.checked;
            jobs.push(api.get('/habits').then((res) => {
                const habit = (res.habits || []).find((h) => h.id === Number(input.dataset.habit));
                if (Boolean(habit && habit.done_today) !== want) {
                    return api.post('/habits/' + input.dataset.habit + '/toggle', { date: today });
                }
            }));
        });
        await Promise.all(jobs);
        await load();
    });

    document.getElementById('life-hook-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = new FormData(e.target).get('name') || 'Home Assistant';
        const res = await api.post('/life/hooks', { name: String(name) });
        const once = document.getElementById('life-hook-once');
        once.hidden = false;
        once.textContent = location.origin + res.hook.path;
        e.target.reset();
        await load();
    });

    document.getElementById('life-hooks').addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-revoke]');
        if (!btn) return;
        await api.post('/life/hooks/' + btn.dataset.revoke + '/revoke', {});
        await load();
    });

    load().catch((err) => { form.textContent = err.message; });
})();
