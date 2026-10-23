'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/settings-confirms.js'), 'utf8');

function readSettings(name) {
    return fs.readFileSync(path.join(root, 'templates/settings', name), 'utf8');
}

test('feed, token, and user pages no longer use a native confirm', () => {
    const feeds = readSettings('feeds.php');
    const tokens = readSettings('tokens.php');
    const users = readSettings('users.php');

    for (const [name, html] of [['feeds.php', feeds], ['tokens.php', tokens], ['users.php', users]]) {
        assert.equal(html.includes('confirm('), false, name);
        assert.match(html, /\/assets\/js\/settings-confirms\.js\?v=/);
    }

    assert.match(feeds, /Delete ' \. \$source\['name'\] \. ' and its cached articles\?/);
    assert.match(feeds, /data-confirm-label="Delete"/);
    assert.match(feeds, /data-confirm-danger="1"/);

    assert.match(tokens, /Regenerate\? The old token will stop working immediately\./);
    assert.match(tokens, /data-confirm-label="Regenerate"/);
    assert.match(tokens, /Revoke this token\?/);
    assert.match(tokens, /data-confirm-label="Revoke"/);

    assert.match(users, /Delete this user and all of their private data\? This cannot be undone\./);
    assert.match(users, /data-confirm-label="Delete"/);
    assert.match(users, /data-confirm-danger="1"/);

    assert.equal((feeds.match(/data-confirm-message=/g) || []).length, 1);
    assert.equal((tokens.match(/data-confirm-message=/g) || []).length, 2);
    assert.equal((users.match(/data-confirm-message=/g) || []).length, 1);
});

function bind(forms, answer) {
    const calls = [];
    const sandbox = {
        document: {
            querySelectorAll(selector) {
                assert.equal(selector, 'form[data-confirm-message]');
                return forms;
            },
        },
        AppModal: {
            confirm(options) {
                calls.push(options);
                return Promise.resolve(answer);
            },
        },
    };
    sandbox.globalThis = sandbox;
    vm.runInNewContext(script, sandbox);
    return calls;
}

function form(dataset) {
    const listeners = [];
    return {
        dataset: { ...dataset },
        isConnected: true,
        submitted: 0,
        addEventListener(type, fn) {
            assert.equal(type, 'submit');
            listeners.push(fn);
        },
        submit() {
            this.submitted += 1;
        },
        fire() {
            const event = {
                defaultPrevented: false,
                preventDefault() {
                    this.defaultPrevented = true;
                },
            };
            const pending = listeners[0](event);
            return { event, pending };
        },
    };
}

test('a confirm posts the same form only after yes', async () => {
    const target = form({
        confirmTitle: 'Revoke token',
        confirmMessage: 'Revoke this token?',
        confirmLabel: 'Revoke',
        confirmDanger: '1',
    });
    const calls = bind([target], true);
    const { event, pending } = target.fire();
    await pending;

    assert.equal(event.defaultPrevented, true);
    assert.equal(calls.length, 1);
    assert.equal(String(calls[0].title), 'Revoke token');
    assert.equal(String(calls[0].message), 'Revoke this token?');
    assert.equal(String(calls[0].confirmLabel), 'Revoke');
    assert.equal(calls[0].danger, true);
    assert.equal(target.submitted, 1);
    assert.equal(target.dataset.confirming, undefined);
});

test('cancel leaves the form unsent', async () => {
    const target = form({
        confirmTitle: 'Delete user',
        confirmMessage: 'Delete this user and all of their private data? This cannot be undone.',
        confirmLabel: 'Delete',
        confirmDanger: '1',
    });
    bind([target], false);
    const { pending } = target.fire();
    await pending;
    assert.equal(target.submitted, 0);
    assert.equal(target.dataset.confirming, undefined);
});

test('a second submit while the dialog is open does not ask twice', async () => {
    let release;
    const gate = new Promise((resolve) => {
        release = resolve;
    });
    const target = form({
        confirmMessage: 'Delete Example and its cached articles?',
        confirmLabel: 'Delete',
        confirmDanger: '1',
    });
    const calls = [];
    const sandbox = {
        document: {
            querySelectorAll() {
                return [target];
            },
        },
        AppModal: {
            confirm(options) {
                calls.push(options);
                return gate;
            },
        },
    };
    sandbox.globalThis = sandbox;
    vm.runInNewContext(script, sandbox);

    const first = target.fire();
    const second = target.fire();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].title, 'Confirm');
    assert.equal(calls[0].danger, true);
    release(true);
    await first.pending;
    await second.pending;
    assert.equal(calls.length, 1);
    assert.equal(target.submitted, 1);
});

test('a missing dialog does not post', async () => {
    const target = form({
        confirmMessage: 'Revoke this token?',
        confirmLabel: 'Revoke',
        confirmDanger: '1',
    });
    const sandbox = {
        document: {
            querySelectorAll() {
                return [target];
            },
        },
    };
    sandbox.globalThis = sandbox;
    vm.runInNewContext(script, sandbox);
    const { pending } = target.fire();
    await pending;
    assert.equal(target.submitted, 0);
});
