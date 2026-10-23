const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const js = fs.readFileSync(path.join(root, 'public/assets/js/spotify-player.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public/assets/css/lifedash.css'), 'utf8');
const themes = fs.readFileSync(path.join(root, 'public/assets/css/dashboard.css'), 'utf8');

function extract(source, name) {
    const start = source.indexOf('function ' + name + '(');
    assert.ok(start >= 0, name + ' is defined');
    const brace = source.indexOf('{', start);
    let depth = 0;
    for (let i = brace; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) return source.slice(start, i + 1);
        }
    }
    throw new Error('unclosed ' + name);
}

const sandbox = {};
vm.runInNewContext(
    [
        extract(js, 'spotifySdkMessage'),
        extract(js, 'spotifySdkReason'),
        extract(js, 'spotifyClip'),
        extract(js, 'spotifyDeviceLabel'),
        extract(js, 'spotifyDeviceIcon'),
        extract(js, 'spotifyHoldId'),
        extract(js, 'spotifySyncSelect'),
    ].join('\n'),
    sandbox
);

test('sdk status names the failure instead of a generic browser message', () => {
    assert.equal(js.includes('This browser is not a Spotify Connect device'), false);
    assert.equal(sandbox.spotifySdkMessage('scope'), 'Reconnect Spotify to enable in-browser playback.');
    assert.equal(sandbox.spotifySdkMessage('https'), 'In-browser playback needs HTTPS.');
    assert.equal(sandbox.spotifySdkMessage('premium'), 'In-browser playback needs Spotify Premium.');
    assert.equal(sandbox.spotifySdkMessage('autoplay'), 'The browser blocked audio. Click Play to start in-browser playback.');
    assert.equal(sandbox.spotifySdkMessage('script'), 'The Spotify player script did not load.');
    assert.equal(sandbox.spotifySdkMessage('token'), 'Could not get a Spotify token for this browser.');
    assert.equal(sandbox.spotifySdkMessage('auth', 'invalid_client'), 'Spotify rejected the browser player (invalid_client).');
    assert.equal(sandbox.spotifySdkMessage('auth'), 'Spotify rejected the browser player token.');
    assert.equal(sandbox.spotifySdkMessage('init', 'timeout'), 'The browser player did not start (timeout).');
    assert.equal(sandbox.spotifySdkMessage('failed', 'device id missing'), 'device id missing');
    assert.equal(sandbox.spotifySdkMessage('failed'), '');
});

test('sdk events map onto those reasons', () => {
    assert.equal(sandbox.spotifySdkReason('account_error', ''), 'premium');
    assert.equal(sandbox.spotifySdkReason('playback_error', 'Premium required'), 'premium');
    assert.equal(sandbox.spotifySdkReason('authentication_error', 'Invalid token scope'), 'scope');
    assert.equal(sandbox.spotifySdkReason('authentication_error', 'Invalid token'), 'auth');
    assert.equal(sandbox.spotifySdkReason('playback_error', 'play() failed: not allowed'), 'autoplay');
    assert.equal(sandbox.spotifySdkReason('initialization_error', 'boom'), 'init');
    assert.equal(sandbox.spotifySdkReason('playback_error', 'track failed'), 'init');
    assert.equal(sandbox.spotifySdkReason('unknown', ''), 'failed');
});

test('device and playlist labels stay inside the panel', () => {
    const active = sandbox.spotifyDeviceLabel({ type: 'Computer', name: 'Desk', is_active: true });
    assert.equal(active, 'Computer · Desk (active)');
    assert.equal(sandbox.spotifyDeviceLabel({ type: 'Smartphone', name: 'Pocket', is_active: false }), 'Smartphone · Pocket');
    const long = sandbox.spotifyDeviceLabel({
        type: 'Computer',
        name: 'A very long web player name that should not widen the panel',
        is_active: true,
    });
    assert.equal(long.length, 42);
    assert.equal(long.endsWith('…'), true);
    assert.equal(sandbox.spotifyClip('Evening Instrumentals For A Very Long Commute Home Tonight', 42).length, 42);
    assert.match(sandbox.spotifyDeviceIcon('Computer'), /<rect /);
    assert.match(sandbox.spotifyDeviceIcon('Smartphone'), /<rect /);
    assert.equal(sandbox.spotifyDeviceIcon('Speaker').includes('Computer'), false);
});

