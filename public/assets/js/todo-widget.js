'use strict';

async function todoApi(url, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = getCsrfToken();
    }
    const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function todoEsc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

let todoWidgetListId = null;
let todoWidgetCanWrite = false;
let todoWidgetItems = [];
let todoWidgetAssignable = [];

function renderTodoTabs(tabLists, activeId) {
    const tabs = document.getElementById('todo-widget-tabs');
    if (!tabs) {
        return;
    }

    if (!tabLists.length || tabLists.length === 1) {
        tabs.replaceChildren();
        tabs.classList.add('hidden');
        return;
    }

    tabs.classList.remove('hidden');
    tabs.replaceChildren();

    tabLists.forEach(list => {
        const isActive = list.id === activeId;
        const suffix = list.is_owner ? '' : ` (${list.owner_username})`;
        const label = `${list.name}${suffix}`;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `todo-widget-tab clip-tab text-xs px-2 py-0.5 rounded mono truncate${isActive ? ' clip-tab-active' : ''}`;
        btn.setAttribute('role', 'tab');
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        btn.title = label;
        btn.textContent = list.name + (list.is_owner ? '' : '*');
        btn.dataset.id = String(list.id);

        btn.addEventListener('click', () => {
            if (list.id !== todoWidgetListId) {
                loadTodoWidget(list.id);
            }
        });

        tabs.appendChild(btn);
    });
}

function updateAddButton() {
    const btn = document.getElementById('todo-widget-add-btn');
    if (!btn) {
        return;
    }

    if (todoWidgetCanWrite && todoWidgetListId) {
        btn.removeAttribute('disabled');
        btn.classList.remove('hidden');
    } else {
        btn.setAttribute('disabled', 'disabled');
        btn.classList.add('hidden');
    }
}

function openTodoAddModal() {
    if (!todoWidgetCanWrite || !todoWidgetListId) {
        return;
    }

    closeTodoEditModal();
    const modal = document.getElementById('todo-widget-add-modal');
    modal?.classList.remove('hidden');
    document.body.classList.add('app-modal-open');
    const input = document.getElementById('todo-widget-input');
    const dueInput = document.getElementById('todo-widget-due');
    if (input) {
        input.value = '';
        input.focus();
    }
    if (dueInput) {
        dueInput.value = '';
    }
}

function closeTodoAddModal() {
    document.getElementById('todo-widget-add-modal')?.classList.add('hidden');
    if (!document.getElementById('todo-widget-edit-modal')?.classList.contains('hidden')) {
        return;
    }
    document.body.classList.remove('app-modal-open');
}

function closeTodoEditModal() {
    document.getElementById('todo-widget-edit-modal')?.classList.add('hidden');
    if (!document.getElementById('todo-widget-add-modal')?.classList.contains('hidden')) {
        return;
    }
    document.body.classList.remove('app-modal-open');
}

function renderEditLinkRow(link = { label: '', url: '' }, showRemove = false) {
    const row = document.createElement('div');
    row.className = 'todo-link-row flex flex-wrap gap-2 mb-2';
    row.innerHTML = `
        <input type="text" class="todo-link-label input mono text-xs flex-1 min-w-[6rem]" placeholder="Label (optional)" maxlength="128" value="${todoEsc(link.label || '')}">
        <input type="url" class="todo-link-url input mono text-xs flex-[2] min-w-[10rem]" placeholder="https://…" value="${todoEsc(link.url || '')}">
        ${showRemove ? '<button type="button" class="todo-link-remove text-danger text-xs">Remove</button>' : ''}
    `;
    row.querySelector('.todo-link-remove')?.addEventListener('click', () => row.remove());
    return row;
}

function populateEditLinkFields(links = []) {
    const wrap = document.getElementById('todo-widget-edit-links');
    if (!wrap) {
        return;
    }
    wrap.replaceChildren();
    const rows = links.length ? links : [{ label: '', url: '' }];
    rows.forEach((link, i) => {
        wrap.appendChild(renderEditLinkRow(link, i > 0));
    });
}

function populateEditAssigneeOptions(selectedId = null) {
    const select = document.getElementById('todo-widget-edit-assignee');
    if (!select) {
        return;
    }
    select.innerHTML = '<option value="">Unassigned</option>';
    todoWidgetAssignable.forEach(u => {
        const opt = document.createElement('option');
        opt.value = String(u.id);
        opt.textContent = u.username;
        if (selectedId !== null && Number(u.id) === Number(selectedId)) {
            opt.selected = true;
        }
        select.appendChild(opt);
    });
}

