/**
 * widgets.js — widget catalog groups, and the flights, packages, pings,
 * and stocks dashboard widgets.
 *
 * Categories are grouped here, not on the registry, so the catalog does
 * not need a PHP change. The same seven names are the only groups.
 */

'use strict';

function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

window.LD = window.LD || {};

if (!window.LD.catalog) {
    window.LD.catalog = (function () {
        const ORDER = ['Tracking', 'Data', 'Life', 'Money', 'Knowledge', 'Media', 'Custom'];
        const GROUP = {
            habits: 'Tracking',
            mood: 'Tracking',
            goal: 'Tracking',
            streak: 'Tracking',
            stat: 'Data',
            chart: 'Data',
            gauge: 'Data',
            table: 'Data',
            life: 'Life',
            calls: 'Life',
            shopping: 'Life',
            digest: 'Life',
            vitals: 'Life',
            agenda: 'Life',
            quick_actions: 'Life',
            pantry: 'Life',
            'legacy.weather': 'Life',
            'legacy.todos': 'Life',
            'legacy.bookmarks': 'Life',
            'legacy.flights': 'Life',
            'legacy.packages': 'Life',
            'legacy.pings': 'Life',
            spending: 'Money',
            networth: 'Money',
            baby_step: 'Money',
            debt_snowball: 'Money',
            food_week: 'Money',
            budget_actual: 'Money',
            sinking_funds: 'Money',
            bills_due: 'Money',
            retirement: 'Money',
            credit: 'Money',
            portfolio: 'Money',
            'legacy.stocks': 'Money',
            pages: 'Knowledge',
            'legacy.clipboards': 'Knowledge',
            youtube: 'Media',
            reddit: 'Media',
            spotify: 'Media',
            spotify_player: 'Media',
            reddit_feed: 'Media',
            x: 'Media',
            'legacy.news': 'Media',
            custom: 'Custom',
        };
        const ICON = {
            habits: 'list-checks',
            mood: 'smile',
            goal: 'target',
            streak: 'flame',
            stat: 'activity',
            chart: 'chart-line',
            gauge: 'gauge',
            table: 'square-pen',
            life: 'cloud-sun',
            vitals: 'heart-pulse',
            agenda: 'calendar-days',
            quick_actions: 'sparkles',
            calls: 'phone',
            shopping: 'shopping-cart',
            pantry: 'cooking-pot',
            digest: 'bot',
            'legacy.weather': 'sun',
            'legacy.todos': 'clipboard-list',
            'legacy.bookmarks': 'book',
            'legacy.flights': 'plane',
            'legacy.packages': 'package',
            'legacy.pings': 'server',
            spending: 'wallet-cards',
            networth: 'trending-up',
            baby_step: 'trophy',
            debt_snowball: 'credit-card',
            food_week: 'utensils',
            budget_actual: 'chart-column',
            sinking_funds: 'piggy-bank',
            bills_due: 'calendar-days',
            retirement: 'trending-up',
            credit: 'credit-card',
            portfolio: 'landmark',
            'legacy.stocks': 'chart-column',
            pages: 'book-open',
            'legacy.clipboards': 'file-text',
            spotify: 'music-2',
            spotify_player: 'music-2',
            youtube: 'play-square',
            reddit: 'message-square',
            reddit_feed: 'inbox',
            x: 'command',
            'legacy.news': 'newspaper',
            custom: 'code-xml',
        };
        const SETUP = {
            habits: { href: '/settings/trackers', label: 'Open habits' },
            mood: { href: '/settings/trackers', label: 'Open trackers' },
            goal: { href: '/settings/trackers', label: 'Open trackers' },
            streak: { href: '/settings/trackers', label: 'Open habits' },
            stat: { href: '/settings/trackers', label: 'Open trackers' },
            chart: { href: '/settings/trackers', label: 'Open trackers' },
            gauge: { href: '/settings/trackers', label: 'Open trackers' },
            table: { href: '/settings/trackers', label: 'Open trackers' },
            life: { href: '/settings/life', label: 'Open life' },
            vitals: { href: '/settings/trackers', label: 'Open trackers' },
            pantry: { href: '/settings/pantry', label: 'Open pantry' },
            spending: { href: '/settings/money', label: 'Open money' },
            networth: { href: '/settings/money', label: 'Open money' },
            baby_step: { href: '/settings/money', label: 'Open money' },
            debt_snowball: { href: '/settings/money', label: 'Open money' },
            food_week: { href: '/settings/money', label: 'Open money' },
            budget_actual: { href: '/settings/money', label: 'Open money' },
            sinking_funds: { href: '/settings/money', label: 'Open money' },
            bills_due: { href: '/settings/money', label: 'Open money' },
            retirement: { href: '/settings/money', label: 'Open money' },
            credit: { href: '/settings/money', label: 'Open money' },
            portfolio: { href: '/settings/investing', label: 'Open investing' },
            'legacy.stocks': { href: '/settings/stocks', label: 'Add a symbol' },
            'legacy.weather': { href: '/settings/weather', label: 'Open weather' },
            'legacy.packages': { href: '/settings/packages', label: 'Add a watch' },
            'legacy.pings': { href: '/settings/pings', label: 'Add a target' },
            'legacy.todos': { href: '/settings/todos', label: 'Open todos' },
            'legacy.bookmarks': { href: '/settings/bookmarks', label: 'Open bookmarks' },
            'legacy.clipboards': { href: '/settings/clipboards', label: 'Open clipboards' },
            'legacy.news': { href: '/settings/feeds', label: 'Open feeds' },
            pages: { href: '/brain', label: 'Open the vault' },
            spotify: { href: '/settings/spotify', label: 'Connect Spotify' },
            spotify_player: { href: '/settings/spotify', label: 'Connect Spotify' },
            reddit_feed: { href: '/settings/reddit', label: 'Open Reddit' },
            youtube: { sources: true, label: 'Add channels' },
            reddit: { sources: true, label: 'Add subreddits' },
            x: { sources: true, label: 'Add accounts' },
        };
        const iconCache = new Map();

        function categoryOf(type) {
            return GROUP[type] || 'Custom';
        }

        function iconOf(type) {
            return ICON[type] || 'layout-dashboard';
        }

        function setupFor(type) {
            return SETUP[type] || null;
        }

        function group(types) {
            const buckets = {};
            ORDER.forEach((name) => { buckets[name] = []; });
            const seen = new Set();
            (types || []).forEach((row) => {
                if (!row || row.type == null || seen.has(row.type)) return;
                seen.add(row.type);
                buckets[categoryOf(row.type)].push(row);
            });
            return ORDER.filter((name) => buckets[name].length).map((name) => ({
                name: name,
                items: buckets[name],
            }));
        }

        function iconMarkup(name) {
            if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name)) return Promise.resolve('');
            if (!iconCache.has(name)) {
                iconCache.set(name, fetch('/assets/vendor/lucide/' + name + '.svg', { credentials: 'same-origin' })
                    .then((res) => (res.ok ? res.text() : ''))
                    .then((text) => (text.indexOf('<svg') !== -1 && text.indexOf('<script') === -1 ? text : ''))
                    .catch(() => ''));
            }
            return iconCache.get(name);
        }

        return { order: ORDER, categoryOf: categoryOf, iconOf: iconOf, setupFor: setupFor, group: group, iconMarkup: iconMarkup };
    })();
}

