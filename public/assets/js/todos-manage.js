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

let manageLists = [];
let shareListId = null;

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
                    <button type="button" class="todo-share-btn link text-xs" data-id="${list.id}" data-name="${esc(list.name)}">Share</button>
                    <button type="button" class="todo-delete-list btn-danger text-xs" data-id="${list.id}">Delete</button>
                    ` : ''}
                </div>
            </div>
            ${list.is_owner ? `
            <form class="todo-rename-form flex gap-2 mt-3" data-id="${list.id}">
                <input type="text" name="name" value="${esc(list.name)}" maxlength="128" class="input mono text-xs flex-1">
                <button type="submit" class="btn text-xs">Rename</button>
            </form>
            ` : ''}
        </div>
    `;
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
        el.innerHTML = '<p class="empty-state mono text-xs">No lists yet.</p>';
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
            const { res, body } = await api(`/api/todos/${id}/update`, {
                method: 'POST',
                body: JSON.stringify({ pin_to_dashboard: box.checked }),
            });
            if (!res.ok) {
                box.checked = !box.checked;
                alert(body.error || 'Could not update dashboard tab.');
            }
        });
    });

    el.querySelectorAll('.todo-rename-form').forEach(form => {
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const id = form.dataset.id;
            const name = form.name.value.trim();
            if (name === '') {
                return;
            }
            const { res, body } = await api(`/api/todos/${id}/update`, {
                method: 'POST',
                body: JSON.stringify({ name }),
            });
            if (!res.ok) {
                alert(body.error || 'Could not rename list.');
                return;
            }
            loadLists();
        });
    });

    el.querySelectorAll('.todo-delete-list').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this list and all its tasks?')) {
                return;
            }
            await api(`/api/todos/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
            if (shareListId === parseInt(btn.dataset.id, 10)) {
                document.getElementById('todo-share-panel')?.classList.add('hidden');
                shareListId = null;
            }
            loadLists();
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

document.getElementById('todo-list-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const name = form.name.value.trim();
    if (name === '') {
        return;
    }
    const { res, data } = await api('/api/todos/create', {
        method: 'POST',
        body: JSON.stringify({ name }),
    });
    if (!res.ok) {
        alert(data.error || 'Could not add list.');
        return;
    }
    form.reset();
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
