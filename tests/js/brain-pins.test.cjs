'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const pins = require('../../public/assets/js/brain-pins.js');

function memory(initial) {
    const data = Object.assign({}, initial);
    return {
        getItem(key) { return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null; },
        setItem(key, value) { data[key] = String(value); },
        dump: data,
    };
}

test('favorites and recent collapse independently', () => {
    const start = { favorites: false, recent: false };
    const favoritesClosed = pins.toggle(start, 'favorites');
    assert.deepEqual(favoritesClosed, { favorites: true, recent: false });
    const recentClosed = pins.toggle(favoritesClosed, 'recent');
    assert.deepEqual(recentClosed, { favorites: true, recent: true });
    assert.deepEqual(pins.toggle(recentClosed, 'favorites'), { favorites: false, recent: true });
    assert.deepEqual(pins.toggle(start, 'other'), start);
});

test('enter and space toggle one section and other keys do not', () => {
    const start = { favorites: false, recent: true };
    assert.deepEqual(pins.press(start, 'favorites', 'Enter'), { favorites: true, recent: true });
    assert.deepEqual(pins.press(start, 'recent', ' '), { favorites: false, recent: false });
    assert.deepEqual(pins.press(start, 'recent', 'Spacebar'), { favorites: false, recent: false });
    assert.equal(pins.press(start, 'favorites', 'ArrowDown'), start);
    assert.equal(pins.press(start, 'recent', 'Escape'), start);
});

test('a saved collapse survives reload and each section keeps its own value', () => {
    const store = memory();
    assert.equal(pins.write(store, { favorites: true, recent: false }), true);
    assert.deepEqual(pins.read(store, { narrow: false, coarse: false }), {
        favorites: true,
        recent: false,
    });
    assert.equal(store.dump[pins.key], '{"favorites":true,"recent":false}');
});

test('first use collapses on a narrow or touch layout and stays open on desktop', () => {
    const empty = memory();
    assert.deepEqual(pins.read(empty, { narrow: true, coarse: false }), {
        favorites: true,
        recent: true,
    });
    assert.deepEqual(pins.read(empty, { narrow: false, coarse: true }), {
        favorites: true,
        recent: true,
    });
    assert.deepEqual(pins.read(empty, { narrow: false, coarse: false }), {
        favorites: false,
        recent: false,
    });
    const saved = memory({ [pins.key]: '{"favorites":false,"recent":true}' });
    assert.deepEqual(pins.read(saved, { narrow: true, coarse: true }), {
        favorites: false,
        recent: true,
    });
});

test('a blocked store still renders the default and does not throw', () => {
    const blocked = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
    };
    assert.deepEqual(pins.read(blocked, { narrow: false, coarse: false }), {
        favorites: false,
        recent: false,
    });
    assert.equal(pins.write(blocked, { favorites: true, recent: true }), false);
    assert.deepEqual(pins.read(null, { narrow: true }), { favorites: true, recent: true });
});

test('a rerender keeps the collapsed section and refreshes its rows', () => {
    const chevron = '<svg class="lucide lucide-chevron-right"></svg>';
    const closed = pins.section('favorites', 'Favorites', [{ id: 4, title: 'Home' }], true, chevron);
    assert.match(closed, /data-pin-toggle="favorites"/);
    assert.match(closed, /type="button"/);
    assert.match(closed, /aria-expanded="false"/);
    assert.match(closed, /aria-controls="brain-pin-favorites"/);
    assert.match(closed, /aria-label="Expand Favorites"/);
    assert.match(closed, /id="brain-pin-favorites"[^>]* hidden/);
    assert.match(closed, /data-id="4"/);
    assert.match(closed, />1</);

    const opened = pins.section('recent', 'Recent', [
        { id: 4, title: 'Home' },
        { id: 9, title: 'Trip & notes' },
    ], false, chevron);
    assert.match(opened, /aria-expanded="true"/);
    assert.match(opened, /aria-label="Collapse Recent"/);
    assert.equal(opened.includes(' hidden'), false);
    assert.match(opened, /Trip &amp; notes/);
    assert.match(opened, />2</);
    assert.equal(opened.includes('data-id="4"'), true);
});