if (!window.LD.states) {
    window.LD.states = {
        skeleton: function () {
            return '<div class="ld-skeleton" role="status" aria-label="Loading"><i></i><i></i><i></i><i></i></div>';
        },
        html: function (kind, opts) {
            opts = opts || {};
            const title = esc(opts.title || '');
            const body = esc(opts.body || '');
            let action = '';
            if (kind === 'error' && opts.reload) {
                action = '<button type="button" class="ld-state-action" data-ld-reload="' + esc(opts.reload) + '">Retry</button>';
            } else if (kind === 'error') {
                const retry = opts.retry != null ? opts.retry : opts.id;
                action = '<button type="button" class="ld-state-action" data-ld-retry="' + esc(retry) + '">Retry</button>';
            } else if (opts.href) {
                action = '<a class="ld-state-action" href="' + esc(opts.href) + '">' + esc(opts.label || 'Open Settings') + '</a>';
            } else if (opts.configure) {
                action = '<button type="button" class="ld-state-action" data-ld-setup="' + esc(opts.id) + '">' + esc(opts.label || 'Open Settings') + '</button>';
            }
            return '<div class="ld-state ld-state-' + esc(kind) + '">'
                + '<span class="ld-state-icon" aria-hidden="true"></span>'
                + '<strong>' + title + '</strong>'
                + (body ? '<span>' + body + '</span>' : '')
                + action
                + '</div>';
        },
    };
}

function formatCountdown(isoDep) {
    const nowMs = window.DashboardTime?.nowMs() ?? Date.now();
    const diff = new Date(isoDep.replace(' ', 'T')).getTime() - nowMs;
    if (diff <= 0) return 'departed / in progress';
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    return h > 0 ? `in ${h}h ${m}m` : `in ${m}m`;
}

function paintLegacyState(root, kind, opts) {
    root.dataset.ready = '1';
    root.innerHTML = window.LD.states.html(kind, opts);
    const slot = root.querySelector('.ld-state-icon');
    if (!slot || !window.LD.catalog) return;
    const icon = kind === 'error' ? 'activity' : (kind === 'setup' ? 'settings' : 'inbox');
    window.LD.catalog.iconMarkup(icon).then((svg) => {
        if (svg && slot.isConnected) slot.innerHTML = svg;
    });
}

