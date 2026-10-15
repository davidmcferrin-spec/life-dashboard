/**
 * Dashboard sprite for bearer-token / MCP activity.
 * The socket says an audit row arrived; this fetches a safe label and
 * floats toward the widget that area owns. Nothing sensitive is rendered.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.AiPresence = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const IDLE_MS = 30000;
    const AREAS = {
        Money: ['food_week', 'spending', 'networth', 'budget_actual', 'debt_snowball', 'bills_due', 'credit', 'sinking_funds', 'baby_step', 'retirement', 'portfolio'],
        Investing: ['portfolio'],
        Vault: ['pages'],
        Reddit: ['reddit_feed', 'reddit'],
        Todos: ['legacy.todos'],
        Life: ['life'],
        Habits: ['habits', 'mood', 'streak'],
        Widgets: [],
        Bookmarks: ['legacy.bookmarks'],
        Flights: ['legacy.flights'],
        Packages: ['legacy.packages'],
        Stocks: ['legacy.stocks'],
        Clipboards: ['legacy.clipboards'],
        Search: [],
        Dashboard: [],
    };

    function targetTypes(area) {
        return AREAS[area] || [];
    }

    function bubbleText(event) {
        const agent = String((event && event.agent) || 'Agent').replace(/[\r\n]/g, ' ').slice(0, 64);
        const label = String((event && event.label) || '').replace(/[\r\n]/g, ' ').slice(0, 80);
        return { agent: agent || 'Agent', label };
    }

    function pickTarget(area, nodes) {
        const types = targetTypes(area);
        for (let i = 0; i < types.length; i++) {
            const found = (nodes || []).find((node) => node && node.type === types[i]);
            if (found) return found;
        }
        return null;
    }

    function parseUtc(value) {
        if (!value) return Date.now();
        const norm = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
        const stamped = /Z|[+-]\d\d:?\d\d$/.test(norm) ? norm : norm + 'Z';
        const parsed = Date.parse(stamped);
        return Number.isFinite(parsed) ? parsed : Date.now();
    }

    function fresh(event, now) {
        return now - parseUtc(event && event.at) <= IDLE_MS;
    }

    return { IDLE_MS, AREAS, targetTypes, bubbleText, pickTarget, parseUtc, fresh };
});

(function () {
    if (typeof document === 'undefined' || !window.AiPresence || !window.LD) return;
    const host = document.getElementById('ai-presence');
    if (!host || document.body.dataset.aiPresence === '0') return;

    const { api } = window.LD;
    const presence = window.AiPresence;
    const agentEl = document.getElementById('ai-agent');
    const labelEl = document.getElementById('ai-label');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const POLL_MS = 4000;

    let lastId = 0;
    let live = false;
    let pollTimer = null;
    let idleTimer = null;
    let hideTimer = null;
    let raf = 0;
    let pos = { x: 80, y: 120 };
    let goal = null;
    let goalUntil = 0;
    let shownAt = 0;

    if (reduced) host.classList.add('is-static');

    function widgets() {
        return [...document.querySelectorAll('[data-widget-type]')].map((el) => ({
            type: el.dataset.widgetType,
            el,
        }));
    }

    function place(x, y) {
        pos = {
            x: Math.max(8, Math.min(window.innerWidth - 88, x)),
            y: Math.max(56, Math.min(window.innerHeight - 96, y)),
        };
        if (!reduced) {
            host.style.left = pos.x + 'px';
            host.style.top = pos.y + 'px';
        }
    }

    function flyToward(area) {
        const target = presence.pickTarget(area, widgets());
        document.querySelectorAll('.ai-touched').forEach((el) => el.classList.remove('ai-touched'));
        if (!target) return;
        target.el.classList.add('ai-touched');
        setTimeout(() => target.el.classList.remove('ai-touched'), 2800);
        const rect = target.el.getBoundingClientRect();
        goal = {
            x: rect.left + Math.min(rect.width * 0.5, 48),
            y: Math.max(56, rect.top + 12),
        };
        goalUntil = performance.now() + 2600;
    }

    function show(event) {
        const text = presence.bubbleText(event);
        agentEl.textContent = text.agent;
        labelEl.textContent = text.label;
        host.hidden = false;
        host.classList.remove('is-leaving');
        host.classList.add('is-on');
        shownAt = Date.now();
        flyToward(event.area);
        clearTimeout(idleTimer);
        clearTimeout(hideTimer);
        const age = Date.now() - presence.parseUtc(event.at);
        const wait = Math.max(400, presence.IDLE_MS - Math.max(0, age));
        idleTimer = setTimeout(() => {
            host.classList.add('is-leaving');
            hideTimer = setTimeout(() => {
                host.hidden = true;
                host.classList.remove('is-on', 'is-leaving');
            }, 700);
        }, wait);
    }

    function ingest(events) {
        let newest = null;
        (events || []).forEach((event) => {
            if (event.id > lastId) lastId = event.id;
            if (presence.fresh(event, Date.now())) newest = event;
        });
        if (newest) show(newest);
    }

    async function pull() {
        if (document.hidden) return;
        try {
            const res = await api.get('/audit/presence?after=' + lastId + '&limit=20');
            ingest(res.events || []);
        } catch (e) {
            /* the dashboard keeps working if this feed is quiet */
        }
    }

    function startPoll() {
        if (pollTimer) return;
        pollTimer = setInterval(pull, POLL_MS);
    }

    function stopPoll() {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    function wander(now) {
        if (host.hidden || reduced) {
            raf = requestAnimationFrame(wander);
            return;
        }
        if (goal && now < goalUntil) {
            place(pos.x + (goal.x - pos.x) * 0.08, pos.y + (goal.y - pos.y) * 0.08);
        } else {
            goal = null;
            const t = now / 1000;
            const x = 36 + (window.innerWidth - 140) * (0.5 + 0.42 * Math.sin(t * 0.17 + shownAt / 1000));
            const y = 72 + (window.innerHeight - 180) * (0.45 + 0.36 * Math.cos(t * 0.13));
            place(pos.x + (x - pos.x) * 0.02, pos.y + (y - pos.y) * 0.02);
        }
        raf = requestAnimationFrame(wander);
    }

    window.addEventListener('lifedash:event', (e) => {
        const evt = e.detail;
        if (evt && evt.table === 'audit_events') pull();
    });
    window.addEventListener('lifedash:resync', () => pull());
    window.addEventListener('lifedash:status', (e) => {
        live = !!(e.detail && e.detail.live);
        if (live) stopPoll();
        else startPoll();
        pull();
    });

    place(window.innerWidth * 0.62, window.innerHeight * 0.28);
    pull();
    startPoll();
    raf = requestAnimationFrame(wander);
})();
