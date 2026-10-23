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

function openModal(modal) {
    if (!modal) {
        return;
    }
    modal.classList.remove('hidden');
    document.body.classList.add('app-modal-open');
}

function closeModal(modal) {
    if (!modal) {
        return;
    }
    modal.classList.add('hidden');
    if (!document.querySelector('.app-modal:not(.hidden)')) {
        document.body.classList.remove('app-modal-open');
    }
}

function bindModal(modal) {
    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) {
            closeModal(modal);
        }
    });
}

function clearFieldError(id) {
    const el = document.getElementById(id);
    if (!el) {
        return;
    }
    el.textContent = '';
    el.classList.add('hidden');
}

function showFieldError(id, message) {
    const el = document.getElementById(id);
    if (!el) {
        alert(message);
        return;
    }
    el.textContent = message;
    el.classList.remove('hidden');
}

function modalShell(id, titleId, title, wide) {
    const modal = document.createElement('div');
    modal.id = id;
    modal.className = 'app-modal hidden';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', titleId);

    const backdrop = document.createElement('div');
    backdrop.className = 'app-modal-backdrop';
    backdrop.setAttribute('data-close', '');
    backdrop.setAttribute('aria-hidden', 'true');

    const panel = document.createElement('div');
    panel.className = 'app-modal-panel surface' + (wide ? ' ld-modal-wide' : '');

    const head = document.createElement('div');
    head.className = 'flex items-center justify-between gap-2 mb-3';

    const heading = document.createElement('h2');
    heading.id = titleId;
    heading.className = 'section-title text-sm';
    heading.textContent = title;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'app-modal-close';
    close.setAttribute('data-close', '');
    close.setAttribute('aria-label', 'Close');
    close.textContent = '×';

    head.append(heading, close);
    panel.append(head);
    modal.append(backdrop, panel);
    return modal;
}

let pageLists = [];
let pageItems = [];
let activeListId = null;
let activeStatus = 'open';
let canWrite = true;
let assignableUsers = [];
let editingItemId = null;

let addModal = null;
let editModal = null;

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
    const addOpen = document.getElementById('todo-page-add-open-wrap');
    if (listSelect) {
        listSelect.disabled = activeStatus === 'assigned';
        listSelect.classList.toggle('opacity-50', activeStatus === 'assigned');
    }
    if (addOpen) {
        addOpen.classList.toggle('hidden', activeStatus !== 'open' || !canWrite);
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

function bindLinkRemoves(root) {
    root.querySelectorAll('.todo-link-remove').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.todo-link-row')?.remove());
    });
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
    const { rowClass, flag, label } = dueMeta(item);

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
                        ${item.has_details ? '<span class="text-faint text-xs" title="Has details">⋯</span>' : ''}
                    </div>
                    <div class="flex flex-wrap gap-2 mt-1">
                        ${canWrite || isOpen ? `<button type="button" class="todo-details-toggle link text-xs" data-id="${item.id}">${item.has_details ? 'Show details' : 'Add details'}</button>` : ''}
                        ${canWrite && item.status === 'completed' ? `<button type="button" class="todo-reopen-item link text-xs" data-id="${item.id}">Mark incomplete</button>` : ''}
                        ${canWrite && item.status === 'completed' ? `<button type="button" class="todo-archive-one link text-xs" data-id="${item.id}">Archive</button>` : ''}
                        ${canWrite ? `<button type="button" class="todo-delete-item text-danger text-xs" data-id="${item.id}">Delete</button>` : ''}
                        ${item.status === 'archived' && canWrite ? `<button type="button" class="todo-restore-item link text-xs" data-id="${item.id}">Restore</button>` : ''}
                    </div>
                    ${item.links && item.links.length ? `
                    <div class="mt-1 flex flex-wrap gap-2">
                        ${item.links.map(l => `<a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer" class="link text-xs mono">${esc(l.label || l.url)}</a>`).join('')}
                    </div>
                    ` : ''}
                </div>
            </div>
        </article>
    `;
}

function openPageAddModal() {
    if (activeStatus !== 'open' || !canWrite || !activeListId) {
        return;
    }
    closeModal(editModal);
    clearFieldError('todo-page-add-error');
    openModal(addModal);
}

