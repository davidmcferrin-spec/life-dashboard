'use strict';

async function api(url, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = getCsrfToken();
    }
    const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

let pageLists = [];
let activeListId = null;
let activeStatus = 'open';
let canWrite = true;
let assignableUsers = [];
const expandedDetails = new Set();

function readInitialState() {
    const params = new URLSearchParams(window.location.search);
    const list = params.get('list');
    const status = params.get('filter') || params.get('status');
    if (status && ['open', 'completed', 'archived', 'assigned'].includes(status)) {
        activeStatus = status;
    }
    if (list && pageLists.length) {
        const match = pageLists.find(l => l.slug === list || String(l.id) === list);
        if (match) {
            activeListId = match.id;
        }
    }
}

function syncStatusButtons() {
    document.querySelectorAll('#todo-status-filters .seg-btn').forEach(btn => {
        btn.classList.toggle('seg-btn-active', btn.dataset.status === activeStatus);
    });
    const listSelect = document.getElementById('todo-page-list');
    const addForm = document.getElementById('todo-page-add');
    if (listSelect) {
        listSelect.disabled = activeStatus === 'assigned';
        listSelect.classList.toggle('opacity-50', activeStatus === 'assigned');
    }
    if (addForm) {
        addForm.classList.toggle('hidden', activeStatus !== 'open' || !canWrite);
    }
}

function dueMeta(item) {
    const due = window.TodoDue || {};
    const level = item.due_urgency || due.urgency?.(item.due_date, item.status);
    return {
        level,
        rowClass: due.rowClass?.(level) || '',
        flag: due.flagHtml?.(level) || '',
        label: item.due_date && due.formatLabel
            ? `<span class="todo-due-label mono text-xs text-faint">${esc(due.formatLabel(item.due_date))}</span>`
            : '',
    };
}

function renderLinkFields(links = []) {
    const rows = links.length ? links : [{ label: '', url: '' }];
    return rows.map((link, i) => `
        <div class="todo-link-row flex flex-wrap gap-2 mb-2" data-index="${i}">
            <input type="text" class="todo-link-label input mono text-xs flex-1 min-w-[6rem]" placeholder="Label (optional)" value="${esc(link.label || '')}" maxlength="128">
            <input type="url" class="todo-link-url input mono text-xs flex-[2] min-w-[10rem]" placeholder="https://…" value="${esc(link.url || '')}">
            ${i > 0 ? '<button type="button" class="todo-link-remove text-danger text-xs">Remove</button>' : ''}
        </div>
    `).join('');
}

