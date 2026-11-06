'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/hermes.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'templates/hermes.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/assets/css/hermes.css'), 'utf8');

function classes(el) {
    return new Set(String(el.className || '').split(/\s+/).filter(Boolean));
}

function matches(el, selector) {
    let rest = selector;
    let saw = false;
    while (rest) {
        if (rest[0] === '.') {
            let j = 1;
            while (j < rest.length && rest[j] !== '[' && rest[j] !== '.') j += 1;
            if (!classes(el).has(rest.slice(1, j))) return false;
            rest = rest.slice(j);
            saw = true;
            continue;
        }
        if (rest[0] === '[') {
            const end = rest.indexOf(']');
            const body = rest.slice(1, end);
            const eq = body.indexOf('=');
            const attr = eq === -1 ? body : body.slice(0, eq);
            if (attr === 'data-id') {
                if (!el.dataset || !el.dataset.id) return false;
            } else if (attr === 'data-hermes-pending') {
                if (!el.dataset || !el.dataset.hermesPending) return false;
            } else if (!el.attrs || !Object.prototype.hasOwnProperty.call(el.attrs, attr)) {
                return false;
            }
            rest = rest.slice(end + 1);
            saw = true;
            continue;
        }
        let j = 0;
        while (j < rest.length && rest[j] !== '[' && rest[j] !== '.') j += 1;
        const tag = rest.slice(0, j);
        if (tag && el.tag !== tag) return false;
        rest = rest.slice(j);
        saw = true;
    }
    return saw;
}

function query(el, selector, all) {
    const found = [];
    function visit(node) {
        for (const child of node.children || []) {
            if (matches(child, selector)) {
                found.push(child);
                if (!all) return;
            }
            visit(child);
            if (!all && found.length) return;
        }
    }
    visit(el);
    return found;
}

function element(tag) {
    const el = {
        tag,
        className: '',
        textContent: '',
        dataset: {},
        attrs: {},
        children: [],
        parent: null,
        disabled: false,
        value: '',
        dateTime: '',
        listeners: {},
        clientHeight: 0,
        _scrollTop: 0,
        appendChild(child) {
            child.parent = el;
            el.children.push(child);
            return child;
        },
        insertBefore(child, ref) {
            child.parent = el;
            const at = el.children.indexOf(ref);
            el.children.splice(at < 0 ? el.children.length : at, 0, child);
            return child;
        },
        replaceChildren() {
            for (const child of el.children) child.parent = null;
            el.children = [];
        },
        remove() {
            if (!el.parent) return;
            const at = el.parent.children.indexOf(el);
            if (at >= 0) el.parent.children.splice(at, 1);
            el.parent = null;
        },
        querySelector(selector) {
            return query(el, selector, false)[0] || null;
        },
        querySelectorAll(selector) {
            return query(el, selector, true);
        },
        addEventListener(type, fn) {
            el.listeners[type] = fn;
        },
        setAttribute(name, value) {
            el.attrs[name] = String(value);
        },
        get scrollHeight() {
            return el.children.reduce((sum, child) => sum + 80, 0);
        },
    };
    Object.defineProperty(el, 'scrollTop', {
        get() {
            return el._scrollTop;
        },
        set(value) {
            const max = Math.max(0, el.scrollHeight - el.clientHeight);
            el._scrollTop = Math.max(0, Math.min(max, value));
            if (el.listeners.scroll) el.listeners.scroll();
        },
    });
    return el;
}

function boot(handlers) {
    const gets = [];
    const posts = [];
    const field = element('textarea');
    const sendBtn = element('button');
    sendBtn.attrs['data-hermes-send'] = '';
    const form = element('form');
    form.attrs['data-hermes-form'] = '';
    form.appendChild(field);
    form.appendChild(sendBtn);
    form.requestSubmit = function () {
        form.listeners.submit({ preventDefault() {} });
    };
    const log = element('div');
    log.attrs['data-hermes-log'] = '';
    log.dataset.page = '2';
    log.clientHeight = 100;
    const loading = element('p');
    loading.className = 'hermes-empty';
    loading.textContent = 'Loading…';
    log.appendChild(loading);
    const status = element('p');
    status.attrs['data-hermes-status'] = '';
    const newBtn = element('button');
    newBtn.attrs['data-hermes-new'] = '';
    const app = element('div');
    app.appendChild(status);
    app.appendChild(log);
    app.appendChild(form);
    app.appendChild(newBtn);

    const sandbox = {
        document: {
            getElementById(id) {
                return id === 'hermes-app' ? app : null;
            },
            createElement: element,
        },
        timers: [],
        setTimeout(fn) {
            sandbox.timers.push(fn);
            return sandbox.timers.length;
        },
        clearTimeout() {},
        LD: {
            api: {
                get(url) {
                    gets.push(url);
                    const next = handlers.get.shift();
                    return Promise.resolve(next);
                },
                post(url, body) {
                    posts.push({ url, body });
                    const next = handlers.post.shift();
                    return next || Promise.resolve({});
                },
            },
        },
        AppModal: handlers.modal || null,
        confirm() {
            return false;
        },
    };
    for (const name of ['Promise', 'Set', 'parseInt', 'encodeURIComponent', 'Number', 'Date', 'String', 'Math', 'Object', 'Array']) {
        sandbox[name] = global[name];
    }
    sandbox.window = sandbox;
    vm.runInNewContext(script, sandbox);
    return { gets, posts, field, sendBtn, form, log, status, newBtn, timers: sandbox.timers };
}