function fillEditDue(item) {
    const dueWrap = document.getElementById('todo-page-edit-due-wrap');
    if (!dueWrap) {
        return;
    }
    const dueValue = item.due_date ? String(item.due_date) : '';
    if (canWrite) {
        dueWrap.innerHTML = `
            <label class="block">
                <span class="mono text-xs text-faint">Due date</span>
                <div class="flex flex-wrap items-center gap-2 mt-1">
                    <input type="date" id="todo-page-edit-due" class="todo-due-date input mono text-sm" value="${esc(dueValue)}">
                    <button type="button" class="todo-due-clear link text-xs">Clear</button>
                </div>
            </label>`;
        dueWrap.querySelector('.todo-due-clear')?.addEventListener('click', () => {
            const input = document.getElementById('todo-page-edit-due');
            if (input) {
                input.value = '';
            }
        });
        return;
    }
    dueWrap.innerHTML = dueValue ? `<p class="mono text-xs text-faint">Due ${esc(dueValue)}</p>` : '';
}

function fillEditAssignee(item) {
    const wrap = document.getElementById('todo-page-edit-assign-wrap');
    const select = document.getElementById('todo-page-edit-assignee');
    if (!wrap || !select) {
        return;
    }
    wrap.classList.toggle('hidden', !canWrite);
    if (!canWrite) {
        return;
    }
    select.innerHTML = '<option value="">Unassigned</option>' + assignableUsers.map(u =>
        `<option value="${u.id}">${esc(u.username)}</option>`
    ).join('');
    select.value = item.assigned_to_user_id == null ? '' : String(item.assigned_to_user_id);
}

function openPageEdit(item) {
    if (!editModal || !item) {
        return;
    }
    editingItemId = item.id;
    closeModal(addModal);
    clearFieldError('todo-page-edit-error');
    const notes = document.getElementById('todo-page-edit-notes');
    if (notes) {
        notes.value = item.notes || '';
    }
    const linksWrap = document.getElementById('todo-page-edit-links');
    if (linksWrap) {
        linksWrap.innerHTML = renderLinkFields(item.links || []);
        bindLinkRemoves(linksWrap);
    }
    fillEditDue(item);
    fillEditAssignee(item);
    openModal(editModal);
}

function mountPageDialogs() {
    const form = document.getElementById('todo-page-add');
    if (!form || document.getElementById('todo-page-add-modal')) {
        return;
    }

    const openWrap = document.createElement('div');
    openWrap.id = 'todo-page-add-open-wrap';
    openWrap.className = 'pt-2 border-t border-chrome';
    const openBtn = document.createElement('button');
    openBtn.type = 'button';
    openBtn.id = 'todo-page-add-open';
    openBtn.className = 'btn-primary text-sm';
    openBtn.textContent = 'Add task';
    openWrap.append(openBtn);
    form.before(openWrap);
    openBtn.addEventListener('click', openPageAddModal);

    form.className = 'space-y-3';
    form.querySelectorAll('label').forEach(label => {
        label.classList.add('block');
    });
    document.getElementById('todo-page-due')?.classList.add('w-full');

    const error = document.createElement('p');
    error.id = 'todo-page-add-error';
    error.className = 'ld-field-error hidden';
    error.setAttribute('role', 'alert');

    const actions = document.createElement('div');
    actions.className = 'flex flex-wrap gap-2';
    const submit = form.querySelector('button[type="submit"]');
    submit?.classList.remove('self-end');
    if (submit) {
        actions.append(submit);
    }
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn text-sm';
    cancel.setAttribute('data-close', '');
    cancel.textContent = 'Cancel';
    actions.append(cancel);
    form.append(error, actions);

    addModal = modalShell('todo-page-add-modal', 'todo-page-add-title', 'Add task', false);
    addModal.querySelector('.app-modal-panel').append(form);
    bindModal(addModal);
    document.body.append(addModal);

    editModal = modalShell('todo-page-edit-modal', 'todo-page-edit-title', 'Task details', true);
    const editBody = document.createElement('div');
    editBody.className = 'space-y-3';
    editBody.innerHTML = `
        <div id="todo-page-edit-due-wrap"></div>
        <label class="block">
            <span class="mono text-xs text-faint">Notes</span>
            <textarea id="todo-page-edit-notes" class="input w-full mt-1 mono text-sm" rows="3" maxlength="4000" placeholder="Optional notes…"></textarea>
        </label>
        <div>
            <span class="mono text-xs text-faint">Links</span>
            <div id="todo-page-edit-links" class="todo-links-wrap mt-1"></div>
            <button type="button" id="todo-page-edit-link-add" class="todo-link-add link text-xs mt-1">+ Add link</button>
        </div>
        <label class="block" id="todo-page-edit-assign-wrap">
            <span class="mono text-xs text-faint">Assign to</span>
            <select id="todo-page-edit-assignee" class="todo-assignee input mono text-xs mt-1 w-full max-w-xs">
                <option value="">Unassigned</option>
            </select>
        </label>
        <p id="todo-page-edit-error" class="ld-field-error hidden" role="alert"></p>
        <div class="flex flex-wrap gap-2">
            <button type="button" id="todo-page-edit-save" class="todo-save-details btn text-xs">Save details</button>
            <button type="button" class="btn text-xs" data-close>Cancel</button>
        </div>
    `;
    editModal.querySelector('.app-modal-panel').append(editBody);
    bindModal(editModal);
    document.body.append(editModal);

    document.getElementById('todo-page-edit-link-add')?.addEventListener('click', () => {
        const wrap = document.getElementById('todo-page-edit-links');
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
        wrap.append(row);
        bindLinkRemoves(row);
    });

    document.getElementById('todo-page-edit-save')?.addEventListener('click', savePageDetails);
}