function itemRow(item) {
    const isOpen = item.status === 'open';
    const isAssignedView = activeStatus === 'assigned';
    const listLabel = isAssignedView && item.list_name
        ? `<span class="chip chip-neutral text-xs mr-1">${esc(item.list_name)}</span>`
        : '';
    const assignee = item.assignee_username
        ? `<span class="text-faint mono text-xs ml-1">→ ${esc(item.assignee_username)}</span>`
        : '';
    const expanded = expandedDetails.has(item.id);
    const { rowClass, flag, label } = dueMeta(item);

    const assignOptions = assignableUsers.map(u =>
        `<option value="${u.id}"${item.assigned_to_user_id === u.id ? ' selected' : ''}>${esc(u.username)}</option>`
    ).join('');

    const dueValue = item.due_date ? esc(item.due_date) : '';

    return `
        <article class="todo-item surface p-3${rowClass}" data-id="${item.id}">
            <div class="flex items-start gap-2">
                ${isOpen ? `<input type="checkbox" class="todo-item-check mt-1 flex-shrink-0" data-id="${item.id}" aria-label="Complete task">` : ''}
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-1">
                        ${listLabel}
                        ${flag}
                        <span class="text-sm text-primary${isOpen ? '' : ' line-through opacity-70'}">${esc(item.title)}</span>
                        ${label}
                        ${assignee}
                        ${item.has_details && !expanded ? '<span class="text-faint text-xs" title="Has details">⋯</span>' : ''}
                    </div>
                    <div class="flex flex-wrap gap-2 mt-1">
                        ${canWrite || isOpen ? `<button type="button" class="todo-details-toggle link text-xs" data-id="${item.id}">${expanded ? 'Hide details' : (item.has_details ? 'Show details' : 'Add details')}</button>` : ''}
                        ${canWrite && item.status === 'completed' ? `<button type="button" class="todo-reopen-item link text-xs" data-id="${item.id}">Mark incomplete</button>` : ''}
                        ${canWrite && item.status === 'completed' ? `<button type="button" class="todo-archive-one link text-xs" data-id="${item.id}">Archive</button>` : ''}
                        ${canWrite ? `<button type="button" class="todo-delete-item text-danger text-xs" data-id="${item.id}">Delete</button>` : ''}
                        ${item.status === 'archived' && canWrite ? `<button type="button" class="todo-restore-item link text-xs" data-id="${item.id}">Restore</button>` : ''}
                    </div>
                    <div class="todo-details-panel mt-3 space-y-2${expanded ? '' : ' hidden'}" data-id="${item.id}">
                        ${canWrite ? `
                        <label class="block">
                            <span class="mono text-xs text-faint">Due date</span>
                            <div class="flex flex-wrap items-center gap-2 mt-1">
                                <input type="date" class="todo-due-date input mono text-sm" value="${dueValue}">
                                <button type="button" class="todo-due-clear link text-xs">Clear</button>
                            </div>
                        </label>
                        ` : (dueValue ? `<p class="mono text-xs text-faint">Due ${dueValue}</p>` : '')}
                        <label class="block">
                            <span class="mono text-xs text-faint">Notes</span>
                            <textarea class="todo-notes input w-full mt-1 mono text-sm" rows="3" maxlength="4000" placeholder="Optional notes…">${esc(item.notes || '')}</textarea>
                        </label>
                        <div>
                            <span class="mono text-xs text-faint">Links</span>
                            <div class="todo-links-wrap mt-1">${renderLinkFields(item.links || [])}</div>
                            <button type="button" class="todo-link-add link text-xs mt-1">+ Add link</button>
                        </div>
                        ${canWrite ? `
                        <label class="block">
                            <span class="mono text-xs text-faint">Assign to</span>
                            <select class="todo-assignee input mono text-xs mt-1 w-full max-w-xs">
                                <option value="">Unassigned</option>
                                ${assignOptions}
                            </select>
                        </label>
                        ` : ''}
                        <button type="button" class="todo-save-details btn text-xs" data-id="${item.id}">Save details</button>
                    </div>
                    ${!expanded && item.links && item.links.length ? `
                    <div class="mt-1 flex flex-wrap gap-2">
                        ${item.links.map(l => `<a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer" class="link text-xs mono">${esc(l.label || l.url)}</a>`).join('')}
                    </div>
                    ` : ''}
                </div>
            </div>
        </article>
    `;
}

