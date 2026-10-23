'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/brain.js'), 'utf8');
const template = fs.readFileSync(path.join(root, 'templates/brain.php'), 'utf8');

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

    ['brain-tree', 'brain-title', 'brain-body', 'brain-preview', 'brain-kind', 'brain-status', 'brain-links', 'brain-backlinks', 'brain-revs', 'brain-crumbs', 'brain-move', 'brain-redline', 'brain-redline-view', 'brain-redline-summary', 'brain-rev-current', 'brain-rev-restore', 'brain-save', 'brain-split', 'brain-new', 'brain-folder', 'brain-search'].forEach(make);

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
    vm.runInNewContext(script, sandbox);

    return {
        posts,
        editBtn,
        previewBtn,
        stage,
        body: nodes['brain-body'],
        preview: nodes['brain-preview'],
        title: nodes['brain-title'],
        status: nodes['brain-status'],
        tree: nodes['brain-tree'],
        saveBtn: nodes['brain-save'],
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

function bootTree() {
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
    const doc = richDocument();
    const nodes = {};
    const editBtn = element({ brainTab: 'edit' });
    const previewBtn = element({ brainTab: 'preview' });
    const shell = element({ openPage: '0' });
    const stage = element();

    ['brain-tree', 'brain-title', 'brain-body', 'brain-preview', 'brain-kind', 'brain-status', 'brain-links', 'brain-backlinks', 'brain-revs', 'brain-crumbs', 'brain-move', 'brain-redline', 'brain-redline-view', 'brain-redline-summary', 'brain-rev-current', 'brain-rev-restore', 'brain-save', 'brain-split', 'brain-new', 'brain-folder', 'brain-search'].forEach((id) => {
        nodes[id] = doc.make('div', { id });
    });

    const sandbox = {
        document: {
            get activeElement() { return doc.activeElement; },
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
                async post() { throw new Error('unexpected post'); },
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
        longName,
        editBtn,
        previewBtn,
        stage,
        body: nodes['brain-body'],
        preview: nodes['brain-preview'],
        title: nodes['brain-title'],
        tree: nodes['brain-tree'],
        saveBtn: nodes['brain-save'],
        split: nodes['brain-split'],
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

test('a note opens in preview and Edit is a toggle', async () => {
    const ui = boot();
    await flush();

    assertPreview(ui, '# Hello');
    assert.equal(ui.title.value, 'Home');
    assert.equal(ui.body.value, '# Hello');

    await ui.saveBtn.dispatch('click');
    await flush();
    assertPreview(ui, '# Hello');
    assert.equal(ui.posts.at(-1).url, '/pages/7/update');
    assert.equal(ui.posts.at(-1).payload.body, '# Hello');
    assert.equal(ui.posts.at(-1).payload.title, 'Home');
    assert.equal(ui.status.textContent, 'Saved.');

    ui.editBtn.dispatch('click');
    assertEdit(ui);

    ui.body.value = '# Hello\n\nedited';
    await ui.saveBtn.dispatch('click');
    await flush();
    assertEdit(ui);
    assert.equal(ui.posts.at(-1).payload.body, '# Hello\n\nedited');
    assert.equal(ui.body.value, '# Hello\n\nedited');
    assert.equal(ui.status.textContent, 'Saved.');

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
    assert.equal(script.includes('▾'), false);
    assert.equal(script.includes('▸'), false);

    const ui = bootTree();
    await flush();

    assert.equal(ui.tree.attrs.role, 'tree');
    assert.equal(ui.tree.attrs['aria-label'], 'Vault folders');
    const html = ui.tree.innerHTML;
    assert.match(html, /class="brain-chevron"/);
    assert.match(html, /class="brain-tree-folder"/);
    assert.match(html, /class="brain-kind brain-kind-daily"/);
    assert.match(html, /class="brain-kind brain-kind-project"/);
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
