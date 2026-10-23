'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/brain.js'), 'utf8');
const template = fs.readFileSync(path.join(root, 'templates/brain.php'), 'utf8');
const treeIconFiles = {
    'brain-icon-chevron': 'chevron-right.svg',
    'brain-icon-folder': 'folder.svg',
    'brain-icon-file': 'file-text.svg',
};
const treeIcons = Object.fromEntries(Object.entries(treeIconFiles).map(([id, name]) => [
    id,
    fs.readFileSync(path.join(root, 'public/assets/vendor/lucide', name), 'utf8'),
]));

function mountTreeIcons(nodes) {
    Object.entries(treeIcons).forEach(([id, svg]) => {
        nodes[id].innerHTML = svg;
    });
}

function element(dataset) {
    const listeners = {};
    const classes = new Set();
    return {
        hidden: false,
        value: '',
        textContent: '',
        innerHTML: '',
        readOnly: false,
        disabled: false,
        checked: false,
        dataset: dataset || {},
        style: {},
        attrs: {},
        classList: {
            add(name) { classes.add(name); },
            remove(name) { classes.delete(name); },
            toggle(name, on) {
                const force = on === undefined ? !classes.has(name) : Boolean(on);
                if (force) classes.add(name);
                else classes.delete(name);
                return force;
            },
            contains(name) { return classes.has(name); },
        },
        addEventListener(type, fn) {
            (listeners[type] ||= []).push(fn);
        },
        dispatch(type, event) {
            return Promise.all((listeners[type] || []).map((fn) => fn(event)));
        },
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
        querySelector() { return null; },
        querySelectorAll() { return []; },
    };
}

function page(id, title, body) {
    return {
        id,
        title,
        body,
        kind: 'note',
        slug: title.toLowerCase(),
        folder_id: null,
        updated_at: '2026-10-23T00:00:00Z',
        breadcrumbs: [],
        links: [],
        backlinks: [],
        revisions: [],
    };
}

