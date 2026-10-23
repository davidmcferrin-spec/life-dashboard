/**
 * Grok Digest page: a short feed, with sources and compare folded away.
 */
'use strict';

(function () {
    const { api, esc } = window.LD;
    const app = document.getElementById('digest-app');
    if (!app || !api) return;

    const PAGE = 8;
    const DATE_ORDER = ['Today', 'Yesterday', 'This week', 'Older'];
    const els = {
        checked: document.getElementById('digest-checked'),
        count: document.getElementById('digest-count'),
        status: document.getElementById('digest-status'),
        error: document.getElementById('digest-error'),
        products: document.getElementById('digest-products'),
        feed: document.getElementById('digest-feed'),
        more: document.getElementById('digest-more'),
        sources: document.getElementById('digest-sources'),
        q: document.getElementById('digest-q'),
        unread: document.getElementById('digest-unread'),
        product: document.getElementById('digest-product'),
        label: document.getElementById('digest-label'),
        url: document.getElementById('digest-url'),
        compareProduct: document.getElementById('digest-compare-product'),
        fromVersion: document.getElementById('digest-from-version'),
        toVersion: document.getElementById('digest-to-version'),
        snapSource: document.getElementById('digest-snap-source'),
        compareCount: document.getElementById('digest-compare-count'),
        compareOut: document.getElementById('digest-compare-out'),
        retain: document.getElementById('digest-retain'),
    };
    let product = '';
    let products = [];
    let sourceRows = [];
    let compareMode = 'date';
    let group = 'date';
    let timer = null;
    let items = [];
    let hasMore = false;
    let newCount = 0;
    let today = '';
    let loadGen = 0;
    const openIds = new Set();

    function showError(error) {
        const message = (error && error.message) || 'Request failed.';
        els.error.textContent = message;
        els.error.classList.remove('hidden');
    }

    function clearError() {
        els.error.textContent = '';
        els.error.classList.add('hidden');
    }

    function when(value) {
        if (!value) return 'Not checked yet';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return formatStamp(date);
    }

    function formatStamp(date) {
        const tz = app.getAttribute('data-tz') || 'America/Chicago';
        return new Intl.DateTimeFormat('en-US', {
            timeZone: tz,
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    function formatDay(iso) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!match) return '';
        const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
        return new Intl.DateTimeFormat('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(date);
    }

    function shiftDay(iso, delta) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!match) return '';
        const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
        date.setUTCDate(date.getUTCDate() + delta);
        return date.toISOString().slice(0, 10);
    }

    function bucket(day) {
        if (!day || !today) return 'Older';
        if (day === today) return 'Today';
        if (day === shiftDay(today, -1)) return 'Yesterday';
        const start = shiftDay(today, -6);
        if (day >= start && day < today) return 'This week';
        return 'Older';
    }

    function query(offset) {
        const params = new URLSearchParams();
        if (product) params.set('product', product);
        if (els.unread.checked) params.set('unread', '1');
        const q = els.q.value.trim();
        if (q) params.set('q', q);
        params.set('limit', String(PAGE));
        if (offset) params.set('offset', String(offset));
        return '?' + params.toString();
    }

    function renderChips() {
        const chips = [{ id: '', label: 'All' }].concat(products);
        els.products.innerHTML = chips.map((chip) => {
            const on = chip.id === product ? ' is-on' : '';
            return `<button type="button" class="ld-digest-chip${on}" data-product="${esc(chip.id)}">${esc(chip.label)}</button>`;
        }).join('');
        if (els.product.options.length === 0) {
            els.product.innerHTML = products.map((chip) => `<option value="${esc(chip.id)}">${esc(chip.label)}</option>`).join('');
        }
    }

    function card(item) {
        const id = String(item.id);
        const open = openIds.has(id);
        const dateIso = item.day || item.published_on || '';
        const date = formatDay(dateIso);
        const found = item.date_source === 'found';
        const badges = [
            item.is_new ? '<span class="ld-digest-badge">Unread</span>' : '',
            item.edited ? '<span class="ld-digest-badge">Edited</span>' : '',
        ].filter(Boolean).join('');
        const version = item.version ? `<span class="text-sm text-muted">${esc(item.version)}</span>` : '';
        const source = item.source_label ? `<span class="text-sm text-muted">${esc(item.source_label)}</span>` : '';
        const summary = item.summary || '';
        const body = item.body || '';
        const extra = body && body !== summary
            ? `<button type="button" class="btn ld-digest-toggle" data-toggle-note="${esc(id)}" aria-expanded="${open ? 'true' : 'false'}">${open ? 'Hide note' : 'Show note'}</button><div class="ld-digest-body" id="digest-note-${esc(id)}"${open ? '' : ' hidden'}>${esc(body)}</div>`
            : '';
        return `<article class="surface ld-digest-item">
            <div class="ld-digest-head">
                <span class="ld-digest-badge">${esc(item.product_label || '')}</span>
                ${source}
                ${version}
                ${badges}
                ${date ? `<time class="ld-digest-date${found ? ' is-found' : ''}" datetime="${esc(dateIso)}">${found ? 'Found ' : ''}${esc(date)}</time>` : ''}
            </div>
            <h3 class="ld-digest-title"><a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.title)}</a></h3>
            ${summary ? `<p class="ld-digest-brief">${esc(summary)}</p>` : ''}
            ${extra}
        </article>`;
    }

    function groupsFor(rows) {
        if (group === 'source') {
            const order = products.map((chip) => chip.label);
            const buckets = {};
            rows.forEach((item) => {
                const name = item.product_label || 'Source';
                buckets[name] = buckets[name] || [];
                buckets[name].push(item);
            });
            return Object.keys(buckets).sort((a, b) => {
                const ai = order.indexOf(a);
                const bi = order.indexOf(b);
                return (ai < 0 ? 99 : ai) - (bi < 0 ? 99 : bi);
            }).map((name) => ({ name: name, items: buckets[name] }));
        }
        const buckets = {};
        rows.forEach((item) => {
            const name = bucket(item.day || item.published_on || '');
            buckets[name] = buckets[name] || [];
            buckets[name].push(item);
        });
        return DATE_ORDER.filter((name) => buckets[name]).map((name) => ({ name: name, items: buckets[name] }));
    }

    function renderFeed() {
        const noun = newCount === 1 ? '1 unread' : newCount + ' unread';
        els.count.textContent = items.length ? noun + ' · ' + items.length + ' shown' : noun;
        els.more.classList.toggle('hidden', !hasMore);
        if (!items.length) {
            const never = sourceRows.length
                ? sourceRows.every((row) => !row.last_checked_at)
                : (els.checked.textContent || '').indexOf('Not checked') === 0;
            els.feed.innerHTML = window.DashboardEmpty.html(never ? {
                title: 'Not checked yet',
                body: 'Check now to pull the latest release notes.',
                actionLabel: 'Check now',
                actionAttrs: 'type="button" data-empty-check',
            } : {
                title: 'No entries',
                body: 'Nothing matches this filter.',
                actionLabel: 'Show everything',
                actionAttrs: 'type="button" data-empty-reset',
            });
            return;
        }
        els.feed.innerHTML = groupsFor(items).map((section) => {
            return `<section class="ld-digest-group"><h2>${esc(section.name)}</h2>${section.items.map(card).join('')}</section>`;
        }).join('');
    }

    function renderChecked(rows) {
        let latest = 0;
        (rows || []).forEach((source) => {
            if (!source.last_checked_at) return;
            const time = Date.parse(source.last_checked_at);
            if (!Number.isNaN(time) && time > latest) latest = time;
        });
        els.checked.textContent = latest
            ? 'Last checked ' + formatStamp(new Date(latest)) + '.'
            : 'Not checked yet.';
    }

    function renderSources(data) {
        products = data.products || products;
        sourceRows = data.sources || [];
        renderChips();
        fillCompareSources();
        renderChecked(sourceRows);
        if (els.retain && !els.retain.dataset.ready && data.retention_days) {
            els.retain.value = String(data.retention_days);
            els.retain.dataset.ready = '1';
        }
        const groups = {};
        sourceRows.forEach((source) => {
            const key = source.product_label || source.product;
            groups[key] = groups[key] || [];
            groups[key].push(source);
        });
        els.sources.innerHTML = Object.keys(groups).map((name) => {
            const rows = groups[name].map((source) => {
                const state = source.status === 'needs_attention'
                    ? esc(source.last_error || 'Needs attention')
                    : (source.parse_mode ? esc(source.parse_mode) + ' · ' : '') + esc(when(source.last_checked_at));
                const toggle = source.enabled ? 'Disable' : 'Enable';
                const action = source.enabled ? 'disable' : 'enable';
                return `<li class="py-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <a href="${esc(source.url)}" target="_blank" rel="noopener">${esc(source.label)}</a>
                            <p class="text-sm text-muted">${state}</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="btn" data-toggle="${source.id}" data-action="${action}">${toggle}</button>
                            <button type="button" class="btn" data-remove="${source.id}">Remove</button>
                        </div>
                    </div>
                </li>`;
            }).join('');
            return `<div><h3 class="text-sm text-muted">${esc(name)}</h3><ul>${rows}</ul></div>`;
        }).join('');
    }

    async function load(reset) {
        const gen = ++loadGen;
        clearError();
        const offset = reset ? 0 : items.length;
        try {
            const feedPromise = api.get('/digest/items' + query(offset));
            const sourcePromise = reset ? api.get('/digest/sources') : Promise.resolve(null);
            const [feed, sources] = await Promise.all([feedPromise, sourcePromise]);
            if (gen !== loadGen) return;
            today = feed.today || today;
            newCount = Number(feed.new_count || 0);
            hasMore = !!feed.has_more;
            const batch = feed.items || [];
            items = reset ? batch : items.concat(batch);
            products = feed.products || products;
            if (sources) renderSources(sources);
            renderChips();
            renderFeed();
        } catch (error) {
            if (gen !== loadGen) return;
            showError(error);
        }
    }

    els.products.addEventListener('click', (event) => {
        const button = event.target.closest('[data-product]');
        if (!button) return;
        product = button.getAttribute('data-product') || '';
        load(true);
    });
    document.getElementById('digest-group').addEventListener('click', (event) => {
        const button = event.target.closest('[data-group]');
        if (!button) return;
        group = button.getAttribute('data-group') || 'date';
        document.querySelectorAll('#digest-group [data-group]').forEach((chip) => {
            chip.classList.toggle('is-on', chip === button);
        });
        renderFeed();
    });
    els.unread.addEventListener('change', () => load(true));
    document.getElementById('digest-search').addEventListener('submit', (event) => {
        event.preventDefault();
        load(true);
    });
    els.q.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => load(true), 250);
    });
    els.more.addEventListener('click', () => load(false));
    els.feed.addEventListener('click', (event) => {
        const button = event.target.closest('[data-toggle-note]');
        if (!button) return;
        const id = button.getAttribute('data-toggle-note');
        const note = document.getElementById('digest-note-' + id);
        if (!note) return;
        const open = note.hasAttribute('hidden');
        if (open) {
            note.removeAttribute('hidden');
            openIds.add(id);
            button.textContent = 'Hide note';
        } else {
            note.setAttribute('hidden', '');
            openIds.delete(id);
            button.textContent = 'Show note';
        }
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.getElementById('digest-read').addEventListener('click', async () => {
        clearError();
        try {
            await api.post('/digest/read', {});
            els.status.textContent = 'Marked read.';
            await load(true);
        } catch (error) {
            showError(error);
        }
    });
    els.feed.addEventListener('click', (event) => {
        if (event.target.closest('[data-empty-check]')) {
            document.getElementById('digest-check').click();
            return;
        }
        if (event.target.closest('[data-empty-reset]')) {
            product = '';
            els.q.value = '';
            els.unread.checked = false;
            load(true);
        }
    });
    document.getElementById('digest-check').addEventListener('click', async () => {
        clearError();
        try {
            await api.post('/digest/check', {});
            els.status.textContent = 'Check queued.';
        } catch (error) {
            showError(error);
        }
    });
    document.getElementById('digest-add').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        try {
            await api.post('/digest/sources', {
                product: els.product.value,
                label: els.label.value,
                url: els.url.value,
            });
            els.label.value = '';
            els.url.value = '';
            await load(true);
        } catch (error) {
            showError(error);
        }
    });
    els.sources.addEventListener('click', async (event) => {
        const toggle = event.target.closest('[data-toggle]');
        const remove = event.target.closest('[data-remove]');
        clearError();
        try {
            if (toggle) {
                const id = toggle.getAttribute('data-toggle');
                const action = toggle.getAttribute('data-action');
                await api.post('/digest/sources/' + id + '/' + action, {});
                await load(true);
            } else if (remove) {
                await api.post('/digest/sources/' + remove.getAttribute('data-remove') + '/remove', {});
                await load(true);
            }
        } catch (error) {
            showError(error);
        }
    });

    function fillCompareSources() {
        const options = ['<option value="">All products</option>'].concat(products.map((chip) => {
            return `<option value="${esc(chip.id)}">${esc(chip.label)}</option>`;
        }));
        const current = els.compareProduct.value;
        els.compareProduct.innerHTML = options.join('');
        if (current) els.compareProduct.value = current;
        const selected = els.snapSource.value;
        els.snapSource.innerHTML = sourceRows.map((source) => {
            return `<option value="${source.id}">${esc(source.product_label)} · ${esc(source.label)}</option>`;
        }).join('');
        if (selected) els.snapSource.value = selected;
    }

    function applyCompareMode(mode) {
        compareMode = mode;
        document.querySelectorAll('#digest-compare-modes [data-mode]').forEach((button) => {
            button.classList.toggle('is-on', button.getAttribute('data-mode') === mode);
        });
        document.querySelectorAll('#digest-compare [data-for]').forEach((el) => {
            const modes = (el.getAttribute('data-for') || '').split(/\s+/);
            el.classList.toggle('hidden', modes.indexOf(mode) === -1);
        });
    }

    async function loadVersions() {
        const productId = els.compareProduct.value;
        if (!productId) {
            els.fromVersion.innerHTML = '';
            els.toVersion.innerHTML = '';
            return;
        }
        const data = await api.get('/digest/versions?product=' + encodeURIComponent(productId));
        const options = (data.versions || []).map((row) => `<option value="${esc(row.version)}">${esc(row.version)}</option>`).join('');
        els.fromVersion.innerHTML = options;
        els.toVersion.innerHTML = options;
        if (els.toVersion.options.length > 1) {
            els.toVersion.selectedIndex = els.toVersion.options.length - 1;
        }
    }

    function renderCompare(data) {
        if (data.mode === 'version') {
            const noun = data.count === 1 ? ' entry' : ' entries';
            const line = data.truncated
                ? 'Showing ' + data.items.length + ' of ' + data.count
                : String(data.count) + noun;
            els.compareCount.textContent = line + ' from ' + data.from_version + ' to ' + data.to_version;
            els.compareOut.innerHTML = data.items.length
                ? data.items.map(card).join('')
                : window.DashboardEmpty.html({
                    title: 'No entries',
                    body: 'Nothing falls in that range.',
                    actionLabel: 'Compare again',
                    actionAttrs: 'type="submit" form="digest-compare-form"',
                });
            return;
        }
        const groups = data.groups || [];
        const noun = data.count === 1 ? ' entry' : ' entries';
        els.compareCount.textContent = data.count + noun + ' from ' + data.from + ' to ' + data.to
            + (data.truncated ? '. Showing the first ' + groups.reduce((n, section) => n + section.items.length, 0) + '.' : '');
        if (!groups.length) {
            els.compareOut.innerHTML = window.DashboardEmpty.html({
                title: 'No entries',
                body: 'Nothing falls in that range.',
                actionLabel: 'Compare again',
                actionAttrs: 'type="submit" form="digest-compare-form"',
            });
            return;
        }
        els.compareOut.innerHTML = groups.map((section) => {
            const rows = (section.items || []).map(card).join('');
            return `<section class="ld-digest-group"><h2>${esc(section.product_label)} · ${section.count}</h2>${rows}</section>`;
        }).join('');
    }

    document.getElementById('digest-compare-modes').addEventListener('click', (event) => {
        const button = event.target.closest('[data-mode]');
        if (!button) return;
        applyCompareMode(button.getAttribute('data-mode') || 'date');
        if (compareMode === 'version') {
            loadVersions().catch(showError);
        }
    });
    els.compareProduct.addEventListener('change', () => {
        if (compareMode === 'version') loadVersions().catch(showError);
    });
    document.getElementById('digest-compare-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        els.compareCount.textContent = '';
        els.compareOut.innerHTML = '';
        try {
            if (compareMode === 'version' && !els.compareProduct.value) {
                showError({ message: 'Choose a product for a version compare.' });
                return;
            }
            if (compareMode === 'snapshot') {
                const params = new URLSearchParams({
                    source_id: els.snapSource.value,
                    from: document.getElementById('digest-snap-from').value,
                    to: document.getElementById('digest-snap-to').value,
                });
                const data = await api.get('/digest/snapshot?' + params.toString());
                els.compareCount.textContent = data.diff.summary + ' (' + data.from_captured_on + ' to ' + data.to_captured_on + ')';
                els.compareOut.innerHTML = '<pre class="brain-redline ld-digest-diff" aria-label="Red-line between the two snapshots"></pre>';
                els.compareOut.querySelector('pre').innerHTML = data.diff.html;
                return;
            }
            const params = new URLSearchParams();
            if (els.compareProduct.value) params.set('product', els.compareProduct.value);
            if (compareMode === 'version') {
                params.set('from_version', els.fromVersion.value);
                params.set('to_version', els.toVersion.value);
            } else {
                params.set('from_date', document.getElementById('digest-from-date').value);
                params.set('to_date', document.getElementById('digest-to-date').value);
            }
            renderCompare(await api.get('/digest/compare?' + params.toString()));
        } catch (error) {
            showError(error);
        }
    });
    document.getElementById('digest-retain-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        try {
            const saved = await api.post('/digest/retention', { days: Number(els.retain.value) });
            els.retain.value = String(saved.retention_days);
            els.status.textContent = 'Snapshots are kept for ' + saved.retention_days + ' days.';
        } catch (error) {
            showError(error);
        }
    });

    window.addEventListener('lifedash:poll', () => load(true));
    window.addEventListener('lifedash:event', (event) => {
        const table = event.detail && event.detail.table;
        if (table === 'digest_entries' || table === 'digest_sources' || table === 'digest_cursors') {
            load(true);
        }
    });
    load(true);
})();
