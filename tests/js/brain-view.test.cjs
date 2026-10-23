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