async function flush() {
    for (let i = 0; i < 8; i += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

const editorIds = [
    'brain-tree', 'brain-title', 'brain-body', 'brain-preview', 'brain-kind', 'brain-status', 'brain-links',
    'brain-backlinks', 'brain-revs', 'brain-crumbs', 'brain-move', 'brain-redline', 'brain-redline-view',
    'brain-redline-summary', 'brain-rev-current', 'brain-rev-restore', 'brain-split', 'brain-new',
    'brain-search', 'brain-collapse', 'brain-note-menu', 'brain-note-panel', 'brain-icon-chevron',
    'brain-icon-folder', 'brain-icon-file', 'brain-icon-plus', 'brain-icon-ellipsis',
];

function installTimers(sandbox) {
    const pending = [];
    let next = 1;
    sandbox.setTimeout = (fn) => {
        const id = next++;
        pending.push({ id, fn });
        return id;
    };
    sandbox.clearTimeout = (id) => {
        const index = pending.findIndex((item) => item.id === id);
        if (index >= 0) pending.splice(index, 1);
    };
    return async function drain() {
        while (pending.length) {
            const batch = pending.splice(0, pending.length);
            for (const item of batch) await item.fn();
        }
    };
}

function boot() {
    const store = {
        7: page(7, 'Home', '# Hello'),
        8: page(8, 'Trip', '# Trip'),
    };
    const posts = [];
    const nodes = {};
    const editBtn = element({ brainTab: 'edit' });
    const previewBtn = element({ brainTab: 'preview' });
    const shell = element({ openPage: '0' });
    const stage = element();

    function make(id) {
        const node = element();
        nodes[id] = node;
        return node;
    }

    editorIds.forEach(make);
    mountTreeIcons(nodes);

    const sandbox = {
        document: {
            getElementById(id) {
                if (!nodes[id]) throw new Error('missing ' + id);
                return nodes[id];
            },
            querySelector(selector) {
                if (selector === '.brain-shell') return shell;
                if (selector === '.brain-stage') return stage;
                return null;
            },
            querySelectorAll(selector) {
                if (selector === '[data-brain-tab]') return [editBtn, previewBtn];
                return [];
            },
            body: element(),
        },
        localStorage: {
            getItem() { return null; },
            setItem() {},
        },
        history: { replaceState() {} },
        LD: {
            api: {
                async get(url) {
                    if (url === '/pages/folders') return { folders: [] };
                    if (url === '/pages') {
                        return {
                            pages: Object.values(store).map((item) => ({
                                id: item.id,
                                title: item.title,
                                kind: item.kind,
                                slug: item.slug,
                                folder_id: item.folder_id,
                                updated_at: item.updated_at,
                            })),
                        };
                    }
                    const found = /^\/pages\/(\d+)$/.exec(url);
                    if (found) return { page: store[Number(found[1])] };
                    throw new Error('GET ' + url);
                },
                async post(url, payload) {
                    posts.push({ url, payload });
                    const found = /^\/pages\/(\d+)\/update$/.exec(url);
                    if (!found) throw new Error('POST ' + url);
                    const id = Number(found[1]);
                    store[id] = { ...store[id], title: payload.title, body: payload.body, kind: payload.kind };
                    return { page: store[id] };
                },
            },
            esc: (value) => String(value),
            fmt: { monthDay: (value) => String(value) },
        },
        BrainMarkdown: {
            render(raw) { return '<article>' + String(raw) + '</article>'; },
        },
        DashboardEmpty: { html: () => '<p>empty</p>' },
        addEventListener() {},
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    const drain = installTimers(sandbox);
    vm.runInNewContext(script, sandbox);

    return {
        posts,
        drain,
        editBtn,
        previewBtn,
        stage,
        body: nodes['brain-body'],
        preview: nodes['brain-preview'],
        title: nodes['brain-title'],
        kind: nodes['brain-kind'],
        status: nodes['brain-status'],
        tree: nodes['brain-tree'],
        split: nodes['brain-split'],
    };
}

function assertPreview(ui, raw) {
    assert.equal(ui.body.hidden, true);
    assert.equal(ui.preview.hidden, false);
    assert.equal(ui.stage.classList.contains('is-preview'), true);
    assert.equal(ui.editBtn.classList.contains('is-active'), false);
    assert.equal(ui.previewBtn.classList.contains('is-active'), true);
    assert.equal(ui.editBtn.attrs['aria-pressed'], 'false');
    assert.equal(ui.previewBtn.attrs['aria-pressed'], 'true');
    assert.equal(ui.split.style.gridTemplateColumns, '1fr');
    if (raw !== undefined) assert.equal(ui.preview.innerHTML, '<article>' + raw + '</article>');
}

function assertEdit(ui) {
    assert.equal(ui.body.hidden, false);
    assert.equal(ui.preview.hidden, true);
    assert.equal(ui.stage.classList.contains('is-preview'), false);
    assert.equal(ui.editBtn.classList.contains('is-active'), true);
    assert.equal(ui.previewBtn.classList.contains('is-active'), false);
    assert.equal(ui.editBtn.attrs['aria-pressed'], 'true');
    assert.equal(ui.previewBtn.attrs['aria-pressed'], 'false');
}

test('the vault template opens on preview', () => {
    assert.match(template, /<section class="brain-stage is-preview">/);
    assert.match(template, /data-brain-tab="edit" aria-pressed="false">Edit</);
    assert.match(template, /data-brain-tab="preview" class="is-active" aria-pressed="true">Preview</);
    assert.match(template, /id="brain-body" class="brain-editor" hidden /);
    assert.match(template, /id="brain-preview" class="brain-preview km"><\/div>/);
    assert.equal(template.includes('data-brain-tab="edit" class="is-active"'), false);
    assert.equal(template.includes('id="brain-save"'), false);
    assert.match(template, /aria-label="Note actions"/);
    assert.match(template, /aria-label="Collapse all"/);
    assert.match(template, /aria-label="New note"/);
    assert.match(template, /vendor\/lucide\/archive\.svg/);
    assert.match(template, /id="brain-note-panel"/);
    assert.match(template, /id="brain-archive"/);
    assert.match(template, /id="brain-kind"/);
    assert.match(template, /id="brain-move"/);
});

function camelData(name) {
    return name.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
}

function richDocument() {
    const state = { active: null };
    const listenersOf = new WeakMap();

    function matches(el, selector) {
        if (selector.startsWith('[') && selector.endsWith(']') && !selector.includes('=')) {
            const name = selector.slice(1, -1);
            if (name === 'hidden') return el.hidden === true;
            if (name.startsWith('data-')) {
                return Object.prototype.hasOwnProperty.call(el.dataset, camelData(name.slice(5)));
            }
            return Object.prototype.hasOwnProperty.call(el.attrs, name);
        }
        if (selector.startsWith('.')) {
            return selector.slice(1).split('.').filter(Boolean).every((name) => el.classList.contains(name));
        }
        if (selector.startsWith('#')) return el.id === selector.slice(1);
        return el.tag === selector;
    }

    function walk(el, visit) {
        (el.children || []).forEach((child) => {
            visit(child);
            walk(child, visit);
        });
    }

    function make(tag, attrs) {
        const classes = new Set(String(attrs.class || '').split(/\s+/).filter(Boolean));
        const dataset = {};
        Object.keys(attrs).forEach((name) => {
            if (name.startsWith('data-')) dataset[camelData(name.slice(5))] = attrs[name];
        });
        const el = {
            tag,
            id: attrs.id || '',
            hidden: Object.prototype.hasOwnProperty.call(attrs, 'hidden'),
            value: '',
            textContent: '',
            readOnly: false,
            disabled: false,
            checked: false,
            tabIndex: attrs.tabindex === undefined ? -1 : Number(attrs.tabindex),
            dataset,
            attrs,
            style: {},
            children: [],
            parent: null,
            classList: {
                add(name) { classes.add(name); },
                remove(name) { classes.delete(name); },
                toggle(name, on) {
                    const force = on === undefined ? !classes.has(name) : Boolean(on);
                    if (force) classes.add(name);
                    else classes.delete(name);
                    return force;
                },
                contains(name) { return classes.has(name); },
            },
            addEventListener(type, fn) {
                const bag = listenersOf.get(el) || {};
                (bag[type] ||= []).push(fn);
                listenersOf.set(el, bag);
            },
            dispatch(type, event) {
                const ev = event || {};
                if (!ev.target) ev.target = el;
                if (ev.defaultPrevented === undefined) ev.defaultPrevented = false;
                if (typeof ev.preventDefault !== 'function') {
                    ev.preventDefault = () => { ev.defaultPrevented = true; };
                }
                const chain = [];
                let node = el;
                while (node) {
                    chain.push(node);
                    node = node.parent;
                }
                const runs = [];
                chain.forEach((node) => {
                    const bag = listenersOf.get(node) || {};
                    (bag[type] || []).forEach((fn) => runs.push(fn(ev)));
                });
                return Promise.all(runs);
            },
            setAttribute(name, value) {
                this.attrs[name] = value;
                if (name === 'id') this.id = value;
                if (name.startsWith('data-')) this.dataset[camelData(name.slice(5))] = value;
            },
            getAttribute(name) {
                return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
            },
            removeAttribute(name) {
                delete this.attrs[name];
            },
            focus() { state.active = el; },
            appendChild(child) {
                el.children.push(child);
                child.parent = el;
                return child;
            },
            remove() {
                if (el.parent && el.parent.children) {
                    el.parent.children = el.parent.children.filter((child) => child !== el);
                    el.parent = null;
                }
            },
            closest(selector) {
                let node = el;
                while (node) {
                    if (matches(node, selector)) return node;
                    node = node.parent;
                }
                return null;
            },
            contains(node) {
                let cur = node;
                while (cur) {
                    if (cur === el) return true;
                    cur = cur.parent;
                }
                return false;
            },
            querySelector(selector) {
                let found = null;
                walk(el, (node) => {
                    if (!found && matches(node, selector)) found = node;
                });
                return found;
            },
            querySelectorAll(selector) {
                const found = [];
                walk(el, (node) => {
                    if (matches(node, selector)) found.push(node);
                });
                return found;
            },
        };
        Object.defineProperty(el, 'innerHTML', {
            get() { return el._html || ''; },
            set(value) {
                el._html = String(value);
                el.children = parseFragment(String(value));
                el.children.forEach((child) => { child.parent = el; });
            },
        });
        return el;
    }

    function parseTag(raw) {
        const trimmed = raw.trim();
        const split = trimmed.search(/\s/);
        const tag = (split === -1 ? trimmed : trimmed.slice(0, split)).toLowerCase();
        const rest = split === -1 ? '' : trimmed.slice(split);
        const attrs = {};
        const re = /([^\s"'>/=]+)(?:\s*=\s*"([^"]*)")?/g;
        let match = re.exec(rest);
        while (match) {
            attrs[match[1]] = match[2] === undefined ? '' : match[2];
            match = re.exec(rest);
        }
        return { tag, attrs };
    }

    function parseFragment(html) {
        let index = 0;
        function parseChildren(stop) {
            const nodes = [];
            while (index < html.length) {
                while (index < html.length && html[index] !== '<') index += 1;
                if (index >= html.length) break;
                if (html.startsWith('<!--', index)) {
                    const end = html.indexOf('-->', index);
                    index = end === -1 ? html.length : end + 3;
                    continue;
                }
                if (html.startsWith('</', index)) {
                    const end = html.indexOf('>', index);
                    const name = html.slice(index + 2, end).trim().toLowerCase();
                    index = end + 1;
                    if (stop && name === stop) return nodes;
                    continue;
                }
                const end = html.indexOf('>', index);
                let raw = html.slice(index + 1, end).trim();
                index = end + 1;
                let selfClosing = false;
                if (raw.endsWith('/')) {
                    selfClosing = true;
                    raw = raw.slice(0, -1).trim();
                }
                const parsed = parseTag(raw);
                const node = make(parsed.tag, parsed.attrs);
                if (!selfClosing) {
                    node.children = parseChildren(parsed.tag);
                    node.children.forEach((child) => { child.parent = node; });
                }
                nodes.push(node);
            }
            return nodes;
        }
        return parseChildren(null);
    }

    return {
        state,
        make,
        get activeElement() { return state.active; },
    };
}

function bootTree(options) {
    const recordPosts = Boolean(options && options.recordPosts);
    const longName = 'A very long folder name that should truncate';
    const folders = [{
        id: 1,
        name: 'Projects',
        parent_id: null,
        children: [{ id: 2, name: longName, parent_id: 1, children: [] }],
    }];
    const store = {
        7: page(7, 'Home', '# Hello'),
        8: Object.assign(page(8, 'Trip', '# Trip'), { folder_id: 1, kind: 'daily' }),
        9: Object.assign(page(9, "Bob's plan", '# Plan'), { folder_id: 2, kind: 'project' }),
    };
    const gets = [];
    const posts = [];
    const doc = richDocument();
    const nodes = {};
    const editBtn = element({ brainTab: 'edit' });
    const previewBtn = element({ brainTab: 'preview' });
    const shell = element({ openPage: '0' });
    const stage = element();
    const body = doc.make('body', {});

    editorIds.forEach((id) => {
        nodes[id] = doc.make('div', { id });
    });
    mountTreeIcons(nodes);

    const sandbox = {
        document: {
            get activeElement() { return doc.activeElement; },
            getElementById(id) {
                if (!nodes[id]) throw new Error('missing ' + id);
                return nodes[id];
            },
            createElement(tag) {
                return doc.make(tag, {});
            },
            querySelector(selector) {
                if (selector === '.brain-shell') return shell;
                if (selector === '.brain-stage') return stage;
                if (selector === '.app-modal:not(.hidden)') {
                    const modal = body.querySelector('.app-modal');
                    if (modal && !modal.classList.contains('hidden')) return modal;
                    return null;
                }
                if (selector.startsWith('#')) {
                    const id = selector.slice(1);
                    if (nodes[id]) return nodes[id];
                    return body.querySelector(selector);
                }
                return null;
            },
            querySelectorAll(selector) {
                if (selector === '[data-brain-tab]') return [editBtn, previewBtn];
                return [];
            },
            body,
        },
        localStorage: { getItem() { return null; }, setItem() {} },
        history: { replaceState() {} },
        LD: {
            api: {
                async get(url) {
                    gets.push(url);
                    if (url === '/pages/folders') return { folders };
                    if (url === '/pages' || url.startsWith('/pages?')) {
                        return {
                            pages: Object.values(store).map((item) => ({
                                id: item.id,
                                title: item.title,
                                kind: item.kind,
                                slug: item.slug,
                                folder_id: item.folder_id,
                                updated_at: item.updated_at,
                            })),
                        };
                    }
                    const found = /^\/pages\/(\d+)$/.exec(url);
                    if (found) return { page: store[Number(found[1])] };
                    throw new Error('GET ' + url);
                },
                async post(url, payload) {
                    if (!recordPosts) throw new Error('unexpected post');
                    posts.push({ url, payload });
                    if (url === '/pages') {
                        const id = 40 + posts.length;
                        const created = Object.assign(page(id, payload.title, payload.body || ''), {
                            folder_id: payload.folder_id || null,
                            kind: payload.kind || 'note',
                        });
                        store[id] = created;
                        return { page: created };
                    }
                    const updated = /^\/pages\/(\d+)\/update$/.exec(url);
                    if (updated) {
                        const id = Number(updated[1]);
                        store[id] = Object.assign({}, store[id], {
                            title: payload.title,
                            body: payload.body,
                            kind: payload.kind,
                        });
                        return { page: store[id] };
                    }
                    throw new Error('POST ' + url);
                },
            },
            esc: (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;'),
            fmt: { monthDay: (value) => String(value) },
        },
        BrainMarkdown: { render(raw) { return '<article>' + String(raw) + '</article>'; } },
        DashboardEmpty: { html: () => '<p>empty</p>' },
        addEventListener() {},
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    vm.runInNewContext(script, sandbox);
    return {
        gets,
        posts,
        longName,
        editBtn,
        previewBtn,
        stage,
        body: nodes['brain-body'],
        preview: nodes['brain-preview'],
        title: nodes['brain-title'],
        tree: nodes['brain-tree'],
        split: nodes['brain-split'],
        pageBody: body,
        newBtn: nodes['brain-new'],
        collapse: nodes['brain-collapse'],
    };
}

function treeRows(tree) {
    return tree.querySelectorAll('[data-tree-key]').filter((row) => {
        return !row.closest('[hidden]') && !row.closest('.brain-folder-children.is-collapsed');
    });
}

function focusedRow(tree) {
    return treeRows(tree).find((row) => row.tabIndex === 0) || null;
}

async function press(tree, key) {
    const row = focusedRow(tree);
    row.focus();
    const event = { key, defaultPrevented: false };
    await row.dispatch('keydown', event);
    return event;
}

test('a note opens in preview and autosave keeps the Edit toggle', async () => {
    const ui = boot();
    await flush();

    assertPreview(ui, '# Hello');
    assert.equal(ui.title.value, 'Home');
    assert.equal(ui.body.value, '# Hello');
    assert.match(ui.status.innerHTML, /Saved automatically/);

    await ui.title.dispatch('input');
    await ui.drain();
    await flush();
    assert.equal(ui.posts.length, 0);

    ui.title.value = 'Home note';
    await ui.title.dispatch('input');
    await ui.drain();
    await flush();
    assertPreview(ui, '# Hello');
    assert.equal(ui.posts.at(-1).url, '/pages/7/update');
    assert.equal(ui.posts.at(-1).payload.body, '# Hello');
    assert.equal(ui.posts.at(-1).payload.title, 'Home note');
    assert.match(ui.status.innerHTML, /Saved automatically/);
    assert.equal(ui.status.innerHTML.includes('brain-saved'), true);

    ui.editBtn.dispatch('click');
    assertEdit(ui);

    ui.body.value = '# Hello\n\nedited';
    await ui.body.dispatch('input');
    await ui.drain();
    await flush();
    assertEdit(ui);
    assert.equal(ui.posts.at(-1).payload.body, '# Hello\n\nedited');
    assert.equal(ui.body.value, '# Hello\n\nedited');
    assert.match(ui.status.innerHTML, /Saved automatically/);

    ui.kind.value = 'project';
    await ui.kind.dispatch('change');
    await ui.drain();
    await flush();
    assertEdit(ui);
    assert.equal(ui.posts.at(-1).payload.kind, 'project');

    await ui.tree.dispatch('click', {
        target: {
            closest(selector) {
                if (selector === '[data-id]') return { dataset: { id: '7' } };
                return null;
            },
        },
    });
    await flush();
    assertEdit(ui);

    ui.previewBtn.dispatch('click');
    assertPreview(ui, '# Hello\n\nedited');

    await ui.tree.dispatch('click', {
        target: {
            closest(selector) {
                if (selector === '[data-id]') return { dataset: { id: '8' } };
                return null;
            },
        },
    });
    await flush();
    assertPreview(ui, '# Trip');
    assert.equal(ui.title.value, 'Trip');
    assert.equal(ui.body.value, '# Trip');
});

test('the vault tree uses guides, chevrons, and kind marks', async () => {
    const css = fs.readFileSync(path.join(root, 'public/assets/css/lifedash.css'), 'utf8');
    const chevron = css.slice(css.indexOf('.brain-chevron {'), css.indexOf('.brain-folder-toggle[aria-expanded="false"]'));
    assert.match(css, /\.brain-folder-branch::before\s*\{[^}]*width:\s*1px/);
    assert.match(css, /\.brain-tree-label\s*\{[^}]*text-overflow:\s*ellipsis/);
    assert.match(css, /\.brain-node\.is-active,\s*\n\.brain-node\.is-active:hover \{\n    background: var\(--accent-soft\);\n    box-shadow: inset 2px 0 0 var\(--accent\);/);
    assert.match(css, /\.brain-node\.is-active \.brain-tree-label\s*\{[^}]*var\(--accent\)/);
    assert.match(css, /@media \(prefers-reduced-motion: no-preference\) \{\n    \.brain-folder-children \{ transition: grid-template-rows 160ms ease; \}\n    \.brain-chevron \{ transition: transform 160ms ease; \}\n\}/);
    assert.equal(chevron.includes('transition'), false);
    assert.equal(chevron.includes('border'), false);
    assert.equal(css.includes('.brain-tree-folder::before'), false);
    assert.match(css, /\.brain-kind-daily \{ color: var\(--ok\); \}/);
    assert.equal(script.includes('▾'), false);
    assert.equal(script.includes('▸'), false);
    Object.entries(treeIconFiles).forEach(([id, name]) => {
        assert.match(template, new RegExp("readfile\\(__DIR__ \\. '/\\.\\./public/assets/vendor/lucide/" + name.replace('.', '\\.') + "'\\)"));
        const pathD = /<path d="([^"]+)"/.exec(treeIcons[id])[1];
        assert.equal(script.includes(pathD), false);
        assert.match(treeIcons[id], /stroke="currentColor"/);
        assert.match(treeIcons[id], /lucide-static v1\.61\.0 - ISC/);
    });

    const ui = bootTree();
    await flush();

    assert.equal(ui.tree.attrs.role, 'tree');
    assert.equal(ui.tree.attrs['aria-label'], 'Vault folders');
    const html = ui.tree.innerHTML;
    assert.match(html, /class="brain-chevron"/);
    assert.match(html, /class="lucide lucide-chevron-right"/);
    assert.match(html, /class="brain-tree-folder"/);
    assert.match(html, /class="lucide lucide-folder"/);
    assert.match(html, /class="brain-kind brain-kind-daily"/);
    assert.match(html, /class="brain-kind brain-kind-project"/);
    assert.match(html, /class="lucide lucide-file-text"/);
    Object.values(treeIcons).forEach((svg) => {
        const pathD = /<path d="([^"]+)"/.exec(svg)[1];
        assert.equal(html.includes(pathD), true);
    });
    assert.match(html, /class="brain-tree-label"/);
    assert.match(html, /title="A very long folder name that should truncate"/);
    assert.match(html, /title="Bob&#39;s plan"/);
    assert.equal(html.includes('▾'), false);
    assert.equal(html.includes('▸'), false);

    const rows = treeRows(ui.tree);
    assert.deepEqual(rows.map((row) => row.dataset.treeKey), ['root', 'folder:1', 'folder:2', 'page:9', 'page:8', 'page:7']);
    assert.equal(rows.filter((row) => row.tabIndex === 0).length, 1);
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'page:7');
    assert.equal(rows.find((row) => row.dataset.treeKey === 'folder:2').dataset.parentKey, 'folder:1');
    assert.equal(rows.find((row) => row.dataset.treeKey === 'page:9').dataset.parentKey, 'folder:2');
    assert.equal(rows.find((row) => row.dataset.treeKey === 'page:8').dataset.parentKey, 'folder:1');
    assert.equal(rows.find((row) => row.dataset.treeKey === 'page:7').dataset.parentKey, '');
    const nestedGroup = rows.find((row) => row.dataset.treeKey === 'folder:2').closest('.brain-folder').querySelector('.brain-folder-children');
    const projectGroup = rows.find((row) => row.dataset.treeKey === 'folder:1').closest('.brain-folder').querySelector('.brain-folder-children');
    assert.equal(nestedGroup.classList.contains('brain-folder-children'), true);
    assert.equal(projectGroup.classList.contains('brain-folder-children'), true);
    assert.equal(nestedGroup.hidden, false);
    assert.equal(nestedGroup.classList.contains('is-collapsed'), false);
});

test('vault tree keys move, expand, and open without scrolling', async () => {
    const ui = bootTree();
    await flush();
    assertPreview(ui, '# Hello');

    await press(ui.tree, 'ArrowUp');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'page:8');
    await press(ui.tree, 'ArrowUp');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'page:9');
    await press(ui.tree, 'ArrowUp');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'folder:2');
    assert.equal(focusedRow(ui.tree).attrs['aria-expanded'], 'true');

    await press(ui.tree, 'ArrowLeft');
    let folder = focusedRow(ui.tree);
    assert.equal(folder.dataset.treeKey, 'folder:2');
    assert.equal(folder.attrs['aria-expanded'], 'false');
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'page:9'), false);

    await press(ui.tree, 'ArrowLeft');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'folder:1');
    await press(ui.tree, 'ArrowRight');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'folder:2');
    await press(ui.tree, 'ArrowRight');
    folder = focusedRow(ui.tree);
    assert.equal(folder.dataset.treeKey, 'folder:2');
    assert.equal(folder.attrs['aria-expanded'], 'true');
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'page:9'), true);

    await press(ui.tree, 'ArrowRight');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'page:9');
    await press(ui.tree, 'ArrowDown');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'page:8');

    ui.editBtn.dispatch('click');
    assertEdit(ui);
    await press(ui.tree, 'Enter');
    await flush();
    assertPreview(ui, '# Trip');
    assert.equal(ui.title.value, 'Trip');

    await press(ui.tree, 'ArrowDown');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'page:7');
    const spaceOnNote = await press(ui.tree, ' ');
    assert.equal(spaceOnNote.defaultPrevented, true);
    assert.equal(ui.title.value, 'Trip');
    assertPreview(ui, '# Trip');

    await press(ui.tree, 'ArrowUp');
    await press(ui.tree, 'ArrowUp');
    await press(ui.tree, 'ArrowUp');
    await press(ui.tree, 'ArrowUp');
    assert.equal(focusedRow(ui.tree).dataset.treeKey, 'folder:1');
    const spaceOnFolder = await press(ui.tree, ' ');
    assert.equal(spaceOnFolder.defaultPrevented, true);
    assert.equal(focusedRow(ui.tree).attrs['aria-expanded'], 'false');
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'page:8'), false);

    const toggle = ui.tree.querySelectorAll('[data-toggle]').find((node) => node.dataset.toggle === '1');
    await toggle.dispatch('click');
    assert.equal(focusedRow(ui.tree).attrs['aria-expanded'], 'true');

    ui.editBtn.dispatch('click');
    assertEdit(ui);
    const home = treeRows(ui.tree).find((row) => row.dataset.treeKey === 'page:7');
    await home.dispatch('click');
    await flush();
    assertPreview(ui, '# Hello');
    assert.equal(ui.title.value, 'Home');

    const project = ui.tree.querySelectorAll('[data-folder-pick]').find((node) => node.dataset.folderPick === '1');
    await project.dispatch('click');
    await flush();
    const projectRow = treeRows(ui.tree).find((row) => row.dataset.treeKey === 'folder:1');
    assert.equal(projectRow.closest('.brain-folder').classList.contains('is-active'), true);
    assert.equal(ui.gets.includes('/pages?folder_id=1'), true);
    assertPreview(ui, '# Hello');
});

