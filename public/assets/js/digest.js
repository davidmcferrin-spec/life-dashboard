/**
 * Grok Digest page: feed, filters, and source form.
 */
'use strict';

(function () {
    const { api, esc } = window.LD;
    const app = document.getElementById('digest-app');
    if (!app || !api) return;

    const els = {
        count: document.getElementById('digest-count'),
        status: document.getElementById('digest-status'),
        error: document.getElementById('digest-error'),
        products: document.getElementById('digest-products'),
        feed: document.getElementById('digest-feed'),
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
    let timer = null;

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
        return date.toLocaleString();
    }

    function query() {
        const params = new URLSearchParams();
        if (product) params.set('product', product);
        if (els.unread.checked) params.set('unread', '1');
        const q = els.q.value.trim();
        if (q) params.set('q', q);
        const text = params.toString();
        return text ? '?' + text : '';
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

    function renderFeed(data) {
        const count = Number(data.new_count || 0);
        els.count.textContent = count === 1 ? '1 new entry' : count + ' new entries';
        const items = data.items || [];
        if (!items.length) {
            els.feed.innerHTML = '<p class="empty-state mono text-xs">No matching entries.</p>';
            return;
        }
        els.feed.innerHTML = items.map((item) => {
            const badges = [
                item.is_new ? '<span class="ld-digest-badge">New</span>' : '',
                item.edited ? '<span class="ld-digest-badge">Edited</span>' : '',
            ].filter(Boolean).join(' ');
            const date = item.published_on || '';
            return `<article class="surface p-3">
                <p class="text-sm text-muted">${esc(item.product_label)} · ${esc(item.source_label)}${date ? ' · ' + esc(date) : ''}${item.version ? ' · ' + esc(item.version) : ''}</p>
                <h2 class="text-primary text-base mt-1"><a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.title)}</a></h2>
                ${badges ? `<p class="mt-1">${badges}</p>` : ''}
                ${item.summary ? `<p class="text-sm mt-2">${esc(item.summary)}</p>` : ''}
            </article>`;
        }).join('');
    }

    function renderSources(data) {
        products = data.products || products;
        sourceRows = data.sources || [];
        renderChips();
        fillCompareSources();
        if (els.retain && !els.retain.dataset.ready && data.retention_days) {
            els.retain.value = String(data.retention_days);
            els.retain.dataset.ready = '1';
        }
        const groups = {};
        (data.sources || []).forEach((source) => {
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

    async function load() {
        clearError();
        try {
            const [feed, sources] = await Promise.all([
                api.get('/digest/items' + query()),
                api.get('/digest/sources'),
            ]);
            products = feed.products || [];
            renderChips();
            renderFeed(feed);
            renderSources(sources);
        } catch (error) {
            showError(error);
        }
    }

    els.products.addEventListener('click', (event) => {
        const button = event.target.closest('[data-product]');
        if (!button) return;
        product = button.getAttribute('data-product') || '';
        load();
    });
    els.unread.addEventListener('change', () => load());
    document.getElementById('digest-search').addEventListener('submit', (event) => {
        event.preventDefault();
        load();
    });
    els.q.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(load, 250);
    });
    document.getElementById('digest-read').addEventListener('click', async () => {
        clearError();
        try {
            await api.post('/digest/read', {});
            els.status.textContent = 'Marked read.';
            await load();
        } catch (error) {
            showError(error);
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
            await load();
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
                await load();
            } else if (remove) {
                await api.post('/digest/sources/' + remove.getAttribute('data-remove') + '/remove', {});
                await load();
            }
        } catch (error) {
            showError(error);
        }
    });
    function article(item) {
        const date = item.published_on || '';
        const version = item.version ? ' · ' + esc(item.version) : '';
        return `<article class="surface p-3">
            <p class="text-sm text-muted">${esc(item.product_label)} · ${esc(item.source_label)}${date ? ' · ' + esc(date) : ''}${version}</p>
            <h3 class="text-primary text-base mt-1"><a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.title)}</a></h3>
            ${item.summary ? `<p class="text-sm mt-2">${esc(item.summary)}</p>` : ''}
        </article>`;
    }

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
                ? data.items.map(article).join('')
                : '<p class="empty-state mono text-xs">No entries in that range.</p>';
            return;
        }
        const groups = data.groups || [];
        const noun = data.count === 1 ? ' entry' : ' entries';
        els.compareCount.textContent = data.count + noun + ' from ' + data.from + ' to ' + data.to
            + (data.truncated ? '. Showing the first ' + groups.reduce((n, group) => n + group.items.length, 0) + '.' : '');
        if (!groups.length) {
            els.compareOut.innerHTML = '<p class="empty-state mono text-xs">No entries in that range.</p>';
            return;
        }
        els.compareOut.innerHTML = groups.map((group) => {
            const rows = (group.items || []).map(article).join('');
            return `<section><h3 class="text-sm text-muted">${esc(group.product_label)} · ${group.count}</h3>${rows}</section>`;
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

    window.addEventListener('lifedash:poll', () => load());
    window.addEventListener('lifedash:event', (event) => {
        const table = event.detail && event.detail.table;
        if (table === 'digest_entries' || table === 'digest_sources' || table === 'digest_cursors') {
            load();
        }
    });
    load();
})();
