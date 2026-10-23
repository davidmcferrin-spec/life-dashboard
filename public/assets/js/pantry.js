/**
 * Pantry page: inventory, scan, purge, products, recipes, and the meal builder.
 * BarcodeDetector when the browser has it, otherwise the vendored ZXing build.
 * The file is same-origin because the page CSP only allows scripts from 'self'.
 */
'use strict';

(function () {
    const { api, esc } = window.LD;
    const body = document.getElementById('pantry-body');
    const errorEl = document.getElementById('pantry-error');
    if (!body || !api) return;

    const ZXING_SRC = '/assets/vendor/zxing/zxing-0.21.3.min.js';
    const ZXING_SRI = 'sha384-BzBxP10ZE72aitqj5UMmUsbKFliP/DZqA8Wq+BNNhlIJDGoEd1tpkMYXOg9+n6sB';
    const DEBOUNCE_MS = 2500;

    const view = new URLSearchParams(location.search).get('view') || 'inventory';
    document.querySelectorAll('#pantry-nav a[data-view]').forEach((link) => {
        link.classList.toggle('is-on', link.dataset.view === view);
    });
    if (view === 'meals') {
        const pantryApp = document.getElementById('pantry-app');
        if (pantryApp) pantryApp.classList.add('is-meals');
    }

    let scanStop = null;
    let lastCode = '';
    let lastAt = 0;
    let sheetOpen = false;
    let onEditKey = null;

    function fail(err) {
        errorEl.textContent = (err && err.fields && Object.values(err.fields)[0]) || (err && err.message) || 'Something went wrong.';
        errorEl.classList.remove('hidden');
    }

    function clearError() {
        errorEl.classList.add('hidden');
        errorEl.textContent = '';
    }

    function suggestUseBy(shelf, place) {
        if (!shelf || shelf[place] === null || shelf[place] === undefined || shelf[place] === '') return '';
        const when = new Date();
        when.setDate(when.getDate() + Number(shelf[place]));
        const month = String(when.getMonth() + 1).padStart(2, '0');
        const day = String(when.getDate()).padStart(2, '0');
        return when.getFullYear() + '-' + month + '-' + day;
    }

    function qtyText(lot) {
        const q = Number(lot.quantity);
        const shown = Math.abs(q - Math.round(q)) < 0.001 ? String(Math.round(q)) : String(Math.round(q * 1000) / 1000);
        const level = lot.qty_level ? ' · ' + lot.qty_level.replace('_', ' ') : '';
        return shown + ' ' + (lot.unit || '') + level;
    }

    function lotRow(lot, extra) {
        return `<li class="ld-pantry-lot" data-lot="${lot.id}">
            <div class="ld-pantry-lot-main">
                <span class="ld-pantry-name">${esc(lot.name)}${lot.brand ? ' <span class="ld-meta">' + esc(lot.brand) + '</span>' : ''}</span>
                <span class="ld-pantry-qty ld-pantry-${esc(lot.freshness)}">${esc(qtyText(lot))}${lot.use_by ? ' · ' + esc(lot.use_by) : ''}</span>
            </div>
            <div class="ld-pantry-actions mt-1">${extra}</div>
        </li>`;
    }

    function go(query) {
        const url = '/pantry' + (query ? '?' + query : '');
        if (view === 'inventory') {
            history.replaceState(null, '', url);
            inventory().catch(fail);
            return;
        }
        window.location.href = url;
    }

    const LIVE_MS = 400;
    let liveTimer = null;
    let booted = false;
    let liveQueued = false;

    function affectsOpenView(table) {
        const sections = {
            pantry_lots: ['inventory', 'purge', 'products', 'recipes', 'meals'],
            pantry_products: ['inventory', 'purge', 'products', 'recipes', 'meals'],
            pantry_recipes: ['recipes', 'meals'],
            pantry_recipe_ingredients: ['recipes', 'meals'],
            meal_components: ['meals'],
            meal_pairings: ['meals'],
            shopping_lists: ['meals'],
            shopping_items: ['meals'],
        };
        return (sections[table] || []).indexOf(view) !== -1;
    }

    function refreshOpen() {
        if (view === 'inventory') return inventory();
        if (view === 'purge') return purge();
        if (view === 'products') return products();
        if (view === 'recipes') return recipes();
        if (view === 'meals') return meals(true);
        return Promise.resolve();
    }

    function scheduleLive() {
        if (!booted) {
            liveQueued = true;
            return;
        }
        clearTimeout(liveTimer);
        liveTimer = setTimeout(() => {
            liveTimer = null;
            refreshOpen().catch(fail);
        }, LIVE_MS);
    }

    let lotCache = [];
    let inventoryBound = false;
    let inventoryGen = 0;

    function paintLots() {
        const search = document.getElementById('pantry-search');
        const lotsEl = document.getElementById('pantry-lots');
        if (!search || !lotsEl) return;
        const q = (search.value || '').toLowerCase();
        const locBtn = body.querySelector('[data-loc].is-on');
        const loc = locBtn ? locBtn.dataset.loc : '';
        const groups = { fridge: [], freezer: [], pantry: [] };
        lotCache.forEach((lot) => {
            if (loc && lot.location !== loc) return;
            const hay = (lot.name + ' ' + (lot.brand || '') + ' ' + (lot.category || '')).toLowerCase();
            if (q && !hay.includes(q)) return;
            if (groups[lot.location]) groups[lot.location].push(lot);
        });
        lotsEl.innerHTML = ['fridge', 'freezer', 'pantry'].map((key) => {
            if (!groups[key].length) return '';
            const rows = groups[key].map((lot) => lotRow(lot, lotActions(lot))).join('');
            return `<section class="ld-pantry-group"><h2 class="ld-pantry-label">${esc(key)}</h2><ul>${rows}</ul></section>`;
        }).join('') || window.DashboardEmpty.html({
            title: 'Nothing matches',
            body: 'Try another search, or clear the filter.',
            actionLabel: 'Pantry',
            actionHref: '/pantry',
        });
    }

    async function inventory() {
        const gen = ++inventoryGen;
        const params = new URLSearchParams(location.search);
        const status = params.get('status') || 'on_hand';
        const category = params.get('category') || '';
        const query = 'status=' + encodeURIComponent(status) + (category ? '&category=' + encodeURIComponent(category) : '');
        const [data, cats] = await Promise.all([
            api.get('/pantry/lots?' + query),
            api.get('/pantry/categories'),
        ]);
        if (gen !== inventoryGen) return;
        lotCache = data.lots || [];
        if (!document.getElementById('pantry-search')) {
            const catOptions = (cats.categories || []).map((row) =>
                `<option value="${esc(row.category)}"${row.category === category ? ' selected' : ''}>${esc(row.category)}</option>`
            ).join('');
            const statuses = [
                ['on_hand', 'on hand'],
                ['any', 'any'],
                ['used', 'used'],
                ['expired', 'expired'],
                ['tossed', 'tossed'],
            ];
            const statusOptions = statuses.map(([value, label]) =>
                `<option value="${value}"${value === status ? ' selected' : ''}>${label}</option>`
            ).join('');
            body.innerHTML = `
                <div class="ld-pantry-filters">
                    <input id="pantry-search" class="input flex-1" placeholder="Search" aria-label="Search pantry">
                    <select id="pantry-status" class="input" aria-label="Status" title="Any is everything except tossed.">${statusOptions}</select>
                    <select id="pantry-category" class="input" aria-label="Category">
                        <option value="">All categories</option>${catOptions}
                    </select>
                    <button type="button" data-loc="" class="is-on">All</button>
                    <button type="button" data-loc="fridge">Fridge</button>
                    <button type="button" data-loc="freezer">Freezer</button>
                    <button type="button" data-loc="pantry">Pantry</button>
                </div>
                <div id="pantry-lots"></div><div id="pantry-sheet"></div>`;
        } else {
            const statusSel = document.getElementById('pantry-status');
            if (statusSel && statusSel.value !== status) statusSel.value = status;
            const catSel = document.getElementById('pantry-category');
            if (catSel) {
                const names = (cats.categories || []).map((row) => row.category);
                const have = [...catSel.options].slice(1).map((opt) => opt.value);
                if (have.join('|') !== names.join('|')) {
                    const keep = catSel.value;
                    catSel.innerHTML = '<option value="">All categories</option>' + names.map((name) =>
                        `<option value="${esc(name)}">${esc(name)}</option>`
                    ).join('');
                    catSel.value = names.indexOf(keep) === -1 ? '' : keep;
                }
            }
        }
        paintLots();
        if (inventoryBound) return;
        inventoryBound = true;
        document.getElementById('pantry-search').addEventListener('input', paintLots);
        document.getElementById('pantry-status').addEventListener('change', (event) => {
            const next = new URLSearchParams();
            if (event.target.value !== 'on_hand') next.set('status', event.target.value);
            const cat = document.getElementById('pantry-category').value;
            if (cat) next.set('category', cat);
            go(next.toString());
        });
        document.getElementById('pantry-category').addEventListener('change', (event) => {
            const next = new URLSearchParams();
            const current = new URLSearchParams(location.search).get('status') || 'on_hand';
            if (current !== 'on_hand') next.set('status', current);
            if (event.target.value) next.set('category', event.target.value);
            go(next.toString());
        });
        body.querySelectorAll('[data-loc]').forEach((btn) => {
            btn.addEventListener('click', () => {
                body.querySelectorAll('[data-loc]').forEach((el) => el.classList.remove('is-on'));
                btn.classList.add('is-on');
                paintLots();
            });
        });
        body.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-act]');
            if (!btn) return;
            const lot = lotCache.find((row) => row.id === Number(btn.dataset.id));
            if (!lot) return;
            const act = btn.dataset.act;
            if (act === 'consume') {
                openConsume(lot);
            } else if (act === 'open') {
                api.post('/pantry/lots/' + lot.id + '/open', {}).then(() => inventory()).catch(fail);
            } else if (act === 'toss') {
                api.post('/pantry/lots/' + lot.id + '/toss', {}).then(() => inventory()).catch(fail);
            } else if (act === 'move') {
                openMove(lot);
            } else if (act === 'edit') {
                openEdit(lot);
            }
        });
    }

    function lotActions(lot) {
        const id = lot.id;
        if (lot.status === 'used') {
            return `
            <button type="button" data-act="edit" data-id="${id}" data-tip="Set a quantity above zero to put this lot back on hand." aria-label="Edit. Set a quantity above zero to put this lot back on hand.">Edit</button>
            <span class="ld-meta">used</span>`;
        }
        if (lot.status !== 'on_hand') {
            return `<span class="ld-meta">${esc(String(lot.status || '').replace('_', ' '))}</span>`;
        }
        return `
            <button type="button" data-act="consume" data-id="${id}" data-tip="Record some of this as used. A fraction stays on hand. Using all of it marks the lot used." aria-label="Use. Record some of this as used. A fraction stays on hand. Using all of it marks the lot used.">Use</button>
            <button type="button" data-act="open" data-id="${id}" data-tip="Mark the package opened and recompute the use-by date. The quantity stays the same." aria-label="Open. Mark the package opened and recompute the use-by date. The quantity stays the same.">Open</button>
            <button type="button" data-act="move" data-id="${id}" data-tip="Move all or part of this lot to the fridge, freezer, or pantry." aria-label="Move. Move all or part of this lot to the fridge, freezer, or pantry.">Move</button>
            <button type="button" data-act="edit" data-id="${id}" data-tip="Change the quantity, place, use-by date, or store." aria-label="Edit. Change the quantity, place, use-by date, or store.">Edit</button>
            <button type="button" data-act="toss" data-id="${id}" data-tip="Take this off the shelf. It stays on the tossed list and is not deleted." aria-label="Toss. Take this off the shelf. It stays on the tossed list and is not deleted.">Toss</button>`;
    }

    function dateParts(iso) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!match) return { y: '', m: '', d: '' };
        return { y: match[1], m: String(Number(match[2])), d: String(Number(match[3])) };
    }

    function dateFields(name, label, iso, required) {
        const parts = dateParts(iso);
        const req = required ? ' required' : '';
        return `<fieldset class="ld-pantry-date mt-2" data-date="${name}">
            <legend class="text-xs text-muted">${label}</legend>
            <div class="flex gap-2 mt-1">
                <label class="text-xs text-muted">Month
                    <input class="input" name="${name}_m" inputmode="numeric" maxlength="2" placeholder="MM" value="${esc(parts.m)}"${req} aria-label="${label} month">
                </label>
                <label class="text-xs text-muted">Day
                    <input class="input" name="${name}_d" inputmode="numeric" maxlength="2" placeholder="DD" value="${esc(parts.d)}"${req} aria-label="${label} day">
                </label>
                <label class="text-xs text-muted">Year
                    <input class="input" name="${name}_y" inputmode="numeric" maxlength="4" placeholder="YYYY" value="${esc(parts.y)}"${req} aria-label="${label} year">
                </label>
            </div>
        </fieldset>`;
    }

    function readDate(form, name) {
        const raw = (part) => String(form.querySelector('[name="' + name + '_' + part + '"]').value || '').trim();
        const m = raw('m');
        const d = raw('d');
        const y = raw('y');
        if (m === '' && d === '' && y === '') return '';
        const month = Number(m);
        const day = Number(d);
        const year = Number(y);
        if (!Number.isInteger(month) || month < 1 || month > 12 || !Number.isInteger(day) || day < 1 || day > 31 || !Number.isInteger(year) || String(year).length !== 4) {
            return null;
        }
        const iso = String(year) + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        const check = new Date(iso + 'T00:00:00');
        if (check.getFullYear() !== year || check.getMonth() + 1 !== month || check.getDate() !== day) return null;
        return iso;
    }

    function writeDate(form, name, iso) {
        const parts = dateParts(iso);
        form.querySelector('[name="' + name + '_m"]').value = parts.m;
        form.querySelector('[name="' + name + '_d"]').value = parts.d;
        form.querySelector('[name="' + name + '_y"]').value = parts.y;
    }

    function openMove(lot) {
        closeEdit();
        closeMove();
        closeConsume();
        const wrap = document.createElement('div');
        wrap.innerHTML = moveSheet(lot);
        const modal = wrap.firstElementChild;
        document.body.appendChild(modal);
        document.body.classList.add('app-modal-open');
        bindMove(lot, modal);
        modal.querySelector('[name="fridge"]')?.focus();
    }

    function closeMove() {
        if (onEditKey) {
            document.removeEventListener('keydown', onEditKey);
            onEditKey = null;
        }
        const modal = document.getElementById('pantry-move-modal');
        if (!modal) return;
        modal.remove();
        if (!document.querySelector('.app-modal')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    function openEdit(lot) {
        closeMove();
        closeEdit();
        closeConsume();
        const wrap = document.createElement('div');
        wrap.innerHTML = editSheet(lot);
        const modal = wrap.firstElementChild;
        document.body.appendChild(modal);
        document.body.classList.add('app-modal-open');
        bindEdit(lot, modal);
        modal.querySelector('[name="quantity"]')?.focus();
    }

    function closeEdit() {
        if (onEditKey) {
            document.removeEventListener('keydown', onEditKey);
            onEditKey = null;
        }
        const modal = document.getElementById('pantry-edit-modal');
        if (!modal) return;
        modal.remove();
        if (!document.querySelector('.app-modal')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    function moveSheet(lot) {
        const total = Number(lot.quantity);
        const field = (place, label) => {
            const value = place === lot.location ? total : 0;
            return `<label class="block text-xs text-muted mt-2">${label}
                <input class="input" name="${place}" type="number" step="any" min="0" inputmode="decimal" value="${value}" aria-label="${label} ${esc(lot.unit)}">
            </label>`;
        };
        return `<div id="pantry-move-modal" class="app-modal" role="dialog" aria-modal="true" aria-labelledby="pantry-move-title">
            <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
            <div class="app-modal-panel surface">
                <form id="move-form">
                    <div class="flex items-start justify-between gap-3">
                        <p id="pantry-move-title" class="text-sm">Split ${esc(lot.name)} (${esc(String(lot.quantity))} ${esc(lot.unit)})</p>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <p class="text-xs text-muted mt-1">Counts are ${esc(lot.unit)}, not packages. They must add up to ${esc(String(lot.quantity))}.</p>
                    ${field('fridge', 'Fridge')}
                    ${field('freezer', 'Freezer')}
                    ${field('pantry', 'Pantry')}
                    <div class="flex gap-2 mt-3">
                        <button type="submit" class="btn-primary text-sm">Split</button>
                        <button type="button" class="btn text-sm" data-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>`;
    }

    function bindMove(lot, modal) {
        const form = modal.querySelector('#move-form');
        modal.querySelectorAll('[data-close]').forEach((el) => {
            el.addEventListener('click', () => closeMove());
        });
        onEditKey = (event) => {
            if (event.key === 'Escape') closeMove();
        };
        document.addEventListener('keydown', onEditKey);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = new FormData(form);
            const pieces = [];
            let sum = 0;
            ['fridge', 'freezer', 'pantry'].forEach((place) => {
                const quantity = Number(data.get(place) || 0);
                if (quantity > 0) {
                    pieces.push({ quantity: quantity, location: place });
                    sum += quantity;
                }
            });
            const total = Number(lot.quantity);
            if (!pieces.length || Math.abs(sum - total) > 0.001) {
                fail({ message: 'Split quantities must add up to ' + total + ' ' + lot.unit + '.' });
                return;
            }
            const body = pieces.length === 1 && pieces[0].quantity === total
                ? { location: pieces[0].location, quantity: total }
                : { pieces: pieces };
            api.post('/pantry/lots/' + lot.id + '/split', body).then(() => {
                closeMove();
                return inventory();
            }).catch(fail);
        });
    }

    function editSheet(lot) {
        return `<div id="pantry-edit-modal" class="app-modal" role="dialog" aria-modal="true" aria-labelledby="pantry-edit-title">
            <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
            <div class="app-modal-panel surface">
                <form id="edit-form">
                    <div class="flex items-start justify-between gap-3">
                        <p id="pantry-edit-title" class="text-sm">Edit ${esc(lot.name)}</p>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <label class="block text-xs text-muted mt-2">Quantity
                        <input class="input w-full" name="quantity" type="number" step="any" min="0" inputmode="decimal" value="${esc(String(lot.quantity))}">
                    </label>
                    <div class="ld-pantry-locs mt-2">
                        <button type="button" data-location="fridge">Fridge</button>
                        <button type="button" data-location="freezer">Freezer</button>
                        <button type="button" data-location="pantry">Pantry</button>
                    </div>
                    ${dateFields('use_by', 'Use by', lot.use_by || '', false)}
                    <label class="block text-xs text-muted mt-2">Store
                        <input class="input w-full" name="store" value="${esc(lot.store || '')}">
                    </label>
                    <div class="flex gap-2 mt-3">
                        <button type="submit" class="btn-primary text-sm">Save lot</button>
                        <button type="button" class="btn text-sm" data-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>`;
    }

    function bindEdit(lot, modal) {
        const form = modal.querySelector('#edit-form');
        let place = lot.location;
        modal.querySelectorAll('[data-close]').forEach((el) => {
            el.addEventListener('click', () => closeEdit());
        });
        onEditKey = (event) => {
            if (event.key === 'Escape') closeEdit();
        };
        document.addEventListener('keydown', onEditKey);
        form.querySelectorAll('[data-location]').forEach((btn) => {
            if (btn.dataset.location === place) btn.classList.add('is-on');
            btn.addEventListener('click', () => {
                place = btn.dataset.location;
                form.querySelectorAll('[data-location]').forEach((el) => el.classList.remove('is-on'));
                btn.classList.add('is-on');
            });
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            const useBy = readDate(form, 'use_by');
            if (useBy === null) {
                fail({ message: 'Enter the use-by date as month, day, and year.' });
                return;
            }
            api.post('/pantry/lots/' + lot.id, {
                quantity: Number(data.quantity),
                location: place,
                use_by: useBy || null,
                store: data.store || null,
            }).then(() => {
                closeEdit();
                return inventory();
            }).catch(fail);
        });
    }

    function openConsume(lot) {
        closeMove();
        closeEdit();
        closeConsume();
        const wrap = document.createElement('div');
        wrap.innerHTML = `<div id="pantry-consume-modal" class="app-modal" role="dialog" aria-modal="true" aria-labelledby="pantry-consume-title">
            <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
            <div class="app-modal-panel surface">
                <form id="consume-form">
                    <div class="flex items-start justify-between gap-3">
                        <p id="pantry-consume-title" class="text-sm">Use ${esc(lot.name)}</p>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <label class="block text-xs text-muted mt-2">Quantity (${esc(lot.unit || 'each')})
                        <input class="input w-full" name="quantity" type="number" step="any" min="0" inputmode="decimal" value="1" required>
                    </label>
                    <p class="text-xs text-muted mt-1">${esc(String(lot.quantity))} on hand. A fraction stays on the lot.</p>
                    <div class="flex gap-2 mt-3">
                        <button type="submit" class="btn-primary text-sm">Use</button>
                        <button type="button" class="btn text-sm" data-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>`;
        const modal = wrap.firstElementChild;
        document.body.appendChild(modal);
        document.body.classList.add('app-modal-open');
        const form = modal.querySelector('#consume-form');
        modal.querySelectorAll('[data-close]').forEach((el) => {
            el.addEventListener('click', () => closeConsume());
        });
        onEditKey = (event) => {
            if (event.key === 'Escape') closeConsume();
        };
        document.addEventListener('keydown', onEditKey);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const quantity = Number(new FormData(form).get('quantity'));
            if (!Number.isFinite(quantity) || quantity <= 0) {
                fail({ message: 'Enter a quantity above zero.' });
                return;
            }
            api.post('/pantry/consume', { lot_id: lot.id, quantity: quantity }).then(() => {
                closeConsume();
                return inventory();
            }).catch(fail);
        });
        form.querySelector('[name="quantity"]').focus();
    }

    function closeConsume() {
        if (onEditKey) {
            document.removeEventListener('keydown', onEditKey);
            onEditKey = null;
        }
        const modal = document.getElementById('pantry-consume-modal');
        if (!modal) return;
        modal.remove();
        if (!document.querySelector('.app-modal')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    let purgeBound = false;
    let purgeGen = 0;

    function purgeRows(lots, checked) {
        return (lots || []).map((lot) => `
            <label class="ld-pantry-lot flex gap-2 items-start">
                <input type="checkbox" value="${lot.id}"${checked.has(String(lot.id)) ? ' checked' : ''}>
                <span class="ld-pantry-${esc(lot.freshness)}">${esc(lot.name)} · ${esc(lot.location)} · ${esc(lot.use_by || '')}</span>
            </label>`).join('');
    }

    async function purge() {
        const gen = ++purgeGen;
        const form = document.getElementById('purge-form');
        const days = form
            ? String(form.days.value || '3')
            : String(new URLSearchParams(location.search).get('days') || '3');
        const checked = new Set(form
            ? [...form.querySelectorAll('input[type="checkbox"]:checked')].map((box) => box.value)
            : []);
        const data = await api.get('/pantry/expiring?days=' + encodeURIComponent(days));
        if (gen !== purgeGen) return;
        const rows = purgeRows(data.lots, checked) || window.DashboardEmpty.html({
            title: 'Nothing due',
            body: 'No lots need to be used up in this window.',
            actionLabel: 'Inventory',
            actionHref: '/pantry',
        });
        if (!form) {
            body.innerHTML = `
                <form id="purge-form">
                    <label class="text-xs text-muted">Due within days
                        <input class="input" name="days" inputmode="numeric" value="${esc(days)}">
                    </label>
                    <div class="mt-3" id="purge-lots">${rows}</div>
                    <button type="submit" class="btn-primary text-sm mt-3">Mark tossed</button>
                </form>`;
        } else {
            const list = document.getElementById('purge-lots');
            if (list) list.innerHTML = rows;
        }
        if (purgeBound) return;
        purgeBound = true;
        document.getElementById('purge-form').addEventListener('submit', (event) => {
            event.preventDefault();
            const current = event.target;
            const nextDays = current.days.value;
            if (document.activeElement && document.activeElement.name === 'days') {
                history.replaceState(null, '', '/pantry?view=purge&days=' + encodeURIComponent(nextDays));
                purge().catch(fail);
                return;
            }
            const ids = [...current.querySelectorAll('input[type="checkbox"]:checked')].map((box) => Number(box.value));
            if (!ids.length) {
                fail({ message: 'Select at least one lot.' });
                return;
            }
            api.post('/pantry/purge', { lot_ids: ids }).then(() => purge()).catch(fail);
        });
    }

    let productCache = [];
    let productBound = false;
    let productGen = 0;

    function paintProducts() {
        const input = document.getElementById('product-search');
        const list = document.getElementById('product-list');
        if (!input || !list) return;
        const q = input.value.toLowerCase();
        list.innerHTML = productCache.filter((row) => {
            return !q || (row.name + ' ' + (row.brand || '') + ' ' + (row.upc || '')).toLowerCase().includes(q);
        }).slice(0, 40).map((row) => `
            <li class="ld-pantry-lot">
                <button type="button" class="text-left w-full" data-product="${row.id}">
                    ${esc(row.name)} <span class="ld-meta">${esc(row.category)}${row.units_per_package ? ' · ' + esc(String(row.units_per_package)) + ' ' + esc(row.piece_unit || '') : ''}${row.upc ? ' · ' + esc(row.upc) : ''}</span>
                </button>
            </li>`).join('');
    }

    async function products() {
        const gen = ++productGen;
        const data = await api.get('/pantry/products');
        if (gen !== productGen) return;
        productCache = data.products || [];
        if (!document.getElementById('product-search')) {
            body.innerHTML = `
                <input id="product-search" class="input w-full" placeholder="Search produce or a product" aria-label="Search products">
                <ul id="product-list" class="mt-3"></ul>
                <div id="product-sheet"></div>`;
        }
        paintProducts();
        if (productBound) return;
        productBound = true;
        document.getElementById('product-search').addEventListener('input', paintProducts);
        body.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-product]');
            if (!btn) return;
            const product = productCache.find((row) => row.id === Number(btn.dataset.product));
            if (!product) return;
            document.getElementById('product-sheet').innerHTML = productSheet(product);
            bindProduct(product);
        });
    }

    function productSheet(product) {
        const life = product;
        return `<form class="ld-pantry-sheet" id="product-form">
            <label class="block text-xs text-muted">Name <input class="input w-full" name="name" value="${esc(product.name)}"></label>
            <label class="block text-xs text-muted mt-2">Brand <input class="input w-full" name="brand" value="${esc(product.brand || '')}"></label>
            <label class="block text-xs text-muted mt-2">UPC <input class="input w-full" name="upc" value="${esc(product.upc || '')}"></label>
            <label class="block text-xs text-muted mt-2">Category <input class="input w-full" name="category" value="${esc(product.category)}"></label>
            <label class="block text-xs text-muted mt-2">Min on hand <input class="input w-full" name="min_on_hand" value="${esc(product.min_on_hand ?? '')}"></label>
            <div class="grid grid-cols-2 gap-2 mt-2">
                <label class="text-xs text-muted">Pieces per pack <input class="input w-full" name="units_per_package" inputmode="numeric" value="${esc(product.units_per_package ?? '')}"></label>
                <label class="text-xs text-muted">Piece unit <input class="input w-full" name="piece_unit" value="${esc(product.piece_unit || '')}" placeholder="slice"></label>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-2">
                <label class="text-xs text-muted">Pantry days <input class="input w-full" name="shelf_life_pantry_days" value="${esc(life.shelf_life_pantry_days ?? '')}"></label>
                <label class="text-xs text-muted">Fridge days <input class="input w-full" name="shelf_life_fridge_days" value="${esc(life.shelf_life_fridge_days ?? '')}"></label>
                <label class="text-xs text-muted">Freezer days <input class="input w-full" name="shelf_life_freezer_days" value="${esc(life.shelf_life_freezer_days ?? '')}"></label>
                <label class="text-xs text-muted">Opened days <input class="input w-full" name="opened_life_days" value="${esc(life.opened_life_days ?? '')}"></label>
            </div>
            <button type="submit" class="btn-primary text-sm mt-2">Save product</button>
            <button type="button" class="btn text-sm mt-2" id="product-add">Add to inventory</button>
        </form>`;
    }

    function bindProduct(product) {
        const form = document.getElementById('product-form');
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            data.product_id = product.id;
            ['shelf_life_pantry_days', 'shelf_life_fridge_days', 'shelf_life_freezer_days', 'opened_life_days', 'min_on_hand', 'units_per_package'].forEach((key) => {
                data[key] = data[key] === '' ? null : Number(data[key]);
            });
            data.piece_unit = data.piece_unit === '' ? null : data.piece_unit;
            api.post('/pantry/products', data).then(() => products()).catch(fail);
        });
        document.getElementById('product-add').addEventListener('click', () => {
            openAddSheet({
                product_id: product.id,
                name: product.name,
                unit: product.default_unit,
                shelf: product.effective_shelf_life,
            });
        });
    }

    function openAddSheet(prefill) {
        sheetOpen = true;
        const today = new Date().toISOString().slice(0, 10);
        const loading = sessionStorage.getItem('pantry-loading') === '1';
        const host = document.getElementById('pantry-sheet') || body;
        const wrap = document.createElement('div');
        wrap.innerHTML = `<form class="ld-pantry-sheet" id="add-form">
            <p class="text-sm">${esc(prefill.name || 'New item')}</p>
            ${prefill.product_id ? '' : `
                <label class="block text-xs text-muted mt-2">Name <input class="input w-full" name="name" value="${esc(prefill.name || '')}" required></label>
                <label class="block text-xs text-muted mt-2">Brand <input class="input w-full" name="brand" value="${esc(prefill.brand || '')}"></label>
                <label class="block text-xs text-muted mt-2">Size <input class="input w-full" name="default_size" value="${esc(prefill.size || '')}"></label>
            `}
            <label class="block text-xs text-muted mt-2">Quantity <input class="input w-full" name="quantity" type="number" step="any" min="0" inputmode="decimal" value="1"></label>
            <div class="ld-pantry-locs mt-2">
                <button type="button" data-location="fridge" class="is-on">Fridge</button>
                <button type="button" data-location="freezer">Freezer</button>
                <button type="button" data-location="pantry">Pantry</button>
            </div>
            ${dateFields('use_by', view === 'scan' ? 'Expiration' : 'Use by', prefill.use_by || suggestUseBy(prefill.shelf, 'fridge'), view === 'scan')}
            <label class="block text-xs text-muted mt-2">Purchased <input class="input w-full" type="date" name="purchased_at" value="${esc(loading ? today : (prefill.purchased_at || today))}"></label>
            <label class="block text-xs text-muted mt-2">Store <input class="input w-full" name="store" value="${esc(loading ? 'existing' : (prefill.store || ''))}"></label>
            <label class="text-xs text-muted mt-2 flex gap-2 items-center"><input type="checkbox" name="loading" ${loading ? 'checked' : ''}> Loading what is already here</label>
            <button type="submit" class="btn-primary text-sm mt-3">Add</button>
        </form>`;
        host.prepend(wrap.firstElementChild);
        const form = document.getElementById('add-form');
        let place = 'fridge';
        const dateBox = form.querySelector('[data-date="use_by"]');
        if (dateBox && prefill.use_by) dateBox.dataset.touched = '1';
        dateBox?.addEventListener('input', () => { dateBox.dataset.touched = '1'; });
        form.querySelectorAll('[data-location]').forEach((btn) => {
            btn.addEventListener('click', () => {
                place = btn.dataset.location;
                form.querySelectorAll('[data-location]').forEach((el) => el.classList.remove('is-on'));
                btn.classList.add('is-on');
                if (dateBox && dateBox.dataset.touched !== '1') {
                    writeDate(form, 'use_by', suggestUseBy(prefill.shelf, place));
                }
            });
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const useBy = readDate(form, 'use_by');
            if (useBy === null || (view === 'scan' && useBy === '')) {
                fail({ message: 'Enter the expiration as month, day, and year.' });
                return;
            }
            const data = Object.fromEntries(new FormData(form).entries());
            sessionStorage.setItem('pantry-loading', data.loading ? '1' : '0');
            try {
                let productId = prefill.product_id || null;
                if (!productId) {
                    const saved = await api.post('/pantry/products', {
                        name: data.name,
                        brand: data.brand || null,
                        upc: prefill.upc || null,
                        default_size: data.default_size || null,
                        category: prefill.category || null,
                    });
                    productId = saved.product.id;
                }
                await api.post('/pantry/lots', {
                    items: [{
                        product_id: productId,
                        quantity: Number(data.quantity || 1),
                        location: place,
                        purchased_at: data.purchased_at,
                        store: data.store || null,
                        use_by: useBy || null,
                    }],
                });
                sheetOpen = false;
                form.remove();
                clearError();
                if (view === 'scan') {
                    lastCode = prefill.upc || lastCode;
                    lastAt = Date.now();
                } else if (view === 'products') {
                    await products();
                } else {
                    await inventory();
                }
            } catch (err) {
                fail(err);
            }
        });
    }

    function cameraAvailable() {
        return !!(navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function');
    }

    async function scan() {
        const live = cameraAvailable();
        body.innerHTML = `
            ${live ? '<video id="pantry-video" class="ld-pantry-video" autoplay playsinline muted></video>' : '<p class="text-xs text-muted">This address is not secure, so the camera stays off. Take a photo of the barcode.</p>'}
            <img id="pantry-photo" class="ld-pantry-video hidden" alt="Barcode photo">
            <div class="flex gap-2 mt-3">
                <button type="button" class="btn-primary text-sm" id="scan-start">${live ? 'Scan' : 'Take a photo'}</button>
                ${live ? '<button type="button" class="btn text-sm" id="scan-photo">Photo</button>' : ''}
            </div>
            <input id="pantry-photo-input" class="hidden" type="file" accept="image/*" capture="environment">
            <form id="manual-upc" class="flex gap-2 mt-3">
                <input class="input flex-1" name="upc" inputmode="numeric" placeholder="Type a UPC" aria-label="UPC">
                <button type="submit" class="btn">Look up</button>
            </form>
            <input id="produce-search" class="input w-full mt-3" placeholder="Search produce" aria-label="Search produce">
            <ul id="produce-list"></ul>
            <div id="pantry-sheet"></div>`;
        document.getElementById('manual-upc').addEventListener('submit', (event) => {
            event.preventDefault();
            const upc = new FormData(event.target).get('upc');
            if (upc) lookupCode(String(upc));
        });
        const photoInput = document.getElementById('pantry-photo-input');
        photoInput.addEventListener('change', () => {
            const file = photoInput.files && photoInput.files[0];
            photoInput.value = '';
            if (file) decodePhoto(file).catch(fail);
        });
        document.getElementById('scan-start').addEventListener('click', () => {
            if (live) startCamera().catch(fail);
            else photoInput.click();
        });
        const photoBtn = document.getElementById('scan-photo');
        if (photoBtn) photoBtn.addEventListener('click', () => photoInput.click());
        const produce = await api.get('/pantry/products?search=');
        const list = document.getElementById('produce-list');
        document.getElementById('produce-search').addEventListener('input', (event) => {
            const q = event.target.value.toLowerCase();
            if (q.length < 1) {
                list.innerHTML = '';
                return;
            }
            list.innerHTML = (produce.products || []).filter((row) => row.name.toLowerCase().includes(q)).slice(0, 8).map((row) =>
                `<li><button type="button" class="ld-pantry-lot w-full text-left" data-pick="${row.id}">${esc(row.name)}</button></li>`
            ).join('');
        });
        list.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-pick]');
            if (!btn) return;
            const product = (produce.products || []).find((row) => row.id === Number(btn.dataset.pick));
            if (product) openAddSheet({ product_id: product.id, name: product.name, unit: product.default_unit, shelf: product.effective_shelf_life });
        });
    }

    function acceptCode(code) {
        const now = Date.now();
        if (sheetOpen || (code === lastCode && now - lastAt < DEBOUNCE_MS)) return;
        lastCode = code;
        lastAt = now;
        lookupCode(code);
    }

    async function lookupCode(code) {
        clearError();
        try {
            const found = await api.get('/pantry/lookup?upc=' + encodeURIComponent(code) + '&external=1');
            const product = found.product;
            const suggestion = found.suggestion || {};
            if (found.lookup_error && !product && !suggestion.name) {
                fail({ message: found.lookup_error });
            }
            openAddSheet({
                product_id: product ? product.id : null,
                upc: found.upc,
                name: product ? product.name : (suggestion.name || ''),
                brand: product ? product.brand : (suggestion.brand || ''),
                size: product ? product.default_size : (suggestion.size || ''),
                category: product ? product.category : null,
                shelf: product ? product.effective_shelf_life : null,
            });
        } catch (err) {
            fail(err);
        }
    }

    function readPhoto(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result || ''));
            reader.onerror = () => reject(new Error('Could not read that photo.'));
            reader.readAsDataURL(file);
        });
    }

    async function decodePhoto(file) {
        clearError();
        const url = await readPhoto(file);
        const img = document.getElementById('pantry-photo');
        img.classList.remove('hidden');
        await new Promise((resolve, reject) => {
            img.onload = () => resolve();
            img.onerror = () => reject(new Error('Could not read that photo.'));
            img.src = url;
        });
        await loadZxing();
        const reader = new window.ZXing.BrowserMultiFormatReader();
        try {
            const result = await reader.decodeFromImageElement(img);
            acceptCode(result.getText());
        } catch (err) {
            if (err && err.name === 'NotFoundException') {
                fail({ message: 'No barcode in that photo. Fill the frame with the code and try again.' });
                return;
            }
            throw err;
        }
    }

    async function openCamera() {
        const attempts = [
            { video: { facingMode: { ideal: 'environment' } } },
            { video: true },
        ];
        let last = null;
        for (const constraints of attempts) {
            try {
                return await navigator.mediaDevices.getUserMedia(constraints);
            } catch (err) {
                last = err;
            }
        }
        throw last || new Error('Camera is not available.');
    }

    async function startCamera() {
        if (!cameraAvailable()) {
            fail({ message: 'This address is not secure, so the camera stays off. Take a photo of the barcode.' });
            return;
        }
        const video = document.getElementById('pantry-video');
        if (scanStop) scanStop();
        const stream = await openCamera();
        video.srcObject = stream;
        if ('BarcodeDetector' in window) {
            const detector = new window.BarcodeDetector({ formats: ['upc_a', 'upc_e', 'ean_13', 'ean_8'] });
            let timer = 0;
            const tick = async () => {
                if (video.readyState >= 2) {
                    try {
                        const codes = await detector.detect(video);
                        if (codes[0] && codes[0].rawValue) acceptCode(String(codes[0].rawValue));
                    } catch (err) {
                        fail(err);
                    }
                }
                timer = window.setTimeout(tick, 400);
            };
            tick();
            scanStop = () => {
                window.clearTimeout(timer);
                stream.getTracks().forEach((track) => track.stop());
            };
            return;
        }
        await loadZxing();
        const reader = new window.ZXing.BrowserMultiFormatReader();
        scanStop = () => reader.reset();
        reader.decodeFromVideoDevice(undefined, video, (result) => {
            if (result) acceptCode(result.getText());
        });
    }

    function loadZxing() {
        if (window.ZXing) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = ZXING_SRC;
            script.integrity = ZXING_SRI;
            script.crossOrigin = 'anonymous';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Barcode reader failed to load.'));
            document.head.appendChild(script);
        });
    }

    function formatQty(value) {
        const q = Number(value);
        if (!Number.isFinite(q)) return '';
        return Math.abs(q - Math.round(q)) < 0.001 ? String(Math.round(q)) : String(Math.round(q * 1000) / 1000);
    }

    function ingredientLine(item) {
        const bits = [];
        if (item.quantity !== null && item.quantity !== undefined && item.quantity !== '') bits.push(formatQty(item.quantity));
        if (item.unit) bits.push(item.unit);
        bits.push(item.product_id ? item.label : ('category:' + item.category));
        return bits.join(' ');
    }

    function parseIngredientLine(line, products) {
        let text = line.trim();
        if (!text) return null;
        let quantity = null;
        const qtyMatch = text.match(/^(\d+(?:\.\d+)?)\s+(.+)$/);
        if (qtyMatch) {
            quantity = Number(qtyMatch[1]);
            text = qtyMatch[2];
        }
        const cat = text.match(/^(?:([A-Za-z]+)\s+)?category:\s*(.+)$/i);
        if (cat && (!cat[1] || quantity !== null)) {
            return {
                category: cat[2].trim(),
                quantity: quantity,
                unit: cat[1] && quantity !== null ? cat[1] : null,
            };
        }
        const named = (label) => (products || []).find((row) => row.name.toLowerCase() === label.toLowerCase());
        const direct = named(text);
        if (direct) return { product_id: direct.id, quantity: quantity, unit: null };
        const split = text.match(/^([A-Za-z]+)\s+(.+)$/);
        if (split && quantity !== null) {
            const product = named(split[2]);
            if (product) return { product_id: product.id, quantity: quantity, unit: split[1] };
        }
        return { error: line };
    }

    function linesToIngredients(raw, products) {
        const ingredients = [];
        for (const line of String(raw || '').split('\n').map((row) => row.trim()).filter(Boolean)) {
            const parsed = parseIngredientLine(line, products);
            if (!parsed || parsed.error) {
                return { error: 'No product named ' + line + '. Use category:Produce for an aisle.' };
            }
            const item = {};
            if (parsed.product_id) item.product_id = parsed.product_id;
            if (parsed.category) item.category = parsed.category;
            if (parsed.quantity !== null) item.quantity = parsed.quantity;
            if (parsed.unit) item.unit = parsed.unit;
            ingredients.push(item);
        }
        return { ingredients: ingredients };
    }

    function closeRecipe() {
        if (onEditKey) {
            document.removeEventListener('keydown', onEditKey);
            onEditKey = null;
        }
        const modal = document.getElementById('pantry-recipe-modal');
        if (!modal) return;
        modal.remove();
        if (!document.querySelector('.app-modal')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    function openRecipe(recipe) {
        closeRecipe();
        const lines = (recipe.ingredients || []).map(ingredientLine).join('\n');
        const wrap = document.createElement('div');
        wrap.innerHTML = `<div id="pantry-recipe-modal" class="app-modal" role="dialog" aria-modal="true" aria-labelledby="pantry-recipe-title">
            <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
            <div class="app-modal-panel surface">
                <form id="recipe-edit-form">
                    <div class="flex items-start justify-between gap-3">
                        <p id="pantry-recipe-title" class="text-sm">Edit recipe</p>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <label class="block text-xs text-muted mt-2">Name
                        <input class="input w-full" name="name" required value="${esc(recipe.name)}">
                    </label>
                    <label class="block text-xs text-muted mt-2">Notes
                        <textarea class="input w-full" name="notes" rows="3">${esc(recipe.notes || '')}</textarea>
                    </label>
                    ${recipeFields(recipe)}
                    <label class="block text-xs text-muted mt-2">Ingredients, one per line. Optional amount and unit, then a product name or category:Produce
                        <textarea class="input w-full" name="lines" rows="5">${esc(lines)}</textarea>
                    </label>
                    <div class="flex gap-2 mt-3">
                        <button type="submit" class="btn-primary text-sm">Save recipe</button>
                        <button type="button" class="btn text-sm" data-close>Cancel</button>
                    </div>
                </form>
            </div>
        </div>`;
        const modal = wrap.firstElementChild;
        document.body.appendChild(modal);
        document.body.classList.add('app-modal-open');
        modal.querySelectorAll('[data-close]').forEach((el) => {
            el.addEventListener('click', () => closeRecipe());
        });
        onEditKey = (event) => {
            if (event.key === 'Escape') closeRecipe();
        };
        document.addEventListener('keydown', onEditKey);
        modal.querySelector('#recipe-edit-form').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = new FormData(event.target);
            const parsed = linesToIngredients(form.get('lines'), recipeCatalog);
            if (parsed.error) {
                fail({ message: parsed.error });
                return;
            }
            api.post('/pantry/recipes/' + recipe.id, Object.assign({
                name: form.get('name'),
                notes: form.get('notes') || null,
                ingredients: parsed.ingredients,
            }, recipePayload(event.target))).then(() => {
                closeRecipe();
                return recipes();
            }).catch(fail);
        });
        modal.querySelector('[name="name"]').focus();
    }

    let recipeCache = [];
    let recipeCatalog = [];
    let recipeBound = false;
    let recipeGen = 0;
    let recipeScrolled = false;
    let recipePrep = false;

    function proteinBit(row) {
        if (!row || row.protein_g == null || row.protein_g === '') return '';
        return `<span class="ld-meal-protein">${esc(String(row.protein_g))} g protein</span>`;
    }

    function prepBit(row) {
        if (!row || !row.meal_prep) return '';
        const days = row.keeps_days != null ? row.keeps_days : 3;
        return `<span class="ld-meta">Meal prep · keeps ${esc(String(days))} days</span>`;
    }

    function recipeFields(recipe) {
        const servings = recipe && recipe.servings != null ? recipe.servings : '';
        const keeps = recipe && recipe.keeps_days != null ? recipe.keeps_days : 3;
        const protein = recipe && recipe.protein_g != null ? recipe.protein_g : '';
        const prep = recipe && recipe.meal_prep ? ' checked' : '';
        return `<div class="ld-meal-actions">
                <label class="block text-xs text-muted">Servings
                    <input class="input w-full" name="servings" type="number" min="1" max="30" inputmode="numeric" value="${esc(String(servings))}">
                </label>
                <label class="block text-xs text-muted">Keeps days
                    <input class="input w-full" name="keeps_days" type="number" min="1" max="30" inputmode="numeric" value="${esc(String(keeps))}">
                </label>
                <label class="block text-xs text-muted">Protein g per serving
                    <input class="input w-full" name="protein_g" type="number" min="0" max="999.9" step="0.1" inputmode="decimal" value="${esc(String(protein))}">
                </label>
            </div>
            <label class="block text-xs text-muted mt-2"><input type="checkbox" name="meal_prep"${prep}> Meal prep</label>`;
    }

    function recipePayload(form) {
        const fields = new FormData(form);
        const servings = String(fields.get('servings') || '').trim();
        const keeps = String(fields.get('keeps_days') || '').trim();
        const protein = String(fields.get('protein_g') || '').trim();
        return {
            servings: servings === '' ? null : Number(servings),
            keeps_days: keeps === '' ? 3 : Number(keeps),
            protein_g: protein === '' ? null : Number(protein),
            meal_prep: fields.get('meal_prep') === 'on',
        };
    }

    function recipeItems(rows) {
        return (rows || []).map((row) => `
            <li class="ld-pantry-lot" id="recipe-${row.id}">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm">${esc(row.name)} ${proteinBit(row)} ${prepBit(row)}</span>
                    <button type="button" data-recipe="${row.id}">Edit</button>
                </div>
            </li>`).join('');
    }

    async function recipes() {
        const gen = ++recipeGen;
        const [data, ideas, catalogRes] = await Promise.all([
            api.get('/pantry/recipes' + (recipePrep ? '?meal_prep=1' : '')),
            api.get('/pantry/what-can-i-make'),
            api.get('/pantry/products'),
        ]);
        if (gen !== recipeGen) return;
        recipeCache = data.recipes || [];
        recipeCatalog = catalogRes.products || [];
        const ideaRows = (ideas.recipes || []).filter((row) => !recipePrep || row.meal_prep);
        const ranked = ideaRows.map((row) =>
            `<li class="ld-pantry-lot">${esc(row.name)} ${proteinBit(row)} ${prepBit(row)} <span class="ld-meta">${Math.round(row.coverage * 100)}%${row.soonest_use_by ? ' · ' + esc(row.soonest_use_by) : ''}${row.missing.length ? ' · missing ' + esc(row.missing.join(', ')) : ''}</span></li>`
        ).join('');
        if (!document.getElementById('recipe-form')) {
            body.innerHTML = `
                <h2 class="ld-pantry-label">What you can make</h2>
                <ul id="recipe-ideas"></ul>
                <div class="ld-meal-chips" id="recipe-filters">
                    <button type="button" class="ld-meal-chip" data-prep="0">All recipes</button>
                    <button type="button" class="ld-meal-chip" data-prep="1">Meal prep</button>
                </div>
                <form id="recipe-form" class="ld-pantry-sheet">
                    <label class="block text-xs text-muted">Name <input class="input w-full" name="name" required></label>
                    <label class="block text-xs text-muted mt-2">Notes <textarea class="input w-full" name="notes" rows="2"></textarea></label>
                    ${recipeFields(null)}
                    <label class="block text-xs text-muted mt-2">Ingredients, one per line. Optional amount and unit, then a product name or category:Produce
                        <textarea class="input w-full" name="lines" rows="4"></textarea>
                    </label>
                    <button type="submit" class="btn-primary text-sm mt-2">Save recipe</button>
                </form>
                <ul class="mt-3" id="recipe-list"></ul>`;
        }
        document.querySelectorAll('#recipe-filters [data-prep]').forEach((button) => {
            const on = (button.dataset.prep === '1') === recipePrep;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        const ideasEl = document.getElementById('recipe-ideas');
        const listEl = document.getElementById('recipe-list');
        if (ideasEl) ideasEl.innerHTML = ranked || '<li>' + window.DashboardEmpty.html({
            title: 'No recipes yet',
            body: 'Save a meal as a recipe to see ideas here.',
            actionLabel: 'Recipes',
            actionHref: '/pantry?view=recipes',
        }) + '</li>';
        if (listEl) listEl.innerHTML = recipeItems(recipeCache);
        if (!recipeScrolled) {
            recipeScrolled = true;
            const focusId = new URLSearchParams(location.search).get('recipe');
            const focus = focusId ? document.getElementById('recipe-' + focusId) : null;
            if (focus) focus.scrollIntoView({ block: 'center' });
            const recipe = focusId ? recipeCache.find((row) => row.id === Number(focusId)) : null;
            if (recipe) openRecipe(recipe);
        }
        if (recipeBound) return;
        recipeBound = true;
        document.getElementById('recipe-list').addEventListener('click', (event) => {
            const btn = event.target.closest('[data-recipe]');
            if (!btn) return;
            const recipe = recipeCache.find((row) => row.id === Number(btn.dataset.recipe));
            if (recipe) openRecipe(recipe);
        });
        document.getElementById('recipe-filters').addEventListener('click', (event) => {
            const button = event.target.closest('[data-prep]');
            if (!button) return;
            recipePrep = button.dataset.prep === '1';
            recipes().catch(fail);
        });
        document.getElementById('recipe-form').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.target;
            const parsed = linesToIngredients(new FormData(form).get('lines'), recipeCatalog);
            if (parsed.error) {
                fail({ message: parsed.error });
                return;
            }
            const bodyFields = new FormData(form);
            api.post('/pantry/recipes', Object.assign({
                name: bodyFields.get('name'),
                notes: bodyFields.get('notes') || null,
                ingredients: parsed.ingredients,
            }, recipePayload(form))).then(() => {
                form.reset();
                return recipes();
            }).catch(fail);
        });
    }

    const MEAL_FILTERS = [
        ['protein', 'Protein'],
        ['base', 'Base'],
        ['sauce', 'Sauce'],
        ['side', 'Side/Garnish'],
    ];
    const MEAL_SUPPORT = [
        ['aromatic', 'Aromatics'],
        ['vegetable', 'Vegetables'],
        ['spice', 'Spices'],
    ];
    let mealPicks = [];
    let mealById = {};
    let mealGen = 0;
    let mealRepairDepth = 0;
    let mealServings = 3;
    let mealRecipeId = null;
    let mealRecipeName = '';
    let mealPrepName = '';
    let mealPlan = null;
    let mealPlanKey = '';

    async function mealRepair(err) {
        const message = (err && err.fields && err.fields.picks) || (err && err.message) || '';
        const unknown = message.indexOf('Unknown component') !== -1;
        const conflict = message.indexOf('do not all pair') !== -1 || message.indexOf('Pick up to 2') !== -1;
        if ((!unknown && !conflict) || mealRepairDepth > 8) return false;
        mealRepairDepth += 1;
        try {
            if (unknown) {
                const listed = await api.get('/pantry/meals/components');
                const known = new Set((listed.components || []).map((row) => row.id));
                const next = mealPicks.filter((id) => known.has(id));
                if (next.length === mealPicks.length) return false;
                mealPicks = next;
            } else if (!mealPicks.length) {
                return false;
            } else {
                mealPicks = mealPicks.slice(0, -1);
            }
            await meals(true);
            return true;
        } finally {
            mealRepairDepth -= 1;
        }
    }

    function mealName(a, b) {
        return String(a.name).localeCompare(String(b.name));
    }

    function mealLine(row, picked, apart) {
        const classes = ['ld-meal-row'];
        if (picked) classes.push('is-on');
        if (apart) classes.push('is-apart');
        if (!row.on_hand) classes.push('is-off');
        const unpick = picked
            ? `<button type="button" class="ld-meal-x" data-unpick="${row.id}" aria-label="Remove ${esc(row.name)}">×</button>`
            : '';
        return `<div class="ld-meal-line"><button type="button" class="${classes.join(' ')}" data-pick="${row.id}" data-apart="${apart ? '1' : '0'}" data-category="${esc(row.category)}" aria-pressed="${picked ? 'true' : 'false'}">${esc(row.name)}</button>${unpick}</div>`;
    }

    function mealSuggestion(row) {
        const classes = ['ld-meal-row'];
        if (!row.on_hand) classes.push('is-off');
        return `<div class="${classes.join(' ')}">${esc(row.name)}</div>`;
    }

    function mealColumn(label, picks, compatible, apart) {
        const lines = picks.map((row) => mealLine(row, true, false) + mealSwaps(row)).join('')
            + compatible.map((row) => mealLine(row, false, false)).join('');
        const divided = apart.length
            ? `<div class="ld-meal-split" role="separator"></div><p class="ld-meal-split-label">doesn't pair</p>${apart.map((row) => mealLine(row, false, true)).join('')}`
            : '';
        const empty = !lines && !apart.length ? '<p class="text-muted text-xs">None</p>' : '';
        return `<section class="ld-meal-col"><h2 class="ld-pantry-label">${esc(label)}</h2>${lines}${divided}${empty}</section>`;
    }

    function renderPlan(plan) {
        const steps = (plan.steps || []).map((step) => `<li>${esc(step)}</li>`).join('');
        const lines = (plan.lines || []).map((line) => {
            const mark = line.on_hand ? '' : ' class="is-off"';
            const short = line.short ? ` <span class="is-off">have ${esc(String(line.have))}, need ${esc(String(line.need))}</span>` : '';
            return `<li${mark}>${esc(line.name)} (${esc(String(line.quantity))} ${esc(line.unit || '')})${short}</li>`;
        }).join('');
        const protein = proteinBit(plan);
        return `<p class="text-sm">${esc(plan.label || '')}</p><p class="ld-meta">Use by ${esc(plan.use_by || '')} · keeps ${esc(String(plan.keeps_days || ''))} days</p>${protein}<ol class="ld-meal-steps">${steps}</ol><ul class="ld-meal-scale">${lines}</ul>`;
    }

    function prepPayload() {
        const servingsEl = document.querySelector('#meal-prep [name="servings"]');
        const nameEl = document.querySelector('#meal-prep [name="prep_name"]');
        const servings = servingsEl ? Number(servingsEl.value) : mealServings;
        if (servings >= 1 && servings <= 30) mealServings = servings;
        mealPrepName = nameEl ? nameEl.value : mealPrepName;
        const payload = { servings: mealServings };
        const name = String(mealPrepName || '').trim();
        if (name) payload.name = name;
        if (mealRecipeId) payload.recipe_id = mealRecipeId;
        else payload.picks = mealPicks.slice();
        return payload;
    }

    function prepQuery(payload) {
        const query = { servings: String(payload.servings) };
        if (payload.recipe_id) query.recipe_id = String(payload.recipe_id);
        if (payload.picks && payload.picks.length) query.picks = payload.picks.join(',');
        if (payload.name) query.name = payload.name;
        return query;
    }

    function mealSwaps(row) {
        if (!row || row.on_hand || !row.swaps || !row.swaps.length) return '';
        return `<div class="ld-meal-swaps">${row.swaps.map((swap) =>
            `<button type="button" class="ld-meal-chip" data-swap-from="${row.id}" data-swap-to="${swap.id}">Swap ${esc(swap.name)}</button>`
        ).join('')}</div>`;
    }

    function ingredientHtml(item, picks) {
        if (!item || !item.name) return '';
        const name = item.on_hand ? esc(item.name) : `<span class="is-off">${esc(item.name)}</span>`;
        const swaps = !item.on_hand && item.swaps ? item.swaps : [];
        const buttons = swaps.map((swap) => {
            const next = (picks || []).map((id) => id === item.component_id ? swap.id : id);
            return `<button type="button" class="ld-meal-chip" data-load-picks="${esc(next.join(','))}">Swap ${esc(swap.name)}</button>`;
        }).join('');
        return name + (buttons ? `<span class="ld-meal-swaps">${buttons}</span>` : '');
    }

    function mealSupport(label, rows) {
        const lines = (rows || []).map(mealSuggestion).join('');
        return `<section class="ld-meal-col"><h2 class="ld-pantry-label">${esc(label)}</h2>${lines || '<p class="text-muted text-xs">None</p>'}</section>`;
    }

    // A grayed item stays. Current picks it does not pair with are dropped, then the column cap is applied.
    function mealKeep(ids, addedId) {
        const counts = {};
        const kept = [];
        ids.slice().reverse().forEach((id) => {
            const row = mealById[id];
            const category = row ? row.category : '';
            counts[category] = counts[category] || 0;
            if (counts[category] >= 2) return;
            counts[category] += 1;
            kept.push(id);
        });
        if (kept.indexOf(addedId) === -1) kept.push(addedId);
        return kept.reverse();
    }

    // Per browser, not the account.
    const MEAL_DRAWER_KEY = 'meal_recipes_open';
    let mealDrawerOpen = false;

    function mealDrawerStored() {
        try {
            return localStorage.getItem(MEAL_DRAWER_KEY) === '1';
        } catch (err) {
            return mealDrawerOpen;
        }
    }

    function mealDrawerRemember(open) {
        mealDrawerOpen = open;
        try {
            localStorage.setItem(MEAL_DRAWER_KEY, open ? '1' : '0');
        } catch (err) {
            // Storage can throw. mealDrawerOpen already holds this browser's state.
        }
    }

    function applyMealDrawer(open, moveFocus) {
        const drawer = document.getElementById('meal-drawer');
        const app = document.getElementById('pantry-app');
        if (!drawer) return;
        drawer.classList.toggle('is-open', open);
        if (app) app.classList.toggle('is-drawer-open', open);
        const handle = document.getElementById('meal-drawer-handle');
        const panel = document.getElementById('meal-drawer-panel');
        if (handle) handle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (panel) {
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
            if (open) panel.removeAttribute('inert');
            else panel.setAttribute('inert', '');
        }
        if (!moveFocus) return;
        if (open) {
            const first = panel && panel.querySelector('a, button');
            if (first) first.focus();
            else if (panel) panel.focus();
        } else if (handle) {
            handle.focus();
        }
    }

    function ensureMealDrawer() {
        let drawer = document.getElementById('meal-drawer');
        if (drawer) return drawer;
        const app = document.getElementById('pantry-app');
        if (!app) return null;
        drawer = document.createElement('div');
        drawer.id = 'meal-drawer';
        drawer.className = 'ld-meal-drawer';
        drawer.innerHTML = `
            <button type="button" class="ld-meal-drawer-handle" id="meal-drawer-handle" aria-expanded="false" aria-controls="meal-drawer-panel">
                Matching recipes (<span id="meal-drawer-count">0</span>)
            </button>
            <div class="ld-meal-drawer-panel" id="meal-drawer-panel" role="region" tabindex="-1" aria-labelledby="meal-drawer-handle" aria-hidden="true" inert>
                <ul id="meal-drawer-list"></ul>
            </div>`;
        app.appendChild(drawer);
        mealDrawerOpen = mealDrawerStored();
        applyMealDrawer(mealDrawerOpen, false);
        document.getElementById('meal-drawer-handle').addEventListener('click', () => {
            const next = !mealDrawerOpen;
            mealDrawerRemember(next);
            applyMealDrawer(next, true);
        });
        document.getElementById('meal-drawer-list').addEventListener('click', (event) => {
            const load = event.target.closest('[data-load-picks]');
            if (!load) return;
            const ids = String(load.dataset.loadPicks || '').split(',').map(Number).filter((id) => id > 0);
            const prev = mealPicks.slice();
            mealRecipeId = load.dataset.prepRecipe ? Number(load.dataset.prepRecipe) : null;
            mealRecipeName = load.dataset.prepName || '';
            mealPicks = ids;
            meals().catch((err) => {
                mealPicks = prev;
                fail(err);
            });
        });
        return drawer;
    }

    function renderMealDrawer(recipes) {
        const drawer = ensureMealDrawer();
        if (!drawer) return;
        const list = document.getElementById('meal-drawer-list');
        const count = document.getElementById('meal-drawer-count');
        const panel = document.getElementById('meal-drawer-panel');
        const rows = recipes || [];
        const scroll = panel ? panel.scrollTop : 0;
        const active = document.activeElement;
        const activeKey = active && drawer.contains(active) ? active.getAttribute('data-drawer-focus') : '';
        if (count) count.textContent = String(rows.length);
        if (!list) return;
        if (!rows.length) {
            list.innerHTML = '<li class="ld-meal-drawer-empty">No matching recipes.</li>';
        } else {
            list.innerHTML = rows.map((row) => {
                const parts = (row.ingredients || []).map((item) => ingredientHtml(item, row.picks || [])).filter(Boolean);
                const badge = row.exact ? '<span class="ld-meal-exact">exact match</span>' : '';
                const picks = (row.picks || []).join(',');
                const id = Number(row.id);
                return `<li class="ld-meal-drawer-row">
                    <a data-drawer-focus="recipe-${id}" href="${esc(row.url || '')}">${esc(row.name || '')}</a>
                    ${badge} ${proteinBit(row)} ${prepBit(row)}
                    <div class="ld-meal-uses">${parts.join(', ')}</div>
                    <button type="button" class="ld-meal-chip" data-drawer-focus="load-${id}" data-load-picks="${esc(picks)}">Load picks</button>
                    <button type="button" class="ld-meal-chip" data-drawer-focus="prep-${id}" data-load-picks="${esc(picks)}" data-prep-recipe="${id}" data-prep-name="${esc(row.name || '')}">Prep</button>
                </li>`;
            }).join('');
        }
        if (panel) panel.scrollTop = scroll;
        if (activeKey) {
            const next = drawer.querySelector('[data-drawer-focus="' + activeKey.replace(/"/g, '') + '"]');
            if (next) next.focus();
        }
    }

    async function meals(repair) {
        const gen = ++mealGen;
        const qs = new URLSearchParams();
        if (mealPicks.length) qs.set('picks', mealPicks.join(','));
        let board;
        let listed;
        try {
            board = await api.get('/pantry/meals' + (qs.toString() ? '?' + qs.toString() : ''));
            listed = await api.get('/pantry/meals/components');
        } catch (err) {
            if (gen !== mealGen) return;
            if (repair && await mealRepair(err)) return;
            throw err;
        }
        if (gen !== mealGen) return;
        if (!repair) clearError();
        const returned = new Set((board.picks || []).map((row) => row.id));
        mealPicks = mealPicks.filter((id) => returned.has(id));
        (board.picks || []).forEach((row) => {
            if (mealPicks.indexOf(row.id) === -1) mealPicks.push(row.id);
        });
        const picked = new Set(mealPicks);
        mealById = {};
        (listed.components || []).forEach((row) => {
            if (!board.on_hand_only || row.on_hand || picked.has(row.id)) mealById[row.id] = row;
        });
        (board.picks || []).forEach((row) => {
            mealById[row.id] = Object.assign({}, mealById[row.id] || {}, row);
        });
        const columns = MEAL_FILTERS.map(([key, label]) => {
            const compatible = ((board.categories || {})[key] || []).slice();
            const compatibleIds = new Set(compatible.map((row) => row.id));
            compatible.forEach((row) => {
                mealById[row.id] = Object.assign({}, mealById[row.id] || {}, row);
            });
            const chosen = mealPicks.map((id) => mealById[id]).filter((row) => row && row.category === key);
            const chosenIds = new Set(chosen.map((row) => row.id));
            const rest = compatible.filter((row) => !chosenIds.has(row.id)).sort((a, b) => {
                const score = (b.pair_score || 0) - (a.pair_score || 0);
                return score !== 0 ? score : mealName(a, b);
            });
            const apart = Object.keys(mealById).map((id) => mealById[id]).filter((row) => {
                return row.category === key && !compatibleIds.has(row.id);
            }).sort(mealName);
            return mealColumn(label, chosen, rest, apart);
        }).join('');
        const rail = MEAL_SUPPORT.map(([key, label]) => mealSupport(label, (board.supporting || {})[key])).join('');
        const made = (board.made_before || []).map((row) => {
            const uses = (row.also_uses || []).map((item) => ingredientHtml(item, row.picks || [])).filter(Boolean);
            const extra = uses.length ? `<div class="ld-meal-uses">also uses: ${uses.join(', ')}</div>` : '';
            return `<div class="ld-meal-made"><a class="text-sm" href="${esc(row.url)}">${esc(row.name)}</a> ${proteinBit(row)} ${prepBit(row)}${extra}</div>`;
        }).join('');
        const only = !!board.on_hand_only;
        const scrollY = window.scrollY;
        const nameInput = document.querySelector('#meal-recipe [name="name"]');
        const typedName = nameInput ? nameInput.value : null;
        const nameFocused = !!(nameInput && document.activeElement === nameInput);
        const selection = nameFocused ? [nameInput.selectionStart, nameInput.selectionEnd] : null;
        const noteEl = document.getElementById('meal-note');
        const typedNote = noteEl ? noteEl.textContent : '';
        const servingsEl = document.querySelector('#meal-prep [name="servings"]');
        if (servingsEl && servingsEl.value) {
            const nextServings = Number(servingsEl.value);
            if (nextServings >= 1 && nextServings <= 30) mealServings = nextServings;
        }
        const prepNameEl = document.querySelector('#meal-prep [name="prep_name"]');
        if (prepNameEl) mealPrepName = prepNameEl.value;
        const planKey = (mealRecipeId || 'picks') + ':' + mealPicks.join(',') + ':' + mealServings + ':' + mealPrepName;
        if (mealPlanKey !== planKey) mealPlan = null;
        const fromRecipe = mealRecipeId
            ? `<p class="text-sm">From recipe: ${esc(mealRecipeName || ('#' + mealRecipeId))}</p>`
            : '';
        const planHtml = mealPlan ? renderPlan(mealPlan) : '';
        body.innerHTML = `
            <div class="ld-meal-chips" id="meal-modes">
                <button type="button" class="ld-meal-chip ld-meal-mode${only ? '' : ' is-on'}" data-hand="0" aria-pressed="${only ? 'false' : 'true'}">Show everything</button>
                <button type="button" class="ld-meal-chip ld-meal-mode${only ? ' is-on' : ''}" data-hand="1" aria-pressed="${only ? 'true' : 'false'}">Only what I have</button>
                <button type="button" class="ld-meal-chip" id="meal-clear">Clear</button>
            </div>
            <div class="ld-meal-board" id="meal-board">
                ${columns}
                <aside class="ld-meal-rail" aria-label="Flavor">
                    <h2 class="ld-pantry-label ld-meal-flavor">Flavor</h2>
                    ${rail}
                </aside>
            </div>
            <div class="mt-3" id="meal-made">${made ? '<p class="text-sm">Made before</p>' + made : ''}</div>
            <p class="text-sm text-muted" id="meal-note"></p>
            <section class="ld-meal-prep" id="meal-prep">
                <h2 class="ld-pantry-label">Meal prep</h2>
                ${fromRecipe}
                <div class="ld-meal-actions">
                    <label class="text-xs text-muted">Servings
                        <input class="input" name="servings" type="number" min="1" max="30" inputmode="numeric" value="${esc(String(mealServings))}" aria-label="Servings">
                    </label>
                    <input class="input" name="prep_name" placeholder="Prep name" aria-label="Prep name" value="${esc(mealPrepName)}">
                    <button type="button" class="btn text-sm" id="meal-plan">Preview steps</button>
                    <button type="button" class="btn-primary text-sm" id="meal-log">Log prep</button>
                </div>
                <div id="meal-plan-out">${planHtml}</div>
            </section>
            <form id="meal-recipe" class="ld-meal-actions">
                <input class="input" name="name" placeholder="Recipe name" aria-label="Recipe name" required>
                <button type="submit" class="btn-primary text-sm">Save as recipe</button>
                <button type="button" class="btn text-sm" id="meal-groceries">Add missing to Groceries</button>
            </form>`;
        const nextName = document.querySelector('#meal-recipe [name="name"]');
        if (nextName && typedName !== null) {
            nextName.value = typedName;
            if (nameFocused) {
                nextName.focus();
                if (selection && nextName.setSelectionRange) nextName.setSelectionRange(selection[0], selection[1]);
            }
        }
        const nextNote = document.getElementById('meal-note');
        if (nextNote && typedNote) nextNote.textContent = typedNote;
        window.scrollTo(0, scrollY);
        renderMealDrawer(board.matching_recipes || []);
        body.querySelector('#meal-modes').addEventListener('click', (event) => {
            const mode = event.target.closest('[data-hand]');
            if (mode) {
                api.post('/pantry/meals/view', { on_hand_only: mode.dataset.hand === '1' }).then(() => meals()).catch(fail);
                return;
            }
            if (event.target.closest('#meal-clear')) {
                mealPicks = [];
                mealRecipeId = null;
                mealRecipeName = '';
                meals().catch(fail);
            }
        });
        document.getElementById('meal-prep').addEventListener('click', (event) => {
            const preview = event.target.closest('#meal-plan');
            const log = event.target.closest('#meal-log');
            if (!preview && !log) return;
            const payload = prepPayload();
            const req = preview
                ? api.get('/pantry/meals/prep?' + new URLSearchParams(prepQuery(payload)).toString())
                : api.post('/pantry/meals/prep', payload);
            req.then((plan) => {
                if (log) {
                    mealPlan = null;
                    mealPlanKey = '';
                    const lot = plan.lot || {};
                    return meals().then(() => {
                        const note = document.getElementById('meal-note');
                        if (note && lot.name) note.textContent = 'Logged ' + lot.name + ', use by ' + (lot.use_by || '') + '.';
                    });
                }
                mealPlan = plan;
                mealPlanKey = (mealRecipeId || 'picks') + ':' + mealPicks.join(',') + ':' + mealServings + ':' + mealPrepName;
                const out = document.getElementById('meal-plan-out');
                if (out) out.innerHTML = renderPlan(plan);
            }).catch(fail);
        });
        document.getElementById('meal-board').addEventListener('click', (event) => {
            const swap = event.target.closest('[data-swap-to]');
            if (swap) {
                const from = Number(swap.dataset.swapFrom);
                const to = Number(swap.dataset.swapTo);
                const prev = mealPicks.slice();
                mealRecipeId = null;
                mealRecipeName = '';
                mealPicks = mealPicks.map((id) => id === from ? to : id);
                meals().catch((err) => {
                    mealPicks = prev;
                    fail(err);
                });
                return;
            }
            const unpick = event.target.closest('[data-unpick]');
            if (unpick) {
                const id = Number(unpick.dataset.unpick);
                const prev = mealPicks.slice();
                mealRecipeId = null;
                mealRecipeName = '';
                mealPicks = mealPicks.filter((pick) => pick !== id);
                meals().catch((err) => {
                    mealPicks = prev;
                    fail(err);
                });
                return;
            }
            const row = event.target.closest('[data-pick]');
            if (!row || row.getAttribute('aria-pressed') === 'true') return;
            const id = Number(row.dataset.pick);
            const prev = mealPicks.slice();
            mealRecipeId = null;
            mealRecipeName = '';
            if (row.dataset.apart === '1') {
                api.get('/pantry/meals/components/' + id + '/pairings').then((data) => {
                    const friends = new Set((data.pairings || []).map((pair) => pair.other && pair.other.id).filter(Boolean));
                    const kept = mealPicks.filter((pick) => friends.has(pick));
                    mealPicks = mealKeep(kept.concat([id]), id);
                    return meals();
                }).catch((err) => {
                    mealPicks = prev;
                    fail(err);
                });
                return;
            }
            const category = row.dataset.category;
            const inCategory = mealPicks.filter((pick) => mealById[pick] && mealById[pick].category === category).length;
            if (inCategory >= 2) {
                fail({ message: 'Pick up to 2 in ' + category + '.' });
                return;
            }
            mealPicks = prev.concat([id]);
            meals().catch((err) => {
                mealPicks = prev;
                fail(err);
            });
        });
        document.getElementById('meal-recipe').addEventListener('submit', (event) => {
            event.preventDefault();
            const name = new FormData(event.target).get('name');
            api.post('/pantry/meals/recipe', { name: name, picks: mealPicks }).then((res) => {
                const id = res.recipe && res.recipe.id;
                window.location.href = '/pantry?view=recipes' + (id ? '&recipe=' + id : '');
            }).catch(fail);
        });
        document.getElementById('meal-groceries').addEventListener('click', () => {
            api.post('/pantry/meals/groceries', { picks: mealPicks }).then((res) => {
                const names = (res.added || []).map((row) => row.name).filter(Boolean);
                document.getElementById('meal-note').textContent = names.length
                    ? 'Added to Groceries: ' + names.join(', ')
                    : 'Nothing missing from Groceries.';
            }).catch(fail);
        });
    }

    const screens = { inventory, scan, purge, products, recipes, meals };
    (screens[view] || inventory)().catch(fail).then(() => {
        booted = true;
        if (liveQueued) {
            liveQueued = false;
            scheduleLive();
        }
    });

    window.addEventListener('lifedash:event', (event) => {
        const table = event.detail && event.detail.table;
        if (affectsOpenView(table)) scheduleLive();
    });
    window.addEventListener('lifedash:poll', () => {
        if (view !== 'scan') scheduleLive();
    });
    window.addEventListener('lifedash:resync', () => {
        if (view !== 'scan') scheduleLive();
    });
})();