function showLegacySkeleton(root) {
    if (root.dataset.ready === '1') return;
    root.innerHTML = window.LD.states.skeleton();
}

// -------------------------------------------------------
// Flights
// -------------------------------------------------------

async function loadFlightsWidget() {
    const root = document.getElementById('flight-widget');
    if (!root) return;
    showLegacySkeleton(root);

    try {
        const res = await fetch('/api/flights');
        if (!res.ok) throw new Error('The list could not be loaded.');
        const data = await res.json();
        const flights = data.flights || [];

        if (flights.length === 0) {
            paintLegacyState(root, 'empty', {
                title: 'No active flights',
                body: 'Add a watch to follow a flight here.',
                label: 'Add a watch',
                href: '/flights',
            });
            return;
        }

        root.dataset.ready = '1';
        root.innerHTML = flights.map(f => {
            const rowClass = f.external_url ? 'widget-row widget-row-link block' : 'widget-row';
            const rowTag   = f.external_url ? 'a' : 'div';
            const rowAttrs = f.external_url
                ? ` href="${esc(f.external_url)}" target="_blank" rel="noopener noreferrer" title="View on FlightAware"`
                : '';

            return `
            <${rowTag} class="${rowClass}"${rowAttrs}>
                <div class="flex justify-between items-center gap-1 mb-1 min-w-0">
                    <span class="mono text-primary font-semibold text-sm truncate">${esc(f.ident)}</span>
                    <span class="chip flex-shrink-0 ${f.airborne ? 'chip-ok' : 'chip-neutral'}">${esc(f.status_label)}</span>
                </div>
                <div class="mono widget-mono-sm text-faint flight-route truncate">
                    ${esc(f.origin_display || '---')} → ${esc(f.dest_display || '---')}
                </div>
                <div class="mono widget-mono-sm text-faint mt-1 truncate">
                    Departs ${formatCountdown(f.departure_at)}
                    <span class="flight-poll-hint">${f.in_poll_window ? '' : ' · 24h prior'}</span>
                </div>
            </${rowTag}>
        `;
        }).join('');
    } catch {
        paintLegacyState(root, 'error', {
            title: 'Could not load this widget',
            body: 'The list could not be loaded.',
            reload: 'flights',
        });
    }
}

// -------------------------------------------------------
// Ping status
// -------------------------------------------------------

async function loadPingsWidget() {
    const root = document.getElementById('ping-widget');
    if (!root) return;
    showLegacySkeleton(root);

    try {
        const res = await fetch('/api/pings');
        if (!res.ok) throw new Error('The checks could not be loaded.');
        const data = await res.json();
        const targets = data.targets || [];

        if (targets.length === 0) {
            paintLegacyState(root, 'setup', {
                title: 'Setup required',
                body: 'Add a host to watch whether it answers.',
                label: 'Add a target',
                href: '/settings/pings',
            });
            return;
        }

        root.dataset.ready = '1';
        root.innerHTML = `<ul class="space-y-1.5">${targets.map(t => {
            const up = t.is_up;
            const dot = up === null ? 'dot-unknown' : (up ? 'dot-up' : 'dot-down');
            const latency = t.latency_ms != null ? `${t.latency_ms} ms` : '—';
            return `
                <li class="flex items-center gap-2 text-sm min-w-0">
                    <span class="w-2 h-2 rounded-full flex-shrink-0 ${dot}"></span>
                    <span class="text-secondary truncate flex-1 min-w-0">${esc(t.label)}</span>
                    <span class="mono widget-mono-sm text-faint flex-shrink-0">${latency}</span>
                </li>
            `;
        }).join('')}</ul>`;
    } catch {
        paintLegacyState(root, 'error', {
            title: 'Could not load this widget',
            body: 'The checks could not be loaded.',
            reload: 'pings',
        });
    }
}

// -------------------------------------------------------
// Stocks
// -------------------------------------------------------

