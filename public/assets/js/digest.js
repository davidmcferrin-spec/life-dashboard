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
    };
    let product = '';
    let products = [];
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
                <p class="text-sm text-muted">${esc(item.product_label)} · ${esc(item.source_label)}${date ? ' · ' + esc(date) : ''}</p>
                <h2 class="text-primary text-base mt-1"><a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.title)}</a></h2>
                ${badges ? `<p class="mt-1">${badges}</p>` : ''}
                ${item.summary ? `<p class="text-sm mt-2">${esc(item.summary)}</p>` : ''}
            </article>`;
        }).join('');
    }

    function renderSources(data) {
        products = data.products || products;
        renderChips();
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
    window.addEventListener('lifedash:poll', () => load());
    window.addEventListener('lifedash:event', (event) => {
        const table = event.detail && event.detail.table;
        if (table === 'digest_entries' || table === 'digest_sources' || table === 'digest_cursors') {
            load();
        }
    });
    load();
})();