function openTodoEditModal(item) {
    if (!item?.can_edit) {
        return;
    }

    closeTodoAddModal();
    const modal = document.getElementById('todo-widget-edit-modal');
    if (!modal) {
        return;
    }

    document.getElementById('todo-widget-edit-id').value = String(item.id);
    document.getElementById('todo-widget-edit-input').value = item.title || '';
    document.getElementById('todo-widget-edit-due').value = item.due_date || '';
    document.getElementById('todo-widget-edit-notes').value = item.notes || '';
    populateEditLinkFields(item.links || []);
    populateEditAssigneeOptions(item.assigned_to_user_id);

    modal.querySelectorAll('.todo-widget-edit-owner-only').forEach(el => {
        el.classList.toggle('hidden', !todoWidgetCanWrite);
    });

    modal.classList.remove('hidden');
    document.body.classList.add('app-modal-open');
    document.getElementById('todo-widget-edit-input')?.focus();
}

function collectEditLinks() {
    const links = [];
    document.querySelectorAll('#todo-widget-edit-links .todo-link-row').forEach(row => {
        const url = row.querySelector('.todo-link-url')?.value.trim() ?? '';
        if (url === '') {
            return;
        }
        links.push({
            label: row.querySelector('.todo-link-label')?.value.trim() ?? '',
            url,
        });
    });
    return links;
}

function widgetItemHtml(item) {
    const due = window.TodoDue || {};
    const level = item.due_urgency || due.urgency?.(item.due_date, item.status);
    const dueClass = due.rowClass?.(level) || '';
    const flag = due.flagHtml?.(level) || '';
    const pri = item.priority
        ? `<span class="todo-priority widget-mono-sm text-faint flex-shrink-0" title="Priority ${todoEsc(String(item.priority))}">P${todoEsc(String(item.priority))}</span>`
        : '';
    const dueLabel = item.due_date && due.formatLabel
        ? `<span class="todo-due-label widget-mono-sm text-faint flex-shrink-0">${todoEsc(due.formatLabel(item.due_date))}</span>`
        : '';
    const suffix = item.assignee_username
        ? `<span class="widget-mono-sm text-faint flex-shrink-0">→ ${todoEsc(item.assignee_username)}</span>`
        : '';
    const details = item.has_details
        ? '<span class="text-faint flex-shrink-0" title="Has details">⋯</span>'
        : '';
    const titleClass = item.can_edit
        ? 'todo-widget-title text-muted truncate flex-1 min-w-0 cursor-pointer hover:text-accent'
        : 'todo-widget-title text-muted truncate flex-1 min-w-0';

    return `
        <div class="todo-line mono text-sm min-w-0${dueClass}" data-id="${item.id}">
            <input type="checkbox" class="todo-widget-check flex-shrink-0" data-id="${item.id}" aria-label="Complete task">
            ${flag}
            ${pri}
            <span class="${titleClass}" data-id="${item.id}" title="${item.can_edit ? 'Click to edit' : ''}">${todoEsc(item.title)}</span>
            ${details}
            ${dueLabel}
            ${suffix}
        </div>
    `;
}

function bindWidgetTitleEdit(root) {
    root.querySelectorAll('.todo-widget-title').forEach(span => {
        span.addEventListener('click', e => {
            e.stopPropagation();
            const id = parseInt(span.dataset.id, 10);
            const item = todoWidgetItems.find(i => i.id === id);
            if (item) {
                openTodoEditModal(item);
            }
        });
    });
}

