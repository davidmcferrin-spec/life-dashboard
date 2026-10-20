/**
 * Pantry page: inventory, scan, purge, products, and recipes.
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
        window.location.href = '/pantry' + (query ? '?' + query : '');
    }

    async function inventory() {
        const params = new URLSearchParams(location.search);
        const status = params.get('status') || 'on_hand';
        const category = params.get('category') || '';
        const query = 'status=' + encodeURIComponent(status) + (category ? '&category=' + encodeURIComponent(category) : '');
        const [data, cats] = await Promise.all([
            api.get('/pantry/lots?' + query),
            api.get('/pantry/categories'),
        ]);
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
        const filters = `
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
            </div>`;
        body.innerHTML = filters + '<div id="pantry-lots"></div><div id="pantry-sheet"></div>';
        const paint = () => {
            const q = (document.getElementById('pantry-search').value || '').toLowerCase();
            const loc = body.querySelector('[data-loc].is-on').dataset.loc;
            const groups = { fridge: [], freezer: [], pantry: [] };
            (data.lots || []).forEach((lot) => {
                if (loc && lot.location !== loc) return;
                const hay = (lot.name + ' ' + (lot.brand || '') + ' ' + (lot.category || '')).toLowerCase();
                if (q && !hay.includes(q)) return;
                groups[lot.location].push(lot);
            });
            document.getElementById('pantry-lots').innerHTML = ['fridge', 'freezer', 'pantry'].map((key) => {
                if (!groups[key].length) return '';
                const rows = groups[key].map((lot) => lotRow(lot, lotActions(lot))).join('');
                return `<section class="ld-pantry-group"><h2 class="ld-pantry-label">${esc(key)}</h2><ul>${rows}</ul></section>`;
            }).join('') || '<p class="empty-state mono text-xs mt-4">Nothing matches.</p>';
        };
        paint();
        document.getElementById('pantry-search').addEventListener('input', paint);
        document.getElementById('pantry-status').addEventListener('change', (event) => {
            const next = new URLSearchParams();
            if (event.target.value !== 'on_hand') next.set('status', event.target.value);
            if (category) next.set('category', category);
            go(next.toString());
        });
        document.getElementById('pantry-category').addEventListener('change', (event) => {
            const next = new URLSearchParams();
            if (status !== 'on_hand') next.set('status', status);
            if (event.target.value) next.set('category', event.target.value);
            go(next.toString());
        });
        body.querySelectorAll('[data-loc]').forEach((btn) => {
            btn.addEventListener('click', () => {
                body.querySelectorAll('[data-loc]').forEach((el) => el.classList.remove('is-on'));
                btn.classList.add('is-on');
                paint();
            });
        });
        body.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-act]');
            if (!btn) return;
            const lot = (data.lots || []).find((row) => row.id === Number(btn.dataset.id));
            if (!lot) return;
            const act = btn.dataset.act;
            const sheet = document.getElementById('pantry-sheet');
            if (act === 'consume') {
                openConsume(lot);
            } else if (act === 'open') {
                api.post('/pantry/lots/' + lot.id + '/open', {}).then(() => window.location.reload()).catch(fail);
            } else if (act === 'toss') {
                api.post('/pantry/lots/' + lot.id + '/toss', {}).then(() => window.location.reload()).catch(fail);
            } else if (act === 'move') {
                sheet.innerHTML = '';
                openMove(lot);
            } else if (act === 'edit') {
                sheet.innerHTML = '';
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
            api.post('/pantry/lots/' + lot.id + '/split', body).then(() => window.location.reload()).catch(fail);
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
            }).then(() => window.location.reload()).catch(fail);
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
            api.post('/pantry/consume', { lot_id: lot.id, quantity: quantity }).then(() => window.location.reload()).catch(fail);
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

    async function purge() {
        const days = Number(new URLSearchParams(location.search).get('days') || 3);
        const data = await api.get('/pantry/expiring?days=' + days);
        const rows = (data.lots || []).map((lot) => `
            <label class="ld-pantry-lot flex gap-2 items-start">
                <input type="checkbox" value="${lot.id}">
                <span class="ld-pantry-${esc(lot.freshness)}">${esc(lot.name)} · ${esc(lot.location)} · ${esc(lot.use_by || '')}</span>
            </label>`).join('');
        body.innerHTML = `
            <form id="purge-form">
                <label class="text-xs text-muted">Due within days
                    <input class="input" name="days" inputmode="numeric" value="${days}">
                </label>
                <div class="mt-3">${rows || '<p class="empty-state mono text-xs">Nothing due.</p>'}</div>
                <button type="submit" class="btn-primary text-sm mt-3">Mark tossed</button>
            </form>`;
        document.getElementById('purge-form').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.target;
            const nextDays = form.days.value;
            if (document.activeElement && document.activeElement.name === 'days') {
                location.href = '/pantry?view=purge&days=' + encodeURIComponent(nextDays);
                return;
            }
            const ids = [...form.querySelectorAll('input[type="checkbox"]:checked')].map((box) => Number(box.value));
            if (!ids.length) {
                fail({ message: 'Select at least one lot.' });
                return;
            }
            api.post('/pantry/purge', { lot_ids: ids }).then(() => window.location.reload()).catch(fail);
        });
    }

    async function products() {
        const data = await api.get('/pantry/products');
        body.innerHTML = `
            <input id="product-search" class="input w-full" placeholder="Search produce or a product" aria-label="Search products">
            <ul id="product-list" class="mt-3"></ul>
            <div id="product-sheet"></div>`;
        const paint = () => {
            const q = document.getElementById('product-search').value.toLowerCase();
            document.getElementById('product-list').innerHTML = (data.products || []).filter((row) => {
                return !q || (row.name + ' ' + (row.brand || '') + ' ' + (row.upc || '')).toLowerCase().includes(q);
            }).slice(0, 40).map((row) => `
                <li class="ld-pantry-lot">
                    <button type="button" class="text-left w-full" data-product="${row.id}">
                        ${esc(row.name)} <span class="ld-meta">${esc(row.category)}${row.units_per_package ? ' · ' + esc(String(row.units_per_package)) + ' ' + esc(row.piece_unit || '') : ''}${row.upc ? ' · ' + esc(row.upc) : ''}</span>
                    </button>
                </li>`).join('');
        };
        paint();
        document.getElementById('product-search').addEventListener('input', paint);
        body.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-product]');
            if (!btn) return;
            const product = (data.products || []).find((row) => row.id === Number(btn.dataset.product));
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
            api.post('/pantry/products', data).then(() => window.location.reload()).catch(fail);
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
                if (view === 'scan') {
                    form.remove();
                    clearError();
                    lastCode = prefill.upc || lastCode;
                    lastAt = Date.now();
                } else {
                    window.location.reload();
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

    function openRecipe(recipe, products) {
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
            const parsed = linesToIngredients(form.get('lines'), products);
            if (parsed.error) {
                fail({ message: parsed.error });
                return;
            }
            api.post('/pantry/recipes/' + recipe.id, {
                name: form.get('name'),
                notes: form.get('notes') || null,
                ingredients: parsed.ingredients,
            }).then(() => window.location.reload()).catch(fail);
        });
        modal.querySelector('[name="name"]').focus();
    }

    async function recipes() {
        const data = await api.get('/pantry/recipes');
        const ideas = await api.get('/pantry/what-can-i-make');
        const products = await api.get('/pantry/products');
        const catalog = products.products || [];
        const ranked = (ideas.recipes || []).map((row) =>
            `<li class="ld-pantry-lot">${esc(row.name)} <span class="ld-meta">${Math.round(row.coverage * 100)}%${row.soonest_use_by ? ' · ' + esc(row.soonest_use_by) : ''}${row.missing.length ? ' · missing ' + esc(row.missing.join(', ')) : ''}</span></li>`
        ).join('');
        body.innerHTML = `
            <h2 class="ld-pantry-label">What you can make</h2>
            <ul>${ranked || '<li class="empty-state mono text-xs">No recipes yet.</li>'}</ul>
            <form id="recipe-form" class="ld-pantry-sheet">
                <label class="block text-xs text-muted">Name <input class="input w-full" name="name" required></label>
                <label class="block text-xs text-muted mt-2">Notes <textarea class="input w-full" name="notes" rows="2"></textarea></label>
                <label class="block text-xs text-muted mt-2">Ingredients, one per line. Optional amount and unit, then a product name or category:Produce
                    <textarea class="input w-full" name="lines" rows="4"></textarea>
                </label>
                <button type="submit" class="btn-primary text-sm mt-2">Save recipe</button>
            </form>
            <ul class="mt-3" id="recipe-list">${(data.recipes || []).map((row) => `
                <li class="ld-pantry-lot">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm">${esc(row.name)}</span>
                        <button type="button" data-recipe="${row.id}">Edit</button>
                    </div>
                </li>`).join('')}</ul>`;
        document.getElementById('recipe-list').addEventListener('click', (event) => {
            const btn = event.target.closest('[data-recipe]');
            if (!btn) return;
            const recipe = (data.recipes || []).find((row) => row.id === Number(btn.dataset.recipe));
            if (recipe) openRecipe(recipe, catalog);
        });
        document.getElementById('recipe-form').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = new FormData(event.target);
            const parsed = linesToIngredients(form.get('lines'), catalog);
            if (parsed.error) {
                fail({ message: parsed.error });
                return;
            }
            api.post('/pantry/recipes', {
                name: form.get('name'),
                notes: form.get('notes') || null,
                ingredients: parsed.ingredients,
            }).then(() => window.location.reload()).catch(fail);
        });
    }

    const screens = { inventory, scan, purge, products, recipes };
    (screens[view] || inventory)().catch(fail);
})();