async function loadStocksWidget() {
    const root = document.getElementById('stocks-widget');
    if (!root) return;
    showLegacySkeleton(root);

    try {
        const res = await fetch('/api/stocks');
        if (!res.ok) throw new Error('Quotes could not be loaded.');
        const data = await res.json();
        const symbols = data.widget_symbols || data.symbols || [];

        if (symbols.length === 0) {
            paintLegacyState(root, 'setup', {
                title: 'Setup required',
                body: 'Add a ticker to follow it here.',
                label: 'Add a symbol',
                href: '/settings/stocks',
            });
            return;
        }

        root.dataset.ready = '1';
        root.innerHTML = `<div class="space-y-1">${symbols.map(s => {
            const price = s.price != null ? s.price.toFixed(2) : '—';
            const chg = s.change_amount;
            const pct = s.change_percent;
            const hasQuote = s.price != null;
            const up = hasQuote && chg >= 0;
            const color = !hasQuote ? 'quote-flat' : (up ? 'quote-up' : 'quote-down');
            const chgStr = hasQuote
                ? `${chg >= 0 ? '+' : ''}${chg.toFixed(2)} (${pct >= 0 ? '+' : ''}${pct.toFixed(2)}%)`
                : 'awaiting cron';
            return `
                <div class="stock-line mono text-sm min-w-0">
                    <span class="text-primary font-medium truncate flex-shrink-0">${esc(s.symbol)}</span>
                    <span class="text-secondary flex-shrink-0">$${price}</span>
                    <span class="widget-mono-sm ${color} flex-shrink-0 text-right">${chgStr}</span>
                </div>
            `;
        }).join('')}</div>`;
    } catch {
        paintLegacyState(root, 'error', {
            title: 'Could not load this widget',
            body: 'Quotes could not be loaded.',
            reload: 'stocks',
        });
    }
}

// -------------------------------------------------------
// Packages
// -------------------------------------------------------

async function loadPackagesWidget() {
    const root = document.getElementById('package-widget');
    if (!root) return;
    showLegacySkeleton(root);

    try {
        const res = await fetch('/api/packages');
        if (!res.ok) throw new Error('Tracking could not be loaded.');
        const data = await res.json();
        const packages = data.packages || [];

        if (packages.length === 0) {
            paintLegacyState(root, 'setup', {
                title: 'Setup required',
                body: 'Add a tracking number to follow a delivery.',
                label: 'Add a watch',
                href: '/settings/packages',
            });
            return;
        }

        root.dataset.ready = '1';
        root.innerHTML = packages.map(p => {
            const title = p.label || p.tracking_number;
            const rowClass = p.external_url ? 'widget-row widget-row-link block' : 'widget-row';
            const rowTag   = p.external_url ? 'a' : 'div';
            const rowAttrs = p.external_url
                ? ` href="${esc(p.external_url)}" target="_blank" rel="noopener noreferrer" title="Track on carrier site"`
                : '';
            const carrier = p.carrier_resolved || p.carrier;
            const carrierName = { ups: 'UPS', fedex: 'FedEx', dhl: 'DHL', usps: 'USPS', amazon: 'Amazon', ontrac: 'OnTrac', lasership: 'LaserShip' }[carrier] || carrier;
            const eta = p.estimated_delivery_at
                ? new Date(p.estimated_delivery_at.replace(' ', 'T')).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
                : null;

            return `
            <${rowTag} class="${rowClass}"${rowAttrs}>
                <div class="flex justify-between items-center gap-1 mb-1 min-w-0">
                    <span class="mono text-primary font-semibold text-sm truncate">${esc(title)}</span>
                    <span class="chip flex-shrink-0 chip-neutral">${esc(p.status_label)}</span>
                </div>
                <div class="mono widget-mono-sm text-faint truncate">
                    ${esc(carrierName)} · ${esc(p.tracking_number)}
                </div>
                ${eta ? `<div class="mono widget-mono-sm text-faint mt-1 truncate">ETA ${esc(eta)}</div>` : ''}
            </${rowTag}>
        `;
        }).join('');
    } catch {
        paintLegacyState(root, 'error', {
            title: 'Could not load this widget',
            body: 'Tracking could not be loaded.',
            reload: 'packages',
        });
    }
}

if (!window.__ldWidgetsBooted) {
    window.__ldWidgetsBooted = true;
    const legacyReload = {
        flights: loadFlightsWidget,
        pings: loadPingsWidget,
        stocks: loadStocksWidget,
        packages: loadPackagesWidget,
    };
    const legacyRoot = { flights: 'flight-widget', pings: 'ping-widget', stocks: 'stocks-widget', packages: 'package-widget' };
    document.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-ld-reload]');
        if (!btn) return;
        const host = document.getElementById(legacyRoot[btn.dataset.ldReload] || '');
        if (host) host.dataset.ready = '';
        const load = legacyReload[btn.dataset.ldReload];
        if (load) load();
    });
    function arm(id, load, ms) {
        if (!document.getElementById(id)) return;
        load();
        setInterval(load, ms);
    }
    arm('flight-widget', loadFlightsWidget, 5 * 60 * 1000);
    arm('ping-widget', loadPingsWidget, 2 * 60 * 1000);
    arm('stocks-widget', loadStocksWidget, 15 * 60 * 1000);
    arm('package-widget', loadPackagesWidget, 5 * 60 * 1000);
}