async function savePageDetails() {
    const id = editingItemId;
    if (!id) {
        return;
    }
    const notes = document.getElementById('todo-page-edit-notes')?.value ?? '';
    const links = [];
    document.querySelectorAll('#todo-page-edit-links .todo-link-row').forEach(row => {
        const url = row.querySelector('.todo-link-url')?.value.trim() ?? '';
        if (url === '') {
            return;
        }
        links.push({
            label: row.querySelector('.todo-link-label')?.value.trim() ?? '',
            url,
        });
    });
    const assigneeEl = document.getElementById('todo-page-edit-assignee');
    const assignWrap = document.getElementById('todo-page-edit-assign-wrap');
    const dueEl = document.getElementById('todo-page-edit-due');
    const body = { notes, links };
    if (assigneeEl && assignWrap && !assignWrap.classList.contains('hidden')) {
        body.assigned_to_user_id = assigneeEl.value === '' ? null : parseInt(assigneeEl.value, 10);
    }
    if (dueEl) {
        body.due_date = dueEl.value === '' ? null : dueEl.value;
    }
    clearFieldError('todo-page-edit-error');
    const { res, data } = await api(`/api/todos/items/${id}/update`, {
        method: 'POST',
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        showFieldError('todo-page-edit-error', data.error || 'Could not save details.');
        return;
    }
    closeModal(editModal);
    loadItems();
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
            const item = pageItems.find(entry => Number(entry.id) === id);
            if (item) {
                openPageEdit(item);
            }
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
            if (btn.dataset.confirming === '1') {
                return;
            }
            btn.dataset.confirming = '1';
            try {
                const ok = await globalThis.AppModal.confirm({
                    title: 'Delete task',
                    message: 'Delete this task?',
                    confirmLabel: 'Delete',
                    danger: true,
                });
                if (!ok) {
                    return;
                }
                await api(`/api/todos/items/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
                loadItems();
            } finally {
                if (btn.isConnected) {
                    btn.dataset.confirming = '';
                }
            }
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
        pageItems = items;
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
        pageItems = [];
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
    pageItems = items;

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
                ? 'Add one with Add task.'
                : 'No ' + activeStatus + ' tasks in this list.',
            actionLabel: 'Manage lists',
            actionHref: '/settings/todos',
        });
        return;
    }

    container.innerHTML = items.map(itemRow).join('');
    bindItemEvents();
}

mountPageDialogs();

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
    clearFieldError('todo-page-add-error');
    const { res, data } = await api(`/api/todos/${activeListId}/items/create`, {
        method: 'POST',
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        showFieldError('todo-page-add-error', data.error || 'Could not add task.');
        return;
    }
    input.value = '';
    if (dueInput) {
        dueInput.value = '';
    }
    closeModal(addModal);
    loadItems();
});

loadLists();
