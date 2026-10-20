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
                <span>${esc(lot.name)}${lot.brand ? ' <span class="ld-meta">' + esc(lot.brand) + '</span>' : ''}</span>
                <span class="ld-pantry-${esc(lot.freshness)}">${esc(qtyText(lot))}${lot.use_by ? ' · ' + esc(lot.use_by) : ''}</span>
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
        const statuses = ['on_hand', 'used', 'tossed', 'expired', 'any'];
        const statusOptions = statuses.map((value) =>
            `<option value="${value}"${value === status ? ' selected' : ''}>${value.replace('_', ' ')}</option>`
        ).join('');
        const filters = `
            <div class="ld-pantry-filters">
                <input id="pantry-search" class="input flex-1" placeholder="Search" aria-label="Search pantry">
                <select id="pantry-status" class="input" aria-label="Status">${statusOptions}</select>
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
                const rows = groups[key].map((lot) => lotRow(lot, `
                    <button type="button" data-act="consume" data-id="${lot.id}">Use</button>
                    <button type="button" data-act="open" data-id="${lot.id}">Open</button>
                    <button type="button" data-act="move" data-id="${lot.id}">Move</button>
                    <button type="button" data-act="edit" data-id="${lot.id}">Edit</button>
                    <button type="button" data-act="toss" data-id="${lot.id}">Toss</button>
                `)).join('');
                return `<section class="ld-pantry-group"><h2 class="ld-pantry-label">${esc(key)}</h2><ul>${rows}</ul></section>`;
            }).join('') || '<p class="empty-state mono text-xs mt-4">Nothing matches.</p>';
        };
        paint();
        document.getElementById('pantry-search').addEventListener('input', paint);
        document.getElementById('pantry-status').addEventListener('change', (event) => {
            const next = new URLSearchParams();
            next.set('status', event.target.value);
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
                const raw = window.prompt('How many ' + lot.name + '?', '1');
                if (!raw) return;
                api.post('/pantry/consume', { lot_id: lot.id, quantity: Number(raw) }).then(() => window.location.reload()).catch(fail);
            } else if (act === 'open') {
                api.post('/pantry/lots/' + lot.id + '/open', {}).then(() => window.location.reload()).catch(fail);
            } else if (act === 'toss') {
                api.post('/pantry/lots/' + lot.id + '/toss', {}).then(() => window.location.reload()).catch(fail);
            } else if (act === 'move') {
                sheet.innerHTML = moveSheet(lot);
                bindMove(lot);
            } else if (act === 'edit') {
                sheet.innerHTML = editSheet(lot);
                bindEdit(lot);
            }
        });
    }

    function moveSheet(lot) {
        return `<form class="ld-pantry-sheet" id="move-form">
            <p class="text-sm">Move ${esc(lot.name)} (${esc(String(lot.quantity))} ${esc(lot.unit)})</p>
            <div class="ld-pantry-locs mt-2">
                <button type="button" data-location="fridge">Fridge</button>
                <button type="button" data-location="freezer">Freezer</button>
                <button type="button" data-location="pantry">Pantry</button>
            </div>
            <label class="block text-xs text-muted mt-2">Quantity to move
                <input class="input" name="quantity" inputmode="decimal" value="${esc(String(lot.quantity))}">
            </label>
            <p class="text-xs text-muted mt-2">Or split a pack. Example: eat 1, freeze 2, keep the rest in the fridge.</p>
            <button type="submit" class="btn-primary text-sm mt-2">Move</button>
        </form>`;
    }

    function bindMove(lot) {
        const form = document.getElementById('move-form');
        let place = lot.location;
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
            const quantity = Number(new FormData(form).get('quantity'));
            api.post('/pantry/lots/' + lot.id + '/split', { location: place, quantity }).then(() => window.location.reload()).catch(fail);
        });
    }

    function editSheet(lot) {
        return `<form class="ld-pantry-sheet" id="edit-form">
            <p class="text-sm">Edit ${esc(lot.name)}</p>
            <label class="block text-xs text-muted mt-2">Quantity
                <input class="input" name="quantity" inputmode="decimal" value="${esc(String(lot.quantity))}">
            </label>
            <div class="ld-pantry-locs mt-2">
                <button type="button" data-location="fridge">Fridge</button>
                <button type="button" data-location="freezer">Freezer</button>
                <button type="button" data-location="pantry">Pantry</button>
            </div>
            <label class="block text-xs text-muted mt-2">Use by
                <input class="input" type="date" name="use_by" value="${esc(lot.use_by || '')}">
            </label>
            <label class="block text-xs text-muted mt-2">Store
                <input class="input" name="store" value="${esc(lot.store || '')}">
            </label>
            <button type="submit" class="btn-primary text-sm mt-2">Save lot</button>
        </form>`;
    }

    function bindEdit(lot) {
        const form = document.getElementById('edit-form');
        let place = lot.location;
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
            api.post('/pantry/lots/' + lot.id, {
                quantity: Number(data.quantity),
                location: place,
                use_by: data.use_by || null,
                store: data.store || null,
            }).then(() => window.location.reload()).catch(fail);
        });
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
                        ${esc(row.name)} <span class="ld-meta">${esc(row.category)}${row.upc ? ' · ' + esc(row.upc) : ''}</span>
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
            ['shelf_life_pantry_days', 'shelf_life_fridge_days', 'shelf_life_freezer_days', 'opened_life_days', 'min_on_hand'].forEach((key) => {
                data[key] = data[key] === '' ? null : Number(data[key]);
            });
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
            <label class="block text-xs text-muted mt-2">Quantity <input class="input w-full" name="quantity" inputmode="decimal" value="1"></label>
            <div class="ld-pantry-locs mt-2">
                <button type="button" data-location="fridge" class="is-on">Fridge</button>
                <button type="button" data-location="freezer">Freezer</button>
                <button type="button" data-location="pantry">Pantry</button>
            </div>
            <label class="block text-xs text-muted mt-2">Use by <input class="input w-full" type="date" name="use_by" value="${esc(prefill.use_by || suggestUseBy(prefill.shelf, 'fridge'))}"></label>
            <label class="block text-xs text-muted mt-2">Purchased <input class="input w-full" type="date" name="purchased_at" value="${esc(loading ? today : (prefill.purchased_at || today))}"></label>
            <label class="block text-xs text-muted mt-2">Store <input class="input w-full" name="store" value="${esc(loading ? 'existing' : (prefill.store || ''))}"></label>
            <label class="text-xs text-muted mt-2 flex gap-2 items-center"><input type="checkbox" name="loading" ${loading ? 'checked' : ''}> Loading what is already here</label>
            <button type="submit" class="btn-primary text-sm mt-3">Add</button>
        </form>`;
        host.prepend(wrap.firstElementChild);
        const form = document.getElementById('add-form');
        let place = 'fridge';
        const useBy = form.querySelector('[name="use_by"]');
        if (useBy && prefill.use_by) useBy.dataset.touched = '1';
        useBy?.addEventListener('input', () => { useBy.dataset.touched = '1'; });
        form.querySelectorAll('[data-location]').forEach((btn) => {
            btn.addEventListener('click', () => {
                place = btn.dataset.location;
                form.querySelectorAll('[data-location]').forEach((el) => el.classList.remove('is-on'));
                btn.classList.add('is-on');
                if (useBy && useBy.dataset.touched !== '1') {
                    useBy.value = suggestUseBy(prefill.shelf, place);
                }
            });
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
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
                        use_by: data.use_by || null,
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

    async function scan() {
        body.innerHTML = `
            <video id="pantry-video" class="ld-pantry-video" autoplay playsinline muted></video>
            <button type="button" class="btn-primary text-sm mt-3" id="scan-start">Scan</button>
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
        document.getElementById('scan-start').addEventListener('click', startCamera);
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

    async function startCamera() {
        const video = document.getElementById('pantry-video');
        if (scanStop) scanStop();
        if ('BarcodeDetector' in window) {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
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

    async function recipes() {
        const data = await api.get('/pantry/recipes');
        const ideas = await api.get('/pantry/what-can-i-make');
        const ranked = (ideas.recipes || []).map((row) =>
            `<li class="ld-pantry-lot">${esc(row.name)} <span class="ld-meta">${Math.round(row.coverage * 100)}%${row.soonest_use_by ? ' · ' + esc(row.soonest_use_by) : ''}${row.missing.length ? ' · missing ' + esc(row.missing.join(', ')) : ''}</span></li>`
        ).join('');
        body.innerHTML = `
            <h2 class="ld-pantry-label">What you can make</h2>
            <ul>${ranked || '<li class="empty-state mono text-xs">No recipes yet.</li>'}</ul>
            <form id="recipe-form" class="ld-pantry-sheet">
                <label class="block text-xs text-muted">Name <input class="input w-full" name="name" required></label>
                <label class="block text-xs text-muted mt-2">Notes <input class="input w-full" name="notes"></label>
                <label class="block text-xs text-muted mt-2">Ingredients, one per line: product name, or category:Produce
                    <textarea class="input w-full" name="lines" rows="4"></textarea>
                </label>
                <button type="submit" class="btn-primary text-sm mt-2">Save recipe</button>
            </form>
            <ul class="mt-3">${(data.recipes || []).map((row) => `<li class="text-sm">${esc(row.name)}</li>`).join('')}</ul>`;
        document.getElementById('recipe-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = new FormData(event.target);
            const products = await api.get('/pantry/products');
            const ingredients = [];
            for (const line of String(form.get('lines') || '').split('\n').map((row) => row.trim()).filter(Boolean)) {
                if (line.toLowerCase().startsWith('category:')) {
                    ingredients.push({ category: line.slice(9).trim() });
                    continue;
                }
                const match = (products.products || []).find((row) => row.name.toLowerCase() === line.toLowerCase());
                if (!match) {
                    fail({ message: 'No product named ' + line + '. Use category:Produce for an aisle.' });
                    return;
                }
                ingredients.push({ product_id: match.id });
            }
            api.post('/pantry/recipes', { name: form.get('name'), notes: form.get('notes') || null, ingredients })
                .then(() => window.location.reload()).catch(fail);
        });
    }

    const screens = { inventory, scan, purge, products, recipes };
    (screens[view] || inventory)().catch(fail);
})();
