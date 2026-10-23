/**
 * widgets.js — flights, packages, pings, stocks dashboard widgets
 */

'use strict';

function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function formatCountdown(isoDep) {
    const nowMs = window.DashboardTime?.nowMs() ?? Date.now();
    const diff = new Date(isoDep.replace(' ', 'T')).getTime() - nowMs;
    if (diff <= 0) return 'departed / in progress';
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    return h > 0 ? `in ${h}h ${m}m` : `in ${m}m`;
}

// -------------------------------------------------------
// Flights
// -------------------------------------------------------

async function loadFlightsWidget() {
    const root = document.getElementById('flight-widget');
    if (!root) return;

    try {
        const res = await fetch('/api/flights');
        const data = await res.json();
        const flights = data.flights || [];

        if (flights.length === 0) {
            root.innerHTML = window.DashboardEmpty.html({
                title: 'No active flights',
                body: 'Add a watch to follow a flight here.',
                actionLabel: 'Add a watch',
                actionHref: '/settings/flights',
            });
            return;
        }

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
        root.innerHTML = window.DashboardEmpty.html({
            title: 'Flights unavailable',
            body: 'The list could not be loaded.',
            actionLabel: 'Check watches',
            actionHref: '/settings/flights',
        });
    }
}

// -------------------------------------------------------
// Ping status
// -------------------------------------------------------

async function loadPingsWidget() {
    const root = document.getElementById('ping-widget');
    if (!root) return;

    try {
        const res = await fetch('/api/pings');
        const data = await res.json();
        const targets = data.targets || [];

        if (targets.length === 0) {
            root.innerHTML = window.DashboardEmpty.html({
                title: 'No targets',
                body: 'Add a host to watch whether it answers.',
                actionLabel: 'Add a target',
                actionHref: '/settings/pings',
            });
            return;
        }

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
        root.innerHTML = window.DashboardEmpty.html({
            title: 'Status unavailable',
            body: 'The checks could not be loaded.',
            actionLabel: 'Open status',
            actionHref: '/settings/pings',
        });
    }
}

// -------------------------------------------------------
// Stocks
// -------------------------------------------------------

async function loadStocksWidget() {
    const root = document.getElementById('stocks-widget');
    if (!root) return;

    try {
        const res = await fetch('/api/stocks');
        const data = await res.json();
        const symbols = data.widget_symbols || data.symbols || [];

        if (symbols.length === 0) {
            root.innerHTML = window.DashboardEmpty.html({
                title: 'No symbols',
                body: 'Add a ticker to follow it here.',
                actionLabel: 'Add a symbol',
                actionHref: '/settings/stocks',
            });
            return;
        }

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
        root.innerHTML = window.DashboardEmpty.html({
            title: 'Stocks unavailable',
            body: 'Quotes could not be loaded.',
            actionLabel: 'Check symbols',
            actionHref: '/settings/stocks',
        });
    }
}

// -------------------------------------------------------
// Packages
// -------------------------------------------------------

async function loadPackagesWidget() {
    const root = document.getElementById('package-widget');
    if (!root) return;

    try {
        const res = await fetch('/api/packages');
        const data = await res.json();
        const packages = data.packages || [];

        if (packages.length === 0) {
            root.innerHTML = window.DashboardEmpty.html({
                title: 'No packages in transit',
                body: 'Add a tracking number to follow a delivery.',
                actionLabel: 'Add a watch',
                actionHref: '/settings/packages',
            });
            return;
        }

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
        root.innerHTML = window.DashboardEmpty.html({
            title: 'Packages unavailable',
            body: 'Tracking could not be loaded.',
            actionLabel: 'Check watches',
            actionHref: '/settings/packages',
        });
    }
}

loadFlightsWidget();
loadPackagesWidget();
loadPingsWidget();
loadStocksWidget();

setInterval(loadFlightsWidget, 5 * 60 * 1000);
setInterval(loadPackagesWidget, 5 * 60 * 1000);
setInterval(loadPingsWidget, 2 * 60 * 1000);
setInterval(loadStocksWidget, 15 * 60 * 1000);
