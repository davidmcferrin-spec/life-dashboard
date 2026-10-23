'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/app-modal.js'), 'utf8');
const head = fs.readFileSync(path.join(root, 'templates/partials/head.php'), 'utf8');

function matchesCompound(el, sel) {
    let i = 0;
    let saw = false;
    while (i < sel.length) {
        const ch = sel[i];
        if (ch === '.') {
            let j = i + 1;
            while (j < sel.length && sel[j] !== '.' && sel[j] !== '#' && sel[j] !== '[' && sel[j] !== ':') j += 1;
            if (!el.classList.contains(sel.slice(i + 1, j))) return false;
            i = j;
            saw = true;
            continue;
        }
        if (ch === '#') {
            let j = i + 1;
            while (j < sel.length && /[\w-]/.test(sel[j])) j += 1;
            if (el.id !== sel.slice(i + 1, j)) return false;
            i = j;
            saw = true;
            continue;
        }
        if (ch === '[') {
            const end = sel.indexOf(']', i);
            const body = sel.slice(i + 1, end);
            const eq = body.indexOf('=');
            if (eq === -1) {
                if (!el.hasAttribute(body)) return false;
            } else {
                let val = body.slice(eq + 1);
                if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
                    val = val.slice(1, -1);
                }
                if (el.getAttribute(body.slice(0, eq)) !== val) return false;
            }
            i = end + 1;
            saw = true;
            continue;
        }
        if (ch === ':') {
            if (!sel.startsWith(':not(', i)) return false;
            const start = i + 5;
            let depth = 1;
            let j = start;
            while (j < sel.length && depth) {
                if (sel[j] === '(') depth += 1;
                else if (sel[j] === ')') depth -= 1;
                if (depth) j += 1;
            }
            if (matchesCompound(el, sel.slice(start, j))) return false;
            i = j + 1;
            saw = true;
            continue;
        }
        if (/[a-zA-Z]/.test(ch)) {
            let j = i + 1;
            while (j < sel.length && /[a-zA-Z0-9-]/.test(sel[j])) j += 1;
            if (el.tagName !== sel.slice(i, j).toUpperCase()) return false;
            i = j;
            saw = true;
            continue;
        }
        return false;
    }
    return saw;
}

function matches(el, selector) {
    return selector.split(',').some((part) => matchesCompound(el, part.trim()));
}