async function loadTodoWidget(listId = null) {
    const root = document.getElementById('todo-widget');
    if (!root) {
        return;
    }

    const qs = listId ? `?list_id=${encodeURIComponent(listId)}` : '';
    const { res, data } = await todoApi(`/api/todos/widget${qs}`);

    if (!res.ok) {
        root.innerHTML = window.DashboardEmpty.html({
            title: 'Todos unavailable',
            body: 'The lists could not be loaded.',
            actionLabel: 'Open todos',
            actionHref: '/todos',
        });
        return;
    }

    const lists = data.lists || [];
    const tabLists = data.tab_lists || [];

    if (lists.length === 0) {
        renderTodoTabs([], null);
        root.innerHTML = window.DashboardEmpty.html({
            title: 'No lists yet',
            body: 'Create a list to track tasks here.',
            actionLabel: 'Add a list',
            actionHref: '/settings/todos',
        });
        todoWidgetListId = null;
        todoWidgetCanWrite = false;
        todoWidgetItems = [];
        todoWidgetAssignable = [];
        updateAddButton();
        return;
    }

    todoWidgetListId = data.list_id;
    todoWidgetCanWrite = !!data.can_write;
    todoWidgetItems = data.items || [];
    todoWidgetAssignable = data.assignable_users || [];

    renderTodoTabs(tabLists, todoWidgetListId);
    updateAddButton();

    const items = todoWidgetItems;
    if (items.length === 0) {
        root.innerHTML = window.DashboardEmpty.html({
            title: 'No open tasks',
            body: 'Add a task below, or open the full list.',
            actionLabel: 'Open todos',
            actionHref: '/todos',
        });
        return;
    }

    root.innerHTML = `<div class="space-y-1">${items.map(widgetItemHtml).join('')}</div>`;

    root.querySelectorAll('.todo-widget-check').forEach(box => {
        box.addEventListener('change', async () => {
            if (!box.checked) {
                return;
            }
            box.disabled = true;
            await todoApi(`/api/todos/items/${box.dataset.id}/update`, {
                method: 'POST',
                body: JSON.stringify({ status: 'completed' }),
            });
            loadTodoWidget(todoWidgetListId);
        });
    });

    bindWidgetTitleEdit(root);
}

document.getElementById('todo-widget-add-btn')?.addEventListener('click', openTodoAddModal);
document.getElementById('todo-widget-add-close')?.addEventListener('click', closeTodoAddModal);
document.getElementById('todo-widget-add-cancel')?.addEventListener('click', closeTodoAddModal);
document.getElementById('todo-widget-add-backdrop')?.addEventListener('click', closeTodoAddModal);

document.getElementById('todo-widget-edit-close')?.addEventListener('click', closeTodoEditModal);
document.getElementById('todo-widget-edit-cancel')?.addEventListener('click', closeTodoEditModal);
document.getElementById('todo-widget-edit-backdrop')?.addEventListener('click', closeTodoEditModal);
document.getElementById('todo-widget-edit-due-clear')?.addEventListener('click', () => {
    const input = document.getElementById('todo-widget-edit-due');
    if (input) {
        input.value = '';
    }
});

document.getElementById('todo-widget-edit-link-add')?.addEventListener('click', () => {
    document.getElementById('todo-widget-edit-links')?.appendChild(renderEditLinkRow({}, true));
});

document.getElementById('todo-widget-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const input = document.getElementById('todo-widget-input');
    const dueInput = document.getElementById('todo-widget-due');
    if (!input || !todoWidgetListId || !todoWidgetCanWrite) {
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
    const { res, data } = await todoApi(`/api/todos/${todoWidgetListId}/items/create`, {
        method: 'POST',
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        alert(data.error || 'Could not add task.');
        return;
    }
    closeTodoAddModal();
    loadTodoWidget(todoWidgetListId);
});

document.getElementById('todo-widget-edit-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = parseInt(document.getElementById('todo-widget-edit-id')?.value ?? '0', 10);
    const title = document.getElementById('todo-widget-edit-input')?.value.trim() ?? '';
    if (!id || title === '') {
        return;
    }

    const body = {
        title,
        notes: document.getElementById('todo-widget-edit-notes')?.value ?? '',
        links: collectEditLinks(),
    };

    if (todoWidgetCanWrite) {
        const dueEl = document.getElementById('todo-widget-edit-due');
        body.due_date = dueEl?.value === '' ? null : (dueEl?.value ?? null);
        const assigneeEl = document.getElementById('todo-widget-edit-assignee');
        if (assigneeEl) {
            body.assigned_to_user_id = assigneeEl.value === '' ? null : parseInt(assigneeEl.value, 10);
        }
    }

    const { res, data } = await todoApi(`/api/todos/items/${id}/update`, {
        method: 'POST',
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        alert(data.error || 'Could not save task.');
        return;
    }
    closeTodoEditModal();
    loadTodoWidget(todoWidgetListId);
});

document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') {
        return;
    }
    if (!document.getElementById('todo-widget-edit-modal')?.classList.contains('hidden')) {
        closeTodoEditModal();
    } else if (!document.getElementById('todo-widget-add-modal')?.classList.contains('hidden')) {
        closeTodoAddModal();
    }
});

async function loadTodosWidget() {
    await loadTodoWidget();
}

loadTodosWidget();
