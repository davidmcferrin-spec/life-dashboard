'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const drawer = require('../../public/assets/js/brain-drawer.js');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/brain.js'), 'utf8');
const template = fs.readFileSync(path.join(root, 'templates/brain.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/assets/css/vault.css'), 'utf8');

function memory(initial) {
    const data = Object.assign({}, initial);
    return {
        getItem(key) { return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null; },
        setItem(key, value) { data[key] = String(value); },
        dump: data,
    };
}

function fire(el, type, event) {
    const ev = Object.assign({
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    }, event || {});
    const fn = el.bag && el.bag[type];
    if (fn) fn(ev);
    return ev;
}

function node() {
    const el = {
        bag: {},
        attrs: {},
        tabIndex: 0,
        focused: false,
        styleMap: {},
        classList: {
            names: new Set(),
            add(name) { this.names.add(name); },
            remove(name) { this.names.delete(name); },
            toggle(name, on) {
                const force = on === undefined ? !this.names.has(name) : !!on;
                if (force) this.names.add(name);
                else this.names.delete(name);
                return force;
            },
            contains(name) { return this.names.has(name); },
        },
        style: {
            setProperty(name, value) { el.styleMap[name] = value; },
            removeProperty(name) { delete el.styleMap[name]; },
            getPropertyValue(name) { return el.styleMap[name] || ''; },
        },
        setAttribute(name, value) { el.attrs[name] = String(value); },
        removeAttribute(name) { delete el.attrs[name]; },
        addEventListener(type, fn) { el.bag[type] = fn; },
        setPointerCapture() { el.captured = true; },
        focus() { el.focused = true; },
        querySelector(selector) {
            if (selector === '#brain-tree-pane' || selector === '.brain-tree-pane') return el.pane || null;
            return null;
        },
    };
    return el;
}

function harness(options) {
    const opts = options || {};
    let width = opts.viewport == null ? 1400 : opts.viewport;
    const shell = node();
    const handle = node();
    const hideButton = node();
    const showButton = node();
    const pane = node();
    const host = node();
    shell.pane = pane;
    const mobileOpens = [];
    const win = {
        addEventListener(type, fn) { (win.bag[type] ||= []).push(fn); },
        bag: {},
        resize() { (win.bag.resize || []).forEach((fn) => fn()); },
    };
    const control = drawer.mount(shell, {
        storage: opts.storage,
        viewport() { return width; },
        measure: opts.measure,
        handle,
        hideButton,
        showButton,
        body: host,
        window: win,
        mobileOpen: opts.mobileOpen,
        onMobileToggle() { mobileOpens.push(width); },
    });
    return {
        control,
        shell,
        handle,
        hideButton,
        showButton,
        pane,
        host,
        win,
        mobileOpens,
        setViewport(next) { width = next; },
    };
}

test('a saved width and hidden flag round-trip and ignore junk', () => {
    const store = memory();
    assert.equal(drawer.write(store, { width: 320.4, hidden: true }), true);
    assert.deepEqual(drawer.read(store), { width: 320, hidden: true });
    assert.equal(store.dump[drawer.key], '{"hidden":true,"width":320}');

    const partial = memory({ [drawer.key]: '{"hidden":true}' });
    assert.deepEqual(drawer.read(partial), { width: null, hidden: true });
    const junk = memory({ [drawer.key]: '{"width":"320","hidden":"yes"}' });
    assert.deepEqual(drawer.read(junk), { width: null, hidden: false });
    assert.deepEqual(drawer.read(memory({ [drawer.key]: '{' })), { width: null, hidden: false });
    assert.deepEqual(drawer.read(null), { width: null, hidden: false });
});

test('width clamps to the column limits and the stylesheet defaults', () => {
    assert.equal(drawer.defaultWidth(1400), 230);
    assert.equal(drawer.defaultWidth(1000), 210);
    assert.equal(drawer.defaultWidth(800), 190);
    assert.equal(drawer.clamp(40, 1400), drawer.minWidth);
    assert.equal(drawer.clamp(900, 1400), drawer.maxWidth);
    assert.equal(drawer.clamp(900, 700), 420);
    assert.equal(drawer.limits(700).max, 700 - drawer.reserve);
    assert.equal(drawer.clamp(300, 1400), 300);
});

test('a blocked store still renders the default and does not throw', () => {
    const blocked = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
    };
    assert.deepEqual(drawer.read(blocked), { width: null, hidden: false });
    assert.equal(drawer.write(blocked, { width: 300, hidden: true }), false);
    assert.deepEqual(drawer.read({ getItem() { throw new Error('nope'); } }), { width: null, hidden: false });
});