function createDom() {
    const observers = [];
    let pending = [];
    let scheduled = false;

    function deliver(record) {
        pending.push(record);
        if (scheduled) return;
        scheduled = true;
        queueMicrotask(() => {
            scheduled = false;
            const batch = pending;
            pending = [];
            observers.forEach((obs) => obs.cb(batch));
        });
    }

    class El {
        constructor(tag) {
            this.tagName = String(tag).toUpperCase();
            this.nodeType = 1;
            this.children = [];
            this.parentElement = null;
            this.attrs = new Map();
            this.listeners = {};
            this.id = '';
            this.className = '';
            this.disabled = false;
        }

        get isConnected() {
            let node = this;
            while (node) {
                if (node === document.documentElement) return true;
                node = node.parentElement;
            }
            return false;
        }

        get classList() {
            const el = this;
            const tokens = () => el.className.split(/\s+/).filter(Boolean);
            return {
                contains(name) { return tokens().includes(name); },
                add(name) {
                    if (tokens().includes(name)) return;
                    el.className = (el.className + ' ' + name).trim();
                    deliver({ type: 'attributes', target: el, attributeName: 'class', addedNodes: [], removedNodes: [] });
                },
                remove(name) {
                    const next = tokens().filter((token) => token !== name);
                    if (next.length === tokens().length) return;
                    el.className = next.join(' ');
                    deliver({ type: 'attributes', target: el, attributeName: 'class', addedNodes: [], removedNodes: [] });
                },
            };
        }

        setAttribute(name, value) {
            if (name === 'class') {
                this.className = String(value);
                return;
            }
            if (name === 'id') this.id = String(value);
            this.attrs.set(name, String(value));
        }

        getAttribute(name) {
            if (name === 'class') return this.className;
            if (name === 'id') return this.id;
            return this.attrs.has(name) ? this.attrs.get(name) : null;
        }

        hasAttribute(name) {
            if (name === 'class') return this.className !== '';
            if (name === 'id') return this.id !== '';
            return this.attrs.has(name);
        }

        removeAttribute(name) {
            if (name === 'class') this.className = '';
            else if (name === 'id') this.id = '';
            this.attrs.delete(name);
        }

        appendChild(child) {
            if (child.parentElement) child.parentElement.removeChild(child, true);
            child.parentElement = this;
            this.children.push(child);
            deliver({ type: 'childList', target: this, addedNodes: [child], removedNodes: [] });
            return child;
        }

        removeChild(child, silent) {
            const index = this.children.indexOf(child);
            if (index === -1) return;
            this.children.splice(index, 1);
            child.parentElement = null;
            if (!silent) deliver({ type: 'childList', target: this, addedNodes: [], removedNodes: [child] });
        }

        remove() {
            if (this.parentElement) this.parentElement.removeChild(this);
        }

        contains(other) {
            let node = other;
            while (node) {
                if (node === this) return true;
                node = node.parentElement;
            }
            return false;
        }

        closest(selector) {
            let node = this;
            while (node && node.nodeType === 1) {
                if (matches(node, selector)) return node;
                node = node.parentElement;
            }
            return null;
        }

        querySelectorAll(selector) {
            const found = [];
            const walk = (node) => {
                node.children.forEach((child) => {
                    if (matches(child, selector)) found.push(child);
                    walk(child);
                });
            };
            walk(this);
            return found;
        }

        querySelector(selector) {
            return this.querySelectorAll(selector)[0] || null;
        }

        addEventListener(type, fn) {
            (this.listeners[type] || (this.listeners[type] = [])).push(fn);
        }

        focus() {
            let node = this;
            while (node) {
                if (node.hasAttribute && node.hasAttribute('inert')) return;
                node = node.parentElement;
            }
            document.activeElement = this;
            document.dispatch('focusin', { target: this });
        }

        click() {
            const event = { target: this, preventDefault() {} };
            let node = this;
            while (node) {
                (node.listeners.click || []).forEach((fn) => fn(event));
                node = node.parentElement;
            }
        }
    }

    function treeFind(node, pred) {
        if (pred(node)) return node;
        for (const child of node.children || []) {
            const found = treeFind(child, pred);
            if (found) return found;
        }
        return null;
    }

    const document = {
        readyState: 'complete',
        nodeType: 9,
        activeElement: null,
        listeners: {},
        documentElement: null,
        body: null,
        addEventListener(type, fn) {
            (this.listeners[type] || (this.listeners[type] = [])).push(fn);
        },
        dispatch(type, event) {
            (this.listeners[type] || []).forEach((fn) => fn(event));
        },
        getElementById(id) {
            return treeFind(this.documentElement, (node) => node.id === id);
        },
        querySelectorAll(selector) {
            return this.documentElement ? this.documentElement.querySelectorAll(selector) : [];
        },
        querySelector(selector) {
            return this.querySelectorAll(selector)[0] || null;
        },
        createElement(tag) {
            return new El(tag);
        },
    };

    const html = new El('html');
    const body = new El('body');
    html.appendChild(body);
    document.documentElement = html;
    document.body = body;
    document.activeElement = body;

    class MutationObserver {
        constructor(cb) { this.cb = cb; }
        observe() { observers.push(this); }
        disconnect() {}
    }

    function h(tag, attrs, children) {
        const node = new El(tag);
        Object.entries(attrs || {}).forEach(([key, value]) => {
            if (key === 'class') node.className = value;
            else if (key === 'id') node.id = value;
            else node.setAttribute(key, value);
        });
        (children || []).forEach((child) => node.appendChild(child));
        return node;
    }

    function keydown(key, extra) {
        const event = {
            key,
            shiftKey: false,
            altKey: false,
            ctrlKey: false,
            metaKey: false,
            defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
            ...extra,
        };
        document.dispatch('keydown', event);
        return event;
    }

    function load() {
        const sandbox = { document, MutationObserver, console };
        vm.createContext(sandbox);
        vm.runInContext(script, sandbox);
    }

    return { document, body, h, keydown, load, flush: () => Promise.resolve() };
}

test('the shared modal script is loaded from the document head', () => {
    assert.match(head, /\/assets\/js\/app-modal\.js\?v=/);
    assert.match(script, /inert/);
    assert.match(script, /Escape/);
    assert.match(script, /data-modal-inert/);
});

