'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const filter = require('../../public/assets/js/todo-list-filter.js');

const lists = [
    { id: 4, slug: 'work', name: 'Work', pin_to_dashboard: true },
    { id: 5, slug: 'personal', name: 'Personal', pin_to_dashboard: true },
    { id: 9, slug: 'home', name: 'Home', pin_to_dashboard: false },
];

function memory(initial) {
    const data = Object.assign({}, initial);
    return {
        getItem(key) { return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null; },
        setItem(key, value) { data[key] = String(value); },
        dump: data,
    };
}

test('the pinned list is the default, then the first list', () => {
    assert.equal(filter.defaultList(lists).id, 4);
    assert.equal(filter.defaultList([
        { id: 9, slug: 'home', pin_to_dashboard: false },
        { id: 5, slug: 'personal', pin_to_dashboard: false },
    ]).id, 9);
    assert.equal(filter.defaultList([]), null);
});

test('a list link wins over the remembered choice', () => {
    assert.deepEqual(filter.choose(lists, 'home', 'all'), { id: 9, source: 'url' });
    assert.deepEqual(filter.choose(lists, '5', 'all'), { id: 5, source: 'url' });
    assert.deepEqual(filter.choose(lists, 'all', '4'), { id: 'all', source: 'url' });
    assert.deepEqual(filter.choose(lists, 'ALL', '4'), { id: 'all', source: 'url' });
});

test('the last selection is used when the link does not name a list', () => {
    assert.deepEqual(filter.choose(lists, '', 'all'), { id: 'all', source: 'stored' });
    assert.deepEqual(filter.choose(lists, null, 'personal'), { id: 5, source: 'stored' });
    assert.deepEqual(filter.choose(lists, 'missing', '9'), { id: 9, source: 'stored' });
    assert.deepEqual(filter.choose(lists, '', 'gone'), { id: 4, source: 'default' });
    assert.deepEqual(filter.choose([], 'all', 'all'), { id: null, source: 'default' });
});

test('adding while All is selected uses the default list', () => {
    assert.equal(filter.addTarget('all', lists), 4);
    assert.equal(filter.addTarget('ALL', lists), 4);
    assert.equal(filter.addTarget(9, lists), 9);
    assert.equal(filter.addTarget('all', []), null);
});

test('a blocked store falls back to the default and does not throw', () => {
    const blocked = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
    };
    assert.equal(filter.readStored(blocked), '');
    assert.equal(filter.writeStored(blocked, 'all'), false);
    assert.deepEqual(filter.choose(lists, null, filter.readStored(blocked)), { id: 4, source: 'default' });
    assert.equal(filter.readStored(null), '');
});

test('the remembered value is the list id or all', () => {
    const store = memory();
    assert.equal(filter.writeStored(store, 'all'), true);
    assert.equal(filter.readStored(store), 'all');
    assert.equal(store.dump[filter.KEY], 'all');
    assert.equal(filter.writeStored(store, 5), true);
    assert.equal(filter.readStored(store), '5');
    assert.equal(filter.writeStored(store, ''), false);
});
