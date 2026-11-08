'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const pins = require('../../public/assets/js/brain-pins.js');

const script = fs.readFileSync(path.join(__dirname, '../../public/assets/js/brain.js'), 'utf8');

function matches(node, selector) {
    if (selector.startsWith('[') && selector.endsWith(']')) {
        const body = selector.slice(1, -1);
        const eq = body.indexOf('=');
        if (eq === -1) return node.attrs[body] !== undefined;
        const name = body.slice(0, eq);
        const value = body.slice(eq + 1).replace(/^"/, '').replace(/"$/, '');
        return node.attrs[name] === value;
    }
    if (selector.startsWith('#')) return node.id === selector.slice(1);
    if (selector.startsWith('.')) {
        const classes = String(node.attrs.class || node.className || '').split(/\s+/);
        return classes.includes(selector.slice(1));
    }
    return node.tag === selector;
}

function createElement(tag) {
    const listeners = {};
    const node = {
        tag,
        id: '',
        hidden: false,
        className: '',
        textContent: '',
        parent: null,
        children: [],
        attrs: {},
        dataset: {},
        focused: false,
        addEventListener(type, fn) {
            (listeners[type] ||= []).push(fn);
        },
        dispatch(type, event) {
            const ev = {
                type,
                target: node,
                bubbles: true,
                key: event && event.key,
                defaultPrevented: false,
                preventDefault() { this.defaultPrevented = true; },
            };
            let current = node;
            while (current) {
                const list = (current.listeners && current.listeners[type]) || [];
                list.forEach((fn) => fn(ev));
                current = ev.bubbles ? current.parent : null;
            }
            node.lastEvent = ev;
            return ev;
        },
        setAttribute(name, value) {
            node.attrs[name] = String(value);
            if (name === 'data-pin-toggle') node.dataset.pinToggle = String(value);
            if (name === 'data-id') node.dataset.id = String(value);
            if (name === 'id') node.id = String(value);
        },
        getAttribute(name) { return node.attrs[name]; },
        appendChild(child) {
            child.parent = node;
            node.children.push(child);
            return child;
        },
        closest(selector) {
            let current = node;
            while (current) {
                if (matches(current, selector)) return current;
                current = current.parent;
            }
            return null;
        },
        querySelector(selector) {
            const all = [];
            const walk = (item) => {
                item.children.forEach((child) => {
                    if (matches(child, selector)) all.push(child);
                    walk(child);
                });
            };
            walk(node);
            return all[0] || null;
        },
        contains(other) {
            let current = other;
            while (current) {
                if (current === node) return true;
                current = current.parent;
            }
            return false;
        },
        focus() { node.focused = true; },
        listeners,
    };
    Object.defineProperty(node, 'innerHTML', {
        set(html) {
            node.children = [];
            parseChildren(String(html), node);
        },
        get() { return ''; },
    });
    return node;
}

function parseChildren(html, parent) {
    const re = /<(\/?)([a-z0-9]+)([^>]*)>|([^<]+)/gi;
    const stack = [parent];
    let match;
    while ((match = re.exec(html))) {
        if (match[4]) {
            stack[stack.length - 1].textContent += match[4];
            continue;
        }
        const closing = match[1] === '/';
        const tag = match[2].toLowerCase();
        if (closing) {
            if (stack.length > 1) stack.pop();
            continue;
        }
        const el = createElement(tag);
        const attrs = match[3] || '';
        attrs.replace(/([^\s=]+)(?:="([^"]*)")?/g, (_, name, value) => {
            if (name === 'hidden') el.hidden = true;
            else el.setAttribute(name, value === undefined ? '' : value);
            return '';
        });
        stack[stack.length - 1].appendChild(el);
        if (!attrs.trim().endsWith('/')) stack.push(el);
    }
}

function host() {
    const node = createElement('div');
    node.ownerDocument = { createElement };
    return node;
}

function lists() {
    return {
        favorites: [{ id: 1, title: 'Cottage' }],
        recent: [{ id: 2, title: 'Trip' }],
    };
}

function expandedAttr(node, kind) {
    return node.querySelector('[data-pin-toggle="' + kind + '"]').getAttribute('aria-expanded');
}

function browserKey(button, key) {
    const keyEvent = button.dispatch('keydown', { key });
    if (!keyEvent.defaultPrevented) button.dispatch('click', {});
    return keyEvent;
}