test('the player surface is opaque and selects cannot grow past it', () => {
    for (const theme of ['light', 'dark', 'jarvis']) {
        const block = themes.match(new RegExp('html\\[data-theme="' + theme + '"\\] \\{[^}]+\\}'));
        assert.ok(block, theme);
        assert.match(block[0], /--player-bg:\s*#[0-9a-fA-F]{6};/);
    }
    const panel = css.match(/\.sp-player \{[^}]+\}/);
    assert.ok(panel);
    assert.match(panel[0], /background:\s*var\(--player-bg/);
    assert.equal(panel[0].includes('color-mix'), false);
    assert.match(css, /\.sp-player select \{[^}]*max-width:\s*100%/);
    assert.match(css, /\.sp-player select \{[^}]*min-width:\s*0/);
    assert.match(css, /\.sp-player select \{[^}]*text-overflow:\s*ellipsis/);
    assert.match(css, /\.sp-field-row \{[^}]*min-width:\s*0/);
    assert.match(css, /\.sp-field-row \{[^}]*overflow:\s*hidden/);
});

test('collapsed player keeps transport and a compact volume control', () => {
    assert.match(js, /data-collapse/);
    assert.match(js, /state\.min/);
    assert.match(js, /localStorage\.setItem\(STORE/);
    assert.match(js, /data-cmd="shuffle"/);
    assert.match(js, /data-cmd="previous"/);
    assert.match(js, /class="sp-play"/);
    assert.match(js, /data-cmd="next"/);
    assert.match(js, /data-cmd="repeat"/);
    assert.match(js, /data-mute/);
    assert.match(js, /volume_percent/);
    assert.match(js, /sendVolume\(value\)\.catch\(showCommandError\)/);
    assert.match(js, /sp-expanded-only/);
    assert.match(js, /sp-queue-modal/);
    assert.match(js, /className = 'app-modal'/);
    assert.match(js, /event\.key === 'Escape'/);
    const mini = css.match(/\.sp-player\.is-min \{[^}]+\}/);
    assert.ok(mini);
    assert.match(mini[0], /max-width:\s*min\(20rem/);
    assert.match(mini[0], /background:\s*var\(--player-bg/);
    assert.match(css, /\.sp-player\.is-min \.sp-expanded-only \{\s*display:\s*none/);
    assert.match(css, /\.sp-controls svg \{[^}]*fill:\s*none/);
    assert.match(css, /\.sp-controls svg\.sp-fill \{[^}]*fill:\s*currentColor/);
    assert.match(css, /\.sp-controls \.sp-play \{[^}]*2\.65rem/);
});

function mediaBlock(source, marker) {
    const at = source.indexOf(marker);
    assert.ok(at >= 0, marker);
    const start = source.lastIndexOf('@media', at);
    const brace = source.indexOf('{', start);
    let depth = 0;
    for (let i = brace; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) return source.slice(start, i + 1);
        }
    }
    throw new Error('unclosed media');
}

test('a phone can open the queue from the player it actually shows', () => {
    const repeatAt = js.indexOf('data-cmd="repeat"');
    const queueAt = js.indexOf('class="sp-queue-compact" data-queue aria-label="Queue" title="Queue"');
    const volAt = js.indexOf('sp-vol-pop sp-mini-only');
    assert.ok(repeatAt >= 0 && queueAt > repeatAt && volAt > queueAt);
    assert.match(js, /\$\{icon\('queue'\)\}/);
    assert.match(js, /class="sp-expanded-only" data-queue>Queue</);
    assert.equal(js.includes('sp-queue-compact sp-expanded-only'), false);
    assert.equal(js.includes('sp-queue-compact sp-mini-only'), false);
    assert.match(js, /button\.dataset\.queue !== undefined/);
    assert.match(js, /await openQueue\(\)/);
    assert.match(js, /modal\.id = 'sp-queue-modal'/);
    assert.match(js, /class="app-modal-close" data-close aria-label="Close"/);
    assert.match(css, /\.sp-player \.sp-queue-compact \{\s*display:\s*none/);
    const phone = mediaBlock(css, '.sp-player:not(.is-min) .sp-expanded-only[data-queue]');
    assert.match(phone, /@media \(max-width: 700px\)/);
    assert.match(phone, /\.sp-player \.sp-controls \.sp-queue-compact \{\s*display:\s*inline-flex/);
    assert.match(phone, /\.sp-player:not\(\.is-min\) \.sp-expanded-only\[data-queue\] \{\s*display:\s*none/);
    assert.equal(/\.sp-player:not\(\.is-min\) \[data-queue\] \{\s*display:\s*none/.test(phone), false);
    assert.match(phone, /#sp-queue-modal \{[^}]*env\(safe-area-inset-top, 0px\)/);
    assert.match(phone, /#sp-queue-modal \.app-modal-panel \{[^}]*box-sizing:\s*border-box/);
    assert.match(phone, /#sp-queue-modal \.app-modal-panel \{[^}]*width:\s*100%/);
    assert.match(phone, /#sp-queue-modal \.app-modal-panel \{[^}]*max-width:\s*100%/);
    assert.match(phone, /#sp-queue-modal \.app-modal-panel \{[^}]*max-height:\s*calc\(100vh - 1rem\)/);
    assert.match(phone, /#sp-queue-modal \.app-modal-panel \{[^}]*max-height:\s*calc\(100dvh - env\(safe-area-inset-top, 0px\)/);
    assert.match(phone, /#sp-queue-modal \.app-modal-panel \{[^}]*overflow:\s*hidden/);
    assert.match(phone, /#sp-queue-modal \.sp-queue-head \{[^}]*flex:\s*none/);
    assert.match(phone, /#sp-queue-modal \.sp-queue-list \{[^}]*overflow-y:\s*auto/);
    assert.match(phone, /#sp-queue-modal \.sp-queue-list \{[^}]*min-height:\s*0/);
    assert.match(phone, /#sp-queue-modal \.app-modal-close \{[^}]*width:\s*2\.75rem/);
    assert.match(phone, /#sp-queue-modal \.app-modal-close \{[^}]*height:\s*2\.75rem/);
});

test('a playlist choice is held for 30 seconds and then dropped', () => {
    assert.match(js, /SPOTIFY_PICK_HOLD_MS = 30000/);
    assert.equal(sandbox.spotifyHoldId('8', 1000, 31000, 30000, ['8', '9']), '8');
    assert.equal(sandbox.spotifyHoldId('8', 1000, 31001, 30000, ['8']), '');
    assert.equal(sandbox.spotifyHoldId('8', 1000, 2000, 30000, ['9']), '');
    assert.equal(sandbox.spotifyHoldId('', 0, 2000, 30000, ['8']), '');
});

test('an unchanged picker is not rebuilt and an open picker is left alone', () => {
    const select = {
        dataset: { options: 'same' },
        innerHTML: '<option value="8">Night</option>',
        value: '8',
    };
    sandbox.spotifySyncSelect(select, false, 'same', '<option value="">Choose</option>', '8');
    assert.equal(select.value, '8');
    assert.equal(select.innerHTML, '<option value="8">Night</option>');
    sandbox.spotifySyncSelect(select, true, 'next', '<option value="">Choose</option>', '');
    assert.equal(select.value, '8');
    assert.equal(select.innerHTML, '<option value="8">Night</option>');
    sandbox.spotifySyncSelect(select, false, 'same', 'ignored', '');
    assert.equal(select.innerHTML, '<option value="8">Night</option>');
    assert.equal(select.value, '');
    sandbox.spotifySyncSelect(select, false, 'next', '<option value="8" selected>Night</option>', '8');
    assert.equal(select.dataset.options, 'next');
    assert.equal(select.value, '8');
    assert.match(select.innerHTML, /value="8"/);
});

test('choosing a playlist does not schedule the refresh that clears it', () => {
    const changeAt = js.indexOf("el.addEventListener('change'");
    const playlistAt = js.indexOf("matches('[data-playlist]')", changeAt);
    const deviceAt = js.indexOf("matches('[data-device]')", playlistAt);
    const refreshAt = js.indexOf('refresh(config, true)', playlistAt);
    const returnAt = js.indexOf('return;', playlistAt);
    assert.ok(changeAt > 0 && playlistAt > changeAt && deviceAt > playlistAt);
    assert.ok(returnAt > playlistAt && returnAt < deviceAt && returnAt < refreshAt);
    assert.match(js, /notePick\('playlist', event\.target\.value\)/);
    assert.match(js, /notePick\('device', event\.target\.value\)/);
    const transferAt = js.indexOf("command('transfer', { device_id: event.target.value })", deviceAt);
    assert.ok(transferAt > deviceAt);
    assert.match(js, /playlist_id: Number\(select\.value\), device_id: deviceId \|\| undefined/);
    const playAt = js.indexOf('playlist_id: Number(select.value)', js.indexOf('button.dataset.playList'));
    const clearAt = js.indexOf("notePick('playlist', '')", playAt);
    assert.ok(playAt > 0 && clearAt > playAt);
});

test('a device choice stays until play instead of snapping back to the active device', () => {
    const holdStart = js.indexOf('function heldDeviceId');
    const holdEnd = js.indexOf('function playlistOptionKey', holdStart);
    const hold = js.slice(holdStart, holdEnd);
    assert.ok(holdStart > 0 && holdEnd > holdStart);
    assert.equal(hold.includes('activeDevice'), false);
    assert.equal(hold.includes('is_active'), false);
    const transport = js.indexOf("button.dataset.cmd === 'play'");
    const list = js.indexOf('button.dataset.playList');
    assert.ok(js.indexOf("notePick('device', '')", transport) > transport);
    assert.ok(js.indexOf("notePick('device', '')", list) > list);
    assert.match(js, /pickerOpen\(deviceSelect\)/);
    assert.match(js, /spotifySyncSelect\(select, false, deviceKey, options, chosenId\)/);
});
