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

let manageLists = [];
let shareListId = null;

const addModal = document.getElementById('todo-list-add-modal');
const renameModal = document.getElementById('todo-rename-modal');
if (addModal) {
    bindModal(addModal);
}
if (renameModal) {
    bindModal(renameModal);
}

function listCard(list) {
    const counts = list.counts || {};
    const shared = list.is_owner ? '' : `<span class="chip chip-neutral text-xs">Shared · ${esc(list.owner_username)}</span>`;
    const perm   = list.is_owner ? '' : `<span class="mono text-xs text-faint">${esc(list.permission)} access</span>`;

    return `
        <div class="surface p-4" data-list-id="${list.id}">
            <div class="flex flex-wrap justify-between items-start gap-2">
                <div>
                    <span class="text-primary font-medium">${esc(list.name)}</span>
                    ${shared}
                    <p class="mono text-xs text-faint mt-1">
                        ${counts.open || 0} open · ${counts.completed || 0} completed · ${counts.archived || 0} archived
                    </p>
                    ${perm}
                </div>
                <div class="flex flex-wrap gap-2 items-center">
                    ${list.is_owner ? `
                    <label class="flex items-center gap-1.5 text-xs text-muted cursor-pointer">
                        <input type="checkbox" class="todo-pin" data-id="${list.id}"${list.pin_to_dashboard ? ' checked' : ''}>
                        Dashboard tab
                    </label>
                    <button type="button" class="todo-rename-btn link text-xs" data-id="${list.id}" data-name="${esc(list.name)}">Rename</button>
                    <button type="button" class="todo-share-btn link text-xs" data-id="${list.id}" data-name="${esc(list.name)}">Share</button>
                    <button type="button" class="todo-delete-list btn-danger text-xs" data-id="${list.id}">Delete</button>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
}

function openRename(id, name) {
    const input = document.getElementById('todo-rename-input');
    const idInput = document.getElementById('todo-rename-id');
    if (!renameModal || !input || !idInput) {
        return;
    }
    clearFieldError('todo-rename-error');
    idInput.value = String(id);
    input.value = name;
    openModal(renameModal);
}

