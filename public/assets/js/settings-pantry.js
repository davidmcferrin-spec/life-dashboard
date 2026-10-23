/**
 * Edit category shelf-life defaults.
 */
'use strict';

(function () {
    const { api, esc } = window.LD;
    const root = document.getElementById('pantry-shelf');
    const error = document.getElementById('pantry-shelf-error');
    if (!root || !api) return;

    function showError(err) {
        error.textContent = err && err.message ? err.message : 'Could not save.';
        error.classList.remove('hidden');
    }

    function field(name, value) {
        const shown = value === null || value === undefined ? '' : String(value);
        return `<label class="block text-xs text-muted">${esc(name.replace('_days', ''))}
            <input class="input mono text-sm w-full" name="${esc(name)}" inputmode="numeric" value="${esc(shown)}" placeholder="inherit none"></label>`;
    }

    async function load() {
        const data = await api.get('/pantry/categories');
        root.innerHTML = (data.categories || []).map((row) => `
            <form class="settings-block" data-category="${esc(row.category)}">
                <h2 class="settings-section">${esc(row.category)}</h2>
                <div class="grid grid-cols-2 gap-2 mt-2 sm:grid-cols-4">
                    ${field('pantry_days', row.pantry_days)}
                    ${field('fridge_days', row.fridge_days)}
                    ${field('freezer_days', row.freezer_days)}
                    ${field('opened_days', row.opened_days)}
                </div>
                <button type="submit" class="btn text-xs mt-2">Save</button>
            </form>`).join('');
        root.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const body = { category: form.dataset.category };
                ['pantry_days', 'fridge_days', 'freezer_days', 'opened_days'].forEach((key) => {
                    const value = new FormData(form).get(key);
                    body[key] = value === '' ? null : Number(value);
                });
                api.post('/pantry/categories', body).then(() => {
                    error.classList.add('hidden');
                }).catch(showError);
            });
        });
    }

    load().catch(showError);
})();