test('collapse all closes every folder and tree rows keep hover actions', async () => {
    const css = fs.readFileSync(path.join(root, 'public/assets/css/lifedash.css'), 'utf8');
    assert.match(css, /\.brain-tree-row:hover > \.brain-row-actions/);
    assert.match(css, /\.brain-saved \{ color: var\(--ok\);/);
    assert.match(script, /function scheduleAutosave\(/);
    assert.match(script, /function openCreateNote\(/);
    assert.match(script, /function collapseAll\(/);

    const ui = bootTree();
    await flush();
    const html = ui.tree.innerHTML;
    assert.match(html, /data-tree-action="add"/);
    assert.match(html, /data-tree-action="menu"/);
    assert.match(html, /aria-label="New note"/);
    assert.match(html, /aria-label="Row actions"/);
    assert.match(html, /data-menu="new-folder"/);
    assert.match(html, /data-menu="rename"/);
    assert.match(html, /data-menu="delete"/);
    assert.match(html, /data-menu="archive"/);
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'page:9'), true);

    await ui.collapse.dispatch('click');
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'page:9'), false);
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'page:8'), false);
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'folder:2'), false);
    const projects = treeRows(ui.tree).find((row) => row.dataset.treeKey === 'folder:1');
    assert.equal(projects.attrs['aria-expanded'], 'false');
    assert.equal(treeRows(ui.tree).some((row) => row.dataset.treeKey === 'page:7'), true);
});