async function loadLists() {
    const el = document.getElementById('todo-manage-lists');
    if (!el) {
        return;
    }

    const { res, data } = await api('/api/todos');
    if (!res.ok) {
        el.innerHTML = '<p class="text-danger text-sm mono">Could not load lists.</p>';
        return;
    }

    manageLists = data.lists || [];
    if (manageLists.length === 0) {
        el.innerHTML = window.DashboardEmpty.html({
            title: 'No lists yet',
            body: 'Add one with Add list.',
            actionLabel: 'Todo lists',
            actionHref: '/todos',
        });
        return;
    }

    el.innerHTML = manageLists.map(listCard).join('');

    el.querySelectorAll('.todo-pin').forEach(box => {
        box.addEventListener('change', async () => {
            const id = box.dataset.id;
            if (box.checked) {
                const pinnedCount = el.querySelectorAll('.todo-pin:checked').length;
                if (pinnedCount > 4) {
                    box.checked = false;
                    alert('Maximum of 4 lists can appear as dashboard tabs.');
                    return;
                }
            }
            const { res, data } = await api(`/api/todos/${id}/update`, {
                method: 'POST',
                body: JSON.stringify({ pin_to_dashboard: box.checked }),
            });
            if (!res.ok) {
                box.checked = !box.checked;
                alert(data.error || 'Could not update dashboard tab.');
            }
        });
    });

    el.querySelectorAll('.todo-rename-btn').forEach(btn => {
        btn.addEventListener('click', () => openRename(btn.dataset.id, btn.dataset.name || ''));
    });

    el.querySelectorAll('.todo-delete-list').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.dataset.confirming === '1') {
                return;
            }
            btn.dataset.confirming = '1';
            try {
                const ok = await globalThis.AppModal.confirm({
                    title: 'Delete list',
                    message: 'Delete this list and all its tasks?',
                    confirmLabel: 'Delete',
                    danger: true,
                });
                if (!ok) {
                    return;
                }
                await api(`/api/todos/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
                if (shareListId === parseInt(btn.dataset.id, 10)) {
                    document.getElementById('todo-share-panel')?.classList.add('hidden');
                    shareListId = null;
                }
                loadLists();
            } finally {
                if (btn.isConnected) {
                    btn.dataset.confirming = '';
                }
            }
        });
    });

    el.querySelectorAll('.todo-share-btn').forEach(btn => {
        btn.addEventListener('click', () => openSharePanel(parseInt(btn.dataset.id, 10), btn.dataset.name));
    });
}

async function openSharePanel(listId, listName) {
    shareListId = listId;
    const panel = document.getElementById('todo-share-panel');
    const nameEl = document.getElementById('todo-share-list-name');
    const hidden = document.getElementById('todo-share-list-id');
    if (!panel || !nameEl || !hidden) {
        return;
    }
    panel.classList.remove('hidden');
    nameEl.textContent = listName;
    hidden.value = String(listId);
    await loadShares(listId);
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function loadShares(listId) {
    const ul = document.getElementById('todo-share-users');
    if (!ul) {
        return;
    }

    const { res, data } = await api(`/api/todos/${listId}/shares`);
    if (!res.ok) {
        ul.innerHTML = '<li class="text-danger text-xs mono">Could not load shares.</li>';
        return;
    }

    const users = data.users || [];
    ul.innerHTML = users.length === 0
        ? '<li class="text-faint mono text-xs">Not shared with anyone yet.</li>'
        : users.map(u => `
            <li class="flex flex-wrap items-center justify-between gap-2 surface px-3 py-2">
                <span>${esc(u.username)} <span class="text-faint mono text-xs">${esc(u.email)} · ${esc(u.permission)}</span></span>
                <button type="button" class="todo-revoke-share text-danger text-xs underline" data-share-id="${u.id}">Remove</button>
            </li>
        `).join('');

    ul.querySelectorAll('.todo-revoke-share').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api(`/api/todos/${listId}/shares/user/${btn.dataset.shareId}/remove`, {
                method: 'POST',
                body: '{}',
            });
            loadShares(listId);
        });
    });
}

document.getElementById('todo-list-add-open')?.addEventListener('click', () => {
    clearFieldError('todo-list-add-error');
    openModal(addModal);
});

document.getElementById('todo-list-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const name = form.name.value.trim();
    if (name === '') {
        return;
    }
    clearFieldError('todo-list-add-error');
    const { res, data } = await api('/api/todos/create', {
        method: 'POST',
        body: JSON.stringify({ name }),
    });
    if (!res.ok) {
        showFieldError('todo-list-add-error', data.error || 'Could not add list.');
        return;
    }
    form.reset();
    closeModal(addModal);
    loadLists();
});

document.getElementById('todo-rename-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('todo-rename-id')?.value;
    const name = document.getElementById('todo-rename-input')?.value.trim() ?? '';
    if (!id || name === '') {
        return;
    }
    clearFieldError('todo-rename-error');
    const { res, data } = await api(`/api/todos/${id}/update`, {
        method: 'POST',
        body: JSON.stringify({ name }),
    });
    if (!res.ok) {
        showFieldError('todo-rename-error', data.error || 'Could not rename list.');
        return;
    }
    closeModal(renameModal);
    loadLists();
});

document.getElementById('todo-share-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const listId = parseInt(form.list_id.value, 10);
    const { res, data } = await api(`/api/todos/${listId}/shares/user`, {
        method: 'POST',
        body: JSON.stringify({
            identifier: form.identifier.value.trim(),
            permission: form.permission.value,
        }),
    });
    if (!res.ok) {
        alert(data.error || 'Could not share list.');
        return;
    }
    form.identifier.value = '';
    loadShares(listId);
});

loadLists();
