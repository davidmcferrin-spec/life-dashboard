'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/homelab.js'), 'utf8');

function load() {
    const sandbox = { console };
    sandbox.globalThis = sandbox;
    vm.runInNewContext(script, sandbox);
    return sandbox.HomelabCard;
}

test('deletes need an explicit confirm', () => {
    const card = load();
    assert.equal(card.needsConfirm('delete'), true);
    assert.equal(card.needsConfirm('pause'), false);
    assert.match(card.confirmCopy('The Office', false), /Delete The Office\?/);
    assert.match(card.confirmCopy('The Office', true), /files/);
    assert.match(card.errorText({ code: 'confirmation_required', error: 'needs confirm' }), /confirm/);
    assert.match(card.errorText({ code: 'homelab_unconfigured' }), /Settings/);
    assert.equal(script.includes('window.confirm'), false);
    assert.equal(script.includes('window.alert'), false);
});