test('the vault script does not touch localStorage outside a guard or swallow the next click', () => {
    assert.equal(script.includes('pinFromKey'), false);
    const storageFn = script.slice(script.indexOf('function pinStorage'), script.indexOf('function pinStorage') + 180);
    assert.match(storageFn, /try \{[\s\S]*window\.localStorage/);
    assert.equal(script.includes("addEventListener('keydown'"), true);
    const pinKey = script.indexOf('function pinStorage');
    const afterPins = script.slice(pinKey);
    assert.equal(afterPins.includes("pinsEl.addEventListener('keydown'"), false);
});

test('a blocked localStorage getter still toggles and a later paint keeps it', () => {
    const node = host();
    const rows = lists();
    const control = pins.mount(node, {
        storage() { throw new Error('SecurityError'); },
        layout() { return { narrow: false, coarse: false }; },
        favorites: () => rows.favorites,
        recent: () => rows.recent,
        chevron: () => '',
    });
    control.paint(rows.favorites, rows.recent, '');
    assert.equal(expandedAttr(node, 'favorites'), 'true');
    node.querySelector('[data-pin-toggle="favorites"]').dispatch('click', {});
    assert.equal(expandedAttr(node, 'favorites'), 'false');
    assert.equal(expandedAttr(node, 'recent'), 'true');
    rows.favorites = [{ id: 1, title: 'Cottage' }, { id: 3, title: 'Kitchen' }];
    control.paint(rows.favorites, rows.recent, '');
    assert.equal(expandedAttr(node, 'favorites'), 'false');
    assert.match(node.querySelector('.brain-pin-count').textContent, /2/);
    assert.deepEqual(control.state(), { favorites: true, recent: false });
});

test('throwing get and set still leave the toggled section collapsed', () => {
    const node = host();
    const control = pins.mount(node, {
        storage() {
            return {
                getItem() { throw new Error('get failed'); },
                setItem() { throw new Error('set failed'); },
            };
        },
        layout() { return { narrow: true, coarse: false }; },
    });
    const rows = lists();
    control.paint(rows.favorites, rows.recent, '');
    const found = node.querySelector('[data-pin-toggle="recent"]');
    assert.ok(found, 'recent header missing');
    assert.equal(found.getAttribute('aria-expanded'), 'false');
    node.querySelector('[data-pin-toggle="recent"]').dispatch('click', {});
    assert.equal(expandedAttr(node, 'recent'), 'true');
    assert.equal(expandedAttr(node, 'favorites'), 'false');
    control.paint(rows.favorites, rows.recent, '');
    assert.equal(expandedAttr(node, 'recent'), 'true');
    assert.equal(node.querySelector('#brain-pin-recent').hidden, false);
    assert.equal(node.querySelector('#brain-pin-favorites').hidden, true);
});

test('enter then a pointer click both change the section', () => {
    const node = host();
    const control = pins.mount(node, {
        storage() { return { getItem() { return null; }, setItem() {} }; },
        layout() { return { narrow: false, coarse: false }; },
    });
    const rows = lists();
    control.paint(rows.favorites, rows.recent, '');
    const favorites = () => node.querySelector('[data-pin-toggle="favorites"]');
    const keyEvent = browserKey(favorites(), 'Enter');
    assert.equal(keyEvent.defaultPrevented, false);
    assert.equal(expandedAttr(node, 'favorites'), 'false');
    assert.equal(expandedAttr(node, 'recent'), 'true');
    favorites().dispatch('click', {});
    assert.equal(expandedAttr(node, 'favorites'), 'true');
    browserKey(node.querySelector('[data-pin-toggle="recent"]'), ' ');
    assert.equal(expandedAttr(node, 'recent'), 'false');
    assert.equal(expandedAttr(node, 'favorites'), 'true');
});

test('saved sections stay independent when storage works', () => {
    const data = {};
    const store = {
        getItem(key) { return data[key] || null; },
        setItem(key, value) { data[key] = value; },
    };
    const first = host();
    const control = pins.mount(first, {
        storage: () => store,
        layout: () => ({ narrow: false, coarse: false }),
    });
    const rows = lists();
    control.paint(rows.favorites, rows.recent, '');
    first.querySelector('[data-pin-toggle="favorites"]').dispatch('click', {});
    const second = host();
    const again = pins.mount(second, {
        storage: () => store,
        layout: () => ({ narrow: true, coarse: true }),
    });
    again.paint(rows.favorites, rows.recent, '');
    assert.equal(expandedAttr(second, 'favorites'), 'false');
    assert.equal(expandedAttr(second, 'recent'), 'true');
});