function bodies(log) {
    return log.children
        .filter((node) => node.dataset && node.dataset.id)
        .map((node) => node.children.find((child) => classes(child).has('hermes-msg-body')).textContent);
}

function whos(log) {
    return log.children
        .filter((node) => node.dataset && node.dataset.id)
        .map((node) => node.children.find((child) => classes(child).has('hermes-msg-who')).textContent);
}

function key(extra) {
    return {
        key: 'Enter',
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        altKey: false,
        isComposing: false,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        ...extra,
    };
}

test('the hermes page keeps the composer on screen and describes Ctrl+Enter', () => {
    assert.match(page, /class="page hermes-chat/);
    assert.match(page, /data-page="/);
    assert.match(page, /id="hermes-hint"/);
    assert.equal(page.includes('localStorage'), false);
    assert.match(css, /max-height:\s*50vh/);
    assert.match(css, /overflow:\s*auto/);
    assert.match(css, /\.hermes-msg-user[\s\S]*align-self:\s*flex-end/);
    assert.match(css, /\.hermes-msg-agent[\s\S]*align-self:\s*flex-end/);
    assert.match(css, /\.hermes-msg-assistant[\s\S]*align-self:\s*flex-start/);
    assert.match(css, /body\.hermes-chat[\s\S]*overflow:\s*hidden/);
    assert.equal(script.includes('localStorage'), false);
});

test('scroll-up prepends older rows and keeps the viewport anchored', async () => {
    const ui = boot({
        get: [
            {
                reachable: true,
                has_more: true,
                messages: [
                    { id: 'b', role: 'user', content: 'second', ts: '' },
                    { id: 'c', role: 'assistant', content: 'third', ts: '2026-11-05T16:00:00Z' },
                ],
            },
            {
                reachable: true,
                has_more: false,
                messages: [{ id: 'a', role: 'user', content: 'first', ts: '' }],
            },
        ],
        post: [],
    });
    await Promise.resolve();

    assert.deepEqual(bodies(ui.log), ['second', 'third']);
    assert.equal(ui.log.scrollTop, 60);
    assert.equal(ui.gets[0], '/hermes/thread?limit=2');
    const time = ui.log.children[1].children.find((child) => child.tag === 'time');
    assert.equal(time.dateTime, '2026-11-05T16:00:00Z');
    assert.equal(classes(ui.log.children[0]).has('hermes-msg-user'), true);
    assert.equal(classes(ui.log.children[1]).has('hermes-msg-assistant'), true);

    ui.log.scrollTop = 0;
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(ui.gets[1], '/hermes/thread?limit=2&before_id=b');
    assert.deepEqual(bodies(ui.log), ['first', 'second', 'third']);
    assert.equal(ui.log.scrollTop, 80);
    assert.equal(ui.gets.length, 2);
});

test('Ctrl+Enter sends once and stays disabled while Hermes is working', async () => {
    let release = () => {};
    const pending = new Promise((resolve) => {
        release = resolve;
    });
    const ui = boot({
        get: [
            { reachable: true, has_more: false, messages: [] },
            {
                reachable: true,
                run_id: 'run_1',
                has_more: false,
                messages: [{ id: 'u1', role: 'user', content: 'Plan the week', ts: '' }],
            },
        ],
        post: [pending],
    });
    await Promise.resolve();

    const plain = key();
    ui.field.value = 'Plan the week';
    ui.field.listeners.keydown(plain);
    assert.equal(plain.defaultPrevented, false);
    assert.equal(ui.posts.length, 0);

    const cmd = key({ metaKey: true });
    ui.field.listeners.keydown(cmd);
    assert.equal(cmd.defaultPrevented, true);
    assert.equal(ui.posts.length, 1);
    assert.equal(ui.posts[0].url, '/hermes/chat');
    assert.equal(ui.posts[0].body.message, 'Plan the week');
    assert.equal(ui.sendBtn.disabled, true);

    const again = key({ ctrlKey: true });
    ui.field.listeners.keydown(again);
    assert.equal(ui.posts.length, 1);

    release({});
    await pending;
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(ui.field.value, '');
    assert.equal(ui.sendBtn.disabled, true);
    assert.equal(bodies(ui.log).includes('Plan the week'), true);
    const pendingBubble = ui.log.children.find((node) => node.dataset && node.dataset.hermesPending);
    assert.ok(pendingBubble);
    assert.equal(
        pendingBubble.children.find((child) => classes(child).has('hermes-msg-body')).textContent,
        'Working…',
    );
    ui.field.value = 'Still waiting';
    ui.field.listeners.keydown(key({ ctrlKey: true }));
    assert.equal(ui.posts.length, 1);
});

test('agent turns stay labeled and a tail refresh does not duplicate them', async () => {
    const first = {
        reachable: true,
        has_more: true,
        run_id: '',
        messages: [
            { id: 'd.1.1', role: 'user', origin: 'you', sender: 'You', content: 'Hello', ts: '' },
            { id: 'm.2.1', role: 'user', origin: 'mcp', sender: 'AI Agent (MCP)', content: 'Hi Hermes! Checking in from Chief of Staff via Life-Dashboard.', ts: '' },
            { id: 'm.3.2', role: 'assistant', origin: 'hermes', sender: 'Hermes', content: 'Hello from Hermes.', ts: '' },
        ],
    };
    const tail = {
        reachable: true,
        has_more: true,
        run_id: 'run_mcp',
        messages: first.messages.concat([
            { id: 'd.4.3', role: 'user', origin: 'mcp', sender: 'MCP · Grok Bot v3', content: 'One more check.', ts: '' },
        ]),
    };
    const ui = boot({
        get: [first, tail, tail],
        post: [],
    });
    await Promise.resolve();

    assert.deepEqual(whos(ui.log), ['You', 'AI Agent (MCP)', 'Hermes']);
    assert.equal(classes(ui.log.children[0]).has('hermes-msg-user'), true);
    assert.equal(classes(ui.log.children[1]).has('hermes-msg-agent'), true);
    assert.equal(classes(ui.log.children[2]).has('hermes-msg-assistant'), true);

    const tailTimer = ui.timers[ui.timers.length - 1];
    tailTimer();
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(bodies(ui.log), [
        'Hello',
        'Hi Hermes! Checking in from Chief of Staff via Life-Dashboard.',
        'Hello from Hermes.',
        'One more check.',
    ]);
    assert.equal(whos(ui.log)[3], 'MCP · Grok Bot v3');
    assert.equal(classes(ui.log.children[3]).has('hermes-msg-agent'), true);
    const pending = ui.log.children.find((node) => node.dataset && node.dataset.hermesPending);
    assert.ok(pending);

    const again = ui.timers[ui.timers.length - 1];
    again();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(bodies(ui.log), [
        'Hello',
        'Hi Hermes! Checking in from Chief of Staff via Life-Dashboard.',
        'Hello from Hermes.',
        'One more check.',
    ]);
    assert.equal(ui.log.children.filter((node) => node.dataset && node.dataset.id === 'd.4.3').length, 1);
});

test('a refresh already in flight cannot restore a new chat', async () => {
    let release = () => {};
    const pending = new Promise((resolve) => {
        release = resolve;
    });
    const ui = boot({
        get: [
            {
                reachable: true,
                has_more: false,
                messages: [{ id: 'a', role: 'user', origin: 'you', sender: 'You', content: 'Hello', ts: '' }],
            },
            pending,
        ],
        post: [Promise.resolve({ reachable: true, has_more: false, messages: [] })],
        modal: { confirm() { return Promise.resolve(true); } },
    });
    await Promise.resolve();
    assert.deepEqual(bodies(ui.log), ['Hello']);

    const tail = ui.timers[ui.timers.length - 1];
    tail();
    ui.newBtn.listeners.click();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(bodies(ui.log), []);

    release({
        reachable: true,
        has_more: false,
        messages: [
            { id: 'a', role: 'user', origin: 'you', sender: 'You', content: 'Hello', ts: '' },
            { id: 'm', role: 'user', origin: 'mcp', sender: 'AI Agent (MCP)', content: 'old agent', ts: '' },
        ],
    });
    await pending;
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(bodies(ui.log), []);
    assert.equal(ui.log.querySelector('.hermes-empty').textContent, 'No messages yet. Ask Hermes something.');
});
