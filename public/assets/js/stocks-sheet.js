'use strict';

(function () {
    const root = document.getElementById('stocks-sheet');
    if (!root || !window.LD) return;
    const ld = window.LD;

    function esc(value) {
        return ld.esc(value == null ? '' : String(value));
    }

    function money(value, currency) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        const code = /^[A-Z]{3}$/.test(String(currency || '')) ? String(currency) : 'USD';
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).format(Number(value));
        } catch (err) {
            return ld.fmt.money(value);
        }
    }

    function points(value) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        const number = Number(value);
        const sign = number > 0 ? '+' : '';
        return sign + number.toLocaleString(undefined, { maximumFractionDigits: 2 }) + '%';
    }

    function plain(value, digits) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        return Number(value).toLocaleString(undefined, { maximumFractionDigits: digits });
    }

    function tone(value) {
        if (value == null || !Number.isFinite(Number(value)) || Number(value) === 0) return '';
        return Number(value) < 0 ? 'is-down' : 'is-up';
    }

    function empty(title, body) {
        if (!window.DashboardEmpty) return '<p class="fin-note">' + esc(title) + '</p>';
        return window.DashboardEmpty.html({ title: title, body: body });
    }

    function detail(row) {
        const bits = [];
        if (row.pe != null) bits.push('P/E ' + plain(row.pe, 2));
        if (row.eps != null) bits.push('EPS ' + plain(row.eps, 2));
        if (row.beta != null) bits.push('Beta ' + plain(row.beta, 2));
        if (row.market_cap != null) bits.push('Mkt ' + plain(row.market_cap, 0));
        if (row.volume != null) bits.push('Vol ' + plain(row.volume, 0));
        if (row.exchange) bits.push(row.exchange);
        return bits.join(' · ');
    }

    function quoteTable(rows, commodity) {
        if (!rows.length) {
            return empty(commodity ? 'No commodity rows' : 'No watchlist rows', 'A pull fills this table from the sheet.');
        }
        const head = commodity
            ? '<th>Role</th><th>Symbol</th><th>Name</th><th>Proxy</th><th class="fin-num">Price</th><th class="fin-num">Change</th><th class="fin-num">Change %</th><th>Thesis</th><th>Reviewed</th>'
            : '<th>Role</th><th>Symbol</th><th>Name</th><th class="fin-num">Price</th><th class="fin-num">Change</th><th class="fin-num">Change %</th><th class="fin-num">Off 52w</th><th>Thesis</th><th>Reviewed</th>';
        const body = rows.map((row) => {
            const extra = detail(row);
            const thesis = row.thesis ? esc(row.thesis) : '—';
            const proxy = commodity ? '<td>' + esc(row.proxy_for || '—') + '</td>' : '';
            const off = commodity ? '' : '<td class="fin-num ' + tone(row.pct_off_52w) + '">' + esc(points(row.pct_off_52w)) + '</td>';
            return '<tr title="' + esc(extra) + '"><td>' + esc(row.role || '—') + '</td><td class="fin-symbol">' + esc(row.symbol) + '</td><td>'
                + esc(row.name || '—') + '</td>' + proxy + '<td class="fin-num">' + esc(money(row.price, row.currency)) + '</td><td class="fin-num '
                + tone(row.change) + '">' + esc(money(row.change, row.currency)) + '</td><td class="fin-num ' + tone(row.change_pct) + '">'
                + esc(points(row.change_pct)) + '</td>' + off + '<td>' + thesis + '</td><td>' + esc(row.last_reviewed || '—') + '</td></tr>';
        }).join('');
        return '<div class="fin-table-wrap"><table class="fin-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
    }

    function playTable(rows) {
        if (!rows.length) {
            return empty('No play-account buys', 'The sheet is empty until those rows are added.');
        }
        const body = rows.map((row) => '<tr><td>' + esc(row.date) + '</td><td class="fin-symbol">' + esc(row.ticker)
            + '</td><td class="fin-num">' + esc(money(row.amount, 'USD')) + '</td><td class="fin-num">' + esc(plain(row.shares, 4))
            + '</td><td class="fin-num">' + esc(money(row.price, 'USD')) + '</td></tr>').join('');
        return '<div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>Date</th><th>Ticker</th><th class="fin-num">Amount</th><th class="fin-num">Shares</th><th class="fin-num">Price</th></tr></thead><tbody>'
            + body + '</tbody></table></div>';
    }

    function statusLine(sync) {
        if (!sync || !sync.configured) return 'The sheet is not connected.';
        if (sync.status === 'error') return 'Sync error. ' + (sync.sync_error || 'The last pull failed.');
        if (!sync.last_synced_at) return 'Not synced yet.';
        if (sync.stale) return 'Behind. Last synced ' + sync.last_synced_at + '.';
        return 'Up to date. Last synced ' + sync.last_synced_at + '.';
    }

    function paint(page) {
        const sync = page.sync || {};
        const status = document.getElementById('stocks-sheet-status');
        if (status) {
            status.textContent = statusLine(sync);
            status.classList.toggle('fin-stale', !!(sync.configured && (sync.stale || sync.status === 'error')));
        }
        const setup = document.getElementById('stocks-sheet-setup');
        if (setup) setup.hidden = !!(sync.configured);
        const watch = document.getElementById('stocks-watchlist');
        const commodities = document.getElementById('stocks-commodities');
        const play = document.getElementById('stocks-play');
        if (watch) watch.innerHTML = quoteTable(page.watchlist || [], false);
        if (commodities) commodities.innerHTML = quoteTable(page.commodities || [], true);
        if (play) play.innerHTML = playTable(page.play_account || []);
    }

    async function load() {
        const page = await ld.api.get('/stocks/sheet');
        paint(page);
    }

    load().catch((err) => {
        const status = document.getElementById('stocks-sheet-status');
        if (status) status.textContent = err && err.message ? err.message : 'Could not load the sheet.';
    });
})();