function bindItemEvents() {
    document.querySelectorAll('.todo-item-check').forEach(box => {
        box.addEventListener('change', async () => {
            if (!box.checked) {
                return;
            }
            box.disabled = true;
            await api(`/api/todos/items/${box.dataset.id}/update`, {
                method: 'POST',
                body: JSON.stringify({ status: 'completed' }),
            });
            loadItems();
        });
    });

    document.querySelectorAll('.todo-details-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id, 10);
            if (expandedDetails.has(id)) {
                expandedDetails.delete(id);
            } else {
                expandedDetails.add(id);
            }
            loadItems();
        });
    });

    document.querySelectorAll('.todo-due-clear').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.closest('.todo-details-panel')?.querySelector('.todo-due-date');
            if (input) {
                input.value = '';
            }
        });
    });

    document.querySelectorAll('.todo-link-add').forEach(btn => {
        btn.addEventListener('click', () => {
            const wrap = btn.previousElementSibling;
            if (!wrap) {
                return;
            }
            const row = document.createElement('div');
            row.className = 'todo-link-row flex flex-wrap gap-2 mb-2';
            row.innerHTML = `
                <input type="text" class="todo-link-label input mono text-xs flex-1 min-w-[6rem]" placeholder="Label (optional)" maxlength="128">
                <input type="url" class="todo-link-url input mono text-xs flex-[2] min-w-[10rem]" placeholder="https://…">
                <button type="button" class="todo-link-remove text-danger text-xs">Remove</button>
            `;
            wrap.appendChild(row);
            row.querySelector('.todo-link-remove')?.addEventListener('click', () => row.remove());
        });
    });

    document.querySelectorAll('.todo-link-remove').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.todo-link-row')?.remove());
    });

    document.querySelectorAll('.todo-save-details').forEach(btn => {
        btn.addEventListener('click', async () => {
            const article = btn.closest('.todo-item');
            if (!article) {
                return;
            }
            const id = parseInt(btn.dataset.id, 10);
            const notes = article.querySelector('.todo-notes')?.value ?? '';
            const links = [];
            article.querySelectorAll('.todo-link-row').forEach(row => {
                const url = row.querySelector('.todo-link-url')?.value.trim() ?? '';
                if (url === '') {
                    return;
                }
                links.push({
                    label: row.querySelector('.todo-link-label')?.value.trim() ?? '',
                    url,
                });
            });
            const assigneeEl = article.querySelector('.todo-assignee');
            const dueEl = article.querySelector('.todo-due-date');
            const body = { notes, links };
            if (assigneeEl) {
                body.assigned_to_user_id = assigneeEl.value === '' ? null : parseInt(assigneeEl.value, 10);
            }
            if (dueEl) {
                body.due_date = dueEl.value === '' ? null : dueEl.value;
            }
            const { res, data } = await api(`/api/todos/items/${id}/update`, {
                method: 'POST',
                body: JSON.stringify(body),
            });
            if (!res.ok) {
                alert(data.error || 'Could not save details.');
                return;
            }
            expandedDetails.add(id);
            loadItems();
        });
    });

    document.querySelectorAll('.todo-reopen-item').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api(`/api/todos/items/${btn.dataset.id}/update`, {
                method: 'POST',
                body: JSON.stringify({ status: 'open' }),
            });
            loadItems();
        });
    });

    document.querySelectorAll('.todo-archive-one').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api(`/api/todos/items/${btn.dataset.id}/update`, {
                method: 'POST',
                body: JSON.stringify({ status: 'archived' }),
            });
            loadItems();
        });
    });

    document.querySelectorAll('.todo-restore-item').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api(`/api/todos/items/${btn.dataset.id}/update`, {
                method: 'POST',
                body: JSON.stringify({ status: 'open' }),
            });
            loadItems();
        });
    });

    document.querySelectorAll('.todo-delete-item').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this task?')) {
                return;
            }
            await api(`/api/todos/items/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
            loadItems();
        });
    });
}

async function loadLists() {
    const select = document.getElementById('todo-page-list');
    if (!select) {
        return;
    }

    const { res, data } = await api('/api/todos');
    if (!res.ok) {
        return;
    }

    pageLists = data.lists || [];
    readInitialState();

    if (!activeListId && pageLists.length) {
        const pinned = pageLists.find(l => l.pin_to_dashboard);
        activeListId = pinned ? pinned.id : pageLists[0].id;
    }

    select.innerHTML = pageLists.map(list => {
        const suffix = list.is_owner ? '' : ` (${list.owner_username})`;
        return `<option value="${list.id}"${list.id === activeListId ? ' selected' : ''}>${esc(list.name)}${esc(suffix)}</option>`;
    }).join('');

    syncStatusButtons();
    loadItems();
}

async function loadItems() {
    const container = document.getElementById('todo-page-items');
    const meta = document.getElementById('todo-page-meta');
    if (!container) {
        return;
    }

    syncStatusButtons();

    if (activeStatus === 'assigned') {
        const { res, data } = await api(`/api/todos/assigned?status=all`);
        if (!res.ok) {
            container.innerHTML = '<p class="text-danger mono text-xs">Could not load tasks.</p>';
            return;
        }
        const items = (data.items || []).filter(i => i.status !== 'archived');
        canWrite = false;
        if (meta) {
            meta.innerHTML = `<span>${items.length} task(s) assigned to you</span>`;
        }
        if (items.length === 0) {
            container.innerHTML = window.DashboardEmpty.html({
                title: 'Nothing assigned',
                body: 'Tasks someone assigns to you will show up here.',
                actionLabel: 'Manage lists',
                actionHref: '/settings/todos',
            });
            return;
        }
        container.innerHTML = items.map(itemRow).join('');
        bindItemEvents();
        return;
    }

    if (!activeListId) {
        container.innerHTML = window.DashboardEmpty.html({
            title: 'No lists yet',
            body: 'Create a list to start adding tasks.',
            actionLabel: 'Create a list',
            actionHref: '/settings/todos',
        });
        return;
    }

    const { res, data } = await api(`/api/todos/${activeListId}/items?status=${encodeURIComponent(activeStatus)}`);
    if (!res.ok) {
        container.innerHTML = '<p class="text-danger mono text-xs">Could not load tasks.</p>';
        return;
    }

    canWrite = !!data.can_write;
    assignableUsers = data.assignable_users || [];
    const list = data.list;
    const items = data.items || [];

    syncStatusButtons();

    if (meta && list) {
        const counts = list.counts || {};
        let actions = '';
        if (canWrite && activeStatus === 'completed' && (counts.completed || 0) > 0) {
            actions = `<button type="button" id="todo-archive-all" class="link text-xs">Archive all completed</button>`;
        }
        meta.innerHTML = `<span>${items.length} shown · ${counts.open || 0} open · ${counts.completed || 0} completed</span>${actions ? `<span>${actions}</span>` : ''}`;
        document.getElementById('todo-archive-all')?.addEventListener('click', async () => {
            await api(`/api/todos/${activeListId}/archive-completed`, { method: 'POST', body: '{}' });
            loadItems();
        });
    }

    if (items.length === 0) {
        container.innerHTML = window.DashboardEmpty.html({
            title: activeStatus === 'open' ? 'No open tasks' : 'Nothing here',
            body: activeStatus === 'open'
                ? 'Add one in the field below.'
                : 'No ' + activeStatus + ' tasks in this list.',
            actionLabel: 'Manage lists',
            actionHref: '/settings/todos',
        });
        return;
    }

    container.innerHTML = items.map(itemRow).join('');
    bindItemEvents();
}

document.getElementById('todo-page-list')?.addEventListener('change', e => {
    activeListId = parseInt(e.target.value, 10);
    loadItems();
});

document.querySelectorAll('#todo-status-filters .seg-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        activeStatus = btn.dataset.status;
        syncStatusButtons();
        loadItems();
    });
});

document.getElementById('todo-page-add')?.addEventListener('submit', async e => {
    e.preventDefault();
    const input = document.getElementById('todo-page-input');
    const dueInput = document.getElementById('todo-page-due');
    if (!input || !activeListId) {
        return;
    }
    const title = input.value.trim();
    if (title === '') {
        return;
    }
    const body = { title };
    const dueVal = dueInput?.value.trim();
    if (dueVal) {
        body.due_date = dueVal;
    }
    const { res, data } = await api(`/api/todos/${activeListId}/items/create`, {
        method: 'POST',
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        alert(data.error || 'Could not add task.');
        return;
    }
    input.value = '';
    if (dueInput) {
        dueInput.value = '';
    }
    loadItems();
});

loadLists();