test('create note posts the title and the folder from the tree row', async () => {
    const ui = bootTree({ recordPosts: true });
    await flush();

    const projects = treeRows(ui.tree).find((row) => row.dataset.treeKey === 'folder:1');
    const add = projects.querySelectorAll('[data-tree-action]').find((node) => node.dataset.treeAction === 'add');
    await add.dispatch('click');
    const modal = ui.pageBody.querySelector('#brain-note-modal');
    assert.equal(modal !== null, true);
    assert.match(modal.innerHTML, /Create note/);
    assert.match(modal.innerHTML, /Add a new note to your knowledge vault\./);
    assert.match(modal.innerHTML, /Note title/);
    assert.match(modal.innerHTML, /Untitled note/);

    const field = modal.querySelector('#brain-note-title');
    const form = modal.querySelector('#brain-note-modal-form');
    field.value = '   ';
    await form.dispatch('submit');
    await flush();
    assert.equal(ui.posts.length, 0);
    assert.equal(modal.querySelector('[data-error]').textContent, 'Add a title first.');
    assert.equal(ui.pageBody.querySelector('#brain-note-modal') !== null, true);

    field.value = 'Field notes';
    await form.dispatch('submit');
    await flush();
    assert.equal(ui.posts.length, 1);
    assert.equal(ui.posts[0].url, '/pages');
    assert.equal(ui.posts[0].payload.title, 'Field notes');
    assert.equal(ui.posts[0].payload.kind, 'note');
    assert.equal(ui.posts[0].payload.body, '');
    assert.equal(ui.posts[0].payload.folder_id, 1);
    assert.equal(ui.title.value, 'Field notes');
    assert.equal(ui.pageBody.querySelector('#brain-note-modal'), null);

    await ui.newBtn.dispatch('click');
    const rootModal = ui.pageBody.querySelector('#brain-note-modal');
    const rootField = rootModal.querySelector('#brain-note-title');
    rootField.value = 'Root note';
    await rootModal.querySelector('#brain-note-modal-form').dispatch('submit');
    await flush();
    assert.equal(ui.posts[1].url, '/pages');
    assert.equal(ui.posts[1].payload.title, 'Root note');
    assert.equal(ui.posts[1].payload.folder_id, undefined);
    assert.equal(ui.title.value, 'Root note');
    assertPreview(ui, '');
});