test('opening a dialog inerts siblings and keeps the field already focused', async () => {
    const dom = createDom();
    const opener = dom.h('button', { id: 'ld-add' }, []);
    const page = dom.h('div', { id: 'page' }, [opener]);
    const input = dom.h('input', { type: 'text' }, []);
    const close = dom.h('button', { class: 'app-modal-close', 'aria-label': 'Close' }, []);
    const later = dom.h('textarea', {}, []);
    const modal = dom.h('div', { class: 'app-modal hidden', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 't' }, [
        dom.h('h3', { id: 't' }, []),
        close,
        input,
        later,
    ]);
    const player = dom.h('div', { id: 'sp-player' }, []);
    dom.body.appendChild(page);
    dom.body.appendChild(modal);
    dom.body.appendChild(player);
    await dom.flush();
    dom.load();

    opener.focus();
    modal.classList.remove('hidden');
    later.focus();
    await dom.flush();

    assert.equal(dom.document.activeElement, later);
    assert.equal(page.hasAttribute('inert'), true);
    assert.equal(player.hasAttribute('inert'), true);
    assert.equal(modal.hasAttribute('inert'), false);
    assert.equal(dom.body.hasAttribute('inert'), false);
    assert.equal(dom.document.documentElement.hasAttribute('inert'), false);
    assert.equal(page.getAttribute('data-modal-inert'), '1');
});

test('Escape closes through the existing control and focus returns to the opener', async () => {
    const dom = createDom();
    const opener = dom.h('button', { id: 'ld-add' }, []);
    const page = dom.h('div', { id: 'page' }, [opener]);
    const input = dom.h('input', { type: 'text' }, []);
    const save = dom.h('button', { id: 'save' }, []);
    const close = dom.h('button', { class: 'app-modal-close', 'data-close': '' }, []);
    const backdrop = dom.h('div', { class: 'app-modal-backdrop', 'data-close': '' }, []);
    const modal = dom.h('div', { class: 'app-modal hidden', role: 'dialog', 'aria-labelledby': 't' }, [
        backdrop,
        dom.h('h3', { id: 't' }, []),
        close,
        input,
        save,
    ]);
    let closed = 0;
    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) {
            closed += 1;
            modal.classList.add('hidden');
        }
    });
    dom.body.appendChild(page);
    dom.body.appendChild(modal);
    await dom.flush();
    dom.load();

    opener.focus();
    modal.classList.remove('hidden');
    await dom.flush();
    assert.equal(dom.document.activeElement, input);

    const tab = dom.keydown('Tab');
    assert.equal(tab.defaultPrevented, false);
    save.focus();
    const wrapped = dom.keydown('Tab');
    assert.equal(wrapped.defaultPrevented, true);
    assert.equal(dom.document.activeElement, close);

    const back = dom.keydown('Tab', { shiftKey: true });
    assert.equal(back.defaultPrevented, true);
    assert.equal(dom.document.activeElement, save);

    dom.document.activeElement = dom.body;
    const entered = dom.keydown('Tab');
    assert.equal(entered.defaultPrevented, true);
    assert.equal(dom.document.activeElement, close);

    const escape = dom.keydown('Escape');
    assert.equal(escape.defaultPrevented, true);
    await dom.flush();
    assert.equal(closed, 1);
    assert.equal(modal.classList.contains('hidden'), true);
    assert.equal(page.hasAttribute('inert'), false);
    assert.equal(dom.document.activeElement, opener);
});

test('a dialog with no field focuses its title, and removing it restores focus', async () => {
    const dom = createDom();
    const opener = dom.h('button', { id: 'queue' }, []);
    const main = dom.h('main', {}, [opener]);
    dom.body.appendChild(main);
    await dom.flush();
    dom.load();
    opener.focus();

    const title = dom.h('h2', { id: 'sp-queue-title' }, []);
    const close = dom.h('button', { class: 'app-modal-close', 'data-close': '' }, []);
    const modal = dom.h('div', {
        id: 'sp-queue-modal',
        class: 'app-modal',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': 'sp-queue-title',
    }, [
        dom.h('div', { 'data-close': '' }, []),
        title,
        close,
    ]);
    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) modal.remove();
    });
    dom.body.appendChild(modal);
    await dom.flush();

    assert.equal(title.getAttribute('tabindex'), '-1');
    assert.equal(dom.document.activeElement, title);
    assert.equal(main.hasAttribute('inert'), true);
    assert.equal(dom.body.hasAttribute('inert'), false);
    assert.equal(modal.hasAttribute('inert'), false);

    dom.keydown('Escape');
    await dom.flush();
    assert.equal(modal.isConnected, false);
    assert.equal(main.hasAttribute('inert'), false);
    assert.equal(dom.document.activeElement, opener);
});

test('a dialog already on screen does not inert the page', async () => {
    const dom = createDom();
    const page = dom.h('div', { id: 'page' }, []);
    const modal = dom.h('div', { class: 'app-modal hidden' }, []);
    dom.body.appendChild(page);
    dom.body.appendChild(modal);
    await dom.flush();
    dom.load();
    await dom.flush();
    assert.equal(page.hasAttribute('inert'), false);
});