test('pointer and touch drags resize, clamp, and reset without doubling', () => {
    const store = memory();
    const ui = harness({ storage: () => store, measure: () => 230 });
    assert.equal(ui.handle.styleMap['touch-action'], 'none');
    assert.equal(ui.handle.attrs['aria-valuenow'], '230');
    assert.equal(ui.shell.styleMap['--brain-tree-width'], undefined);

    const preview = fire(ui.handle, 'pointerdown', { button: 0, pointerId: 7, clientX: 100, pointerType: 'touch' });
    assert.equal(preview.defaultPrevented, false);
    assert.equal(ui.handle.captured, true);
    fire(ui.handle, 'pointermove', { pointerId: 7, clientX: 104 });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], undefined);
    fire(ui.handle, 'pointermove', { pointerId: 7, clientX: 180 });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '310px');
    assert.equal(ui.host.classList.contains('is-drawer-dragging'), true);
    fire(ui.handle, 'touchstart', { changedTouches: [{ identifier: 1, clientX: 400 }] });
    fire(ui.handle, 'pointermove', { pointerId: 7, clientX: 190 });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '320px');
    fire(ui.handle, 'pointerup', { pointerId: 7, clientX: 190 });
    assert.equal(ui.host.classList.contains('is-drawer-dragging'), false);
    assert.equal(store.dump[drawer.key], '{"hidden":false,"width":320}');
    assert.deepEqual(ui.control.state(), { width: 320, hidden: false });

    fire(ui.handle, 'pointerdown', { button: 0, pointerId: 3, clientX: 0 });
    fire(ui.handle, 'pointermove', { pointerId: 3, clientX: 2000 });
    fire(ui.handle, 'pointerup', { pointerId: 3, clientX: 2000 });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], drawer.maxWidth + 'px');

    fire(ui.handle, 'pointerdown', { button: 0, pointerId: 4, clientX: 800 });
    fire(ui.handle, 'pointermove', { pointerId: 4, clientX: 100 });
    fire(ui.handle, 'pointercancel', { pointerId: 4, clientX: 100 });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], drawer.maxWidth + 'px');

    const touch = harness({ storage: () => memory(), measure: () => 190 });
    fire(touch.handle, 'touchstart', { changedTouches: [{ identifier: 0, clientX: 40 }] });
    fire(touch.handle, 'touchmove', { changedTouches: [{ identifier: 0, clientX: 80 }] });
    fire(touch.handle, 'touchend', { changedTouches: [{ identifier: 0, clientX: 80 }] });
    assert.equal(touch.shell.styleMap['--brain-tree-width'], '230px');

    fire(ui.handle, 'dblclick', {});
    assert.equal(ui.shell.styleMap['--brain-tree-width'], undefined);
    assert.equal(ui.control.state().width, null);
    assert.equal(JSON.parse(store.dump[drawer.key]).width, undefined);
});

test('double-tap and keyboard reset, and arrows stay inside the limits', () => {
    const store = memory();
    const ui = harness({ storage: () => store, measure: () => 230 });
    fire(ui.handle, 'pointerdown', { button: 0, pointerId: 1, clientX: 10 });
    fire(ui.handle, 'pointermove', { pointerId: 1, clientX: 50 });
    fire(ui.handle, 'pointerup', { pointerId: 1, clientX: 50 });
    assert.equal(ui.control.state().width, 270);

    fire(ui.handle, 'pointerdown', { button: 0, pointerId: 2, clientX: 10 });
    fire(ui.handle, 'pointerup', { pointerId: 2, clientX: 10 });
    fire(ui.handle, 'pointerdown', { button: 0, pointerId: 3, clientX: 12 });
    fire(ui.handle, 'pointerup', { pointerId: 3, clientX: 12 });
    assert.equal(ui.control.state().width, null);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], undefined);

    const right = fire(ui.handle, 'keydown', { key: 'ArrowRight' });
    assert.equal(right.defaultPrevented, true);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '246px');
    const space = fire(ui.handle, 'keydown', { key: ' ' });
    assert.equal(space.defaultPrevented, true);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '246px');
    fire(ui.handle, 'keydown', { key: 'Home' });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], drawer.minWidth + 'px');
    fire(ui.handle, 'keydown', { key: 'End' });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], drawer.maxWidth + 'px');
    ui.setViewport(700);
    fire(ui.handle, 'keydown', { key: 'End' });
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '420px');
    const enter = fire(ui.handle, 'keydown', { key: 'Enter' });
    assert.equal(enter.defaultPrevented, true);
    assert.equal(ui.control.state().width, null);
    assert.equal(ui.handle.attrs['aria-valuenow'], '190');
});

