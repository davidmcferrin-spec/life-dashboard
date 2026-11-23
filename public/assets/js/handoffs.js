(function () {
    const root = document.getElementById('handoffs-app');
    if (!root || !window.LD) return;
    const esc = window.LD.esc;
    const api = window.LD.api;
    const error = document.getElementById('handoff-error');
    const board = document.getElementById('handoff-board');
    const detail = document.getElementById('handoff-detail');
    const agentSelect = document.getElementById('handoff-agent');
    const tagInput = document.getElementById('handoff-tag');
    const STATUSES = [
        ['open', 'Open'],
        ['claimed', 'Claimed'],
        ['in_progress', 'In progress'],
        ['blocked', 'Blocked'],
        ['done', 'Done'],
        ['cancelled', 'Cancelled'],
    ];
    let rows = [];
    let selected = 0;

    function showError(err) {
        error.textContent = err && err.message ? err.message : 'Handoffs did not load.';
        error.classList.remove('hidden');
    }

    function clearError() {
        error.textContent = '';
        error.classList.add('hidden');
    }

    function agentMatch(row, agent) {
        if (!agent) return true;
        if (agent === '__anyone__') return !row.to_agent;
        const name = agent.toLowerCase();
        return [row.to_agent, row.from_agent, row.claimed_by].some(function (value) {
            return String(value || '').toLowerCase() === name;
        });
    }

    function knownAgents() {
        const names = {};
        rows.forEach(function (row) {
            [row.to_agent, row.from_agent, row.claimed_by].forEach(function (value) {
                if (value) names[value] = value;
            });
        });
        return Object.keys(names).sort(function (a, b) {
            return a.localeCompare(b);
        });
    }

    function fillAgents() {
        const current = agentSelect.value;
        const names = knownAgents();
        agentSelect.innerHTML = '<option value="">All</option><option value="__anyone__">Anyone</option>'
            + names.map(function (name) {
                return '<option value="' + esc(name) + '">' + esc(name) + '</option>';
            }).join('');
        if ([...agentSelect.options].some(function (option) { return option.value === current; })) {
            agentSelect.value = current;
        }
        const list = document.getElementById('handoff-agents');
        list.innerHTML = names.map(function (name) {
            return '<option value="' + esc(name) + '"></option>';
        }).join('');
    }

    function card(row) {
        const who = row.to_agent || 'Anyone';
        const due = row.due_at
            ? '<span class="handoff-due' + (row.overdue ? ' is-overdue' : '') + '">' + esc(row.due_at) + (row.overdue ? ' overdue' : '') + '</span>'
            : '';
        const tags = (row.tags || []).map(esc).join(' ');
        return '<button type="button" class="handoff-card is-' + esc(row.priority) + (row.overdue ? ' is-overdue' : '') + '" data-id="' + esc(row.id) + '">'
            + '<strong>' + esc(row.title) + '</strong>'
            + '<span>' + esc(who) + ' · ' + esc(row.priority) + (tags ? ' · ' + tags : '') + '</span>'
            + due
            + '</button>';
    }

    function paint() {
        fillAgents();
        const agent = agentSelect.value;
        const filtered = rows.filter(function (row) { return agentMatch(row, agent); });
        board.innerHTML = STATUSES.map(function (pair) {
            const status = pair[0];
            const label = pair[1];
            const cards = filtered.filter(function (row) { return row.status === status; });
            return '<section class="handoff-column"><header><span>' + esc(label) + '</span><span>' + cards.length + '</span></header>'
                + (cards.map(card).join('') || '<p class="platform-meta">None</p>')
                + '</section>';
        }).join('');
    }

    function threadItem(event) {
        const status = event.status ? ' · ' + event.status : '';
        const body = event.body ? '<p class="handoff-body">' + esc(event.body) + '</p>' : '';
        return '<li><p class="platform-meta">' + esc(event.sender) + ' · ' + esc(event.kind) + status + ' · ' + esc(event.created_at) + '</p>' + body + '</li>';
    }

    function paintDetail(payload) {
        const row = payload.handoff;
        const events = payload.events || [];
        const pages = (row.linked_page_ids || []).map(function (id) {
            return '<a href="/brain?page=' + esc(id) + '">Page ' + esc(id) + '</a>';
        }).join(' ');
        const urls = (row.linked_urls || []).map(function (url) {
            return '<a href="' + esc(url) + '" rel="noopener">' + esc(url) + '</a>';
        }).join('<br>');
        const options = STATUSES.map(function (pair) {
            return '<option value="' + pair[0] + '"' + (pair[0] === row.status ? ' selected' : '') + '>' + esc(pair[1]) + '</option>';
        }).join('');
        detail.classList.remove('hidden');
        detail.innerHTML = '<p class="platform-kicker">' + esc(row.status) + ' · ' + esc(row.priority) + '</p>'
            + '<h2>' + esc(row.title) + '</h2>'
            + '<p class="platform-meta">From ' + esc(row.from_agent) + ' to ' + esc(row.to_agent || 'anyone')
            + (row.claimed_by ? ' · claimed by ' + esc(row.claimed_by) : '')
            + (row.due_at ? ' · due ' + esc(row.due_at) : '')
            + (row.overdue ? ' · overdue' : '')
            + '</p>'
            + (row.body ? '<p class="handoff-body">' + esc(row.body) + '</p>' : '')
            + (pages ? '<p>' + pages + '</p>' : '')
            + (urls ? '<p>' + urls + '</p>' : '')
            + '<ol class="handoff-thread">' + events.map(threadItem).join('') + '</ol>'
            + '<form class="handoff-create" id="handoff-comment"><textarea class="input" name="body" placeholder="Comment" required maxlength="4000"></textarea><button class="btn" type="submit">Comment</button></form>'
            + '<form class="handoff-filters" id="handoff-reassign"><input class="input" name="to_agent" placeholder="Reassign to, blank for anyone" maxlength="60" value="' + esc(row.to_agent || '') + '"><button class="btn" type="submit">Reassign</button></form>'
            + '<form class="handoff-filters" id="handoff-status"><select class="input" name="status" aria-label="Status">' + options + '</select><input class="input" name="note" placeholder="Note" maxlength="4000"><button class="btn" type="submit">Update status</button></form>'
            + '<div class="handoff-actions"><button class="btn" type="button" id="handoff-close-done">Close done</button><button class="btn" type="button" id="handoff-close-cancel">Cancel handoff</button></div>';
        document.getElementById('handoff-comment').addEventListener('submit', function (event) {
            event.preventDefault();
            act(api.post('/handoffs/' + row.id + '/comment', { body: event.target.body.value }));
        });
        document.getElementById('handoff-reassign').addEventListener('submit', function (event) {
            event.preventDefault();
            const name = event.target.to_agent.value.trim();
            act(api.post('/handoffs/' + row.id + '/reassign', { to_agent: name || null }));
        });
        document.getElementById('handoff-status').addEventListener('submit', function (event) {
            event.preventDefault();
            act(api.post('/handoffs/' + row.id + '/status', {
                status: event.target.status.value,
                note: event.target.note.value,
            }));
        });
        document.getElementById('handoff-close-done').addEventListener('click', function () {
            act(api.post('/handoffs/' + row.id + '/close', { status: 'done' }));
        });
        document.getElementById('handoff-close-cancel').addEventListener('click', function () {
            act(api.post('/handoffs/' + row.id + '/close', { status: 'cancelled' }));
        });
    }

    function act(promise) {
        clearError();
        promise.then(function () {
            return load();
        }).catch(showError);
    }

    async function openCard(id) {
        selected = id;
        const payload = await api.get('/handoffs/' + id);
        paintDetail(payload);
    }

    async function load() {
        clearError();
        const tag = tagInput.value.trim();
        const query = tag ? '?tag=' + encodeURIComponent(tag) : '';
        const data = await api.get('/handoffs' + query);
        rows = data.handoffs || [];
        paint();
        if (selected && rows.some(function (row) { return row.id === selected; })) {
            await openCard(selected);
        } else if (selected) {
            selected = 0;
            detail.classList.add('hidden');
            detail.innerHTML = '';
        }
    }

    board.addEventListener('click', function (event) {
        const cardButton = event.target.closest('.handoff-card');
        if (!cardButton) return;
        openCard(Number(cardButton.dataset.id)).catch(showError);
    });
    agentSelect.addEventListener('change', paint);
    document.getElementById('handoff-filters').addEventListener('submit', function (event) {
        event.preventDefault();
        load().catch(showError);
    });
    document.getElementById('handoff-create').addEventListener('submit', function (event) {
        event.preventDefault();
        const form = event.target;
        const body = {
            title: form.title.value,
            body: form.body.value,
            to_agent: form.to_agent.value.trim() || null,
            priority: form.priority.value,
            tags: form.tags.value,
            linked_page_ids: form.pages.value,
            linked_urls: form.urls.value.split(/\s+/).filter(Boolean),
        };
        if (form.due_at.value) body.due_at = form.due_at.value;
        clearError();
        api.post('/handoffs', body).then(function () {
            form.reset();
            return load();
        }).catch(showError);
    });

    load().catch(showError);
})();
