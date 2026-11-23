'use strict';

(function () {
    const ld = window.LD;
    const RANGES = ['day', 'week', 'month', 'year'];
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    let symbol = '';
    let name = '';
    let range = 'year';
    let series = null;
    let request = 0;

    function esc(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function money(value) {
        if (!ld || value == null || !Number.isFinite(Number(value))) return '—';
        return ld.fmt.money(value);
    }

    function empty(title, body) {
        if (!window.DashboardEmpty) return '<p class="fin-note">' + esc(title) + '</p>';
        return window.DashboardEmpty.html({ title: title, body: body });
    }

    function pointLabel(iso) {
        const month = MONTHS[Number(String(iso).slice(5, 7)) - 1] || '';
        const day = String(Number(String(iso).slice(8, 10)));
        return (month + ' ' + day).trim();
    }

    function lineChart(points, label, down) {
        const w = 700;
        const h = 180;
        const pad = 12;
        const values = points.map((point) => point.value);
        const min = Math.min(...values);
        const max = Math.max(...values);
        const span = (max - min) || 1;
        let coords = values.map((value, index) => {
            const x = points.length === 1 ? w / 2 : (index / (points.length - 1)) * w;
            const y = pad + (1 - ((value - min) / span)) * (h - pad * 2);
            return [x, y];
        });
        if (coords.length === 1) {
            coords = [[0, coords[0][1]], [w, coords[0][1]]];
        }
        const line = coords.map((coord, index) => (index ? 'L' : 'M') + coord[0].toFixed(1) + ' ' + coord[1].toFixed(1)).join(' ');
        const area = line + ' L' + w + ' ' + h + ' L0 ' + h + ' Z';
        let labels = points.map((point) => point.label);
        if (labels.length > 6) {
            labels = [labels[0], labels[Math.floor((labels.length - 1) / 2)], labels[labels.length - 1]];
        }
        return '<div class="fin-chart-frame"><div class="fin-chart-y"><span>' + esc(money(max)) + '</span><span>' + esc(money(min))
            + '</span></div><svg class="' + (down ? 'is-down' : '') + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="' + esc(label) + '">'
            + '<path class="fin-chart-area" d="' + area + '"></path><path class="fin-chart-line" d="' + line + '"></path></svg></div>'
            + '<div class="fin-axis">' + labels.map((text) => '<span>' + esc(text) + '</span>').join('') + '</div>';
    }

    function mark() {
        document.querySelectorAll('[data-focus-symbol]').forEach((button) => {
            const on = button.getAttribute('data-focus-symbol') === symbol;
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            const row = button.closest('tr');
            if (row) row.classList.toggle('is-focused', on);
        });
    }

    function setRange(next) {
        if (RANGES.indexOf(next) === -1) return;
        range = next;
        RANGES.forEach((name) => {
            const button = document.getElementById('stocks-range-' + name);
            if (button) button.setAttribute('aria-selected', name === range ? 'true' : 'false');
        });
        paint();
    }

    function pointsFor(block) {
        const rows = block && block.points ? block.points : [];
        if (block && block.grain === 'hour') {
            return rows.filter((point) => point && point.price != null && Number.isFinite(Number(point.price))).map((point) => ({
                label: point.label || point.at,
                value: Number(point.price),
            }));
        }
        return rows.filter((point) => point && point.close != null && Number.isFinite(Number(point.close))).map((point) => ({
            label: pointLabel(point.date),
            value: Number(point.close),
        }));
    }

    function paint() {
        const host = document.getElementById('stocks-symbol-chart');
        const title = document.getElementById('stocks-symbol-title');
        const eyebrow = document.getElementById('stocks-symbol-eyebrow');
        const change = document.getElementById('stocks-symbol-change');
        if (title) title.textContent = symbol ? symbol : 'Price history';
        if (eyebrow) eyebrow.textContent = symbol ? (name && name !== symbol ? name : 'Price history') : 'Symbol';
        if (!host) return;
        if (!symbol) {
            host.classList.remove('is-down');
            host.innerHTML = '<p class="fin-note">Select a symbol to see Day, Week, Month, and Year.</p>';
            if (change) change.textContent = '';
            return;
        }
        if (!series) {
            host.classList.remove('is-down');
            host.innerHTML = '<p class="fin-note">Loading ' + esc(symbol) + '…</p>';
            if (change) change.textContent = '';
            return;
        }
        const block = series.ranges && series.ranges[range] ? series.ranges[range] : { points: [] };
        const points = pointsFor(block);
        if (!points.length) {
            host.classList.remove('is-down');
            const body = range === 'day'
                ? 'An hourly price is stored each time the sheet sync runs while the market is open.'
                : 'Daily closes show up here after the History tab is pulled.';
            host.innerHTML = empty('No ' + range + ' prices yet', body);
            if (change) change.textContent = '';
            return;
        }
        const first = points[0].value;
        const last = points[points.length - 1].value;
        const down = last < first;
        host.classList.toggle('is-down', down);
        const label = symbol + ' ' + range + ' price';
        host.innerHTML = lineChart(points, label, down);
        if (change) {
            const delta = last - first;
            const pct = first ? (delta / first) * 100 : 0;
            const sign = delta > 0 ? '+' : (delta < 0 ? '−' : '');
            change.textContent = money(last) + ' · ' + sign + money(Math.abs(delta)) + ' · ' + sign + Math.abs(pct).toFixed(2) + '%';
            change.classList.toggle('is-down', down);
            change.classList.toggle('is-up', delta > 0);
        }
    }

    async function load() {
        const ticket = ++request;
        series = null;
        paint();
        if (!ld || !symbol) return;
        try {
            const page = await ld.api.get('/stocks/sheet/history?symbol=' + encodeURIComponent(symbol));
            if (ticket !== request) return;
            series = page;
            paint();
        } catch (err) {
            if (ticket !== request) return;
            const host = document.getElementById('stocks-symbol-chart');
            if (host) host.innerHTML = empty('Could not load the chart', err && err.message ? err.message : 'The history request failed.');
        }
    }

    function focus(next, nextName) {
        const ticker = String(next || '').trim().toUpperCase();
        if (!ticker) return;
        symbol = ticker;
        name = String(nextName || '').trim();
        const card = document.getElementById('stocks-symbol');
        if (card) card.scrollIntoView({ block: 'nearest' });
        mark();
        load();
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-focus-symbol]');
        if (!button) return;
        event.preventDefault();
        focus(button.getAttribute('data-focus-symbol'), button.getAttribute('data-focus-name') || '');
    });

    document.querySelectorAll('.fin-range').forEach((button) => {
        button.addEventListener('click', () => setRange(button.getAttribute('data-range')));
    });

    window.StockChart = { focus: focus, mark: mark };
})();