test('hide and show keep the width, and a phone keeps the overlay toggle', () => {
    const store = memory();
    const ui = harness({
        storage: () => store,
        measure: () => 230,
        mobileOpen() { return false; },
    });
    fire(ui.handle, 'pointerdown', { button: 0, pointerId: 1, clientX: 0 });
    fire(ui.handle, 'pointermove', { pointerId: 1, clientX: 40 });
    fire(ui.handle, 'pointerup', { pointerId: 1, clientX: 40 });

    fire(ui.hideButton, 'click', {});
    assert.equal(ui.shell.classList.contains('is-drawer-collapsed'), true);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '0px');
    assert.equal(ui.hideButton.attrs['aria-expanded'], 'false');
    assert.equal(ui.showButton.attrs['aria-expanded'], 'false');
    assert.equal(ui.showButton.focused, true);
    assert.equal(ui.pane.attrs['aria-hidden'], 'true');
    assert.equal(ui.pane.attrs.inert, '');
    assert.equal(ui.handle.tabIndex, -1);
    assert.equal(ui.control.state().width, 270);
    assert.equal(ui.control.state().hidden, true);

    ui.setViewport(500);
    ui.win.resize();
    assert.equal(ui.shell.classList.contains('is-drawer-collapsed'), false);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], undefined);
    assert.equal(ui.pane.attrs['aria-hidden'], undefined);
    assert.equal(ui.showButton.attrs['aria-expanded'], 'false');
    fire(ui.showButton, 'click', {});
    assert.equal(ui.mobileOpens.length, 1);
    assert.equal(ui.control.state().hidden, true);

    ui.setViewport(1400);
    ui.win.resize();
    assert.equal(ui.shell.classList.contains('is-drawer-collapsed'), true);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '0px');
    fire(ui.showButton, 'click', {});
    assert.equal(ui.shell.classList.contains('is-drawer-collapsed'), false);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '270px');
    assert.equal(ui.hideButton.focused, true);
    assert.equal(ui.pane.attrs['aria-hidden'], undefined);
    assert.equal(ui.control.state().hidden, false);
    assert.equal(JSON.parse(store.dump[drawer.key]).hidden, false);
    assert.equal(JSON.parse(store.dump[drawer.key]).width, 270);
});

test('a throwing store still hides and a later paint keeps it', () => {
    const ui = harness({
        storage() { throw new Error('SecurityError'); },
    });
    ui.control.hide();
    assert.equal(ui.shell.classList.contains('is-drawer-collapsed'), true);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '0px');
    ui.control.paint();
    assert.equal(ui.control.state().hidden, true);
    assert.equal(ui.shell.styleMap['--brain-tree-width'], '0px');

    const throwing = harness({
        storage() {
            return {
                getItem() { throw new Error('get failed'); },
                setItem() { throw new Error('set failed'); },
            };
        },
        measure: () => 210,
    });
    fire(throwing.handle, 'keydown', { key: 'ArrowRight' });
    assert.equal(throwing.shell.styleMap['--brain-tree-width'], '226px');
    throwing.control.paint();
    assert.equal(throwing.control.state().width, 226);
    assert.equal(throwing.shell.styleMap['--brain-tree-width'], '226px');
});

test('the notes column markup keeps the mobile overlay and the guarded store', () => {
    assert.match(template, /id="brain-drawer-resize"/);
    assert.match(template, /role="separator"/);
    assert.match(template, /aria-label="Resize notes"/);
    assert.match(template, /id="brain-drawer-hide"/);
    assert.match(template, /aria-label="Hide notes"/);
    assert.match(template, /aria-controls="brain-tree-pane"/);
    assert.match(template, /brain-drawer\.js/);
    assert.match(template, /id="brain-tree-toggle"/);
    assert.match(css, /--brain-tree-width: 230px/);
    assert.match(css, /grid-template-columns: var\(--brain-tree-width\) minmax\(0, 1fr\) var\(--brain-context-width\)/);
    assert.match(css, /--brain-tree-width: 210px/);
    assert.match(css, /--brain-tree-width: 190px/);
    assert.match(css, /\.brain-shell\.is-drawer-collapsed \{ --brain-tree-width: 0px; \}/);
    assert.match(css, /\.brain-drawer-resize \{[^}]*touch-action: none/);
    assert.match(css, /transform: translateX\(-105%\)/);
    assert.match(css, /\.brain-shell\.is-tree-open \.brain-tree-pane \{ transform: translateX\(0\); \}/);
    assert.match(css, /\.brain-drawer-resize,\s*\n\s*#brain-drawer-hide \{ display: none; \}/);
    const storageFn = script.slice(script.indexOf('function drawerStorage'), script.indexOf('function drawerStorage') + 180);
    assert.match(storageFn, /try \{[\s\S]*return window\.localStorage/);
    assert.match(script, /BrainDrawer\.mount/);
    assert.match(script, /onMobileToggle\(\) \{\s*setTreeOpen\(!shell\.classList\.contains\('is-tree-open'\)\);/);
    assert.match(script, /data-folder="root"/);
    assert.match(script, /folderHtml\(folders, 'root'\)/);
});
